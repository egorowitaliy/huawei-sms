<?php

declare(strict_types=1);

function quick_login_config(): array
{
    global $config;

    return (array)(
        $config['auth']['quick_login']
        ?? []
    );
}

function quick_login_ttl(): int
{
    $cfg = quick_login_config();

    return max(
        300,
        min(
            604800,
            (int)(
                $cfg['token_ttl_seconds']
                ?? 1800
            )
        )
    );
}

function quick_login_channel_enabled(
    string $channel
): bool {
    $cfg = quick_login_config();

    if (empty($cfg['enabled'])) {
        return false;
    }

    $channel = strtolower(trim($channel));

    if (
        !in_array(
            $channel,
            ['telegram', 'matrix', 'max'],
            true
        )
    ) {
        return false;
    }

    $channels = (array)(
        $cfg['channels']
        ?? []
    );

    /*
     * Поддерживается как новый наглядный формат
     * telegram => true, так и старый список имён.
     */
    if (!array_is_list($channels)) {
        return !empty($channels[$channel]);
    }

    $channels = array_map(
        static fn(mixed $value): string =>
            strtolower(trim((string)$value)),
        $channels
    );

    return in_array(
        $channel,
        $channels,
        true
    );
}

function quick_login_open_link(
    string $channel
): array {
    global $config;

    $visibleUrl =
        rtrim(
            (string)$config['app']['base_url'],
            '/'
        ) .
        '/';

    $token =
        quick_login_create_token(
            $channel
        );

    if ($token === null) {
        return [
            'visible_url' => $visibleUrl,
            'target_url' => $visibleUrl,
            'quick_login' => false,
        ];
    }

    return [
        'visible_url' => $visibleUrl,
        'target_url' =>
            $visibleUrl .
            '#access=' .
            $token,
        'quick_login' => true,
    ];
}

function quick_login_cleanup(): void
{
    db()->prepare("
        DELETE FROM auth_quick_login_tokens
        WHERE expires_at <= ?
    ")->execute([
        time(),
    ]);
}

function quick_login_create_token(
    string $channel
): ?string {
    if (!quick_login_channel_enabled($channel)) {
        return null;
    }

    quick_login_cleanup();

    $raw = rtrim(
        strtr(
            base64_encode(random_bytes(32)),
            '+/',
            '-_'
        ),
        '='
    );

    $hash = hash('sha256', $raw);
    $now = time();

    db()->prepare("
        INSERT INTO auth_quick_login_tokens(
            token_hash,
            channel,
            created_at,
            expires_at
        )
        VALUES(?, ?, ?, ?)
    ")->execute([
        $hash,
        strtolower(trim($channel)),
        $now,
        $now + quick_login_ttl(),
    ]);

    return $raw;
}

function quick_login_consume_token(
    string $raw
): bool {
    $cfg = quick_login_config();

    if (empty($cfg['enabled'])) {
        return false;
    }

    $raw = trim($raw);

    if (
        preg_match(
            '/\A[A-Za-z0-9_-]{43}\z/',
            $raw
        ) !== 1
    ) {
        return false;
    }

    $hash =
        hash(
            'sha256',
            $raw
        );

    $pdo = db();

    /*
     * Транзакция не даёт двум параллельным запросам
     * использовать одну и ту же ссылку.
     */
    $pdo->exec('BEGIN IMMEDIATE');

    try {
        $now = time();
        $stmt = $pdo->prepare("
            SELECT
                channel,
                expires_at
            FROM auth_quick_login_tokens
            WHERE token_hash = ?
        ");

        $stmt->execute([
            $hash,
        ]);

        $row = $stmt->fetch();

        if (!is_array($row)) {
            $pdo->commit();
            return false;
        }

        if (
            !quick_login_channel_enabled(
                (string)$row['channel']
            )
        ) {
            $pdo->commit();
            return false;
        }

        if (
            (int)$row['expires_at'] <= $now
        ) {
            /*
             * Если пользователь открыл уже просроченную
             * ссылку, сразу убираем её из базы.
             */
            $delete = $pdo->prepare("
                DELETE FROM auth_quick_login_tokens
                WHERE token_hash = ?
            ");

            $delete->execute([
                $hash,
            ]);

            $pdo->commit();

            return false;
        }

        /*
         * Валидный токен одноразовый:
         * после успешной проверки удаляем его целиком.
         */
        $delete = $pdo->prepare("
            DELETE FROM auth_quick_login_tokens
            WHERE token_hash = ?
              AND expires_at > ?
        ");

        $delete->execute([
            $hash,
            $now,
        ]);

        $accepted =
            $delete->rowCount() === 1;

        $pdo->commit();

        return $accepted;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}


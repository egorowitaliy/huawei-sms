<?php

declare(strict_types=1);

require_once __DIR__ . '/script_commands.php';

function sms_cmd_normalize_phone(string $phone): string
{
    $raw = trim($phone);
    $normalized = sms_normalize_numeric_phone($raw);

    if ($normalized === '') {
        return '';
    }

    $digits = substr($normalized, 1);

    /*
     * Совместимость только для старой российской записи 8XXXXXXXXXX в
     * trusted_phones. Международный номер с явным "+" никогда не меняется.
     */
    if (
        !str_starts_with($raw, '+')
        && strlen($digits) === 11
        && str_starts_with($digits, '8')
    ) {
        $digits = '7' . substr($digits, 1);
    }

    return $digits;
}

function sms_cmd_format_phone(string $phone): string
{
    $normalized = sms_cmd_normalize_phone($phone);

    return $normalized === '' ? '' : '+' . $normalized;
}

function sms_cmd_phone_trusted(string $phone): bool
{
    global $config;

    $incoming = sms_cmd_normalize_phone($phone);

    if ($incoming === '') {
        return false;
    }

    foreach ((array)($config['sms_commands']['trusted_phones'] ?? []) as $trusted) {
        $normalizedTrusted = sms_cmd_normalize_phone((string)$trusted);

        if (
            $normalizedTrusted !== ''
            && hash_equals($normalizedTrusted, $incoming)
        ) {
            return true;
        }
    }

    return false;
}

function sms_cmd_normalize_text(string $text): string
{
    return mb_strtolower(
        trim(preg_replace('/\s+/u', ' ', $text) ?? $text),
        'UTF-8'
    );
}

function sms_cmd_matches(string $text, array $commands): bool
{
    $normalized = sms_cmd_normalize_text($text);

    foreach ($commands as $command) {
        if ($normalized === sms_cmd_normalize_text((string)$command)) {
            return true;
        }
    }

    return false;
}

function sms_cmd_is_help(string $text): bool
{
    global $config;

    return sms_cmd_matches(
        $text,
        (array)($config['sms_commands']['help_commands'] ?? ['помощь', 'help'])
    );
}

function sms_cmd_fingerprint(array $sms): string
{
    /*
     * modem_index добавляется к составному отпечатку, чтобы две одинаковые
     * команды, пришедшие от одного номера в одну секунду, оставались двумя
     * отдельными SMS. Сам по себе индекс Huawei не считается уникальным:
     * после удаления сообщений модем может использовать его повторно.
     */
    return hash('sha256', implode('|', [
        sms_cmd_normalize_phone((string)($sms['phone'] ?? '')),
        trim((string)($sms['sms_date'] ?? '')),
        sms_cmd_normalize_text((string)($sms['content'] ?? '')),
        (string)(int)($sms['modem_index'] ?? 0),
    ]));
}

function sms_cmd_legacy_fingerprint(array $sms): string
{
    return hash('sha256', implode('|', [
        sms_cmd_normalize_phone((string)($sms['phone'] ?? '')),
        trim((string)($sms['sms_date'] ?? '')),
        sms_cmd_normalize_text((string)($sms['content'] ?? '')),
    ]));
}

function sms_cmd_history_fingerprint_for_sms(array $sms): string
{
    $current = sms_cmd_fingerprint($sms);

    if (sms_cmd_history_get($current) !== null) {
        return $current;
    }

    /*
     * Совместимость с рабочими базами, созданными до добавления индекса
     * Huawei в отпечаток команды. Старую запись используем только если она
     * реально уже существует; для новой команды всегда создаётся новый
     * составной отпечаток.
     */
    $legacy = sms_cmd_legacy_fingerprint($sms);

    if ($legacy !== $current && sms_cmd_history_get($legacy) !== null) {
        return $legacy;
    }

    return $current;
}

function sms_cmd_history_get(string $fingerprint): ?array
{
    $stmt = db()->prepare(
        'SELECT * FROM sms_command_history WHERE fingerprint = ?'
    );
    $stmt->execute([$fingerprint]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function sms_cmd_history_begin(
    string $fingerprint,
    string $type,
    string $phone
): bool {
    $now = date('Y-m-d H:i:s');
    $stmt = db()->prepare("
        INSERT OR IGNORE INTO sms_command_history(
            fingerprint,
            command_type,
            phone,
            status,
            attempts,
            last_error,
            request_id,
            result_status,
            reply_sent,
            reply_text,
            reply_chunks_json,
            reply_next_chunk,
            reply_attempts,
            reply_last_attempt_at,
            finished_at,
            created_at,
            updated_at
        ) VALUES(
            ?, ?, ?, 'processing', 1, NULL, NULL, NULL,
            0, NULL, NULL, 0, 0, NULL, NULL, ?, ?
        )
    ");
    $stmt->execute([
        $fingerprint,
        $type,
        sms_cmd_format_phone($phone),
        $now,
        $now,
    ]);

    return $stmt->rowCount() > 0;
}

function sms_cmd_split_text(string $text, int $maxChars): array
{
    $maxChars = max(70, $maxChars);

    if (mb_strlen($text, 'UTF-8') <= $maxChars) {
        return [$text];
    }

    /*
     * Для составного ответа оставляем место под служебный префикс
     * вида "[1/3]\n", чтобы итоговая часть не превышала лимит.
     */
    $payloadLimit = max(70, $maxChars - 12);
    $chunks = [];
    $current = '';

    foreach (explode("\n", $text) as $line) {
        $candidate = $current === '' ? $line : $current . "\n" . $line;

        if (mb_strlen($candidate, 'UTF-8') <= $payloadLimit) {
            $current = $candidate;
            continue;
        }

        if ($current !== '') {
            $chunks[] = $current;
            $current = '';
        }

        while (mb_strlen($line, 'UTF-8') > $payloadLimit) {
            $chunks[] = mb_substr($line, 0, $payloadLimit, 'UTF-8');
            $line = mb_substr($line, $payloadLimit, null, 'UTF-8');
        }

        $current = $line;
    }

    if ($current !== '') {
        $chunks[] = $current;
    }

    if ($chunks === []) {
        $chunks[] = '';
    }

    $count = count($chunks);

    foreach ($chunks as $index => $chunk) {
        $chunks[$index] =
            '[' . ($index + 1) . '/' . $count . "]\n" . $chunk;
    }

    return $chunks;
}

function sms_cmd_history_finish_with_reply(
    string $fingerprint,
    string $status,
    ?string $resultStatus,
    ?string $error,
    ?string $requestId,
    string $reply
): void {
    global $config;

    if (!in_array($status, ['completed', 'failed'], true)) {
        throw new InvalidArgumentException('Некорректный итоговый статус команды');
    }

    $maxChars = (int)($config['sms_commands']['reply_chunk_chars'] ?? 420);
    $chunks = sms_cmd_split_text($reply, $maxChars);
    $chunksJson = json_encode(
        $chunks,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_THROW_ON_ERROR
    );
    $now = date('Y-m-d H:i:s');

    $stmt = db()->prepare("
        UPDATE sms_command_history
        SET status = ?,
            result_status = ?,
            last_error = ?,
            request_id = ?,
            reply_sent = 0,
            reply_text = ?,
            reply_chunks_json = ?,
            reply_next_chunk = 0,
            reply_attempts = 0,
            reply_last_attempt_at = NULL,
            finished_at = ?,
            updated_at = ?
        WHERE fingerprint = ?
    ");
    $stmt->execute([
        $status,
        $resultStatus,
        $error,
        $requestId,
        $reply,
        $chunksJson,
        $now,
        $now,
        $fingerprint,
    ]);
}

function sms_cmd_history_finish_without_reply(
    string $fingerprint,
    string $status,
    ?string $resultStatus,
    ?string $error,
    ?string $requestId
): void {
    if (!in_array($status, ['completed', 'failed'], true)) {
        throw new InvalidArgumentException(
            'Некорректный итоговый статус команды'
        );
    }

    $now = date('Y-m-d H:i:s');

    $stmt = db()->prepare("
        UPDATE sms_command_history
        SET status = ?,
            result_status = ?,
            last_error = ?,
            request_id = ?,
            reply_sent = 1,
            reply_text = NULL,
            reply_chunks_json = NULL,
            reply_next_chunk = 0,
            reply_attempts = 0,
            reply_last_attempt_at = NULL,
            finished_at = ?,
            updated_at = ?
        WHERE fingerprint = ?
    ");

    $stmt->execute([
        $status,
        $resultStatus,
        $error,
        $requestId,
        $now,
        $now,
        $fingerprint,
    ]);
}

function sms_cmd_history_reply_chunks(array $history): array
{
    $json = trim((string)($history['reply_chunks_json'] ?? ''));

    if ($json !== '') {
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);

            if (
                is_array($decoded)
                && $decoded !== []
                && array_is_list($decoded)
                && count(array_filter(
                    $decoded,
                    static fn(mixed $item): bool => is_string($item)
                )) === count($decoded)
            ) {
                return array_values($decoded);
            }
        } catch (Throwable) {
            /* Ниже попробуем восстановить очередь из reply_text. */
        }
    }

    $text = (string)($history['reply_text'] ?? '');

    if ($text === '') {
        return [];
    }

    global $config;
    $maxChars = (int)($config['sms_commands']['reply_chunk_chars'] ?? 420);

    return sms_cmd_split_text($text, $maxChars);
}

function sms_cmd_history_send_reply(
    string $fingerprint,
    bool $respectRetryInterval = false
): bool {
    global $config;

    $history = sms_cmd_history_get($fingerprint);

    if ($history === null) {
        return false;
    }

    if ((int)($history['reply_sent'] ?? 0) === 1) {
        return true;
    }

    $chunks = sms_cmd_history_reply_chunks($history);

    if ($chunks === []) {
        return false;
    }

    $phone = sms_cmd_format_phone((string)($history['phone'] ?? ''));

    if ($phone === '') {
        app_log(
            'ERROR command_reply_invalid_phone fingerprint=' . $fingerprint
        );
        return false;
    }

    $maxAttempts = max(1, min(100, (int)(
        $config['sms_commands']['reply_retry_max_attempts'] ?? 10
    )));
    $attempts = max(0, (int)($history['reply_attempts'] ?? 0));

    if ($attempts >= $maxAttempts) {
        return false;
    }

    if ($respectRetryInterval) {
        $interval = max(10, min(3600, (int)(
            $config['sms_commands']['reply_retry_interval_seconds'] ?? 60
        )));
        $lastAttempt = strtotime(
            (string)($history['reply_last_attempt_at'] ?? '')
        );

        if (
            $lastAttempt !== false
            && (time() - $lastAttempt) < $interval
        ) {
            return false;
        }
    }

    $now = date('Y-m-d H:i:s');
    db()->prepare("
        UPDATE sms_command_history
        SET reply_attempts = reply_attempts + 1,
            reply_last_attempt_at = ?,
            updated_at = ?
        WHERE fingerprint = ? AND reply_sent = 0
    ")->execute([$now, $now, $fingerprint]);

    $nextChunk = max(0, (int)($history['reply_next_chunk'] ?? 0));
    $total = count($chunks);

    for ($index = $nextChunk; $index < $total; $index++) {
        $chunk = (string)$chunks[$index];

        if (!sms_send_by_modem($phone, $chunk)) {
            app_log(
                'ERROR command_reply_send_failed fingerprint=' . $fingerprint .
                ' chunk=' . ($index + 1) . '/' . $total
            );
            return false;
        }

        sms_archive_outbound($phone, $chunk);

        db()->prepare("
            UPDATE sms_command_history
            SET reply_next_chunk = ?, updated_at = ?
            WHERE fingerprint = ?
        ")->execute([
            $index + 1,
            date('Y-m-d H:i:s'),
            $fingerprint,
        ]);

        usleep(300000);
    }

    db()->prepare("
        UPDATE sms_command_history
        SET reply_sent = 1, updated_at = ?
        WHERE fingerprint = ?
    ")->execute([date('Y-m-d H:i:s'), $fingerprint]);

    return true;
}

function sms_cmd_retry_pending_replies(): void
{
    global $config;

    $maxAttempts = max(1, min(100, (int)(
        $config['sms_commands']['reply_retry_max_attempts'] ?? 10
    )));
    $interval = max(10, min(3600, (int)(
        $config['sms_commands']['reply_retry_interval_seconds'] ?? 60
    )));
    $cutoff = date('Y-m-d H:i:s', time() - $interval);

    $stmt = db()->prepare("
        SELECT fingerprint
        FROM sms_command_history
        WHERE status IN ('completed', 'failed')
          AND reply_sent = 0
          AND reply_chunks_json IS NOT NULL
          AND reply_attempts < ?
          AND (
              reply_last_attempt_at IS NULL
              OR reply_last_attempt_at <= ?
          )
        ORDER BY finished_at ASC, updated_at ASC
        LIMIT 10
    ");
    $stmt->execute([$maxAttempts, $cutoff]);

    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $fingerprint) {
        try {
            sms_cmd_history_send_reply((string)$fingerprint, true);
        } catch (Throwable $exception) {
            app_log(
                'ERROR command_reply_retry_failed fingerprint=' .
                app_log_clean((string)$fingerprint) .
                ' error="' . app_log_clean($exception->getMessage()) . '"'
            );
        }
    }
}

function sms_cmd_handle_help(array $sms): array
{
    $phone = (string)($sms['phone'] ?? '');
    $fingerprint = sms_cmd_history_fingerprint_for_sms($sms);
    $history = sms_cmd_history_get($fingerprint);

    if (
        $history !== null
        && in_array((string)$history['status'], ['completed', 'failed'], true)
    ) {
        if ((int)($history['reply_sent'] ?? 0) !== 1) {
            sms_cmd_history_send_reply($fingerprint);
        }

        return [
            'handled' => true,
            'delete' => true,
        ];
    }

    if ($history !== null && (string)$history['status'] === 'processing') {
        global $config;

        $updated = strtotime((string)($history['updated_at'] ?? ''));
        $staleSeconds = max(
            60,
            min(
                3600,
                (int)($config['sms_commands']['processing_stale_seconds'] ?? 300)
            )
        );

        if (
            $updated !== false
            && (time() - $updated) < $staleSeconds
        ) {
            return [
                'handled' => true,
                'delete' => false,
            ];
        }

        /*
         * HELP не выполняет внешних действий, поэтому после аварийного
         * завершения его безопасно восстановить без риска повторить команду.
         */
        $reply = sms_script_command_help_text();

        sms_cmd_history_finish_with_reply(
            $fingerprint,
            'completed',
            'recovered',
            null,
            null,
            $reply
        );

        $sent = sms_cmd_history_send_reply($fingerprint);

        if (!$sent) {
            app_log(
                'ERROR sms_help_recovered_reply_failed fingerprint=' .
                $fingerprint
            );
        }

        return [
            'handled' => true,
            'delete' => true,
        ];
    }

    if (!sms_cmd_history_begin($fingerprint, 'help', $phone)) {
        return [
            'handled' => true,
            'delete' => false,
        ];
    }

    $reply = sms_script_command_help_text();

    sms_cmd_history_finish_with_reply(
        $fingerprint,
        'completed',
        'completed',
        null,
        null,
        $reply
    );

    $sent = sms_cmd_history_send_reply($fingerprint);

    if (!$sent) {
        app_log(
            'ERROR sms_help_reply_failed fingerprint=' . $fingerprint
        );
    }

    return [
        'handled' => true,
        'delete' => true,
    ];
}

function sms_cmd_try_handle(array $sms): array
{
    global $config;

    $notHandled = [
        'handled' => false,
        'delete' => false,
    ];

    if (empty($config['sms_commands']['enabled'])) {
        return $notHandled;
    }

    $phone = (string)($sms['phone'] ?? '');
    $content = (string)($sms['content'] ?? '');

    if (!sms_cmd_phone_trusted($phone)) {
        return $notHandled;
    }

    if (sms_cmd_is_help($content)) {
        return sms_cmd_handle_help($sms);
    }

    return sms_script_command_try_handle($sms);
}

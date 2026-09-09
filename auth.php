<?php
declare(strict_types=1);

require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/quick_login.php';

function auth_current_ip(): string {
    global $config;

    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $trusted = array_map(
        static fn(mixed $value): string => trim((string)$value),
        (array)($config['app']['trusted_proxies'] ?? [])
    );

    if ($remote !== '' && in_array($remote, $trusted, true)) {
        $forwarded = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));

        if ($forwarded === '') {
            $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        }

        if (str_contains($forwarded, ',')) {
            $forwarded = trim(explode(',', $forwarded, 2)[0]);
        }

        if (filter_var($forwarded, FILTER_VALIDATE_IP) !== false) {
            return $forwarded;
        }
    }

    return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : 'unknown';
}

function auth_user_agent(): string {
    return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 0, 255);
}

function auth_attempt_key(): string {
    return hash('sha256', auth_current_ip());
}

function auth_log(string $event, string $username = '-'): void {
    $ip = auth_current_ip();
    $ua = str_replace(["\r", "\n"], ' ', auth_user_agent());
    $username = str_replace(["\r", "\n"], ' ', $username);

    app_log('AUTH event=' . $event . ' ip=' . $ip . ' user="' . $username . '" ua="' . $ua . '"');
}

function totp_base32_decode(string $base32): string|false {
    $base32 = strtoupper(trim($base32));

    if (
        $base32 === ''
        || preg_match('/\A[A-Z2-7]+\z/', $base32) !== 1
    ) {
        return false;
    }

    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    $bits = '';

    for ($i = 0; $i < strlen($base32); $i++) {
        $pos = strpos($alphabet, $base32[$i]);

        if ($pos === false) {
            return false;
        }

        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }

    $bytes = '';

    for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
        $bytes .= chr(bindec(substr($bits, $i, 8)));
    }

    return $bytes;
}

function totp_code(string $secret, int $timeSlice): ?string {
    $key = totp_base32_decode($secret);

    if ($key === false || $key === '') {
        return null;
    }

    $counter = pack('N*', 0, $timeSlice);
    $hash = hash_hmac('sha1', $counter, $key, true);
    $offset = ord(substr($hash, -1)) & 0x0F;

    $binary =
        ((ord($hash[$offset]) & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) << 8) |
        (ord($hash[$offset + 3]) & 0xFF);

    return str_pad((string)($binary % 1000000), 6, '0', STR_PAD_LEFT);
}

function totp_matching_slice(string $secret, string $code): ?int {
    $code = trim($code);

    if (preg_match('/\A[0-9]{6}\z/', $code) !== 1) {
        return null;
    }

    if (totp_base32_decode($secret) === false) {
        return null;
    }

    $slice = (int)floor(time() / 30);

    foreach ([-1, 0, 1] as $window) {
        $candidateSlice = $slice + $window;
        $valid = totp_code($secret, $candidateSlice);

        if ($valid !== null && hash_equals($valid, $code)) {
            return $candidateSlice;
        }
    }

    return null;
}

function totp_verify(string $secret, string $code): bool {
    $slice = totp_matching_slice($secret, $code);

    if ($slice === null) {
        return false;
    }

    /*
     * Запоминаем несколько недавно использованных 30-секундных интервалов.
     * Это запрещает повтор того же TOTP-кода, но не ломает допустимое окно
     * -1/0/+1, если однажды был принят код из соседнего будущего интервала.
     * BEGIN IMMEDIATE не даёт двум параллельным входам принять один код.
     */
    $pdo = db();
    $stateKey = 'auth_totp_used_slices';
    $currentSlice = (int)floor(time() / 30);

    $pdo->exec('BEGIN IMMEDIATE');

    try {
        $stmt = $pdo->prepare(
            'SELECT value FROM app_state WHERE key = ?'
        );
        $stmt->execute([$stateKey]);
        $raw = (string)($stmt->fetchColumn() ?: '[]');

        try {
            $used = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $used = [];
        }

        if (!is_array($used)) {
            $used = [];
        }

        $used = array_values(array_unique(array_map('intval', $used)));

        if (in_array($slice, $used, true)) {
            $pdo->commit();
            return false;
        }

        $used[] = $slice;
        $used = array_values(array_filter(
            $used,
            static fn(int $value): bool =>
                $value >= ($currentSlice - 4)
                && $value <= ($currentSlice + 2)
        ));

        $stmt = $pdo->prepare("\n            INSERT INTO app_state(key, value, updated_at)\n            VALUES(?, ?, ?)\n            ON CONFLICT(key) DO UPDATE SET\n                value = excluded.value,\n                updated_at = excluded.updated_at\n        ");
        $stmt->execute([
            $stateKey,
            json_encode($used, JSON_THROW_ON_ERROR),
            date('Y-m-d H:i:s'),
        ]);

        $pdo->commit();
        return true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function auth_totp_global_limits(): array
{
    global $config;

    return [
        'max' =>
            max(
                1,
                min(
                    100,
                    (int)(
                        $config['totp']['max_failed_attempts']
                        ?? 10
                    )
                )
            ),

        'window' =>
            max(
                60,
                min(
                    86400,
                    (int)(
                        $config['totp']['failure_window_seconds']
                        ?? 600
                    )
                )
            ),

        'block' =>
            max(
                60,
                min(
                    86400,
                    (int)(
                        $config['totp']['global_block_seconds']
                        ?? 900
                    )
                )
            ),
    ];
}

function auth_totp_global_decode_state(
    string $raw
): array {
    try {
        $state = json_decode(
            $raw,
            true,
            16,
            JSON_THROW_ON_ERROR
        );
    } catch (Throwable) {
        $state = [];
    }

    if (!is_array($state)) {
        $state = [];
    }

    return [
        'attempts' =>
            max(
                0,
                (int)(
                    $state['attempts']
                    ?? 0
                )
            ),

        'window_started_at' =>
            max(
                0,
                (int)(
                    $state['window_started_at']
                    ?? 0
                )
            ),

        'blocked_until' =>
            max(
                0,
                (int)(
                    $state['blocked_until']
                    ?? 0
                )
            ),
    ];
}

function auth_totp_global_block_remaining(): int
{
    $stmt = db()->prepare(
        'SELECT value
         FROM app_state
         WHERE key = ?'
    );

    $stmt->execute([
        'auth_totp_global_failures',
    ]);

    $raw = $stmt->fetchColumn();

    if ($raw === false) {
        return 0;
    }

    $state =
        auth_totp_global_decode_state(
            (string)$raw
        );

    return max(
        0,
        (int)$state['blocked_until'] - time()
    );
}

function auth_totp_global_failure(): bool
{
    $limits = auth_totp_global_limits();
    $pdo = db();
    $now = time();
    $key = 'auth_totp_global_failures';

    $pdo->exec('BEGIN IMMEDIATE');

    try {
        $stmt = $pdo->prepare(
            'SELECT value
             FROM app_state
             WHERE key = ?'
        );

        $stmt->execute([$key]);

        $raw = $stmt->fetchColumn();

        $state =
            auth_totp_global_decode_state(
                $raw === false
                    ? ''
                    : (string)$raw
            );

        if (
            (int)$state['blocked_until'] > $now
        ) {
            $pdo->commit();
            return false;
        }

        if (
            (int)$state['blocked_until'] > 0
            && (int)$state['blocked_until'] <= $now
        ) {
            $state = [
                'attempts' => 0,
                'window_started_at' => $now,
                'blocked_until' => 0,
            ];
        }

        if (
            (int)$state['window_started_at'] <= 0
            || (
                $now
                - (int)$state['window_started_at']
            ) >= (int)$limits['window']
        ) {
            $state['attempts'] = 0;
            $state['window_started_at'] = $now;
            $state['blocked_until'] = 0;
        }

        $state['attempts'] =
            (int)$state['attempts'] + 1;

        $becameBlocked = false;

        if (
            (int)$state['attempts']
            >= (int)$limits['max']
        ) {
            $state['attempts'] = 0;
            $state['window_started_at'] = $now;
            $state['blocked_until'] =
                $now + (int)$limits['block'];

            $becameBlocked = true;
        }

        $stmt = $pdo->prepare("
            INSERT INTO app_state(
                key,
                value,
                updated_at
            )
            VALUES(?, ?, ?)
            ON CONFLICT(key) DO UPDATE SET
                value = excluded.value,
                updated_at = excluded.updated_at
        ");

        $stmt->execute([
            $key,
            json_encode(
                $state,
                JSON_THROW_ON_ERROR
            ),
            date('Y-m-d H:i:s'),
        ]);

        $pdo->commit();

        return $becameBlocked;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function auth_totp_global_reset(): void
{
    db()->prepare(
        'DELETE FROM app_state WHERE key = ?'
    )->execute([
        'auth_totp_global_failures',
    ]);
}

function auth_csrf_notification_allowed(string $ip): bool {
    global $config;

    $interval = max(
        30,
        min(
            3600,
            (int)(
                $config['notifications']['auth']['csrf_notification_interval_seconds']
                ?? 300
            )
        )
    );

    /*
     * Ограничение глобальное: для диагностического уведомления достаточно
     * знать, что такие запросы продолжаются. Короткая транзакция нужна,
     * чтобы параллельные POST не прошли проверку одновременно.
     */
    $pdo = db();
    $key = 'auth_csrf_notify_last_at';
    $now = time();

    $pdo->exec('BEGIN IMMEDIATE');

    try {
        $stmt = $pdo->prepare(
            'SELECT value FROM app_state WHERE key = ?'
        );
        $stmt->execute([$key]);
        $last = (int)($stmt->fetchColumn() ?: 0);

        if ($last > 0 && ($now - $last) < $interval) {
            $pdo->commit();
            return false;
        }

        $stmt = $pdo->prepare("
            INSERT INTO app_state(key, value, updated_at)
            VALUES(?, ?, ?)
            ON CONFLICT(key) DO UPDATE SET
                value = excluded.value,
                updated_at = excluded.updated_at
        ");
        $stmt->execute([
            $key,
            (string)$now,
            date('Y-m-d H:i:s'),
        ]);

        $pdo->commit();
        return true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function auth_handle_logout(): void {
    global $config;

    if (empty($config['auth']['enabled'])) {
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
        csrf_check();

        auth_log('logout');

        $_SESSION = [];
        session_destroy();

        header('Location: /?login=1');
        exit;
    }
}

function render_login_blocked_page(
    int $retryAfter
): void {
    $retryAfter =
        max(
            1,
            $retryAfter
        );

    http_response_code(429);

    header(
        'Retry-After: ' .
        $retryAfter
    );

    ?>
    <!doctype html>
    <html lang="ru">
    <head>
        <meta charset="utf-8">
        <meta
            name="viewport"
            content="width=device-width, initial-scale=1"
        >
        <meta name="robots" content="noindex,nofollow">
        <title>Авторизация</title>
        <link rel="stylesheet" href="/assets/style.css">
    </head>
    <body class="login-page">
        <main class="login-box">
            <h1>Авторизация</h1>

            <div class="alert error">
                Вход временно заблокирован из-за нескольких
                неудачных попыток. Попробуй позже.
            </div>
        </main>
    </body>
    </html>
    <?php
}

function auth_handle_login(): void {
    global $config;

    if (
        $_SERVER['REQUEST_METHOD'] !== 'POST'
        || ($_POST['action'] ?? '') !== 'login'
    ) {
        return;
    }

    $token =
        $_POST['csrf_token']
        ?? '';

    $username =
        trim(
            (string)(
                $_POST['username']
                ?? ''
            )
        );

    if (
        !is_string($token)
        || !hash_equals(
            (string)(
                $_SESSION['csrf_token']
                ?? ''
            ),
            $token
        )
    ) {
        $ip = auth_current_ip();
        $userAgent = auth_user_agent();

        auth_log(
            'csrf_failed',
            $username
        );

        if (
            auth_csrf_notification_allowed(
                $ip
            )
        ) {
            auth_notify(
                'csrf_failed',
                $ip,
                $username,
                $userAgent
            );
        }

        render_login_page(
            'Сессия формы устарела. Обнови страницу и попробуй ещё раз'
        );
        exit;
    }

    $key = auth_attempt_key();
    $ip = auth_current_ip();
    $userAgent = auth_user_agent();

    $password =
        (string)(
            $_POST['password']
            ?? ''
        );

    $passwordValid =
        password_verify(
            $password,
            (string)(
                $config['auth']['password_hash']
                ?? ''
            )
        );

    $usernameValid =
        hash_equals(
            (string)(
                $config['auth']['username']
                ?? ''
            ),
            $username
        );

    $passwordOk =
        $usernameValid
        && $passwordValid;

    if (!$passwordOk) {
        $becameBlocked =
            login_failed(
                $key,
                $ip,
                $userAgent
            );

        if ($becameBlocked) {
            auth_log(
                'blocked',
                $username
            );

            auth_notify(
                'blocked',
                $ip,
                $username,
                $userAgent
            );

            render_login_blocked_page(
                max(
                    1,
                    login_blocked_until($key)
                    - time()
                )
            );
        } else {
            auth_log(
                'failed',
                $username
            );

            auth_notify(
                'failed',
                $ip,
                $username,
                $userAgent
            );

            render_login_page(
                'Неверные данные входа'
            );
        }

        exit;
    }

    $totp =
        $config['totp']
        ?? [];

    $quickLoginToken =
        trim(
            (string)(
                $_POST['quick_login_token']
                ?? ''
            )
        );

    $quickLoginAccepted = false;

    if ($quickLoginToken !== '') {
        $quickLoginAccepted =
            quick_login_consume_token(
                $quickLoginToken
            );

        if (!$quickLoginAccepted) {
            $blocked = login_failed($key, $ip, $userAgent);

            auth_log(
                $blocked ? 'blocked' : 'quick_login_failed',
                $username
            );

            if ($blocked) {
                auth_notify('blocked', $ip, $username, $userAgent);
                render_login_blocked_page(
                    max(1, login_blocked_until($key) - time())
                );
            } else {
                render_login_page('Неверные данные входа');
            }

            exit;
        }
    }

    if (
        !empty($totp['enabled'])
        && empty($totp['emergency_bypass'])
        && !$quickLoginAccepted
    ) {
        if (
            auth_totp_global_block_remaining()
            > 0
        ) {
            $blocked = login_failed($key, $ip, $userAgent);

            auth_log(
                $blocked ? 'blocked' : 'totp_global_blocked',
                $username
            );

            if ($blocked) {
                auth_notify('blocked', $ip, $username, $userAgent);
                render_login_blocked_page(
                    max(1, login_blocked_until($key) - time())
                );
            } else {
                render_login_page('Неверные данные входа');
            }

            exit;
        }

        $totpCode =
            trim(
                (string)(
                    $_POST['totp']
                    ?? ''
                )
            );

        $totpSecret =
            (string)(
                $totp['secret']
                ?? ''
            );

        if (
            !totp_verify(
                $totpSecret,
                $totpCode
            )
        ) {
            $becameBlocked =
                login_failed(
                    $key,
                    $ip,
                    $userAgent
                );

            $totpBecameBlocked =
                auth_totp_global_failure();

            auth_log(
                $becameBlocked
                    ? 'blocked'
                    : 'totp_failed',
                $username
            );

            if ($totpBecameBlocked) {
                auth_log(
                    'totp_global_blocked',
                    $username
                );
            }

            auth_notify(
                (
                    $becameBlocked
                    || $totpBecameBlocked
                )
                    ? 'blocked'
                    : 'failed',
                $ip,
                $username,
                $userAgent
            );

            if ($becameBlocked) {
                render_login_blocked_page(
                    max(
                        1,
                        login_blocked_until(
                            $key
                        ) - time()
                    )
                );
            } else {
                render_login_page(
                    'Неверные данные входа'
                );
            }

            exit;
        }
    }

    auth_totp_global_reset();

    login_success($key);

    auth_log(
        'success',
        $username
    );

    auth_notify(
        'success',
        $ip,
        $username,
        $userAgent
    );

    header('Location: /');
    exit;
}

function auth_bootstrap(): void {
    global $config;

    if (
        empty(
            $config['auth']['enabled']
        )
    ) {
        $_SESSION['auth'] = true;
        $_SESSION['last_activity'] =
            time();

        csrf_token();
        return;
    }

    auth_handle_logout();

    $loginContext =
        isset($_GET['login'])
        || isset($_GET['timeout'])
        || empty($_SESSION['auth']);

    if ($loginContext) {
        $key =
            auth_attempt_key();

        $blockedUntil =
            login_blocked_until(
                $key
            );

        if (
            $blockedUntil > time()
        ) {
            auth_log('blocked');

            render_login_blocked_page(
                max(
                    1,
                    $blockedUntil - time()
                )
            );

            exit;
        }
    }

    if (
        $_SERVER['REQUEST_METHOD'] === 'GET'
        && isset($_GET['timeout'])
    ) {
        auth_log('timeout');

        $_SESSION = [];
        session_destroy();

        session_start();
        csrf_token();

        render_login_page(
            '',
            true
        );

        exit;
    }

    if (
        isset($_GET['login'])
        || empty($_SESSION['auth'])
    ) {
        auth_handle_login();

        render_login_page();

        exit;
    }

    require_auth();
}

function render_login_page(string $error = '', bool $timeout = false): void {
    global $config;

    csrf_token();

    $totpEnabled =
        !empty($config['totp']['enabled']) &&
        empty($config['totp']['emergency_bypass']);

    ?>
    <!doctype html>
    <html lang="ru">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex,nofollow">
        <title>Авторизация</title>
        <link rel="stylesheet" href="/assets/style.css">
    </head>
    <body class="login-page">
        <main class="login-box">
            <h1>Авторизация</h1>

            <?php if ($error !== ''): ?>
                <div class="alert error"><?= e($error) ?></div>
            <?php endif; ?>

            <?php if ($timeout || isset($_GET['timeout'])): ?>
                <div class="alert">Сессия завершена по таймауту</div>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="action" value="login">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="quick_login_token" id="quick-login-token" value="">

                <label>Логин</label>
                <input name="username" autocomplete="username" required>

                <label>Пароль</label>
                <input name="password" type="password" autocomplete="current-password" required>

                <?php if ($totpEnabled): ?>
                    <div id="totp-field">
                        <label>Код 2FA</label>
                        <input
                            name="totp"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            maxlength="6"
                            pattern="[0-9]{6}"
                            required
                        >
                    </div>
                <?php endif; ?>

                <button type="submit">Войти</button>
            </form>

            <script>
            (() => {
                const handleQuickLoginFragment = () => {
                    const params = new URLSearchParams(
                        window.location.hash.replace(/^#/, '')
                    );

                    if (!params.has('access')) {
                        return;
                    }

                    const token =
                        params.get('access') || '';

                    const hidden =
                        document.getElementById(
                            'quick-login-token'
                        );

                    const totp =
                        document.querySelector(
                            'input[name="totp"]'
                        );

                    const field =
                        document.getElementById(
                            'totp-field'
                        );

                    if (!/^[A-Za-z0-9_-]{43}$/.test(token)) {
                        if (hidden) {
                            hidden.value = '';
                        }

                        if (totp) {
                            totp.required = true;
                        }

                        if (field) {
                            field.hidden = false;
                        }

                        return;
                    }

                    if (hidden) {
                        hidden.value = token;
                    }

                    if (totp) {
                        totp.required = false;
                    }

                    if (field) {
                        field.hidden = true;
                    }

                    history.replaceState(
                        null,
                        '',
                        window.location.pathname +
                        window.location.search
                    );
                };

                handleQuickLoginFragment();

                window.addEventListener(
                    'hashchange',
                    handleQuickLoginFragment
                );

                window.addEventListener(
                    'pageshow',
                    handleQuickLoginFragment
                );
            })();
            </script>
        </main>
    </body>
    </html>
    <?php
}
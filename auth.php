<?php
declare(strict_types=1);

require_once __DIR__ . '/notify.php';

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

function auth_handle_login(): void {
    global $config;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'login') {
        return;
    }

    $token = $_POST['csrf_token'] ?? '';
    $username = trim((string)($_POST['username'] ?? ''));

    if (!is_string($token) || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        $ip = auth_current_ip();
        $userAgent = auth_user_agent();

        auth_log('csrf_failed', $username);

        if (auth_csrf_notification_allowed($ip)) {
            auth_notify('csrf_failed', $ip, $username, $userAgent);
        }

        render_login_page('Сессия формы устарела. Обнови страницу и попробуй ещё раз');
        exit;
    }

    $key = auth_attempt_key();
    $ip = auth_current_ip();
    $userAgent = auth_user_agent();
    $password = (string)($_POST['password'] ?? '');

    if (login_blocked($key)) {
        auth_log('blocked', $username);

        /*
         * Уведомление о блокировке отправляется в момент, когда лимит
         * достигнут. Последующие запросы во время той же блокировки только
         * пишутся в журнал и не создают поток одинаковых уведомлений.
         */
        render_login_page('Слишком много попыток. Попробуй позже');
        exit;
    }

    $passwordOk =
        hash_equals((string)$config['auth']['username'], $username) &&
        password_verify($password, (string)$config['auth']['password_hash']);

    if (!$passwordOk) {
        $becameBlocked = login_failed($key, $ip, $userAgent);

        if ($becameBlocked) {
            auth_log('blocked', $username);
            auth_notify('blocked', $ip, $username, $userAgent);
        } else {
            auth_log('failed', $username);
            auth_notify('failed', $ip, $username, $userAgent);
        }

        render_login_page(
            $becameBlocked
                ? 'Слишком много попыток. Попробуй позже'
                : 'Неверный логин или пароль'
        );
        exit;
    }

    $totp = $config['totp'] ?? [];

    if (!empty($totp['enabled']) && empty($totp['emergency_bypass'])) {
        $totpCode = trim((string)($_POST['totp'] ?? ''));
        $totpSecret = (string)($totp['secret'] ?? '');

        if (!totp_verify($totpSecret, $totpCode)) {
            $becameBlocked = login_failed($key, $ip, $userAgent);
            auth_log(
                $becameBlocked ? 'blocked' : 'totp_failed',
                $username
            );
            auth_notify(
                $becameBlocked ? 'blocked' : 'failed',
                $ip,
                $username,
                $userAgent
            );

            render_login_page(
                $becameBlocked
                    ? 'Слишком много попыток. Попробуй позже'
                    : 'Неверный код 2FA'
            );
            exit;
        }
    }

    login_success($key);
    auth_log('success', $username);
    auth_notify('success', $ip, $username, $userAgent);

    header('Location: /');
    exit;
}

function auth_bootstrap(): void {
    global $config;

    if (empty($config['auth']['enabled'])) {
        $_SESSION['auth'] = true;
        $_SESSION['last_activity'] = time();
        csrf_token();
        return;
    }

    auth_handle_logout();

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['timeout'])) {
        auth_log('timeout');

        $_SESSION = [];
        session_destroy();

        session_start();
        csrf_token();

        render_login_page('', true);
        exit;
    }

    if (isset($_GET['login']) || empty($_SESSION['auth'])) {
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

                <label>Логин</label>
                <input name="username" autocomplete="username" required>

                <label>Пароль</label>
                <input name="password" type="password" autocomplete="current-password" required>

                <?php if ($totpEnabled): ?>
                    <label>Код 2FA</label>
                    <input name="totp" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" required>
                <?php endif; ?>

                <button type="submit">Войти</button>
            </form>
        </main>
    </body>
    </html>
    <?php
}
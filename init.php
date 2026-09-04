<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';

final class SyncBusyException extends RuntimeException {}
final class ModemApiException extends RuntimeException {}

date_default_timezone_set($config['app']['timezone'] ?? 'Europe/Moscow');

function app_request_is_https(): bool
{
    global $config;

    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $trusted = array_map(
        static fn(mixed $value): string => trim((string)$value),
        (array)($config['app']['trusted_proxies'] ?? [])
    );

    if (
        $remote !== ''
        && in_array($remote, $trusted, true)
    ) {
        $forwardedProto = strtolower(
            trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))
        );

        if (str_contains($forwardedProto, ',')) {
            $forwardedProto = trim(
                explode(',', $forwardedProto, 2)[0]
            );
        }

        return $forwardedProto === 'https';
    }

    return false;
}

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');

if (
    !empty($config['app']['force_secure_cookie'])
    || app_request_is_https()
) {
    ini_set('session.cookie_secure', '1');
}

session_name('huawei_sms_sid');

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function xml_e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sms_normalize_numeric_phone(string $phone): string
{
    $raw = trim($phone);

    if (preg_match('/\A\+?([0-9]{7,15})\z/', $raw, $match) !== 1) {
        return '';
    }

    $digits = (string)$match[1];

    /*
     * Сохраняем привычную российскую запись 8XXXXXXXXXX только когда номер
     * введён без ведущего "+". Полноценный международный номер, например
     * +81..., никогда не переопределяется по российским правилам.
     */
    if (
        !str_starts_with($raw, '+')
        && strlen($digits) === 11
        && str_starts_with($digits, '8')
    ) {
        $digits = '7' . substr($digits, 1);
    }

    return '+' . $digits;
}

function app_log_clean(string $value): string
{
    return trim(str_replace(["\r", "\n", "\0"], ' ', $value));
}

function app_log(string $message): void
{
    global $config;

    @file_put_contents(
        (string)$config['paths']['log'],
        '[' . date('Y-m-d H:i:s') . '] ' . app_log_clean($message) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function spam_log(string $message): void
{
    global $config;

    @file_put_contents(
        (string)($config['paths']['spam_log'] ?? (__DIR__ . '/logs/spam.log')),
        '[' . date('Y-m-d H:i:s') . '] ' . app_log_clean($message) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function db(): PDO
{
    global $config;

    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dbPath = (string)$config['paths']['db'];
    $dbDir = dirname($dbPath);

    if (!is_dir($dbDir) && !mkdir($dbDir, 0770, true) && !is_dir($dbDir)) {
        throw new RuntimeException('Cannot create database directory');
    }

    $bootstrapLockPath = $dbPath . '.bootstrap.lock';
    $bootstrapLock = fopen($bootstrapLockPath, 'c');

    if ($bootstrapLock === false) {
        throw new RuntimeException('Cannot open database bootstrap lock');
    }

    if (!flock($bootstrapLock, LOCK_EX)) {
        fclose($bootstrapLock);
        throw new RuntimeException('Cannot lock database bootstrap');
    }

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');

        $currentSchemaVersion = (int)$pdo
            ->query('PRAGMA user_version')
            ->fetchColumn();

        if ($currentSchemaVersion > 5) {
            throw new RuntimeException(
                'Database schema version ' .
                $currentSchemaVersion .
                ' is newer than supported version 5'
            );
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sms (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                modem_index INTEGER DEFAULT NULL,
                direction TEXT NOT NULL CHECK(direction IN ('inbox', 'outbox')),
                phone TEXT NOT NULL,
                sms_date TEXT NOT NULL,
                content TEXT NOT NULL,
                smstat INTEGER DEFAULT NULL,
                deleted_from_modem INTEGER NOT NULL DEFAULT 0,
                notified INTEGER NOT NULL DEFAULT 0,
                source_fingerprint TEXT DEFAULT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $columns = $pdo->query('PRAGMA table_info(sms)')->fetchAll();
        $names = array_column($columns, 'name');

        foreach (
            [
                'deleted_from_modem' => 'INTEGER NOT NULL DEFAULT 0',
                'notified' => 'INTEGER NOT NULL DEFAULT 0',
                'source_fingerprint' => 'TEXT DEFAULT NULL',
            ] as $name => $definition
        ) {
            if (!in_array($name, $names, true)) {
                $pdo->exec('ALTER TABLE sms ADD COLUMN ' . $name . ' ' . $definition);
            }
        }

        /* Huawei переиспользует modem_index после удаления SMS. */
        $pdo->exec('DROP INDEX IF EXISTS ux_sms_modem');
        $pdo->exec("
            CREATE UNIQUE INDEX IF NOT EXISTS ux_sms_source_fingerprint
            ON sms(source_fingerprint)
            WHERE source_fingerprint IS NOT NULL
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS login_attempts (
                attempt_key TEXT PRIMARY KEY,
                ip TEXT NOT NULL,
                user_agent TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                blocked_until INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS app_state (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sms_notification_deliveries (
                source_fingerprint TEXT NOT NULL,
                channel TEXT NOT NULL,
                status TEXT NOT NULL CHECK(status IN ('pending', 'sent', 'failed')),
                attempts INTEGER NOT NULL DEFAULT 0,
                last_error TEXT DEFAULT NULL,
                updated_at TEXT NOT NULL,
                PRIMARY KEY(source_fingerprint, channel)
            )
        ");

        /*
         * Старые версии ограничивали channel только telegram/max. Для Matrix и
         * будущих каналов таблица один раз перестраивается без жёсткого CHECK.
         */
        $deliverySqlStmt = $pdo->prepare("
            SELECT sql
            FROM sqlite_master
            WHERE type = 'table' AND name = 'sms_notification_deliveries'
        ");
        $deliverySqlStmt->execute();
        $deliverySql = (string)($deliverySqlStmt->fetchColumn() ?: '');

        if (preg_match('/CHECK\s*\(\s*channel/i', $deliverySql) === 1) {
            $pdo->beginTransaction();

            try {
                $pdo->exec("
                    CREATE TABLE sms_notification_deliveries_v4 (
                        source_fingerprint TEXT NOT NULL,
                        channel TEXT NOT NULL,
                        status TEXT NOT NULL CHECK(status IN ('pending', 'sent', 'failed')),
                        attempts INTEGER NOT NULL DEFAULT 0,
                        last_error TEXT DEFAULT NULL,
                        updated_at TEXT NOT NULL,
                        PRIMARY KEY(source_fingerprint, channel)
                    )
                ");
                $pdo->exec("
                    INSERT OR REPLACE INTO sms_notification_deliveries_v4(
                        source_fingerprint, channel, status, attempts, last_error, updated_at
                    )
                    SELECT source_fingerprint, channel, status, attempts, last_error, updated_at
                    FROM sms_notification_deliveries
                ");
                $pdo->exec('DROP TABLE sms_notification_deliveries');
                $pdo->exec(
                    'ALTER TABLE sms_notification_deliveries_v4 ' .
                    'RENAME TO sms_notification_deliveries'
                );
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $exception;
            }
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sms_command_history (
                fingerprint TEXT PRIMARY KEY,
                command_type TEXT NOT NULL,
                phone TEXT NOT NULL,
                status TEXT NOT NULL CHECK(status IN ('processing', 'completed', 'queued', 'failed')),
                attempts INTEGER NOT NULL DEFAULT 0,
                last_error TEXT DEFAULT NULL,
                request_id TEXT DEFAULT NULL,
                result_status TEXT DEFAULT NULL,
                reply_sent INTEGER NOT NULL DEFAULT 0,
                reply_text TEXT DEFAULT NULL,
                reply_chunks_json TEXT DEFAULT NULL,
                reply_next_chunk INTEGER NOT NULL DEFAULT 0,
                reply_attempts INTEGER NOT NULL DEFAULT 0,
                reply_last_attempt_at TEXT DEFAULT NULL,
                finished_at TEXT DEFAULT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        ");

        $historyColumns = $pdo->query(
            'PRAGMA table_info(sms_command_history)'
        )->fetchAll();
        $historyNames = array_column($historyColumns, 'name');

        foreach (
            [
                'request_id' => 'TEXT DEFAULT NULL',
                'result_status' => 'TEXT DEFAULT NULL',
                'reply_sent' => 'INTEGER NOT NULL DEFAULT 0',
                'reply_text' => 'TEXT DEFAULT NULL',
                'reply_chunks_json' => 'TEXT DEFAULT NULL',
                'reply_next_chunk' => 'INTEGER NOT NULL DEFAULT 0',
                'reply_attempts' => 'INTEGER NOT NULL DEFAULT 0',
                'reply_last_attempt_at' => 'TEXT DEFAULT NULL',
                'finished_at' => 'TEXT DEFAULT NULL',
            ] as $name => $definition
        ) {
            if (!in_array($name, $historyNames, true)) {
                $pdo->exec(
                    'ALTER TABLE sms_command_history ADD COLUMN ' .
                    $name . ' ' . $definition
                );
            }
        }

        /* Старые завершённые команды не должны внезапно попасть в очередь ответа. */
        $pdo->exec("
            UPDATE sms_command_history
            SET reply_sent = 1,
                finished_at = COALESCE(finished_at, updated_at)
            WHERE status IN ('completed', 'queued', 'failed')
              AND reply_text IS NULL
              AND reply_chunks_json IS NULL
        ");

        $pdo->exec('PRAGMA user_version = 5');

        return $pdo;
    } catch (Throwable $exception) {
        $pdo = null;
        throw $exception;
    } finally {
        flock($bootstrapLock, LOCK_UN);
        fclose($bootstrapLock);
    }
}

function state_get(string $key, string $default = ''): string
{
    $stmt = db()->prepare('SELECT value FROM app_state WHERE key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();

    return $value === false ? $default : (string)$value;
}

function state_set(string $key, string $value): void
{
    $stmt = db()->prepare("
        INSERT INTO app_state(key, value, updated_at)
        VALUES(?, ?, ?)
        ON CONFLICT(key) DO UPDATE SET
            value = excluded.value,
            updated_at = excluded.updated_at
    ");

    $stmt->execute([$key, $value, date('Y-m-d H:i:s')]);
}

function state_delete(string $key): void
{
    db()->prepare('DELETE FROM app_state WHERE key = ?')->execute([$key]);
}

function app_log_throttled(string $key, string $message, int $intervalSeconds): void
{
    $stateKey = 'log_throttle.' . $key;
    $last = (int)state_get($stateKey, '0');
    $now = time();

    if ($last > 0 && ($now - $last) < $intervalSeconds) {
        return;
    }

    state_set($stateKey, (string)$now);
    app_log($message);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['csrf_token'];
}

function csrf_check(): void
{
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        http_response_code(403);
        exit('CSRF check failed');
    }
}

function require_auth(): void
{
    global $config;

    if (empty($_SESSION['auth'])) {
        header('Location: /?login=1');
        exit;
    }

    $now = time();
    $last = (int)($_SESSION['last_activity'] ?? 0);
    $limit = (int)$config['auth']['session_lifetime'];

    if ($last > 0 && ($now - $last) > $limit) {
        $_SESSION = [];
        session_destroy();
        header('Location: /?login=1&timeout=1');
        exit;
    }

    $_SESSION['last_activity'] = $now;
}

function login_attempts_cleanup(): void
{
    global $config;

    $days = max(1, min(365, (int)(
        $config['auth']['login_attempt_retention_days'] ?? 30
    )));

    db()->prepare(
        'DELETE FROM login_attempts WHERE updated_at < ?'
    )->execute([time() - ($days * 86400)]);
}

function login_blocked(string $key): bool
{
    $stmt = db()->prepare('SELECT blocked_until FROM login_attempts WHERE attempt_key = ?');
    $stmt->execute([$key]);

    return (int)($stmt->fetchColumn() ?: 0) > time();
}

function login_failed(string $key, string $ip, string $userAgent): bool
{
    global $config;

    login_attempts_cleanup();

    $pdo = db();
    $now = time();
    $max = max(1, (int)$config['auth']['max_login_attempts']);
    $block = max(1, (int)$config['auth']['login_block_seconds']);

    /*
     * Две параллельные попытки входа не должны терять счётчик.
     * BEGIN IMMEDIATE сериализует короткое чтение-изменение-запись.
     */
    $pdo->exec('BEGIN IMMEDIATE');

    try {
        $stmt = $pdo->prepare(
            'SELECT attempts FROM login_attempts WHERE attempt_key = ?'
        );
        $stmt->execute([$key]);
        $attempts = (int)($stmt->fetchColumn() ?: 0) + 1;
        $blockedUntil = 0;

        if ($attempts >= $max) {
            $blockedUntil = $now + $block;
            $attempts = 0;
        }

        $stmt = $pdo->prepare("
            INSERT INTO login_attempts(
                attempt_key,
                ip,
                user_agent,
                attempts,
                blocked_until,
                updated_at
            )
            VALUES(?, ?, ?, ?, ?, ?)
            ON CONFLICT(attempt_key) DO UPDATE SET
                ip = excluded.ip,
                user_agent = excluded.user_agent,
                attempts = excluded.attempts,
                blocked_until = excluded.blocked_until,
                updated_at = excluded.updated_at
        ");
        $stmt->execute([
            $key,
            $ip,
            $userAgent,
            $attempts,
            $blockedUntil,
            $now,
        ]);

        $pdo->commit();

        return $blockedUntil > 0;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function login_success(string $key): void
{
    login_attempts_cleanup();
    db()->prepare('DELETE FROM login_attempts WHERE attempt_key = ?')->execute([$key]);
    session_regenerate_id(true);
    $_SESSION['auth'] = true;
    $_SESSION['last_activity'] = time();
    csrf_token();
}

function parse_xml(string $xml): SimpleXMLElement
{
    libxml_use_internal_errors(true);
    $parsed = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);

    if (!$parsed) {
        throw new ModemApiException('Bad XML response');
    }

    if ($parsed->getName() === 'error') {
        $code = (string)($parsed->code ?? 'unknown');
        throw new ModemApiException('Modem API error: ' . $code);
    }

    return $parsed;
}

function http_request(string $method, string $url, ?string $body = null, array $headers = []): string
{
    global $config;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => (int)$config['modem']['timeout'],
        CURLOPT_TIMEOUT => (int)$config['modem']['timeout'],
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new ModemApiException($error !== '' ? $error : 'Modem request failed');
    }

    if ($code < 200 || $code >= 300) {
        $error = 'HTTP status ' . $code;
        throw new ModemApiException($error);
    }

    return (string)$response;
}

function modem_auth(): array
{
    global $config;

    $url = rtrim((string)$config['modem']['url'], '/') . '/api/webserver/SesTokInfo';
    $xml = parse_xml(http_request('GET', $url));
    $token = (string)($xml->TokInfo ?? '');
    $cookie = (string)($xml->SesInfo ?? '');

    if ($token === '' || $cookie === '') {
        throw new ModemApiException('Modem token not received');
    }

    return ['token' => $token, 'cookie' => $cookie];
}

function modem_get(string $path): SimpleXMLElement
{
    global $config;

    return parse_xml(http_request('GET', rtrim((string)$config['modem']['url'], '/') . $path));
}

function modem_post(string $path, string $body): SimpleXMLElement
{
    global $config;

    $lockPath = (string)($config['paths']['modem_api_lock'] ?? (__DIR__ . '/data/modem-api.lock'));
    $lock = fopen($lockPath, 'c');

    if ($lock === false) {
        throw new RuntimeException('Cannot open modem API lock');
    }

    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Cannot lock modem API');
        }

        $auth = modem_auth();
        $url = rtrim((string)$config['modem']['url'], '/') . $path;

        return parse_xml(http_request('POST', $url, $body, [
            '__RequestVerificationToken: ' . $auth['token'],
            'Cookie: ' . $auth['cookie'],
            'Content-Type: text/xml',
        ]));
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function modem_reboot(): bool
{
    $xml = modem_post(
        '/api/device/control',
        '<?xml version="1.0" encoding="UTF-8"?>' .
        '<request><Control>1</Control></request>'
    );

    return trim((string)$xml) === 'OK';
}

function sms_list_from_modem(int $boxType): array
{
    $xml = modem_post('/api/sms/sms-list', '<?xml version="1.0" encoding="UTF-8"?>
<request>
<PageIndex>1</PageIndex>
<ReadCount>10</ReadCount>
<BoxType>' . $boxType . '</BoxType>
<SortType>0</SortType>
<Ascending>0</Ascending>
<UnreadPreferred>0</UnreadPreferred>
</request>');

    if (!isset($xml->Messages->Message)) {
        return [];
    }

    $items = [];

    foreach ($xml->Messages->Message as $message) {
        $items[] = [
            'modem_index' => (int)$message->Index,
            'phone' => (string)$message->Phone,
            'content' => html_entity_decode((string)$message->Content, ENT_QUOTES | ENT_XML1, 'UTF-8'),
            'sms_date' => (string)$message->Date,
            'smstat' => (int)$message->Smstat,
        ];
    }

    return $items;
}

function sms_delete_from_modem(int $index): bool
{
    $xml = modem_post('/api/sms/delete-sms', '<?xml version="1.0" encoding="UTF-8"?>
<request><Index>' . $index . '</Index></request>');

    return trim((string)$xml) === 'OK';
}

function sms_send_by_modem(string $phone, string $content): bool
{
    $xml = modem_post('/api/sms/send-sms', '<?xml version="1.0" encoding="UTF-8"?>
<request>
<Index>-1</Index>
<Phones><Phone>' . xml_e($phone) . '</Phone></Phones>
<Sca></Sca>
<Content>' . xml_e($content) . '</Content>
<Length>' . mb_strlen($content, 'UTF-8') . '</Length>
<Reserved>1</Reserved>
<Date>-1</Date>
</request>');

    return trim((string)$xml) === 'OK';
}

function modem_status(): array
{
    $info = modem_get('/api/device/information');
    $signal = modem_get('/api/device/signal');
    $plmn = modem_get('/api/net/current-plmn');

    return [
        'available' => true,
        'operator' => (string)($plmn->FullName ?? ''),
        'network' => trim((string)($info->workmode ?? '')),
        'rsrp' => (string)($signal->rsrp ?? ''),
        'sinr' => (string)($signal->sinr ?? ''),
        'error' => '',
    ];
}

function signal_label(string $rsrp, string $sinr): string
{
    preg_match('/-?\d+/', $rsrp, $rsrpMatch);
    preg_match('/-?\d+/', $sinr, $sinrMatch);
    $rsrpValue = isset($rsrpMatch[0]) ? (int)$rsrpMatch[0] : -999;
    $sinrValue = isset($sinrMatch[0]) ? (int)$sinrMatch[0] : -999;

    if ($rsrpValue >= -90 && $sinrValue >= 10) {
        return 'Сигнал: отличный';
    }
    if ($rsrpValue >= -100 && $sinrValue >= 0) {
        return 'Сигнал: хороший';
    }
    if ($rsrpValue >= -110 && $sinrValue >= -8) {
        return 'Сигнал: средний';
    }

    return 'Сигнал: слабый';
}

function sms_blocklist(): array
{
    $localFile = __DIR__ . '/blocklist.local.php';
    $file = is_file($localFile)
        ? $localFile
        : (__DIR__ . '/blocklist.php');

    if (is_link($file)) {
        throw new RuntimeException('Blocklist must not be a symbolic link');
    }

    $data = is_file($file) ? require $file : [];
    $clean = static fn(array $items): array => array_values(array_filter(array_map(
        static fn(mixed $value): string => mb_strtolower(trim((string)$value), 'UTF-8'),
        $items
    )));

    return [
        'senders' => $clean($data['senders'] ?? []),
        'keywords' => $clean($data['keywords'] ?? []),
        'fragments' => $clean($data['fragments'] ?? []),
    ];
}

function sms_block_reason(array $sms): ?string
{
    $blocklist = sms_blocklist();
    $sender = mb_strtolower(trim((string)($sms['phone'] ?? '')), 'UTF-8');
    $content = mb_strtolower(trim((string)($sms['content'] ?? '')), 'UTF-8');

    foreach ($blocklist['senders'] as $value) {
        if ($value !== '' && str_contains($sender, $value)) {
            return 'sender:' . $value;
        }
    }
    foreach ($blocklist['keywords'] as $value) {
        if ($value !== '' && str_contains($content, $value)) {
            return 'keyword:' . $value;
        }
    }
    foreach ($blocklist['fragments'] as $value) {
        if ($value !== '' && str_contains($content, $value)) {
            return 'fragment:' . $value;
        }
    }

    return null;
}

function sms_log_blocked(array $sms, string $reason, bool $deleted): void
{
    $sender = app_log_clean((string)($sms['phone'] ?? ''));
    $text = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($sms['content'] ?? '')) ?? ''), 0, 200, 'UTF-8');

    spam_log(
        'blocked_sms deleted_from_modem=' . ($deleted ? '1' : '0') .
        ' index=' . (int)($sms['modem_index'] ?? 0) .
        ' sender="' . $sender . '" reason="' . $reason . '" text="' . $text . '"'
    );
}

function sms_source_fingerprint(string $direction, array $sms): string
{
    return hash('sha256', implode('|', [
        $direction,
        preg_replace('/\s+/u', '', (string)($sms['phone'] ?? '')) ?? '',
        trim((string)($sms['sms_date'] ?? '')),
        trim(preg_replace('/\s+/u', ' ', (string)($sms['content'] ?? '')) ?? ''),
    ]));
}

function sms_source_fingerprint_for_archive(string $direction, array $sms): string
{
    $base = sms_source_fingerprint($direction, $sms);
    $index = (int)($sms['modem_index'] ?? 0);

    if ($index <= 0) {
        return $base;
    }

    /*
     * Обычно базового отпечатка достаточно. Но два отдельных SMS могут
     * иметь одинаковые номер, дату с точностью до секунды и текст. Если
     * такой отпечаток уже занят другим индексом Huawei, добавляем индекс
     * только как признак различия, а не как самостоятельный идентификатор.
     */
    $stmt = db()->prepare(
        'SELECT modem_index FROM sms WHERE source_fingerprint = ?'
    );
    $stmt->execute([$base]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (
        $existing === false
        || $existing['modem_index'] === null
        || (int)$existing['modem_index'] === $index
    ) {
        return $base;
    }

    return hash('sha256', $base . '|modem_index=' . $index);
}

function sms_archive_outbound(string $phone, string $content): void
{
    $normalizedPhone = sms_normalize_numeric_phone($phone);

    if ($normalizedPhone !== '') {
        $phone = $normalizedPhone;
    }

    $sms = [
        'phone' => $phone,
        'sms_date' => date('Y-m-d H:i:s'),
        'content' => $content,
    ];

    /*
     * Каждая подтверждённая локальная отправка является отдельным событием,
     * даже если номер, текст и время с точностью до секунды совпали. К базовому
     * отпечатку добавляем случайный локальный признак. Когда копия сообщения
     * появится в папке отправленных Huawei, она будет связана с этой записью
     * по номеру, тексту и времени и получит уже модемный отпечаток.
     */
    $fingerprint = hash(
        'sha256',
        sms_source_fingerprint('outbox', $sms) .
        '|local=' . bin2hex(random_bytes(16))
    );

    db()->prepare("
        INSERT INTO sms(
            modem_index, direction, phone, sms_date, content, smstat,
            deleted_from_modem, notified, source_fingerprint
        ) VALUES(NULL, 'outbox', ?, ?, ?, NULL, 1, 1, ?)
    ")->execute([
        $phone,
        $sms['sms_date'],
        $content,
        $fingerprint,
    ]);
}

function sms_archive_inbox(array $sms, bool $notified = false): array
{
    $fingerprint = sms_source_fingerprint_for_archive('inbox', $sms);

    $stmt = db()->prepare("
        INSERT OR IGNORE INTO sms(
            modem_index,
            direction,
            phone,
            sms_date,
            content,
            smstat,
            deleted_from_modem,
            notified,
            source_fingerprint
        )
        VALUES(?, 'inbox', ?, ?, ?, ?, 0, ?, ?)
    ");
    $stmt->execute([
        (int)($sms['modem_index'] ?? 0),
        (string)($sms['phone'] ?? ''),
        (string)($sms['sms_date'] ?? ''),
        (string)($sms['content'] ?? ''),
        $sms['smstat'] ?? null,
        $notified ? 1 : 0,
        $fingerprint,
    ]);

    if ($notified) {
        db()->prepare(
            'UPDATE sms SET notified = 1 WHERE source_fingerprint = ?'
        )->execute([$fingerprint]);
    }

    return [
        'fingerprint' => $fingerprint,
        'inserted' => $stmt->rowCount() > 0,
    ];
}

function sms_archive_modem_outbox(array $sms): string
{
    $normalizedPhone = sms_normalize_numeric_phone(
        (string)($sms['phone'] ?? '')
    );

    if ($normalizedPhone !== '') {
        $sms['phone'] = $normalizedPhone;
    }

    $fingerprint = sms_source_fingerprint_for_archive(
        'outbox',
        $sms
    );

    $pdo = db();

    $existing = $pdo->prepare(
        'SELECT id, modem_index FROM sms WHERE source_fingerprint = ?'
    );
    $existing->execute([$fingerprint]);
    $existingRow = $existing->fetch(PDO::FETCH_ASSOC);

    if ($existingRow !== false) {
        /*
         * Совместимость с записями, созданными локально более старой версией:
         * у них базовый отпечаток уже мог существовать без modem_index.
         * При появлении копии в Huawei связываем индекс с этой записью.
         */
        if (
            $existingRow['modem_index'] === null
            && (int)($sms['modem_index'] ?? 0) > 0
        ) {
            $pdo->prepare("
                UPDATE sms
                SET modem_index = ?,
                    sms_date = ?,
                    smstat = ?,
                    deleted_from_modem = 0
                WHERE id = ?
            ")->execute([
                (int)$sms['modem_index'],
                (string)($sms['sms_date'] ?? ''),
                $sms['smstat'] ?? null,
                (int)$existingRow['id'],
            ]);
        }

        return $fingerprint;
    }

    /*
     * Сообщение, отправленное через веб-интерфейс или как ответ на
     * SMS-команду, сохраняется локально сразу. Когда его копия появляется
     * в папке отправленных Huawei, связываем её с уже существующей записью,
     * а не создаём дубль.
     *
     * Если одинаковый текст отправлялся несколько раз подряд, каждая уже
     * сопоставленная запись получает modem_index и больше не участвует
     * в следующем поиске.
     */
    $match = $pdo->prepare("
        SELECT id
        FROM sms
        WHERE direction = 'outbox'
          AND modem_index IS NULL
          AND phone = ?
          AND content = ?
          AND ABS(
              strftime('%s', sms_date)
              - strftime('%s', ?)
          ) <= 120
        ORDER BY id DESC
        LIMIT 1
    ");
    $match->execute([
        (string)($sms['phone'] ?? ''),
        (string)($sms['content'] ?? ''),
        (string)($sms['sms_date'] ?? ''),
    ]);

    $localId = $match->fetchColumn();

    if ($localId !== false) {
        $pdo->prepare("
            UPDATE sms
            SET modem_index = ?,
                sms_date = ?,
                smstat = ?,
                deleted_from_modem = 0,
                source_fingerprint = ?
            WHERE id = ?
        ")->execute([
            (int)($sms['modem_index'] ?? 0),
            (string)($sms['sms_date'] ?? ''),
            $sms['smstat'] ?? null,
            $fingerprint,
            (int)$localId,
        ]);

        return $fingerprint;
    }

    $pdo->prepare("
        INSERT OR IGNORE INTO sms(
            modem_index,
            direction,
            phone,
            sms_date,
            content,
            smstat,
            deleted_from_modem,
            notified,
            source_fingerprint
        )
        VALUES(?, 'outbox', ?, ?, ?, ?, 0, 1, ?)
    ")->execute([
        (int)($sms['modem_index'] ?? 0),
        (string)($sms['phone'] ?? ''),
        (string)($sms['sms_date'] ?? ''),
        (string)($sms['content'] ?? ''),
        $sms['smstat'] ?? null,
        $fingerprint,
    ]);

    return $fingerprint;
}

function sms_delete_safe(array $sms): bool
{
    $index = (int)($sms['modem_index'] ?? 0);

    if ($index <= 0) {
        return true;
    }

    try {
        return sms_delete_from_modem($index);
    } catch (Throwable $exception) {
        app_log(
            'ERROR sms_delete_failed index=' . $index .
            ' error="' . app_log_clean($exception->getMessage()) . '"'
        );
        return false;
    }
}

function sync_sms_from_modem(bool $notify = true): int
{
    global $config;

    require_once __DIR__ . '/notify.php';
    require_once __DIR__ . '/sms_commands.php';

    $lockPath = (string)($config['paths']['sync_lock'] ?? (__DIR__ . '/data/sync.lock'));
    $lock = fopen($lockPath, 'c');

    if ($lock === false) {
        throw new RuntimeException('Cannot open sync lock');
    }

    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        throw new SyncBusyException('Синхронизация уже выполняется');
    }

    try {
        $pdo = db();
        $added = 0;
        $blocked = 0;
        $deleted = 0;

        /*
         * Ответы уже выполненных команд повторяются отдельно от самой
         * команды. Скрипт повторно не запускается.
         */
        sms_cmd_retry_pending_replies();

        foreach ([1 => 'inbox', 2 => 'outbox'] as $boxType => $direction) {
            /*
             * Huawei отдаёт SMS страницами. После каждой обработанной порции
             * снова читаем первую страницу: удалённые сообщения освобождают
             * место, поэтому за один poll память модема очищается полностью.
             *
             * Если ни одно сообщение из порции удалить не удалось, выходим
             * из цикла, чтобы не зациклиться на одной и той же записи.
             */
            for ($batch = 0; $batch < 1000; $batch++) {
                $modemMessages = sms_list_from_modem($boxType);

                if ($modemMessages === []) {
                    break;
                }

                $deletedBeforeBatch = $deleted;

                foreach ($modemMessages as $sms) {
                    if ($direction === 'outbox') {
                        $fingerprint = sms_archive_modem_outbox($sms);

                        if (sms_delete_safe($sms)) {
                            $deleted++;
                            $pdo->prepare(
                                'UPDATE sms SET deleted_from_modem = 1 ' .
                                'WHERE source_fingerprint = ?'
                            )->execute([$fingerprint]);
                        }

                        continue;
                    }

                    $commandResult = sms_cmd_try_handle($sms);

                    if ($commandResult['handled']) {
                        /*
                         * Командное SMS тоже остаётся в общей истории сообщений.
                         * Обычное уведомление "новое SMS" для него не требуется:
                         * результат команды отправляется отдельным ответом.
                         */
                        $archived = sms_archive_inbox($sms, true);
                        $fingerprint = (string)$archived['fingerprint'];

                        if (
                            $commandResult['delete']
                            && sms_delete_safe($sms)
                        ) {
                            $deleted++;
                            $pdo->prepare(
                                'UPDATE sms SET deleted_from_modem = 1 ' .
                                'WHERE source_fingerprint = ?'
                            )->execute([$fingerprint]);
                        }

                        continue;
                    }

                    $reason = sms_block_reason($sms);

                    if ($reason !== null) {
                        $wasDeleted = sms_delete_safe($sms);
                        sms_log_blocked($sms, $reason, $wasDeleted);
                        $blocked++;

                        if ($wasDeleted) {
                            $deleted++;
                        }

                        continue;
                    }

                    $skipNotification =
                        !$notify
                        || empty($config['notifications']['enabled']);

                    $archived = sms_archive_inbox(
                        $sms,
                        $skipNotification
                    );
                    $fingerprint = (string)$archived['fingerprint'];
                    $inserted = (bool)$archived['inserted'];
                    $wasDeleted = sms_delete_safe($sms);

                    if ($wasDeleted) {
                        $deleted++;
                        $pdo->prepare(
                            'UPDATE sms SET deleted_from_modem = 1 ' .
                            'WHERE source_fingerprint = ?'
                        )->execute([$fingerprint]);
                    }

                    if ($inserted) {
                        $added++;
                    }
                }

                if ($deleted === $deletedBeforeBatch) {
                    app_log(
                        'WARN modem_sms_cleanup_stalled box=' .
                        $direction .
                        ' count=' .
                        count($modemMessages)
                    );
                    break;
                }
            }
        }

        if ($blocked > 0) {
            app_log('INFO blocked_sms_batch count=' . $blocked);
        }
        if ($deleted > 0) {
            app_log('INFO modem_sms_cleaned count=' . $deleted);
        }

        if ($notify && !empty($config['notifications']['enabled'])) {
            $pending = $pdo->query("
                SELECT phone, sms_date, content, source_fingerprint
                FROM sms
                WHERE direction = 'inbox' AND notified = 0
                ORDER BY id ASC
                LIMIT 50
            ")->fetchAll();

            if ($pending !== []) {
                app_log(
                    'INFO pending_sms_notifications count=' . count($pending)
                );

                if (!sms_notify_batch($pending)) {
                    app_log(
                        'ERROR sms_notification_batch_failed count=' .
                        count($pending)
                    );
                }
            }
        }

        return $added;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

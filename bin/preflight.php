#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../sms_commands.php';
require_once __DIR__ . '/../modem_monitor.php';

$errors = [];
$warnings = [];

$effectiveUid = sms_script_command_effective_uid();

if ($effectiveUid === null) {
    fwrite(
        STDERR,
        "PREFLIGHT: не удалось определить UID текущего процесса. " .
        "Проверка прав внешних SMS-команд невозможна.\n"
    );
    exit(2);
}

if ($effectiveUid === 0) {
    fwrite(
        STDERR,
        "PREFLIGHT: не запускайте эту проверку от root. " .
        "Запустите её от пользователя, под которым работают PHP-FPM " .
        "и cron/poll.php, чтобы рабочие файлы не создавались с владельцем root.\n"
    );
    exit(2);
}

foreach (['curl', 'mbstring', 'pdo_sqlite', 'SimpleXML'] as $extension) {
    if (!extension_loaded($extension)) {
        $errors[] = 'Не загружено PHP-расширение: ' . $extension;
    }
}

foreach (['proc_open', 'curl_init', 'simplexml_load_string'] as $function) {
    if (!function_exists($function)) {
        $errors[] = 'Недоступна PHP-функция: ' . $function;
    }
}

foreach (
    [
        '/usr/bin/python3' => 'Python 3',
        '/bin/bash' => 'Bash',
    ] as $path => $label
) {
    if (!is_executable($path)) {
        $errors[] = $label . ' недоступен: ' . $path;
    }
}

if (!is_executable('/usr/bin/ping') && !is_executable('/bin/ping')) {
    $errors[] = 'Не найдена утилита ping';
}

if (!is_executable('/usr/bin/curl') && !is_executable('/bin/curl')) {
    $errors[] = 'Не найдена CLI-утилита curl для scripts/modem-health.sh';
}

if (
    !function_exists('posix_kill')
    && !is_executable('/usr/bin/kill')
    && !is_executable('/bin/kill')
) {
    $errors[] = 'Нечем завершать группу дочерних процессов';
}

foreach (['data', 'logs'] as $directory) {
    $path = dirname(__DIR__) . '/' . $directory;

    if (!is_dir($path) || !is_writable($path)) {
        $errors[] = 'Каталог недоступен на запись: ' . $path;
    }
}

if (!empty($config['auth']['enabled'])) {
    if (trim((string)($config['auth']['username'] ?? '')) === '') {
        $errors[] = 'auth.username пуст';
    }

    $passwordHash = trim((string)($config['auth']['password_hash'] ?? ''));

    if ($passwordHash === '') {
        $errors[] = 'auth.password_hash пуст';
    } elseif ((password_get_info($passwordHash)['algoName'] ?? 'unknown') === 'unknown') {
        $errors[] = 'auth.password_hash не является результатом password_hash()';
    }

    if (!empty($config['totp']['enabled'])) {
        $totpSecret = trim((string)($config['totp']['secret'] ?? ''));

        if ($totpSecret === '') {
            $errors[] = 'TOTP включён, но totp.secret пуст';
        } elseif (
            preg_match('/\A[A-Z2-7]+\z/i', $totpSecret) !== 1
            || strlen($totpSecret) < 16
        ) {
            $errors[] = 'totp.secret должен быть корректной строкой Base32 длиной не меньше 16 символов';
        }
    }
} else {
    $warnings[] = 'Веб-авторизация отключена';
}

$trustedPhones = (array)($config['sms_commands']['trusted_phones'] ?? []);

if (!empty($config['sms_commands']['enabled']) && $trustedPhones === []) {
    $warnings[] = 'Не настроены доверенные номера SMS-команд';
} else {
    foreach ($trustedPhones as $phone) {
        if (sms_cmd_normalize_phone((string)$phone) === '') {
            $errors[] = 'В trusted_phones найден номер в недопустимом формате';
            break;
        }
    }
}

$baseUrl = trim((string)($config['app']['base_url'] ?? ''));

if (str_starts_with($baseUrl, 'http://')) {
    $warnings[] = 'Web-интерфейс использует HTTP: ограничь доступ сетью или reverse proxy';
}

$enabledChannels = [];

foreach (['telegram', 'matrix', 'max'] as $channelName) {
    $channel = (array)($config['notifications'][$channelName] ?? []);

    if (empty($channel['enabled'])) {
        continue;
    }

    $enabledChannels[] = $channelName;

    if ($channelName === 'telegram') {
        $token = notify_read_secret($channel, 'bot_token', 'bot_token_file');

        if ($token === '' || trim((string)($channel['chat_id'] ?? '')) === '') {
            $errors[] = 'Telegram включён, но token/chat_id не настроены';
        }
    }

    if ($channelName === 'matrix') {
        $token = notify_read_secret($channel, 'access_token', 'access_token_file');

        if (
            $token === ''
            || trim((string)($channel['homeserver'] ?? '')) === ''
            || trim((string)($channel['room_id'] ?? '')) === ''
        ) {
            $errors[] = 'Matrix включён, но homeserver/room_id/access_token не настроены';
        }
    }

    if ($channelName === 'max') {
        $token = notify_read_secret($channel, 'token', 'token_file');

        if ($token === '' || trim((string)($channel['chat_id'] ?? '')) === '') {
            $errors[] = 'MAX включён, но token/chat_id не настроены';
        }
    }

    if (in_array($channelName, ['telegram', 'max'], true)) {
        $proxy = (array)($channel['proxy'] ?? []);

        if (!empty($proxy['enabled'])) {
            $proxyUrl = trim((string)($proxy['url'] ?? ''));
            $proxyType = strtolower(trim((string)($proxy['type'] ?? 'http')));

            if (
                $proxyUrl === ''
                || preg_match('/\A(?:https?|socks4a?|socks5h?):\/\/\S+\z/i', $proxyUrl) !== 1
            ) {
                $errors[] = strtoupper($channelName) . ': некорректный proxy.url';
            }

            if (!in_array(
                $proxyType,
                ['http', 'https', 'socks4', 'socks4a', 'socks5', 'socks5h'],
                true
            )) {
                $errors[] = strtoupper($channelName) . ': неподдерживаемый proxy.type';
            } else {
                try {
                    notify_proxy_type($proxyType);
                } catch (Throwable $exception) {
                    $errors[] =
                        strtoupper($channelName) .
                        ': выбранный тип proxy не поддерживается этой сборкой cURL: ' .
                        $exception->getMessage();
                }
            }
        }
    }
}

if (!empty($config['notifications']['enabled']) && $enabledChannels === []) {
    $errors[] = 'notifications.enabled=true, но ни один канал не включён';
}

$monitor = (array)($config['modem']['monitor'] ?? []);

if (!empty($monitor['enabled'])) {
    if ((int)($monitor['failure_threshold'] ?? 0) < 1) {
        $errors[] = 'modem.monitor.failure_threshold должен быть не меньше 1';
    }

    if ((int)($monitor['recovery_threshold'] ?? 0) < 1) {
        $errors[] = 'modem.monitor.recovery_threshold должен быть не меньше 1';
    }
}

$autoReboot = (array)($config['modem']['auto_reboot'] ?? []);

if (!empty($autoReboot['enabled'])) {
    $time = trim((string)($autoReboot['time'] ?? ''));

    if (preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $time) !== 1) {
        $errors[] = 'modem.auto_reboot.time должен быть в формате HH:MM';
    }

    foreach ((array)($autoReboot['days_of_week'] ?? []) as $day) {
        if ((int)$day < 1 || (int)$day > 7) {
            $errors[] = 'modem.auto_reboot.days_of_week допускает только 1–7';
            break;
        }
    }
}

try {
    $pdo = db();
    $check = (string)$pdo->query('PRAGMA quick_check')->fetchColumn();

    if ($check !== 'ok') {
        $errors[] = 'SQLite quick_check: ' . $check;
    } else {
        echo "DB: OK\n";
    }

    echo 'SCHEMA: ' . (int)$pdo->query('PRAGMA user_version')->fetchColumn() . PHP_EOL;
} catch (Throwable $exception) {
    $errors[] = 'DB: ' . $exception->getMessage();
}

try {
    $registry = sms_script_command_registry();
    $seen = [];

    foreach ($registry as $registered) {
        $name = (string)$registered['canonical_name'];

        if (isset($seen[$name])) {
            continue;
        }

        $seen[$name] = true;
        $entry = (array)$registered['entry'];

        if (empty($entry['enabled'])) {
            continue;
        }

        sms_script_command_resolve_runtime($entry, true);
    }

    echo 'COMMANDS: OK (' . count($seen) . ")\n";
} catch (Throwable $exception) {
    $errors[] = 'Реестр команд: ' . $exception->getMessage();
}

if (!sms_script_command_audit_available()) {
    $errors[] = 'Недоступен logs/commands.log';
}

try {
    $status = modem_status();
    echo 'MODEM: OK ' .
        ($status['operator'] ?: '-') . ' ' .
        ($status['network'] ?: '-') . PHP_EOL;
} catch (Throwable $exception) {
    $warnings[] = 'Модем недоступен: ' . $exception->getMessage();
}

if ($enabledChannels !== []) {
    echo 'CHANNELS: ' . implode(', ', $enabledChannels) . PHP_EOL;
}

if ($warnings !== []) {
    echo "\nПРЕДУПРЕЖДЕНИЯ:\n";

    foreach ($warnings as $warning) {
        echo '- ' . $warning . PHP_EOL;
    }
}

if ($errors !== []) {
    echo "\nОШИБКИ:\n";

    foreach ($errors as $error) {
        echo '- ' . $error . PHP_EOL;
    }

    exit(1);
}

echo "\nPREFLIGHT: OK\n";

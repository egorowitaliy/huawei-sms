<?php

declare(strict_types=1);

$defaults = [
    'app' => [
        'name' => 'Huawei SMS',
        'base_url' => 'http://127.0.0.1',
        'timezone' => 'Europe/Moscow',
        'force_secure_cookie' => false,
        'trusted_proxies' => [],
    ],

    'auth' => [
        'enabled' => true,
        'username' => '',
        'password_hash' => '',
        'session_lifetime' => 900,
        'max_login_attempts' => 5,
        'login_block_seconds' => 900,
        'login_attempt_retention_days' => 30,

        'quick_login' => [
            'enabled' => false,
            'token_ttl_seconds' => 86400,
            'channels' => [
                'telegram' => false,
                'matrix' => false,
                'max' => false,
            ],
        ],
    ],

    'totp' => [
        'enabled' => false,
        'secret' => '',
        'emergency_bypass' => false,
    ],

    'modem' => [
        'url' => 'http://192.168.8.1',
        'timeout' => 8,

        /*
         * Состояние модема меняет только cron/poll.php. Запрос статуса из
         * веб-интерфейса и команда «Модем» не влияют на счётчики отказов.
         */
        'monitor' => [
            'enabled' => true,
            'failure_threshold' => 3,
            'recovery_threshold' => 2,
            'notification_retry_seconds' => 300,
            'offline_log_interval' => 1800,
            'maintenance_log_interval' => 300,
            'include_error_in_notification' => true,
        ],

        /*
         * Плановая перезагрузка запускается из cron/poll.php после успешного
         * опроса SMS. Дни недели: 1 — понедельник, 7 — воскресенье.
         * Пустой список означает каждый день.
         */
        'auto_reboot' => [
            'enabled' => false,
            'time' => '04:10',
            'days_of_week' => [7],
            'window_minutes' => 15,
            'maintenance_seconds' => 600,
            'notify_started' => true,
            'notify_completed' => true,
        ],
    ],

    'paths' => [
        'db' => __DIR__ . '/data/sms.sqlite',
        'log' => __DIR__ . '/logs/sms.log',
        'spam_log' => __DIR__ . '/logs/spam.log',
        'sync_lock' => __DIR__ . '/data/sync.lock',
        'modem_api_lock' => __DIR__ . '/data/modem-api.lock',
        'scripts_dir' => __DIR__ . '/scripts',
        'script_roots' => [
            __DIR__ . '/scripts',
            __DIR__ . '/scripts.local',
        ],
        'command_runner' => __DIR__ . '/bin/command-runner.py',
        'command_log' => __DIR__ . '/logs/commands.log',
        'script_command_lock' => __DIR__ . '/data/script-commands.lock',
    ],

    'sms_commands' => [
        'enabled' => true,
        'trusted_phones' => [],
        'help_commands' => ['помощь', 'команды', 'help'],
        'reply_chunk_chars' => 420,
        'reply_retry_max_attempts' => 10,
        'reply_retry_interval_seconds' => 60,
        'processing_stale_seconds' => 300,

        /*
         * В комплекте только универсальная сетевая проверка и диагностика
         * Huawei. commands.local.php дополняет этот список; локальная запись
         * с тем же script_command заменяет только соответствующую штатную.
         */
        'script_commands' => [
            [
                'script_command' => 'network',
                'aliases' => ['сеть', 'ping', 'пинг', 'nc', 'port', 'порт'],
                'alias_prefix_args' => [
                    'ping' => ['ping'],
                    'пинг' => ['ping'],
                    'nc' => ['port'],
                    'port' => ['port'],
                    'порт' => ['port'],
                ],
                'argument_aliases' => [
                    0 => [
                        'ping' => ['ping', 'пинг'],
                        'port' => ['port', 'порт', 'nc'],
                    ],
                ],
                'argument_patterns' => [
                    0 => '/\A(?:ping|port)\z/',
                    1 => '/\A[a-z0-9][a-z0-9.-]{0,252}\z/i',
                    2 => '/\A[0-9]{1,5}\z/',
                ],
                'description' => 'разовая проверка ICMP или TCP-порта',
                'usage' => "Пинг <IPv4 или имя>\nПорт <IPv4 или имя> <1–65535>",
                'script_path' => __DIR__ . '/scripts/network-check.py',
                'type' => 'python3',
                'enabled' => true,
                'timeout' => 12,
                'min_args' => 2,
                'max_args' => 3,
                'max_arg_chars' => 253,
                'max_total_arg_bytes' => 1024,
                'max_output_bytes' => 4096,
                'max_reply_chars' => 700,
                'log_arguments' => true,
                'log_output' => true,
            ],
            [
                'script_command' => 'modem',
                'aliases' => ['модем', 'lte'],
                'description' => 'состояние LTE-модема Huawei',
                'usage' => 'Модем',
                'script_path' => __DIR__ . '/scripts/modem-health.sh',
                'type' => 'bash',
                'enabled' => true,
                'timeout' => 20,
                'min_args' => 0,
                'max_args' => 0,
                'max_arg_chars' => 1,
                'max_output_bytes' => 4096,
                'max_reply_chars' => 700,
                'log_arguments' => false,
                'log_output' => true,
            ],
        ],
    ],

    'notifications' => [
        'enabled' => false,
        'connect_timeout' => 5,
        'timeout' => 15,

        'auth' => [
            'login_success' => false,
            'login_failed' => false,
            'login_blocked' => false,
            'csrf_failed' => false,
            'csrf_notification_interval_seconds' => 300,
        ],

        'modem' => [
            'offline' => true,
            'online' => true,
            'auto_reboot_started' => true,
            'auto_reboot_completed' => true,
        ],

        'telegram' => [
            'enabled' => false,
            'bot_token' => '',
            'bot_token_file' => '',
            'chat_id' => '',
            'proxy' => [
                'enabled' => false,
                'url' => '',
                'username' => '',
                'password' => '',
                'type' => 'http',
                'fallback_direct' => false,
            ],
        ],

        'matrix' => [
            'enabled' => false,
            'homeserver' => '',
            'room_id' => '',
            'access_token' => '',
            'access_token_file' => '',
        ],

        'max' => [
            'enabled' => false,
            'api_url' => 'https://platform-api2.max.ru/messages',
            'token' => '',
            'token_file' => '',
            'chat_id' => '',
            'proxy' => [
                'enabled' => false,
                'url' => '',
                'username' => '',
                'password' => '',
                'type' => 'http',
                'fallback_direct' => false,
            ],
        ],
    ],
];

$localFile = __DIR__ . '/config.local.php';
$local = is_file($localFile) ? require $localFile : [];

if (!is_array($local)) {
    throw new RuntimeException('config.local.php must return an array');
}

$config = array_replace_recursive($defaults, $local);

/*
 * commands.local.php дополняет комплектный набор команд.
 * Если локальная запись использует тот же script_command, что и штатная,
 * она заменяет именно эту запись. Такой порядок сохраняет совместимость со
 * старыми локальными файлами, в которых Пинг/Порт и Модем были скопированы.
 */
$commandsFile = __DIR__ . '/commands.local.php';

if (is_file($commandsFile)) {
    $commands = require $commandsFile;

    if (!is_array($commands)) {
        throw new RuntimeException('commands.local.php must return an array');
    }

    $mergedCommands = array_values(
        (array)$config['sms_commands']['script_commands']
    );

    foreach ($commands as $localCommand) {
        if (!is_array($localCommand)) {
            continue;
        }

        $localName = strtolower(trim(
            (string)($localCommand['script_command'] ?? '')
        ));
        $replaced = false;

        if ($localName !== '') {
            foreach ($mergedCommands as $index => $defaultCommand) {
                if (!is_array($defaultCommand)) {
                    continue;
                }

                $defaultName = strtolower(trim(
                    (string)($defaultCommand['script_command'] ?? '')
                ));

                if ($defaultName === $localName) {
                    $mergedCommands[$index] = $localCommand;
                    $replaced = true;
                    break;
                }
            }
        }

        if (!$replaced) {
            $mergedCommands[] = $localCommand;
        }
    }

    $config['sms_commands']['script_commands'] = array_values($mergedCommands);
}

return $config;

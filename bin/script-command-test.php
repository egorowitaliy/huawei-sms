#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../init.php';
require_once __DIR__ . '/../sms_commands.php';

if ($argc < 2) {
    fwrite(
        STDERR,
        "Использование:\n" .
        "  php script-command-test.php пинг 192.168.1.1\n" .
        "  php script-command-test.php порт 192.168.1.1 443\n" .
        "  php script-command-test.php модем\n"
    );
    exit(2);
}

$text = implode(' ', array_slice($argv, 1));
$result = sms_script_command_dispatch(
    $text,
    '+70000000000',
    hash('sha256', 'console|' . $text . '|' . microtime(true))
);

if (empty($result['matched'])) {
    fwrite(STDERR, "Команда не найдена в конфигурации\n");
    exit(3);
}

echo (string)$result['reply'], PHP_EOL;
exit(!empty($result['ok']) ? 0 : 1);

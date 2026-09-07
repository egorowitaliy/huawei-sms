<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../quick_login.php';

$channel = strtolower(
    trim((string)($argv[1] ?? 'telegram'))
);

$token = quick_login_create_token($channel);

if ($token === null) {
    fwrite(
        STDERR,
        "Быстрый вход для канала {$channel} выключен\n"
    );

    exit(1);
}

$baseUrl = rtrim(
    (string)$config['app']['base_url'],
    '/'
);

echo
    $baseUrl .
    '/#access=' .
    $token .
    PHP_EOL;

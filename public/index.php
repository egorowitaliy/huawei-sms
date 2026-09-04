<?php
declare(strict_types=1);

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../app.php';

session_start();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

try {
    db();
} catch (Throwable $e) {
    app_log('db error: ' . $e->getMessage());
    http_response_code(500);
    exit('DB error');
}

auth_bootstrap();
app_run();
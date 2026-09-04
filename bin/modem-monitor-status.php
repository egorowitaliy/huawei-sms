#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../modem_monitor.php';

$snapshot = modem_monitor_snapshot();

echo json_encode(
    $snapshot,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
), PHP_EOL;

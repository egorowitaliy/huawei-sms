#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../init.php';

$pdo = db();
$version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
$check = (string)$pdo->query('PRAGMA quick_check')->fetchColumn();

echo "Database ready\n";
echo "Path: " . $config['paths']['db'] . "\n";
echo "Quick check: {$check}\n";
echo "Schema marker: {$version}\n";

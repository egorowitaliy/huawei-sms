#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../notify.php';

$channel = strtolower(trim((string)($argv[1] ?? 'all')));
$appName = trim((string)($config['app']['name'] ?? 'Huawei SMS'));
$text =
    "🔔 " . $appName . "\n\n" .
    "✅ Тестовое уведомление\n" .
    "🕒 Время: " . date('d.m.Y H:i:s');

if (!in_array($channel, ['all', 'telegram', 'matrix', 'max'], true)) {
    fwrite(
        STDERR,
        "Использование: test-notifications.php all|telegram|matrix|max\n"
    );
    exit(2);
}

if (empty($config['notifications']['enabled'])) {
    fwrite(STDERR, "Уведомления отключены: notifications.enabled=false\n");
    exit(2);
}

if ($channel === 'all') {
    $enabled = notify_enabled_channels();

    if ($enabled === []) {
        fwrite(STDERR, "Не включён ни один канал уведомлений\n");
        exit(2);
    }

    $result = notify_send($text, 'test');
} else {
    if (empty($config['notifications'][$channel]['enabled'])) {
        fwrite(
            STDERR,
            strtoupper($channel) . " отключён в конфигурации\n"
        );
        exit(2);
    }

    $result = notify_send_channel($channel, $text);
}

if (!$result) {
    fwrite(STDERR, "Ошибка отправки; смотри logs/sms.log\n");
    exit(1);
}

echo "Уведомление отправлено: {$channel}\n";

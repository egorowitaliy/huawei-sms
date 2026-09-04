<?php

declare(strict_types=1);

require_once __DIR__ . '/notify.php';

function modem_monitor_config(): array
{
    global $config;

    return (array)($config['modem']['monitor'] ?? []);
}

function modem_auto_reboot_config(): array
{
    global $config;

    return (array)($config['modem']['auto_reboot'] ?? []);
}

function modem_monitor_event_enabled(string $event): bool
{
    global $config;

    if (empty($config['notifications']['enabled'])) {
        return false;
    }

    /*
     * Ошибка плановой перезагрузки является итогом той же операции,
     * поэтому управляется тем же флагом, что и уведомление о завершении.
     */
    $configEvent = $event === 'auto_reboot_failed'
        ? 'auto_reboot_completed'
        : $event;

    return !empty(
        $config['notifications']['modem'][$configEvent]
    );
}

function modem_monitor_event_state_key(string $event): string
{
    return 'modem_notification_delivery_' . $event;
}

function modem_monitor_event_delivery_load(string $event): ?array
{
    $raw = trim(
        state_get(
            modem_monitor_event_state_key($event),
            ''
        )
    );

    if ($raw === '') {
        return null;
    }

    try {
        $decoded = json_decode(
            $raw,
            true,
            32,
            JSON_THROW_ON_ERROR
        );
    } catch (Throwable) {
        return null;
    }

    return is_array($decoded)
        ? $decoded
        : null;
}

function modem_monitor_event_delivery_save(
    string $event,
    array $delivery
): void {
    state_set(
        modem_monitor_event_state_key($event),
        json_encode(
            $delivery,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        )
    );
}

function modem_monitor_event_delivery_clear(string $event): void
{
    state_delete(
        modem_monitor_event_state_key($event)
    );
}

function modem_monitor_send_event(
    string $event,
    string $text,
    string $eventId = ''
): bool {
    if (!modem_monitor_event_enabled($event)) {
        modem_monitor_event_delivery_clear($event);
        return true;
    }

    $channels = notify_enabled_channels();

    if ($channels === []) {
        return false;
    }

    if ($eventId === '') {
        $eventId = hash(
            'sha256',
            $event . '|' . $text
        );
    }

    $delivery = modem_monitor_event_delivery_load($event);

    if (
        $delivery === null
        || (string)($delivery['id'] ?? '') !== $eventId
    ) {
        $delivery = [
            'id' => $eventId,
            'text' => $text,
            'channels' => [],
        ];
    } else {
        $delivery['text'] = $text;
    }

    foreach ($channels as $channel) {
        if (!isset($delivery['channels'][$channel])) {
            $delivery['channels'][$channel] = [
                'sent' => false,
                'last_attempt' => 0,
            ];
        }
    }

    $monitor = modem_monitor_config();
    $retrySeconds = max(
        60,
        (int)($monitor['notification_retry_seconds'] ?? 300)
    );
    $now = time();
    $dispatchId = notify_make_dispatch_id();

    foreach ($channels as $channel) {
        $channelState = (array)(
            $delivery['channels'][$channel]
            ?? []
        );

        if (!empty($channelState['sent'])) {
            continue;
        }

        $lastAttempt = (int)(
            $channelState['last_attempt']
            ?? 0
        );

        if (
            $lastAttempt > 0
            && ($now - $lastAttempt) < $retrySeconds
        ) {
            continue;
        }

        $delivery['channels'][$channel]['last_attempt'] =
            $now;

        $hadPreviousContext =
            array_key_exists(
                'notify_log_context',
                $GLOBALS
            );

        $previousContext =
            $GLOBALS['notify_log_context']
            ?? null;

        $GLOBALS['notify_log_context'] = [
            'dispatch_id' => $dispatchId,
            'event' => $event,
            'message_sha256' => hash('sha256', $text),
            'message_bytes' => strlen($text),
        ];

        try {
            $sent = notify_send_channel(
                $channel,
                $text
            );
        } finally {
            if ($hadPreviousContext) {
                $GLOBALS['notify_log_context'] =
                    $previousContext;
            } else {
                unset(
                    $GLOBALS['notify_log_context']
                );
            }
        }

        if ($sent) {
            $delivery['channels'][$channel]['sent'] =
                true;
        }
    }

    $allSent = true;

    foreach ($channels as $channel) {
        if (
            empty(
                $delivery['channels'][$channel]['sent']
            )
        ) {
            $allSent = false;
            break;
        }
    }

    if ($allSent) {
        modem_monitor_event_delivery_clear($event);
    } else {
        modem_monitor_event_delivery_save(
            $event,
            $delivery
        );
    }

    return $allSent;
}

function modem_monitor_retry_pending_events(): void
{
    foreach (
        [
            'offline',
            'online',
            'auto_reboot_started',
            'auto_reboot_completed',
            'auto_reboot_failed',
        ] as $event
    ) {
        $delivery =
            modem_monitor_event_delivery_load(
                $event
            );

        if ($delivery === null) {
            continue;
        }

        $text = (string)(
            $delivery['text']
            ?? ''
        );

        $eventId = (string)(
            $delivery['id']
            ?? ''
        );

        if ($text === '' || $eventId === '') {
            modem_monitor_event_delivery_clear(
                $event
            );
            continue;
        }

        $sent = modem_monitor_send_event(
            $event,
            $text,
            $eventId
        );

        /*
         * Старые pending-флаги ещё используются основным автоматом
         * состояний. Если отложенная доставка завершилась в начале
         * следующего poll, синхронизируем их здесь, иначе тот же event
         * будет создан повторно и уже доставленные каналы получат дубль.
         */
        if ($sent && $event === 'offline') {
            state_set(
                'modem_monitor_offline_notification_pending',
                '0'
            );
        }

        if ($sent && $event === 'online') {
            state_set(
                'modem_monitor_online_notification_pending',
                '0'
            );
            state_delete(
                'modem_monitor_offline_since'
            );
        }
    }
}

function modem_monitor_clear_maintenance(): void
{
    foreach (
        [
            'modem_maintenance_until',
            'modem_maintenance_reason',
            'modem_maintenance_started_at',
            'modem_maintenance_seen_failure',
        ] as $key
    ) {
        state_delete($key);
    }
}

function modem_monitor_maintenance_active(): bool
{
    return (int)state_get('modem_maintenance_until', '0') > time();
}

function modem_monitor_format_duration(int $seconds): string
{
    $seconds = max(0, $seconds);

    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $remainingSeconds = $seconds % 60;

    $parts = [];

    if ($hours > 0) {
        $parts[] = $hours . ' ч';
    }

    if ($minutes > 0 || $hours > 0) {
        $parts[] = $minutes . ' мин';
    }

    $parts[] = $remainingSeconds . ' сек';

    return implode(' ', $parts);
}

function modem_monitor_notify_offline(): bool
{
    global $config;

    $monitor = modem_monitor_config();

    $appName = trim(
        (string)($config['app']['name'] ?? 'Huawei SMS')
    );

    $offlineSince = (int)state_get(
        'modem_monitor_offline_since',
        (string)time()
    );

    $error = trim(
        state_get('modem_last_error', '')
    );

    $text =
        "📡 " . $appName . "\n\n" .
        "❌ Модем недоступен\n" .
        "🕒 С: " . date(
            'd.m.Y H:i:s',
            $offlineSince
        );

    if (
        !empty($monitor['include_error_in_notification'])
        && $error !== ''
    ) {
        $text .=
            "\n⚠️ Ошибка: " .
            mb_substr(
                $error,
                0,
                240,
                'UTF-8'
            );
    }

    $sent = modem_monitor_send_event(
        'offline',
        $text,
        'offline-' . $offlineSince
    );

    state_set(
        'modem_monitor_offline_notification_pending',
        $sent ? '0' : '1'
    );

    return $sent;
}

function modem_monitor_notify_online(
    int $offlineSince
): bool {
    global $config;

    $appName = trim(
        (string)($config['app']['name'] ?? 'Huawei SMS')
    );

    $duration = modem_monitor_format_duration(
        time() - $offlineSince
    );

    $text =
        "📡 " . $appName . "\n\n" .
        "✅ Модем снова доступен\n" .
        "🕒 Время: " .
        date('d.m.Y H:i:s') . "\n" .
        "⏱ Недоступность: " .
        $duration;

    $sent = modem_monitor_send_event(
        'online',
        $text,
        'online-' . $offlineSince
    );

    state_set(
        'modem_monitor_online_notification_pending',
        $sent ? '0' : '1'
    );

    if ($sent) {
        state_delete(
            'modem_monitor_offline_since'
        );
    }

    return $sent;
}

function modem_monitor_complete_maintenance(): void
{
    global $config;

    $reason = state_get(
        'modem_maintenance_reason',
        ''
    );

    $startedAt = (int)state_get(
        'modem_maintenance_started_at',
        '0'
    );

    $seenFailure = state_get(
        'modem_maintenance_seen_failure',
        '0'
    ) === '1';

    modem_monitor_clear_maintenance();

    state_set(
        'modem_monitor_status',
        'online'
    );

    state_set(
        'modem_monitor_failures',
        '0'
    );

    state_set(
        'modem_monitor_successes',
        '0'
    );

    state_set(
        'modem_monitor_last_online_at',
        (string)time()
    );

    state_set(
        'modem_monitor_offline_notification_pending',
        '0'
    );

    state_set(
        'modem_monitor_online_notification_pending',
        '0'
    );

    state_delete(
        'modem_monitor_offline_since'
    );

    state_delete(
        'modem_last_error'
    );

    if ($reason !== 'scheduled-reboot') {
        return;
    }

    state_set(
        'modem_auto_reboot_last_recovered_at',
        (string)time()
    );

    $reboot = modem_auto_reboot_config();

    if (empty($reboot['notify_completed'])) {
        return;
    }

    $appName = trim(
        (string)($config['app']['name'] ?? 'Huawei SMS')
    );

    $elapsed = $startedAt > 0
        ? modem_monitor_format_duration(
            time() - $startedAt
        )
        : 'неизвестно';

    $text =
        "📡 " . $appName . "\n\n" .
        "✅ Плановая перезагрузка модема завершена\n" .
        "🕒 Время: " .
        date('d.m.Y H:i:s') . "\n" .
        "⏱ Ожидание восстановления: " .
        $elapsed;

    if (!$seenFailure) {
        $text .=
            "\nℹ️ Пауза API могла не попасть " .
            "между минутными опросами";
    }

    modem_monitor_event_delivery_clear(
        'auto_reboot_started'
    );

    modem_monitor_send_event(
        'auto_reboot_completed',
        $text,
        'completed-' .
        state_get(
            'modem_auto_reboot_last_attempt_date',
            date('Y-m-d')
        )
    );
}

function modem_monitor_mark_success(): void
{
    $monitor = modem_monitor_config();

    $now = time();

    $maintenanceUntil = (int)state_get(
        'modem_maintenance_until',
        '0'
    );

    $maintenanceStarted = (int)state_get(
        'modem_maintenance_started_at',
        '0'
    );

    /*
     * Плановая команда выставляет maintenance после текущего
     * успешного poll.
     *
     * Следующий успешный poll считается восстановлением,
     * но не раньше чем через 10 секунд после команды.
     */
    if (
        $maintenanceStarted > 0
        && ($now - $maintenanceStarted) >= 10
        && (
            $maintenanceUntil > 0
            || state_get(
                'modem_maintenance_reason',
                ''
            ) !== ''
        )
    ) {
        modem_monitor_complete_maintenance();
        return;
    }

    if (empty($monitor['enabled'])) {
        return;
    }

    $status = state_get(
        'modem_monitor_status',
        'unknown'
    );

    if ($status === 'unknown') {
        state_set(
            'modem_monitor_status',
            'online'
        );

        state_set(
            'modem_monitor_failures',
            '0'
        );

        state_set(
            'modem_monitor_successes',
            '0'
        );

        state_set(
            'modem_monitor_last_online_at',
            (string)$now
        );

        state_set(
            'modem_monitor_offline_notification_pending',
            '0'
        );

        state_set(
            'modem_monitor_online_notification_pending',
            '0'
        );

        state_delete(
            'modem_last_error'
        );

        return;
    }

    if ($status === 'offline') {
        $successes =
            (int)state_get(
                'modem_monitor_successes',
                '0'
            ) + 1;

        $threshold = max(
            1,
            (int)(
                $monitor['recovery_threshold']
                ?? 2
            )
        );

        state_set(
            'modem_monitor_successes',
            (string)$successes
        );

        if ($successes < $threshold) {
            return;
        }

        $offlineSince = (int)state_get(
            'modem_monitor_offline_since',
            (string)$now
        );

        state_set(
            'modem_monitor_status',
            'online'
        );

        state_set(
            'modem_monitor_failures',
            '0'
        );

        state_set(
            'modem_monitor_successes',
            '0'
        );

        state_set(
            'modem_monitor_last_online_at',
            (string)$now
        );

        state_set(
            'modem_monitor_offline_notification_pending',
            '0'
        );

        modem_monitor_event_delivery_clear(
            'offline'
        );

        state_delete(
            'modem_last_error'
        );

        state_set(
            'modem_monitor_online_notification_pending',
            '1'
        );

        modem_monitor_notify_online(
            $offlineSince
        );

        return;
    }

    state_set(
        'modem_monitor_failures',
        '0'
    );

    state_set(
        'modem_monitor_successes',
        '0'
    );

    state_set(
        'modem_monitor_last_online_at',
        (string)$now
    );

    state_delete(
        'modem_last_error'
    );

    if (
        state_get(
            'modem_monitor_online_notification_pending',
            '0'
        ) === '1'
    ) {
        $offlineSince = (int)state_get(
            'modem_monitor_offline_since',
            (string)$now
        );

        modem_monitor_notify_online(
            $offlineSince
        );
    }
}

function modem_monitor_mark_failure(
    Throwable|string $error
): void {
    $monitor = modem_monitor_config();

    $message = $error instanceof Throwable
        ? $error->getMessage()
        : (string)$error;

    $message = trim($message) !== ''
        ? trim($message)
        : 'неизвестная ошибка';

    state_set(
        'modem_last_error',
        $message
    );

    if (modem_monitor_maintenance_active()) {
        state_set(
            'modem_maintenance_seen_failure',
            '1'
        );

        app_log_throttled(
            'modem_maintenance_failure',
            'INFO modem_unavailable_during_maintenance error="' .
            app_log_clean($message) .
            '"',
            max(
                60,
                (int)(
                    $monitor['maintenance_log_interval']
                    ?? 300
                )
            )
        );

        return;
    }

    $maintenanceReason = state_get(
        'modem_maintenance_reason',
        ''
    );

    /*
     * Истёкшая плановая перезагрузка должна получить итог даже когда
     * обычный offline/online monitor отключён. Это отдельный механизм.
     */
    if (
        (int)state_get(
            'modem_maintenance_until',
            '0'
        ) > 0
        || $maintenanceReason !== ''
    ) {
        if ($maintenanceReason === 'scheduled-reboot') {
            global $config;

            $appName = trim(
                (string)(
                    $config['app']['name']
                    ?? 'Huawei SMS'
                )
            );

            modem_monitor_event_delivery_clear(
                'auto_reboot_started'
            );

            modem_monitor_send_event(
                'auto_reboot_failed',
                "📡 " . $appName . "\n\n" .
                "❌ Модем не восстановился после плановой перезагрузки\n" .
                "🕒 Время: " . date('d.m.Y H:i:s') . "\n" .
                "⚠️ Ошибка: " .
                mb_substr(
                    $message,
                    0,
                    240,
                    'UTF-8'
                ),
                'failed-' .
                state_get(
                    'modem_auto_reboot_last_attempt_date',
                    date('Y-m-d')
                )
            );

            state_set(
                'modem_auto_reboot_last_result',
                'failed'
            );
        }

        modem_monitor_clear_maintenance();
    }

    if (empty($monitor['enabled'])) {
        return;
    }

    $failures =
        (int)state_get(
            'modem_monitor_failures',
            '0'
        ) + 1;

    $threshold = max(
        1,
        (int)(
            $monitor['failure_threshold']
            ?? 3
        )
    );

    $status = state_get(
        'modem_monitor_status',
        'unknown'
    );

    state_set(
        'modem_monitor_failures',
        (string)$failures
    );

    state_set(
        'modem_monitor_successes',
        '0'
    );

    app_log_throttled(
        'poll_modem_unavailable',
        'WARN poll_modem_unavailable failures=' .
        $failures .
        ' error="' .
        app_log_clean($message) .
        '"',
        max(
            60,
            (int)(
                $monitor['offline_log_interval']
                ?? 1800
            )
        )
    );

    if (
        $status !== 'offline'
        && $failures >= $threshold
    ) {
        state_set(
            'modem_monitor_status',
            'offline'
        );

        state_set(
            'modem_monitor_offline_since',
            (string)time()
        );

        state_set(
            'modem_monitor_offline_notification_pending',
            '1'
        );

        modem_monitor_event_delivery_clear(
            'online'
        );

        modem_monitor_notify_offline();

        return;
    }

    if (
        $status === 'offline'
        && state_get(
            'modem_monitor_offline_notification_pending',
            '0'
        ) === '1'
    ) {
        modem_monitor_notify_offline();
    }
}

function modem_auto_reboot_tick(): string
{
    global $config;

    $reboot = modem_auto_reboot_config();

    if (empty($reboot['enabled'])) {
        return 'disabled';
    }

    $time = trim(
        (string)($reboot['time'] ?? '')
    );

    if (
        !preg_match(
            '/\A([01][0-9]|2[0-3]):([0-5][0-9])\z/',
            $time,
            $match
        )
    ) {
        app_log_throttled(
            'modem_auto_reboot_bad_time',
            'ERROR modem_auto_reboot_invalid_time value="' .
            app_log_clean($time) .
            '"',
            3600
        );

        return 'invalid-time';
    }

    $days = array_values(
        array_unique(
            array_filter(
                array_map(
                    'intval',
                    (array)(
                        $reboot['days_of_week']
                        ?? []
                    )
                ),
                static fn(int $day): bool =>
                    $day >= 1
                    && $day <= 7
            )
        )
    );

    $now = new DateTimeImmutable(
        'now'
    );

    $day = (int)$now->format('N');

    if (
        $days !== []
        && !in_array(
            $day,
            $days,
            true
        )
    ) {
        return 'wrong-day';
    }

    $scheduled = $now->setTime(
        (int)$match[1],
        (int)$match[2],
        0
    );

    $windowMinutes = max(
        1,
        min(
            180,
            (int)(
                $reboot['window_minutes']
                ?? 15
            )
        )
    );

    $windowEnd = $scheduled->modify(
        '+' .
        $windowMinutes .
        ' minutes'
    );

    if (
        $now < $scheduled
        || $now >= $windowEnd
    ) {
        return 'outside-window';
    }

    $dateKey = $scheduled->format(
        'Y-m-d'
    );

    if (
        state_get(
            'modem_auto_reboot_last_attempt_date',
            ''
        ) === $dateKey
    ) {
        return 'already-attempted';
    }

    /*
     * Ставим метку до запроса:
     * повторная перезагрузка в тот же день опаснее.
     */
    state_set(
        'modem_auto_reboot_last_attempt_date',
        $dateKey
    );

    state_set(
        'modem_auto_reboot_last_attempt_at',
        (string)time()
    );

    state_set(
        'modem_auto_reboot_last_result',
        'started'
    );

    $maintenanceSeconds = max(
        60,
        min(
            3600,
            (int)(
                $reboot['maintenance_seconds']
                ?? 600
            )
        )
    );

    state_set(
        'modem_maintenance_until',
        (string)(
            time()
            + $maintenanceSeconds
        )
    );

    state_set(
        'modem_maintenance_reason',
        'scheduled-reboot'
    );

    state_set(
        'modem_maintenance_started_at',
        (string)time()
    );

    state_set(
        'modem_maintenance_seen_failure',
        '0'
    );

    /*
     * Уведомляем ДО команды reboot.
     *
     * Это намеренно: Telegram/MAX на некоторых площадках
     * могут использовать прокси через сам Huawei-модем.
     * После modem_reboot() сетевой путь может исчезнуть
     * раньше, чем успеет уйти уведомление.
     */
    if (!empty($reboot['notify_started'])) {
        $appName = trim(
            (string)(
                $config['app']['name']
                ?? 'Huawei SMS'
            )
        );

        modem_monitor_send_event(
            'auto_reboot_started',
            "🔄 " . $appName . "\n\n" .
            "🛠 Плановая перезагрузка модема\n" .
            "🕒 Время: " .
            date('d.m.Y H:i:s'),
            'started-' . $dateKey
        );
    }

    try {
        if (modem_reboot()) {
            state_set(
                'modem_auto_reboot_last_result',
                'accepted'
            );

            app_log(
                'INFO modem_auto_reboot_command_accepted' .
                ' date=' .
                $dateKey
            );

            return 'accepted';
        }

        state_set(
            'modem_auto_reboot_last_result',
            'rejected'
        );

        modem_monitor_clear_maintenance();
        modem_monitor_event_delivery_clear(
            'auto_reboot_started'
        );

        if (!empty($reboot['notify_completed'])) {
            $appName = trim(
                (string)(
                    $config['app']['name']
                    ?? 'Huawei SMS'
                )
            );

            modem_monitor_send_event(
                'auto_reboot_failed',
                "📡 " . $appName . "\n\n" .
                "❌ Модем отклонил команду плановой перезагрузки\n" .
                "🕒 Время: " .
                date('d.m.Y H:i:s'),
                'rejected-' . $dateKey
            );
        }

        app_log(
            'ERROR modem_auto_reboot_rejected' .
            ' date=' .
            $dateKey
        );

        return 'rejected';
    } catch (Throwable $exception) {
        /*
         * После POST Huawei часто закрывает сокет раньше
         * ответа. Оставляем maintenance и не повторяем
         * запрос в тот же день.
         */
        state_set(
            'modem_auto_reboot_last_result',
            'uncertain'
        );

        app_log(
            'WARN modem_auto_reboot_response_lost' .
            ' date=' .
            $dateKey .
            ' error="' .
            app_log_clean(
                $exception->getMessage()
            ) .
            '"'
        );

        return 'uncertain';
    }
}

function modem_monitor_snapshot(): array
{
    return [
        'status' => state_get(
            'modem_monitor_status',
            'unknown'
        ),

        'failures' => (int)state_get(
            'modem_monitor_failures',
            '0'
        ),

        'successes' => (int)state_get(
            'modem_monitor_successes',
            '0'
        ),

        'last_error' => state_get(
            'modem_last_error',
            ''
        ),

        'offline_since' => (int)state_get(
            'modem_monitor_offline_since',
            '0'
        ),

        'maintenance_until' => (int)state_get(
            'modem_maintenance_until',
            '0'
        ),

        'auto_reboot_last_attempt_date' =>
            state_get(
                'modem_auto_reboot_last_attempt_date',
                ''
            ),

        'auto_reboot_last_result' =>
            state_get(
                'modem_auto_reboot_last_result',
                ''
            ),
    ];
}
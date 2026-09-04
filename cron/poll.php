<?php

declare(strict_types=1);

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../sms_commands.php';
require_once __DIR__ . '/../modem_monitor.php';

try {
    modem_monitor_retry_pending_events();

    $added = sync_sms_from_modem(true);
    modem_monitor_mark_success();
    $rebootStatus = modem_auto_reboot_tick();

    echo "OK, added: {$added}, modem: online";

    if (!in_array(
        $rebootStatus,
        ['disabled', 'wrong-day', 'outside-window', 'already-attempted'],
        true
    )) {
        echo ", auto-reboot: {$rebootStatus}";
    }

    echo PHP_EOL;
    exit(0);
} catch (SyncBusyException $exception) {
    echo "SKIP, sync already running\n";
    exit(0);
} catch (ModemApiException $exception) {
    modem_monitor_mark_failure($exception);

    if (modem_monitor_maintenance_active()) {
        echo "MAINTENANCE, modem rebooting\n";
        exit(0);
    }

    fwrite(
        STDERR,
        'MODEM UNAVAILABLE: ' . $exception->getMessage() . PHP_EOL
    );
    exit(2);
} catch (Throwable $exception) {
    app_log(
        'ERROR poll_failed class=' . get_class($exception) .
        ' error="' . app_log_clean($exception->getMessage()) . '"'
    );

    fwrite(
        STDERR,
        'POLL FAILED: ' . get_class($exception) . ': ' .
        $exception->getMessage() . PHP_EOL
    );
    exit(1);
}

<?php

declare(strict_types=1);

function app_handle_post(string &$tab, int &$id, string &$message, string &$error): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    csrf_check();

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'sync') {
        try {
            $added = sync_sms_from_modem(true);
            $message = 'Синхронизация выполнена. Новых SMS: ' . $added;
        } catch (Throwable $e) {
            app_log('manual sync error: ' . $e->getMessage());
            $error = 'Ошибка синхронизации с модемом';
        }

        return;
    }

    if ($action === 'send') {
        $phone = trim((string)($_POST['phone'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));

        if ($phone === '' || $content === '') {
            $error = 'Номер и текст обязательны';
            $tab = 'send';
            return;
        }

        $normalizedPhone = sms_normalize_numeric_phone($phone);

        if ($normalizedPhone === '') {
            $error = 'Некорректный номер телефона';
            $tab = 'send';
            return;
        }

        $phone = $normalizedPhone;

        try {
            if (sms_send_by_modem($phone, $content)) {
                sms_archive_outbound($phone, $content);

                $message = 'SMS отправлено';
                $tab = 'outbox';
                return;
            }

            $error = 'Модем не подтвердил отправку';
            $tab = 'send';
        } catch (Throwable $e) {
            app_log('send error: ' . $e->getMessage());
            $error = 'Ошибка отправки SMS';
            $tab = 'send';
        }

        return;
    }

    if ($action === 'delete') {
        $deleteId = (int)($_POST['id'] ?? 0);

        $stmt = db()->prepare('
            SELECT direction
            FROM sms
            WHERE id = ?
        ');
        $stmt->execute([$deleteId]);

        $sms = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($sms) {
            db()->prepare('DELETE FROM sms WHERE id = ?')->execute([$deleteId]);

            $message = 'SMS удалено';
            $tab = (string)$sms['direction'];
        }

        $id = 0;
        return;
    }
}

function app_load_status(): array
{
    try {
        return modem_status();
    } catch (Throwable $e) {
        app_log_throttled(
            'web_modem_status',
            'WARN web_modem_status_failed error="' . app_log_clean($e->getMessage()) . '"',
            1800
        );

        return [
            'available' => false,
            'operator' => '',
            'network' => '',
            'rsrp' => '',
            'sinr' => '',
            'error' => state_get('modem_last_error', $e->getMessage()),
        ];
    }
}

function app_load_items(string $tab, int &$id): array
{
    if ($tab === 'send') {
        return [];
    }

    $stmt = db()->prepare("
        SELECT *
        FROM sms
        WHERE direction = ?
        ORDER BY datetime(sms_date) DESC, id DESC
        LIMIT 50
    ");
    $stmt->execute([$tab]);

    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($id === 0 && !empty($items)) {
        $id = (int)$items[0]['id'];
    }

    return $items;
}

function app_load_current(string $tab, int $id): ?array
{
    if ($id <= 0 || $tab === 'send') {
        return null;
    }

    $stmt = db()->prepare('
        SELECT *
        FROM sms
        WHERE id = ?
          AND direction = ?
    ');
    $stmt->execute([$id, $tab]);

    $sms = $stmt->fetch(PDO::FETCH_ASSOC);

    return $sms ?: null;
}

function app_render_page(string $tab, array $status, array $items, ?array $current, string $message, string $error): void
{
    global $config;

    csrf_token();

    $authEnabled = !empty($config['auth']['enabled']);
    $sessionLifetime = $authEnabled
        ? (int)($config['auth']['session_lifetime'] ?? 900)
        : 0;

?>
    <!doctype html>
    <html lang="ru">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex,nofollow">
        <title><?= e($config['app']['name'] ?? 'Huawei SMS') ?></title>
        <link rel="stylesheet" href="/assets/style.css">
        <script>
            window.APP_SESSION_LIFETIME = <?= $sessionLifetime ?>;
        </script>
        <script src="/assets/app.js" defer></script>
    </head>

    <body>
        <div class="app-shell">
            <header class="topbar">
                <div class="top-main">
                    <div class="brand"><?= e($config['app']['name'] ?? 'Huawei SMS') ?></div>
                    <div class="status">
                        <?php if (!empty($status['available'])): ?>
                            <span title="RSRP <?= e($status['rsrp'] ?? '') ?> · SINR <?= e($status['sinr'] ?? '') ?>">
                                <?= e($status['operator'] ?: 'Модем') ?>
                                <?= !empty($status['network']) ? ' ' . e($status['network']) : '' ?>
                                · <?= e(signal_label($status['rsrp'] ?? '', $status['sinr'] ?? '')) ?>
                            </span>
                        <?php else: ?>
                            <span class="status-offline" title="<?= e($status['error'] ?? '') ?>">Модем недоступен</span>
                        <?php endif; ?>
                    </div>
                </div>

                <form method="post" class="top-action">
                    <input type="hidden" name="action" value="sync">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <button type="submit">Синхр.</button>
                </form>

                <?php if ($authEnabled): ?>
                    <form method="post" class="top-action">
                        <input type="hidden" name="action" value="logout">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <button type="submit">Выход</button>
                    </form>
                <?php endif; ?>
            </header>

            <nav class="tabs">
                <a class="<?= $tab === 'inbox' ? 'active' : '' ?>" href="/?tab=inbox">Входящие</a>
                <a class="<?= $tab === 'outbox' ? 'active' : '' ?>" href="/?tab=outbox">Исходящие</a>
                <a class="<?= $tab === 'send' ? 'active' : '' ?>" href="/?tab=send">Написать</a>
            </nav>

            <?php if ($message !== ''): ?>
                <div class="notice ok"><?= e($message) ?></div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="notice error"><?= e($error) ?></div>
            <?php endif; ?>

            <?php if ($tab === 'send'): ?>
                <main class="compose">
                    <form method="post">
                        <input type="hidden" name="action" value="send">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                        <label>Номер</label>
                        <input name="phone" placeholder="+79000000000" required>

                        <label>Текст SMS</label>
                        <textarea name="content" rows="7" required data-sms-text></textarea>
                        <div class="counter" data-sms-counter>Символов: 0</div>

                        <button type="submit" class="primary">Отправить</button>
                    </form>
                </main>
            <?php else: ?>
                <main class="sms-layout">
                    <section class="sms-list">
                        <?php if (empty($items)): ?>
                            <div class="empty">SMS нет</div>
                        <?php endif; ?>

                        <?php foreach ($items as $item): ?>
                            <a class="sms-row <?= (int)$item['id'] === (int)($current['id'] ?? 0) ? 'selected' : '' ?>"
                               href="/?tab=<?= e($tab) ?>&id=<?= (int)$item['id'] ?>">
                                <span><?= e($item['phone']) ?></span>
                                <time><?= e(date('d.m H:i', strtotime((string)$item['sms_date']))) ?></time>
                            </a>
                        <?php endforeach; ?>
                    </section>

                    <section class="viewer">
                        <?php if ($current): ?>
                            <div class="viewer-head">
                                <div>
                                    <div class="viewer-phone"><?= e($current['phone']) ?></div>
                                    <div class="viewer-date"><?= e(date('d.m.Y H:i:s', strtotime((string)$current['sms_date']))) ?></div>
                                </div>

                                <form method="post" onsubmit="return confirm('Удалить SMS?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$current['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <button class="danger" type="submit">Удалить</button>
                                </form>
                            </div>

                            <pre class="sms-text"><?= e($current['content']) ?></pre>
                        <?php else: ?>
                            <div class="empty">Выбери SMS</div>
                        <?php endif; ?>
                    </section>
                </main>
            <?php endif; ?>

            <footer class="app-footer">
                Разработка:
                <a
                    href="https://e-v-s.ru/"
                    target="_blank"
                    rel="noopener noreferrer"
                >Виталий Егоров</a>
                <span>·</span>
                Telegram:
                <a
                    href="https://t.me/egorowitaliy"
                    target="_blank"
                    rel="noopener noreferrer"
                >@egorowitaliy</a>
            </footer>
        </div>
    </body>

    </html>
<?php
}

function app_run(): void
{
    if (isset($_GET['ping'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'time' => time(),
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    $tab = $_GET['tab'] ?? 'inbox';
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $error = '';
    $message = '';

    if (!in_array($tab, ['inbox', 'outbox', 'send'], true)) {
        $tab = 'inbox';
    }

    app_handle_post($tab, $id, $message, $error);

    $status = app_load_status();
    $items = app_load_items($tab, $id);
    $current = app_load_current($tab, $id);

    app_render_page($tab, $status, $items, $current, $message, $error);
}
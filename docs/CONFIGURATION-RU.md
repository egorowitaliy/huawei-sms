# Конфигурация Huawei SMS

Основной локальный файл:

```text
config.local.php
```

Создать его можно из примера:

```bash
cd /srv/huawei-sms
cp config.local.php.example config.local.php
mcedit config.local.php
```

`config.local.php` содержит только отличия конкретной установки. Остальные значения берутся из `config.php`.

Не редактируйте `config.php` под конкретный сервер: при обновлении ядра он может быть заменён.

## 1. Общие параметры приложения

```php
'app' => [
    'name' => 'Huawei SMS',
    'base_url' => 'https://sms.example.org',
    'timezone' => 'Europe/Moscow',
    'force_secure_cookie' => true,
    'trusted_proxies' => [],
],
```

### `name`

Название в веб-интерфейсе и уведомлениях.

### `base_url`

Публичный адрес веб-интерфейса. Используется в уведомлениях.

Для внешнего доступа указывайте HTTPS.

### `timezone`

Часовой пояс PHP. Он влияет на отображение времени, журналы, окно плановой перезагрузки и служебные отметки.

Примеры:

```text
Europe/Moscow
Europe/Helsinki
Asia/Yekaterinburg
```

### `force_secure_cookie`

```php
'force_secure_cookie' => true,
```

принудительно ставит флаг Secure у сессионной cookie.

Для сайта по HTTPS рекомендуется `true`.

### `trusted_proxies`

Список адресов обратных прокси, от которых разрешено принимать служебные заголовки клиента и протокола.

Например:

```php
'trusted_proxies' => [
    '127.0.0.1',
],
```

Не добавляйте сюда произвольные клиентские сети.

## 2. Веб-авторизация

```php
'auth' => [
    'enabled' => true,
    'username' => 'admin',
    'password_hash' => '...',
    'session_lifetime' => 900,
    'max_login_attempts' => 5,
    'login_block_seconds' => 900,
    'login_attempt_retention_days' => 30,
],
```

### `enabled`

Включает парольную защиту веб-интерфейса.

Для доступного извне сайта оставляйте `true`.

### `username`

Имя пользователя.

### `password_hash`

Хеш пароля. Открытый пароль сюда не записывается.

Создать:

```bash
cd /srv/huawei-sms
read -rsp 'Пароль: ' P; echo
HUAWEI_SMS_PASSWORD="$P" php bin/password-hash.php
unset P
```

### `session_lifetime`

Время бездействия до завершения сессии, в секундах.

### `max_login_attempts`

Количество неудачных попыток до временной блокировки.

### `login_block_seconds`

Длительность блокировки.

### `login_attempt_retention_days`

Сколько дней хранить старые записи счётчика входов.

### Быстрый вход по одноразовой ссылке

Быстрый вход является дополнением к TOTP и настраивается внутри секции `auth`:

```php
'quick_login' => [
    'enabled' => true,
    'token_ttl_seconds' => 86400,
    'channels' => [
        'telegram' => true,
        'matrix' => false,
        'max' => true,
    ],
],
```

#### `enabled`

Включает механизм одноразовых ссылок.

Если установлено `false`, вход работает обычным способом: логин, пароль и TOTP, если он включён.

#### `token_ttl_seconds`

Срок действия ссылки в секундах.

Значение по умолчанию:

```text
86400
```

Это 24 часа.

Допустимый диапазон — от `300` до `604800` секунд, то есть от 5 минут до 7 дней.

`bin/preflight.php` считает значение вне этого диапазона ошибкой конфигурации. Внутренний обработчик также ограничивает TTL этими границами.

#### `channels`

Определяет, в каких каналах уведомлений обычная ссылка заменяется ссылкой быстрого входа.

Например:

```php
'channels' => [
    'telegram' => true,
    'matrix' => false,
    'max' => true,
],
```

означает:

```text
Telegram — быстрый вход
Matrix   — обычный вход
MAX      — быстрый вход
```

Быстрая ссылка не отменяет пароль.

После перехода пользователь вводит логин и пароль как обычно. Если пароль верный и одноразовая ссылка действительна, код TOTP для этой попытки не требуется.

Для каждого канала и каждого нового уведомления создаётся отдельный случайный ключ.

Ключ:

- используется только один раз;
- после успешного входа сразу удаляется из базы;
- автоматически перестаёт действовать после указанного срока;
- просроченная запись удаляется ближайшим запуском `cron/poll.php`;
- не хранится в SQLite в открытом виде — сохраняется только его SHA-256.

В сообщении отображается обычный адрес кабинета. Одноразовая часть находится в фактическом адресе ссылки и не показывается пользователю как длинная строка.

Для Telegram и MAX предпросмотр страницы в таких уведомлениях отключается.

### Ручное создание ссылки из CLI

Администратор может вручную создать одноразовую ссылку для разрешённого канала:

```bash
php /srv/huawei-sms/bin/quick-login-token.php telegram
```

Вместо `telegram` можно указать `matrix` или `max`.

Команда выполняется только из CLI и только для канала, которому разрешён быстрый вход в `auth.quick_login.channels`.

Утилита выводит готовую действующую ссылку в стандартный вывод. Эта ссылка содержит секрет второго фактора: её нельзя публиковать, записывать в общедоступные журналы или передавать посторонним.

Пароль такая ссылка не заменяет.

## 3. TOTP

```php
'totp' => [
    'enabled' => false,
    'secret' => '',
    'emergency_bypass' => false,
],
```

Создать секрет:

```bash
cd /srv/huawei-sms
php bin/totp-secret.php
```

Команда выводит:

```text
Secret: ...
URI: otpauth://...
```

`Secret` помещается в `totp.secret`.

URI можно добавить в совместимое приложение для одноразовых кодов.

### `emergency_bypass`

Аварийно отключает проверку TOTP без удаления секрета.

Обычное состояние:

```php
'emergency_bypass' => false,
```

## 4. Huawei

```php
'modem' => [
    'url' => 'http://192.168.8.1',
    'timeout' => 8,
],
```

### `url`

Базовый адрес HiLink API.

Стандартный адрес многих Huawei:

```text
http://192.168.8.1
```

Но он не зашит в логику приложения и может быть изменён.

### `timeout`

Таймаут HTTP-запросов PHP к модему, в секундах.

## 5. Контроль доступности модема

```php
'monitor' => [
    'enabled' => true,
    'failure_threshold' => 3,
    'recovery_threshold' => 2,
    'notification_retry_seconds' => 300,
    'offline_log_interval' => 1800,
    'maintenance_log_interval' => 300,
    'include_error_in_notification' => true,
],
```

### `failure_threshold`

Сколько последовательных неудачных циклов `cron/poll.php` требуется для перехода в состояние «недоступен».

При значении `3` одиночная ошибка не объявляет аварию.

### `recovery_threshold`

Сколько последовательных успешных циклов `cron/poll.php` нужно для подтверждения восстановления.

### `notification_retry_seconds`

Минимальный интервал повторной попытки недоставленного системного уведомления по конкретному каналу.

### `offline_log_interval`

Ограничение частоты повторяющейся записи о недоступности в основном журнале.

### `maintenance_log_interval`

Ограничение частоты записей об ожидаемой недоступности во время плановой перезагрузки.

### `include_error_in_notification`

Добавлять ли последнюю ошибку API в уведомление о недоступности Huawei.

Ручное открытие веб-страницы и команда `Модем` не меняют эти счётчики. Состояние изменяет только основной цикл `cron/poll.php`.

## 6. Плановая перезагрузка Huawei

```php
'auto_reboot' => [
    'enabled' => false,
    'time' => '04:10',
    'days_of_week' => [7],
    'window_minutes' => 15,
    'maintenance_seconds' => 600,
    'notify_started' => true,
    'notify_completed' => true,
],
```

### `time`

Время в часовом поясе `app.timezone`.

### `days_of_week`

Номера дней ISO:

```text
1 — понедельник
2 — вторник
3 — среда
4 — четверг
5 — пятница
6 — суббота
7 — воскресенье
```

Например:

```php
'days_of_week' => [7],
```

означает только воскресенье.

Пустой массив означает любой день.

### `window_minutes`

Сколько минут после `time` текущий цикл `cron/poll.php` имеет право начать перезагрузку.

Это нужно потому, что минутный таймер может запуститься не ровно в указанную секунду.

### `maintenance_seconds`

Период, в течение которого ожидаемая недоступность Huawei после перезагрузки не считается обычной аварией.

### `notify_started` и `notify_completed`

Управляют уведомлением о начале и итоговом результате операции.

Отдельное периодическое задание для перезагрузки не нужно.

## 7. SMS-команды

```php
'sms_commands' => [
    'enabled' => true,
    'trusted_phones' => [
        '+79000000000',
    ],
    'reply_chunk_chars' => 420,
    'reply_retry_max_attempts' => 10,
    'reply_retry_interval_seconds' => 60,
    'processing_stale_seconds' => 300,
],
```

### `trusted_phones`

Только эти номера могут запускать зарегистрированные команды.

Для международных номеров используйте:

```text
+<код страны><номер>
```

Цифр должно быть от 7 до 15.

### `reply_chunk_chars`

Прикладной предел текста одной части длинного ответа.

К служебному префиксу `[1/N]` этот предел не относится.

### `reply_retry_max_attempts`

Максимальное число попыток отправить уже готовый ответ команды.

### `reply_retry_interval_seconds`

Минимальный интервал между повторными попытками.

### `processing_stale_seconds`

Защитное время для команды, оставшейся в `processing` после аварии процесса.

Для внешнего обработчика фактический порог не будет меньше его собственного `timeout + 60` секунд.

## 8. Общий переключатель уведомлений

```php
'notifications' => [
    'enabled' => false,
    'connect_timeout' => 5,
    'timeout' => 15,
],
```

Если `notifications.enabled=false`, никакой канал уведомлений не используется, даже если у него самого стоит `enabled=true`.

Входящие SMS при этом всё равно сохраняются в SQLite и удаляются из памяти модема после успешной обработки. Они сразу отмечаются как не требующие уведомления, поэтому последующее включение уведомлений не приводит к рассылке старых сообщений, накопленных за время отключения.

### `connect_timeout`

Максимальное время установки соединения.

### `timeout`

Общий таймаут одного HTTP-запроса уведомления.

## 9. Уведомления о входе

```php
'auth' => [
    'login_success' => false,
    'login_failed' => false,
    'login_blocked' => false,
    'csrf_failed' => false,
    'csrf_notification_interval_seconds' => 300,
],
```

Каждый тип включается отдельно.

`csrf_notification_interval_seconds` ограничивает частоту повторяющихся уведомлений об ошибке CSRF. Само событие при этом продолжает записываться в журнал.

`login_blocked` отправляется в момент, когда достигнут лимит попыток. Последующие запросы в течение уже действующей блокировки не создают новые одинаковые уведомления.

## 10. Уведомления о модеме

```php
'modem' => [
    'offline' => true,
    'online' => true,
    'auto_reboot_started' => true,
    'auto_reboot_completed' => true,
],
```

`auto_reboot_completed` управляет также итоговым уведомлением об ошибке плановой перезагрузки.

## 11. Telegram

```php
'telegram' => [
    'enabled' => true,
    'bot_token' => '',
    'bot_token_file' => __DIR__ . '/secrets/telegram-token',
    'chat_id' => '...',
    'proxy' => [
        'enabled' => false,
        'url' => '',
        'username' => '',
        'password' => '',
        'type' => 'http',
        'fallback_direct' => false,
    ],
],
```

Токен можно задать либо в `bot_token`, либо в файле `bot_token_file`.

Если заполнены оба, значение из конфигурации имеет приоритет.

## 12. MAX

```php
'max' => [
    'enabled' => true,
    'api_url' => 'https://platform-api2.max.ru/messages',
    'token' => '',
    'token_file' => __DIR__ . '/secrets/max-token',
    'chat_id' => '...',
    'proxy' => [
        'enabled' => false,
        'url' => '',
        'username' => '',
        'password' => '',
        'type' => 'http',
        'fallback_direct' => false,
    ],
],
```

`api_url` оставляйте стандартным, если используемый API MAX не требует другого адреса.

## 13. Matrix

```php
'matrix' => [
    'enabled' => true,
    'homeserver' => 'https://matrix.example.org',
    'room_id' => '!room:example.org',
    'access_token' => '',
    'access_token_file' => __DIR__ . '/secrets/matrix-token',
],
```

Для Matrix отдельная настройка прокси не предусмотрена.

Сообщение отправляется через Matrix Client API `m.room.message`.

## 14. Прокси Telegram и MAX

Доступные значения `type`:

```text
http
https
socks4
socks4a
socks5
socks5h
```

Фактическая поддержка зависит от libcurl, с которым собран PHP.

`bin/preflight.php` проверяет выбранный тип. Если текущая сборка cURL его не поддерживает, Huawei SMS сообщает ошибку и не подменяет тип другим молча.

Пример с авторизацией:

```php
'proxy' => [
    'enabled' => true,
    'url' => 'http://proxy.example.org:3128',
    'username' => 'proxy-user',
    'password' => 'proxy-password',
    'type' => 'http',
    'fallback_direct' => true,
],
```

### `fallback_direct`

```php
'fallback_direct' => true,
```

разрешает одну прямую попытку, если соединение через прокси завершилось транспортной ошибкой или прокси вернул ошибку аутентификации.

Не выполняется прямой обход, если удалённый сервис нормально ответил HTTP-ошибкой или своим отрицательным ответом.

Если политика сети запрещает прямой доступ, оставляйте:

```php
'fallback_direct' => false,
```

## 15. Секреты в отдельных файлах

Рекомендуемый каталог:

```text
/srv/huawei-sms/secrets/
```

Пример:

```bash
printf '%s\n' 'TOKEN' > /srv/huawei-sms/secrets/telegram-token
chown root:www-data /srv/huawei-sms/secrets/telegram-token
chmod 0640 /srv/huawei-sms/secrets/telegram-token
```

Не добавляйте такие файлы в Git.

## 16. Локальные команды

`commands.local.php` — отдельный файл и не является частью `config.local.php`.

Он дополняет штатные Пинг/Порт/Модем.

Пример начала:

```bash
cd /srv/huawei-sms
cp commands.local.php.example commands.local.php
mcedit commands.local.php
```

Подробности: [PLUGINS-RU.md](PLUGINS-RU.md).

## 17. Локальный список блокировки

Создаётся отдельно:

```bash
cp blocklist.local.php.example blocklist.local.php
mcedit blocklist.local.php
```

Формат зависит от полей, показанных в самом примере.

Сообщение, которое попало под локальный список блокировки, удаляется из Huawei и записывается в `logs/spam.log`, но не сохраняется в обычную таблицу сообщений.

## 18. Какие файлы не публиковать

В публичный репозиторий и архив не должны попадать:

```text
config.local.php
commands.local.php
blocklist.local.php
data/sms.sqlite
logs/*
secrets/*
scripts.local/*
```

`.gitignore` уже исключает эти рабочие данные, кроме `.gitkeep`.

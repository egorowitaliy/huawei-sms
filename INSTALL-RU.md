# Установка Huawei SMS

Эта инструкция описывает обычную установку Huawei SMS на Linux с nginx и PHP-FPM. Проект не содержит веб-установщика и не изменяет систему автоматически.

Примеры рассчитаны на каталог:

```text
/srv/huawei-sms
```

Имена пользователя, группы, версии PHP и сокета PHP-FPM при необходимости замените на свои.

## 1. Требования

Необходимы:

- PHP 8.2 или новее;
- PHP-FPM;
- PHP-расширения `curl`, `mbstring`, `pdo_sqlite`, `SimpleXML`;
- SQLite;
- Python 3;
- Bash;
- системные утилиты `curl` и `ping`;
- nginx или другой веб-сервер.

Для Debian 12/13 набор пакетов может выглядеть так:

```bash
apt update
apt install php-fpm php-cli php-curl php-mbstring php-sqlite3 php-xml sqlite3 python3 curl iputils-ping nginx
```

Названия PHP-пакетов зависят от используемого репозитория и версии PHP.

## 2. Распаковка

Создайте каталог приложения и распакуйте архив:

```bash
mkdir -p /srv/huawei-sms
tar -xzf huawei-sms-1.0.0.tar.gz --strip-components=1 -C /srv/huawei-sms
```

Внешнему веб-серверу должен быть доступен только:

```text
/srv/huawei-sms/public
```

`data/`, `logs/`, `secrets/`, `config.local.php`, `commands.local.php` и пользовательские скрипты не должны публиковаться через HTTP.

## 3. Права

В примерах ниже процесс PHP работает от `www-data`. Если у вас отдельная сервисная учётная запись, используйте её вместо `www-data`.

Код приложения можно оставить принадлежащим `root`, разрешив веб-группе только чтение:

```bash
chown -R root:www-data /srv/huawei-sms
find /srv/huawei-sms -type d -exec chmod 0755 {} \;
find /srv/huawei-sms -type f -exec chmod 0644 {} \;
```

Исполняемым файлам верните право запуска:

```bash
chmod 0755 \
  /srv/huawei-sms/bin/command-runner.py \
  /srv/huawei-sms/scripts/network-check.py \
  /srv/huawei-sms/scripts/modem-health.sh \
  /srv/huawei-sms/examples/plugins/status.sh
```

Рабочие каталоги должны быть доступны на запись пользователю PHP:

```bash
chown www-data:www-data /srv/huawei-sms/data /srv/huawei-sms/logs
chmod 0750 /srv/huawei-sms/data /srv/huawei-sms/logs
```

Каталог секретов лучше держать закрытым:

```bash
chown root:www-data /srv/huawei-sms/secrets
chmod 0750 /srv/huawei-sms/secrets
```

## 4. Локальная конфигурация

Создайте локальный файл:

```bash
cp /srv/huawei-sms/config.local.php.example /srv/huawei-sms/config.local.php
chown root:www-data /srv/huawei-sms/config.local.php
chmod 0640 /srv/huawei-sms/config.local.php
mcedit /srv/huawei-sms/config.local.php
```

Минимально нужно указать:

- `app.base_url`;
- `modem.url`;
- имя пользователя веб-интерфейса;
- хеш пароля;
- доверенные телефонные номера;
- при необходимости TOTP;
- каналы уведомлений и их реквизиты.

Полное описание: [docs/CONFIGURATION-RU.md](docs/CONFIGURATION-RU.md).

## 5. Пароль веб-интерфейса

Пароль в конфигурацию в открытом виде не записывается.

Создайте хеш:

```bash
cd /srv/huawei-sms
read -rsp 'Пароль: ' P; echo
HUAWEI_SMS_PASSWORD="$P" php bin/password-hash.php
unset P
```

Скрипт использует Argon2id, если он доступен в вашей сборке PHP. В противном случае применяется bcrypt.

Полученную строку вставьте в:

```php
'auth' => [
    'enabled' => true,
    'username' => 'admin',
    'password_hash' => '...',
],
```

При входе используется стандартная функция PHP `password_verify()`.

## 6. TOTP

Двухфакторная аутентификация необязательна.

Создать секрет можно так:

```bash
php /srv/huawei-sms/bin/totp-secret.php
```

С пользовательским названием записи:

```bash
php /srv/huawei-sms/bin/totp-secret.php "Huawei SMS" "sms.example.org"
```

Скрипт выводит Base32-секрет и строку `otpauth://`, которую можно импортировать в совместимое приложение-аутентификатор.

Секрет записывается в `config.local.php`:

```php
'totp' => [
    'enabled' => true,
    'secret' => 'BASE32SECRET',
    'emergency_bypass' => false,
],
```

## 7. nginx

Готовый пример находится в:

```text
deploy-examples/nginx/huawei-sms.conf.example
```

Для отдельного сайта:

```nginx
server {
    listen 80;
    server_name sms.example.org;

    root /srv/huawei-sms/public;
    index index.php;

    client_max_body_size 1m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /index.php {
        include /etc/nginx/fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_PROXY "";
        fastcgi_pass unix:/run/php/huawei-sms.sock;
    }

    location ~ \.php$ {
        return 404;
    }

    location ~ /\. {
        deny all;
    }
}
```

Для внешнего доступа используйте HTTPS. Сертификат и перенаправление HTTP → HTTPS настраиваются обычными средствами nginx или используемого обратного прокси.

После изменения конфигурации:

```bash
nginx -t
systemctl reload nginx
```

## 8. PHP-FPM

Пример отдельного пула:

```text
deploy-examples/php-fpm/huawei-sms.conf.example
```

Он создаёт сокет:

```text
/run/php/huawei-sms.sock
```

После добавления пула перезагрузите конфигурацию PHP-FPM. Имя службы зависит от версии PHP, например:

```bash
systemctl reload php8.4-fpm
```

Не останавливайте общий PHP-FPM без необходимости, если через него работают другие сайты.

## 9. Предварительная проверка

`preflight.php` специально нельзя запускать от `root`: на чистой установке он может создать рабочую базу и файлы журналов, поэтому владелец должен сразу быть правильным.

Для `www-data`:

```bash
runuser -u www-data -- php /srv/huawei-sms/bin/preflight.php
```

Проверяются, в частности:

- необходимые расширения PHP;
- Python, Bash, `curl` и `ping`;
- права на рабочие каталоги;
- настройка пароля;
- формат TOTP-секрета;
- поддержка выбранного типа прокси текущей сборкой cURL;
- доступность и безопасность обработчиков команд;
- база SQLite;
- связь с Huawei.

Исправьте все ошибки перед включением периодического опроса.

## 10. База данных

Готовой базы в проекте нет и импортировать `schema.sql` не требуется.

При первом запуске веб-интерфейса, `preflight.php` или `cron/poll.php` создаётся:

```text
data/sms.sqlite
```

Схема создаётся автоматически. Для Huawei SMS 1.0.0 используется:

```text
PRAGMA user_version = 5
```

Повторный запуск безопасен: существующая актуальная база не создаётся заново.

Если приложение обнаружит базу с более новой версией схемы, чем понимает этот выпуск, работа прекращается вместо попытки понизить номер версии.

## 11. Периодический опрос через systemd

Примеры находятся в:

```text
deploy-examples/systemd/
```

Скопируйте их:

```bash
cp /srv/huawei-sms/deploy-examples/systemd/huawei-sms-poll.service.example \
  /etc/systemd/system/huawei-sms-poll.service

cp /srv/huawei-sms/deploy-examples/systemd/huawei-sms-poll.timer.example \
  /etc/systemd/system/huawei-sms-poll.timer
```

Проверьте пользователя и пути через:

```bash
mcedit /etc/systemd/system/huawei-sms-poll.service
mcedit /etc/systemd/system/huawei-sms-poll.timer
```

Затем:

```bash
systemctl daemon-reload
systemctl enable --now huawei-sms-poll.timer
```

Проверка:

```bash
systemctl status huawei-sms-poll.timer --no-pager
systemctl list-timers huawei-sms-poll.timer --all
```

`cron/poll.php` не является постоянным процессом. Каждый запуск выполняет один цикл и завершается.

## 12. Периодический опрос через cron

Вместо systemd можно использовать cron:

```cron
* * * * * runuser -u www-data -- /usr/bin/php /srv/huawei-sms/cron/poll.php >/dev/null 2>&1
```

Пример для проекта, смонтированного в PHP-контейнер:

```cron
* * * * * docker exec -u www-data huawei-sms-php php /var/www/huawei-sms/cron/poll.php >/dev/null 2>&1
```

`huawei-sms-php` здесь только условное имя контейнера. Подставьте имя своего контейнера и фактический путь.

Не запускайте одновременно systemd timer и cron для одной установки.

## 13. Проверка уведомлений

Общий переключатель и нужный канал должны быть включены в `config.local.php`.

Telegram:

```bash
runuser -u www-data -- php /srv/huawei-sms/bin/test-notifications.php telegram
```

MAX:

```bash
runuser -u www-data -- php /srv/huawei-sms/bin/test-notifications.php max
```

Matrix:

```bash
runuser -u www-data -- php /srv/huawei-sms/bin/test-notifications.php matrix
```

Все включённые каналы:

```bash
runuser -u www-data -- php /srv/huawei-sms/bin/test-notifications.php all
```

Если выбранный канал отключён, утилита завершится с ошибкой вместо ложного сообщения об успешной отправке.

## 14. Собственные SMS-команды

Если нужны пользовательские команды:

```bash
cp /srv/huawei-sms/commands.local.php.example /srv/huawei-sms/commands.local.php
chown root:www-data /srv/huawei-sms/commands.local.php
chmod 0640 /srv/huawei-sms/commands.local.php
mcedit /srv/huawei-sms/commands.local.php
```

Обработчики размещайте в:

```text
/srv/huawei-sms/scripts.local/
```

`commands.local.php` дополняет штатные команды. Копировать в него `Пинг`, `Порт` и `Модем` не требуется: добавьте только свои обработчики. Если локальная запись использует то же `script_command`, что и штатная, она заменит только эту конкретную команду.

Перед подключением команды обязательно прочитайте [docs/PLUGINS-RU.md](docs/PLUGINS-RU.md).

Тест отдельной команды:

```bash
runuser -u www-data -- php /srv/huawei-sms/bin/script-command-test.php
```

Точная форма вызова показана самой утилитой при запуске без аргументов.

## 15. Журналы

Основные журналы:

```text
logs/sms.log
logs/spam.log
logs/commands.log
```

`commands.log` содержит аудит запуска пользовательских обработчиков. Для чувствительных команд отключайте журналирование аргументов и результата либо используйте маскирование отдельных аргументов.

Не публикуйте рабочие журналы.

## 16. Резервное копирование

Базу SQLite нельзя надёжно резервировать обычным копированием работающего файла, особенно при включённом WAL.

Используйте штатный механизм SQLite:

```bash
install -d -m 0700 /root/huawei-sms-backup

sqlite3 /srv/huawei-sms/data/sms.sqlite \
  ".backup '/root/huawei-sms-backup/sms.sqlite'"
```

Проверка:

```bash
sqlite3 /root/huawei-sms-backup/sms.sqlite "PRAGMA integrity_check;"
sqlite3 /root/huawei-sms-backup/sms.sqlite "PRAGMA foreign_key_check;"
```

Для полного восстановления также сохраните отдельно:

- `config.local.php`;
- `commands.local.php`, если он используется;
- `scripts.local/`;
- `secrets/`.

Подробности: [docs/DATABASE-RU.md](docs/DATABASE-RU.md).

## 17. Что не следует помещать в публичный репозиторий

Не публикуйте:

```text
config.local.php
commands.local.php
blocklist.local.php
data/sms.sqlite
data/*.lock
logs/*
secrets/*
scripts.local/*
```

Исключение — специально подготовленные обезличенные примеры.

Актуальные исключения также приведены в `.gitignore`.

#!/usr/bin/env php
<?php

declare(strict_types=1);

$password = getenv('HUAWEI_SMS_PASSWORD');

if (!is_string($password) || $password === '') {
    fwrite(STDERR, "Передайте пароль через переменную HUAWEI_SMS_PASSWORD\n");
    fwrite(STDERR, "Пример:\n");
    fwrite(STDERR, "  read -rsp 'Пароль: ' P; echo; HUAWEI_SMS_PASSWORD=\"\$P\" php bin/password-hash.php; unset P\n");
    exit(2);
}

$algorithm = (
    defined('PASSWORD_ARGON2ID')
    && in_array('argon2id', password_algos(), true)
)
    ? constant('PASSWORD_ARGON2ID')
    : PASSWORD_BCRYPT;

$options = $algorithm === PASSWORD_BCRYPT
    ? ['cost' => 12]
    : [];

$hash = password_hash($password, $algorithm, $options);

if (!is_string($hash) || $hash === '') {
    fwrite(STDERR, "Не удалось создать хеш пароля\n");
    exit(1);
}

echo $hash, PHP_EOL;

#!/usr/bin/env php
<?php

declare(strict_types=1);

function base32_encode(string $data): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';

    foreach (str_split($data) as $char) {
        $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }

    $result = '';

    for ($offset = 0; $offset < strlen($bits); $offset += 5) {
        $chunk = substr($bits, $offset, 5);

        if (strlen($chunk) < 5) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        }

        $result .= $alphabet[bindec($chunk)];
    }

    return $result;
}

$secret = base32_encode(random_bytes(20));
$issuer = trim((string)($argv[1] ?? 'Huawei SMS'));
$label = trim((string)($argv[2] ?? $issuer));

if ($issuer === '') {
    $issuer = 'Huawei SMS';
}

if ($label === '') {
    $label = $issuer;
}

$uri =
    'otpauth://totp/' .
    rawurlencode($label) .
    '?secret=' .
    rawurlencode($secret) .
    '&issuer=' .
    rawurlencode($issuer) .
    '&algorithm=SHA1&digits=6&period=30';

echo "Secret: " . $secret . PHP_EOL;
echo "URI: " . $uri . PHP_EOL;

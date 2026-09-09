<?php

declare(strict_types=1);

require_once __DIR__ . '/quick_login.php';

function notify_html_escape(
    string $value
): string {
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function notify_message_with_open_link(
    string $channel,
    string $prefix
): array {
    $link =
        quick_login_open_link(
            $channel
        );

    $visibleUrl =
        (string)$link['visible_url'];

    $targetUrl =
        (string)$link['target_url'];

    $text =
        $prefix .
        $visibleUrl;

    $html =
        notify_html_escape($prefix) .
        '<a href="' .
        notify_html_escape($targetUrl) .
        '">' .
        notify_html_escape($visibleUrl) .
        '</a>';

    /*
     * Matrix использует HTML formatted_body.
     * Telegram и MAX сохраняют обычные переводы строк.
     */
    if ($channel === 'matrix') {
        $html = str_replace(
            "\n",
            "<br>\n",
            $html
        );
    }

    return [
        'text' => $text,
        'html' => $html,
        'quick_login' =>
            !empty($link['quick_login']),
    ];
}

function notify_read_secret(
    array $channel,
    string $valueKey,
    string $fileKey
): string {
    $value = trim((string)($channel[$valueKey] ?? ''));

    if ($value !== '') {
        return $value;
    }

    $file = trim((string)($channel[$fileKey] ?? ''));

    if (
        $file === ''
        || !is_readable($file)
        || is_link($file)
    ) {
        return '';
    }

    $value = file_get_contents($file);

    return $value === false
        ? ''
        : trim($value);
}

function notify_proxy_type(string $type): int
{
    $type = strtolower(trim($type));

    return match ($type) {
        'http' => CURLPROXY_HTTP,
        'socks4' => CURLPROXY_SOCKS4,
        'socks5' => CURLPROXY_SOCKS5,

        'socks4a' => defined('CURLPROXY_SOCKS4A')
            ? constant('CURLPROXY_SOCKS4A')
            : throw new RuntimeException(
                'Текущая сборка cURL не поддерживает SOCKS4A proxy'
            ),

        'socks5h' => defined('CURLPROXY_SOCKS5_HOSTNAME')
            ? constant('CURLPROXY_SOCKS5_HOSTNAME')
            : throw new RuntimeException(
                'Текущая сборка cURL не поддерживает SOCKS5H proxy'
            ),

        'https' => defined('CURLPROXY_HTTPS')
            ? constant('CURLPROXY_HTTPS')
            : throw new RuntimeException(
                'Текущая сборка cURL не поддерживает HTTPS proxy'
            ),

        default => throw new InvalidArgumentException(
            'Неподдерживаемый тип proxy: ' . $type
        ),
    };
}

function notify_proxy_transport_failed(array $result): bool
{
    $errno = (int)($result['curl_errno'] ?? 0);
    $httpCode = (int)($result['http_code'] ?? 0);

    /*
     * Прямое соединение разрешается только если запрос не дошёл до
     * удалённого API через proxy. HTTP-ответ Telegram/MAX (например 400,
     * 401 или 429) не является отказом proxy и не должен обходить его.
     */
    return $errno !== 0
        || $httpCode === 0
        || $httpCode === 407;
}

function notify_curl_attempt(
    string $url,
    array $options,
    ?array $proxy = null
): array {
    $ch = curl_init($url);

    if ($ch === false) {
        return [
            'ok' => false,
            'http_code' => 0,
            'curl_errno' => -1,
            'error' => 'curl_init failed',
            'response' => '',
        ];
    }

    curl_setopt_array(
        $ch,
        $options
    );

    if ($proxy !== null) {
        $proxyUrl = trim(
            (string)($proxy['url'] ?? '')
        );

        if ($proxyUrl !== '') {
            curl_setopt(
                $ch,
                CURLOPT_PROXY,
                $proxyUrl
            );

            try {
                $proxyType = notify_proxy_type(
                    (string)($proxy['type'] ?? 'http')
                );
            } catch (Throwable $exception) {

                return [
                    'ok' => false,
                    'http_code' => 0,
                    'curl_errno' => -2,
                    'error' => $exception->getMessage(),
                    'response' => '',
                ];
            }

            curl_setopt(
                $ch,
                CURLOPT_PROXYTYPE,
                $proxyType
            );

            $username = (string)(
                $proxy['username']
                ?? ''
            );

            $password = (string)(
                $proxy['password']
                ?? ''
            );

            if (
                $username !== ''
                || $password !== ''
            ) {
                curl_setopt(
                    $ch,
                    CURLOPT_PROXYUSERPWD,
                    $username . ':' . $password
                );

                curl_setopt(
                    $ch,
                    CURLOPT_PROXYAUTH,
                    CURLAUTH_ANY
                );
            }
        }
    }

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $errno = curl_errno($ch);

    $code = (int)curl_getinfo(
        $ch,
        CURLINFO_RESPONSE_CODE
    );


    return [
        'ok' =>
            $response !== false
            && $code >= 200
            && $code < 300,

        'http_code' => $code,
        'curl_errno' => $errno,
        'error' => $error,

        'response' =>
            $response === false
                ? ''
                : (string)$response,
    ];
}

function notify_make_dispatch_id(): string
{
    try {
        return bin2hex(
            random_bytes(8)
        );
    } catch (Throwable) {
        return substr(
            hash(
                'sha256',
                microtime(true) .
                ':' .
                getmypid() .
                ':' .
                mt_rand()
            ),
            0,
            16
        );
    }
}

function notify_log_context_suffix(): string
{
    $context =
        $GLOBALS['notify_log_context']
        ?? null;

    if (!is_array($context)) {
        return '';
    }

    $parts = [];

    $dispatchId = trim(
        (string)(
            $context['dispatch_id']
            ?? ''
        )
    );

    $event = trim(
        (string)(
            $context['event']
            ?? ''
        )
    );

    $messageHash = trim(
        (string)(
            $context['message_sha256']
            ?? ''
        )
    );

    $messageBytes = (int)(
        $context['message_bytes']
        ?? 0
    );

    if ($dispatchId !== '') {
        $parts[] =
            'dispatch_id="' .
            app_log_clean($dispatchId) .
            '"';
    }

    if ($event !== '') {
        $parts[] =
            'event="' .
            app_log_clean($event) .
            '"';
    }

    if ($messageHash !== '') {
        $parts[] =
            'message_sha256=' .
            $messageHash;
    }

    $parts[] =
        'message_bytes=' .
        $messageBytes;

    return $parts === []
        ? ''
        : ' ' . implode(
            ' ',
            $parts
        );
}

function notify_response_remote_id(
    string $channel,
    string $response
): string {
    if ($response === '') {
        return '';
    }

    $decoded = json_decode(
        $response,
        true
    );

    if (!is_array($decoded)) {
        return '';
    }

    $value = match ($channel) {
        'telegram' =>
            $decoded['result']['message_id']
            ?? '',

        'matrix' =>
            $decoded['event_id']
            ?? '',

        'max' =>
            $decoded['message']['body']['mid']
            ?? $decoded['message']['mid']
            ?? $decoded['message_id']
            ?? $decoded['id']
            ?? '',

        default => '',
    };

    if (!is_scalar($value)) {
        return '';
    }

    return trim(
        (string)$value
    );
}

function notify_response_remote_error(
    string $channel,
    string $response
): string {
    if ($response === '') {
        return '';
    }

    $decoded = json_decode(
        $response,
        true
    );

    if (!is_array($decoded)) {
        return '';
    }

    if ($channel === 'telegram') {
        return trim(
            (string)(
                $decoded['description']
                ?? ''
            )
        );
    }

    if ($channel === 'matrix') {
        $code = trim(
            (string)(
                $decoded['errcode']
                ?? ''
            )
        );

        $error = trim(
            (string)(
                $decoded['error']
                ?? ''
            )
        );

        return trim(
            $code .
            (
                $code !== ''
                && $error !== ''
                    ? ': '
                    : ''
            ) .
            $error
        );
    }

    foreach (
        [
            'error_description',
            'error',
            'message',
        ] as $key
    ) {
        $value =
            $decoded[$key]
            ?? null;

        if (
            is_scalar($value)
            && trim((string)$value) !== ''
        ) {
            return trim(
                (string)$value
            );
        }
    }

    return '';
}

function notify_result_log_details(
    string $channel,
    array $result
): string {
    $response = (string)(
        $result['response']
        ?? ''
    );

    $curlError = trim(
        (string)(
            $result['error']
            ?? ''
        )
    );

    $parts = [
        'http=' .
        (int)(
            $result['http_code']
            ?? 0
        ),

        'curl_errno=' .
        (int)(
            $result['curl_errno']
            ?? 0
        ),

        'response_bytes=' .
        strlen($response),
    ];

    if ($response !== '') {
        $parts[] =
            'response_sha256=' .
            hash(
                'sha256',
                $response
            );

        $remoteId =
            notify_response_remote_id(
                $channel,
                $response
            );

        if ($remoteId !== '') {
            $parts[] =
                'remote_id="' .
                app_log_clean(
                    substr(
                        $remoteId,
                        0,
                        240
                    )
                ) .
                '"';
        }

        $remoteError =
            notify_response_remote_error(
                $channel,
                $response
            );

        if ($remoteError !== '') {
            $parts[] =
                'remote_error="' .
                app_log_clean(
                    substr(
                        $remoteError,
                        0,
                        300
                    )
                ) .
                '"';
        }
    }

    if ($curlError !== '') {
        $parts[] =
            'curl_error="' .
            app_log_clean(
                substr(
                    $curlError,
                    0,
                    300
                )
            ) .
            '"';
    }

    return implode(
        ' ',
        $parts
    );
}

function notify_send_transport(
    string $channel,
    string $url,
    array $options,
    array $proxyConfig = [],
    ?callable $validator = null
): bool {
    $proxyEnabled =
        !empty(
            $proxyConfig['enabled']
        );

    $proxyUrl = trim(
        (string)(
            $proxyConfig['url']
            ?? ''
        )
    );

    $fallbackDirect =
        !empty(
            $proxyConfig['fallback_direct']
        );

    $validate = static function (
        array $result
    ) use (
        $validator
    ): bool {
        if (empty($result['ok'])) {
            return false;
        }

        return $validator === null
            || $validator(
                (string)$result['response']
            );
    };

    if (
        $proxyEnabled
        && $proxyUrl !== ''
    ) {
        $result = notify_curl_attempt(
            $url,
            $options,
            $proxyConfig
        );

        if ($validate($result)) {
            app_log(
                'INFO notification_sent' .
                ' channel=' .
                $channel .
                ' transport=proxy' .
                notify_log_context_suffix() .
                ' ' .
                notify_result_log_details(
                    $channel,
                    $result
                )
            );

            return true;
        }

        app_log(
            'WARN notification_failed' .
            ' channel=' .
            $channel .
            ' transport=proxy' .
            notify_log_context_suffix() .
            ' ' .
            notify_result_log_details(
                $channel,
                $result
            )
        );

        if (
            !$fallbackDirect
            || !notify_proxy_transport_failed($result)
        ) {
            return false;
        }

        app_log(
            'INFO notification_direct_fallback' .
            ' channel=' .
            $channel .
            notify_log_context_suffix()
        );
    } elseif ($proxyEnabled) {
        app_log(
            'ERROR notification_proxy_url_missing' .
            ' channel=' .
            $channel .
            notify_log_context_suffix()
        );

        /*
         * Пустой адрес — ошибка конфигурации, а не отказ proxy.
         * Не обходим явно включённый proxy прямым соединением.
         */
        return false;
    }

    $result = notify_curl_attempt(
        $url,
        $options
    );

    if ($validate($result)) {
        app_log(
            'INFO notification_sent' .
            ' channel=' .
            $channel .
            ' transport=direct' .
            notify_log_context_suffix() .
            ' ' .
            notify_result_log_details(
                $channel,
                $result
            )
        );

        return true;
    }

    app_log(
        'ERROR notification_failed' .
        ' channel=' .
        $channel .
        ' transport=direct' .
        notify_log_context_suffix() .
        ' ' .
        notify_result_log_details(
            $channel,
            $result
        )
    );

    return false;
}

function notify_dispatch_messages(
    callable $builder,
    string $event = ''
): bool {
    global $config;

    if (
        empty(
            $config['notifications']['enabled']
        )
    ) {
        return true;
    }

    $channels =
        notify_enabled_channels();

    if ($channels === []) {
        app_log(
            'ERROR notifications_enabled_without_channels'
        );

        return false;
    }

    $dispatchId =
        notify_make_dispatch_id();

    app_log(
        'INFO notification_dispatch' .
        ' dispatch_id="' .
        app_log_clean($dispatchId) .
        '"' .
        ' event="' .
        app_log_clean(trim($event)) .
        '"' .
        ' channels="' .
        app_log_clean(
            implode(',', $channels)
        ) .
        '"'
    );

    $hadPreviousContext =
        array_key_exists(
            'notify_log_context',
            $GLOBALS
        );

    $previousContext =
        $GLOBALS['notify_log_context']
        ?? null;

    $sent = [];
    $failed = [];

    try {
        foreach ($channels as $channel) {
            try {
                $message =
                    $builder($channel);

                if (!is_array($message)) {
                    throw new RuntimeException(
                        'Notification builder returned invalid value'
                    );
                }

                $text = (string)(
                    $message['text']
                    ?? ''
                );

                $html =
                    isset($message['html'])
                    && is_string($message['html'])
                        ? $message['html']
                        : null;

                $hashSource =
                    $text .
                    "\0" .
                    ($html ?? '');

                $GLOBALS['notify_log_context'] = [
                    'dispatch_id' =>
                        $dispatchId,

                    'event' =>
                        trim($event),

                    /*
                     * В журнал попадает только хеш сообщения.
                     * Одноразовая ссылка и сам токен не записываются.
                     */
                    'message_sha256' =>
                        hash(
                            'sha256',
                            $hashSource
                        ),

                    'message_bytes' =>
                        strlen($text)
                        + strlen($html ?? ''),
                ];

                $ok =
                    notify_send_channel(
                        $channel,
                        $text,
                        $html
                    );
            } catch (Throwable $exception) {
                app_log(
                    'ERROR notification_build_failed' .
                    ' channel=' .
                    $channel .
                    ' dispatch_id="' .
                    app_log_clean($dispatchId) .
                    '"' .
                    ' error="' .
                    app_log_clean(
                        $exception->getMessage()
                    ) .
                    '"'
                );

                $ok = false;
            }

            if ($ok) {
                $sent[] = $channel;
            } else {
                $failed[] = $channel;
            }
        }
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

    $level =
        $sent === []
            ? 'ERROR'
            : (
                $failed === []
                    ? 'INFO'
                    : 'WARN'
            );

    app_log(
        $level .
        ' notification_dispatch_result' .
        ' dispatch_id="' .
        app_log_clean($dispatchId) .
        '"' .
        ' event="' .
        app_log_clean(trim($event)) .
        '"' .
        ' sent="' .
        app_log_clean(
            implode(',', $sent)
        ) .
        '"' .
        ' failed="' .
        app_log_clean(
            implode(',', $failed)
        ) .
        '"'
    );

    return $failed === [];
}

function notify_send(
    string $text,
    string $event = ''
): bool {
    return notify_dispatch_messages(
        static fn(string $channel): array => [
            'text' => $text,
            'html' => null,
        ],
        $event
    );
}

function notify_send_with_open_link(
    string $prefix,
    string $event = ''
): bool {
    return notify_dispatch_messages(
        static fn(string $channel): array =>
            notify_message_with_open_link(
                $channel,
                $prefix
            ),
        $event
    );
}

function tg_send(
    string $text,
    ?string $htmlText = null
): bool {
    global $config;

    $tg = (array)(
        $config['notifications']['telegram']
        ?? []
    );

    if (empty($tg['enabled'])) {
        return true;
    }

    $token = notify_read_secret(
        $tg,
        'bot_token',
        'bot_token_file'
    );

    $chatId = trim(
        (string)(
            $tg['chat_id']
            ?? ''
        )
    );

    if (
        $token === ''
        || $chatId === ''
    ) {
        app_log(
            'ERROR telegram_config_missing' .
            notify_log_context_suffix()
        );

        return false;
    }

    if (
        !preg_match(
            '/^[0-9]+:[A-Za-z0-9_-]+$/',
            $token
        )
    ) {
        app_log(
            'ERROR telegram_token_invalid' .
            notify_log_context_suffix()
        );

        return false;
    }

    $url =
        'https://api.telegram.org/bot' .
        $token .
        '/sendMessage';

    $payload = [
        'chat_id' =>
            $chatId,

        'text' =>
            $htmlText !== null
                ? $htmlText
                : $text,

        /*
         * Современный Bot API использует LinkPreviewOptions.
         */
        'link_preview_options' =>
            '{"is_disabled":true}',
    ];

    if ($htmlText !== null) {
        $payload['parse_mode'] =
            'HTML';
    }

    $options = [
        CURLOPT_POST =>
            true,

        CURLOPT_RETURNTRANSFER =>
            true,

        CURLOPT_POSTFIELDS =>
            $payload,

        CURLOPT_CONNECTTIMEOUT =>
            max(
                1,
                (int)(
                    $config['notifications']['connect_timeout']
                    ?? 5
                )
            ),

        CURLOPT_TIMEOUT =>
            max(
                1,
                (int)(
                    $config['notifications']['timeout']
                    ?? 15
                )
            ),
    ];

    return notify_send_transport(
        'telegram',
        $url,
        $options,
        (array)(
            $tg['proxy']
            ?? []
        ),
        static function (
            string $response
        ): bool {
            $decoded = json_decode(
                $response,
                true
            );

            return
                is_array($decoded)
                && (
                    $decoded['ok']
                    ?? false
                ) === true;
        }
    );
}

function matrix_send(
    string $text,
    ?string $htmlText = null
): bool {
    global $config;

    $mx = (array)(
        $config['notifications']['matrix']
        ?? []
    );

    if (empty($mx['enabled'])) {
        return true;
    }

    $homeserver = rtrim(
        (string)(
            $mx['homeserver']
            ?? ''
        ),
        '/'
    );

    $roomId = trim(
        (string)(
            $mx['room_id']
            ?? ''
        )
    );

    $token = notify_read_secret(
        $mx,
        'access_token',
        'access_token_file'
    );

    if (
        $homeserver === ''
        || $roomId === ''
        || $token === ''
    ) {
        app_log(
            'ERROR matrix_config_missing' .
            notify_log_context_suffix()
        );

        return false;
    }

    try {
        $txn = bin2hex(
            random_bytes(12)
        );

        $message = [
            'msgtype' => 'm.text',
            'body' => $text,
        ];

        if ($htmlText !== null) {
            $message['format'] =
                'org.matrix.custom.html';

            $message['formatted_body'] =
                $htmlText;
        }

        $payload = json_encode(
            $message,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_THROW_ON_ERROR
        );
    } catch (Throwable $exception) {
        app_log(
            'ERROR matrix_payload_failed' .
            notify_log_context_suffix() .
            ' error="' .
            app_log_clean(
                $exception->getMessage()
            ) .
            '"'
        );

        return false;
    }

    $url =
        $homeserver .
        '/_matrix/client/v3/rooms/' .
        rawurlencode($roomId) .
        '/send/m.room.message/' .
        rawurlencode($txn);

    $options = [
        CURLOPT_CUSTOMREQUEST =>
            'PUT',

        CURLOPT_RETURNTRANSFER =>
            true,

        CURLOPT_POSTFIELDS =>
            $payload,

        CURLOPT_CONNECTTIMEOUT =>
            max(
                1,
                (int)(
                    $config['notifications']['connect_timeout']
                    ?? 5
                )
            ),

        CURLOPT_TIMEOUT =>
            max(
                1,
                (int)(
                    $config['notifications']['timeout']
                    ?? 15
                )
            ),

        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' .
            $token,

            'Content-Type: application/json',
        ],
    ];

    return notify_send_transport(
        'matrix',
        $url,
        $options
    );
}

function max_send(
    string $text,
    ?string $htmlText = null
): bool {
    global $config;

    $mx = (array)(
        $config['notifications']['max']
        ?? []
    );

    if (empty($mx['enabled'])) {
        return true;
    }

    $token = notify_read_secret(
        $mx,
        'token',
        'token_file'
    );

    $chatId = trim(
        (string)(
            $mx['chat_id']
            ?? ''
        )
    );

    $apiUrl = rtrim(
        (string)(
            $mx['api_url']
            ?? 'https://platform-api2.max.ru/messages'
        ),
        '?'
    );

    if (
        $token === ''
        || $chatId === ''
        || $apiUrl === ''
    ) {
        app_log(
            'ERROR max_config_missing' .
            notify_log_context_suffix()
        );

        return false;
    }

    try {
        $message = [
            'text' =>
                $htmlText !== null
                    ? $htmlText
                    : $text,
        ];

        if ($htmlText !== null) {
            $message['format'] =
                'html';
        }

        $payload = json_encode(
            $message,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_THROW_ON_ERROR
        );
    } catch (Throwable $exception) {
        app_log(
            'ERROR max_payload_failed' .
            notify_log_context_suffix() .
            ' error="' .
            app_log_clean(
                $exception->getMessage()
            ) .
            '"'
        );

        return false;
    }

    $url =
        $apiUrl .
        '?chat_id=' .
        rawurlencode($chatId) .
        '&disable_link_preview=true';

    $options = [
        CURLOPT_POST =>
            true,

        CURLOPT_RETURNTRANSFER =>
            true,

        CURLOPT_POSTFIELDS =>
            $payload,

        CURLOPT_CONNECTTIMEOUT =>
            max(
                1,
                (int)(
                    $config['notifications']['connect_timeout']
                    ?? 5
                )
            ),

        CURLOPT_TIMEOUT =>
            max(
                1,
                (int)(
                    $config['notifications']['timeout']
                    ?? 15
                )
            ),

        CURLOPT_HTTPHEADER => [
            'Authorization: ' .
            $token,

            'Content-Type: application/json',
        ],
    ];

    return notify_send_transport(
        'max',
        $url,
        $options,
        (array)(
            $mx['proxy']
            ?? []
        )
    );
}

function notify_enabled_channels(): array
{
    global $config;

    if (
        empty(
            $config['notifications']['enabled']
        )
    ) {
        return [];
    }

    $channels = [];

    foreach (
        [
            'telegram',
            'matrix',
            'max',
        ] as $channel
    ) {
        if (
            !empty(
                $config['notifications'][$channel]['enabled']
            )
        ) {
            $channels[] =
                $channel;
        }
    }

    return $channels;
}

function notify_send_channel(
    string $channel,
    string $text,
    ?string $htmlText = null
): bool {
    return match ($channel) {
        'telegram' =>
            tg_send(
                $text,
                $htmlText
            ),

        'matrix' =>
            matrix_send(
                $text,
                $htmlText
            ),

        'max' =>
            max_send(
                $text,
                $htmlText
            ),

        default =>
            false,
    };
}

function mask_phone(string $phone): string
{
    $digits = preg_replace(
        '/\D+/',
        '',
        trim($phone)
    ) ?? '';

    if (
        strlen($digits) === 11
        && str_starts_with(
            $digits,
            '8'
        )
    ) {
        $digits =
            '7' .
            substr(
                $digits,
                1
            );
    }

    if (
        strlen($digits) === 11
        && str_starts_with(
            $digits,
            '7'
        )
    ) {
        return
            '+7' .
            str_repeat(
                '*',
                7
            ) .
            substr(
                $digits,
                -2
            );
    }

    if (strlen($digits) >= 7) {
        return
            '+' .
            substr(
                $digits,
                0,
                2
            ) .
            str_repeat(
                '*',
                max(
                    3,
                    strlen($digits) - 4
                )
            ) .
            substr(
                $digits,
                -2
            );
    }

    return $phone;
}

function sms_notification_text(
    array $inbox
): string {
    $count =
        count($inbox);

    if ($count === 1) {
        $sms =
            $inbox[0];

        $phone = mask_phone(
            (string)(
                $sms['phone']
                ?? ''
            )
        );

        $date = (string)(
            $sms['sms_date']
            ?? date('Y-m-d H:i:s')
        );

        return
            "📩 Новое SMS\n\n" .
            "👤 От: " .
            $phone .
            "\n" .
            "🕒 Дата: " .
            $date .
            "\n\n" .
            "🔗 Открыть:\n";
    }

    return
        "📨 Новые SMS\n\n" .
        "🔢 Количество: " .
        $count .
        "\n\n" .
        "🔗 Открыть:\n";
}

function sms_notification_channel_sent(
    string $fingerprint,
    string $channel
): bool {
    $stmt = db()->prepare("
        SELECT status
        FROM sms_notification_deliveries
        WHERE source_fingerprint = ?
          AND channel = ?
    ");

    $stmt->execute([
        $fingerprint,
        $channel,
    ]);

    return
        (string)(
            $stmt->fetchColumn()
            ?: ''
        ) === 'sent';
}

function sms_notification_record_delivery(
    string $fingerprint,
    string $channel,
    bool $sent,
    ?string $error = null
): void {
    $status =
        $sent
            ? 'sent'
            : 'failed';

    $stmt = db()->prepare("
        INSERT INTO sms_notification_deliveries(
            source_fingerprint,
            channel,
            status,
            attempts,
            last_error,
            updated_at
        )
        VALUES(
            ?,
            ?,
            ?,
            1,
            ?,
            ?
        )
        ON CONFLICT(
            source_fingerprint,
            channel
        )
        DO UPDATE SET
            status = excluded.status,
            attempts =
                sms_notification_deliveries.attempts + 1,
            last_error =
                excluded.last_error,
            updated_at =
                excluded.updated_at
    ");

    $stmt->execute([
        $fingerprint,
        $channel,
        $status,
        $error,
        date('Y-m-d H:i:s'),
    ]);
}

function sms_notification_update_final_status(
    array $inbox,
    array $channels
): bool {
    $allSent = true;

    $mark = db()->prepare(
        'UPDATE sms
         SET notified = 1
         WHERE source_fingerprint = ?'
    );

    foreach (
        $inbox
        as $sms
    ) {
        $fingerprint = trim(
            (string)(
                $sms['source_fingerprint']
                ?? ''
            )
        );

        if ($fingerprint === '') {
            $allSent = false;
            continue;
        }

        $sent = true;

        foreach (
            $channels
            as $channel
        ) {
            if (
                !sms_notification_channel_sent(
                    $fingerprint,
                    $channel
                )
            ) {
                $sent = false;
                break;
            }
        }

        if ($sent) {
            $mark->execute([
                $fingerprint,
            ]);
        } else {
            $allSent = false;
        }
    }

    return $allSent;
}

function sms_notify_batch(
    array $newInbox
): bool {
    global $config;

    if (
        empty(
            $config['notifications']['enabled']
        )
        || empty($newInbox)
    ) {
        return true;
    }

    $channels =
        notify_enabled_channels();

    if ($channels === []) {
        app_log(
            'ERROR notifications_enabled_without_channels'
        );

        return false;
    }

    /*
     * Для входящих SMS сохраняем прежнюю важную механику:
     * каждый канал отслеживается отдельно.
     *
     * Если Telegram уже получил уведомление, а Matrix нет,
     * следующий poll повторит только Matrix.
     */
    $pendingByChannel = [];

    foreach (
        $channels
        as $channel
    ) {
        $channelInbox = [];

        foreach (
            $newInbox
            as $sms
        ) {
            $fingerprint = trim(
                (string)(
                    $sms['source_fingerprint']
                    ?? ''
                )
            );

            if (
                $fingerprint !== ''
                && !sms_notification_channel_sent(
                    $fingerprint,
                    $channel
                )
            ) {
                $channelInbox[] =
                    $sms;
            }
        }

        if ($channelInbox !== []) {
            $pendingByChannel[$channel] =
                $channelInbox;
        }
    }

    /*
     * Всё уже было доставлено.
     * Только синхронизируем notified в sms.
     */
    if ($pendingByChannel === []) {
        return sms_notification_update_final_status(
            $newInbox,
            $channels
        );
    }

    $dispatchId =
        notify_make_dispatch_id();

    $attemptedChannels =
        array_keys(
            $pendingByChannel
        );

    app_log(
        'INFO notification_sms_batch' .
        ' dispatch_id="' .
        app_log_clean($dispatchId) .
        '"' .
        ' event="new_sms"' .
        ' channels="' .
        app_log_clean(
            implode(
                ',',
                $attemptedChannels
            )
        ) .
        '"' .
        ' sms_count=' .
        count($newInbox)
    );

    $hadPreviousContext =
        array_key_exists(
            'notify_log_context',
            $GLOBALS
        );

    $previousContext =
        $GLOBALS['notify_log_context']
        ?? null;

    $sentChannels = [];
    $failedChannels = [];

    try {
        foreach (
            $pendingByChannel
            as $channel => $channelInbox
        ) {
            $sent = false;
            $deliveryError =
                'Channel delivery failed';

            /*
             * Сбрасываем контекст в начале каждой итерации,
             * чтобы ошибка построения quick-link не унаследовала
             * hash предыдущего канала.
             */
            $GLOBALS['notify_log_context'] = [
                'dispatch_id' =>
                    $dispatchId,

                'event' =>
                    'new_sms',

                'message_sha256' =>
                    '',

                'message_bytes' =>
                    0,
            ];

            try {
                $message =
                    notify_message_with_open_link(
                        $channel,
                        sms_notification_text(
                            $channelInbox
                        )
                    );

                $text =
                    (string)$message['text'];

                $html =
                    (string)$message['html'];

                /*
                 * dispatch_id общий для этого прохода batch.
                 *
                 * message_sha256/message_bytes рассчитываются
                 * отдельно для каждого канала, потому что при retry
                 * набор SMS для Telegram/Matrix/MAX может отличаться.
                 */
                $GLOBALS['notify_log_context'] = [
                    'dispatch_id' =>
                        $dispatchId,

                    'event' =>
                        'new_sms',

                    'message_sha256' =>
                        hash(
                            'sha256',
                            $text .
                            "\0" .
                            $html
                        ),

                    'message_bytes' =>
                        strlen($text)
                        + strlen($html),
                ];

                $sent = notify_send_channel(
                    $channel,
                    $text,
                    $html
                );

                if ($sent) {
                    $deliveryError = null;
                }
            } catch (Throwable $exception) {
                /*
                 * Ошибка одного транспорта или создания его
                 * quick-link не должна прерывать fan-out.
                 */
                $deliveryError =
                    'Channel exception';

                app_log(
                    'ERROR notification_sms_channel_exception' .
                    ' channel=' .
                    app_log_clean($channel) .
                    ' dispatch_id="' .
                    app_log_clean($dispatchId) .
                    '"' .
                    ' event="new_sms"' .
                    ' exception="' .
                    app_log_clean(
                        $exception::class
                    ) .
                    '"' .
                    ' error_sha256=' .
                    hash(
                        'sha256',
                        $exception->getMessage()
                    )
                );
            }

            if ($sent) {
                $sentChannels[] =
                    $channel;
            } else {
                $failedChannels[] =
                    $channel;
            }

            foreach (
                $channelInbox
                as $sms
            ) {
                $fingerprint = trim(
                    (string)(
                        $sms['source_fingerprint']
                        ?? ''
                    )
                );

                if ($fingerprint === '') {
                    continue;
                }

                try {
                    sms_notification_record_delivery(
                        $fingerprint,
                        $channel,
                        $sent,
                        $deliveryError
                    );
                } catch (Throwable $exception) {
                    /*
                     * Ошибка записи delivery-state также не должна
                     * мешать попытке доставки в следующий канал.
                     * Не записанная отметка будет повторена poll.
                     */
                    app_log(
                        'ERROR notification_sms_delivery_state_failed' .
                        ' channel=' .
                        app_log_clean($channel) .
                        ' dispatch_id="' .
                        app_log_clean($dispatchId) .
                        '"' .
                        ' event="new_sms"' .
                        ' exception="' .
                        app_log_clean(
                            $exception::class
                        ) .
                        '"' .
                        ' error_sha256=' .
                        hash(
                            'sha256',
                            $exception->getMessage()
                        )
                    );
                }
            }
        }
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

    $allSent =
        sms_notification_update_final_status(
            $newInbox,
            $channels
        );

    $level =
        $failedChannels !== []
            ? 'WARN'
            : 'INFO';

    app_log(
        $level .
        ' notification_sms_batch_result' .
        ' dispatch_id="' .
        app_log_clean($dispatchId) .
        '"' .
        ' event="new_sms"' .
        ' sent="' .
        app_log_clean(
            implode(
                ',',
                $sentChannels
            )
        ) .
        '"' .
        ' failed="' .
        app_log_clean(
            implode(
                ',',
                $failedChannels
            )
        ) .
        '"' .
        ' all_delivered=' .
        (
            $allSent
                ? '1'
                : '0'
        )
    );

    return $allSent;
}

function auth_notify(
    string $event,
    string $ip,
    string $username = '-',
    string $userAgent = ''
): void {
    global $config;

    if (
        empty(
            $config['notifications']['enabled']
        )
    ) {
        return;
    }

    $authNotify =
        $config['notifications']['auth']
        ?? [];

    $enabled = match ($event) {
        'success' =>
            !empty(
                $authNotify['login_success']
            ),

        'failed' =>
            !empty(
                $authNotify['login_failed']
            ),

        'blocked' =>
            !empty(
                $authNotify['login_blocked']
            ),

        'csrf_failed' =>
            !empty(
                $authNotify['csrf_failed']
            ),

        default =>
            false,
    };

    if (!$enabled) {
        return;
    }

    $labels = [
        'success' =>
            '✅ Выполнен вход в кабинет',

        'failed' =>
            '❌ Неудачная попытка входа',

        'blocked' =>
            '⛔ Вход временно заблокирован',

        'csrf_failed' =>
            '⚠️ Ошибка проверки CSRF',
    ];

    $title =
        $labels[$event]
        ?? 'Событие авторизации';

    $username =
        app_log_clean(
            $username
        );

    $userAgent = mb_substr(
        trim(
            preg_replace(
                '/\s+/u',
                ' ',
                $userAgent
            )
            ?? ''
        ),
        0,
        180,
        'UTF-8'
    );

    $appName = trim(
        (string)(
            $config['app']['name']
            ?? 'Huawei SMS'
        )
    );

    $prefix =
        "🔐 " .
        $appName .
        "\n\n" .
        $title .
        "\n\n" .
        "🌐 IP: " .
        $ip .
        "\n" .
        "👤 Логин: " .
        $username .
        "\n" .
        (
            $userAgent !== ''
                ? "🧭 Клиент: " .
                    $userAgent .
                    "\n"
                : ''
        ) .
        "\n🔗 Открыть:\n";

    $openUrl =
        rtrim(
            (string)(
                $config['app']['base_url']
                ?? ''
            ),
            '/'
        ) .
        '/';

    notify_send(
        $prefix . $openUrl,
        'auth_' . $event
    );
}

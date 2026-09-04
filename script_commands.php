<?php

declare(strict_types=1);

/*
 * Безопасный реестр внешних SMS-команд.
 *
 * Основные правила:
 *
 * - имя и путь скрипта берутся только из config.php;
 * - shell для запуска не используется;
 * - proc_open() получает массив отдельных аргументов;
 * - каноническое имя команды всегда латинское;
 * - русские имена работают только как явно заданные алиасы;
 * - алиасы аргументов нормализуются до запуска скрипта;
 * - скрипт получает только проверенные канонические аргументы;
 * - скрипты выполняются последовательно через flock();
 * - подробный аудит записывается в JSONL.
 */

function sms_script_command_effective_uid(): ?int
{
    if (function_exists('posix_geteuid')) {
        return posix_geteuid();
    }

    $status = @file_get_contents('/proc/self/status');

    if (
        is_string($status)
        && preg_match('/^Uid:\s+\d+\s+(\d+)/m', $status, $match) === 1
    ) {
        return (int)$match[1];
    }

    return null;
}

function sms_script_command_unicode_normalize(string $value): string
{
    if (class_exists('Normalizer')) {
        $normalized = Normalizer::normalize(
            $value,
            Normalizer::FORM_C
        );

        if (is_string($normalized)) {
            return $normalized;
        }
    }

    return $value;
}

function sms_script_command_normalize_name(string $name): string
{
    return mb_strtolower(
        trim(
            sms_script_command_unicode_normalize($name)
        ),
        'UTF-8'
    );
}

function sms_script_command_reserved_names(): array
{
    global $config;

    $reserved = [
        'help',
        'помощь',
        'команды',
    ];

    foreach ((array)($config['sms_commands']['help_commands'] ?? []) as $name) {
        $reserved[] = sms_script_command_normalize_name((string)$name);
    }

    return array_values(
        array_unique(
            array_filter(
                $reserved,
                static fn(string $name): bool => $name !== ''
            )
        )
    );
}

function sms_script_command_alias_is_valid(string $alias): bool
{
    /*
     * Алиас должен быть либо полностью латинским,
     * либо полностью кириллическим.
     *
     * Смешанные варианты вроде pду запрещены.
     */
    return preg_match(
        '/\A(?:[a-z0-9][a-z0-9_-]{0,31}'
        . '|[а-яё0-9][а-яё0-9_-]{0,31})\z/iu',
        $alias
    ) === 1;
}

function sms_script_command_registry(): array
{
    global $config;

    static $registry = null;

    if (is_array($registry)) {
        return $registry;
    }

    $registry = [];

    $reserved = array_fill_keys(
        sms_script_command_reserved_names(),
        true
    );

    foreach (
        (array)($config['sms_commands']['script_commands'] ?? [])
        as $key => $entry
    ) {
        if (!is_array($entry)) {
            continue;
        }

        $canonicalName = trim(
            (string)($entry['script_command'] ?? '')
        );

        if (
            $canonicalName === ''
            && is_string($key)
        ) {
            $canonicalName = $key;
        }

        $canonicalName = sms_script_command_normalize_name(
            $canonicalName
        );

        /*
         * Каноническое внутреннее имя только латинское.
         */
        if (
            $canonicalName === ''
            || preg_match(
                '/\A[a-z0-9][a-z0-9_-]{0,31}\z/',
                $canonicalName
            ) !== 1
        ) {
            app_log(
                'ERROR script_command_invalid_name'
                . ' command="'
                . app_log_clean($canonicalName)
                . '"'
            );

            continue;
        }

        if (isset($reserved[$canonicalName])) {
            app_log(
                'ERROR script_command_reserved_name'
                . ' command="'
                . app_log_clean($canonicalName)
                . '"'
            );

            continue;
        }

        $entry['script_command'] = $canonicalName;

        /*
         * Каноническое имя автоматически становится
         * первым разрешённым вариантом вызова.
         */
        $names = array_merge(
            [$canonicalName],
            (array)($entry['aliases'] ?? [])
        );

        foreach ($names as $configuredAlias) {
            $alias = sms_script_command_normalize_name(
                (string)$configuredAlias
            );

            if (
                $alias === ''
                || !sms_script_command_alias_is_valid($alias)
            ) {
                app_log(
                    'ERROR script_command_invalid_alias'
                    . ' command="'
                    . app_log_clean($canonicalName)
                    . '"'
                    . ' alias="'
                    . app_log_clean($alias)
                    . '"'
                );

                continue;
            }

            if (isset($reserved[$alias])) {
                app_log(
                    'ERROR script_command_reserved_alias'
                    . ' command="'
                    . app_log_clean($canonicalName)
                    . '"'
                    . ' alias="'
                    . app_log_clean($alias)
                    . '"'
                );

                continue;
            }

            if (isset($registry[$alias])) {
                /*
                 * Повтор того же алиаса у той же команды
                 * просто игнорируется.
                 */
                if (
                    (string)$registry[$alias]['canonical_name']
                    === $canonicalName
                ) {
                    continue;
                }

                app_log(
                    'ERROR script_command_duplicate_alias'
                    . ' command="'
                    . app_log_clean($canonicalName)
                    . '"'
                    . ' alias="'
                    . app_log_clean($alias)
                    . '"'
                    . ' owner="'
                    . app_log_clean(
                        (string)$registry[$alias]['canonical_name']
                    )
                    . '"'
                );

                continue;
            }

            $registry[$alias] = [
                'canonical_name' => $canonicalName,
                'alias' => $alias,
                'entry' => $entry,
            ];
        }
    }

    return $registry;
}

function sms_script_command_usage(array $entry): string
{
    $usage = trim((string)($entry['usage'] ?? ''));

    return sms_script_command_limit_text($usage, 500);
}

function sms_script_command_help_text(): string
{
    $registry = sms_script_command_registry();
    $seen = [];
    $lines = ['ДОСТУПНЫЕ КОМАНДЫ'];

    foreach ($registry as $registered) {
        $name = (string)$registered['canonical_name'];

        if (isset($seen[$name])) {
            continue;
        }

        $seen[$name] = true;
        $entry = (array)$registered['entry'];

        if (empty($entry['enabled'])) {
            continue;
        }

        $usage = sms_script_command_usage($entry);
        $description = trim((string)($entry['description'] ?? ''));
        $usageLines = array_values(array_filter(
            array_map('trim', explode("\n", $usage !== '' ? $usage : $name)),
            static fn(string $line): bool => $line !== ''
        ));

        foreach ($usageLines as $index => $usageLine) {
            $lines[] = $index === 0 && $description !== ''
                ? $usageLine . ' — ' . $description
                : $usageLine;
        }
    }

    return implode("\n", $lines);
}

function sms_script_command_alias_prefix_args(
    string $invokedAs,
    array $entry
): array {
    foreach ((array)($entry['alias_prefix_args'] ?? []) as $alias => $args) {
        if (!is_string($alias)) {
            throw new RuntimeException('Некорректный alias_prefix_args');
        }

        if (sms_script_command_normalize_name($alias) !== $invokedAs) {
            continue;
        }

        if (!is_array($args)) {
            throw new RuntimeException('Некорректные аргументы алиаса команды');
        }

        $normalized = [];

        foreach ($args as $index => $arg) {
            $arg = sms_script_command_normalize_name((string)$arg);

            if (
                preg_match(
                    '/\A[a-z0-9][a-z0-9_.:@+=,-]{0,63}\z/',
                    $arg
                ) !== 1
            ) {
                throw new RuntimeException(
                    'Небезопасный префикс аргумента №' . ($index + 1)
                );
            }

            $normalized[] = $arg;
        }

        return $normalized;
    }

    return [];
}

function sms_script_command_parse(string $text): array
{
    $normalized = trim(
        preg_replace('/\s+/u', ' ', $text) ?? $text
    );

    if ($normalized === '') {
        return ['matched' => false];
    }

    $tokens = preg_split(
        '/\s+/u',
        $normalized,
        -1,
        PREG_SPLIT_NO_EMPTY
    );

    if (!is_array($tokens) || $tokens === []) {
        return ['matched' => false];
    }

    $invokedAs = sms_script_command_normalize_name(
        (string)array_shift($tokens)
    );

    $registry = sms_script_command_registry();

    if (!isset($registry[$invokedAs])) {
        return ['matched' => false];
    }

    $registered = $registry[$invokedAs];
    $entry = (array)$registered['entry'];
    $rawArgs = array_values($tokens);
    $prefixArgs = sms_script_command_alias_prefix_args(
        $invokedAs,
        $entry
    );

    return [
        'matched' => true,
        'name' => (string)$registered['canonical_name'],
        'invoked_as' => $invokedAs,
        'args' => $rawArgs,
        'execution_args' => array_values(
            array_merge($prefixArgs, $rawArgs)
        ),
        'entry' => $entry,
        'raw' => $normalized,
    ];
}

function sms_script_command_basic_validate_args(
    array $args,
    array $entry
): void {
    $minArgs = max(
        0,
        min(
            32,
            (int)($entry['min_args'] ?? 0)
        )
    );

    $maxArgs = max(
        $minArgs,
        min(
            32,
            (int)($entry['max_args'] ?? 8)
        )
    );

    $maxArgChars = max(
        1,
        min(
            512,
            (int)($entry['max_arg_chars'] ?? 128)
        )
    );

    $maxTotalBytes = max(
        64,
        min(
            8192,
            (int)(
                $entry['max_total_arg_bytes']
                ?? 2048
            )
        )
    );

    if (count($args) < $minArgs) {
        throw new InvalidArgumentException(
            'Недостаточно аргументов'
        );
    }

    if (count($args) > $maxArgs) {
        throw new InvalidArgumentException(
            'Слишком много аргументов'
        );
    }

    $totalBytes = 0;

    foreach ($args as $index => $arg) {
        if (!is_string($arg)) {
            throw new InvalidArgumentException(
                'Аргумент №'
                . ($index + 1)
                . ' должен быть строкой'
            );
        }

        if (
            $arg === ''
            || !mb_check_encoding($arg, 'UTF-8')
            || mb_strlen($arg, 'UTF-8') > $maxArgChars
            || preg_match(
                '/[\x00-\x1F\x7F]/',
                $arg
            ) === 1
        ) {
            throw new InvalidArgumentException(
                'Недопустимый аргумент №'
                . ($index + 1)
            );
        }

        $totalBytes += strlen($arg);
    }

    if ($totalBytes > $maxTotalBytes) {
        throw new InvalidArgumentException(
            'Слишком большой общий размер аргументов'
        );
    }
}

function sms_script_command_argument_alias_map(
    int $position,
    mixed $configuredValues
): array {
    if (!is_array($configuredValues)) {
        throw new RuntimeException(
            'Некорректная карта алиасов аргумента №'
            . ($position + 1)
        );
    }

    $map = [];

    foreach ($configuredValues as $canonical => $aliases) {
        if (!is_string($canonical)) {
            throw new RuntimeException(
                'Некорректное каноническое значение аргумента №'
                . ($position + 1)
            );
        }

        $canonical = sms_script_command_normalize_name(
            $canonical
        );

        /*
         * В скрипт разрешается передавать только безопасное
         * каноническое латинское значение.
         */
        if (
            preg_match(
                '/\A[a-z0-9][a-z0-9_.:@+=,-]{0,63}\z/',
                $canonical
            ) !== 1
        ) {
            throw new RuntimeException(
                'Небезопасное каноническое значение аргумента №'
                . ($position + 1)
            );
        }

        /*
         * Каноническое значение автоматически является
         * допустимым алиасом.
         */
        $allAliases = array_merge(
            [$canonical],
            (array)$aliases
        );

        foreach ($allAliases as $configuredAlias) {
            $alias = sms_script_command_normalize_name(
                (string)$configuredAlias
            );

            if (
                $alias === ''
                || preg_match(
                    '/\A(?:[a-z0-9][a-z0-9_-]{0,63}'
                    . '|[а-яё0-9][а-яё0-9_-]{0,63})\z/iu',
                    $alias
                ) !== 1
            ) {
                throw new RuntimeException(
                    'Некорректный алиас аргумента №'
                    . ($position + 1)
                );
            }

            if (
                isset($map[$alias])
                && $map[$alias] !== $canonical
            ) {
                throw new RuntimeException(
                    'Дублирующийся алиас аргумента №'
                    . ($position + 1)
                );
            }

            $map[$alias] = $canonical;
        }
    }

    return $map;
}

function sms_script_command_normalize_args(
    array $args,
    array $entry
): array {
    $normalized = array_values($args);
    $aliasPositions = [];

    /*
     * Сначала заменяем явно разрешённые русские и английские
     * алиасы каноническими латинскими значениями.
     */
    foreach (
        (array)($entry['argument_aliases'] ?? [])
        as $position => $configuredValues
    ) {
        if (
            filter_var(
                $position,
                FILTER_VALIDATE_INT,
                [
                    'options' => [
                        'min_range' => 0,
                        'max_range' => 31,
                    ],
                ]
            ) === false
        ) {
            throw new RuntimeException(
                'Некорректная позиция argument_aliases'
            );
        }

        $position = (int)$position;
        $aliasPositions[$position] = true;

        if (!array_key_exists($position, $normalized)) {
            continue;
        }

        $incoming = sms_script_command_normalize_name(
            (string)$normalized[$position]
        );

        $map = sms_script_command_argument_alias_map(
            $position,
            $configuredValues
        );

        if (!isset($map[$incoming])) {
            throw new InvalidArgumentException(
                'Недопустимое значение аргумента №'
                . ($position + 1)
            );
        }

        $normalized[$position] = $map[$incoming];
    }

    /*
     * Отдельные регулярные выражения для конкретных позиций.
     */
    $positionPatterns = (array)(
        $entry['argument_patterns'] ?? []
    );

    /*
     * Общий безопасный шаблон.
     *
     * Намеренно не разрешены:
     *
     * ; & | $ ` \ кавычки, скобки, пробелы
     * и управляющие символы.
     *
     * Косая черта тоже не разрешена по умолчанию.
     * Для путей и URL нужно задавать собственный
     * argument_pattern или argument_patterns.
     */
    $defaultPattern = (string)(
        $entry['argument_pattern']
        ?? '/\A[a-zа-яё0-9._:@+=,-]+\z/iu'
    );

    foreach ($normalized as $position => $arg) {
        $pattern = array_key_exists(
            $position,
            $positionPatterns
        )
            ? (string)$positionPatterns[$position]
            : $defaultPattern;

        $match = @preg_match(
            $pattern,
            (string)$arg
        );

        if ($match === false) {
            throw new RuntimeException(
                'Некорректное регулярное выражение для аргумента №'
                . ($position + 1)
            );
        }

        if ($match !== 1) {
            throw new InvalidArgumentException(
                'Недопустимое значение аргумента №'
                . ($position + 1)
            );
        }

        /*
         * Значение из argument_aliases после нормализации
         * обязано быть безопасным латинским токеном.
         */
        if (
            isset($aliasPositions[$position])
            && preg_match(
                '/\A[a-z0-9][a-z0-9_.:@+=,-]{0,63}\z/',
                (string)$arg
            ) !== 1
        ) {
            throw new RuntimeException(
                'Небезопасное нормализованное значение аргумента №'
                . ($position + 1)
            );
        }
    }

    return array_values($normalized);
}

function sms_script_command_clean_text(string $text): string
{
    $text = str_replace("\0", '', $text);

    $text = preg_replace(
        "/\r\n?/",
        "\n",
        $text
    ) ?? $text;

    $text = preg_replace(
        '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
        '',
        $text
    ) ?? $text;

    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding(
            $text,
            'UTF-8',
            'UTF-8'
        );
    }

    return trim($text);
}

function sms_script_command_limit_text(
    string $text,
    int $maxChars
): string {
    $text = sms_script_command_clean_text($text);
    $maxChars = max(1, $maxChars);

    if (
        mb_strlen($text, 'UTF-8')
        <= $maxChars
    ) {
        return $text;
    }

    return rtrim(
        mb_substr(
            $text,
            0,
            max(1, $maxChars - 14),
            'UTF-8'
        )
    ) . "\n[сокращено]";
}

function sms_script_command_assert_safe_directory(string $path): void
{
    $owner = fileowner($path);
    $permissions = fileperms($path);

    if (
        $owner !== 0
        || $permissions === false
        || ($permissions & 0022) !== 0
        || ($permissions & 06000) !== 0
    ) {
        throw new RuntimeException(
            'Небезопасные права каталога скрипта: ' . $path
        );
    }
}

function sms_script_command_resolve_runtime(
    array $entry,
    bool $validationOnly = false
): array {
    global $config;

    $interpreters = [
        'bash' => '/bin/bash',
        'python3' => '/usr/bin/python3',
    ];

    $type = sms_script_command_normalize_name(
        (string)($entry['type'] ?? '')
    );

    if (!isset($interpreters[$type])) {
        throw new RuntimeException('Неподдерживаемый тип обработчика');
    }

    $interpreter = $interpreters[$type];

    if (!is_executable($interpreter)) {
        throw new RuntimeException('Интерпретатор недоступен: ' . $interpreter);
    }

    $configuredRoots = (array)(
        $config['paths']['script_roots']
        ?? [
            $config['paths']['scripts_dir'] ?? (__DIR__ . '/scripts'),
        ]
    );
    $scriptRoots = [];

    foreach ($configuredRoots as $configuredRoot) {
        $root = realpath((string)$configuredRoot);

        if ($root !== false && is_dir($root)) {
            $scriptRoots[] = $root;
        }
    }

    $scriptRoots = array_values(array_unique($scriptRoots));

    if ($scriptRoots === []) {
        throw new RuntimeException('Разрешённые каталоги скриптов не найдены');
    }

    $configuredPath = trim((string)($entry['script_path'] ?? ''));

    if ($configuredPath === '') {
        throw new RuntimeException('Путь к скрипту не задан');
    }

    if (is_link($configuredPath)) {
        throw new RuntimeException('Символические ссылки на скрипты запрещены');
    }

    $scriptPath = realpath($configuredPath);

    if ($scriptPath === false || !is_file($scriptPath)) {
        throw new RuntimeException('Скрипт не найден');
    }

    $allowed = false;

    foreach ($scriptRoots as $scriptRoot) {
        if (
            $scriptPath === $scriptRoot
            || str_starts_with($scriptPath, $scriptRoot . DIRECTORY_SEPARATOR)
        ) {
            $allowed = true;
            break;
        }
    }

    if (!$allowed) {
        throw new RuntimeException('Скрипт находится вне разрешённых каталогов');
    }

    $scriptRootForPath = null;

    foreach ($scriptRoots as $scriptRoot) {
        if (
            $scriptPath === $scriptRoot
            || str_starts_with($scriptPath, $scriptRoot . DIRECTORY_SEPARATOR)
        ) {
            $scriptRootForPath = $scriptRoot;
            break;
        }
    }

    if ($scriptRootForPath === null) {
        throw new RuntimeException('Не удалось определить каталог скрипта');
    }

    sms_script_command_assert_safe_directory($scriptRootForPath);

    $currentDirectory = dirname($scriptPath);
    $directories = [];

    while (
        $currentDirectory !== $scriptRootForPath
        && str_starts_with(
            $currentDirectory,
            $scriptRootForPath . DIRECTORY_SEPARATOR
        )
    ) {
        $directories[] = $currentDirectory;
        $parent = dirname($currentDirectory);

        if ($parent === $currentDirectory) {
            break;
        }

        $currentDirectory = $parent;
    }

    foreach (array_reverse($directories) as $directory) {
        sms_script_command_assert_safe_directory($directory);
    }

    if (!is_readable($scriptPath)) {
        throw new RuntimeException('Скрипт недоступен для чтения');
    }

    $owner = fileowner($scriptPath);
    $permissions = fileperms($scriptPath);

    if ($owner !== 0) {
        throw new RuntimeException('Владелец скрипта должен быть root');
    }

    if ($permissions === false) {
        throw new RuntimeException('Не удалось проверить права скрипта');
    }

    if (($permissions & 0022) !== 0) {
        throw new RuntimeException(
            'Скрипт доступен на запись группе или остальным'
        );
    }

    if (($permissions & 06000) !== 0) {
        throw new RuntimeException('setuid/setgid для скриптов запрещены');
    }

    if (empty($entry['allow_root_execution']) && !$validationOnly) {
        $effectiveUid = sms_script_command_effective_uid();

        if ($effectiveUid === null) {
            throw new RuntimeException(
                'Не удалось определить UID процесса; запуск внешней команды запрещён'
            );
        }

        if ($effectiveUid === 0) {
            throw new RuntimeException('Запуск внешних SMS-команд от root запрещён');
        }
    }

    $runnerConfigured = (string)(
        $config['paths']['command_runner']
        ?? (__DIR__ . '/bin/command-runner.py')
    );

    if (is_link($runnerConfigured)) {
        throw new RuntimeException('Command runner не должен быть ссылкой');
    }

    $runnerPath = realpath($runnerConfigured);

    if ($runnerPath === false || !is_file($runnerPath) || !is_readable($runnerPath)) {
        throw new RuntimeException('Command runner недоступен');
    }

    sms_script_command_assert_safe_directory(dirname($runnerPath));

    $runnerOwner = fileowner($runnerPath);
    $runnerPermissions = fileperms($runnerPath);

    if (
        $runnerOwner !== 0
        || $runnerPermissions === false
        || ($runnerPermissions & 0022) !== 0
        || ($runnerPermissions & 06000) !== 0
    ) {
        throw new RuntimeException('Небезопасные права command runner');
    }

    return [
        'type' => $type,
        'interpreter' => $interpreter,
        'script_path' => $scriptPath,
        'runner_path' => $runnerPath,
        'working_directory' => dirname($scriptPath),
    ];
}

function sms_script_command_terminate_process(
    mixed $process,
    int $processGroupId,
    int $signal
): void {
    $groupSignaled = false;

    if ($processGroupId > 1 && function_exists('posix_kill')) {
        $groupSignaled = @posix_kill(-$processGroupId, $signal);
    }

    if (!$groupSignaled && $processGroupId > 1) {
        $killBinary = is_executable('/usr/bin/kill')
            ? '/usr/bin/kill'
            : '/bin/kill';

        if (is_executable($killBinary)) {
            $descriptors = [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ];

            $killer = @proc_open(
                [
                    $killBinary,
                    '-' . $signal,
                    '--',
                    '-' . $processGroupId,
                ],
                $descriptors,
                $pipes,
                null,
                null,
                ['bypass_shell' => true]
            );

            if (is_resource($killer)) {
                @proc_close($killer);
            }
        }
    }

    if (is_resource($process)) {
        @proc_terminate($process, $signal);
    }
}

function sms_script_command_run_process(
    array $command,
    int $timeoutSeconds,
    int $maxOutputBytes,
    array $environment,
    string $workingDirectory
): array {
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $started = microtime(true);
    $process = proc_open(
        $command,
        $descriptors,
        $pipes,
        $workingDirectory,
        $environment,
        ['bypass_shell' => true]
    );

    if (!is_resource($process)) {
        return [
            'code' => 127,
            'stdout' => '',
            'stderr' => 'proc_open failed',
            'timed_out' => false,
            'output_limited' => false,
            'duration_ms' => 0,
        ];
    }

    $initialStatus = proc_get_status($process);
    $processGroupId = (int)($initialStatus['pid'] ?? 0);

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $timedOut = false;
    $outputLimited = false;
    $exitCode = null;

    while (true) {
        $status = proc_get_status($process);
        $stdout .= (string)fread($pipes[1], 8192);
        $stderr .= (string)fread($pipes[2], 8192);

        if (strlen($stdout) + strlen($stderr) > $maxOutputBytes) {
            $outputLimited = true;
            sms_script_command_terminate_process(
                $process,
                $processGroupId,
                15
            );
            usleep(200000);

            /*
             * SIGKILL отправляется всей process group безусловно.
             * Родитель мог уже завершиться от SIGTERM, пока дочерний
             * процесс проигнорировал сигнал и продолжает работать.
             */
            sms_script_command_terminate_process(
                $process,
                $processGroupId,
                9
            );

            /*
             * Даём ядру короткое время закрыть процессы и каналы.
             */
            usleep(50000);
            break;
        }

        if (empty($status['running'])) {
            $exitCode = (int)$status['exitcode'];
            break;
        }

        if ((microtime(true) - $started) >= $timeoutSeconds) {
            $timedOut = true;
            sms_script_command_terminate_process(
                $process,
                $processGroupId,
                15
            );
            usleep(200000);

            /*
             * SIGKILL отправляется всей process group безусловно.
             * Родитель мог уже завершиться от SIGTERM, пока дочерний
             * процесс проигнорировал сигнал и продолжает работать.
             */
            sms_script_command_terminate_process(
                $process,
                $processGroupId,
                9
            );

            /*
             * Даём ядру короткое время закрыть процессы и каналы.
             */
            usleep(50000);
            break;
        }

        usleep(20000);
    }

    $stdout .= (string)stream_get_contents($pipes[1]);
    $stderr .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closeCode = proc_close($process);

    if ($exitCode === null || $exitCode < 0) {
        $exitCode = $closeCode;
    }

    if ($timedOut) {
        $exitCode = 124;
    } elseif ($outputLimited) {
        $exitCode = 125;
    }

    $stdout = substr($stdout, 0, $maxOutputBytes);
    $stderr = substr($stderr, 0, $maxOutputBytes);

    return [
        'code' => $exitCode,
        'stdout' => sms_script_command_clean_text($stdout),
        'stderr' => sms_script_command_clean_text($stderr),
        'timed_out' => $timedOut,
        'output_limited' => $outputLimited,
        'duration_ms' => (int)round((microtime(true) - $started) * 1000),
    ];
}

function sms_script_command_logged_args(
    array $args,
    array $entry
): mixed {
    if (empty($entry['log_arguments'])) {
        return null;
    }

    $redactIndexes = array_map(
        'intval',
        (array)($entry['redact_args'] ?? [])
    );

    $logged = [];

    foreach ($args as $index => $arg) {
        $logged[] = in_array(
            $index,
            $redactIndexes,
            true
        )
            ? '***'
            : (string)$arg;
    }

    return $logged;
}

function sms_script_command_audit_available(): bool
{
    global $config;

    $logPath = (string)(
        $config['paths']['command_log']
        ?? (__DIR__ . '/logs/commands.log')
    );

    try {
        if (is_link($logPath)) {
            throw new RuntimeException('Command audit log must not be a symlink');
        }

        $handle = fopen($logPath, 'ab');

        if ($handle === false) {
            throw new RuntimeException('Cannot open command audit log');
        }

        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Cannot lock command audit log');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return true;
    } catch (Throwable $exception) {
        app_log(
            'ERROR script_command_audit_unavailable error="' .
            app_log_clean($exception->getMessage()) . '"'
        );
        return false;
    }
}

function sms_script_command_audit(array $record): bool
{
    global $config;

    $logPath = (string)(
        $config['paths']['command_log']
        ?? (__DIR__ . '/logs/commands.log')
    );

    $record = array_merge(['time' => date(DATE_ATOM)], $record);

    try {
        if (is_link($logPath)) {
            throw new RuntimeException('Command audit log must not be a symlink');
        }

        $line = json_encode(
            $record,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        ) . PHP_EOL;

        $handle = fopen($logPath, 'ab');

        if ($handle === false) {
            throw new RuntimeException('Cannot open command audit log');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Cannot lock command audit log');
            }

            $written = fwrite($handle, $line);

            if ($written === false || $written !== strlen($line)) {
                throw new RuntimeException('Cannot write command audit log');
            }

            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return true;
    } catch (Throwable $exception) {
        app_log(
            'ERROR script_command_audit_failed error="' .
            app_log_clean($exception->getMessage()) . '"'
        );
        return false;
    }
}

function sms_script_command_execute(
    array $parsed,
    string $phone,
    string $fingerprint
): array {
    global $config;

    $name = (string)$parsed['name'];
    $invokedAs = (string)($parsed['invoked_as'] ?? $name);
    $rawArgs = array_values((array)($parsed['args'] ?? []));
    $executionArgs = array_values(
        (array)($parsed['execution_args'] ?? $rawArgs)
    );
    $entry = (array)$parsed['entry'];
    $requestId = bin2hex(random_bytes(8));

    $baseAudit = [
        'request_id' => $requestId,
        'fingerprint' => $fingerprint,
        'phone' => sms_cmd_format_phone($phone),
        'command' => $name,
        'invoked_as' => $invokedAs,
        'raw_args' => sms_script_command_logged_args($rawArgs, $entry),
    ];

    try {
        if (empty($entry['enabled'])) {
            throw new RuntimeException('Команда отключена');
        }

        sms_script_command_basic_validate_args(
            $executionArgs,
            $entry
        );

        $normalizedArgs = sms_script_command_normalize_args(
            $executionArgs,
            $entry
        );

        $runtime = sms_script_command_resolve_runtime($entry);

        if (!empty($entry['audit_required'])) {
            if (!sms_script_command_audit_available()) {
                throw new RuntimeException(
                    'Журнал команд недоступен, выполнение запрещено'
                );
            }

            if (!sms_script_command_audit(array_merge(
                $baseAudit,
                [
                    'event' => 'execution_started',
                    'normalized_args' => sms_script_command_logged_args(
                        $normalizedArgs,
                        $entry
                    ),
                    'handler_type' => $runtime['type'],
                    'script' => $runtime['script_path'],
                    'status' => 'processing',
                ]
            ))) {
                throw new RuntimeException(
                    'Не удалось записать начало выполнения в журнал команд'
                );
            }
        }

        $timeout = max(
            1,
            min(300, (int)($entry['timeout'] ?? 15))
        );
        $maxOutputBytes = max(
            1024,
            min(
                65536,
                (int)($entry['max_output_bytes'] ?? 8192)
            )
        );

        /*
         * command-runner.py создаёт отдельную сессию/группу процессов.
         * Поэтому при таймауте завершается не только Bash/Python,
         * но и запущенные ими дочерние процессы.
         */
        $command = [
            '/usr/bin/python3',
            $runtime['runner_path'],
            $runtime['interpreter'],
            $runtime['script_path'],
            ...$normalizedArgs,
        ];

        $environment = [
            'LC_ALL' => 'C.UTF-8',
            'LANG' => 'C.UTF-8',
            'PATH' =>
                '/usr/local/sbin:/usr/local/bin:' .
                '/usr/sbin:/usr/bin:/sbin:/bin',
            'HOME' => '/nonexistent',
            'TMPDIR' => '/tmp',
            'PYTHONIOENCODING' => 'UTF-8',
            'PYTHONUNBUFFERED' => '1',
            'SMS_PHONE' => sms_cmd_format_phone($phone),
            'SMS_COMMAND' => $name,
            'SMS_COMMAND_ALIAS' => $invokedAs,
            'SMS_REQUEST_ID' => $requestId,
            'SMS_FINGERPRINT' => $fingerprint,
            'SMS_RECEIVED_AT' => date(DATE_ATOM),
            'MODEM_URL' => rtrim(
                (string)($config['modem']['url'] ?? 'http://192.168.8.1'),
                '/'
            ),
            'MODEM_TIMEOUT' => (string)max(
                1,
                (int)($config['modem']['timeout'] ?? 8)
            ),
            'MODEM_LOCK_FILE' => (string)(
                $config['paths']['modem_api_lock']
                ?? (__DIR__ . '/data/modem-api.lock')
            ),
        ];

        $lockPath = (string)(
            $config['paths']['script_command_lock']
            ?? (__DIR__ . '/data/script-commands.lock')
        );

        if (is_link($lockPath)) {
            throw new RuntimeException(
                'Файл блокировки не должен быть ссылкой'
            );
        }

        $lock = fopen($lockPath, 'c');

        if ($lock === false) {
            throw new RuntimeException(
                'Не удалось открыть очередь команд'
            );
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException(
                    'Не удалось заблокировать очередь команд'
                );
            }

            $result = sms_script_command_run_process(
                $command,
                $timeout,
                $maxOutputBytes,
                $environment,
                $runtime['working_directory']
            );
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $stdout = trim((string)$result['stdout']);
        $stderr = trim((string)$result['stderr']);

        if (!empty($result['timed_out'])) {
            $status = 'timeout';
            $reply = 'Команда ' . $name . ': превышено время ожидания';
        } elseif (!empty($result['output_limited'])) {
            $status = 'output_limited';
            $reply = 'Команда ' . $name . ': превышен допустимый размер вывода';
        } elseif ((int)$result['code'] === 0) {
            $status = 'completed';
            $reply = $stdout !== ''
                ? $stdout
                : 'Команда выполнена успешно';
        } else {
            $status = 'failed';
            $reply = $stdout !== ''
                ? $stdout
                : 'Команда завершилась с ошибкой' .
                    "\nКод: " . (int)$result['code'];
        }

        $maxReplyChars = max(
            70,
            min(4000, (int)($entry['max_reply_chars'] ?? 1200))
        );
        $reply = sms_script_command_limit_text($reply, $maxReplyChars);

        sms_script_command_audit(
            array_merge(
                $baseAudit,
                [
                    'event' => 'execution',
                    'normalized_args' =>
                        sms_script_command_logged_args(
                            $normalizedArgs,
                            $entry
                        ),
                    'handler_type' => $runtime['type'],
                    'script' => $runtime['script_path'],
                    'status' => $status,
                    'exit_code' => (int)$result['code'],
                    'duration_ms' => (int)$result['duration_ms'],
                    'timed_out' => (bool)$result['timed_out'],
                    'output_limited' => (bool)$result['output_limited'],
                    'response' => !empty($entry['log_output'])
                        ? sms_script_command_limit_text($reply, 2000)
                        : null,
                    'stderr' =>
                        !empty($entry['log_output'])
                        && $stderr !== ''
                            ? sms_script_command_limit_text($stderr, 2000)
                            : null,
                ]
            )
        );

        return [
            'matched' => true,
            'ok' => $status === 'completed',
            'status' => $status,
            'code' => (int)$result['code'],
            'reply' => $reply,
            'request_id' => $requestId,
        ];
    } catch (Throwable $exception) {
        $reply = 'Команда ' . $name . ': ' . $exception->getMessage();
        $usage = sms_script_command_usage($entry);

        if ($exception instanceof InvalidArgumentException && $usage !== '') {
            $reply .= "\nПодсказка: " . $usage;
        }

        $reply = sms_script_command_limit_text($reply, 1000);

        sms_script_command_audit(
            array_merge(
                $baseAudit,
                [
                    'event' => 'execution',
                    'normalized_args' => null,
                    'status' => 'rejected',
                    'exit_code' => null,
                    'duration_ms' => 0,
                    'timed_out' => false,
                    'output_limited' => false,
                    'response' => !empty($entry['log_output'])
                        ? $reply
                        : null,
                    'stderr' => null,
                ]
            )
        );

        return [
            'matched' => true,
            'ok' => false,
            'status' => 'rejected',
            'code' => null,
            'reply' => $reply,
            'request_id' => $requestId,
        ];
    }
}

function sms_script_command_dispatch(
    string $text,
    string $phone,
    string $fingerprint = ''
): array {
    $parsed = sms_script_command_parse($text);

    if (empty($parsed['matched'])) {
        return [
            'matched' => false,
        ];
    }

    if ($fingerprint === '') {
        $fingerprint = hash(
            'sha256',
            $phone
            . '|'
            . $text
            . '|'
            . microtime(true)
        );
    }

    return sms_script_command_execute(
        $parsed,
        $phone,
        $fingerprint
    );
}

function sms_script_command_try_handle(array $sms): array
{
    global $config;

    $notHandled = [
        'handled' => false,
        'delete' => false,
    ];

    $phone = (string)($sms['phone'] ?? '');
    $content = (string)($sms['content'] ?? '');

    if (!sms_cmd_phone_trusted($phone)) {
        return $notHandled;
    }

    $parsed = sms_script_command_parse($content);

    if (empty($parsed['matched'])) {
        return $notHandled;
    }

    $name = (string)$parsed['name'];
    $type = 'script:' . $name;
    $entry = (array)$parsed['entry'];
    $fingerprint = sms_cmd_history_fingerprint_for_sms($sms);
    $history = sms_cmd_history_get($fingerprint);

    if (
        $history !== null
        && in_array(
            (string)$history['status'],
            ['completed', 'failed'],
            true
        )
    ) {
        app_log(
            'INFO script_command_duplicate_terminal' .
            ' command=' . $name .
            ' status=' . (string)$history['status'] .
            ' fingerprint=' . $fingerprint
        );

        if ((int)($history['reply_sent'] ?? 0) !== 1) {
            sms_cmd_history_send_reply($fingerprint);
        }

        return [
            'handled' => true,
            'delete' => true,
        ];
    }

    if (
        $history !== null
        && in_array(
            (string)$history['status'],
            ['processing', 'queued'],
            true
        )
    ) {
        $updated = strtotime((string)$history['updated_at']);
        $globalStale = (int)(
            $config['sms_commands']['processing_stale_seconds']
            ?? 300
        );
        $commandTimeout = max(
            1,
            min(300, (int)($entry['timeout'] ?? 15))
        );
        $staleSeconds = max(
            $commandTimeout + 60,
            max(60, min(3600, $globalStale))
        );

        if (
            $updated !== false
            && (time() - $updated) < $staleSeconds
        ) {
            return [
                'handled' => true,
                'delete' => false,
            ];
        }

        $reply =
            "КОМАНДА НЕ ПОВТОРЕНА\n" .
            'Предыдущее выполнение завершилось неопределённо' .
            "\nКоманда: " . $name;

        sms_cmd_history_finish_with_reply(
            $fingerprint,
            'failed',
            'uncertain',
            'Stale processing blocked; automatic retry is forbidden',
            (string)($history['request_id'] ?? '') ?: null,
            $reply
        );

        sms_script_command_audit([
            'event' => 'stale_processing_blocked',
            'request_id' => $history['request_id'] ?? null,
            'fingerprint' => $fingerprint,
            'phone' => sms_cmd_format_phone($phone),
            'command' => $name,
            'invoked_as' => $parsed['invoked_as'] ?? $name,
            'status' => 'uncertain',
            'response' => $reply,
        ]);

        $replySent = sms_cmd_history_send_reply($fingerprint);

        if (!$replySent) {
            app_log(
                'ERROR script_command_uncertain_reply_failed' .
                ' command=' . $name .
                ' fingerprint=' . $fingerprint
            );
        }

        return [
            'handled' => true,
            'delete' => true,
        ];
    }

    if (!sms_cmd_history_begin($fingerprint, $type, $phone)) {
        return [
            'handled' => true,
            'delete' => false,
        ];
    }

    $result = sms_script_command_execute(
        $parsed,
        $phone,
        $fingerprint
    );

    $finalStatus = !empty($result['ok'])
        && (string)($result['status'] ?? '') === 'completed'
            ? 'completed'
            : 'failed';

    $suppressSuccessReply =
        $finalStatus === 'completed'
        && array_key_exists('reply_on_success', $entry)
        && $entry['reply_on_success'] === false;

    if ($suppressSuccessReply) {
        sms_cmd_history_finish_without_reply(
            $fingerprint,
            $finalStatus,
            (string)($result['status'] ?? 'unknown'),
            null,
            isset($result['request_id'])
                ? (string)$result['request_id']
                : null
        );

        sms_script_command_audit([
            'event' => 'reply_suppressed',
            'request_id' => $result['request_id'] ?? null,
            'fingerprint' => $fingerprint,
            'phone' => sms_cmd_format_phone($phone),
            'command' => $name,
            'status' => 'completed',
        ]);
    } else {
        sms_cmd_history_finish_with_reply(
            $fingerprint,
            $finalStatus,
            (string)($result['status'] ?? 'unknown'),
            !empty($result['ok'])
                ? null
                : sms_script_command_limit_text(
                    (string)$result['reply'],
                    500
                ),
            isset($result['request_id'])
                ? (string)$result['request_id']
                : null,
            (string)$result['reply']
        );

        $replySent = sms_cmd_history_send_reply($fingerprint);

        if (!$replySent) {
            sms_script_command_audit([
                'event' => 'reply_failed',
                'request_id' => $result['request_id'] ?? null,
                'fingerprint' => $fingerprint,
                'phone' => sms_cmd_format_phone($phone),
                'command' => $name,
                'status' => 'reply_failed',
            ]);

            app_log(
                'ERROR script_command_reply_failed' .
                ' command=' . $name .
                ' fingerprint=' . $fingerprint
            );
        }
    }

    return [
        'handled' => true,
        'delete' => true,
    ];
}


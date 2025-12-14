<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

function sv_logs_root(array $config): string
{
    $paths = $config['paths'] ?? [];
    $root = $paths['logs'] ?? (sv_base_dir() . DIRECTORY_SEPARATOR . 'LOGS');

    return sv_normalize_directory($root);
}

function sv_rotate_logs(string $dir, string $prefix, int $retention): void
{
    $files = glob($dir . DIRECTORY_SEPARATOR . $prefix . '_*.log');
    if ($files === false || $files === []) {
        return;
    }

    rsort($files, SORT_NATURAL);
    $surplus = array_slice($files, $retention);
    foreach ($surplus as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

function sv_prepare_log_file(array $config, string $type, bool $unique, int $retention = 30): string
{
    $logsRoot = sv_logs_root($config);
    $channelDir = $logsRoot . DIRECTORY_SEPARATOR . $type;
    if (!is_dir($channelDir)) {
        @mkdir($channelDir, 0777, true);
    }

    $suffix = $unique ? date('Ymd_His') : date('Ymd');
    $logFile = $channelDir . DIRECTORY_SEPARATOR . $type . '_' . $suffix . '.log';

    sv_rotate_logs($channelDir, $type, $retention);

    return $logFile;
}

function sv_operation_logger(?string $logFile, ?array &$buffer = null): callable
{
    if ($logFile !== null) {
        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
    }

    return function (string $message) use ($logFile, &$buffer): void {
        $line = '[' . date('c') . '] ' . $message;
        if ($buffer !== null) {
            $buffer[] = $line;
        }
        if ($logFile !== null) {
            file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
        }
    };
}

function sv_create_operation_log(array $config, string $type, ?array &$buffer = null, int $retention = 30): array
{
    $logFile = sv_prepare_log_file($config, $type, true, $retention);
    $logger  = sv_operation_logger($logFile, $buffer);

    return [$logFile, $logger];
}

function sv_security_log(array $config, string $message, array $context = []): void
{
    $logFile = sv_prepare_log_file($config, 'security', false, 60);
    $line = '[' . date('c') . '] ' . $message;
    if ($context !== []) {
        $line .= ' | ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
}

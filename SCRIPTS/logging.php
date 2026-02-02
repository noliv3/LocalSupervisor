<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

function sv_logs_root(array $config): string
{
    $paths = $config['paths'] ?? [];
    $root = $paths['logs'] ?? (sv_base_dir() . DIRECTORY_SEPARATOR . 'LOGS');

    return sv_normalize_directory($root);
}

function sv_log_path(array $config, string $filename): string
{
    $clean = ltrim(str_replace('\\', '/', $filename), '/');
    return sv_logs_root($config) . DIRECTORY_SEPARATOR . $clean;
}

function sv_ensure_logs_root(array $config, ?string &$error = null): ?string
{
    $root = sv_logs_root($config);
    if (!is_dir($root)) {
        if (!mkdir($root, 0777, true) && !is_dir($root)) {
            $error = 'log_root_create_failed';
            return null;
        }
    }

    if (!is_writable($root)) {
        $error = 'log_root_not_writable';
        return null;
    }

    return $root;
}

function sv_log_system_error(array $config, string $message, array $context = []): void
{
    $root = sv_logs_root($config);
    if (!is_dir($root)) {
        if (!mkdir($root, 0777, true) && !is_dir($root)) {
            error_log('[sv_log_system_error] ' . $message . ' | log_root_unavailable');
            return;
        }
    }

    $payload = [
        'ts' => date('c'),
        'message' => $message,
        'context' => $context,
    ];
    $line = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($line === false) {
        error_log('[sv_log_system_error] ' . $message . ' | json_encode_failed');
        return;
    }

    $logFile = $root . DIRECTORY_SEPARATOR . 'system_errors.jsonl';
    $result = file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
    if ($result === false) {
        error_log('[sv_log_system_error] ' . $message . ' | write_failed');
    }
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

function sv_write_jsonl_log(array $config, string $filename, array $payload, int $retention = 30): void
{
    $logsRoot = sv_logs_root($config);
    if (!is_dir($logsRoot)) {
        @mkdir($logsRoot, 0777, true);
    }

    $logFile = $logsRoot . DIRECTORY_SEPARATOR . $filename;
    $prefix = pathinfo($filename, PATHINFO_FILENAME);

    if (is_file($logFile)) {
        $mtime = @filemtime($logFile);
        if ($mtime !== false && date('Ymd', $mtime) !== date('Ymd')) {
            $archive = $logsRoot . DIRECTORY_SEPARATOR . $prefix . '_' . date('Ymd', $mtime) . '.log';
            @rename($logFile, $archive);
            sv_rotate_logs($logsRoot, $prefix, $retention);
        }
    }

    $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        return;
    }

    file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
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

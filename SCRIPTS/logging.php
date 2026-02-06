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
            error_log('[sv_ensure_logs_root] ' . $error . ' path=' . $root);
            return null;
        }
    }

    if (!is_writable($root)) {
        $error = 'log_root_not_writable';
        error_log('[sv_ensure_logs_root] ' . $error . ' path=' . $root);
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

function sv_rotate_channel_logs(string $dir, string $prefix, int $retention): void
{
    $files = glob($dir . DIRECTORY_SEPARATOR . $prefix . '_*.log');
    if ($files === false || $files === []) {
        return;
    }

    rsort($files, SORT_NATURAL);
    $surplus = array_slice($files, $retention);
    foreach ($surplus as $file) {
        if (is_file($file)) {
            if (!unlink($file)) {
                error_log('[sv_rotate_channel_logs] log_delete_failed file=' . $file);
            }
        }
    }
}

function sv_rotate_logs(array $config): void
{
    $logsRoot = sv_logs_root($config);
    $targets = [
        $logsRoot . DIRECTORY_SEPARATOR . 'ollama_jobs.jsonl',
        $logsRoot . DIRECTORY_SEPARATOR . 'system_errors.jsonl',
    ];
    $maxBytes = 10 * 1024 * 1024;
    $maxLines = 1000;

    foreach ($targets as $path) {
        if (!is_file($path)) {
            continue;
        }
        $size = filesize($path);
        if ($size === false || $size <= $maxBytes) {
            continue;
        }

        $oldPath = $path . '.old';
        if (is_file($oldPath) && !unlink($oldPath)) {
            error_log('[sv_rotate_logs] log_old_delete_failed file=' . $oldPath);
        }

        if (@rename($path, $oldPath)) {
            continue;
        }

        $lines = [];
        $file = new SplFileObject($path, 'r');
        while (!$file->eof()) {
            $line = $file->fgets();
            if ($line === false) {
                break;
            }
            $lines[] = $line;
            if (count($lines) > $maxLines) {
                array_shift($lines);
            }
        }
        $result = file_put_contents($path, implode('', $lines));
        if ($result === false) {
            error_log('[sv_rotate_logs] log_truncate_failed file=' . $path);
        }
    }
}

function sv_prepare_log_file(array $config, string $type, bool $unique, int $retention = 30): string
{
    $logsRoot = sv_logs_root($config);
    $channelDir = $logsRoot . DIRECTORY_SEPARATOR . $type;
    if (!is_dir($channelDir)) {
        if (!mkdir($channelDir, 0777, true) && !is_dir($channelDir)) {
            error_log('[sv_prepare_log_file] log_channel_create_failed type=' . $type . ' path=' . $channelDir);
        }
    }

    $suffix = $unique ? date('Ymd_His') : date('Ymd');
    $logFile = $channelDir . DIRECTORY_SEPARATOR . $type . '_' . $suffix . '.log';

    sv_rotate_channel_logs($channelDir, $type, $retention);

    return $logFile;
}

function sv_write_jsonl_log(array $config, string $filename, array $payload, int $retention = 30): void
{
    $logsRoot = sv_logs_root($config);
    if (!is_dir($logsRoot)) {
        if (!mkdir($logsRoot, 0777, true) && !is_dir($logsRoot)) {
            error_log('[sv_write_jsonl_log] log_root_create_failed path=' . $logsRoot);
            return;
        }
    }

    $logFile = $logsRoot . DIRECTORY_SEPARATOR . $filename;
    $prefix = pathinfo($filename, PATHINFO_FILENAME);

    if (is_file($logFile)) {
        $mtime = filemtime($logFile);
        if ($mtime !== false && date('Ymd', $mtime) !== date('Ymd')) {
            $archive = $logsRoot . DIRECTORY_SEPARATOR . $prefix . '_' . date('Ymd', $mtime) . '.log';
            if (!rename($logFile, $archive)) {
                error_log('[sv_write_jsonl_log] log_rotate_rename_failed from=' . $logFile . ' to=' . $archive);
            }
            sv_rotate_channel_logs($logsRoot, $prefix, $retention);
        }
    }

    $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        error_log('[sv_write_jsonl_log] json_encode_failed file=' . $logFile);
        return;
    }

    $result = file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
    if ($result === false) {
        error_log('[sv_write_jsonl_log] log_write_failed file=' . $logFile);
    }
}

function sv_operation_logger(?string $logFile, ?array &$buffer = null): callable
{
    if ($logFile !== null) {
        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
                error_log('[sv_operation_logger] log_dir_create_failed path=' . $dir);
            }
        }
    }

    return function (string $message) use ($logFile, &$buffer): void {
        $line = '[' . date('c') . '] ' . $message;
        if ($buffer !== null) {
            $buffer[] = $line;
        }
        if ($logFile !== null) {
            $result = file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
            if ($result === false) {
                error_log('[sv_operation_logger] log_write_failed file=' . $logFile);
            }
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

    $result = file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
    if ($result === false) {
        error_log('[sv_security_log] log_write_failed file=' . $logFile);
    }
}

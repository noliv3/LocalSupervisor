<?php
declare(strict_types=1);

require_once __DIR__ . '/logging.php';

function sv_ollama_trace_path(array $config, int $jobId, string $mode, int $attempt): string
{
    $logsRoot = sv_logs_root($config);
    $dateDir = date('Ymd');
    $dir = $logsRoot . DIRECTORY_SEPARATOR . 'ollama_raw' . DIRECTORY_SEPARATOR . $dateDir;
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    $safeMode = preg_replace('/[^a-z0-9_\-]/i', '_', $mode);
    $filename = 'job_' . $jobId . '_' . $safeMode . '_a' . $attempt . '.json';

    return $dir . DIRECTORY_SEPARATOR . $filename;
}

function sv_ollama_write_trace(array $config, array $trace): ?string
{
    $jobId = isset($trace['job_id']) ? (int)$trace['job_id'] : 0;
    $mode = isset($trace['mode']) ? (string)$trace['mode'] : '';
    $attempt = isset($trace['attempt']) ? (int)$trace['attempt'] : 0;
    if ($jobId <= 0 || $mode === '' || $attempt <= 0) {
        return null;
    }

    $target = null;
    if (isset($trace['trace_file']) && is_string($trace['trace_file']) && trim($trace['trace_file']) !== '') {
        $target = $trace['trace_file'];
    } else {
        $target = sv_ollama_trace_path($config, $jobId, $mode, $attempt);
    }

    $json = json_encode($trace, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        return null;
    }

    $dir = dirname($target);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    file_put_contents($target, $json . PHP_EOL);

    return $target;
}

function sv_ollama_trace_update(array $config, string $traceFile, array $patch): void
{
    $traceFile = trim($traceFile);
    if ($traceFile === '' || $patch === []) {
        return;
    }

    $existing = [];
    if (is_file($traceFile)) {
        $raw = @file_get_contents($traceFile);
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $existing = $decoded;
            }
        }
    }

    $merged = array_replace_recursive($existing, $patch);

    $json = json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        return;
    }

    $dir = dirname($traceFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    @file_put_contents($traceFile, $json . PHP_EOL);
}

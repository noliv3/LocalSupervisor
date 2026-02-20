<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Nur CLI erlaubt.\n");
    exit(1);
}

require_once __DIR__ . '/operations.php';

try {
    $config = sv_load_config();
    $pdo = sv_open_pdo($config);
} catch (Throwable $e) {
    fwrite(STDERR, 'Init-Fehler: ' . $e->getMessage() . "\n");
    exit(1);
}

$logsError = null;
$logsRoot = sv_ensure_logs_root($config, $logsError);
if ($logsRoot === null) {
    sv_log_system_error($config, 'scan_service_log_root_unavailable', ['error' => $logsError]);
    fwrite(STDERR, 'Log-Root nicht verfügbar: ' . ($logsError ?? 'unbekannt') . "\n");
    exit(1);
}

$heartbeatIntervalSeconds = 5;

$serviceName = 'scan_service';
$heartbeatPath = $logsRoot . '/scan_service.heartbeat.json';
$limit = 11;
$sleepMs = (int)($config['workers']['scan']['sleep_ms'] ?? 1500);
if ($sleepMs < 100) {
    $sleepMs = 100;
}

foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $limit = max(1, (int)substr($arg, 8));
    } elseif (strpos($arg, '--sleep-ms=') === 0) {
        $sleepMs = max(100, (int)substr($arg, 11));
    }
}

$running = true;
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    $handler = static function () use (&$running): void {
        $running = false;
    };
    pcntl_signal(SIGTERM, $handler);
    pcntl_signal(SIGINT, $handler);
}

$writeHeartbeat = static function (string $state, array $extra = []) use ($heartbeatPath): void {
    $payload = array_merge([
        'ts' => gmdate('c'),
        'pid' => (int)getmypid(),
        'state' => $state,
    ], $extra);
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json !== false) {
        @file_put_contents($heartbeatPath, $json, LOCK_EX);
    }
};

$logger = static function (string $msg): void {
    fwrite(STDOUT, '[' . date('c') . '] ' . $msg . PHP_EOL);
};

$writeHeartbeat('starting', ['limit' => $limit, 'sleep_ms' => $sleepMs]);
$logger($serviceName . ' started pid=' . (int)getmypid() . ' limit=' . $limit . ' sleep_ms=' . $sleepMs);

while ($running) {
    try {
        $summary = sv_process_scan_job_batch($pdo, $config, $limit, $logger);
        $processed = (int)($summary['total'] ?? 0);

        if ($processed > 0) {
            $writeHeartbeat('running', ['processed' => $processed]);
        } else {
            $writeHeartbeat('idle', ['processed' => 0]);
            $remainingMs = max(0, $sleepMs);
            while ($running && $remainingMs > 0) {
                $sliceMs = min($remainingMs, $heartbeatIntervalSeconds * 1000);
                usleep($sliceMs * 1000);
                $remainingMs -= $sliceMs;
                $writeHeartbeat('idle', ['processed' => 0]);
            }
        }
    } catch (Throwable $e) {
        sv_log_worker_event($config, $serviceName, 'batch_exception', 'error', ['error_code' => 'batch_failed', 'error' => $e->getMessage()]);
        sv_log_system_error($config, 'scan_service_batch_failed', ['worker_type' => $serviceName, 'error_code' => 'batch_failed', 'error' => $e->getMessage()]);
        fwrite(STDERR, '[' . date('c') . '] Batch-Fehler: ' . $e->getMessage() . PHP_EOL);
        $writeHeartbeat('error', ['error' => $e->getMessage()]);
        $remainingMs = max(0, $sleepMs);
        while ($running && $remainingMs > 0) {
            $sliceMs = min($remainingMs, $heartbeatIntervalSeconds * 1000);
            usleep($sliceMs * 1000);
            $remainingMs -= $sliceMs;
            $writeHeartbeat('error', ['error' => $e->getMessage()]);
        }
    }
}

$writeHeartbeat('stopped');
$logger($serviceName . ' stopped pid=' . (int)getmypid());
exit(0);

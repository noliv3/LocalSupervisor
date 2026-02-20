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
    sv_log_system_error($config, 'library_rename_service_log_root_unavailable', ['error' => $logsError]);
    fwrite(STDERR, 'Log-Root nicht verfügbar: ' . ($logsError ?? 'unbekannt') . "\n");
    exit(1);
}

$serviceName = 'library_rename_service';
$heartbeatPath = $logsRoot . '/library_rename_service.heartbeat.json';
$limit = 50;
$sleepMs = (int)($config['workers']['library_rename']['sleep_ms'] ?? 1500);
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
        sv_recover_stuck_jobs($pdo, [SV_JOB_TYPE_LIBRARY_RENAME], SV_JOB_STUCK_MINUTES, $logger);
        $summary = sv_process_library_rename_jobs($pdo, $config, $limit, $logger);
        $processed = (int)($summary['total'] ?? 0);

        if ($processed > 0) {
            $writeHeartbeat('running', ['processed' => $processed]);
        } else {
            $writeHeartbeat('idle', ['processed' => 0]);
            usleep($sleepMs * 1000);
        }
    } catch (Throwable $e) {
        sv_log_system_error($config, 'library_rename_service_batch_failed', ['error' => $e->getMessage()]);
        fwrite(STDERR, '[' . date('c') . '] Batch-Fehler: ' . $e->getMessage() . PHP_EOL);
        $writeHeartbeat('error', ['error' => $e->getMessage()]);
        usleep($sleepMs * 1000);
    }
}

$writeHeartbeat('stopped');
$logger($serviceName . ' stopped pid=' . (int)getmypid());
exit(0);

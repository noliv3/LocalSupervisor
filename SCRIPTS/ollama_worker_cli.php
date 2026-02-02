<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Nur CLI erlaubt.\n");
    exit(1);
}

require_once __DIR__ . '/ollama_jobs.php';

try {
    $config = sv_load_config();
    $pdo    = sv_open_pdo($config);
} catch (Throwable $e) {
    fwrite(STDERR, "Init-Fehler: " . $e->getMessage() . "\n");
    exit(1);
}

$logsError = null;
$logsRoot = sv_ensure_logs_root($config, $logsError);
$errLogPath = null;
if ($logsRoot === null) {
    sv_log_system_error($config, 'ollama_worker_log_root_unavailable', ['error' => $logsError]);
} else {
    $errLogPath = $logsRoot . '/ollama_worker.err.log';
}

$writeErrLog = static function (string $message) use ($errLogPath): void {
    $line = '[' . date('c') . '] ' . $message . PHP_EOL;
    if ($errLogPath === null) {
        fwrite(STDERR, $line);
        return;
    }
    file_put_contents($errLogPath, $line, FILE_APPEND);
};

$loop = false;
$sleepMs = 1000;
$batchSize = null;
$limit = null;
$mediaId = null;
$maxBatches = null;
$maxMinutes = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--loop') {
        $loop = true;
    } elseif (strpos($arg, '--sleep-ms=') === 0) {
        $sleepMs = (int)substr($arg, 11);
    } elseif (strpos($arg, '--batch=') === 0) {
        $batchSize = (int)substr($arg, 8);
    } elseif (strpos($arg, '--limit=') === 0) {
        $limit = (int)substr($arg, 8);
    } elseif (strpos($arg, '--media-id=') === 0) {
        $mediaId = (int)substr($arg, 11);
    } elseif (strpos($arg, '--max-batches=') === 0) {
        $maxBatches = (int)substr($arg, 14);
    } elseif (strpos($arg, '--max-minutes=') === 0) {
        $maxMinutes = (int)substr($arg, 14);
    }
}

$logger = function (string $msg): void {
    fwrite(STDOUT, $msg . PHP_EOL);
};

$workerPid = function_exists('getmypid') ? (int)getmypid() : null;
$updateWorkerStatus = static function (bool $active, string $message, array $details = []) use ($config, $workerPid): void {
    $baseDetails = [
        'pid' => $workerPid,
        'owner' => 'cli:ollama_worker_cli.php',
    ];
    sv_ollama_update_global_status($config, 'worker_active', $active, $message, array_merge($baseDetails, $details));
};

$lock = sv_ollama_acquire_runner_lock($config, 'cli:ollama_worker_cli.php');
if (empty($lock['ok'])) {
    $writeErrLog('Ollama-Worker bereits aktiv (Lock), neuer Start abgebrochen.');
    exit(0);
}

$batchCount = 0;
$aggregate = [
    'total' => 0,
    'done' => 0,
    'error' => 0,
    'skipped' => 0,
    'retried' => 0,
];

try {
    $updateWorkerStatus(true, 'started', [
        'loop' => $loop ? 1 : 0,
        'sleep_ms' => $sleepMs,
    ]);
    sv_run_migrations_if_needed($pdo, $config, $logger);

    $batchSize = $batchSize ?? $limit;
    if ($batchSize !== null && $batchSize <= 0) {
        $batchSize = null;
    }
    if ($sleepMs < 0) {
        $sleepMs = 0;
    }
    if ($maxMinutes !== null && $maxMinutes <= 0) {
        $maxMinutes = null;
    }

    sv_ollama_watchdog_stale_running($pdo, $config, 10, 'requeue');

    $maxConcurrency = sv_ollama_max_concurrency($config);
    $running = sv_ollama_running_job_count($pdo);
    if ($running >= $maxConcurrency) {
        $writeErrLog('Ollama-Worker nicht gestartet (busy): running=' . $running . ' max=' . $maxConcurrency . '.');
        exit(0);
    }

    $startedAt = time();
    while (true) {
        $summary = sv_process_ollama_job_batch($pdo, $config, $batchSize, $logger, $mediaId);
        foreach ($aggregate as $key => $value) {
            $aggregate[$key] = $value + (int)($summary[$key] ?? 0);
        }
        $batchCount++;
        $updateWorkerStatus(true, 'heartbeat', [
            'batch' => $batchCount,
            'processed' => (int)($summary['total'] ?? 0),
        ]);

        $total = (int)($summary['total'] ?? 0);
        if ($maxBatches !== null && $batchCount >= $maxBatches) {
            break;
        }
        if ($loop) {
            if ($maxMinutes !== null && (time() - $startedAt) >= ($maxMinutes * 60)) {
                break;
            }
            if ($total === 0 && $sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
            continue;
        }

        if ($total === 0) {
            break;
        }
    }

    $line = sprintf(
        'Batches: %d | Verarbeitet: %d | Erfolgreich: %d | Fehler: %d | Retries: %d | Übersprungen: %d',
        $batchCount,
        (int)($aggregate['total'] ?? 0),
        (int)($aggregate['done'] ?? 0),
        (int)($aggregate['error'] ?? 0),
        (int)($aggregate['retried'] ?? 0),
        (int)($aggregate['skipped'] ?? 0)
    );
    fwrite(STDOUT, $line . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Worker-Fehler: " . $e->getMessage() . "\n");
    exit(1);
} finally {
    $updateWorkerStatus(false, 'stopped', [
        'batches' => $batchCount,
        'total' => (int)($aggregate['total'] ?? 0),
    ]);
    sv_ollama_release_runner_lock($lock['handle'] ?? null);
}

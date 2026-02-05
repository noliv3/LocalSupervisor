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
    try {
        $pdo->exec('PRAGMA busy_timeout = 3500');
    } catch (Throwable $e) {
    }
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
    $result = file_put_contents($errLogPath, $line, FILE_APPEND);
    if ($result === false) {
        error_log('[ollama_worker_cli] Failed to write error log: ' . $message);
    }
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
$heartbeatIntervalSeconds = 2;
$globalStatusIntervalSeconds = 4;
$updateWorkerStatus = static function (bool $active, string $message, array $details = []) use ($config, $workerPid): void {
    $baseDetails = [
        'pid' => $workerPid,
        'owner' => sv_ollama_worker_owner(),
    ];
    sv_ollama_update_global_status($config, 'worker_active', $active, $message, array_merge($baseDetails, $details));
};

$writeWorkerHeartbeat = static function (string $state, ?int $currentJobId = null, ?string $lastBatchTs = null) use ($config, $workerPid): void {
    sv_ollama_write_worker_heartbeat($config, [
        'ts_utc' => gmdate('c'),
        'pid' => $workerPid,
        'owner' => sv_ollama_worker_owner(),
        'state' => $state,
        'current_job_id' => $currentJobId,
        'last_batch_ts' => $lastBatchTs,
    ]);
};

$lastGlobalStatusWrite = 0;
$writeGlobalStatus = static function (bool $force = false, ?string $lastBatchTs = null) use ($pdo, $config, $workerPid, &$lastGlobalStatusWrite, $globalStatusIntervalSeconds): void {
    $now = time();
    if (!$force && ($now - $lastGlobalStatusWrite) < $globalStatusIntervalSeconds) {
        return;
    }
    $lastGlobalStatusWrite = $now;

    $counts = sv_ollama_collect_queue_counts($pdo);
    $legacyStatus = sv_ollama_read_global_status($config);
    $lastError = null;
    if (!empty($legacyStatus['ollama_down']['active'])) {
        $lastError = is_string($legacyStatus['ollama_down']['message'] ?? null)
            ? $legacyStatus['ollama_down']['message']
            : 'ollama_down';
    }

    sv_ollama_write_runtime_global_status($config, [
        'ts_utc' => gmdate('c'),
        'queue_pending' => (int)($counts['queued'] ?? 0) + (int)($counts['pending'] ?? 0),
        'queue_queued' => (int)($counts['queued'] ?? 0),
        'queue_running' => (int)($counts['running'] ?? 0),
        'last_error' => $lastError,
        'worker_pid' => $workerPid,
        'worker_running' => true,
        'last_batch_ts' => $lastBatchTs,
    ]);
};

$lock = sv_ollama_acquire_runner_lock($config, sv_ollama_worker_owner());
if (empty($lock['ok'])) {
    $reason = $lock['reason'] ?? 'lock_failed';
    if ($reason === 'locked') {
        $writeErrLog('Ollama-Worker bereits aktiv (Lock), neuer Start abgebrochen.');
        exit(0);
    }
    $writeErrLog('Ollama-Worker Lock fehlgeschlagen: ' . $reason);
    sv_log_system_error($config, 'ollama_worker_lock_failed', ['reason' => $reason]);
    exit(1);
}

$lockHandle = $lock['handle'] ?? null;
$lockPayload = $lock['payload'] ?? [
    'pid' => $workerPid,
    'started_at' => date('c'),
    'host' => function_exists('gethostname') ? (string)gethostname() : 'unknown',
    'owner' => sv_ollama_worker_owner(),
];
$lastHeartbeat = 0;
$updateLockHeartbeat = static function (bool $force = false) use (&$lockPayload, &$lastHeartbeat, $lockHandle, $config, $writeErrLog): void {
    $now = time();
    if (!$force && ($now - $lastHeartbeat) < 10) {
        return;
    }
    $lastHeartbeat = $now;
    $lockPayload['heartbeat_at'] = date('c', $now);
    $encoded = json_encode($lockPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        $writeErrLog('Ollama-Worker Heartbeat konnte nicht codiert werden.');
        sv_log_system_error($config, 'ollama_worker_heartbeat_encode_failed', []);
        throw new RuntimeException('ollama_worker_heartbeat_encode_failed');
    }
    if (!is_resource($lockHandle)) {
        $writeErrLog('Ollama-Worker Lock-Handle ungültig.');
        sv_log_system_error($config, 'ollama_worker_lock_handle_invalid', []);
        throw new RuntimeException('ollama_worker_lock_handle_invalid');
    }
    ftruncate($lockHandle, 0);
    rewind($lockHandle);
    $written = fwrite($lockHandle, $encoded);
    if ($written === false) {
        $writeErrLog('Ollama-Worker Heartbeat konnte nicht geschrieben werden.');
        sv_log_system_error($config, 'ollama_worker_heartbeat_write_failed', []);
        throw new RuntimeException('ollama_worker_heartbeat_write_failed');
    }
    fflush($lockHandle);
};

$batchCount = 0;
$emptyStreak = 0;
$minEmptyChecks = 2;
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
    $updateLockHeartbeat(true);
    $writeWorkerHeartbeat('idle');
    $writeGlobalStatus(true);
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
        $writeWorkerHeartbeat('running');
        $summary = sv_process_ollama_job_batch($pdo, $config, $batchSize, $logger, $mediaId);
        $lastBatchTs = gmdate('c');
        foreach ($aggregate as $key => $value) {
            $aggregate[$key] = $value + (int)($summary[$key] ?? 0);
        }
        $batchCount++;
        $updateLockHeartbeat();
        $writeWorkerHeartbeat(((int)($summary['total'] ?? 0) > 0) ? 'running' : 'idle', null, $lastBatchTs);
        $writeGlobalStatus(false, $lastBatchTs);
        $updateWorkerStatus(true, 'heartbeat', [
            'batch' => $batchCount,
            'processed' => (int)($summary['total'] ?? 0),
        ]);

        $total = (int)($summary['total'] ?? 0);
        $pending = null;
        $runningJobs = null;
        if ($total === 0 || ($maxBatches !== null && $batchCount >= $maxBatches)) {
            $pending = sv_ollama_pending_job_count($pdo);
            $runningJobs = sv_ollama_running_job_count($pdo);
        }

        if ($total === 0 && ($pending ?? 0) === 0 && ($runningJobs ?? 0) === 0) {
            $emptyStreak++;
        } elseif ($total === 0) {
            $emptyStreak = 0;
        } else {
            $emptyStreak = 0;
        }

        if ($maxBatches !== null && $batchCount >= $maxBatches) {
            if (($pending ?? 0) === 0 && ($runningJobs ?? 0) === 0 && $emptyStreak >= $minEmptyChecks) {
                break;
            }
        }

        if ($maxMinutes !== null && (time() - $startedAt) >= ($maxMinutes * 60)) {
            break;
        }

        if ($total === 0) {
            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
            $writeWorkerHeartbeat('idle', null, $lastBatchTs ?? null);
            $writeGlobalStatus(false, $lastBatchTs ?? null);
            if ($emptyStreak >= $minEmptyChecks) {
                break;
            }
            continue;
        }

        if ($loop) {
            continue;
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
    sv_ollama_write_worker_heartbeat($config, [
        'ts_utc' => gmdate('c'),
        'pid' => $workerPid,
        'owner' => sv_ollama_worker_owner(),
        'state' => 'idle',
    ]);
    sv_ollama_write_runtime_global_status($config, [
        'ts_utc' => gmdate('c'),
        'queue_pending' => 0,
        'queue_queued' => 0,
        'queue_running' => 0,
        'worker_pid' => $workerPid,
        'worker_running' => false,
    ]);
    $updateWorkerStatus(false, 'stopped', [
        'batches' => $batchCount,
        'total' => (int)($aggregate['total'] ?? 0),
    ]);
    try {
        $updateLockHeartbeat(true);
    } catch (Throwable $e) {
        $writeErrLog('Ollama-Worker Heartbeat-Update fehlgeschlagen (finally): ' . $e->getMessage());
    }
    sv_ollama_release_runner_lock($lock['handle'] ?? null);
}

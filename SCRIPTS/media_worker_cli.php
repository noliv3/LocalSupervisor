<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Nur CLI erlaubt.\n");
    exit(1);
}

require_once __DIR__ . '/operations.php';

try {
    $config = sv_load_config();
    $pdo    = sv_open_pdo($config);
} catch (Throwable $e) {
    fwrite(STDERR, "Init-Fehler: " . $e->getMessage() . "\n");
    exit(1);
}

$logsError = null;
$logsRoot = sv_ensure_logs_root($config, $logsError);
if ($logsRoot === null) {
    sv_log_system_error($config, 'media_worker_log_root_unavailable', ['error' => $logsError]);
    fwrite(STDERR, "Log-Root nicht verfügbar: " . ($logsError ?? 'unbekannt') . "\n");
    exit(1);
}

$limit = 50;
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $limit = max(1, (int)substr($arg, 8));
    }
}

$lockPath = $logsRoot . '/media_worker.lock.json';
$errLogPath = $logsRoot . '/media_worker.err.log';
$writeErrLog = static function (string $message) use ($errLogPath): void {
    $line = '[' . date('c') . '] ' . $message . PHP_EOL;
    $result = file_put_contents($errLogPath, $line, FILE_APPEND);
    if ($result === false) {
        error_log('[media_worker_cli] Failed to write error log: ' . $message);
    }
};

$lockQuarantine = static function (string $reason) use ($lockPath, $writeErrLog, $config): void {
    $timestamp = date('Ymd_His');
    $brokenPath = $lockPath . '.broken.' . $timestamp;
    if (rename($lockPath, $brokenPath)) {
        $writeErrLog($reason . ': ' . $lockPath . ' -> ' . $brokenPath);
        sv_log_system_error($config, 'media_worker_lock_quarantined', ['path' => $lockPath, 'reason' => $reason]);
        return;
    }
    if (!unlink($lockPath)) {
        $writeErrLog('Media-Worker Lock konnte nicht entfernt werden (' . $reason . ').');
        sv_log_system_error($config, 'media_worker_lock_remove_failed', ['path' => $lockPath, 'reason' => $reason]);
        exit(1);
    }
    $writeErrLog($reason . ': ' . $lockPath);
    sv_log_system_error($config, 'media_worker_lock_removed', ['path' => $lockPath, 'reason' => $reason]);
};

if (is_file($lockPath)) {
    $raw = file_get_contents($lockPath);
    if ($raw === false) {
        $writeErrLog('Media-Worker Lock konnte nicht gelesen werden.');
        sv_log_system_error($config, 'media_worker_lock_read_failed', ['path' => $lockPath]);
        $lockQuarantine('broken_lock_quarantined');
    } else {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $lockQuarantine('broken_lock_quarantined');
        } else {
            $heartbeat = isset($data['heartbeat_at']) ? strtotime((string)$data['heartbeat_at']) : false;
            if ($heartbeat !== false && (time() - $heartbeat) <= 30) {
                $writeErrLog('Media-Worker bereits aktiv (Lock Heartbeat), neuer Start abgebrochen.');
                exit(0);
            }
            $lockQuarantine('stale_lock_removed');
        }
    }
}

$lockPayload = [
    'pid' => (int)getmypid(),
    'started_at' => date('c'),
    'host' => function_exists('gethostname') ? (string)gethostname() : 'unknown',
    'cmdline' => implode(' ', $argv),
    'heartbeat_at' => date('c'),
];
$lockJson = json_encode($lockPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($lockJson === false || file_put_contents($lockPath, $lockJson, LOCK_EX) === false) {
    $writeErrLog('Media-Worker Lock konnte nicht geschrieben werden.');
    sv_log_system_error($config, 'media_worker_lock_write_failed', ['path' => $lockPath]);
    exit(1);
}

$lastHeartbeat = 0;
$updateHeartbeat = static function (bool $force = false) use (&$lockPayload, &$lastHeartbeat, $lockPath, $config, $writeErrLog): void {
    $now = time();
    if (!$force && ($now - $lastHeartbeat) < 10) {
        return;
    }
    $lastHeartbeat = $now;
    $lockPayload['heartbeat_at'] = date('c', $now);
    $lockJson = json_encode($lockPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($lockJson === false || file_put_contents($lockPath, $lockJson, LOCK_EX) === false) {
        $writeErrLog('Media-Worker Heartbeat konnte nicht geschrieben werden.');
        sv_log_system_error($config, 'media_worker_heartbeat_write_failed', ['path' => $lockPath]);
        throw new RuntimeException('media_worker_heartbeat_write_failed');
    }
};

$logger = function (string $msg) use ($updateHeartbeat): void {
    $updateHeartbeat();
    fwrite(STDOUT, $msg . PHP_EOL);
};

try {
    $updateHeartbeat(true);
    sv_recover_stuck_jobs($pdo, sv_media_job_types(), SV_JOB_STUCK_MINUTES, $logger);
    $summary = sv_process_media_jobs($pdo, $config, $limit, $logger);
    $updateHeartbeat(true);
    $line = sprintf(
        'Verarbeitet: %d | Erfolgreich: %d | Fehler: %d',
        (int)($summary['total'] ?? 0),
        (int)($summary['done'] ?? 0),
        (int)($summary['error'] ?? 0)
    );
    fwrite(STDOUT, $line . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Worker-Fehler: " . $e->getMessage() . "\n");
    exit(1);
} finally {
    if (is_file($lockPath) && !unlink($lockPath)) {
        $writeErrLog('Media-Worker Lock konnte nicht entfernt werden (finally).');
        sv_log_system_error($config, 'media_worker_lock_remove_failed_finally', ['path' => $lockPath]);
    }
}

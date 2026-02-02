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
    sv_log_system_error($config, 'forge_worker_log_root_unavailable', ['error' => $logsError]);
    fwrite(STDERR, "Log-Root nicht verfügbar: " . ($logsError ?? 'unbekannt') . "\n");
    exit(1);
}

$limit   = null;
$mediaId = null;
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $limit = (int)substr($arg, 8);
    } elseif (strpos($arg, '--media-id=') === 0) {
        $mediaId = (int)substr($arg, 11);
    }
}

$runtimeLog = $logsRoot . '/forge_worker_runtime.log';
$runtimeLine = sprintf(
    '[%s] pid=%d argv=%s media_id=%s limit=%s',
    date('c'),
    (int)getmypid(),
    json_encode($argv, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    $mediaId === null ? '-' : (string)$mediaId,
    $limit === null ? '-' : (string)$limit
);
$runtimeWrite = file_put_contents($runtimeLog, $runtimeLine . PHP_EOL, FILE_APPEND);
if ($runtimeWrite === false) {
    sv_log_system_error($config, 'forge_worker_runtime_log_write_failed', ['path' => $runtimeLog]);
}

$lockPath = $logsRoot . '/forge_worker.lock.json';
$errLogPath = $logsRoot . '/forge_worker.err.log';
$writeErrLog = static function (string $message) use ($errLogPath): void {
    $line = '[' . date('c') . '] ' . $message . PHP_EOL;
    $result = file_put_contents($errLogPath, $line, FILE_APPEND);
    if ($result === false) {
        error_log('[forge_worker_cli] Failed to write error log: ' . $message);
    }
};

if (is_file($lockPath)) {
    $raw = file_get_contents($lockPath);
    if ($raw === false) {
        $writeErrLog('Forge-Worker Lock konnte nicht gelesen werden.');
        sv_log_system_error($config, 'forge_worker_lock_read_failed', ['path' => $lockPath]);
        exit(1);
    }
    $data = json_decode($raw, true);
    $existingPid = is_array($data) && isset($data['pid']) ? (int)$data['pid'] : 0;
    if ($existingPid > 0) {
        $pidInfo = sv_is_pid_running($existingPid);
        if (!empty($pidInfo['running'])) {
            $writeErrLog('Forge-Worker bereits aktiv (PID ' . $existingPid . '), neuer Start abgebrochen.');
            exit(0);
        }
    }
    if (!unlink($lockPath)) {
        $writeErrLog('Forge-Worker Lock konnte nicht entfernt werden.');
        sv_log_system_error($config, 'forge_worker_lock_remove_failed', ['path' => $lockPath]);
        exit(1);
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
    $writeErrLog('Forge-Worker Lock konnte nicht geschrieben werden.');
    sv_log_system_error($config, 'forge_worker_lock_write_failed', ['path' => $lockPath]);
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
        $writeErrLog('Forge-Worker Heartbeat konnte nicht geschrieben werden.');
        sv_log_system_error($config, 'forge_worker_heartbeat_write_failed', ['path' => $lockPath]);
    }
};

$logger = function (string $msg) use ($updateHeartbeat): void {
    $updateHeartbeat();
    fwrite(STDOUT, $msg . PHP_EOL);
};

try {
    $updateHeartbeat(true);
    $stuck = sv_mark_stuck_jobs($pdo, SV_FORGE_JOB_TYPES, SV_JOB_STUCK_MINUTES, $logger);
    if ($stuck > 0) {
        $logger('Stuck Forge-Jobs bereinigt: ' . $stuck);
    }

    $updateHeartbeat();
    $summary = sv_process_forge_job_batch($pdo, $config, $limit, $logger, $mediaId);
    $updateHeartbeat(true);

    if (($summary['total'] ?? 0) === 0) {
        $scope = $mediaId === null ? 'all' : (string)$mediaId;
        $message = 'No forge jobs found for media_id=' . $scope . ', exiting';
        $runtimeWrite = file_put_contents(
            $runtimeLog,
            sprintf('[%s] %s', date('c'), $message) . PHP_EOL,
            FILE_APPEND
        );
        if ($runtimeWrite === false) {
            sv_log_system_error($config, 'forge_worker_runtime_log_write_failed', ['path' => $runtimeLog]);
        }
        $logger($message);
    }

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
    $updateHeartbeat(true);
    if (is_file($lockPath) && !unlink($lockPath)) {
        $writeErrLog('Forge-Worker Lock konnte nicht entfernt werden (finally).');
        sv_log_system_error($config, 'forge_worker_lock_remove_failed_finally', ['path' => $lockPath]);
    }
}

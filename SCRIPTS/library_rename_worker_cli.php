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
    sv_log_system_error($config, 'library_rename_worker_log_root_unavailable', ['error' => $logsError]);
    fwrite(STDERR, "Log-Root nicht verfügbar: " . ($logsError ?? 'unbekannt') . "\n");
    exit(1);
}

$limit = 50;
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $limit = max(1, (int)substr($arg, 8));
    }
}

$lockPath = $logsRoot . '/library_rename_worker.lock.json';
$errLogPath = $logsRoot . '/library_rename_worker.err.log';
$writeErrLog = static function (string $message) use ($errLogPath): void {
    $line = '[' . date('c') . '] ' . $message . PHP_EOL;
    $result = file_put_contents($errLogPath, $line, FILE_APPEND);
    if ($result === false) {
        error_log('[library_rename_worker_cli] Failed to write error log: ' . $message);
    }
};

$pid = (int)getmypid();
$startedAtUtc = gmdate('c');
$commandHash = sv_command_hash($argv);
$lockPayload = sv_lock_payload($pid, $startedAtUtc, $startedAtUtc, $commandHash);
$lockReason = null;
$lockHandle = sv_try_acquire_worker_lock($lockPath, $lockPayload, $lockReason);
if (!is_resource($lockHandle)) {
    if ($lockReason === 'already_running') {
        $writeErrLog('Library-Rename Worker bereits aktiv, neuer Start abgebrochen.');
        exit(0);
    }
    $writeErrLog('Library-Rename Worker Lock konnte nicht gesetzt werden: ' . ($lockReason ?? 'unknown'));
    sv_log_system_error($config, 'library_rename_worker_lock_acquire_failed', ['path' => $lockPath, 'reason' => $lockReason]);
    exit(1);
}

$lastHeartbeat = 0;
$updateHeartbeat = static function (bool $force = false) use (&$lockPayload, &$lastHeartbeat, $config, $writeErrLog, $lockHandle): void {
    $now = time();
    if (!$force && ($now - $lastHeartbeat) < 5) {
        return;
    }
    $lastHeartbeat = $now;
    $lockPayload['last_heartbeat_utc'] = gmdate('c', $now);
    if (!sv_write_worker_lock($lockHandle, $lockPayload)) {
        $writeErrLog('Library-Rename Worker Heartbeat konnte nicht geschrieben werden.');
        sv_log_system_error($config, 'library_rename_worker_heartbeat_write_failed', ['path' => 'library_rename_worker.lock.json']);
        throw new RuntimeException('library_rename_worker_heartbeat_write_failed');
    }
};

$logger = function (string $msg) use ($updateHeartbeat): void {
    $updateHeartbeat();
    fwrite(STDOUT, $msg . PHP_EOL);
};

try {
    $updateHeartbeat(true);
    sv_recover_stuck_jobs($pdo, [SV_JOB_TYPE_LIBRARY_RENAME], SV_JOB_STUCK_MINUTES, $logger);
    $summary = sv_process_library_rename_jobs($pdo, $config, $limit, $logger);
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
    sv_release_worker_lock($lockHandle);
}

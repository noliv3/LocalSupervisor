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
    sv_log_system_error($config, 'scan_worker_log_root_unavailable', ['error' => $logsError]);
    fwrite(STDERR, "Log-Root nicht verfügbar: " . ($logsError ?? 'unbekannt') . "\n");
    exit(1);
}

$lockPath = $logsRoot . '/scan_worker.lock.json';
$errLogPath = $logsRoot . '/scan_worker.err.log';
$heartbeatPath = $logsRoot . '/scan_worker_heartbeat.json';

$writeErrLog = static function (string $message) use ($errLogPath): void {
    $line = '[' . date('c') . '] ' . $message . PHP_EOL;
    $result = file_put_contents($errLogPath, $line, FILE_APPEND);
    if ($result === false) {
        error_log('[scan_worker_cli] Failed to write error log: ' . $message);
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
        $writeErrLog('Scan-Worker bereits aktiv, neuer Start abgebrochen.');
        exit(0);
    }
    $writeErrLog('Scan-Worker Lock konnte nicht gesetzt werden: ' . ($lockReason ?? 'unknown'));
    sv_log_system_error($config, 'scan_worker_lock_acquire_failed', ['path' => $lockPath, 'reason' => $lockReason]);
    exit(1);
}
$limit      = null;
$pathFilter = null;
$mediaId    = null;
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $limit = (int)substr($arg, 8);
    } elseif (strpos($arg, '--path=') === 0) {
        $pathFilter = trim(substr($arg, 7), "\"' ");
    } elseif (strpos($arg, '--media-id=') === 0) {
        $mediaId = (int)substr($arg, 11);
    }
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
        $writeErrLog('Scan-Worker Heartbeat konnte nicht geschrieben werden.');
        sv_log_system_error($config, 'scan_worker_heartbeat_write_failed', ['path' => 'scan_worker.lock.json']);
        throw new RuntimeException('scan_worker_heartbeat_write_failed');
    }
};

$writeHeartbeat = static function (string $state, ?int $currentJobId = null, ?string $lastProgressTs = null) use ($heartbeatPath, $pid): void {
    sv_write_worker_heartbeat($heartbeatPath, 'scan_worker', $pid, $state, [
        'current_job_id' => $currentJobId,
        'last_progress_ts' => $lastProgressTs,
    ]);
};

$logger = function (string $msg) use ($updateHeartbeat, $writeHeartbeat): void {
    $updateHeartbeat();
    $writeHeartbeat('running', null, gmdate('c'));
    fwrite(STDOUT, $msg . PHP_EOL);
};

fwrite(STDOUT, sprintf(
    'scan_worker_started pid=%d limit=%s media_id=%s config_path=%s' . PHP_EOL,
    (int)$pid,
    $limit !== null ? (string)$limit : '-',
    $mediaId !== null ? (string)$mediaId : '-',
    (string)($config['_config_path'] ?? 'unknown')
));
$writeHeartbeat('starting', null, gmdate('c'));

try {
    $options = [];
    if ($limit === null || $limit <= 0) {
        $limit = 11;
        $options = [
            'backfill_limit' => 1,
            'rescan_limit'   => 10,
        ];
    }
    $updateHeartbeat(true);
    $summary = sv_process_scan_job_batch($pdo, $config, $limit, $logger, $pathFilter, $mediaId, $options);
    $updateHeartbeat(true);
    $line = sprintf(
        'Verarbeitet: %d | Erfolgreich: %d | Fehler: %d | Rescan: %d | Scan: %d | Backfill: %d',
        (int)($summary['total'] ?? 0),
        (int)($summary['done'] ?? 0),
        (int)($summary['error'] ?? 0),
        (int)($summary['rescan'] ?? 0),
        (int)($summary['scan'] ?? 0),
        (int)($summary['backfill'] ?? 0)
    );
    fwrite(STDOUT, $line . PHP_EOL);
    $writeHeartbeat('idle', null, gmdate('c'));
    exit(0);
} catch (Throwable $e) {
    $writeHeartbeat('error', null, gmdate('c'));
    fwrite(STDERR, "Worker-Fehler: " . $e->getMessage() . "\n");
    exit(1);
} finally {
    sv_release_worker_lock($lockHandle);
}

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

$writeErrLog = static function (string $message) use ($errLogPath): void {
    $line = '[' . date('c') . '] ' . $message . PHP_EOL;
    $result = file_put_contents($errLogPath, $line, FILE_APPEND);
    if ($result === false) {
        error_log('[scan_worker_cli] Failed to write error log: ' . $message);
    }
};

$pid = getmypid();
$cmdline = implode(' ', $argv);
$host = function_exists('gethostname') ? (string)gethostname() : 'unknown';

if (is_file($lockPath)) {
    $raw = file_get_contents($lockPath);
    if ($raw === false) {
        $writeErrLog('Scan-Worker Lock konnte nicht gelesen werden.');
        sv_log_system_error($config, 'scan_worker_lock_read_failed', ['path' => $lockPath]);
        exit(1);
    }
    $data = json_decode($raw, true);
    $existingPid = is_array($data) && isset($data['pid']) ? (int)$data['pid'] : 0;
    if ($existingPid > 0) {
        $pidInfo = sv_is_pid_running($existingPid);
        if (!empty($pidInfo['running'])) {
            $writeErrLog('Scan-Worker bereits aktiv (PID ' . $existingPid . '), neuer Start abgebrochen.');
            exit(0);
        }
    }
    if (!unlink($lockPath)) {
        $writeErrLog('Scan-Worker Lock konnte nicht entfernt werden.');
        sv_log_system_error($config, 'scan_worker_lock_remove_failed', ['path' => $lockPath]);
        exit(1);
    }
}

$lockPayload = [
    'pid'        => $pid,
    'started_at' => date('c'),
    'host'       => $host,
    'cmdline'    => $cmdline,
];
$lockJson = json_encode($lockPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($lockJson === false || file_put_contents($lockPath, $lockJson, LOCK_EX) === false) {
    $writeErrLog('Scan-Worker Lock konnte nicht geschrieben werden.');
    sv_log_system_error($config, 'scan_worker_lock_write_failed', ['path' => $lockPath]);
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

$logger = function (string $msg): void {
    fwrite(STDOUT, $msg . PHP_EOL);
};

try {
    $options = [];
    if ($limit === null || $limit <= 0) {
        $limit = 11;
        $options = [
            'backfill_limit' => 1,
            'rescan_limit'   => 10,
        ];
    }
    $summary = sv_process_scan_job_batch($pdo, $config, $limit, $logger, $pathFilter, $mediaId, $options);
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
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Worker-Fehler: " . $e->getMessage() . "\n");
    exit(1);
} finally {
    if (is_file($lockPath) && !unlink($lockPath)) {
        $writeErrLog('Scan-Worker Lock konnte nicht entfernt werden (finally).');
        sv_log_system_error($config, 'scan_worker_lock_remove_failed_finally', ['path' => $lockPath]);
    }
}

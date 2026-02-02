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

if (is_file($lockPath)) {
    $raw = file_get_contents($lockPath);
    if ($raw === false) {
        $writeErrLog('Library-Rename Worker Lock konnte nicht gelesen werden.');
        sv_log_system_error($config, 'library_rename_worker_lock_read_failed', ['path' => $lockPath]);
        exit(1);
    }
    $data = json_decode($raw, true);
    $existingPid = is_array($data) && isset($data['pid']) ? (int)$data['pid'] : 0;
    if ($existingPid > 0) {
        $pidInfo = sv_is_pid_running($existingPid);
        if (!empty($pidInfo['running'])) {
            $writeErrLog('Library-Rename Worker bereits aktiv (PID ' . $existingPid . '), neuer Start abgebrochen.');
            exit(0);
        }
    }
    if (!unlink($lockPath)) {
        $writeErrLog('Library-Rename Worker Lock konnte nicht entfernt werden.');
        sv_log_system_error($config, 'library_rename_worker_lock_remove_failed', ['path' => $lockPath]);
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
    $writeErrLog('Library-Rename Worker Lock konnte nicht geschrieben werden.');
    sv_log_system_error($config, 'library_rename_worker_lock_write_failed', ['path' => $lockPath]);
    exit(1);
}

$logger = function (string $msg): void {
    fwrite(STDOUT, $msg . PHP_EOL);
};

try {
    sv_mark_stuck_jobs($pdo, [SV_JOB_TYPE_LIBRARY_RENAME], SV_JOB_STUCK_MINUTES, $logger);
    $summary = sv_process_library_rename_jobs($pdo, $config, $limit, $logger);
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
        $writeErrLog('Library-Rename Worker Lock konnte nicht entfernt werden (finally).');
        sv_log_system_error($config, 'library_rename_worker_lock_remove_failed_finally', ['path' => $lockPath]);
    }
}

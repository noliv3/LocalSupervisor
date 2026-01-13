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

$baseDir = sv_base_dir();
$lockPath = $baseDir . '/LOGS/scan_worker.lock.json';
$errLogPath = $baseDir . '/LOGS/scan_worker.err.log';

$isPidAlive = static function (int $pid): bool {
    if ($pid <= 0) {
        return false;
    }
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }
    $isWindows = stripos(PHP_OS_FAMILY ?? PHP_OS, 'Windows') !== false;
    if ($isWindows) {
        $cmd = 'tasklist /FI ' . escapeshellarg('PID eq ' . $pid);
        $output = @shell_exec($cmd);
        if (!is_string($output)) {
            return false;
        }
        return stripos($output, (string)$pid) !== false;
    }
    $cmd = 'ps -p ' . (int)$pid . ' -o pid=';
    $output = @shell_exec($cmd);
    if (!is_string($output)) {
        return false;
    }
    return trim($output) !== '';
};

$writeErrLog = static function (string $message) use ($errLogPath): void {
    $line = '[' . date('c') . '] ' . $message . PHP_EOL;
    @file_put_contents($errLogPath, $line, FILE_APPEND);
};

$pid = getmypid();
$cmdline = implode(' ', $argv);
$host = function_exists('gethostname') ? (string)gethostname() : 'unknown';

if (is_file($lockPath)) {
    $raw = @file_get_contents($lockPath);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    $existingPid = isset($data['pid']) ? (int)$data['pid'] : 0;
    if ($existingPid > 0 && $isPidAlive($existingPid)) {
        $writeErrLog('Scan-Worker bereits aktiv (PID ' . $existingPid . '), neuer Start abgebrochen.');
        exit(0);
    }
    @unlink($lockPath);
}

$lockPayload = [
    'pid'        => $pid,
    'started_at' => date('c'),
    'host'       => $host,
    'cmdline'    => $cmdline,
];
@file_put_contents($lockPath, json_encode($lockPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

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
    $summary = sv_process_scan_job_batch($pdo, $config, $limit, $logger, $pathFilter, $mediaId);
    $line = sprintf(
        'Verarbeitet: %d | Erfolgreich: %d | Fehler: %d | Rescan: %d | Scan: %d',
        (int)($summary['total'] ?? 0),
        (int)($summary['done'] ?? 0),
        (int)($summary['error'] ?? 0),
        (int)($summary['rescan'] ?? 0),
        (int)($summary['scan'] ?? 0)
    );
    fwrite(STDOUT, $line . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Worker-Fehler: " . $e->getMessage() . "\n");
    exit(1);
} finally {
    if (is_file($lockPath)) {
        @unlink($lockPath);
    }
}

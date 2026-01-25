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

$logsRoot = sv_logs_root($config);
if (!is_dir($logsRoot)) {
    @mkdir($logsRoot, 0777, true);
}
$lockPath = $logsRoot . '/ollama_worker.lock.json';
$errLogPath = $logsRoot . '/ollama_worker.err.log';

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
        $writeErrLog('Ollama-Worker bereits aktiv (PID ' . $existingPid . '), neuer Start abgebrochen.');
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

try {
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

    $batchCount = 0;
    $aggregate = [
        'total' => 0,
        'done' => 0,
        'error' => 0,
        'skipped' => 0,
        'retried' => 0,
    ];

    $startedAt = time();
    while (true) {
        $summary = sv_process_ollama_job_batch($pdo, $config, $batchSize, $logger, $mediaId);
        foreach ($aggregate as $key => $value) {
            $aggregate[$key] = $value + (int)($summary[$key] ?? 0);
        }
        $batchCount++;

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
    if (is_file($lockPath)) {
        @unlink($lockPath);
    }
}

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

$limit   = null;
$mediaId = null;
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $limit = (int)substr($arg, 8);
    } elseif (strpos($arg, '--media-id=') === 0) {
        $mediaId = (int)substr($arg, 11);
    }
}

$runtimeLog = sv_base_dir() . '/LOGS/forge_worker_runtime.log';
$runtimeLine = sprintf(
    '[%s] pid=%d argv=%s media_id=%s limit=%s',
    date('c'),
    (int)getmypid(),
    json_encode($argv, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    $mediaId === null ? '-' : (string)$mediaId,
    $limit === null ? '-' : (string)$limit
);
@file_put_contents($runtimeLog, $runtimeLine . PHP_EOL, FILE_APPEND);

$logger = function (string $msg): void {
    fwrite(STDOUT, $msg . PHP_EOL);
};

try {
    $summary = sv_process_forge_job_batch($pdo, $config, $limit, $logger, $mediaId);
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
}

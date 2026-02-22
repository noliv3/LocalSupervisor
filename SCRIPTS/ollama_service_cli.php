<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Nur CLI erlaubt.\n");
    exit(1);
}

require_once __DIR__ . '/logging.php';

try {
    $config = sv_load_config();
} catch (Throwable $e) {
    fwrite(STDERR, "Init-Fehler: " . $e->getMessage() . "\n");
    exit(1);
}

$workerArgs = ['--loop'];
$hasSleep = false;
$hasBatch = false;

foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--sleep-ms=') === 0) {
        $hasSleep = true;
        $workerArgs[] = $arg;
    } elseif (strpos($arg, '--batch=') === 0 || strpos($arg, '--limit=') === 0) {
        $hasBatch = true;
        $workerArgs[] = $arg;
    } elseif (strpos($arg, '--max-minutes=') === 0) {
        $workerArgs[] = $arg;
    } elseif (strpos($arg, '--media-id=') === 0) {
        $workerArgs[] = $arg;
    } elseif (strpos($arg, '--max-batches=') === 0) {
        $workerArgs[] = $arg;
    }
}

if (!$hasSleep) {
    $workerArgs[] = '--sleep-ms=1000';
}

if (!$hasBatch) {
    $batchSize = (int)($config['ollama']['worker']['batch_size'] ?? 0);
    if ($batchSize > 0) {
        $workerArgs[] = '--batch=' . $batchSize;
    }
}

sv_write_jsonl_log($config, 'ollama_service.jsonl', [
    'ts' => date('c'),
    'event' => 'service_started',
    'pid' => getmypid(),
    'worker_args' => $workerArgs,
    'logs_path' => sv_logs_root($config),
]);

$phpCli = sv_get_php_cli($config);
$workerScript = sv_base_dir() . '/SCRIPTS/ollama_worker_cli.php';

$cmdParts = [escapeshellarg($phpCli), escapeshellarg($workerScript)];
foreach ($workerArgs as $arg) {
    $cmdParts[] = escapeshellarg($arg);
}

$command = implode(' ', $cmdParts);

$exitCode = 0;

passthru($command, $exitCode);
exit((int)$exitCode);

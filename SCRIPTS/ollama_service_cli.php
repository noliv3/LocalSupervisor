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
$requireWebUrl = null;
$requireWebMissThreshold = 3;
$webMissingCount = 0;

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
    } elseif (strpos($arg, '--require-web=') === 0) {
        $requireWebUrl = trim((string)substr($arg, 14));
    } elseif (strpos($arg, '--require-web-miss=') === 0) {
        $requireWebMissThreshold = max(1, (int)substr($arg, 19));
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

if ($requireWebUrl !== null && $requireWebUrl !== '') {
    while (true) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 3,
                'ignore_errors' => true,
            ],
        ]);
        $probeResult = @file_get_contents($requireWebUrl, false, $context);
        $statusCode = null;
        if (isset($http_response_header) && is_array($http_response_header) && isset($http_response_header[0])) {
            if (preg_match('/\s(\d{3})\b/', (string)$http_response_header[0], $matches)) {
                $statusCode = (int)$matches[1];
            }
        }

        if ($probeResult !== false && $statusCode !== null && $statusCode >= 200 && $statusCode < 300) {
            $webMissingCount = 0;
        } else {
            $webMissingCount++;
            sv_write_jsonl_log($config, 'ollama_service.jsonl', [
                'ts' => date('c'),
                'event' => 'web_missing_probe',
                'pid' => getmypid(),
                'probe_url' => $requireWebUrl,
                'status_code' => $statusCode,
                'miss_count' => $webMissingCount,
                'miss_threshold' => $requireWebMissThreshold,
            ]);
            if ($webMissingCount >= $requireWebMissThreshold) {
                sv_write_jsonl_log($config, 'ollama_service.jsonl', [
                    'ts' => date('c'),
                    'event' => 'web_missing_exit',
                    'pid' => getmypid(),
                    'probe_url' => $requireWebUrl,
                    'status_code' => $statusCode,
                    'miss_count' => $webMissingCount,
                    'miss_threshold' => $requireWebMissThreshold,
                ]);
                exit(0);
            }
        }

        $exitCode = 0;
        passthru($command, $exitCode);
        if ($exitCode !== 0) {
            exit((int)$exitCode);
        }

        usleep(1000 * 1000);
    }
}

$exitCode = 0;

passthru($command, $exitCode);
exit((int)$exitCode);

<?php
declare(strict_types=1);

// Minimaler CLI-Wrapper, der die zentrale Operation verwendet.

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Nur CLI.\n");
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

$limit    = null;
$scanPath = null;

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if (strpos($arg, '--limit=') === 0) {
        $limit = (int)substr($arg, strlen('--limit='));
        continue;
    }
    if ($arg === '--limit' && isset($argv[$i + 1])) {
        $limit = (int)$argv[$i + 1];
        $i++;
        continue;
    }

    if ($scanPath === null) {
        $scanPath = $arg;
    }
}

if ($scanPath === null || $scanPath === '') {
    fwrite(STDERR, "Pfad als Argument nötig.\n");
    fwrite(STDERR, "Beispiel: php SCRIPTS/scan_path_cli.php \"D:\\ImportOrdner\" [--limit=100]\n");
    exit(1);
}

$logger = function (string $msg): void {
    fwrite(STDOUT, $msg . PHP_EOL);
};

try {
    sv_run_scan_operation($pdo, $config, $scanPath, $limit, $logger);
} catch (Throwable $e) {
    fwrite(STDERR, "Scan-Fehler: " . $e->getMessage() . "\n");
    exit(1);
}

<?php
declare(strict_types=1);

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

$limit  = null;
$offset = null;
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $limit = (int)substr($arg, strlen('--limit='));
    } elseif (strpos($arg, '--offset=') === 0) {
        $offset = (int)substr($arg, strlen('--offset='));
    }
}

$logger = function (string $msg): void {
    fwrite(STDOUT, $msg . PHP_EOL);
};

try {
    sv_run_rescan_operation($pdo, $config, $limit, $offset, $logger);
} catch (Throwable $e) {
    fwrite(STDERR, "Rescan-Fehler: " . $e->getMessage() . "\n");
    exit(1);
}

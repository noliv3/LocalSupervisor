<?php
declare(strict_types=1);

// CLI-Wrapper für Konsistenzprüfungen (Report oder einfache Reparaturen).

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

$repairMode = 'report';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--repair=')) {
        $value = substr($arg, strlen('--repair='));
        if ($value === 'simple') {
            $repairMode = 'simple';
        } else {
            fwrite(STDERR, "Unbekannter Repair-Modus: {$value}\n");
            exit(1);
        }
    }
}

$logger = function (string $msg): void {
    fwrite(STDOUT, $msg . PHP_EOL);
};

try {
    sv_run_consistency_operation($pdo, $config, $repairMode, $logger);
} catch (Throwable $e) {
    fwrite(STDERR, "Consistency-Fehler: " . $e->getMessage() . "\n");
    exit(1);
}

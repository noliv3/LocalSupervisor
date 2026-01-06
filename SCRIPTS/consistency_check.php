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
    $result = sv_run_consistency_operation($pdo, $config, $repairMode, $logger);
    try {
        $health = sv_collect_health_snapshot($pdo, $config, 5);
        fwrite(STDOUT, "Health-Snapshot:\n");
        fwrite(STDOUT, json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    } catch (Throwable $healthError) {
        fwrite(STDERR, "Health-Snapshot fehlgeschlagen: " . $healthError->getMessage() . PHP_EOL);
    }

    $findingCount = count($result['findings'] ?? []);
    fwrite(STDOUT, "Findings gesamt: {$findingCount}\n");
    if ($findingCount > 0) {
        exit(2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Consistency-Fehler: " . $e->getMessage() . "\n");
    exit(1);
}

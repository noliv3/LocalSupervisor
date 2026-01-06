<?php
declare(strict_types=1);

// Liefert einen konsolidierten DB-Status inkl. Schema-Abgleich und Migrationsstand.

$baseDir = realpath(__DIR__ . '/..');
if ($baseDir === false) {
    fwrite(STDERR, "Fehler: Basisverzeichnis nicht gefunden.\n");
    exit(1);
}

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/db_helpers.php';

try {
    $config = sv_load_config($baseDir);
} catch (Throwable $e) {
    fwrite(STDERR, "Config-Fehler: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

$hasIssues = false;

try {
    $db  = sv_db_connect($config);
    $pdo = $db['pdo'];
} catch (Throwable $e) {
    fwrite(STDERR, "DB-Fehler: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo "DB-Status\n";
echo "  Config: " . ($config['_config_path'] ?? 'unbekannt') . "\n";
echo "  Treiber: {$db['driver']}\n";
echo "  DSN: {$db['redacted_dsn']}\n";
if (!empty($config['_config_warning'])) {
    echo "  Hinweis: {$config['_config_warning']}\n";
}

$diff = sv_db_diff_schema($pdo, $db['driver']);

if ($diff['missing_tables'] === [] && $diff['missing_columns'] === []) {
    echo "Schema: OK (alle Kerntabellen vorhanden)\n";
} else {
    $hasIssues = true;
    echo "Schema: Unvollständig\n";
    foreach ($diff['missing_tables'] as $table) {
        echo "  - Fehlende Tabelle: {$table}\n";
    }
    foreach ($diff['missing_columns'] as $table => $columns) {
        echo "  - Fehlende Spalten in {$table}: " . implode(', ', $columns) . "\n";
    }
}

$migrationDir    = $baseDir . '/SCRIPTS/migrations';
$pendingVersions = [];
$appliedCount    = 0;
$migrationError  = null;

if (is_dir($migrationDir)) {
    $migrations = sv_db_load_migrations($migrationDir);
    try {
        $appliedVersions = sv_db_load_applied_versions($pdo);
        $appliedCount    = count($appliedVersions);
        foreach ($migrations as $migration) {
            if (!isset($appliedVersions[$migration['version']])) {
                $pendingVersions[] = $migration['version'];
            }
        }
    } catch (Throwable $e) {
        $migrationError = 'Migrationsstand nicht lesbar: ' . $e->getMessage();
    }

    if ($migrationError !== null) {
        $hasIssues = true;
        echo "Migrationen: FEHLER ({$migrationError})\n";
    } elseif ($pendingVersions !== []) {
        $hasIssues = true;
        echo "Migrationen: Ausstehend (" . implode(', ', $pendingVersions) . ")\n";
    } else {
        echo "Migrationen: Alle Versionen eingetragen ({$appliedCount} Versionen).\n";
    }
} else {
    $hasIssues = true;
    echo "Migrationen: FEHLER (Verzeichnis fehlt: {$migrationDir})\n";
}

if ($hasIssues) {
    echo "Ergebnis: FEHLER – bitte fehlende Migrationen ausführen oder Schema ergänzen.\n";
    exit(1);
}

echo "Ergebnis: OK\n";
exit(0);

<?php
// Initialisiert die SQLite-Datenbank anhand von DB/schema.sql

declare(strict_types=1);

$baseDir = realpath(__DIR__ . '/..');
if ($baseDir === false) {
    fwrite(STDERR, "Fehler: Basisverzeichnis nicht gefunden.\n");
    exit(1);
}

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/db_helpers.php';

$schemaFile = $baseDir . '/DB/schema.sql';

if (!is_file($schemaFile)) {
    fwrite(STDERR, "Fehler: DB/schema.sql nicht gefunden.\n");
    exit(1);
}

try {
    $config = sv_load_config($baseDir);
} catch (Throwable $e) {
    fwrite(STDERR, "Config-Fehler: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

try {
    $db  = sv_db_connect($config);
    $pdo = $db['pdo'];
} catch (Throwable $e) {
    fwrite(STDERR, "Fehler beim Verbindungsaufbau zur DB: " . $e->getMessage() . "\n");
    exit(1);
}

$schemaSql = file_get_contents($schemaFile);
if ($schemaSql === false) {
    fwrite(STDERR, "Fehler: Konnte DB/schema.sql nicht lesen.\n");
    exit(1);
}

$schemaSql = sv_db_filter_schema_sql($schemaSql, $db['driver']);

try {
    $pdo->beginTransaction();
    $pdo->exec($schemaSql);
    $pdo->commit();
    fwrite(STDOUT, "Datenbank initialisiert ({$db['driver']}, " . $db['redacted_dsn'] . ").\n");

    $diff = sv_db_diff_schema($pdo, $db['driver']);
    if ($diff['missing_tables'] !== [] || $diff['missing_columns'] !== []) {
        fwrite(STDOUT, "WARNUNG: Schema unvollständig.\n");
        foreach ($diff['missing_tables'] as $table) {
            fwrite(STDOUT, "  - Fehlende Tabelle: {$table}\n");
        }
        foreach ($diff['missing_columns'] as $table => $columns) {
            fwrite(STDOUT, "  - Fehlende Spalten in {$table}: " . implode(', ', $columns) . "\n");
        }
    } else {
        fwrite(STDOUT, "Schema-Konsistenz: OK (alle Kerntabellen vorhanden).\n");
    }

    $migrationDir = $baseDir . '/SCRIPTS/migrations';
    if (is_dir($migrationDir)) {
        $migrations = sv_db_load_migrations($migrationDir);
        $pending    = [];
        try {
            $applied = sv_db_load_applied_versions($pdo);
            foreach ($migrations as $migration) {
                if (!isset($applied[$migration['version']])) {
                    $pending[] = $migration['version'];
                }
            }
        } catch (Throwable $e) {
            $pending = array_column($migrations, 'version');
        }

        if ($pending !== []) {
            fwrite(STDOUT, "Ausstehende Migrationen: " . implode(', ', $pending) . "\n");
            fwrite(STDOUT, "Hinweis: Migrationen nur über php SCRIPTS/migrate.php ausführen.\n");
        } else {
            fwrite(STDOUT, "Migrationen: Alle bekannten Versionen eingetragen.\n");
        }
    } else {
        fwrite(STDOUT, "Warnung: Migrationsverzeichnis fehlt, Migrationsstatus unbekannt.\n");
    }

    if (!empty($config['_config_warning'])) {
        fwrite(STDOUT, $config['_config_warning'] . PHP_EOL);
    }
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Fehler beim Ausführen des Schemas: " . $e->getMessage() . "\n");
    exit(1);
}

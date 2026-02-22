<?php
// CLI-Runner für Schema-Migrationen. Beispiel: php SCRIPTS/migrate.php
// Führt neue Migrationen aus SCRIPTS/migrations/ in Versionsreihenfolge aus.
// Migrationen werden nur manuell über dieses Skript gestartet, niemals implizit über Web-Requests.

declare(strict_types=1);

$baseDir = realpath(__DIR__ . '/..');
if ($baseDir === false) {
    fwrite(STDERR, "Fehler: Basisverzeichnis nicht gefunden.\n");
    exit(1);
}

$migrationDir  = $baseDir . '/SCRIPTS/migrations';
$securityFile  = $baseDir . '/SCRIPTS/security.php';

if (!is_dir($migrationDir)) {
    fwrite(STDERR, "Fehler: Migrationsverzeichnis fehlt: {$migrationDir}\n");
    exit(1);
}

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/status.php';

try {
    $config = sv_load_config($baseDir);
} catch (Throwable $e) {
    fwrite(STDERR, "Config-Fehler: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

require_once $securityFile;

try {
    $db  = sv_db_connect($config);
    $pdo = $db['pdo'];
} catch (Throwable $e) {
    fwrite(STDERR, "Fehler beim Verbindungsaufbau zur DB: " . $e->getMessage() . "\n");
    exit(1);
}

if (!empty($config['_config_warning'])) {
    fwrite(STDOUT, $config['_config_warning'] . "\n");
}

fwrite(STDOUT, "Verbunden mit DB ({$db['driver']}, " . $db['redacted_dsn'] . ").\n");

sv_db_ensure_schema_migrations($pdo);
$migrations      = sv_db_load_migrations($migrationDir);
$appliedVersions = sv_db_load_applied_versions($pdo);

foreach ($migrations as $migration) {
    $version     = $migration['version'];
    $description = $migration['description'] ?? '';

    if (sv_db_is_migration_applied($appliedVersions, $migration)) {
        fwrite(STDOUT, "- Übersprungen {$version} (bereits eingetragen)\n");
        continue;
    }

    fwrite(STDOUT, "- Starte {$version}... ");

    try {
        $migration['run']($pdo);
        sv_db_record_version($pdo, $version, $description, $migration['version_hash'] ?? null);
        sv_audit_log($pdo, 'migration_run', 'db', null, ['version' => $version]);
        fwrite(STDOUT, "OK\n");
    } catch (Throwable $e) {
        fwrite(STDOUT, "FEHLER\n");
        fwrite(STDERR, "  -> Migration {$version} abgebrochen: " . $e->getMessage() . "\n");
        exit(1);
    }
}

fwrite(STDOUT, "Alle Migrationen geprüft.\n");

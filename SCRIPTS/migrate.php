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

$configFile    = $baseDir . '/CONFIG/config.php';
$migrationDir  = $baseDir . '/SCRIPTS/migrations';

if (!is_file($configFile)) {
    fwrite(STDERR, "Fehler: CONFIG/config.php nicht gefunden.\n");
    exit(1);
}
if (!is_dir($migrationDir)) {
    fwrite(STDERR, "Fehler: Migrationsverzeichnis fehlt: {$migrationDir}\n");
    exit(1);
}

$config = require $configFile;

if (!isset($config['db']['dsn'])) {
    fwrite(STDERR, "Fehler: DB-DSN in config.php fehlt.\n");
    exit(1);
}

$dsn      = $config['db']['dsn'];
$user     = $config['db']['user']     ?? null;
$password = $config['db']['password'] ?? null;
$options  = $config['db']['options']  ?? [];

try {
    $pdo = new PDO($dsn, $user, $password, $options);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    fwrite(STDERR, "Fehler beim Verbindungsaufbau zur DB: " . $e->getMessage() . "\n");
    exit(1);
}

ensureSchemaMigrationsTable($pdo);
$migrations     = loadMigrations($migrationDir);
$appliedVersions = loadAppliedVersions($pdo);

foreach ($migrations as $migration) {
    $version     = $migration['version'];
    $description = $migration['description'] ?? '';

    if (isset($appliedVersions[$version])) {
        fwrite(STDOUT, "- Übersprungen {$version} (bereits eingetragen)\n");
        continue;
    }

    fwrite(STDOUT, "- Starte {$version}... ");

    try {
        $migration['run']($pdo);
        ensureVersionRecorded($pdo, $version, $description);
        fwrite(STDOUT, "OK\n");
    } catch (Throwable $e) {
        fwrite(STDOUT, "FEHLER\n");
        fwrite(STDERR, "  -> Migration {$version} abgebrochen: " . $e->getMessage() . "\n");
        exit(1);
    }
}

fwrite(STDOUT, "Alle Migrationen geprüft.\n");

function ensureSchemaMigrationsTable(PDO $pdo): void
{
    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS schema_migrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    version TEXT NOT NULL UNIQUE,
    applied_at TEXT NOT NULL,
    description TEXT
);
SQL;
    $pdo->exec($sql);
}

function loadAppliedVersions(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT version FROM schema_migrations');
    $versions = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) as $version) {
        $versions[(string) $version] = true;
    }
    return $versions;
}

function loadMigrations(string $migrationDir): array
{
    $files = glob($migrationDir . '/*.php');
    sort($files, SORT_NATURAL);

    $migrations = [];
    foreach ($files as $file) {
        $migration = require $file;

        if (!is_array($migration) || empty($migration['version']) || !isset($migration['run'])) {
            fwrite(STDERR, "Ungültige Migrationsdatei: {$file}\n");
            exit(1);
        }

        $baseName = basename($file, '.php');
        if ($baseName !== $migration['version']) {
            fwrite(
                STDERR,
                "Versionsstring passt nicht zum Dateinamen ({$baseName}): {$migration['version']}\n"
            );
            exit(1);
        }

        if (!is_callable($migration['run'])) {
            fwrite(STDERR, "Migration besitzt keine ausführbare run()-Funktion: {$file}\n");
            exit(1);
        }

        $migrations[] = $migration;
    }

    return $migrations;
}

function ensureVersionRecorded(PDO $pdo, string $version, string $description): void
{
    $stmt = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = ? LIMIT 1');
    $stmt->execute([$version]);
    if ($stmt->fetchColumn() !== false) {
        return;
    }

    $insert = $pdo->prepare(
        'INSERT INTO schema_migrations (version, applied_at, description) VALUES (?, ?, ?)' 
    );
    $insert->execute([
        $version,
        date('c'),
        $description,
    ]);
}

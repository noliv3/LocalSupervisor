<?php
// Initialisiert die SQLite-Datenbank anhand von DB/schema.sql

declare(strict_types=1);

$baseDir = realpath(__DIR__ . '/..');
if ($baseDir === false) {
    fwrite(STDERR, "Fehler: Basisverzeichnis nicht gefunden.\n");
    exit(1);
}

$configFile = $baseDir . '/CONFIG/config.php';
$schemaFile = $baseDir . '/DB/schema.sql';

if (!is_file($configFile)) {
    fwrite(STDERR, "Fehler: CONFIG/config.php nicht gefunden.\n");
    exit(1);
}
if (!is_file($schemaFile)) {
    fwrite(STDERR, "Fehler: DB/schema.sql nicht gefunden.\n");
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
} catch (Throwable $e) {
    fwrite(STDERR, "Fehler beim Verbindungsaufbau zur DB: " . $e->getMessage() . "\n");
    exit(1);
}

$schemaSql = file_get_contents($schemaFile);
if ($schemaSql === false) {
    fwrite(STDERR, "Fehler: Konnte DB/schema.sql nicht lesen.\n");
    exit(1);
}

try {
    $pdo->beginTransaction();
    $pdo->exec($schemaSql);
    $pdo->commit();
    fwrite(STDOUT, "Datenbank initialisiert.\n");
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Fehler beim Ausführen des Schemas: " . $e->getMessage() . "\n");
    exit(1);
}

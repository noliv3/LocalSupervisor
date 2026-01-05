<?php
declare(strict_types=1);

/**
 * Legacy-CLI für EXIF/Prompt-Extraktion.
 * Hinweis: Die regulären Scan-/Rescan-Pfade ziehen Prompts automatisch;
 * dieses Skript dient nur noch als Kompatibilitäts- und Altbestand-Runner.
 */

$root = dirname(__DIR__);
require_once __DIR__ . '/common.php';
require __DIR__ . '/scan_core.php';

try {
    $config = sv_load_config($root);
} catch (Throwable $e) {
    fwrite(STDERR, "Config-Fehler: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

$dsn      = $config['db']['dsn'] ?? null;
$user     = $config['db']['user'] ?? null;
$password = $config['db']['password'] ?? null;
$options  = $config['db']['options'] ?? [];

$limit  = null;
$offset = null;

foreach ($argv as $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $limit = (int)substr($arg, 8);
    } elseif (strpos($arg, '--offset=') === 0) {
        $offset = (int)substr($arg, 9);
    }
}

if (!is_string($dsn) || $dsn === '') {
    fwrite(STDERR, "DB-Fehler: DSN fehlt in config.\n");
    exit(1);
}

echo "SuperVisOr EXIF/Prompt-Scan (Legacy)\n";
echo "=================================\n\n";

if (!empty($config['_config_warning'])) {
    echo $config['_config_warning'] . "\n\n";
}

try {
    $pdo = new PDO($dsn, $user, $password, $options);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    fwrite(STDERR, "DB-Fehler: " . $e->getMessage() . "\n");
    exit(1);
}

$sql = "SELECT id, path, type FROM media WHERE status = 'active' AND type = 'image' ORDER BY id ASC";
if ($limit !== null) {
    $sql .= ' LIMIT ' . max(0, $limit);
}
if ($offset !== null) {
    $sql .= ' OFFSET ' . max(0, $offset);
}

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$total     = count($rows);
$processed = 0;
$skipped   = 0;
$errors    = 0;

foreach ($rows as $row) {
    $mediaId = (int)$row['id'];
    $path    = (string)$row['path'];
    $type    = (string)$row['type'];

    echo "Media ID {$mediaId}: {$path}\n";

    if (!is_file($path)) {
        echo "  -> Datei nicht gefunden, übersprungen.\n";
        $skipped++;
        continue;
    }

    try {
        $metadata = sv_extract_metadata($path, $type, 'exif_cli');
        sv_store_extracted_metadata($pdo, $mediaId, $type, $metadata, 'exif_cli', function (string $msg) use ($mediaId): void {
            echo "  -> {$msg}\n";
        });
        $processed++;
    } catch (Throwable $e) {
        $errors++;
        echo "  -> Fehler: " . $e->getMessage() . "\n";
    }
}

echo "\nFertig.\n";
echo "Gesamt:   {$total}\n";
echo "Processed:{$processed}\n";
echo "Skipped:  {$skipped}\n";
echo "Errors:   {$errors}\n";

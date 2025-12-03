<?php
declare(strict_types=1);

/**
 * Wendet den zentralen Prompt-Parser auf bestehende Medien an,
 * sofern noch kein vollständiger Prompt-Datensatz vorliegt.
 */

$root = dirname(__DIR__);
$config = require $root . DIRECTORY_SEPARATOR . 'CONFIG' . DIRECTORY_SEPARATOR . 'config.php';
require __DIR__ . '/scan_core.php';

$dsn      = $config['db']['dsn'] ?? null;
$user     = $config['db']['user'] ?? null;
$password = $config['db']['password'] ?? null;
$options  = $config['db']['options'] ?? [];

$limit  = 250;
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

echo "SuperVisOr Prompt-Rebuild\n";
echo "========================\n\n";

try {
    $pdo = new PDO($dsn, $user, $password, $options);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    fwrite(STDERR, "DB-Fehler: " . $e->getMessage() . "\n");
    exit(1);
}

$sql = "SELECT m.id, m.path, m.type, p.id AS prompt_id FROM media m "
     . "LEFT JOIN prompts p ON p.media_id = m.id "
     . "WHERE m.status = 'active' AND m.type = 'image' AND (";
$sql .= "p.id IS NULL OR p.prompt IS NULL OR p.negative_prompt IS NULL OR p.source_metadata IS NULL";
$sql .= " OR p.model IS NULL OR p.sampler IS NULL OR p.cfg_scale IS NULL OR p.steps IS NULL OR p.seed IS NULL OR p.width IS NULL OR p.height IS NULL OR p.scheduler IS NULL";
$sql .= ") ORDER BY m.id ASC";

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
        $metadata = sv_extract_metadata($path, $type, 'prompts_rebuild');
        sv_store_extracted_metadata($pdo, $mediaId, $type, $metadata, 'prompts_rebuild', function (string $msg) use ($mediaId): void {
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

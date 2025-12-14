<?php
declare(strict_types=1);

$baseDir = realpath(__DIR__ . '/..');
if ($baseDir === false) {
    fwrite(STDERR, "Basisverzeichnis nicht gefunden.\n");
    exit(1);
}

$configFile = $baseDir . '/CONFIG/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "CONFIG/config.php fehlt.\n");
    exit(1);
}

$config = require $configFile;

$dsn      = $config['db']['dsn'];
$user     = $config['db']['user']     ?? null;
$password = $config['db']['password'] ?? null;
$options  = $config['db']['options']  ?? [];

try {
    $pdo = new PDO($dsn, $user, $password, $options);
} catch (Throwable $e) {
    fwrite(STDERR, "DB-Fehler: " . $e->getMessage() . "\n");
    exit(1);
}

function countTable(PDO $pdo, string $table): int
{
    $stmt = $pdo->query("SELECT COUNT(*) FROM {$table}");
    return (int)$stmt->fetchColumn();
}

echo "SuperVisOr DB-Status\n";
echo "====================\n\n";

$tables = [
    'media',
    'tags',
    'media_tags',
    'scan_results',
    'prompts',
    'jobs',
    'collections',
    'collection_media',
    'import_log',
];

foreach ($tables as $t) {
    try {
        $c = countTable($pdo, $t);
        echo str_pad($t, 18, ' ') . " : {$c}\n";
    } catch (Throwable $e) {
        echo str_pad($t, 18, ' ') . " : [Fehler: " . $e->getMessage() . "]\n";
    }
}

echo "\nLetzte 5 media-Einträge\n";
echo "-----------------------\n";

$stmt = $pdo->query("
    SELECT id, path, type, has_nsfw, rating, imported_at
    FROM media
    ORDER BY imported_at DESC
    LIMIT 5
");

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "(keine Einträge)\n";
} else {
    foreach ($rows as $row) {
        echo "ID: {$row['id']}\n";
        echo "  Path    : {$row['path']}\n";
        echo "  Type    : {$row['type']}\n";
        echo "  has_nsfw: {$row['has_nsfw']}\n";
        echo "  Rating  : {$row['rating']}\n";
        echo "  Imported: {$row['imported_at']}\n\n";
    }
}

echo "Letzte 5 scan_results\n";
echo "---------------------\n";

$stmt = $pdo->query("
    SELECT id, media_id, scanner, nsfw_score, run_at
    FROM scan_results
    ORDER BY run_at DESC
    LIMIT 5
");

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "(keine Scan-Ergebnisse)\n";
} else {
    foreach ($rows as $row) {
        echo "ID: {$row['id']}\n";
        echo "  media_id : {$row['media_id']}\n";
        echo "  scanner  : {$row['scanner']}\n";
        echo "  nsfw_score: {$row['nsfw_score']}\n";
        echo "  run_at   : {$row['run_at']}\n\n";
    }
}

echo "Tag-Überblick\n";
echo "-------------\n";

$stmt = $pdo->query("
    SELECT COUNT(*) AS tags_total,
           (SELECT COUNT(*) FROM media_tags) AS media_tags_total
    FROM tags
");

$info = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['tags_total' => 0, 'media_tags_total' => 0];

echo "Tags gesamt      : {$info['tags_total']}\n";
echo "media_tags gesamt: {$info['media_tags_total']}\n\n";

echo "Beispiel-Tags (max 10)\n";
echo "----------------------\n";

$stmt = $pdo->query("
    SELECT id, name, type
    FROM tags
    ORDER BY id DESC
    LIMIT 10
");

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "(keine Tags)\n";
} else {
    foreach ($rows as $row) {
        echo "ID: {$row['id']}  Name: {$row['name']}  Type: {$row['type']}\n";
    }
}

echo "\nFertig.\n";

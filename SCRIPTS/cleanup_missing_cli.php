<?php
declare(strict_types=1);

$baseDir = dirname(__DIR__);
$config  = require $baseDir . '/CONFIG/config.php';

$dsn      = $config['db']['dsn'];
$user     = $config['db']['user']     ?? null;
$password = $config['db']['password'] ?? null;
$options  = $config['db']['options']  ?? [];

try {
    $pdo = new PDO($dsn, $user, $password, $options);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA foreign_keys = ON");
} catch (Throwable $e) {
    fwrite(STDERR, "DB-Fehler: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo "SuperVisOr cleanup_missing_cli\n";
echo "==============================\n\n";

// Liste aller missing IDs + paths
$missing = $pdo->query("
    SELECT id, path
      FROM media
     WHERE status = 'missing'
")->fetchAll(PDO::FETCH_ASSOC);

$totalMissing = count($missing);

echo "Missing media-Einträge: {$totalMissing}\n";

if ($totalMissing === 0) {
    echo "Nichts zu tun.\n";
    exit(0);
}

try {
    $pdo->beginTransaction();

    // Lösche media_tags über media_id
    $stmt = $pdo->prepare("DELETE FROM media_tags WHERE media_id = ?");
    $deletedMediaTags = 0;
    foreach ($missing as $m) {
        $deletedMediaTags += $stmt->execute([$m['id']]) ? $stmt->rowCount() : 0;
    }

    // Lösche scan_results über media_id
    $stmt = $pdo->prepare("DELETE FROM scan_results WHERE media_id = ?");
    $deletedScanResults = 0;
    foreach ($missing as $m) {
        $deletedScanResults += $stmt->execute([$m['id']]) ? $stmt->rowCount() : 0;
    }

    // Lösche collection_media über media_id
    $stmt = $pdo->prepare("DELETE FROM collection_media WHERE media_id = ?");
    $deletedCollectionMedia = 0;
    foreach ($missing as $m) {
        $deletedCollectionMedia += $stmt->execute([$m['id']]) ? $stmt->rowCount() : 0;
    }

    // Lösche import_log über path
    $stmt = $pdo->prepare("DELETE FROM import_log WHERE path = ?");
    $deletedImportLog = 0;
    foreach ($missing as $m) {
        $deletedImportLog += $stmt->execute([$m['path']]) ? $stmt->rowCount() : 0;
    }

    // Lösche media-Einträge
    $stmt = $pdo->prepare("DELETE FROM media WHERE id = ?");
    $deletedMedia = 0;
    foreach ($missing as $m) {
        $deletedMedia += $stmt->execute([$m['id']]) ? $stmt->rowCount() : 0;
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Fehler beim Cleanup: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo "Gelöscht:\n";
echo "  media              : {$deletedMedia}\n";
echo "  media_tags         : {$deletedMediaTags}\n";
echo "  scan_results       : {$deletedScanResults}\n";
echo "  import_log         : {$deletedImportLog}\n";
echo "  collection_media   : {$deletedCollectionMedia}\n";

echo "\nCleanup fertig.\n";

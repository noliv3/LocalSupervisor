<?php
declare(strict_types=1);

require_once __DIR__ . '/operations.php';

try {
    $config = sv_load_config();
    $pdo    = sv_open_pdo($config);
    $pdo->exec("PRAGMA foreign_keys = ON");
} catch (Throwable $e) {
    fwrite(STDERR, "Init-Fehler: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

$configWarning = $config['_config_warning'] ?? null;

echo "SuperVisOr cleanup_missing_cli\n";
echo "==============================\n\n";

if ($configWarning) {
    echo $configWarning . "\n\n";
}

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

$lockedRows = $pdo->query("
    SELECT mt.media_id, COUNT(*) AS locked_count
      FROM media_tags mt
      JOIN media m ON m.id = mt.media_id
     WHERE m.status = 'missing' AND mt.locked = 1
     GROUP BY mt.media_id
")->fetchAll(PDO::FETCH_ASSOC);

$protected = [];
foreach ($lockedRows as $row) {
    $protected[(int)$row['media_id']] = (int)$row['locked_count'];
}

$targets = array_values(array_filter($missing, static function (array $row) use ($protected): bool {
    return !isset($protected[(int)$row['id']]);
}));

$protectedCount = count($protected);

if ($protectedCount > 0) {
    echo "Geschützt (locked tags): {$protectedCount}\n";
    if (count($targets) === 0) {
        echo "Alle missing-Einträge enthalten gesperrte Tags und werden nicht gelöscht.\n";
    }
}

if (count($targets) === 0) {
    exit(0);
}

try {
    $pdo->beginTransaction();

    // Lösche media_tags über media_id
    $stmt = $pdo->prepare("DELETE FROM media_tags WHERE media_id = ? AND locked = 0");
    $deletedMediaTags = 0;
    foreach ($targets as $m) {
        $deletedMediaTags += $stmt->execute([$m['id']]) ? $stmt->rowCount() : 0;
    }

    // Lösche scan_results über media_id
    $stmt = $pdo->prepare("DELETE FROM scan_results WHERE media_id = ?");
    $deletedScanResults = 0;
    foreach ($targets as $m) {
        $deletedScanResults += $stmt->execute([$m['id']]) ? $stmt->rowCount() : 0;
    }

    // Lösche collection_media über media_id
    $stmt = $pdo->prepare("DELETE FROM collection_media WHERE media_id = ?");
    $deletedCollectionMedia = 0;
    foreach ($targets as $m) {
        $deletedCollectionMedia += $stmt->execute([$m['id']]) ? $stmt->rowCount() : 0;
    }

    // Lösche import_log über path
    $stmt = $pdo->prepare("DELETE FROM import_log WHERE path = ?");
    $deletedImportLog = 0;
    foreach ($targets as $m) {
        $deletedImportLog += $stmt->execute([$m['path']]) ? $stmt->rowCount() : 0;
    }

    // Lösche media-Einträge
    $stmt = $pdo->prepare("DELETE FROM media WHERE id = ?");
    $deletedMedia = 0;
    foreach ($targets as $m) {
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

if ($protectedCount > 0) {
    echo "\nHinweis: {$protectedCount} Medien mit locked Tags wurden nicht gelöscht.\n";
}

echo "\nCleanup fertig.\n";

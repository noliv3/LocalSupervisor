<?php
// Fügt locked-Flag in media_tags hinzu.

declare(strict_types=1);

$migration = [
    'version' => '006_add_media_tags_locked',
    'description' => 'Add locked flag to media_tags to preserve manuelle Tags',
];

$migration['run'] = function (PDO $pdo) use (&$migration): void {
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $columns = [];
    if ($driver === 'mysql') {
        $stmt = $pdo->query("SHOW COLUMNS FROM media_tags");
        $columns = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } else {
        $stmt = $pdo->query("PRAGMA table_info(media_tags)");
        $columns = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    $hasLocked = false;
    foreach ($columns as $col) {
        $name = (string)($col['Field'] ?? $col['name'] ?? '');
        if ($name === 'locked') {
            $hasLocked = true;
            break;
        }
    }

    if (!$hasLocked) {
        $pdo->exec('ALTER TABLE media_tags ADD COLUMN locked INTEGER NOT NULL DEFAULT 0;');
        $hasLocked = true;
    }

    if ($hasLocked) {
        $pdo->exec('UPDATE media_tags SET locked = 0 WHERE locked IS NULL;');
    }
};

return $migration;

<?php
// Adds an index to speed up media_meta meta_key/meta_value lookups.

declare(strict_types=1);

$migration = [
    'version' => '017_add_media_meta_key_value_index',
    'description' => 'Add index for media_meta(meta_key, meta_value) to accelerate meta lookups.',
];

$migration['run'] = function (PDO $pdo) use (&$migration): void {
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $indexName = 'idx_media_meta_key_value';

    if ($driver === 'mysql') {
        $stmt = $pdo->prepare('SHOW INDEX FROM media_meta WHERE Key_name = ?');
        $stmt->execute([$indexName]);
        $exists = $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    } else {
        $stmt = $pdo->prepare("PRAGMA index_list('media_meta')");
        $stmt->execute();
        $exists = false;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($row['name']) && $row['name'] === $indexName) {
                $exists = true;
                break;
            }
        }
    }

    if ($exists) {
        return;
    }

    $pdo->exec('CREATE INDEX IF NOT EXISTS ' . $indexName . ' ON media_meta(meta_key, meta_value)');
};

return $migration;

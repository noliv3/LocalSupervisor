<?php
// Ergänzt media um Vote-Status, Aktiv-Flag und Soft-Delete-Flag.

declare(strict_types=1);

$migration = [
    'version'     => '20261020_001_add_media_vote_active_deleted',
    'description' => 'Add status_vote, is_active, is_deleted to media table.',
];

$migration['run'] = function (PDO $pdo) use (&$migration): void {
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    $columns = [];
    if ($driver === 'mysql') {
        $stmt = $pdo->query('SHOW COLUMNS FROM media');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $row) {
            if (isset($row['Field'])) {
                $columns[] = strtolower((string)$row['Field']);
            }
        }
    } else {
        $stmt = $pdo->query('PRAGMA table_info(media)');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $row) {
            if (isset($row['name'])) {
                $columns[] = strtolower((string)$row['name']);
            }
        }
    }

    $columns = array_unique($columns);

    if ($driver === 'mysql') {
        $columnDefs = [
            'status_vote' => "VARCHAR(16) NOT NULL DEFAULT 'neutral'",
            'is_active'   => 'INT NOT NULL DEFAULT 1',
            'is_deleted'  => 'INT NOT NULL DEFAULT 0',
        ];
    } else {
        $columnDefs = [
            'status_vote' => "TEXT NOT NULL DEFAULT 'neutral'",
            'is_active'   => 'INTEGER NOT NULL DEFAULT 1',
            'is_deleted'  => 'INTEGER NOT NULL DEFAULT 0',
        ];
    }

    foreach ($columnDefs as $column => $definition) {
        if (!in_array($column, $columns, true)) {
            $pdo->exec('ALTER TABLE media ADD COLUMN ' . $column . ' ' . $definition . ';');
        }
    }
};

return $migration;

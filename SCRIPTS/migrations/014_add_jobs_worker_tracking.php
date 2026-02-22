<?php
// Ergänzt jobs um Worker/Stage-Tracking (SQLite/MySQL).

declare(strict_types=1);

$migration = [
    'version' => '014_add_jobs_worker_tracking',
    'description' => 'Add worker pid/owner and stage tracking to jobs table.',
];

$migration['run'] = function (PDO $pdo) use (&$migration): void {
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    $columns = [];
    if ($driver === 'mysql') {
        $stmt = $pdo->query('SHOW COLUMNS FROM jobs');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $row) {
            if (isset($row['Field'])) {
                $columns[] = strtolower((string)$row['Field']);
            }
        }
    } else {
        $stmt = $pdo->query('PRAGMA table_info(jobs)');
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
            'worker_pid' => 'INT',
            'worker_owner' => 'VARCHAR(255)',
            'stage' => 'VARCHAR(255)',
            'stage_changed_at' => 'DATETIME',
        ];
    } else {
        $columnDefs = [
            'worker_pid' => 'INTEGER',
            'worker_owner' => 'TEXT',
            'stage' => 'TEXT',
            'stage_changed_at' => 'TEXT',
        ];
    }

    foreach ($columnDefs as $column => $definition) {
        if (!in_array($column, $columns, true)) {
            $pdo->exec('ALTER TABLE jobs ADD COLUMN ' . $column . ' ' . $definition . ';');
        }
    }
};

return $migration;

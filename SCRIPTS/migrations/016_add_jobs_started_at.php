<?php
// Ergänzt jobs um ein started_at-Feld (SQLite/MySQL).

declare(strict_types=1);

$migration = [
    'version' => '016_add_jobs_started_at',
    'description' => 'Add started_at column to jobs table for worker start tracking.',
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

    if (!in_array('started_at', $columns, true)) {
        if ($driver === 'mysql') {
            $pdo->exec('ALTER TABLE jobs ADD COLUMN started_at DATETIME;');
        } else {
            $pdo->exec('ALTER TABLE jobs ADD COLUMN started_at TEXT;');
        }
    }
};

return $migration;

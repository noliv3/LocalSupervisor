<?php
// Ergänzt jobs um ein not_before-Feld für Retry-Backoff (SQLite/MySQL).

declare(strict_types=1);

$migration = [
    'version' => '015_add_jobs_not_before',
    'description' => 'Add not_before column to jobs table for retry backoff gating.',
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

    if (!in_array('not_before', $columns, true)) {
        if ($driver === 'mysql') {
            $pdo->exec('ALTER TABLE jobs ADD COLUMN not_before DATETIME;');
        } else {
            $pdo->exec('ALTER TABLE jobs ADD COLUMN not_before TEXT;');
        }
    }
};

return $migration;

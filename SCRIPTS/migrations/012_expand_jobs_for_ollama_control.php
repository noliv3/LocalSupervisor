<?php
// Ergänzt jobs um OLLAMA-Control-Felder (SQLite/MySQL).

declare(strict_types=1);

$migration = [
    'version' => '012_expand_jobs_for_ollama_control',
    'description' => 'Add OLLAMA control fields to jobs table (heartbeat/progress/cancel/error code).',
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
            'last_error_code' => 'VARCHAR(255)',
            'heartbeat_at' => 'DATETIME',
            'progress_bits' => 'INT',
            'progress_bits_total' => 'INT',
            'cancel_requested' => 'INT NOT NULL DEFAULT 0',
            'cancelled_at' => 'DATETIME',
        ];
    } else {
        $columnDefs = [
            'last_error_code' => 'TEXT',
            'heartbeat_at' => 'TEXT',
            'progress_bits' => 'INTEGER',
            'progress_bits_total' => 'INTEGER',
            'cancel_requested' => 'INTEGER NOT NULL DEFAULT 0',
            'cancelled_at' => 'TEXT',
        ];
    }

    foreach ($columnDefs as $column => $definition) {
        if (!in_array($column, $columns, true)) {
            $pdo->exec('ALTER TABLE jobs ADD COLUMN ' . $column . ' ' . $definition . ';');
        }
    }
};

return $migration;

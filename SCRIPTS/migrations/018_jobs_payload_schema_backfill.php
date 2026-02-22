<?php
// Ergänzt fehlende jobs-Spalten für Payload/Stage idempotent (SQLite/MySQL).

declare(strict_types=1);

$migration = [
    'version' => '018_jobs_payload_schema_backfill',
    'description' => 'Add missing jobs payload/stage columns and backfill payload_json from payload.',
];

$migration['run'] = function (PDO $pdo) use (&$migration): void {
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    $columns = [];
    if ($driver === 'mysql') {
        $stmt = $pdo->query('SHOW COLUMNS FROM jobs');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $row) {
            if (isset($row['Field'])) {
                $columns[strtolower((string)$row['Field'])] = true;
            }
        }
    } else {
        $stmt = $pdo->query('PRAGMA table_info(jobs)');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $row) {
            if (isset($row['name'])) {
                $columns[strtolower((string)$row['name'])] = true;
            }
        }
    }

    $columnDefs = $driver === 'mysql'
        ? [
            'payload_json' => 'LONGTEXT',
            'forge_request_json' => 'LONGTEXT',
            'forge_response_json' => 'LONGTEXT',
            'error_message' => 'TEXT',
            'stage' => 'VARCHAR(100)',
            'stage_changed_at' => 'DATETIME',
        ]
        : [
            'payload_json' => 'TEXT',
            'forge_request_json' => 'TEXT',
            'forge_response_json' => 'TEXT',
            'error_message' => 'TEXT',
            'stage' => 'TEXT',
            'stage_changed_at' => 'TEXT',
        ];

    foreach ($columnDefs as $column => $definition) {
        if (!isset($columns[$column])) {
            $pdo->exec('ALTER TABLE jobs ADD COLUMN ' . $column . ' ' . $definition . ';');
            $columns[$column] = true;
        }
    }

    if (isset($columns['payload']) && isset($columns['payload_json'])) {
        $pdo->exec('UPDATE jobs SET payload_json = payload WHERE payload_json IS NULL AND payload IS NOT NULL');
    }
};

return $migration;

<?php
// Erweitert ollama_results um strukturierte Felder.

declare(strict_types=1);

$migration = [
    'version' => '011_expand_ollama_results',
    'description' => 'Expand ollama_results with structured fields for title/caption/score and metadata',
];

$migration['run'] = function (PDO $pdo) use (&$migration): void {
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    $pdo->exec(
        <<<SQL
CREATE TABLE IF NOT EXISTS ollama_results (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    media_id    INTEGER NOT NULL,
    mode        TEXT NOT NULL,
    model       TEXT NOT NULL,
    title       TEXT,
    caption     TEXT,
    score       INTEGER,
    contradictions TEXT,
    missing     TEXT,
    rationale   TEXT,
    raw_json    TEXT,
    raw_text    TEXT,
    parse_error INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT NOT NULL,
    meta        TEXT,
    FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE
);
SQL
    );

    $columns = [];
    if ($driver === 'mysql') {
        $stmt = $pdo->query('SHOW COLUMNS FROM ollama_results');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $row) {
            if (isset($row['Field'])) {
                $columns[] = strtolower((string)$row['Field']);
            }
        }
    } else {
        $stmt = $pdo->query('PRAGMA table_info(ollama_results)');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $row) {
            if (isset($row['name'])) {
                $columns[] = strtolower((string)$row['name']);
            }
        }
    }

    $columns = array_unique($columns);

    $columnDefs = [
        'mode' => 'TEXT NOT NULL DEFAULT "caption"',
        'title' => 'TEXT',
        'caption' => 'TEXT',
        'score' => 'INTEGER',
        'contradictions' => 'TEXT',
        'missing' => 'TEXT',
        'rationale' => 'TEXT',
        'raw_json' => 'TEXT',
        'raw_text' => 'TEXT',
        'parse_error' => 'INTEGER NOT NULL DEFAULT 0',
        'meta' => 'TEXT',
    ];

    foreach ($columnDefs as $column => $definition) {
        if (!in_array($column, $columns, true)) {
            $pdo->exec('ALTER TABLE ollama_results ADD COLUMN ' . $column . ' ' . $definition . ';');
        }
    }

    if ($driver === 'mysql') {
        try {
            $pdo->exec('CREATE INDEX idx_ollama_results_media ON ollama_results (media_id)');
        } catch (Throwable $e) {
        }
        try {
            $pdo->exec('CREATE INDEX idx_ollama_results_mode ON ollama_results (mode)');
        } catch (Throwable $e) {
        }
        try {
            $pdo->exec('CREATE INDEX idx_ollama_results_media_mode ON ollama_results (media_id, mode)');
        } catch (Throwable $e) {
        }
    } else {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ollama_results_media ON ollama_results(media_id);');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ollama_results_mode ON ollama_results(mode);');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ollama_results_media_mode ON ollama_results(media_id, mode);');
    }
};

return $migration;

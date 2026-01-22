<?php
// Fügt ollama_results für Bildanalyse hinzu.

declare(strict_types=1);

$migration = [
    'version'     => '20260920_001_add_ollama_results',
    'description' => 'Add ollama_results table for ollama image analysis results',
];

/**
 * Legt ollama_results an und trägt die Migration ein.
 */
$migration['run'] = function (PDO $pdo) use (&$migration): void {
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

CREATE INDEX IF NOT EXISTS idx_ollama_results_media
    ON ollama_results(media_id);

CREATE INDEX IF NOT EXISTS idx_ollama_results_mode
    ON ollama_results(mode);

CREATE INDEX IF NOT EXISTS idx_ollama_results_media_mode
    ON ollama_results(media_id, mode);
SQL
    );
};

return $migration;

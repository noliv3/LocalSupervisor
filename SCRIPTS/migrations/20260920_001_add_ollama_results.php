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
    model       TEXT NOT NULL,
    result_json TEXT NOT NULL,
    created_at  TEXT NOT NULL,
    FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_ollama_results_media
    ON ollama_results(media_id);
SQL
    );
};

return $migration;

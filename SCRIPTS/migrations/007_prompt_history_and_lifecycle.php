<?php
// Prompt-Historie, Lifecycle- und Quality-Felder hinzufügen.

declare(strict_types=1);

$migration = [
    'version' => '007_prompt_history_and_lifecycle',
    'description' => 'Add prompt_history, lifecycle/quality columns and lifecycle events',
];

$migration['run'] = function (PDO $pdo) use (&$migration): void {
    // Medienfelder erweitern
    $columns = [
        'lifecycle_status' => "ALTER TABLE media ADD COLUMN lifecycle_status TEXT NOT NULL DEFAULT 'active'",
        'lifecycle_reason' => "ALTER TABLE media ADD COLUMN lifecycle_reason TEXT",
        'quality_status'   => "ALTER TABLE media ADD COLUMN quality_status TEXT NOT NULL DEFAULT 'unknown'",
        'quality_score'    => "ALTER TABLE media ADD COLUMN quality_score REAL",
        'quality_notes'    => "ALTER TABLE media ADD COLUMN quality_notes TEXT",
        'deleted_at'       => "ALTER TABLE media ADD COLUMN deleted_at TEXT",
    ];

    foreach ($columns as $column => $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            // SQLite wirft bei vorhandener Spalte eine Exception; sicher ignorieren, wenn Spalte existiert.
        }
    }

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_media_lifecycle_status ON media(lifecycle_status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_media_quality_status ON media(quality_status)');

    // Prompt-Historie
    $pdo->exec(
        <<<SQL
CREATE TABLE IF NOT EXISTS prompt_history (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    media_id         INTEGER NOT NULL,
    prompt_id        INTEGER,
    version          INTEGER NOT NULL,
    source           TEXT NOT NULL,
    created_at       TEXT NOT NULL,
    prompt           TEXT,
    negative_prompt  TEXT,
    model            TEXT,
    sampler          TEXT,
    cfg_scale        REAL,
    steps            INTEGER,
    seed             TEXT,
    width            INTEGER,
    height           INTEGER,
    scheduler        TEXT,
    sampler_settings TEXT,
    loras            TEXT,
    controlnet       TEXT,
    source_metadata  TEXT,
    raw_text         TEXT,
    FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE,
    FOREIGN KEY (prompt_id) REFERENCES prompts(id) ON DELETE SET NULL
);
SQL
    );

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_prompt_history_media ON prompt_history(media_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_prompt_history_prompt ON prompt_history(prompt_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_prompt_history_version ON prompt_history(media_id, version DESC)');

    // Lifecycle-Events
    $pdo->exec(
        <<<SQL
CREATE TABLE IF NOT EXISTS media_lifecycle_events (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    media_id       INTEGER NOT NULL,
    event_type     TEXT NOT NULL,
    from_status    TEXT,
    to_status      TEXT,
    quality_status TEXT,
    quality_score  REAL,
    rule           TEXT,
    reason         TEXT,
    actor          TEXT,
    created_at     TEXT NOT NULL,
    FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE
);
SQL
    );

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_media_lifecycle_events_media ON media_lifecycle_events(media_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_media_lifecycle_events_type ON media_lifecycle_events(event_type)');
};

return $migration;

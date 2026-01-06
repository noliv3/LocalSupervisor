<?php
// Fügt media_meta für strukturierte Metadaten hinzu.

declare(strict_types=1);

$migration = [
    'version'     => '005_media_meta_and_video_meta',
    'description' => 'Add media_meta table for extracted metadata and video details',
];

/**
 * Legt media_meta an und trägt die Migration ein.
 */
$migration['run'] = function (PDO $pdo) use (&$migration): void {
    $pdo->exec(
        <<<SQL
CREATE TABLE IF NOT EXISTS media_meta (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    media_id    INTEGER NOT NULL,
    source      TEXT NOT NULL,
    meta_key    TEXT NOT NULL,
    meta_value  TEXT,
    created_at  TEXT NOT NULL,
    FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_media_meta_media
    ON media_meta(media_id);
SQL
    );
};

return $migration;

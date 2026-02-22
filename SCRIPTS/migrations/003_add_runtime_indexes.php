<?php
// Zusätzliche Laufzeit-Indizes für häufige Filter.

declare(strict_types=1);

$migration = [
    'version' => '003_add_runtime_indexes',
    'description' => 'Add runtime indexes for media type filters',
];

/**
 * Legt zusätzliche Indizes für häufige WHERE/ORDER-Bedingungen an.
 */
$migration['run'] = function (PDO $pdo) use (&$migration): void {
    $pdo->exec(
        <<<SQL
CREATE INDEX IF NOT EXISTS idx_media_type
    ON media(type);
CREATE INDEX IF NOT EXISTS idx_jobs_status_created
    ON jobs(status, created_at);
CREATE INDEX IF NOT EXISTS idx_media_tags_media_locked
    ON media_tags(media_id, locked);
SQL
    );
};

return $migration;

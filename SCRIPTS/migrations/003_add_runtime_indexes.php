<?php
// Zusätzliche Laufzeit-Indizes für häufige Filter.

declare(strict_types=1);

$migration = [
    'version'     => '003_add_runtime_indexes',
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
SQL
    );
};

return $migration;

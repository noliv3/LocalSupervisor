<?php
// Fügt eine Log-Tabelle für Konsistenzprüfungen hinzu.

declare(strict_types=1);

$migration = [
    'version'     => '002_add_consistency_log',
    'description' => 'Add consistency_log table for consistency checks',
];

/**
 * Legt die Tabelle consistency_log an, falls sie noch nicht existiert.
 */
$migration['run'] = function (PDO $pdo) use (&$migration): void {
    $pdo->exec(
        <<<SQL
CREATE TABLE IF NOT EXISTS consistency_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    check_name TEXT NOT NULL,
    severity TEXT NOT NULL,
    message TEXT NOT NULL,
    created_at TEXT NOT NULL
);
SQL
    );

    $stmt = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = ? LIMIT 1');
    $stmt->execute([$migration['version']]);
    if ($stmt->fetchColumn() !== false) {
        return;
    }

    $insert = $pdo->prepare(
        'INSERT INTO schema_migrations (version, applied_at, description) VALUES (?, ?, ?)'
    );
    $insert->execute([
        $migration['version'],
        date('c'),
        $migration['description'],
    ]);
};

return $migration;

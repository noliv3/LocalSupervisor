<?php
// Fügt audit_log für sicherheitsrelevante Aktionen hinzu.

declare(strict_types=1);

$migration = [
    'version'     => '004_add_audit_log',
    'description' => 'Add audit_log table for security-relevant actions',
];

/**
 * Legt die Tabelle audit_log an und trägt die Migration ein.
 */
$migration['run'] = function (PDO $pdo) use (&$migration): void {
    $pdo->exec(
        <<<SQL
CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    action TEXT NOT NULL,
    entity_type TEXT,
    entity_id INTEGER,
    details_json TEXT,
    actor_ip TEXT,
    actor_key TEXT,
    created_at TEXT NOT NULL
);
SQL
    );
};

return $migration;

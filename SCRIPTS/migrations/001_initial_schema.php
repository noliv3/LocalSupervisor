<?php
// Markiert das bestätigte REFERENZSCHEMA_V1 als Basisstand.

declare(strict_types=1);

$migration = [
    'version'     => '001_initial_schema',
    'description' => 'Initial schema baseline supervisor.sqlite',
];

/**
 * Diese Migration nimmt keine Schemaänderungen vor und markiert lediglich den
 * vorhandenen Stand als baseline.
 */
$migration['run'] = function (PDO $pdo) use (&$migration): void {
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

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
    // Keine Schemaänderungen erforderlich; Version wird durch migrate.php protokolliert.
};

return $migration;

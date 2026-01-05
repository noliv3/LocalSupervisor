<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

try {
    $config = sv_load_config();
} catch (Throwable $e) {
    fwrite(STDERR, "Config-Fehler: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

$dsn = (string)($config['db']['dsn'] ?? '');
if ($dsn === '') {
    fwrite(STDERR, "DB-DSN in config.php fehlt.\n");
    exit(1);
}

$pdo = new PDO($dsn, $config['db']['user'] ?? null, $config['db']['password'] ?? null, $config['db']['options'] ?? []);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Spalten in 'prompts':\n";

foreach ($pdo->query('PRAGMA table_info(prompts)') as $row) {
    echo "- " . $row['name'] . "\n";
}

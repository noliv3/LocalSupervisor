<?php
declare(strict_types=1);

$config = require __DIR__ . '/../CONFIG/config.php';

$pdo = new PDO($config['db']['dsn']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Spalten in 'prompts':\n";

foreach ($pdo->query('PRAGMA table_info(prompts)') as $row) {
    echo "- " . $row['name'] . "\n";
}

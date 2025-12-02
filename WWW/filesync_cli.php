<?php
declare(strict_types=1);

// CLI-Wrapper für Filesystem-Sync.
// Aufruf: php SCRIPTS/filesync_cli.php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Nur CLI.\n");
    exit(1);
}

$baseDir = realpath(__DIR__ . '/..');
if ($baseDir === false) {
    fwrite(STDERR, "Basisverzeichnis nicht gefunden.\n");
    exit(1);
}

$configFile = $baseDir . '/CONFIG/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "CONFIG/config.php fehlt.\n");
    exit(1);
}

$config = require $configFile;

$dsn      = $config['db']['dsn'];
$user     = $config['db']['user']     ?? null;
$password = $config['db']['password'] ?? null;
$options  = $config['db']['options']  ?? [];

try {
    $pdo = new PDO($dsn, $user, $password, $options);
} catch (Throwable $e) {
    fwrite(STDERR, "DB-Fehler: " . $e->getMessage() . "\n");
    exit(1);
}

require_once $baseDir . '/SCRIPTS/scan_core.php';

$logger = function (string $msg): void {
    fwrite(STDOUT, $msg . PHP_EOL);
};

try {
    $result = sv_run_filesync($pdo, $logger);

    fwrite(STDOUT, sprintf(
        "Filesync fertig: total=%d, active=%d, missing=%d, changed=%d\n",
        (int)$result['total'],
        (int)$result['active'],
        (int)$result['missing'],
        (int)$result['changed']
    ));
} catch (Throwable $e) {
    fwrite(STDERR, "Filesync-Fehler: " . $e->getMessage() . "\n");
    exit(1);
}

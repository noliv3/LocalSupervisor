<?php
declare(strict_types=1);

// CLI-Wrapper für Rescan bestehender Media ohne Scan-Ergebnis.
// Aufruf: php SCRIPTS/rescan_cli.php

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

$limit  = null;
$offset = null;

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if (strpos($arg, '--limit=') === 0) {
        $limit = (int)substr($arg, strlen('--limit='));
        continue;
    }
    if ($arg === '--limit' && isset($argv[$i + 1])) {
        $limit = (int)$argv[$i + 1];
        $i++;
        continue;
    }
    if (strpos($arg, '--offset=') === 0) {
        $offset = (int)substr($arg, strlen('--offset='));
        continue;
    }
    if ($arg === '--offset' && isset($argv[$i + 1])) {
        $offset = (int)$argv[$i + 1];
        $i++;
        continue;
    }
}

require_once $baseDir . '/SCRIPTS/scan_core.php';

$scannerCfg    = $config['scanner'] ?? [];
$pathsCfg      = $config['paths'] ?? [];
$nsfwThreshold = (float)($scannerCfg['nsfw_threshold'] ?? 0.7);

$logger = function (string $msg): void {
    fwrite(STDOUT, $msg . PHP_EOL);
};

try {
    $result = sv_run_rescan_unscanned(
        $pdo,
        $pathsCfg,
        $scannerCfg,
        $nsfwThreshold,
        $logger,
        $limit,
        $offset
    );

    fwrite(STDOUT, sprintf(
        "Rescan fertig: total=%d, processed=%d, skipped=%d, errors=%d\n",
        (int)$result['total'],
        (int)$result['processed'],
        (int)$result['skipped'],
        (int)$result['errors']
    ));
} catch (Throwable $e) {
    fwrite(STDERR, "Rescan-Fehler: " . $e->getMessage() . "\n");
    exit(1);
}

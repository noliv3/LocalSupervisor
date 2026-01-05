<?php
declare(strict_types=1);

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

require_once __DIR__ . '/scan_core.php';

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

$scannerCfg = $config['scanner'] ?? [];
$pathsCfg   = $config['paths']   ?? [];

if ($argc < 2) {
    fwrite(STDERR, "Pfad als Argument nötig.\n");
    fwrite(STDERR, "Beispiel: php SCRIPTS/scan_path.php \"D:\\ImportOrdner\"\n");
    exit(1);
}

$inputPath = $argv[1];
$inputReal = realpath($inputPath);

if ($inputReal === false || !is_dir($inputReal)) {
    fwrite(STDERR, "Pfad nicht gefunden oder kein Verzeichnis: {$inputPath}\n");
    exit(1);
}

$inputReal = rtrim(str_replace('\\', '/', $inputReal), '/');
$nsfwThreshold = (float)($scannerCfg['nsfw_threshold'] ?? 0.7);

$logger = static function (string $line): void {
    fwrite(STDOUT, $line . PHP_EOL);
};

$logger("Scanne Pfad: {$inputReal}");
if (empty($pathsCfg)) {
    $logger('Warnung: paths-Konfiguration fehlt, Import kann fehlschlagen.');
}
if (empty($scannerCfg['base_url'])) {
    $logger('Hinweis: Scanner base_url nicht gesetzt, Scan wird übersprungen.');
}

try {
    $result = sv_run_scan_path(
        $inputReal,
        $pdo,
        $pathsCfg,
        $scannerCfg,
        $nsfwThreshold,
        $logger
    );
    $logger(
        'Scan abgeschlossen: processed=' . (int)$result['processed']
        . ', skipped=' . (int)$result['skipped']
        . ', errors=' . (int)$result['errors']
    );
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Scan-Fehler: " . $e->getMessage() . "\n");
    exit(1);
}


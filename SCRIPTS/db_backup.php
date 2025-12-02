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

$config = require $configFile;
$dsn    = (string)($config['db']['dsn'] ?? '');

$logDir = (string)($config['paths']['logs'] ?? ($baseDir . '/LOGS'));
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}

$timestamp = date('Ymd_His');
$logFile   = rtrim($logDir, '/\\') . "/db_backup_{$timestamp}.log";

$log = function (string $message) use ($logFile): void {
    $line = '[' . date('c') . "] {$message}";
    fwrite(STDOUT, $line . PHP_EOL);
    file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
};

$log('Starte DB-Backup.');

if (strpos($dsn, 'sqlite:') !== 0) {
    $log('Backup-CLI aktuell nur für SQLite implementiert.');
    exit(1);
}

$dbPath = substr($dsn, strlen('sqlite:'));
if ($dbPath === '') {
    $log('Kein SQLite-Pfad im DSN gefunden.');
    exit(1);
}

$pathIsAbsolute = preg_match('/^(?:[A-Za-z]:\\\\|\\\\\\\\|\/)/', $dbPath) === 1;
if (!$pathIsAbsolute) {
    $dbPath = $baseDir . '/' . ltrim($dbPath, '/\\');
}

$dbPath = str_replace('\\', '/', $dbPath);
if (!is_file($dbPath)) {
    $log("SQLite-Datei nicht gefunden: {$dbPath}");
    exit(1);
}

$backupDir = (string)($config['paths']['backups'] ?? ($baseDir . '/BACKUPS'));
$backupDir = rtrim(str_replace('\\', '/', $backupDir), '/');
if (!is_dir($backupDir)) {
    if (!mkdir($backupDir, 0777, true) && !is_dir($backupDir)) {
        $log("Backup-Verzeichnis kann nicht angelegt werden: {$backupDir}");
        exit(1);
    }
}

$backupFile   = $backupDir . "/supervisor_{$timestamp}.sqlite";
$backupGzip   = $backupFile . '.gz';
$copySuccess  = copy($dbPath, $backupFile);
$gzipSuccess  = false;

if ($copySuccess) {
    $log("Backup erstellt: {$backupFile}");
    $data = file_get_contents($dbPath);
    if ($data !== false) {
        $gz = gzencode($data, 6);
        if ($gz !== false) {
            $gzipSuccess = file_put_contents($backupGzip, $gz) !== false;
            if ($gzipSuccess) {
                $log("Komprimiertes Backup erstellt: {$backupGzip}");
            }
        }
    }

    if (!$gzipSuccess) {
        $log('Hinweis: Komprimierte Backup-Datei wurde nicht erzeugt.');
    }
} else {
    $log('Backup fehlgeschlagen.');
    exit(1);
}

$log('Backup abgeschlossen.');

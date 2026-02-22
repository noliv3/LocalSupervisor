<?php
declare(strict_types=1);


if (!defined('SV_WEB_CONTEXT')) {
    define('SV_WEB_CONTEXT', PHP_SAPI !== 'cli');
}

function sv_base_dir(): string
{
    $baseDir = realpath(__DIR__ . '/..');
    if ($baseDir === false) {
        throw new RuntimeException('Basisverzeichnis nicht gefunden.');
    }

    return $baseDir;
}

function sv_normalize_directory(string $path): string
{
    $normalized = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
    $normalized = rtrim($normalized, DIRECTORY_SEPARATOR);

    return $normalized;
}

function sv_load_config(?string $baseDir = null, bool $allowExampleFallback = false): array
{
    $baseDir = $baseDir ?? sv_base_dir();
    $primary = $baseDir . '/CONFIG/config.php';
    $mounted = '/mnt/data/config.php';
    $allowMountedOverride = filter_var(getenv('SV_ALLOW_MOUNTED_CONFIG') ?: '0', FILTER_VALIDATE_BOOLEAN);

    $usedFile = null;
    $warning  = null;

    if ($allowMountedOverride && is_file($mounted)) {
        $usedFile = $mounted;
    } elseif (is_file($primary)) {
        $usedFile = $primary;
    } else {
        $message = 'CONFIG/config.php fehlt. Systemstart ohne valide Konfiguration nicht möglich.';
        if ($allowExampleFallback) {
            $message .= ' Beispiel-Fallback ist deaktiviert.';
        }
        throw new RuntimeException($message);
    }

    $config = require $usedFile;
    if (!is_array($config)) {
        throw new RuntimeException(basename($usedFile) . ' liefert keine Konfiguration.');
    }

    $config['_config_path'] = $usedFile;
    if ($allowMountedOverride) {
        $config['_mounted_config_opt_in'] = true;
    }

    if (!isset($config['paths']) || !is_array($config['paths'])) {
        $config['paths'] = [];
    }
    $logsPath = $config['paths']['logs'] ?? null;
    if (!is_string($logsPath) || trim($logsPath) === '') {
        $logsPath = $baseDir . '/LOGS';
    }
    $config['paths']['logs'] = sv_normalize_directory($logsPath);

    return $config;
}

function sv_get_php_cli(array $config = []): string
{
    if (!empty($config['php_cli']) && is_string($config['php_cli'])) {
        return (string)$config['php_cli'];
    }

    $baseDir = sv_base_dir();
    $toolsPhp = $baseDir . '/TOOLS/php/php.exe';
    if (is_file($toolsPhp)) {
        return $toolsPhp;
    }

    $isWindows = stripos(PHP_OS_FAMILY ?? PHP_OS, 'Windows') !== false;
    $which = null;
    if (function_exists('shell_exec')) {
        if ($isWindows) {
            $which = shell_exec('where php 2>NUL');
        } else {
            $which = shell_exec('command -v php 2>/dev/null');
        }
    }
    if (is_string($which) && trim($which) !== '') {
        $lines = preg_split('~\\R~u', trim($which));
        if (!empty($lines[0])) {
            return trim($lines[0]);
        }
    }

    return 'php';
}

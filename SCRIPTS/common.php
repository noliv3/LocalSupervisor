<?php
declare(strict_types=1);

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

function sv_load_config(?string $baseDir = null, bool $allowExampleFallback = true): array
{
    $baseDir = $baseDir ?? sv_base_dir();
    $primary = $baseDir . '/CONFIG/config.php';
    $example = $baseDir . '/CONFIG/config.example.php';

    $usedFile = null;
    $warning  = null;

    if (is_file($primary)) {
        $usedFile = $primary;
    } elseif ($allowExampleFallback && is_file($example)) {
        $usedFile = $example;
        $warning  = 'CONFIG/config.php fehlt, verwende CONFIG/config.example.php (nur Beispielwerte).';
    } else {
        throw new RuntimeException('CONFIG/config.php fehlt (kein Beispiel gefunden).');
    }

    $config = require $usedFile;
    if (!is_array($config)) {
        throw new RuntimeException(basename($usedFile) . ' liefert keine Konfiguration.');
    }

    $config['_config_path'] = $usedFile;
    if ($warning !== null) {
        $config['_config_warning'] = $warning;
    }

    return $config;
}

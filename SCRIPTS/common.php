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

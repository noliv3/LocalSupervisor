<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

function sv_resolve_library_path(string $hash, string $ext, string $mediaRoot): string
{
    $hash = strtolower(trim($hash));
    if ($hash === '' || !preg_match('~^[a-f0-9]+$~', $hash)) {
        throw new InvalidArgumentException('Ungültiger Hash für Zielpfad.');
    }

    $mediaRoot = sv_normalize_directory($mediaRoot);
    if ($mediaRoot === '') {
        throw new InvalidArgumentException('Media-Root fehlt für Zielpfad.');
    }

    $extSanitized = strtolower(trim($ext));
    $extSanitized = preg_replace('~[^a-z0-9]+~', '', $extSanitized ?? '');
    $extPart      = $extSanitized !== '' ? '.' . $extSanitized : '';

    $subdir = substr($hash, 0, 2);

    return $mediaRoot . DIRECTORY_SEPARATOR . $subdir . DIRECTORY_SEPARATOR . $hash . $extPart;
}

function sv_media_roots(array $pathsCfg): array
{
    $map = [
        'images_sfw'  => $pathsCfg['images_sfw'] ?? ($pathsCfg['images'] ?? null),
        'images_nsfw' => $pathsCfg['images_nsfw'] ?? ($pathsCfg['images_18'] ?? null),
        'videos_sfw'  => $pathsCfg['videos_sfw'] ?? ($pathsCfg['videos'] ?? null),
        'videos_nsfw' => $pathsCfg['videos_nsfw'] ?? ($pathsCfg['videos_18'] ?? null),
    ];

    $normalized = [];
    foreach ($map as $key => $path) {
        if (!is_string($path) || trim($path) === '') {
            continue;
        }
        $normalized[$key] = sv_normalize_directory($path);
    }

    return $normalized;
}

function sv_media_root_for_path(string $path, array $pathsCfg): ?string
{
    $normPath = sv_normalize_directory($path);
    $roots = sv_media_roots($pathsCfg);

    foreach ($roots as $key => $root) {
        $len = strlen($root);
        if ($len === 0) {
            continue;
        }
        if (strncasecmp($normPath, $root, $len) !== 0) {
            continue;
        }
        $nextChar = substr($normPath, $len, 1);
        if ($normPath === $root || $nextChar === DIRECTORY_SEPARATOR || $nextChar === '' || $nextChar === false) {
            return $key;
        }
    }

    return null;
}

function sv_assert_media_path_allowed(string $path, array $pathsCfg, string $context): void
{
    if (sv_media_root_for_path($path, $pathsCfg) !== null) {
        return;
    }

    throw new RuntimeException('Pfad nicht erlaubt (' . $context . ')');
}

function sv_normalize_absolute_path(string $path): string
{
    $normalized = str_replace('\\', '/', $path);
    $drivePrefix = '';

    if (preg_match('~^[a-zA-Z]:~', $normalized)) {
        $drivePrefix = substr($normalized, 0, 2);
        $normalized = substr($normalized, 2);
    } elseif (!str_starts_with($normalized, '/')) {
        $normalized = sv_base_dir() . '/' . ltrim($normalized, '/');
        $normalized = str_replace('\\', '/', $normalized);
        if (preg_match('~^[a-zA-Z]:~', $normalized)) {
            $drivePrefix = substr($normalized, 0, 2);
            $normalized  = substr($normalized, 2);
        }
    }

    $parts = [];
    foreach (explode('/', $normalized) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            if ($parts === []) {
                throw new RuntimeException('Pfad enthält ungültige Traversalbestandteile.');
            }
            array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }

    $normalizedPath = ($drivePrefix !== '' ? $drivePrefix : '') . '/' . implode('/', $parts);

    $normalizedPath = rtrim($normalizedPath, '/');

    if ($normalizedPath === '') {
        return ($drivePrefix !== '' ? $drivePrefix : '') . '/';
    }

    return $normalizedPath;
}

function sv_collect_stream_roots(array $config, bool $allowPreviews = false, bool $allowBackups = false): array
{
    $pathsCfg = $config['paths'] ?? [];
    $rootsMap = sv_media_roots($pathsCfg);
    $roots    = [];

    foreach ($rootsMap as $root) {
        $rootNormalized = sv_normalize_absolute_path($root);
        if ($rootNormalized !== '') {
            $roots[] = $rootNormalized;
        }
    }

    if ($allowPreviews) {
        $previewCandidate = $pathsCfg['previews'] ?? (sv_base_dir() . '/PREVIEWS');
        if (is_string($previewCandidate) && trim($previewCandidate) !== '') {
            $roots[] = sv_normalize_absolute_path((string)$previewCandidate);
        }
    }

    if ($allowBackups) {
        $backupCandidate = $pathsCfg['backups'] ?? (sv_base_dir() . '/BACKUPS');
        if (is_string($backupCandidate) && trim($backupCandidate) !== '') {
            $roots[] = sv_normalize_absolute_path((string)$backupCandidate);
        }
    }

    $unique = [];
    foreach ($roots as $root) {
        if (!in_array($root, $unique, true)) {
            $unique[] = $root;
        }
    }

    return $unique;
}

function sv_assert_stream_path_allowed(string $path, array $config, string $context, bool $allowPreviews = false, bool $allowBackups = false): void
{
    $normalizedPath = sv_normalize_absolute_path($path);
    $allowedRoots   = sv_collect_stream_roots($config, $allowPreviews, $allowBackups);

    foreach ($allowedRoots as $root) {
        $normalizedRoot = rtrim($root, '/');
        if ($normalizedRoot !== '' && str_starts_with($normalizedPath . '/', $normalizedRoot . '/')) {
            return;
        }
    }

    throw new RuntimeException('Pfad nicht erlaubt (' . $context . ')');
}

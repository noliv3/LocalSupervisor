<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

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

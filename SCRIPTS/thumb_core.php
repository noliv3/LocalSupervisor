<?php
declare(strict_types=1);

/**
 * Thumb-Helfer für Bilder/Videos.
 */

function sv_resolve_ffmpeg_path(array $toolsCfg): string
{
    if (!empty($toolsCfg['ffmpeg'])) {
        return (string)$toolsCfg['ffmpeg'];
    }

    return 'ffmpeg';
}

function sv_render_video_thumbnail(
    string $sourcePath,
    string $cachePath,
    string $ffmpeg,
    ?float $durationSeconds = null,
    ?callable $logger = null
): bool {
    if (!is_file($sourcePath)) {
        if ($logger) {
            $logger('Video-Thumb: Quelle fehlt');
        }
        return false;
    }

    $cacheDir = dirname($cachePath);
    if (!is_dir($cacheDir) && !mkdir($cacheDir, 0777, true) && !is_dir($cacheDir)) {
        if ($logger) {
            $logger('Video-Thumb: Cache-Verzeichnis fehlt: ' . $cacheDir);
        }
        return false;
    }

    if (!function_exists('shell_exec')) {
        if ($logger) {
            $logger('Video-Thumb: shell_exec deaktiviert');
        }
        return false;
    }

    $seek = 1.0;
    if ($durationSeconds !== null && $durationSeconds < 1.0) {
        $seek = 0.0;
    }

    $filter = "scale='if(gt(iw,ih),640,-2)':'if(gt(ih,iw),640,-2)':force_original_aspect_ratio=decrease";
    $cmd = escapeshellarg($ffmpeg)
        . ' -y -ss ' . escapeshellarg((string)$seek)
        . ' -i ' . escapeshellarg($sourcePath)
        . ' -vframes 1 -vf ' . escapeshellarg($filter)
        . ' -f image2 -q:v 4 '
        . escapeshellarg($cachePath)
        . ' 2>&1';

    $output = @shell_exec($cmd);
    if ($output === null || trim((string)$output) === '') {
        if ($logger) {
            $logger('Video-Thumb: ffmpeg ohne Ausgabe');
        }
        return false;
    }

    if (!is_file($cachePath) || (int)@filesize($cachePath) <= 0) {
        if ($logger) {
            $logger('Video-Thumb fehlgeschlagen: ' . trim((string)$output));
        }
        return false;
    }

    return true;
}

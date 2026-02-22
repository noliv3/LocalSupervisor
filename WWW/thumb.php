<?php
declare(strict_types=1);

if (!defined('SV_WEB_CONTEXT')) {
    define('SV_WEB_CONTEXT', true);
}

require_once __DIR__ . '/../SCRIPTS/common.php';
require_once __DIR__ . '/../SCRIPTS/paths.php';
require_once __DIR__ . '/../SCRIPTS/security.php';
require_once __DIR__ . '/../SCRIPTS/operations.php';
require_once __DIR__ . '/../SCRIPTS/thumb_core.php';

try {
    $config = sv_load_config();
} catch (Throwable $e) {
    sv_security_error(500, 'config');
}

$hasInternalAccess = sv_validate_internal_access($config, 'thumb', false);


$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD'], true)) {
    sv_security_error(405, 'Method not allowed.');
}

try {
    $pdo = sv_open_pdo_web($config, true);
} catch (Throwable $e) {
    sv_security_error(500, 'db');
}

function sv_clamp_int(int $value, int $min, int $max, int $default): int
{
    if ($value < $min || $value > $max) {
        return $default;
    }

    return $value;
}

function sv_normalize_adult_flag(array $input): bool
{
    $adultParam = $input['adult'] ?? null;
    $altParam   = $input['18']    ?? null;

    if (is_string($adultParam)) {
        $candidate = strtolower(trim($adultParam));
        if ($candidate === '1') {
            return true;
        }
        if ($candidate === '0') {
            return false;
        }
    }

    if (is_string($altParam)) {
        $candidate = strtolower(trim($altParam));
        if ($candidate === 'true' || $candidate === '1') {
            return true;
        }
    }

    return false;
}

function sv_emit_video_placeholder(): void
{
    $svg = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="200" viewBox="0 0 320 200" role="img" aria-label="Video">'
        . '<rect width="320" height="200" fill="#1c1c1c"/>'
        . '<rect x="20" y="20" width="280" height="160" fill="#2a2a2a" rx="8"/>'
        . '<polygon points="140,90 140,110 170,100" fill="#eaeaea"/>'
        . '<rect x="20" y="20" width="280" height="160" fill="none" stroke="#3a3a3a" rx="8"/>'
        . '</svg>';
    header('Content-Type: image/svg+xml');
    header('Content-Length: ' . (string)strlen($svg));
    echo $svg;
    exit;
}

function sv_emit_busy_placeholder(): void
{
    http_response_code(503);
    header('Retry-After: 1');
    sv_emit_video_placeholder();
}

$allowedJobTypes = ['forge_regen', 'forge_regen_replace', 'forge_regen_v3'];

$showAdult = sv_normalize_adult_flag($_GET);

$jobId   = isset($_GET['job_id']) ? sv_clamp_int((int)$_GET['job_id'], 1, 1_000_000_000, 0) : 0;
$asset   = isset($_GET['asset']) && is_string($_GET['asset']) ? strtolower(trim((string)$_GET['asset'])) : null;
$id      = isset($_GET['id']) ? sv_clamp_int((int)$_GET['id'], 1, 1_000_000_000, 0) : 0;
$variant = isset($_GET['variant']) && is_string($_GET['variant']) ? strtolower(trim((string)$_GET['variant'])) : 'effective';
$forceParent = $variant === 'parent';
$jobAsset = null;
$usingJobAsset = false;

if (!$hasInternalAccess && !in_array($method, ['GET', 'HEAD'], true)) {
    sv_security_error(403, 'Forbidden.');
}

try {
    if ($jobId > 0) {
        if (!$hasInternalAccess) {
            sv_security_error(403, 'Forbidden.');
        }
        if (!in_array((string)$asset, ['preview', 'backup', 'output'], true)) {
            http_response_code(400);
            exit;
        }
        try {
            $jobAsset = sv_resolve_job_asset($pdo, $config, $jobId, (string)$asset, $allowedJobTypes, $id > 0 ? $id : null);
            $id = (int)$jobAsset['media_id'];
            $usingJobAsset = true;
        } catch (Throwable $e) {
            if (sv_is_sqlite_busy($e)) {
                sv_emit_busy_placeholder();
            }
            http_response_code(404);
            exit;
        }
    }

    if ($id <= 0) {
        http_response_code(400);
        exit;
    }

    if (!$usingJobAsset) {
        $effective = sv_resolve_effective_media($pdo, $config, $id, $forceParent);
        $id = (int)$effective['effective_id'];
    }

    $stmt = $pdo->prepare('SELECT path, type, has_nsfw, width, height, duration FROM media WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    if (sv_is_sqlite_busy($e)) {
        sv_emit_busy_placeholder();
    }
    http_response_code(503);
    exit;
}

if (!$row) {
    http_response_code(404);
    exit;
}

if (!$showAdult && (int)($row['has_nsfw'] ?? 0) === 1) {
    http_response_code(403);
    exit;
}

if ($usingJobAsset && $jobAsset !== null) {
    $type     = (string)$row['type'];
    $path     = (string)$jobAsset['path'];
    $duration = isset($row['duration']) ? (float)$row['duration'] : null;
} else {
    $type     = (string)$row['type'];
    $path     = (string)$row['path'];
    $duration = isset($row['duration']) ? (float)$row['duration'] : null;
}

try {
    if ($usingJobAsset) {
        sv_assert_stream_path_allowed($path, $config, 'thumb_job_asset', true, true);
    } else {
        sv_assert_media_path_allowed($path, $config['paths'] ?? [], 'thumb');
    }
} catch (Throwable $e) {
    http_response_code(403);
    exit;
}

if ($type !== 'image' && $type !== 'video') {
    http_response_code(415);
    exit;
}

if (!is_file($path)) {
    http_response_code(404);
    exit;
}

if ($type === 'video') {
    $baseDir   = realpath(__DIR__ . '/..');
    $cachePath = $baseDir ? $baseDir . '/CACHE/thumbs/video/' . $id . '.jpg' : null;
    if ($cachePath === null) {
        sv_emit_video_placeholder();
    }
    $ffmpeg = sv_resolve_ffmpeg_path($config['tools'] ?? []);
    $cacheOk = is_file($cachePath);
    $srcMTime = is_file($path) ? (int)filemtime($path) : 0;
    $cacheMTime = ($cacheOk && is_file($cachePath)) ? (int)filemtime($cachePath) : 0;
    if (!$cacheOk || $srcMTime > $cacheMTime) {
        $logFn = static function (string $msg): void {
            error_log('[thumb.php] ' . $msg);
        };
        $cacheOk = sv_render_video_thumbnail($path, $cachePath, $ffmpeg, $duration, $logFn);
    }

    if (!$cacheOk || !is_file($cachePath)) {
        sv_emit_video_placeholder();
    }

    header('Content-Type: image/jpeg');
    header('Content-Length: ' . (string)filesize($cachePath));
    readfile($cachePath);
    exit;
}

$maxSize = 640;
$mime    = null;
$finfo   = finfo_open(FILEINFO_MIME_TYPE);
if ($finfo !== false) {
    $mime = finfo_file($finfo, $path) ?: null;
    finfo_close($finfo);
}

$raw = file_get_contents($path);
if ($raw === false) {
    http_response_code(500);
    exit;
}

if (function_exists('imagecreatefromstring')) {
    $img = imagecreatefromstring($raw);
    if ($img !== false) {
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = max($w, $h) > 0 ? min(1, $maxSize / max($w, $h)) : 1;
        if ($scale < 1) {
            $newW = (int)round($w * $scale);
            $newH = (int)round($h * $scale);
            $thumb = imagecreatetruecolor($newW, $newH);
            if ($thumb !== false) {
                imagecopyresampled($thumb, $img, 0, 0, 0, 0, $newW, $newH, $w, $h);
                header('Content-Type: image/jpeg');
                imagejpeg($thumb, null, 85);
                imagedestroy($thumb);
                imagedestroy($img);
                exit;
            }
        }
        if ($mime !== null) {
            header('Content-Type: ' . $mime);
        }
        imagejpeg($img, null, 85);
        imagedestroy($img);
        exit;
    }
}

if ($mime !== null) {
    header('Content-Type: ' . $mime);
}

echo $raw;

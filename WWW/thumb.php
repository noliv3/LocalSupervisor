<?php
declare(strict_types=1);

require_once __DIR__ . '/../SCRIPTS/common.php';
require_once __DIR__ . '/../SCRIPTS/paths.php';
require_once __DIR__ . '/../SCRIPTS/thumb_core.php';

try {
    $config = sv_load_config();
} catch (Throwable $e) {
    http_response_code(500);
    echo 'CONFIG-Fehler: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    exit;
}

$dsn      = $config['db']['dsn'];
$user     = $config['db']['user']     ?? null;
$password = $config['db']['password'] ?? null;
$options  = $config['db']['options']  ?? [];

try {
    $pdo = new PDO($dsn, $user, $password, $options);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'DB-Fehler';
    exit;
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

$allowedJobTypes = ['forge_regen', 'forge_regen_replace', 'forge_regen_v3'];

function sv_resolve_job_asset(PDO $pdo, array $config, int $jobId, string $asset, array $allowedJobTypes, ?int $expectedMediaId = null): array
{
    $jobStmt = $pdo->prepare('SELECT id, media_id, type, status, forge_response_json FROM jobs WHERE id = :id');
    $jobStmt->execute([':id' => $jobId]);
    $jobRow = $jobStmt->fetch(PDO::FETCH_ASSOC);

    if (!$jobRow) {
        throw new RuntimeException('Job nicht gefunden.');
    }
    if (!in_array((string)($jobRow['type'] ?? ''), $allowedJobTypes, true)) {
        throw new RuntimeException('Job-Typ nicht erlaubt.');
    }

    $mediaId = isset($jobRow['media_id']) ? (int)$jobRow['media_id'] : 0;
    if ($expectedMediaId !== null && $mediaId !== $expectedMediaId) {
        throw new RuntimeException('Job gehört zu einem anderen Medium.');
    }
    if ($mediaId <= 0) {
        throw new RuntimeException('Ungültige Job-Referenz.');
    }

    $response = json_decode((string)($jobRow['forge_response_json'] ?? ''), true);
    $result   = is_array($response['result'] ?? null) ? $response['result'] : [];

    $path = null;
    if ($asset === 'preview') {
        $path = $result['preview_path'] ?? null;
    } elseif ($asset === 'backup') {
        $path = $result['backup_path'] ?? null;
    } else {
        $path = $result['output_path'] ?? ($result['preview_path'] ?? null);
    }

    if (!is_string($path) || trim($path) === '') {
        throw new RuntimeException('Asset nicht vorhanden.');
    }

    sv_assert_stream_path_allowed($path, $config, 'thumb_job_asset', true, true);

    return [
        'media_id' => $mediaId,
        'path'     => (string)$path,
        'status'   => (string)($jobRow['status'] ?? ''),
    ];
}

$showAdult = sv_normalize_adult_flag($_GET);

$jobId   = isset($_GET['job_id']) ? sv_clamp_int((int)$_GET['job_id'], 1, 1_000_000_000, 0) : 0;
$asset   = isset($_GET['asset']) && is_string($_GET['asset']) ? strtolower(trim((string)$_GET['asset'])) : null;
$id      = isset($_GET['id']) ? sv_clamp_int((int)$_GET['id'], 1, 1_000_000_000, 0) : 0;
$jobAsset = null;
$usingJobAsset = false;

if ($jobId > 0) {
    if (!in_array((string)$asset, ['preview', 'backup', 'output'], true)) {
        http_response_code(400);
        exit;
    }
    try {
        $jobAsset = sv_resolve_job_asset($pdo, $config, $jobId, (string)$asset, $allowedJobTypes, $id > 0 ? $id : null);
        $id = (int)$jobAsset['media_id'];
        $usingJobAsset = true;
    } catch (Throwable $e) {
        http_response_code(404);
        exit;
    }
}

if ($id <= 0) {
    http_response_code(400);
    exit;
}

$stmt = $pdo->prepare('SELECT path, type, has_nsfw, width, height, duration FROM media WHERE id = :id');
$stmt->execute([':id' => $id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

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
        http_response_code(500);
        exit;
    }
    $ffmpeg = sv_resolve_ffmpeg_path($config['tools'] ?? []);
    $cacheOk = is_file($cachePath);
    $srcMTime = (int)@filemtime($path);
    $cacheMTime = $cacheOk ? (int)@filemtime($cachePath) : 0;
    if (!$cacheOk || $srcMTime > $cacheMTime) {
        $logFn = static function (string $msg): void {
            error_log('[thumb.php] ' . $msg);
        };
        $cacheOk = sv_render_video_thumbnail($path, $cachePath, $ffmpeg, $duration, $logFn);
    }

    if (!$cacheOk || !is_file($cachePath)) {
        http_response_code(500);
        exit;
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

$raw = @file_get_contents($path);
if ($raw === false) {
    http_response_code(500);
    exit;
}

if (function_exists('imagecreatefromstring')) {
    $img = @imagecreatefromstring($raw);
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

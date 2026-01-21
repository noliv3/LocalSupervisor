<?php
declare(strict_types=1);

require_once __DIR__ . '/../SCRIPTS/common.php';
require_once __DIR__ . '/../SCRIPTS/paths.php';
require_once __DIR__ . '/../SCRIPTS/security.php';
require_once __DIR__ . '/../SCRIPTS/operations.php';

try {
    $config = sv_load_config();
} catch (Throwable $e) {
    sv_security_error(500, 'config');
}

$hasInternalAccess = sv_validate_internal_access($config, 'media_stream', false);

$dsn      = $config['db']['dsn'];
$user     = $config['db']['user']     ?? null;
$password = $config['db']['password'] ?? null;
$options  = $config['db']['options']  ?? [];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD'], true)) {
    sv_security_error(405, 'Method not allowed.');
}

try {
    $pdo = new PDO($dsn, $user, $password, $options);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    sv_apply_sqlite_pragmas($pdo, $config);
    sv_db_ensure_runtime_indexes($pdo);
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

$allowedJobTypes = ['forge_regen', 'forge_regen_replace', 'forge_regen_v3'];

function sv_stream_media(array $config, array $row, ?string $pathOverride = null, bool $allowPreviews = false, bool $allowBackups = false): void
{
    $pathsCfg = $config['paths'] ?? [];
    $path     = $pathOverride !== null ? (string)$pathOverride : (string)$row['path'];

    try {
        if ($allowPreviews || $allowBackups) {
            sv_assert_stream_path_allowed($path, $config, 'media_stream', $allowPreviews, $allowBackups);
        } else {
            sv_assert_media_path_allowed($path, $pathsCfg, 'media_stream');
        }
    } catch (Throwable $e) {
        http_response_code(403);
        echo 'Pfad blockiert';
        return;
    }

    if (!is_file($path)) {
        http_response_code(404);
        echo 'Datei nicht gefunden';
        return;
    }

    $mime  = null;
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo !== false) {
        $mime = finfo_file($finfo, $path) ?: null;
        finfo_close($finfo);
    }

    $download = isset($_GET['dl']) && $_GET['dl'] === '1';
    $basename = basename($path);

    if ($mime !== null) {
        header('Content-Type: ' . $mime);
    }
    $size = (int)filesize($path);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . rawurlencode($basename) . '"');
    header('Accept-Ranges: bytes');

    $rangeHeader = $_SERVER['HTTP_RANGE'] ?? null;
    $start = 0;
    $end   = $size > 0 ? $size - 1 : 0;
    $status = 200;

    if (is_string($rangeHeader) && preg_match('/bytes=(\\d*)-(\\d*)/i', $rangeHeader, $m)) {
        $rangeStart = $m[1] !== '' ? (int)$m[1] : null;
        $rangeEnd   = $m[2] !== '' ? (int)$m[2] : null;

        if ($rangeStart === null && $rangeEnd !== null) {
            $start = max(0, $size - $rangeEnd);
            $end   = $size - 1;
        } else {
            $start = $rangeStart ?? 0;
            $end   = $rangeEnd !== null ? $rangeEnd : $end;
        }

        if ($start < 0 || $end < $start || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            return;
        }

        $end    = min($end, $size - 1);
        $status = 206;
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }

    $length = $end - $start + 1;
    header('Content-Length: ' . $length);

    $fh = fopen($path, 'rb');
    if ($fh === false) {
        http_response_code(500);
        return;
    }

    if ($start > 0) {
        fseek($fh, $start);
    }

    $bytesLeft = $length;
    while ($bytesLeft > 0 && !feof($fh)) {
        $readSize = (int)min(8192, $bytesLeft);
        $chunk = fread($fh, $readSize);
        if ($chunk === false) {
            break;
        }
        $bytesLeft -= strlen($chunk);
        echo $chunk;
    }
    fclose($fh);
}

$showAdult = sv_normalize_adult_flag($_GET);
$showAdult = $showAdult && $hasInternalAccess;

$jobId   = isset($_GET['job_id']) ? sv_clamp_int((int)$_GET['job_id'], 1, 1_000_000_000, 0) : 0;
$asset   = isset($_GET['asset']) && is_string($_GET['asset']) ? strtolower(trim((string)$_GET['asset'])) : null;
$id      = isset($_GET['id']) ? sv_clamp_int((int)$_GET['id'], 1, 1_000_000_000, 0) : 0;
$jobAsset = null;
$usingJobAsset = false;

if (!$hasInternalAccess && !in_array($method, ['GET', 'HEAD'], true)) {
    sv_security_error(403, 'Forbidden.');
}

if ($jobId > 0) {
    if (!$hasInternalAccess) {
        sv_security_error(403, 'Forbidden.');
    }
    if (!in_array((string)$asset, ['preview', 'backup', 'output'], true)) {
        http_response_code(400);
        echo 'Ungültiges Asset';
        exit;
    }
    try {
        $jobAsset = sv_resolve_job_asset($pdo, $config, $jobId, (string)$asset, $allowedJobTypes, $id > 0 ? $id : null);
        $id = (int)$jobAsset['media_id'];
        $usingJobAsset = true;
    } catch (Throwable $e) {
        http_response_code(404);
        echo 'Asset nicht gefunden';
        exit;
    }
}

if ($id <= 0) {
    http_response_code(400);
    echo 'Ungültige ID';
    exit;
}

$stmt = $pdo->prepare('SELECT path, type, has_nsfw FROM media WHERE id = :id');
$stmt->execute([':id' => $id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    echo 'Eintrag nicht gefunden';
    exit;
}

if (!$showAdult && (int)($row['has_nsfw'] ?? 0) === 1) {
    http_response_code(403);
    echo 'FSK18 blockiert';
    exit;
}

$streamPath = $usingJobAsset && $jobAsset !== null ? (string)$jobAsset['path'] : null;
sv_stream_media($config, $row, $streamPath, $usingJobAsset, $usingJobAsset);

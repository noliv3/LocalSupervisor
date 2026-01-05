<?php
declare(strict_types=1);

require_once __DIR__ . '/../SCRIPTS/common.php';
require_once __DIR__ . '/../SCRIPTS/paths.php';

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

function sv_stream_media(array $config, array $row): void
{
    $pathsCfg = $config['paths'] ?? [];
    $path     = (string)$row['path'];

    try {
        sv_assert_media_path_allowed($path, $pathsCfg, 'media_stream');
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

$id = isset($_GET['id']) ? sv_clamp_int((int)$_GET['id'], 1, 1_000_000_000, 0) : 0;
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

sv_stream_media($config, $row);

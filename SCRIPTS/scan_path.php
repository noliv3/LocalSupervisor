<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Nur CLI.\n");
    exit(1);
}

$baseDir = realpath(__DIR__ . '/..');
if ($baseDir === false) {
    fwrite(STDERR, "Basisverzeichnis nicht gefunden.\n");
    exit(1);
}

$configFile = $baseDir . '/CONFIG/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "CONFIG/config.php fehlt.\n");
    exit(1);
}

$config = require $configFile;

$dsn      = $config['db']['dsn'];
$user     = $config['db']['user']     ?? null;
$password = $config['db']['password'] ?? null;
$options  = $config['db']['options']  ?? [];

try {
    $pdo = new PDO($dsn, $user, $password, $options);
} catch (Throwable $e) {
    fwrite(STDERR, "DB-Fehler: " . $e->getMessage() . "\n");
    exit(1);
}

$scannerCfg = $config['scanner'] ?? [];
$pathsCfg   = $config['paths']   ?? [];

if (empty($scannerCfg['enabled']) || empty($scannerCfg['base_url'])) {
    fwrite(STDERR, "Scanner in config.php nicht konfiguriert.\n");
    exit(1);
}

if ($argc < 2) {
    fwrite(STDERR, "Pfad als Argument nötig.\n");
    fwrite(STDERR, "Beispiel: php SCRIPTS/scan_path.php \"D:\\ImportOrdner\"\n");
    exit(1);
}

$inputPath = $argv[1];
$inputReal = realpath($inputPath);

if ($inputReal === false || !is_dir($inputReal)) {
    fwrite(STDERR, "Pfad nicht gefunden oder kein Verzeichnis: {$inputPath}\n");
    exit(1);
}

$inputReal = rtrim(str_replace('\\', '/', $inputReal), '/');

fwrite(STDOUT, "Scanne Pfad: {$inputReal}\n");

$nsfwThreshold = (float)($scannerCfg['nsfw_threshold'] ?? 0.7);

/**
 * Datei verschieben, inkl. Cross-Drive Fallback.
 */
function moveFile(string $src, string $dest): bool
{
    $destDir = dirname($dest);
    if (!is_dir($destDir)) {
        if (!mkdir($destDir, 0777, true) && !is_dir($destDir)) {
            return false;
        }
    }

    $srcNorm  = str_replace('\\', '/', $src);
    $destNorm = str_replace('\\', '/', $dest);

    if (@rename($srcNorm, $destNorm)) {
        return true;
    }

    if (@copy($srcNorm, $destNorm)) {
        @unlink($srcNorm);
        return true;
    }

    return false;
}

/**
 * Bildgröße auslesen.
 */
function getImageSizeSafe(string $file): array
{
    $info = @getimagesize($file);
    if ($info === false) {
        return [null, null];
    }
    return [$info[0] ?? null, $info[1] ?? null];
}

/**
 * Lokalen Scanner via HTTP aufrufen.
 */
function scanWithLocalScanner(string $file, array $scannerCfg): ?array
{
    $url = rtrim($scannerCfg['base_url'], '/') . '/check';

    $ch = curl_init();
    $postFields = [
        'file'        => new CURLFile($file),
        'autorefresh' => '1',
    ];

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, (int)($scannerCfg['timeout'] ?? 20));

    $headers = ['Accept: application/json'];
    if (!empty($scannerCfg['api_key']) && !empty($scannerCfg['api_key_header'])) {
        $headers[] = $scannerCfg['api_key_header'] . ': ' . $scannerCfg['api_key'];
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        fwrite(STDERR, "Scanner CURL-Fehler: {$err}\n");
        return null;
    }

    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        fwrite(STDERR, "Scanner HTTP-Status {$status} für Datei {$file}\n");
        return null;
    }

    $data = json_decode($resp, true);
    if (!is_array($data)) {
        fwrite(STDERR, "Scanner lieferte kein gültiges JSON für {$file}\n");
        return null;
    }

    return $data;
}

/**
 * Tags in DB eintragen.
 */
function storeTags(PDO $pdo, int $mediaId, array $tags): void
{
    if (!$tags) {
        return;
    }

    $insertTag = $pdo->prepare(
        "INSERT OR IGNORE INTO tags (name, type, locked) VALUES (?, ?, 0)"
    );
    $getTagId = $pdo->prepare(
        "SELECT id FROM tags WHERE name = ?"
    );
    $insertMediaTag = $pdo->prepare(
        "INSERT OR REPLACE INTO media_tags (media_id, tag_id, confidence) VALUES (?, ?, ?)"
    );

    foreach ($tags as $tag) {
        if (is_string($tag)) {
            $name       = $tag;
            $confidence = 1.0;
            $type       = 'content';
        } elseif (is_array($tag)) {
            $name       = (string)($tag['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $confidence = isset($tag['confidence']) ? (float)$tag['confidence'] : 1.0;
            $type       = (string)($tag['type'] ?? 'content');
        } else {
            continue;
        }

        $insertTag->execute([$name, $type]);

        $getTagId->execute([$name]);
        $tagId = (int)$getTagId->fetchColumn();
        if ($tagId <= 0) {
            continue;
        }

        $insertMediaTag->execute([$mediaId, $tagId, $confidence]);
    }
}

/**
 * Scan-Resultat in DB.
 */
function storeScanResult(PDO $pdo, int $mediaId, string $scannerName, ?float $nsfwScore, array $flags, array $raw): void
{
    $stmt = $pdo->prepare("
        INSERT INTO scan_results (media_id, scanner, run_at, nsfw_score, flags, raw_json)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $mediaId,
        $scannerName,
        date('c'),
        $nsfwScore,
        $flags ? json_encode($flags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

/**
 * Import eines einzelnen Files:
 * - Scanner
 * - Zielordner bestimmen
 * - Datei verschieben
 * - DB-Eintrag
 */
function importFile(
    PDO $pdo,
    string $file,
    string $rootInput,
    array $pathsCfg,
    array $scannerCfg,
    float $nsfwThreshold
): void {
    $file = str_replace('\\', '/', $file);

    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $isImage = in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'bmp']);
    $isVideo = in_array($ext, ['mp4', 'mkv', 'mov', 'webm', 'avi']);

    if (!$isImage && !$isVideo) {
        return;
    }

    $type = $isImage ? 'image' : 'video';

    $hash = @hash_file('md5', $file) ?: null;
    $size = @filesize($file) ?: null;
    $ctime = @filemtime($file) ?: time();
    $createdAt = date('c', $ctime);

    $width  = null;
    $height = null;
    $duration = null;
    $fps      = null;

    if ($isImage) {
        [$w, $h] = getImageSizeSafe($file);
        $width  = $w;
        $height = $h;
    }

    $scanData = scanWithLocalScanner($file, $scannerCfg);
    $nsfwScore = null;
    $hasNsfw   = 0;
    $rating    = 0;
    $scanTags  = [];
    $scanFlags = [];

    if ($scanData !== null) {
        $nsfwScore = isset($scanData['nsfw_score']) ? (float)$scanData['nsfw_score'] : null;
        $scanTags  = is_array($scanData['tags'] ?? null) ? $scanData['tags'] : [];
        $scanFlags = is_array($scanData['flags'] ?? null) ? $scanData['flags'] : [];

        if ($nsfwScore !== null && $nsfwScore >= $nsfwThreshold) {
            $hasNsfw = 1;
            $rating  = 3;
        } else {
            $hasNsfw = 0;
            $rating  = 1;
        }

        if (isset($scanData['rating']) && is_string($scanData['rating'])) {
            $r = strtolower($scanData['rating']);
            if ($r === 'explicit') {
                $rating  = 3;
                $hasNsfw = 1;
            } elseif ($r === 'questionable') {
                $rating = max($rating, 2);
            } elseif ($r === 'safe') {
                $rating  = max($rating, 1);
            }
        }
    }

    if ($isImage) {
        $destBase = $hasNsfw ? ($pathsCfg['images_nsfw'] ?? null) : ($pathsCfg['images_sfw'] ?? null);
    } else {
        $destBase = $hasNsfw ? ($pathsCfg['videos_nsfw'] ?? null) : ($pathsCfg['videos_sfw'] ?? null);
    }

    if (!$destBase) {
        fwrite(STDERR, "Zielpfad nicht konfiguriert für Typ {$type}.\n");
        return;
    }

    $destBase = rtrim(str_replace('\\', '/', $destBase), '/');

    $rel = ltrim(substr($file, strlen($rootInput)), '/\\');
    if ($rel === '' || $rel === $file) {
        $rel = basename($file);
    }

    $destPath = $destBase . '/' . $rel;

    if (!moveFile($file, $destPath)) {
        fwrite(STDERR, "Verschieben fehlgeschlagen: {$file} -> {$destPath}\n");
        return;
    }

    $destPathDb = str_replace('\\', '/', $destPath);

    try {
        $pdo->beginTransaction();

        if ($hash !== null) {
            $stmt = $pdo->prepare("SELECT id FROM media WHERE hash = ?");
            $stmt->execute([$hash]);
            $existingId = $stmt->fetchColumn();
            if ($existingId) {
                $pdo->commit();
                return;
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO media
                (path, type, source, width, height, duration, fps, filesize, hash,
                 created_at, imported_at, rating, has_nsfw, parent_media_id, status)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 'active')
        ");

        $stmt->execute([
            $destPathDb,
            $type,
            'import',
            $width,
            $height,
            $duration,
            $fps,
            $size,
            $hash,
            $createdAt,
            date('c'),
            $rating,
            $hasNsfw,
        ]);

        $mediaId = (int)$pdo->lastInsertId();

        if ($scanData !== null) {
            storeScanResult($pdo, $mediaId, 'pixai_sensible', $nsfwScore, $scanFlags, $scanData);
            storeTags($pdo, $mediaId, $scanTags);
        }

        $logStmt = $pdo->prepare("
            INSERT INTO import_log (path, status, message, created_at)
            VALUES (?, ?, ?, ?)
        ");
        $logStmt->execute([
            $destPathDb,
            'imported',
            null,
            date('c'),
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "DB-Fehler beim Import von {$file}: " . $e->getMessage() . "\n");
    }
}

// Iterator über alle Dateien im angegebenen Pfad
$dirIt = new RecursiveDirectoryIterator($inputReal, FilesystemIterator::SKIP_DOTS);
$it    = new RecursiveIteratorIterator($dirIt);

foreach ($it as $fileInfo) {
    if (!$fileInfo->isFile()) {
        continue;
    }
    $path = $fileInfo->getPathname();
    fwrite(STDOUT, "Verarbeite: {$path}\n");
    importFile($pdo, $path, $inputReal, $pathsCfg, $scannerCfg, $nsfwThreshold);
}

fwrite(STDOUT, "Scan abgeschlossen.\n");

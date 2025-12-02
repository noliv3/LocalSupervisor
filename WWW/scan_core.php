<?php
declare(strict_types=1);

/**
 * Hilfsfunktionen und zentrale Scan-Logik für SuperVisOr.
 * Kann von CLI und Web eingebunden werden.
 */

function sv_move_file(string $src, string $dest): bool
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

function sv_get_image_size(string $file): array
{
    $info = @getimagesize($file);
    if ($info === false) {
        return [null, null];
    }
    return [$info[0] ?? null, $info[1] ?? null];
}

/**
 * Scanner-Antwort so interpretieren, dass sie in unser Schema passt.
 *
 * Erwartete Struktur (scanner_api.process_image):
 * {
 *   "modules.nsfw_scanner": {...},
 *   "modules.tagging": {"tags": [...]},
 *   "modules.deepdanbooru_tags": {"tags": [...]},
 *   ...
 * }
 */
function sv_interpret_scanner_response(array $data): array
{
    $nsfw     = is_array($data['modules.nsfw_scanner'] ?? null) ? $data['modules.nsfw_scanner'] : [];
    $tagMod   = is_array(($data['modules.tagging']['tags'] ?? null) ?? null) ? $data['modules.tagging']['tags'] : [];
    $ddbMod   = is_array(($data['modules.deepdanbooru_tags']['tags'] ?? null) ?? null) ? $data['modules.deepdanbooru_tags']['tags'] : [];

    // Risk-Berechnung: max(hentai,porn,sexy) + DeepDanbooru-Rating
    $risk = 0.0;
    foreach (['hentai', 'porn', 'sexy'] as $k) {
        if (isset($nsfw[$k]) && is_numeric($nsfw[$k])) {
            $risk = max($risk, (float)$nsfw[$k]);
        }
    }

    $allDdbLabels = [];
    foreach ($ddbMod as $t) {
        if (is_array($t) && isset($t['label']) && is_string($t['label'])) {
            $allDdbLabels[] = $t['label'];
        }
    }
    if (in_array('rating:explicit', $allDdbLabels, true)) {
        $risk = max($risk, 1.0);
    } elseif (in_array('rating:questionable', $allDdbLabels, true)) {
        $risk = max($risk, 0.7);
    }

    // Tags aus Tagging + DeepDanbooru bündeln
    $tagSet = [];
    $addTag = function (?array $t) use (&$tagSet): void {
        if (!$t) {
            return;
        }
        $label = $t['label'] ?? null;
        if (!is_string($label) || $label === '') {
            return;
        }
        $tagSet[$label] = true;
    };

    foreach ($tagMod as $t) {
        if (is_array($t)) {
            $addTag($t);
        }
    }
    foreach ($ddbMod as $t) {
        if (is_array($t)) {
            $addTag($t);
        }
    }

    $tags = [];
    foreach (array_keys($tagSet) as $label) {
        $tags[] = [
            'name'       => $label,
            'confidence' => 1.0,
            'type'       => 'content',
        ];
    }

    return [
        'nsfw_score' => $risk,
        'tags'       => $tags,
        'flags'      => [],
        'raw'        => $data,
    ];
}

/**
 * Lokalen PixAI-Scanner via HTTP /check aufrufen.
 * - Feldname: "image"
 * - Header:   Authorization: <TOKEN>
 */
function sv_scan_with_local_scanner(string $file, array $scannerCfg, ?callable $logger = null): ?array
{
    $baseUrl = (string)($scannerCfg['base_url'] ?? '');
    $token   = (string)($scannerCfg['token']     ?? '');

    if ($baseUrl === '' || $token === '') {
        if ($logger) {
            $logger('Scanner nicht konfiguriert (base_url oder token fehlt).');
        }
        return null;
    }

    $url = rtrim($baseUrl, '/') . '/check';

    $ch = curl_init();
    $postFields = [
        'image' => new CURLFile($file),
    ];

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, (int)($scannerCfg['timeout'] ?? 30));

    $headers = [
        'Accept: application/json',
        'Authorization: ' . $token,
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        if ($logger) {
            $logger("Scanner CURL-Fehler: {$err}");
        }
        return null;
    }

    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        if ($logger) {
            $logger("Scanner HTTP-Status {$status} für Datei {$file}");
        }
        return null;
    }

    $data = json_decode($resp, true);
    if (!is_array($data)) {
        if ($logger) {
            $logger("Scanner lieferte ungültiges JSON für {$file}");
        }
        return null;
    }

    return sv_interpret_scanner_response($data);
}

function sv_store_tags(PDO $pdo, int $mediaId, array $tags): void
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

function sv_store_scan_result(PDO $pdo, int $mediaId, string $scannerName, ?float $nsfwScore, array $flags, array $raw): void
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
 * Einzeldatei importieren (neuer Eintrag):
 * - Scanner
 * - Zielspeicher
 * - DB-Insert
 */
function sv_import_file(
    PDO $pdo,
    string $file,
    string $rootInput,
    array $pathsCfg,
    array $scannerCfg,
    float $nsfwThreshold,
    ?callable $logger = null
): bool {
    $file = str_replace('\\', '/', $file);

    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $isImage = in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'bmp']);
    $isVideo = in_array($ext, ['mp4', 'mkv', 'mov', 'webm', 'avi']);

    if (!$isImage && !$isVideo) {
        return false;
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
        [$w, $h] = sv_get_image_size($file);
        $width  = $w;
        $height = $h;
    }

    $scanData  = sv_scan_with_local_scanner($file, $scannerCfg, $logger);
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
    }

    if ($isImage) {
        $destBase = $hasNsfw ? ($pathsCfg['images_nsfw'] ?? null) : ($pathsCfg['images_sfw'] ?? null);
    } else {
        $destBase = $hasNsfw ? ($pathsCfg['videos_nsfw'] ?? null) : ($pathsCfg['videos_sfw'] ?? null);
    }

    if (!$destBase) {
        if ($logger) {
            $logger("Zielpfad nicht konfiguriert für Typ {$type}.");
        }
        return false;
    }

    $destBase = rtrim(str_replace('\\', '/', $destBase), '/');

    $rootInput = rtrim(str_replace('\\', '/', $rootInput), '/');
    $rel = ltrim(substr($file, strlen($rootInput)), '/\\');
    if ($rel === '' || $rel === $file) {
        $rel = basename($file);
    }

    $destPath = $destBase . '/' . $rel;

    if (!sv_move_file($file, $destPath)) {
        if ($logger) {
            $logger("Verschieben fehlgeschlagen: {$file} -> {$destPath}");
        }
        return false;
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
                return true;
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
            $raw = is_array($scanData['raw'] ?? null) ? $scanData['raw'] : [];
            sv_store_scan_result($pdo, $mediaId, 'pixai_sensible', $nsfwScore, $scanFlags, $raw);
            sv_store_tags($pdo, $mediaId, $scanTags);
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
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        if ($logger) {
            $logger("DB-Fehler beim Import von {$file}: " . $e->getMessage());
        }
        return false;
    }
}

/**
 * Kompletten Pfad scannen (Import neuer Dateien).
 */
function sv_run_scan_path(
    string $inputPath,
    PDO $pdo,
    array $pathsCfg,
    array $scannerCfg,
    float $nsfwThreshold,
    ?callable $logger = null,
    ?int $limit = null
): array {
    $inputReal = realpath($inputPath);
    if ($inputReal === false || !is_dir($inputReal)) {
        throw new RuntimeException("Pfad nicht gefunden oder kein Verzeichnis: {$inputPath}");
    }

    $inputReal = rtrim(str_replace('\\', '/', $inputReal), '/');

    if ($logger) {
        $logger("Starte Scan: {$inputReal}");
    }

    $processed    = 0;
    $skipped      = 0;
    $errors       = 0;
    $handledTotal = 0;
    $limitReached = false;

    $dirIt = new RecursiveDirectoryIterator($inputReal, FilesystemIterator::SKIP_DOTS);
    $it    = new RecursiveIteratorIterator($dirIt);

    foreach ($it as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }
        $path = $fileInfo->getPathname();

        if ($logger) {
            $logger("Verarbeite: {$path}");
        }

        if ($limit !== null && $handledTotal >= $limit) {
            $limitReached = true;
            break;
        }

        try {
            $ok = sv_import_file($pdo, $path, $inputReal, $pathsCfg, $scannerCfg, $nsfwThreshold, $logger);
            if ($ok) {
                $processed++;
            } else {
                $skipped++;
            }
            $handledTotal++;
        } catch (Throwable $e) {
            $errors++;
            $handledTotal++;
            if ($logger) {
                $logger("Fehler bei Datei {$path}: " . $e->getMessage());
            }
        }
    }

    if ($logger) {
        $suffix = $limitReached ? ' (Limit erreicht)' : '';
        $logger("Scan abgeschlossen. processed={$processed}, skipped={$skipped}, errors={$errors}{$suffix}");
    }

    return [
        'processed' => $processed,
        'skipped'   => $skipped,
        'errors'    => $errors,
    ];
}

/**
 * Bestehenden media-Datensatz neu scannen und ggf. verschieben/aktualisieren.
 */
function sv_rescan_media(
    PDO $pdo,
    array $mediaRow,
    array $pathsCfg,
    array $scannerCfg,
    float $nsfwThreshold,
    ?callable $logger = null
): bool {
    $id   = (int)$mediaRow['id'];
    $path = (string)$mediaRow['path'];
    $type = (string)$mediaRow['type'];

    $fullPath = str_replace('\\', '/', $path);
    if (!is_file($fullPath)) {
        if ($logger) {
            $logger("Media ID {$id}: Datei nicht gefunden: {$fullPath}");
        }

        // Status auf missing setzen
        $stmt = $pdo->prepare("UPDATE media SET status = 'missing' WHERE id = ?");
        $stmt->execute([$id]);

        return false;
    }

    $scanData  = sv_scan_with_local_scanner($fullPath, $scannerCfg, $logger);
    if ($scanData === null) {
        if ($logger) {
            $logger("Media ID {$id}: Scanner fehlgeschlagen.");
        }
        return false;
    }

    $nsfwScore = isset($scanData['nsfw_score']) ? (float)$scanData['nsfw_score'] : null;
    $scanTags  = is_array($scanData['tags'] ?? null) ? $scanData['tags'] : [];
    $scanFlags = is_array($scanData['flags'] ?? null) ? $scanData['flags'] : [];
    $raw       = is_array($scanData['raw'] ?? null) ? $scanData['raw'] : [];

    $hasNsfw = 0;
    $rating  = 0;

    if ($nsfwScore !== null && $nsfwScore >= $nsfwThreshold) {
        $hasNsfw = 1;
        $rating  = 3;
    } else {
        $hasNsfw = 0;
        $rating  = 1;
    }

    $isImage = ($type === 'image');
    $isVideo = ($type === 'video');

    if ($isImage) {
        $targetBase = $hasNsfw ? ($pathsCfg['images_nsfw'] ?? null) : ($pathsCfg['images_sfw'] ?? null);
    } elseif ($isVideo) {
        $targetBase = $hasNsfw ? ($pathsCfg['videos_nsfw'] ?? null) : ($pathsCfg['videos_sfw'] ?? null);
    } else {
        $targetBase = null;
    }

    if (!$targetBase) {
        if ($logger) {
            $logger("Media ID {$id}: kein Zielpfad konfiguriert für Typ {$type}.");
        }
        return false;
    }

    $targetBase = rtrim(str_replace('\\', '/', $targetBase), '/');

    $managedRoots = [];
    foreach (['images_sfw', 'images_nsfw', 'videos_sfw', 'videos_nsfw'] as $k) {
        if (!empty($pathsCfg[$k])) {
            $managedRoots[] = rtrim(str_replace('\\', '/', $pathsCfg[$k]), '/');
        }
    }

    $currentRoot = null;
    foreach ($managedRoots as $root) {
        if (strpos($fullPath, $root . '/') === 0 || $fullPath === $root) {
            $currentRoot = $root;
            break;
        }
    }

    $newPath = $fullPath;

    if ($currentRoot !== null && $currentRoot !== $targetBase) {
        $fileName = basename($fullPath);
        $candidate = $targetBase . '/' . $fileName;

        $i = 1;
        $candidateTest = $candidate;
        while (is_file($candidateTest) && $candidateTest !== $fullPath) {
            $candidateTest = $targetBase . '/' . $i . '_' . $fileName;
            $i++;
        }

        if (!sv_move_file($fullPath, $candidateTest)) {
            if ($logger) {
                $logger("Media ID {$id}: Verschieben fehlgeschlagen: {$fullPath} -> {$candidateTest}");
            }
            $candidateTest = $fullPath;
        }

        $newPath = str_replace('\\', '/', $candidateTest);
    }

    try {
        $pdo->beginTransaction();

        $delTags = $pdo->prepare("DELETE FROM media_tags WHERE media_id = ?");
        $delTags->execute([$id]);

        sv_store_tags($pdo, $id, $scanTags);
        sv_store_scan_result($pdo, $id, 'pixai_sensible', $nsfwScore, $scanFlags, $raw);

        $stmt = $pdo->prepare("
            UPDATE media
            SET path = ?, has_nsfw = ?, rating = ?, status = 'active'
            WHERE id = ?
        ");
        $stmt->execute([
            $newPath,
            $hasNsfw,
            $rating,
            $id,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        if ($logger) {
            $logger("Media ID {$id}: DB-Fehler beim Rescan: " . $e->getMessage());
        }
        return false;
    }

    if ($logger) {
        $logger("Media ID {$id}: Rescan OK (has_nsfw={$hasNsfw}, rating={$rating})");
    }

    return true;
}

/**
 * Alle Media ohne Scan-Ergebnis neu scannen.
 */
function sv_run_rescan_unscanned(
    PDO $pdo,
    array $pathsCfg,
    array $scannerCfg,
    float $nsfwThreshold,
    ?callable $logger = null,
    ?int $limit = null,
    ?int $offset = null
): array {
    $sql = "
        SELECT m.*
        FROM media m
        LEFT JOIN scan_results s ON s.media_id = m.id
        WHERE s.id IS NULL
          AND m.type = 'image'
        ORDER BY m.id ASC
    ";

    if ($limit !== null) {
        $sql .= ' LIMIT ' . max(0, (int)$limit);
    }

    if ($offset !== null) {
        $sql .= ' OFFSET ' . max(0, (int)$offset);
    }

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total     = count($rows);
    $processed = 0;
    $skipped   = 0;
    $errors    = 0;
    $limitHit  = $limit !== null && $total >= $limit;

    if ($logger) {
        $limitInfo = $limit !== null ? " (limit={$limit}" . ($offset !== null ? ", offset={$offset}" : '') . ')' : '';
        $logger("Rescan: {$total} Medien ohne Scan-Ergebnis gefunden{$limitInfo}.");
    }

    foreach ($rows as $row) {
        $id = (int)$row['id'];
        if ($logger) {
            $logger("Rescan Media ID {$id}: {$row['path']}");
        }

        try {
            $ok = sv_rescan_media($pdo, $row, $pathsCfg, $scannerCfg, $nsfwThreshold, $logger);
            if ($ok) {
                $processed++;
            } else {
                $skipped++;
            }
        } catch (Throwable $e) {
            $errors++;
            if ($logger) {
                $logger("Rescan Media ID {$id}: Fehler: " . $e->getMessage());
            }
        }
    }

    if ($logger) {
        $suffix = $limitHit ? ' (Limit erreicht)' : '';
        $logger("Rescan abgeschlossen. total={$total}, processed={$processed}, skipped={$skipped}, errors={$errors}{$suffix}");
    }

    return [
        'total'     => $total,
        'processed' => $processed,
        'skipped'   => $skipped,
        'errors'    => $errors,
    ];
}

/**
 * Filesystem-Sync:
 * - Prüft alle media.path
 * - setzt status = 'active' oder 'missing'
 */
function sv_run_filesync(
    PDO $pdo,
    ?callable $logger = null,
    ?int $limit = null,
    ?int $offset = null
): array {
    $sql = "SELECT id, path, status FROM media ORDER BY id ASC";

    if ($limit !== null) {
        $sql .= ' LIMIT ' . max(0, (int)$limit);
    }

    if ($offset !== null) {
        $sql .= ' OFFSET ' . max(0, (int)$offset);
    }

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total    = count($rows);
    $active   = 0;
    $missing  = 0;
    $touched  = 0;
    $limitHit = $limit !== null && $total >= $limit;

    if ($logger) {
        $limitInfo = $limit !== null ? " (limit={$limit}" . ($offset !== null ? ", offset={$offset}" : '') . ')' : '';
        $logger("Filesync: {$total} Medien prüfen{$limitInfo}.");
    }

    $updateStatus = $pdo->prepare("UPDATE media SET status = ? WHERE id = ?");

    foreach ($rows as $row) {
        $id     = (int)$row['id'];
        $path   = (string)$row['path'];
        $status = (string)($row['status'] ?? 'active');

        $fullPath = str_replace('\\', '/', $path);
        $exists   = is_file($fullPath);

        if ($exists) {
            $active++;
            if ($status !== 'active') {
                $updateStatus->execute(['active', $id]);
                $touched++;
                if ($logger) {
                    $logger("Media ID {$id}: existiert, status -> active");
                }
            }
        } else {
            $missing++;
            if ($status !== 'missing') {
                $updateStatus->execute(['missing', $id]);
                $touched++;
                if ($logger) {
                    $logger("Media ID {$id}: fehlt, status -> missing");
                }
            }
        }
    }

    if ($logger) {
        $suffix = $limitHit ? ' (Limit erreicht)' : '';
        $logger("Filesync abgeschlossen. total={$total}, active={$active}, missing={$missing}, geändert={$touched}{$suffix}");
    }

    return [
        'total'   => $total,
        'active'  => $active,
        'missing' => $missing,
        'changed' => $touched,
    ];
}

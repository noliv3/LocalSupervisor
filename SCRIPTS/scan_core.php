<?php
declare(strict_types=1);

/**
 * Hilfsfunktionen und zentrale Scan-Logik für SuperVisOr.
 * Kann von CLI und Web eingebunden werden.
 */

require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/prompt_parser.php';

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
        $label = isset($t['label']) && is_string($t['label']) ? trim($t['label']) : '';
        if ($label === '') {
            return;
        }
        $confidence = null;
        foreach (['confidence', 'probability', 'score'] as $confKey) {
            if (isset($t[$confKey]) && is_numeric($t[$confKey])) {
                $confidence = (float)$t[$confKey];
                break;
            }
        }
        if ($confidence === null) {
            $confidence = 1.0;
        }
        if ($confidence <= 0.0) {
            return;
        }
        if (!isset($tagSet[$label]) || $tagSet[$label]['confidence'] < $confidence) {
            $tagSet[$label] = [
                'name'       => $label,
                'confidence' => $confidence,
                'type'       => is_string($t['type'] ?? null) ? (string)$t['type'] : 'content',
            ];
        }
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

    $tags = array_values($tagSet);

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
            $name       = trim($tag);
            $confidence = 1.0;
            $type       = 'content';
        } elseif (is_array($tag)) {
            $name       = isset($tag['name']) ? trim((string)$tag['name']) : '';
            if ($name === '') {
                continue;
            }
            $confidence = isset($tag['confidence']) && is_numeric($tag['confidence']) ? (float)$tag['confidence'] : 1.0;
            $type       = (string)($tag['type'] ?? 'content');
        } else {
            continue;
        }

        if ($confidence <= 0.0) {
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


function sv_stringify_meta_value($value): ?string
{
    if ($value === null) {
        return null;
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_scalar($value)) {
        return (string)$value;
    }

    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return null;
    }

    return $json;
}

function sv_read_png_text_chunks(string $file): array
{
    $data = @file_get_contents($file);
    if ($data === false || strlen($data) < 8) {
        return [];
    }

    $offset = 8; // PNG Signatur überspringen
    $len    = strlen($data);
    $result = [];

    while ($offset + 8 <= $len) {
        $lengthData = substr($data, $offset, 4);
        $chunkLen   = unpack('N', $lengthData)[1] ?? 0;
        $chunkType  = substr($data, $offset + 4, 4);
        $chunkData  = substr($data, $offset + 8, $chunkLen);
        $offset    += 12 + $chunkLen; // Länge + Typ + Daten + CRC

        if ($offset > $len) {
            break;
        }

        if ($chunkType === 'tEXt') {
            $parts = explode("\0", $chunkData, 2);
            if (count($parts) === 2) {
                $result[$parts[0]] = $parts[1];
            }
        } elseif ($chunkType === 'iTXt') {
            $parts = explode("\0", $chunkData, 5);
            if (count($parts) >= 5) {
                $keyword       = $parts[0];
                $compressed    = (int)($parts[1] ?? 0) === 1;
                $compression   = $parts[2] ?? '';
                $translated    = $parts[4] ?? '';
                $remainingText = count($parts) === 5 ? $parts[4] : implode("\0", array_slice($parts, 4));

                if ($compressed && function_exists('gzuncompress')) {
                    $uncompressed = @gzuncompress($remainingText);
                    $text         = $uncompressed !== false ? $uncompressed : $remainingText;
                } else {
                    $text = $remainingText;
                }

                $result[$keyword !== '' ? $keyword : 'iTXt'] = $text;
                if ($translationLabel = trim((string)$translated)) {
                    $result[$keyword . '.lang'] = $translationLabel;
                }
                if ($compression !== '') {
                    $result[$keyword . '.compression'] = $compression;
                }
            }
        } elseif ($chunkType === 'zTXt') {
            $parts = explode("\0", $chunkData, 2);
            if (count($parts) === 2) {
                $keyword = $parts[0];
                $text    = $parts[1];
                $plain   = $text;
                if (function_exists('gzuncompress')) {
                    $uncompressed = @gzuncompress($text);
                    if ($uncompressed !== false) {
                        $plain = $uncompressed;
                    }
                }
                $result[$keyword] = $plain;
            }
        }
    }

    return $result;
}

function sv_collect_media_meta_dimensions(array $metaPairs): array
{
    $dimensions = [
        'width'    => null,
        'height'   => null,
        'duration' => null,
        'fps'      => null,
        'filesize' => null,
    ];

    foreach ($metaPairs as $pair) {
        if (!is_array($pair)) {
            continue;
        }
        $key   = (string)($pair['key'] ?? '');
        $value = $pair['value'] ?? null;
        if ($key === '') {
            continue;
        }
        if ($key === 'video.width' || $key === 'width') {
            $dimensions['width'] = is_numeric($value) ? (int)$value : $dimensions['width'];
        } elseif ($key === 'video.height' || $key === 'height') {
            $dimensions['height'] = is_numeric($value) ? (int)$value : $dimensions['height'];
        } elseif ($key === 'video.duration' || $key === 'format.duration' || $key === 'duration') {
            $dimensions['duration'] = is_numeric($value) ? (float)$value : $dimensions['duration'];
        } elseif ($key === 'video.fps' || $key === 'fps') {
            $dimensions['fps'] = is_numeric($value) ? (float)$value : $dimensions['fps'];
        } elseif ($key === 'filesize' || $key === 'format.size') {
            $dimensions['filesize'] = is_numeric($value) ? (int)$value : $dimensions['filesize'];
        }
    }

    return $dimensions;
}

/**
 * Extrahiert Metadaten und Prompts aus einer Datei.
 */
function sv_extract_metadata(string $file, string $type, string $source, ?callable $logger = null): array
{
    $metaPairs   = [];

    if ($type === 'image') {
        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($file, null, true);
            if (is_array($exif)) {
                foreach ($exif as $section => $values) {
                    if (!is_array($values)) {
                        continue;
                    }
                    foreach ($values as $k => $v) {
                        $keyName = is_string($section) ? $section . '.' . $k : (string)$k;
                        $val     = sv_stringify_meta_value($v);
                        $metaPairs[] = [
                            'source' => 'exif',
                            'key'    => $keyName,
                            'value'  => $val,
                        ];

                    }
                }
            }
        }

        $pngTexts = sv_read_png_text_chunks($file);
        foreach ($pngTexts as $k => $v) {
            $textVal    = sv_stringify_meta_value($v);
            $metaPairs[] = [
                'source' => 'pngtext',
                'key'    => (string)$k,
                'value'  => $textVal,
            ];
        }
    } elseif ($type === 'video') {
        $baseDir  = realpath(__DIR__ . '/..');
        $cfgFile  = $baseDir ? $baseDir . '/CONFIG/config.php' : null;
        $ffprobe  = null;
        if ($cfgFile && is_file($cfgFile)) {
            $cfg = require $cfgFile;
            $tools = $cfg['tools'] ?? [];
            if (!empty($tools['ffprobe'])) {
                $ffprobe = (string)$tools['ffprobe'];
            } elseif (!empty($tools['ffmpeg'])) {
                $maybe = str_replace('ffmpeg', 'ffprobe', (string)$tools['ffmpeg']);
                if (is_file($maybe)) {
                    $ffprobe = $maybe;
                }
            }
        }
        if ($ffprobe === null) {
            $ffprobe = 'ffprobe';
        }

        $cmd = escapeshellarg($ffprobe) . ' -v quiet -print_format json -show_format -show_streams ' . escapeshellarg($file);
        $json = @shell_exec($cmd);

        if (is_string($json) && trim($json) !== '') {
            $data = json_decode($json, true);
            if (is_array($data)) {
                $format = $data['format'] ?? [];
                if (isset($format['duration'])) {
                    $metaPairs[] = ['source' => 'ffmpeg', 'key' => 'format.duration', 'value' => sv_stringify_meta_value($format['duration'])];
                }
                if (isset($format['size'])) {
                    $metaPairs[] = ['source' => 'ffmpeg', 'key' => 'format.size', 'value' => sv_stringify_meta_value($format['size'])];
                }
                if (isset($format['bit_rate'])) {
                    $metaPairs[] = ['source' => 'ffmpeg', 'key' => 'format.bit_rate', 'value' => sv_stringify_meta_value($format['bit_rate'])];
                }
                $streams = is_array($data['streams'] ?? null) ? $data['streams'] : [];
                $videoStream = null;
                foreach ($streams as $idx => $stream) {
                    if (!is_array($stream)) {
                        continue;
                    }
                    $typeStream = (string)($stream['codec_type'] ?? '');
                    if ($typeStream === 'video' && $videoStream === null) {
                        $videoStream = $stream;
                    }
                    $prefix = $typeStream !== '' ? $typeStream : 'stream' . $idx;
                    foreach ($stream as $k => $v) {
                        if (in_array($k, ['tags', 'disposition', 'side_data_list'], true)) {
                            continue;
                        }
                        $metaPairs[] = [
                            'source' => 'ffmpeg',
                            'key'    => $prefix . '.' . $k,
                            'value'  => sv_stringify_meta_value($v),
                        ];
                    }
                }

                if ($videoStream !== null) {
                    $width  = $videoStream['width'] ?? null;
                    $height = $videoStream['height'] ?? null;
                    $fpsRaw = $videoStream['avg_frame_rate'] ?? ($videoStream['r_frame_rate'] ?? null);
                    if ($width !== null) {
                        $metaPairs[] = ['source' => 'ffmpeg', 'key' => 'video.width', 'value' => sv_stringify_meta_value($width)];
                    }
                    if ($height !== null) {
                        $metaPairs[] = ['source' => 'ffmpeg', 'key' => 'video.height', 'value' => sv_stringify_meta_value($height)];
                    }
                    if ($fpsRaw) {
                        $fpsVal = null;
                        if (is_string($fpsRaw) && strpos($fpsRaw, '/') !== false) {
                            [$n, $d] = array_map('floatval', explode('/', $fpsRaw, 2));
                            if ($d > 0) {
                                $fpsVal = $n / $d;
                            }
                        } elseif (is_numeric($fpsRaw)) {
                            $fpsVal = (float)$fpsRaw;
                        }
                        if ($fpsVal !== null) {
                            $metaPairs[] = ['source' => 'ffmpeg', 'key' => 'video.fps', 'value' => sv_stringify_meta_value($fpsVal)];
                        }
                    }
                    if (!empty($videoStream['duration'])) {
                        $metaPairs[] = ['source' => 'ffmpeg', 'key' => 'video.duration', 'value' => sv_stringify_meta_value($videoStream['duration'])];
                    }
                }
            }
        }

        $metaPairs[] = [
            'source' => 'ffmpeg',
            'key'    => 'filesize',
            'value'  => sv_stringify_meta_value(@filesize($file)),
        ];
    }

    $promptCandidates = sv_collect_prompt_candidates($metaPairs);

    return [
        'meta_pairs'        => $metaPairs,
        'prompt_candidates' => $promptCandidates,
    ];
}

function sv_store_extracted_metadata(
    PDO $pdo,
    int $mediaId,
    string $type,
    array $metadata,
    string $sourceLabel,
    ?callable $logger = null
): void {
    $metaPairs        = is_array($metadata['meta_pairs'] ?? null) ? $metadata['meta_pairs'] : [];
    $promptCandidates = is_array($metadata['prompt_candidates'] ?? null) ? $metadata['prompt_candidates'] : [];
    if (!$promptCandidates && isset($metadata['raw_block']) && is_string($metadata['raw_block'])) {
        $promptCandidates[] = [
            'source'    => $sourceLabel,
            'key'       => 'raw_block',
            'short_key' => 'raw_block',
            'text'      => (string)$metadata['raw_block'],
            'type'      => 'fallback',
        ];
    }

    $selectedCandidate = sv_select_prompt_candidate($promptCandidates);
    $rawBlock          = is_string($selectedCandidate['text'] ?? null) ? (string)$selectedCandidate['text'] : null;
    $normalized        = sv_empty_normalized_prompt();

    if ($rawBlock !== null) {
        $context    = is_array($selectedCandidate) ? $selectedCandidate : [];
        $normalized = sv_normalize_prompt_block($rawBlock, $context);
    }

    $createdAt = date('c');

    $insertMeta = $pdo->prepare(
        "INSERT INTO media_meta (media_id, source, meta_key, meta_value, created_at) VALUES (?, ?, ?, ?, ?)"
    );

    foreach ($metaPairs as $pair) {
        if (!is_array($pair)) {
            continue;
        }
        $metaSource = (string)($pair['source'] ?? $sourceLabel);
        $metaKey    = (string)($pair['key'] ?? '');
        $metaValue  = array_key_exists('value', $pair) ? $pair['value'] : null;
        if ($metaSource === '' || $metaKey === '') {
            continue;
        }
        $insertMeta->execute([
            $mediaId,
            $metaSource,
            $metaKey,
            $metaValue === null ? null : (string)$metaValue,
            $createdAt,
        ]);
    }

    $dimensions = sv_collect_media_meta_dimensions($metaPairs);
    $dimStmt    = $pdo->prepare("SELECT width, height, duration, fps, filesize FROM media WHERE id = ?");
    $dimStmt->execute([$mediaId]);
    $currentDims = $dimStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $updateParts = [];
    $updateVals  = [];

    if ($dimensions['width'] !== null && (empty($currentDims['width']) || (int)$currentDims['width'] === 0)) {
        $updateParts[] = 'width = ?';
        $updateVals[]  = (int)$dimensions['width'];
    }
    if ($dimensions['height'] !== null && (empty($currentDims['height']) || (int)$currentDims['height'] === 0)) {
        $updateParts[] = 'height = ?';
        $updateVals[]  = (int)$dimensions['height'];
    }
    if ($dimensions['duration'] !== null && (empty($currentDims['duration']) || (float)$currentDims['duration'] === 0.0)) {
        $updateParts[] = 'duration = ?';
        $updateVals[]  = (float)$dimensions['duration'];
    }
    if ($dimensions['fps'] !== null && (empty($currentDims['fps']) || (float)$currentDims['fps'] === 0.0)) {
        $updateParts[] = 'fps = ?';
        $updateVals[]  = (float)$dimensions['fps'];
    }
    if ($dimensions['filesize'] !== null && (empty($currentDims['filesize']) || (int)$currentDims['filesize'] === 0)) {
        $updateParts[] = 'filesize = ?';
        $updateVals[]  = (int)$dimensions['filesize'];
    }

    if ($updateParts) {
        $updateSql = 'UPDATE media SET ' . implode(', ', $updateParts) . ' WHERE id = ?';
        $updStmt   = $pdo->prepare($updateSql);
        $updateVals[] = $mediaId;
        $updStmt->execute($updateVals);
    }

    $promptStmt = $pdo->prepare('SELECT id FROM prompts WHERE media_id = ? LIMIT 1');
    $promptStmt->execute([$mediaId]);
    $existingPromptId = $promptStmt->fetchColumn();
    $hasNormalized    = sv_normalized_prompt_has_data($normalized);
    $hasPromptText    = $rawBlock !== null && trim($rawBlock) !== '';

    if ($rawBlock !== null && $normalized['source_metadata'] === null) {
        $normalized['source_metadata'] = $rawBlock;
    }
    if (!$hasNormalized && $hasPromptText) {
        $normalized['prompt']          = $normalized['prompt'] ?? trim($rawBlock);
        if ($normalized['source_metadata'] === null) {
            $normalized['source_metadata'] = json_encode([
                'raw_parameters' => $rawBlock,
                'context'        => $selectedCandidate,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $hasNormalized = sv_normalized_prompt_has_data($normalized);
    }

    if ($existingPromptId === false && ($hasNormalized || $rawBlock !== null)) {
        $insertPrompt = $pdo->prepare(
            "INSERT INTO prompts (media_id, prompt, negative_prompt, model, sampler, cfg_scale, steps, seed, width, height, scheduler, sampler_settings, loras, controlnet, source_metadata)"
            . " VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $insertPrompt->execute([
            $mediaId,
            $normalized['prompt'],
            $normalized['negative_prompt'],
            $normalized['model'],
            $normalized['sampler'],
            $normalized['cfg_scale'],
            $normalized['steps'],
            $normalized['seed'],
            $normalized['width'],
            $normalized['height'],
            $normalized['scheduler'],
            $normalized['sampler_settings'],
            $normalized['loras'],
            $normalized['controlnet'],
            $normalized['source_metadata'],
        ]);
    } elseif ($existingPromptId !== false && $hasNormalized) {
        $updatePrompt = $pdo->prepare(
            "UPDATE prompts SET"
            . " prompt = COALESCE(prompt, ?),"
            . " negative_prompt = COALESCE(negative_prompt, ?),"
            . " model = COALESCE(model, ?),"
            . " sampler = COALESCE(sampler, ?),"
            . " cfg_scale = COALESCE(cfg_scale, ?),"
            . " steps = COALESCE(steps, ?),"
            . " seed = COALESCE(seed, ?),"
            . " width = COALESCE(width, ?),"
            . " height = COALESCE(height, ?),"
            . " scheduler = COALESCE(scheduler, ?),"
            . " sampler_settings = COALESCE(sampler_settings, ?),"
            . " loras = COALESCE(loras, ?),"
            . " controlnet = COALESCE(controlnet, ?),"
            . " source_metadata = COALESCE(source_metadata, ?)"
            . " WHERE id = ?"
        );
        $updatePrompt->execute([
            $normalized['prompt'],
            $normalized['negative_prompt'],
            $normalized['model'],
            $normalized['sampler'],
            $normalized['cfg_scale'],
            $normalized['steps'],
            $normalized['seed'],
            $normalized['width'],
            $normalized['height'],
            $normalized['scheduler'],
            $normalized['sampler_settings'],
            $normalized['loras'],
            $normalized['controlnet'],
            $normalized['source_metadata'],
            $existingPromptId,
        ]);
    }

    if ($rawBlock !== null) {
        $insertMeta->execute([
            $mediaId,
            'raw_block',
            'prompt_raw',
            $rawBlock,
            $createdAt,
        ]);
    }
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
    if ($hash === null) {
        if ($logger) {
            $logger('Hash konnte nicht berechnet werden: ' . $file);
        }
        return false;
    }
    $size = @filesize($file) ?: null;
    $ctime = @filemtime($file) ?: time();
    $createdAt = date('c', $ctime);

    $originalPath = str_replace('\\', '/', $file);
    $originalName = basename($file);

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

    $destPath = sv_resolve_library_path($hash, $ext, $destBase);
    $destPath = str_replace('\\', '/', $destPath);

    $destExists = is_file($destPath);
    if ($destExists) {
        $existingHash = @hash_file('md5', $destPath);
        if ($existingHash !== $hash) {
            if ($logger) {
                $logger('Hash-Kollision bei Zielpfad: ' . $destPath);
            }
            return false;
        }
    } else {
        if (!sv_move_file($file, $destPath)) {
            if ($logger) {
                $logger("Verschieben fehlgeschlagen: {$file} -> {$destPath}");
            }
            return false;
        }
    }

    $destPathDb = $destPath;

    $existingRow = null;
    $existingStmt = $pdo->prepare("SELECT id, path FROM media WHERE hash = ?");
    $existingStmt->execute([$hash]);
    $existingRow = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    try {
        $pdo->beginTransaction();

        if ($existingRow) {
            $existingId   = (int)$existingRow['id'];
            $existingPath = (string)$existingRow['path'];

            if ($existingPath !== $destPathDb) {
                $upd = $pdo->prepare('UPDATE media SET path = ? WHERE id = ?');
                $upd->execute([$destPathDb, $existingId]);
            }

            $logStmt = $pdo->prepare(
                "INSERT INTO import_log (path, status, message, created_at) VALUES (?, ?, ?, ?)"
            );
            $logStmt->execute([
                $destPathDb,
                'skipped_duplicate',
                'Hash bereits vorhanden',
                date('c'),
            ]);

            $metaCheck = $pdo->prepare('SELECT 1 FROM media_meta WHERE media_id = ? AND source = ? AND meta_key = ? LIMIT 1');
            $metaInsert = $pdo->prepare(
                "INSERT INTO media_meta (media_id, source, meta_key, meta_value, created_at) VALUES (?, ?, ?, ?, ?)"
            );

            $existingExt = strtolower(pathinfo($existingPath, PATHINFO_EXTENSION));
            $metaKeyPairs = [
                ['import', 'original_path', $originalPath],
                ['import', 'original_name', $originalName],
            ];

            if ($existingExt !== '' && $existingExt !== $ext) {
                $metaKeyPairs[] = [
                    'import',
                    'ext_mismatch',
                    json_encode(
                        [
                            'existing' => $existingExt,
                            'incoming' => $ext,
                        ],
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    ),
                ];
            }

            foreach ($metaKeyPairs as [$src, $key, $value]) {
                $metaCheck->execute([$existingId, $src, $key]);
                if ($metaCheck->fetchColumn() !== false) {
                    continue;
                }
                $metaInsert->execute([
                    $existingId,
                    $src,
                    $key,
                    $value,
                    $createdAt,
                ]);
            }

            $pdo->commit();
            return true;
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

        $metaInsert = $pdo->prepare(
            "INSERT INTO media_meta (media_id, source, meta_key, meta_value, created_at) VALUES (?, ?, ?, ?, ?)"
        );
        $metaInsert->execute([$mediaId, 'import', 'original_path', $originalPath, $createdAt]);
        $metaInsert->execute([$mediaId, 'import', 'original_name', $originalName, $createdAt]);

        try {
            $metadata = sv_extract_metadata($destPathDb, $type, 'import', $logger);
            sv_store_extracted_metadata($pdo, $mediaId, $type, $metadata, 'import', $logger);
        } catch (Throwable $e) {
            if ($logger) {
                $logger('Metadaten konnten nicht gespeichert werden: ' . $e->getMessage());
            }
        }

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

    $needMeta   = false;
    $metaCheck  = $pdo->prepare('SELECT 1 FROM media_meta WHERE media_id = ? LIMIT 1');
    $metaCheck->execute([$id]);
    if ($metaCheck->fetchColumn() === false) {
        $needMeta = true;
    }
    $promptCheck = $pdo->prepare('SELECT 1 FROM prompts WHERE media_id = ? LIMIT 1');
    $promptCheck->execute([$id]);
    if ($promptCheck->fetchColumn() === false) {
        $needMeta = true;
    }

    if ($needMeta) {
        try {
            $metadata = sv_extract_metadata($newPath, $type, 'rescan', $logger);
            sv_store_extracted_metadata($pdo, $id, $type, $metadata, 'rescan', $logger);
        } catch (Throwable $e) {
            if ($logger) {
                $logger("Media ID {$id}: Metadaten konnten nicht gespeichert werden: " . $e->getMessage());
            }
        }
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

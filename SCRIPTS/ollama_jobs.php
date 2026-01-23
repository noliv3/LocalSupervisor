<?php
declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/ollama_client.php';
require_once __DIR__ . '/ollama_prompts.php';

const SV_JOB_TYPE_OLLAMA_CAPTION     = 'ollama_caption';
const SV_JOB_TYPE_OLLAMA_TITLE       = 'ollama_title';
const SV_JOB_TYPE_OLLAMA_PROMPT_EVAL = 'ollama_prompt_eval';
const SV_JOB_TYPE_OLLAMA_TAGS_NORMALIZE = 'ollama_tags_normalize';
const SV_JOB_TYPE_OLLAMA_QUALITY     = 'ollama_quality';
const SV_JOB_TYPE_OLLAMA_PROMPT_RECON = 'ollama_prompt_recon';
const SV_OLLAMA_STAGE_VERSION        = 'stage4_v1';

const SV_OLLAMA_QUALITY_FLAGS = [
    'blur',
    'out_of_focus',
    'motion_blur',
    'lowres',
    'jpeg_artifacts',
    'noise',
    'overexposed',
    'underexposed',
    'watermark',
    'text_overlay',
    'signature',
    'cropped_subject',
    'distorted',
    'glitch',
];

const SV_OLLAMA_DOMAIN_TYPES = [
    'anime',
    'photo',
    'illustration',
    '3d_render',
    'screenshot',
    'other',
];

function sv_ollama_job_types(): array
{
    return [
        SV_JOB_TYPE_OLLAMA_CAPTION,
        SV_JOB_TYPE_OLLAMA_TITLE,
        SV_JOB_TYPE_OLLAMA_PROMPT_EVAL,
        SV_JOB_TYPE_OLLAMA_TAGS_NORMALIZE,
        SV_JOB_TYPE_OLLAMA_QUALITY,
        SV_JOB_TYPE_OLLAMA_PROMPT_RECON,
    ];
}

function sv_ollama_job_type_for_mode(string $mode): string
{
    $mode = trim($mode);
    if ($mode === 'caption') {
        return SV_JOB_TYPE_OLLAMA_CAPTION;
    }
    if ($mode === 'title') {
        return SV_JOB_TYPE_OLLAMA_TITLE;
    }
    if ($mode === 'prompt_eval') {
        return SV_JOB_TYPE_OLLAMA_PROMPT_EVAL;
    }
    if ($mode === 'tags_normalize') {
        return SV_JOB_TYPE_OLLAMA_TAGS_NORMALIZE;
    }
    if ($mode === 'quality') {
        return SV_JOB_TYPE_OLLAMA_QUALITY;
    }
    if ($mode === 'prompt_recon') {
        return SV_JOB_TYPE_OLLAMA_PROMPT_RECON;
    }

    throw new InvalidArgumentException('Unbekannter Ollama-Modus: ' . $mode);
}

function sv_ollama_mode_for_job_type(string $jobType): string
{
    if ($jobType === SV_JOB_TYPE_OLLAMA_CAPTION) {
        return 'caption';
    }
    if ($jobType === SV_JOB_TYPE_OLLAMA_TITLE) {
        return 'title';
    }
    if ($jobType === SV_JOB_TYPE_OLLAMA_PROMPT_EVAL) {
        return 'prompt_eval';
    }
    if ($jobType === SV_JOB_TYPE_OLLAMA_TAGS_NORMALIZE) {
        return 'tags_normalize';
    }
    if ($jobType === SV_JOB_TYPE_OLLAMA_QUALITY) {
        return 'quality';
    }
    if ($jobType === SV_JOB_TYPE_OLLAMA_PROMPT_RECON) {
        return 'prompt_recon';
    }

    throw new InvalidArgumentException('Unbekannter Ollama-Jobtyp: ' . $jobType);
}

function sv_ollama_payload_column(PDO $pdo): string
{
    return sv_jobs_supports_payload_json($pdo) ? 'payload_json' : 'forge_request_json';
}

function sv_ollama_decode_job_payload(array $jobRow): array
{
    $payloadJson = null;
    if (array_key_exists('payload_json', $jobRow) && is_string($jobRow['payload_json'])) {
        $payloadJson = $jobRow['payload_json'];
    }
    if ($payloadJson === null && isset($jobRow['forge_request_json']) && is_string($jobRow['forge_request_json'])) {
        $payloadJson = $jobRow['forge_request_json'];
    }

    $payload = is_string($payloadJson) ? json_decode($payloadJson, true) : null;
    return is_array($payload) ? $payload : [];
}

function sv_ollama_normalize_payload(int $mediaId, string $mode, array $payload): array
{
    $payload['media_id'] = $mediaId;
    $payload['mode'] = $mode;

    if (isset($payload['prompt']) && !is_string($payload['prompt'])) {
        unset($payload['prompt']);
    }
    if (isset($payload['model']) && !is_string($payload['model'])) {
        unset($payload['model']);
    }
    if (isset($payload['options']) && !is_array($payload['options'])) {
        unset($payload['options']);
    }
    if (isset($payload['image_source']) && !is_array($payload['image_source']) && !is_string($payload['image_source'])) {
        unset($payload['image_source']);
    }

    return $payload;
}

function sv_ollama_log_jsonl(array $config, string $filename, array $payload): void
{
    sv_write_jsonl_log($config, $filename, $payload);
}

function sv_enqueue_ollama_job(PDO $pdo, array $config, int $mediaId, string $mode, array $payload, callable $logger): array
{
    if ($mediaId <= 0) {
        throw new InvalidArgumentException('Ungültige Media-ID für Ollama-Job.');
    }

    $jobType = sv_ollama_job_type_for_mode($mode);

    $existing = $pdo->prepare(
        'SELECT id FROM jobs WHERE media_id = :media_id AND type = :type AND status IN ("pending","running","queued") ORDER BY id DESC LIMIT 1'
    );
    $existing->execute([
        ':media_id' => $mediaId,
        ':type' => $jobType,
    ]);
    $presentId = $existing->fetchColumn();
    if ($presentId) {
        $logger('Ollama-Job existiert bereits (#' . (int)$presentId . ', queued/pending/running).');
        return [
            'job_id' => (int)$presentId,
            'deduped' => true,
        ];
    }

    sv_enforce_job_queue_capacity($pdo, $config, $jobType, $mediaId);

    $now = date('c');
    $payload = sv_ollama_normalize_payload($mediaId, $mode, $payload);
    $payload['created_at'] = $now;

    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payloadJson === false) {
        $payloadJson = '{}';
    }

    $column = sv_ollama_payload_column($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO jobs (media_id, prompt_id, type, status, created_at, updated_at, ' . $column . ') '
        . 'VALUES (:media_id, NULL, :type, :status, :created_at, :updated_at, :payload)'
    );
    $stmt->execute([
        ':media_id' => $mediaId,
        ':type' => $jobType,
        ':status' => 'queued',
        ':created_at' => $now,
        ':updated_at' => $now,
        ':payload' => $payloadJson,
    ]);

    $jobId = (int)$pdo->lastInsertId();
    $logger('Ollama-Job angelegt: ID=' . $jobId . ' (Media ' . $mediaId . ', ' . $mode . ')');

    sv_audit_log($pdo, 'ollama_enqueue', 'jobs', $jobId, [
        'media_id' => $mediaId,
        'mode' => $mode,
        'job_type' => $jobType,
    ]);

    sv_ollama_log_jsonl($config, 'ollama_jobs.jsonl', [
        'ts' => $now,
        'event' => 'enqueue',
        'job_id' => $jobId,
        'media_id' => $mediaId,
        'job_type' => $jobType,
        'mode' => $mode,
    ]);

    return [
        'job_id' => $jobId,
        'deduped' => false,
    ];
}

function sv_enqueue_ollama_caption_job(PDO $pdo, array $config, int $mediaId, array $payload, callable $logger): array
{
    return sv_enqueue_ollama_job($pdo, $config, $mediaId, 'caption', $payload, $logger);
}

function sv_enqueue_ollama_title_job(PDO $pdo, array $config, int $mediaId, array $payload, callable $logger): array
{
    return sv_enqueue_ollama_job($pdo, $config, $mediaId, 'title', $payload, $logger);
}

function sv_enqueue_ollama_prompt_eval_job(PDO $pdo, array $config, int $mediaId, array $payload, callable $logger): array
{
    return sv_enqueue_ollama_job($pdo, $config, $mediaId, 'prompt_eval', $payload, $logger);
}

function sv_ollama_fetch_prompt(PDO $pdo, array $config, int $mediaId): array
{
    $ollamaCfg = sv_ollama_config($config);
    $fallback = $ollamaCfg['prompt_eval_fallback'] ?? 'tags';
    $separator = $ollamaCfg['prompt_eval_fallback_separator'] ?? ', ';

    $stmt = $pdo->prepare('SELECT prompt, source_metadata FROM prompts WHERE media_id = :media_id ORDER BY id DESC LIMIT 1');
    $stmt->execute([':media_id' => $mediaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (is_array($row) && isset($row['prompt']) && is_string($row['prompt']) && trim($row['prompt']) !== '') {
        return [
            'prompt' => trim($row['prompt']),
            'source' => 'prompt',
        ];
    }

    if ($fallback === 'metadata' && is_array($row) && isset($row['source_metadata']) && is_string($row['source_metadata'])) {
        $metadata = trim($row['source_metadata']);
        if ($metadata !== '') {
            return [
                'prompt' => $metadata,
                'source' => 'metadata',
            ];
        }
    }

    if ($fallback === 'tags' || $fallback === 'metadata') {
        $tagsStmt = $pdo->prepare(
            'SELECT t.name FROM tags t INNER JOIN media_tags mt ON mt.tag_id = t.id WHERE mt.media_id = :media_id ORDER BY t.name ASC'
        );
        $tagsStmt->execute([':media_id' => $mediaId]);
        $tags = $tagsStmt->fetchAll(PDO::FETCH_COLUMN, 0);
        $tags = array_values(array_filter(array_map('strval', $tags), static fn ($v) => trim($v) !== ''));
        if ($tags !== []) {
            return [
                'prompt' => implode($separator, $tags),
                'source' => 'tags',
            ];
        }
    }

    return [
        'prompt' => null,
        'source' => 'none',
    ];
}

function sv_ollama_fetch_prompt_context(PDO $pdo, int $mediaId): ?string
{
    $stmt = $pdo->prepare('SELECT prompt FROM prompts WHERE media_id = :media_id ORDER BY id DESC LIMIT 1');
    $stmt->execute([':media_id' => $mediaId]);
    $prompt = $stmt->fetchColumn();
    if (is_string($prompt) && trim($prompt) !== '') {
        return trim($prompt);
    }

    return null;
}

function sv_ollama_decode_json_list(?string $value): ?array
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    $decoded = json_decode($value, true);
    if (!is_array($decoded)) {
        return null;
    }

    $list = array_values(array_filter(array_map('strval', $decoded), static fn ($v) => trim($v) !== ''));
    return $list === [] ? null : $list;
}

function sv_ollama_build_prompt_recon_payload(PDO $pdo, int $mediaId): array
{
    $caption = sv_get_media_meta_value($pdo, $mediaId, 'ollama.caption');
    $title = sv_get_media_meta_value($pdo, $mediaId, 'ollama.title');
    $tagsRaw = sv_get_media_meta_value($pdo, $mediaId, 'ollama.tags_normalized');
    $domainType = sv_get_media_meta_value($pdo, $mediaId, 'ollama.domain.type');
    $qualityFlagsRaw = sv_get_media_meta_value($pdo, $mediaId, 'ollama.quality.flags');
    $originalPrompt = sv_ollama_fetch_prompt_context($pdo, $mediaId);

    return [
        'caption' => $caption,
        'title' => $title,
        'tags_normalized' => sv_ollama_decode_json_list($tagsRaw),
        'domain_type' => $domainType,
        'quality_flags' => sv_ollama_decode_json_list($qualityFlagsRaw),
        'original_prompt' => $originalPrompt,
    ];
}

function sv_ollama_fetch_media_tags(PDO $pdo, int $mediaId): array
{
    $tagsStmt = $pdo->prepare(
        'SELECT t.name FROM tags t INNER JOIN media_tags mt ON mt.tag_id = t.id WHERE mt.media_id = :media_id ORDER BY t.name ASC'
    );
    $tagsStmt->execute([':media_id' => $mediaId]);
    $tags = $tagsStmt->fetchAll(PDO::FETCH_COLUMN, 0);
    return array_values(array_filter(array_map('strval', $tags), static fn ($v) => trim($v) !== ''));
}

function sv_ollama_fetch_pending_jobs(PDO $pdo, array $jobTypes, int $limit, ?int $mediaId = null): array
{
    $limit = $limit > 0 ? $limit : 5;
    $placeholders = implode(',', array_fill(0, count($jobTypes), '?'));
    $params = $jobTypes;
    $sql = 'SELECT id, media_id, type, status, created_at, updated_at, forge_request_json';
    if (sv_jobs_supports_payload_json($pdo)) {
        $sql .= ', payload_json';
    }
    $sql .= ' FROM jobs WHERE type IN (' . $placeholders . ') AND status IN ("pending","queued")';
    if ($mediaId !== null && $mediaId > 0) {
        $sql .= ' AND media_id = ?';
        $params[] = $mediaId;
    }
    $sql .= ' ORDER BY id ASC LIMIT ' . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function sv_ollama_normalize_text_value($value): ?string
{
    if (is_string($value)) {
        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : null;
    }
    if (is_numeric($value)) {
        return (string)$value;
    }

    return null;
}

function sv_ollama_normalize_list_value($value): ?string
{
    if (is_array($value)) {
        $list = array_values(array_filter(array_map('strval', $value), static fn ($v) => trim($v) !== ''));
        if ($list === []) {
            return null;
        }
        $json = json_encode($list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? null : $json;
    }
    if (is_string($value)) {
        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : null;
    }

    return null;
}

function sv_ollama_normalize_tag_list($value): ?array
{
    if (!is_array($value)) {
        return null;
    }

    $list = array_values(array_filter(array_map('strval', $value), static fn ($v) => trim($v) !== ''));
    return $list === [] ? null : $list;
}

function sv_ollama_normalize_tag_map($value): ?array
{
    if (!is_array($value)) {
        return null;
    }

    $normalized = [];
    foreach ($value as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $raw = sv_ollama_normalize_text_value($entry['raw'] ?? null);
        $normalizedTag = sv_ollama_normalize_text_value($entry['normalized'] ?? null);
        if ($raw === null || $normalizedTag === null) {
            continue;
        }
        $confidence = null;
        if (isset($entry['confidence']) && is_numeric($entry['confidence'])) {
            $confidence = (float)$entry['confidence'];
        }
        $type = sv_ollama_normalize_text_value($entry['type'] ?? null);

        $normalized[] = [
            'raw' => $raw,
            'normalized' => $normalizedTag,
            'confidence' => $confidence,
            'type' => $type,
        ];
    }

    return $normalized === [] ? null : $normalized;
}

function sv_ollama_normalize_quality_flags($value): ?array
{
    if (!is_array($value)) {
        return null;
    }

    $allowed = array_fill_keys(SV_OLLAMA_QUALITY_FLAGS, true);
    $normalized = [];
    foreach ($value as $flag) {
        if (!is_string($flag)) {
            continue;
        }
        $flag = trim($flag);
        if ($flag === '' || !isset($allowed[$flag])) {
            continue;
        }
        $normalized[$flag] = true;
    }

    return array_keys($normalized);
}

function sv_ollama_encode_json($value): ?string
{
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $json === false ? null : $json;
}

function sv_ollama_normalize_score($value): ?int
{
    if (!is_numeric($value)) {
        return null;
    }

    $score = (float)$value;
    if ($score <= 1.0) {
        $score = $score * 100.0;
    }
    $score = (int)round($score);
    if ($score < 0) {
        $score = 0;
    }
    if ($score > 100) {
        $score = 100;
    }

    return $score;
}

function sv_ollama_insert_result(PDO $pdo, array $data): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO ollama_results (media_id, mode, model, title, caption, score, contradictions, missing, rationale, raw_json, raw_text, parse_error, created_at, meta) '
        . 'VALUES (:media_id, :mode, :model, :title, :caption, :score, :contradictions, :missing, :rationale, :raw_json, :raw_text, :parse_error, :created_at, :meta)'
    );
    $stmt->execute([
        ':media_id' => (int)$data['media_id'],
        ':mode' => (string)$data['mode'],
        ':model' => (string)$data['model'],
        ':title' => $data['title'],
        ':caption' => $data['caption'],
        ':score' => $data['score'],
        ':contradictions' => $data['contradictions'],
        ':missing' => $data['missing'],
        ':rationale' => $data['rationale'],
        ':raw_json' => $data['raw_json'],
        ':raw_text' => $data['raw_text'],
        ':parse_error' => $data['parse_error'] ? 1 : 0,
        ':created_at' => $data['created_at'],
        ':meta' => $data['meta'],
    ]);

    return (int)$pdo->lastInsertId();
}

function sv_ollama_build_meta_snapshot(int $resultId, string $model, bool $parseError, ?string $contradictions, ?string $missing, ?string $rationale): string
{
    $meta = [
        'result_id' => $resultId,
        'model' => $model,
        'parse_error' => $parseError,
    ];
    if ($contradictions !== null) {
        $meta['contradictions'] = $contradictions;
    }
    if ($missing !== null) {
        $meta['missing'] = $missing;
    }
    if ($rationale !== null) {
        $meta['rationale'] = $rationale;
    }

    $metaJson = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $metaJson === false ? '{}' : $metaJson;
}

function sv_ollama_build_tags_normalize_meta(int $resultId, string $model, bool $parseError): string
{
    $meta = [
        'result_id' => $resultId,
        'model' => $model,
        'parse_error' => $parseError,
    ];

    $metaJson = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $metaJson === false ? '{}' : $metaJson;
}

function sv_ollama_persist_media_meta(PDO $pdo, int $mediaId, string $mode, array $values, array $options = []): void
{
    $source = 'ollama';
    $lastRunAt = $values['last_run_at'] ?? date('c');
    $setCommonMeta = !array_key_exists('set_common_meta', $options) || (bool)$options['set_common_meta'] === true;

    if ($mode === 'caption' && isset($values['caption']) && $values['caption'] !== null) {
        sv_set_media_meta_value($pdo, $mediaId, 'ollama.caption', $values['caption'], $source);
    }
    if ($mode === 'title' && isset($values['title']) && $values['title'] !== null) {
        sv_set_media_meta_value($pdo, $mediaId, 'ollama.title', $values['title'], $source);
    }
    if ($mode === 'prompt_eval' && isset($values['score']) && $values['score'] !== null) {
        sv_set_media_meta_value($pdo, $mediaId, 'ollama.prompt_eval.score', $values['score'], $source);
    }
    if ($mode === 'tags_normalize') {
        if (isset($values['tags_raw']) && $values['tags_raw'] !== null) {
            sv_set_media_meta_value($pdo, $mediaId, 'ollama.tags_raw', $values['tags_raw'], $source);
        }
        if (isset($values['tags_normalized']) && $values['tags_normalized'] !== null) {
            sv_set_media_meta_value($pdo, $mediaId, 'ollama.tags_normalized', $values['tags_normalized'], $source);
        }
        if (isset($values['tags_map']) && $values['tags_map'] !== null) {
            sv_set_media_meta_value($pdo, $mediaId, 'ollama.tags_map', $values['tags_map'], $source);
        }
    }
    if ($mode === 'quality') {
        if (isset($values['quality_score']) && $values['quality_score'] !== null) {
            sv_set_media_meta_value($pdo, $mediaId, 'ollama.quality.score', $values['quality_score'], $source);
        }
        if (isset($values['quality_flags']) && $values['quality_flags'] !== null) {
            sv_set_media_meta_value($pdo, $mediaId, 'ollama.quality.flags', $values['quality_flags'], $source);
        }
        if (isset($values['domain_type']) && $values['domain_type'] !== null) {
            sv_set_media_meta_value($pdo, $mediaId, 'ollama.domain.type', $values['domain_type'], $source);
        }
        if (isset($values['domain_confidence']) && $values['domain_confidence'] !== null) {
            sv_set_media_meta_value($pdo, $mediaId, 'ollama.domain.confidence', $values['domain_confidence'], $source);
        }
    }
    if ($mode === 'prompt_recon') {
        if (isset($values['prompt']) && $values['prompt'] !== null) {
            sv_set_media_meta_value($pdo, $mediaId, 'ollama.prompt_recon.prompt', $values['prompt'], $source);
        }
        if (isset($values['negative_prompt']) && $values['negative_prompt'] !== null) {
            sv_set_media_meta_value($pdo, $mediaId, 'ollama.prompt_recon.negative', $values['negative_prompt'], $source);
        }
        if (isset($values['confidence']) && $values['confidence'] !== null) {
            sv_set_media_meta_value($pdo, $mediaId, 'ollama.prompt_recon.confidence', $values['confidence'], $source);
        }
        if (isset($values['style_tokens']) && $values['style_tokens'] !== null) {
            sv_set_media_meta_value($pdo, $mediaId, 'ollama.prompt_recon.style_tokens', $values['style_tokens'], $source);
        }
        if (isset($values['subject_tokens']) && $values['subject_tokens'] !== null) {
            sv_set_media_meta_value($pdo, $mediaId, 'ollama.prompt_recon.subject_tokens', $values['subject_tokens'], $source);
        }
    }

    if ($setCommonMeta) {
        sv_set_media_meta_value($pdo, $mediaId, 'ollama.last_run_at', $lastRunAt, $source);
        sv_set_media_meta_value($pdo, $mediaId, 'ollama.stage_version', SV_OLLAMA_STAGE_VERSION, $source);
    }

    if (isset($values['meta']) && is_string($values['meta']) && trim($values['meta']) !== '') {
        sv_set_media_meta_value($pdo, $mediaId, 'ollama.' . $mode . '.meta', $values['meta'], $source);
    }
}

function sv_ollama_load_image_source(PDO $pdo, array $config, int $mediaId, array $payload): array
{
    $source = $payload['image_source'] ?? null;
    $mediaPath = null;

    if (is_array($source)) {
        if (isset($source['base64']) && is_string($source['base64']) && trim($source['base64']) !== '') {
            return [
                'base64' => trim($source['base64']),
                'source' => 'base64',
            ];
        }
        if (isset($source['path']) && is_string($source['path']) && trim($source['path']) !== '') {
            $mediaPath = trim($source['path']);
        } elseif (isset($source['url']) && is_string($source['url']) && trim($source['url']) !== '') {
            $mediaPath = trim($source['url']);
        }
    } elseif (is_string($source) && trim($source) !== '') {
        $mediaPath = trim($source);
    }

    if ($mediaPath === null) {
        $stmt = $pdo->prepare('SELECT path, type, filesize FROM media WHERE id = :id');
        $stmt->execute([':id' => $mediaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Media-Eintrag nicht gefunden.');
        }
        $type = (string)($row['type'] ?? '');
        if ($type !== 'image') {
            throw new RuntimeException('Nur Bildmedien werden unterstützt.');
        }
        $mediaPath = (string)($row['path'] ?? '');
        if ($mediaPath === '') {
            throw new RuntimeException('Media-Pfad fehlt.');
        }
        $source = [
            'path' => $mediaPath,
            'filesize' => isset($row['filesize']) ? (int)$row['filesize'] : null,
        ];
    }

    $ollamaCfg = sv_ollama_config($config);
    $timeoutMs = (int)$ollamaCfg['timeout_ms'];
    $maxBytes = (int)$ollamaCfg['max_image_bytes'];

    if (preg_match('~^https?://~i', $mediaPath)) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => max(1, (int)ceil($timeoutMs / 1000)),
                'follow_location' => 1,
                'header' => "User-Agent: LocalSupervisor-Ollama\r\n",
            ],
        ]);
        $raw = @file_get_contents($mediaPath, false, $context);
        if ($raw === false) {
            throw new RuntimeException('Bild konnte nicht von URL geladen werden.');
        }
        if ($maxBytes > 0 && strlen($raw) > $maxBytes) {
            throw new RuntimeException('Bildgröße zu groß (' . strlen($raw) . ' > ' . $maxBytes . ' Bytes).');
        }
        return [
            'base64' => base64_encode($raw),
            'source' => 'url',
        ];
    }

    $pathsCfg = $config['paths'] ?? [];
    sv_assert_media_path_allowed($mediaPath, $pathsCfg, 'ollama_job');

    if (!is_file($mediaPath)) {
        throw new RuntimeException('Media-Datei fehlt: ' . sv_safe_path_label($mediaPath));
    }

    $fileSize = null;
    if (is_array($source) && array_key_exists('filesize', $source)) {
        $fileSize = $source['filesize'] !== null ? (int)$source['filesize'] : null;
    }
    if ($fileSize === null) {
        $fileSize = @filesize($mediaPath);
        $fileSize = $fileSize === false ? null : (int)$fileSize;
    }

    if ($maxBytes > 0 && $fileSize !== null && $fileSize > $maxBytes) {
        throw new RuntimeException('Bildgröße zu groß (' . $fileSize . ' > ' . $maxBytes . ' Bytes).');
    }

    $raw = @file_get_contents($mediaPath);
    if ($raw === false) {
        throw new RuntimeException('Bilddatei konnte nicht gelesen werden.');
    }

    if ($maxBytes > 0 && strlen($raw) > $maxBytes) {
        throw new RuntimeException('Bildgröße zu groß (' . strlen($raw) . ' > ' . $maxBytes . ' Bytes).');
    }

    return [
        'base64' => base64_encode($raw),
        'source' => 'path',
    ];
}

function sv_process_ollama_job_batch(PDO $pdo, array $config, ?int $limit, callable $logger, ?int $mediaId = null): array
{
    $jobTypes = sv_ollama_job_types();
    sv_mark_stuck_jobs($pdo, $jobTypes, SV_JOB_STUCK_MINUTES, $logger);

    $ollamaCfg = sv_ollama_config($config);
    $limit = $limit !== null ? (int)$limit : (int)$ollamaCfg['worker']['batch_size'];
    if ($limit <= 0) {
        $limit = (int)$ollamaCfg['worker']['batch_size'];
    }

    $rows = sv_ollama_fetch_pending_jobs($pdo, $jobTypes, $limit, $mediaId);

    $summary = [
        'total' => 0,
        'done' => 0,
        'error' => 0,
        'skipped' => 0,
        'retried' => 0,
    ];

    foreach ($rows as $row) {
        $summary['total']++;
        $result = sv_process_ollama_job($pdo, $config, $row, $logger);
        if (($result['status'] ?? null) === 'done') {
            $summary['done']++;
        } elseif (($result['status'] ?? null) === 'error') {
            $summary['error']++;
        } elseif (($result['status'] ?? null) === 'retry') {
            $summary['retried']++;
        } elseif (($result['status'] ?? null) === 'skipped') {
            $summary['skipped']++;
        }
    }

    return $summary;
}

function sv_process_ollama_job(PDO $pdo, array $config, array $jobRow, callable $logger): array
{
    $jobId = (int)($jobRow['id'] ?? 0);
    $mediaId = (int)($jobRow['media_id'] ?? 0);
    $jobType = (string)($jobRow['type'] ?? '');

    if ($jobId <= 0 || $mediaId <= 0 || $jobType === '') {
        if ($jobId > 0) {
            sv_update_job_status($pdo, $jobId, 'error', null, 'Ungültiger Ollama-Job.');
        }
        return [
            'job_id' => $jobId,
            'media_id' => $mediaId,
            'job_type' => $jobType,
            'status' => 'error',
            'error' => 'Ungültiger Ollama-Job.',
        ];
    }

    $payload = sv_ollama_decode_job_payload($jobRow);
    $mode = isset($payload['mode']) && is_string($payload['mode']) && trim($payload['mode']) !== ''
        ? trim($payload['mode'])
        : sv_ollama_mode_for_job_type($jobType);
    $payload['mode'] = $mode;

    $payload['last_started_at'] = date('c');
    sv_update_job_checkpoint_payload($pdo, $jobId, $payload);

    sv_update_job_status($pdo, $jobId, 'running', json_encode([
        'job_type' => $jobType,
        'media_id' => $mediaId,
        'mode' => $mode,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    sv_audit_log($pdo, 'ollama_start', 'jobs', $jobId, [
        'media_id' => $mediaId,
        'job_type' => $jobType,
        'mode' => $mode,
    ]);

    sv_ollama_log_jsonl($config, 'ollama_jobs.jsonl', [
        'ts' => date('c'),
        'event' => 'start',
        'job_id' => $jobId,
        'media_id' => $mediaId,
        'job_type' => $jobType,
        'mode' => $mode,
    ]);

    $ollamaCfg = sv_ollama_config($config);
    $maxRetries = (int)$ollamaCfg['worker']['max_retries'];
    $attempts = isset($payload['attempts']) ? (int)$payload['attempts'] : 0;
    $imageLoadError = null;

    try {
        if (!$ollamaCfg['enabled']) {
            throw new RuntimeException('Ollama ist deaktiviert.');
        }

        $imageData = null;
        $promptPayload = $payload;
        $rawTags = null;
        $contextText = '';
        if ($mode === 'tags_normalize') {
            $rawTags = sv_ollama_fetch_media_tags($pdo, $mediaId);
            if ($rawTags === []) {
                throw new RuntimeException('Keine Roh-Tags für Normalisierung gefunden.');
            }

            $contextParts = [];
            $caption = sv_get_media_meta_value($pdo, $mediaId, 'ollama.caption');
            if (is_string($caption) && trim($caption) !== '') {
                $contextParts[] = 'Caption: ' . trim($caption);
            }
            $title = sv_get_media_meta_value($pdo, $mediaId, 'ollama.title');
            if (is_string($title) && trim($title) !== '') {
                $contextParts[] = 'Title: ' . trim($title);
            }
            $promptContext = sv_ollama_fetch_prompt_context($pdo, $mediaId);
            if (is_string($promptContext) && trim($promptContext) !== '') {
                $contextParts[] = 'Prompt: ' . trim($promptContext);
            }

            if ($contextParts !== []) {
                $contextText = implode("\n", $contextParts);
            }

            $promptPayload = [
                'tags' => $rawTags,
                'context' => $contextText,
            ];
        } elseif ($mode === 'prompt_recon') {
            $promptPayload = sv_ollama_build_prompt_recon_payload($pdo, $mediaId);
            $hasCaption = isset($promptPayload['caption']) && is_string($promptPayload['caption']) && trim($promptPayload['caption']) !== '';
            $hasTags = isset($promptPayload['tags_normalized']) && is_array($promptPayload['tags_normalized']) && $promptPayload['tags_normalized'] !== [];
            if (!$hasCaption && !$hasTags) {
                throw new RuntimeException('Prompt-Rekonstruktion benötigt mindestens Caption oder Tags.');
            }
        } else {
            try {
                $imageData = sv_ollama_load_image_source($pdo, $config, $mediaId, $payload);
            } catch (Throwable $e) {
                $imageLoadError = $e;
                throw $e;
            }
            $imageBase64 = $imageData['base64'] ?? null;
            if (!is_string($imageBase64) || $imageBase64 === '') {
                throw new RuntimeException('Bilddaten fehlen.');
            }
        }

        $promptData = sv_ollama_build_prompt($mode, $config, $promptPayload);
        $prompt = $promptData['prompt'];

        $options = isset($payload['options']) && is_array($payload['options']) ? $payload['options'] : [];
        if (isset($payload['model']) && is_string($payload['model']) && trim($payload['model']) !== '') {
            $options['model'] = trim($payload['model']);
        }
        if (!isset($options['model']) || trim((string)$options['model']) === '') {
            $options['model'] = ($mode === 'tags_normalize' || $mode === 'prompt_recon')
                ? ($ollamaCfg['model']['text'] ?? $ollamaCfg['model_default'])
                : ($ollamaCfg['model']['vision'] ?? $ollamaCfg['model_default']);
        }
        if (!isset($options['timeout_ms'])) {
            $options['timeout_ms'] = $ollamaCfg['timeout_ms'];
        }
        if (!array_key_exists('deterministic', $options)) {
            $options['deterministic'] = $ollamaCfg['deterministic']['enabled'] ?? true;
        }

        if ($mode === 'tags_normalize' || $mode === 'prompt_recon') {
            $response = sv_ollama_generate_text($config, $prompt, $options);
        } else {
            $response = sv_ollama_analyze_image($config, $imageBase64, $prompt, $options);
        }
        if (empty($response['ok'])) {
            $error = isset($response['error']) ? (string)$response['error'] : 'Ollama-Request fehlgeschlagen.';
            throw new RuntimeException($error);
        }

        $responseJson = is_array($response['response_json'] ?? null) ? $response['response_json'] : null;
        $responseText = isset($response['response_text']) && is_string($response['response_text']) ? $response['response_text'] : null;
        $parseError = !empty($response['parse_error']) || $responseJson === null;

        $title = null;
        $caption = null;
        $score = null;
        $contradictions = null;
        $missing = null;
        $rationale = null;
        $tagsNormalized = null;
        $tagsMap = null;
        $qualityScore = null;
        $qualityFlags = null;
        $domainType = null;
        $domainConfidence = null;
        $promptReconPrompt = null;
        $promptReconNegative = null;
        $promptReconConfidence = null;
        $promptReconStyleTokens = null;
        $promptReconSubjectTokens = null;
        if (is_array($responseJson)) {
            $title = sv_ollama_normalize_text_value($responseJson['title'] ?? null);
            $caption = sv_ollama_normalize_text_value($responseJson['caption'] ?? ($responseJson['description'] ?? null));
            $score = sv_ollama_normalize_score($responseJson['score'] ?? ($responseJson['prompt_score'] ?? ($responseJson['quality_score'] ?? null)));
            $contradictions = sv_ollama_normalize_list_value($responseJson['contradictions'] ?? null);
            $missing = sv_ollama_normalize_list_value($responseJson['missing'] ?? null);
            $rationale = sv_ollama_normalize_text_value($responseJson['rationale'] ?? ($responseJson['reason'] ?? null));
            if ($mode === 'tags_normalize') {
                $tagsNormalized = sv_ollama_normalize_tag_list($responseJson['tags_normalized'] ?? null);
                $tagsMap = sv_ollama_normalize_tag_map($responseJson['tags_map'] ?? null);
            }
            if ($mode === 'quality') {
                if (isset($responseJson['quality_score']) && is_numeric($responseJson['quality_score'])) {
                    $qualityScore = (float)$responseJson['quality_score'];
                }
                $qualityFlags = sv_ollama_normalize_quality_flags($responseJson['quality_flags'] ?? null);
                $domainTypeRaw = sv_ollama_normalize_text_value($responseJson['domain_type'] ?? null);
                if ($domainTypeRaw !== null) {
                    $candidate = strtolower($domainTypeRaw);
                    if (in_array($candidate, SV_OLLAMA_DOMAIN_TYPES, true)) {
                        $domainType = $candidate;
                    }
                }
                if (isset($responseJson['domain_confidence']) && is_numeric($responseJson['domain_confidence'])) {
                    $domainConfidence = (float)$responseJson['domain_confidence'];
                }
            }
            if ($mode === 'prompt_recon') {
                $promptReconPrompt = sv_ollama_normalize_text_value($responseJson['prompt'] ?? null);
                $promptReconNegative = sv_ollama_normalize_text_value($responseJson['negative_prompt'] ?? null);
                if (isset($responseJson['confidence']) && is_numeric($responseJson['confidence'])) {
                    $promptReconConfidence = (float)$responseJson['confidence'];
                }
                $promptReconStyleTokens = sv_ollama_normalize_tag_list($responseJson['style_tokens'] ?? null);
                $promptReconSubjectTokens = sv_ollama_normalize_tag_list($responseJson['subject_tokens'] ?? null);
            }
        }

        if ($mode === 'tags_normalize') {
            if ($parseError) {
                throw new RuntimeException('Ollama-Antwort für tags_normalize konnte nicht geparst werden.');
            }
            if ($tagsNormalized === null || $tagsMap === null) {
                throw new RuntimeException('Ollama-Antwort für tags_normalize unvollständig.');
            }
        }

        $promptReconErrorType = null;
        $promptReconErrorDetail = null;
        if ($mode === 'prompt_recon') {
            if ($parseError) {
                $promptReconErrorType = 'parse_error';
                $promptReconErrorDetail = 'Ollama-Antwort für prompt_recon konnte nicht geparst werden.';
            } else {
                if ($promptReconPrompt === null) {
                    $promptReconErrorType = 'parse_error';
                    $promptReconErrorDetail = 'Ollama-Antwort für prompt_recon enthält keinen gültigen Prompt.';
                } elseif ($promptReconConfidence === null || $promptReconConfidence < 0 || $promptReconConfidence > 1) {
                    $promptReconErrorType = 'parse_error';
                    $promptReconErrorDetail = 'Ollama-Antwort für prompt_recon enthält keine gültige Confidence.';
                }
            }
        }

        $qualityErrorType = null;
        $qualityErrorDetail = null;
        if ($mode === 'quality') {
            if ($parseError) {
                $qualityErrorType = 'parse_error';
                $qualityErrorDetail = 'Ollama-Antwort für quality konnte nicht geparst werden.';
            } else {
                if ($qualityScore === null || $qualityScore < 0 || $qualityScore > 100) {
                    $qualityErrorType = 'parse_error';
                    $qualityErrorDetail = 'Ollama-Antwort für quality enthält keinen gültigen quality_score.';
                } elseif ($qualityFlags === null) {
                    $qualityErrorType = 'parse_error';
                    $qualityErrorDetail = 'Ollama-Antwort für quality enthält keine gültigen quality_flags.';
                } elseif ($domainType === null) {
                    $qualityErrorType = 'parse_error';
                    $qualityErrorDetail = 'Ollama-Antwort für quality enthält keinen gültigen domain_type.';
                } elseif ($domainConfidence === null || $domainConfidence < 0 || $domainConfidence > 1) {
                    $qualityErrorType = 'parse_error';
                    $qualityErrorDetail = 'Ollama-Antwort für quality enthält keine gültige domain_confidence.';
                }
            }
        }

        if ($qualityErrorType !== null) {
            $parseError = true;
        }
        if ($promptReconErrorType !== null) {
            $parseError = true;
        }

        $rawJson = is_array($responseJson) ? sv_ollama_encode_json($responseJson) : null;

        $meta = [
            'job_id' => $jobId,
            'job_type' => $jobType,
            'mode' => $mode,
            'prompt_id' => $promptData['prompt_id'],
            'latency_ms' => $response['latency_ms'] ?? null,
            'usage' => $response['usage'] ?? null,
            'parse_error' => $parseError,
            'image_source' => is_array($imageData) ? ($imageData['source'] ?? null) : null,
        ];
        if ($qualityErrorType !== null) {
            $meta['error_type'] = $qualityErrorType;
        }
        if ($promptReconErrorType !== null) {
            $meta['error_type'] = $promptReconErrorType;
        }
        $metaJson = sv_ollama_encode_json($meta);

        if ($mode === 'quality' && $qualityErrorType !== null) {
            $resultId = sv_ollama_insert_result($pdo, [
                'media_id' => $mediaId,
                'mode' => $mode,
                'model' => (string)($response['model'] ?? $options['model']),
                'title' => null,
                'caption' => null,
                'score' => $qualityScore !== null && $qualityScore >= 0 && $qualityScore <= 100 ? $qualityScore : null,
                'contradictions' => null,
                'missing' => null,
                'rationale' => $rationale,
                'raw_json' => $rawJson,
                'raw_text' => $responseText,
                'parse_error' => true,
                'created_at' => date('c'),
                'meta' => $metaJson,
            ]);

            $metaSnapshot = sv_ollama_build_meta_snapshot(
                $resultId,
                (string)($response['model'] ?? $options['model']),
                true,
                null,
                null,
                null
            );

            sv_ollama_persist_media_meta($pdo, $mediaId, $mode, [
                'meta' => $metaSnapshot,
            ], [
                'set_common_meta' => false,
            ]);

            $detail = $qualityErrorDetail ?? 'Ollama-Antwort für quality ist ungültig.';
            throw new RuntimeException('parse_error: ' . $detail);
        }

        if ($mode === 'prompt_recon' && $promptReconErrorType !== null) {
            $resultId = sv_ollama_insert_result($pdo, [
                'media_id' => $mediaId,
                'mode' => $mode,
                'model' => (string)($response['model'] ?? $options['model']),
                'title' => null,
                'caption' => $promptReconPrompt,
                'score' => null,
                'contradictions' => null,
                'missing' => null,
                'rationale' => $rationale,
                'raw_json' => $rawJson,
                'raw_text' => $responseText,
                'parse_error' => true,
                'created_at' => date('c'),
                'meta' => $metaJson,
            ]);

            $metaSnapshot = sv_ollama_build_tags_normalize_meta(
                $resultId,
                (string)($response['model'] ?? $options['model']),
                true
            );

            sv_ollama_persist_media_meta($pdo, $mediaId, $mode, [
                'meta' => $metaSnapshot,
            ], [
                'set_common_meta' => false,
            ]);

            $detail = $promptReconErrorDetail ?? 'Ollama-Antwort für prompt_recon ist ungültig.';
            throw new RuntimeException('parse_error: ' . $detail);
        }

        $resultId = sv_ollama_insert_result($pdo, [
            'media_id' => $mediaId,
            'mode' => $mode,
            'model' => (string)($response['model'] ?? $options['model']),
            'title' => $title,
            'caption' => $mode === 'prompt_recon' ? $promptReconPrompt : $caption,
            'score' => $mode === 'quality'
                ? ($qualityScore !== null && $qualityScore >= 0 && $qualityScore <= 100 ? $qualityScore : null)
                : $score,
            'contradictions' => $contradictions,
            'missing' => $missing,
            'rationale' => $rationale,
            'raw_json' => $rawJson,
            'raw_text' => $parseError ? $responseText : null,
            'parse_error' => $parseError,
            'created_at' => date('c'),
            'meta' => $metaJson,
        ]);

        $lastSuccessAt = date('c');
        $payload['last_success_at'] = $lastSuccessAt;
        sv_update_job_checkpoint_payload($pdo, $jobId, $payload);

        if ($mode === 'tags_normalize' || $mode === 'prompt_recon') {
            $metaSnapshot = sv_ollama_build_tags_normalize_meta(
                $resultId,
                (string)($response['model'] ?? $options['model']),
                $parseError
            );
        } else {
            $metaSnapshot = sv_ollama_build_meta_snapshot(
                $resultId,
                (string)($response['model'] ?? $options['model']),
                $parseError,
                $mode === 'quality' ? null : $contradictions,
                $mode === 'quality' ? null : $missing,
                $mode === 'quality' ? null : $rationale
            );
        }

        sv_ollama_persist_media_meta($pdo, $mediaId, $mode, [
            'caption' => $caption,
            'title' => $title,
            'score' => $score,
            'last_run_at' => $lastSuccessAt,
            'meta' => $metaSnapshot,
            'tags_raw' => $mode === 'tags_normalize' ? sv_ollama_encode_json($rawTags ?? []) : null,
            'tags_normalized' => $mode === 'tags_normalize' ? sv_ollama_encode_json($tagsNormalized ?? []) : null,
            'tags_map' => $mode === 'tags_normalize' ? sv_ollama_encode_json($tagsMap ?? []) : null,
            'quality_score' => $mode === 'quality' ? $qualityScore : null,
            'quality_flags' => $mode === 'quality' ? sv_ollama_encode_json($qualityFlags ?? []) : null,
            'domain_type' => $mode === 'quality' ? $domainType : null,
            'domain_confidence' => $mode === 'quality' ? $domainConfidence : null,
            'prompt' => $mode === 'prompt_recon' ? $promptReconPrompt : null,
            'negative_prompt' => $mode === 'prompt_recon' ? $promptReconNegative : null,
            'confidence' => $mode === 'prompt_recon' ? $promptReconConfidence : null,
            'style_tokens' => $mode === 'prompt_recon' && $promptReconStyleTokens !== null
                ? sv_ollama_encode_json($promptReconStyleTokens)
                : null,
            'subject_tokens' => $mode === 'prompt_recon' && $promptReconSubjectTokens !== null
                ? sv_ollama_encode_json($promptReconSubjectTokens)
                : null,
        ]);

        $responseLog = $rawJson ? sv_ollama_truncate_for_log($rawJson, 300) : ($responseText ? sv_ollama_truncate_for_log($responseText, 300) : '');
        $promptLog = sv_ollama_truncate_for_log($prompt, 200);

        sv_update_job_status($pdo, $jobId, 'done', json_encode([
            'job_type' => $jobType,
            'media_id' => $mediaId,
            'mode' => $mode,
            'result_id' => $resultId,
            'model' => $response['model'] ?? $options['model'],
            'parse_error' => $parseError,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        sv_audit_log($pdo, 'ollama_done', 'jobs', $jobId, [
            'media_id' => $mediaId,
            'job_type' => $jobType,
            'mode' => $mode,
            'result_id' => $resultId,
            'model' => $response['model'] ?? $options['model'],
            'parse_error' => $parseError,
        ]);

        sv_ollama_log_jsonl($config, 'ollama_jobs.jsonl', [
            'ts' => date('c'),
            'event' => 'success',
            'job_id' => $jobId,
            'media_id' => $mediaId,
            'job_type' => $jobType,
            'mode' => $mode,
            'prompt_preview' => $promptLog,
            'response_preview' => $responseLog,
            'model' => $response['model'] ?? $options['model'],
            'parse_error' => $parseError,
        ]);

        return [
            'job_id' => $jobId,
            'media_id' => $mediaId,
            'job_type' => $jobType,
            'status' => 'done',
            'result_id' => $resultId,
        ];
    } catch (Throwable $e) {
        $errorMessage = sv_sanitize_error_message($e->getMessage(), 240);
        if ($mode === 'quality' && $imageLoadError !== null) {
            $errorMessage = 'invalid_image: ' . $errorMessage;
        }
        $attempts++;
        $payload['attempts'] = $attempts;
        $payload['last_error_at'] = date('c');
        $payload['last_error'] = $errorMessage;
        sv_update_job_checkpoint_payload($pdo, $jobId, $payload);

        if ($attempts <= $maxRetries) {
            sv_update_job_status($pdo, $jobId, 'pending', null, $errorMessage);

            sv_ollama_log_jsonl($config, 'ollama_jobs.jsonl', [
                'ts' => date('c'),
                'event' => 'retry',
                'job_id' => $jobId,
                'media_id' => $mediaId,
                'job_type' => $jobType,
                'mode' => $mode,
                'attempts' => $attempts,
                'error' => $errorMessage,
            ]);

            $logger('Ollama-Job Retry (' . $attempts . '/' . $maxRetries . '): ' . $errorMessage);

            return [
                'job_id' => $jobId,
                'media_id' => $mediaId,
                'job_type' => $jobType,
                'status' => 'retry',
                'error' => $errorMessage,
            ];
        }

        sv_update_job_status($pdo, $jobId, 'error', null, $errorMessage);

        sv_audit_log($pdo, 'ollama_error', 'jobs', $jobId, [
            'media_id' => $mediaId,
            'job_type' => $jobType,
            'mode' => $mode,
            'error' => $errorMessage,
        ]);

        sv_ollama_log_jsonl($config, 'ollama_jobs.jsonl', [
            'ts' => date('c'),
            'event' => 'error',
            'job_id' => $jobId,
            'media_id' => $mediaId,
            'job_type' => $jobType,
            'mode' => $mode,
            'error' => $errorMessage,
        ]);

        sv_ollama_log_jsonl($config, 'ollama_errors.jsonl', [
            'ts' => date('c'),
            'event' => 'error',
            'job_id' => $jobId,
            'media_id' => $mediaId,
            'job_type' => $jobType,
            'mode' => $mode,
            'error' => $errorMessage,
        ]);

        $logger('Ollama-Job Fehler: ' . $errorMessage);

        return [
            'job_id' => $jobId,
            'media_id' => $mediaId,
            'job_type' => $jobType,
            'status' => 'error',
            'error' => $errorMessage,
        ];
    }
}

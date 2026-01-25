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
const SV_JOB_TYPE_OLLAMA_EMBED       = 'ollama_embed';
const SV_JOB_TYPE_OLLAMA_PROMPT_RECON = 'ollama_prompt_recon';
const SV_OLLAMA_STAGE_VERSION        = 'stage5_v1';
const SV_OLLAMA_EMBED_VERSION        = 'embed_v1';

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
        SV_JOB_TYPE_OLLAMA_EMBED,
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
    if ($mode === 'embed') {
        return SV_JOB_TYPE_OLLAMA_EMBED;
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
    if ($jobType === SV_JOB_TYPE_OLLAMA_EMBED) {
        return 'embed';
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

function sv_ollama_jobs_columns(PDO $pdo): array
{
    static $columns = null;
    if (is_array($columns)) {
        return $columns;
    }

    $columns = [];
    $stmt = $pdo->query('PRAGMA table_info(jobs)');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($row['name'])) {
            $columns[(string)$row['name']] = true;
        }
    }

    return $columns;
}

function sv_ollama_job_has_column(PDO $pdo, string $column): bool
{
    $columns = sv_ollama_jobs_columns($pdo);
    return isset($columns[$column]);
}

function sv_ollama_update_job_columns(PDO $pdo, int $jobId, array $fields, bool $touchUpdatedAt = true): void
{
    if ($jobId <= 0 || $fields === []) {
        return;
    }

    $columns = sv_ollama_jobs_columns($pdo);
    $setParts = [];
    $params = [':id' => $jobId];
    foreach ($fields as $column => $value) {
        if (!isset($columns[$column])) {
            continue;
        }
        $setParts[] = $column . ' = :' . $column;
        $params[':' . $column] = $value;
    }

    if ($touchUpdatedAt && isset($columns['updated_at'])) {
        $setParts[] = 'updated_at = :updated_at';
        $params[':updated_at'] = date('c');
    }

    if ($setParts === []) {
        return;
    }

    $stmt = $pdo->prepare('UPDATE jobs SET ' . implode(', ', $setParts) . ' WHERE id = :id');
    $stmt->execute($params);
}

function sv_ollama_touch_job_progress(PDO $pdo, int $jobId, int $progressBits, ?int $progressTotal, ?string $errorCode = null): void
{
    $fields = [
        'heartbeat_at' => date('c'),
        'progress_bits' => max(0, $progressBits),
    ];
    if ($progressTotal !== null) {
        $fields['progress_bits_total'] = max(0, $progressTotal);
    }
    if ($errorCode !== null) {
        $fields['last_error_code'] = $errorCode;
    }

    sv_ollama_update_job_columns($pdo, $jobId, $fields, true);
}

function sv_ollama_progress_bits_total(string $mode): int
{
    $mode = trim($mode);
    $defaults = [
        'caption' => 1000,
        'title' => 1000,
        'prompt_eval' => 1000,
        'tags_normalize' => 1000,
        'quality' => 1000,
        'prompt_recon' => 1000,
        'embed' => 1000,
    ];

    return $defaults[$mode] ?? 1000;
}

function sv_ollama_fetch_job_control(PDO $pdo, int $jobId): array
{
    $columns = ['id', 'status'];
    $optional = ['cancel_requested', 'progress_bits', 'progress_bits_total', 'heartbeat_at', 'last_error_code'];
    foreach ($optional as $column) {
        if (sv_ollama_job_has_column($pdo, $column)) {
            $columns[] = $column;
        }
    }

    $stmt = $pdo->prepare('SELECT ' . implode(', ', $columns) . ' FROM jobs WHERE id = :id');
    $stmt->execute([':id' => $jobId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

function sv_ollama_request_cancel(PDO $pdo, int $jobId): bool
{
    if ($jobId <= 0) {
        return false;
    }

    sv_ollama_update_job_columns($pdo, $jobId, [
        'cancel_requested' => 1,
        'heartbeat_at' => date('c'),
    ], true);

    return true;
}

function sv_ollama_is_cancelled(array $jobRow): bool
{
    $status = isset($jobRow['status']) ? (string)$jobRow['status'] : '';
    $cancelRequested = isset($jobRow['cancel_requested']) ? (int)$jobRow['cancel_requested'] : 0;
    return $cancelRequested === 1 || $status === 'cancelled';
}

function sv_ollama_mark_cancelled(PDO $pdo, int $jobId, array $context, callable $logger): array
{
    $now = date('c');
    sv_update_job_status($pdo, $jobId, 'cancelled', null, 'cancelled');
    sv_ollama_update_job_columns($pdo, $jobId, [
        'cancel_requested' => 1,
        'cancelled_at' => $now,
        'heartbeat_at' => $now,
        'last_error_code' => 'cancelled',
    ], true);

    $logger('Ollama-Job #' . $jobId . ' abgebrochen.');
    sv_ollama_log_jsonl($context['config'], 'ollama_jobs.jsonl', [
        'ts' => $now,
        'event' => 'cancelled',
        'job_id' => $jobId,
        'media_id' => $context['media_id'] ?? null,
        'job_type' => $context['job_type'] ?? null,
        'mode' => $context['mode'] ?? null,
    ]);

    return [
        'job_id' => $jobId,
        'media_id' => $context['media_id'] ?? null,
        'job_type' => $context['job_type'] ?? null,
        'status' => 'cancelled',
    ];
}

function sv_ollama_error_code_for_message(string $message, ?Throwable $imageLoadError): string
{
    $normalized = strtolower($message);
    if (strpos($normalized, 'parse_error:') === 0 || strpos($normalized, 'parse_error') !== false) {
        return 'parse_error';
    }
    if ($imageLoadError !== null || strpos($normalized, 'invalid_image') === 0) {
        return 'invalid_image';
    }

    return 'transport_error';
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

function sv_ollama_mode_meta_keys(string $mode): array
{
    $mode = trim($mode);
    if ($mode === 'caption') {
        return ['ollama.caption', 'ollama.caption.meta'];
    }
    if ($mode === 'title') {
        return ['ollama.title', 'ollama.title.meta'];
    }
    if ($mode === 'prompt_eval') {
        return ['ollama.prompt_eval.score', 'ollama.prompt_eval.meta'];
    }
    if ($mode === 'tags_normalize') {
        return ['ollama.tags_raw', 'ollama.tags_normalized', 'ollama.tags_map', 'ollama.tags_normalize.meta'];
    }
    if ($mode === 'quality') {
        return [
            'ollama.quality.score',
            'ollama.quality.flags',
            'ollama.domain.type',
            'ollama.domain.confidence',
            'ollama.quality.meta',
        ];
    }
    if ($mode === 'embed') {
        return [
            'ollama.embed.text.model',
            'ollama.embed.text.dims',
            'ollama.embed.text.hash',
            'ollama.embed.text.vector_id',
            'ollama.embed.meta',
        ];
    }
    if ($mode === 'prompt_recon') {
        return [
            'ollama.prompt_recon.prompt',
            'ollama.prompt_recon.negative',
            'ollama.prompt_recon.confidence',
            'ollama.prompt_recon.style_tokens',
            'ollama.prompt_recon.subject_tokens',
            'ollama.prompt_recon.meta',
        ];
    }
    if ($mode === 'dupe_hints') {
        return [
            'ollama.dupe_hints.top',
            'ollama.dupe_hints.threshold',
            'ollama.dupe_hints.meta',
        ];
    }

    throw new InvalidArgumentException('Unbekannter Ollama-Modus: ' . $mode);
}

function sv_ollama_delete_results(PDO $pdo, int $mediaId, string $mode): array
{
    $deletedResults = 0;
    $deletedMeta = 0;
    $deletedVectors = 0;

    $resultStmt = $pdo->prepare('DELETE FROM ollama_results WHERE media_id = :media_id AND mode = :mode');
    $resultStmt->execute([
        ':media_id' => $mediaId,
        ':mode' => $mode,
    ]);
    $deletedResults = $resultStmt->rowCount();

    $metaKeys = sv_ollama_mode_meta_keys($mode);
    if ($metaKeys !== []) {
        $placeholders = implode(',', array_fill(0, count($metaKeys), '?'));
        $metaStmt = $pdo->prepare(
            'DELETE FROM media_meta WHERE media_id = ? AND meta_key IN (' . $placeholders . ')'
        );
        $metaStmt->execute(array_merge([$mediaId], $metaKeys));
        $deletedMeta = $metaStmt->rowCount();
    }

    if ($mode === 'embed') {
        $vectorId = sv_get_media_meta_value($pdo, $mediaId, 'ollama.embed.text.vector_id');
        if ($vectorId !== null && ctype_digit((string)$vectorId)) {
            $vectorStmt = $pdo->prepare('DELETE FROM ollama_vectors WHERE id = :id');
            $vectorStmt->execute([':id' => (int)$vectorId]);
            $deletedVectors += $vectorStmt->rowCount();
        } else {
            $model = sv_get_media_meta_value($pdo, $mediaId, 'ollama.embed.text.model');
            $hash = sv_get_media_meta_value($pdo, $mediaId, 'ollama.embed.text.hash');
            if (is_string($model) && $model !== '' && is_string($hash) && $hash !== '') {
                $vectorStmt = $pdo->prepare(
                    'DELETE FROM ollama_vectors WHERE media_id = :media_id AND kind = :kind AND model = :model AND input_hash = :hash'
                );
                $vectorStmt->execute([
                    ':media_id' => $mediaId,
                    ':kind' => 'text',
                    ':model' => $model,
                    ':hash' => $hash,
                ]);
                $deletedVectors += $vectorStmt->rowCount();
            }
        }
    }

    return [
        'deleted_results' => $deletedResults,
        'deleted_meta' => $deletedMeta,
        'deleted_vectors' => $deletedVectors,
    ];
}

function sv_ollama_delete_jobs(PDO $pdo, int $mediaId, ?string $mode = null): array
{
    $jobTypes = $mode !== null ? [sv_ollama_job_type_for_mode($mode)] : sv_ollama_job_types();
    $placeholders = implode(',', array_fill(0, count($jobTypes), '?'));
    $params = $jobTypes;
    $params[] = $mediaId;

    $stmt = $pdo->prepare(
        'SELECT id, status, type' . (sv_ollama_job_has_column($pdo, 'cancel_requested') ? ', cancel_requested' : '')
        . ' FROM jobs WHERE type IN (' . $placeholders . ') AND media_id = ?'
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $blocked = [];
    $canceledSet = 0;
    $deleted = 0;

    foreach ($rows as $row) {
        $status = (string)($row['status'] ?? '');
        $jobId = (int)($row['id'] ?? 0);
        if ($status === 'running') {
            if (!empty($row['cancel_requested'])) {
                $blocked[] = $jobId;
            } else {
                sv_ollama_request_cancel($pdo, $jobId);
                $canceledSet++;
                $blocked[] = $jobId;
            }
            continue;
        }
        if (!in_array($status, ['done', 'error', 'cancelled'], true)) {
            $blocked[] = $jobId;
            continue;
        }
    }

    if ($blocked !== []) {
        return [
            'deleted' => 0,
            'blocked' => $blocked,
            'cancel_requested_set' => $canceledSet,
        ];
    }

    $statusPlaceholders = implode(',', array_fill(0, 3, '?'));
    $deleteParams = array_merge($jobTypes, [$mediaId], ['done', 'error', 'cancelled']);
    $deleteStmt = $pdo->prepare(
        'DELETE FROM jobs WHERE type IN (' . $placeholders . ') AND media_id = ? AND status IN (' . $statusPlaceholders . ')'
    );
    $deleteStmt->execute($deleteParams);
    $deleted = $deleteStmt->rowCount();

    return [
        'deleted' => $deleted,
        'blocked' => [],
        'cancel_requested_set' => $canceledSet,
    ];
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

function sv_ollama_parse_json_list(?string $value): ?array
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

function sv_ollama_build_embedding_input(PDO $pdo, int $mediaId): ?string
{
    $parts = [];

    $caption = sv_ollama_normalize_text_value(sv_get_media_meta_value($pdo, $mediaId, 'ollama.caption'));
    if ($caption !== null) {
        $parts[] = 'caption: ' . $caption;
    }

    $title = sv_ollama_normalize_text_value(sv_get_media_meta_value($pdo, $mediaId, 'ollama.title'));
    if ($title !== null) {
        $parts[] = 'title: ' . $title;
    }

    $tagsRaw = sv_get_media_meta_value($pdo, $mediaId, 'ollama.tags_normalized');
    $tags = sv_ollama_parse_json_list(is_string($tagsRaw) ? $tagsRaw : null);
    if ($tags === null && is_string($tagsRaw) && trim($tagsRaw) !== '') {
        $tags = array_values(array_filter(array_map('trim', explode(',', $tagsRaw)), static fn ($v) => $v !== ''));
    }
    if ($tags !== null) {
        sort($tags, SORT_STRING);
        $parts[] = 'tags_normalized: ' . implode(', ', $tags);
    }

    $domainType = sv_ollama_normalize_text_value(sv_get_media_meta_value($pdo, $mediaId, 'ollama.domain.type'));
    if ($domainType !== null) {
        $parts[] = 'domain_type: ' . $domainType;
    }

    $flagsRaw = sv_get_media_meta_value($pdo, $mediaId, 'ollama.quality.flags');
    $flags = sv_ollama_parse_json_list(is_string($flagsRaw) ? $flagsRaw : null);
    if ($flags !== null) {
        sort($flags, SORT_STRING);
        $parts[] = 'quality_flags: ' . implode(', ', $flags);
    }

    $promptRecon = sv_ollama_normalize_text_value(sv_get_media_meta_value($pdo, $mediaId, 'ollama.prompt_recon.prompt'));
    if ($promptRecon !== null) {
        $parts[] = 'prompt_recon: ' . $promptRecon;
    }

    if ($parts === []) {
        return null;
    }

    return implode("\n", $parts);
}

function sv_ollama_has_embedding_seed(PDO $pdo, int $mediaId): bool
{
    $caption = sv_ollama_normalize_text_value(sv_get_media_meta_value($pdo, $mediaId, 'ollama.caption'));
    if ($caption !== null) {
        return true;
    }

    $tagsRaw = sv_get_media_meta_value($pdo, $mediaId, 'ollama.tags_normalized');
    $tags = sv_ollama_parse_json_list(is_string($tagsRaw) ? $tagsRaw : null);
    if ($tags !== null) {
        return true;
    }
    if (is_string($tagsRaw) && trim($tagsRaw) !== '') {
        return true;
    }

    $promptRecon = sv_ollama_normalize_text_value(sv_get_media_meta_value($pdo, $mediaId, 'ollama.prompt_recon.prompt'));
    return $promptRecon !== null;
}

function sv_ollama_embed_input_hash(string $input, string $model, string $kind, string $version): string
{
    return hash('sha256', $input . "\n" . $model . "\n" . $kind . "\n" . $version);
}

function sv_ollama_fetch_vector_id(PDO $pdo, int $mediaId, string $kind, string $model, string $inputHash): ?int
{
    $stmt = $pdo->prepare(
        'SELECT id FROM ollama_vectors WHERE media_id = :media_id AND kind = :kind AND model = :model AND input_hash = :input_hash ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([
        ':media_id' => $mediaId,
        ':kind' => $kind,
        ':model' => $model,
        ':input_hash' => $inputHash,
    ]);
    $vectorId = $stmt->fetchColumn();
    return $vectorId ? (int)$vectorId : null;
}

function sv_ollama_upsert_vector(PDO $pdo, array $data): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO ollama_vectors (media_id, kind, model, dims, vector_json, input_hash, created_at, updated_at) '
        . 'VALUES (:media_id, :kind, :model, :dims, :vector_json, :input_hash, :created_at, :updated_at) '
        . 'ON CONFLICT(media_id, kind, model, input_hash) DO UPDATE SET dims = excluded.dims, vector_json = excluded.vector_json, updated_at = excluded.updated_at'
    );
    $stmt->execute([
        ':media_id' => (int)$data['media_id'],
        ':kind' => (string)$data['kind'],
        ':model' => (string)$data['model'],
        ':dims' => (int)$data['dims'],
        ':vector_json' => (string)$data['vector_json'],
        ':input_hash' => (string)$data['input_hash'],
        ':created_at' => (string)$data['created_at'],
        ':updated_at' => (string)$data['updated_at'],
    ]);

    $vectorId = (int)$pdo->lastInsertId();
    if ($vectorId > 0) {
        return $vectorId;
    }

    $lookup = $pdo->prepare(
        'SELECT id FROM ollama_vectors WHERE media_id = :media_id AND kind = :kind AND model = :model AND input_hash = :input_hash ORDER BY id DESC LIMIT 1'
    );
    $lookup->execute([
        ':media_id' => (int)$data['media_id'],
        ':kind' => (string)$data['kind'],
        ':model' => (string)$data['model'],
        ':input_hash' => (string)$data['input_hash'],
    ]);
    $vectorId = $lookup->fetchColumn();
    return $vectorId ? (int)$vectorId : 0;
}

function sv_ollama_encode_json($value): ?string
{
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $json === false ? null : $json;
}

function sv_ollama_vector_decode(string $json): ?array
{
    $decoded = json_decode($json, true);
    if (!is_array($decoded) || $decoded === []) {
        return null;
    }

    $vector = [];
    foreach ($decoded as $entry) {
        if (!is_numeric($entry)) {
            return null;
        }
        $vector[] = (float)$entry;
    }

    return $vector;
}

function sv_ollama_cosine_similarity(array $a, array $b): ?float
{
    $count = count($a);
    if ($count === 0 || $count !== count($b)) {
        return null;
    }

    $dot = 0.0;
    $normA = 0.0;
    $normB = 0.0;

    for ($i = 0; $i < $count; $i++) {
        $va = (float)$a[$i];
        $vb = (float)$b[$i];
        $dot += $va * $vb;
        $normA += $va * $va;
        $normB += $vb * $vb;
    }

    if ($normA <= 0.0 || $normB <= 0.0) {
        return null;
    }

    return $dot / (sqrt($normA) * sqrt($normB));
}

function sv_ollama_select_topk(array $items, int $k): array
{
    if ($k <= 0 || $items === []) {
        return [];
    }

    usort($items, static function (array $left, array $right): int {
        $scoreLeft = isset($left['score']) ? (float)$left['score'] : 0.0;
        $scoreRight = isset($right['score']) ? (float)$right['score'] : 0.0;
        if ($scoreLeft === $scoreRight) {
            return 0;
        }
        return ($scoreLeft < $scoreRight) ? 1 : -1;
    });

    return array_slice($items, 0, $k);
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
    if ($mode === 'embed') {
        if (isset($values['embed_model']) && $values['embed_model'] !== null) {
            sv_set_media_meta_value($pdo, $mediaId, 'ollama.embed.text.model', $values['embed_model'], $source);
        }
        if (isset($values['embed_dims']) && $values['embed_dims'] !== null) {
            sv_set_media_meta_value($pdo, $mediaId, 'ollama.embed.text.dims', $values['embed_dims'], $source);
        }
        if (isset($values['embed_hash']) && $values['embed_hash'] !== null) {
            sv_set_media_meta_value($pdo, $mediaId, 'ollama.embed.text.hash', $values['embed_hash'], $source);
        }
        if (isset($values['embed_vector_id']) && $values['embed_vector_id'] !== null) {
            sv_set_media_meta_value($pdo, $mediaId, 'ollama.embed.text.vector_id', $values['embed_vector_id'], $source);
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

function sv_ollama_embed_candidate(PDO $pdo, array $config, int $mediaId, bool $allFlag): array
{
    if (!sv_ollama_has_embedding_seed($pdo, $mediaId)) {
        return [
            'eligible' => false,
            'reason' => 'Keine Embedding-Quelle verfügbar.',
        ];
    }

    $input = sv_ollama_build_embedding_input($pdo, $mediaId);
    if ($input === null) {
        return [
            'eligible' => false,
            'reason' => 'Kein Embedding-Input vorhanden.',
        ];
    }

    $ollamaCfg = sv_ollama_config($config);
    $model = $ollamaCfg['model']['embed'] ?? $ollamaCfg['model_default'];
    $kind = 'text';
    $hash = sv_ollama_embed_input_hash($input, $model, $kind, SV_OLLAMA_EMBED_VERSION);

    if (!$allFlag) {
        $existingHash = sv_get_media_meta_value($pdo, $mediaId, 'ollama.embed.text.hash');
        if (is_string($existingHash) && $existingHash === $hash) {
            $vectorId = sv_ollama_fetch_vector_id($pdo, $mediaId, $kind, $model, $hash);
            if ($vectorId !== null) {
                return [
                    'eligible' => false,
                    'reason' => 'Embedding bereits vorhanden.',
                    'hash' => $hash,
                    'model' => $model,
                ];
            }
        }
    }

    return [
        'eligible' => true,
        'hash' => $hash,
        'model' => $model,
    ];
}

function sv_ollama_fetch_media_row(PDO $pdo, int $mediaId): array
{
    $stmt = $pdo->prepare('SELECT path, type, filesize, hash, has_nsfw FROM media WHERE id = :id');
    $stmt->execute([':id' => $mediaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('Media-Eintrag nicht gefunden.');
    }

    return $row;
}

function sv_ollama_resolve_local_image_path(array $mediaRow, array $config): ?string
{
    $pathsCfg = $config['paths'] ?? [];
    $target = sv_resolve_media_target($mediaRow, $pathsCfg);
    if (!is_array($target)) {
        return null;
    }

    $candidate = (string)($target['path'] ?? '');
    if ($candidate === '') {
        return null;
    }

    try {
        sv_assert_media_path_allowed($candidate, $pathsCfg, 'ollama_job_fallback');
    } catch (Throwable $e) {
        return null;
    }

    if (!is_file($candidate)) {
        return null;
    }

    return $candidate;
}

function sv_ollama_load_image_source(PDO $pdo, array $config, int $mediaId, array $payload): array
{
    $source = $payload['image_source'] ?? null;
    $mediaPath = null;
    $mediaRow = null;

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
        $row = sv_ollama_fetch_media_row($pdo, $mediaId);
        $mediaRow = $row;
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
            if ($mediaRow === null) {
                $mediaRow = sv_ollama_fetch_media_row($pdo, $mediaId);
            }
            $fallbackPath = sv_ollama_resolve_local_image_path($mediaRow, $config);
            if ($fallbackPath === null) {
                throw new RuntimeException('Bild konnte nicht von URL geladen werden.');
            }
            $mediaPath = $fallbackPath;
        } else {
            if ($maxBytes > 0 && strlen($raw) > $maxBytes) {
                throw new RuntimeException('Bildgröße zu groß (' . strlen($raw) . ' > ' . $maxBytes . ' Bytes).');
            }
            return [
                'base64' => base64_encode($raw),
                'source' => 'url',
            ];
        }
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

function sv_ollama_run_batch(int $batch, int $maxSeconds): array
{
    try {
        $config = sv_load_config();
        $pdo = sv_open_pdo($config);

        return sv_ollama_run_batch_with_context($pdo, $config, $batch, $maxSeconds, static function (): void {
        });
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'error' => $e->getMessage(),
            'processed' => 0,
            'done' => 0,
            'errors' => 0,
            'cancelled' => 0,
            'remaining_queued' => 0,
            'remaining_running' => 0,
        ];
    }
}

function sv_ollama_run_batch_with_context(PDO $pdo, array $config, int $batch, int $maxSeconds, callable $logger): array
{
    $ollamaCfg = sv_ollama_config($config);
    if ($batch <= 0) {
        $batch = (int)($ollamaCfg['worker']['batch_size'] ?? 5);
    }
    if ($batch <= 0) {
        $batch = 5;
    }
    if ($maxSeconds <= 0) {
        $maxSeconds = 20;
    }

    $jobTypes = sv_ollama_job_types();
    sv_mark_stuck_jobs($pdo, $jobTypes, SV_JOB_STUCK_MINUTES, $logger);

    $processed = 0;
    $done = 0;
    $errors = 0;
    $cancelled = 0;

    $startedAt = microtime(true);
    $timeUp = false;

    while ($processed < $batch && !$timeUp) {
        $limit = min($batch - $processed, $batch);
        $rows = sv_ollama_fetch_pending_jobs($pdo, $jobTypes, $limit, null);
        if ($rows === []) {
            break;
        }

        foreach ($rows as $row) {
            $processed++;
            $result = sv_process_ollama_job($pdo, $config, $row, $logger);
            $status = (string)($result['status'] ?? '');
            if ($status === 'done') {
                $done++;
            } elseif ($status === 'error') {
                $errors++;
            } elseif ($status === 'cancelled') {
                $cancelled++;
            }

            if ((microtime(true) - $startedAt) >= $maxSeconds) {
                $timeUp = true;
                break;
            }
            if ($processed >= $batch) {
                break;
            }
        }
    }

    $placeholders = implode(',', array_fill(0, count($jobTypes), '?'));
    $statusStmt = $pdo->prepare(
        'SELECT status, COUNT(*) AS cnt FROM jobs WHERE type IN (' . $placeholders . ') AND status IN ("queued","pending","running") GROUP BY status'
    );
    $statusStmt->execute($jobTypes);
    $queued = 0;
    $running = 0;
    foreach ($statusStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $status = (string)($row['status'] ?? '');
        $count = (int)($row['cnt'] ?? 0);
        if ($status === 'running') {
            $running += $count;
        } elseif ($status === 'queued' || $status === 'pending') {
            $queued += $count;
        }
    }

    return [
        'ok' => true,
        'processed' => $processed,
        'done' => $done,
        'errors' => $errors,
        'cancelled' => $cancelled,
        'remaining_queued' => $queued,
        'remaining_running' => $running,
    ];
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

    $context = [
        'config' => $config,
        'media_id' => $mediaId,
        'job_type' => $jobType,
        'mode' => $mode,
    ];
    $progressTotal = sv_ollama_progress_bits_total($mode);
    $progressBits = 0;

    $cancelState = sv_ollama_fetch_job_control($pdo, $jobId);
    if (sv_ollama_is_cancelled($cancelState)) {
        return sv_ollama_mark_cancelled($pdo, $jobId, $context, $logger);
    }

    $payload['last_started_at'] = date('c');
    sv_update_job_checkpoint_payload($pdo, $jobId, $payload);

    sv_update_job_status($pdo, $jobId, 'running', json_encode([
        'job_type' => $jobType,
        'media_id' => $mediaId,
        'mode' => $mode,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    sv_ollama_touch_job_progress($pdo, $jobId, $progressBits, $progressTotal);

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

        if ($mode === 'embed') {
            if (!sv_ollama_has_embedding_seed($pdo, $mediaId)) {
                throw new RuntimeException('Keine Embedding-Quelle verfügbar.');
            }

            $embedInput = sv_ollama_build_embedding_input($pdo, $mediaId);
            if ($embedInput === null) {
                throw new RuntimeException('Kein Embedding-Input vorhanden.');
            }

            $options = isset($payload['options']) && is_array($payload['options']) ? $payload['options'] : [];
            if (isset($payload['model']) && is_string($payload['model']) && trim($payload['model']) !== '') {
                $options['model'] = trim($payload['model']);
            }
            if (!isset($options['model']) || trim((string)$options['model']) === '') {
                $options['model'] = $ollamaCfg['model']['embed'] ?? $ollamaCfg['model_default'];
            }
            if (!isset($options['timeout_ms'])) {
                $options['timeout_ms'] = $ollamaCfg['timeout_ms'];
            }
            if (!array_key_exists('deterministic', $options)) {
                $options['deterministic'] = $ollamaCfg['deterministic']['enabled'] ?? true;
            }

            $progressBits = 50;
            sv_ollama_touch_job_progress($pdo, $jobId, $progressBits, $progressTotal);
            if (sv_ollama_is_cancelled(sv_ollama_fetch_job_control($pdo, $jobId))) {
                return sv_ollama_mark_cancelled($pdo, $jobId, $context, $logger);
            }

            $embedModel = (string)$options['model'];
            $embedKind = 'text';
            $inputHash = sv_ollama_embed_input_hash($embedInput, $embedModel, $embedKind, SV_OLLAMA_EMBED_VERSION);

            $existingHash = sv_get_media_meta_value($pdo, $mediaId, 'ollama.embed.text.hash');
            if (is_string($existingHash) && $existingHash === $inputHash) {
                $vectorId = sv_ollama_fetch_vector_id($pdo, $mediaId, $embedKind, $embedModel, $inputHash);
                if ($vectorId !== null) {
                    sv_update_job_status($pdo, $jobId, 'done', json_encode([
                        'job_type' => $jobType,
                        'media_id' => $mediaId,
                        'mode' => $mode,
                        'vector_id' => $vectorId,
                        'model' => $embedModel,
                        'input_hash' => $inputHash,
                        'deduped' => true,
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                    $progressBits = 1000;
                    sv_ollama_touch_job_progress($pdo, $jobId, $progressBits, $progressTotal);

                    sv_ollama_log_jsonl($config, 'ollama_jobs.jsonl', [
                        'ts' => date('c'),
                        'event' => 'success',
                        'job_id' => $jobId,
                        'media_id' => $mediaId,
                        'job_type' => $jobType,
                        'mode' => $mode,
                        'model' => $embedModel,
                        'input_hash' => $inputHash,
                        'deduped' => true,
                    ]);

                    return [
                        'job_id' => $jobId,
                        'media_id' => $mediaId,
                        'job_type' => $jobType,
                        'status' => 'done',
                        'vector_id' => $vectorId,
                        'deduped' => true,
                    ];
                }
            }

            $progressBits = 150;
            sv_ollama_touch_job_progress($pdo, $jobId, $progressBits, $progressTotal);
            if (sv_ollama_is_cancelled(sv_ollama_fetch_job_control($pdo, $jobId))) {
                return sv_ollama_mark_cancelled($pdo, $jobId, $context, $logger);
            }

            $response = sv_ollama_embed_text($config, $embedInput, $options);
            if (empty($response['ok'])) {
                $error = isset($response['error']) ? (string)$response['error'] : 'Ollama-Request fehlgeschlagen.';
                throw new RuntimeException($error);
            }

            $progressBits = 500;
            sv_ollama_touch_job_progress($pdo, $jobId, $progressBits, $progressTotal);
            if (sv_ollama_is_cancelled(sv_ollama_fetch_job_control($pdo, $jobId))) {
                return sv_ollama_mark_cancelled($pdo, $jobId, $context, $logger);
            }

            $vector = $response['vector'] ?? null;
            if (!is_array($vector) || $vector === []) {
                throw new RuntimeException('Ungültige Embedding-Antwort.');
            }

            $cleanVector = [];
            foreach ($vector as $entry) {
                if (!is_numeric($entry)) {
                    throw new RuntimeException('Embedding enthält ungültige Werte.');
                }
                $cleanVector[] = (float)$entry;
            }

            $dims = count($cleanVector);
            if ($dims <= 0) {
                throw new RuntimeException('Embedding-Dimensionen fehlen.');
            }

            $progressBits = 800;
            sv_ollama_touch_job_progress($pdo, $jobId, $progressBits, $progressTotal);
            if (sv_ollama_is_cancelled(sv_ollama_fetch_job_control($pdo, $jobId))) {
                return sv_ollama_mark_cancelled($pdo, $jobId, $context, $logger);
            }

            $vectorJson = json_encode($cleanVector, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($vectorJson === false) {
                throw new RuntimeException('Embedding konnte nicht serialisiert werden.');
            }

            $now = date('c');
            $vectorId = sv_ollama_upsert_vector($pdo, [
                'media_id' => $mediaId,
                'kind' => $embedKind,
                'model' => $embedModel,
                'dims' => $dims,
                'vector_json' => $vectorJson,
                'input_hash' => $inputHash,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $rawJson = sv_ollama_encode_json([
                'input_hash' => $inputHash,
                'dims' => $dims,
                'kind' => $embedKind,
            ]);

            $meta = [
                'job_id' => $jobId,
                'job_type' => $jobType,
                'mode' => $mode,
                'latency_ms' => $response['latency_ms'] ?? null,
                'usage' => $response['usage'] ?? null,
                'parse_error' => false,
            ];
            $metaJson = sv_ollama_encode_json($meta);

            $resultId = sv_ollama_insert_result($pdo, [
                'media_id' => $mediaId,
                'mode' => $mode,
                'model' => $embedModel,
                'title' => null,
                'caption' => null,
                'score' => null,
                'contradictions' => null,
                'missing' => null,
                'rationale' => null,
                'raw_json' => $rawJson,
                'raw_text' => null,
                'parse_error' => false,
                'created_at' => $now,
                'meta' => $metaJson,
            ]);

            $payload['last_success_at'] = $now;
            sv_update_job_checkpoint_payload($pdo, $jobId, $payload);

            $metaSnapshot = sv_ollama_build_meta_snapshot($resultId, $embedModel, false, null, null, null);

            sv_ollama_persist_media_meta($pdo, $mediaId, $mode, [
                'embed_model' => $embedModel,
                'embed_dims' => $dims,
                'embed_hash' => $inputHash,
                'embed_vector_id' => $vectorId,
                'last_run_at' => $now,
                'meta' => $metaSnapshot,
            ]);

            $progressBits = 950;
            sv_ollama_touch_job_progress($pdo, $jobId, $progressBits, $progressTotal);

            sv_update_job_status($pdo, $jobId, 'done', json_encode([
                'job_type' => $jobType,
                'media_id' => $mediaId,
                'mode' => $mode,
                'vector_id' => $vectorId,
                'model' => $embedModel,
                'input_hash' => $inputHash,
                'result_id' => $resultId,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            $progressBits = 1000;
            sv_ollama_touch_job_progress($pdo, $jobId, $progressBits, $progressTotal);

            sv_audit_log($pdo, 'ollama_done', 'jobs', $jobId, [
                'media_id' => $mediaId,
                'job_type' => $jobType,
                'mode' => $mode,
                'result_id' => $resultId,
                'model' => $embedModel,
                'parse_error' => false,
            ]);

            sv_ollama_log_jsonl($config, 'ollama_jobs.jsonl', [
                'ts' => date('c'),
                'event' => 'success',
                'job_id' => $jobId,
                'media_id' => $mediaId,
                'job_type' => $jobType,
                'mode' => $mode,
                'model' => $embedModel,
                'input_hash' => $inputHash,
                'dims' => $dims,
            ]);

            return [
                'job_id' => $jobId,
                'media_id' => $mediaId,
                'job_type' => $jobType,
                'status' => 'done',
                'result_id' => $resultId,
                'vector_id' => $vectorId,
            ];
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

        $progressBits = 50;
        sv_ollama_touch_job_progress($pdo, $jobId, $progressBits, $progressTotal);
        if (sv_ollama_is_cancelled(sv_ollama_fetch_job_control($pdo, $jobId))) {
            return sv_ollama_mark_cancelled($pdo, $jobId, $context, $logger);
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

        $progressBits = 150;
        sv_ollama_touch_job_progress($pdo, $jobId, $progressBits, $progressTotal);
        if (sv_ollama_is_cancelled(sv_ollama_fetch_job_control($pdo, $jobId))) {
            return sv_ollama_mark_cancelled($pdo, $jobId, $context, $logger);
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

        $progressBits = 500;
        sv_ollama_touch_job_progress($pdo, $jobId, $progressBits, $progressTotal);
        if (sv_ollama_is_cancelled(sv_ollama_fetch_job_control($pdo, $jobId))) {
            return sv_ollama_mark_cancelled($pdo, $jobId, $context, $logger);
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
                $progressBits = 800;
                sv_ollama_touch_job_progress($pdo, $jobId, $progressBits, $progressTotal, 'parse_error');
                throw new RuntimeException('Ollama-Antwort für tags_normalize konnte nicht geparst werden.');
            }
            if ($tagsNormalized === null || $tagsMap === null) {
                $progressBits = 800;
                sv_ollama_touch_job_progress($pdo, $jobId, $progressBits, $progressTotal, 'parse_error');
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

        $progressBits = 800;
        sv_ollama_touch_job_progress($pdo, $jobId, $progressBits, $progressTotal, $parseError ? 'parse_error' : null);
        if (sv_ollama_is_cancelled(sv_ollama_fetch_job_control($pdo, $jobId))) {
            return sv_ollama_mark_cancelled($pdo, $jobId, $context, $logger);
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

        $progressBits = 950;
        sv_ollama_touch_job_progress($pdo, $jobId, $progressBits, $progressTotal);

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

        $progressBits = 1000;
        sv_ollama_touch_job_progress($pdo, $jobId, $progressBits, $progressTotal, $parseError ? 'parse_error' : null);

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
        if (sv_ollama_is_cancelled(sv_ollama_fetch_job_control($pdo, $jobId))) {
            return sv_ollama_mark_cancelled($pdo, $jobId, $context, $logger);
        }

        $errorMessage = sv_sanitize_error_message($e->getMessage(), 240);
        if ($mode === 'quality' && $imageLoadError !== null) {
            $errorMessage = 'invalid_image: ' . $errorMessage;
        }
        $errorCode = sv_ollama_error_code_for_message($errorMessage, $imageLoadError);
        $attempts++;
        $payload['attempts'] = $attempts;
        $payload['last_error_at'] = date('c');
        $payload['last_error'] = $errorMessage;
        sv_update_job_checkpoint_payload($pdo, $jobId, $payload);

        sv_ollama_touch_job_progress($pdo, $jobId, $progressBits, $progressTotal, $errorCode);

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
        sv_ollama_update_job_columns($pdo, $jobId, [
            'last_error_code' => $errorCode,
            'heartbeat_at' => date('c'),
        ], true);

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

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
const SV_OLLAMA_STAGE_VERSION        = 'stage2_v1';

function sv_ollama_job_types(): array
{
    return [SV_JOB_TYPE_OLLAMA_CAPTION, SV_JOB_TYPE_OLLAMA_TITLE, SV_JOB_TYPE_OLLAMA_PROMPT_EVAL];
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

function sv_ollama_persist_media_meta(PDO $pdo, int $mediaId, string $mode, array $values): void
{
    $source = 'ollama';
    $lastRunAt = $values['last_run_at'] ?? date('c');

    if ($mode === 'caption' && isset($values['caption']) && $values['caption'] !== null) {
        sv_set_media_meta_value($pdo, $mediaId, 'ollama.caption', $values['caption'], $source);
    }
    if ($mode === 'title' && isset($values['title']) && $values['title'] !== null) {
        sv_set_media_meta_value($pdo, $mediaId, 'ollama.title', $values['title'], $source);
    }
    if ($mode === 'prompt_eval' && isset($values['score']) && $values['score'] !== null) {
        sv_set_media_meta_value($pdo, $mediaId, 'ollama.prompt_eval.score', $values['score'], $source);
    }

    sv_set_media_meta_value($pdo, $mediaId, 'ollama.last_run_at', $lastRunAt, $source);
    sv_set_media_meta_value($pdo, $mediaId, 'ollama.stage_version', SV_OLLAMA_STAGE_VERSION, $source);

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

    try {
        if (!$ollamaCfg['enabled']) {
            throw new RuntimeException('Ollama ist deaktiviert.');
        }

        $imageData = sv_ollama_load_image_source($pdo, $config, $mediaId, $payload);
        $imageBase64 = $imageData['base64'] ?? null;
        if (!is_string($imageBase64) || $imageBase64 === '') {
            throw new RuntimeException('Bilddaten fehlen.');
        }

        $promptData = sv_ollama_build_prompt($mode, $config, $payload);
        $prompt = $promptData['prompt'];

        $options = isset($payload['options']) && is_array($payload['options']) ? $payload['options'] : [];
        if (isset($payload['model']) && is_string($payload['model']) && trim($payload['model']) !== '') {
            $options['model'] = trim($payload['model']);
        }
        if (!isset($options['model']) || trim((string)$options['model']) === '') {
            $options['model'] = $ollamaCfg['model']['vision'] ?? $ollamaCfg['model_default'];
        }
        if (!isset($options['timeout_ms'])) {
            $options['timeout_ms'] = $ollamaCfg['timeout_ms'];
        }
        if (!array_key_exists('deterministic', $options)) {
            $options['deterministic'] = $ollamaCfg['deterministic']['enabled'] ?? true;
        }

        $response = sv_ollama_analyze_image($config, $imageBase64, $prompt, $options);
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
        if (is_array($responseJson)) {
            $title = sv_ollama_normalize_text_value($responseJson['title'] ?? null);
            $caption = sv_ollama_normalize_text_value($responseJson['caption'] ?? ($responseJson['description'] ?? null));
            $score = sv_ollama_normalize_score($responseJson['score'] ?? ($responseJson['prompt_score'] ?? ($responseJson['quality_score'] ?? null)));
            $contradictions = sv_ollama_normalize_list_value($responseJson['contradictions'] ?? null);
            $missing = sv_ollama_normalize_list_value($responseJson['missing'] ?? null);
            $rationale = sv_ollama_normalize_text_value($responseJson['rationale'] ?? ($responseJson['reason'] ?? null));
        }

        $rawJson = null;
        if (is_array($responseJson)) {
            $rawJson = json_encode($responseJson, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($rawJson === false) {
                $rawJson = null;
            }
        }

        $meta = [
            'job_id' => $jobId,
            'job_type' => $jobType,
            'mode' => $mode,
            'prompt_id' => $promptData['prompt_id'],
            'latency_ms' => $response['latency_ms'] ?? null,
            'usage' => $response['usage'] ?? null,
            'parse_error' => $parseError,
            'image_source' => $imageData['source'] ?? null,
        ];
        $metaJson = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($metaJson === false) {
            $metaJson = null;
        }

        $resultId = sv_ollama_insert_result($pdo, [
            'media_id' => $mediaId,
            'mode' => $mode,
            'model' => (string)($response['model'] ?? $options['model']),
            'title' => $title,
            'caption' => $caption,
            'score' => $score,
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

        $metaSnapshot = sv_ollama_build_meta_snapshot(
            $resultId,
            (string)($response['model'] ?? $options['model']),
            $parseError,
            $contradictions,
            $missing,
            $rationale
        );

        sv_ollama_persist_media_meta($pdo, $mediaId, $mode, [
            'caption' => $caption,
            'title' => $title,
            'score' => $score,
            'last_run_at' => $lastSuccessAt,
            'meta' => $metaSnapshot,
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

<?php
declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/ollama_client.php';
require_once __DIR__ . '/ollama_prompts.php';

const SV_JOB_TYPE_OLLAMA_CAPTION = 'ollama_caption';
const SV_JOB_TYPE_OLLAMA_TITLE   = 'ollama_title';
const SV_OLLAMA_META_SOURCE      = 'ollama';
const SV_OLLAMA_STAGE_VERSION    = 'stage1_v1';

function sv_ollama_job_types(): array
{
    return [SV_JOB_TYPE_OLLAMA_CAPTION, SV_JOB_TYPE_OLLAMA_TITLE];
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

function sv_ollama_job_meta_key(string $jobType): string
{
    if ($jobType === SV_JOB_TYPE_OLLAMA_CAPTION) {
        return 'ollama.caption';
    }
    if ($jobType === SV_JOB_TYPE_OLLAMA_TITLE) {
        return 'ollama.title';
    }

    throw new InvalidArgumentException('Unbekannter Ollama-Jobtyp: ' . $jobType);
}

function sv_ollama_log_jsonl(array $config, string $filename, array $payload): void
{
    sv_write_jsonl_log($config, $filename, $payload);
}

function sv_enqueue_ollama_job(PDO $pdo, array $config, int $mediaId, string $jobType, array $payload, callable $logger): array
{
    $jobType = trim($jobType);
    if (!in_array($jobType, sv_ollama_job_types(), true)) {
        throw new InvalidArgumentException('Unbekannter Ollama-Jobtyp: ' . $jobType);
    }

    if ($mediaId <= 0) {
        throw new InvalidArgumentException('Ungültige Media-ID für Ollama-Job.');
    }

    $existing = $pdo->prepare(
        'SELECT id FROM jobs WHERE media_id = :media_id AND type = :type AND status IN ("queued","running") ORDER BY id DESC LIMIT 1'
    );
    $existing->execute([
        ':media_id' => $mediaId,
        ':type' => $jobType,
    ]);
    $presentId = $existing->fetchColumn();
    if ($presentId) {
        $logger('Ollama-Job existiert bereits (#' . (int)$presentId . ', queued/running).');
        return [
            'job_id' => (int)$presentId,
            'deduped' => true,
        ];
    }

    sv_enforce_job_queue_capacity($pdo, $config, $jobType, $mediaId);

    $now = date('c');
    $payload = array_merge([
        'job_type' => $jobType,
        'media_id' => $mediaId,
        'created_at' => $now,
    ], $payload);

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
    $logger('Ollama-Job angelegt: ID=' . $jobId . ' (Media ' . $mediaId . ', ' . $jobType . ')');

    sv_audit_log($pdo, 'ollama_enqueue', 'jobs', $jobId, [
        'media_id' => $mediaId,
        'job_type' => $jobType,
    ]);

    sv_ollama_log_jsonl($config, 'ollama_jobs.jsonl', [
        'ts' => $now,
        'event' => 'enqueue',
        'job_id' => $jobId,
        'media_id' => $mediaId,
        'job_type' => $jobType,
    ]);

    return [
        'job_id' => $jobId,
        'deduped' => false,
    ];
}

function sv_process_ollama_job_batch(PDO $pdo, array $config, ?int $limit, callable $logger, ?int $mediaId = null): array
{
    $jobTypes = sv_ollama_job_types();
    sv_mark_stuck_jobs($pdo, $jobTypes, SV_JOB_STUCK_MINUTES, $logger);

    $limit = $limit !== null ? (int)$limit : 5;
    if ($limit <= 0) {
        $limit = 5;
    }

    $placeholders = implode(',', array_fill(0, count($jobTypes), '?'));
    $params = $jobTypes;
    $sql = 'SELECT id, media_id, type, status, created_at, updated_at, forge_request_json';
    if (sv_jobs_supports_payload_json($pdo)) {
        $sql .= ', payload_json';
    }
    $sql .= ' FROM jobs WHERE type IN (' . $placeholders . ') AND status IN ("queued","running")';
    if ($mediaId !== null && $mediaId > 0) {
        $sql .= ' AND media_id = ?';
        $params[] = $mediaId;
    }
    $sql .= ' ORDER BY id ASC LIMIT ' . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $summary = [
        'total' => 0,
        'done' => 0,
        'error' => 0,
        'skipped' => 0,
    ];

    foreach ($rows as $row) {
        $summary['total']++;
        $result = sv_process_ollama_job($pdo, $config, $row, $logger);
        if (($result['status'] ?? null) === 'done') {
            $summary['done']++;
            if (!empty($result['skipped'])) {
                $summary['skipped']++;
            }
        } elseif (($result['status'] ?? null) === 'error') {
            $summary['error']++;
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
    $payload['last_started_at'] = date('c');
    sv_update_job_checkpoint_payload($pdo, $jobId, $payload);

    sv_update_job_status($pdo, $jobId, 'running', json_encode([
        'job_type' => $jobType,
        'media_id' => $mediaId,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    sv_audit_log($pdo, 'ollama_start', 'jobs', $jobId, [
        'media_id' => $mediaId,
        'job_type' => $jobType,
    ]);

    sv_ollama_log_jsonl($config, 'ollama_jobs.jsonl', [
        'ts' => date('c'),
        'event' => 'start',
        'job_id' => $jobId,
        'media_id' => $mediaId,
        'job_type' => $jobType,
    ]);

    try {
        $stmt = $pdo->prepare('SELECT id, path, file_size FROM media WHERE id = :id');
        $stmt->execute([':id' => $mediaId]);
        $mediaRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$mediaRow) {
            throw new RuntimeException('Media-Eintrag nicht gefunden.');
        }

        $path = (string)($mediaRow['path'] ?? '');
        if ($path === '' || !is_file($path)) {
            throw new RuntimeException('Media-Datei fehlt: ' . sv_safe_path_label($path));
        }

        $pathsCfg = $config['paths'] ?? [];
        sv_assert_media_path_allowed($path, $pathsCfg, 'ollama_job');

        $fileSize = isset($mediaRow['file_size']) ? (int)$mediaRow['file_size'] : (int)@filesize($path);
        $ollamaCfg = sv_ollama_config($config);
        $maxImageBytes = (int)$ollamaCfg['max_image_bytes'];
        if ($maxImageBytes > 0 && $fileSize > $maxImageBytes) {
            throw new RuntimeException('Bildgröße zu groß (' . $fileSize . ' > ' . $maxImageBytes . ' Bytes).');
        }

        $rawImage = @file_get_contents($path);
        if ($rawImage === false) {
            throw new RuntimeException('Bilddatei konnte nicht gelesen werden.');
        }

        $imageBase64 = base64_encode($rawImage);

        $promptData = sv_ollama_build_prompt($jobType);
        $prompt = $promptData['prompt'];
        $promptId = $promptData['prompt_id'];
        $outputKey = $promptData['output_key'];

        $options = [
            'model' => $ollamaCfg['model']['vision'] ?? $ollamaCfg['model']['default'],
            'timeout_ms' => $ollamaCfg['timeout_ms'],
            'deterministic' => $ollamaCfg['deterministic']['enabled'] ?? true,
        ];

        $response = sv_ollama_analyze_image($config, $imageBase64, $prompt, $options);
        if (empty($response['ok'])) {
            $error = isset($response['error']) ? (string)$response['error'] : 'Ollama-Request fehlgeschlagen.';
            throw new RuntimeException($error);
        }

        $responseJson = $response['response_json'] ?? null;
        if (!is_array($responseJson)) {
            throw new RuntimeException('Ollama-Antwort fehlt oder ungültig.');
        }

        if (!array_key_exists($outputKey, $responseJson) || trim((string)$responseJson[$outputKey]) === '') {
            throw new RuntimeException('Ollama-Antwort enthält kein Feld "' . $outputKey . '".');
        }
        $confidence = $responseJson['confidence'] ?? null;
        if (!is_numeric($confidence)) {
            throw new RuntimeException('Ollama-Antwort enthält keine gültige Confidence.');
        }
        $confidence = (float)$confidence;
        if ($confidence < 0 || $confidence > 1) {
            throw new RuntimeException('Ollama-Confidence außerhalb 0..1.');
        }

        $metaKey = sv_ollama_job_meta_key($jobType);
        $force = !empty($payload['force']);
        $metaCheck = $pdo->prepare('SELECT 1 FROM media_meta WHERE media_id = :media_id AND meta_key = :meta_key LIMIT 1');
        $metaCheck->execute([
            ':media_id' => $mediaId,
            ':meta_key' => $metaKey,
        ]);
        $exists = (bool)$metaCheck->fetchColumn();

        if ($exists && !$force) {
            $payload['last_skipped_at'] = date('c');
            sv_update_job_checkpoint_payload($pdo, $jobId, $payload);

            $logger('Ollama-Job übersprungen (Meta existiert): ' . $metaKey . ' für Media ' . $mediaId . '.');
            sv_update_job_status($pdo, $jobId, 'done', json_encode([
                'job_type' => $jobType,
                'media_id' => $mediaId,
                'skipped' => true,
                'meta_key' => $metaKey,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            sv_audit_log($pdo, 'ollama_success', 'jobs', $jobId, [
                'media_id' => $mediaId,
                'job_type' => $jobType,
                'skipped' => true,
                'meta_key' => $metaKey,
            ]);

            sv_ollama_log_jsonl($config, 'ollama_jobs.jsonl', [
                'ts' => date('c'),
                'event' => 'success',
                'job_id' => $jobId,
                'media_id' => $mediaId,
                'job_type' => $jobType,
                'meta_key' => $metaKey,
                'skipped' => true,
            ]);

            return [
                'job_id' => $jobId,
                'media_id' => $mediaId,
                'job_type' => $jobType,
                'status' => 'done',
                'skipped' => true,
            ];
        }

        $resultJson = json_encode([
            $outputKey => (string)$responseJson[$outputKey],
            'confidence' => $confidence,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($resultJson === false) {
            throw new RuntimeException('Ollama-Ergebnis konnte nicht serialisiert werden.');
        }

        sv_set_media_meta_value($pdo, $mediaId, $metaKey, $resultJson, SV_OLLAMA_META_SOURCE);
        sv_set_media_meta_value($pdo, $mediaId, 'ollama.model', (string)($response['model'] ?? $options['model']), SV_OLLAMA_META_SOURCE);
        sv_set_media_meta_value($pdo, $mediaId, 'ollama.version', SV_OLLAMA_STAGE_VERSION, SV_OLLAMA_META_SOURCE);
        sv_set_media_meta_value($pdo, $mediaId, 'ollama.last_run_at', date('c'), SV_OLLAMA_META_SOURCE);

        $payload['last_success_at'] = date('c');
        sv_update_job_checkpoint_payload($pdo, $jobId, $payload);

        $responseLog = sv_ollama_truncate_for_log($resultJson, 300);
        $promptLog = sv_ollama_truncate_for_log($prompt, 200);

        sv_update_job_status($pdo, $jobId, 'done', json_encode([
            'job_type' => $jobType,
            'media_id' => $mediaId,
            'meta_key' => $metaKey,
            'prompt_id' => $promptId,
            'model' => $response['model'] ?? $options['model'],
            'confidence' => $confidence,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        sv_audit_log($pdo, 'ollama_success', 'jobs', $jobId, [
            'media_id' => $mediaId,
            'job_type' => $jobType,
            'meta_key' => $metaKey,
            'model' => $response['model'] ?? $options['model'],
        ]);

        sv_ollama_log_jsonl($config, 'ollama_jobs.jsonl', [
            'ts' => date('c'),
            'event' => 'success',
            'job_id' => $jobId,
            'media_id' => $mediaId,
            'job_type' => $jobType,
            'meta_key' => $metaKey,
            'prompt_id' => $promptId,
            'prompt_preview' => $promptLog,
            'response_preview' => $responseLog,
            'model' => $response['model'] ?? $options['model'],
        ]);

        return [
            'job_id' => $jobId,
            'media_id' => $mediaId,
            'job_type' => $jobType,
            'status' => 'done',
            'meta_key' => $metaKey,
        ];
    } catch (Throwable $e) {
        $errorMessage = sv_sanitize_error_message($e->getMessage(), 240);
        sv_update_job_status($pdo, $jobId, 'error', null, $errorMessage);

        sv_audit_log($pdo, 'ollama_error', 'jobs', $jobId, [
            'media_id' => $mediaId,
            'job_type' => $jobType,
            'error' => $errorMessage,
        ]);

        sv_ollama_log_jsonl($config, 'ollama_jobs.jsonl', [
            'ts' => date('c'),
            'event' => 'error',
            'job_id' => $jobId,
            'media_id' => $mediaId,
            'job_type' => $jobType,
            'error' => $errorMessage,
        ]);

        sv_ollama_log_jsonl($config, 'ollama_errors.jsonl', [
            'ts' => date('c'),
            'event' => 'error',
            'job_id' => $jobId,
            'media_id' => $mediaId,
            'job_type' => $jobType,
            'error' => $errorMessage,
        ]);

        $logger('Ollama-Job Fehler: ' . $errorMessage);

        $payload['last_error_at'] = date('c');
        $payload['last_error'] = $errorMessage;
        sv_update_job_checkpoint_payload($pdo, $jobId, $payload);

        return [
            'job_id' => $jobId,
            'media_id' => $mediaId,
            'job_type' => $jobType,
            'status' => 'error',
            'error' => $errorMessage,
        ];
    }
}

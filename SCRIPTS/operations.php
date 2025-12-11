<?php
declare(strict_types=1);

// Zentrale Operationsbibliothek für Web- und CLI-Aufrufer.

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/scan_core.php';
require_once __DIR__ . '/security.php';

const SV_FORGE_JOB_TYPE           = 'forge_regen';
const SV_FORGE_DEFAULT_BASE_URL   = 'http://127.0.0.1:7861/';
const SV_FORGE_MODEL_LIST_PATH    = '/sdapi/v1/sd-models';
const SV_FORGE_FALLBACK_MODEL     = 'SDXL_FP16_waiNSFWIllustrious_v120.safetensors';
const SV_FORGE_MAX_TAGS_PROMPT    = 8;
const SV_FORGE_SCAN_SOURCE_LABEL  = 'forge_regen_replace';

const SV_JOB_STATUS_CANCELED      = 'canceled';

function sv_load_config(?string $baseDir = null): array
{
    $baseDir = $baseDir ?? sv_base_dir();
    $configFile = $baseDir . '/CONFIG/config.php';
    if (!is_file($configFile)) {
        throw new RuntimeException('CONFIG/config.php fehlt.');
    }

    $config = require $configFile;
    if (!is_array($config)) {
        throw new RuntimeException('CONFIG/config.php liefert keine Konfiguration.');
    }

    return $config;
}

function sv_open_pdo(array $config): PDO
{
    $dsn      = $config['db']['dsn'] ?? null;
    $user     = $config['db']['user'] ?? null;
    $password = $config['db']['password'] ?? null;
    $options  = $config['db']['options'] ?? [];

    if (!is_string($dsn) || $dsn === '') {
        throw new RuntimeException('DB-DSN fehlt.');
    }

    $pdo = new PDO($dsn, $user, $password, $options);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

function sv_prompt_core_complete_condition(string $alias = 'p'): string
{
    $alias = preg_replace('~[^a-zA-Z0-9_]+~', '', $alias);
    $alias = $alias === '' ? 'p' : $alias;

    return implode(' AND ', [
        "{$alias}.prompt IS NOT NULL",
        "TRIM({$alias}.prompt) <> ''",
        "{$alias}.negative_prompt IS NOT NULL",
        "{$alias}.source_metadata IS NOT NULL",
        "{$alias}.model IS NOT NULL",
        "{$alias}.sampler IS NOT NULL",
        "{$alias}.cfg_scale IS NOT NULL",
        "{$alias}.steps IS NOT NULL",
        "{$alias}.seed IS NOT NULL",
        "{$alias}.width IS NOT NULL",
        "{$alias}.height IS NOT NULL",
        "{$alias}.scheduler IS NOT NULL",
    ]);
}

function sv_load_media_with_prompt(PDO $pdo, int $mediaId): array
{
    $stmt = $pdo->prepare(
        'SELECT m.*, p.id AS prompt_id, p.prompt, p.negative_prompt, p.model, p.sampler, p.cfg_scale, p.steps, '
        . 'p.seed, p.width, p.height, p.scheduler, p.sampler_settings, p.loras, p.controlnet, p.source_metadata '
        . 'FROM media m '
        . 'LEFT JOIN prompts p ON p.id = (SELECT p2.id FROM prompts p2 WHERE p2.media_id = m.id ORDER BY p2.id DESC LIMIT 1) '
        . 'WHERE m.id = :id'
    );
    $stmt->execute([':id' => $mediaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new InvalidArgumentException('Media-Eintrag nicht gefunden.');
    }

    return $row;
}

function sv_validate_forge_prompt_payload(array $mediaRow): array
{
    $type = (string)($mediaRow['type'] ?? '');
    if ($type !== 'image') {
        throw new InvalidArgumentException('Nur Bildmedien können regeneriert werden.');
    }

    $promptId = $mediaRow['prompt_id'] ?? null;
    if ($promptId === null) {
        throw new InvalidArgumentException('Kein Prompt für dieses Medium gefunden.');
    }

    $requiredFields = [
        'prompt',
        'negative_prompt',
        'model',
        'sampler',
        'cfg_scale',
        'steps',
        'seed',
        'width',
        'height',
    ];

    foreach ($requiredFields as $field) {
        if (!isset($mediaRow[$field])) {
            throw new InvalidArgumentException('Prompteintrag unvollständig: Feld fehlt: ' . $field);
        }
        if (is_string($mediaRow[$field]) && trim($mediaRow[$field]) === '') {
            throw new InvalidArgumentException('Prompteintrag unvollständig: Feld leer: ' . $field);
        }
    }

    $payload = [
        'prompt'          => (string)$mediaRow['prompt'],
        'negative_prompt' => (string)$mediaRow['negative_prompt'],
        'width'           => (int)$mediaRow['width'],
        'height'          => (int)$mediaRow['height'],
        'steps'           => (int)$mediaRow['steps'],
        'cfg_scale'       => (float)$mediaRow['cfg_scale'],
        'seed'            => (string)$mediaRow['seed'],
        'model'           => (string)$mediaRow['model'],
        'sampler'         => (string)$mediaRow['sampler'],
    ];

    if (!empty($mediaRow['scheduler'])) {
        $payload['scheduler'] = (string)$mediaRow['scheduler'];
    }
    if (!empty($mediaRow['sampler_settings'])) {
        $payload['sampler_settings'] = json_decode((string)$mediaRow['sampler_settings'], true) ?? (string)$mediaRow['sampler_settings'];
    }
    if (!empty($mediaRow['loras'])) {
        $payload['loras'] = json_decode((string)$mediaRow['loras'], true) ?? (string)$mediaRow['loras'];
    }
    if (!empty($mediaRow['controlnet'])) {
        $payload['controlnet'] = json_decode((string)$mediaRow['controlnet'], true) ?? (string)$mediaRow['controlnet'];
    }
    if (!empty($mediaRow['source_metadata'])) {
        $payload['source_metadata'] = (string)$mediaRow['source_metadata'];
    }

    return [
        'payload'   => $payload,
        'prompt_id' => (int)$promptId,
    ];
}

function sv_create_forge_job(PDO $pdo, int $mediaId, array $payload, ?int $promptId, callable $logger): int
{
    $now = date('c');
    $requestJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $stmt = $pdo->prepare(
        'INSERT INTO jobs (media_id, prompt_id, type, status, created_at, updated_at, forge_request_json) '
        . 'VALUES (:media_id, :prompt_id, :type, :status, :created_at, :updated_at, :forge_request_json)'
    );
    $stmt->execute([
        ':media_id'          => $mediaId,
        ':prompt_id'         => $promptId,
        ':type'              => SV_FORGE_JOB_TYPE,
        ':status'            => 'queued',
        ':created_at'        => $now,
        ':updated_at'        => $now,
        ':forge_request_json'=> $requestJson,
    ]);

    $jobId = (int)$pdo->lastInsertId();
    $logger('Forge-Job angelegt: ID=' . $jobId . ' (Status queued)');
    sv_audit_log($pdo, 'forge_job_created', 'jobs', $jobId, [
        'media_id'   => $mediaId,
        'prompt_id'  => $promptId,
        'job_type'   => SV_FORGE_JOB_TYPE,
        'status'     => 'queued',
    ]);

    return $jobId;
}

function sv_forge_endpoint_config(array $config): ?array
{
    if (!isset($config['forge']) || !is_array($config['forge'])) {
        return null;
    }

    $forge = $config['forge'];
    $baseUrl = isset($forge['base_url']) && is_string($forge['base_url']) ? trim($forge['base_url']) : '';
    $token   = isset($forge['token']) && is_string($forge['token']) ? trim($forge['token']) : '';
    $timeout = isset($forge['timeout']) ? (int)$forge['timeout'] : 15;

    if ($baseUrl === '' || $token === '') {
        return null;
    }

    return [
        'base_url' => $baseUrl,
        'token'    => $token,
        'timeout'  => $timeout > 0 ? $timeout : 15,
    ];
}

function sv_forge_base_url(array $config): string
{
    if (isset($config['forge']) && is_array($config['forge'])) {
        $candidate = trim((string)($config['forge']['base_url'] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return SV_FORGE_DEFAULT_BASE_URL;
}

function sv_forge_fetch_model_list(array $config, callable $logger): ?array
{
    $baseUrl = rtrim(sv_forge_base_url($config), '/');
    $url     = $baseUrl . SV_FORGE_MODEL_LIST_PATH;

    $timeout = 15;
    if (isset($config['forge']) && is_array($config['forge'])) {
        $timeoutCfg = (int)($config['forge']['timeout'] ?? 15);
        $timeout    = $timeoutCfg > 0 ? $timeoutCfg : 15;
    }

    $headers = ['Accept: application/json'];
    $token   = isset($config['forge']['token']) && is_string($config['forge']['token'])
        ? trim($config['forge']['token'])
        : '';
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $context = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => implode("\r\n", $headers),
            'timeout' => $timeout,
        ],
    ]);

    try {
        $responseBody = @file_get_contents($url, false, $context);
    } catch (Throwable $e) {
        $logger('Forge-Modelliste konnte nicht geladen werden: ' . $e->getMessage());
        return null;
    }

    if ($responseBody === false) {
        $logger('Forge-Modelliste konnte nicht geladen werden (HTTP-Fehler).');
        return null;
    }

    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        $logger('Forge-Modelliste ungültig oder leer.');
        return null;
    }

    return $decoded;
}

function sv_resolve_forge_model(array $config, ?string $requestedModelName, callable $logger): string
{
    $requested = trim((string)$requestedModelName);
    $models    = sv_forge_fetch_model_list($config, $logger);

    if ($models === null || $models === []) {
        if ($requested === '') {
            $logger('Forge model fallback used (no request, no model list).');
        } else {
            $logger('Forge model fallback used (requested=' . $requested . ', no model list).');
        }

        return SV_FORGE_FALLBACK_MODEL;
    }

    if ($requested === '') {
        $logger('Forge model fallback used (no model specified).');
        return SV_FORGE_FALLBACK_MODEL;
    }

    $requestedLower = strtolower($requested);

    foreach ($models as $model) {
        if (!is_array($model)) {
            continue;
        }

        $candidates = [];
        foreach (['model_name', 'title', 'filename', 'name'] as $field) {
            if (isset($model[$field]) && is_string($model[$field])) {
                $candidates[] = trim((string)$model[$field]);
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $candidateLower = strtolower($candidate);
            if ($candidateLower === $requestedLower
                || str_contains($candidateLower, $requestedLower)
                || str_contains($requestedLower, $candidateLower)
            ) {
                if ($candidateLower !== $requestedLower) {
                    $logger('Forge model resolved via fuzzy match: requested=' . $requested . ', used=' . $candidate . '.');
                }
                return $candidate;
            }
        }
    }

    $logger('Forge model fallback used (requested=' . $requested . ', fallback=' . SV_FORGE_FALLBACK_MODEL . ').');
    return SV_FORGE_FALLBACK_MODEL;
}

function sv_dispatch_forge_job(PDO $pdo, array $config, int $jobId, array $payload, callable $logger): array
{
    $endpoint = sv_forge_endpoint_config($config);
    if ($endpoint === null) {
        $logger('Forge-Dispatch übersprungen: keine gültige Forge-Konfiguration.');
        return [
            'dispatched' => false,
            'status'     => 'queued',
            'message'    => 'Forge-Konfiguration fehlt oder ist unvollständig.',
        ];
    }

    $url      = rtrim($endpoint['base_url'], '/');
    $timeout  = $endpoint['timeout'];
    $headers  = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $endpoint['token'],
    ];

    $logger('Sende Forge-Request für Job ID ' . $jobId . ' an ' . $url);

    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => implode("\r\n", $headers),
            'content' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'timeout' => $timeout,
        ],
    ]);

    $responseBody = @file_get_contents($url, false, $context);
    $httpCode     = null;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            if (preg_match('~^HTTP/[^ ]+ ([0-9]{3})~', (string)$headerLine, $matches)) {
                $httpCode = (int)$matches[1];
                break;
            }
        }
    }

    $now = date('c');
    $responseJson = $responseBody !== false ? $responseBody : null;

    if ($httpCode !== null && $httpCode >= 200 && $httpCode < 300 && $responseBody !== false) {
        $status = 'running';
        $logger('Forge-Dispatch erfolgreich, Status auf running gesetzt.');
        $update = $pdo->prepare(
            'UPDATE jobs SET status = :status, forge_response_json = :response, updated_at = :updated_at WHERE id = :id'
        );
        $update->execute([
            ':status'     => $status,
            ':response'   => $responseJson,
            ':updated_at' => $now,
            ':id'         => $jobId,
        ]);

        sv_audit_log($pdo, 'forge_job_dispatched', 'jobs', $jobId, [
            'status'      => $status,
            'http_code'   => $httpCode,
        ]);

        return [
            'dispatched' => true,
            'status'     => $status,
            'response'   => $responseJson,
        ];
    }

    $status = 'queued';
    $error  = 'Forge-Dispatch fehlgeschlagen';
    if ($httpCode !== null) {
        $error .= ' (HTTP ' . $httpCode . ')';
    }
    $logger($error);

    $update = $pdo->prepare(
        'UPDATE jobs SET status = :status, forge_response_json = :response, error_message = :error, updated_at = :updated_at '
        . 'WHERE id = :id'
    );
    $update->execute([
        ':status'     => $status,
        ':response'   => $responseJson,
        ':error'      => $error,
        ':updated_at' => $now,
        ':id'         => $jobId,
    ]);

    sv_audit_log($pdo, 'forge_job_dispatch_failed', 'jobs', $jobId, [
        'status'    => $status,
        'http_code' => $httpCode,
        'error'     => $error,
    ]);

    return [
        'dispatched' => false,
        'status'     => $status,
        'error'      => $error,
        'response'   => $responseJson,
    ];
}

function sv_prepare_forge_regen_job(PDO $pdo, array $config, int $mediaId, callable $logger): array
{
    $mediaRow = sv_load_media_with_prompt($pdo, $mediaId);
    $type     = (string)($mediaRow['type'] ?? '');
    $status   = (string)($mediaRow['status'] ?? '');

    if ($type !== 'image') {
        throw new InvalidArgumentException('Nur Bildmedien können regeneriert werden.');
    }
    if ($status === 'missing') {
        throw new RuntimeException('Regeneration für fehlende Dateien nicht möglich.');
    }

    $path = (string)($mediaRow['path'] ?? '');
    if ($path === '' || !is_file($path)) {
        throw new RuntimeException('Dateipfad nicht vorhanden.');
    }

    $pathsCfg = $config['paths'] ?? [];
    sv_assert_media_path_allowed($path, $pathsCfg, 'forge_regen_replace');

    $tags       = sv_fetch_media_tags_for_regen($pdo, $mediaId, SV_FORGE_MAX_TAGS_PROMPT);
    $regenPlan  = sv_prepare_forge_regen_prompt($mediaRow, $tags, $logger);
    $validation = sv_validate_forge_prompt_payload($mediaRow);

    $payload    = $validation['payload'];
    $promptId   = $validation['prompt_id'];
    $payload['prompt'] = $regenPlan['final_prompt'];

    $requestedModel = (string)($payload['model'] ?? '');
    $resolvedModel  = sv_resolve_forge_model($config, $requestedModel, $logger);
    $payload['model'] = $resolvedModel;
    $payload['_sv_requested_model'] = $requestedModel;

    $payload['_sv_regen_plan'] = [
        'category'       => $regenPlan['category'],
        'final_prompt'   => $regenPlan['final_prompt'],
        'fallback_used'  => $regenPlan['fallback_used'],
        'tag_prompt_used'=> $regenPlan['tag_prompt_used'],
        'original_prompt'=> $regenPlan['original_prompt'] ?? null,
    ];

    return [
        'payload'         => $payload,
        'prompt_id'       => $promptId,
        'regen_plan'      => $regenPlan,
        'requested_model' => $requestedModel,
        'resolved_model'  => $resolvedModel,
        'media_row'       => $mediaRow,
        'path'            => $path,
    ];
}

function sv_queue_forge_regeneration(PDO $pdo, array $config, int $mediaId, callable $logger): array
{
    $jobData = sv_prepare_forge_regen_job($pdo, $config, $mediaId, $logger);

    $jobId = sv_create_forge_job(
        $pdo,
        $mediaId,
        $jobData['payload'],
        $jobData['prompt_id'],
        $logger
    );

    if ($jobData['resolved_model'] !== $jobData['requested_model']) {
        $logger('Forge-Modell in Job #' . $jobId . ' angepasst: requested=' . $jobData['requested_model'] . ', used=' . $jobData['resolved_model'] . '.');
        sv_audit_log($pdo, 'forge_model_resolved', 'jobs', $jobId, [
            'requested_model' => $jobData['requested_model'],
            'resolved_model'  => $jobData['resolved_model'],
            'fallback_used'   => $jobData['resolved_model'] === SV_FORGE_FALLBACK_MODEL,
        ]);
    }

    return [
        'job_id'          => $jobId,
        'status'          => 'queued',
        'resolved_model'  => $jobData['resolved_model'],
        'requested_model' => $jobData['requested_model'],
        'regen_plan'      => $jobData['regen_plan'],
    ];
}

function sv_forge_job_overview(PDO $pdo): array
{
    $stmt = $pdo->prepare('SELECT status, COUNT(*) AS cnt FROM jobs WHERE type = :type GROUP BY status');
    $stmt->execute([':type' => SV_FORGE_JOB_TYPE]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $byStatus = [];
    foreach ($rows as $row) {
        $status = (string)($row['status'] ?? '');
        $byStatus[$status] = (int)$row['cnt'];
    }

    $openStatuses = ['queued', 'pending', 'running'];
    $openCount = 0;
    foreach ($openStatuses as $status) {
        $openCount += $byStatus[$status] ?? 0;
    }

    return [
        'by_status' => $byStatus,
        'open'      => $openCount,
        'done'      => $byStatus['done'] ?? 0,
        'error'     => $byStatus['error'] ?? 0,
    ];
}

function sv_job_summary_from_row(array $row): string
{
    $payload  = json_decode((string)($row['forge_request_json'] ?? ''), true);
    $response = json_decode((string)($row['forge_response_json'] ?? ''), true);

    $parts = [];
    if (is_array($payload) && isset($payload['model'])) {
        $parts[] = 'Modell: ' . (string)$payload['model'];
    }
    if (is_array($response) && isset($response['error'])) {
        $parts[] = 'Response-Error: ' . trim((string)$response['error']);
    }
    if (isset($row['error_message']) && trim((string)$row['error_message']) !== '') {
        $parts[] = 'Fehler: ' . trim((string)$row['error_message']);
    }

    return $parts === [] ? '' : implode(' | ', $parts);
}

function sv_load_job(PDO $pdo, int $jobId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, media_id, prompt_id, type, status, created_at, updated_at, forge_request_json, forge_response_json, error_message '
        . 'FROM jobs WHERE id = :id'
    );
    $stmt->execute([':id' => $jobId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new InvalidArgumentException('Job nicht gefunden.');
    }

    return $row;
}

function sv_list_jobs(PDO $pdo, array $filters = [], int $limit = 100): array
{
    $limit = max(1, min(250, $limit));

    $where  = [];
    $params = [];

    if (isset($filters['job_type']) && is_string($filters['job_type']) && $filters['job_type'] !== '') {
        $where[]          = 'type = :type';
        $params[':type']  = trim($filters['job_type']);
    }
    if (isset($filters['status']) && is_string($filters['status']) && $filters['status'] !== '') {
        $where[]            = 'status = :status';
        $params[':status']  = trim($filters['status']);
    }
    if (isset($filters['media_id']) && (int)$filters['media_id'] > 0) {
        $where[]              = 'media_id = :media_id';
        $params[':media_id']  = (int)$filters['media_id'];
    }
    if (isset($filters['since']) && $filters['since'] !== null) {
        $since = $filters['since'];
        if (is_numeric($since)) {
            $since = date('c', (int)$since);
        }
        if (is_string($since) && trim($since) !== '') {
            $where[]             = 'created_at >= :since';
            $params[':since']    = $since;
        }
    }

    $sql = 'SELECT id, media_id, prompt_id, type, status, created_at, updated_at, forge_request_json, forge_response_json, error_message '
         . 'FROM jobs';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY id DESC LIMIT :limit';

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $jobs = [];

    foreach ($rows as $row) {
        $jobs[] = [
            'id'         => (int)$row['id'],
            'media_id'   => (int)$row['media_id'],
            'prompt_id'  => isset($row['prompt_id']) ? (int)$row['prompt_id'] : null,
            'type'       => (string)$row['type'],
            'status'     => (string)$row['status'],
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
            'summary'    => sv_job_summary_from_row($row),
        ];
    }

    return $jobs;
}

function sv_requeue_job(PDO $pdo, int $jobId, callable $logger): array
{
    $job = sv_load_job($pdo, $jobId);
    $type   = (string)($job['type'] ?? '');
    $status = (string)($job['status'] ?? '');

    if ($type !== SV_FORGE_JOB_TYPE) {
        throw new InvalidArgumentException('Requeue wird für diesen Job-Typ nicht unterstützt.');
    }
    if (!in_array($status, ['error', 'done', SV_JOB_STATUS_CANCELED], true)) {
        throw new InvalidArgumentException('Requeue nur für abgeschlossene oder fehlerhafte Jobs möglich.');
    }

    $stmt = $pdo->prepare(
        'UPDATE jobs SET status = :status, updated_at = :updated_at, error_message = NULL, forge_response_json = NULL WHERE id = :id'
    );
    $stmt->execute([
        ':status'     => 'queued',
        ':updated_at' => date('c'),
        ':id'         => $jobId,
    ]);

    $logger('Job #' . $jobId . ' erneut eingereiht.');
    sv_audit_log($pdo, 'job_requeue', 'jobs', $jobId, [
        'previous_status' => $status,
        'job_type'        => $type,
    ]);

    return [
        'job_id'  => $jobId,
        'status'  => 'queued',
        'message' => 'Job wurde erneut eingereiht.',
    ];
}

function sv_cancel_job(PDO $pdo, int $jobId, callable $logger): array
{
    $job = sv_load_job($pdo, $jobId);
    $type   = (string)($job['type'] ?? '');
    $status = (string)($job['status'] ?? '');

    if ($type !== SV_FORGE_JOB_TYPE) {
        throw new InvalidArgumentException('Abbruch wird für diesen Job-Typ nicht unterstützt.');
    }
    if (!in_array($status, ['queued', 'running'], true)) {
        throw new InvalidArgumentException('Nur queued/running-Jobs können abgebrochen werden.');
    }

    $stmt = $pdo->prepare(
        'UPDATE jobs SET status = :status, updated_at = :updated_at, error_message = :error WHERE id = :id'
    );
    $stmt->execute([
        ':status'     => SV_JOB_STATUS_CANCELED,
        ':updated_at' => date('c'),
        ':error'      => 'Canceled by operator',
        ':id'         => $jobId,
    ]);

    $logger('Job #' . $jobId . ' abgebrochen.');
    sv_audit_log($pdo, 'job_cancel', 'jobs', $jobId, [
        'previous_status' => $status,
        'job_type'        => $type,
    ]);

    return [
        'job_id'  => $jobId,
        'status'  => SV_JOB_STATUS_CANCELED,
        'message' => 'Job wurde abgebrochen.',
    ];
}

function sv_fetch_media_tags_for_regen(PDO $pdo, int $mediaId, int $limit = 8): array
{
    $limit = max(1, $limit);
    $stmt = $pdo->prepare(
        'SELECT t.name, t.type, t.locked, mt.confidence '
        . 'FROM media_tags mt '
        . 'JOIN tags t ON t.id = mt.tag_id '
        . 'WHERE mt.media_id = :media_id AND mt.confidence IS NOT NULL AND mt.confidence > 0 '
        . 'ORDER BY t.locked DESC, mt.confidence DESC, t.name ASC '
        . 'LIMIT :limit'
    );
    $stmt->bindValue(':media_id', $mediaId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function sv_assess_prompt_quality(string $prompt, array $tags): array
{
    $normalized   = trim($prompt);
    $wordCount    = preg_match_all('/[\p{L}\p{N}]{2,}/u', $normalized, $wordMatches);
    $fragmentsRaw = explode(',', $normalized);
    $fragments    = array_map('trim', $fragmentsRaw);
    $emptyFragments = 0;
    $shortFragments = 0;
    foreach ($fragments as $fragment) {
        if ($fragment === '') {
            $emptyFragments++;
            continue;
        }
        $fragmentWords = preg_match_all('/[\p{L}\p{N}]{2,}/u', $fragment, $fragMatches);
        if ($fragmentWords <= 1 || strlen($fragment) < 8) {
            $shortFragments++;
        }
    }

    $brokenCommaSpacing = preg_match('/\s,\s*/', $prompt) === 1;
    $garbledTokens      = preg_match_all('/[\p{L}]{4,}(?:[^\s\p{L}]+|\d+)/u', $prompt, $garbledMatches);
    $tagSubstance       = min(count($tags), SV_FORGE_MAX_TAGS_PROMPT);

    $score   = 0;
    $reasons = [];

    if ($wordCount >= 12) {
        $score += 2;
    } elseif ($wordCount >= 8) {
        $score += 1;
    } elseif ($wordCount < 6) {
        $score -= 2;
        $reasons[] = 'zu kurz';
    }

    if ($emptyFragments > 0) {
        $score   -= 2;
        $reasons[] = 'leere Fragmente';
    }
    if ($brokenCommaSpacing) {
        $score   -= 1;
        $reasons[] = 'unsaubere Kommastruktur';
    }
    if ($shortFragments >= max(1, (int)floor(count($fragments) / 2))) {
        $score   -= 1;
        $reasons[] = 'kurze Fragmente';
    }
    if ($garbledTokens > 0) {
        $score   -= 2;
        $reasons[] = 'zerhackte Tokens';
    }
    if ($tagSubstance > $wordCount) {
        $score   -= 1;
        $reasons[] = 'Tags haben mehr Substanz als Prompt';
    }

    $category = 'B';
    if ($score >= 2) {
        $category = 'A';
    } elseif ($score <= -1) {
        $category = 'C';
    }

    return [
        'category'        => $category,
        'score'           => $score,
        'word_count'      => $wordCount,
        'fragment_count'  => count($fragments),
        'broken_commas'   => $brokenCommaSpacing,
        'garbled_tokens'  => $garbledTokens,
        'empty_fragments' => $emptyFragments,
        'short_fragments' => $shortFragments,
        'tag_substance'   => $tagSubstance,
        'reasons'         => $reasons,
    ];
}

function sv_prepare_forge_regen_prompt(array $mediaRow, array $tags, callable $logger): array
{
    $originalPrompt = trim((string)($mediaRow['prompt'] ?? ''));
    if ($originalPrompt === '') {
        throw new RuntimeException('Kein Prompt vorhanden.');
    }

    $tagNames = [];
    foreach ($tags as $tag) {
        if (isset($tag['name']) && is_string($tag['name'])) {
            $tagNames[] = trim((string)$tag['name']);
        }
    }

    $assessment = sv_assess_prompt_quality($originalPrompt, $tagNames);
    $category   = $assessment['category'];
    $finalPrompt = $originalPrompt;
    $fallbackUsed = false;
    $tagPromptUsed = false;
    $tagFragment = '';

    if ($category === 'B' && $tagNames !== []) {
        $tagFragment = implode(', ', array_slice($tagNames, 0, 4));
        $finalPrompt = rtrim($originalPrompt, ', ');
        $finalPrompt .= $tagFragment !== '' ? ', ' . $tagFragment : '';
        $fallbackUsed = true;
    } elseif ($category === 'C') {
        if ($tagNames === []) {
            throw new RuntimeException('Prompt zu schwach und keine Tags verfügbar.');
        }
        $tagFragment = implode(', ', array_slice($tagNames, 0, SV_FORGE_MAX_TAGS_PROMPT));
        $finalPrompt = 'Detailed reconstruction, ' . $tagFragment;
        $fallbackUsed = true;
        $tagPromptUsed = true;
    }

    if ($fallbackUsed && $tagFragment !== '') {
        $logger('Prompt-Fallback aktiv (' . $category . '): Tags genutzt -> ' . $tagFragment);
    }

    return [
        'category'        => $category,
        'final_prompt'    => $finalPrompt,
        'original_prompt' => $originalPrompt,
        'fallback_used'   => $fallbackUsed,
        'tag_prompt_used' => $tagPromptUsed,
        'assessment'      => $assessment,
        'tag_fragment'    => $tagFragment,
    ];
}

function sv_store_forge_regen_meta(PDO $pdo, int $mediaId, array $info): void
{
    $insert = $pdo->prepare(
        'INSERT INTO media_meta (media_id, source, meta_key, meta_value, created_at) VALUES (?, ?, ?, ?, ?)'
    );
    $now = date('c');
    foreach ($info as $key => $value) {
        $insert->execute([
            $mediaId,
            SV_FORGE_SCAN_SOURCE_LABEL,
            $key,
            $value,
            $now,
        ]);
    }
}

function sv_backup_media_file(array $config, string $path, callable $logger): string
{
    $baseDir   = sv_base_dir();
    $backupDir = (string)($config['paths']['backups'] ?? ($baseDir . '/BACKUPS'));
    $backupDir = rtrim(str_replace('\\', '/', $backupDir), '/');
    if (!is_dir($backupDir)) {
        if (!mkdir($backupDir, 0777, true) && !is_dir($backupDir)) {
            throw new RuntimeException('Backup-Verzeichnis kann nicht angelegt werden: ' . $backupDir);
        }
    }

    $timestamp  = date('Ymd_His');
    $fileName   = basename($path);
    $backupPath = $backupDir . '/forge_regen_' . $timestamp . '_' . $fileName;

    if (!copy($path, $backupPath)) {
        throw new RuntimeException('Backup fehlgeschlagen: ' . $backupPath);
    }

    $logger('Backup erstellt: ' . $backupPath);

    return $backupPath;
}

function sv_replace_media_file_with_image(array $config, string $targetPath, string $binary, callable $logger): array
{
    $pathsCfg = $config['paths'] ?? [];
    sv_assert_media_path_allowed($targetPath, $pathsCfg, 'forge_regen_replace');

    $tmpDir = isset($pathsCfg['tmp']) && is_string($pathsCfg['tmp']) && trim($pathsCfg['tmp']) !== ''
        ? rtrim(str_replace('\\', '/', (string)$pathsCfg['tmp']), '/')
        : (sv_base_dir() . '/TMP');
    if (!is_dir($tmpDir)) {
        mkdir($tmpDir, 0777, true);
    }

    $tmpFile = tempnam($tmpDir, 'forge_regen_');
    if ($tmpFile === false) {
        throw new RuntimeException('Temporäre Datei konnte nicht angelegt werden.');
    }

    if (file_put_contents($tmpFile, $binary) === false) {
        @unlink($tmpFile);
        throw new RuntimeException('Schreiben der neuen Bilddatei fehlgeschlagen.');
    }

    $imageInfo = @getimagesize($tmpFile);
    if ($imageInfo === false) {
        @unlink($tmpFile);
        throw new RuntimeException('Forge lieferte keine gültige Bilddatei.');
    }

    if (!rename($tmpFile, $targetPath)) {
        @unlink($tmpFile);
        throw new RuntimeException('Ersetzen der Zieldatei fehlgeschlagen.');
    }

    $hash     = @hash_file('md5', $targetPath) ?: null;
    $filesize = @filesize($targetPath) ?: null;

    return [
        'width'    => (int)$imageInfo[0],
        'height'   => (int)$imageInfo[1],
        'hash'     => $hash,
        'filesize' => $filesize === false ? null : (int)$filesize,
    ];
}

function sv_call_forge_sync(array $config, array $payload, callable $logger): array
{
    $endpoint = sv_forge_endpoint_config($config);
    if ($endpoint === null) {
        throw new RuntimeException('Forge-Konfiguration fehlt oder ist unvollständig.');
    }

    $url     = rtrim($endpoint['base_url'], '/');
    $timeout = $endpoint['timeout'];
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $endpoint['token'],
    ];

    $logger('Sende Forge-Request (synchron) an ' . $url);

    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => implode("\r\n", $headers),
            'content' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'timeout' => $timeout,
        ],
    ]);

    $responseBody = @file_get_contents($url, false, $context);
    $httpCode     = null;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            if (preg_match('~^HTTP/[^ ]+ ([0-9]{3})~', (string)$headerLine, $matches)) {
                $httpCode = (int)$matches[1];
                break;
            }
        }
    }

    if ($responseBody === false || $httpCode === null || $httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException('Forge-Request fehlgeschlagen' . ($httpCode !== null ? ' (HTTP ' . $httpCode . ')' : ''));
    }

    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Forge-Antwort ungültig oder leer.');
    }

    $imageBinary = null;
    if (isset($decoded['images']) && is_array($decoded['images']) && isset($decoded['images'][0])) {
        $rawImage = (string)$decoded['images'][0];
        if (str_starts_with($rawImage, 'data:')) {
            $rawImage = preg_replace('~^data:image/[^;]+;base64,~', '', $rawImage) ?? $rawImage;
        }
        $imageBinary = base64_decode($rawImage, true);
    }

    if ($imageBinary === null || $imageBinary === false) {
        throw new RuntimeException('Forge lieferte keine decodierbare Bildantwort.');
    }

    return [
        'binary'         => $imageBinary,
        'response_array' => $decoded,
        'response_json'  => $responseBody,
        'http_code'      => $httpCode,
    ];
}

function sv_refresh_media_after_regen(
    PDO $pdo,
    array $config,
    int $mediaId,
    string $path,
    array $regenPlan,
    array $payload,
    callable $logger
): array {
    $scannerCfg    = $config['scanner'] ?? [];
    $nsfwThreshold = (float)($scannerCfg['nsfw_threshold'] ?? 0.7);
    $result        = [
        'scan_updated'   => false,
        'meta_updated'   => false,
        'prompt_created' => false,
    ];

    $scanData  = sv_scan_with_local_scanner($path, $scannerCfg, $logger);
    $hasNsfw   = (int)($config['default_nsfw'] ?? 0);
    $rating    = 0;
    $scanTags  = [];
    $scanFlags = [];
    $nsfwScore = null;

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

        $delTags = $pdo->prepare('DELETE FROM media_tags WHERE media_id = ?');
        $delTags->execute([$mediaId]);
        sv_store_tags($pdo, $mediaId, $scanTags);
        sv_store_scan_result($pdo, $mediaId, 'pixai_sensible', $nsfwScore, $scanFlags, $scanData['raw'] ?? []);

        $stmt = $pdo->prepare('UPDATE media SET has_nsfw = ?, rating = ?, status = "active" WHERE id = ?');
        $stmt->execute([$hasNsfw, $rating, $mediaId]);
        $result['scan_updated'] = true;
    } else {
        $logger('Scanner lieferte keine verwertbaren Daten, Tags unverändert.');
    }

    try {
        $metadata = sv_extract_metadata($path, 'image', SV_FORGE_SCAN_SOURCE_LABEL, $logger);
        sv_store_extracted_metadata($pdo, $mediaId, 'image', $metadata, SV_FORGE_SCAN_SOURCE_LABEL, $logger);
        $result['meta_updated'] = true;
    } catch (Throwable $e) {
        $logger('Metadaten konnten nicht aktualisiert werden: ' . $e->getMessage());
    }

    $insertPrompt = $pdo->prepare(
        'INSERT INTO prompts (media_id, prompt, negative_prompt, model, sampler, cfg_scale, steps, seed, width, height, scheduler, sampler_settings, loras, controlnet, source_metadata) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $insertPrompt->execute([
        $mediaId,
        $regenPlan['final_prompt'],
        (string)($payload['negative_prompt'] ?? ''),
        (string)($payload['model'] ?? ''),
        (string)($payload['sampler'] ?? ''),
        isset($payload['cfg_scale']) ? (float)$payload['cfg_scale'] : null,
        isset($payload['steps']) ? (int)$payload['steps'] : null,
        isset($payload['seed']) ? (string)$payload['seed'] : null,
        isset($payload['width']) ? (int)$payload['width'] : null,
        isset($payload['height']) ? (int)$payload['height'] : null,
        isset($payload['scheduler']) ? (string)$payload['scheduler'] : null,
        isset($payload['sampler_settings']) ? (is_string($payload['sampler_settings']) ? $payload['sampler_settings'] : json_encode($payload['sampler_settings'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : null,
        isset($payload['loras']) ? (is_string($payload['loras']) ? $payload['loras'] : json_encode($payload['loras'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : null,
        isset($payload['controlnet']) ? (is_string($payload['controlnet']) ? $payload['controlnet'] : json_encode($payload['controlnet'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : null,
        json_encode([
            'source'          => SV_FORGE_SCAN_SOURCE_LABEL,
            'prompt_category' => $regenPlan['category'] ?? null,
            'fallback_used'   => $regenPlan['fallback_used'] ?? null,
            'tag_prompt_used' => $regenPlan['tag_prompt_used'] ?? null,
            'original_prompt' => $regenPlan['original_prompt'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $result['prompt_created'] = true;

    return $result;
}

function sv_update_job_status(PDO $pdo, int $jobId, string $status, ?string $responseJson = null, ?string $error = null): void
{
    $stmt = $pdo->prepare(
        'UPDATE jobs SET status = :status, forge_response_json = COALESCE(:response, forge_response_json), error_message = :error, updated_at = :updated_at WHERE id = :id'
    );
    $stmt->execute([
        ':status'     => $status,
        ':response'   => $responseJson,
        ':error'      => $error,
        ':updated_at' => date('c'),
        ':id'         => $jobId,
    ]);
}

function sv_run_forge_regen_replace(PDO $pdo, array $config, int $mediaId, callable $logger): array
{
    $logger('Forge-Regeneration wird in V3 asynchron über die Job-Queue abgewickelt.');

    return sv_queue_forge_regeneration($pdo, $config, $mediaId, $logger);
}

function sv_extract_regen_plan_from_payload(array $payload): array
{
    $regenPlan = is_array($payload['_sv_regen_plan'] ?? null) ? $payload['_sv_regen_plan'] : [];

    if (!isset($regenPlan['final_prompt']) && isset($payload['prompt'])) {
        $regenPlan['final_prompt'] = (string)$payload['prompt'];
    }
    if (!isset($regenPlan['category']) && isset($payload['_sv_prompt_category'])) {
        $regenPlan['category'] = $payload['_sv_prompt_category'];
    }
    if (!isset($regenPlan['fallback_used']) && isset($payload['_sv_prompt_fallback'])) {
        $regenPlan['fallback_used'] = (bool)$payload['_sv_prompt_fallback'];
    }
    if (!isset($regenPlan['tag_prompt_used']) && isset($payload['_sv_prompt_tags_used'])) {
        $regenPlan['tag_prompt_used'] = (bool)$payload['_sv_prompt_tags_used'];
    }

    return [
        'category'        => $regenPlan['category'] ?? null,
        'final_prompt'    => (string)($regenPlan['final_prompt'] ?? ''),
        'fallback_used'   => (bool)($regenPlan['fallback_used'] ?? false),
        'tag_prompt_used' => (bool)($regenPlan['tag_prompt_used'] ?? false),
        'original_prompt' => $regenPlan['original_prompt'] ?? null,
    ];
}

function sv_process_single_forge_job(PDO $pdo, array $config, array $jobRow, callable $logger): array
{
    $jobId   = (int)($jobRow['id'] ?? 0);
    $mediaId = (int)($jobRow['media_id'] ?? 0);

    $payloadRaw = (string)($jobRow['forge_request_json'] ?? '');
    $payload    = json_decode($payloadRaw, true);
    if (!is_array($payload)) {
        sv_update_job_status($pdo, $jobId, 'error', null, 'Forge-Job hat keine gültige Payload.');
        throw new RuntimeException('Forge-Job #' . $jobId . ' hat keine gültige Payload.');
    }

    $regenPlan = sv_extract_regen_plan_from_payload($payload);
    $requestedModel = $payload['_sv_requested_model'] ?? ($payload['model'] ?? null);
    if (trim((string)$regenPlan['final_prompt']) === '') {
        sv_update_job_status($pdo, $jobId, 'error', null, 'Forge-Job ohne finalen Prompt.');
        throw new RuntimeException('Forge-Job #' . $jobId . ' hat keinen finalen Prompt.');
    }

    $payload['prompt'] = $regenPlan['final_prompt'];

    $mediaRow = sv_load_media_with_prompt($pdo, $mediaId);
    $type     = (string)($mediaRow['type'] ?? '');
    $status   = (string)($mediaRow['status'] ?? '');
    if ($type !== 'image') {
        sv_update_job_status($pdo, $jobId, 'error', null, 'Nur Bildmedien können regeneriert werden.');
        throw new InvalidArgumentException('Nur Bildmedien können regeneriert werden.');
    }
    if ($status === 'missing') {
        sv_update_job_status($pdo, $jobId, 'error', null, 'Regeneration für fehlende Dateien nicht möglich.');
        throw new RuntimeException('Regeneration für fehlende Dateien nicht möglich.');
    }

    $path = (string)($mediaRow['path'] ?? '');
    if ($path === '' || !is_file($path)) {
        sv_update_job_status($pdo, $jobId, 'error', null, 'Dateipfad nicht vorhanden.');
        throw new RuntimeException('Dateipfad nicht vorhanden.');
    }

    $pathsCfg = $config['paths'] ?? [];
    sv_assert_media_path_allowed($path, $pathsCfg, 'forge_regen_replace');

    sv_update_job_status($pdo, $jobId, 'running');

    $backupPath    = null;
    $newFileInfo   = null;
    $forgeResponse = null;

    try {
        $forgeResponse = sv_call_forge_sync($config, $payload, $logger);
        $backupPath    = sv_backup_media_file($config, $path, $logger);
        $newFileInfo   = sv_replace_media_file_with_image($config, $path, $forgeResponse['binary'], $logger);

        $update = $pdo->prepare(
            'UPDATE media SET hash = ?, width = ?, height = ?, filesize = ?, status = "active" WHERE id = ?'
        );
        $update->execute([
            $newFileInfo['hash'],
            $newFileInfo['width'],
            $newFileInfo['height'],
            $newFileInfo['filesize'],
            $mediaId,
        ]);

        $refreshResult = sv_refresh_media_after_regen($pdo, $config, $mediaId, $path, $regenPlan, $payload, $logger);

        sv_store_forge_regen_meta($pdo, $mediaId, [
            'prompt_category' => $regenPlan['category'],
            'prompt_fallback' => $regenPlan['fallback_used'] ? '1' : '0',
            'tag_prompt_used' => $regenPlan['tag_prompt_used'] ? '1' : '0',
            'prompt_original' => $regenPlan['original_prompt'],
            'prompt_effective'=> $regenPlan['final_prompt'],
            'backup_path'     => $backupPath,
        ]);

        $responsePayload = [
            'forge_response' => $forgeResponse['response_array'] ?? null,
            'result' => [
                'replaced'        => true,
                'backup_path'     => $backupPath,
                'new_hash'        => $newFileInfo['hash'] ?? null,
                'old_hash'        => $mediaRow['hash'] ?? null,
                'prompt_category' => $regenPlan['category'],
                'fallback_used'   => $regenPlan['fallback_used'],
                'tag_prompt_used' => $regenPlan['tag_prompt_used'],
                'model'           => $payload['model'] ?? null,
                'requested_model' => $requestedModel,
            ],
        ];

        $responseJson = json_encode($responsePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        sv_update_job_status($pdo, $jobId, 'done', $responseJson, null);

        sv_audit_log($pdo, 'forge_regen_replace', 'media', $mediaId, [
            'job_id'            => $jobId,
            'requested_model'   => $requestedModel,
            'resolved_model'    => $payload['model'] ?? null,
            'fallback_used'     => $regenPlan['fallback_used'],
            'tag_prompt_used'   => $regenPlan['tag_prompt_used'],
            'old_hash'          => $mediaRow['hash'] ?? null,
            'new_hash'          => $newFileInfo['hash'] ?? null,
            'scan_updated'      => $refreshResult['scan_updated'] ?? false,
            'meta_updated'      => $refreshResult['meta_updated'] ?? false,
            'prompt_created'    => $refreshResult['prompt_created'] ?? false,
            'prompt_category'   => $regenPlan['category'],
            'backup_path'       => $backupPath,
        ]);

        return [
            'job_id'            => $jobId,
            'media_id'          => $mediaId,
            'prompt_category'   => $regenPlan['category'],
            'fallback_used'     => $regenPlan['fallback_used'],
            'tag_prompt_used'   => $regenPlan['tag_prompt_used'],
            'backup_path'       => $backupPath,
            'new_hash'          => $newFileInfo['hash'] ?? null,
            'old_hash'          => $mediaRow['hash'] ?? null,
            'resolved_model'    => $payload['model'] ?? null,
            'requested_model'   => $requestedModel,
            'scan_updated'      => $refreshResult['scan_updated'] ?? false,
            'meta_updated'      => $refreshResult['meta_updated'] ?? false,
            'prompt_created'    => $refreshResult['prompt_created'] ?? false,
            'status'            => 'done',
        ];
    } catch (Throwable $e) {
        if ($forgeResponse !== null) {
            sv_update_job_status($pdo, $jobId, 'error', $forgeResponse['response_json'] ?? null, $e->getMessage());
        } else {
            sv_update_job_status($pdo, $jobId, 'error', null, $e->getMessage());
        }

        if ($backupPath !== null && $newFileInfo !== null && is_file($backupPath)) {
            try {
                copy($backupPath, $path);
            } catch (Throwable $restoreError) {
                $logger('Restore nach Fehler fehlgeschlagen: ' . $restoreError->getMessage());
            }
        }

        return [
            'job_id'   => $jobId,
            'media_id' => $mediaId,
            'status'   => 'error',
            'error'    => $e->getMessage(),
        ];
    }
}

function sv_process_forge_job_batch(PDO $pdo, array $config, ?int $limit, callable $logger): array
{
    $effectiveLimit = $limit === null ? 1 : max(1, min(10, (int)$limit));

    $stmt = $pdo->prepare(
        'SELECT * FROM jobs WHERE type = :type AND status IN ("queued", "running") ORDER BY id ASC LIMIT :limit'
    );
    $stmt->bindValue(':type', SV_FORGE_JOB_TYPE, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $effectiveLimit, PDO::PARAM_INT);
    $stmt->execute();

    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $summary = [
        'total'   => count($jobs),
        'done'    => 0,
        'error'   => 0,
        'results' => [],
    ];

    foreach ($jobs as $jobRow) {
        $jobId = (int)($jobRow['id'] ?? 0);
        try {
            $logger('Verarbeite Forge-Job #' . $jobId . ' (Media ' . (int)($jobRow['media_id'] ?? 0) . ')');
            $result = sv_process_single_forge_job($pdo, $config, $jobRow, $logger);
            if (($result['status'] ?? '') === 'done') {
                $summary['done']++;
            } else {
                $summary['error']++;
            }
            $summary['results'][] = $result;
        } catch (Throwable $e) {
            $summary['error']++;
            $summary['results'][] = [
                'job_id' => $jobId,
                'status' => 'error',
                'error'  => $e->getMessage(),
            ];
            $logger('Fehler bei Forge-Job #' . $jobId . ': ' . $e->getMessage());
        }
    }

    return $summary;
}

function sv_get_media_versions(PDO $pdo, int $mediaId): array
{
    $mediaStmt = $pdo->prepare('SELECT id, path, hash, created_at, imported_at FROM media WHERE id = :id');
    $mediaStmt->execute([':id' => $mediaId]);
    $mediaRow = $mediaStmt->fetch(PDO::FETCH_ASSOC);

    if (!$mediaRow) {
        throw new InvalidArgumentException('Media-Eintrag nicht gefunden.');
    }

    $metaStmt = $pdo->prepare(
        'SELECT source, meta_key, meta_value, created_at FROM media_meta WHERE media_id = :id ORDER BY id ASC'
    );
    $metaStmt->execute([':id' => $mediaId]);
    $metaRows = $metaStmt->fetchAll(PDO::FETCH_ASSOC);

    $backupMeta = [];
    foreach ($metaRows as $metaRow) {
        if (
            (string)($metaRow['source'] ?? '') === SV_FORGE_SCAN_SOURCE_LABEL
            && (string)($metaRow['meta_key'] ?? '') === 'backup_path'
            && isset($metaRow['meta_value'])
        ) {
            $backupMeta[] = [
                'path'      => (string)$metaRow['meta_value'],
                'created_at'=> (string)($metaRow['created_at'] ?? ''),
            ];
        }
    }

    $versions = [[
        'version_index'   => 0,
        'is_current'      => true,
        'source'          => 'import',
        'status'          => 'baseline',
        'timestamp'       => (string)($mediaRow['created_at'] ?? ($mediaRow['imported_at'] ?? '')),
        'model_requested' => null,
        'model_used'      => null,
        'prompt_category' => null,
        'fallback_used'   => false,
        'backup_path'     => null,
        'backup_exists'   => false,
        'hash_old'        => null,
        'hash_new'        => $mediaRow['hash'] ?? null,
        'job_id'          => null,
    ]];

    $jobsStmt = $pdo->prepare(
        'SELECT id, status, created_at, updated_at, forge_request_json, forge_response_json, error_message '
        . 'FROM jobs WHERE type = :type AND media_id = :media_id AND status IN ("done", "error") ORDER BY id ASC'
    );
    $jobsStmt->execute([
        ':type'     => SV_FORGE_JOB_TYPE,
        ':media_id' => $mediaId,
    ]);

    $rows = $jobsStmt->fetchAll(PDO::FETCH_ASSOC);
    $currentIndex = 0;

    foreach ($rows as $row) {
        $payload  = json_decode((string)($row['forge_request_json'] ?? ''), true);
        $response = json_decode((string)($row['forge_response_json'] ?? ''), true);

        $regenPlan = is_array($payload) ? sv_extract_regen_plan_from_payload($payload) : [
            'category'        => null,
            'fallback_used'   => null,
            'tag_prompt_used' => null,
        ];

        $requestedModel = is_array($payload)
            ? ($payload['_sv_requested_model'] ?? ($payload['model'] ?? null))
            : null;
        $resolvedModel = is_array($response) && isset($response['result']['model'])
            ? $response['result']['model']
            : (is_array($payload) ? ($payload['model'] ?? null) : null);

        $promptCategory = $response['result']['prompt_category']
            ?? $regenPlan['category']
            ?? null;
        $fallbackUsed = (bool)($response['result']['fallback_used'] ?? $regenPlan['fallback_used'] ?? false);

        $jobCreatedAt = (string)($row['created_at'] ?? '');
        $backupPath = $response['result']['backup_path'] ?? null;
        if ($backupPath === null && $backupMeta !== []) {
            $jobCreatedTs = strtotime($jobCreatedAt) ?: null;
            foreach ($backupMeta as $candidate) {
                $metaTs = isset($candidate['created_at']) ? strtotime((string)$candidate['created_at']) : false;
                if ($jobCreatedTs !== null && $metaTs !== false && $metaTs >= $jobCreatedTs) {
                    $backupPath = $candidate['path'];
                    break;
                }
            }
            if ($backupPath === null) {
                $lastCandidate = end($backupMeta);
                $backupPath    = $lastCandidate['path'] ?? null;
                reset($backupMeta);
            }
        }

        $hashOld = $response['result']['old_hash'] ?? null;
        $hashNew = $response['result']['new_hash'] ?? null;

        $versionIndex = count($versions);
        $versions[] = [
            'version_index'   => $versionIndex,
            'is_current'      => false,
            'source'          => 'forge_regen',
            'status'          => ((string)($row['status'] ?? '') === 'done') ? 'ok' : 'error',
            'timestamp'       => (string)($row['updated_at'] ?? $jobCreatedAt),
            'model_requested' => $requestedModel,
            'model_used'      => $resolvedModel,
            'prompt_category' => $promptCategory,
            'fallback_used'   => $fallbackUsed,
            'backup_path'     => $backupPath,
            'backup_exists'   => $backupPath !== null && is_file((string)$backupPath),
            'hash_old'        => $hashOld,
            'hash_new'        => $hashNew,
            'job_id'          => (int)($row['id'] ?? 0),
        ];

        if ((string)($row['status'] ?? '') === 'done') {
            $currentIndex = $versionIndex;
        }
    }

    foreach ($versions as $idx => $version) {
        $versions[$idx]['is_current'] = $idx === $currentIndex;
    }

    return $versions;
}

function sv_fetch_forge_jobs_for_media(PDO $pdo, int $mediaId, int $limit = 10): array
{
    $limit = max(1, $limit);
    $stmt = $pdo->prepare(
        'SELECT id, status, created_at, updated_at, forge_request_json, forge_response_json, error_message '
        . 'FROM jobs WHERE type = :type AND media_id = :media_id ORDER BY id DESC LIMIT :limit'
    );
    $stmt->bindValue(':type', SV_FORGE_JOB_TYPE, PDO::PARAM_STR);
    $stmt->bindValue(':media_id', $mediaId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $jobs = [];
    foreach ($rows as $row) {
        $payload  = json_decode((string)($row['forge_request_json'] ?? ''), true);
        $response = json_decode((string)($row['forge_response_json'] ?? ''), true);

        $jobs[] = [
            'id'         => (int)$row['id'],
            'status'     => (string)$row['status'],
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
            'model'      => $payload['model'] ?? null,
            'replaced'   => $response['result']['replaced'] ?? ($row['status'] === 'done'),
            'error'      => $row['error_message'] ?? null,
        ];
    }

    return $jobs;
}

function sv_run_scan_operation(PDO $pdo, array $config, string $scanPath, ?int $limit, callable $logger): array
{
    $scannerCfg    = $config['scanner'] ?? [];
    $pathsCfg      = $config['paths'] ?? [];
    $nsfwThreshold = (float)($scannerCfg['nsfw_threshold'] ?? 0.7);

    $logger('Starte Scan: ' . $scanPath . ($limit !== null ? " (limit={$limit})" : ''));

    $result = sv_run_scan_path(
        $scanPath,
        $pdo,
        $pathsCfg,
        $scannerCfg,
        $nsfwThreshold,
        $logger,
        $limit
    );

    $logger(sprintf(
        'Scan fertig: processed=%d, skipped=%d, errors=%d',
        (int)$result['processed'],
        (int)$result['skipped'],
        (int)$result['errors']
    ));

    return $result;
}

function sv_run_rescan_operation(
    PDO $pdo,
    array $config,
    ?int $limit,
    ?int $offset,
    callable $logger
): array {
    $scannerCfg    = $config['scanner'] ?? [];
    $pathsCfg      = $config['paths'] ?? [];
    $nsfwThreshold = (float)($scannerCfg['nsfw_threshold'] ?? 0.7);

    $logger('Starte Rescan fehlender Ergebnisse' . ($limit !== null ? " (limit={$limit}" . ($offset !== null ? ", offset={$offset}" : '') . ')' : ''));

    $result = sv_run_rescan_unscanned(
        $pdo,
        $pathsCfg,
        $scannerCfg,
        $nsfwThreshold,
        $logger,
        $limit,
        $offset
    );

    $logger(sprintf(
        'Rescan fertig: processed=%d, skipped=%d, errors=%d',
        (int)$result['processed'],
        (int)$result['skipped'],
        (int)$result['errors']
    ));

    return $result;
}

function sv_run_filesync_operation(
    PDO $pdo,
    array $config,
    ?int $limit,
    ?int $offset,
    callable $logger
): array {
    $pathsCfg = $config['paths'] ?? [];

    $logger('Starte Filesync' . ($limit !== null ? " (limit={$limit}" . ($offset !== null ? ", offset={$offset}" : '') . ')' : ''));

    $result = sv_run_filesync(
        $pdo,
        $pathsCfg,
        $logger,
        $limit,
        $offset
    );

    $logger(sprintf(
        'Filesync fertig: processed=%d, missing=%d, restored=%d, errors=%d',
        (int)$result['processed'],
        (int)$result['missing'],
        (int)$result['restored'],
        (int)$result['errors']
    ));

    return $result;
}

function sv_run_prompts_rebuild_operation(
    PDO $pdo,
    array $config,
    ?int $limit,
    ?int $offset,
    callable $logger
): array {
    $limit = $limit ?? 250;

    $sql = "SELECT m.id, m.path, m.type, p.id AS prompt_id FROM media m "
         . "LEFT JOIN prompts p ON p.media_id = m.id "
         . "WHERE m.status = 'active' AND m.type = 'image' AND (";
    $sql .= "p.id IS NULL OR p.prompt IS NULL OR p.negative_prompt IS NULL OR p.source_metadata IS NULL";
    $sql .= " OR p.model IS NULL OR p.sampler IS NULL OR p.cfg_scale IS NULL OR p.steps IS NULL OR p.seed IS NULL OR p.width IS NULL OR p.height IS NULL OR p.scheduler IS NULL";
    $sql .= ") ORDER BY m.id ASC";

    if ($limit !== null) {
        $sql .= ' LIMIT ' . max(0, (int)$limit);
    }
    if ($offset !== null) {
        $sql .= ' OFFSET ' . max(0, (int)$offset);
    }

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $total     = count($rows);
    $processed = 0;
    $skipped   = 0;
    $errors    = 0;

    $logger('Prompt-Rebuild: ' . $total . ' Kandidaten gefunden' . ($limit !== null ? " (limit={$limit}" . ($offset !== null ? ", offset={$offset}" : '') . ')' : ''));

    foreach ($rows as $row) {
        $mediaId = (int)$row['id'];
        $path    = (string)$row['path'];
        $type    = (string)$row['type'];

        $logger("Media ID {$mediaId}: {$path}");

        if (!is_file($path)) {
            $logger('  -> Datei nicht gefunden, übersprungen.');
            $skipped++;
            continue;
        }

        try {
            $metadata = sv_extract_metadata($path, $type, 'prompts_rebuild', $logger);
            sv_store_extracted_metadata($pdo, $mediaId, $type, $metadata, 'prompts_rebuild', $logger);
            $processed++;
        } catch (Throwable $e) {
            $errors++;
            $logger('  -> Fehler: ' . $e->getMessage());
        }
    }

    $logger(sprintf(
        'Prompt-Rebuild fertig: total=%d, processed=%d, skipped=%d, errors=%d',
        $total,
        $processed,
        $skipped,
        $errors
    ));

    return [
        'total'     => $total,
        'processed' => $processed,
        'skipped'   => $skipped,
        'errors'    => $errors,
    ];
}

function sv_find_media_with_incomplete_prompts(PDO $pdo, int $limit = 100): array
{
    $limit = max(1, $limit);
    $promptComplete = sv_prompt_core_complete_condition('p2');

    $sql = "SELECT m.id FROM media m "
        . "WHERE m.status = 'active' AND m.type = 'image' "
        . "AND NOT EXISTS (SELECT 1 FROM prompts p2 WHERE p2.media_id = m.id AND {$promptComplete}) "
        . "ORDER BY m.id ASC LIMIT :limit";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function sv_run_prompt_rebuild_single(PDO $pdo, array $config, int $mediaId, callable $logger): array
{
    $stmt = $pdo->prepare('SELECT id, path, type, status FROM media WHERE id = ?');
    $stmt->execute([$mediaId]);
    $media = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$media) {
        throw new InvalidArgumentException('Media-Eintrag nicht gefunden.');
    }

    $path   = (string)$media['path'];
    $type   = (string)$media['type'];
    $status = (string)$media['status'];

    $logger('Prompt-Rebuild (Single) für Media ID ' . $mediaId);

    if ($status !== 'active') {
        $logger('  -> Übersprungen: Status ist nicht active.');
        return [
            'processed' => 0,
            'skipped'   => 1,
            'errors'    => 0,
        ];
    }

    if ($type !== 'image') {
        $logger('  -> Übersprungen: Nur Bilder werden unterstützt.');
        return [
            'processed' => 0,
            'skipped'   => 1,
            'errors'    => 0,
        ];
    }

    if (!is_file($path)) {
        $logger('  -> Datei nicht gefunden, Status prüfen (Filesync?).');
        return [
            'processed' => 0,
            'skipped'   => 1,
            'errors'    => 0,
        ];
    }

    try {
        $metadata = sv_extract_metadata($path, $type, 'prompts_rebuild_single', $logger);
        sv_store_extracted_metadata($pdo, $mediaId, $type, $metadata, 'prompts_rebuild_single', $logger);
        $logger('  -> Rebuild abgeschlossen.');
        sv_audit_log($pdo, 'prompt_rebuild_single', 'media', $mediaId, [
            'path'   => $path,
            'status' => $status,
        ]);
        return [
            'processed' => 1,
            'skipped'   => 0,
            'errors'    => 0,
        ];
    } catch (Throwable $e) {
        $logger('  -> Fehler: ' . $e->getMessage());
        return [
            'processed' => 0,
            'skipped'   => 0,
            'errors'    => 1,
            'error'     => $e->getMessage(),
        ];
    }
}

function sv_run_prompt_rebuild_missing(PDO $pdo, array $config, callable $logger, int $maxBatch = 100): array
{
    $maxBatch    = max(1, $maxBatch);
    $candidates  = sv_find_media_with_incomplete_prompts($pdo, $maxBatch);
    $found       = count($candidates);
    $processed   = 0;
    $skipped     = 0;
    $errors      = 0;

    $logger('Komfort-Rebuild: ' . $found . ' Kandidaten (Limit ' . $maxBatch . ')');

    foreach ($candidates as $mediaId) {
        $result    = sv_run_prompt_rebuild_single($pdo, $config, $mediaId, $logger);
        $processed += (int)($result['processed'] ?? 0);
        $skipped   += (int)($result['skipped'] ?? 0);
        $errors    += (int)($result['errors'] ?? 0);
    }

    sv_audit_log($pdo, 'prompts_rebuild_missing', 'media', null, [
        'limit'     => $maxBatch,
        'found'     => $found,
        'processed' => $processed,
        'skipped'   => $skipped,
        'errors'    => $errors,
    ]);

    return [
        'found'     => $found,
        'processed' => $processed,
        'skipped'   => $skipped,
        'errors'    => $errors,
    ];
}

function sv_mark_media_missing(PDO $pdo, int $mediaId, callable $logger): array
{
    $stmt = $pdo->prepare('SELECT id, status FROM media WHERE id = ?');
    $stmt->execute([$mediaId]);
    $media = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$media) {
        throw new InvalidArgumentException('Media-Eintrag nicht gefunden.');
    }

    $currentStatus = (string)($media['status'] ?? '');
    $logger('Logisches Löschen für Media ID ' . $mediaId . ' (Status: ' . $currentStatus . ')');

    if ($currentStatus === 'missing') {
        $logger('  -> Bereits als missing markiert, keine Änderung.');
        return [
            'changed'         => false,
            'previous_status' => $currentStatus,
        ];
    }

    $update = $pdo->prepare("UPDATE media SET status = 'missing' WHERE id = ?");
    $update->execute([$mediaId]);

    sv_audit_log($pdo, 'media_mark_missing', 'media', $mediaId, [
        'previous_status' => $currentStatus,
        'new_status'      => 'missing',
    ]);

    $logger('  -> Status auf missing gesetzt.');

    return [
        'changed'         => true,
        'previous_status' => $currentStatus,
    ];
}

function sv_media_consistency_status(PDO $pdo, int $mediaId): array
{
    $promptComplete = sv_prompt_core_complete_condition('p');

    $stmt = $pdo->prepare(
        'SELECT '
        . '(SELECT CASE WHEN EXISTS (SELECT 1 FROM prompts p WHERE p.media_id = :id AND ' . $promptComplete . ') '
        . 'THEN 1 ELSE 0 END) AS prompt_complete, '
        . '(SELECT CASE WHEN EXISTS (SELECT 1 FROM prompts p WHERE p.media_id = :id) THEN 1 ELSE 0 END) AS prompt_present, '
        . '(SELECT CASE WHEN EXISTS (SELECT 1 FROM media_tags mt WHERE mt.media_id = :id) THEN 1 ELSE 0 END) AS has_tags, '
        . '(SELECT CASE WHEN EXISTS (SELECT 1 FROM media_meta mm WHERE mm.media_id = :id) THEN 1 ELSE 0 END) AS has_meta'
    );
    $stmt->execute([':id' => $mediaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
        'prompt_complete' => 0,
        'prompt_present'  => 0,
        'has_tags'        => 0,
        'has_meta'        => 0,
    ];

    $promptPresent  = (int)($row['prompt_present'] ?? 0) === 1;
    $promptCompleteFlag = (int)($row['prompt_complete'] ?? 0) === 1;

    return [
        'prompt_present'   => $promptPresent,
        'prompt_complete'  => $promptCompleteFlag,
        'prompt_incomplete'=> $promptPresent ? !$promptCompleteFlag : true,
        'has_tags'         => (int)($row['has_tags'] ?? 0) === 1,
        'has_meta'         => (int)($row['has_meta'] ?? 0) === 1,
    ];
}

function sv_collect_integrity_issues(PDO $pdo, ?array $mediaIds = null): array
{
    $mediaIds = $mediaIds !== null
        ? array_values(array_filter(array_map('intval', $mediaIds), static fn ($v) => $v > 0))
        : null;

    $issueByMedia = [];
    $issueByType  = [];

    if ($mediaIds !== null && $mediaIds === []) {
        return [
            'by_media' => $issueByMedia,
            'by_type'  => $issueByType,
        ];
    }

    $registerIssue = static function (int $mediaId, string $type, string $message) use (&$issueByMedia, &$issueByType): void {
        $entry = [
            'media_id' => $mediaId,
            'type'     => $type,
            'message'  => $message,
        ];
        $issueByMedia[$mediaId][] = $entry;
        $issueByType[$type][]     = $entry;
    };

    $filterSql   = '';
    $filterParts = [];
    if ($mediaIds !== null) {
        $placeholders = implode(',', array_fill(0, count($mediaIds), '?'));
        $filterSql    = ' AND m.id IN (' . $placeholders . ')';
        $filterParts  = $mediaIds;
    }

    $hashSql  = "SELECT m.id FROM media m WHERE (m.hash IS NULL OR TRIM(m.hash) = '')" . $filterSql;
    $hashStmt = $pdo->prepare($hashSql);
    $hashStmt->execute($filterParts);
    foreach ($hashStmt->fetchAll(PDO::FETCH_COLUMN) as $mediaId) {
        $registerIssue((int)$mediaId, 'hash', 'Hash fehlt.');
    }

    $promptSql  = "SELECT DISTINCT p.media_id FROM prompts p JOIN media m ON m.id = p.media_id"
        . " WHERE (p.source_metadata IS NULL OR TRIM(p.source_metadata) = '')" . $filterSql;
    $promptStmt = $pdo->prepare($promptSql);
    $promptStmt->execute($filterParts);
    foreach ($promptStmt->fetchAll(PDO::FETCH_COLUMN) as $mediaId) {
        $registerIssue((int)$mediaId, 'prompt', 'Prompt vorhanden, aber Roh-Metadaten fehlen.');
    }

    $tagSql  = "SELECT DISTINCT mt.media_id FROM media_tags mt JOIN media m ON m.id = mt.media_id"
        . " WHERE mt.confidence IS NULL" . $filterSql;
    $tagStmt = $pdo->prepare($tagSql);
    $tagStmt->execute($filterParts);
    foreach ($tagStmt->fetchAll(PDO::FETCH_COLUMN) as $mediaId) {
        $registerIssue((int)$mediaId, 'tag', 'Tag-Konfidenz fehlt.');
    }

    $fileSql  = "SELECT m.id, m.path FROM media m WHERE m.status = 'active' AND m.path IS NOT NULL" . $filterSql;
    $fileStmt = $pdo->prepare($fileSql);
    $fileStmt->execute($filterParts);
    while ($row = $fileStmt->fetch(PDO::FETCH_ASSOC)) {
        $mediaId = (int)$row['id'];
        $path    = (string)$row['path'];
        if (!is_file($path)) {
            $registerIssue($mediaId, 'file', 'Dateipfad nicht gefunden.');
        }
    }

    return [
        'by_media' => $issueByMedia,
        'by_type'  => $issueByType,
    ];
}

function sv_run_simple_integrity_repair(PDO $pdo, callable $logLine): array
{
    $changes = [
        'status_missing_set' => 0,
        'tag_rows_removed'   => 0,
        'prompts_removed'    => 0,
    ];

    $fileStmt = $pdo->query("SELECT id, path FROM media WHERE status = 'active' AND path IS NOT NULL");
    while ($row = $fileStmt->fetch(PDO::FETCH_ASSOC)) {
        $mediaId = (int)$row['id'];
        $path    = (string)$row['path'];
        if (!is_file($path)) {
            $update = $pdo->prepare("UPDATE media SET status = 'missing' WHERE id = ?");
            $update->execute([$mediaId]);
            $changes['status_missing_set']++;
            $logLine('Media #' . $mediaId . ' als missing markiert (Datei fehlt).');
        }
    }

    $deleteTags = $pdo->prepare('DELETE FROM media_tags WHERE confidence IS NULL');
    $deleteTags->execute();
    $changes['tag_rows_removed'] = (int)$deleteTags->rowCount();
    if ($changes['tag_rows_removed'] > 0) {
        $logLine('Tag-Zuordnungen ohne Confidence entfernt: ' . $changes['tag_rows_removed']);
    }

    $promptIdsStmt = $pdo->query(
        "SELECT id FROM prompts WHERE "
        . "(prompt IS NULL OR TRIM(prompt) = '')"
        . " AND (negative_prompt IS NULL OR TRIM(negative_prompt) = '')"
        . " AND (source_metadata IS NULL OR TRIM(source_metadata) = '')"
    );
    $promptIds = $promptIdsStmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($promptIds)) {
        $placeholders = implode(',', array_fill(0, count($promptIds), '?'));
        $deletePrompts = $pdo->prepare('DELETE FROM prompts WHERE id IN (' . $placeholders . ')');
        $deletePrompts->execute(array_map('intval', $promptIds));
        $changes['prompts_removed'] = (int)$deletePrompts->rowCount();
        $logLine('Leere Prompt-Einträge entfernt: ' . $changes['prompts_removed']);
    }

    return $changes;
}

function sv_run_consistency_operation(PDO $pdo, array $config, string $mode, callable $logLine): array
{
    $repairMode = $mode === 'simple' ? 'simple' : 'report';
    $logFile = sv_prepare_log_file($config, 'consistency', true, 30);
    $logHandle = @fopen($logFile, 'ab');

    $consistencyLogAvailable = sv_has_consistency_log_table($pdo);
    if (!$consistencyLogAvailable) {
        $logLine('Warnung: Tabelle consistency_log fehlt. Migration ausführen, um DB-Logging zu aktivieren.');
    }

    $auditFindings = [];
    $logLine('Starte Konsistenzprüfung...');
    $logLine('Modus: ' . ($repairMode === 'simple' ? 'report + simple repair' : 'report-only'));

    $logWriter = function (string $message) use ($logHandle, $logLine): void {
        $logLine($message);
        if (is_resource($logHandle)) {
            fwrite($logHandle, '[' . date('c') . '] ' . $message . PHP_EOL);
        }
    };

    $insertStmt = null;
    if ($consistencyLogAvailable) {
        $insertStmt = $pdo->prepare(
            'INSERT INTO consistency_log (check_name, severity, message, created_at) VALUES (?, ?, ?, ?)'
        );
    }

    $logFinding = function (
        string $checkName,
        string $severity,
        string $message
    ) use ($insertStmt, $consistencyLogAvailable, $logWriter, &$auditFindings): void {
        $logWriter("[{$severity}] {$checkName}: {$message}");
        $auditFindings[] = [
            'check'    => $checkName,
            'severity' => $severity,
            'message'  => $message,
        ];
        if ($consistencyLogAvailable && $insertStmt !== null) {
            $insertStmt->execute([
                $checkName,
                $severity,
                $message,
                date('c'),
            ]);
        }
    };

    sv_check_orphans($pdo, $logFinding, $repairMode);
    sv_check_duplicate_hashes($pdo, $logFinding);
    sv_check_missing_files($pdo, $logFinding, $repairMode);

    $logWriter('Konsistenzprüfung abgeschlossen.');
    if (is_resource($logHandle)) {
        fclose($logHandle);
    }

    if ($repairMode === 'simple') {
        sv_audit_log($pdo, 'consistency_repair', 'db', null, [
            'mode'     => $repairMode,
            'findings' => $auditFindings,
        ]);
    }

    return [
        'mode'     => $repairMode,
        'findings' => $auditFindings,
        'log_file' => $logFile,
    ];
}

function sv_has_consistency_log_table(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM consistency_log LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function sv_check_orphans(PDO $pdo, callable $logFinding, string $repairMode): void
{
    $mtStmt = $pdo->query(
        'SELECT mt.media_id, mt.tag_id FROM media_tags mt ' .
        'LEFT JOIN media m ON m.id = mt.media_id ' .
        'LEFT JOIN tags t ON t.id = mt.tag_id ' .
        'WHERE m.id IS NULL OR t.id IS NULL'
    );
    $mtRows = $mtStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($mtRows as $row) {
        $logFinding('media_tags_orphan', 'warn', 'Media_ID=' . $row['media_id'] . ', Tag_ID=' . $row['tag_id']);
    }

    if ($repairMode === 'simple' && !empty($mtRows)) {
        $deleteStmt = $pdo->prepare('DELETE FROM media_tags WHERE media_id = ? AND tag_id = ?');
        foreach ($mtRows as $row) {
            $deleteStmt->execute([$row['media_id'], $row['tag_id']]);
            $logFinding('media_tags_orphan', 'info', 'gelöscht: Media_ID=' . $row['media_id'] . ', Tag_ID=' . $row['tag_id']);
        }
    }

    $scanStmt = $pdo->query(
        'SELECT s.id, s.media_id FROM scan_results s ' .
        'LEFT JOIN media m ON m.id = s.media_id ' .
        'WHERE m.id IS NULL'
    );
    $scanRows = $scanStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($scanRows as $row) {
        $logFinding('scan_results_orphan', 'warn', 'Scan_ID=' . $row['id'] . ', Media_ID=' . $row['media_id']);
    }

    if ($repairMode === 'simple' && !empty($scanRows)) {
        $deleteStmt = $pdo->prepare('DELETE FROM scan_results WHERE id = ?');
        foreach ($scanRows as $row) {
            $deleteStmt->execute([$row['id']]);
            $logFinding('scan_results_orphan', 'info', 'gelöscht: Scan_ID=' . $row['id'] . ', Media_ID=' . $row['media_id']);
        }
    }
}

function sv_check_duplicate_hashes(PDO $pdo, callable $logFinding): void
{
    $dups = $pdo->query(
        'SELECT hash, COUNT(*) AS cnt FROM media WHERE hash IS NOT NULL GROUP BY hash HAVING cnt > 1'
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($dups as $row) {
        $logFinding('media_duplicate_hash', 'warn', 'Hash=' . $row['hash'] . ' | Count=' . $row['cnt']);
    }
}

function sv_check_missing_files(PDO $pdo, callable $logFinding, string $repairMode): void
{
    $stmt = $pdo->query(
        "SELECT id, path, status FROM media WHERE path IS NOT NULL ORDER BY id ASC"
    );

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $mediaId = (int)$row['id'];
        $path    = (string)$row['path'];
        $status  = (string)$row['status'];

        $exists = is_file($path);
        if ($exists && $status === 'missing' && $repairMode === 'simple') {
            $pdo->prepare('UPDATE media SET status = \"active\" WHERE id = ?')->execute([$mediaId]);
            $logFinding('media_missing', 'info', 'Status auf active gesetzt: Media_ID=' . $mediaId . ' (war missing, Datei vorhanden)');
            continue;
        }

        if (!$exists && $status !== 'missing') {
            $logFinding('media_missing', 'warn', 'Datei fehlt: Media_ID=' . $mediaId . ' (' . $path . ')');
            if ($repairMode === 'simple') {
                $pdo->prepare('UPDATE media SET status = \"missing\" WHERE id = ?')->execute([$mediaId]);
                $logFinding('media_missing', 'info', 'Status auf missing gesetzt: Media_ID=' . $mediaId . ' (' . $path . ')');
            }
        }
    }
}

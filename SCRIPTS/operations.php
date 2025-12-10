<?php
declare(strict_types=1);

// Zentrale Operationsbibliothek für Web- und CLI-Aufrufer.

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/scan_core.php';
require_once __DIR__ . '/security.php';

const SV_FORGE_JOB_TYPE = 'forge_regen';

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

function sv_queue_forge_regeneration(
    PDO $pdo,
    array $config,
    int $mediaId,
    bool $dispatchNow,
    callable $logger
): array {
    $mediaRow = sv_load_media_with_prompt($pdo, $mediaId);
    $validation = sv_validate_forge_prompt_payload($mediaRow);

    $payload  = $validation['payload'];
    $promptId = $validation['prompt_id'];

    $jobId = sv_create_forge_job($pdo, $mediaId, $payload, $promptId, $logger);

    $result = [
        'job_id'     => $jobId,
        'status'     => 'queued',
        'dispatched' => false,
    ];

    if ($dispatchNow) {
        $dispatchResult = sv_dispatch_forge_job($pdo, $config, $jobId, $payload, $logger);
        $result = array_merge($result, $dispatchResult);
    }

    return $result;
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

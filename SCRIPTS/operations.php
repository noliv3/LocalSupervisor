<?php
declare(strict_types=1);

// Zentrale Operationsbibliothek für Web- und CLI-Aufrufer.

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/scan_core.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/forge_recipes.php';

const SV_FORGE_JOB_TYPE           = 'forge_regen';
const SV_FORGE_JOB_TYPES          = ['forge_regen', 'forge_regen_replace', 'forge_regen_v3'];
const SV_FORGE_JOB_STATUSES       = ['queued', 'pending', 'created'];
const SV_JOB_TYPE_SCAN_PATH       = 'scan_path';
const SV_JOB_TYPE_RESCAN_MEDIA    = 'rescan_media';
const SV_JOB_TYPE_SCAN_BACKFILL_TAGS = 'scan_backfill_tags';
const SV_JOB_TYPE_LIBRARY_RENAME  = 'library_rename';
const SV_FORGE_DEFAULT_BASE_URL   = 'http://127.0.0.1:7861/';
const SV_FORGE_MODEL_LIST_PATH    = '/sdapi/v1/sd-models';
const SV_FORGE_TXT2IMG_PATH       = '/sdapi/v1/txt2img';
const SV_FORGE_IMG2IMG_PATH       = '/sdapi/v1/img2img';
const SV_FORGE_OPTIONS_PATH       = '/sdapi/v1/options';
const SV_FORGE_PROGRESS_PATH      = '/sdapi/v1/progress';
const SV_FORGE_FALLBACK_MODEL     = 'SDXL_FP16_waiNSFWIllustrious_v120.safetensors';
const SV_FORGE_MODEL_CACHE_TTL    = 90;
const SV_FORGE_MAX_TAGS_PROMPT    = 8;
const SV_FORGE_SCAN_SOURCE_LABEL  = 'forge_regen_replace';
const SV_FORGE_WORKER_META_SOURCE = 'forge_worker';

const SV_JOB_STATUS_CANCELED      = 'canceled';
const SV_JOB_STUCK_MINUTES        = 30;
const SV_JOB_QUEUE_STATUSES       = ['queued', 'pending', 'created'];
const SV_LIFECYCLE_ACTIVE         = 'active';
const SV_LIFECYCLE_REVIEW         = 'review';
const SV_LIFECYCLE_PENDING_DELETE = 'pending_delete';
const SV_LIFECYCLE_DELETED        = 'deleted_logical';
const SV_QUALITY_UNKNOWN          = 'unknown';
const SV_QUALITY_OK               = 'ok';
const SV_QUALITY_REVIEW           = 'review';
const SV_QUALITY_BLOCKED          = 'blocked';
const SV_BACKFILL_MAX_ENQUEUE_PER_RUN = 200;

function sv_run_migrations_if_needed(PDO $pdo, array $config, ?callable $logger = null): array
{
    $migrationDir = realpath(__DIR__ . '/migrations');
    if ($migrationDir === false || !is_dir($migrationDir)) {
        throw new RuntimeException('Migrationsverzeichnis fehlt.');
    }

    sv_db_ensure_schema_migrations($pdo);
    $migrations = sv_db_load_migrations($migrationDir);
    $applied    = sv_db_load_applied_versions($pdo);

    $appliedNow = [];
    foreach ($migrations as $migration) {
        $version = $migration['version'];
        if (isset($applied[$version])) {
            continue;
        }

        if ($logger !== null) {
            $logger('Migration gestartet: ' . $version);
        }

        $migration['run']($pdo);
        sv_db_record_version($pdo, $version, $migration['description'] ?? '');
        sv_audit_log($pdo, 'migration_run', 'db', null, ['version' => $version]);
        $appliedNow[] = $version;

        if ($logger !== null) {
            $logger('Migration abgeschlossen: ' . $version);
        }
    }

    return [
        'applied' => $appliedNow,
    ];
}

function sv_job_queue_limits(array $config): array
{
    $jobsCfg = $config['jobs'] ?? [];
    $perType = $jobsCfg['queue_max_per_type'] ?? [];
    if (!is_array($perType)) {
        $perType = [];
    }

    return [
        'total' => isset($jobsCfg['queue_max_total']) ? (int)$jobsCfg['queue_max_total'] : 2000,
        'per_type_default' => isset($jobsCfg['queue_max_per_type_default'])
            ? (int)$jobsCfg['queue_max_per_type_default']
            : 500,
        'per_type' => $perType,
        'per_media' => isset($jobsCfg['queue_max_per_media']) ? (int)$jobsCfg['queue_max_per_media'] : 2,
    ];
}

function sv_enforce_job_queue_capacity(PDO $pdo, array $config, string $type, ?int $mediaId = null): void
{
    $limits = sv_job_queue_limits($config);
    $queueStatuses = SV_JOB_QUEUE_STATUSES;
    $placeholders = implode(',', array_fill(0, count($queueStatuses), '?'));

    $maxTotal = $limits['total'];
    if ($maxTotal > 0) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM jobs WHERE status IN (' . $placeholders . ')');
        $stmt->execute($queueStatuses);
        $total = (int)$stmt->fetchColumn();
        if ($total >= $maxTotal) {
            throw new RuntimeException('Job-Queue-Limit erreicht (gesamt).');
        }
    }

    $maxPerType = $limits['per_type_default'];
    if (isset($limits['per_type'][$type])) {
        $maxPerType = (int)$limits['per_type'][$type];
    }
    if ($maxPerType > 0) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM jobs WHERE type = ? AND status IN (' . $placeholders . ')'
        );
        $stmt->execute(array_merge([$type], $queueStatuses));
        $count = (int)$stmt->fetchColumn();
        if ($count >= $maxPerType) {
            throw new RuntimeException('Job-Queue-Limit erreicht (' . $type . ').');
        }
    }

    $maxPerMedia = $limits['per_media'];
    if ($mediaId !== null && $maxPerMedia > 0) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM jobs WHERE type = ? AND media_id = ? AND status IN (' . $placeholders . ')'
        );
        $stmt->execute(array_merge([$type, $mediaId], $queueStatuses));
        $count = (int)$stmt->fetchColumn();
        if ($count >= $maxPerMedia) {
            throw new RuntimeException('Job-Queue-Limit erreicht (Media).');
        }
    }
}

function sv_is_sqlite_busy(Throwable $error): bool
{
    if (!$error instanceof PDOException) {
        return false;
    }

    $message = $error->getMessage();
    if (is_string($message) && stripos($message, 'SQLITE_BUSY') !== false) {
        return true;
    }
    if (is_string($message) && stripos($message, 'database is locked') !== false) {
        return true;
    }

    $info = $error->errorInfo ?? null;
    if (is_array($info) && isset($info[1]) && (int)$info[1] === 5) {
        return true;
    }

    return false;
}

function sv_db_exec_retry(callable $fn, int $retries = 5, int $minSleepMs = 50, int $maxSleepMs = 250)
{
    $attempts = 0;
    while (true) {
        try {
            return $fn();
        } catch (Throwable $e) {
            if (!sv_is_sqlite_busy($e) || $attempts >= $retries) {
                throw $e;
            }
            $sleepMs = random_int($minSleepMs, $maxSleepMs);
            usleep($sleepMs * 1000);
            $attempts++;
        }
    }
}

function sv_jobs_supports_payload_json(PDO $pdo): bool
{
    static $hasColumn = null;
    if ($hasColumn !== null) {
        return $hasColumn;
    }

    try {
        $stmt = $pdo->query('PRAGMA table_info(jobs)');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $row) {
            if (($row['name'] ?? null) === 'payload_json') {
                $hasColumn = true;
                return true;
            }
        }
    } catch (Throwable $e) {
        $hasColumn = false;
        return false;
    }

    $hasColumn = false;
    return false;
}

function sv_update_job_checkpoint_payload(PDO $pdo, int $jobId, array $payload): void
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return;
    }
    $column = sv_jobs_supports_payload_json($pdo) ? 'payload_json' : 'forge_request_json';
    sv_db_exec_retry(static function () use ($pdo, $jobId, $json, $column): void {
        $stmt = $pdo->prepare('UPDATE jobs SET ' . $column . ' = :payload, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':payload'    => $json,
            ':updated_at' => date('c'),
            ':id'         => $jobId,
        ]);
    });
}

function sv_scan_job_types(): array
{
    return [SV_JOB_TYPE_SCAN_PATH, SV_JOB_TYPE_RESCAN_MEDIA, SV_JOB_TYPE_SCAN_BACKFILL_TAGS];
}

function sv_finished_job_statuses(): array
{
    return ['done', 'error', SV_JOB_STATUS_CANCELED];
}

function sv_quality_status_values(): array
{
    return [SV_QUALITY_UNKNOWN, SV_QUALITY_OK, SV_QUALITY_REVIEW, SV_QUALITY_BLOCKED];
}

function sv_quality_status_labels(): array
{
    return [
        SV_QUALITY_UNKNOWN => 'Unknown',
        SV_QUALITY_OK      => 'OK',
        SV_QUALITY_REVIEW  => 'Review',
        SV_QUALITY_BLOCKED => 'Blocked',
    ];
}

function sv_normalize_quality_status(?string $value, string $default = SV_QUALITY_REVIEW): string
{
    $value = is_string($value) ? trim($value) : '';
    if ($value === '') {
        return $default;
    }

    $allowed = sv_quality_status_values();
    return in_array($value, $allowed, true) ? $value : $default;
}

function sv_prompt_quality_values(): array
{
    return ['A', 'B', 'C'];
}

function sv_prompt_quality_labels(): array
{
    return [
        'A' => 'A',
        'B' => 'B',
        'C' => 'C',
    ];
}

function sv_forge_limit_error(string $value, int $maxLen = 240): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (function_exists('mb_substr')) {
        return mb_strlen($value) > $maxLen ? mb_substr($value, 0, $maxLen) : $value;
    }

    return strlen($value) > $maxLen ? substr($value, 0, $maxLen) : $value;
}

class SvForgeHttpException extends RuntimeException
{
    private array $logData;

    public function __construct(string $message, array $logData = [], int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->logData = $logData;
    }

    public function getLogData(): array
    {
        return $this->logData;
    }
}

function sv_sanitize_url(string $url): string
{
    $parts = @parse_url($url);
    if (!is_array($parts)) {
        return $url;
    }

    $scheme   = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
    $host     = $parts['host'] ?? '';
    $port     = isset($parts['port']) ? ':' . $parts['port'] : '';
    $path     = $parts['path'] ?? '';
    $query    = isset($parts['query']) ? '?' . $parts['query'] : '';
    $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

    return $scheme . $host . $port . $path . $query . $fragment;
}

function sv_forge_response_snippet(?string $body, int $limit = 200): ?string
{
    if (!is_string($body) || $body === '') {
        return null;
    }

    $substr  = function_exists('mb_substr') ? 'mb_substr' : 'substr';
    $snippet = $substr($body, 0, $limit);
    $snippet = sv_sanitize_error_message($snippet, $limit);
    return $snippet === '' ? null : $snippet;
}

function sv_forge_build_url(string $baseUrl, string $path): string
{
    return rtrim($baseUrl, '/') . $path;
}

function sv_forge_basic_auth_header(array $endpoint): ?string
{
    $user = isset($endpoint['basic_auth_user']) && is_string($endpoint['basic_auth_user'])
        ? trim($endpoint['basic_auth_user'])
        : '';
    $pass = isset($endpoint['basic_auth_pass']) && is_string($endpoint['basic_auth_pass'])
        ? trim($endpoint['basic_auth_pass'])
        : '';

    if ($user === '' && $pass === '') {
        return null;
    }

    return 'Authorization: Basic ' . base64_encode($user . ':' . $pass);
}

function sv_forge_log_payload(string $targetUrl, ?int $httpStatus, ?string $responseBody, array $extra = []): array
{
    $payload = [
        'target_url'       => sv_sanitize_url($targetUrl),
        'http_status'      => $httpStatus,
        'response_snippet' => sv_forge_response_snippet($responseBody),
    ];

    foreach ($extra as $key => $value) {
        $payload[$key] = $value;
    }

    return $payload;
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
    sv_apply_sqlite_pragmas($pdo, $config);
    sv_db_ensure_runtime_indexes($pdo);

    return $pdo;
}

function sv_is_pid_running(int $pid): array
{
    if ($pid <= 0) {
        return ['running' => false, 'unknown' => false];
    }

    if (function_exists('posix_kill')) {
        return [
            'running' => posix_kill($pid, 0),
            'unknown' => false,
        ];
    }

    $osFamily = PHP_OS_FAMILY ?? PHP_OS;
    if (stripos((string)$osFamily, 'Windows') !== false) {
        $output = shell_exec('tasklist /FO CSV /NH /FI "PID eq ' . (int)$pid . '" 2> NUL');
        if ($output === null) {
            return ['running' => false, 'unknown' => true];
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($output));
        if ($lines === false || $lines === []) {
            return ['running' => false, 'unknown' => false];
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || stripos($trimmed, 'INFO:') === 0) {
                continue;
            }
            $cols = str_getcsv($trimmed);
            if (!is_array($cols) || count($cols) < 2) {
                continue;
            }
            $pidValue = (int)trim((string)$cols[1], "\" \t");
            if ($pidValue === $pid) {
                return [
                    'running' => true,
                    'unknown' => false,
                ];
            }
        }

        return [
            'running' => false,
            'unknown' => false,
        ];
    }

    $output = shell_exec('ps -p ' . (int)$pid . ' -o pid=');
    if ($output === null) {
        return ['running' => false, 'unknown' => true];
    }

    return [
        'running' => trim($output) !== '',
        'unknown' => false,
    ];
}

function sv_collect_media_roots(array $pathsCfg): array
{
    $keys = ['images_sfw', 'videos_sfw', 'images_nsfw', 'videos_nsfw'];
    $roots = [];
    foreach ($keys as $key) {
        if (!empty($pathsCfg[$key]) && is_string($pathsCfg[$key])) {
            $roots[] = rtrim(str_replace('\\', '/', (string)$pathsCfg[$key]), '/');
        }
    }

    return $roots;
}

function sv_resolve_media_target(array $mediaRow, array $pathsCfg): ?array
{
    $hash = isset($mediaRow['hash']) ? strtolower((string)$mediaRow['hash']) : '';
    if ($hash === '') {
        return null;
    }

    $type    = (string)($mediaRow['type'] ?? '');
    $hasNsfw = (int)($mediaRow['has_nsfw'] ?? 0) === 1;

    if ($type === 'image') {
        $base = $hasNsfw ? ($pathsCfg['images_nsfw'] ?? null) : ($pathsCfg['images_sfw'] ?? null);
    } elseif ($type === 'video') {
        $base = $hasNsfw ? ($pathsCfg['videos_nsfw'] ?? null) : ($pathsCfg['videos_sfw'] ?? null);
    } else {
        return null;
    }

    if (!is_string($base) || trim($base) === '') {
        return null;
    }

    $ext = strtolower((string)pathinfo((string)($mediaRow['path'] ?? ''), PATHINFO_EXTENSION));
    if ($ext === '') {
        $ext = strtolower((string)($mediaRow['ext'] ?? ''));
    }

    $targetPath = sv_resolve_library_path($hash, $ext, $base);

    return [
        'root' => sv_normalize_directory($base),
        'path' => str_replace('\\', '/', $targetPath),
    ];
}

function sv_assert_backup_outside_media_roots(array $config, string $backupDir): void
{
    $pathsCfg  = $config['paths'] ?? [];
    $roots     = sv_collect_media_roots($pathsCfg);
    $backupAbs = realpath($backupDir) ?: $backupDir;
    $backupAbs = rtrim(str_replace('\\', '/', $backupAbs), '/');

    foreach ($roots as $root) {
        $rootAbs = realpath($root) ?: $root;
        $rootAbs = rtrim(str_replace('\\', '/', $rootAbs), '/');
        if ($rootAbs === '') {
            continue;
        }
        if (str_starts_with($backupAbs . '/', $rootAbs . '/')) {
            throw new RuntimeException('Backup-Verzeichnis darf nicht innerhalb der Medien-Bibliothek liegen.');
        }
    }
}

function sv_resolve_preview_dir(array $config): string
{
    $baseDir  = sv_base_dir();
    $pathsCfg = $config['paths'] ?? [];

    $dir = '';
    if (isset($pathsCfg['previews']) && is_string($pathsCfg['previews']) && trim((string)$pathsCfg['previews']) !== '') {
        $dir = (string)$pathsCfg['previews'];
    } else {
        $dir = $baseDir . '/PREVIEWS';
    }

    $dir = str_replace('\\', '/', $dir);
    if (!str_starts_with($dir, '/')) {
        $dir = $baseDir . '/' . ltrim($dir, '/');
    }

    if (str_contains($dir, '..')) {
        throw new RuntimeException('Preview-Verzeichnis enthält ungültige Traversalbestandteile.');
    }

    $dir = rtrim($dir, '/');
    if ($dir === '') {
        throw new RuntimeException('Preview-Verzeichnis kann nicht aufgelöst werden.');
    }

    $previewAbs = realpath($dir) ?: $dir;

    $roots = sv_collect_media_roots($pathsCfg);
    foreach ($roots as $root) {
        $rootAbs = realpath($root) ?: $root;
        $rootAbs = rtrim(str_replace('\\', '/', $rootAbs), '/');
        if ($rootAbs === '') {
            continue;
        }
        if (str_starts_with($previewAbs . '/', $rootAbs . '/')) {
            throw new RuntimeException('Preview-Verzeichnis darf nicht innerhalb der Medien-Bibliothek liegen: ' . $previewAbs);
        }
    }

    if (!is_dir($previewAbs)) {
        if (!mkdir($previewAbs, 0777, true) && !is_dir($previewAbs)) {
            throw new RuntimeException('Preview-Verzeichnis kann nicht angelegt werden: ' . $previewAbs);
        }
    }

    return rtrim(str_replace('\\', '/', $previewAbs), '/');
}

function sv_is_suspicious_image(string $path): array
{
    $info = @getimagesize($path);
    if ($info === false) {
        return ['suspicious' => true, 'reason' => 'getimagesize failed'];
    }

    $filesize = @filesize($path);
    if ($filesize !== false && $filesize < 10240) {
        return ['suspicious' => true, 'reason' => 'filesize below 10KB'];
    }

    $width  = (int)($info[0] ?? 0);
    $height = (int)($info[1] ?? 0);

    if ($filesize !== false && ($width >= 2000 || $height >= 2000) && $filesize < 20000) {
        return ['suspicious' => true, 'reason' => 'dimension/filesize mismatch'];
    }

    return ['suspicious' => false, 'reason' => null];
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

function sv_record_lifecycle_event(PDO $pdo, int $mediaId, string $eventType, array $payload = []): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO media_lifecycle_events (media_id, event_type, from_status, to_status, quality_status, quality_score, rule, reason, actor, created_at)'
            . ' VALUES (:media_id, :event_type, :from_status, :to_status, :quality_status, :quality_score, :rule, :reason, :actor, :created_at)'
        );
        $stmt->execute([
            ':media_id'       => $mediaId,
            ':event_type'     => $eventType,
            ':from_status'    => $payload['from_status'] ?? null,
            ':to_status'      => $payload['to_status'] ?? null,
            ':quality_status' => $payload['quality_status'] ?? null,
            ':quality_score'  => $payload['quality_score'] ?? null,
            ':rule'           => $payload['rule'] ?? null,
            ':reason'         => $payload['reason'] ?? null,
            ':actor'          => $payload['actor'] ?? 'internal',
            ':created_at'     => date('c'),
        ]);
    } catch (Throwable $e) {
        // Protokollierung darf Ablauf nicht blockieren (Migration optional).
    }
}

function sv_set_media_lifecycle_status(
    PDO $pdo,
    int $mediaId,
    string $newStatus,
    ?string $reason,
    string $actor,
    ?callable $logger = null
): array {
    $newStatus = trim($newStatus) === '' ? SV_LIFECYCLE_REVIEW : $newStatus;
    $stmt = $pdo->prepare('SELECT lifecycle_status, status FROM media WHERE id = ?');
    $stmt->execute([$mediaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new InvalidArgumentException('Media-Eintrag nicht gefunden.');
    }
    $prevLifecycle = (string)($row['lifecycle_status'] ?? SV_LIFECYCLE_ACTIVE);

    $now = date('c');
    try {
        $update = $pdo->prepare('UPDATE media SET lifecycle_status = ?, lifecycle_reason = ?, deleted_at = CASE WHEN ? = ? THEN COALESCE(deleted_at, ?) ELSE deleted_at END WHERE id = ?');
        $update->execute([
            $newStatus,
            $reason,
            $newStatus,
            SV_LIFECYCLE_DELETED,
            $now,
            $mediaId,
        ]);
    } catch (Throwable $e) {
        if ($logger) {
            $logger('Lifecycle-Status konnte nicht geschrieben werden: ' . $e->getMessage());
        }
        sv_audit_log($pdo, 'lifecycle_status_error', 'media', $mediaId, [
            'from_status' => $prevLifecycle,
            'to_status'   => $newStatus,
            'reason'      => $reason,
            'error'       => $e->getMessage(),
        ]);
        throw new RuntimeException('Lifecycle-Status konnte nicht gespeichert werden. Migration/Schema prüfen.', 0, $e);
    }

    sv_record_lifecycle_event($pdo, $mediaId, 'status_change', [
        'from_status' => $prevLifecycle,
        'to_status'   => $newStatus,
        'reason'      => $reason,
        'actor'       => $actor,
    ]);

    if ($logger) {
        $logger('Lifecycle-Status aktualisiert: ' . $prevLifecycle . ' -> ' . $newStatus);
    }

    return [
        'previous' => $prevLifecycle,
        'current'  => $newStatus,
    ];
}

function sv_set_media_quality_status(
    PDO $pdo,
    int $mediaId,
    string $qualityStatus,
    ?float $qualityScore,
    ?string $notes,
    ?string $rule,
    string $actor,
    ?callable $logger = null
): array {
    $qualityStatus = sv_normalize_quality_status($qualityStatus, SV_QUALITY_REVIEW);
    $stmt = $pdo->prepare('SELECT quality_status FROM media WHERE id = ?');
    $stmt->execute([$mediaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new InvalidArgumentException('Media-Eintrag nicht gefunden.');
    }
    $prevStatus = (string)($row['quality_status'] ?? SV_QUALITY_UNKNOWN);

    try {
        $update = $pdo->prepare('UPDATE media SET quality_status = ?, quality_score = ?, quality_notes = ? WHERE id = ?');
        $update->execute([
            $qualityStatus,
            $qualityScore,
            $notes,
            $mediaId,
        ]);
    } catch (Throwable $e) {
        if ($logger) {
            $logger('Quality-Status konnte nicht geschrieben werden: ' . $e->getMessage());
        }
        sv_audit_log($pdo, 'quality_status_error', 'media', $mediaId, [
            'from_status'   => $prevStatus,
            'to_status'     => $qualityStatus,
            'quality_score' => $qualityScore,
            'notes'         => $notes,
            'error'         => $e->getMessage(),
        ]);
        throw new RuntimeException('Quality-Status konnte nicht gespeichert werden. Migration/Schema prüfen.', 0, $e);
    }

    sv_record_lifecycle_event($pdo, $mediaId, 'quality_eval', [
        'quality_status' => $qualityStatus,
        'quality_score'  => $qualityScore,
        'reason'         => $notes,
        'rule'           => $rule,
        'actor'          => $actor,
    ]);

    if ($logger) {
        $logger('Quality-Status aktualisiert: ' . $prevStatus . ' -> ' . $qualityStatus);
    }

    return [
        'previous' => $prevStatus,
        'current'  => $qualityStatus,
    ];
}

function sv_set_media_nsfw_status(PDO $pdo, array $config, int $mediaId, bool $hasNsfw, callable $logger): array
{
    $mediaStmt = $pdo->prepare('SELECT * FROM media WHERE id = :id');
    $mediaStmt->execute([':id' => $mediaId]);
    $media = $mediaStmt->fetch(PDO::FETCH_ASSOC);
    if (!$media) {
        throw new InvalidArgumentException('Media-Eintrag nicht gefunden.');
    }

    $type = (string)($media['type'] ?? '');
    if (!in_array($type, ['image', 'video'], true)) {
        throw new InvalidArgumentException('Nur Bilder/Videos unterstützen den NSFW-Schalter.');
    }

    $pathsCfg = $config['paths'] ?? [];
    $roots    = sv_media_roots($pathsCfg);
    $targetBase = null;
    if ($type === 'image') {
        $targetBase = $hasNsfw ? ($roots['images_nsfw'] ?? null) : ($roots['images_sfw'] ?? null);
    } else {
        $targetBase = $hasNsfw ? ($roots['videos_nsfw'] ?? null) : ($roots['videos_sfw'] ?? null);
    }

    if ($targetBase === null || $targetBase === '') {
        throw new RuntimeException('Kein Zielpfad für NSFW-Umschaltung konfiguriert.');
    }

    $path = (string)($media['path'] ?? '');
    if ($path === '' || !is_file($path)) {
        throw new RuntimeException('Dateipfad nicht vorhanden.');
    }

    sv_assert_media_path_allowed($path, $pathsCfg, 'nsfw_toggle');

    $normalizedTarget = rtrim(str_replace('\\', '/', $targetBase), '/');
    $currentRootKey   = sv_media_root_for_path($path, $pathsCfg);
    $needsMove        = true;

    if ($currentRootKey !== null) {
        $currentRoot = $roots[$currentRootKey] ?? null;
        if ($currentRoot !== null && str_starts_with(str_replace('\\', '/', $path), $currentRoot)) {
            $needsMove = str_starts_with($currentRootKey, 'images') ? ($hasNsfw ? $currentRootKey !== 'images_nsfw' : $currentRootKey !== 'images_sfw') : ($hasNsfw ? $currentRootKey !== 'videos_nsfw' : $currentRootKey !== 'videos_sfw');
        }
    }

    $newPath = $path;
    $moved   = false;

    if ($needsMove) {
        $fileName      = basename($path);
        $candidateBase = $normalizedTarget . '/' . $fileName;
        $candidate     = $candidateBase;
        $i             = 1;
        while (is_file($candidate) && $candidate !== $path) {
            $candidate = $normalizedTarget . '/' . $i . '_' . $fileName;
            $i++;
        }

        if (!sv_move_file($path, $candidate)) {
            throw new RuntimeException('Datei konnte nicht verschoben werden.');
        }
        $newPath = str_replace('\\', '/', $candidate);
        $moved   = true;
    }

    $stmt = $pdo->prepare('UPDATE media SET has_nsfw = ?, path = ?, status = "active" WHERE id = ?');
    $stmt->execute([
        $hasNsfw ? 1 : 0,
        $newPath,
        $mediaId,
    ]);

    sv_audit_log($pdo, 'media_nsfw_toggle', 'media', $mediaId, [
        'previous' => (int)($media['has_nsfw'] ?? 0),
        'current'  => $hasNsfw ? 1 : 0,
        'moved'    => $moved,
        'old_path' => $path,
        'new_path' => $newPath,
    ]);

    $logger('NSFW-Status angepasst: ' . (($media['has_nsfw'] ?? 0) ? '1' : '0') . ' → ' . ($hasNsfw ? '1' : '0') . ($moved ? ' (verschoben)' : ''));

    return [
        'moved'   => $moved,
        'old'     => (int)($media['has_nsfw'] ?? 0) === 1,
        'current' => $hasNsfw,
        'path'    => $newPath,
    ];
}

function sv_fetch_prompt_snapshot(PDO $pdo, int $mediaId): ?array
{
    try {
        $metaStmt = $pdo->prepare("SELECT source, meta_key, meta_value FROM media_meta WHERE media_id = :media_id ORDER BY id DESC");
        $metaStmt->execute([':media_id' => $mediaId]);
        $metaRows = $metaStmt->fetchAll(PDO::FETCH_ASSOC);

        $raw = null;
        $metaPairs = [];
        foreach ($metaRows as $metaRow) {
            $key = (string)($metaRow['meta_key'] ?? '');
            $val = $metaRow['meta_value'] ?? null;
            $src = (string)($metaRow['source'] ?? 'snapshot');
            if ($key === 'prompt_raw' && is_string($val) && trim($val) !== '') {
                $raw = (string)$val;
            }
            $metaPairs[] = [
                'source' => $src,
                'key'    => $key,
                'value'  => $val,
            ];
        }

        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $structuredPairs = array_filter($metaPairs, static function (array $pair): bool {
            return isset($pair['key']) && $pair['key'] !== 'prompt_raw';
        });

        return [
            'meta_pairs'        => array_values($metaPairs),
            'prompt_candidates' => [
                [
                    'source'    => (string)($metaRows[0]['source'] ?? 'snapshot'),
                    'key'       => 'prompt_raw',
                    'short_key' => 'snapshot',
                    'text'      => $raw,
                    'type'      => 'snapshot',
                ],
            ],
            'raw_block' => $raw,
            'snapshot_incomplete' => $structuredPairs === [],
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function sv_create_forge_job(PDO $pdo, array $config, int $mediaId, array $payload, ?int $promptId, callable $logger): int
{
    $now = date('c');
    $requestJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    sv_enforce_job_queue_capacity($pdo, $config, SV_FORGE_JOB_TYPE, $mediaId);

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

function sv_is_forge_enabled(array $config): bool
{
    if (!isset($config['forge']) || !is_array($config['forge'])) {
        return false;
    }

    if (!array_key_exists('enabled', $config['forge'])) {
        return true;
    }

    return (bool)$config['forge']['enabled'];
}

function sv_is_forge_dispatch_enabled(array $config): bool
{
    if (!sv_is_forge_enabled($config)) {
        return false;
    }

    if (!isset($config['forge']['dispatch_enabled'])) {
        return true;
    }

    return (bool)$config['forge']['dispatch_enabled'];
}

function sv_forge_timeout(array $config): int
{
    $timeout = isset($config['forge']['timeout']) ? (int)$config['forge']['timeout'] : 15;
    return $timeout > 0 ? $timeout : 15;
}

function sv_forge_fallback_model(array $config): string
{
    if (isset($config['forge']['internal_model_fallback']) && is_string($config['forge']['internal_model_fallback'])) {
        $candidate = trim($config['forge']['internal_model_fallback']);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return SV_FORGE_FALLBACK_MODEL;
}

function sv_forge_endpoint_config(array $config, bool $requireDispatchEnabled = false): ?array
{
    if (!sv_is_forge_enabled($config)) {
        return null;
    }

    if (!isset($config['forge']) || !is_array($config['forge'])) {
        return null;
    }

    if ($requireDispatchEnabled && !sv_is_forge_dispatch_enabled($config)) {
        return null;
    }

    $forge = $config['forge'];
    $baseUrl = isset($forge['base_url']) && is_string($forge['base_url']) ? trim($forge['base_url']) : '';
    if ($baseUrl === '') {
        $baseUrl = SV_FORGE_DEFAULT_BASE_URL;
    }

    $timeout = sv_forge_timeout($config);

    return [
        'base_url'        => $baseUrl,
        'timeout'         => $timeout > 0 ? $timeout : 15,
        'basic_auth_user' => isset($forge['basic_auth_user']) && is_string($forge['basic_auth_user'])
            ? trim($forge['basic_auth_user'])
            : '',
        'basic_auth_pass' => isset($forge['basic_auth_pass']) && is_string($forge['basic_auth_pass'])
            ? trim($forge['basic_auth_pass'])
            : '',
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

function sv_forge_healthcheck(array $endpoint, callable $logger): array
{
    $baseUrl = rtrim((string)($endpoint['base_url'] ?? SV_FORGE_DEFAULT_BASE_URL), '/');
    $url     = sv_forge_build_url($baseUrl, SV_FORGE_OPTIONS_PATH);
    $timeout = (int)($endpoint['timeout'] ?? 10);

    $headers = ['Accept: application/json'];
    $authHeader = sv_forge_basic_auth_header($endpoint);
    if ($authHeader !== null) {
        $headers[] = $authHeader;
    }

    $context = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => implode("\r\n", $headers),
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

    if ($httpCode !== null && $httpCode >= 200 && $httpCode < 300) {
        $logger('Forge-Healthcheck erfolgreich (options).');
        return [
            'ok'          => true,
            'http_code'   => $httpCode,
            'body'        => $responseBody === false ? null : $responseBody,
            'target_url'  => $url,
            'log_payload' => sv_forge_log_payload($url, $httpCode, $responseBody),
        ];
    }

    $logger('Forge-Healthcheck fehlgeschlagen' . ($httpCode !== null ? ' (HTTP ' . $httpCode . ')' : '')); 
    return [
        'ok'          => false,
        'http_code'   => $httpCode,
        'body'        => $responseBody === false ? null : $responseBody,
        'target_url'  => $url,
        'log_payload' => sv_forge_log_payload($url, $httpCode, $responseBody),
    ];
}

function sv_forge_fetch_model_list(array $config, callable $logger): ?array
{
    $result = [
        'ok'         => false,
        'status'     => 'unavailable',
        'error'      => null,
        'http_code'  => null,
        'models'     => null,
        'target_url' => null,
        'fetched_at' => time(),
    ];

    if (!sv_is_forge_enabled($config)) {
        $logger('Forge deaktiviert; Modellliste wird nicht abgefragt.');
        $result['status'] = 'disabled';
        return $result;
    }

    $endpoint = sv_forge_endpoint_config($config) ?? [
        'base_url' => sv_forge_base_url($config),
        'timeout'  => sv_forge_timeout($config),
    ];
    $baseUrl = rtrim((string)($endpoint['base_url'] ?? sv_forge_base_url($config)), '/');
    $url     = $baseUrl . SV_FORGE_MODEL_LIST_PATH;
    $result['target_url'] = sv_sanitize_url($url);

    $timeout = (int)($endpoint['timeout'] ?? sv_forge_timeout($config));

    $headers = ['Accept: application/json'];
    $authHeader = sv_forge_basic_auth_header($endpoint);
    if ($authHeader !== null) {
        $headers[] = $authHeader;
    }

    $context = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => implode("\r\n", $headers),
            'timeout' => $timeout,
        ],
    ]);

    $lastError = null;
    set_error_handler(static function ($severity, $message) use (&$lastError): bool {
        $lastError = $message;
        return false;
    });
    try {
        $responseBody = @file_get_contents($url, false, $context);
    } catch (Throwable $e) {
        restore_error_handler();
        $msg = sv_forge_limit_error($e->getMessage());
        $logger('Forge-Modelliste konnte nicht geladen werden: ' . $msg);
        $result['status'] = 'transport_error';
        $result['error']  = $msg;
        return $result;
    }
    restore_error_handler();

    if ($responseBody === false) {
        $status = 'http_error';
        if (is_string($lastError) && stripos($lastError, 'timed out') !== false) {
            $status = 'timeout';
        }
        $logger('Forge-Modelliste konnte nicht geladen werden (' . $status . ').');
        $result['status'] = $status;
        $result['error']  = $lastError ? sv_forge_limit_error($lastError) : null;
        return $result;
    }

    $httpCode = null;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            if (preg_match('~^HTTP/[^ ]+ ([0-9]{3})~', (string)$headerLine, $matches)) {
                $httpCode = (int)$matches[1];
                break;
            }
        }
    }
    $result['http_code'] = $httpCode;

    if ($httpCode !== null && ($httpCode < 200 || $httpCode >= 300)) {
        $status = ($httpCode === 401 || $httpCode === 403) ? 'auth_failed' : 'http_error';
        $logger('Forge-Modelliste HTTP-Fehler ' . $httpCode . ' (' . $status . ').');
        $result['status'] = $status;
        $result['error']  = 'HTTP ' . $httpCode;
        return $result;
    }

    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        $logger('Forge-Modelliste ungültig oder leer.');
        $result['status'] = 'parse_error';
        $result['error']  = 'JSON decode failed';
        return $result;
    }

    $result['ok']     = true;
    $result['status'] = 'ok';
    $result['models'] = $decoded;

    return $result;
}

function sv_forge_model_cache_path(array $config): string
{
    return sv_log_path($config, 'forge_models.cache.json');
}

function sv_forge_normalize_models(array $modelsRaw, string $fallback): array
{
    $models    = [];
    $seen      = [];

    foreach ($modelsRaw as $model) {
        if (!is_array($model)) {
            continue;
        }
        $name = '';
        foreach (['model_name', 'title', 'filename', 'name'] as $field) {
            if (isset($model[$field]) && is_string($model[$field]) && trim((string)$model[$field]) !== '') {
                $candidate = trim((string)$model[$field]);
                if ($candidate !== '') {
                    $name = $candidate;
                    break;
                }
            }
        }
        if ($name === '') {
            continue;
        }
        $lower = strtolower($name);
        if (isset($seen[$lower])) {
            continue;
        }
        $seen[$lower] = true;
        $models[] = [
            'name'  => $name,
            'title' => isset($model['title']) && is_string($model['title']) ? (string)$model['title'] : null,
            'hash'  => isset($model['hash']) && is_string($model['hash']) ? (string)$model['hash'] : null,
        ];
    }

    if ($fallback !== '' && !isset($seen[strtolower($fallback)])) {
        $models[] = [
            'name'  => $fallback,
            'title' => 'Fallback',
            'hash'  => null,
        ];
    }

    return $models;
}

function sv_forge_list_models(array $config, callable $logger, int $ttlSeconds = SV_FORGE_MODEL_CACHE_TTL): array
{
    $cacheFile = sv_forge_model_cache_path($config);
    $now       = time();
    $ttl       = max(10, $ttlSeconds);
    $result    = [
        'models'     => [],
        'status'     => 'unavailable',
        'error'      => null,
        'source'     => 'live',
        'http_code'  => null,
        'fetched_at' => null,
        'age'        => null,
        'ttl'        => $ttl,
    ];

    $cachedPayload = null;
    if (is_file($cacheFile) && is_readable($cacheFile)) {
        $raw = @file_get_contents($cacheFile);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['fetched_at'], $decoded['models'])) {
                $cachedPayload = $decoded;
                $age = $now - (int)$decoded['fetched_at'];
                if ($age >= 0 && $age <= $ttl) {
                    $logger('Forge-Modelliste aus Cache (' . $age . 's alt).');
                    $result['models']     = is_array($decoded['models']) ? $decoded['models'] : [];
                    $result['status']     = $decoded['status'] ?? 'ok';
                    $result['error']      = $decoded['error'] ?? null;
                    $result['source']     = 'cache';
                    $result['fetched_at'] = (int)$decoded['fetched_at'];
                    $result['age']        = $age;
                    $result['http_code']  = $decoded['http_code'] ?? null;
                    return $result;
                }
            }
        }
    }

    $fetch = sv_forge_fetch_model_list($config, $logger);
    $modelsRaw = is_array($fetch['models'] ?? null) ? $fetch['models'] : [];
    $fallback = sv_forge_fallback_model($config);
    $normalized = sv_forge_normalize_models($modelsRaw, $fallback);

    $result['models']     = $normalized;
    $result['status']     = $fetch['status'] ?? 'unavailable';
    $result['error']      = $fetch['error'] ?? null;
    $result['http_code']  = $fetch['http_code'] ?? null;
    $result['fetched_at'] = $fetch['fetched_at'] ?? $now;
    $result['age']        = 0;
    $result['source']     = ($fetch['status'] ?? '') === 'disabled' ? 'disabled' : 'live';

    if ($fetch['ok'] ?? false) {
        $payload = json_encode(
            [
                'fetched_at' => $result['fetched_at'],
                'models'     => $normalized,
                'status'     => $result['status'],
                'error'      => $result['error'],
                'http_code'  => $result['http_code'],
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($payload !== false) {
            $cacheDir = dirname($cacheFile);
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0775, true);
            }
            @file_put_contents($cacheFile, $payload);
        }
        $result['source'] = 'live';
        return $result;
    }

    if ($cachedPayload !== null && isset($cachedPayload['models'], $cachedPayload['fetched_at'])) {
        $age = $now - (int)$cachedPayload['fetched_at'];
        $logger('Forge-Modelliste: Live-Request fehlgeschlagen, nutze Cache (' . $age . 's alt).');
        $result['models']     = is_array($cachedPayload['models']) ? $cachedPayload['models'] : [];
        $result['source']     = 'stale_cache';
        $result['fetched_at'] = (int)$cachedPayload['fetched_at'];
        $result['age']        = $age;
        $result['status']     = $fetch['status'] ?? ($cachedPayload['status'] ?? 'unavailable');
        $result['error']      = $fetch['error'] ?? ($cachedPayload['error'] ?? null);
        $result['http_code']  = $fetch['http_code'] ?? ($cachedPayload['http_code'] ?? null);
        return $result;
    }

    if ($normalized === [] && $fallback !== '') {
        $result['models'] = [['name' => $fallback, 'title' => 'Fallback', 'hash' => null]];
        $result['source'] = 'fallback';
    }

    return $result;
}

function sv_resolve_forge_model(array $config, ?string $requestedModelName, callable $logger, ?array $modelListResult = null, ?array &$meta = null): string
{
    $requested = trim((string)$requestedModelName);
    $fallback  = sv_forge_fallback_model($config);
    $meta = [
        'requested'     => $requested,
        'fallback'      => $fallback,
        'model_source'  => 'live',
        'model_status'  => null,
        'model_error'   => null,
        'fallback_used' => false,
    ];

    if ($modelListResult === null) {
        $modelListResult = sv_forge_list_models($config, $logger);
    }

    $models = is_array($modelListResult['models'] ?? null) ? $modelListResult['models'] : [];
    $meta['model_source'] = $modelListResult['source'] ?? 'live';
    $meta['model_status'] = $modelListResult['status'] ?? null;
    $meta['model_error']  = $modelListResult['error'] ?? null;

    if ($models === null || $models === []) {
        $meta['fallback_used'] = true;
        $logger('Forge model fallback genutzt (keine Modellliste verfügbar).');
        return $fallback;
    }

    if ($requested === '') {
        $meta['fallback_used'] = true;
        $logger('Forge model fallback genutzt (kein Modell angegeben).');
        return $fallback !== '' ? $fallback : (string)($models[0]['name'] ?? '');
    }

    $requestedLower = strtolower($requested);

    foreach ($models as $model) {
        if (!is_array($model)) {
            continue;
        }

        $candidate = isset($model['name']) && is_string($model['name']) ? trim((string)$model['name']) : '';
        if ($candidate === '') {
            continue;
        }

        if (strtolower($candidate) === $requestedLower) {
            $meta['fallback_used'] = ($candidate === $fallback);
            $logger('Forge-Modell bestätigt: ' . $candidate . '.');
            return $candidate;
        }
    }

    $meta['fallback_used'] = true;
    $logger('Forge model fallback genutzt (requested=' . $requested . ', fallback=' . $fallback . ').');
    return $fallback;
}

function sv_forge_target_path(array $payload): string
{
    $hasInitImages = isset($payload['init_images']) && is_array($payload['init_images']) && $payload['init_images'] !== [];
    return $hasInitImages ? SV_FORGE_IMG2IMG_PATH : SV_FORGE_TXT2IMG_PATH;
}

function sv_dispatch_forge_job(PDO $pdo, array $config, int $jobId, array $payload, callable $logger): array
{
    if (!sv_is_forge_dispatch_enabled($config)) {
        $logger('Forge-Dispatch übersprungen: Forge ist deaktiviert oder Dispatch ist abgeschaltet.');
        return [
            'dispatched' => false,
            'status'     => 'queued',
            'message'    => 'Forge-Dispatch ist deaktiviert.',
        ];
    }

    $endpoint = sv_forge_endpoint_config($config, true);
    if ($endpoint === null) {
        $logger('Forge-Dispatch übersprungen: keine gültige Forge-Konfiguration.');
        return [
            'dispatched' => false,
            'status'     => 'queued',
            'message'    => 'Forge-Konfiguration fehlt oder ist unvollständig.',
        ];
    }

    $health = sv_forge_healthcheck($endpoint, $logger);
    if (!$health['ok']) {
        $status       = 'error';
        $errorMessage = sv_forge_limit_error('Forge-Healthcheck fehlgeschlagen' . ($health['http_code'] !== null ? ' (HTTP ' . $health['http_code'] . ')' : ''));
        $responseJson = json_encode($health['log_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        sv_update_job_status($pdo, $jobId, $status, $responseJson, $errorMessage);

        sv_audit_log($pdo, 'forge_job_dispatch_failed', 'jobs', $jobId, [
            'status'      => $status,
            'http_code'   => $health['http_code'],
            'error'       => $errorMessage,
            'target_url'  => $health['log_payload']['target_url'] ?? null,
        ]);

        return [
            'dispatched' => false,
            'status'     => $status,
            'error'      => $errorMessage,
            'response'   => $responseJson,
        ];
    }

    $claimReason = null;
    if (!sv_claim_job_running($pdo, $jobId, SV_FORGE_JOB_STATUSES, ['dispatch' => 'forge'], $claimReason)) {
        $logger('Forge-Dispatch übersprungen: Claim fehlgeschlagen (' . ($claimReason ?? 'claim_failed') . ').');
        return [
            'dispatched' => false,
            'status'     => 'queued',
            'error'      => $claimReason ?? 'claim_failed_or_already_running',
        ];
    }

    $url      = sv_forge_build_url($endpoint['base_url'], sv_forge_target_path($payload));
    $timeout  = $endpoint['timeout'];
    $headers  = [
        'Content-Type: application/json',
    ];

    $authHeader = sv_forge_basic_auth_header($endpoint);
    if ($authHeader !== null) {
        $headers[] = $authHeader;
    }

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

    $logPayload   = sv_forge_log_payload($url, $httpCode, $responseBody);
    $responseJson = json_encode($logPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($httpCode !== null && $httpCode >= 200 && $httpCode < 300 && $responseBody !== false) {
        $status = 'running';
        $logger('Forge-Dispatch erfolgreich, Status auf running gesetzt.');

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

    sv_update_job_status($pdo, $jobId, $status, $responseJson, $error);

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

function sv_prepare_forge_regen_job(PDO $pdo, array $config, int $mediaId, callable $logger, array $overrides = []): array
{
    $mediaRow = sv_load_media_with_prompt($pdo, $mediaId);
    $type     = (string)($mediaRow['type'] ?? '');
    $status   = (string)($mediaRow['status'] ?? '');
    $normalizedOverrides = sv_normalize_forge_overrides($overrides);

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
    $regenPlan  = sv_prepare_forge_regen_prompt($mediaRow, $tags, $logger, $normalizedOverrides);
    $promptId   = isset($mediaRow['prompt_id']) ? (int)$mediaRow['prompt_id'] : null;

    $imageInfo = @getimagesize($path);
    $origWidth = $imageInfo !== false ? (int)$imageInfo[0] : (int)($mediaRow['width'] ?? 0);
    $origHeight = $imageInfo !== false ? (int)$imageInfo[1] : (int)($mediaRow['height'] ?? 0);

    $payload = [
        'prompt'          => $regenPlan['final_prompt'],
        'negative_prompt' => '',
        'model'           => (string)($mediaRow['model'] ?? ''),
        'sampler'         => (string)($mediaRow['sampler'] ?? ''),
        'cfg_scale'       => isset($mediaRow['cfg_scale']) ? (float)$mediaRow['cfg_scale'] : 7.0,
        'steps'           => isset($mediaRow['steps']) ? (int)$mediaRow['steps'] : 30,
        'seed'            => (string)($mediaRow['seed'] ?? ''),
        'width'           => $origWidth,
        'height'          => $origHeight,
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

    if ($payload['steps'] <= 0) {
        $payload['steps'] = 30;
    }
    if (!isset($payload['cfg_scale']) || (float)$payload['cfg_scale'] <= 0) {
        $payload['cfg_scale'] = 7.0;
    }

    if ($normalizedOverrides['seed'] !== null) {
        $payload['seed'] = (string)$normalizedOverrides['seed'];
        $seedInfo        = ['seed' => $payload['seed'], 'created' => false];
    } else {
        $seedInfo        = sv_ensure_media_seed($pdo, (int)$mediaRow['id'], $payload['seed'], $logger);
        $payload['seed'] = $seedInfo['seed'];
    }

    $negativePlan = sv_resolve_negative_prompt($mediaRow, $normalizedOverrides, $regenPlan, $tags);
    $payload['negative_prompt'] = $negativePlan['negative_prompt'];

    if ($normalizedOverrides['sampler'] !== null) {
        $payload['sampler'] = $normalizedOverrides['sampler'];
    }
    if ($normalizedOverrides['scheduler'] !== null) {
        $payload['scheduler'] = $normalizedOverrides['scheduler'];
    }
    if ($normalizedOverrides['steps'] !== null) {
        $payload['steps'] = (int)$normalizedOverrides['steps'];
    }
    if ($normalizedOverrides['denoising_strength'] !== null) {
        $payload['denoising_strength'] = (float)$normalizedOverrides['denoising_strength'];
    }
    $requestedModel   = $normalizedOverrides['model'] ?? (string)($payload['model'] ?? '');
    $modelListResult  = sv_forge_list_models($config, $logger);
    $modelResolution  = [];
    $resolvedModel    = sv_resolve_forge_model($config, $requestedModel, $logger, $modelListResult, $modelResolution);
    $payload['model'] = $resolvedModel;
    $payload['_sv_requested_model'] = $requestedModel;
    $payload['_sv_model_source']    = $modelResolution['model_source'] ?? ($modelListResult['source'] ?? null);
    $payload['_sv_model_status']    = $modelResolution['model_status'] ?? ($modelListResult['status'] ?? null);
    if (!empty($modelResolution['model_error'])) {
        $payload['_sv_model_error'] = sv_forge_limit_error((string)$modelResolution['model_error']);
    } elseif (!empty($modelListResult['error'])) {
        $payload['_sv_model_error'] = sv_forge_limit_error((string)$modelListResult['error']);
    }

    $decision = sv_decide_forge_mode($mediaRow, $regenPlan, $normalizedOverrides, $payload);
    $generationMode = $decision['mode'] ?? 'img2img';
    if ($generationMode === 'img2img') {
        $payload['init_images'] = [sv_encode_init_image($path)];
        if (!isset($payload['denoising_strength']) && isset($decision['params']['denoising_strength'])) {
            $payload['denoising_strength'] = (float)$decision['params']['denoising_strength'];
        }
    }
    $payload['_sv_generation_mode'] = $generationMode;
    $payload['_sv_decided_mode']    = $generationMode;
    $payload['_sv_decided_reason']  = $decision['reason'] ?? null;
    $payload['_sv_decided_denoise'] = isset($payload['denoising_strength']) ? (float)$payload['denoising_strength'] : null;

    $origExt = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $negativeSource = match ($negativePlan['negative_mode']) {
        'manual', 'manual_empty' => 'manual',
        'fallback'               => 'fallback',
        default                  => 'empty',
    };
    $payload['_sv_prompt_source']   = $regenPlan['prompt_source'] ?? null;
    $payload['_sv_negative_source'] = $negativeSource;

    $payload['_sv_regen_plan'] = [
        'category'         => $regenPlan['category'],
        'final_prompt'     => $regenPlan['final_prompt'],
        'fallback_used'    => $regenPlan['fallback_used'],
        'tag_prompt_used'  => $regenPlan['tag_prompt_used'],
        'original_prompt'  => $regenPlan['original_prompt'] ?? null,
        'quality_score'    => $regenPlan['assessment']['score'] ?? null,
        'quality_issues'   => $regenPlan['assessment']['issues'] ?? null,
        'tag_fragment'     => $regenPlan['tag_fragment'] ?? null,
        'seed_generated'   => $seedInfo['created'],
        'prompt_missing'   => $regenPlan['prompt_missing'],
        'prompt_source'    => $regenPlan['prompt_source'] ?? null,
        'tags_used_count'  => $regenPlan['tags_used_count'] ?? 0,
        'tags_limited'     => $regenPlan['tags_limited'] ?? false,
        'negative_mode'    => $negativePlan['negative_mode'],
        'negative_len'     => $negativePlan['negative_len'],
        'orig_width'       => $origWidth,
        'orig_height'      => $origHeight,
        'orig_ext'         => $origExt,
        'allow_empty_neg'  => !empty($normalizedOverrides['negative_allow_empty']),
        'manual_prompt'    => $normalizedOverrides['manual_prompt'] ?? null,
        'manual_negative'  => $normalizedOverrides['manual_negative_set']
            ? ($normalizedOverrides['manual_negative'] ?? '')
            : null,
        'use_hybrid'       => !empty($normalizedOverrides['use_hybrid']),
        'decided_mode'     => $generationMode,
        'decided_reason'   => $decision['reason'] ?? null,
        'decided_denoise'  => $payload['_sv_decided_denoise'],
    ];

    $modeOverride = $normalizedOverrides['mode'];

    $resolvedMode = 'preview';
    if (is_string($modeOverride)) {
        $candidate = strtolower(trim($modeOverride));
        if (in_array($candidate, ['preview', 'replace'], true)) {
            $resolvedMode = $candidate;
        }
    }

    $payload['_sv_mode'] = $resolvedMode;
    $payload['_sv_force_txt2img'] = $normalizedOverrides['force_txt2img'] ?? false;

    $payload['_sv_negative_mode'] = $negativePlan['negative_mode'];
    $payload['_sv_negative_len']  = $negativePlan['negative_len'];

    return [
        'payload'         => $payload,
        'prompt_id'       => $promptId,
        'regen_plan'      => $regenPlan,
        'requested_model' => $requestedModel,
        'resolved_model'  => $resolvedModel,
        'model_source'    => $payload['_sv_model_source'] ?? null,
        'model_status'    => $payload['_sv_model_status'] ?? null,
        'model_error'     => $payload['_sv_model_error'] ?? null,
        'media_row'       => $mediaRow,
        'path'            => $path,
        'negative_mode'   => $negativePlan['negative_mode'],
        'negative_len'    => $negativePlan['negative_len'],
    ];
}

function sv_queue_forge_regeneration(PDO $pdo, array $config, int $mediaId, callable $logger, array $overrides = []): array
{
    if (!sv_is_forge_enabled($config)) {
        throw new RuntimeException('Forge ist deaktiviert.');
    }

    $jobData = sv_prepare_forge_regen_job($pdo, $config, $mediaId, $logger, $overrides);
    $fallbackModel = sv_forge_fallback_model($config);

    $jobId = sv_create_forge_job(
        $pdo,
        $config,
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
            'fallback_used'   => $jobData['resolved_model'] === $fallbackModel,
        ]);
    }

    return [
        'job_id'          => $jobId,
        'status'          => 'queued',
        'resolved_model'  => $jobData['resolved_model'],
        'requested_model' => $jobData['requested_model'],
        'model_source'    => $jobData['model_source'] ?? null,
        'model_status'    => $jobData['model_status'] ?? null,
        'model_error'     => $jobData['model_error'] ?? null,
        'regen_plan'      => $jobData['regen_plan'],
    ];
}

function sv_prepare_forge_repair_job(PDO $pdo, array $config, int $mediaId, array $options, callable $logger): array
{
    $mediaRow = sv_load_media_with_prompt($pdo, $mediaId);
    $type     = (string)($mediaRow['type'] ?? '');
    $status   = (string)($mediaRow['status'] ?? '');

    if ($type !== 'image') {
        throw new InvalidArgumentException('Nur Bildmedien können repariert werden.');
    }
    if ($status === 'missing') {
        throw new RuntimeException('Repair für fehlende Dateien nicht möglich.');
    }

    $path = (string)($mediaRow['path'] ?? '');
    if ($path === '' || !is_file($path)) {
        throw new RuntimeException('Dateipfad nicht vorhanden.');
    }

    $pathsCfg = $config['paths'] ?? [];
    sv_assert_media_path_allowed($path, $pathsCfg, 'forge_regen_replace');

    $tags = sv_fetch_media_tags_for_regen($pdo, $mediaId, SV_FORGE_MAX_TAGS_PROMPT);
    $mediaRow['tags'] = $tags;
    $mediaRow['_image_exists'] = is_file($path);

    $recipes = sv_load_forge_recipes($config, $logger);
    $resolved = sv_resolve_forge_repair($mediaRow, $options, $recipes);

    $seedInfo = sv_ensure_media_seed($pdo, (int)$mediaRow['id'], (string)($resolved['params']['seed'] ?? ''), $logger);
    $seedPolicy = (string)($resolved['seed_policy'] ?? 'keep');
    $seedUsed = $seedInfo['seed'];
    $seedSource = $seedInfo['created'] ? 'generated' : 'stored';
    if ($seedPolicy === 'new') {
        $seedUsed = (string)random_int(1, 2147483647);
        $seedSource = 'policy:new';
    }

    $payload = [
        'prompt'          => (string)$resolved['prompt'],
        'negative_prompt' => (string)$resolved['negative'],
        'model'           => (string)($resolved['params']['model'] ?? ''),
        'sampler'         => (string)($resolved['params']['sampler'] ?? ''),
        'cfg_scale'       => (float)($resolved['params']['cfg'] ?? 7.0),
        'steps'           => (int)($resolved['params']['steps'] ?? 30),
        'seed'            => (string)$seedUsed,
        'width'           => (int)($resolved['params']['width'] ?? 1024),
        'height'          => (int)($resolved['params']['height'] ?? 1024),
    ];

    if (!empty($resolved['params']['scheduler'])) {
        $payload['scheduler'] = (string)$resolved['params']['scheduler'];
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

    if (!empty($resolved['variants']) && (int)$resolved['variants'] > 1) {
        $payload['batch_size'] = (int)$resolved['variants'];
        $payload['n_iter'] = 1;
    }

    $requestedModel = (string)($payload['model'] ?? '');
    $modelListResult = sv_forge_list_models($config, $logger);
    $modelResolution = [];
    $resolvedModel = sv_resolve_forge_model($config, $requestedModel, $logger, $modelListResult, $modelResolution);
    $payload['model'] = $resolvedModel;
    $payload['_sv_requested_model'] = $requestedModel;
    $payload['_sv_model_source']    = $modelResolution['model_source'] ?? ($modelListResult['source'] ?? null);
    $payload['_sv_model_status']    = $modelResolution['model_status'] ?? ($modelListResult['status'] ?? null);
    if (!empty($modelResolution['model_error'])) {
        $payload['_sv_model_error'] = sv_forge_limit_error((string)$modelResolution['model_error']);
    } elseif (!empty($modelListResult['error'])) {
        $payload['_sv_model_error'] = sv_forge_limit_error((string)$modelListResult['error']);
    }

    $generationMode = $resolved['mode'] === 'img2img' ? 'img2img' : 'txt2img';
    if ($generationMode === 'img2img') {
        $payload['init_images'] = [sv_encode_init_image($path)];
        if (!isset($payload['denoising_strength']) && $resolved['denoise'] !== null) {
            $payload['denoising_strength'] = (float)$resolved['denoise'];
        }
    }
    $payload['_sv_generation_mode'] = $generationMode;
    $payload['_sv_decided_mode']    = $generationMode;
    $payload['_sv_decided_reason']  = $resolved['mode_reason'] ?? null;
    $payload['_sv_decided_denoise'] = $payload['denoising_strength'] ?? null;

    $payload['_sv_prompt_source']   = $resolved['source_used'] ?? null;
    $payload['_sv_negative_source'] = $resolved['negative_source'] ?? null;
    $payload['_sv_negative_mode']   = $resolved['negative_source'] ?? null;
    $payload['_sv_negative_len']    = $resolved['negative_len'] ?? null;
    $payload['_sv_mode']            = 'preview';
    $payload['_sv_job_label']       = 'Repair';

    $payload['_sv_regen_plan'] = [
        'category'         => 'repair',
        'final_prompt'     => $resolved['prompt'],
        'original_prompt'  => $mediaRow['prompt'] ?? null,
        'prompt_missing'   => $resolved['prompt_missing'] ?? false,
        'prompt_source'    => $resolved['source_used'] ?? null,
        'tags_used_count'  => $resolved['tags_used_count'] ?? 0,
        'tags_limited'     => $resolved['tags_limited'] ?? false,
        'negative_mode'    => $resolved['negative_source'] ?? null,
        'negative_len'     => $resolved['negative_len'] ?? 0,
        'seed_generated'   => $seedInfo['created'],
        'seed_source'      => $seedSource,
        'recipe_id'        => $resolved['recipe_id'] ?? null,
        'goal'             => $resolved['goal'] ?? null,
        'intensity'        => $resolved['intensity'] ?? null,
        'tech_fix'         => $resolved['tech_fix'] ?? null,
        'mode_hint'        => $resolved['mode_hint'] ?? null,
        'mode'             => $generationMode,
        'variants'         => $resolved['variants'] ?? 1,
        'seed_policy'      => $seedPolicy,
        'applied_deltas'   => $resolved['applied_deltas'] ?? '',
        'model_family'     => $resolved['model_family'] ?? null,
        'decided_mode'     => $generationMode,
        'decided_reason'   => $resolved['mode_reason'] ?? null,
        'decided_denoise'  => $payload['_sv_decided_denoise'],
    ];

    $payload['_sv_repair_meta'] = [
        'recipe_id'      => $resolved['recipe_id'] ?? null,
        'source_used'    => $resolved['source_used'] ?? null,
        'goal'           => $resolved['goal'] ?? null,
        'intensity'      => $resolved['intensity'] ?? null,
        'tech_fix'       => $resolved['tech_fix'] ?? null,
        'applied_deltas' => $resolved['applied_deltas'] ?? '',
        'seed_policy'    => $seedPolicy,
    ];

    $promptId = $resolved['source_used'] === 'prompt' && isset($mediaRow['prompt_id'])
        ? (int)$mediaRow['prompt_id']
        : null;

    return [
        'payload'         => $payload,
        'prompt_id'       => $promptId,
        'repair_plan'     => $resolved,
        'requested_model' => $requestedModel,
        'resolved_model'  => $resolvedModel,
        'model_source'    => $payload['_sv_model_source'] ?? null,
        'model_status'    => $payload['_sv_model_status'] ?? null,
        'model_error'     => $payload['_sv_model_error'] ?? null,
    ];
}

function sv_queue_forge_repair_job(PDO $pdo, array $config, int $mediaId, array $options, callable $logger): array
{
    if (!sv_is_forge_enabled($config)) {
        throw new RuntimeException('Forge ist deaktiviert.');
    }

    $jobData = sv_prepare_forge_repair_job($pdo, $config, $mediaId, $options, $logger);
    $fallbackModel = sv_forge_fallback_model($config);

    $jobId = sv_create_forge_job(
        $pdo,
        $config,
        $mediaId,
        $jobData['payload'],
        $jobData['prompt_id'],
        $logger
    );

    if ($jobData['resolved_model'] !== $jobData['requested_model']) {
        $logger('Forge-Modell in Repair-Job #' . $jobId . ' angepasst: requested=' . $jobData['requested_model'] . ', used=' . $jobData['resolved_model'] . '.');
        sv_audit_log($pdo, 'forge_model_resolved', 'jobs', $jobId, [
            'requested_model' => $jobData['requested_model'],
            'resolved_model'  => $jobData['resolved_model'],
            'fallback_used'   => $jobData['resolved_model'] === $fallbackModel,
        ]);
    }

    return [
        'job_id'          => $jobId,
        'status'          => 'queued',
        'resolved_model'  => $jobData['resolved_model'],
        'requested_model' => $jobData['requested_model'],
        'model_source'    => $jobData['model_source'] ?? null,
        'model_status'    => $jobData['model_status'] ?? null,
        'model_error'     => $jobData['model_error'] ?? null,
        'repair_plan'     => $jobData['repair_plan'],
    ];
}

function sv_run_forge_repair_job(PDO $pdo, array $config, int $mediaId, array $options, callable $logger): array
{
    $logger('Forge-Repair wird asynchron über die Job-Queue abgewickelt.');

    $queued = sv_queue_forge_repair_job($pdo, $config, $mediaId, $options, $logger);

    $worker = sv_spawn_forge_worker_for_media(
        $pdo,
        $config,
        $mediaId,
        (int)($queued['job_id'] ?? 0) ?: null,
        1,
        $logger
    );

    if (($worker['state'] ?? null) === 'error' && !empty($queued['job_id'])) {
        $snippet = trim((string)($worker['err_snippet'] ?? $worker['reason'] ?? 'spawn failed'));
        $snippet = sv_sanitize_error_message($snippet, 200);
        $snippet = sv_forge_limit_error($snippet, 200);
        $message = 'worker spawn failed: ' . ($snippet === '' ? 'unknown' : $snippet);
        $stmt    = $pdo->prepare(
            'UPDATE jobs SET error_message = CASE WHEN error_message IS NULL OR error_message = "" THEN :msg'
            . ' ELSE error_message || "; " || :msg END WHERE id = :id'
        );
        $stmt->execute([
            ':msg' => $message,
            ':id'  => (int)$queued['job_id'],
        ]);
        $queued['error_message'] = isset($queued['error_message']) && (string)$queued['error_message'] !== ''
            ? $queued['error_message'] . '; ' . $message
            : $message;
    }

    $pidStatus = $worker['pid'] !== null ? sv_is_pid_running((int)$worker['pid']) : ['running' => false, 'unknown' => $worker['unknown']];
    $status    = ($pidStatus['running'] ?? false) ? 'running' : ($queued['status'] ?? 'queued');
    $spawnMessage = 'Job in Queue';
    if (!empty($worker['skipped'])) {
        $spawnMessage = 'Worker-Spawn übersprungen: ' . ((string)($worker['reason'] ?? 'cooldown'));
    } elseif ($status === 'running') {
        $spawnMessage = 'Worker läuft';
    }

    return array_merge($queued, [
        'status'               => $status,
        'worker_pid'           => $worker['pid'],
        'worker_started_at'    => $worker['started'],
        'worker_status_unknown'=> (bool)($pidStatus['unknown'] ?? $worker['unknown']),
        'worker_spawn_skipped' => (bool)($worker['skipped'] ?? false),
        'worker_spawn_reason'  => $worker['reason'] ?? null,
        'worker_spawn'         => $worker['state'] ?? null,
        'worker_spawn_cmd'     => $worker['cmd'] ?? null,
        'worker_spawn_log_paths' => $worker['log_paths'] ?? null,
        'message'              => $spawnMessage,
    ]);
}

function sv_record_forge_worker_meta(PDO $pdo, int $mediaId, ?int $pid, string $startedAt): void
{
    $insert = $pdo->prepare(
        'INSERT INTO media_meta (media_id, source, meta_key, meta_value, created_at) VALUES (?, ?, ?, ?, ?)' 
    );

    $insert->execute([
        $mediaId,
        SV_FORGE_WORKER_META_SOURCE,
        'worker_started_at',
        $startedAt,
        $startedAt,
    ]);

    if ($pid !== null) {
        $insert->execute([
            $mediaId,
            SV_FORGE_WORKER_META_SOURCE,
            'worker_pid',
            (string)$pid,
            $startedAt,
        ]);
    }
}

function sv_merge_job_response_metadata(PDO $pdo, int $jobId, array $data): void
{
    $stmt = $pdo->prepare('SELECT forge_response_json FROM jobs WHERE id = :id');
    $stmt->execute([':id' => $jobId]);
    $current = $stmt->fetchColumn();
    $decoded = is_string($current) && $current !== '' ? json_decode($current, true) : [];
    if (!is_array($decoded)) {
        $decoded = [];
    }

    $merged = array_merge($decoded, $data);
    $update = $pdo->prepare('UPDATE jobs SET forge_response_json = :json WHERE id = :id');
    $update->execute([
        ':json' => json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':id'   => $jobId,
    ]);
}

function sv_resolve_job_asset(PDO $pdo, array $config, int $jobId, string $asset, array $allowedJobTypes, ?int $expectedMediaId = null): array
{
    $assetKey = match ($asset) {
        'preview' => 'preview_path',
        'backup'  => 'backup_path',
        'output'  => 'output_path',
        default   => null,
    };

    if ($assetKey === null) {
        throw new RuntimeException('Ungültiges Asset angefordert.');
    }

    $jobStmt = $pdo->prepare('SELECT id, media_id, type, status, forge_response_json FROM jobs WHERE id = :id');
    $jobStmt->execute([':id' => $jobId]);
    $jobRow = $jobStmt->fetch(PDO::FETCH_ASSOC);

    if (!$jobRow) {
        throw new RuntimeException('Job nicht gefunden.');
    }
    if (!in_array((string)($jobRow['type'] ?? ''), $allowedJobTypes, true)) {
        throw new RuntimeException('Job-Typ nicht erlaubt.');
    }

    $mediaId = isset($jobRow['media_id']) ? (int)$jobRow['media_id'] : 0;
    if ($expectedMediaId !== null && $mediaId !== $expectedMediaId) {
        throw new RuntimeException('Job gehört zu einem anderen Medium.');
    }
    if ($mediaId <= 0) {
        throw new RuntimeException('Ungültige Job-Referenz.');
    }

    $responseRaw = (string)($jobRow['forge_response_json'] ?? '');
    $response    = $responseRaw !== '' ? json_decode($responseRaw, true) : null;
    if (!is_array($response)) {
        throw new RuntimeException('Job hat keine Antwortdaten.');
    }

    $result = is_array($response['result'] ?? null) ? $response['result'] : [];
    if ($result === []) {
        throw new RuntimeException('Job enthält kein Result-Objekt.');
    }

    $path = $result[$assetKey] ?? null;
    if ($path === null && $assetKey === 'output_path') {
        $path = $result['preview_path'] ?? null;
    }

    if (!is_string($path) || trim($path) === '') {
        throw new RuntimeException('Asset nicht vorhanden: ' . $asset);
    }

    return [
        'media_id' => $mediaId,
        'path'     => (string)$path,
        'status'   => (string)($jobRow['status'] ?? ''),
    ];
}

function sv_spawn_forge_worker_for_media(
    PDO $pdo,
    array $config,
    int $mediaId,
    ?int $jobId,
    ?int $limit,
    callable $logger
): array {
    if (!sv_is_cli() && !sv_has_valid_internal_key()) {
        throw new RuntimeException('Internal-Key erforderlich, um Forge-Worker zu starten.');
    }

    $cooldownSeconds = isset($config['forge']['spawn_cooldown']) ? max(1, (int)$config['forge']['spawn_cooldown']) : 15;
    $logsError = null;
    $logsRoot = sv_ensure_logs_root($config, $logsError);
    if ($logsRoot === null) {
        sv_log_system_error($config, 'forge_worker_spawn_log_root_unavailable', ['error' => $logsError]);
        if ($jobId !== null) {
            sv_merge_job_response_metadata($pdo, $jobId, [
                '_sv_worker_spawned'          => false,
                '_sv_worker_spawn_skipped'    => true,
                '_sv_worker_spawn_reason'     => 'log_dir_unwritable',
                '_sv_worker_spawn_error'      => $logsError,
                '_sv_worker_spawn_attempt'    => date('c'),
                'worker_spawn'                => 'error',
                'worker_spawn_cmd'            => null,
                'worker_spawn_err_snippet'    => null,
                'worker_spawn_log_paths'      => [],
                'worker_spawn_status'         => 'open_failed',
                'worker_spawn_reason_code'    => 'log_root_unavailable',
            ]);
        }
        return [
            'pid'          => null,
            'started'      => date('c'),
            'unknown'      => false,
            'skipped'      => true,
            'reason'       => 'log_dir_unwritable',
            'state'        => 'error',
            'cmd'          => null,
            'err_snippet'  => $logsError,
            'log_paths'    => [],
            'status'       => 'open_failed',
            'reason_code'  => 'log_root_unavailable',
            'locked'       => false,
            'path'         => sv_logs_root($config),
            'running'      => false,
        ];
    }

    $lockPath        = $logsRoot . '/forge_worker_spawn.lock';
    $spawnLastPath   = $logsRoot . '/forge_worker_spawn.last.json';
    $spawnOutLog     = $logsRoot . '/forge_worker_spawn.out.log';
    $spawnErrLog     = $logsRoot . '/forge_worker_spawn.err.log';
    $workerLockPath  = $logsRoot . '/forge_worker.lock.json';
    $logPaths        = [
        'stdout' => $spawnOutLog,
        'stderr' => $spawnErrLog,
    ];

    $now            = time();
    $lastSpawnAt    = null;
    if (is_file($spawnLastPath)) {
        $raw = file_get_contents($spawnLastPath);
        if ($raw === false) {
            sv_log_system_error($config, 'forge_worker_spawn_last_read_failed', ['path' => $spawnLastPath]);
        } else {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['last_spawn_at'])) {
                $lastSpawnAt = (int)$decoded['last_spawn_at'];
            }
        }
    }
    $cooldownActive = $lastSpawnAt !== null && ($now - $lastSpawnAt) < $cooldownSeconds;
    $remaining      = $cooldownActive ? max(0, $cooldownSeconds - ($now - $lastSpawnAt)) : 0;

    $recordSpawnLog = function (string $state, string $reason) use ($spawnErrLog, $config): void {
        $line = '[' . date('c') . '] ' . $state . ': ' . $reason . PHP_EOL;
        $result = file_put_contents($spawnErrLog, $line, FILE_APPEND);
        if ($result === false) {
            sv_log_system_error($config, 'forge_worker_spawn_log_write_failed', ['path' => $spawnErrLog]);
        }
    };

    if ($cooldownActive) {
        $reason = 'cooldown (' . $remaining . 's verbleibend)';
        $logger('Forge-Worker-Spawn übersprungen (Cooldown aktiv, letzter Start vor ' . ($now - $lastSpawnAt) . 's).');
        $recordSpawnLog('skipped', $reason);
        $snippet = substr($reason, 0, 200);
        if ($jobId !== null) {
            sv_merge_job_response_metadata($pdo, $jobId, [
                '_sv_worker_spawned'          => false,
                '_sv_worker_spawn_skipped'    => true,
                '_sv_worker_spawn_reason'     => 'cooldown',
                '_sv_worker_spawn_error'      => null,
                '_sv_worker_spawn_attempt'    => date('c', $now),
                'worker_spawn'                => 'skipped',
                'worker_spawn_cmd'            => null,
                'worker_spawn_err_snippet'    => $snippet,
                'worker_spawn_log_paths'      => $logPaths,
                'worker_spawn_status'         => 'busy',
                'worker_spawn_reason_code'    => 'spawn_cooldown',
            ]);
        }

        return [
            'pid'          => null,
            'started'      => date('c', $now),
            'unknown'      => false,
            'skipped'      => true,
            'reason'       => 'cooldown',
            'state'        => 'skipped',
            'cmd'          => null,
            'err_snippet'  => $snippet,
            'log_paths'    => $logPaths,
            'status'       => 'busy',
            'reason_code'  => 'spawn_cooldown',
            'locked'       => false,
            'path'         => $lockPath,
            'running'      => false,
        ];
    }

    $lockHandle = fopen($lockPath, 'c+');
    if ($lockHandle === false) {
        $recordSpawnLog('error', 'spawn_lock_open_failed');
        if ($jobId !== null) {
            sv_merge_job_response_metadata($pdo, $jobId, [
                '_sv_worker_spawned'          => false,
                '_sv_worker_spawn_skipped'    => true,
                '_sv_worker_spawn_reason'     => 'spawn_lock_open_failed',
                '_sv_worker_spawn_error'      => 'spawn_lock_open_failed',
                '_sv_worker_spawn_attempt'    => date('c', $now),
                'worker_spawn'                => 'error',
                'worker_spawn_cmd'            => null,
                'worker_spawn_err_snippet'    => 'spawn_lock_open_failed',
                'worker_spawn_log_paths'      => $logPaths,
                'worker_spawn_status'         => 'open_failed',
                'worker_spawn_reason_code'    => 'spawn_lock_open_failed',
            ]);
        }
        return [
            'pid'          => null,
            'started'      => date('c', $now),
            'unknown'      => false,
            'skipped'      => true,
            'reason'       => 'spawn_lock_open_failed',
            'state'        => 'error',
            'cmd'          => null,
            'err_snippet'  => 'spawn_lock_open_failed',
            'log_paths'    => $logPaths,
            'status'       => 'open_failed',
            'reason_code'  => 'spawn_lock_open_failed',
            'locked'       => false,
            'path'         => $lockPath,
            'running'      => false,
        ];
    }

    $hasLock = flock($lockHandle, LOCK_EX | LOCK_NB);
    if (!$hasLock) {
        fclose($lockHandle);
        $recordSpawnLog('skipped', 'spawn_lock_busy');
        if ($jobId !== null) {
            sv_merge_job_response_metadata($pdo, $jobId, [
                '_sv_worker_spawned'          => false,
                '_sv_worker_spawn_skipped'    => true,
                '_sv_worker_spawn_reason'     => 'spawn_lock_busy',
                '_sv_worker_spawn_error'      => 'spawn_lock_busy',
                '_sv_worker_spawn_attempt'    => date('c', $now),
                'worker_spawn'                => 'skipped',
                'worker_spawn_cmd'            => null,
                'worker_spawn_err_snippet'    => 'spawn_lock_busy',
                'worker_spawn_log_paths'      => $logPaths,
                'worker_spawn_status'         => 'locked',
                'worker_spawn_reason_code'    => 'spawn_lock_busy',
            ]);
        }
        return [
            'pid'          => null,
            'started'      => date('c', $now),
            'unknown'      => false,
            'skipped'      => true,
            'reason'       => 'spawn_lock_busy',
            'state'        => 'skipped',
            'cmd'          => null,
            'err_snippet'  => 'spawn_lock_busy',
            'log_paths'    => $logPaths,
            'status'       => 'locked',
            'reason_code'  => 'spawn_lock_busy',
            'locked'       => true,
            'path'         => $lockPath,
            'running'      => false,
        ];
    }

    $effectiveLimit = $limit === null ? 1 : max(1, (int)$limit);
    $baseDir        = sv_base_dir();
    $script         = $baseDir . '/SCRIPTS/forge_worker_cli.php';
    $startedAt      = date('c');
    $pid            = null;
    $unknown        = false;
    $spawnError     = null;
    $spawned        = false;
    $spawnState     = 'error';
    $spawnCmd       = null;
    $errSnippet     = null;

    $phpCli = sv_get_php_cli($config);
    if ($phpCli === '') {
        $spawnError = 'php_cli_missing';
        $recordSpawnLog('error', $spawnError);
        $errSnippet = substr($spawnError, 0, 200);
    } else {
        $logger('Starte Forge-Worker (media_id=' . $mediaId . ', limit=' . $effectiveLimit . ')');

        if (stripos(PHP_OS_FAMILY ?? PHP_OS, 'Windows') !== false) {
            $toWindowsPath = static function (string $path): string {
                return str_replace('/', '\\', $path);
            };
            $psArg = static function (string $value): string {
                return "'" . str_replace("'", "''", $value) . "'";
            };
            $psArgs = [
                $psArg($toWindowsPath($script)),
                $psArg('--limit=' . $effectiveLimit),
                $psArg('--media-id=' . $mediaId),
            ];
            $psCommand = '$p = Start-Process -FilePath ' . $psArg($toWindowsPath($phpCli))
                . ' -ArgumentList ' . implode(',', $psArgs)
                . ' -RedirectStandardOutput ' . $psArg($toWindowsPath($spawnOutLog))
                . ' -RedirectStandardError ' . $psArg($toWindowsPath($spawnErrLog))
                . ' -PassThru; if ($p) { $p.Id }';
            $spawnCmd = 'powershell -NoProfile -Command ' . escapeshellarg($psCommand);

            $output = shell_exec($spawnCmd);
            if ($output !== null && trim((string)$output) !== '') {
                $pid = (int)trim((string)$output);
                if ($pid <= 0) {
                    $pid = null;
                }
            } else {
                $unknown = true;
                $spawnError = 'Kein PID aus PowerShell';
            }
            if ($pid !== null) {
                $spawned    = true;
                $spawnState = 'spawned';
            } else {
                $spawnState = 'error';
            }
        } else {
            $spawnCmd = 'nohup ' . escapeshellarg($phpCli) . ' ' . escapeshellarg($script)
                . ' --limit=' . $effectiveLimit . ' --media-id=' . $mediaId
                . ' >> ' . escapeshellarg($spawnOutLog) . ' 2>> ' . escapeshellarg($spawnErrLog) . ' & echo $!';

            $output = shell_exec($spawnCmd);
            if ($output !== null && trim((string)$output) !== '') {
                $pid = (int)trim((string)$output);
                if ($pid <= 0) {
                    $pid = null;
                }
            }
            if ($pid !== null) {
                $spawned    = true;
                $spawnState = 'spawned';
            } else {
                $unknown    = true;
                $spawnError = 'Kein PID aus shell_exec';
                $spawnState = 'error';
            }
        }

        $recordSpawnLog($spawnState === 'spawned' ? 'spawned' : 'error', $spawnError === null ? 'ok' : $spawnError);
        $errLog = file_get_contents($spawnErrLog);
        if ($errLog !== false && $errLog !== '') {
            $errSnippet = substr($errLog, -200);
        } elseif ($errLog === false) {
            sv_log_system_error($config, 'forge_worker_spawn_errlog_read_failed', ['path' => $spawnErrLog]);
        }
    }

    $verifyWindowSeconds = 10;
    $lockVerified = false;
    $pidVerified = false;
    $lockReason = null;
    $checkLock = static function () use ($workerLockPath, $verifyWindowSeconds): array {
        if (!is_file($workerLockPath)) {
            return ['ok' => false, 'reason' => 'lock_missing'];
        }
        $raw = file_get_contents($workerLockPath);
        if ($raw === false) {
            return ['ok' => false, 'reason' => 'lock_read_failed'];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['ok' => false, 'reason' => 'lock_json_invalid'];
        }
        $heartbeat = isset($data['heartbeat_at']) ? strtotime((string)$data['heartbeat_at']) : false;
        if ($heartbeat === false) {
            return ['ok' => false, 'reason' => 'lock_heartbeat_invalid'];
        }
        if ((time() - $heartbeat) > $verifyWindowSeconds) {
            return ['ok' => false, 'reason' => 'lock_heartbeat_stale'];
        }
        return ['ok' => true, 'reason' => null];
    };
    if ($spawned) {
        $deadline = time() + $verifyWindowSeconds;
        while (time() <= $deadline) {
            if ($pid !== null) {
                $pidInfo = sv_is_pid_running($pid);
                if (!($pidInfo['unknown'] ?? false) && ($pidInfo['running'] ?? false)) {
                    $pidVerified = true;
                    break;
                }
            }
            $lockCheck = $checkLock();
            $lockReason = $lockCheck['reason'] ?? null;
            if (!empty($lockCheck['ok'])) {
                $lockVerified = true;
                break;
            }
            usleep(200000);
        }
    }

    $verified = $spawned && ($pidVerified || $lockVerified);
    if ($spawned && !$verified) {
        $spawnError = 'spawn_unverified';
        $spawnState = 'error';
        $spawned = false;
    }

    if ($spawned) {
        sv_record_forge_worker_meta($pdo, $mediaId, $pid, $startedAt);
    }

    $lastPayload = [
        'last_spawn_at' => $now,
        'started_at' => $startedAt,
        'command' => $spawnCmd,
        'logs' => $logPaths,
        'media_id' => $mediaId,
        'limit' => $effectiveLimit,
    ];
    $lastJson = json_encode($lastPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($lastJson === false || file_put_contents($spawnLastPath, $lastJson) === false) {
        sv_log_system_error($config, 'forge_worker_spawn_last_write_failed', ['path' => $spawnLastPath]);
    }

    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);

    $status = $spawned ? 'running' : ($spawnError === 'php_cli_missing' ? 'config_failed' : 'start_failed');
    if ($spawnError === 'spawn_lock_open_failed') {
        $status = 'open_failed';
    }
    if ($spawnError === 'spawn_lock_busy') {
        $status = 'locked';
    }
    $reasonCode = $spawned ? 'start_verified' : ($spawnError === 'spawn_unverified' ? 'spawn_unverified' : ($spawnError === 'php_cli_missing' ? 'php_cli_missing' : 'spawn_failed'));

    if ($jobId !== null) {
        sv_merge_job_response_metadata($pdo, $jobId, [
            '_sv_worker_pid'        => $pid,
            '_sv_worker_started_at' => $startedAt,
            '_sv_worker_spawned'    => $spawned,
            '_sv_worker_spawn_skipped' => false,
            '_sv_worker_spawn_reason'  => $reasonCode,
            '_sv_worker_spawn_error'   => $spawnError,
            '_sv_worker_spawn_attempt' => $startedAt,
            'worker_spawn'             => $spawnState,
            'worker_spawn_cmd'         => $spawnCmd,
            'worker_spawn_err_snippet' => $errSnippet,
            'worker_spawn_log_paths'   => $logPaths,
            'worker_spawn_status'      => $status,
            'worker_spawn_reason_code' => $reasonCode,
        ]);
    }

    return [
        'pid'      => $pid,
        'started'  => $startedAt,
        'unknown'  => $unknown,
        'skipped'  => false,
        'reason'   => $spawnError,
        'state'    => $spawnState,
        'cmd'      => $spawnCmd,
        'err_snippet' => $errSnippet,
        'log_paths'   => $logPaths,
        'status'      => $status,
        'reason_code' => $reasonCode,
        'locked'      => false,
        'path'        => $lockPath,
        'running'     => $spawned,
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
    $type     = (string)($row['type'] ?? '');
    $status   = (string)($row['status'] ?? '');

    $parts = [];
    if (is_array($payload) && isset($payload['model'])) {
        $parts[] = 'Modell: ' . (string)$payload['model'];
    }
    if ($type === SV_JOB_TYPE_SCAN_PATH) {
        $path  = is_string($payload['path'] ?? null) ? (string)$payload['path'] : '';
        $limit = isset($payload['limit']) ? (int)$payload['limit'] : null;
        if ($path !== '') {
            $parts[] = 'Pfad: ' . sv_safe_path_label($path);
        }
        if ($limit !== null && $limit > 0) {
            $parts[] = 'Limit: ' . $limit;
        }
    }
    if ($status !== '') {
        $parts[] = 'Status: ' . $status;
    }
    if (is_array($response) && isset($response['error'])) {
        $parts[] = 'Response-Error: ' . sv_sanitize_error_message((string)$response['error']);
    }
    if (isset($row['error_message']) && trim((string)$row['error_message']) !== '') {
        $parts[] = 'Fehler: ' . sv_sanitize_error_message((string)$row['error_message']);
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

function sv_fetch_media_ids_without_scan_results(PDO $pdo, ?int $limit, ?int $offset): array
{
    $sql = "SELECT m.id FROM media m "
        . "LEFT JOIN scan_results s ON s.media_id = m.id "
        . "WHERE s.id IS NULL AND m.type = 'image' "
        . "ORDER BY m.id ASC";

    if ($limit !== null) {
        $sql .= ' LIMIT ' . max(0, (int)$limit);
    }
    if ($offset !== null) {
        $sql .= ' OFFSET ' . max(0, (int)$offset);
    }

    $stmt = $pdo->query($sql);
    $ids = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    return array_map('intval', $ids);
}

function sv_fetch_media_ids_without_tags(PDO $pdo, int $limit, int $afterId = 0): array
{
    $limit = max(1, $limit);
    $afterId = max(0, $afterId);

    $stmt = $pdo->prepare(
        'SELECT m.id FROM media m '
        . 'WHERE m.id > :after_id '
        . 'AND NOT EXISTS (SELECT 1 FROM media_tags t WHERE t.media_id = m.id) '
        . 'ORDER BY m.id ASC LIMIT :limit'
    );
    $stmt->bindValue(':after_id', $afterId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function sv_enqueue_rescan_unscanned_jobs(PDO $pdo, array $config, ?int $limit, ?int $offset, callable $logger): array
{
    $mediaIds = sv_fetch_media_ids_without_scan_results($pdo, $limit, $offset);

    $created = 0;
    $deduped = 0;
    $errors  = 0;

    foreach ($mediaIds as $mediaId) {
        try {
            $result = sv_enqueue_rescan_media_job($pdo, $config, (int)$mediaId, $logger);
            if (!empty($result['deduped'])) {
                $deduped++;
            } else {
                $created++;
            }
        } catch (Throwable $e) {
            $errors++;
            $logger('Rescan-Job fehlgeschlagen (Media ' . (int)$mediaId . '): ' . $e->getMessage());
        }
    }

    return [
        'total'   => count($mediaIds),
        'created' => $created,
        'deduped' => $deduped,
        'errors'  => $errors,
    ];
}

function sv_create_scan_backfill_job(PDO $pdo, array $config, array $options, callable $logger): array
{
    $mode = is_string($options['mode'] ?? null) ? trim((string)$options['mode']) : 'no_tags';
    if ($mode === '') {
        $mode = 'no_tags';
    }

    $existingStmt = $pdo->prepare(
        'SELECT id FROM jobs WHERE type = :type AND status = "queued" ORDER BY id DESC LIMIT 1'
    );
    $existingStmt->execute([':type' => SV_JOB_TYPE_SCAN_BACKFILL_TAGS]);
    $existingId = (int)$existingStmt->fetchColumn();
    if ($existingId > 0) {
        $logger('Backfill-Job existiert bereits (#' . $existingId . ').');
        return [
            'job_id'  => $existingId,
            'deduped' => true,
        ];
    }

    $chunk = isset($options['chunk']) ? (int)$options['chunk'] : 200;
    $max   = isset($options['max']) ? (int)$options['max'] : null;
    if ($chunk <= 0) {
        $chunk = 200;
    }
    if ($max !== null && $max <= 0) {
        $max = null;
    }

    $payload = [
        'mode'       => $mode,
        'chunk'      => $chunk,
        'max'        => $max,
        'created_at' => date('c'),
    ];

    sv_enforce_job_queue_capacity($pdo, $config, SV_JOB_TYPE_SCAN_BACKFILL_TAGS);

    $now = date('c');
    $insert = $pdo->prepare(
        'INSERT INTO jobs (media_id, prompt_id, type, status, created_at, updated_at, forge_request_json) '
        . 'VALUES (0, NULL, :type, :status, :created_at, :updated_at, :payload)'
    );
    $insert->execute([
        ':type'       => SV_JOB_TYPE_SCAN_BACKFILL_TAGS,
        ':status'     => 'queued',
        ':created_at' => $now,
        ':updated_at' => $now,
        ':payload'    => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);

    $jobId = (int)$pdo->lastInsertId();
    $logger('Backfill-Job angelegt: ID=' . $jobId . ' (' . $mode . ')');

    sv_audit_log($pdo, 'backfill_no_tags', 'jobs', $jobId, [
        'mode'   => $mode,
        'chunk'  => $chunk,
        'max'    => $max,
        'job_id' => $jobId,
    ]);

    return [
        'job_id'  => $jobId,
        'deduped' => false,
        'payload' => $payload,
    ];
}

function sv_create_scan_job(PDO $pdo, array $config, string $scanPath, ?int $limit, callable $logger): array
{
    $scanPath = trim($scanPath);
    if ($scanPath === '') {
        throw new InvalidArgumentException('Scan-Pfad fehlt.');
    }
    if (mb_strlen($scanPath) > 500) {
        throw new InvalidArgumentException('Scan-Pfad zu lang (max. 500 Zeichen).');
    }

    $real = realpath($scanPath);
    if ($real === false || (!is_dir($real) && !is_file($real))) {
        throw new RuntimeException('Pfad nicht gefunden oder kein Verzeichnis/Datei.');
    }

    $limit = $limit !== null && $limit > 0 ? $limit : null;
    $now   = date('c');
    $normalizedPath = str_replace('\\', '/', $scanPath);
    if ($normalizedPath !== '/' && !preg_match('~^[A-Za-z]:/$~', $normalizedPath)) {
        $normalizedPath = rtrim($normalizedPath, '/');
    }
    $payload = [
        'path'            => $normalizedPath,
        'requested_path'  => $scanPath,
        'limit'           => $limit,
        'nsfw_threshold'  => (float)(($config['scanner']['nsfw_threshold'] ?? 0.7)),
        'created_at'      => $now,
    ];

    sv_enforce_job_queue_capacity($pdo, $config, SV_JOB_TYPE_SCAN_PATH);

    $stmt = $pdo->prepare(
        'INSERT INTO jobs (media_id, prompt_id, type, status, created_at, updated_at, forge_request_json) '
        . 'VALUES (:media_id, NULL, :type, :status, :created_at, :updated_at, :payload)'
    );
    $stmt->execute([
        ':media_id'   => 0,
        ':type'       => SV_JOB_TYPE_SCAN_PATH,
        ':status'     => 'queued',
        ':created_at' => $now,
        ':updated_at' => $now,
        ':payload'    => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);

    $jobId = (int)$pdo->lastInsertId();
    $logger('Scan-Job angelegt: ID=' . $jobId . ' (' . sv_safe_path_label($payload['path']) . ')');

    sv_audit_log($pdo, 'scan_job_created', 'jobs', $jobId, [
        'path'   => sv_safe_path_label($payload['path']),
        'limit'  => $limit,
        'job_id' => $jobId,
    ]);

    return [
        'job_id'  => $jobId,
        'payload' => $payload,
    ];
}

function sv_enqueue_rescan_media_job(PDO $pdo, array $config, int $mediaId, callable $logger): array
{
    if ($mediaId <= 0) {
        throw new InvalidArgumentException('Ungültige Media-ID für Rescan.');
    }

    $stmt = $pdo->prepare('SELECT * FROM media WHERE id = :id');
    $stmt->execute([':id' => $mediaId]);
    $mediaRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$mediaRow) {
        throw new InvalidArgumentException('Media-Eintrag nicht gefunden.');
    }

    $path = (string)($mediaRow['path'] ?? '');
    if ($path === '' || !is_file($path)) {
        throw new RuntimeException('Dateipfad nicht vorhanden, Rescan nicht möglich.');
    }

    $pathsCfg = $config['paths'] ?? [];
    sv_assert_media_path_allowed($path, $pathsCfg, 'rescan_job_enqueue');

    $existing = $pdo->prepare(
        'SELECT id FROM jobs WHERE media_id = :media_id AND type = :type AND status = "queued" ORDER BY id DESC LIMIT 1'
    );
    $existing->execute([
        ':media_id' => $mediaId,
        ':type'     => SV_JOB_TYPE_RESCAN_MEDIA,
    ]);
    $presentId = $existing->fetchColumn();
    if ($presentId) {
        $logger('Rescan-Job existiert bereits (#' . (int)$presentId . ', queued/running).');
        return [
            'job_id'  => (int)$presentId,
            'deduped' => true,
        ];
    }

    $now = date('c');
    $payload = [
        'media_id'       => $mediaId,
        'path'           => str_replace('\\', '/', $path),
        'nsfw_threshold' => (float)($config['scanner']['nsfw_threshold'] ?? 0.7),
    ];

    sv_enforce_job_queue_capacity($pdo, $config, SV_JOB_TYPE_RESCAN_MEDIA, $mediaId);

    sv_db_exec_retry(static function () use ($pdo, $mediaId, $now, $payload): void {
        $insert = $pdo->prepare(
            'INSERT INTO jobs (media_id, prompt_id, type, status, created_at, updated_at, forge_request_json) '
            . 'VALUES (:media_id, NULL, :type, :status, :created_at, :updated_at, :payload)'
        );
        $insert->execute([
            ':media_id'   => $mediaId,
            ':type'       => SV_JOB_TYPE_RESCAN_MEDIA,
            ':status'     => 'queued',
            ':created_at' => $now,
            ':updated_at' => $now,
            ':payload'    => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    });

    $jobId = (int)$pdo->lastInsertId();
    $logger('Rescan-Job angelegt: ID=' . $jobId . ' (Media ' . $mediaId . ')');
    sv_audit_log($pdo, 'rescan_enqueue', 'jobs', $jobId, [
        'media_id' => $mediaId,
        'path'     => $payload['path'],
    ]);

    return [
        'job_id'  => $jobId,
        'deduped' => false,
        'path'    => $payload['path'],
    ];
}

function sv_process_rescan_media_job(PDO $pdo, array $config, array $jobRow, callable $logger): array
{
    $jobId   = (int)($jobRow['id'] ?? 0);
    $mediaId = isset($jobRow['media_id']) ? (int)$jobRow['media_id'] : 0;
    if ($jobId <= 0 || $mediaId <= 0) {
        sv_update_job_status($pdo, $jobId, 'error', null, 'Ungültiger Rescan-Job.');
        return [
            'job_id' => $jobId,
            'status' => 'error',
            'error'  => 'Ungültiger Rescan-Job.',
        ];
    }

    if (sv_is_job_canceled($pdo, $jobId)) {
        return sv_finalize_canceled_job($pdo, $jobId, ['media_id' => $mediaId], $logger, 'rescan pre-start');
    }

    $claimReason = null;
    if (!sv_claim_job_running($pdo, $jobId, ['queued'], ['media_id' => $mediaId], $claimReason)) {
        $logger('Rescan-Job #' . $jobId . ' nicht übernommen: ' . ($claimReason ?? 'claim_failed'));
        return [
            'job_id' => $jobId,
            'status' => 'skipped',
            'error'  => $claimReason ?? 'claim_failed_or_already_running',
        ];
    }

    $payload = json_decode((string)($jobRow['forge_request_json'] ?? ''), true) ?: [];
    $logger('Starte Rescan-Job #' . $jobId . ' für Media #' . $mediaId);

    $jobLogger = function (string $msg) use ($logger, $jobId): void {
        $logger('[Rescan ' . $jobId . '] ' . $msg);
    };

    $response = [
        'media_id' => $mediaId,
        'path'     => null,
        'result'   => null,
    ];

    $startedAt = date('c');
    $response['started_at'] = $startedAt;

    try {
        $stmt = $pdo->prepare('SELECT * FROM media WHERE id = :id');
        $stmt->execute([':id' => $mediaId]);
        $mediaRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$mediaRow) {
            throw new RuntimeException('Media-Eintrag nicht gefunden.');
        }

        $path          = (string)($mediaRow['path'] ?? '');
        $pathsCfg      = $config['paths'] ?? [];
        $scannerCfg    = $config['scanner'] ?? [];
        $nsfwThreshold = isset($payload['nsfw_threshold']) ? (float)$payload['nsfw_threshold'] : (float)($scannerCfg['nsfw_threshold'] ?? 0.7);

        if ($path === '' || !is_file($path)) {
            throw new RuntimeException('Dateipfad nicht vorhanden.');
        }
        sv_assert_media_path_allowed($path, $pathsCfg, 'rescan_job_run');

        $resultMeta = [];
        $cancelCheck = static function () use ($pdo, $jobId): bool {
            return sv_is_job_canceled($pdo, $jobId);
        };
        $ok = sv_rescan_media(
            $pdo,
            $mediaRow,
            $pathsCfg,
            $scannerCfg,
            $nsfwThreshold,
            $jobLogger,
            $resultMeta,
            $cancelCheck
        );

        if (!empty($resultMeta['canceled'])) {
            $response = array_merge($response, [
                'media_id' => $mediaId,
                'path'     => $path,
                'result'   => 'canceled',
                'started_at' => $startedAt,
            ]);
            return sv_finalize_canceled_job($pdo, $jobId, $response, $logger, 'rescan in-progress');
        }

        $finishedAt = date('c');
        $response = array_merge($response, [
            'media_id'     => $mediaId,
            'path'         => $path,
            'result'       => $ok ? 'ok' : 'failed',
            'completed_at' => $finishedAt,
            'finished_at'  => $finishedAt,
            'scanner'      => $resultMeta['scanner'] ?? null,
            'nsfw_score'   => $resultMeta['nsfw_score'] ?? null,
            'tags_written' => $resultMeta['tags_written'] ?? null,
            'run_at'       => $resultMeta['run_at'] ?? null,
            'path_after'   => $resultMeta['path_after'] ?? null,
            'has_nsfw'     => $resultMeta['has_nsfw'] ?? null,
            'rating'       => $resultMeta['rating'] ?? null,
            'started_at'   => $startedAt,
        ]);

        if ($ok) {
            $pdo->prepare("DELETE FROM media_meta WHERE media_id = ? AND meta_key = 'scan_stale'")->execute([$mediaId]);
            sv_update_job_status(
                $pdo,
                $jobId,
                'done',
                json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                null
            );
            sv_audit_log($pdo, 'rescan_job_done', 'jobs', $jobId, [
                'media_id' => $mediaId,
                'path'     => $path,
            ]);
            return [
                'job_id' => $jobId,
                'status' => 'done',
                'result' => $response,
            ];
        }

        $error = $resultMeta['error'] ?? 'Rescan fehlgeschlagen';
        $finishedAt = date('c');
        $response['error'] = $error;
        $response['finished_at'] = $finishedAt;
        if (!empty($resultMeta['short_error'])) {
            $response['short_error'] = $resultMeta['short_error'];
        }
        sv_update_job_status(
            $pdo,
            $jobId,
            'error',
            json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $error
        );
        sv_audit_log($pdo, 'rescan_job_failed', 'jobs', $jobId, [
            'media_id' => $mediaId,
            'path'     => $path,
            'error'    => $error,
        ]);
        return [
            'job_id' => $jobId,
            'status' => 'error',
            'error'  => $error,
            'result' => $response,
        ];
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $response['error'] = $message;
        if (!isset($response['run_at']) && isset($resultMeta['run_at'])) {
            $response['run_at'] = $resultMeta['run_at'];
        }
        if (!empty($resultMeta['short_error'])) {
            $response['short_error'] = $resultMeta['short_error'];
        }
        $response['finished_at'] = date('c');
        sv_update_job_status(
            $pdo,
            $jobId,
            'error',
            json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $message
        );
        sv_audit_log($pdo, 'rescan_job_failed', 'jobs', $jobId, [
            'media_id' => $mediaId,
            'error'    => $message,
        ]);
        return [
            'job_id' => $jobId,
            'status' => 'error',
            'error'  => $message,
        ];
    } finally {
        $now = date('c');
        $response['updated_at'] = $now;
        sv_db_exec_retry(static function () use ($pdo, $jobId, $now): void {
            $update = $pdo->prepare('UPDATE jobs SET updated_at = :updated_at WHERE id = :id');
            $update->execute([
                ':updated_at' => $now,
                ':id'         => $jobId,
            ]);
        });
    }
}

function sv_process_scan_backfill_job(PDO $pdo, array $config, array $jobRow, callable $logger): array
{
    $jobId = (int)($jobRow['id'] ?? 0);
    if ($jobId <= 0) {
        sv_update_job_status($pdo, $jobId, 'error', null, 'Ungültiger Backfill-Job.');
        return [
            'job_id' => $jobId,
            'status' => 'error',
            'error'  => 'Ungültiger Backfill-Job.',
        ];
    }

    if (sv_is_job_canceled($pdo, $jobId)) {
        return sv_finalize_canceled_job($pdo, $jobId, [], $logger, 'backfill pre-start');
    }

    $payload = json_decode((string)($jobRow['forge_request_json'] ?? ''), true) ?: [];
    $mode = is_string($payload['mode'] ?? null) ? trim((string)$payload['mode']) : 'no_tags';
    $chunk = isset($payload['chunk']) ? (int)$payload['chunk'] : 200;
    $max = isset($payload['max']) ? (int)$payload['max'] : null;
    $maxEnqueuePerRun = isset($payload['max_enqueue_per_run']) ? (int)$payload['max_enqueue_per_run'] : SV_BACKFILL_MAX_ENQUEUE_PER_RUN;
    if ($chunk <= 0) {
        $chunk = 200;
    }
    if ($max !== null && $max <= 0) {
        $max = null;
    }
    if ($maxEnqueuePerRun <= 0) {
        $maxEnqueuePerRun = SV_BACKFILL_MAX_ENQUEUE_PER_RUN;
    }

    $jobLogger = function (string $msg) use ($logger, $jobId): void {
        $logger('[Backfill ' . $jobId . '] ' . $msg);
    };

    if ($mode !== 'no_tags') {
        $error = 'Unbekannter Backfill-Modus.';
        sv_update_job_status($pdo, $jobId, 'error', null, $error);
        $jobLogger($error);
        return [
            'job_id' => $jobId,
            'status' => 'error',
            'error'  => $error,
        ];
    }

    $response = json_decode((string)($jobRow['forge_response_json'] ?? ''), true) ?: [];
    $progress = is_array($response['progress'] ?? null) ? $response['progress'] : [];
    $processed = isset($progress['processed']) ? (int)$progress['processed'] : 0;
    $enqueued = isset($progress['enqueued']) ? (int)$progress['enqueued'] : 0;
    $deduped = isset($progress['deduped']) ? (int)$progress['deduped'] : 0;
    $lastId = isset($progress['last_id']) ? (int)$progress['last_id'] : 0;
    $payloadLastId = isset($payload['cursor_last_id']) ? (int)$payload['cursor_last_id'] : 0;
    $payloadEnqueued = isset($payload['enqueued_total']) ? (int)$payload['enqueued_total'] : null;
    if ($payloadLastId > 0) {
        $lastId = $payloadLastId;
    }
    if ($payloadEnqueued !== null) {
        $enqueued = $payloadEnqueued;
    }

    $claimReason = null;
    $claimPayload = [
        'mode' => $mode,
        'progress' => [
            'processed' => $processed,
            'enqueued'  => $enqueued,
            'deduped'   => $deduped,
            'last_id'   => $lastId,
        ],
    ];
    if (!sv_claim_job_running($pdo, $jobId, ['queued'], $claimPayload, $claimReason)) {
        $jobLogger('Backfill-Job nicht übernommen: ' . ($claimReason ?? 'claim_failed'));
        return [
            'job_id' => $jobId,
            'status' => 'skipped',
            'error'  => $claimReason ?? 'claim_failed_or_already_running',
        ];
    }

    $jobLogger('Starte Backfill (' . $mode . '), chunk=' . $chunk . ($max !== null ? ', max=' . $max : '') . ', cap=' . $maxEnqueuePerRun);

    $cancelCheck = static function () use ($pdo, $jobId): bool {
        return sv_is_job_canceled($pdo, $jobId);
    };

    sv_update_job_checkpoint_payload($pdo, $jobId, array_filter([
        'mode'            => $mode,
        'chunk'           => $chunk,
        'max'             => $max,
        'max_enqueue_per_run' => $maxEnqueuePerRun,
        'cursor_last_id'  => $lastId,
        'enqueued_total'  => $enqueued,
        'done'            => false,
    ], static fn ($value) => $value !== null));

    $enqueuedThisRun = 0;
    $stopEarly = false;
    while (true) {
        if ($cancelCheck()) {
            return sv_finalize_canceled_job(
                $pdo,
                $jobId,
                [
                    'mode'     => $mode,
                    'progress' => [
                        'processed' => $processed,
                        'enqueued'  => $enqueued,
                        'deduped'   => $deduped,
                        'last_id'   => $lastId,
                    ],
                ],
                $logger,
                'backfill loop'
            );
        }

        if ($enqueuedThisRun >= $maxEnqueuePerRun) {
            $stopEarly = true;
            break;
        }

        $remaining = $max !== null ? max(0, $max - $processed) : null;
        if ($remaining !== null && $remaining <= 0) {
            break;
        }
        $fetchLimit = $remaining !== null ? min($chunk, $remaining) : $chunk;
        if ($fetchLimit <= 0) {
            break;
        }

        $ids = sv_fetch_media_ids_without_tags($pdo, $fetchLimit, $lastId);
        if ($ids === []) {
            break;
        }

        foreach ($ids as $mediaId) {
            if ($cancelCheck()) {
                return sv_finalize_canceled_job(
                    $pdo,
                    $jobId,
                    [
                        'mode'     => $mode,
                        'progress' => [
                            'processed' => $processed,
                            'enqueued'  => $enqueued,
                            'deduped'   => $deduped,
                            'last_id'   => $lastId,
                        ],
                    ],
                    $logger,
                    'backfill loop'
                );
            }

            $processed++;
            $lastId = (int)$mediaId;
            try {
                $result = sv_enqueue_rescan_media_job($pdo, $config, $lastId, $jobLogger);
                if (!empty($result['deduped'])) {
                    $deduped++;
                } else {
                    $enqueued++;
                    $enqueuedThisRun++;
                }
            } catch (Throwable $e) {
                $jobLogger('Backfill-Rescan-Fehler (Media ' . $lastId . '): ' . $e->getMessage());
            }

            usleep(random_int(10000, 25000));

            if ($enqueuedThisRun >= $maxEnqueuePerRun) {
                $stopEarly = true;
                break;
            }
        }

        sv_update_job_status(
            $pdo,
            $jobId,
            'running',
            json_encode([
                'mode'     => $mode,
                'progress' => [
                    'processed' => $processed,
                    'enqueued'  => $enqueued,
                    'deduped'   => $deduped,
                    'last_id'   => $lastId,
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            null
        );
        sv_update_job_checkpoint_payload($pdo, $jobId, array_filter([
            'mode'            => $mode,
            'chunk'           => $chunk,
            'max'             => $max,
            'max_enqueue_per_run' => $maxEnqueuePerRun,
            'cursor_last_id'  => $lastId,
            'enqueued_total'  => $enqueued,
            'done'            => false,
        ], static fn ($value) => $value !== null));

        if ($stopEarly) {
            break;
        }
    }

    if ($stopEarly) {
        $response = [
            'mode'     => $mode,
            'result'   => 'partial',
            'progress' => [
                'processed' => $processed,
                'enqueued'  => $enqueued,
                'deduped'   => $deduped,
                'last_id'   => $lastId,
                'enqueued_this_run' => $enqueuedThisRun,
                'max_enqueue_per_run' => $maxEnqueuePerRun,
            ],
        ];
        sv_update_job_status(
            $pdo,
            $jobId,
            'running',
            json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            null
        );
        sv_update_job_checkpoint_payload($pdo, $jobId, array_filter([
            'mode'            => $mode,
            'chunk'           => $chunk,
            'max'             => $max,
            'max_enqueue_per_run' => $maxEnqueuePerRun,
            'cursor_last_id'  => $lastId,
            'enqueued_total'  => $enqueued,
            'done'            => false,
        ], static fn ($value) => $value !== null));

        return [
            'job_id' => $jobId,
            'status' => 'running',
            'result' => $response,
        ];
    }

    $resultState = $max !== null && $processed >= $max ? 'max_reached' : 'done';
    $response = [
        'mode'     => $mode,
        'result'   => $resultState,
        'progress' => [
            'processed' => $processed,
            'enqueued'  => $enqueued,
            'deduped'   => $deduped,
            'last_id'   => $lastId,
        ],
    ];

    sv_update_job_status(
        $pdo,
        $jobId,
        'done',
        json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        null
    );
    sv_update_job_checkpoint_payload($pdo, $jobId, array_filter([
        'mode'            => $mode,
        'chunk'           => $chunk,
        'max'             => $max,
        'max_enqueue_per_run' => $maxEnqueuePerRun,
        'cursor_last_id'  => $lastId,
        'enqueued_total'  => $enqueued,
        'done'            => true,
    ], static fn ($value) => $value !== null));

    sv_audit_log($pdo, 'backfill_no_tags_done', 'jobs', $jobId, $response);
    $jobLogger('Backfill abgeschlossen: processed=' . $processed . ', enqueued=' . $enqueued . ', deduped=' . $deduped);

    return [
        'job_id' => $jobId,
        'status' => 'done',
        'result' => $response,
    ];
}


function sv_spawn_scan_worker(array $config, ?string $pathFilter, ?int $limit, callable $logger, ?int $mediaId = null): array
{
    if (!sv_is_cli() && !sv_has_valid_internal_key()) {
        throw new RuntimeException('Internal-Key erforderlich, um Scan-Worker zu starten.');
    }

    $baseDir         = sv_base_dir();
    $script          = $baseDir . '/SCRIPTS/scan_worker_cli.php';
    $logsError = null;
    $logsRoot = sv_ensure_logs_root($config, $logsError);
    if ($logsRoot === null) {
        sv_log_system_error($config, 'scan_worker_spawn_log_root_unavailable', ['error' => $logsError]);
        return [
            'pid'     => null,
            'running' => false,
            'reason'  => 'log_dir_unwritable',
            'reason_code' => 'log_root_unavailable',
            'message' => 'Log-Root nicht verfügbar.',
            'status'  => 'open_failed',
            'locked'  => false,
            'path'    => sv_logs_root($config),
            'unknown' => false,
            'skipped' => true,
            'spawned' => false,
        ];
    }
    $spawnLockPath   = $logsRoot . '/scan_worker_spawn.lock';
    $spawnLastPath   = $logsRoot . '/scan_worker_spawn.last.json';
    $spawnOutLog     = $logsRoot . '/scan_worker.out.log';
    $spawnErrLog     = $logsRoot . '/scan_worker.err.log';
    $workerLockPath  = $logsRoot . '/scan_worker.lock.json';
    $cooldownSeconds = 10;
    $logPaths        = [
        'stdout' => $spawnOutLog,
        'stderr' => $spawnErrLog,
    ];

    $lockHandle = fopen($spawnLockPath, 'c+');
    if ($lockHandle === false) {
        sv_log_system_error($config, 'scan_worker_spawn_lock_open_failed', ['path' => $spawnLockPath]);
        return [
            'pid'     => null,
            'running' => false,
            'reason'  => 'spawn_lock_open_failed',
            'reason_code' => 'spawn_lock_open_failed',
            'message' => 'Spawn-Lock konnte nicht geöffnet werden.',
            'status'  => 'open_failed',
            'locked'  => false,
            'path'    => $spawnLockPath,
            'unknown' => false,
            'skipped' => true,
            'spawned' => false,
        ];
    }
    $hasLock = flock($lockHandle, LOCK_EX | LOCK_NB);

    $now            = time();
    $lastSpawnAt    = null;
    if (is_file($spawnLastPath)) {
        $raw = file_get_contents($spawnLastPath);
        if ($raw === false) {
            sv_log_system_error($config, 'scan_worker_spawn_last_read_failed', ['path' => $spawnLastPath]);
        } else {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['last_spawn_at'])) {
                $lastSpawnAt = (int)$decoded['last_spawn_at'];
            }
        }
    }
    $cooldownActive = $lastSpawnAt !== null && ($now - $lastSpawnAt) < $cooldownSeconds;

    if (!$hasLock || $cooldownActive) {
        if ($hasLock) {
            flock($lockHandle, LOCK_UN);
        }
        fclose($lockHandle);
        $reason = !$hasLock ? 'spawn_lock_busy' : 'spawn_cooldown';
        return [
            'pid'     => null,
            'running' => false,
            'reason'  => $reason,
            'reason_code' => $reason,
            'message' => $reason === 'spawn_cooldown' ? 'Spawn im Cooldown.' : 'Spawn-Lock belegt.',
            'status'  => $reason === 'spawn_cooldown' ? 'busy' : 'locked',
            'locked'  => $reason !== 'spawn_cooldown',
            'path'    => $spawnLockPath,
            'unknown' => false,
            'skipped' => true,
            'spawned' => false,
        ];
    }

    $phpCli = sv_get_php_cli($config);
    $parts = [$phpCli, $script];
    if ($limit !== null && $limit > 0) {
        $parts[] = '--limit=' . (int)$limit;
    }
    if ($pathFilter !== null && trim($pathFilter) !== '') {
        $parts[] = '--path=' . $pathFilter;
    }
    if ($mediaId !== null && $mediaId > 0) {
        $parts[] = '--media-id=' . (int)$mediaId;
    }
    $isWindows = stripos(PHP_OS_FAMILY ?? PHP_OS, 'Windows') !== false;
    if ($isWindows) {
        $toWindowsPath = static function (string $path): string {
            return '"' . str_replace('/', '\\', $path) . '"';
        };
        $toWindowsArg = static function (string $value): string {
            $escaped = str_replace('"', '\"', $value);
            return '"' . $escaped . '"';
        };
        $args = [];
        foreach ($parts as $idx => $part) {
            if ($idx < 2) {
                $args[] = $toWindowsPath($part);
            } elseif (str_starts_with($part, '--path=')) {
                $pathValue = substr($part, strlen('--path='));
                $args[] = '--path=' . $toWindowsArg($pathValue);
            } else {
                $args[] = $part;
            }
        }
        $cmd = implode(' ', $args);
    } else {
        $args = [];
        foreach ($parts as $part) {
            if (str_starts_with($part, '--path=')) {
                $pathValue = substr($part, strlen('--path='));
                $args[] = '--path=' . escapeshellarg($pathValue);
            } else {
                $args[] = escapeshellarg($part);
            }
        }
        $cmd = implode(' ', $args);
    }

    $startedAt = date('c');
    $pid       = null;
    $unknown   = false;
    $spawnCmd  = null;
    $spawned   = false;
    $spawnErr  = null;

    $logger('Starte Scan-Worker: ' . $cmd);

    if ($isWindows) {
        $toWindowsPath = static function (string $path): string {
            return str_replace('/', '\\', $path);
        };
        $psArg = static function (string $value): string {
            return "'" . str_replace("'", "''", $value) . "'";
        };
        $psArgs = [$psArg($toWindowsPath($script))];
        if ($limit !== null && $limit > 0) {
            $psArgs[] = $psArg('--limit=' . (int)$limit);
        }
        if ($pathFilter !== null && trim($pathFilter) !== '') {
            $psArgs[] = $psArg('--path=' . $pathFilter);
        }
        if ($mediaId !== null && $mediaId > 0) {
            $psArgs[] = $psArg('--media-id=' . (int)$mediaId);
        }
        $psCommand = '$p = Start-Process -FilePath ' . $psArg($toWindowsPath($phpCli))
            . ' -ArgumentList ' . implode(',', $psArgs)
            . ' -RedirectStandardOutput ' . $psArg($toWindowsPath($spawnOutLog))
            . ' -RedirectStandardError ' . $psArg($toWindowsPath($spawnErrLog))
            . ' -PassThru; if ($p) { $p.Id }';
        $spawnCmd = 'powershell -NoProfile -Command ' . escapeshellarg($psCommand);
        $output = shell_exec($spawnCmd);
        if ($output !== null && trim($output) !== '') {
            $pid = (int)trim($output);
            if ($pid <= 0) {
                $pid = null;
            }
        } else {
            $unknown = true;
            $spawnErr = 'Kein PID aus PowerShell';
        }
        if ($pid !== null) {
            $spawned = true;
        }
    } else {
        $spawnCmd = 'nohup ' . $cmd
            . ' >> ' . escapeshellarg($spawnOutLog) . ' 2>> ' . escapeshellarg($spawnErrLog) . ' & echo $!';
        $output = shell_exec($spawnCmd);
        if ($output !== null && trim($output) !== '') {
            $pid = (int)trim($output);
            if ($pid <= 0) {
                $pid = null;
            }
        } else {
            $unknown = true;
            $spawnErr = 'Kein PID aus shell_exec';
        }
        if ($pid !== null) {
            $spawned = true;
        }
    }

    $lastPayload = [
        'started_at' => $startedAt,
        'command'    => $spawnCmd ?? $cmd,
        'logs'       => $logPaths,
        'dedupeInfo' => [
            'path_filter' => $pathFilter,
            'limit'       => $limit,
            'media_id'    => $mediaId,
        ],
    ];
    $lastPayload['last_spawn_at'] = $now;
    $lastJson = json_encode($lastPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($lastJson === false || file_put_contents($spawnLastPath, $lastJson) === false) {
        sv_log_system_error($config, 'scan_worker_spawn_last_write_failed', ['path' => $spawnLastPath]);
    }

    $verifyWindowSeconds = 10;
    $lockVerified = false;
    $pidVerified = false;
    $lockReason = null;
    $checkLock = static function () use ($workerLockPath, $verifyWindowSeconds): array {
        if (!is_file($workerLockPath)) {
            return ['ok' => false, 'reason' => 'lock_missing'];
        }
        $raw = file_get_contents($workerLockPath);
        if ($raw === false) {
            return ['ok' => false, 'reason' => 'lock_read_failed'];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['ok' => false, 'reason' => 'lock_json_invalid'];
        }
        $heartbeat = isset($data['heartbeat_at']) ? strtotime((string)$data['heartbeat_at']) : false;
        if ($heartbeat === false) {
            return ['ok' => false, 'reason' => 'lock_heartbeat_invalid'];
        }
        if ((time() - $heartbeat) > $verifyWindowSeconds) {
            return ['ok' => false, 'reason' => 'lock_heartbeat_stale'];
        }
        return ['ok' => true, 'reason' => null];
    };
    $deadline = time() + $verifyWindowSeconds;
    while (time() <= $deadline) {
        if ($pid !== null) {
            $pidInfo = sv_is_pid_running($pid);
            if (!($pidInfo['unknown'] ?? false) && ($pidInfo['running'] ?? false)) {
                $pidVerified = true;
                break;
            }
        }
        $lockCheck = $checkLock();
        $lockReason = $lockCheck['reason'] ?? null;
        if (!empty($lockCheck['ok'])) {
            $lockVerified = true;
            break;
        }
        usleep(200000);
    }
    $verified = $spawned && ($pidVerified || $lockVerified);
    if (!$verified && $spawnErr === null) {
        $spawnErr = 'start_not_verified';
    }

    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);

    $reasonCode = $verified ? 'start_verified' : ($spawnErr === 'start_not_verified' ? 'spawn_unverified' : 'spawn_failed');
    $status = $verified ? 'running' : 'start_failed';

    return [
        'pid'     => $pid,
        'started' => $startedAt,
        'unknown' => $unknown,
        'running' => $verified,
        'skipped' => !$verified,
        'spawned' => $verified,
        'reason'  => $spawnErr,
        'reason_code' => $reasonCode,
        'message' => $verified
            ? 'Scan-Worker-Start verifiziert.'
            : 'Scan-Worker-Start nicht verifiziert' . ($lockReason !== null ? ' (' . $lockReason . ')' : '.'),
        'log_paths' => $logPaths,
        'status' => $status,
        'locked' => false,
    ];
}

function sv_spawn_update_center_run(array $config, string $action = 'update_ff_restart'): array
{
    if (!sv_is_cli() && !sv_has_valid_internal_key()) {
        throw new RuntimeException('Internal-Key erforderlich, um Update zu starten.');
    }

    $logsError = null;
    $logsRoot = sv_ensure_logs_root($config, $logsError);
    if ($logsRoot === null) {
        sv_log_system_error($config, 'update_center_log_root_unavailable', ['error' => $logsError]);
        return [
            'spawned'     => false,
            'started'     => date('c'),
            'error'       => 'Log-Root nicht verfügbar.',
            'reason_code' => 'log_root_unavailable',
            'message'     => 'Log-Root nicht verfügbar.',
            'status'      => 'open_failed',
            'path'        => sv_logs_root($config),
            'lock_path'   => null,
            'running'     => false,
            'locked'      => false,
            'log_paths'   => [],
        ];
    }

    $lockPath = $logsRoot . '/update_center.lock';
    $outLog   = $logsRoot . '/update_center.out.log';
    $errLog   = $logsRoot . '/update_center.err.log';
    $logPaths = [
        'stdout' => $outLog,
        'stderr' => $errLog,
    ];

    $lockHandle = fopen($lockPath, 'c+');
    if ($lockHandle === false) {
        sv_log_system_error($config, 'update_center_lock_open_failed', ['path' => $lockPath]);
        return [
            'spawned'     => false,
            'started'     => date('c'),
            'error'       => 'Update-Lock konnte nicht geöffnet werden.',
            'reason_code' => 'spawn_lock_open_failed',
            'message'     => 'Update-Lock konnte nicht geöffnet werden.',
            'status'      => 'open_failed',
            'path'        => $lockPath,
            'lock_path'   => $lockPath,
            'running'     => false,
            'locked'      => false,
            'log_paths'   => $logPaths,
        ];
    }

    $hasLock = flock($lockHandle, LOCK_EX | LOCK_NB);
    if (!$hasLock) {
        fclose($lockHandle);
        return [
            'spawned'     => false,
            'started'     => date('c'),
            'error'       => 'Update-Lock belegt.',
            'reason_code' => 'spawn_lock_busy',
            'message'     => 'Update-Lock belegt.',
            'status'      => 'locked',
            'path'        => $lockPath,
            'lock_path'   => $lockPath,
            'running'     => false,
            'locked'      => true,
            'log_paths'   => $logPaths,
        ];
    }

    $dbPathReason = null;
    $dbPath = sv_resolve_db_path($config, $dbPathReason);
    if ($dbPath === null) {
        try {
            return [
                'spawned'     => false,
                'error'       => 'DB-Pfad fehlt.',
                'reason_code' => $dbPathReason ?? 'db_path_missing',
                'message'     => 'DB-Pfad fehlt.',
                'status'      => 'config_failed',
                'path'        => null,
                'lock_path'   => $lockPath,
                'running'     => false,
                'locked'      => false,
                'log_paths'   => $logPaths,
            ];
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }
    if (!is_file($dbPath)) {
        try {
            return [
                'spawned'     => false,
                'error'       => 'DB-Datei fehlt.',
                'reason_code' => 'db_path_missing',
                'message'     => 'DB-Datei fehlt.',
                'status'      => 'config_failed',
                'path'        => $dbPath,
                'lock_path'   => $lockPath,
                'running'     => false,
                'locked'      => false,
                'log_paths'   => $logPaths,
            ];
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    $baseDir = sv_base_dir();
    $script  = $baseDir . '/start.ps1';
    if (!is_file($script)) {
        try {
            return [
                'spawned'     => false,
                'error'       => 'start.ps1 fehlt.',
                'reason_code' => 'script_missing',
                'message'     => 'start.ps1 fehlt.',
                'status'      => 'config_failed',
                'path'        => $script,
                'lock_path'   => $lockPath,
                'running'     => false,
                'locked'      => false,
                'log_paths'   => $logPaths,
            ];
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    $allowedActions = ['update_ff_restart', 'merge_restart'];
    if (!in_array($action, $allowedActions, true)) {
        $action = 'update_ff_restart';
    }

    $startedAt = date('c');
    $spawned   = false;
    $error     = null;
    $pid       = null;
    $unknown   = false;

    try {
        if (stripos(PHP_OS_FAMILY ?? PHP_OS, 'Windows') !== false) {
            $psCommand = 'powershell -NoProfile -Command '
                . escapeshellarg(
                    '$p = Start-Process -FilePath powershell -ArgumentList '
                    . "'-NoProfile','-ExecutionPolicy','Bypass','-File','" . str_replace("'", "''", $script) . "','-Action','" . str_replace("'", "''", $action) . "'"
                    . ' -RedirectStandardOutput "' . str_replace('/', '\\', $outLog) . '" -RedirectStandardError "' . str_replace('/', '\\', $errLog) . '" -PassThru;'
                    . ' if ($p) { $p.Id }'
                );
            $output = shell_exec($psCommand);
            if ($output !== null && trim($output) !== '') {
                $pid = (int)trim($output);
                if ($pid <= 0) {
                    $pid = null;
                }
            } else {
                $unknown = true;
                $error = 'Update-Start fehlgeschlagen.';
            }
            $spawned = $pid !== null;
        } else {
            $cmd = 'nohup pwsh -NoProfile -File ' . escapeshellarg($script) . ' -Action ' . escapeshellarg($action)
                . ' >> ' . escapeshellarg($outLog) . ' 2>> ' . escapeshellarg($errLog) . ' & echo $!';
            $output = shell_exec($cmd);
            if ($output !== null && trim($output) !== '') {
                $pid = (int)trim($output);
                if ($pid <= 0) {
                    $pid = null;
                }
                $spawned = $pid !== null;
            } else {
                $error = 'Update-Start fehlgeschlagen.';
            }
        }

        $verified = false;
        if ($pid !== null) {
            $pidInfo = sv_is_pid_running($pid);
            if (!($pidInfo['unknown'] ?? false) && ($pidInfo['running'] ?? false)) {
                $verified = true;
            }
        }
        if (!$verified) {
            $spawned = false;
            $error = $error ?? 'Start nicht verifiziert.';
        }

        return [
            'spawned'     => $spawned,
            'started'     => $startedAt,
            'pid'         => $pid,
            'unknown'     => $unknown,
            'error'       => $error,
            'reason_code' => $spawned ? 'start_verified' : 'spawn_unverified',
            'message'     => $spawned ? 'Update-Start verifiziert.' : 'Update-Start nicht verifiziert.',
            'status'      => $spawned ? 'running' : 'start_failed',
            'path'        => $script,
            'lock_path'   => $lockPath,
            'running'     => $spawned,
            'locked'      => false,
            'log_paths'   => $logPaths,
        ];
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

function sv_process_single_scan_job(PDO $pdo, array $config, array $jobRow, callable $logger): array
{
    $jobId   = (int)($jobRow['id'] ?? 0);
    $payload = json_decode((string)($jobRow['forge_request_json'] ?? ''), true) ?: [];

    $path = is_string($payload['path'] ?? null) ? trim((string)$payload['path']) : '';
    $limit = isset($payload['limit']) ? (int)$payload['limit'] : null;
    if ($limit !== null && $limit <= 0) {
        $limit = null;
    }

    if ($path === '') {
        sv_update_job_status($pdo, $jobId, 'error', null, 'Scan-Job ohne Pfad.');
        throw new RuntimeException('Scan-Job #' . $jobId . ' hat keinen Pfad.');
    }

    $jobLogger = function (string $msg) use ($logger, $jobId): void {
        $logger('[Job ' . $jobId . '] ' . $msg);
    };

    if (sv_is_job_canceled($pdo, $jobId)) {
        return sv_finalize_canceled_job($pdo, $jobId, ['path' => $path], $logger, 'scan pre-start');
    }

    $claimReason = null;
    if (!sv_claim_job_running($pdo, $jobId, ['queued'], ['path' => $path], $claimReason)) {
        $logger('Scan-Job #' . $jobId . ' nicht übernommen: ' . ($claimReason ?? 'claim_failed'));
        return [
            'job_id' => $jobId,
            'status' => 'skipped',
            'error'  => $claimReason ?? 'claim_failed_or_already_running',
        ];
    }
    $jobLogger('Beginne Scan für ' . sv_safe_path_label($path) . ($limit !== null ? ' (limit=' . $limit . ')' : ''));

    $cancelCheck = static function () use ($pdo, $jobId): bool {
        return sv_is_job_canceled($pdo, $jobId);
    };
    $result = sv_run_scan_operation($pdo, $config, $path, $limit, $jobLogger, $cancelCheck);

    if (!empty($result['canceled'])) {
        return sv_finalize_canceled_job($pdo, $jobId, [
            'path'  => sv_safe_path_label($path),
            'limit' => $limit,
        ], $logger, 'scan in-progress');
    }

    $response = [
        'path'         => sv_safe_path_label($path),
        'limit'        => $limit,
        'result'       => $result,
        'completed_at' => date('c'),
    ];

    sv_update_job_status(
        $pdo,
        $jobId,
        'done',
        json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        null
    );

    sv_audit_log($pdo, 'scan_job_done', 'jobs', $jobId, [
        'path'     => sv_safe_path_label($path),
        'limit'    => $limit,
        'result'   => $result,
        'job_id'   => $jobId,
    ]);

    return [
        'job_id' => $jobId,
        'status' => 'done',
        'result' => $result,
    ];
}

function sv_process_scan_job_batch(
    PDO $pdo,
    array $config,
    ?int $limit,
    callable $logger,
    ?string $pathFilter = null,
    ?int $mediaId = null,
    array $options = []
): array
{
    sv_recover_stuck_jobs($pdo, sv_scan_job_types(), SV_JOB_STUCK_MINUTES, $logger);

    $remaining = $limit !== null && $limit > 0 ? (int)$limit : null;
    $processed = 0;
    $done      = 0;
    $errors    = 0;
    $skipped   = 0;
    $rescanProcessed = 0;
    $scanProcessed   = 0;
    $backfillProcessed = 0;

    $filter = null;
    if ($pathFilter !== null && trim($pathFilter) !== '') {
        $normalized = realpath($pathFilter);
        $filter = $normalized !== false ? rtrim(str_replace('\\', '/', $normalized), '/') : trim($pathFilter);
    }

    // Backfill-Jobs zuerst abarbeiten
    $backfillLimit = isset($options['backfill_limit']) ? (int)$options['backfill_limit'] : null;
    if ($backfillLimit !== null && $backfillLimit <= 0) {
        $backfillLimit = null;
    }
    $backfillRemaining = $backfillLimit !== null
        ? ($remaining !== null ? min($remaining, $backfillLimit) : $backfillLimit)
        : $remaining;
    $backfillSql = 'SELECT * FROM jobs WHERE type = :type AND status = "queued" ORDER BY id ASC';
    if ($backfillRemaining !== null) {
        $backfillSql .= ' LIMIT :limit';
    }
    $backfillStmt = $pdo->prepare($backfillSql);
    $backfillStmt->bindValue(':type', SV_JOB_TYPE_SCAN_BACKFILL_TAGS, PDO::PARAM_STR);
    if ($backfillRemaining !== null) {
        $backfillStmt->bindValue(':limit', $backfillRemaining, PDO::PARAM_INT);
    }
    $backfillStmt->execute();
    $backfillJobs = $backfillStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($backfillJobs as $jobRow) {
        if ($remaining !== null && $remaining <= 0) {
            break;
        }
        if ($backfillRemaining !== null && $backfillRemaining <= 0) {
            break;
        }
        $jobId = (int)($jobRow['id'] ?? 0);
        try {
            $processed++;
            $backfillProcessed++;
            if ($remaining !== null) {
                $remaining--;
            }
            if ($backfillRemaining !== null) {
                $backfillRemaining--;
            }
            $logger('Verarbeite Backfill-Job #' . $jobId);
            $result = sv_process_scan_backfill_job($pdo, $config, $jobRow, $logger);
            if (($result['status'] ?? '') === 'done') {
                $done++;
            } elseif (($result['status'] ?? '') === 'skipped') {
                $skipped++;
            } elseif (($result['status'] ?? '') === SV_JOB_STATUS_CANCELED) {
                $done++;
            } else {
                $errors++;
            }
        } catch (Throwable $e) {
            $errors++;
            sv_update_job_status($pdo, $jobId, 'error', null, $e->getMessage());
            sv_audit_log($pdo, 'scan_backfill_failed', 'jobs', $jobId, [
                'error'  => $e->getMessage(),
                'job_id' => $jobId,
            ]);
            $logger('Fehler bei Backfill-Job #' . $jobId . ': ' . $e->getMessage());
        }
    }

    // Rescan-Medien danach abarbeiten
    $rescanLimit = isset($options['rescan_limit']) ? (int)$options['rescan_limit'] : null;
    if ($rescanLimit !== null && $rescanLimit <= 0) {
        $rescanLimit = null;
    }
    $rescanRemaining = $rescanLimit !== null
        ? ($remaining !== null ? min($remaining, $rescanLimit) : $rescanLimit)
        : $remaining;
    $rescanSql = 'SELECT * FROM jobs WHERE type = :type AND status = "queued"';
    if ($mediaId !== null && $mediaId > 0) {
        $rescanSql .= ' AND media_id = :media_id';
    }
    $rescanSql .= ' ORDER BY id ASC';
    if ($rescanRemaining !== null) {
        $rescanSql .= ' LIMIT :limit';
    }

    $rescanStmt = $pdo->prepare($rescanSql);
    $rescanStmt->bindValue(':type', SV_JOB_TYPE_RESCAN_MEDIA, PDO::PARAM_STR);
    if ($mediaId !== null && $mediaId > 0) {
        $rescanStmt->bindValue(':media_id', $mediaId, PDO::PARAM_INT);
    }
    if ($rescanRemaining !== null) {
        $rescanStmt->bindValue(':limit', $rescanRemaining, PDO::PARAM_INT);
    }
    $rescanStmt->execute();
    $rescanJobs = $rescanStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rescanJobs as $jobRow) {
        if ($remaining !== null && $remaining <= 0) {
            break;
        }
        if ($rescanRemaining !== null && $rescanRemaining <= 0) {
            break;
        }
        $jobId = (int)($jobRow['id'] ?? 0);
        try {
            $processed++;
            $rescanProcessed++;
            if ($remaining !== null) {
                $remaining--;
            }
            if ($rescanRemaining !== null) {
                $rescanRemaining--;
            }
            $logger('Verarbeite Rescan-Job #' . $jobId . ' (Media ' . (int)($jobRow['media_id'] ?? 0) . ')');
            $result = sv_process_rescan_media_job($pdo, $config, $jobRow, $logger);
            if (($result['status'] ?? '') === 'done' || ($result['status'] ?? '') === SV_JOB_STATUS_CANCELED) {
                $done++;
            } elseif (($result['status'] ?? '') === 'skipped') {
                $skipped++;
            } else {
                $errors++;
            }
        } catch (Throwable $e) {
            $errors++;
            sv_update_job_status($pdo, $jobId, 'error', null, $e->getMessage());
            sv_audit_log($pdo, 'rescan_job_failed', 'jobs', $jobId, [
                'error'  => $e->getMessage(),
                'job_id' => $jobId,
            ]);
            $logger('Fehler bei Rescan-Job #' . $jobId . ': ' . $e->getMessage());
        }
    }

    // Danach Scan-Path-Jobs
    $scanLimit = isset($options['scan_limit']) ? (int)$options['scan_limit'] : null;
    if ($scanLimit !== null && $scanLimit <= 0) {
        $scanLimit = null;
    }
    $scanRemaining = $scanLimit !== null
        ? ($remaining !== null ? min($remaining, $scanLimit) : $scanLimit)
        : $remaining;
    $scanSql = 'SELECT * FROM jobs WHERE type = :type AND status = "queued" ORDER BY id ASC';
    if ($scanRemaining !== null) {
        $scanSql .= ' LIMIT :limit';
    }

    $stmt = $pdo->prepare($scanSql);
    $stmt->bindValue(':type', SV_JOB_TYPE_SCAN_PATH, PDO::PARAM_STR);
    if ($scanRemaining !== null) {
        $stmt->bindValue(':limit', $scanRemaining, PDO::PARAM_INT);
    }
    $stmt->execute();

    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($jobs as $jobRow) {
        if ($remaining !== null && $remaining <= 0) {
            break;
        }
        if ($scanRemaining !== null && $scanRemaining <= 0) {
            break;
        }

        $jobId = (int)($jobRow['id'] ?? 0);
        $payload = json_decode((string)($jobRow['forge_request_json'] ?? ''), true) ?: [];
        $jobPath = is_string($payload['path'] ?? null) ? rtrim(str_replace('\\', '/', (string)$payload['path']), '/') : '';

        if ($filter !== null && $jobPath !== '' && strpos($jobPath, $filter) !== 0) {
            continue;
        }

        try {
            $processed++;
            $scanProcessed++;
            if ($remaining !== null) {
                $remaining--;
            }
            if ($scanRemaining !== null) {
                $scanRemaining--;
            }
            $logger('Verarbeite Scan-Job #' . $jobId . ' (' . $jobPath . ')');
            $result = sv_process_single_scan_job($pdo, $config, $jobRow, $logger);
            if (($result['status'] ?? '') === 'done' || ($result['status'] ?? '') === SV_JOB_STATUS_CANCELED) {
                $done++;
            } elseif (($result['status'] ?? '') === 'skipped') {
                $skipped++;
            } else {
                $errors++;
            }
        } catch (Throwable $e) {
            $errors++;
            $safeError = sv_sanitize_error_message($e->getMessage());
            sv_update_job_status($pdo, $jobId, 'error', null, $safeError);
            sv_audit_log($pdo, 'scan_job_failed', 'jobs', $jobId, [
                'error'  => $safeError,
                'job_id' => $jobId,
            ]);
            $logger('Fehler bei Scan-Job #' . $jobId . ': ' . $safeError);
        }
    }

    return [
        'total'   => $processed,
        'done'    => $done,
        'error'   => $errors,
        'skipped' => $skipped,
        'rescan'  => $rescanProcessed,
        'scan'    => $scanProcessed,
        'backfill'=> $backfillProcessed,
    ];
}

function sv_fetch_scan_jobs(PDO $pdo, ?string $pathFilter = null, int $limit = 20): array
{
    $limit = max(1, min(100, $limit));

    $stmt = $pdo->prepare('SELECT * FROM jobs WHERE type = :type ORDER BY id DESC LIMIT :limit');
    $stmt->bindValue(':type', SV_JOB_TYPE_SCAN_PATH, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $jobs = [];

    $filter = null;
    if ($pathFilter !== null && trim($pathFilter) !== '') {
        $normalized = realpath($pathFilter);
        $filter = $normalized !== false ? rtrim(str_replace('\\', '/', $normalized), '/') : trim($pathFilter);
    }

    foreach ($rows as $row) {
        $payload  = json_decode((string)($row['forge_request_json'] ?? ''), true) ?: [];
        $response = json_decode((string)($row['forge_response_json'] ?? ''), true) ?: [];
        $path     = is_string($payload['path'] ?? null) ? rtrim((string)$payload['path'], '/') : '';

        if ($filter !== null && $path !== '' && strpos($path, $filter) !== 0) {
            continue;
        }

        $jobs[] = [
            'id'          => (int)($row['id'] ?? 0),
            'status'      => (string)($row['status'] ?? ''),
            'path'        => $path !== '' ? sv_safe_path_label($path) : '',
            'limit'       => isset($payload['limit']) ? (int)$payload['limit'] : null,
            'created_at'  => (string)($row['created_at'] ?? ''),
            'updated_at'  => (string)($row['updated_at'] ?? ''),
            'result'      => $response['result'] ?? null,
            'error'       => isset($row['error_message']) ? sv_sanitize_error_message((string)$row['error_message']) : null,
            'worker_pid'  => $response['_sv_worker_pid'] ?? null,
            'worker_started_at' => $response['_sv_worker_started_at'] ?? null,
        ];
    }

    return $jobs;
}

function sv_fetch_scan_related_jobs(PDO $pdo, int $limit = 30): array
{
    $limit = max(1, min(100, $limit));
    $types = sv_scan_job_types();
    $placeholders = implode(',', array_fill(0, count($types), '?'));
    $sql = 'SELECT id, media_id, type, status, created_at, updated_at, forge_request_json, forge_response_json, error_message '
        . 'FROM jobs WHERE type IN (' . $placeholders . ') ORDER BY id DESC LIMIT ?';
    $stmt = $pdo->prepare($sql);
    $idx = 1;
    foreach ($types as $type) {
        $stmt->bindValue($idx++, $type, PDO::PARAM_STR);
    }
    $stmt->bindValue($idx, $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $jobs = [];

    foreach ($rows as $row) {
        $payload  = json_decode((string)($row['forge_request_json'] ?? ''), true) ?: [];
        $response = json_decode((string)($row['forge_response_json'] ?? ''), true) ?: [];
        $type     = (string)($row['type'] ?? '');

        $progress = is_array($response['progress'] ?? null) ? $response['progress'] : null;
        $jobs[] = [
            'id'          => (int)($row['id'] ?? 0),
            'media_id'    => (int)($row['media_id'] ?? 0),
            'type'        => $type,
            'status'      => (string)($row['status'] ?? ''),
            'created_at'  => (string)($row['created_at'] ?? ''),
            'updated_at'  => (string)($row['updated_at'] ?? ''),
            'started_at'  => (string)($response['started_at'] ?? ''),
            'finished_at' => (string)($response['finished_at'] ?? ''),
            'path'        => is_string($payload['path'] ?? null) ? sv_safe_path_label((string)$payload['path']) : null,
            'limit'       => isset($payload['limit']) ? (int)$payload['limit'] : null,
            'mode'        => is_string($payload['mode'] ?? null) ? (string)$payload['mode'] : null,
            'progress'    => $progress,
            'error'       => isset($row['error_message'])
                ? sv_sanitize_error_message((string)$row['error_message'])
                : (isset($response['error']) ? sv_sanitize_error_message((string)$response['error']) : null),
        ];
    }

    return $jobs;
}

function sv_fetch_rescan_jobs_for_media(PDO $pdo, int $mediaId, int $limit = 5): array
{
    $limit = max(1, min(20, $limit));

    $stmt = $pdo->prepare(
        'SELECT id, status, created_at, updated_at, forge_request_json, forge_response_json, error_message '
        . 'FROM jobs WHERE type = :type AND media_id = :media_id ORDER BY id DESC LIMIT :limit'
    );
    $stmt->bindValue(':type', SV_JOB_TYPE_RESCAN_MEDIA, PDO::PARAM_STR);
    $stmt->bindValue(':media_id', $mediaId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $jobs = [];

    foreach ($rows as $row) {
        $payload  = json_decode((string)($row['forge_request_json'] ?? ''), true) ?: [];
        $response = json_decode((string)($row['forge_response_json'] ?? ''), true) ?: [];
        $pathLabel = isset($payload['path']) ? sv_safe_path_label((string)$payload['path']) : '';
        $timing = sv_extract_job_timestamps($row, $response);
        $stuckInfo = sv_job_stuck_info($row, $response, SV_JOB_STUCK_MINUTES);
        $jobs[] = [
            'id'          => (int)($row['id'] ?? 0),
            'status'      => (string)($row['status'] ?? ''),
            'created_at'  => (string)($row['created_at'] ?? ''),
            'updated_at'  => (string)($row['updated_at'] ?? ''),
            'path'        => $pathLabel,
            'result'      => $response['result'] ?? null,
            'completed_at'=> $response['completed_at'] ?? null,
            'error'       => sv_sanitize_error_message((string)($row['error_message'] ?? ($response['error'] ?? ''))),
            'scanner'     => $response['scanner'] ?? null,
            'nsfw_score'  => $response['nsfw_score'] ?? null,
            'run_at'      => $response['run_at'] ?? null,
            'has_nsfw'    => $response['has_nsfw'] ?? null,
            'rating'      => $response['rating'] ?? null,
            'started_at'  => $timing['started_at'],
            'finished_at' => $timing['finished_at'],
            'tags_written'=> $response['tags_written'] ?? null,
            'age_seconds' => $timing['age_seconds'],
            'age_label'   => $timing['age_label'],
            'stuck'       => $stuckInfo['stuck'],
            'stuck_reason'=> $stuckInfo['reason'],
        ];
    }

    return $jobs;
}

function sv_find_strict_dupes(PDO $pdo, ?string $hashFilter = null, int $limit = 500): array
{
    $limit = max(1, min(2000, $limit));
    $params = [];
    $sql = 'SELECT hash, COUNT(*) AS cnt FROM media WHERE hash IS NOT NULL';
    if ($hashFilter !== null && trim($hashFilter) !== '') {
        $sql           .= ' AND hash = :hash';
        $params[':hash'] = trim($hashFilter);
    }
    $sql .= ' GROUP BY hash HAVING COUNT(*) > 1 ORDER BY cnt DESC, hash ASC LIMIT :limit';

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($groups === []) {
        return [];
    }

    $hashes       = array_column($groups, 'hash');
    $placeholder  = implode(',', array_fill(0, count($hashes), '?'));
    $detailStmt   = $pdo->prepare(
        'SELECT id, path, status, rating, has_nsfw, type, hash FROM media WHERE hash IN (' . $placeholder . ') ORDER BY id ASC'
    );
    $detailStmt->execute($hashes);
    $rows = $detailStmt->fetchAll(PDO::FETCH_ASSOC);

    $byHash = [];
    foreach ($rows as $row) {
        $h = (string)$row['hash'];
        if (!isset($byHash[$h])) {
            $byHash[$h] = [];
        }
        $byHash[$h][] = $row;
    }

    $result = [];
    foreach ($groups as $group) {
        $hash  = (string)$group['hash'];
        $items = $byHash[$hash] ?? [];
        $masterId = null;
        foreach ($items as $item) {
            $preferred = ($item['status'] ?? '') !== 'missing';
            if ($masterId === null) {
                $masterId = (int)$item['id'];
                continue;
            }
            if ($preferred && $masterId > (int)$item['id']) {
                $masterId = (int)$item['id'];
            }
        }
        if ($masterId === null && $items) {
            $masterId = (int)$items[0]['id'];
        }
        $result[] = [
            'hash'      => $hash,
            'count'     => (int)$group['cnt'],
            'media'     => $items,
            'master_id' => $masterId,
        ];
    }

    return $result;
}

function sv_enqueue_library_rename_jobs(PDO $pdo, array $config, int $limit, int $offset, callable $logger): array
{
    $limit  = max(1, min(1000, $limit));
    $offset = max(0, $offset);
    $pathsCfg = $config['paths'] ?? [];

    $stmt = $pdo->prepare(
        'SELECT id, path, type, has_nsfw, hash FROM media WHERE hash IS NOT NULL ORDER BY id ASC LIMIT :limit OFFSET :offset'
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows           = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $enqueued       = 0;
    $alreadyPresent = 0;
    $skipped        = 0;

    $checkJob = $pdo->prepare(
        'SELECT id FROM jobs WHERE media_id = :media_id AND type = :type AND status = "queued" LIMIT 1'
    );

    $insertJob = $pdo->prepare(
        'INSERT INTO jobs (media_id, prompt_id, type, status, created_at, updated_at, forge_request_json) '
        . 'VALUES (:media_id, NULL, :type, :status, :created_at, NULL, :payload)'
    );

    foreach ($rows as $row) {
        $target = sv_resolve_media_target($row, $pathsCfg);
        if ($target === null) {
            $skipped++;
            continue;
        }

        $currentPath = str_replace('\\', '/', (string)$row['path']);
        if ($currentPath === $target['path']) {
            $skipped++;
            continue;
        }

        try {
            sv_assert_media_path_allowed($currentPath, $pathsCfg, 'library_rename_source');
            sv_assert_media_path_allowed($target['path'], $pathsCfg, 'library_rename_target');
        } catch (Throwable $e) {
            $logger('Pfadvalidierung fehlgeschlagen für Media #' . (int)$row['id'] . ': ' . $e->getMessage());
            $skipped++;
            continue;
        }

        $checkJob->execute([
            ':media_id' => (int)$row['id'],
            ':type'     => SV_JOB_TYPE_LIBRARY_RENAME,
        ]);
        if ($checkJob->fetchColumn()) {
            $alreadyPresent++;
            continue;
        }

        try {
            sv_enforce_job_queue_capacity($pdo, $config, SV_JOB_TYPE_LIBRARY_RENAME, (int)$row['id']);
        } catch (Throwable $e) {
            $logger('Library-Rename Queue-Limit erreicht: ' . sv_sanitize_error_message($e->getMessage()));
            break;
        }

        $payload = [
            'media_id' => (int)$row['id'],
            'from_path'=> $currentPath,
            'to_path'  => $target['path'],
            'hash'     => (string)$row['hash'],
            'ext'      => strtolower((string)pathinfo($currentPath, PATHINFO_EXTENSION)),
        ];

        $insertJob->execute([
            ':media_id'   => (int)$row['id'],
            ':type'       => SV_JOB_TYPE_LIBRARY_RENAME,
            ':status'     => 'queued',
            ':created_at' => date('c'),
            ':payload'    => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        $enqueued++;
    }

    if ($enqueued > 0) {
        $logger('Library-Rename Jobs angelegt: ' . $enqueued);
    }

    return [
        'enqueued'        => $enqueued,
        'already_present' => $alreadyPresent,
        'skipped'         => $skipped,
    ];
}

function sv_process_library_rename_jobs(PDO $pdo, array $config, int $limit, callable $logger): array
{
    $limit = max(1, min(200, $limit));
    $pathsCfg = $config['paths'] ?? [];

    $stmt = $pdo->prepare(
        'SELECT * FROM jobs WHERE type = :type AND status = "queued" ORDER BY id ASC LIMIT :limit'
    );
    $stmt->bindValue(':type', SV_JOB_TYPE_LIBRARY_RENAME, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $done = 0;
    $error = 0;

    foreach ($jobs as $job) {
        $jobId = (int)$job['id'];
        $payload = json_decode((string)($job['forge_request_json'] ?? ''), true) ?: [];
        $mediaId = (int)($payload['media_id'] ?? $job['media_id']);
        $from    = isset($payload['from_path']) ? str_replace('\\', '/', (string)$payload['from_path']) : '';
        $to      = isset($payload['to_path']) ? str_replace('\\', '/', (string)$payload['to_path']) : '';
        $hash    = isset($payload['hash']) ? (string)$payload['hash'] : '';
        $ext     = isset($payload['ext']) ? (string)$payload['ext'] : '';

        $updateStatus = function (string $status, ?string $errorMsg = null, ?array $response = null) use ($pdo, $jobId): void {
            $responseJson = $response !== null
                ? json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : null;
            sv_update_job_status($pdo, $jobId, $status, $responseJson, $errorMsg);
        };

        $claimReason = null;
        if (!sv_claim_job_running($pdo, $jobId, ['queued'], [
            'from_path' => $from,
            'to_path'   => $to,
            'hash'      => $hash,
        ], $claimReason)) {
            $logger('Library-Rename Job #' . $jobId . ' übersprungen (claim failed): ' . ($claimReason ?? 'claim_failed'));
            continue;
        }

        try {
            if ($from === '' || $to === '' || $hash === '') {
                throw new RuntimeException('Payload unvollständig.');
            }

            sv_assert_media_path_allowed($from, $pathsCfg, 'library_rename_source');
            sv_assert_media_path_allowed($to, $pathsCfg, 'library_rename_target');

            $targetExists = is_file($to);
            if ($targetExists) {
                $targetHash = @hash_file('md5', $to);
                if ($targetHash !== $hash) {
                    throw new RuntimeException('Zielpfad belegt mit anderem Inhalt.');
                }
            }

            if (!$targetExists) {
                if (is_file($from)) {
                    if (!sv_move_file($from, $to)) {
                        throw new RuntimeException('Verschieben fehlgeschlagen.');
                    }
                } else {
                    throw new RuntimeException('Quelldatei fehlt.');
                }
            }

            $pdo->beginTransaction();
            $upd = $pdo->prepare('UPDATE media SET path = :path WHERE id = :id');
            $upd->execute([
                ':path' => $to,
                ':id'   => $mediaId,
            ]);

            $metaCheck = $pdo->prepare('SELECT 1 FROM media_meta WHERE media_id = ? AND source = ? AND meta_key = ? LIMIT 1');
            $metaIns   = $pdo->prepare('INSERT INTO media_meta (media_id, source, meta_key, meta_value, created_at) VALUES (?, ?, ?, ?, ?)');
            foreach ([['import', 'original_path', $from], ['import', 'original_name', basename($from)]] as $metaDef) {
                $metaCheck->execute([$mediaId, $metaDef[0], $metaDef[1]]);
                if ($metaCheck->fetchColumn() === false) {
                    $metaIns->execute([$mediaId, $metaDef[0], $metaDef[1], $metaDef[2], date('c')]);
                }
            }
            $metaIns->execute([$mediaId, 'library_rename', 'rename_at', date('c'), date('c')]);

            $pdo->commit();

            $updateStatus('done', null, [
                'from_path' => $from,
                'to_path'   => $to,
                'hash'      => $hash,
            ]);
            $done++;
            $logger('Library-Rename Job #' . $jobId . ' abgeschlossen.');
            sv_audit_log($pdo, 'library_rename', 'media', $mediaId, [
                'job_id' => $jobId,
                'from'   => $from,
                'to'     => $to,
                'ext'    => $ext,
            ]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            $error++;
            $updateStatus('error', $e->getMessage(), [
                'from_path' => $from,
                'to_path'   => $to,
                'hash'      => $hash,
            ]);
            $logger('Library-Rename Job #' . $jobId . ' Fehler: ' . $e->getMessage());
        }
    }

    return [
        'total' => count($jobs),
        'done'  => $done,
        'error' => $error,
    ];
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

function sv_log_scan_worker_event(string $message): void
{
    $config = sv_load_config();
    $logsError = null;
    $logsRoot = sv_ensure_logs_root($config, $logsError);
    if ($logsRoot === null) {
        sv_log_system_error($config, 'scan_worker_log_root_unavailable', ['error' => $logsError]);
        return;
    }
    $path = $logsRoot . '/scan_worker.err.log';
    $line = '[' . date('c') . '] ' . $message . PHP_EOL;
    $result = file_put_contents($path, $line, FILE_APPEND);
    if ($result === false) {
        sv_log_system_error($config, 'scan_worker_log_write_failed', ['path' => $path]);
    }
}

function sv_fetch_job_status(PDO $pdo, int $jobId): string
{
    $stmt = $pdo->prepare('SELECT status FROM jobs WHERE id = :id');
    $stmt->execute([':id' => $jobId]);
    return (string)$stmt->fetchColumn();
}

function sv_is_job_canceled(PDO $pdo, int $jobId): bool
{
    return sv_fetch_job_status($pdo, $jobId) === SV_JOB_STATUS_CANCELED;
}

function sv_finalize_canceled_job(PDO $pdo, int $jobId, array $responseMeta, callable $logger, string $note): array
{
    $payload = array_merge([
        'result'      => 'canceled',
        'canceled_at' => date('c'),
    ], $responseMeta);

    sv_update_job_status(
        $pdo,
        $jobId,
        SV_JOB_STATUS_CANCELED,
        json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'Canceled by operator'
    );

    $logger('Job #' . $jobId . ' abgebrochen (Cancel-Signal erkannt).');
    sv_log_scan_worker_event('Cancel erkannt: Job #' . $jobId . ' (' . $note . ')');

    return [
        'job_id' => $jobId,
        'status' => SV_JOB_STATUS_CANCELED,
        'result' => $payload,
    ];
}

function sv_cancel_job(PDO $pdo, int $jobId, callable $logger): array
{
    $job = sv_load_job($pdo, $jobId);
    $type   = (string)($job['type'] ?? '');
    $status = (string)($job['status'] ?? '');

    $cancelableTypes = array_merge([SV_FORGE_JOB_TYPE], sv_scan_job_types());
    if (!in_array($type, $cancelableTypes, true)) {
        throw new InvalidArgumentException('Abbruch wird für diesen Job-Typ nicht unterstützt.');
    }
    if (!in_array($status, ['queued', 'running'], true)) {
        throw new InvalidArgumentException('Nur queued/running-Jobs können abgebrochen werden.');
    }

    $payload = [
        'result'      => 'canceled',
        'canceled_at' => date('c'),
    ];
    sv_update_job_status(
        $pdo,
        $jobId,
        SV_JOB_STATUS_CANCELED,
        json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'Canceled by operator'
    );

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

function sv_delete_job(PDO $pdo, int $jobId, callable $logger): array
{
    $job = sv_load_job($pdo, $jobId);
    $currentStatus = '';
    $currentType = '';
    $deletedCount = 0;

    $pdo->beginTransaction();
    try {
        $check = $pdo->prepare('SELECT id, type, status FROM jobs WHERE id = :id');
        $check->execute([':id' => $jobId]);
        $row = $check->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new InvalidArgumentException('Job nicht gefunden.');
        }
        $currentStatus = (string)($row['status'] ?? '');
        $currentType = (string)($row['type'] ?? '');
        if (!in_array($currentStatus, sv_finished_job_statuses(), true)) {
            throw new InvalidArgumentException('Nur abgeschlossene Jobs können gelöscht werden.');
        }

        $placeholders = implode(',', array_fill(0, count(sv_finished_job_statuses()), '?'));
        $params = array_merge([$jobId], sv_finished_job_statuses());
        $stmt = $pdo->prepare('DELETE FROM jobs WHERE id = ? AND status IN (' . $placeholders . ')');
        $stmt->execute($params);
        $deletedCount = $stmt->rowCount();
        if ($deletedCount <= 0) {
            throw new RuntimeException('Job-Status geändert, Löschung abgebrochen.');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $logger('Job #' . $jobId . ' gelöscht.');
    sv_audit_log($pdo, 'job_delete', 'jobs', $jobId, [
        'previous_status' => (string)($job['status'] ?? ''),
        'checked_status'  => $currentStatus,
        'job_type'        => $currentType,
    ]);

    return [
        'job_id'       => $jobId,
        'status'       => $currentStatus,
        'job_type'     => $currentType,
        'deleted'      => $deletedCount > 0,
        'deleted_count'=> $deletedCount,
    ];
}

function sv_prune_jobs(PDO $pdo, array $criteria, callable $logger): array
{
    $statuses = sv_finished_job_statuses();
    $types = isset($criteria['types']) && is_array($criteria['types'])
        ? array_values(array_filter(array_map('strval', $criteria['types'])))
        : [];
    $olderThanDays = isset($criteria['older_than_days']) ? (int)$criteria['older_than_days'] : null;
    $keepLast = isset($criteria['keep_last']) ? max(0, (int)$criteria['keep_last']) : 0;

    $where = ['status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')'];
    $params = $statuses;
    if ($types !== []) {
        $where[] = 'type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
        $params = array_merge($params, $types);
    }
    if ($olderThanDays !== null && $olderThanDays > 0) {
        $where[] = 'updated_at <= ?';
        $params[] = date('c', time() - ($olderThanDays * 86400));
    }

    $sql = 'SELECT id, type, status FROM jobs WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC';
    $stmt = $pdo->prepare($sql);
    foreach ($params as $idx => $value) {
        $stmt->bindValue($idx + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $candidates = [];
    if ($rows !== []) {
        if ($keepLast > 0) {
            $byType = [];
            foreach ($rows as $row) {
                $type = (string)($row['type'] ?? '');
                if (!isset($byType[$type])) {
                    $byType[$type] = [];
                }
                $byType[$type][] = $row;
            }
            foreach ($byType as $type => $entries) {
                $keep = array_slice($entries, 0, $keepLast);
                $keepIds = array_map(static fn ($r) => (int)$r['id'], $keep);
                foreach ($entries as $entry) {
                    $id = (int)$entry['id'];
                    if (!in_array($id, $keepIds, true)) {
                        $candidates[] = $entry;
                    }
                }
            }
        } else {
            $candidates = $rows;
        }
    }

    $ids = [];
    foreach ($candidates as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $ids[] = $id;
    }

    $deletedTotal = 0;
    $skipped = 0;
    $deletedByStatus = [];
    $deletedByType = [];

    if ($ids !== []) {
        $chunks = array_chunk($ids, 200);
        foreach ($chunks as $chunk) {
            $pdo->beginTransaction();
            try {
                $chunkPlaceholders = implode(',', array_fill(0, count($chunk), '?'));
                $statusPlaceholders = implode(',', array_fill(0, count($statuses), '?'));
                $select = $pdo->prepare(
                    'SELECT id, type, status FROM jobs WHERE id IN (' . $chunkPlaceholders . ')'
                    . ' AND status IN (' . $statusPlaceholders . ')'
                );
                $pos = 1;
                foreach ($chunk as $id) {
                    $select->bindValue($pos++, $id, PDO::PARAM_INT);
                }
                foreach ($statuses as $status) {
                    $select->bindValue($pos++, $status, PDO::PARAM_STR);
                }
                $select->execute();
                $rowsToDelete = $select->fetchAll(PDO::FETCH_ASSOC);

                $deleteIds = [];
                foreach ($rowsToDelete as $row) {
                    $deleteIds[] = (int)$row['id'];
                    $status = (string)($row['status'] ?? '');
                    $type = (string)($row['type'] ?? '');
                    $deletedByStatus[$status] = ($deletedByStatus[$status] ?? 0) + 1;
                    $deletedByType[$type] = ($deletedByType[$type] ?? 0) + 1;
                }
                $skipped += count($chunk) - count($deleteIds);

                if ($deleteIds !== []) {
                    $deletePlaceholders = implode(',', array_fill(0, count($deleteIds), '?'));
                    $delete = $pdo->prepare(
                        'DELETE FROM jobs WHERE id IN (' . $deletePlaceholders . ') AND status IN (' . $statusPlaceholders . ')'
                    );
                    $pos = 1;
                    foreach ($deleteIds as $id) {
                        $delete->bindValue($pos++, $id, PDO::PARAM_INT);
                    }
                    foreach ($statuses as $status) {
                        $delete->bindValue($pos++, $status, PDO::PARAM_STR);
                    }
                    $delete->execute();
                    $deletedTotal += $delete->rowCount();
                }

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }
    }

    $logger('Jobs bereinigt: ' . $deletedTotal . ' gelöscht, ' . $skipped . ' übersprungen.');
    sv_audit_log($pdo, 'jobs_pruned', 'jobs', null, [
        'types'           => $types,
        'older_than_days' => $olderThanDays,
        'keep_last'       => $keepLast,
        'candidates_total'=> count($ids),
        'deleted_total'   => $deletedTotal,
        'skipped_total'   => $skipped,
        'by_status'       => $deletedByStatus,
        'by_type'         => $deletedByType,
    ]);

    return [
        'candidates_total' => count($ids),
        'deleted_total'    => $deletedTotal,
        'skipped_total'    => $skipped,
        'by_status'        => $deletedByStatus,
        'by_type'          => $deletedByType,
    ];
}

function sv_fetch_media_tags_for_regen(PDO $pdo, int $mediaId, int $limit = 8): array
{
    $limit = max(1, $limit);
    $stmt = $pdo->prepare(
        'SELECT t.name, t.type, mt.locked, mt.confidence '
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

function sv_build_tag_prompt(array $tags, int $limit = SV_FORGE_MAX_TAGS_PROMPT): ?string
{
    $names = [];
    foreach ($tags as $tag) {
        if (!isset($tag['name']) || !is_string($tag['name'])) {
            continue;
        }
        $names[] = trim((string)$tag['name']);
    }

    $names = array_values(array_filter($names, static fn ($n) => $n !== ''));
    if ($names === []) {
        return null;
    }

    $limited = array_slice($names, 0, max(1, $limit));

    return implode(', ', $limited);
}

function sv_get_or_create_tag(PDO $pdo, string $name, string $type): int
{
    $name = trim($name);
    $type = trim($type) !== '' ? trim($type) : 'content';
    if ($name === '') {
        throw new InvalidArgumentException('Tag-Name fehlt.');
    }

    $insert = $pdo->prepare('INSERT OR IGNORE INTO tags (name, type, locked) VALUES (?, ?, 0)');
    $insert->execute([$name, $type]);

    $idStmt = $pdo->prepare('SELECT id FROM tags WHERE name = ? AND type = ?');
    $idStmt->execute([$name, $type]);
    $tagId = (int)$idStmt->fetchColumn();
    if ($tagId <= 0) {
        throw new RuntimeException('Tag konnte nicht geladen werden.');
    }

    return $tagId;
}

function sv_update_media_tags(PDO $pdo, int $mediaId, string $action, array $payload, callable $logger): array
{
    $name       = trim((string)($payload['name'] ?? ''));
    $type       = trim((string)($payload['type'] ?? ''));
    $confidence = isset($payload['confidence']) && is_numeric($payload['confidence'])
        ? max(0, (float)$payload['confidence'])
        : null;
    $lockFlag   = !empty($payload['lock']);

    if ($name === '') {
        throw new InvalidArgumentException('Tag-Name fehlt.');
    }

    $tagId = sv_get_or_create_tag($pdo, $name, $type);
    $select = $pdo->prepare('SELECT locked FROM media_tags WHERE media_id = ? AND tag_id = ?');
    $select->execute([$mediaId, $tagId]);
    $existing = $select->fetch(PDO::FETCH_ASSOC);

    if ($action === 'add') {
        $stmt = $pdo->prepare(
            'INSERT INTO media_tags (media_id, tag_id, confidence, locked) VALUES (?, ?, ?, ?)'
            . ' ON CONFLICT(media_id, tag_id) DO UPDATE SET confidence = CASE WHEN media_tags.locked = 0 THEN excluded.confidence ELSE media_tags.confidence END'
        );
        $stmt->execute([$mediaId, $tagId, $confidence ?? 1.0, $lockFlag ? 1 : 0]);
        $logger('Tag hinzugefügt: ' . $name . ' (' . $tagId . ')');
        sv_audit_log($pdo, 'tag_add', 'media', $mediaId, [
            'tag_id'     => $tagId,
            'tag_name'   => $name,
            'lock'       => $lockFlag,
            'confidence' => $confidence,
        ]);
        return ['action' => 'added', 'tag_id' => $tagId, 'locked' => $lockFlag];
    }

    if ($existing === false) {
        throw new RuntimeException('Tag nicht mit diesem Medium verknüpft.');
    }

    $isLocked = (int)($existing['locked'] ?? 0) === 1;

    if ($action === 'remove') {
        if ($isLocked) {
            throw new RuntimeException('Tag ist gesperrt und kann nicht entfernt werden. Erst entsperren.');
        }
        $del = $pdo->prepare('DELETE FROM media_tags WHERE media_id = ? AND tag_id = ?');
        $del->execute([$mediaId, $tagId]);
        sv_audit_log($pdo, 'tag_remove', 'media', $mediaId, [
            'tag_id'   => $tagId,
            'tag_name' => $name,
        ]);
        $logger('Tag entfernt: ' . $name);
        return ['action' => 'removed', 'tag_id' => $tagId];
    }

    if ($action === 'lock' || $action === 'unlock') {
        $newLock = $action === 'lock';
        $upd = $pdo->prepare('UPDATE media_tags SET locked = ? WHERE media_id = ? AND tag_id = ?');
        $upd->execute([$newLock ? 1 : 0, $mediaId, $tagId]);
        sv_audit_log($pdo, 'tag_lock_toggle', 'media', $mediaId, [
            'tag_id'   => $tagId,
            'tag_name' => $name,
            'locked'   => $newLock,
        ]);
        $logger('Tag ' . ($newLock ? 'gesperrt' : 'entsperrt') . ': ' . $name);
        return ['action' => 'lock', 'tag_id' => $tagId, 'locked' => $newLock];
    }

    throw new RuntimeException('Unbekannte Tag-Aktion.');
}

function sv_rescan_single_media(PDO $pdo, array $config, int $mediaId, callable $logger): array
{
    $mediaStmt = $pdo->prepare('SELECT * FROM media WHERE id = :id');
    $mediaStmt->execute([':id' => $mediaId]);
    $mediaRow = $mediaStmt->fetch(PDO::FETCH_ASSOC);
    if (!$mediaRow) {
        throw new InvalidArgumentException('Media-Eintrag nicht gefunden.');
    }

    $path = (string)($mediaRow['path'] ?? '');
    if ($path === '' || !is_file($path)) {
        throw new RuntimeException('Datei nicht gefunden oder Pfad fehlt.');
    }

    $pathsCfg      = $config['paths'] ?? [];
    $scannerCfg    = $config['scanner'] ?? [];
    $nsfwThreshold = (float)($scannerCfg['nsfw_threshold'] ?? 0.7);

    sv_assert_media_path_allowed($path, $pathsCfg, 'rescan_single');

    $ok = sv_rescan_media($pdo, $mediaRow, $pathsCfg, $scannerCfg, $nsfwThreshold, $logger);
    if ($ok) {
        $pdo->prepare("DELETE FROM media_meta WHERE media_id = ? AND meta_key = 'scan_stale'")->execute([$mediaId]);
        sv_audit_log($pdo, 'media_rescan', 'media', $mediaId, [
            'path' => $path,
        ]);
        $logger('Rescan abgeschlossen.');
    } else {
        sv_audit_log($pdo, 'media_rescan_failed', 'media', $mediaId, [
            'path' => $path,
        ]);
        $logger('Rescan fehlgeschlagen.');
    }

    return [
        'success' => $ok,
    ];
}

function sv_analyze_prompt_quality(?array $promptRow, array $tags): array
{
    $promptText = '';
    if ($promptRow !== null) {
        $promptText = trim((string)($promptRow['prompt'] ?? ''));
    }

    $width  = isset($promptRow['width']) ? (int)$promptRow['width'] : null;
    $height = isset($promptRow['height']) ? (int)$promptRow['height'] : null;

    $score  = 85;
    $issues = [];

    $tagPrompt = sv_build_tag_prompt($tags);
    $hybridSuggestion = null;

    if ($promptText === '') {
        $issues[] = 'too_short';
        $issues[] = 'missing_subject';
        $score -= 35;
    }

    $fragments = array_filter(array_map('trim', explode(',', $promptText)), static fn ($f) => $f !== '');
    $wordCount = $promptText === '' ? 0 : (int)preg_match_all('/[\p{L}\p{N}]{2,}/u', $promptText, $wordMatches);
    $fragmentWordCounts = [];
    foreach ($fragments as $fragment) {
        $fragmentWordCounts[] = (int)preg_match_all('/[\p{L}\p{N}]{2,}/u', $fragment, $fragMatches);
    }

    $shortFragments = array_filter($fragmentWordCounts, static fn ($c) => $c <= 2);
    if ($wordCount > 0 && $wordCount < 6) {
        $issues[] = 'too_short';
        $score   -= 25;
    } elseif ($wordCount > 20) {
        $score   += 5;
    }

    if (count($fragments) >= 3 && count($shortFragments) >= 2) {
        $issues[] = 'fragmented';
        $score   -= 15;
    } elseif (count($fragments) > 1 && count($shortFragments) === 0) {
        $score   += 5;
    }

    $brokenTokens = preg_match_all('/[\p{L}]{3,}[\p{N}]{2,}|[,]{2,}|[\p{L}]{4,}[^\s\p{L}\p{N}]{1,2}[\p{L}]{3,}/u', $promptText, $garbled);
    if ($brokenTokens > 0) {
        $issues[] = 'gibberish_like';
        $score   -= 20;
    }

    $hasSubject = false;
    foreach ($fragments as $fragment) {
        if (mb_strlen($fragment) >= 6) {
            $hasSubject = true;
            break;
        }
    }
    if (!$hasSubject) {
        $issues[] = 'missing_subject';
        $score   -= 15;
    }

    if ($width === null || $height === null || $width <= 0 || $height <= 0) {
        $issues[] = 'missing_resolution';
        $score   -= 5;
    }

    $tagConfidence = 0.0;
    foreach ($tags as $tag) {
        $tagConfidence += (float)($tag['confidence'] ?? 0.0);
    }
    if ($tagConfidence >= 3.0 && $wordCount > 0 && $tagConfidence > ($wordCount / 2)) {
        $issues[] = 'tags_stronger_than_prompt';
        $score   -= 10;
    }

    $issues = array_values(array_unique($issues));

    $score = max(0, min(100, $score));
    if ($score >= 75) {
        $quality = 'A';
    } elseif ($score >= 55) {
        $quality = 'B';
    } else {
        $quality = 'C';
    }

    if ($promptText !== '' && $tagPrompt !== null) {
        $sanitized = rtrim($promptText, ', ');
        $hybridSuggestion = $sanitized . ', ' . $tagPrompt;
    }

    return [
        'quality_class'      => $quality,
        'score'              => $score,
        'issues'             => $issues,
        'tag_based_suggestion' => $tagPrompt,
        'hybrid_suggestion'    => $hybridSuggestion,
    ];
}

function sv_prompt_quality_from_text(?string $promptText, ?int $width = null, ?int $height = null, array $tags = []): array
{
    $row = [
        'prompt' => $promptText,
        'width'  => $width,
        'height' => $height,
    ];

    return sv_analyze_prompt_quality($row, $tags);
}

function sv_tag_has_no_humans_flag(array $tags): bool
{
    foreach ($tags as $tag) {
        $name = strtolower(trim((string)($tag['name'] ?? '')));
        if ($name === 'no_humans' || $name === 'no humans') {
            return true;
        }
    }

    return false;
}

function sv_strip_human_tokens(string $prompt): string
{
    if ($prompt === '') {
        return $prompt;
    }

    $humanTokens = [
        'human', 'people', 'person', 'man', 'woman', 'male', 'female', 'boy', 'girl',
        'guy', 'lady', 'gentleman', 'person', 'portrait', 'face', 'body', 'adult', 'kid',
    ];

    $pattern = '~\b(' . implode('|', array_map('preg_quote', $humanTokens)) . ')s?\b~i';
    $cleaned = preg_replace($pattern, '', $prompt) ?? $prompt;
    $cleaned = preg_replace('~[,]{2,}~', ',', $cleaned) ?? $cleaned;

    return trim(preg_replace('~\s{2,}~', ' ', $cleaned) ?? $cleaned, " ,");
}

function sv_limit_tag_bucket(array $items, int $limit, string $fallbackLabel): array
{
    $unique = array_values(array_unique(array_filter(array_map('trim', $items), fn($v) => $v !== '')));
    if (count($unique) <= $limit) {
        return [$unique, false];
    }

    $kept      = array_slice($unique, 0, $limit);
    $kept[]    = $fallbackLabel;

    return [$kept, true];
}

function sv_build_grouped_tag_prompt(array $tags, bool $noHumans): ?array
{
    $subject = [];
    $background = [];
    $style = [];
    $pattern = [];

    foreach ($tags as $tag) {
        $name = isset($tag['name']) ? trim((string)$tag['name']) : '';
        if ($name === '') {
            continue;
        }

        $type = strtolower((string)($tag['type'] ?? 'content'));
        if ($type === 'style') {
            $style[] = $name;
            continue;
        }

        if ($type === 'pattern') {
            $pattern[] = $name;
            continue;
        }

        if ($type === 'background') {
            $background[] = $name;
            continue;
        }

        if (in_array($type, ['content', 'character'], true)) {
            $subject[] = $name;
            continue;
        }

        $background[] = $name;
    }

    if ($noHumans) {
        $subject = array_values(array_filter($subject, static function (string $name): bool {
            return trim(sv_strip_human_tokens($name)) !== '';
        }));
    }

    [$pattern, $patternLimited]     = sv_limit_tag_bucket($pattern, 3, 'pattern mix');
    [$background, $backgroundLimited] = sv_limit_tag_bucket($background, 3, 'background detail');

    $parts = [];
    if ($subject !== []) {
        $parts[] = implode(', ', $subject);
    }
    if ($pattern !== []) {
        $parts[] = implode(', ', $pattern);
    }
    if ($background !== []) {
        $parts[] = implode(', ', $background);
    }
    if ($style !== []) {
        $parts[] = implode(', ', $style);
    }

    if ($parts === []) {
        return null;
    }

    $prompt = implode(', ', $parts);
    if ($prompt === '') {
        return null;
    }

    return [
        'prompt'          => $prompt,
        'tags_used_count' => count($subject) + count($background) + count($style) + count($pattern),
        'limited'         => $patternLimited || $backgroundLimited,
        'no_humans'       => $noHumans,
    ];
}

function sv_is_flux_media(array $mediaRow): bool
{
    $modelName = strtolower((string)($mediaRow['model'] ?? ''));
    if ($modelName !== '' && str_contains($modelName, 'flux')) {
        return true;
    }

    $metaRaw = (string)($mediaRow['source_metadata'] ?? '');
    return stripos($metaRaw, 'flux') !== false;
}

function sv_normalize_manual_field(?string $value, int $maxLen): string
{
    if (!is_string($value)) {
        return '';
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }

    return mb_substr($trimmed, 0, $maxLen);
}

function sv_normalize_forge_overrides(array $overrides): array
{
    $manualPromptRaw   = $overrides['_sv_manual_prompt'] ?? $overrides['manual_prompt'] ?? null;
    $manualNegativeSet = array_key_exists('_sv_manual_negative', $overrides)
        || array_key_exists('manual_negative_prompt', $overrides);
    $manualNegativeRaw = $overrides['_sv_manual_negative'] ?? $overrides['manual_negative_prompt'] ?? null;

    $normalizeString = static function ($value, int $maxLen = 2000): ?string {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        return mb_substr($trimmed, 0, $maxLen);
    };

    $normalized = [
        'mode'                 => is_string($overrides['_sv_mode'] ?? null) ? (string)$overrides['_sv_mode'] : null,
        'force_txt2img'        => (isset($overrides['_sv_force_txt2img']) && (string)$overrides['_sv_force_txt2img'] === '1'),
        'manual_prompt'        => sv_normalize_manual_field($manualPromptRaw, 2000),
        'manual_prompt_set'    => $manualPromptRaw !== null && trim((string)$manualPromptRaw) !== '',
        'manual_negative_set'  => $manualNegativeSet,
        'manual_negative'      => $manualNegativeSet ? $normalizeString($manualNegativeRaw, 2000) ?? '' : null,
        'negative_allow_empty' => !empty($overrides['_sv_negative_allow_empty']),
        'seed'                 => isset($overrides['_sv_seed']) && trim((string)$overrides['_sv_seed']) !== ''
            ? (string)trim((string)$overrides['_sv_seed'])
            : null,
        'steps' => isset($overrides['_sv_steps']) && is_numeric($overrides['_sv_steps'])
            ? (int)$overrides['_sv_steps']
            : null,
        'denoising_strength' => isset($overrides['_sv_denoise']) && is_numeric($overrides['_sv_denoise'])
            ? (float)$overrides['_sv_denoise']
            : null,
        'sampler' => $normalizeString($overrides['_sv_sampler'] ?? null, 200),
        'scheduler' => $normalizeString($overrides['_sv_scheduler'] ?? null, 200),
        'model' => $normalizeString($overrides['_sv_model'] ?? null, 200),
        'use_hybrid' => !empty($overrides['_sv_use_hybrid'] ?? $overrides['use_hybrid'] ?? null),
        'prompt_strategy' => $normalizeString($overrides['_sv_recreate_strategy'] ?? $overrides['prompt_strategy'] ?? null, 40),
    ];

    $allowedStrategies = ['prompt_only', 'img2img', 'tags', 'hybrid'];
    if ($normalized['prompt_strategy'] !== null && !in_array($normalized['prompt_strategy'], $allowedStrategies, true)) {
        $normalized['prompt_strategy'] = null;
    }

    return $normalized;
}

function sv_detect_forge_core_gaps(array $forgePayload): array
{
    $missing = [];

    $prompt = trim((string)($forgePayload['prompt'] ?? ''));
    if ($prompt === '') {
        $missing[] = 'prompt';
    }

    $model = trim((string)($forgePayload['model'] ?? ''));
    if ($model === '') {
        $missing[] = 'model';
    }

    $sampler = trim((string)($forgePayload['sampler'] ?? ''));
    if ($sampler === '') {
        $missing[] = 'sampler';
    }

    $schedulerProvided = array_key_exists('scheduler', $forgePayload);
    $scheduler         = trim((string)($forgePayload['scheduler'] ?? ''));
    if ($schedulerProvided && $scheduler === '') {
        $missing[] = 'scheduler';
    }

    if (!isset($forgePayload['steps']) || (int)$forgePayload['steps'] <= 0) {
        $missing[] = 'steps';
    }

    $seed = trim((string)($forgePayload['seed'] ?? ''));
    if ($seed === '') {
        $missing[] = 'seed';
    }

    $width  = isset($forgePayload['width']) ? (int)$forgePayload['width'] : 0;
    $height = isset($forgePayload['height']) ? (int)$forgePayload['height'] : 0;
    if ($width <= 0 || $height <= 0) {
        $missing[] = 'size';
    }

    return [
        'incomplete' => $missing !== [],
        'missing'    => $missing,
    ];
}

function sv_is_i2i_unsuitable(string $path, array $forgePayload = []): array
{
    if (!is_file($path)) {
        return ['unsuitable' => true, 'reason' => 'source image missing'];
    }

    $info = @getimagesize($path);
    if ($info === false) {
        return ['unsuitable' => true, 'reason' => 'getimagesize failed'];
    }

    $filesize = @filesize($path);
    if ($filesize !== false && $filesize < 8192) {
        return ['unsuitable' => true, 'reason' => 'source image too small/blank'];
    }

    $width  = (int)($info[0] ?? 0);
    $height = (int)($info[1] ?? 0);
    if ($width <= 32 || $height <= 32) {
        return ['unsuitable' => true, 'reason' => 'source dimensions extremely small'];
    }

    $aspect = ($width > 0 && $height > 0) ? max($width / $height, $height / $width) : 0.0;
    $attempts = isset($forgePayload['attempt_index']) ? (int)$forgePayload['attempt_index'] : null;
    if ($aspect > 10 && $attempts !== null && $attempts > 1) {
        return ['unsuitable' => true, 'reason' => 'extreme aspect after repeated i2i failures'];
    }

    return ['unsuitable' => false, 'reason' => null];
}

function sv_decide_forge_mode(array $mediaRow, array $regenPlan, array $overrides, array $forgePayload): array
{
    $mode   = 'img2img';
    $reason = 'original image available';
    $params = [];

    $path = (string)($mediaRow['path'] ?? '');
    $type = (string)($mediaRow['type'] ?? '');

    $strategy = $overrides['prompt_strategy'] ?? null;
    if (in_array($strategy, ['prompt_only', 'tags'], true)) {
        return [
            'mode'   => 'txt2img',
            'reason' => 'strategy ' . $strategy,
            'params' => $params,
        ];
    }

    if ($strategy === 'img2img') {
        $mode   = 'img2img';
        $reason = 'strategy img2img';
    }

    if ($type !== 'image' || $path === '' || !is_file($path)) {
        return [
            'mode'   => 'txt2img',
            'reason' => 'no source image for img2img',
            'params' => $params,
        ];
    }

    if (!empty($overrides['force_txt2img'])) {
        return [
            'mode'   => 'txt2img',
            'reason' => 'override _sv_force_txt2img',
            'params' => $params,
        ];
    }

    $promptCategory = strtoupper((string)($regenPlan['category'] ?? ''));
    $promptMissing  = !empty($regenPlan['prompt_missing']);
    $fallbackUsed   = !empty($regenPlan['fallback_used']);
    $coreGaps       = sv_detect_forge_core_gaps($forgePayload);

    if ($promptMissing) {
        return [
            'mode'   => 'img2img',
            'reason' => 'prompt missing - rely on source image',
            'params' => $params,
        ];
    }

    if ($promptCategory === 'C') {
        return [
            'mode'   => 'img2img',
            'reason' => 'prompt quality C - keep composition via img2img',
            'params' => $params,
        ];
    }

    if (!empty($coreGaps['missing'])) {
        return [
            'mode'   => 'img2img',
            'reason' => 'core fields incomplete: ' . implode(', ', $coreGaps['missing']),
            'params' => $params,
        ];
    }

    if (!$fallbackUsed && in_array($promptCategory, ['A', 'B'], true)) {
        return [
            'mode'   => 'txt2img',
            'reason' => 'prompt quality ' . $promptCategory . ' without fallback',
            'params' => $params,
        ];
    }

    $unsuitable = sv_is_i2i_unsuitable($path, $forgePayload);
    if (!empty($unsuitable['unsuitable'])) {
        return [
            'mode'   => 'txt2img',
            'reason' => (string)($unsuitable['reason'] ?? 'input unsuitable for img2img'),
            'params' => $params,
        ];
    }

    if (!isset($overrides['denoising_strength']) || $overrides['denoising_strength'] === null) {
        $params['denoising_strength'] = 0.25;
    }

    return [
        'mode'   => $mode,
        'reason' => $reason,
        'params' => $params,
    ];
}

function sv_prepare_forge_regen_prompt(array $mediaRow, array $tags, callable $logger, array $overrides = []): array
{
    $manualPrompt     = sv_normalize_manual_field($overrides['manual_prompt'] ?? null, 2000);
    $manualPromptSet  = !empty($overrides['manual_prompt_set']);
    $useHybrid        = !empty($overrides['use_hybrid']);
    $strategy         = $overrides['prompt_strategy'] ?? null;
    $noHumans         = sv_tag_has_no_humans_flag($tags);
    $tagPromptData    = sv_build_grouped_tag_prompt($tags, $noHumans);
    $tagPrompt        = is_array($tagPromptData) ? ($tagPromptData['prompt'] ?? null) : null;

    $originalPrompt  = trim((string)($mediaRow['prompt'] ?? ''));
    $effectivePrompt = ($manualPromptSet && $manualPrompt !== '') ? $manualPrompt : $originalPrompt;
    $tagPromptUsed   = false;
    $fallbackUsed    = false;
    $promptSource    = ($manualPromptSet && $manualPrompt !== '') ? 'manual' : 'stored_prompt';
    $assessment      = null;
    $allowFallback   = $strategy !== 'prompt_only';

    if ($strategy === 'tags') {
        if ($tagPrompt === null) {
            throw new RuntimeException('Tag-Prompt nicht verfügbar.');
        }
        $effectivePrompt = $tagPrompt;
        $tagPromptUsed   = true;
        $fallbackUsed    = true;
        $promptSource    = 'tags_prompt';
    } elseif ($strategy === 'hybrid' && $tagPrompt !== null && $originalPrompt !== '') {
        $effectivePrompt = $originalPrompt . ', ' . $tagPrompt;
        $tagPromptUsed   = true;
        $promptSource    = 'hybrid';
    }

    if ($manualPrompt === '' && $effectivePrompt === '' && $tagPrompt !== null) {
        $effectivePrompt = $tagPrompt;
        $tagPromptUsed   = true;
        $fallbackUsed    = true;
        $promptSource    = 'tags_prompt';
    }

    if ($effectivePrompt === '' && $tagPrompt === null) {
        throw new RuntimeException('Kein Prompt vorhanden und keine Tags verfügbar.');
    }

    $assessment = sv_analyze_prompt_quality(
        [
            'prompt' => $effectivePrompt,
            'width'  => $mediaRow['width'] ?? null,
            'height' => $mediaRow['height'] ?? null,
        ],
        $tags
    );
    $quality     = $assessment['quality_class'];
    $tagFragment = $assessment['tag_based_suggestion'] ?? '';

    if ($manualPrompt === '' && $strategy !== 'tags') {
        if ($quality === 'C' && $allowFallback) {
            $effectivePrompt = $tagPrompt !== null ? 'Detailed reconstruction, ' . $tagPrompt : $effectivePrompt;
            $promptSource    = 'tags_prompt';
            $fallbackUsed    = true;
            $tagPromptUsed   = $tagPrompt !== null;
        } elseif (($useHybrid || $strategy === 'hybrid') && $tagPrompt !== null && $originalPrompt !== '') {
            $effectivePrompt = $originalPrompt . ', ' . $tagPrompt;
            $promptSource    = 'hybrid';
            $tagPromptUsed   = true;
        } elseif ($effectivePrompt === '' && $tagPrompt !== null) {
            $effectivePrompt = $tagPrompt;
            $promptSource    = 'tags_prompt';
            $tagPromptUsed   = true;
        } elseif ($promptSource === 'stored_prompt' && $quality !== 'A' && $quality !== 'B' && $tagPrompt !== null && $allowFallback) {
            $effectivePrompt = $tagPrompt;
            $promptSource    = 'tags_prompt';
            $tagPromptUsed   = true;
            $fallbackUsed    = true;
        }
    }

    if ($noHumans) {
        $cleaned = sv_strip_human_tokens($effectivePrompt);
        if ($cleaned !== '') {
            $effectivePrompt = $cleaned;
        }
    }

    if ($fallbackUsed && $tagFragment !== '') {
        $logger('Prompt-Fallback aktiv (' . $quality . '): Tags genutzt -> ' . $tagFragment);
    }

    return [
        'category'        => $quality,
        'final_prompt'    => $effectivePrompt,
        'original_prompt' => $originalPrompt,
        'fallback_used'   => $fallbackUsed,
        'tag_prompt_used' => $tagPromptUsed,
        'assessment'      => $assessment,
        'tag_fragment'    => $tagFragment,
        'no_humans'       => $noHumans,
        'prompt_missing'  => $manualPrompt === '' ? ($originalPrompt === '') : false,
        'prompt_source'   => $promptSource,
        'tags_used_count' => is_array($tagPromptData) ? ($tagPromptData['tags_used_count'] ?? 0) : 0,
        'tags_limited'    => is_array($tagPromptData) ? (bool)($tagPromptData['limited'] ?? false) : false,
        'strategy'        => $strategy,
    ];
}

function sv_ensure_media_seed(PDO $pdo, int $mediaId, ?string $existingSeed, callable $logger): array
{
    if (is_string($existingSeed) && trim($existingSeed) !== '') {
        return ['seed' => trim($existingSeed), 'created' => false];
    }

    $stmt = $pdo->prepare(
        'SELECT meta_value FROM media_meta WHERE media_id = :media_id AND meta_key = :meta_key ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([
        ':media_id' => $mediaId,
        ':meta_key' => 'seed',
    ]);
    $storedSeed = $stmt->fetchColumn();
    if (is_string($storedSeed) && trim($storedSeed) !== '') {
        return ['seed' => trim((string)$storedSeed), 'created' => false];
    }

    $seed = (string)random_int(1_000_000, 9_999_999_999);
    $now  = date('c');
    $insert = $pdo->prepare(
        'INSERT INTO media_meta (media_id, source, meta_key, meta_value, created_at) VALUES (?, ?, ?, ?, ?)'
    );
    $insert->execute([
        $mediaId,
        SV_FORGE_SCAN_SOURCE_LABEL,
        'seed',
        $seed,
        $now,
    ]);
    $logger('Seed generiert und gespeichert: ' . $seed);

    return ['seed' => $seed, 'created' => true];
}

function sv_get_media_meta_value(PDO $pdo, int $mediaId, string $key): ?string
{
    $stmt = $pdo->prepare(
        'SELECT meta_value FROM media_meta WHERE media_id = :media_id AND meta_key = :meta_key ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([
        ':media_id' => $mediaId,
        ':meta_key' => $key,
    ]);
    $value = $stmt->fetchColumn();
    if ($value === false || $value === null) {
        return null;
    }

    return (string)$value;
}

function sv_set_media_meta_value(PDO $pdo, int $mediaId, string $key, $value, string $source = 'web'): bool
{
    $valueStr = is_bool($value) ? ($value ? '1' : '0') : (string)$value;
    $valueStr = trim($valueStr);
    $now      = date('c');

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT meta_value FROM media_meta WHERE media_id = :media_id AND meta_key = :meta_key ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([
            ':media_id' => $mediaId,
            ':meta_key' => $key,
        ]);
        $current = $stmt->fetchColumn();
        if ($current !== false && $current !== null && (string)$current === $valueStr) {
            $pdo->commit();
            return false;
        }

        $insert = $pdo->prepare(
            'INSERT INTO media_meta (media_id, source, meta_key, meta_value, created_at) VALUES (?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $mediaId,
            $source,
            $key,
            $valueStr,
            $now,
        ]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function sv_inc_media_meta_int(PDO $pdo, int $mediaId, string $key, int $delta = 1, string $source = 'web'): int
{
    $pdo->beginTransaction();
    try {
        $current = 0;
        $stmt = $pdo->prepare(
            'SELECT meta_value FROM media_meta WHERE media_id = :media_id AND meta_key = :meta_key ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([
            ':media_id' => $mediaId,
            ':meta_key' => $key,
        ]);
        $currentVal = $stmt->fetchColumn();
        if ($currentVal !== false && $currentVal !== null && is_numeric($currentVal)) {
            $current = (int)$currentVal;
        }

        $next = $current + $delta;
        $insert = $pdo->prepare(
            'INSERT INTO media_meta (media_id, source, meta_key, meta_value, created_at) VALUES (?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $mediaId,
            $source,
            $key,
            (string)$next,
            date('c'),
        ]);
        $pdo->commit();
        return $next;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function sv_resolve_negative_prompt(array $mediaRow, array $overrides, array $regenPlan, array $tags): array
{
    $manualNegativeSet = !empty($overrides['manual_negative_set']);
    $manualNegative    = $manualNegativeSet ? sv_normalize_manual_field((string)$overrides['manual_negative'], 2000) : null;
    $allowEmpty        = !empty($overrides['negative_allow_empty']);
    $fallback          = 'low quality, blurry, watermark, jpeg artifacts, extra limbs';
    $isFlux            = sv_is_flux_media($mediaRow);

    if ($manualNegativeSet) {
        if ($manualNegative === '') {
            return [
                'negative_prompt' => '',
                'negative_mode'   => 'manual_empty',
                'negative_len'    => 0,
            ];
        }

        return [
            'negative_prompt' => $manualNegative,
            'negative_mode'   => 'manual',
            'negative_len'    => mb_strlen($manualNegative),
        ];
    }

    $storedNegative = trim((string)($mediaRow['negative_prompt'] ?? ''));
    if ($storedNegative !== '') {
        return [
            'negative_prompt' => $storedNegative,
            'negative_mode'   => 'manual',
            'negative_len'    => mb_strlen($storedNegative),
        ];
    }

    if ($isFlux) {
        return [
            'negative_prompt' => '',
            'negative_mode'   => 'empty_flux',
            'negative_len'    => 0,
        ];
    }

    if ($allowEmpty) {
        return [
            'negative_prompt' => '',
            'negative_mode'   => 'empty_allowed',
            'negative_len'    => 0,
        ];
    }

    return [
        'negative_prompt' => $fallback,
        'negative_mode'   => 'fallback',
        'negative_len'    => mb_strlen($fallback),
    ];
}

function sv_encode_init_image(string $path): string
{
    $binary = @file_get_contents($path);
    if ($binary === false || $binary === '') {
        throw new RuntimeException('Init-Bild konnte nicht gelesen werden.');
    }

    return base64_encode($binary);
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

function sv_resolve_backup_dir(array $config): string
{
    $baseDir      = sv_base_dir();
    $configured   = $config['paths']['backups'] ?? null;
    $fallbackPath = $baseDir . '/BACKUPS';

    if (!is_string($configured) || trim($configured) === '') {
        return $fallbackPath;
    }

    $normalized = rtrim(str_replace('\\', '/', (string)$configured), '/');

    return $normalized === '' ? $fallbackPath : $normalized;
}

function sv_build_backup_path(string $backupDir, string $sourcePath): string
{
    $timestamp = date('Ymd_His');
    $fileName  = basename($sourcePath);

    return rtrim($backupDir, '/') . '/forge_regen_' . $timestamp . '_' . $fileName;
}

function sv_backup_media_file(array $config, string $path, callable $logger, ?string $backupDir = null, ?string $backupPath = null): string
{
    $backupDir = $backupDir ?? sv_resolve_backup_dir($config);
    $backupDir = rtrim(str_replace('\\', '/', $backupDir), '/');
    sv_assert_backup_outside_media_roots($config, $backupDir);
    sv_assert_stream_path_allowed($backupDir, $config, 'forge_backup_dir', false, true);
    if (!is_dir($backupDir)) {
        if (!mkdir($backupDir, 0777, true) && !is_dir($backupDir)) {
            throw new RuntimeException('Backup-Verzeichnis kann nicht angelegt werden.');
        }
    }

    $backupPath = $backupPath ?? sv_build_backup_path($backupDir, $path);
    sv_assert_stream_path_allowed($backupPath, $config, 'forge_backup_path', false, true);

    if (!copy($path, $backupPath)) {
        throw new RuntimeException('Backup fehlgeschlagen.');
    }

    $logger('Backup erstellt: ' . sv_safe_path_label($backupPath));

    return $backupPath;
}

function sv_build_temp_output_path(string $tmpDir, string $targetPath): string
{
    $tmpDir   = rtrim($tmpDir, '/');
    $tmpFile  = $tmpDir . '/forge_regen_' . md5($targetPath) . '_' . basename($targetPath);
    $suffix   = 0;

    while (file_exists($tmpFile)) {
        $suffix++;
        $tmpFile = $tmpDir . '/forge_regen_' . md5($targetPath) . '_' . $suffix . '_' . basename($targetPath);
    }

    return $tmpFile;
}

function sv_write_forge_image(array $config, string $targetPath, string $binary, callable $logger, array $expectedMeta = [], ?string $tempOutputPath = null): array
{
    sv_assert_stream_path_allowed($targetPath, $config, 'forge_output_path', false, false, true);
    $pathsCfg = $config['paths'] ?? [];
    $tmpDirRaw = $pathsCfg['tmp'] ?? null;
    $tmpDir = (is_string($tmpDirRaw) && trim($tmpDirRaw) !== '')
        ? rtrim(str_replace('\\', '/', (string)$tmpDirRaw), '/')
        : (sv_base_dir() . '/TMP');
    sv_assert_stream_path_allowed($tmpDir, $config, 'forge_tmp_dir', false, false, true);
    if (!is_dir($tmpDir)) {
        mkdir($tmpDir, 0777, true);
    }

    $targetDir = rtrim(str_replace('\\', '/', dirname($targetPath)), '/');
    if ($targetDir === '') {
        throw new RuntimeException('Ungültiger Zielpfad.');
    }
    if (!is_dir($targetDir)) {
        if (!mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Zielverzeichnis kann nicht angelegt werden.');
        }
    }

    $tmpFile = $tempOutputPath !== null ? $tempOutputPath : sv_build_temp_output_path($tmpDir, $targetPath);
    sv_assert_stream_path_allowed($tmpFile, $config, 'forge_tmp_file', false, false, true);

    $image = @imagecreatefromstring($binary);
    if ($image === false) {
        throw new RuntimeException('Forge lieferte keine decodierbare Bildantwort.');
    }

    $targetExt   = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));
    $targetWidth = isset($expectedMeta['width']) ? (int)$expectedMeta['width'] : null;
    $targetHeight = isset($expectedMeta['height']) ? (int)$expectedMeta['height'] : null;

    $currentWidth  = imagesx($image);
    $currentHeight = imagesy($image);
    if ($targetWidth !== null && $targetHeight !== null
        && $targetWidth > 0 && $targetHeight > 0
        && ($currentWidth !== $targetWidth || $currentHeight !== $targetHeight)
    ) {
        $resized = @imagescale($image, $targetWidth, $targetHeight);
        if ($resized !== false) {
            imagedestroy($image);
            $image         = $resized;
            $currentWidth  = $targetWidth;
            $currentHeight = $targetHeight;
        }
    }

    $formatMap = [
        'jpg'  => 'jpeg',
        'jpeg' => 'jpeg',
        'png'  => 'png',
        'webp' => 'webp',
    ];
    $targetFormat = $formatMap[$targetExt] ?? 'jpeg';
    $formatPreserved = isset($formatMap[$targetExt]);
    $outExt = $formatMap[$targetExt] ?? 'jpeg';

    $writeOk = false;
    if ($targetFormat === 'jpeg') {
        $writeOk = @imagejpeg($image, $tmpFile, 95);
    } elseif ($targetFormat === 'png') {
        $writeOk = @imagepng($image, $tmpFile, 6);
    } elseif ($targetFormat === 'webp' && function_exists('imagewebp')) {
        $writeOk = @imagewebp($image, $tmpFile, 88);
    } else {
        $formatPreserved = false;
        $targetFormat    = 'png';
        $outExt          = 'png';
        $writeOk         = @imagepng($image, $tmpFile, 6);
    }

    imagedestroy($image);

    if (!$writeOk) {
        @unlink($tmpFile);
        throw new RuntimeException('Neue Bilddatei konnte nicht geschrieben werden.');
    }

    if (!rename($tmpFile, $targetPath)) {
        @unlink($tmpFile);
        throw new RuntimeException('Ersetzen der Zieldatei fehlgeschlagen.');
    }

    $hash     = @hash_file('md5', $targetPath) ?: null;
    $filesize = @filesize($targetPath) ?: null;

    return [
        'width'             => $currentWidth,
        'height'            => $currentHeight,
        'hash'              => $hash,
        'filesize'          => $filesize === false ? null : (int)$filesize,
        'format_preserved'  => $formatPreserved,
        'orig_ext'          => $targetExt,
        'out_ext'           => $outExt,
    ];
}

function sv_replace_media_file_with_image(array $config, string $targetPath, string $binary, callable $logger, array $expectedMeta = [], ?string $tempOutputPath = null): array
{
    $pathsCfg = $config['paths'] ?? [];
    sv_assert_media_path_allowed($targetPath, $pathsCfg, 'forge_regen_replace');

    return sv_write_forge_image($config, $targetPath, $binary, $logger, $expectedMeta, $tempOutputPath);
}

function sv_forge_fetch_options(array $endpoint, callable $logger): ?array
{
    $url      = sv_forge_build_url($endpoint['base_url'], SV_FORGE_OPTIONS_PATH);
    $headers  = ['Accept: application/json'];
    $auth     = sv_forge_basic_auth_header($endpoint);
    if ($auth !== null) {
        $headers[] = $auth;
    }

    $context = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => implode("\r\n", $headers),
            'timeout' => $endpoint['timeout'],
        ],
    ]);

    $responseBody = @file_get_contents($url, false, $context);
    if ($responseBody === false) {
        $logger('Forge-Options konnten nicht gelesen werden.');
        return null;
    }

    $decoded = json_decode($responseBody, true);
    return is_array($decoded) ? $decoded : null;
}

function sv_forge_set_options(array $endpoint, array $options, callable $logger): bool
{
    $url      = sv_forge_build_url($endpoint['base_url'], SV_FORGE_OPTIONS_PATH);
    $headers  = ['Content-Type: application/json'];
    $auth     = sv_forge_basic_auth_header($endpoint);
    if ($auth !== null) {
        $headers[] = $auth;
    }

    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => implode("\r\n", $headers),
            'content' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'timeout' => $endpoint['timeout'],
        ],
    ]);

    $responseBody = @file_get_contents($url, false, $context);
    if ($responseBody === false) {
        $logger('Forge-Options konnten nicht gesetzt werden.');
        return false;
    }

    return true;
}

function sv_build_sampler_attempt_chain(array $payload): array
{
    $chain = [];

    $baseSampler   = isset($payload['sampler']) ? trim((string)$payload['sampler']) : '';
    $baseScheduler = isset($payload['scheduler']) ? trim((string)$payload['scheduler']) : '';
    if ($baseSampler !== '' || $baseScheduler !== '') {
        $chain[] = ['sampler' => $baseSampler ?: null, 'scheduler' => $baseScheduler ?: null];
    }

    $fallbacks = [
        ['sampler' => 'DPM++ 2M', 'scheduler' => 'Karras'],
        ['sampler' => 'Euler a', 'scheduler' => 'Normal'],
        ['sampler' => 'DPM++ SDE', 'scheduler' => 'Karras'],
    ];

    foreach ($fallbacks as $fallback) {
        $exists = false;
        foreach ($chain as $entry) {
            if ($entry['sampler'] === $fallback['sampler'] && $entry['scheduler'] === $fallback['scheduler']) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $chain[] = $fallback;
        }
    }

    return array_slice($chain, 0, 4);
}

function sv_execute_forge_payload(array $endpoint, array $payload, callable $logger): array
{
    $url     = sv_forge_build_url($endpoint['base_url'], sv_forge_target_path($payload));
    $timeout = $endpoint['timeout'];
    $headers = [
        'Content-Type: application/json',
    ];

    $authHeader = sv_forge_basic_auth_header($endpoint);
    if ($authHeader !== null) {
        $headers[] = $authHeader;
    }

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

    $logPayload = sv_forge_log_payload($url, $httpCode, $responseBody);

    if ($responseBody === false || $httpCode === null || $httpCode < 200 || $httpCode >= 300) {
        throw new SvForgeHttpException(
            'Forge-Request fehlgeschlagen' . ($httpCode !== null ? ' (HTTP ' . $httpCode . ')' : ''),
            $logPayload
        );
    }

    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        throw new SvForgeHttpException('Forge-Antwort ungültig oder leer.', $logPayload);
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
        'response_json'  => json_encode($logPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'http_code'      => $httpCode,
        'log_payload'    => $logPayload,
    ];
}

function sv_call_forge_sync(array $config, array $payload, callable $logger): array
{
    if (!sv_is_forge_dispatch_enabled($config)) {
        throw new RuntimeException('Forge-Dispatch ist deaktiviert.');
    }

    $endpoint = sv_forge_endpoint_config($config, true);
    if ($endpoint === null) {
        throw new RuntimeException('Forge-Konfiguration fehlt oder ist unvollständig.');
    }

    $health = sv_forge_healthcheck($endpoint, $logger);
    if (!$health['ok']) {
        throw new SvForgeHttpException(
            'Forge-Healthcheck fehlgeschlagen' . ($health['http_code'] !== null ? ' (HTTP ' . $health['http_code'] . ')' : ''),
            $health['log_payload'] ?? []
        );
    }

    $options        = sv_forge_fetch_options($endpoint, $logger);
    $activeModel    = is_array($options) ? (string)($options['sd_model_checkpoint'] ?? '') : '';
    $requestedModel = (string)($payload['model'] ?? '');
    $fallbackModel  = sv_forge_fallback_model($config);
    $targetModel    = $requestedModel !== '' ? $requestedModel : $fallbackModel;

    if ($activeModel === '' || strtolower($activeModel) !== strtolower($targetModel)) {
        sv_forge_set_options($endpoint, ['sd_model_checkpoint' => $targetModel], $logger);
        $options     = sv_forge_fetch_options($endpoint, $logger);
        $activeModel = is_array($options) ? (string)($options['sd_model_checkpoint'] ?? '') : '';
    }

    if (strtolower($activeModel) !== strtolower($targetModel)) {
        sv_forge_set_options($endpoint, ['sd_model_checkpoint' => $fallbackModel], $logger);
        $options     = sv_forge_fetch_options($endpoint, $logger);
        $activeModel = is_array($options) ? (string)($options['sd_model_checkpoint'] ?? '') : '';
        $targetModel = $fallbackModel;
    }

    if ($activeModel === '' || strtolower($activeModel) !== strtolower($targetModel)) {
        throw new SvForgeHttpException('model resolve failed', [
            'requested_model' => $requestedModel,
            'fallback_model'  => $fallbackModel,
            'active_model'    => $activeModel,
        ]);
    }

    $payload['model'] = $activeModel;

    $attemptChain = sv_build_sampler_attempt_chain($payload);
    $attemptErrors = [];
    $usedAttempt   = null;
    $result        = null;

    foreach ($attemptChain as $index => $attempt) {
        $attemptPayload = $payload;
        if ($attempt['sampler'] !== null) {
            $attemptPayload['sampler'] = $attempt['sampler'];
        }
        if ($attempt['scheduler'] !== null) {
            $attemptPayload['scheduler'] = $attempt['scheduler'];
        } else {
            unset($attemptPayload['scheduler']);
        }

        try {
            $tryResult = sv_execute_forge_payload($endpoint, $attemptPayload, $logger);
            $imageInfo = @getimagesizefromstring($tryResult['binary']);
            $sizeOk    = $imageInfo !== false && (int)$imageInfo[0] > 16 && (int)$imageInfo[1] > 16 && strlen($tryResult['binary']) > 1024;
            if (!$sizeOk) {
                throw new SvForgeHttpException('Forge lieferte eine fehlerhafte Bildantwort.', $tryResult['log_payload']);
            }

            $usedAttempt = $index + 1;
            $result      = array_merge($tryResult, [
                'used_sampler'    => $attempt['sampler'],
                'used_scheduler'  => $attempt['scheduler'],
                'attempt_index'   => $usedAttempt,
                'attempt_chain'   => $attemptChain,
            ]);
            break;
        } catch (Throwable $e) {
            $attemptErrors[] = [
                'attempt' => $index + 1,
                'error'   => $e->getMessage(),
                'log'     => $e instanceof SvForgeHttpException ? $e->getLogData() : null,
            ];

            if ($index >= 3) {
                break;
            }
        }
    }

    if ($result === null) {
        throw new SvForgeHttpException('Forge-Request fehlgeschlagen', [
            'attempt_chain'  => $attemptChain,
            'attempt_errors' => $attemptErrors,
        ]);
    }

    return $result;
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

    $pathsCfg = $config['paths'] ?? [];
    $logContext = [
        'operation'     => 'regen',
        'media_id'      => $mediaId,
        'file_basename' => basename($path),
    ];
    $scanData  = sv_scan_with_local_scanner($path, $scannerCfg, $logger, $logContext, $pathsCfg);
    $hasNsfw   = (int)($config['default_nsfw'] ?? 0);
    $rating    = 0;
    $scanTags  = [];
    $scanFlags = [];
    $nsfwScore = null;

    if (!empty($scanData['ok'])) {
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

        $delTags = $pdo->prepare('DELETE FROM media_tags WHERE media_id = ? AND locked = 0');
        $delTags->execute([$mediaId]);
        $writtenTags = sv_store_tags($pdo, $mediaId, $scanTags);
        $runAt = date('c');
        sv_store_scan_result(
            $pdo,
            $mediaId,
            'pixai_sensible',
            $nsfwScore,
            $scanFlags,
            $scanData['raw'] ?? [],
            $runAt,
            [
                'tags_written' => $writtenTags,
                'path_after'   => $path,
                'has_nsfw'     => $hasNsfw,
                'rating'       => $rating,
                'response_shape' => $scanData['debug']['response_shape'] ?? null,
                'merged_parts_count' => $scanData['debug']['merged_parts_count'] ?? null,
                'fallback_used' => $scanData['debug']['fallback_used'] ?? null,
            ]
        );
        sv_scanner_persist_log(
            $pathsCfg,
            $logContext,
            $writtenTags,
            $nsfwScore,
            $runAt,
            false,
            true,
            true
        );

        $stmt = $pdo->prepare('UPDATE media SET has_nsfw = ?, rating = ?, status = "active" WHERE id = ?');
        $stmt->execute([$hasNsfw, $rating, $mediaId]);
        $pdo->prepare("DELETE FROM media_meta WHERE media_id = ? AND meta_key = 'scan_stale'")->execute([$mediaId]);
        $result['scan_updated'] = true;
    } else {
        $logger('Scanner lieferte keine verwertbaren Daten, Tags unverändert.');
        $errorMeta = is_array($scanData['error_meta'] ?? null) ? $scanData['error_meta'] : ['notice' => 'scanner_failed'];
        $runAt = date('c');
        sv_store_scan_result(
            $pdo,
            $mediaId,
            'pixai_sensible',
            null,
            [],
            $errorMeta,
            $runAt,
            [
                'tags_written' => 0,
                'path_after'   => $path,
                'has_nsfw'     => $hasNsfw,
                'rating'       => $rating,
                'response_shape' => $scanData['debug']['response_shape'] ?? null,
                'merged_parts_count' => $scanData['debug']['merged_parts_count'] ?? null,
                'fallback_used' => $scanData['debug']['fallback_used'] ?? null,
            ],
            sv_scanner_format_error_message($errorMeta)
        );
        $pdo->prepare("DELETE FROM media_meta WHERE media_id = ? AND meta_key = 'scan_stale'")->execute([$mediaId]);
        $metaStmt = $pdo->prepare("INSERT INTO media_meta (media_id, source, meta_key, meta_value, created_at) VALUES (?, ?, ?, ?, ?)");
        $metaStmt->execute([
            $mediaId,
            SV_FORGE_SCAN_SOURCE_LABEL,
            'scan_stale',
            '1',
            date('c'),
        ]);
        sv_audit_log($pdo, 'scan_stale', 'media', $mediaId, [
            'reason' => 'forge_regen_no_scanner',
        ]);
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

    $promptId = (int)$pdo->lastInsertId();
    sv_record_prompt_history(
        $pdo,
        $mediaId,
        $promptId,
        [
            'prompt'           => $regenPlan['final_prompt'],
            'negative_prompt'  => (string)($payload['negative_prompt'] ?? ''),
            'model'            => (string)($payload['model'] ?? ''),
            'sampler'          => (string)($payload['sampler'] ?? ''),
            'cfg_scale'        => isset($payload['cfg_scale']) ? (float)$payload['cfg_scale'] : null,
            'steps'            => isset($payload['steps']) ? (int)$payload['steps'] : null,
            'seed'             => isset($payload['seed']) ? (string)$payload['seed'] : null,
            'width'            => isset($payload['width']) ? (int)$payload['width'] : null,
            'height'           => isset($payload['height']) ? (int)$payload['height'] : null,
            'scheduler'        => isset($payload['scheduler']) ? (string)$payload['scheduler'] : null,
            'sampler_settings' => isset($payload['sampler_settings']) ? (is_string($payload['sampler_settings']) ? $payload['sampler_settings'] : json_encode($payload['sampler_settings'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : null,
            'loras'            => isset($payload['loras']) ? (is_string($payload['loras']) ? $payload['loras'] : json_encode($payload['loras'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : null,
            'controlnet'       => isset($payload['controlnet']) ? (is_string($payload['controlnet']) ? $payload['controlnet'] : json_encode($payload['controlnet'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : null,
            'source_metadata'  => null,
        ],
        $regenPlan['original_prompt'] ?? null,
        SV_FORGE_SCAN_SOURCE_LABEL
    );

    $result['prompt_created'] = true;

    return $result;
}

function sv_update_job_status(PDO $pdo, int $jobId, string $status, ?string $responseJson = null, ?string $error = null): void
{
    if ($error !== null) {
        $error = sv_sanitize_error_message((string)$error, 240);
        $error = sv_forge_limit_error($error);
    }

    $now = date('c');
    $existingJson = sv_db_exec_retry(static function () use ($pdo, $jobId) {
        $existingStmt = $pdo->prepare('SELECT forge_response_json FROM jobs WHERE id = :id');
        $existingStmt->execute([':id' => $jobId]);
        return $existingStmt->fetchColumn();
    });
    $existingData = is_string($existingJson) ? json_decode($existingJson, true) : null;
    $existingData = is_array($existingData) ? $existingData : [];

    $incomingData = null;
    if ($responseJson !== null) {
        $decoded = json_decode($responseJson, true);
        if (is_array($decoded)) {
            $incomingData = $decoded;
        }
    }

    $responseData = $existingData;
    if (is_array($incomingData)) {
        $responseData = array_merge($responseData, $incomingData);
    }

    $needsTiming = $status === 'running' || in_array($status, ['done', 'error', SV_JOB_STATUS_CANCELED], true);
    $shouldWriteResponse = $responseData !== [] || $needsTiming;

    if ($shouldWriteResponse) {
        if ($status === 'running' && empty($responseData['started_at'])) {
            $responseData['started_at'] = $now;
        }
        if (in_array($status, ['done', 'error', SV_JOB_STATUS_CANCELED], true) && empty($responseData['finished_at'])) {
            $responseData['finished_at'] = $now;
        }
        $responseJson = json_encode($responseData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    sv_db_exec_retry(static function () use ($pdo, $status, $responseJson, $error, $now, $jobId): void {
        $stmt = $pdo->prepare(
            'UPDATE jobs SET status = :status, forge_response_json = COALESCE(:response, forge_response_json), error_message = :error, updated_at = :updated_at WHERE id = :id'
        );
        $stmt->execute([
            ':status'     => $status,
            ':response'   => $responseJson,
            ':error'      => $error,
            ':updated_at' => $now,
            ':id'         => $jobId,
        ]);
    });
}

function sv_claim_job_running(PDO $pdo, int $jobId, array $allowedStatuses, ?array $response = null, ?string &$reason = null): bool
{
    $allowedStatuses = array_values(array_filter(array_map('strval', $allowedStatuses), static fn ($status) => $status !== ''));
    if ($allowedStatuses === []) {
        $reason = 'claim_status_missing';
        return false;
    }

    $now = date('c');
    $existingJson = sv_db_exec_retry(static function () use ($pdo, $jobId) {
        $stmt = $pdo->prepare('SELECT forge_response_json FROM jobs WHERE id = :id');
        $stmt->execute([':id' => $jobId]);
        return $stmt->fetchColumn();
    });
    $existingData = is_string($existingJson) ? json_decode($existingJson, true) : null;
    $existingData = is_array($existingData) ? $existingData : [];

    $responseData = $existingData;
    if (is_array($response)) {
        $responseData = array_merge($responseData, $response);
    }
    if (empty($responseData['started_at'])) {
        $responseData['started_at'] = $now;
    }

    $responseJson = json_encode($responseData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($responseJson === false) {
        error_log('[sv_claim_job_running] response_encode_failed job_id=' . $jobId);
        $responseJson = null;
    }

    $placeholders = implode(',', array_fill(0, count($allowedStatuses), '?'));
    $updated = sv_db_exec_retry(static function () use ($pdo, $responseJson, $now, $jobId, $allowedStatuses, $placeholders): int {
        $stmt = $pdo->prepare(
            'UPDATE jobs SET status = "running", forge_response_json = COALESCE(?, forge_response_json), error_message = NULL, updated_at = ?'
            . ' WHERE id = ? AND status IN (' . $placeholders . ')'
        );
        $stmt->execute(array_merge([$responseJson, $now, $jobId], $allowedStatuses));
        return $stmt->rowCount();
    });

    if ($updated === 1) {
        return true;
    }

    $reason = 'claim_failed_or_already_running';
    return false;
}

function sv_format_job_age(?int $seconds): ?string
{
    if ($seconds === null) {
        return null;
    }

    $seconds = max(0, $seconds);
    $hours   = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $secs    = $seconds % 60;

    if ($hours > 0) {
        return sprintf('%dh %dm', $hours, $minutes);
    }
    if ($minutes > 0) {
        return sprintf('%dm %ds', $minutes, $secs);
    }

    return sprintf('%ds', $secs);
}

function sv_extract_job_response(?string $json): array
{
    $decoded = is_string($json) ? json_decode($json, true) : null;
    return is_array($decoded) ? $decoded : [];
}

function sv_extract_job_timestamps(array $row, array $response): array
{
    $heartbeatAt = $response['heartbeat_at']
        ?? $response['_sv_worker_heartbeat_at']
        ?? ($row['heartbeat_at'] ?? null)
        ?? null;
    $startedAt = $response['started_at']
        ?? $response['_sv_worker_started_at']
        ?? ($row['started_at'] ?? null)
        ?? null;
    $updatedAt = $response['updated_at']
        ?? ($row['updated_at'] ?? null)
        ?? null;
    $finishedAt = $response['finished_at']
        ?? $response['completed_at']
        ?? null;

    $ageSource = null;
    $ageBase = null;
    if (is_string($heartbeatAt) && $heartbeatAt !== '') {
        $ageBase = $heartbeatAt;
        $ageSource = 'heartbeat_at';
    } elseif (is_string($startedAt) && $startedAt !== '') {
        $ageBase = $startedAt;
        $ageSource = 'started_at';
    } elseif (is_string($updatedAt) && $updatedAt !== '') {
        $ageBase = $updatedAt;
        $ageSource = 'updated_at';
    }

    $ageSeconds = null;
    if ($ageBase !== null && $ageBase !== '') {
        $baseTs = strtotime($ageBase);
        if ($baseTs !== false) {
            $ageSeconds = time() - $baseTs;
        }
    }

    return [
        'heartbeat_at' => $heartbeatAt,
        'started_at'  => $startedAt,
        'updated_at'  => $updatedAt,
        'finished_at' => $finishedAt,
        'age_seconds' => $ageSeconds,
        'age_label'   => sv_format_job_age($ageSeconds),
        'age_source'  => $ageSource,
    ];
}

function sv_job_stuck_info(array $row, array $response, int $timeoutMinutes): array
{
    $status = strtolower((string)($row['status'] ?? ''));
    if ($status !== 'running') {
        return [
            'stuck'  => false,
            'reason' => null,
            'reason_code' => null,
        ];
    }

    $timing = sv_extract_job_timestamps($row, $response);
    $ageSeconds = $timing['age_seconds'];
    $ageMinutes = $ageSeconds !== null ? ($ageSeconds / 60) : null;
    $ageSource  = $timing['age_source'] ?? null;
    $pid        = $response['_sv_worker_pid'] ?? null;

    $pidInfo = null;
    if (is_numeric($pid)) {
        $pidInfo = sv_is_pid_running((int)$pid);
    }

    if ($ageMinutes === null) {
        return [
            'stuck' => false,
            'reason' => null,
            'reason_code' => null,
        ];
    }

    $maxAge = max(1, $timeoutMinutes);
    if ($ageMinutes !== null && $ageMinutes >= $maxAge) {
        $reasonCode = $ageSource === 'heartbeat_at' ? 'stuck_heartbeat_timeout' : 'stuck_no_activity';
        return [
            'stuck'  => true,
            'reason' => 'timeout > ' . $maxAge . 'm (' . ($ageSource ?? 'unknown') . ')',
            'reason_code' => $reasonCode,
        ];
    }

    if ($pidInfo !== null && !($pidInfo['running'] ?? false)) {
        $minGrace = 2;
        if ($ageMinutes !== null && $ageMinutes >= $minGrace) {
            return [
                'stuck'  => true,
                'reason' => 'worker pid not running',
                'reason_code' => 'stuck_no_activity',
            ];
        }
    }

    return [
        'stuck'  => false,
        'reason' => null,
        'reason_code' => null,
    ];
}

function sv_recover_stuck_jobs(PDO $pdo, array $types, int $maxAgeMinutes, ?callable $logger = null): int
{
    $types = array_values(array_filter(array_map('strval', $types), static fn ($t) => $t !== ''));
    if ($types === []) {
        return 0;
    }

    $maxAgeMinutes = max(1, $maxAgeMinutes);
    $placeholders  = implode(',', array_fill(0, count($types), '?'));

    $stmt = $pdo->prepare(
        'SELECT id, type, status, created_at, updated_at, heartbeat_at, started_at, forge_response_json, error_message '
        . 'FROM jobs WHERE status = "running" AND type IN (' . $placeholders . ')'
    );
    $stmt->execute($types);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $now   = date('c');
    $count = 0;

    foreach ($rows as $row) {
        $response = sv_extract_job_response($row['forge_response_json'] ?? null);
        $stuckInfo = sv_job_stuck_info($row, $response, $maxAgeMinutes);
        if (!$stuckInfo['stuck']) {
            continue;
        }

        $reason = $stuckInfo['reason'] ?? ('timeout > ' . $maxAgeMinutes . 'm');
        $reasonCode = $stuckInfo['reason_code'] ?? 'stuck_no_activity';
        $response['_sv_stuck'] = true;
        $response['_sv_stuck_reason'] = $reason;
        $response['_sv_stuck_reason_code'] = $reasonCode;
        $response['_sv_stuck_checked_at'] = $now;

        sv_update_job_status(
            $pdo,
            (int)$row['id'],
            'error',
            json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $reasonCode
        );
        $count++;

        sv_audit_log($pdo, 'job_stuck_timeout', 'jobs', (int)$row['id'], [
            'error'        => $reasonCode,
            'detail'       => $reason,
            'marked_at'    => $now,
            'types_scoped' => $types,
        ]);
        if ($logger) {
            $logger('Job #' . (int)$row['id'] . ' als stuck markiert (' . $reason . ').');
        }
    }

    return $count;
}

function sv_mark_stuck_jobs(PDO $pdo, array $types, int $maxAgeMinutes, ?callable $logger = null): int
{
    return sv_recover_stuck_jobs($pdo, $types, $maxAgeMinutes, $logger);
}

function sv_log_forge_paths(array $config, array $paths): void
{
    $logsError = null;
    $logsRoot = sv_ensure_logs_root($config, $logsError);
    if ($logsRoot === null) {
        sv_log_system_error($config, 'forge_worker_log_root_unavailable', ['error' => $logsError]);
        return;
    }
    $runtimeLog = $logsRoot . '/forge_worker_runtime.log';
    $shortened  = [];

    foreach ($paths as $key => $value) {
        $shortened[$key] = sv_safe_path_label((string)$value);
    }

    $result = file_put_contents(
        $runtimeLog,
        sprintf('[%s] forge_paths %s', date('c'), json_encode($shortened, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . PHP_EOL,
        FILE_APPEND
    );
    if ($result === false) {
        sv_log_system_error($config, 'forge_worker_runtime_log_write_failed', ['path' => $runtimeLog]);
    }
}

function sv_log_forge_job_runtime(array $config, int $jobId, int $mediaId, string $mode, array $data = []): void
{
    $logsError = null;
    $logsRoot = sv_ensure_logs_root($config, $logsError);
    if ($logsRoot === null) {
        sv_log_system_error($config, 'forge_worker_log_root_unavailable', ['error' => $logsError]);
        return;
    }
    $runtimeLog = $logsRoot . '/forge_worker_runtime.log';
    $payload = [
        'job_id'   => $jobId,
        'media_id' => $mediaId,
        'mode'     => $mode,
    ];

    if (isset($data['preview_path'])) {
        $payload['preview_path'] = sv_safe_path_label((string)$data['preview_path']);
    }
    if (array_key_exists('replaced', $data)) {
        $payload['replaced'] = (bool)$data['replaced'];
    }
    if (isset($data['backup_path'])) {
        $payload['backup_path'] = sv_safe_path_label((string)$data['backup_path']);
    }
    if (isset($data['error'])) {
        $payload['error'] = sv_sanitize_error_message((string)$data['error']);
    }
    if (!empty($data['forced_preview'])) {
        $payload['forced_preview'] = true;
    }
    if (isset($data['reason'])) {
        $payload['reason'] = sv_sanitize_error_message((string)$data['reason']);
    }

    $result = file_put_contents(
        $runtimeLog,
        sprintf('[%s] forge_job %s', date('c'), json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . PHP_EOL,
        FILE_APPEND
    );
    if ($result === false) {
        sv_log_system_error($config, 'forge_worker_runtime_log_write_failed', ['path' => $runtimeLog]);
    }
}

function sv_fail_job_on_empty_path(PDO $pdo, int $jobId, string $name, $value): void
{
    if (!is_string($value) || trim($value) === '') {
        $message = 'empty path: ' . $name;
        sv_update_job_status($pdo, $jobId, 'error', null, $message);
        throw new RuntimeException($message);
    }
}

function sv_run_forge_regen_replace(PDO $pdo, array $config, int $mediaId, callable $logger, array $overrides = []): array
{
    $logger('Forge-Regeneration wird in V3 asynchron über die Job-Queue abgewickelt.');

    $queued = sv_queue_forge_regeneration($pdo, $config, $mediaId, $logger, $overrides);

    $worker = sv_spawn_forge_worker_for_media(
        $pdo,
        $config,
        $mediaId,
        (int)($queued['job_id'] ?? 0) ?: null,
        1,
        $logger
    );

    if (($worker['state'] ?? null) === 'error' && !empty($queued['job_id'])) {
        $snippet = trim((string)($worker['err_snippet'] ?? $worker['reason'] ?? 'spawn failed'));
        $snippet = sv_sanitize_error_message($snippet, 200);
        $snippet = sv_forge_limit_error($snippet, 200);
        $message = 'worker spawn failed: ' . ($snippet === '' ? 'unknown' : $snippet);
        $stmt    = $pdo->prepare(
            'UPDATE jobs SET error_message = CASE WHEN error_message IS NULL OR error_message = "" THEN :msg'
            . ' ELSE error_message || "; " || :msg END WHERE id = :id'
        );
        $stmt->execute([
            ':msg' => $message,
            ':id'  => (int)$queued['job_id'],
        ]);
        $queued['error_message'] = isset($queued['error_message']) && (string)$queued['error_message'] !== ''
            ? $queued['error_message'] . '; ' . $message
            : $message;
    }

    $pidStatus = $worker['pid'] !== null ? sv_is_pid_running((int)$worker['pid']) : ['running' => false, 'unknown' => $worker['unknown']];
    $status    = ($pidStatus['running'] ?? false) ? 'running' : ($queued['status'] ?? 'queued');
    $spawnMessage = 'Job in Queue';
    if (!empty($worker['skipped'])) {
        $spawnMessage = 'Worker-Spawn übersprungen: ' . ((string)($worker['reason'] ?? 'cooldown'));
    } elseif ($status === 'running') {
        $spawnMessage = 'Worker läuft';
    }

    return array_merge($queued, [
        'status'               => $status,
        'worker_pid'           => $worker['pid'],
        'worker_started_at'    => $worker['started'],
        'worker_status_unknown'=> (bool)($pidStatus['unknown'] ?? $worker['unknown']),
        'worker_spawn_skipped' => (bool)($worker['skipped'] ?? false),
        'worker_spawn_reason'  => $worker['reason'] ?? null,
        'worker_spawn'         => $worker['state'] ?? null,
        'worker_spawn_cmd'     => $worker['cmd'] ?? null,
        'worker_spawn_log_paths' => $worker['log_paths'] ?? null,
        'message'              => $spawnMessage,
    ]);
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
    $existingResponse = json_decode((string)($jobRow['forge_response_json'] ?? ''), true);
    $workerPid    = is_array($existingResponse) ? ($existingResponse['_sv_worker_pid'] ?? null) : null;
    $workerStart  = is_array($existingResponse) ? ($existingResponse['_sv_worker_started_at'] ?? null) : null;
    if (!is_array($payload)) {
        sv_update_job_status($pdo, $jobId, 'error', null, 'Forge-Job hat keine gültige Payload.');
        throw new RuntimeException('Forge-Job #' . $jobId . ' hat keine gültige Payload.');
    }

    $regenMode = 'preview';
    if (isset($payload['_sv_mode']) && is_string($payload['_sv_mode'])) {
        $candidate = strtolower(trim((string)$payload['_sv_mode']));
        if (in_array($candidate, ['preview', 'replace'], true)) {
            $regenMode = $candidate;
        }
    }
    $payload['_sv_mode'] = $regenMode;

    $generationMode = $payload['_sv_decided_mode']
        ?? $payload['_sv_generation_mode']
        ?? (isset($payload['init_images']) ? 'img2img' : 'txt2img');

    $regenPlan = sv_extract_regen_plan_from_payload($payload);
    $payloadPrompt = isset($payload['prompt']) ? (string)$payload['prompt'] : '';
    if (trim($payloadPrompt) === '' && trim((string)$regenPlan['final_prompt']) !== '') {
        $payloadPrompt = (string)$regenPlan['final_prompt'];
    }
    if (trim($payloadPrompt) === '') {
        sv_update_job_status($pdo, $jobId, 'error', null, 'Forge-Job ohne finalen Prompt.');
        throw new RuntimeException('Forge-Job #' . $jobId . ' hat keinen finalen Prompt.');
    }

    $payload['prompt'] = $payloadPrompt;

    $mediaRow = sv_load_media_with_prompt($pdo, $mediaId);
    $pathsCfg = $config['paths'] ?? [];
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
    sv_fail_job_on_empty_path($pdo, $jobId, 'original_media_path', $path);
    if (!is_file($path)) {
        sv_update_job_status($pdo, $jobId, 'error', null, 'Dateipfad nicht vorhanden.');
        throw new RuntimeException('Dateipfad nicht vorhanden.');
    }

    sv_assert_media_path_allowed($path, $pathsCfg, 'forge_regen_replace');

    $requestedModel = $payload['_sv_requested_model'] ?? ($payload['model'] ?? null);
    $origExt = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $origImageInfo = @getimagesize($path);
    $origWidth = $origImageInfo !== false ? (int)$origImageInfo[0] : (int)($mediaRow['width'] ?? 0);
    $origHeight = $origImageInfo !== false ? (int)$origImageInfo[1] : (int)($mediaRow['height'] ?? 0);

    $promptSource   = $payload['_sv_prompt_source'] ?? ($regenPlan['prompt_source'] ?? null);
    $negativeSource = $payload['_sv_negative_source'] ?? ($payload['_sv_negative_mode'] ?? $regenPlan['negative_mode'] ?? null);
    $decidedReason  = $payload['_sv_decided_reason'] ?? ($regenPlan['decided_reason'] ?? null);
    $decidedDenoise = isset($payload['_sv_decided_denoise'])
        ? (float)$payload['_sv_decided_denoise']
        : (isset($payload['denoising_strength']) ? (float)$payload['denoising_strength'] : null);
    if ($decidedReason === null || $decidedReason === '') {
        $decidedReason = 'auto img2img default';
    }
    if ($negativeSource === 'manual_empty') {
        $negativeSource = 'manual';
    } elseif ($negativeSource === 'empty_allowed' || $negativeSource === 'empty_flux') {
        $negativeSource = 'empty';
    }
    $payload['_sv_generation_mode'] = $generationMode;

    $backupDir        = sv_resolve_backup_dir($config);
    $tmpDirRaw        = $pathsCfg['tmp'] ?? null;
    $tmpDir           = (is_string($tmpDirRaw) && trim($tmpDirRaw) !== '')
        ? rtrim(str_replace('\\', '/', (string)$tmpDirRaw), '/')
        : (sv_base_dir() . '/TMP');
    if (!is_dir($tmpDir)) {
        mkdir($tmpDir, 0777, true);
    }

    $plannedBackupPath     = sv_build_backup_path($backupDir, $path);
    $tempOutputRoot        = $regenMode === 'replace' ? dirname($path) : $tmpDir;
    $plannedTempOutputPath = sv_build_temp_output_path($tempOutputRoot, $path);
    $finalTargetPath       = $path;

    sv_assert_stream_path_allowed($backupDir, $config, 'forge_backup_dir', false, true);
    sv_assert_stream_path_allowed($plannedBackupPath, $config, 'forge_backup_path', false, true);
    sv_assert_stream_path_allowed($tmpDir, $config, 'forge_tmp_dir', false, false, true);
    sv_assert_stream_path_allowed($plannedTempOutputPath, $config, 'forge_tmp_file', false, false, true);

    sv_fail_job_on_empty_path($pdo, $jobId, 'backup_path', $plannedBackupPath);
    sv_fail_job_on_empty_path($pdo, $jobId, 'temp_output_path', $plannedTempOutputPath);
    sv_fail_job_on_empty_path($pdo, $jobId, 'final_target_path', $finalTargetPath);

    sv_log_forge_paths($config, [
        'job_id'             => $jobId,
        'original_media_path'=> $path,
        'backup_path'        => $plannedBackupPath,
        'temp_output_path'   => $plannedTempOutputPath,
        'final_target_path'  => $finalTargetPath,
    ]);

    $backupPath        = null;
    $newFileInfo       = null;
    $forgeResponse     = null;
    $previewPath       = null;
    $modeForcedPreview = false;
    $forcedReason      = null;
    $replaceApplied    = false;

    try {
        $logger(sprintf(
            'Forge-Request: mode=%s regen_mode=%s sampler=%s scheduler=%s seed=%s steps=%s denoise=%s model=%s',
            $generationMode,
            $regenMode,
            (string)($payload['sampler'] ?? 'auto'),
            (string)($payload['scheduler'] ?? 'auto'),
            (string)($payload['seed'] ?? 'auto'),
            (string)($payload['steps'] ?? 'auto'),
            isset($payload['denoising_strength']) ? (string)$payload['denoising_strength'] : 'auto',
            (string)($payload['model'] ?? 'auto')
        ));

        $forgeResponse = sv_call_forge_sync($config, $payload, $logger);
        $logger('Forge response binary: ' . ((isset($forgeResponse['binary']) && $forgeResponse['binary'] !== '') ? 'yes' : 'no'));
        $imageMeta     = sv_write_forge_image($config, $plannedTempOutputPath, $forgeResponse['binary'], $logger, [
            'width'  => $origWidth,
            'height' => $origHeight,
        ]);

        $suspicious = sv_is_suspicious_image($plannedTempOutputPath);
        if ($regenMode === 'replace' && ($suspicious['suspicious'] ?? false)) {
            $modeForcedPreview = true;
            $forcedReason      = $suspicious['reason'] ?? 'suspicious image output';
            $regenMode         = 'preview';
            $payload['_sv_mode'] = $regenMode;
        }

        if ($regenMode === 'preview') {
            $previewDir = sv_resolve_preview_dir($config);
            $previewExt = ($imageMeta['format_preserved'] ?? false) ? ($imageMeta['orig_ext'] ?? $origExt) : 'png';
            if (!is_string($previewExt) || $previewExt === '') {
                $previewExt = 'png';
            }

            $previewName = sprintf('preview_%d_%d_%s.%s', $mediaId, $jobId, date('Ymd_His'), $previewExt);
            $previewPath = $previewDir . '/' . $previewName;

            if (!rename($plannedTempOutputPath, $previewPath)) {
                if (!@copy($plannedTempOutputPath, $previewPath)) {
                    @unlink($plannedTempOutputPath);
                    throw new RuntimeException('Preview-Datei konnte nicht geschrieben werden.');
                }
                @unlink($plannedTempOutputPath);
            }

            $previewHash     = @hash_file('md5', $previewPath) ?: null;
            $previewFilesize = @filesize($previewPath);
            $previewWidth    = $imageMeta['width'] ?? null;
            $previewHeight   = $imageMeta['height'] ?? null;
            $logger('Preview geschrieben: ' . ($previewPath !== null ? 'yes' : 'no'));

            $responsePayload = [
                '_sv_worker_pid'        => $workerPid,
                '_sv_worker_started_at' => $workerStart,
                'forge_response'        => $forgeResponse['response_array'] ?? null,
                'used_sampler'          => $forgeResponse['used_sampler'] ?? ($payload['sampler'] ?? null),
                'used_scheduler'        => $forgeResponse['used_scheduler'] ?? ($payload['scheduler'] ?? null),
                'attempt_index'         => $forgeResponse['attempt_index'] ?? null,
                'attempt_chain'         => $forgeResponse['attempt_chain'] ?? null,
                'mode'                  => $regenMode,
                'generation_mode'       => $generationMode,
                'denoise'               => isset($payload['denoising_strength']) ? (float)$payload['denoising_strength'] : null,
                'negative_mode'         => $payload['_sv_negative_mode'] ?? null,
                'negative_len'          => $payload['_sv_negative_len'] ?? null,
                'mode_forced_preview'   => $modeForcedPreview,
                'mode_forced_reason'    => $forcedReason,
                'result' => [
                    'replaced'         => false,
                    'preview_path'     => $previewPath,
                    'preview_hash'     => $previewHash,
                    'preview_filesize' => $previewFilesize === false ? null : (int)$previewFilesize,
                    'preview_width'    => $previewWidth,
                    'preview_height'   => $previewHeight,
                    'format_preserved' => (bool)($imageMeta['format_preserved'] ?? false),
                    'orig_path'        => basename($path),
                    'old_hash'         => $mediaRow['hash'] ?? null,
                    'prompt_category'  => $regenPlan['category'],
                    'fallback_used'    => $regenPlan['fallback_used'],
                    'tag_prompt_used'  => $regenPlan['tag_prompt_used'],
                    'model'            => $payload['model'] ?? null,
                    'requested_model'  => $requestedModel,
                    'orig_w'           => $origWidth,
                    'orig_h'           => $origHeight,
                    'out_w'            => $previewWidth,
                    'out_h'            => $previewHeight,
                    'orig_ext'         => $origExt,
                    'out_ext'          => $imageMeta['out_ext'] ?? null,
                ],
            ];

            $responseJson = json_encode($responsePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            sv_update_job_status($pdo, $jobId, 'done', $responseJson, null);
            sv_log_forge_job_runtime($config, $jobId, $mediaId, $regenMode, [
                'replaced'       => false,
                'preview_path'   => $previewPath,
                'error'          => null,
                'forced_preview' => $modeForcedPreview,
                'reason'         => $forcedReason,
            ]);

            return [
                'job_id'   => $jobId,
                'media_id' => $mediaId,
                'status'   => 'done',
                'mode'     => $regenMode,
                'preview'  => $previewPath,
            ];
        }

        $backupPath = sv_backup_media_file($config, $path, $logger, $backupDir, $plannedBackupPath);

        if (!rename($plannedTempOutputPath, $path)) {
            @unlink($plannedTempOutputPath);
            throw new RuntimeException('Ersetzen der Zieldatei fehlgeschlagen.');
        }
        $logger('Replace erfolgreich geschrieben.');
        $replaceApplied = true;

        $newFileInfo = $imageMeta;
        $newFileInfo['hash'] = @hash_file('md5', $path) ?: ($imageMeta['hash'] ?? null);
        $newFileInfo['filesize'] = @filesize($path) ?: ($imageMeta['filesize'] ?? null);

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

        $outputMtime   = @filemtime($path) ?: time();
        $responsePayload = [
            '_sv_worker_pid'        => $workerPid,
            '_sv_worker_started_at' => $workerStart,
            'forge_response'        => $forgeResponse['response_array'] ?? null,
            'used_sampler'          => $forgeResponse['used_sampler'] ?? ($payload['sampler'] ?? null),
            'used_scheduler'        => $forgeResponse['used_scheduler'] ?? ($payload['scheduler'] ?? null),
            'used_prompt_source'    => $promptSource,
            'used_negative_source'  => $negativeSource,
            'used_seed'             => $payload['seed'] ?? null,
            'attempt_index'         => $forgeResponse['attempt_index'] ?? null,
            'attempt_chain'         => $forgeResponse['attempt_chain'] ?? null,
            'mode'                  => $regenMode,
            'generation_mode'       => $generationMode,
            'denoise'               => isset($payload['denoising_strength']) ? (float)$payload['denoising_strength'] : null,
            'decided_mode'          => $generationMode,
            'decided_reason'        => $decidedReason,
            'decided_denoise'       => $decidedDenoise,
            'negative_mode'         => $payload['_sv_negative_mode'] ?? null,
            'negative_len'          => $payload['_sv_negative_len'] ?? null,
            'result' => [
                'replaced'         => true,
                'backup_path'      => $backupPath,
                'new_hash'         => $newFileInfo['hash'] ?? null,
                'old_hash'         => $mediaRow['hash'] ?? null,
                'prompt_category'  => $regenPlan['category'],
                'fallback_used'    => $regenPlan['fallback_used'],
                'tag_prompt_used'  => $regenPlan['tag_prompt_used'],
                'model'            => $payload['model'] ?? null,
                'requested_model'  => $requestedModel,
                'model_source'     => $payload['_sv_model_source'] ?? null,
                'model_status'     => $payload['_sv_model_status'] ?? null,
                'model_error'      => $payload['_sv_model_error'] ?? null,
                'orig_w'           => $origWidth,
                'orig_h'           => $origHeight,
                'out_w'            => $newFileInfo['width'] ?? null,
                'out_h'            => $newFileInfo['height'] ?? null,
                'orig_ext'         => $origExt,
                'out_ext'          => $newFileInfo['out_ext'] ?? null,
                'format_preserved' => (bool)($newFileInfo['format_preserved'] ?? false),
                'output_path'      => $path,
                'output_mtime'     => $outputMtime,
                'version_token'    => (string)$outputMtime,
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
            'orig_ext'          => $origExt,
            'out_ext'           => $newFileInfo['out_ext'] ?? null,
            'format_preserved'  => (bool)($newFileInfo['format_preserved'] ?? false),
            'orig_w'            => $origWidth,
            'orig_h'            => $origHeight,
            'out_w'             => $newFileInfo['width'] ?? null,
            'out_h'             => $newFileInfo['height'] ?? null,
            'scan_updated'      => $refreshResult['scan_updated'] ?? false,
            'meta_updated'      => $refreshResult['meta_updated'] ?? false,
            'prompt_created'    => $refreshResult['prompt_created'] ?? false,
            'prompt_category'   => $regenPlan['category'],
            'backup_path'       => $backupPath,
        ]);

        sv_log_forge_job_runtime($config, $jobId, $mediaId, $regenMode, [
            'replaced'   => true,
            'backup_path'=> $backupPath,
            'error'      => null,
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
            'model_source'      => $payload['_sv_model_source'] ?? null,
            'model_status'      => $payload['_sv_model_status'] ?? null,
            'model_error'       => $payload['_sv_model_error'] ?? null,
            'orig_ext'          => $origExt,
            'out_ext'           => $newFileInfo['out_ext'] ?? null,
            'format_preserved'  => (bool)($newFileInfo['format_preserved'] ?? false),
            'orig_w'            => $origWidth,
            'orig_h'            => $origHeight,
            'out_w'             => $newFileInfo['width'] ?? null,
            'out_h'             => $newFileInfo['height'] ?? null,
            'scan_updated'      => $refreshResult['scan_updated'] ?? false,
            'meta_updated'      => $refreshResult['meta_updated'] ?? false,
            'prompt_created'    => $refreshResult['prompt_created'] ?? false,
            'status'            => 'done',
        ];
    } catch (Throwable $e) {
        $responseJson = null;
        if ($forgeResponse !== null) {
            $errorData = [
                'attempt_chain' => $forgeResponse['attempt_chain'] ?? null,
                'attempt_index' => $forgeResponse['attempt_index'] ?? null,
                'log_payload'   => $forgeResponse['log_payload'] ?? null,
            ];
            $responseJson = json_encode($errorData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif ($e instanceof SvForgeHttpException) {
            $responseJson = json_encode($e->getLogData(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        sv_update_job_status($pdo, $jobId, 'error', $responseJson, $e->getMessage());

        if (is_string($plannedTempOutputPath) && is_file($plannedTempOutputPath)) {
            @unlink($plannedTempOutputPath);
        }

        $restoreOk = false;
        if ($backupPath !== null && is_file($backupPath) && $replaceApplied) {
            try {
                $restoreOk = copy($backupPath, $path);
            } catch (Throwable $restoreError) {
                $logger('Restore nach Fehler fehlgeschlagen: ' . $restoreError->getMessage());
            }
        }

        if ($backupPath !== null && is_file($backupPath) && (!$replaceApplied || $restoreOk)) {
            @unlink($backupPath);
        }

        sv_log_forge_job_runtime($config, $jobId, $mediaId, $regenMode, [
            'replaced' => false,
            'error'    => $e->getMessage(),
        ]);

        return [
            'job_id'   => $jobId,
            'media_id' => $mediaId,
            'status'   => 'error',
            'error'    => $e->getMessage(),
        ];
    }
}

function sv_process_forge_job_batch(PDO $pdo, array $config, ?int $limit, callable $logger, ?int $mediaId = null): array
{
    sv_recover_stuck_jobs($pdo, SV_FORGE_JOB_TYPES, SV_JOB_STUCK_MINUTES, $logger);

    $effectiveLimit = $limit === null ? 1 : max(1, min(10, (int)$limit));

    $typePlaceholders = implode(', ', array_fill(0, count(SV_FORGE_JOB_TYPES), '?'));
    $statusPlaceholders = implode(', ', array_fill(0, count(SV_FORGE_JOB_STATUSES), '?'));

    $whereParts = [
        'type IN (' . $typePlaceholders . ')',
        'status IN (' . $statusPlaceholders . ')',
    ];

    $params = array_merge(SV_FORGE_JOB_TYPES, SV_FORGE_JOB_STATUSES);

    if ($mediaId !== null) {
        $whereParts[] = 'media_id = ?';
        $params[]    = $mediaId;
    }

    $whereClause = implode(' AND ', $whereParts);
    $sql = 'SELECT * FROM jobs WHERE ' . $whereClause . ' ORDER BY id ASC LIMIT ?';

    $stmt = $pdo->prepare($sql);
    $params[] = $effectiveLimit;
    $stmt->execute($params);

    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $logger(sprintf(
        'Forge job query: media_scope=%s WHERE="%s" found=%d',
        $mediaId === null ? 'all' : (string)$mediaId,
        $whereClause,
        count($jobs)
    ));

    $summary = [
        'total'   => count($jobs),
        'done'    => 0,
        'error'   => 0,
        'skipped' => 0,
        'filters' => [
            'types'    => SV_FORGE_JOB_TYPES,
            'statuses' => SV_FORGE_JOB_STATUSES,
            'media_id' => $mediaId,
            'where'    => $whereClause,
        ],
        'results' => [],
    ];

    foreach ($jobs as $jobRow) {
        $jobId = (int)($jobRow['id'] ?? 0);
        $jobType = (string)($jobRow['type'] ?? '');
        $jobStatus = (string)($jobRow['status'] ?? '');
        $jobMediaId = isset($jobRow['media_id']) ? (int)$jobRow['media_id'] : null;
        $jobPayload = json_decode((string)($jobRow['forge_request_json'] ?? ''), true);
        $decidedMode = is_array($jobPayload)
            ? ($jobPayload['_sv_decided_mode'] ?? ($jobPayload['_sv_generation_mode'] ?? null))
            : null;
        $regenMode = is_array($jobPayload) ? ($jobPayload['_sv_mode'] ?? 'preview') : 'preview';

        if (!in_array($jobType, SV_FORGE_JOB_TYPES, true)) {
            $summary['skipped']++;
            $summary['results'][] = [
                'job_id' => $jobId,
                'status' => 'skipped',
                'error'  => 'type mismatch: ' . $jobType,
            ];
            $logger('Überspringe Forge-Job #' . $jobId . ' wegen type mismatch: ' . $jobType);
            continue;
        }

        if (!in_array($jobStatus, SV_FORGE_JOB_STATUSES, true)) {
            $summary['skipped']++;
            $summary['results'][] = [
                'job_id' => $jobId,
                'status' => 'skipped',
                'error'  => 'status mismatch: ' . $jobStatus,
            ];
            $logger('Überspringe Forge-Job #' . $jobId . ' wegen status mismatch: ' . $jobStatus);
            continue;
        }

        if ($mediaId !== null && $jobMediaId !== $mediaId) {
            $summary['skipped']++;
            $summary['results'][] = [
                'job_id' => $jobId,
                'status' => 'skipped',
                'error'  => 'media_id mismatch: ' . ($jobMediaId === null ? 'null' : (string)$jobMediaId),
            ];
            $logger('Überspringe Forge-Job #' . $jobId . ' wegen media_id mismatch: ' . ($jobMediaId ?? 'null'));
            continue;
        }
        $claimReason = null;
        if (!sv_claim_job_running($pdo, $jobId, SV_FORGE_JOB_STATUSES, [
            'media_id' => $jobMediaId,
            'regen_mode' => $regenMode,
        ], $claimReason)) {
            $summary['skipped']++;
            $summary['results'][] = [
                'job_id' => $jobId,
                'status' => 'skipped',
                'error'  => $claimReason ?? 'claim_failed_or_already_running',
            ];
            $logger('Überspringe Forge-Job #' . $jobId . ' (claim failed): ' . ($claimReason ?? 'claim_failed'));
            continue;
        }
        try {
            $logger(sprintf(
                'Verarbeite Forge-Job #%d (Media %d) decided_mode=%s regen_mode=%s',
                $jobId,
                (int)($jobRow['media_id'] ?? 0),
                $decidedMode ?? 'n/a',
                $regenMode
            ));
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
        'version_token'   => (string)($mediaRow['hash'] ?? ($mediaRow['created_at'] ?? 'base')),
        'model_requested' => null,
        'model_used'      => null,
        'sampler'         => null,
        'scheduler'       => null,
        'steps'           => null,
        'cfg_scale'       => null,
        'seed'            => null,
        'width'           => $mediaRow['width'] ?? null,
        'height'          => $mediaRow['height'] ?? null,
        'prompt_category' => null,
        'fallback_used'   => false,
        'backup_path'     => null,
        'backup_exists'   => false,
        'hash_old'        => null,
        'hash_new'        => $mediaRow['hash'] ?? null,
        'hash_display'    => $mediaRow['hash'] ?? null,
        'prompt'          => null,
        'negative_prompt' => null,
        'mode'            => null,
        'strategy'        => null,
        'output_path'     => $mediaRow['path'] ?? null,
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
        $payloadRaw  = json_decode((string)($row['forge_request_json'] ?? ''), true);
        $responseRaw = json_decode((string)($row['forge_response_json'] ?? ''), true);
        $payload     = is_array($payloadRaw) ? $payloadRaw : [];
        $response    = is_array($responseRaw) ? $responseRaw : [];

        $regenPlan = $payload !== [] ? sv_extract_regen_plan_from_payload($payload) : [
            'category'        => null,
            'fallback_used'   => null,
            'tag_prompt_used' => null,
        ];

        $requestedModel = $payload['_sv_requested_model'] ?? ($payload['model'] ?? null);
        $resolvedModel = isset($response['result']['model'])
            ? $response['result']['model']
            : ($payload['model'] ?? null);
        $resultMeta = is_array($response['result'] ?? null) ? $response['result'] : [];
        $generationMode = $response['generation_mode']
            ?? ($payload['_sv_generation_mode'] ?? $payload['_sv_decided_mode'] ?? null)
            ?? null;
        $versionToken = $resultMeta['version_token']
            ?? $resultMeta['output_mtime']
            ?? $resultMeta['preview_hash']
            ?? $row['updated_at']
            ?? $row['id']
            ?? null;
        $promptEffective = $resultMeta['prompt_effective'] ?? $regenPlan['final_prompt'] ?? ($payload['prompt'] ?? null);
        $negativeEffective = $payload['negative_prompt'] ?? null;
        $outputPath = $resultMeta['output_path'] ?? ($resultMeta['preview_path'] ?? null);

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
            'model_source'    => $resultMeta['model_source'] ?? ($payload['_sv_model_source'] ?? null),
            'model_status'    => $resultMeta['model_status'] ?? ($payload['_sv_model_status'] ?? null),
            'model_error'     => $resultMeta['model_error'] ?? ($payload['_sv_model_error'] ?? null),
            'sampler'         => $payload['sampler'] ?? null,
            'scheduler'       => $payload['scheduler'] ?? null,
            'steps'           => $payload['steps'] ?? null,
            'cfg_scale'       => $payload['cfg_scale'] ?? null,
            'seed'            => $payload['seed'] ?? null,
            'width'           => $payload['width'] ?? null,
            'height'          => $payload['height'] ?? null,
            'prompt_category' => $promptCategory,
            'fallback_used'   => $fallbackUsed,
            'backup_path'     => $backupPath,
            'backup_exists'   => $backupPath !== null && is_file((string)$backupPath),
            'hash_old'        => $hashOld,
            'hash_new'        => $hashNew,
            'hash_display'    => $hashNew ?? $hashOld,
            'prompt'          => $promptEffective,
            'negative_prompt' => $negativeEffective,
            'mode'            => $generationMode ?? ($payload['_sv_mode'] ?? null),
            'strategy'        => $regenPlan['strategy'] ?? null,
            'output_path'     => $outputPath,
            'version_token'   => $versionToken,
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

function sv_fetch_worker_meta_for_media(PDO $pdo, int $mediaId): array
{
    $stmt = $pdo->prepare(
        'SELECT meta_key, meta_value FROM media_meta WHERE media_id = :media_id AND source = :source ORDER BY id DESC'
    );
    $stmt->execute([
        ':media_id' => $mediaId,
        ':source'   => SV_FORGE_WORKER_META_SOURCE,
    ]);

    $meta = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = (string)($row['meta_key'] ?? '');
        if ($key !== '' && !isset($meta[$key])) {
            $meta[$key] = (string)($row['meta_value'] ?? '');
        }
    }

    return $meta;
}

function sv_fetch_worker_meta_for_media_list(PDO $pdo, array $mediaIds): array
{
    if ($mediaIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($mediaIds), '?'));
    $stmt = $pdo->prepare(
        'SELECT media_id, meta_key, meta_value FROM media_meta '
        . 'WHERE media_id IN (' . $placeholders . ') AND source = ? ORDER BY id DESC'
    );
    $stmt->execute(array_merge($mediaIds, [SV_FORGE_WORKER_META_SOURCE]));

    $meta = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $mid = (int)($row['media_id'] ?? 0);
        $key = (string)($row['meta_key'] ?? '');
        if ($mid <= 0 || $key === '') {
            continue;
        }
        if (!isset($meta[$mid])) {
            $meta[$mid] = [];
        }
        if (!isset($meta[$mid][$key])) {
            $meta[$mid][$key] = (string)($row['meta_value'] ?? '');
        }
    }

    return $meta;
}

function sv_fetch_forge_jobs_for_media(PDO $pdo, int $mediaId, int $limit = 10, ?array $config = null): array
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

    $rows      = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $workerMeta = sv_fetch_worker_meta_for_media($pdo, $mediaId);

    $jobs = [];
    $fallbackModel = $config !== null ? sv_forge_fallback_model($config) : SV_FORGE_FALLBACK_MODEL;

    foreach ($rows as $row) {
        $payload  = json_decode((string)($row['forge_request_json'] ?? ''), true);
        $response = json_decode((string)($row['forge_response_json'] ?? ''), true);
        $response = is_array($response) ? $response : [];
        $regenPlan = is_array($payload['_sv_regen_plan'] ?? null) ? $payload['_sv_regen_plan'] : [];
        $repairMeta = is_array($payload['_sv_repair_meta'] ?? null) ? $payload['_sv_repair_meta'] : [];
        $jobLabel = $payload['_sv_job_label'] ?? null;

        $workerPid    = $response['_sv_worker_pid'] ?? ($workerMeta['worker_pid'] ?? null);
        $workerStart  = $response['_sv_worker_started_at'] ?? ($workerMeta['worker_started_at'] ?? null);
        $pidInfo      = is_numeric($workerPid) ? sv_is_pid_running((int)$workerPid) : ['running' => false, 'unknown' => $workerPid !== null];
        $promptInfo   = $response['result']['prompt_category'] ?? ($regenPlan['category'] ?? null);

        $mode = $payload['_sv_mode'] ?? 'preview';
        $generationMode = $payload['_sv_decided_mode']
            ?? $payload['_sv_generation_mode']
            ?? (isset($payload['init_images']) ? 'img2img' : 'txt2img');
        $decidedMode   = $response['decided_mode'] ?? $generationMode;
        $decidedReason = $response['decided_reason'] ?? ($payload['_sv_decided_reason'] ?? ($regenPlan['decided_reason'] ?? null));
        $decidedDenoise = $response['decided_denoise'] ?? ($payload['_sv_decided_denoise'] ?? ($regenPlan['decided_denoise'] ?? null));
        $usedSampler   = $response['used_sampler'] ?? ($payload['sampler'] ?? null);
        $usedScheduler = $response['used_scheduler'] ?? ($payload['scheduler'] ?? null);
        $attemptIndex  = $response['attempt_index'] ?? null;
        $attemptChain  = $response['attempt_chain'] ?? null;
        $requestedModel = $payload['_sv_requested_model'] ?? ($payload['model'] ?? null);
        $resolvedModel  = $payload['model'] ?? null;
        $fallbackUsed   = $resolvedModel === $fallbackModel && $requestedModel !== null && $requestedModel !== $resolvedModel;
        $usedPromptSource = $response['used_prompt_source'] ?? ($payload['_sv_prompt_source'] ?? ($regenPlan['prompt_source'] ?? null));
        $usedNegativeSource = $response['used_negative_source'] ?? ($payload['_sv_negative_source'] ?? ($regenPlan['negative_mode'] ?? null));

        $resultMeta = is_array($response['result'] ?? null) ? $response['result'] : [];
        $timing = sv_extract_job_timestamps($row, $response);
        $stuckInfo = sv_job_stuck_info($row, $response, SV_JOB_STUCK_MINUTES);
        $outputPath = isset($resultMeta['output_path']) ? sv_safe_path_label((string)$resultMeta['output_path']) : null;
        $jobs[] = [
            'id'                 => (int)$row['id'],
            'status'             => (string)$row['status'],
            'created_at'         => (string)($row['created_at'] ?? ''),
            'updated_at'         => (string)($row['updated_at'] ?? ''),
            'started_at'         => $timing['started_at'],
            'finished_at'        => $timing['finished_at'],
            'age_seconds'        => $timing['age_seconds'],
            'age_label'          => $timing['age_label'],
            'stuck'              => $stuckInfo['stuck'],
            'stuck_reason'       => $stuckInfo['reason'],
            'model'              => $resolvedModel,
            'mode'               => $mode,
            'generation_mode'    => $generationMode,
            'decided_mode'       => $decidedMode,
            'decided_reason'     => $decidedReason,
            'decided_denoise'    => $decidedDenoise,
            'seed'               => $payload['seed'] ?? null,
            'used_sampler'       => $usedSampler,
            'used_scheduler'     => $usedScheduler,
            'used_prompt_source' => $usedPromptSource,
            'used_negative_source' => $usedNegativeSource,
            'used_seed'          => $response['used_seed'] ?? ($payload['seed'] ?? null),
            'attempt_index'      => $attemptIndex,
            'attempt_chain'      => $attemptChain,
            'fallback_model'     => $fallbackUsed,
            'replaced'           => $resultMeta['replaced'] ?? ($row['status'] === 'done'),
            'error'              => isset($row['error_message']) ? sv_sanitize_error_message((string)$row['error_message']) : null,
            'worker_pid'         => $workerPid !== null ? (int)$workerPid : null,
            'worker_started_at'  => $workerStart,
            'worker_running'     => (bool)($pidInfo['running'] ?? false),
            'worker_unknown'     => (bool)($pidInfo['unknown'] ?? false),
            'prompt_category'    => $promptInfo,
            'prompt_source'      => $payload['_sv_regen_plan']['prompt_source'] ?? null,
            'negative_mode'      => $response['negative_mode'] ?? ($payload['_sv_negative_mode'] ?? null),
            'format_preserved'   => $resultMeta['format_preserved'] ?? null,
            'orig_w'             => $resultMeta['orig_w'] ?? ($payload['_sv_regen_plan']['orig_width'] ?? null),
            'orig_h'             => $resultMeta['orig_h'] ?? ($payload['_sv_regen_plan']['orig_height'] ?? null),
            'out_w'              => $resultMeta['out_w'] ?? null,
            'out_h'              => $resultMeta['out_h'] ?? null,
            'orig_ext'           => $resultMeta['orig_ext'] ?? ($payload['_sv_regen_plan']['orig_ext'] ?? null),
            'out_ext'            => $resultMeta['out_ext'] ?? null,
            'old_hash'           => $resultMeta['old_hash'] ?? null,
            'new_hash'           => $resultMeta['new_hash'] ?? null,
            'output_path'        => $outputPath,
            'version_token'      => $resultMeta['version_token'] ?? null,
            'job_label'          => is_string($jobLabel) ? $jobLabel : null,
            'repair_recipe'      => $repairMeta['recipe_id'] ?? null,
            'repair_goal'        => $repairMeta['goal'] ?? null,
            'repair_intensity'   => $repairMeta['intensity'] ?? null,
            'repair_tech_fix'    => $repairMeta['tech_fix'] ?? null,
            'auto_refresh'       => $jobLabel === 'Repair',
        ];
    }

    return $jobs;
}

function sv_fetch_forge_jobs_grouped(PDO $pdo, array $mediaIds, int $limitPerMedia = 5): array
{
    $limitPerMedia = max(1, $limitPerMedia);
    $mediaIds      = array_values(array_unique(array_filter(array_map('intval', $mediaIds), fn($v) => $v > 0)));
    if ($mediaIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($mediaIds), '?'));
    $stmt = $pdo->prepare(
        'SELECT id, media_id, status, created_at, updated_at, forge_request_json, forge_response_json, error_message '
        . 'FROM jobs WHERE type = ? AND media_id IN (' . $placeholders . ') ORDER BY id DESC'
    );
    $stmt->execute(array_merge([SV_FORGE_JOB_TYPE], $mediaIds));

    $rows       = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $workerMeta = sv_fetch_worker_meta_for_media_list($pdo, $mediaIds);
    $pidCache   = [];
    $grouped    = [];

    foreach ($rows as $row) {
        $mediaId = (int)($row['media_id'] ?? 0);
        if ($mediaId <= 0) {
            continue;
        }
        if (!isset($grouped[$mediaId])) {
            $grouped[$mediaId] = ['jobs' => []];
        }
        if (count($grouped[$mediaId]['jobs']) >= $limitPerMedia) {
            continue;
        }

        $payload       = json_decode((string)($row['forge_request_json'] ?? ''), true);
        $response      = json_decode((string)($row['forge_response_json'] ?? ''), true);
        $workerPid     = $response['_sv_worker_pid'] ?? ($workerMeta[$mediaId]['worker_pid'] ?? null);
        $workerStarted = $response['_sv_worker_started_at'] ?? ($workerMeta[$mediaId]['worker_started_at'] ?? null);
        $pidKey        = is_numeric($workerPid) ? (int)$workerPid : null;
        if ($pidKey !== null && !isset($pidCache[$pidKey])) {
            $pidCache[$pidKey] = sv_is_pid_running($pidKey);
        }
        $pidInfo = $pidKey !== null ? ($pidCache[$pidKey] ?? ['running' => false, 'unknown' => false]) : ['running' => false, 'unknown' => $workerPid !== null];

        $requestedModel = is_array($payload) ? ($payload['_sv_requested_model'] ?? ($payload['model'] ?? null)) : null;
        $resolvedModel  = is_array($payload) ? ($payload['model'] ?? null) : null;
        $shortInfoParts = [];
        if ($resolvedModel) {
            $shortInfoParts[] = 'Model: ' . $resolvedModel;
        }
        if ($requestedModel && $requestedModel !== $resolvedModel) {
            $shortInfoParts[] = 'Requested: ' . $requestedModel;
        }
        $promptCategory = $response['result']['prompt_category']
            ?? ($payload['_sv_regen_plan']['category'] ?? null);
        if ($promptCategory) {
            $shortInfoParts[] = 'Prompt ' . $promptCategory;
        }

        $resultMeta = is_array($response['result'] ?? null) ? $response['result'] : [];

        $grouped[$mediaId]['jobs'][] = [
            'id'                => (int)$row['id'],
            'status'            => (string)$row['status'],
            'created_at'        => (string)($row['created_at'] ?? ''),
            'updated_at'        => (string)($row['updated_at'] ?? ''),
            'model'             => $resolvedModel,
            'prompt_category'   => $promptCategory,
            'error_message'     => isset($row['error_message']) ? sv_sanitize_error_message((string)$row['error_message']) : null,
            'worker_pid'        => $pidKey,
            'worker_started_at' => $workerStarted,
            'worker_running'    => (bool)($pidInfo['running'] ?? false),
            'worker_unknown'    => (bool)($pidInfo['unknown'] ?? false),
            'info'              => implode(' | ', $shortInfoParts),
            'version_token'     => $resultMeta['version_token'] ?? null,
        ];
    }

    return $grouped;
}

function sv_run_scan_operation(PDO $pdo, array $config, string $scanPath, ?int $limit, callable $logger, ?callable $cancelCheck = null): array
{
    $scannerCfg    = $config['scanner'] ?? [];
    $pathsCfg      = $config['paths'] ?? [];
    $nsfwThreshold = (float)($scannerCfg['nsfw_threshold'] ?? 0.7);

    $logger('Starte Scan: ' . sv_safe_path_label($scanPath) . ($limit !== null ? " (limit={$limit})" : ''));

    $result = sv_run_scan_path(
        $scanPath,
        $pdo,
        $pathsCfg,
        $scannerCfg,
        $nsfwThreshold,
        $logger,
        $limit,
        $cancelCheck
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

    $sql = "SELECT m.id, m.path, m.type, (SELECT p2.id FROM prompts p2 WHERE p2.media_id = m.id ORDER BY p2.id DESC LIMIT 1) AS prompt_id "
         . "FROM media m "
         . "WHERE m.status = 'active' AND m.type = 'image' "
         . "AND NOT EXISTS (SELECT 1 FROM prompts p2 WHERE p2.media_id = m.id "
         . "AND p2.prompt IS NOT NULL AND p2.negative_prompt IS NOT NULL AND p2.source_metadata IS NOT NULL "
         . "AND p2.model IS NOT NULL AND p2.sampler IS NOT NULL AND p2.cfg_scale IS NOT NULL AND p2.steps IS NOT NULL "
         . "AND p2.seed IS NOT NULL AND p2.width IS NOT NULL AND p2.height IS NOT NULL AND p2.scheduler IS NOT NULL) "
         . "ORDER BY m.id ASC";

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

        try {
            if (is_file($path)) {
                $metadata = sv_extract_metadata($path, $type, 'prompts_rebuild', $logger);
            } else {
                $metadata = sv_fetch_prompt_snapshot($pdo, $mediaId);
                if ($metadata === null) {
                    $logger('  -> Datei nicht gefunden und kein Snapshot vorhanden, übersprungen.');
                    $skipped++;
                    continue;
                }
                $logger('  -> Nutze gespeicherten Prompt-Snapshot.');
            }
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

    try {
        if (is_file($path)) {
            $metadata = sv_extract_metadata($path, $type, 'prompts_rebuild_single', $logger);
        } else {
            $metadata = sv_fetch_prompt_snapshot($pdo, $mediaId);
            if ($metadata === null) {
                $logger('  -> Datei nicht gefunden und kein Snapshot vorhanden, Status prüfen (Filesync?).');
                return [
                    'processed' => 0,
                    'skipped'   => 1,
                    'errors'    => 0,
                ];
            }
            $logger('  -> Nutze gespeicherten Prompt-Snapshot.');
        }
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

    sv_set_media_lifecycle_status($pdo, $mediaId, SV_LIFECYCLE_PENDING_DELETE, 'missing flag set', 'internal', $logger);

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

function sv_delete_media_hard(PDO $pdo, array $config, int $mediaId, callable $logger): array
{
    $stmt = $pdo->prepare('SELECT * FROM media WHERE id = ?');
    $stmt->execute([$mediaId]);
    $media = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$media) {
        throw new RuntimeException('Medium nicht gefunden.');
    }

    $deletedFiles = [];
    $missingFiles = [];
    $errors       = [];

    $deleteFile = function (string $path, string $context, bool $allowPreviews = false, bool $allowBackups = false, bool $allowTmp = false) use (&$deletedFiles, &$missingFiles, &$errors, $config): void {
        if ($path === '') {
            return;
        }
        if (!is_file($path)) {
            $missingFiles[] = $path;
            return;
        }
        try {
            if ($allowPreviews || $allowBackups || $allowTmp) {
                sv_assert_stream_path_allowed($path, $config, $context, $allowPreviews, $allowBackups, $allowTmp);
            } else {
                sv_assert_media_path_allowed($path, $config['paths'] ?? [], $context);
            }
            if (@unlink($path)) {
                $deletedFiles[] = $path;
                return;
            }
            $errors[] = 'Datei konnte nicht gelöscht werden: ' . $path;
        } catch (Throwable $e) {
            $errors[] = 'Delete-Fehler (' . $context . '): ' . $e->getMessage();
        }
    };

    $primaryPath = (string)($media['path'] ?? '');
    $deleteFile($primaryPath, 'hard_delete_media');

    $previewDir = null;
    try {
        $previewDir = sv_resolve_preview_dir($config);
    } catch (Throwable $e) {
        $previewDir = null;
    }
    if ($previewDir !== null) {
        $pattern = rtrim($previewDir, '/') . '/preview_' . $mediaId . '_*';
        foreach (glob($pattern) ?: [] as $previewFile) {
            $deleteFile((string)$previewFile, 'hard_delete_preview', true, false, false);
        }
    }

    $cacheThumb = sv_base_dir() . '/CACHE/thumbs/video/' . $mediaId . '.jpg';
    if (is_file($cacheThumb)) {
        if (@unlink($cacheThumb)) {
            $deletedFiles[] = $cacheThumb;
        } else {
            $errors[] = 'Cache-Thumb konnte nicht gelöscht werden: ' . $cacheThumb;
        }
    }

    $jobStmt = $pdo->prepare('SELECT id, forge_response_json FROM jobs WHERE media_id = :id');
    $jobStmt->execute([':id' => $mediaId]);
    $jobs = $jobStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($jobs as $job) {
        $responseRaw = (string)($job['forge_response_json'] ?? '');
        if ($responseRaw === '') {
            continue;
        }
        $response = json_decode($responseRaw, true);
        if (!is_array($response)) {
            continue;
        }
        $result = is_array($response['result'] ?? null) ? $response['result'] : [];
        foreach (['preview_path', 'backup_path', 'output_path'] as $key) {
            if (!empty($result[$key]) && is_string($result[$key])) {
                $deleteFile((string)$result[$key], 'hard_delete_job_asset', true, true, true);
            }
        }
    }

    $pdo->beginTransaction();
    try {
        $deleteMap = [
            'media_meta' => 'DELETE FROM media_meta WHERE media_id = ?',
            'media_tags' => 'DELETE FROM media_tags WHERE media_id = ?',
            'scan_results' => 'DELETE FROM scan_results WHERE media_id = ?',
            'prompts' => 'DELETE FROM prompts WHERE media_id = ?',
            'prompt_history' => 'DELETE FROM prompt_history WHERE media_id = ?',
            'jobs' => 'DELETE FROM jobs WHERE media_id = ?',
            'media_lifecycle_events' => 'DELETE FROM media_lifecycle_events WHERE media_id = ?',
            'collection_media' => 'DELETE FROM collection_media WHERE media_id = ?',
        ];
        foreach ($deleteMap as $label => $sql) {
            try {
                $del = $pdo->prepare($sql);
                $del->execute([$mediaId]);
            } catch (Throwable $e) {
                $errors[] = 'DB-Delete-Fehler (' . $label . '): ' . $e->getMessage();
            }
        }

        $mediaDelete = $pdo->prepare('DELETE FROM media WHERE id = ?');
        $mediaDelete->execute([$mediaId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    sv_audit_log($pdo, 'media_hard_delete', 'media', $mediaId, [
        'deleted_files' => count($deletedFiles),
        'missing_files' => count($missingFiles),
        'errors'        => $errors,
    ]);

    return [
        'deleted_files' => $deletedFiles,
        'missing_files' => $missingFiles,
        'errors'        => $errors,
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

    $deleteTags = $pdo->prepare('DELETE FROM media_tags WHERE confidence IS NULL AND locked = 0');
    $deleteTags->execute();
    $changes['tag_rows_removed'] = (int)$deleteTags->rowCount();
    if ($changes['tag_rows_removed'] > 0) {
        $logLine('Tag-Zuordnungen ohne Confidence entfernt (locked ausgenommen): ' . $changes['tag_rows_removed']);
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

function sv_run_consistency_checks(PDO $pdo, callable $logFinding, string $repairMode): void
{
    sv_check_orphans($pdo, $logFinding, $repairMode);
    sv_check_duplicate_hashes($pdo, $logFinding);
    sv_check_missing_files($pdo, $logFinding, $repairMode);
    sv_check_tag_lock_conflicts($pdo, $logFinding);
    sv_check_duplicate_media_tags($pdo, $logFinding);
    sv_check_scan_metadata_gaps($pdo, $logFinding);
    sv_check_scan_missing_links($pdo, $logFinding);
    sv_check_job_state_gaps($pdo, $logFinding);
    sv_check_prompt_history_links($pdo, $logFinding, $repairMode);
}

function sv_collect_consistency_findings(PDO $pdo): array
{
    $findings = [];
    $collector = static function (string $checkName, string $severity, string $message) use (&$findings): void {
        $findings[] = [
            'check'    => $checkName,
            'severity' => $severity,
            'message'  => $message,
        ];
    };

    sv_run_consistency_checks($pdo, $collector, 'report');

    return $findings;
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

    sv_run_consistency_checks($pdo, $logFinding, $repairMode);

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
        'SELECT mt.media_id, mt.tag_id, mt.locked FROM media_tags mt ' .
        'LEFT JOIN media m ON m.id = mt.media_id ' .
        'LEFT JOIN tags t ON t.id = mt.tag_id ' .
        'WHERE m.id IS NULL OR t.id IS NULL'
    );
    $mtRows = $mtStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($mtRows as $row) {
        $suffix = ((int)($row['locked'] ?? 0) === 1) ? ' (locked)' : '';
        $logFinding('media_tags_orphan', 'warn', 'Media_ID=' . $row['media_id'] . ', Tag_ID=' . $row['tag_id'] . $suffix);
    }

    if ($repairMode === 'simple' && !empty($mtRows)) {
        $deleteStmt = $pdo->prepare('DELETE FROM media_tags WHERE media_id = ? AND tag_id = ? AND locked = 0');
        foreach ($mtRows as $row) {
            if ((int)($row['locked'] ?? 0) === 1) {
                $logFinding('media_tags_orphan', 'info', 'locked übersprungen: Media_ID=' . $row['media_id'] . ', Tag_ID=' . $row['tag_id']);
                continue;
            }
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

function sv_check_tag_lock_conflicts(PDO $pdo, callable $logFinding): void
{
    $stmt = $pdo->query(
        'SELECT media_id, tag_id, MIN(locked) AS min_locked, MAX(locked) AS max_locked, COUNT(*) AS cnt '
        . 'FROM media_tags GROUP BY media_id, tag_id HAVING cnt > 1 AND min_locked <> max_locked'
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $logFinding(
            'media_tags_lock_conflict',
            'warn',
            'Media_ID=' . $row['media_id'] . ', Tag_ID=' . $row['tag_id'] . ', Rows=' . $row['cnt']
        );
    }
}

function sv_check_duplicate_media_tags(PDO $pdo, callable $logFinding): void
{
    $stmt = $pdo->query(
        'SELECT media_id, tag_id, COUNT(*) AS cnt, SUM(CASE WHEN locked = 1 THEN 1 ELSE 0 END) AS locked_cnt '
        . 'FROM media_tags GROUP BY media_id, tag_id HAVING cnt > 1'
    );
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $lockedCnt  = (int)($row['locked_cnt'] ?? 0);
        $unlocked   = ((int)($row['cnt'] ?? 0)) - $lockedCnt;
        $logFinding(
            $lockedCnt > 0 && $unlocked > 0 ? 'media_tags_locked_mix' : 'media_tags_duplicate',
            'warn',
            'Media_ID=' . $row['media_id'] . ', Tag_ID=' . $row['tag_id'] . ', Rows=' . $row['cnt']
                . ' (locked=' . $lockedCnt . ', unlocked=' . $unlocked . ')'
        );
    }
}

function sv_check_scan_metadata_gaps(PDO $pdo, callable $logFinding): void
{
    $stmt = $pdo->query(
        "SELECT id, media_id, scanner, run_at FROM scan_results "
        . "WHERE run_at IS NULL OR TRIM(run_at) = '' OR scanner IS NULL OR TRIM(scanner) = '' "
        . "ORDER BY id ASC LIMIT 500"
    );
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $logFinding(
            'scan_missing_run_at',
            'warn',
            'Scan_ID=' . $row['id'] . ', Media_ID=' . $row['media_id'] . ', Scanner=' . ($row['scanner'] ?? '<leer>')
        );
    }
}

function sv_check_scan_missing_links(PDO $pdo, callable $logFinding): void
{
    $stmt = $pdo->query(
        'SELECT m.id FROM media m LEFT JOIN scan_results s ON s.media_id = m.id WHERE s.id IS NULL LIMIT 200'
    );
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $logFinding('scan_missing_latest', 'warn', 'Media_ID=' . $row['id'] . ' ohne Scan-Ergebnis');
    }
}

function sv_check_job_state_gaps(PDO $pdo, callable $logFinding): void
{
    $allowedStatuses = ['queued', 'pending', 'created', 'running', 'done', 'error', 'canceled'];
    $stmt = $pdo->query(
        'SELECT id, type, status, created_at, updated_at, error_message, forge_response_json FROM jobs ORDER BY id DESC LIMIT 500'
    );

    $now = time();
    $stuckThreshold = $now - (SV_JOB_STUCK_MINUTES * 60);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $status     = (string)($row['status'] ?? '');
        $createdAt  = (string)($row['created_at'] ?? '');
        $updatedAt  = (string)($row['updated_at'] ?? '');
        $createdTs  = $createdAt !== '' ? strtotime($createdAt) : null;
        $updatedTs  = $updatedAt !== '' ? strtotime($updatedAt) : null;
        $id         = (int)$row['id'];
        $type       = (string)($row['type'] ?? '');
        $errorMsg   = trim((string)($row['error_message'] ?? ''));
        $response   = json_decode((string)($row['forge_response_json'] ?? ''), true) ?: [];

        if (!in_array($status, $allowedStatuses, true)) {
            $logFinding('job_state_invalid', 'warn', 'Job #' . $id . ' (' . $type . ') mit unbekanntem Status: ' . $status);
        }

        if ($status !== 'queued' && $createdAt === '') {
            $logFinding('job_state_gap', 'warn', 'Job #' . $id . ' (' . $type . ') ohne created_at');
        }

        if (in_array($status, ['done', 'error', 'canceled'], true) && $updatedAt === '') {
            $logFinding('job_state_gap', 'warn', 'Job #' . $id . ' (' . $type . ') ohne updated_at');
        }

        if ($status === 'running' && $updatedTs !== null && $updatedTs < $stuckThreshold) {
            $logFinding('job_state_gap', 'warn', 'Job #' . $id . ' (' . $type . ') läuft seit ' . $row['updated_at'] . ' ohne Update');
        }

        if ($errorMsg !== '' && $status === 'running') {
            $logFinding('job_state_gap', 'info', 'Job #' . $id . ' (' . $type . ') running mit Error-Meldung: ' . $errorMsg);
        }

        if ($status === 'running' && $updatedAt === '') {
            $logFinding('job_state_gap', 'warn', 'Job #' . $id . ' (' . $type . ') running ohne Fortschritt/Timeout-Update');
        }

        if ($status === 'running' && empty($response['_sv_timeout_at']) && empty($response['progress'])) {
            $logFinding('job_state_gap', 'info', 'Job #' . $id . ' (' . $type . ') running ohne Progress-/Timeout-Indikator');
        }

        if ($status === 'error' && $errorMsg === '') {
            $logFinding('job_state_gap', 'warn', 'Job #' . $id . ' (' . $type . ') im Status error ohne Fehlermeldung');
        }
    }
}

function sv_check_prompt_history_links(PDO $pdo, callable $logFinding, string $repairMode): void
{
    $stmt = $pdo->query(
        'SELECT ph.id, ph.media_id, ph.prompt_id FROM prompt_history ph '
        . 'LEFT JOIN prompts p ON p.id = ph.prompt_id '
        . 'WHERE ph.prompt_id IS NOT NULL AND p.id IS NULL'
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $logFinding(
            'prompt_history_orphan',
            'warn',
            'History_ID=' . $row['id'] . ', Prompt_ID=' . $row['prompt_id'] . ', Media_ID=' . $row['media_id']
        );
    }
    if ($repairMode === 'simple' && !empty($rows)) {
        $fixStmt = $pdo->prepare('UPDATE prompt_history SET prompt_id = NULL WHERE id = ? AND prompt_id = ?');
        foreach ($rows as $row) {
            $fixStmt->execute([$row['id'], $row['prompt_id']]);
            $logFinding(
                'prompt_history_orphan',
                'info',
                'Prompt-Link entfernt: History_ID=' . $row['id'] . ', Prompt_ID=' . $row['prompt_id']
            );
        }
    }

    $mediaStmt = $pdo->query(
        'SELECT ph.id, ph.media_id FROM prompt_history ph '
        . 'LEFT JOIN media m ON m.id = ph.media_id WHERE m.id IS NULL LIMIT 200'
    );
    $mediaRows = $mediaStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($mediaRows as $row) {
        $logFinding(
            'prompt_history_media_missing',
            'warn',
            'History_ID=' . $row['id'] . ', Media_ID=' . $row['media_id'] . ' ohne gültiges Medium'
        );
    }
    if ($repairMode === 'simple' && !empty($mediaRows)) {
        $deleteStmt = $pdo->prepare('DELETE FROM prompt_history WHERE id = ?');
        foreach ($mediaRows as $row) {
            $deleteStmt->execute([$row['id']]);
            $logFinding(
                'prompt_history_media_missing',
                'info',
                'History gelöscht (Media fehlt): History_ID=' . $row['id'] . ', Media_ID=' . $row['media_id']
            );
        }
    }

    $versionRows = $pdo->query(
        'SELECT id, media_id, created_at FROM prompt_history WHERE version IS NULL OR version <= 0 ORDER BY media_id ASC, created_at ASC, id ASC LIMIT 500'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($versionRows as $row) {
        $logFinding(
            'prompt_history_version_gap',
            'warn',
            'History_ID=' . $row['id'] . ', Media_ID=' . $row['media_id'] . ' ohne gültige Versionsnummer'
        );
    }
    if ($repairMode === 'simple' && !empty($versionRows)) {
        $maxStmt = $pdo->prepare('SELECT COALESCE(MAX(version), 0) FROM prompt_history WHERE media_id = ?');
        $updateStmt = $pdo->prepare('UPDATE prompt_history SET version = ? WHERE id = ?');
        $grouped = [];
        foreach ($versionRows as $row) {
            $grouped[(int)$row['media_id']][] = $row;
        }
        foreach ($grouped as $mediaId => $rowsForMedia) {
            $maxStmt->execute([$mediaId]);
            $nextVersion = (int)$maxStmt->fetchColumn();
            $nextVersion = max(0, $nextVersion) + 1;
            foreach ($rowsForMedia as $row) {
                $updateStmt->execute([$nextVersion, $row['id']]);
                $logFinding(
                    'prompt_history_version_gap',
                    'info',
                    'Version gesetzt: History_ID=' . $row['id'] . ', Media_ID=' . $mediaId . ' -> ' . $nextVersion
                );
                $nextVersion++;
            }
        }
    }
}

function sv_collect_health_snapshot(PDO $pdo, array $config = [], int $jobLimit = 5): array
{
    $jobLimit = max(1, min(50, $jobLimit));
    $findings = [];
    try {
        $findings = sv_collect_consistency_findings($pdo);
    } catch (Throwable $e) {
        $findings = [
            [
                'check'    => 'consistency_runner',
                'severity' => 'error',
                'message'  => 'Health-Checks fehlgeschlagen: ' . $e->getMessage(),
            ],
        ];
    }

    $issueByCheck    = [];
    $issueBySeverity = [];
    foreach ($findings as $finding) {
        $issueByCheck[$finding['check']] = ($issueByCheck[$finding['check']] ?? 0) + 1;
        $issueBySeverity[$finding['severity']] = ($issueBySeverity[$finding['severity']] ?? 0) + 1;
    }

    $mediaTotal = 0;
    $driver     = 'unknown';
    $redactedDsn = '<unbekannt>';
    try {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    } catch (Throwable $e) {
        $driver = 'unknown';
    }

    if (isset($config['db']['dsn']) && is_string($config['db']['dsn'])) {
        $redactedDsn = sv_db_redact_dsn((string)$config['db']['dsn']);
    }

    $pendingMigrations = [];
    $migrationError    = null;
    $dbDiff            = ['missing_tables' => [], 'missing_columns' => [], 'ok_tables' => []];
    try {
        $dbDiff = sv_db_diff_schema($pdo, $driver);
    } catch (Throwable $e) {
        $migrationError = 'Schema-Abgleich fehlgeschlagen: ' . $e->getMessage();
    }

    $migrationDir = realpath(__DIR__ . '/migrations');
    if ($migrationDir !== false && is_dir($migrationDir)) {
        try {
            $migrations = sv_db_load_migrations($migrationDir);
            $applied    = sv_db_load_applied_versions($pdo);
            foreach ($migrations as $migration) {
                if (!isset($applied[$migration['version']])) {
                    $pendingMigrations[] = $migration['version'];
                }
            }
        } catch (Throwable $e) {
            $migrationError = 'Migrationsstand nicht lesbar: ' . $e->getMessage();
        }
    } else {
        $migrationError = 'Migrationsverzeichnis fehlt.';
    }

    try {
        $mediaTotal = (int)$pdo->query('SELECT COUNT(*) FROM media')->fetchColumn();
    } catch (Throwable $e) {
        $mediaTotal = 0;
    }

    $jobHealth = [
        'stuck_jobs'   => 0,
        'recent_jobs'  => [],
        'operations'   => [],
        'last_error'   => null,
    ];

    try {
        $types         = array_merge(SV_FORGE_JOB_TYPES, [SV_JOB_TYPE_SCAN_PATH, SV_JOB_TYPE_RESCAN_MEDIA, SV_JOB_TYPE_LIBRARY_RENAME]);
        $placeholder   = implode(',', array_fill(0, count($types), '?'));
        $stmt = $pdo->prepare(
            'SELECT id, media_id, type, status, created_at, updated_at, forge_request_json, forge_response_json, error_message '
            . 'FROM jobs WHERE type IN (' . $placeholder . ') ORDER BY id DESC LIMIT ?'
        );
        $idx = 1;
        foreach ($types as $type) {
            $stmt->bindValue($idx, $type, PDO::PARAM_STR);
            $idx++;
        }
        $stmt->bindValue($idx, $jobLimit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $payload  = json_decode((string)($row['forge_request_json'] ?? ''), true) ?: [];
            $response = json_decode((string)($row['forge_response_json'] ?? ''), true) ?: [];
            $jobHealth['recent_jobs'][] = [
                'id'          => (int)$row['id'],
                'type'        => (string)$row['type'],
                'status'      => (string)$row['status'],
                'media_id'    => (int)$row['media_id'],
                'started_at'  => (string)($row['created_at'] ?? ''),
                'finished_at' => (string)($row['updated_at'] ?? ''),
                'error'       => (string)($row['error_message'] ?? ($response['error'] ?? '')),
                'meta'        => [
                    'path'          => $payload['path'] ?? null,
                    'limit'         => $payload['limit'] ?? null,
                    'scanner'       => $response['scanner'] ?? null,
                    'run_at'        => $response['run_at'] ?? null,
                    'result'        => $response['result'] ?? null,
                    'worker_pid'    => $response['_sv_worker_pid'] ?? null,
                    'worker_started'=> $response['_sv_worker_started_at'] ?? null,
                    'model'         => $payload['model'] ?? ($response['model'] ?? null),
                ],
            ];
        }

        $stuckStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM jobs WHERE status = 'running' AND (updated_at IS NULL OR updated_at < :threshold)"
        );
        $stuckStmt->execute([':threshold' => date('c', time() - (SV_JOB_STUCK_MINUTES * 60))]);
        $jobHealth['stuck_jobs'] = (int)$stuckStmt->fetchColumn();

        $lastErrorStmt = $pdo->query(
            "SELECT id, type, error_message, forge_response_json FROM jobs WHERE status = 'error' ORDER BY id DESC LIMIT 1"
        );
        $lastErrorRow = $lastErrorStmt ? $lastErrorStmt->fetch(PDO::FETCH_ASSOC) : null;
        if (is_array($lastErrorRow)) {
            $responseErr = json_decode((string)($lastErrorRow['forge_response_json'] ?? ''), true);
            $jobHealth['last_error'] = [
                'id'      => (int)$lastErrorRow['id'],
                'type'    => (string)$lastErrorRow['type'],
                'message' => (string)($lastErrorRow['error_message'] ?? ($responseErr['error'] ?? '')),
            ];
        }
    } catch (Throwable $e) {
        $jobHealth['recent_jobs'] = [];
        $jobHealth['stuck_jobs']  = 0;
    }

    try {
        $auditActions = ['scan_start', 'rescan_start', 'filesync_start'];
        $placeholders = implode(',', array_fill(0, count($auditActions), '?'));
        $auditStmt = $pdo->prepare(
            'SELECT action, details_json, created_at FROM audit_log WHERE action IN (' . $placeholders . ') '
            . 'ORDER BY id DESC LIMIT 10'
        );
        foreach ($auditActions as $i => $action) {
            $auditStmt->bindValue($i + 1, $action, PDO::PARAM_STR);
        }
        $auditStmt->execute();
        $auditRows = $auditStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($auditRows as $row) {
            $jobHealth['operations'][] = [
                'action'     => (string)$row['action'],
                'created_at' => (string)($row['created_at'] ?? ''),
                'details'    => json_decode((string)($row['details_json'] ?? ''), true) ?: [],
            ];
        }
    } catch (Throwable $e) {
        $jobHealth['operations'] = [];
    }

    $scanHealth = [
        'missing_run_at' => 0,
        'latest'         => [],
        'last_scan_time' => null,
        'scan_job_errors'=> 0,
    ];

    try {
        $scanHealth['missing_run_at'] = (int)$pdo
            ->query("SELECT COUNT(*) FROM scan_results WHERE run_at IS NULL OR TRIM(run_at) = ''")
            ->fetchColumn();

        $scanStmt = $pdo->prepare(
            'SELECT id, media_id, scanner, run_at, nsfw_score FROM scan_results ORDER BY id DESC LIMIT ?'
        );
        $scanStmt->bindValue(1, $jobLimit, PDO::PARAM_INT);
        $scanStmt->execute();
        $scanRows = $scanStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($scanRows as $row) {
            $scanHealth['latest'][] = [
                'id'        => (int)$row['id'],
                'media_id'  => (int)$row['media_id'],
                'scanner'   => (string)($row['scanner'] ?? ''),
                'run_at'    => (string)($row['run_at'] ?? ''),
                'nsfw_score'=> isset($row['nsfw_score']) ? (float)$row['nsfw_score'] : null,
            ];
        }

        $lastScanStmt = $pdo->query('SELECT MAX(run_at) AS last_run FROM scan_results');
        $scanHealth['last_scan_time'] = $lastScanStmt ? (string)$lastScanStmt->fetchColumn() : null;

        $scanTypes = sv_scan_job_types();
        $placeholders = implode(',', array_fill(0, count($scanTypes), '?'));
        $scanErrorStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM jobs WHERE type IN (" . $placeholders . ") AND status = 'error'"
        );
        foreach ($scanTypes as $idx => $type) {
            $scanErrorStmt->bindValue($idx + 1, $type, PDO::PARAM_STR);
        }
        $scanErrorStmt->execute();
        $scanHealth['scan_job_errors'] = (int)$scanErrorStmt->fetchColumn();
    } catch (Throwable $e) {
        $scanHealth['latest'] = [];
    }

    return [
        'db_health' => [
            'driver'            => $driver,
            'redacted_dsn'      => $redactedDsn,
            'pending_migrations'=> $pendingMigrations,
            'pending_migrations_count' => count($pendingMigrations),
            'migration_error'   => $migrationError,
            'schema_diff'       => $dbDiff,
            'media_total'       => $mediaTotal,
            'issues_total'      => count($findings),
            'issues_by_check'   => $issueByCheck,
            'issues_by_severity'=> $issueBySeverity,
        ],
        'job_health'  => $jobHealth,
        'scan_health' => $scanHealth,
    ];
}

function sv_dashboard_format_value($value, int $maxLen = 160): string
{
    if ($value === null) {
        return '';
    }
    if (is_numeric($value)) {
        return (string)$value;
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    return sv_sanitize_error_message((string)$value, $maxLen);
}

function sv_dashboard_sanitize_payload($value, int $maxLen = 160)
{
    if (is_array($value)) {
        $sanitized = [];
        foreach ($value as $key => $entry) {
            $sanitized[$key] = sv_dashboard_sanitize_payload($entry, $maxLen);
        }
        return $sanitized;
    }

    if (is_string($value)) {
        return sv_sanitize_error_message($value, $maxLen);
    }

    if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
        return $value;
    }

    return sv_sanitize_error_message((string)$value, $maxLen);
}

function sv_dashboard_read_runtime_json(string $path, int $maxLen = 160): ?array
{
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    return sv_dashboard_sanitize_payload($decoded, $maxLen);
}

function sv_dashboard_job_row(array $row): array
{
    $payload  = json_decode((string)($row['forge_request_json'] ?? ''), true) ?: [];
    $response = json_decode((string)($row['forge_response_json'] ?? ''), true) ?: [];
    $timing   = sv_extract_job_timestamps($row, $response);
    $stuck    = sv_job_stuck_info($row, $response, SV_JOB_STUCK_MINUTES);

    $error = $row['error_message'] ?? ($response['error'] ?? '');

    return [
        'id'          => (int)$row['id'],
        'media_id'    => isset($row['media_id']) ? (int)$row['media_id'] : null,
        'type'        => sv_dashboard_format_value($row['type'] ?? ''),
        'status'      => sv_dashboard_format_value($row['status'] ?? ''),
        'started_at'  => $timing['started_at'],
        'finished_at' => $timing['finished_at'],
        'age_label'   => $timing['age_label'],
        'stuck'       => (bool)($stuck['stuck'] ?? false),
        'stuck_reason'=> sv_dashboard_format_value($stuck['reason'] ?? ''),
        'error'       => sv_dashboard_format_value($error, 180),
        'model'       => sv_dashboard_format_value($payload['model'] ?? ($response['model'] ?? ''), 80),
    ];
}

function sv_fetch_dashboard_jobs(PDO $pdo, array $filters = [], int $limit = 8): array
{
    $limit = max(1, min(50, $limit));
    $where  = [];
    $params = [];

    if (!empty($filters['statuses']) && is_array($filters['statuses'])) {
        $statuses = array_values(array_filter(array_map('strval', $filters['statuses']), static fn ($s) => $s !== ''));
        if ($statuses !== []) {
            $placeholders = implode(',', array_fill(0, count($statuses), '?'));
            $where[] = 'status IN (' . $placeholders . ')';
            foreach ($statuses as $status) {
                $params[] = $status;
            }
        }
    } elseif (!empty($filters['status']) && is_string($filters['status'])) {
        $where[] = 'status = ?';
        $params[] = trim($filters['status']);
    }

    if (!empty($filters['types']) && is_array($filters['types'])) {
        $types = array_values(array_filter(array_map('strval', $filters['types']), static fn ($t) => $t !== ''));
        if ($types !== []) {
            $placeholders = implode(',', array_fill(0, count($types), '?'));
            $where[] = 'type IN (' . $placeholders . ')';
            foreach ($types as $type) {
                $params[] = $type;
            }
        }
    }

    $sql = 'SELECT id, media_id, type, status, created_at, updated_at, forge_request_json, forge_response_json, error_message FROM jobs';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY id DESC LIMIT ' . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $jobs = [];
    foreach ($rows as $row) {
        $jobs[] = sv_dashboard_job_row($row);
    }

    if (!empty($filters['stuck_only'])) {
        $jobs = array_values(array_filter($jobs, static fn ($job) => !empty($job['stuck'])));
    }

    return $jobs;
}

function sv_dashboard_event_summary(array $details, int $maxLen = 160): string
{
    $details = sv_sanitize_audit_details($details);
    $allowList = [
        'job_id',
        'media_id',
        'prompt_id',
        'limit',
        'offset',
        'mode',
        'status',
        'count',
        'found',
        'processed',
        'skipped',
        'errors',
        'worker_pid',
        'worker_note',
    ];

    $parts = [];
    foreach ($allowList as $key) {
        if (!array_key_exists($key, $details)) {
            continue;
        }
        $value = $details[$key];
        if (is_array($value)) {
            $nestedParts = [];
            foreach ($value as $subKey => $subValue) {
                if (is_numeric($subValue)) {
                    $nestedParts[] = $subKey . '=' . (string)$subValue;
                }
            }
            if ($nestedParts !== []) {
                $parts[] = $key . ': ' . implode(', ', $nestedParts);
            }
        } else {
            $parts[] = $key . ': ' . sv_dashboard_format_value($value, 80);
        }
    }

    $summary = implode(' · ', $parts);
    if ($summary === '') {
        return '';
    }

    return sv_dashboard_format_value($summary, $maxLen);
}

function sv_collect_dashboard_view_model(PDO $pdo, array $config, array $options = []): array
{
    $jobLimit    = isset($options['job_limit']) ? (int)$options['job_limit'] : 8;
    $eventLimit  = isset($options['event_limit']) ? (int)$options['event_limit'] : 8;
    $healthLimit = isset($options['health_limit']) ? (int)$options['health_limit'] : 6;
    $knownActions = isset($options['known_actions']) && is_array($options['known_actions'])
        ? array_values(array_filter(array_map('strval', $options['known_actions']), static fn ($a) => $a !== ''))
        : [];

    $jobLimit = max(1, min(20, $jobLimit));
    $eventLimit = max(1, min(30, $eventLimit));
    $healthLimit = max(1, min(20, $healthLimit));

    $health = sv_collect_health_snapshot($pdo, $config, $healthLimit);

    $jobCounts = [
        'queued'  => 0,
        'running' => 0,
        'done'    => 0,
        'error'   => 0,
    ];
    try {
        $stmt = $pdo->query("SELECT status, COUNT(*) AS cnt FROM jobs WHERE status IN ('queued','running','done','error') GROUP BY status");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $row) {
            $status = (string)($row['status'] ?? '');
            if ($status !== '' && isset($jobCounts[$status])) {
                $jobCounts[$status] = (int)$row['cnt'];
            }
        }
    } catch (Throwable $e) {
        $jobCounts = [
            'queued'  => 0,
            'running' => 0,
            'done'    => 0,
            'error'   => 0,
        ];
    }

    $jobsRunning = sv_fetch_dashboard_jobs($pdo, ['statuses' => ['running']], $jobLimit);
    $jobsQueued  = sv_fetch_dashboard_jobs($pdo, ['statuses' => ['queued']], $jobLimit);
    $jobsStuck   = sv_fetch_dashboard_jobs($pdo, ['statuses' => ['running'], 'stuck_only' => true], $jobLimit);
    $jobsRecent  = sv_fetch_dashboard_jobs($pdo, ['statuses' => ['done', 'error']], $jobLimit);

    $events = [];
    try {
        $stmt = $pdo->prepare('SELECT action, details_json, created_at FROM audit_log ORDER BY id DESC LIMIT ?');
        $stmt->bindValue(1, $eventLimit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $details = json_decode((string)($row['details_json'] ?? ''), true) ?: [];
            $events[] = [
                'action'     => sv_dashboard_format_value($row['action'] ?? '', 80),
                'created_at' => sv_dashboard_format_value($row['created_at'] ?? '', 80),
                'summary'    => sv_dashboard_event_summary($details, 160),
            ];
        }
    } catch (Throwable $e) {
        $events = [];
    }

    $lastRuns = [];
    if ($knownActions !== []) {
        try {
            $placeholders = implode(', ', array_fill(0, count($knownActions), '?'));
            $stmt = $pdo->prepare(
                "SELECT action, MAX(created_at) AS last_at FROM audit_log WHERE action IN ($placeholders) GROUP BY action"
            );
            $stmt->execute($knownActions);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $actionKey = (string)($row['action'] ?? '');
                if ($actionKey !== '') {
                    $lastRuns[$actionKey] = (string)($row['last_at'] ?? '');
                }
            }
        } catch (Throwable $e) {
            $lastRuns = [];
        }
    }

    $forgeOverview = [];
    try {
        $forgeOverview = sv_forge_job_overview($pdo);
    } catch (Throwable $e) {
        $forgeOverview = [];
    }

    $logDir = sv_logs_root($config);
    $gitStatus = sv_dashboard_read_runtime_json($logDir . '/git_status.json', 160);
    $gitLast   = sv_dashboard_read_runtime_json($logDir . '/git_update.last.json', 200);

    return [
        'health' => $health,
        'job_counts' => $jobCounts,
        'jobs' => [
            'running' => $jobsRunning,
            'queued'  => $jobsQueued,
            'stuck'   => $jobsStuck,
            'recent'  => $jobsRecent,
        ],
        'events' => $events,
        'last_runs' => $lastRuns,
        'forge' => $forgeOverview,
        'git' => [
            'status' => $gitStatus,
            'last'   => $gitLast,
        ],
    ];
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../db_helpers.php';
require_once __DIR__ . '/../status.php';
require_once __DIR__ . '/../operations.php';
require_once __DIR__ . '/../ollama_jobs.php';
require_once __DIR__ . '/ollama_analyze_image.php';

const SV_JOB_TYPE_OLLAMA_ANALYZE = 'ollama_analyze';

function sv_ollama_store_result(PDO $pdo, int $mediaId, string $model, array $normalized, bool $parseError = false): void
{
    $title = isset($normalized['title']) ? (string)$normalized['title'] : null;
    $caption = isset($normalized['description']) ? (string)$normalized['description'] : null;
    $score = isset($normalized['quality_score']) ? (int)$normalized['quality_score'] : null;

    $rawJson = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($rawJson === false) {
        $rawJson = null;
    }

    $meta = json_encode([
        'source' => 'ollama_job_runner',
        'mode' => 'analyze',
        'parse_error' => $parseError,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($meta === false) {
        $meta = null;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO ollama_results (media_id, mode, model, title, caption, score, contradictions, missing, rationale, raw_json, raw_text, parse_error, created_at, meta) '
        . 'VALUES (:media_id, :mode, :model, :title, :caption, :score, :contradictions, :missing, :rationale, :raw_json, :raw_text, :parse_error, :created_at, :meta)'
    );
    $stmt->execute([
        ':media_id' => $mediaId,
        ':mode' => 'analyze',
        ':model' => $model,
        ':title' => $title,
        ':caption' => $caption,
        ':score' => $score,
        ':contradictions' => null,
        ':missing' => null,
        ':rationale' => null,
        ':raw_json' => $rawJson,
        ':raw_text' => null,
        ':parse_error' => $parseError ? 1 : 0,
        ':created_at' => date('c'),
        ':meta' => $meta,
    ]);
}

function sv_ollama_job_running_exists(PDO $pdo): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM jobs WHERE type = :type AND status = "running" LIMIT 1');
    $stmt->execute([':type' => SV_JOB_TYPE_OLLAMA_ANALYZE]);

    return (bool)$stmt->fetchColumn();
}

function sv_ollama_fetch_next_job(PDO $pdo): ?array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM jobs WHERE type = :type AND status = "pending" ORDER BY id ASC LIMIT 1'
    );
    $stmt->execute([':type' => SV_JOB_TYPE_OLLAMA_ANALYZE]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }

    return $row;
}

function sv_ollama_analyze_lock_path(array $config): string
{
    return sv_log_path($config, 'ollama_analyze_runner.lock');
}

function sv_ollama_acquire_analyze_lock(array $config): array
{
    $logsError = null;
    $logsRoot = sv_ensure_logs_root($config, $logsError);
    if ($logsRoot === null) {
        sv_log_system_error($config, 'ollama_analyze_log_root_unavailable', ['error' => $logsError]);
        return [
            'ok' => false,
            'handle' => null,
            'path' => null,
            'reason' => 'log_root_unavailable',
        ];
    }

    $lockPath = sv_ollama_analyze_lock_path($config);
    $handle = fopen($lockPath, 'c+');
    if ($handle === false) {
        sv_log_system_error($config, 'ollama_analyze_lock_open_failed', ['path' => $lockPath]);
        return [
            'ok' => false,
            'handle' => null,
            'path' => $lockPath,
            'reason' => 'open_failed',
        ];
    }

    $locked = flock($handle, LOCK_EX | LOCK_NB);
    if (!$locked) {
        fclose($handle);
        return [
            'ok' => false,
            'handle' => null,
            'path' => $lockPath,
            'reason' => 'locked',
        ];
    }

    $nowUtc = gmdate('c');
    $payload = [
        'pid' => function_exists('getmypid') ? (int)getmypid() : null,
        'started_at_utc' => $nowUtc,
        'last_heartbeat_utc' => $nowUtc,
        'service' => 'ollama_job_runner',
    ];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json !== false) {
        ftruncate($handle, 0);
        fwrite($handle, $json);
        fflush($handle);
    }

    return [
        'ok' => true,
        'handle' => $handle,
        'path' => $lockPath,
        'reason' => null,
    ];
}

function sv_ollama_release_analyze_lock($handle): void
{
    if (!is_resource($handle)) {
        return;
    }
    flock($handle, LOCK_UN);
    fclose($handle);
}

function sv_ollama_claim_analyze_job(PDO $pdo): ?array
{
    $hasNotBefore = sv_ollama_job_has_column($pdo, 'not_before');
    $sql = 'SELECT * FROM jobs WHERE type = :type AND status IN ("pending", "queued")';
    if ($hasNotBefore) {
        $sql .= ' AND (not_before IS NULL OR not_before <= :now)';
    }
    $sql .= ' ORDER BY id ASC LIMIT 1';

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare($sql);
        $params = [':type' => SV_JOB_TYPE_OLLAMA_ANALYZE];
        if ($hasNotBefore) {
            $params[':now'] = date('c');
        }
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            $pdo->commit();
            return null;
        }

        $jobId = (int)($row['id'] ?? 0);
        if ($jobId <= 0) {
            $pdo->commit();
            return null;
        }

        $now = date('c');
        $updateSql = 'UPDATE jobs SET status = "running", updated_at = :updated_at, heartbeat_at = :heartbeat_at';
        if ($hasNotBefore) {
            $updateSql .= ', not_before = NULL';
        }
        $updateSql .= ' WHERE id = :id AND status IN ("pending", "queued")';
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute([
            ':updated_at' => $now,
            ':heartbeat_at' => $now,
            ':id' => $jobId,
        ]);

        if ($updateStmt->rowCount() === 0) {
            $pdo->rollBack();
            return null;
        }

        $pdo->commit();
        $row['status'] = 'running';
        $row['heartbeat_at'] = $now;
        return $row;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function sv_ollama_analyze_cancel_requested(PDO $pdo, int $jobId): bool
{
    $stmt = $pdo->prepare('SELECT cancel_requested FROM jobs WHERE id = :id');
    $stmt->execute([':id' => $jobId]);
    $value = $stmt->fetchColumn();
    return (int)$value === 1;
}

function sv_ollama_analyze_mark_cancelled(PDO $pdo, int $jobId, array $payload, string $reason): void
{
    $payload['cancelled_at'] = date('c');
    $payload['cancel_reason'] = $reason;
    sv_update_job_status(
        $pdo,
        $jobId,
        SV_JOB_STATUS_CANCELLED,
        json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        $reason
    );
    $stmt = $pdo->prepare('UPDATE jobs SET cancelled_at = :cancelled_at, updated_at = :updated_at WHERE id = :id');
    $now = date('c');
    $stmt->execute([
        ':cancelled_at' => $now,
        ':updated_at' => $now,
        ':id' => $jobId,
    ]);
}

function sv_ollama_analyze_retry_or_fail(PDO $pdo, array $config, int $jobId, array $response, string $message, string $errorCode): array
{
    $ollamaCfg = sv_ollama_config($config);
    $maxAttempts = (int)($ollamaCfg['retry']['max_attempts'] ?? 3);
    $attempts = isset($response['_sv_attempts']) ? (int)$response['_sv_attempts'] : 0;
    $attempts++;
    $response['_sv_attempts'] = $attempts;
    $response['_sv_error_code'] = $errorCode;
    $response['_sv_error_message'] = $message;
    $response['_sv_last_attempt_at'] = date('c');

    if ($attempts <= $maxAttempts) {
        $delaySeconds = sv_ollama_retry_backoff_seconds($config, $attempts);
        $retryAt = date('c', time() + $delaySeconds);
        $response['_sv_retry_not_before'] = $retryAt;
        sv_update_job_status(
            $pdo,
            $jobId,
            'queued',
            json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $message
        );
        if (sv_ollama_job_has_column($pdo, 'not_before')) {
            $stmt = $pdo->prepare('UPDATE jobs SET not_before = :not_before WHERE id = :id');
            $stmt->execute([
                ':not_before' => $retryAt,
                ':id' => $jobId,
            ]);
        }
        return [
            'status' => 'retry',
            'message' => $message,
            'job_id' => $jobId,
            'retry_after_s' => $delaySeconds,
            'retry_not_before' => $retryAt,
        ];
    }

    sv_update_job_status(
        $pdo,
        $jobId,
        'error',
        json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        $message
    );

    return [
        'status' => 'error',
        'message' => $message,
        'job_id' => $jobId,
    ];
}

function sv_ollama_decode_payload(array $jobRow): array
{
    $payloadJson = null;
    if (array_key_exists('payload_json', $jobRow) && is_string($jobRow['payload_json'])) {
        $payloadJson = $jobRow['payload_json'];
    }
    if ($payloadJson === null && isset($jobRow['forge_request_json']) && is_string($jobRow['forge_request_json'])) {
        $payloadJson = $jobRow['forge_request_json'];
    }

    if (!is_string($payloadJson) || trim($payloadJson) === '') {
        return [];
    }

    $payload = json_decode($payloadJson, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
        return [];
    }

    return $payload;
}

function sv_process_ollama_analyze_job(PDO $pdo, array $config): array
{
    $lock = sv_ollama_acquire_analyze_lock($config);
    if (empty($lock['ok'])) {
        return [
            'status' => 'skipped',
            'message' => 'Ollama-Analyze Runner bereits aktiv (Lock).',
            'reason' => $lock['reason'] ?? 'lock_failed',
        ];
    }

    $jobRow = null;
    try {
        sv_mark_stuck_jobs($pdo, [SV_JOB_TYPE_OLLAMA_ANALYZE], SV_JOB_STUCK_MINUTES);

        $jobRow = sv_ollama_claim_analyze_job($pdo);
        if ($jobRow === null) {
            return [
                'status' => 'empty',
                'message' => 'Keine Ollama-Analyze Jobs vorhanden.',
            ];
        }

        $jobId = (int)($jobRow['id'] ?? 0);
        if ($jobId <= 0) {
            return [
                'status' => 'error',
                'message' => 'Ungültige Job-ID.',
            ];
        }

        $payload = sv_ollama_decode_payload($jobRow);
        $mediaId = (int)($payload['media_id'] ?? $jobRow['media_id'] ?? 0);
        $modelOverride = isset($payload['model']) && is_string($payload['model']) ? $payload['model'] : null;

        if ($mediaId <= 0) {
            return sv_ollama_analyze_retry_or_fail($pdo, $config, $jobId, $payload, 'Media-ID fehlt.', 'missing_media_id');
        }

        if (sv_ollama_analyze_cancel_requested($pdo, $jobId)) {
            sv_ollama_analyze_mark_cancelled($pdo, $jobId, [
                'media_id' => $mediaId,
                'job_type' => SV_JOB_TYPE_OLLAMA_ANALYZE,
            ], 'cancel_requested');
            return [
                'status' => 'cancelled',
                'message' => 'Ollama-Analyze Job abgebrochen.',
                'job_id' => $jobId,
                'media_id' => $mediaId,
            ];
        }

        sv_update_job_status($pdo, $jobId, 'running', json_encode([
            'media_id' => $mediaId,
            'job_type' => SV_JOB_TYPE_OLLAMA_ANALYZE,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $result = sv_ollama_analyze_image($pdo, $config, $mediaId, $modelOverride);
        $modelUsed = $result['model'] ?? ($modelOverride ?? '');
        $normalized = $result['normalized'] ?? [];
        $parseError = !empty($result['parse_error']);

        if (!is_array($normalized)) {
            return sv_ollama_analyze_retry_or_fail($pdo, $config, $jobId, $payload, 'Ollama-Ergebnis konnte nicht normalisiert werden.', 'normalize_failed');
        }

        sv_ollama_store_result($pdo, $mediaId, (string)$modelUsed, $normalized, $parseError);

        $responseJson = json_encode([
            'media_id' => $mediaId,
            'model' => (string)$modelUsed,
            'result' => $normalized,
            'parse_error' => $parseError,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        sv_update_job_status($pdo, $jobId, 'done', $responseJson, null);

        return [
            'status' => 'done',
            'message' => 'Ollama-Analyze Job abgeschlossen.',
            'job_id' => $jobId,
            'media_id' => $mediaId,
        ];
    } catch (Throwable $e) {
        $payload = ['job_type' => SV_JOB_TYPE_OLLAMA_ANALYZE];
        $jobId = isset($jobRow['id']) ? (int)$jobRow['id'] : 0;
        if ($jobId > 0) {
            return sv_ollama_analyze_retry_or_fail($pdo, $config, $jobId, $payload, $e->getMessage(), 'exception');
        }

        return [
            'status' => 'error',
            'message' => $e->getMessage(),
        ];
    } finally {
        sv_ollama_release_analyze_lock($lock['handle'] ?? null);
    }
}

if (PHP_SAPI === 'cli' && isset($argv) && basename($argv[0]) === basename(__FILE__)) {
    $baseDir = sv_base_dir();
    $config = sv_load_config($baseDir);
    $db = sv_db_connect($config);

    $result = sv_process_ollama_analyze_job($db['pdo'], $config);
    $status = $result['status'] ?? 'unknown';
    $message = $result['message'] ?? '';

    fwrite(STDOUT, '[' . $status . '] ' . $message . PHP_EOL);
    exit($status === 'done' || $status === 'empty' || $status === 'skipped' ? 0 : 1);
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../db_helpers.php';
require_once __DIR__ . '/../operations.php';
require_once __DIR__ . '/ollama_analyze_image.php';

const SV_JOB_TYPE_OLLAMA_ANALYZE = 'ollama_analyze';

function sv_ollama_store_result(PDO $pdo, int $mediaId, string $model, array $normalized): void
{
    $resultJson = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($resultJson === false) {
        throw new RuntimeException('Ollama-Ergebnis konnte nicht serialisiert werden.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO ollama_results (media_id, model, result_json, created_at) VALUES (:media_id, :model, :result_json, :created_at)'
    );
    $stmt->execute([
        ':media_id' => $mediaId,
        ':model' => $model,
        ':result_json' => $resultJson,
        ':created_at' => date('c'),
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
        'SELECT * FROM jobs WHERE type = :type AND status = "queued" ORDER BY id ASC LIMIT 1'
    );
    $stmt->execute([':type' => SV_JOB_TYPE_OLLAMA_ANALYZE]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }

    return $row;
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
    if (sv_ollama_job_running_exists($pdo)) {
        return [
            'status' => 'skipped',
            'message' => 'Ollama-Analyze Job läuft bereits.',
        ];
    }

    $jobRow = sv_ollama_fetch_next_job($pdo);
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
        sv_update_job_status($pdo, $jobId, 'error', null, 'Media-ID fehlt.');
        return [
            'status' => 'error',
            'message' => 'Media-ID fehlt.',
        ];
    }

    sv_update_job_status($pdo, $jobId, 'running', json_encode([
        'media_id' => $mediaId,
        'job_type' => SV_JOB_TYPE_OLLAMA_ANALYZE,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    try {
        $result = sv_ollama_analyze_image($pdo, $config, $mediaId, $modelOverride);
        $modelUsed = $result['model'] ?? ($modelOverride ?? '');
        $normalized = $result['normalized'] ?? [];

        if (!is_array($normalized)) {
            throw new RuntimeException('Ollama-Ergebnis konnte nicht normalisiert werden.');
        }

        sv_ollama_store_result($pdo, $mediaId, (string)$modelUsed, $normalized);

        $responseJson = json_encode([
            'media_id' => $mediaId,
            'model' => (string)$modelUsed,
            'result' => $normalized,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        sv_update_job_status($pdo, $jobId, 'done', $responseJson, null);

        return [
            'status' => 'done',
            'message' => 'Ollama-Analyze Job abgeschlossen.',
            'job_id' => $jobId,
            'media_id' => $mediaId,
        ];
    } catch (Throwable $e) {
        sv_update_job_status($pdo, $jobId, 'error', null, $e->getMessage());

        return [
            'status' => 'error',
            'message' => $e->getMessage(),
            'job_id' => $jobId,
            'media_id' => $mediaId,
        ];
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

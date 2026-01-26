<?php
declare(strict_types=1);

require_once __DIR__ . '/../SCRIPTS/common.php';
require_once __DIR__ . '/../SCRIPTS/security.php';
require_once __DIR__ . '/../SCRIPTS/operations.php';
require_once __DIR__ . '/../SCRIPTS/ollama_jobs.php';
require_once __DIR__ . '/../SCRIPTS/jobs_admin.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'POST required.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $config = sv_load_config();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Config-Fehler.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

sv_require_internal_access($config, 'jobs_prune');

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = sv_open_pdo($config);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Keine DB-Verbindung.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$group = is_string($_POST['group'] ?? null) ? trim((string)$_POST['group']) : '';
$status = is_string($_POST['status'] ?? null) ? trim((string)$_POST['status']) : 'all';
$scope = is_string($_POST['scope'] ?? null) ? trim((string)$_POST['scope']) : 'all';
$mediaId = isset($_POST['media_id']) ? (int)$_POST['media_id'] : null;
$force = isset($_POST['force']) && (int)$_POST['force'] === 1;
$dryRun = isset($_POST['dry_run']) && (int)$_POST['dry_run'] === 1;

$statusList = $status === 'all' ? 'all' : array_values(array_filter(array_map('trim', explode(',', $status))));

$jobTypes = [];
$typePrefix = null;
$note = null;

if ($group === 'ollama') {
    $typePrefix = 'ollama_';
    $jobTypes = sv_ollama_job_types();
} elseif ($group === 'scan') {
    $typePrefix = 'scan_';
    $jobTypes = sv_scan_job_types();
} elseif ($group === 'importscan') {
    $jobTypes = [];
    $typePrefix = null;
    $note = 'Keine Importscan-Jobtypen gefunden.';
} elseif ($group === 'custom') {
    $typePrefix = is_string($_POST['type_prefix'] ?? null) ? trim((string)$_POST['type_prefix']) : null;
    $jobTypes = isset($_POST['job_types']) && is_string($_POST['job_types'])
        ? array_values(array_filter(array_map('trim', explode(',', (string)$_POST['job_types']))))
        : [];
} else {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Ungültige Gruppe.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($note !== null) {
    echo json_encode([
        'ok' => true,
        'result' => [
            'matched_count' => 0,
            'deleted_count' => 0,
            'updated_count' => 0,
            'blocked_running_count' => 0,
            'dry_run' => $dryRun,
        ],
        'note' => $note,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $result = sv_jobs_prune($pdo, [
        'type_prefix' => $typePrefix,
        'job_types' => $jobTypes,
        'statuses' => $statusList,
        'scope' => $scope,
        'media_id' => $mediaId,
        'include_running' => $force,
        'force_running' => $force,
        'dry_run' => $dryRun,
    ]);

    echo json_encode([
        'ok' => true,
        'result' => $result,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => sv_sanitize_error_message($e->getMessage()),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

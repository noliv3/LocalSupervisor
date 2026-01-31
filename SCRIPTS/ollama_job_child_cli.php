<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Nur CLI erlaubt.\n");
    exit(1);
}

require_once __DIR__ . '/ollama_jobs.php';

$readArg = static function (string $name, array $argv): ?string {
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (strpos($arg, $prefix) === 0) {
            return substr($arg, strlen($prefix));
        }
    }
    return null;
};

$jobId = (int)($readArg('job-id', $argv) ?? 0);
$attemptArg = (int)($readArg('attempt', $argv) ?? 0);
$traceFileArg = $readArg('trace-file', $argv);
$owner = $readArg('owner', $argv);

if ($jobId <= 0) {
    echo json_encode([
        'ok' => false,
        'job_id' => $jobId,
        'status' => 'error',
        'error_code' => 'invalid_job_id',
        'error' => 'Invalid job id.',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}

try {
    $config = sv_load_config();
    $pdo = sv_open_pdo($config);
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'job_id' => $jobId,
        'status' => 'error',
        'error_code' => 'init_error',
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}

$jobRow = sv_ollama_fetch_job_row($pdo, $jobId);
if ($jobRow === []) {
    echo json_encode([
        'ok' => false,
        'job_id' => $jobId,
        'status' => 'error',
        'error_code' => 'job_not_found',
        'error' => 'Job not found.',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}

$payload = sv_ollama_decode_job_payload($jobRow);
$mode = isset($payload['mode']) && is_string($payload['mode']) && trim($payload['mode']) !== ''
    ? trim($payload['mode'])
    : sv_ollama_mode_for_job_type((string)($jobRow['type'] ?? ''));
$attempt = $attemptArg > 0 ? $attemptArg : sv_ollama_job_attempt_from_payload($payload);
$traceFile = is_string($traceFileArg) && trim($traceFileArg) !== ''
    ? $traceFileArg
    : sv_ollama_job_trace_file_for_attempt($config, $jobId, $mode, $attempt);

if (is_string($owner) && trim($owner) !== '') {
    sv_ollama_update_job_columns($pdo, $jobId, [
        'worker_owner' => $owner,
        'worker_pid' => function_exists('getmypid') ? (int)getmypid() : null,
        'stage' => 'child_running',
        'stage_changed_at' => date('c'),
        'heartbeat_at' => date('c'),
    ], true);
}

register_shutdown_function(static function () use ($pdo, $jobId, $config, $traceFile): void {
    $error = error_get_last();
    if ($error === null) {
        return;
    }
    $fatalTypes = [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE];
    if (!in_array($error['type'], $fatalTypes, true)) {
        return;
    }

    $now = date('c');
    $message = 'fatal_shutdown';
    sv_update_job_status($pdo, $jobId, 'error', null, $message);
    sv_ollama_update_job_columns($pdo, $jobId, [
        'last_error_code' => $message,
        'error_message' => $message,
        'heartbeat_at' => $now,
    ], true);

    sv_ollama_trace_update($config, $traceFile, [
        'error_code' => $message,
        'error_message' => $message,
        'stage_at_fail' => 'fatal_shutdown',
        'killed' => false,
    ]);
});

$logger = static function (string $msg): void {
    fwrite(STDERR, $msg . PHP_EOL);
};

try {
    $result = sv_process_ollama_job($pdo, $config, $jobRow, $logger);
    $status = (string)($result['status'] ?? 'error');
    $control = sv_ollama_fetch_job_control($pdo, $jobId);
    $errorCode = $control['last_error_code'] ?? null;
    $errorMessage = $result['error'] ?? null;

    echo json_encode([
        'ok' => $status === 'done',
        'job_id' => $jobId,
        'status' => $status,
        'error_code' => $errorCode,
        'error' => $errorMessage,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($status === 'done' ? 0 : 1);
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'job_id' => $jobId,
        'status' => 'error',
        'error_code' => 'child_exception',
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}

<?php
declare(strict_types=1);

if (!defined('SV_WEB_CONTEXT')) {
    define('SV_WEB_CONTEXT', true);
}

require_once __DIR__ . '/../SCRIPTS/common.php';
require_once __DIR__ . '/../SCRIPTS/logging.php';

$config = sv_load_config();
$version = 'unknown';
$statusPath = sv_logs_root($config) . '/git_status.json';
if (is_file($statusPath)) {
    $raw = file_get_contents($statusPath);
    if ($raw !== false) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && !empty($decoded['head'])) {
            $version = (string)$decoded['head'];
        }
    }
}

$scanWorkerRunning = false;
$scanWorkerState = 'stopped';
$scanWorkerStatus = 'stopped';
$logsRoot = sv_logs_root($config);
$heartbeatPath = $logsRoot . '/scan_worker_heartbeat.json';
$lockPath = $logsRoot . '/scan_worker.lock.json';
$freshnessSeconds = 180;
$lockFreshnessSeconds = 300;

if (is_file($heartbeatPath)) {
    $hbRaw = file_get_contents($heartbeatPath);
    $hb = $hbRaw !== false ? json_decode($hbRaw, true) : null;
    if (is_array($hb)) {
        $ts = isset($hb['ts_utc']) ? strtotime((string)$hb['ts_utc']) : false;
        if ($ts !== false && (time() - $ts) <= $freshnessSeconds) {
            $scanWorkerRunning = true;
            $scanWorkerState = (string)($hb['state'] ?? 'running');
            $scanWorkerStatus = $scanWorkerState === 'running' ? 'busy' : 'alive';
        } elseif ($ts !== false) {
            $scanWorkerState = (string)($hb['state'] ?? 'stale');
            $scanWorkerStatus = 'stale';
        }
    }
}
if (!$scanWorkerRunning && is_file($lockPath)) {
    $lockRaw = file_get_contents($lockPath);
    $lock = $lockRaw !== false ? json_decode($lockRaw, true) : null;
    if (is_array($lock)) {
        $ts = isset($lock['heartbeat_at']) ? strtotime((string)$lock['heartbeat_at']) : false;
        if ($ts !== false && (time() - $ts) <= $lockFreshnessSeconds) {
            $scanWorkerRunning = true;
            $scanWorkerState = 'lock_fallback';
            $scanWorkerStatus = 'busy';
        }
    }
}

header('Content-Type: application/json; charset=utf-8');
http_response_code(200);
echo json_encode([
    'ok' => true,
    'ts' => date('c'),
    'version' => $version,
    'scan_worker_running' => $scanWorkerRunning,
    'scan_worker_state' => $scanWorkerState,
    'scan_worker_status' => $scanWorkerStatus,
    'scan_worker_freshness_seconds' => $freshnessSeconds,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

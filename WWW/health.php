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

$logsRoot = sv_logs_root($config);
$freshnessSeconds = 180;
$services = [
    ['name' => 'scan_service', 'optional' => false],
    ['name' => 'forge_service', 'optional' => false],
    ['name' => 'media_service', 'optional' => false],
    ['name' => 'library_rename_service', 'optional' => false],
    ['name' => 'ollama_service', 'optional' => (bool)($config['ollama']['optional'] ?? true)],
    ['name' => 'scan_worker', 'optional' => true],
];

$serviceStates = [];
$allOk = true;
$now = time();
$tsUtc = gmdate('Y-m-d\TH:i:s\Z');

foreach ($services as $serviceCfg) {
    $service = (string)$serviceCfg['name'];
    $optional = (bool)$serviceCfg['optional'];
    $heartbeatPath = $logsRoot . '/' . $service . '.heartbeat.json';
    $state = 'missing';
    $status = 'stopped';
    $running = false;
    $lastHeartbeatUtc = null;

    if (is_file($heartbeatPath)) {
        $raw = file_get_contents($heartbeatPath);
        $hb = $raw !== false ? json_decode($raw, true) : null;
        if (is_array($hb)) {
            $lastHeartbeatUtc = is_string($hb['last_heartbeat_utc'] ?? null)
                ? (string)$hb['last_heartbeat_utc']
                : (is_string($hb['ts_utc'] ?? null) ? (string)$hb['ts_utc'] : (is_string($hb['ts'] ?? null) ? (string)$hb['ts'] : null));
            $state = is_string($hb['state'] ?? null) ? (string)$hb['state'] : 'unknown';
            $ts = is_string($lastHeartbeatUtc) ? strtotime($lastHeartbeatUtc) : false;
            if ($ts !== false && ($now - (int)$ts) <= $freshnessSeconds) {
                $running = in_array($state, ['starting', 'running', 'idle', 'error', 'stopped'], true);
                $status = in_array($state, ['running', 'starting'], true) ? 'busy' : 'alive';
            } else {
                $status = 'stale';
            }
        }
    }

    if (!$optional && !$running) {
        $allOk = false;
    }

    $serviceStates[$service] = [
        'optional' => $optional,
        'running' => $running,
        'state' => $state,
        'status' => $status,
        'last_heartbeat_utc' => $lastHeartbeatUtc,
    ];
}

header('Content-Type: application/json; charset=utf-8');
http_response_code(200);
echo json_encode([
    'ok' => $allOk,
    'ts' => $tsUtc,
    'version' => $version,
    'freshness_seconds' => $freshnessSeconds,
    'services' => $serviceStates,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

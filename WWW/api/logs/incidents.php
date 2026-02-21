<?php
declare(strict_types=1);

if (!defined('SV_WEB_CONTEXT')) {
    define('SV_WEB_CONTEXT', true);
}

require_once dirname(__DIR__, 3) . '/SCRIPTS/common.php';
require_once dirname(__DIR__, 3) . '/SCRIPTS/security.php';
require_once dirname(__DIR__, 3) . '/SCRIPTS/log_incidents.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $config = sv_load_config();
    sv_require_internal_access($config, 'logs_incidents_api');

    $date = isset($_GET['date']) && is_string($_GET['date']) ? $_GET['date'] : gmdate('Y-m-d');
    $report = sv_log_incidents_analyze_day($config, $date);

    echo json_encode([
        'ok' => true,
        'summary' => $report['summary'],
        'top_incidents' => $report['top_incidents'],
        'gpt_textbox_text' => $report['gpt_textbox_text'],
        'debug_stats' => $report['debug_stats'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => sv_sanitize_error_message($e->getMessage()),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

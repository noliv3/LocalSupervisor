<?php
declare(strict_types=1);

require_once __DIR__ . '/../SCRIPTS/common.php';

$baseDir = sv_base_dir();
$version = 'unknown';
$statusPath = $baseDir . '/LOGS/git_status.json';
if (is_file($statusPath)) {
    $raw = file_get_contents($statusPath);
    if ($raw !== false) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && !empty($decoded['head'])) {
            $version = (string)$decoded['head'];
        }
    }
}

header('Content-Type: application/json; charset=utf-8');
http_response_code(200);
echo json_encode([
    'ok'      => true,
    'ts'      => date('c'),
    'version' => $version,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

<?php
declare(strict_types=1);

// Gemeinsame Sicherheits- und Audit-Helfer.

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/logging.php';

$GLOBALS['sv_last_internal_key_valid'] = false;

function sv_ensure_session_started(): void
{
    if (sv_is_cli()) {
        return;
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function sv_is_cli(): bool
{
    return PHP_SAPI === 'cli';
}


function sv_csrf_token(): string
{
    sv_ensure_session_started();
    $token = $_SESSION['sv_csrf_token'] ?? '';
    if (!is_string($token) || $token === '') {
        $token = bin2hex(random_bytes(32));
        $_SESSION['sv_csrf_token'] = $token;
    }

    return $token;
}

function sv_csrf_validate(?string $provided): bool
{
    sv_ensure_session_started();
    $expected = $_SESSION['sv_csrf_token'] ?? '';
    if (!is_string($expected) || $expected === '' || !is_string($provided)) {
        return false;
    }

    return hash_equals($expected, trim($provided));
}

function sv_require_csrf_token(): void
{
    if (sv_is_cli()) {
        return;
    }

    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    if (!sv_csrf_validate(is_string($token) ? $token : null)) {
        sv_security_error(403, 'Forbidden: invalid CSRF token.');
    }
}

function sv_require_csrf_token_json(callable $respond): void
{
    if (sv_is_cli()) {
        return;
    }

    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    if (!sv_csrf_validate(is_string($token) ? $token : null)) {
        $respond(403, ['ok' => false, 'status' => 'forbidden', 'reason_code' => 'invalid_csrf_token']);
    }
}

function sv_is_loopback_remote_addr(): bool
{
    if (sv_is_cli()) {
        return true;
    }

    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    return $remoteAddr === '127.0.0.1'
        || $remoteAddr === '::1'
        || $remoteAddr === '::ffff:127.0.0.1';
}

function sv_get_client_ip(): string
{
    if (sv_is_cli()) {
        return 'cli';
    }

    $remoteAddr = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $trustedProxies = [];

    $args = func_get_args();
    if (isset($args[0]) && is_array($args[0])) {
        $security = $args[0]['security'] ?? [];
        $trustedProxies = is_array($security['trusted_proxies'] ?? null)
            ? $security['trusted_proxies']
            : [];
    }

    $isTrustedProxy = $remoteAddr !== '' && in_array($remoteAddr, $trustedProxies, true);

    $candidates = [];
    if ($isTrustedProxy) {
        $candidates[] = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        $candidates[] = $_SERVER['HTTP_CLIENT_IP'] ?? '';
    }
    $candidates[] = $remoteAddr;

    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || $candidate === '') {
            continue;
        }

        $parts = explode(',', $candidate);
        foreach ($parts as $part) {
            $trimmed = trim($part);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }
    }

    return '';
}

function sv_bootstrap_internal_ui_session(array $config, string $action = 'internal_ui'): bool
{
    if (sv_is_cli()) {
        return true;
    }

    if (!sv_is_loopback_remote_addr()) {
        sv_security_log($config, 'Internal UI session blocked: non-loopback', [
            'action' => $action,
            'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        ]);
        return false;
    }

    if (!sv_is_client_local($config)) {
        sv_security_log($config, 'Internal UI session blocked: IP not whitelisted', [
            'action' => $action,
            'ip' => sv_get_client_ip($config),
        ]);
        return false;
    }

    sv_ensure_session_started();
    $_SESSION['sv_internal_admin'] = [
        'granted_at' => time(),
        'remote_addr' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
    ];

    return true;
}

function sv_has_internal_admin_session(): bool
{
    if (sv_is_cli()) {
        return true;
    }

    sv_ensure_session_started();
    $sessionData = $_SESSION['sv_internal_admin'] ?? null;
    if (!is_array($sessionData)) {
        return false;
    }

    $sessionIp = (string)($sessionData['remote_addr'] ?? '');
    $remoteAddr = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($sessionIp === '' || $remoteAddr === '' || $sessionIp !== $remoteAddr) {
        return false;
    }

    return true;
}

function sv_is_client_local(array $config): bool
{
    if (sv_is_cli()) {
        return true;
    }

    $security  = $config['security'] ?? [];
    $whitelist = $security['ip_whitelist'] ?? [];
    $clientIp  = sv_get_client_ip($config);

    if (!is_array($whitelist) || $whitelist === []) {
        return true;
    }

    return in_array($clientIp, $whitelist, true);
}

function sv_internal_access_result(array $config, string $action, array $options = []): array
{
    $GLOBALS['sv_last_internal_key_valid'] = false;
    $allowSessionAuth = !array_key_exists('allow_session', $options) || !empty($options['allow_session']);

    if (sv_is_cli()) {
        $GLOBALS['sv_last_internal_key_valid'] = true;
        return [
            'ok' => true,
            'status' => 'ok',
            'reason_code' => 'cli',
            'bypass' => false,
        ];
    }

    $security    = $config['security'] ?? [];
    $expectedKey = trim((string)($security['internal_api_key'] ?? ''));
    $clientIp    = sv_get_client_ip($config);
    $whitelist   = $security['ip_whitelist'] ?? [];

    if (is_array($whitelist) && $whitelist !== [] && !in_array($clientIp, $whitelist, true)) {
        sv_security_log($config, 'IP not whitelisted', ['action' => $action, 'ip' => $clientIp]);
        return [
            'ok' => false,
            'status' => 'forbidden',
            'reason_code' => 'ip_not_whitelisted',
        ];
    }

    if ($allowSessionAuth && sv_has_internal_admin_session()) {
        sv_security_log($config, 'Internal access allowed: admin session', [
            'action' => $action,
            'ip' => $clientIp,
        ]);
        return [
            'ok' => true,
            'status' => 'ok',
            'reason_code' => 'admin_session_valid',
            'bypass' => false,
            'source' => 'session',
        ];
    }

    if ($expectedKey === '') {
        sv_security_log($config, 'Security misconfigured: internal key missing', ['action' => $action, 'ip' => $clientIp]);
        return [
            'ok' => false,
            'status' => 'config_failed',
            'reason_code' => 'internal_key_missing',
        ];
    }

    $providedKey = trim((string)($_SERVER['HTTP_X_INTERNAL_KEY'] ?? ''));
    if ($providedKey === '') {
        sv_security_log($config, 'Internal access blocked: missing auth', [
            'action' => $action,
            'ip' => $clientIp,
            'session_allowed' => $allowSessionAuth,
        ]);
        return [
            'ok' => false,
            'status' => 'forbidden',
            'reason_code' => 'internal_auth_required',
        ];
    }

    if (!hash_equals($expectedKey, $providedKey)) {
        sv_security_log($config, 'Invalid internal key', ['action' => $action, 'ip' => $clientIp, 'source' => 'header']);
        return [
            'ok' => false,
            'status' => 'forbidden',
            'reason_code' => 'internal_key_invalid',
        ];
    }

    $GLOBALS['sv_last_internal_key_valid'] = true;
    return [
        'ok' => true,
        'status' => 'ok',
        'reason_code' => 'internal_key_valid',
        'bypass' => false,
        'source' => 'header',
    ];
}

function sv_validate_internal_access(array $config, string $action, bool $hardFail = true): bool
{
    $result = sv_internal_access_result($config, $action, ['allow_session' => true]);
    if (!empty($result['ok'])) {
        return true;
    }

    if ($hardFail) {
        $status = $result['status'] ?? 'forbidden';
        $reason = $result['reason_code'] ?? 'forbidden';
        $httpCode = $status === 'config_failed' ? 500 : 403;
        $message = 'Forbidden.';
        if ($reason === 'internal_key_missing') {
            $message = 'Security misconfigured: internal_api_key missing.';
        } elseif ($reason === 'ip_not_whitelisted') {
            $message = 'Forbidden: IP not whitelisted.';
        } elseif ($reason === 'internal_auth_required') {
            $message = 'Forbidden: admin session or internal key required.';
        } elseif ($reason === 'internal_key_invalid') {
            $message = 'Forbidden: invalid internal key.';
        }
        sv_security_error($httpCode, $message);
    }

    return false;
}

function sv_require_internal_access(array $config, string $action): void
{
    sv_validate_internal_access($config, $action, true);
}

function sv_has_valid_internal_key(): bool
{
    return (bool)($GLOBALS['sv_last_internal_key_valid'] ?? false);
}

function sv_public_message_for_http(int $httpCode): string
{
    if ($httpCode === 403) {
        return 'Forbidden.';
    }
    if ($httpCode >= 500) {
        return 'Server error.';
    }

    return 'Request rejected.';
}

function sv_safe_path_label(?string $path): string
{
    if (!is_string($path)) {
        return '';
    }

    $path = trim($path);
    if ($path === '') {
        return '';
    }

    $normalized = str_replace('\\', '/', $path);
    $basename   = basename($normalized);
    if ($basename === '' || $basename === '/' || $basename === '.') {
        return '[hidden]';
    }

    if ($basename === $normalized) {
        return $basename;
    }

    return '…/' . $basename;
}

function sv_sanitize_error_message(string $message, int $maxLen = 200): string
{
    $message = trim($message);
    if ($message === '') {
        return '';
    }

    $message = preg_replace('/\s+/', ' ', $message);
    $message = preg_replace('~(?i)\b(?:mysql|pgsql|sqlite|sqlsrv):[^\s\'"]+~', '<dsn>', $message);
    $message = preg_replace('~(?i)\b(api[_-]?key|token|secret|password|pass)\s*[:=]\s*[^\s\'",;]+~', '$1=<redacted>', $message);
    $message = preg_replace('~(?:(?:[A-Za-z]:)?[\\\\/](?:[^\s\'"<>]+))+~', '[path]', $message);

    if (function_exists('mb_substr')) {
        if (mb_strlen($message) > $maxLen) {
            $message = mb_substr($message, 0, $maxLen);
        }
    } elseif (strlen($message) > $maxLen) {
        $message = substr($message, 0, $maxLen);
    }

    return $message;
}

function sv_sanitize_scanner_log_snippet(string $message, int $maxLen = 4096): string
{
    $message = trim($message);
    if ($message === '') {
        return '';
    }

    $message = preg_replace('~data:[^;]+;base64,[A-Za-z0-9+/=]+~', '<base64>', $message);
    $message = preg_replace('/[A-Za-z0-9+\/=]{120,}/', '<base64>', $message);
    $message = preg_replace('/\s+/', ' ', $message);

    return sv_sanitize_error_message($message, $maxLen);
}

function sv_sanitize_audit_details($value)
{
    if (is_string($value)) {
        return sv_sanitize_error_message($value, 200);
    }
    if (is_array($value)) {
        $sanitized = [];
        foreach ($value as $key => $entry) {
            $sanitized[$key] = sv_sanitize_audit_details($entry);
        }
        return $sanitized;
    }

    return $value;
}

function sv_audit_log(PDO $pdo, string $action, ?string $entityType, ?int $entityId, array $details = []): void
{
    static $tableChecked = false;
    static $tableExists  = false;

    if (!$tableChecked || !$tableExists) {
        try {
            $pdo->query('SELECT 1 FROM audit_log LIMIT 1');
            $tableExists = true;
        } catch (Throwable $e) {
            $tableExists = false;
        }
        $tableChecked = true;
    }

    if (!$tableExists) {
        return;
    }

    $actorKey = 'internal_api_key';
    if (sv_is_cli()) {
        $actorKey = 'cli';
    } elseif (!sv_has_valid_internal_key()) {
        $actorKey = '';
    }

    $detailsJson = json_encode(sv_sanitize_audit_details($details), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO audit_log (action, entity_type, entity_id, details_json, actor_ip, actor_key, created_at) '
            . 'VALUES (:action, :entity_type, :entity_id, :details_json, :actor_ip, :actor_key, :created_at)'
        );
        $stmt->execute([
            ':action'       => $action,
            ':entity_type'  => $entityType,
            ':entity_id'    => $entityId,
            ':details_json' => $detailsJson,
            ':actor_ip'     => sv_get_client_ip(),
            ':actor_key'    => $actorKey,
            ':created_at'   => gmdate('c'),
        ]);
    } catch (Throwable $e) {
        error_log('Audit log failed: ' . $e->getMessage());
    }
}

function sv_security_error(int $httpCode, string $message): void
{
    if (!headers_sent()) {
        http_response_code($httpCode);
        header('Content-Type: text/plain; charset=utf-8');
    }

    echo sv_public_message_for_http($httpCode);
    exit;
}

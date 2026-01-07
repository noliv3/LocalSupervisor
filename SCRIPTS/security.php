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

function sv_get_client_ip(): string
{
    if (sv_is_cli()) {
        return 'cli';
    }

    $candidates = [
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['HTTP_CLIENT_IP'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];

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

function sv_validate_internal_access(array $config, string $action, bool $hardFail = true): bool
{
    $GLOBALS['sv_last_internal_key_valid'] = false;

    if (sv_is_cli()) {
        $GLOBALS['sv_last_internal_key_valid'] = true;
        return true;
    }

    $security    = $config['security'] ?? [];
    $expectedKey = trim((string)($security['internal_api_key'] ?? ''));
    $clientIp    = sv_get_client_ip();
    $whitelist   = $security['ip_whitelist'] ?? [];

    if ($expectedKey === '') {
        sv_security_log($config, 'Security misconfigured: internal key missing', ['action' => $action, 'ip' => $clientIp]);
        if ($hardFail) {
            sv_security_error(500, 'Security misconfigured: internal_api_key missing.');
        }
        return false;
    }

    if (is_array($whitelist) && $whitelist !== []) {
        if (!in_array($clientIp, $whitelist, true)) {
            sv_security_log($config, 'IP not whitelisted', ['action' => $action, 'ip' => $clientIp]);
            if ($hardFail) {
                sv_security_error(403, 'Forbidden: IP not whitelisted.');
            }
            return false;
        }
    }

    sv_ensure_session_started();

    $candidates = [
        'header'  => $_SERVER['HTTP_X_INTERNAL_KEY'] ?? '',
        'get'     => $_GET['internal_key']          ?? '',
        'post'    => $_POST['internal_key']         ?? '',
        'session' => $_SESSION['sv_internal_key']   ?? '',
        'cookie'  => $_COOKIE['internal_key']       ?? '',
    ];

    $providedKey    = '';
    $providedSource = '';
    foreach (['header', 'get', 'post', 'session', 'cookie'] as $src) {
        $value = $candidates[$src] ?? '';
        if (is_string($value)) {
            $value = trim($value);
        }
        if ($value !== '') {
            $providedKey    = (string)$value;
            $providedSource = $src;
            break;
        }
    }

    if ($providedKey === '') {
        if ($hardFail) {
            sv_security_log($config, 'Missing internal key', ['action' => $action, 'ip' => $clientIp]);
            sv_security_error(403, 'Forbidden: internal key required.');
        }
        return false;
    }

    if (!hash_equals($expectedKey, $providedKey)) {
        if ($hardFail) {
            sv_security_log($config, 'Invalid internal key', ['action' => $action, 'ip' => $clientIp, 'source' => $providedSource]);
            sv_security_error(403, 'Forbidden: invalid internal key.');
        }
        return false;
    }

    if (in_array($providedSource, ['header', 'get', 'post'], true)) {
        $_SESSION['sv_internal_key'] = $expectedKey;
        if (!headers_sent()) {
            $secureCookie = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            setcookie('internal_key', $expectedKey, [
                'expires'  => time() + 7 * 24 * 60 * 60,
                'path'     => '/',
                'samesite' => 'Lax',
                'secure'   => $secureCookie,
                'httponly' => true,
            ]);
        }
        sv_security_log($config, 'Internal key stored for session', [
            'action' => $action,
            'ip'     => $clientIp,
            'source' => $providedSource,
        ]);
    }

    $GLOBALS['sv_last_internal_key_valid'] = true;
    return true;
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
            ':created_at'   => date('c'),
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

<?php
declare(strict_types=1);

// Gemeinsame Sicherheits- und Audit-Helfer.

$GLOBALS['sv_last_internal_key_valid'] = false;

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

function sv_require_internal_key(array $config): void
{
    $security = $config['security'] ?? [];
    sv_check_internal_key($security);
}

function sv_check_internal_key(array $configSecurity): void
{
    $GLOBALS['sv_last_internal_key_valid'] = false;

    if (sv_is_cli()) {
        $GLOBALS['sv_last_internal_key_valid'] = true;
        return;
    }

    $expectedKey = trim((string)($configSecurity['internal_api_key'] ?? ''));
    if ($expectedKey === '') {
        sv_security_error(500, 'Security misconfigured: internal_api_key missing.');
    }

    $clientIp = sv_get_client_ip();
    $whitelist = $configSecurity['ip_whitelist'] ?? [];
    if (is_array($whitelist) && $whitelist !== []) {
        $allowed = in_array($clientIp, $whitelist, true);
        if (!$allowed) {
            sv_security_error(403, 'Forbidden: IP not whitelisted.');
        }
    }

    $providedKey = '';
    if (!empty($_SERVER['HTTP_X_INTERNAL_KEY'])) {
        $providedKey = (string)$_SERVER['HTTP_X_INTERNAL_KEY'];
    } elseif (isset($_GET['internal_key'])) {
        $providedKey = (string)$_GET['internal_key'];
    } elseif (isset($_POST['internal_key'])) {
        $providedKey = (string)$_POST['internal_key'];
    }

    if ($providedKey === '') {
        sv_security_error(403, 'Forbidden: internal key required.');
    }

    if (!hash_equals($expectedKey, $providedKey)) {
        sv_security_error(403, 'Forbidden: invalid internal key.');
    }

    $GLOBALS['sv_last_internal_key_valid'] = true;
}

function sv_is_internal_request(array $config): bool
{
    $GLOBALS['sv_last_internal_key_valid'] = false;

    if (sv_is_cli()) {
        $GLOBALS['sv_last_internal_key_valid'] = true;
        return true;
    }

    $security = $config['security'] ?? [];
    $expectedKey = trim((string)($security['internal_api_key'] ?? ''));
    if ($expectedKey === '') {
        return false;
    }

    $clientIp = sv_get_client_ip();
    $whitelist = $security['ip_whitelist'] ?? [];
    if (is_array($whitelist) && $whitelist !== []) {
        if (!in_array($clientIp, $whitelist, true)) {
            return false;
        }
    }

    $providedKey = '';
    if (!empty($_SERVER['HTTP_X_INTERNAL_KEY'])) {
        $providedKey = (string)$_SERVER['HTTP_X_INTERNAL_KEY'];
    } elseif (isset($_GET['internal_key'])) {
        $providedKey = (string)$_GET['internal_key'];
    } elseif (isset($_POST['internal_key'])) {
        $providedKey = (string)$_POST['internal_key'];
    }

    if ($providedKey === '') {
        return false;
    }

    $valid = hash_equals($expectedKey, $providedKey);
    $GLOBALS['sv_last_internal_key_valid'] = $valid;

    return $valid;
}

function sv_has_valid_internal_key(): bool
{
    return (bool)($GLOBALS['sv_last_internal_key_valid'] ?? false);
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

    $detailsJson = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

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

    echo $message;
    exit;
}

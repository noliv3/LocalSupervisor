<?php
declare(strict_types=1);

require_once __DIR__ . '/../SCRIPTS/common.php';
require_once __DIR__ . '/../SCRIPTS/db_helpers.php';
require_once __DIR__ . '/../SCRIPTS/security.php';
require_once __DIR__ . '/../SCRIPTS/operations.php';
require_once __DIR__ . '/_layout.php';

try {
    $config = sv_load_config();
} catch (Throwable $e) {
    http_response_code(500);
    echo "<pre>CONFIG-Fehler: " . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</pre>";
    exit;
}

$configWarning = $config['_config_warning'] ?? null;
$internalKey   = isset($_GET['internal_key']) && is_string($_GET['internal_key']) ? trim($_GET['internal_key']) : '';

$isLoopback = sv_is_loopback_remote_addr();
if (!$isLoopback) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        header('Location: mediadb.php');
        exit;
    }
    sv_security_error(403, 'Forbidden.');
}

sv_require_internal_access($config, 'dashboard');

$dbError = null;
$pdo = null;
try {
    $db  = sv_db_connect($config);
    $pdo = $db['pdo'];
} catch (Throwable $e) {
    http_response_code(503);
    $dbError = $e->getMessage();
}

$actionMessage = null;
$actionError   = null;
$logFile       = null;
$logLines      = [];
$jobCenterLog  = [];

$knownActions = [
    'scan_start'   => 'Scan gestartet',
    'rescan_start' => 'Rescan gestartet',
    'backfill_no_tags' => 'Backfill gestartet',
];

if (is_string($_GET['ajax'] ?? null) && trim((string)$_GET['ajax']) !== '') {
    header('Content-Type: application/json; charset=utf-8');

    if (!$pdo instanceof PDO) {
        http_response_code(503);
        echo json_encode([
            'ok'    => false,
            'error' => 'Keine DB-Verbindung.',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    sv_require_internal_access($config, 'dashboard_ajax');

    $ajaxAction = trim((string)$_GET['ajax']);
    try {
        if ($ajaxAction === 'jobs_list') {
            $type = is_string($_GET['type'] ?? null) ? trim((string)$_GET['type']) : '';
            if ($type !== 'scan') {
                throw new InvalidArgumentException('Ungültiger Job-Typ.');
            }
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 30;
            $jobs = sv_fetch_scan_related_jobs($pdo, $limit);
            echo json_encode([
                'ok'          => true,
                'server_time' => date('c'),
                'jobs'        => $jobs,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($ajaxAction === 'job_cancel') {
            $jobId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
            $logger = sv_operation_logger(null, $jobCenterLog);
            if ($jobId <= 0) {
                throw new InvalidArgumentException('Job-ID fehlt oder ist ungültig.');
            }
            $result = sv_cancel_job($pdo, $jobId, $logger);
            echo json_encode([
                'ok'     => true,
                'result' => $result,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($ajaxAction === 'job_delete') {
            $jobId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
            $logger = sv_operation_logger(null, $jobCenterLog);
            if ($jobId <= 0) {
                throw new InvalidArgumentException('Job-ID fehlt oder ist ungültig.');
            }
            $result = sv_delete_job($pdo, $jobId, $logger);
            echo json_encode([
                'ok'     => true,
                'result' => $result,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($ajaxAction === 'jobs_prune') {
            $olderThanDays = isset($_POST['older_than_days']) ? (int)$_POST['older_than_days'] : null;
            $keepLast = isset($_POST['keep_last']) ? (int)$_POST['keep_last'] : 0;
            $logger = sv_operation_logger(null, $jobCenterLog);
            $result = sv_prune_jobs($pdo, [
                'types'           => sv_scan_job_types(),
                'older_than_days' => $olderThanDays,
                'keep_last'       => $keepLast,
            ], $logger);
            echo json_encode([
                'ok'     => true,
                'result' => $result,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        throw new InvalidArgumentException('Ungültige AJAX-Aktion.');
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'ok'    => false,
            'error' => sv_sanitize_error_message($e->getMessage()),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$pdo instanceof PDO) {
        $actionError = 'Keine DB-Verbindung: ' . ($dbError ?? 'unbekannt');
    } else {
        sv_require_internal_access($config, 'dashboard_action');

        $action = is_string($_POST['action'] ?? null) ? trim($_POST['action']) : '';
        $allowedActions = ['scan_path', 'rescan_db', 'backfill_no_tags', 'job_requeue', 'job_cancel', 'update_center'];

        if (!in_array($action, $allowedActions, true)) {
            $actionError = 'Ungültige Aktion.';
            $action = '';
        }

        if ($action === 'scan_path') {
            $rawPaths = is_string($_POST['scan_path'] ?? null) ? (string)$_POST['scan_path'] : '';
            $limit    = isset($_POST['scan_limit']) ? (int)$_POST['scan_limit'] : null;

            if ($limit !== null && $limit <= 0) {
                $limit = null;
            }

            $lines = preg_split('/\R/u', $rawPaths) ?: [];
            $createdJobs = [];
            $createdCount = 0;
            $skippedInvalid = 0;
            $skippedDup = 0;
            $seen = [];
            $isWindows = stripos(PHP_OS_FAMILY ?? PHP_OS, 'Windows') !== false;

            [$logFile, $logger] = sv_create_operation_log($config, 'scan', $logLines, 50);

            foreach ($lines as $line) {
                $path = trim((string)$line);
                if ($path === '') {
                    $skippedInvalid++;
                    continue;
                }
                if (mb_strlen($path) > 500) {
                    $skippedInvalid++;
                    continue;
                }

                $dupKey = str_replace('\\', '/', $path);
                if ($dupKey !== '/' && !preg_match('~^[A-Za-z]:/$~', $dupKey)) {
                    $dupKey = rtrim($dupKey, '/');
                }
                if ($isWindows) {
                    $dupKey = mb_strtolower($dupKey);
                }
                if (isset($seen[$dupKey])) {
                    $skippedDup++;
                    continue;
                }
                $seen[$dupKey] = true;

                try {
                    $job = sv_create_scan_job($pdo, $config, $path, $limit, $logger);
                    $jobId = (int)($job['job_id'] ?? 0);
                    if ($jobId > 0) {
                        $createdJobs[] = $jobId;
                        $createdCount++;
                    } else {
                        $skippedInvalid++;
                    }
                } catch (Throwable $e) {
                    $skippedInvalid++;
                    $logger('Scan-Job verworfen: ' . sv_sanitize_error_message($e->getMessage()));
                }
            }

            if ($createdCount === 0) {
                $actionError = 'Keine gültigen Scan-Pfade.';
            } else {
                try {
                    $worker  = sv_spawn_scan_worker($config, null, null, $logger, null, 0);
                    $logger('Worker-Spawn: ' . json_encode($worker, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                    $workerStatus = (string)($worker['status'] ?? '');
                    $workerReason = (string)($worker['reason_code'] ?? ($worker['reason'] ?? ''));
                    $workerOk = in_array($workerStatus, ['running', 'started_unverified'], true) && !empty($worker['spawned']);

                    foreach ($createdJobs as $jobId) {
                        sv_merge_job_response_metadata($pdo, $jobId, [
                            '_sv_worker_pid'        => $worker['pid'],
                            '_sv_worker_started_at' => $worker['started'],
                        ]);
                    }

                    if (!$workerOk) {
                        $actionError = 'Scan-Worker-Start fehlgeschlagen (status='
                            . ($workerStatus !== '' ? $workerStatus : 'unknown')
                            . ', reason=' . ($workerReason !== '' ? $workerReason : 'unknown') . ').';
                        if (!empty($createdJobs)) {
                            $actionError .= ' Jobs erstellt: #' . implode(', #', $createdJobs) . '.';
                        }
                    } else {
                        $actionMessage = sprintf(
                            'Scan-Jobs erstellt: created %d, skipped_dup %d, skipped_invalid %d.',
                            $createdCount,
                            $skippedDup,
                            $skippedInvalid
                        );
                        if (!empty($createdJobs)) {
                            $actionMessage .= ' IDs: #' . implode(', #', $createdJobs) . '.';
                        }
                    }

                    sv_audit_log($pdo, 'scan_start', 'fs', null, [
                        'limit'          => $limit,
                        'job_ids'        => $createdJobs,
                        'created'        => $createdCount,
                        'skipped_dup'    => $skippedDup,
                        'skipped_invalid'=> $skippedInvalid,
                        'worker_pid'     => $worker['pid'] ?? null,
                        'worker_note'    => $worker['unknown'] ? 'pid_unknown' : 'pid_recorded',
                        'worker_status'  => $worker['status'] ?? null,
                        'worker_reason_code' => $worker['reason_code'] ?? null,
                        'worker_log_paths' => $worker['log_paths'] ?? null,
                        'user_agent'     => $_SERVER['HTTP_USER_AGENT'] ?? null,
                    ]);
                } catch (Throwable $e) {
                    $actionError = 'Scan-Fehler: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'rescan_db') {
            $limit   = isset($_POST['rescan_limit']) ? (int)$_POST['rescan_limit'] : null;
            $offset  = isset($_POST['rescan_offset']) ? (int)$_POST['rescan_offset'] : null;

            if ($limit === null || $limit <= 0) {
                $limit = 200;
            }
            if ($limit > 500) {
                $limit = 500;
            }
            if ($offset !== null && $offset < 0) {
                $offset = null;
            }

            [$logFile, $logger] = sv_create_operation_log($config, 'rescan', $logLines, 50);

            try {
                $result = sv_enqueue_rescan_unscanned_jobs($pdo, $config, $limit, $offset, $logger);
                $worker = null;
                if (($result['total'] ?? 0) > 0) {
                    $worker = sv_spawn_scan_worker($config, null, null, $logger, null, 0);
                    $logger('Worker-Spawn: ' . json_encode($worker, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }
                $workerStatus = (string)($worker['status'] ?? '');
                $workerReason = (string)($worker['reason_code'] ?? ($worker['reason'] ?? ''));
                $workerOk = $worker === null || (in_array($workerStatus, ['running', 'started_unverified'], true) && !empty($worker['spawned']));
                if (!$workerOk) {
                    $actionError = 'Rescan-Worker-Start fehlgeschlagen (status='
                        . ($workerStatus !== '' ? $workerStatus : 'unknown')
                        . ', reason=' . ($workerReason !== '' ? $workerReason : 'unknown') . ').';
                } else {
                    $actionMessage = sprintf(
                        'Rescan-Jobs eingereiht: total %d, created %d, deduped %d, errors %d. Limit angewandt: %d.',
                        (int)($result['total'] ?? 0),
                        (int)($result['created'] ?? 0),
                        (int)($result['deduped'] ?? 0),
                        (int)($result['errors'] ?? 0),
                        $limit
                    );
                }
                sv_audit_log($pdo, 'rescan_start', 'fs', null, [
                    'limit'      => $limit,
                    'offset'     => $offset,
                    'log_file'   => $logFile,
                    'queued'     => $result,
                    'worker_pid' => $worker['pid'] ?? null,
                    'worker_note'=> $worker && !empty($worker['unknown']) ? 'pid_unknown' : 'pid_recorded',
                    'worker_status' => $worker['status'] ?? null,
                    'worker_reason_code' => $worker['reason_code'] ?? null,
                    'worker_log_paths' => $worker['log_paths'] ?? null,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                ]);
            } catch (Throwable $e) {
                $actionError = 'Rescan-Fehler: ' . $e->getMessage();
            }
        } elseif ($action === 'backfill_no_tags') {
            $chunk = isset($_POST['backfill_chunk']) ? (int)$_POST['backfill_chunk'] : 200;
            $max   = isset($_POST['backfill_max']) ? (int)$_POST['backfill_max'] : null;
            if ($chunk <= 0) {
                $chunk = 200;
            }
            if ($chunk > 500) {
                $chunk = 500;
            }
            if ($max !== null && $max <= 0) {
                $max = null;
            }

            [$logFile, $logger] = sv_create_operation_log($config, 'backfill_no_tags', $logLines, 50);

            try {
                $result = sv_create_scan_backfill_job($pdo, $config, [
                    'mode'  => 'no_tags',
                    'chunk' => $chunk,
                    'max'   => $max,
                ], $logger);
                $worker = sv_spawn_scan_worker($config, null, null, $logger, null, 0);
                $logger('Worker-Spawn: ' . json_encode($worker, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                $workerStatus = (string)($worker['status'] ?? '');
                $workerReason = (string)($worker['reason_code'] ?? ($worker['reason'] ?? ''));
                $workerOk = in_array($workerStatus, ['running', 'started_unverified'], true) && !empty($worker['spawned']);
                $jobId = (int)($result['job_id'] ?? 0);
                if ($jobId > 0) {
                    sv_merge_job_response_metadata($pdo, $jobId, [
                        '_sv_worker_pid'        => $worker['pid'],
                        '_sv_worker_started_at' => $worker['started'],
                    ]);
                }
                sv_audit_log($pdo, 'backfill_start', 'jobs', $jobId > 0 ? $jobId : null, [
                    'chunk' => $chunk,
                    'max' => $max,
                    'job_id' => $jobId,
                    'worker_pid' => $worker['pid'] ?? null,
                    'worker_note' => !empty($worker['unknown']) ? 'pid_unknown' : 'pid_recorded',
                    'worker_status' => $worker['status'] ?? null,
                    'worker_reason_code' => $worker['reason_code'] ?? null,
                    'worker_log_paths' => $worker['log_paths'] ?? null,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                ]);
                if (!$workerOk) {
                    $actionError = 'Backfill-Worker-Start fehlgeschlagen (status='
                        . ($workerStatus !== '' ? $workerStatus : 'unknown')
                        . ', reason=' . ($workerReason !== '' ? $workerReason : 'unknown') . ').';
                } else {
                    $actionMessage = 'Backfill-Job eingereiht: #' . (int)($result['job_id'] ?? 0) . '. Limit angewandt: ' . $chunk . '.';
                    if (!empty($result['deduped'])) {
                        $actionMessage .= ' (bereits vorhanden)';
                    }
                }
            } catch (Throwable $e) {
                $actionError = 'Backfill-Fehler: ' . $e->getMessage();
            }
        } elseif ($action === 'job_requeue') {
            $jobId = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
            $logger = sv_operation_logger(null, $jobCenterLog);

            if ($jobId <= 0) {
                $actionError = 'Job-ID fehlt oder ist ungültig.';
            } else {
                try {
                    $result = sv_requeue_job($pdo, $jobId, $logger);
                    $actionMessage = $result['message'] ?? 'Job erneut eingereiht.';
                } catch (Throwable $e) {
                    $actionError = 'Requeue fehlgeschlagen: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'job_cancel') {
            $jobId = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
            $logger = sv_operation_logger(null, $jobCenterLog);

            if ($jobId <= 0) {
                $actionError = 'Job-ID fehlt oder ist ungültig.';
            } else {
                try {
                    $result = sv_cancel_job($pdo, $jobId, $logger);
                    $actionMessage = $result['message'] ?? 'Job abgebrochen.';
                } catch (Throwable $e) {
                    $actionError = 'Abbruch fehlgeschlagen: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'update_center') {
            $updateAction = is_string($_POST['update_action'] ?? null)
                ? trim((string)$_POST['update_action'])
                : 'update_ff_restart';
            if ($updateAction === '') {
                $updateAction = 'update_ff_restart';
            }
            try {
                $spawn = sv_spawn_update_center_run($config, $updateAction);
                if (!empty($spawn['spawned'])) {
                    $actionMessage = 'Update gestartet.';
                } else {
                    $actionError = 'Update-Start fehlgeschlagen: ' . ($spawn['message'] ?? $spawn['error'] ?? 'unbekannt');
                }
            } catch (Throwable $e) {
                $actionError = 'Update-Start fehlgeschlagen: ' . $e->getMessage();
            }
        }
    }
}

$dashboard = null;
$dashboardError = null;
if ($pdo instanceof PDO) {
    try {
        $dashboard = sv_collect_dashboard_view_model($pdo, $config, [
            'known_actions' => array_keys($knownActions),
            'job_limit'     => 8,
            'event_limit'   => 8,
            'health_limit'  => 6,
        ]);
    } catch (Throwable $e) {
        $dashboardError = $e->getMessage();
    }
}

$hasInternalAccess = sv_has_valid_internal_key();
$jobCounts = $dashboard['job_counts'] ?? ['queued' => 0, 'running' => 0, 'done' => 0, 'error' => 0];
$health = $dashboard['health'] ?? ['db_health' => [], 'job_health' => [], 'scan_health' => []];
$jobHealth = $health['job_health'] ?? [];
$scanHealth = $health['scan_health'] ?? [];
$dbHealth = $health['db_health'] ?? [];
$events = $dashboard['events'] ?? [];
$jobSections = $dashboard['jobs'] ?? ['running' => [], 'queued' => [], 'stuck' => [], 'recent' => []];
$forgeOverview = $dashboard['forge'] ?? [];
$lastRuns = $dashboard['last_runs'] ?? [];
$gitStatus = $dashboard['git']['status'] ?? null;
$gitLast = $dashboard['git']['last'] ?? null;

function sv_badge_class(string $status): string
{
    $status = strtolower($status);
    if (in_array($status, ['ok', 'done'], true)) {
        return 'badge badge--ok';
    }
    if (in_array($status, ['error', 'failed'], true)) {
        return 'badge badge--error';
    }
    if (in_array($status, ['running', 'queued', 'pending'], true)) {
        return 'badge badge--info';
    }
    if (in_array($status, ['warn', 'warning'], true)) {
        return 'badge badge--warn';
    }

    return 'badge';
}
?>
<?php sv_ui_header('Operator Dashboard', 'dashboard'); ?>
    <div class="page-header">
        <div>
            <h1 class="page-title">Start</h1>
            <p class="muted">Operator-Control-Center für Galerie, Health und Jobs.</p>
        </div>
        <div class="header-actions">
            <a class="btn btn--primary" href="mediadb.php">Galerie öffnen</a>
            <div class="header-links">
                <a href="mediadb.php">Letzte Medien</a>
                <a href="#update-center">Update Center</a>
                <a href="#job-center-recent">Letzte Fehler</a>
                <a href="#job-center">Job-Center</a>
                <a href="#health-snapshot">Health</a>
                <a href="#event-log">Ereignisse</a>
            </div>
        </div>
    </div>

        <?php if (!empty($configWarning)): ?>
            <div class="banner banner--warn">
                <?= htmlspecialchars($configWarning, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($dbError !== null): ?>
            <div class="banner banner--error">
                <strong>DB-Verbindung fehlgeschlagen:</strong>
                <?= htmlspecialchars(sv_sanitize_error_message($dbError), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                <div class="muted">Quelle: <?= htmlspecialchars((string)($config['_config_path'] ?? 'unbekannt'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            </div>
        <?php endif; ?>

        <?php if ($actionMessage !== null): ?>
            <div class="banner banner--success">
                <?= htmlspecialchars($actionMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($actionError !== null): ?>
            <div class="banner banner--error">
                <?= htmlspecialchars(sv_sanitize_error_message($actionError), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($hasInternalAccess): ?>
            <section id="update-center" class="card">
                <h2 class="section-title">Update Center</h2>
                <div class="health-grid">
                    <div class="health-row">
                        <strong>Git Status</strong>
                        <?php if (!is_array($gitStatus)): ?>
                            <div class="muted">Kein Git-Status verfügbar.</div>
                        <?php else: ?>
                            <?php
                            $gitAhead  = isset($gitStatus['ahead']) ? (int)$gitStatus['ahead'] : 0;
                            $gitBehind = isset($gitStatus['behind']) ? (int)$gitStatus['behind'] : 0;
                            $gitDirty  = !empty($gitStatus['dirty']);
                            $behindClass = $gitBehind > 0 ? 'badge--warn' : 'badge--ok';
                            $dirtyClass = $gitDirty ? 'badge--warn' : 'badge--ok';
                            $fetchOk = $gitStatus['fetch_ok'] ?? null;
                            $fetchBadge = $fetchOk === null ? 'badge' : ($fetchOk ? 'badge--ok' : 'badge--error');
                            ?>
                            <div class="line">
                                <span class="badge <?= $behindClass ?>">behind <?= $gitBehind ?></span>
                                <span class="badge badge--info">ahead <?= $gitAhead ?></span>
                                <span class="badge <?= $dirtyClass ?>">dirty <?= $gitDirty ? 'yes' : 'no' ?></span>
                            </div>
                            <div class="line">
                                <span>Branch: <?= htmlspecialchars((string)($gitStatus['branch'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                <span>Head: <?= htmlspecialchars((string)($gitStatus['head'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                            </div>
                            <details>
                                <summary>Mehr anzeigen</summary>
                                <div class="muted">Upstream: <?= htmlspecialchars((string)($gitStatus['upstream'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                <div class="muted">Letztes Fetch: <?= htmlspecialchars((string)($gitStatus['updated_at'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                <?php if ($fetchOk !== null): ?>
                                    <div class="line">
                                        <span class="badge <?= $fetchBadge ?>">fetch <?= $fetchOk ? 'ok' : 'error' ?></span>
                                        <?php if (!empty($gitStatus['fetch_error'])): ?>
                                            <span class="job-error"><?= htmlspecialchars((string)$gitStatus['fetch_error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </details>
                        <?php endif; ?>
                    </div>

                    <div class="health-row">
                        <strong>Letztes Update</strong>
                        <?php if (!is_array($gitLast)): ?>
                            <div class="muted">Kein Update-Status verfügbar.</div>
                        <?php else: ?>
                            <?php
                            $updateResult = (string)($gitLast['result'] ?? 'unknown');
                            $updateBadge = sv_badge_class($updateResult);
                            $beforeCommit = is_array($gitLast['before'] ?? null) ? (string)($gitLast['before']['commit'] ?? '—') : '—';
                            $afterCommit = is_array($gitLast['after'] ?? null) ? (string)($gitLast['after']['commit'] ?? '—') : '—';
                            ?>
                            <div class="line">
                                <span class="badge badge--info"><?= htmlspecialchars((string)($gitLast['action'] ?? 'update'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                <span class="<?= $updateBadge ?>"><?= htmlspecialchars($updateResult, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                            </div>
                            <div class="line">
                                <span>Start: <?= htmlspecialchars((string)($gitLast['started_at'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                <span>Ende: <?= htmlspecialchars((string)($gitLast['finished_at'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                            </div>
                            <details>
                                <summary>Mehr anzeigen</summary>
                                <div class="muted">Before: <?= htmlspecialchars($beforeCommit, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                <div class="muted">After: <?= htmlspecialchars($afterCommit, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                <?php if (!empty($gitLast['short_error'])): ?>
                                    <div class="job-error"><?= htmlspecialchars((string)$gitLast['short_error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                <?php endif; ?>
                            </details>
                        <?php endif; ?>
                    </div>
                </div>
                <form method="post" class="inline-fields">
                    <input type="hidden" name="action" value="update_center">
                    <input type="hidden" name="update_action" value="update_ff_restart">
                    <?php if ($internalKey !== ''): ?>
                        <input type="hidden" name="internal_key" value="<?= htmlspecialchars($internalKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <?php endif; ?>
                    <button type="submit" class="btn btn--primary" onclick="return confirm('Update jetzt starten?');">Update (FF + DB + Restart)</button>
                </form>
                <div class="muted">FF-only Standard; Merge nur über separaten Action-Parameter.</div>
            </section>
        <?php endif; ?>


        <section id="health-snapshot" class="card">
            <h2 class="section-title">Health Snapshot</h2>
            <?php if ($dashboardError !== null): ?>
                <div class="muted">Health-Snapshot fehlgeschlagen: <?= htmlspecialchars(sv_sanitize_error_message($dashboardError), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <?php elseif ($dashboard === null): ?>
                <div class="muted">Kein Health-Snapshot verfügbar.</div>
            <?php else: ?>
                <div class="health-grid">
                    <div class="health-row">
                        <strong>DB Health</strong>
                        <div class="line">
                            <span class="badge <?= $dbError === null ? 'badge--ok' : 'badge--error' ?>">
                                <?= $dbError === null ? 'connected' : 'offline' ?>
                            </span>
                            <span>Treiber: <?= htmlspecialchars((string)($dbHealth['driver'] ?? 'unbekannt'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                            <span>Migrationen: <?= htmlspecialchars((string)($dbHealth['pending_migrations_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                            <span>Issues: <?= htmlspecialchars((string)($dbHealth['issues_total'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                        </div>
                        <details>
                            <summary>Mehr anzeigen</summary>
                            <div class="muted">DSN (redacted): <?= htmlspecialchars((string)($dbHealth['redacted_dsn'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                            <?php if (!empty($dbHealth['pending_migrations'])): ?>
                                <div class="muted">Pending: <?= htmlspecialchars(implode(', ', (array)$dbHealth['pending_migrations']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                            <?php endif; ?>
                            <?php if (!empty($dbHealth['migration_error'])): ?>
                                <div class="job-error">Migration: <?= htmlspecialchars((string)$dbHealth['migration_error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </details>
                    </div>

                    <div class="health-row">
                        <strong>Job Health</strong>
                        <div class="line">
                            <span class="badge badge--info">queued <?= (int)($jobCounts['queued'] ?? 0) ?></span>
                            <span class="badge badge--info">running <?= (int)($jobCounts['running'] ?? 0) ?></span>
                            <span class="badge <?= ($jobHealth['stuck_jobs'] ?? 0) > 0 ? 'badge--warn' : 'badge--ok' ?>">
                                stuck <?= (int)($jobHealth['stuck_jobs'] ?? 0) ?>
                            </span>
                            <span>Letzter Fehler: <?= htmlspecialchars((string)($jobHealth['last_error']['message'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                        </div>
                        <details>
                            <summary>Mehr anzeigen</summary>
                            <div class="muted">Done: <?= (int)($jobCounts['done'] ?? 0) ?> · Error: <?= (int)($jobCounts['error'] ?? 0) ?></div>
                            <?php if (!empty($jobHealth['last_error'])): ?>
                                <div class="job-error">#<?= htmlspecialchars((string)($jobHealth['last_error']['id'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> (<?= htmlspecialchars((string)($jobHealth['last_error']['type'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)</div>
                            <?php endif; ?>
                        </details>
                    </div>

                    <div class="health-row">
                        <strong>Scan Health</strong>
                        <div class="line">
                            <span>Letzter Scan: <?= htmlspecialchars((string)($scanHealth['last_scan_time'] ?? 'unbekannt'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                            <span>Scan-Fehler: <?= htmlspecialchars((string)($scanHealth['scan_job_errors'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                            <span>Fehlende Marker: <?= htmlspecialchars((string)($scanHealth['missing_run_at'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                        </div>
                        <details>
                            <summary>Mehr anzeigen</summary>
                            <?php if (!empty($scanHealth['latest'])): ?>
                                <ul class="muted">
                                    <?php foreach ($scanHealth['latest'] as $scan): ?>
                                        <li>#<?= htmlspecialchars((string)($scan['id'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> – Media <?= htmlspecialchars((string)($scan['media_id'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · <?= htmlspecialchars((string)($scan['scanner'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · <?= htmlspecialchars((string)($scan['run_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <div class="muted">Keine aktuellen Scan-Einträge.</div>
                            <?php endif; ?>
                        </details>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2 class="section-title">Operator-Aktionen</h2>
            <div class="operator-grid">
                <div class="operator-card">
                    <h3>Scan-Path Batch</h3>
                    <form method="post">
                        <input type="hidden" name="action" value="scan_path">
                        <div class="inline-fields">
                            <textarea name="scan_path" rows="6" placeholder="Pfad 1&#10;Pfad 2"></textarea>
                        <input type="number" name="scan_limit" min="1" step="1" placeholder="Limit">
                        <?php if ($internalKey !== ''): ?>
                            <input type="hidden" name="internal_key" value="<?= htmlspecialchars($internalKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <?php endif; ?>
                        <button type="submit" class="btn btn--primary btn--sm">Scan starten</button>
                    </div>
                </form>
                    <div class="muted">Ein Pfad pro Zeile (Ordner oder Datei).</div>
                    <div class="muted">Letzter Lauf: <?= htmlspecialchars((string)($lastRuns['scan_start'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                </div>

                <div class="operator-card">
                    <h3>Rescan (unscanned)</h3>
                    <form method="post">
                        <input type="hidden" name="action" value="rescan_db">
                        <div class="inline-fields">
                            <input type="number" name="rescan_limit" min="1" step="1" placeholder="Limit">
                        <input type="number" name="rescan_offset" min="0" step="1" placeholder="Offset">
                        <?php if ($internalKey !== ''): ?>
                            <input type="hidden" name="internal_key" value="<?= htmlspecialchars($internalKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <?php endif; ?>
                        <button type="submit" class="btn btn--primary btn--sm">Rescan starten</button>
                    </div>
                </form>
                    <div class="muted">Reiht Jobs für Medien ohne Scan-Ergebnis ein (kein Scan im Request).</div>
                    <div class="muted">Letzter Lauf: <?= htmlspecialchars((string)($lastRuns['rescan_start'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                </div>

                <div class="operator-card">
                    <h3>Backfill Tags (ohne Tags)</h3>
                    <form method="post">
                        <input type="hidden" name="action" value="backfill_no_tags">
                        <div class="inline-fields">
                            <input type="number" name="backfill_chunk" min="1" step="1" placeholder="Chunk" value="200">
                        <input type="number" name="backfill_max" min="1" step="1" placeholder="Max">
                        <?php if ($internalKey !== ''): ?>
                            <input type="hidden" name="internal_key" value="<?= htmlspecialchars($internalKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <?php endif; ?>
                        <button type="submit" class="btn btn--primary btn--sm">Backfill starten</button>
                    </div>
                </form>
                    <div class="muted">Scant Medien ohne Tags über die Job-Queue (asynchron).</div>
                    <div class="muted">Letzter Lauf: <?= htmlspecialchars((string)($lastRuns['backfill_no_tags'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                </div>

                <div class="operator-card">
                    <h3>Forge Worker Status</h3>
                    <div class="line">
                        <span class="badge badge--info">open <?= (int)($forgeOverview['open'] ?? 0) ?></span>
                        <span class="badge badge--ok">done <?= (int)($forgeOverview['done'] ?? 0) ?></span>
                        <span class="badge badge--error">error <?= (int)($forgeOverview['error'] ?? 0) ?></span>
                    </div>
                    <div class="muted">Dispatch via <code>php SCRIPTS/forge_worker_cli.php --limit=1</code>.</div>
                </div>

                <div class="operator-card">
                    <h3>Consistency Check</h3>
                    <div class="muted">Quick Link (CLI): <code>php SCRIPTS/consistency_check.php --repair=simple</code></div>
                    <div class="muted">Internal-Key/IP-Whitelist bleibt Pflicht für Schreibaktionen.</div>
                </div>
            </div>
            <?php if (!$hasInternalAccess): ?>
                <div class="muted">Aktionen erfordern Internal-Key + IP-Whitelist.</div>
            <?php endif; ?>
        </section>

        <section id="scan-jobs" class="card" data-scan-jobs data-endpoint="index.php?ajax=jobs_list&amp;type=scan" data-manage="<?= $hasInternalAccess ? 'true' : 'false' ?>">
            <h2 class="section-title">Scan-Jobs</h2>
            <div class="line">
                <span class="muted" data-scan-jobs-poll>Polling inaktiv.</span>
                <button type="button" class="btn btn--xs btn--secondary" data-scan-jobs-refresh>Refresh</button>
            </div>
            <div class="job-list" data-scan-jobs-list>
                <div class="muted">Lade Scan-Jobs…</div>
            </div>
            <form class="inline-fields" data-scan-jobs-prune>
                <input type="number" name="older_than_days" min="1" step="1" placeholder="Älter als (Tage)">
                <input type="number" name="keep_last" min="0" step="1" placeholder="Keep last" value="0">
                <button type="submit" class="btn btn--xs btn--ghost">Prune finished</button>
            </form>
            <div class="panel" data-jobs-prune data-endpoint="jobs_prune.php">
                <div class="action-feedback" data-jobs-prune-message>
                    <div class="action-feedback-title">Bereit</div>
                    <div>Scan-Jobs gesammelt löschen.</div>
                </div>
                <div class="form-grid">
                    <button class="btn btn--danger" type="button" data-jobs-prune-button data-group="scan" data-status="done,error,cancelled" data-confirm="Alle done/error/cancelled Scan-Jobs löschen?">Delete done + error</button>
                    <button class="btn btn--secondary" type="button" data-jobs-prune-button data-group="scan" data-status="queued,pending" data-confirm="Alle queued/pending Scan-Jobs löschen?">Purge queue</button>
                    <button class="btn btn--ghost" type="button" data-jobs-prune-button data-group="scan" data-status="running" data-force="1" data-confirm="Running Scan-Jobs forcieren (cancel + delete)?">Force clear running</button>
                </div>
            </div>
            <div class="muted">Cancel/Delete nur für scanbezogene Jobs, Delete/Prune nur in done/error/canceled.</div>
        </section>

        <section id="importscan-jobs" class="card" data-jobs-prune data-endpoint="jobs_prune.php">
            <h2 class="section-title">Importscan-Jobs</h2>
            <div class="action-feedback" data-jobs-prune-message>
                <div class="action-feedback-title">Bereit</div>
                <div>Importscan-Jobs gesammelt löschen.</div>
            </div>
            <div class="form-grid">
                <button class="btn btn--danger" type="button" data-jobs-prune-button data-group="importscan" data-status="done,error,cancelled" data-confirm="Alle done/error/cancelled Importscan-Jobs löschen?">Delete done + error</button>
                <button class="btn btn--secondary" type="button" data-jobs-prune-button data-group="importscan" data-status="queued,pending" data-confirm="Alle queued/pending Importscan-Jobs löschen?">Purge queue</button>
                <button class="btn btn--ghost" type="button" data-jobs-prune-button data-group="importscan" data-status="running" data-force="1" data-confirm="Running Importscan-Jobs forcieren (cancel + delete)?">Force clear running</button>
            </div>
            <?php if (!$hasInternalAccess): ?>
                <div class="muted">Aktionen erfordern Internal-Key + IP-Whitelist.</div>
            <?php endif; ?>
        </section>

        <section id="job-center" class="card">
            <h2 class="section-title">Job-Center</h2>
            <div class="job-columns">
                <?php
                $jobPanels = [
                    'Running' => $jobSections['running'] ?? [],
                    'Queued'  => $jobSections['queued'] ?? [],
                    'Stuck'   => $jobSections['stuck'] ?? [],
                    'Recent Done/Error' => $jobSections['recent'] ?? [],
                ];
                ?>
                <?php foreach ($jobPanels as $panelTitle => $jobs): ?>
                    <div>
                        <h3<?= $panelTitle === 'Recent Done/Error' ? ' id="job-center-recent"' : '' ?>><?= htmlspecialchars($panelTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
                        <?php if ($jobs === []): ?>
                            <div class="muted">Keine Jobs.</div>
                        <?php else: ?>
                            <?php foreach ($jobs as $job): ?>
                                <?php $statusClass = sv_badge_class((string)($job['status'] ?? '')); ?>
                                <div class="job-card">
                                    <div class="job-line">
                                        <span class="badge badge--info">#<?= (int)$job['id'] ?></span>
                                        <span class="badge badge--info"><?= htmlspecialchars((string)$job['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                        <span class="<?= $statusClass ?>"><?= htmlspecialchars((string)$job['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                        <?php if (!empty($job['stuck'])): ?>
                                            <span class="badge badge--warn">stuck</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="job-meta">
                                        <?php if (!empty($job['media_id'])): ?>
                                            Medium: <a href="media_view.php?id=<?= htmlspecialchars((string)$job['media_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">#<?= htmlspecialchars((string)$job['media_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                                        <?php else: ?>
                                            Medium: —
                                        <?php endif; ?>
                                    </div>
                                    <div class="job-meta">
                                        Start: <?= htmlspecialchars((string)($job['started_at'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                        <?php if (!empty($job['age_label'])): ?>
                                            · Age <?= htmlspecialchars((string)$job['age_label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                        <?php endif; ?>
                                        <?php if (!empty($job['finished_at'])): ?>
                                            · Finish: <?= htmlspecialchars((string)$job['finished_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($job['model'])): ?>
                                        <div class="job-meta">Modell: <?= htmlspecialchars((string)$job['model'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($job['stuck_reason'])): ?>
                                        <div class="job-error">Stuck-Grund: <?= htmlspecialchars((string)$job['stuck_reason'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($job['error'])): ?>
                                        <div class="job-error">Fehler: <?= htmlspecialchars((string)$job['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                    <div class="job-actions">
                                        <?php if (in_array($job['status'], ['error', 'done', SV_JOB_STATUS_CANCELED], true)): ?>
                                            <form method="post">
                                                <input type="hidden" name="action" value="job_requeue">
                                                <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
                                                <?php if ($internalKey !== ''): ?>
                                                    <input type="hidden" name="internal_key" value="<?= htmlspecialchars($internalKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                                <?php endif; ?>
                                                <button type="submit" class="btn btn--xs btn--secondary">Requeue</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if (in_array($job['status'], ['queued', 'running'], true)): ?>
                                            <form method="post" onsubmit="return confirm('Job wirklich abbrechen?');">
                                                <input type="hidden" name="action" value="job_cancel">
                                                <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
                                                <?php if ($internalKey !== ''): ?>
                                                    <input type="hidden" name="internal_key" value="<?= htmlspecialchars($internalKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                                <?php endif; ?>
                                                <button type="submit" class="btn btn--xs btn--ghost">Cancel</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                    <details>
                                        <summary>Mehr anzeigen</summary>
                                        <div class="job-meta">ID: <?= (int)$job['id'] ?></div>
                                        <div class="job-meta">Status: <?= htmlspecialchars((string)$job['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                    </details>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section id="event-log" class="card">
            <h2 class="section-title">Ereignisverlauf</h2>
            <?php if ($events === []): ?>
                <div class="muted">Keine Ereignisse verfügbar.</div>
            <?php else: ?>
                <ul class="event-list">
                    <?php foreach ($events as $event): ?>
                        <?php $label = $knownActions[$event['action']] ?? $event['action']; ?>
                        <li class="event-item">
                            <strong><?= htmlspecialchars((string)$label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                            <span class="muted"><?= htmlspecialchars((string)($event['created_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                            <?php if (!empty($event['summary'])): ?>
                                <span class="muted"><?= htmlspecialchars((string)$event['summary'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
<?php sv_ui_footer(); ?>

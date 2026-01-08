<?php
declare(strict_types=1);

require_once __DIR__ . '/../SCRIPTS/common.php';
require_once __DIR__ . '/../SCRIPTS/db_helpers.php';
require_once __DIR__ . '/../SCRIPTS/security.php';
require_once __DIR__ . '/../SCRIPTS/operations.php';

try {
    $config = sv_load_config();
} catch (Throwable $e) {
    http_response_code(500);
    echo "<pre>CONFIG-Fehler: " . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</pre>";
    exit;
}

$configWarning = $config['_config_warning'] ?? null;
$internalKey   = isset($_GET['internal_key']) && is_string($_GET['internal_key']) ? trim($_GET['internal_key']) : '';

$isLocalRequest = sv_is_client_local($config);
if (!$isLocalRequest) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        header('Location: mediadb.php');
        exit;
    }
    sv_security_error(403, 'Forbidden.');
}

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
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$pdo instanceof PDO) {
        $actionError = 'Keine DB-Verbindung: ' . ($dbError ?? 'unbekannt');
    } else {
        sv_require_internal_access($config, 'dashboard_action');

        $action = is_string($_POST['action'] ?? null) ? trim($_POST['action']) : '';
        $allowedActions = ['scan_path', 'rescan_db', 'job_requeue', 'job_cancel'];

        if (!in_array($action, $allowedActions, true)) {
            $actionError = 'Ungültige Aktion.';
            $action = '';
        }

        if ($action === 'scan_path') {
            $lastPath = is_string($_POST['scan_path'] ?? null) ? trim($_POST['scan_path']) : '';
            $limit    = isset($_POST['scan_limit']) ? (int)$_POST['scan_limit'] : null;

            if ($limit !== null && $limit <= 0) {
                $limit = null;
            }

            if ($lastPath === '') {
                $actionError = 'Kein Pfad angegeben.';
            } elseif (mb_strlen($lastPath) > 500) {
                $actionError = 'Pfad zu lang (max. 500 Zeichen).';
            } else {
                [$logFile, $logger] = sv_create_operation_log($config, 'scan', $logLines, 50);

                try {
                    $job     = sv_create_scan_job($pdo, $config, $lastPath, $limit, $logger);
                    $worker  = sv_spawn_scan_worker($config, $job['payload']['path'] ?? $lastPath, null, $logger, null);
                    $jobId   = (int)($job['job_id'] ?? 0);

                    if ($jobId > 0) {
                        sv_merge_job_response_metadata($pdo, $jobId, [
                            '_sv_worker_pid'        => $worker['pid'],
                            '_sv_worker_started_at' => $worker['started'],
                        ]);
                    }

                    $actionMessage = 'Scan-Job eingereiht (#' . $jobId . ').';
                    if (!empty($worker['pid'])) {
                        $actionMessage .= ' Worker PID: ' . (int)$worker['pid'] . '.';
                    }
                    if (!empty($worker['unknown'])) {
                        $actionMessage .= ' Worker-Status unbekannt (Hintergrundstart).';
                    }

                    sv_audit_log($pdo, 'scan_start', 'fs', null, [
                        'limit'       => $limit,
                        'job_id'      => $jobId,
                        'worker_pid'  => $worker['pid'] ?? null,
                        'worker_note' => $worker['unknown'] ? 'pid_unknown' : 'pid_recorded',
                        'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? null,
                    ]);
                } catch (Throwable $e) {
                    $actionError = 'Scan-Fehler: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'rescan_db') {
            $limit   = isset($_POST['rescan_limit']) ? (int)$_POST['rescan_limit'] : null;
            $offset  = isset($_POST['rescan_offset']) ? (int)$_POST['rescan_offset'] : null;

            if ($limit !== null && $limit <= 0) {
                $limit = null;
            }
            if ($offset !== null && $offset < 0) {
                $offset = null;
            }

            [$logFile, $logger] = sv_create_operation_log($config, 'rescan', $logLines, 50);

            try {
                sv_run_rescan_operation($pdo, $config, $limit, $offset, $logger);
                $actionMessage = 'Rescan abgeschlossen.';
                sv_audit_log($pdo, 'rescan_start', 'fs', null, [
                    'limit'      => $limit,
                    'offset'     => $offset,
                    'log_file'   => $logFile,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                ]);
            } catch (Throwable $e) {
                $actionError = 'Rescan-Fehler: ' . $e->getMessage();
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

function sv_badge_class(string $status): string
{
    $status = strtolower($status);
    if (in_array($status, ['ok', 'done'], true)) {
        return 'badge badge-ok';
    }
    if (in_array($status, ['error', 'failed'], true)) {
        return 'badge badge-error';
    }
    if (in_array($status, ['running', 'queued', 'pending'], true)) {
        return 'badge badge-info';
    }
    if (in_array($status, ['warn', 'warning'], true)) {
        return 'badge badge-warn';
    }

    return 'badge';
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Operator Dashboard – SuperVisOr</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f4f6f8;
            --card: #ffffff;
            --text: #1b1f24;
            --muted: #59636e;
            --border: #e0e5ea;
            --accent: #1f6feb;
            --warn: #f2b400;
            --danger: #d1242f;
            --ok: #1a7f37;
        }

        body {
            margin: 0;
            font-family: "Segoe UI", system-ui, -apple-system, sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }

        .container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 24px;
        }

        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }

        .header h1 {
            margin: 0 0 4px 0;
            font-size: 2rem;
        }

        .header p {
            margin: 0;
            color: var(--muted);
        }

        .header-actions {
            display: flex;
            flex-direction: column;
            gap: 8px;
            align-items: flex-end;
        }

        .button {
            display: inline-block;
            padding: 10px 16px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--card);
            color: var(--text);
            font-weight: 600;
        }

        .button.primary {
            background: var(--accent);
            color: #fff;
            border-color: var(--accent);
        }

        .nav-links {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            font-size: 0.9rem;
        }

        .banner {
            padding: 12px 14px;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: #fff;
            margin-bottom: 12px;
        }

        .banner.warn { border-color: #f0df9f; background: #fff7df; color: #7b5a00; }
        .banner.error { border-color: #f2b3b3; background: #ffe9ea; color: #8a1b1b; }
        .banner.success { border-color: #c7e7c0; background: #eff9f0; color: #1a5c2e; }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 16px;
        }

        .section-title {
            margin: 0 0 12px 0;
            font-size: 1.2rem;
        }

        .health-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 12px;
        }

        .health-row {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .health-row .line {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            font-size: 0.95rem;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.75rem;
            background: #eef2f6;
            color: #3c4a5a;
        }

        .badge-ok { background: #e5f6eb; color: var(--ok); }
        .badge-warn { background: #fff4cf; color: #7b5a00; }
        .badge-error { background: #ffe0e2; color: var(--danger); }
        .badge-info { background: #e0edff; color: #2456b3; }

        .job-columns {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 12px;
        }

        .job-card {
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 12px;
            background: #fafbfc;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .job-line {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
        }

        .job-meta {
            color: var(--muted);
            font-size: 0.85rem;
        }

        .job-error {
            color: var(--danger);
            font-size: 0.85rem;
        }

        .job-actions form { display: inline; }
        .job-actions button {
            border: 1px solid var(--border);
            background: #fff;
            border-radius: 6px;
            padding: 4px 8px;
            margin-right: 6px;
        }

        .operator-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 12px;
        }

        .operator-card {
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 12px;
            background: #fff;
        }

        .operator-card h3 {
            margin: 0 0 8px 0;
            font-size: 1rem;
        }

        .inline-fields {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .inline-fields input[type="text"],
        .inline-fields input[type="number"] {
            padding: 6px 8px;
            border-radius: 6px;
            border: 1px solid var(--border);
        }

        .event-list {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .event-item {
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 10px 12px;
            background: #fff;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        details summary { cursor: pointer; color: var(--accent); }
        .muted { color: var(--muted); }
    </style>
</head>
<body>
    <div class="container">
        <header class="header">
            <div>
                <h1>Start</h1>
                <p>Operator-Control-Center für Galerie, Health und Jobs.</p>
            </div>
            <div class="header-actions">
                <a class="button primary" href="mediadb.php">Galerie öffnen</a>
                <div class="nav-links">
                    <a href="mediadb.php">Letzte Medien</a>
                    <a href="#job-center-recent">Letzte Fehler</a>
                    <a href="#job-center">Job-Center</a>
                    <a href="#health-snapshot">Health</a>
                    <a href="#event-log">Ereignisse</a>
                </div>
            </div>
        </header>

        <?php if (!empty($configWarning)): ?>
            <div class="banner warn">
                <?= htmlspecialchars($configWarning, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($dbError !== null): ?>
            <div class="banner error">
                <strong>DB-Verbindung fehlgeschlagen:</strong>
                <?= htmlspecialchars(sv_sanitize_error_message($dbError), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                <div class="muted">Quelle: <?= htmlspecialchars((string)($config['_config_path'] ?? 'unbekannt'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            </div>
        <?php endif; ?>

        <?php if ($actionMessage !== null): ?>
            <div class="banner success">
                <?= htmlspecialchars($actionMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($actionError !== null): ?>
            <div class="banner error">
                <?= htmlspecialchars(sv_sanitize_error_message($actionError), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </div>
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
                            <span class="badge <?= $dbError === null ? 'badge-ok' : 'badge-error' ?>">
                                <?= $dbError === null ? 'connected' : 'offline' ?>
                            </span>
                            <span>Treiber: <?= htmlspecialchars((string)($dbHealth['driver'] ?? 'unbekannt'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                            <span>Migrationen: <?= htmlspecialchars((string)($dbHealth['pending_migrations_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                            <span>Issues: <?= htmlspecialchars((string)($dbHealth['issues_total'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                        </div>
                        <details>
                            <summary>Show more</summary>
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
                            <span class="badge badge-info">queued <?= (int)($jobCounts['queued'] ?? 0) ?></span>
                            <span class="badge badge-info">running <?= (int)($jobCounts['running'] ?? 0) ?></span>
                            <span class="badge <?= ($jobHealth['stuck_jobs'] ?? 0) > 0 ? 'badge-warn' : 'badge-ok' ?>">
                                stuck <?= (int)($jobHealth['stuck_jobs'] ?? 0) ?>
                            </span>
                            <span>Letzter Fehler: <?= htmlspecialchars((string)($jobHealth['last_error']['message'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                        </div>
                        <details>
                            <summary>Show more</summary>
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
                            <summary>Show more</summary>
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
                            <input type="text" name="scan_path" placeholder="Pfad" size="32">
                            <input type="number" name="scan_limit" min="1" step="1" placeholder="Limit">
                            <?php if ($internalKey !== ''): ?>
                                <input type="hidden" name="internal_key" value="<?= htmlspecialchars($internalKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                            <?php endif; ?>
                            <button type="submit">Scan starten</button>
                        </div>
                    </form>
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
                            <button type="submit">Rescan starten</button>
                        </div>
                    </form>
                    <div class="muted">Letzter Lauf: <?= htmlspecialchars((string)($lastRuns['rescan_start'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                </div>

                <div class="operator-card">
                    <h3>Forge Worker Status</h3>
                    <div class="line">
                        <span class="badge badge-info">open <?= (int)($forgeOverview['open'] ?? 0) ?></span>
                        <span class="badge badge-ok">done <?= (int)($forgeOverview['done'] ?? 0) ?></span>
                        <span class="badge badge-error">error <?= (int)($forgeOverview['error'] ?? 0) ?></span>
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
                <div class="muted" style="margin-top: 10px;">Aktionen erfordern Internal-Key + IP-Whitelist.</div>
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
                                        <span class="badge badge-info">#<?= (int)$job['id'] ?></span>
                                        <span class="badge badge-info"><?= htmlspecialchars((string)$job['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                        <span class="<?= $statusClass ?>"><?= htmlspecialchars((string)$job['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                        <?php if (!empty($job['stuck'])): ?>
                                            <span class="badge badge-warn">stuck</span>
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
                                                <button type="submit">Requeue</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if (in_array($job['status'], ['queued', 'running'], true)): ?>
                                            <form method="post" onsubmit="return confirm('Job wirklich abbrechen?');">
                                                <input type="hidden" name="action" value="job_cancel">
                                                <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
                                                <?php if ($internalKey !== ''): ?>
                                                    <input type="hidden" name="internal_key" value="<?= htmlspecialchars($internalKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                                <?php endif; ?>
                                                <button type="submit">Cancel</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                    <details>
                                        <summary>Show more</summary>
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
    </div>
</body>
</html>

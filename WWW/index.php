<?php
declare(strict_types=1);

require_once __DIR__ . '/../SCRIPTS/common.php';
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
$dsn           = $config['db']['dsn']        ?? '';
$user          = $config['db']['user']       ?? null;
$password      = $config['db']['password']   ?? null;
$options       = $config['db']['options']    ?? [];

try {
    $pdo = new PDO((string)$dsn, $user, $password, $options);
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    http_response_code(500);
    echo "<pre>DB-Fehler: " . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</pre>";
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'scan_jobs') {
    $pathFilter = isset($_GET['path']) && is_string($_GET['path']) ? trim($_GET['path']) : null;
    $jobs       = sv_fetch_scan_jobs($pdo, $pathFilter, 25);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['jobs' => $jobs], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$lastPath    = '';
$jobMessage  = null;
$logFile     = null;
$logLines    = [];
$statErrors  = [];
$mediaStats  = [];
$promptStats = [];
$promptQualitySummary = ['A' => 0, 'B' => 0, 'C' => 0];
$tagStats    = [];
$scanStats   = [];
$metaStats   = [];
$importStats = [];
$jobStats    = [];
$forgeJobOverview = [];
$jobCenterMessage = null;
$jobCenterError   = null;
$jobCenterLog     = [];
$jobCenterJobs    = [];
$jobCenterFilters = [
    'job_type' => '',
    'status'   => '',
    'media_id' => null,
    'since'    => null,
];
$lastRuns    = [];
$integrityStatus = ['media_with_issues' => 0];
$knownActions = [
    'scan_start'           => 'Scan (Dashboard)',
    'rescan_start'         => 'Rescan (Dashboard)',
    'filesync_start'       => 'Filesync (Dashboard)',
    'prompts_rebuild'      => 'Prompt-Rebuild (Dashboard)',
    'prompts_rebuild_missing' => 'Prompt-Rebuild fehlender Kerndaten',
    'consistency_report'   => 'Consistency-Report',
    'consistency_repair'   => 'Consistency-Repair',
    'integrity_simple_repair' => 'Einfache Reparatur',
    'db_backup'            => 'DB-Backup',
    'migrate'              => 'Migration',
    'cleanup_missing'      => 'Cleanup Missing',
];

$comfortRebuildLimit = 100;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sv_require_internal_access($config, 'dashboard_action');

    $action         = is_string($_POST['action'] ?? null) ? trim($_POST['action']) : '';
    $allowedActions = ['scan_path', 'rescan_db', 'filesync', 'prompts_rebuild', 'prompts_rebuild_missing', 'consistency_check', 'integrity_simple_repair', 'job_requeue', 'job_cancel'];

    if (!in_array($action, $allowedActions, true)) {
        $jobMessage = 'Ungültige Aktion.';
        $action     = '';
    }

    $logLines = [];

    if ($action === 'scan_path') {
        $lastPath = is_string($_POST['scan_path'] ?? null) ? trim($_POST['scan_path']) : '';
        $limit    = isset($_POST['scan_limit']) ? (int)$_POST['scan_limit'] : null;

        if ($limit !== null && $limit <= 0) {
            $limit = null;
        }

        if ($lastPath === '') {
            $jobMessage = 'Kein Pfad angegeben.';
        } elseif (mb_strlen($lastPath) > 500) {
            $jobMessage = 'Pfad zu lang (max. 500 Zeichen).';
        } else {
            [$logFile, $logger] = sv_create_operation_log($config, 'scan', $logLines, 50);

            try {
                $job     = sv_create_scan_job($pdo, $config, $lastPath, $limit, $logger);
                $worker  = sv_spawn_scan_worker($config, $job['payload']['path'] ?? $lastPath, null, $logger);
                $jobId   = (int)($job['job_id'] ?? 0);

                if ($jobId > 0) {
                    sv_merge_job_response_metadata($pdo, $jobId, [
                        '_sv_worker_pid'        => $worker['pid'],
                        '_sv_worker_started_at' => $worker['started'],
                    ]);
                }

                $jobMessage = 'Scan-Job eingereiht (#' . $jobId . ').';
                if (!empty($worker['pid'])) {
                    $jobMessage .= ' Worker PID: ' . (int)$worker['pid'] . '.';
                }
                if (!empty($worker['unknown'])) {
                    $jobMessage .= ' Worker-Status unbekannt (Hintergrundstart).';
                }

                sv_audit_log($pdo, 'scan_start', 'fs', null, [
                    'path'        => $lastPath,
                    'limit'       => $limit,
                    'log_file'    => $logFile,
                    'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? null,
                    'job_id'      => $jobId,
                    'worker_pid'  => $worker['pid'] ?? null,
                    'worker_note' => $worker['unknown'] ? 'pid_unknown' : 'pid_recorded',
                ]);
            } catch (Throwable $e) {
                $jobMessage = 'Scan-Fehler: ' . $e->getMessage();
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
            $jobMessage = 'Rescan abgeschlossen.';
            sv_audit_log($pdo, 'rescan_start', 'fs', null, [
                'limit'      => $limit,
                'offset'     => $offset,
                'log_file'   => $logFile,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);
        } catch (Throwable $e) {
            $jobMessage = 'Rescan-Fehler: ' . $e->getMessage();
        }
    } elseif ($action === 'filesync') {
        $limit   = isset($_POST['filesync_limit']) ? (int)$_POST['filesync_limit'] : null;
        $offset  = isset($_POST['filesync_offset']) ? (int)$_POST['filesync_offset'] : null;

        if ($limit !== null && $limit <= 0) {
            $limit = null;
        }
        if ($offset !== null && $offset < 0) {
            $offset = null;
        }

        [$logFile, $logger] = sv_create_operation_log($config, 'filesync', $logLines, 50);

        try {
            sv_run_filesync_operation($pdo, $config, $limit, $offset, $logger);
            $jobMessage = 'Filesync abgeschlossen.';
            sv_audit_log($pdo, 'filesync_start', 'fs', null, [
                'limit'      => $limit,
                'offset'     => $offset,
                'log_file'   => $logFile,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);
        } catch (Throwable $e) {
            $jobMessage = 'Filesync-Fehler: ' . $e->getMessage();
        }
    } elseif ($action === 'prompts_rebuild') {
        $limit   = isset($_POST['rebuild_limit']) ? (int)$_POST['rebuild_limit'] : null;
        $offset  = isset($_POST['rebuild_offset']) ? (int)$_POST['rebuild_offset'] : null;

        if ($limit !== null && $limit <= 0) {
            $limit = null;
        }
        if ($offset !== null && $offset < 0) {
            $offset = null;
        }

        [$logFile, $logger] = sv_create_operation_log($config, 'prompts', $logLines, 50);

        try {
            sv_run_prompts_rebuild_operation($pdo, $config, $limit, $offset, $logger);
            $jobMessage = 'Prompt-Rebuild abgeschlossen.';
            sv_audit_log($pdo, 'prompts_rebuild', 'fs', null, [
                'limit'      => $limit,
                'offset'     => $offset,
                'log_file'   => $logFile,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);
        } catch (Throwable $e) {
            $jobMessage = 'Prompt-Rebuild-Fehler: ' . $e->getMessage();
        }
    } elseif ($action === 'prompts_rebuild_missing') {
        [$logFile, $logger] = sv_create_operation_log($config, 'prompts_missing', $logLines, 10);

        try {
            $result = sv_run_prompt_rebuild_missing($pdo, $config, $logger, $comfortRebuildLimit);
            $jobMessage = 'Komfort-Rebuild abgeschlossen: gefunden=' . (int)($result['found'] ?? 0)
                . ', verarbeitet=' . (int)($result['processed'] ?? 0)
                . ', übersprungen=' . (int)($result['skipped'] ?? 0)
                . ', Fehler=' . (int)($result['errors'] ?? 0);
            sv_audit_log($pdo, 'prompts_rebuild_missing', 'fs', null, [
                'limit'     => $comfortRebuildLimit,
                'found'     => $result['found'] ?? 0,
                'processed' => $result['processed'] ?? 0,
                'skipped'   => $result['skipped'] ?? 0,
                'errors'    => $result['errors'] ?? 0,
                'log_file'  => $logFile,
                'user_agent'=> $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);
        } catch (Throwable $e) {
            $jobMessage = 'Komfort-Rebuild-Fehler: ' . $e->getMessage();
        }
    } elseif ($action === 'consistency_check') {
        $mode   = is_string($_POST['consistency_mode'] ?? null) ? $_POST['consistency_mode'] : 'report';
        $logger = sv_operation_logger(null, $logLines);

        try {
            $result   = sv_run_consistency_operation($pdo, $config, $mode, $logger);
            $logFile  = $result['log_file'] ?? null;
            $jobMessage = 'Consistency-Check abgeschlossen.';
            $auditAction = $mode === 'simple' ? 'consistency_repair' : 'consistency_report';
            sv_audit_log($pdo, $auditAction, 'db', null, [
                'mode'       => $mode,
                'log_file'   => $logFile,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);
        } catch (Throwable $e) {
            $jobMessage = 'Consistency-Check-Fehler: ' . $e->getMessage();
        }
    } elseif ($action === 'integrity_simple_repair') {
        [$logFile, $logger] = sv_create_operation_log($config, 'integrity_simple', $logLines, 20);
        try {
            $changes = sv_run_simple_integrity_repair($pdo, $logger);
            $jobMessage = 'Einfache Reparatur abgeschlossen: missing gesetzt=' . (int)$changes['status_missing_set']
                . ', Tags entfernt=' . (int)$changes['tag_rows_removed']
                . ', leere Prompts entfernt=' . (int)$changes['prompts_removed'];
            sv_audit_log($pdo, 'integrity_simple_repair', 'db', null, [
                'changes'    => $changes,
                'log_file'   => $logFile,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);
        } catch (Throwable $e) {
            $jobMessage = 'Einfache Reparatur fehlgeschlagen: ' . $e->getMessage();
        }
    } elseif ($action === 'job_requeue') {
        $jobId = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
        $logger = sv_operation_logger(null, $jobCenterLog);

        if ($jobId <= 0) {
            $jobCenterError = 'Job-ID fehlt oder ist ungültig.';
        } else {
            try {
                $result = sv_requeue_job($pdo, $jobId, $logger);
                $jobCenterMessage = $result['message'] ?? 'Job erneut eingereiht.';
            } catch (Throwable $e) {
                $jobCenterError = 'Requeue fehlgeschlagen: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'job_cancel') {
        $jobId = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
        $logger = sv_operation_logger(null, $jobCenterLog);

        if ($jobId <= 0) {
            $jobCenterError = 'Job-ID fehlt oder ist ungültig.';
        } else {
            try {
                $result = sv_cancel_job($pdo, $jobId, $logger);
                $jobCenterMessage = $result['message'] ?? 'Job abgebrochen.';
            } catch (Throwable $e) {
                $jobCenterError = 'Abbruch fehlgeschlagen: ' . $e->getMessage();
            }
        }
    }
}

$jobFilterType   = isset($_GET['job_type']) && is_string($_GET['job_type']) ? trim($_GET['job_type']) : '';
$jobFilterStatus = isset($_GET['job_status']) && is_string($_GET['job_status']) ? trim($_GET['job_status']) : '';
$jobFilterMedia  = isset($_GET['job_media_id']) ? (int)$_GET['job_media_id'] : null;
$jobFilterRange  = isset($_GET['job_range']) && is_string($_GET['job_range']) ? trim($_GET['job_range']) : '';
$jobCenterInternalKey = isset($_GET['internal_key']) && is_string($_GET['internal_key']) ? trim($_GET['internal_key']) : '';

if ($jobFilterType !== '') {
    $jobCenterFilters['job_type'] = $jobFilterType;
}
if ($jobFilterStatus !== '') {
    $jobCenterFilters['status'] = $jobFilterStatus;
}
if ($jobFilterMedia !== null && $jobFilterMedia > 0) {
    $jobCenterFilters['media_id'] = $jobFilterMedia;
}
if ($jobFilterRange !== '') {
    $ranges = [
        '24h' => 86400,
        '7d'  => 7 * 86400,
        '30d' => 30 * 86400,
    ];
    if (isset($ranges[$jobFilterRange])) {
        $jobCenterFilters['since'] = time() - $ranges[$jobFilterRange];
    }
}

try {
    $mediaStats['total'] = (int)$pdo->query('SELECT COUNT(*) FROM media')->fetchColumn();
    $mediaStats['byType'] = $pdo->query('SELECT type, COUNT(*) AS cnt FROM media GROUP BY type ORDER BY type')
        ->fetchAll(PDO::FETCH_ASSOC);
    $mediaStats['nsfw'] = $pdo->query(
        'SELECT SUM(CASE WHEN has_nsfw = 1 THEN 1 ELSE 0 END) AS nsfw, ' .
        'SUM(CASE WHEN has_nsfw = 1 THEN 0 ELSE 1 END) AS safe FROM media'
    )->fetch(PDO::FETCH_ASSOC);
    $mediaStats['byStatus'] = $pdo->query('SELECT status, COUNT(*) AS cnt FROM media GROUP BY status ORDER BY status')
        ->fetchAll(PDO::FETCH_ASSOC);
    $mediaStats['byRating'] = $pdo->query('SELECT rating, COUNT(*) AS cnt FROM media GROUP BY rating ORDER BY rating')
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $statErrors['media'] = $e->getMessage();
}

try {
    $promptStats['total'] = (int)$pdo->query('SELECT COUNT(*) FROM prompts')->fetchColumn();
    $promptStats['mediaWithPrompts'] = (int)$pdo->query(
        'SELECT COUNT(*) FROM media WHERE EXISTS (SELECT 1 FROM prompts p WHERE p.media_id = media.id)'
    )->fetchColumn();
    $promptStats['mediaWithoutPrompts'] = (int)$pdo->query(
        'SELECT COUNT(*) FROM media WHERE NOT EXISTS (SELECT 1 FROM prompts p WHERE p.media_id = media.id)'
    )->fetchColumn();
} catch (Throwable $e) {
    $statErrors['prompts'] = $e->getMessage();
}

try {
    $qualityStmt = $pdo->query(
        'SELECT p.prompt, p.width, p.height FROM prompts p '
        . 'JOIN (SELECT MAX(id) AS id FROM prompts GROUP BY media_id) latest ON latest.id = p.id '
        . 'ORDER BY p.id DESC LIMIT 2000'
    );
    $qualityRows = $qualityStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($qualityRows as $row) {
        $quality = sv_prompt_quality_from_text(
            $row['prompt'] ?? null,
            isset($row['width']) ? (int)$row['width'] : null,
            isset($row['height']) ? (int)$row['height'] : null
        );
        $class = $quality['quality_class'] ?? null;
        if ($class !== null && isset($promptQualitySummary[$class])) {
            $promptQualitySummary[$class]++;
        }
    }
} catch (Throwable $e) {
    $statErrors['prompt_quality'] = $e->getMessage();
}

try {
    $tagStats['total'] = (int)$pdo->query('SELECT COUNT(*) FROM tags')->fetchColumn();
    $tagStats['locked'] = (int)$pdo->query('SELECT SUM(CASE WHEN locked = 1 THEN 1 ELSE 0 END) FROM tags')->fetchColumn();
    $tagStats['relations'] = (int)$pdo->query('SELECT COUNT(*) FROM media_tags')->fetchColumn();
} catch (Throwable $e) {
    $statErrors['tags'] = $e->getMessage();
}

try {
    $scanStats['total'] = (int)$pdo->query('SELECT COUNT(*) FROM scan_results')->fetchColumn();
    $scanStats['mediaWithScan'] = (int)$pdo->query(
        'SELECT COUNT(*) FROM media WHERE EXISTS (SELECT 1 FROM scan_results s WHERE s.media_id = media.id)'
    )->fetchColumn();
    $scanStats['mediaWithoutScan'] = (int)$pdo->query(
        'SELECT COUNT(*) FROM media WHERE NOT EXISTS (SELECT 1 FROM scan_results s WHERE s.media_id = media.id)'
    )->fetchColumn();
} catch (Throwable $e) {
    $statErrors['scan_results'] = $e->getMessage();
}

try {
    $metaStats['total'] = (int)$pdo->query('SELECT COUNT(*) FROM media_meta')->fetchColumn();
    $metaStats['sources'] = (int)$pdo->query('SELECT COUNT(DISTINCT source) FROM media_meta')->fetchColumn();
} catch (Throwable $e) {
    $statErrors['media_meta'] = $e->getMessage();
}

try {
    $importStats['byStatus'] = $pdo->query('SELECT status, COUNT(*) AS cnt FROM import_log GROUP BY status ORDER BY status')
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $statErrors['import_log'] = $e->getMessage();
}

try {
    $jobStats['byStatus'] = $pdo->query('SELECT status, COUNT(*) AS cnt FROM jobs GROUP BY status ORDER BY status')
        ->fetchAll(PDO::FETCH_ASSOC);
    $jobStats['total'] = array_sum(array_map(static function ($row) {
        return (int)($row['cnt'] ?? 0);
    }, $jobStats['byStatus']));
} catch (Throwable $e) {
    $statErrors['jobs'] = $e->getMessage();
}

try {
    $forgeJobOverview = sv_forge_job_overview($pdo);
} catch (Throwable $e) {
    $statErrors['forge_jobs'] = $e->getMessage();
}

try {
    $jobCenterJobs = sv_list_jobs($pdo, $jobCenterFilters, 100);
} catch (Throwable $e) {
    $jobCenterError = 'Job-Liste konnte nicht geladen werden: ' . $e->getMessage();
}

try {
    $integrityReport = sv_collect_integrity_issues($pdo);
    $integrityStatus['media_with_issues'] = count($integrityReport['by_media'] ?? []);
} catch (Throwable $e) {
    $statErrors['integrity'] = $e->getMessage();
}

if (!empty($knownActions)) {
    try {
        $placeholders = implode(', ', array_fill(0, count($knownActions), '?'));
        $stmt = $pdo->prepare(
            "SELECT action, MAX(created_at) AS last_at FROM audit_log WHERE action IN ($placeholders) GROUP BY action"
        );
        $stmt->execute(array_keys($knownActions));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $actionKey = $row['action'] ?? '';
            if ($actionKey !== '' && isset($knownActions[$actionKey])) {
                $lastRuns[$actionKey] = $row['last_at'];
            }
        }
    } catch (Throwable $e) {
        $statErrors['audit_log'] = $e->getMessage();
    }
}

$cliEntries = [
    [
        'name'        => 'scan_path_cli.php',
        'category'    => 'Import/Scan',
        'description' => 'Durchsucht ein Verzeichnis und legt neue Media-Einträge inklusive Scans an.',
        'example'     => 'php SCRIPTS\\scan_path_cli.php "D:\\Import" --limit=250',
    ],
    [
        'name'        => 'scan_worker_cli.php',
        'category'    => 'Import/Scan',
        'description' => 'Abarbeitung der scan_path-Queue im Hintergrund (keine Web-Timeouts).',
        'example'     => 'php SCRIPTS\\scan_worker_cli.php --limit=5',
    ],
    [
        'name'        => 'rescan_cli.php',
        'category'    => 'Import/Scan',
        'description' => 'Erneuert fehlende Scan-Ergebnisse für bestehende Medien.',
        'example'     => 'php SCRIPTS\\rescan_cli.php --limit=200',
    ],
    [
        'name'        => 'filesync_cli.php',
        'category'    => 'Import/Scan',
        'description' => 'Gleicht DB-Status und Dateisystem ab und markiert fehlende Pfade als missing.',
        'example'     => 'php SCRIPTS\\filesync_cli.php --limit=500 --offset=0',
    ],
    [
        'name'        => 'db_backup.php',
        'category'    => 'Wartung',
        'description' => 'Erzeugt ein Backup der Datenbank unter BACKUPS/ und protokolliert den Lauf.',
        'example'     => 'php SCRIPTS\\db_backup.php',
    ],
    [
        'name'        => 'migrate.php',
        'category'    => 'Wartung',
        'description' => 'Spielt verfügbare Migrationen aus SCRIPTS/migrations/ ein.',
        'example'     => 'php SCRIPTS\\migrate.php',
    ],
    [
        'name'        => 'forge_worker_cli.php',
        'category'    => 'Forge',
        'description' => 'Abarbeitung der queued Forge-Regenerations-Jobs (Replace-in-place) ohne Web-Wartezeiten.',
        'example'     => 'php SCRIPTS\\forge_worker_cli.php --limit=1',
    ],
    [
        'name'        => 'consistency_check.php',
        'category'    => 'Wartung',
        'description' => 'Prüft die DB-Konsistenz; optional mit --repair=simple für einfache Fixes.',
        'example'     => 'php SCRIPTS\\consistency_check.php --repair=simple',
    ],
    [
        'name'        => 'cleanup_missing_cli.php',
        'category'    => 'Wartung',
        'description' => 'Bereinigt Media-Einträge mit Status missing gemäß Konfiguration.',
        'example'     => 'php SCRIPTS\\cleanup_missing_cli.php --limit=200',
    ],
    [
        'name'        => 'meta_inspect.php',
        'category'    => 'Inspektor',
        'description' => 'Textausgabe von Prompts und media_meta-Einträgen pro Medium.',
        'example'     => 'php SCRIPTS\\meta_inspect.php --limit=20',
    ],
    [
        'name'        => 'db_inspect.php',
        'category'    => 'Inspektor',
        'description' => 'Zeigt Metadaten zur Datenbank wie Tabellen- und Indexgrößen an.',
        'example'     => 'php SCRIPTS\\db_inspect.php',
    ],
    [
        'name'        => 'exif_prompts_cli.php',
        'category'    => 'Inspektor',
        'description' => 'Liest EXIF/Metadata aus Bildern und speichert sie als Prompts.',
        'example'     => 'php SCRIPTS\\exif_prompts_cli.php "D:\\Fotos"',
    ],
    [
        'name'        => 'show_prompts_columns.php',
        'category'    => 'Inspektor',
        'description' => 'Listet Prompt-Spalten und erkannte Felder für Debug-Zwecke.',
        'example'     => 'php SCRIPTS\\show_prompts_columns.php',
    ],
    [
        'name'        => 'init_db.php',
        'category'    => 'Setup',
        'description' => 'Initiales Anlegen der Datenbank basierend auf DB/schema.sql.',
        'example'     => 'php SCRIPTS\\init_db.php',
    ],
];
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>SuperVisOr</title>
    <style>
        body { font-family: sans-serif; }
        nav a { margin-right: 1rem; }
    </style>
</head>
<body>
    <h1>SuperVisOr</h1>

    <nav>
        <a href="index.php">Dashboard</a>
        <a href="mediadb.php">Media-Datenbank</a>
    </nav>

    <?php if (!empty($configWarning)): ?>
        <div style="padding: 0.6rem 0.8rem; background: #fff3cd; color: #7f4e00; border: 1px solid #ffeeba; margin-top: 0.6rem;">
            <?= htmlspecialchars($configWarning, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <p style="color: #555; font-size: 0.9rem;">Hinweis: Das neue Card-Grid liegt unter <code>mediadb.php</code>; die alte Grid-Ansicht (<code>media.php</code>) bleibt nur als Legacy-Option bestehen.</p>

    <h2>DB-Status</h2>
    <p>DB-Verbindung: OK</p>
    <ul>
        <?php foreach ($tables as $t): ?>
            <li><?= htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
        <?php endforeach; ?>
    </ul>

    <h2>Pfad scannen (Import)</h2>
    <form method="post">
        <input type="hidden" name="action" value="scan_path">
        <label>
            Pfad:
            <input type="text" name="scan_path" size="80"
                   value="<?= htmlspecialchars($lastPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        </label>
        <label>
            Limit (optional):
            <input type="number" name="scan_limit" min="1" step="1">
        </label>
        <button type="submit">Scan starten</button>
    </form>

    <div id="scan-job-status">
        <h3>Scan-Queue (asynchron)</h3>
        <p>Scans laufen jetzt ausschließlich als Jobs über den Scan-Worker.</p>
        <div id="scan-jobs-list">Lade Scan-Status ...</div>
    </div>

    <h2>Bestehende Medien neu scannen (nur ohne Scan-Ergebnis)</h2>
    <form method="post">
        <input type="hidden" name="action" value="rescan_db">
        <label>
            Limit (optional):
            <input type="number" name="rescan_limit" min="1" step="1">
        </label>
        <label>
            Offset (optional):
            <input type="number" name="rescan_offset" min="0" step="1">
        </label>
        <button type="submit">Rescan starten</button>
    </form>

    <h2>DB / Dateisystem abgleichen (Status active/missing)</h2>
    <form method="post">
        <input type="hidden" name="action" value="filesync">
        <label>
            Limit (optional):
            <input type="number" name="filesync_limit" min="1" step="1">
        </label>
        <label>
            Offset (optional):
            <input type="number" name="filesync_offset" min="0" step="1">
        </label>
        <button type="submit">Filesync starten</button>
    </form>

    <h2>Prompts aus bestehenden Dateien neu aufbauen</h2>
    <form method="post">
        <input type="hidden" name="action" value="prompts_rebuild">
        <label>
            Limit (optional):
            <input type="number" name="rebuild_limit" min="1" step="1">
        </label>
        <label>
            Offset (optional):
            <input type="number" name="rebuild_offset" min="0" step="1">
        </label>
        <button type="submit">Prompt-Rebuild starten</button>
    </form>

    <h2>Komfort-Rebuild fehlender Prompt-Kerndaten</h2>
    <form method="post">
        <input type="hidden" name="action" value="prompts_rebuild_missing">
        <p>Startet Rebuild nur für Medien mit fehlenden Prompt-Kerndaten (internes Limit: <?= (int)$comfortRebuildLimit ?> Items).</p>
        <button type="submit">Rebuild fehlender Prompts</button>
    </form>

    <h2>Konsistenzprüfung</h2>
    <form method="post">
        <input type="hidden" name="action" value="consistency_check">
        <label>
            Modus:
            <select name="consistency_mode">
                <option value="report">Report</option>
                <option value="simple">Report + einfache Reparaturen</option>
            </select>
        </label>
        <button type="submit">Consistency-Check starten</button>
    </form>

    <h2>Integritätsstatus</h2>
    <?php if (isset($statErrors['integrity'])): ?>
        <p>Fehler bei der Integritätsanalyse: <?= htmlspecialchars((string)$statErrors['integrity'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    <?php else: ?>
        <p>Medien mit erkannten Problemen: <?= htmlspecialchars((string)$integrityStatus['media_with_issues'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    <?php endif; ?>
    <p>Hinweis: Anzeige ist nur lesend und nutzt die Prüfungen aus operations.php.</p>

    <form method="post">
        <input type="hidden" name="action" value="integrity_simple_repair">
        <p><strong>Einfache Reparatur durchführen</strong> (setzt fehlende Dateien auf Status missing, entfernt leere Prompts und Tag-Einträge ohne Confidence).</p>
        <button type="submit">Einfache Reparatur durchführen</button>
    </form>

    <?php if ($jobMessage !== null): ?>
        <h3>Status</h3>
        <p><?= htmlspecialchars($jobMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php if ($logFile !== null): ?>
            <p>Log-Datei: <?= htmlspecialchars($logFile, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if (!empty($logLines)): ?>
            <details>
                <summary>Letzte Log-Zeilen</summary>
                <pre><?php foreach ($logLines as $line) { echo htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n"; } ?></pre>
            </details>
        <?php endif; ?>
    <?php endif; ?>

    <h2>Statistik</h2>

    <h3>Media</h3>
    <?php if (isset($statErrors['media'])): ?>
        <p>Fehler bei Media-Statistiken: <?= htmlspecialchars((string)$statErrors['media'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    <?php else: ?>
        <ul>
            <li>Gesamt: <?= htmlspecialchars((string)($mediaStats['total'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
            <li>NSFW: <?= htmlspecialchars((string)($mediaStats['nsfw']['nsfw'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> | Ohne NSFW: <?= htmlspecialchars((string)($mediaStats['nsfw']['safe'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
        </ul>
        <table border="1" cellpadding="4" cellspacing="0">
            <tr><th>Typ</th><th>Anzahl</th></tr>
            <?php foreach ($mediaStats['byType'] as $row): ?>
                <tr>
                    <td><?= htmlspecialchars((string)$row['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string)$row['cnt'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <table border="1" cellpadding="4" cellspacing="0">
            <tr><th>Status</th><th>Anzahl</th></tr>
            <?php foreach ($mediaStats['byStatus'] as $row): ?>
                <tr>
                    <td><?= htmlspecialchars((string)$row['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string)$row['cnt'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <table border="1" cellpadding="4" cellspacing="0">
            <tr><th>Rating</th><th>Anzahl</th></tr>
            <?php foreach ($mediaStats['byRating'] as $row): ?>
                <tr>
                    <td><?= htmlspecialchars((string)$row['rating'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string)$row['cnt'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <h3>Prompts</h3>
    <?php if (isset($statErrors['prompts'])): ?>
        <p>Fehler bei Prompt-Statistiken: <?= htmlspecialchars((string)$statErrors['prompts'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    <?php else: ?>
        <ul>
            <li>Prompts gesamt: <?= htmlspecialchars((string)($promptStats['total'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
            <li>Medien mit Prompt: <?= htmlspecialchars((string)($promptStats['mediaWithPrompts'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
            <li>Medien ohne Prompt: <?= htmlspecialchars((string)($promptStats['mediaWithoutPrompts'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
        </ul>
        <?php if (isset($statErrors['prompt_quality'])): ?>
            <p>Fehler bei Prompt-Qualität: <?= htmlspecialchars((string)$statErrors['prompt_quality'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php else: ?>
            <div style="margin: 8px 0; padding: 8px; border: 1px solid #ddd; border-radius: 4px; background: #f9f9f9;">
                <strong>Prompt-Qualität (Sample bis 2000 Medien):</strong>
                <ul>
                    <li>A (gut): <?= (int)$promptQualitySummary['A'] ?></li>
                    <li>B (mittel): <?= (int)$promptQualitySummary['B'] ?></li>
                    <li>C (kritisch): <?= (int)$promptQualitySummary['C'] ?> – <a href="mediadb.php?prompt_quality=C">anzeigen</a></li>
                </ul>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <h3>Tags</h3>
    <?php if (isset($statErrors['tags'])): ?>
        <p>Fehler bei Tag-Statistiken: <?= htmlspecialchars((string)$statErrors['tags'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    <?php else: ?>
        <ul>
            <li>Tags gesamt: <?= htmlspecialchars((string)($tagStats['total'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
            <li>Gesperrte Tags: <?= htmlspecialchars((string)($tagStats['locked'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
            <li>Tag-Zuordnungen (media_tags): <?= htmlspecialchars((string)($tagStats['relations'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
        </ul>
    <?php endif; ?>

    <h3>Scan-Resultate</h3>
    <?php if (isset($statErrors['scan_results'])): ?>
        <p>Fehler bei Scan-Statistiken: <?= htmlspecialchars((string)$statErrors['scan_results'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    <?php else: ?>
        <ul>
            <li>Scan-Resultate gesamt: <?= htmlspecialchars((string)($scanStats['total'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
            <li>Medien mit Scan: <?= htmlspecialchars((string)($scanStats['mediaWithScan'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
            <li>Medien ohne Scan: <?= htmlspecialchars((string)($scanStats['mediaWithoutScan'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
        </ul>
    <?php endif; ?>

    <h3>Media-Metadaten</h3>
    <?php if (isset($statErrors['media_meta'])): ?>
        <p>Fehler bei Media-Meta-Statistiken: <?= htmlspecialchars((string)$statErrors['media_meta'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    <?php else: ?>
        <ul>
            <li>media_meta-Einträge: <?= htmlspecialchars((string)($metaStats['total'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
            <li>Quellen (distinct source): <?= htmlspecialchars((string)($metaStats['sources'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
        </ul>
    <?php endif; ?>

    <h3>Import- und Job-Logs</h3>
    <?php if (isset($statErrors['import_log'])): ?>
        <p>Fehler bei Import-Log-Statistiken: <?= htmlspecialchars((string)$statErrors['import_log'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    <?php else: ?>
        <table border="1" cellpadding="4" cellspacing="0">
            <tr><th>Import-Status</th><th>Anzahl</th></tr>
            <?php foreach ($importStats['byStatus'] as $row): ?>
                <tr>
                    <td><?= htmlspecialchars((string)$row['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string)$row['cnt'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <?php if (isset($statErrors['jobs'])): ?>
        <p>Fehler bei Job-Statistiken: <?= htmlspecialchars((string)$statErrors['jobs'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    <?php else: ?>
        <table border="1" cellpadding="4" cellspacing="0">
            <tr><th>Job-Status</th><th>Anzahl</th></tr>
            <?php foreach ($jobStats['byStatus'] as $row): ?>
                <tr>
                    <td><?= htmlspecialchars((string)$row['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string)$row['cnt'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <th>Summe</th>
                <th><?= htmlspecialchars((string)($jobStats['total'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
            </tr>
        </table>
    <?php endif; ?>

    <h3>Forge-Jobs (Regeneration)</h3>
    <?php if (isset($statErrors['forge_jobs'])): ?>
        <p>Fehler bei Forge-Jobs: <?= htmlspecialchars((string)$statErrors['forge_jobs'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    <?php else: ?>
        <p>Offene Forge-Jobs: <?= htmlspecialchars((string)($forgeJobOverview['open'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> | Erfolgreich: <?= htmlspecialchars((string)($forgeJobOverview['done'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> | Fehler: <?= htmlspecialchars((string)($forgeJobOverview['error'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php if (!empty($forgeJobOverview['by_status'])): ?>
            <ul>
                <?php foreach ($forgeJobOverview['by_status'] as $status => $cnt): ?>
                    <li><?= htmlspecialchars((string)$status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>: <?= htmlspecialchars((string)$cnt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p>Keine Forge-Jobs vorhanden.</p>
        <?php endif; ?>
        <p>Verarbeitung erfolgt ausschließlich über den Forge-Worker (z. B. <code>php SCRIPTS/forge_worker_cli.php --limit=1</code>) und wird von der Web-Oberfläche nur als Queue verwaltet.</p>
    <?php endif; ?>

    <h2>Job Center</h2>
    <p>Übersicht und Steuerung der Jobs (forge_regen). Lesen ohne Internal-Key, Aktionen nur mit gültigem Internal-Key/IP-Whitelist.</p>

    <form method="get">
        <fieldset>
            <legend>Filter</legend>
            <label>
                Job-Typ:
                <select name="job_type">
                    <option value="" <?= $jobCenterFilters['job_type'] === '' ? 'selected' : '' ?>>Alle</option>
                    <option value="forge_regen" <?= $jobCenterFilters['job_type'] === 'forge_regen' ? 'selected' : '' ?>>forge_regen</option>
                </select>
            </label>
            <label>
                Status:
                <select name="job_status">
                    <?php $statusOptions = ['', 'queued', 'running', 'done', 'error', SV_JOB_STATUS_CANCELED]; ?>
                    <?php foreach ($statusOptions as $statusOpt): ?>
                        <?php $label = $statusOpt === '' ? 'Alle' : $statusOpt; ?>
                        <option value="<?= htmlspecialchars($statusOpt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $jobCenterFilters['status'] === $statusOpt ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Media-ID:
                <input type="number" name="job_media_id" min="1" step="1" value="<?= htmlspecialchars((string)($jobCenterFilters['media_id'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </label>
            <label>
                Zeitraum:
                <select name="job_range">
                    <?php $rangeOptions = ['' => 'Alle', '24h' => 'Letzte 24h', '7d' => 'Letzte 7 Tage', '30d' => 'Letzte 30 Tage']; ?>
                    <?php foreach ($rangeOptions as $rangeKey => $rangeLabel): ?>
                        <option value="<?= htmlspecialchars($rangeKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $jobFilterRange === $rangeKey ? 'selected' : '' ?>><?= htmlspecialchars($rangeLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if ($jobCenterInternalKey !== ''): ?>
                <input type="hidden" name="internal_key" value="<?= htmlspecialchars($jobCenterInternalKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?php endif; ?>
            <button type="submit">Filtern</button>
        </fieldset>
    </form>

    <?php if ($jobCenterMessage !== null): ?>
        <p style="color: green;"><?= htmlspecialchars($jobCenterMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    <?php endif; ?>
    <?php if ($jobCenterError !== null): ?>
        <p style="color: red;"><?= htmlspecialchars($jobCenterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    <?php endif; ?>
    <?php if (!empty($jobCenterLog)): ?>
        <details>
            <summary>Job-Center-Logs</summary>
            <pre><?php foreach ($jobCenterLog as $line) { echo htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n"; } ?></pre>
        </details>
    <?php endif; ?>

    <?php if ($jobCenterError === null && empty($jobCenterJobs)): ?>
        <p>Keine Jobs im gewählten Filter.</p>
    <?php elseif ($jobCenterError === null): ?>
        <table border="1" cellpadding="4" cellspacing="0">
            <tr>
                <th>ID</th>
                <th>Typ</th>
                <th>Media</th>
                <th>Status</th>
                <th>Erstellt</th>
                <th>Aktualisiert</th>
                <th>Kurzinfo</th>
                <th>Aktionen</th>
            </tr>
            <?php foreach ($jobCenterJobs as $job): ?>
                <tr>
                    <td><?= htmlspecialchars((string)$job['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string)$job['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td>
                        <?php if (!empty($job['media_id'])): ?>
                            <a href="media_view.php?id=<?= htmlspecialchars((string)$job['media_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Media <?= htmlspecialchars((string)$job['media_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars((string)$job['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string)$job['created_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string)$job['updated_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string)$job['summary'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td>
                        <?php if (in_array($job['status'], ['error', 'done', SV_JOB_STATUS_CANCELED], true)): ?>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="action" value="job_requeue">
                                <input type="hidden" name="job_id" value="<?= htmlspecialchars((string)$job['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                <?php if ($jobCenterInternalKey !== ''): ?>
                                    <input type="hidden" name="internal_key" value="<?= htmlspecialchars($jobCenterInternalKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                <?php endif; ?>
                                <button type="submit">Requeue</button>
                            </form>
                        <?php endif; ?>
                        <?php if (in_array($job['status'], ['queued', 'running'], true)): ?>
                            <form method="post" style="display:inline" onsubmit="return confirm('Job wirklich abbrechen?');">
                                <input type="hidden" name="action" value="job_cancel">
                                <input type="hidden" name="job_id" value="<?= htmlspecialchars((string)$job['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                <?php if ($jobCenterInternalKey !== ''): ?>
                                    <input type="hidden" name="internal_key" value="<?= htmlspecialchars($jobCenterInternalKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                <?php endif; ?>
                                <button type="submit">Cancel</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <h2>CLI-Übersicht</h2>
    <table border="1" cellpadding="4" cellspacing="0">
        <tr><th>Skript</th><th>Kategorie</th><th>Beschreibung</th><th>Beispielaufruf</th></tr>
        <?php foreach ($cliEntries as $entry): ?>
            <tr>
                <td><?= htmlspecialchars($entry['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($entry['category'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($entry['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($entry['example'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <?php if (!empty($knownActions)): ?>
        <h3>Zuletzt ausgeführt</h3>
        <?php if (isset($statErrors['audit_log'])): ?>
            <p>Fehler beim Auslesen des Audit-Logs: <?= htmlspecialchars((string)$statErrors['audit_log'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php else: ?>
            <ul>
                <?php foreach ($knownActions as $actionKey => $label): ?>
                    <li>
                        <?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>:
                        <?php if (isset($lastRuns[$actionKey])): ?>
                            <?= htmlspecialchars((string)$lastRuns[$actionKey], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        <?php else: ?>
                            <em>Keine Einträge</em>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>

    <script>
    (function () {
        const target = document.getElementById('scan-jobs-list');
        if (!target) {
            return;
        }

        const escapeHtml = (str) => (str || '').toString()
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

        async function loadScanJobs() {
            try {
                const pathInput = document.querySelector('input[name="scan_path"]');
                const path = pathInput && pathInput.value ? pathInput.value.trim() : '';
                const url = 'index.php?ajax=scan_jobs' + (path !== '' ? '&path=' + encodeURIComponent(path) : '');
                const response = await fetch(url);
                const data = await response.json();
                const jobs = Array.isArray(data.jobs) ? data.jobs : [];
                if (jobs.length === 0) {
                    target.innerHTML = '<p>Keine Scan-Jobs vorhanden.</p>';
                    return;
                }

                const items = jobs.map((job) => {
                    const status = escapeHtml(job.status || 'unbekannt');
                    const pathText = escapeHtml(job.path || '(Pfad fehlt)');
                    const limitText = job.limit ? ' | Limit ' + escapeHtml(job.limit) : '';
                    const worker = job.worker_pid ? ' | Worker PID ' + escapeHtml(job.worker_pid) : '';
                    const result = job.result || {};
                    const stats = typeof result === 'object'
                        ? ` | processed=${escapeHtml(result.processed ?? 0)}, skipped=${escapeHtml(result.skipped ?? 0)}, errors=${escapeHtml(result.errors ?? 0)}`
                        : '';
                    return `<li><strong>#${escapeHtml(job.id)}</strong> ${status} – ${pathText}${limitText}${worker}${stats}</li>`;
                });

                target.innerHTML = '<ul>' + items.join('') + '</ul>';
            } catch (err) {
                target.innerHTML = '<p>Scan-Status konnte nicht geladen werden.</p>';
            }
        }

        loadScanJobs();
        setInterval(loadScanJobs, 5000);
    })();
    </script>
</body>
</html>

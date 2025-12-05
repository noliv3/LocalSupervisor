<?php
declare(strict_types=1);

$config = require __DIR__ . '/../CONFIG/config.php';
require_once __DIR__ . '/../SCRIPTS/security.php';
require_once __DIR__ . '/../SCRIPTS/operations.php';

$dsn      = $config['db']['dsn'];
$user     = $config['db']['user']     ?? null;
$password = $config['db']['password'] ?? null;
$options  = $config['db']['options']  ?? [];

try {
    $pdo = new PDO($dsn, $user, $password, $options);
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    http_response_code(500);
    echo "<pre>DB-Fehler: " . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</pre>";
    exit;
}

$lastPath    = '';
$jobMessage  = null;
$logFile     = null;
$logLines    = [];
$statErrors  = [];
$mediaStats  = [];
$promptStats = [];
$tagStats    = [];
$scanStats   = [];
$metaStats   = [];
$importStats = [];
$jobStats    = [];
$lastRuns    = [];
$knownActions = [
    'scan_start'           => 'Scan (Dashboard)',
    'rescan_start'         => 'Rescan (Dashboard)',
    'filesync_start'       => 'Filesync (Dashboard)',
    'prompts_rebuild'      => 'Prompt-Rebuild (Dashboard)',
    'consistency_report'   => 'Consistency-Report',
    'consistency_repair'   => 'Consistency-Repair',
    'db_backup'            => 'DB-Backup',
    'migrate'              => 'Migration',
    'cleanup_missing'      => 'Cleanup Missing',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sv_require_internal_key($config);

    $action         = is_string($_POST['action'] ?? null) ? trim($_POST['action']) : '';
    $allowedActions = ['scan_path', 'rescan_db', 'filesync', 'prompts_rebuild', 'consistency_check'];

    if (!in_array($action, $allowedActions, true)) {
        $jobMessage = 'Ungültige Aktion.';
        $action     = '';
    }

    $baseDir = realpath(__DIR__ . '/..');
    if ($baseDir === false) {
        $jobMessage = 'Basisverzeichnis nicht gefunden.';
    } else {
        $logsDir = $baseDir . '/LOGS';
        if (!is_dir($logsDir)) {
            @mkdir($logsDir, 0777, true);
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
                $logFile = $logsDir . '/scan_' . date('Ymd_His') . '.log';
                $logger  = sv_operation_logger($logFile, $logLines);

                try {
                    sv_run_scan_operation($pdo, $config, $lastPath, $limit, $logger);
                    $jobMessage = 'Scan abgeschlossen.';
                    sv_audit_log($pdo, 'scan_start', 'fs', null, [
                        'path'       => $lastPath,
                        'limit'      => $limit,
                        'log_file'   => $logFile,
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                    ]);
                } catch (Throwable $e) {
                    $jobMessage = 'Scan-Fehler: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'rescan_db') {
            $logFile = $logsDir . '/rescan_' . date('Ymd_His') . '.log';
            $limit   = isset($_POST['rescan_limit']) ? (int)$_POST['rescan_limit'] : null;
            $offset  = isset($_POST['rescan_offset']) ? (int)$_POST['rescan_offset'] : null;

            if ($limit !== null && $limit <= 0) {
                $limit = null;
            }
            if ($offset !== null && $offset < 0) {
                $offset = null;
            }

            $logger = sv_operation_logger($logFile, $logLines);

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
            $logFile = $logsDir . '/filesync_' . date('Ymd_His') . '.log';
            $limit   = isset($_POST['filesync_limit']) ? (int)$_POST['filesync_limit'] : null;
            $offset  = isset($_POST['filesync_offset']) ? (int)$_POST['filesync_offset'] : null;

            if ($limit !== null && $limit <= 0) {
                $limit = null;
            }
            if ($offset !== null && $offset < 0) {
                $offset = null;
            }

            $logger = sv_operation_logger($logFile, $logLines);

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
            $logFile = $logsDir . '/prompts_rebuild_' . date('Ymd_His') . '.log';
            $limit   = isset($_POST['rebuild_limit']) ? (int)$_POST['rebuild_limit'] : null;
            $offset  = isset($_POST['rebuild_offset']) ? (int)$_POST['rebuild_offset'] : null;

            if ($limit !== null && $limit <= 0) {
                $limit = null;
            }
            if ($offset !== null && $offset < 0) {
                $offset = null;
            }

            $logger = sv_operation_logger($logFile, $logLines);

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
        }
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
</body>
</html>

<?php
declare(strict_types=1);

$config = require __DIR__ . '/../CONFIG/config.php';
require_once __DIR__ . '/../SCRIPTS/security.php';

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

$lastPath   = '';
$jobMessage = null;
$logFile    = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sv_require_internal_key($config);

    $action         = is_string($_POST['action'] ?? null) ? trim($_POST['action']) : '';
    $allowedActions = ['scan_path', 'rescan_db', 'filesync'];

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

        if ($action === 'scan_path') {
            $lastPath = is_string($_POST['scan_path'] ?? null) ? trim($_POST['scan_path']) : '';
            if ($lastPath === '') {
                $jobMessage = 'Kein Pfad angegeben.';
            } elseif (mb_strlen($lastPath) > 500) {
                $jobMessage = 'Pfad zu lang (max. 500 Zeichen).';
            } else {
                $logFile = $logsDir . '/scan_' . date('Ymd_His') . '.log';

                $phpExe  = 'php';
                $script  = $baseDir . DIRECTORY_SEPARATOR . 'SCRIPTS' . DIRECTORY_SEPARATOR . 'scan_path_cli.php';
                $argPath = $lastPath;

                $cmd = 'cmd /C start "" /B ' .
                    escapeshellarg($phpExe) . ' ' .
                    escapeshellarg($script) . ' ' .
                    escapeshellarg($argPath) .
                    ' > ' . escapeshellarg($logFile) . ' 2>&1';

                @pclose(@popen($cmd, 'r'));

                $jobMessage = 'Scan-Prozess im Hintergrund gestartet.';
                sv_audit_log($pdo, 'scan_start', 'fs', null, [
                    'path'       => $lastPath,
                    'log_file'   => $logFile,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                ]);
            }
        } elseif ($action === 'rescan_db') {
            $logFile = $logsDir . '/rescan_' . date('Ymd_His') . '.log';

            $phpExe = 'php';
            $script = $baseDir . DIRECTORY_SEPARATOR . 'SCRIPTS' . DIRECTORY_SEPARATOR . 'rescan_cli.php';

            $cmd = 'cmd /C start "" /B ' .
                escapeshellarg($phpExe) . ' ' .
                escapeshellarg($script) .
                ' > ' . escapeshellarg($logFile) . ' 2>&1';

            @pclose(@popen($cmd, 'r'));

            $jobMessage = 'Rescan-Prozess im Hintergrund gestartet.';
            sv_audit_log($pdo, 'rescan_start', 'fs', null, [
                'log_file'   => $logFile,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);
        } elseif ($action === 'filesync') {
            $logFile = $logsDir . '/filesync_' . date('Ymd_His') . '.log';

            $phpExe = 'php';
            $script = $baseDir . DIRECTORY_SEPARATOR . 'SCRIPTS' . DIRECTORY_SEPARATOR . 'filesync_cli.php';

            $cmd = 'cmd /C start "" /B ' .
                escapeshellarg($phpExe) . ' ' .
                escapeshellarg($script) .
                ' > ' . escapeshellarg($logFile) . ' 2>&1';

            @pclose(@popen($cmd, 'r'));

            $jobMessage = 'Filesync-Prozess im Hintergrund gestartet.';
            sv_audit_log($pdo, 'filesync_start', 'fs', null, [
                'log_file'   => $logFile,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);
        }
    }
}
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
        <button type="submit">Scan starten</button>
    </form>

    <h2>Bestehende Medien neu scannen (nur ohne Scan-Ergebnis)</h2>
    <form method="post">
        <input type="hidden" name="action" value="rescan_db">
        <button type="submit">Rescan starten</button>
    </form>

    <h2>DB / Dateisystem abgleichen (Status active/missing)</h2>
    <form method="post">
        <input type="hidden" name="action" value="filesync">
        <button type="submit">Filesync starten</button>
    </form>

    <?php if ($jobMessage !== null): ?>
        <h3>Status</h3>
        <p><?= htmlspecialchars($jobMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php if ($logFile !== null): ?>
            <p>Log-Datei: <?= htmlspecialchars($logFile, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php endif; ?>
    <?php endif; ?>
</body>
</html>

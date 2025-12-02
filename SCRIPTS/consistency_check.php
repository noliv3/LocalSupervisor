<?php
declare(strict_types=1);

// CLI-Tool für Konsistenzprüfungen. Standard: nur Bericht; mit --repair=simple werden einfache, sichere Reparaturen ausgeführt.

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Nur CLI erlaubt.\n");
    exit(1);
}

$baseDir = realpath(__DIR__ . '/..');
if ($baseDir === false) {
    fwrite(STDERR, "Basisverzeichnis nicht gefunden.\n");
    exit(1);
}

$configFile = $baseDir . '/CONFIG/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "CONFIG/config.php fehlt.\n");
    exit(1);
}

$config = require $configFile;

$dsn      = $config['db']['dsn'];
$user     = $config['db']['user']     ?? null;
$password = $config['db']['password'] ?? null;
$options  = $config['db']['options']  ?? [];

try {
    $pdo = new PDO($dsn, $user, $password, $options);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    fwrite(STDERR, "DB-Fehler: " . $e->getMessage() . "\n");
    exit(1);
}

$repairMode = 'report';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--repair=')) {
        $value = substr($arg, strlen('--repair='));
        if ($value === 'simple') {
            $repairMode = 'simple';
        } else {
            fwrite(STDERR, "Unbekannter Repair-Modus: {$value}\n");
            exit(1);
        }
    }
}

$logDir = $config['paths']['logs'] ?? ($baseDir . '/LOGS');
if (!is_dir($logDir)) {
    @mkdir($logDir, 0777, true);
}
$logFile = $logDir . '/consistency_' . date('Ymd_His') . '.log';
$logHandle = @fopen($logFile, 'ab');

$consistencyLogAvailable = hasConsistencyLogTable($pdo);
if (!$consistencyLogAvailable) {
    fwrite(STDERR, "Warnung: Tabelle consistency_log fehlt. Migration ausführen, um DB-Logging zu aktivieren.\n");
}

$logLine = function (string $message) use ($logHandle): void {
    $line = $message . PHP_EOL;
    fwrite(STDOUT, $line);
    if (is_resource($logHandle)) {
        fwrite($logHandle, $line);
    }
};

$insertStmt = null;
if ($consistencyLogAvailable) {
    $insertStmt = $pdo->prepare(
        'INSERT INTO consistency_log (check_name, severity, message, created_at) VALUES (?, ?, ?, ?)'
    );
}

$logFinding = function (string $checkName, string $severity, string $message) use ($insertStmt, $consistencyLogAvailable, $logLine): void {
    $logLine("[{$severity}] {$checkName}: {$message}");
    if ($consistencyLogAvailable && $insertStmt !== null) {
        $insertStmt->execute([
            $checkName,
            $severity,
            $message,
            date('c'),
        ]);
    }
};

$logLine('Starte Konsistenzprüfung...');
$logLine('Modus: ' . ($repairMode === 'simple' ? 'report + simple repair' : 'report-only'));

checkOrphans($pdo, $logFinding, $repairMode);
checkDuplicateHashes($pdo, $logFinding);
checkMissingFiles($pdo, $logFinding, $repairMode);

$logLine('Konsistenzprüfung abgeschlossen.');
if (is_resource($logHandle)) {
    fclose($logHandle);
}

function hasConsistencyLogTable(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM consistency_log LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function checkOrphans(PDO $pdo, callable $logFinding, string $repairMode): void
{
    // media_tags gegen fehlende media- oder tags-Einträge
    $mtStmt = $pdo->query(
        'SELECT mt.media_id, mt.tag_id FROM media_tags mt ' .
        'LEFT JOIN media m ON m.id = mt.media_id ' .
        'LEFT JOIN tags t ON t.id = mt.tag_id ' .
        'WHERE m.id IS NULL OR t.id IS NULL'
    );
    $mtRows = $mtStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($mtRows)) {
        $pairs = array_map(
            static fn (array $row): string => 'media_id=' . ($row['media_id'] ?? 'NULL') . ' tag_id=' . ($row['tag_id'] ?? 'NULL'),
            $mtRows
        );
        $logFinding('orphan_media_tags', 'warning', count($mtRows) . ' rows with missing media or tags [' . implode('; ', $pairs) . ']');
        if ($repairMode === 'simple') {
            $deleted = $pdo->exec(
                'DELETE FROM media_tags WHERE NOT EXISTS (SELECT 1 FROM media m WHERE m.id = media_tags.media_id) ' .
                'OR NOT EXISTS (SELECT 1 FROM tags t WHERE t.id = media_tags.tag_id)'
            );
            $logFinding('orphan_media_tags', 'info', 'deleted ' . (int) $deleted . ' rows');
        }
    } else {
        $logFinding('orphan_media_tags', 'info', 'no orphaned media_tags rows');
    }

    // collection_media gegen fehlende collections oder media
    $cmStmt = $pdo->query(
        'SELECT cm.collection_id, cm.media_id FROM collection_media cm ' .
        'LEFT JOIN collections c ON c.id = cm.collection_id ' .
        'LEFT JOIN media m ON m.id = cm.media_id ' .
        'WHERE c.id IS NULL OR m.id IS NULL'
    );
    $cmRows = $cmStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($cmRows)) {
        $pairs = array_map(
            static fn (array $row): string => 'collection_id=' . ($row['collection_id'] ?? 'NULL') . ' media_id=' . ($row['media_id'] ?? 'NULL'),
            $cmRows
        );
        $logFinding('orphan_collection_media', 'warning', count($cmRows) . ' rows with missing collection or media [' . implode('; ', $pairs) . ']');
        if ($repairMode === 'simple') {
            $deleted = $pdo->exec(
                'DELETE FROM collection_media WHERE NOT EXISTS (SELECT 1 FROM collections c WHERE c.id = collection_media.collection_id) ' .
                'OR NOT EXISTS (SELECT 1 FROM media m WHERE m.id = collection_media.media_id)'
            );
            $logFinding('orphan_collection_media', 'info', 'deleted ' . (int) $deleted . ' rows');
        }
    } else {
        $logFinding('orphan_collection_media', 'info', 'no orphaned collection_media rows');
    }

    // scan_results ohne media
    $srStmt = $pdo->query(
        'SELECT sr.id, sr.media_id FROM scan_results sr ' .
        'LEFT JOIN media m ON m.id = sr.media_id ' .
        'WHERE m.id IS NULL'
    );
    $srRows = $srStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($srRows)) {
        $pairs = array_map(
            static fn (array $row): string => 'id=' . ($row['id'] ?? 'NULL') . ' media_id=' . ($row['media_id'] ?? 'NULL'),
            $srRows
        );
        $logFinding('orphan_scan_results', 'warning', count($srRows) . ' rows with missing media [' . implode('; ', $pairs) . ']');
        if ($repairMode === 'simple') {
            $deleted = $pdo->exec(
                'DELETE FROM scan_results WHERE NOT EXISTS (SELECT 1 FROM media m WHERE m.id = scan_results.media_id)'
            );
            $logFinding('orphan_scan_results', 'info', 'deleted ' . (int) $deleted . ' rows');
        }
    } else {
        $logFinding('orphan_scan_results', 'info', 'no orphaned scan_results rows');
    }

    // prompts ohne media
    $prStmt = $pdo->query(
        'SELECT p.id, p.media_id FROM prompts p ' .
        'LEFT JOIN media m ON m.id = p.media_id ' .
        'WHERE m.id IS NULL'
    );
    $prRows = $prStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($prRows)) {
        $pairs = array_map(
            static fn (array $row): string => 'id=' . ($row['id'] ?? 'NULL') . ' media_id=' . ($row['media_id'] ?? 'NULL'),
            $prRows
        );
        $logFinding('orphan_prompts', 'warning', count($prRows) . ' rows with missing media [' . implode('; ', $pairs) . ']');
        if ($repairMode === 'simple') {
            $deleted = $pdo->exec(
                'DELETE FROM prompts WHERE NOT EXISTS (SELECT 1 FROM media m WHERE m.id = prompts.media_id)'
            );
            $logFinding('orphan_prompts', 'info', 'deleted ' . (int) $deleted . ' rows');
        }
    } else {
        $logFinding('orphan_prompts', 'info', 'no orphaned prompts rows');
    }

    // jobs ohne media oder prompt
    $jobStmt = $pdo->query(
        'SELECT j.id, j.media_id, j.prompt_id FROM jobs j ' .
        'LEFT JOIN media m ON m.id = j.media_id ' .
        'LEFT JOIN prompts p ON p.id = j.prompt_id ' .
        'WHERE m.id IS NULL OR (j.prompt_id IS NOT NULL AND p.id IS NULL)'
    );
    $jobRows = $jobStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($jobRows)) {
        $pairs = array_map(
            static fn (array $row): string => 'id=' . ($row['id'] ?? 'NULL') . ' media_id=' . ($row['media_id'] ?? 'NULL') . ' prompt_id=' . ($row['prompt_id'] ?? 'NULL'),
            $jobRows
        );
        $logFinding('orphan_jobs', 'warning', count($jobRows) . ' rows with missing media or prompt [' . implode('; ', $pairs) . ']');
        if ($repairMode === 'simple') {
            $deleted = $pdo->exec(
                'DELETE FROM jobs WHERE NOT EXISTS (SELECT 1 FROM media m WHERE m.id = jobs.media_id) ' .
                'OR (jobs.prompt_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM prompts p WHERE p.id = jobs.prompt_id))'
            );
            $logFinding('orphan_jobs', 'info', 'deleted ' . (int) $deleted . ' rows');
        }
    } else {
        $logFinding('orphan_jobs', 'info', 'no orphaned jobs rows');
    }
}

function checkDuplicateHashes(PDO $pdo, callable $logFinding): void
{
    $stmt = $pdo->query(
        'SELECT hash, GROUP_CONCAT(id, ",") AS media_ids, COUNT(*) AS cnt FROM media ' .
        'WHERE hash IS NOT NULL ' .
        'GROUP BY hash HAVING COUNT(*) > 1'
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($rows)) {
        foreach ($rows as $row) {
            $hash = (string) $row['hash'];
            $ids  = (string) $row['media_ids'];
            $logFinding('duplicate_media_hash', 'warning', "hash {$hash} appears in media_ids: {$ids}");
        }
    } else {
        $logFinding('duplicate_media_hash', 'info', 'no duplicate hashes in media');
    }
}

function checkMissingFiles(PDO $pdo, callable $logFinding, string $repairMode): void
{
    $stmt = $pdo->query('SELECT id, path, status FROM media');
    $missing = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $path = (string) ($row['path'] ?? '');
        if ($path === '' || !is_file($path)) {
            $missing[] = $row;
        }
    }

    if (!empty($missing)) {
        $messages = array_map(
            static fn (array $row): string => 'id=' . ($row['id'] ?? 'NULL') . ' path=' . ($row['path'] ?? ''),
            $missing
        );
        $logFinding('missing_file', 'warning', count($missing) . ' media entries without file [' . implode('; ', $messages) . ']');

        if ($repairMode === 'simple') {
            $ids = array_column($missing, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $update = $pdo->prepare(
                'UPDATE media SET status = "missing" WHERE id IN (' . $placeholders . ') ' .
                'AND (status IS NULL OR status = "" OR status = "active")'
            );
            $update->execute($ids);
            $logFinding('missing_file', 'info', 'marked ' . (int) $update->rowCount() . ' media rows as missing');
        }
    } else {
        $logFinding('missing_file', 'info', 'all media paths exist');
    }
}

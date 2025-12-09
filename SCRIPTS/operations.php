<?php
declare(strict_types=1);

// Zentrale Operationsbibliothek für Web- und CLI-Aufrufer.

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/scan_core.php';
require_once __DIR__ . '/security.php';

function sv_load_config(?string $baseDir = null): array
{
    $baseDir = $baseDir ?? sv_base_dir();
    $configFile = $baseDir . '/CONFIG/config.php';
    if (!is_file($configFile)) {
        throw new RuntimeException('CONFIG/config.php fehlt.');
    }

    $config = require $configFile;
    if (!is_array($config)) {
        throw new RuntimeException('CONFIG/config.php liefert keine Konfiguration.');
    }

    return $config;
}

function sv_open_pdo(array $config): PDO
{
    $dsn      = $config['db']['dsn'] ?? null;
    $user     = $config['db']['user'] ?? null;
    $password = $config['db']['password'] ?? null;
    $options  = $config['db']['options'] ?? [];

    if (!is_string($dsn) || $dsn === '') {
        throw new RuntimeException('DB-DSN fehlt.');
    }

    $pdo = new PDO($dsn, $user, $password, $options);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

function sv_run_scan_operation(PDO $pdo, array $config, string $scanPath, ?int $limit, callable $logger): array
{
    $scannerCfg    = $config['scanner'] ?? [];
    $pathsCfg      = $config['paths'] ?? [];
    $nsfwThreshold = (float)($scannerCfg['nsfw_threshold'] ?? 0.7);

    $logger('Starte Scan: ' . $scanPath . ($limit !== null ? " (limit={$limit})" : ''));

    $result = sv_run_scan_path(
        $scanPath,
        $pdo,
        $pathsCfg,
        $scannerCfg,
        $nsfwThreshold,
        $logger,
        $limit
    );

    $logger(sprintf(
        'Scan fertig: processed=%d, skipped=%d, errors=%d',
        (int)$result['processed'],
        (int)$result['skipped'],
        (int)$result['errors']
    ));

    return $result;
}

function sv_run_rescan_operation(
    PDO $pdo,
    array $config,
    ?int $limit,
    ?int $offset,
    callable $logger
): array {
    $scannerCfg    = $config['scanner'] ?? [];
    $pathsCfg      = $config['paths'] ?? [];
    $nsfwThreshold = (float)($scannerCfg['nsfw_threshold'] ?? 0.7);

    $logger('Starte Rescan fehlender Ergebnisse' . ($limit !== null ? " (limit={$limit}" . ($offset !== null ? ", offset={$offset}" : '') . ')' : ''));

    $result = sv_run_rescan_unscanned(
        $pdo,
        $pathsCfg,
        $scannerCfg,
        $nsfwThreshold,
        $logger,
        $limit,
        $offset
    );

    $logger(sprintf(
        'Rescan fertig: processed=%d, skipped=%d, errors=%d',
        (int)$result['processed'],
        (int)$result['skipped'],
        (int)$result['errors']
    ));

    return $result;
}

function sv_run_filesync_operation(
    PDO $pdo,
    array $config,
    ?int $limit,
    ?int $offset,
    callable $logger
): array {
    $pathsCfg = $config['paths'] ?? [];

    $logger('Starte Filesync' . ($limit !== null ? " (limit={$limit}" . ($offset !== null ? ", offset={$offset}" : '') . ')' : ''));

    $result = sv_run_filesync(
        $pdo,
        $pathsCfg,
        $logger,
        $limit,
        $offset
    );

    $logger(sprintf(
        'Filesync fertig: processed=%d, missing=%d, restored=%d, errors=%d',
        (int)$result['processed'],
        (int)$result['missing'],
        (int)$result['restored'],
        (int)$result['errors']
    ));

    return $result;
}

function sv_run_prompts_rebuild_operation(
    PDO $pdo,
    array $config,
    ?int $limit,
    ?int $offset,
    callable $logger
): array {
    $limit = $limit ?? 250;

    $sql = "SELECT m.id, m.path, m.type, p.id AS prompt_id FROM media m "
         . "LEFT JOIN prompts p ON p.media_id = m.id "
         . "WHERE m.status = 'active' AND m.type = 'image' AND (";
    $sql .= "p.id IS NULL OR p.prompt IS NULL OR p.negative_prompt IS NULL OR p.source_metadata IS NULL";
    $sql .= " OR p.model IS NULL OR p.sampler IS NULL OR p.cfg_scale IS NULL OR p.steps IS NULL OR p.seed IS NULL OR p.width IS NULL OR p.height IS NULL OR p.scheduler IS NULL";
    $sql .= ") ORDER BY m.id ASC";

    if ($limit !== null) {
        $sql .= ' LIMIT ' . max(0, (int)$limit);
    }
    if ($offset !== null) {
        $sql .= ' OFFSET ' . max(0, (int)$offset);
    }

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $total     = count($rows);
    $processed = 0;
    $skipped   = 0;
    $errors    = 0;

    $logger('Prompt-Rebuild: ' . $total . ' Kandidaten gefunden' . ($limit !== null ? " (limit={$limit}" . ($offset !== null ? ", offset={$offset}" : '') . ')' : ''));

    foreach ($rows as $row) {
        $mediaId = (int)$row['id'];
        $path    = (string)$row['path'];
        $type    = (string)$row['type'];

        $logger("Media ID {$mediaId}: {$path}");

        if (!is_file($path)) {
            $logger('  -> Datei nicht gefunden, übersprungen.');
            $skipped++;
            continue;
        }

        try {
            $metadata = sv_extract_metadata($path, $type, 'prompts_rebuild', $logger);
            sv_store_extracted_metadata($pdo, $mediaId, $type, $metadata, 'prompts_rebuild', $logger);
            $processed++;
        } catch (Throwable $e) {
            $errors++;
            $logger('  -> Fehler: ' . $e->getMessage());
        }
    }

    $logger(sprintf(
        'Prompt-Rebuild fertig: total=%d, processed=%d, skipped=%d, errors=%d',
        $total,
        $processed,
        $skipped,
        $errors
    ));

    return [
        'total'     => $total,
        'processed' => $processed,
        'skipped'   => $skipped,
        'errors'    => $errors,
    ];
}

function sv_run_consistency_operation(PDO $pdo, array $config, string $mode, callable $logLine): array
{
    $repairMode = $mode === 'simple' ? 'simple' : 'report';
    $logFile = sv_prepare_log_file($config, 'consistency', true, 30);
    $logHandle = @fopen($logFile, 'ab');

    $consistencyLogAvailable = sv_has_consistency_log_table($pdo);
    if (!$consistencyLogAvailable) {
        $logLine('Warnung: Tabelle consistency_log fehlt. Migration ausführen, um DB-Logging zu aktivieren.');
    }

    $auditFindings = [];
    $logLine('Starte Konsistenzprüfung...');
    $logLine('Modus: ' . ($repairMode === 'simple' ? 'report + simple repair' : 'report-only'));

    $logWriter = function (string $message) use ($logHandle, $logLine): void {
        $logLine($message);
        if (is_resource($logHandle)) {
            fwrite($logHandle, '[' . date('c') . '] ' . $message . PHP_EOL);
        }
    };

    $insertStmt = null;
    if ($consistencyLogAvailable) {
        $insertStmt = $pdo->prepare(
            'INSERT INTO consistency_log (check_name, severity, message, created_at) VALUES (?, ?, ?, ?)'
        );
    }

    $logFinding = function (
        string $checkName,
        string $severity,
        string $message
    ) use ($insertStmt, $consistencyLogAvailable, $logWriter, &$auditFindings): void {
        $logWriter("[{$severity}] {$checkName}: {$message}");
        $auditFindings[] = [
            'check'    => $checkName,
            'severity' => $severity,
            'message'  => $message,
        ];
        if ($consistencyLogAvailable && $insertStmt !== null) {
            $insertStmt->execute([
                $checkName,
                $severity,
                $message,
                date('c'),
            ]);
        }
    };

    sv_check_orphans($pdo, $logFinding, $repairMode);
    sv_check_duplicate_hashes($pdo, $logFinding);
    sv_check_missing_files($pdo, $logFinding, $repairMode);

    $logWriter('Konsistenzprüfung abgeschlossen.');
    if (is_resource($logHandle)) {
        fclose($logHandle);
    }

    if ($repairMode === 'simple') {
        sv_audit_log($pdo, 'consistency_repair', 'db', null, [
            'mode'     => $repairMode,
            'findings' => $auditFindings,
        ]);
    }

    return [
        'mode'     => $repairMode,
        'findings' => $auditFindings,
        'log_file' => $logFile,
    ];
}

function sv_has_consistency_log_table(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM consistency_log LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function sv_check_orphans(PDO $pdo, callable $logFinding, string $repairMode): void
{
    $mtStmt = $pdo->query(
        'SELECT mt.media_id, mt.tag_id FROM media_tags mt ' .
        'LEFT JOIN media m ON m.id = mt.media_id ' .
        'LEFT JOIN tags t ON t.id = mt.tag_id ' .
        'WHERE m.id IS NULL OR t.id IS NULL'
    );
    $mtRows = $mtStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($mtRows as $row) {
        $logFinding('media_tags_orphan', 'warn', 'Media_ID=' . $row['media_id'] . ', Tag_ID=' . $row['tag_id']);
    }

    if ($repairMode === 'simple' && !empty($mtRows)) {
        $deleteStmt = $pdo->prepare('DELETE FROM media_tags WHERE media_id = ? AND tag_id = ?');
        foreach ($mtRows as $row) {
            $deleteStmt->execute([$row['media_id'], $row['tag_id']]);
            $logFinding('media_tags_orphan', 'info', 'gelöscht: Media_ID=' . $row['media_id'] . ', Tag_ID=' . $row['tag_id']);
        }
    }

    $scanStmt = $pdo->query(
        'SELECT s.id, s.media_id FROM scan_results s ' .
        'LEFT JOIN media m ON m.id = s.media_id ' .
        'WHERE m.id IS NULL'
    );
    $scanRows = $scanStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($scanRows as $row) {
        $logFinding('scan_results_orphan', 'warn', 'Scan_ID=' . $row['id'] . ', Media_ID=' . $row['media_id']);
    }

    if ($repairMode === 'simple' && !empty($scanRows)) {
        $deleteStmt = $pdo->prepare('DELETE FROM scan_results WHERE id = ?');
        foreach ($scanRows as $row) {
            $deleteStmt->execute([$row['id']]);
            $logFinding('scan_results_orphan', 'info', 'gelöscht: Scan_ID=' . $row['id'] . ', Media_ID=' . $row['media_id']);
        }
    }
}

function sv_check_duplicate_hashes(PDO $pdo, callable $logFinding): void
{
    $dups = $pdo->query(
        'SELECT hash, COUNT(*) AS cnt FROM media WHERE hash IS NOT NULL GROUP BY hash HAVING cnt > 1'
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($dups as $row) {
        $logFinding('media_duplicate_hash', 'warn', 'Hash=' . $row['hash'] . ' | Count=' . $row['cnt']);
    }
}

function sv_check_missing_files(PDO $pdo, callable $logFinding, string $repairMode): void
{
    $stmt = $pdo->query(
        "SELECT id, path, status FROM media WHERE path IS NOT NULL ORDER BY id ASC"
    );

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $mediaId = (int)$row['id'];
        $path    = (string)$row['path'];
        $status  = (string)$row['status'];

        $exists = is_file($path);
        if ($exists && $status === 'missing' && $repairMode === 'simple') {
            $pdo->prepare('UPDATE media SET status = \"active\" WHERE id = ?')->execute([$mediaId]);
            $logFinding('media_missing', 'info', 'Status auf active gesetzt: Media_ID=' . $mediaId . ' (war missing, Datei vorhanden)');
            continue;
        }

        if (!$exists && $status !== 'missing') {
            $logFinding('media_missing', 'warn', 'Datei fehlt: Media_ID=' . $mediaId . ' (' . $path . ')');
            if ($repairMode === 'simple') {
                $pdo->prepare('UPDATE media SET status = \"missing\" WHERE id = ?')->execute([$mediaId]);
                $logFinding('media_missing', 'info', 'Status auf missing gesetzt: Media_ID=' . $mediaId . ' (' . $path . ')');
            }
        }
    }
}

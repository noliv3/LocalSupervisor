<?php
declare(strict_types=1);

require_once __DIR__ . '/../SCRIPTS/common.php';
require_once __DIR__ . '/../SCRIPTS/operations.php';
require_once __DIR__ . '/../SCRIPTS/security.php';
require_once __DIR__ . '/../SCRIPTS/ollama_jobs.php';
require_once __DIR__ . '/_layout.php';

try {
    $config = sv_load_config();
} catch (Throwable $e) {
    http_response_code(500);
    echo "<pre>CONFIG-Fehler: " . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</pre>";
    exit;
}

$isLoopback = sv_is_loopback_remote_addr();
if (!$isLoopback) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        header('Location: mediadb.php');
        exit;
    }
    sv_security_error(403, 'Forbidden.');
}

if (!$isLoopback) {
    sv_require_internal_access($config, 'dashboard_ollama');
} else {
    sv_validate_internal_access($config, 'dashboard_ollama', false);
}

try {
    $pdo = sv_open_pdo($config);
} catch (Throwable $e) {
    http_response_code(503);
    echo "<pre>DB-Fehler: " . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</pre>";
    exit;
}

function sv_ollama_normalize_enum(?string $value, array $allowed, string $default): string
{
    if ($value === null) {
        return $default;
    }

    return in_array($value, $allowed, true) ? $value : $default;
}

function sv_ollama_clamp_int(int $value, int $min, int $max, int $default): int
{
    if ($value < $min || $value > $max) {
        return $default;
    }

    return $value;
}

$statusList = ['queued', 'pending', 'running', 'done', 'error', 'cancelled'];
$modeList = ['caption', 'title', 'prompt_eval', 'tags_normalize', 'quality', 'prompt_recon', 'embed', 'dupe_hints'];

$allowedModes = array_merge(['all'], $modeList);
$allowedStatuses = array_merge(['all'], $statusList);
$allowedHasMeta = ['all', 'with', 'without'];
$allowedQuality = ['all', 'high', 'mid', 'low', 'missing'];
$allowedDomain = array_merge(['all', 'missing'], SV_OLLAMA_DOMAIN_TYPES);

$modeFilter = sv_ollama_normalize_enum($_GET['mode'] ?? null, $allowedModes, 'all');
$statusFilter = sv_ollama_normalize_enum($_GET['status'] ?? null, $allowedStatuses, 'all');
$domainFilter = sv_ollama_normalize_enum($_GET['domain'] ?? null, $allowedDomain, 'all');
$qualityFilter = sv_ollama_normalize_enum($_GET['quality'] ?? null, $allowedQuality, 'all');
$hasMetaFilter = sv_ollama_normalize_enum($_GET['has_meta'] ?? null, $allowedHasMeta, 'all');
$limit = sv_ollama_clamp_int((int)($_GET['limit'] ?? 120), 20, 500, 120);

$jobTypes = sv_ollama_job_types();
$typePlaceholders = implode(',', array_fill(0, count($jobTypes), '?'));

$errorCodeStmt = $pdo->prepare(
    'SELECT DISTINCT last_error_code FROM jobs WHERE type IN (' . $typePlaceholders . ') AND last_error_code IS NOT NULL AND last_error_code != "" ORDER BY last_error_code ASC'
);
$errorCodeStmt->execute($jobTypes);
$errorCodes = array_values(array_filter(array_map('strval', $errorCodeStmt->fetchAll(PDO::FETCH_COLUMN, 0))));
$allowedErrorCodes = array_merge(['all', 'none'], $errorCodes);
$errorFilter = sv_ollama_normalize_enum($_GET['error_code'] ?? null, $allowedErrorCodes, 'all');

$payloadColumn = sv_ollama_payload_column($pdo);

$conditions = ['j.type IN (' . $typePlaceholders . ')'];
$params = $jobTypes;

if ($modeFilter !== 'all' && $modeFilter !== 'dupe_hints') {
    $conditions[] = 'j.type = ?';
    $params[] = sv_ollama_job_type_for_mode($modeFilter);
} elseif ($modeFilter === 'dupe_hints') {
    $conditions[] = '1 = 0';
}
if ($statusFilter !== 'all') {
    $conditions[] = 'j.status = ?';
    $params[] = $statusFilter;
}
if ($errorFilter !== 'all') {
    if ($errorFilter === 'none') {
        $conditions[] = '(j.last_error_code IS NULL OR j.last_error_code = "")';
    } else {
        $conditions[] = 'j.last_error_code = ?';
        $params[] = $errorFilter;
    }
}
if ($domainFilter !== 'all') {
    if ($domainFilter === 'missing') {
        $conditions[] = '(mm_domain.meta_value IS NULL OR mm_domain.meta_value = "")';
    } else {
        $conditions[] = 'mm_domain.meta_value = ?';
        $params[] = $domainFilter;
    }
}
if ($qualityFilter !== 'all') {
    if ($qualityFilter === 'missing') {
        $conditions[] = '(mm_quality.meta_value IS NULL OR mm_quality.meta_value = "")';
    } elseif ($qualityFilter === 'high') {
        $conditions[] = 'CAST(mm_quality.meta_value AS INTEGER) >= 80';
    } elseif ($qualityFilter === 'mid') {
        $conditions[] = 'CAST(mm_quality.meta_value AS INTEGER) BETWEEN 50 AND 79';
    } elseif ($qualityFilter === 'low') {
        $conditions[] = 'CAST(mm_quality.meta_value AS INTEGER) < 50';
    }
}
if ($hasMetaFilter !== 'all') {
    $conditions[] = $hasMetaFilter === 'with' ? 'mm_meta.id IS NOT NULL' : 'mm_meta.id IS NULL';
}

$whereClause = $conditions !== [] ? ('WHERE ' . implode(' AND ', $conditions)) : '';

$jobsStmt = $pdo->prepare(
    'SELECT j.id, j.media_id, j.type, j.status, j.progress_bits, j.progress_bits_total, j.heartbeat_at, '
    . 'j.last_error_code, j.created_at, j.updated_at, j.' . $payloadColumn . ' AS payload_json, '
    . 'mm_stage.meta_value AS stage_version, '
    . 'mm_quality.meta_value AS quality_score, '
    . 'mm_domain.meta_value AS domain_type '
    . 'FROM jobs j '
    . 'LEFT JOIN media_meta mm_stage ON mm_stage.id = ('
    . '  SELECT id FROM media_meta WHERE media_id = j.media_id AND meta_key = "ollama.stage_version" ORDER BY id DESC LIMIT 1'
    . ') '
    . 'LEFT JOIN media_meta mm_quality ON mm_quality.id = ('
    . '  SELECT id FROM media_meta WHERE media_id = j.media_id AND meta_key = "ollama.quality.score" ORDER BY id DESC LIMIT 1'
    . ') '
    . 'LEFT JOIN media_meta mm_domain ON mm_domain.id = ('
    . '  SELECT id FROM media_meta WHERE media_id = j.media_id AND meta_key = "ollama.domain.type" ORDER BY id DESC LIMIT 1'
    . ') '
    . 'LEFT JOIN media_meta mm_meta ON mm_meta.id = ('
    . '  SELECT id FROM media_meta WHERE media_id = j.media_id AND meta_key LIKE "ollama.%" ORDER BY id DESC LIMIT 1'
    . ') '
    . $whereClause
    . ' ORDER BY j.updated_at DESC, j.id DESC LIMIT ' . $limit
);
$jobsStmt->execute($params);
$jobs = $jobsStmt->fetchAll(PDO::FETCH_ASSOC);

$statusCounts = array_fill_keys($statusList, 0);
$countStmt = $pdo->prepare(
    'SELECT status, COUNT(*) AS cnt FROM jobs WHERE type IN (' . $typePlaceholders . ') GROUP BY status'
);
$countStmt->execute($jobTypes);
foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $status = (string)($row['status'] ?? '');
    if (isset($statusCounts[$status])) {
        $statusCounts[$status] = (int)($row['cnt'] ?? 0);
    }
}

$modeCounts = [];
foreach ($modeList as $mode) {
    $modeCounts[$mode] = array_fill_keys($statusList, 0);
}
$modeCountStmt = $pdo->prepare(
    'SELECT type, status, COUNT(*) AS cnt FROM jobs WHERE type IN (' . $typePlaceholders . ') GROUP BY type, status'
);
$modeCountStmt->execute($jobTypes);
foreach ($modeCountStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $jobType = (string)($row['type'] ?? '');
    $status = (string)($row['status'] ?? '');
    if (!in_array($status, $statusList, true)) {
        continue;
    }
    try {
        $mode = sv_ollama_mode_for_job_type($jobType);
    } catch (Throwable $e) {
        continue;
    }
    if (isset($modeCounts[$mode])) {
        $modeCounts[$mode][$status] = (int)($row['cnt'] ?? 0);
    }
}

$pendingMigrations = [];
$migrationError = null;
try {
    $migrationDir = realpath(__DIR__ . '/../SCRIPTS/migrations');
    if ($migrationDir !== false && is_dir($migrationDir)) {
        $migrations = sv_db_load_migrations($migrationDir);
        $applied = sv_db_load_applied_versions($pdo);
        foreach ($migrations as $migration) {
            if (!isset($applied[$migration['version']])) {
                $pendingMigrations[] = $migration['version'];
            }
        }
    } else {
        $migrationError = 'Migrationsverzeichnis fehlt.';
    }
} catch (Throwable $e) {
    $migrationError = 'Migrationsstatus nicht lesbar: ' . sv_sanitize_error_message($e->getMessage());
}

$logsPath = sv_logs_root($config);
$statusOrder = [
    'running' => 1,
    'queued' => 2,
    'pending' => 3,
    'done' => 4,
    'error' => 5,
    'cancelled' => 6,
];

sv_ui_header('OLLAMA Dashboard', 'ollama');
?>
<div class="page-header">
    <div>
        <h1 class="page-title">OLLAMA Dashboard</h1>
        <div class="hint">Interne Kontrolle der Ollama-Queue (Polling via ollama.php).</div>
    </div>
    <div class="header-stats">
        <span class="pill">Jobs: <?= (int)array_sum($statusCounts) ?></span>
        <span class="pill">Limit: <?= (int)$limit ?></span>
    </div>
</div>

<div class="ollama-dashboard" data-ollama-dashboard data-endpoint="ollama.php" data-poll-interval="10000" data-heartbeat-stale="180">
    <div class="panel">
        <div class="panel-header">Queue-Übersicht</div>
        <div class="ollama-status-grid">
            <?php foreach ($statusList as $status): ?>
                <div class="ollama-status-card">
                    <div class="ollama-status-label"><?= htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <div class="ollama-status-count" data-status-count="<?= htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <?= (int)($statusCounts[$status] ?? 0) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Mode</th>
                        <?php foreach ($statusList as $status): ?>
                            <th><?= htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($modeList as $mode): ?>
                        <tr data-mode-row="<?= htmlspecialchars($mode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                            <td><strong><?= htmlspecialchars($mode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></td>
                            <?php foreach ($statusList as $status): ?>
                                <td data-mode-count="<?= htmlspecialchars($mode . ':' . $status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                    <?= (int)($modeCounts[$mode][$status] ?? 0) ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="hint small">Logpfad: <span data-logs-path><?= htmlspecialchars($logsPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></div>
        <?php if ($migrationError !== null): ?>
            <div class="action-note error">Migration-Status: <?= htmlspecialchars($migrationError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        <?php elseif ($pendingMigrations !== []): ?>
            <div class="action-note error">Migrationen ausstehend: <?= htmlspecialchars(implode(', ', $pendingMigrations), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        <?php else: ?>
            <div class="hint small">Migrationsstatus: OK.</div>
        <?php endif; ?>
    </div>

    <div class="panel">
        <div class="panel-header">Aktionen</div>
        <div class="action-feedback" data-ollama-message>
            <div class="action-feedback-title">Bereit</div>
            <div>Enqueue/Run/Cancel/Delete/Requeue werden über ollama.php ausgelöst.</div>
        </div>
        <div class="form-grid">
            <button class="btn btn--primary" type="button" data-ollama-quick-enqueue>Enqueue all</button>
            <label>Batch
                <input type="number" min="1" max="50" value="5" data-ollama-run-batch>
            </label>
            <label>Max Sekunden
                <input type="number" min="1" max="120" value="20" data-ollama-run-seconds>
            </label>
            <button class="btn btn--secondary" type="button" data-ollama-run>Run Batch</button>
            <button class="btn btn--ghost" type="button" data-ollama-auto-run aria-pressed="false">Auto-Run: Aus</button>
        </div>
        <form class="ollama-enqueue" data-ollama-enqueue>
            <div class="form-grid">
                <label>Mode
                    <select name="mode">
                        <option value="all">all</option>
                        <option value="caption">caption</option>
                        <option value="title">title</option>
                        <option value="prompt_eval">prompt_eval</option>
                        <option value="tags_normalize">tags_normalize</option>
                        <option value="quality">quality</option>
                        <option value="prompt_recon">prompt_recon</option>
                        <option value="embed">embed</option>
                    </select>
                </label>
                <label>Limit
                    <input type="number" name="limit" min="1" max="500" value="50">
                </label>
                <label>Since (YYYY-MM-DD)
                    <input type="date" name="since">
                </label>
                <label class="checkbox-inline"><input type="checkbox" name="missing_title" value="1"> missing title</label>
                <label class="checkbox-inline"><input type="checkbox" name="missing_caption" value="1"> missing caption</label>
                <label class="checkbox-inline"><input type="checkbox" name="all" value="1"> all</label>
            </div>
            <button class="btn btn--primary" type="submit">Enqueue starten</button>
        </form>
    </div>

    <form method="get" class="filters">
        <details open>
            <summary>Filter</summary>
            <div class="details-body">
                <div class="form-grid">
                    <label>Mode
                        <select name="mode">
                            <?php foreach ($allowedModes as $mode): ?>
                                <option value="<?= htmlspecialchars($mode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $modeFilter === $mode ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($mode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Status
                        <select name="status">
                            <?php foreach ($allowedStatuses as $status): ?>
                                <option value="<?= htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $statusFilter === $status ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Error-Code
                        <select name="error_code">
                            <?php foreach ($allowedErrorCodes as $code): ?>
                                <option value="<?= htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $errorFilter === $code ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Domain
                        <select name="domain">
                            <?php foreach ($allowedDomain as $domain): ?>
                                <option value="<?= htmlspecialchars($domain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $domainFilter === $domain ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($domain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Quality
                        <select name="quality">
                            <?php foreach ($allowedQuality as $quality): ?>
                                <option value="<?= htmlspecialchars($quality, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $qualityFilter === $quality ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($quality, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Has Meta
                        <select name="has_meta">
                            <?php foreach ($allowedHasMeta as $hasMeta): ?>
                                <option value="<?= htmlspecialchars($hasMeta, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $hasMetaFilter === $hasMeta ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($hasMeta, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Limit
                        <input type="number" name="limit" min="20" max="500" value="<?= (int)$limit ?>">
                    </label>
                </div>
                <div class="button-stack inline">
                    <button class="btn btn--secondary" type="submit">Filter anwenden</button>
                    <a class="btn btn--ghost" href="dashboard_ollama.php">Reset</a>
                </div>
            </div>
        </details>
    </form>

    <div class="panel">
        <div class="panel-header">Jobs</div>
        <?php if ($modeFilter === 'dupe_hints'): ?>
            <div class="tab-hint">Dupe-Hints werden außerhalb der Job-Queue erzeugt (CLI). Ergebnisse siehe Media-Detailseite.</div>
        <?php endif; ?>
        <div class="table-wrap">
            <table class="table" data-ollama-jobs>
                <thead>
                    <tr>
                        <th data-sort-key="status" class="sortable">Status</th>
                        <th>Job</th>
                        <th>Mode</th>
                        <th data-sort-key="progress" class="sortable">Progress</th>
                        <th data-sort-key="heartbeat" class="sortable">Heartbeat</th>
                        <th>Error</th>
                        <th>Model</th>
                        <th>Stage</th>
                        <th>Warnungen</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($jobs === []): ?>
                        <tr><td colspan="10" class="job-hint">Keine Jobs gefunden.</td></tr>
                    <?php else: ?>
                        <?php foreach ($jobs as $job): ?>
                            <?php
                            $jobId = (int)($job['id'] ?? 0);
                            $mediaId = (int)($job['media_id'] ?? 0);
                            $status = (string)($job['status'] ?? '');
                            $progressBits = (int)($job['progress_bits'] ?? 0);
                            $progressTotal = (int)($job['progress_bits_total'] ?? 0);
                            $heartbeatAt = (string)($job['heartbeat_at'] ?? '');
                            $lastError = (string)($job['last_error_code'] ?? '');
                            $payload = [];
                            if (isset($job['payload_json']) && is_string($job['payload_json'])) {
                                $decoded = json_decode($job['payload_json'], true);
                                if (is_array($decoded)) {
                                    $payload = $decoded;
                                }
                            }
                            $model = '';
                            if (isset($payload['model']) && is_string($payload['model'])) {
                                $model = trim($payload['model']);
                            } elseif (isset($payload['options']['model']) && is_string($payload['options']['model'])) {
                                $model = trim($payload['options']['model']);
                            }
                            try {
                                $mode = sv_ollama_mode_for_job_type((string)($job['type'] ?? ''));
                            } catch (Throwable $e) {
                                $mode = 'unknown';
                            }
                            $stageVersion = (string)($job['stage_version'] ?? '');
                            $statusClass = match ($status) {
                                'running' => 'status-running',
                                'queued', 'pending' => 'status-queued',
                                'done' => 'status-done',
                                'error' => 'status-error',
                                'cancelled' => 'status-cancelled',
                                default => 'status-queued',
                            };
                            $statusOrderValue = $statusOrder[$status] ?? 99;
                            $progressRatio = $progressTotal > 0 ? ($progressBits / $progressTotal) : 0;
                            $percent = $progressTotal > 0 ? (int)round($progressRatio * 100) : 0;
                            $heartbeatTs = $heartbeatAt !== '' ? (int)strtotime($heartbeatAt) : 0;
                            $canCancel = in_array($status, ['queued', 'pending', 'running'], true);
                            ?>
                            <tr data-job-id="<?= $jobId ?>"
                                data-media-id="<?= $mediaId ?>"
                                data-mode="<?= htmlspecialchars($mode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                data-status="<?= htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                data-status-order="<?= (int)$statusOrderValue ?>"
                                data-progress="<?= htmlspecialchars((string)$progressRatio, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                data-heartbeat="<?= (int)$heartbeatTs ?>">
                                <td><span class="status-badge <?= htmlspecialchars($statusClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-field="status"><?= htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></td>
                                <td>
                                    <div class="job-meta">
                                        <div>#<?= $jobId ?></div>
                                        <div><a href="media_view.php?id=<?= $mediaId ?>" target="_blank" rel="noopener">Media <?= $mediaId ?></a></div>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($mode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                <td data-field="progress"><?= $progressBits ?>/<?= $progressTotal ?><?= $progressTotal > 0 ? ' (' . $percent . '%)' : '' ?></td>
                                <td data-field="heartbeat"><?= $heartbeatAt !== '' ? htmlspecialchars($heartbeatAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '–' ?></td>
                                <td data-field="error"><?= $lastError !== '' ? htmlspecialchars($lastError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '–' ?></td>
                                <td data-field="model"><?= $model !== '' ? htmlspecialchars($model, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '–' ?></td>
                                <td data-field="stage"><?= $stageVersion !== '' ? htmlspecialchars($stageVersion, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '–' ?></td>
                                <td data-field="warnings" class="job-warnings">–</td>
                                <td>
                                    <div class="table-actions">
                                        <button class="btn btn--ghost btn--sm" type="button" data-action="requeue" data-mode="<?= htmlspecialchars($mode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-media-id="<?= $mediaId ?>">Requeue</button>
                                        <button class="btn btn--secondary btn--sm" type="button" data-action="cancel" data-job-id="<?= $jobId ?>" <?= $canCancel ? '' : 'disabled' ?>>Cancel</button>
                                        <button class="btn btn--danger btn--sm" type="button" data-action="delete" data-media-id="<?= $mediaId ?>" data-mode="<?= htmlspecialchars($mode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Delete</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="hint small">Sortierung: Klick auf Status/Progress/Heartbeat.</div>
    </div>
</div>

<?php sv_ui_footer(); ?>

<?php
declare(strict_types=1);

require_once __DIR__ . '/../SCRIPTS/common.php';
require_once __DIR__ . '/../SCRIPTS/operations.php';

try {
    $config = sv_load_config();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<pre>CONFIG-Fehler: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
    exit;
}

$configWarning     = $config['_config_warning'] ?? null;
$hasInternalAccess = sv_validate_internal_access($config, 'media_grid', false);

$dsn      = $config['db']['dsn'];
$user     = $config['db']['user']     ?? null;
$password = $config['db']['password'] ?? null;
$options  = $config['db']['options']  ?? [];

try {
    $pdo = new PDO($dsn, $user, $password, $options);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<pre>DB-Fehler: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
    exit;
}

function sv_media_view_url(int $id, bool $adult): string
{
    $params = [
        'id'    => $id,
        'adult' => $adult ? '1' : '0',
    ];

    return 'media_view.php?' . http_build_query($params);
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'forge_jobs') {
    $idsParam = $_GET['ids'] ?? ($_GET['media_id'] ?? '');
    $ids      = [];
    if (is_string($idsParam)) {
        $ids = array_filter(array_map('intval', explode(',', $idsParam)), fn($v) => $v > 0);
    } elseif (is_array($idsParam)) {
        $ids = array_filter(array_map('intval', $idsParam), fn($v) => $v > 0);
    }

    $jobs = sv_fetch_forge_jobs_grouped($pdo, $ids, 6);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['jobs' => $jobs], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'scan_jobs') {
    $pathFilter = isset($_GET['path']) && is_string($_GET['path']) ? trim($_GET['path']) : null;
    $jobs       = sv_fetch_scan_jobs($pdo, $pathFilter, 25);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['jobs' => $jobs], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/* FSK18-Flag: nur sichtbar, wenn adult=1 oder 18=true in der URL */
$showAdult =
    (isset($_GET['adult']) && $_GET['adult'] === '1')
    || (isset($_GET['18']) && strcasecmp((string)$_GET['18'], 'true') === 0);

$actionMessage = null;
$actionSuccess = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        sv_require_internal_access($config, 'media_forge_regen');

        $action  = is_string($_POST['action'] ?? null) ? trim((string)$_POST['action']) : '';
        $mediaId = isset($_POST['media_id']) ? (int)$_POST['media_id'] : 0;

        if ($mediaId <= 0) {
            throw new RuntimeException('Ungültige Aktion.');
        }

        if ($action === 'forge_regen') {
            $logLines = [];
            [$logFile, $logger] = sv_create_operation_log($config, 'forge_regen', $logLines, 10);
            $result   = sv_run_forge_regen_replace($pdo, $config, $mediaId, $logger, []);
            $jobId    = (int)($result['job_id'] ?? 0);
            $status   = (string)($result['status'] ?? 'queued');

            $actionSuccess = true;
            $actionMessage = 'Forge-Regeneration angestoßen: Job #' . $jobId . ' (' . $status . ').';
            if (!empty($result['resolved_model'])) {
                $actionMessage .= ' Modell: ' . htmlspecialchars((string)$result['resolved_model'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '.';
            }
            if (!empty($result['worker_pid'])) {
                $actionMessage .= ' Worker-PID: ' . (int)$result['worker_pid'] . '.';
            }
            if (!empty($result['worker_status_unknown'])) {
                $actionMessage .= ' Worker-Status unbekannt (Hintergrundstart).';
            }
            if (!empty($result['regen_plan']['fallback_used'])) {
                $actionMessage .= ' Hinweis: Prompt-Fallback aktiv.';
            }
            if (!empty($result['regen_plan']['tag_prompt_used'])) {
                $actionMessage .= ' Tag-basierte Rekonstruktion genutzt.';
            }

            sv_audit_log($pdo, 'forge_regen_web', 'jobs', $jobId, [
                'media_id'   => $mediaId,
                'job_id'     => $jobId,
                'status'     => $status,
                'log_file'   => $logFile,
                'worker_pid' => $result['worker_pid'] ?? null,
            ]);
        } elseif ($action === 'mark_missing') {
            $logger = sv_operation_logger(null, $logLines ?? []);
            $result = sv_mark_media_missing($pdo, $mediaId, $logger);
            $actionSuccess = true;
            $actionMessage = $result['changed'] ? 'Medium als missing markiert.' : 'Medium war bereits als missing markiert.';
        } else {
            throw new RuntimeException('Unbekannte Aktion.');
        }
    } catch (Throwable $e) {
        $actionSuccess = false;
        $actionMessage = 'Aktion fehlgeschlagen: ' . $e->getMessage();
    }
}

$page    = max(1, (int)($_GET['p'] ?? 1));
$perPage = 200; // Grid-tauglich, bei Bedarf anpassen
$offset  = ($page - 1) * $perPage;

$pathFilter          = trim($_GET['q'] ?? '');
$statusFilterRaw     = $_GET['status'] ?? 'alle';
$typeFilterRaw       = $_GET['type'] ?? 'image';
$issuesFilter        = isset($_GET['issues']) && $_GET['issues'] === '1';
$promptQualityFilter = $_GET['prompt_quality'] ?? 'all';
$runningFilter       = isset($_GET['running']) && $_GET['running'] === '1';

$statusFilter = in_array($statusFilterRaw, ['alle', 'active', 'missing'], true) ? $statusFilterRaw : 'alle';
$typeFilter   = in_array($typeFilterRaw, ['all', 'image', 'video'], true) ? $typeFilterRaw : 'image';
$promptQualityFilter = in_array($promptQualityFilter, ['all', 'C'], true) ? $promptQualityFilter : 'all';

$where  = [];
$params = [];

if ($typeFilter !== 'all') {
    $where[]         = 'm.type = :type';
    $params[':type'] = $typeFilter;
}

if (!$showAdult) {
    $where[] = '(m.has_nsfw IS NULL OR m.has_nsfw = 0)';
}

if ($pathFilter !== '') {
    $where[]         = 'm.path LIKE :path';
    $params[':path'] = '%' . $pathFilter . '%';
}

if ($statusFilter === 'active') {
    $where[] = 'm.is_missing = 0';
} elseif ($statusFilter === 'missing') {
    $where[] = 'm.is_missing = 1';
}

$whereSql = $where === [] ? '1=1' : implode(' AND ', $where);
$promptCompleteClause = sv_prompt_core_complete_condition('p4');

/* Gesamtanzahl für Pagination */
$countSql = 'SELECT COUNT(*) AS cnt FROM media m WHERE ' . $whereSql;
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

/* Daten holen */
$listSql = 'SELECT m.id, m.path, m.type, m.status, m.is_missing, m.has_nsfw, m.rating, m.width, m.height,
            m.created_at, m.imported_at, m.updated_at,
            EXISTS (SELECT 1 FROM prompts p4 WHERE p4.media_id = m.id AND ' . $promptCompleteClause . ') AS prompt_complete,
            EXISTS (SELECT 1 FROM prompts p4 WHERE p4.media_id = m.id) AS prompt_present,
            p.prompt AS prompt_text, p.width AS prompt_width, p.height AS prompt_height
            FROM media m
            LEFT JOIN prompts p ON p.id = (SELECT p2.id FROM prompts p2 WHERE p2.media_id = m.id ORDER BY p2.id DESC LIMIT 1)
            WHERE ' . $whereSql . '
            ORDER BY m.id DESC
            LIMIT :limit OFFSET :offset';

$listStmt = $pdo->prepare($listSql);
foreach ($params as $k => $v) {
    $listStmt->bindValue($k, $v);
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();

$rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);
$pages = max(1, (int)ceil($total / $perPage));
$mediaIds = array_map('intval', array_column($rows, 'id'));
$jobsData = $mediaIds ? sv_fetch_forge_jobs_grouped($pdo, $mediaIds, 6) : [];

$displayRows = [];
foreach ($rows as $row) {
    $id             = (int)$row['id'];
    $isMissing      = (int)($row['is_missing'] ?? 0) === 1;
    $promptPresent  = (int)($row['prompt_present'] ?? 0) === 1;
    $promptComplete = (int)($row['prompt_complete'] ?? 0) === 1;

    $qualityData = $promptPresent
        ? sv_prompt_quality_from_text(
            $row['prompt_text'] ?? null,
            isset($row['prompt_width']) ? (int)$row['prompt_width'] : null,
            isset($row['prompt_height']) ? (int)$row['prompt_height'] : null
        )
        : ['quality_class' => 'none'];

    $qualityClass = (string)($qualityData['quality_class'] ?? 'none');

    $jobPayload   = $jobsData[$id]['jobs'] ?? [];
    $hasRunning   = false;
    $latestStatus = null;
    foreach ($jobPayload as $job) {
        $status = strtolower((string)($job['status'] ?? ''));
        if ($latestStatus === null) {
            $latestStatus = $status;
        }
        if ($status === 'running' || $status === 'queued') {
            $hasRunning = true;
            break;
        }
    }

    $hasIssue = $isMissing || !$promptPresent || !$promptComplete || $qualityClass === 'C';

    if ($promptQualityFilter === 'C' && $qualityClass !== 'C') {
        continue;
    }
    if ($issuesFilter && !$hasIssue) {
        continue;
    }
    if ($runningFilter && !$hasRunning) {
        continue;
    }

    $row['quality']          = $qualityData;
    $row['quality_class']    = $qualityClass;
    $row['prompt_present']   = $promptPresent;
    $row['prompt_complete']  = $promptComplete;
    $row['has_issue']        = $hasIssue;
    $row['has_running_job']  = $hasRunning;
    $row['latest_job_status'] = $latestStatus;

    $displayRows[] = $row;
}

$mediaIds = array_map('intval', array_column($displayRows, 'id'));
if ($mediaIds === []) {
    $jobsData = [];
} else {
    $jobsData = array_intersect_key($jobsData, array_flip($mediaIds));
}

$baseQuery = [
    'adult' => $showAdult ? '1' : '0',
    'q'     => $pathFilter,
];

$currentFilters = [
    'type'            => $typeFilter,
    'status'          => $statusFilter,
    'issues'          => $issuesFilter ? '1' : null,
    'prompt_quality'  => $promptQualityFilter,
    'running'         => $runningFilter ? '1' : null,
];

function sv_build_query(array $base, array $overrides): string
{
    $merged = array_merge($base, array_filter($overrides, static fn($v) => $v !== null && $v !== ''));
    return '?' . http_build_query($merged);
}

function sv_tab_active(array $current, array $expected): bool
{
    foreach ($expected as $key => $value) {
        $currentVal = $current[$key] ?? null;
        if ($currentVal !== $value) {
            return false;
        }
    }
    foreach (['issues', 'prompt_quality', 'running'] as $flag) {
        if (!array_key_exists($flag, $expected) && !empty($current[$flag])) {
            return false;
        }
    }

    return true;
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>SuperVisOr Medien</title>
    <link rel="stylesheet" href="mediadb.css">
</head>
<body class="media-grid-page">
<header class="page-header">
    <div>
        <h1>SuperVisOr Medien</h1>
        <div class="header-stats">
            Gesamt: <?= (int)$total ?> Einträge | <?= $showAdult ? 'FSK18 sichtbar' : 'FSK18 ausgeblendet' ?>
        </div>
    </div>
    <div class="header-actions">
        <form method="get" class="search-bar">
            <input type="hidden" name="adult" value="<?= $showAdult ? '1' : '0' ?>">
            <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <input type="hidden" name="type" value="<?= htmlspecialchars($typeFilter, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?php if ($issuesFilter): ?><input type="hidden" name="issues" value="1"><?php endif; ?>
            <?php if ($promptQualityFilter !== 'all'): ?><input type="hidden" name="prompt_quality" value="<?= htmlspecialchars($promptQualityFilter, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?php endif; ?>
            <?php if ($runningFilter): ?><input type="hidden" name="running" value="1"><?php endif; ?>
            <input type="text" name="q" value="<?= htmlspecialchars($pathFilter, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="Pfad oder Name" aria-label="Pfad oder Name">
            <button type="submit" class="btn btn-secondary">Suche</button>
        </form>
        <div class="fsk-toggle">
            <a href="?<?= http_build_query(array_merge($baseQuery, $currentFilters, ['adult' => '0', 'p' => 1])) ?>" class="<?= $showAdult ? '' : 'active' ?>">FSK18 aus</a>
            <a href="?<?= http_build_query(array_merge($baseQuery, $currentFilters, ['adult' => '1', 'p' => 1])) ?>" class="<?= $showAdult ? 'active' : '' ?>">FSK18 an</a>
        </div>
    </div>
</header>
<?php if (!empty($configWarning)): ?>
    <div style="margin: 0.5rem 1rem; padding: 0.6rem 0.8rem; background: #fff3cd; color: #7f4e00; border: 1px solid #ffeeba;">
        <?= htmlspecialchars($configWarning, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    </div>
<?php endif; ?>

<div class="quick-filters">
    <?php
    $tabParams = [
        [
            'label' => 'Alle',
            'overrides' => ['type' => 'all', 'status' => 'alle', 'issues' => null, 'prompt_quality' => 'all', 'running' => null],
        ],
        [
            'label' => 'Images',
            'overrides' => ['type' => 'image', 'status' => 'alle', 'issues' => null, 'prompt_quality' => 'all', 'running' => null],
        ],
        [
            'label' => 'Videos',
            'overrides' => ['type' => 'video', 'status' => 'alle', 'issues' => null, 'prompt_quality' => 'all', 'running' => null],
        ],
        [
            'label' => 'Issues',
            'overrides' => ['issues' => '1', 'status' => 'alle', 'prompt_quality' => 'all', 'running' => null, 'type' => $typeFilter],
        ],
        [
            'label' => 'Prompt C/Schwach',
            'overrides' => ['prompt_quality' => 'C', 'status' => 'alle', 'issues' => null, 'running' => null, 'type' => $typeFilter],
        ],
        [
            'label' => 'Regen läuft',
            'overrides' => ['running' => '1', 'status' => 'alle', 'issues' => null, 'prompt_quality' => 'all', 'type' => $typeFilter],
        ],
    ];

    foreach ($tabParams as $tab) {
        $overrides = $tab['overrides'];
        $tabQuery  = sv_build_query(array_merge($baseQuery, ['p' => 1]), $overrides);
        $isActive  = sv_tab_active(array_merge($currentFilters, ['status' => $statusFilter]), $overrides);
        ?>
        <a class="filter-tab <?= $isActive ? 'active' : '' ?>" href="<?= htmlspecialchars($tabQuery, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?= htmlspecialchars($tab['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </a>
    <?php } ?>
    <div class="quick-filter-spacer"></div>
    <div class="compact-status">
        <label>
            <span>Status:</span>
            <select name="status" form="status-form">
                <option value="alle" <?= $statusFilter === 'alle' ? 'selected' : '' ?>>alle</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>nur vorhandene</option>
                <option value="missing" <?= $statusFilter === 'missing' ? 'selected' : '' ?>>nur fehlende</option>
            </select>
        </label>
    </div>
</div>
<form id="status-form" method="get" class="hidden-form">
    <input type="hidden" name="adult" value="<?= $showAdult ? '1' : '0' ?>">
    <input type="hidden" name="q" value="<?= htmlspecialchars($pathFilter, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <input type="hidden" name="type" value="<?= htmlspecialchars($typeFilter, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <?php if ($issuesFilter): ?><input type="hidden" name="issues" value="1"><?php endif; ?>
    <?php if ($promptQualityFilter !== 'all'): ?><input type="hidden" name="prompt_quality" value="<?= htmlspecialchars($promptQualityFilter, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?php endif; ?>
    <?php if ($runningFilter): ?><input type="hidden" name="running" value="1"><?php endif; ?>
</form>

<div class="pager compact">
    <span>Seite <?= (int)$page ?> / <?= (int)$pages ?></span>
    <?php if ($page > 1): ?>
        <a href="?<?= http_build_query(array_merge($baseQuery, $currentFilters, ['p' => $page - 1])) ?>">« zurück</a>
    <?php endif; ?>
    <?php if ($page < $pages): ?>
        <a href="?<?= http_build_query(array_merge($baseQuery, $currentFilters, ['p' => $page + 1])) ?>">weiter »</a>
    <?php endif; ?>
</div>

<?php if ($actionMessage !== null): ?>
    <div class="action-message <?= $actionSuccess ? 'success' : 'error' ?>">
        <?= htmlspecialchars($actionMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    </div>
<?php endif; ?>

<div class="grid-layout">
    <section class="media-grid">
        <?php if ($displayRows === []): ?>
            <div class="empty">Keine Einträge für diesen Filter.</div>
        <?php endif; ?>
        <?php foreach ($displayRows as $row):
            $id        = (int)$row['id'];
            $path      = (string)$row['path'];
            $type      = (string)$row['type'];
            $status    = (string)($row['status'] ?? '');
            $isMissing = (int)($row['is_missing'] ?? 0) === 1;
            $hasNsfw   = (int)($row['has_nsfw'] ?? 0) === 1;
            $rating    = (int)($row['rating'] ?? 0);
            $width     = isset($row['width']) ? (int)$row['width'] : null;
            $height    = isset($row['height']) ? (int)$row['height'] : null;

            $qualityClass   = (string)($row['quality_class'] ?? 'none');
            $promptPresent  = (bool)($row['prompt_present'] ?? false);
            $promptComplete = (bool)($row['prompt_complete'] ?? false);
            $hasIssue       = (bool)($row['has_issue'] ?? false);
            $hasRunningJob  = (bool)($row['has_running_job'] ?? false);
            $latestJob      = (string)($row['latest_job_status'] ?? '');

            $thumbParams = ['id' => $id];
            if ($showAdult) {
                $thumbParams['adult'] = '1';
            }
            $thumbUrl  = 'thumb.php?' . http_build_query($thumbParams);
            $detailUrl = sv_media_view_url($id, $showAdult);

            $statusVariant = 'clean';
            if ($hasRunningJob) {
                $statusVariant = 'busy';
            } elseif ($isMissing || ($status !== '' && $status !== 'active')) {
                $statusVariant = 'bad';
            } elseif ($hasIssue || $qualityClass === 'C') {
                $statusVariant = 'warn';
            }

            $promptLabel = $promptPresent ? 'PQ ' . htmlspecialchars($qualityClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : 'kein Prompt';
            $resolution  = ($width && $height) ? ($width . '×' . $height) : 'keine Größe';
            ?>
            <article class="media-card status-<?= $statusVariant ?>" data-media-id="<?= $id ?>">
                <div class="card-badges">
                    <?php if ($hasRunningJob): ?>
                        <span class="pill pill-busy" title="Regeneration läuft">●</span>
                    <?php endif; ?>
                    <?php if ($isMissing): ?>
                        <span class="pill pill-bad">Missing</span>
                    <?php elseif ($status !== '' && $status !== 'active'): ?>
                        <span class="pill pill-warn">Status <?= htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <?php endif; ?>
                    <?php if ($qualityClass === 'C'): ?>
                        <span class="pill pill-warn">Prompt C</span>
                    <?php elseif (!$promptComplete && $promptPresent): ?>
                        <span class="pill pill-warn">Prompt unvollständig</span>
                    <?php elseif (!$promptPresent): ?>
                        <span class="pill">Kein Prompt</span>
                    <?php endif; ?>
                    <?php if ($hasNsfw): ?>
                        <span class="pill pill-nsfw">FSK18</span>
                    <?php endif; ?>
                    <?php if ($rating > 0): ?>
                        <span class="pill">Rating <?= $rating ?></span>
                    <?php endif; ?>
                </div>

                <div class="thumb-wrap">
                    <?php if ($isMissing): ?>
                        <div class="thumb-missing">fehlend</div>
                    <?php else: ?>
                        <img
                            src="<?= htmlspecialchars($thumbUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                            data-base-src="<?= htmlspecialchars($thumbUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                            data-media-id="<?= $id ?>"
                            loading="lazy"
                            alt="ID <?= $id ?>">
                    <?php endif; ?>
                    <div class="card-actions">
                        <form method="post" class="action-form">
                            <input type="hidden" name="action" value="forge_regen">
                            <input type="hidden" name="media_id" value="<?= $id ?>">
                            <button type="submit" class="btn btn-primary">Forge Regen</button>
                        </form>
                        <a class="btn btn-secondary" href="<?= htmlspecialchars($detailUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener">Details</a>
                        <?php if ($hasInternalAccess): ?>
                            <form method="post" class="action-form">
                                <input type="hidden" name="action" value="mark_missing">
                                <input type="hidden" name="media_id" value="<?= $id ?>">
                                <button type="submit" class="btn btn-danger" <?= $isMissing ? 'disabled' : '' ?>>Missing</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card-info">
                    <div class="info-line">
                        <span class="info-chip"><?= $type === 'video' ? 'Video' : 'Bild' ?></span>
                        <span class="info-chip"><?= htmlspecialchars($resolution, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                        <span class="info-chip <?= $qualityClass === 'C' ? 'chip-warn' : '' ?>"><?= $promptLabel ?></span>
                    </div>
                    <div class="info-path" title="<?= htmlspecialchars($path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        ID <?= $id ?> · <?= htmlspecialchars(basename($path), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </section>

    <aside class="side-panels">
        <div class="panel">
            <div class="panel-header">Scan-Jobs</div>
            <div class="panel-subtitle">Status der scan_path-Queue für aktuelle Filter.</div>
            <div id="scan-jobs-list" class="panel-content">Lade Scan-Jobs ...</div>
        </div>
        <div class="panel">
            <div class="panel-header">Forge Jobs</div>
            <div class="panel-subtitle">Für die sichtbaren Medien. Aktualisiert alle 4 Sekunden.</div>
            <div id="forge-jobs" class="panel-content"></div>
        </div>
    </aside>
</div>

<div class="pager compact">
    <span>Seite <?= (int)$page ?> / <?= (int)$pages ?></span>
    <?php if ($page > 1): ?>
        <a href="?<?= http_build_query(array_merge($baseQuery, $currentFilters, ['p' => $page - 1])) ?>">« zurück</a>
    <?php endif; ?>
    <?php if ($page < $pages): ?>
        <a href="?<?= http_build_query(array_merge($baseQuery, $currentFilters, ['p' => $page + 1])) ?>">weiter »</a>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const statusSelect = document.querySelector('.compact-status select');
    const statusForm = document.getElementById('status-form');
    if (statusSelect && statusForm) {
        statusSelect.addEventListener('change', () => statusForm.submit());
    }
});
</script>
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

    const filterPath = <?= json_encode($pathFilter, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    async function loadScanJobs() {
        try {
            const url = 'media.php?ajax=scan_jobs' + (filterPath ? '&path=' + encodeURIComponent(filterPath) : '');
            const response = await fetch(url, { cache: 'no-store' });
            const data = await response.json();
            const jobs = Array.isArray(data.jobs) ? data.jobs : [];
            if (jobs.length === 0) {
                target.innerHTML = '<p>Keine Scan-Jobs gefunden.</p>';
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
                return `<div class="job-entry"><div class="job-header"><span>#${escapeHtml(job.id)} – ${status}</span><span>${escapeHtml(job.updated_at || '')}</span></div><div class="job-meta">${pathText}${limitText}${worker}${stats}</div></div>`;
            });

            target.innerHTML = items.join('');
        } catch (err) {
            target.innerHTML = '<p>Scan-Jobs konnten nicht geladen werden.</p>';
        }
    }

    loadScanJobs();
    setInterval(loadScanJobs, 5000);
})();
</script>
<script>
    const visibleIds = <?= json_encode($mediaIds) ?>;
    let jobState = <?= json_encode($jobsData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    function statusClass(status) {
        switch ((status || '').toLowerCase()) {
            case 'running': return 'status-running';
            case 'done': return 'status-done';
            case 'error': return 'status-error';
            default: return 'status-queued';
        }
    }

    function renderJobs(state) {
        const container = document.getElementById('forge-jobs');
        if (!container) return;
        container.innerHTML = '';

        if (!state || Object.keys(state).length === 0) {
            container.textContent = 'Keine Forge-Jobs vorhanden.';
            return;
        }

        visibleIds.forEach((id) => {
            const payload = state[id];
            if (!payload || !payload.jobs || payload.jobs.length === 0) {
                return;
            }
            const wrap = document.createElement('div');
            const title = document.createElement('div');
            title.textContent = 'Media #' + id;
            title.style.fontWeight = '700';
            wrap.appendChild(title);

            payload.jobs.forEach((job) => {
                const jobDiv = document.createElement('div');
                jobDiv.className = 'job-entry';
                const header = document.createElement('div');
                header.className = 'job-header';
                const idSpan = document.createElement('span');
                idSpan.textContent = '#' + job.id;
                const statusSpan = document.createElement('span');
                statusSpan.className = 'job-status ' + statusClass(job.status);
                statusSpan.textContent = job.status;
                header.appendChild(idSpan);
                header.appendChild(statusSpan);
                jobDiv.appendChild(header);

                const meta = document.createElement('div');
                meta.className = 'job-meta';
                meta.textContent = (job.created_at || '') + (job.updated_at ? ' → ' + job.updated_at : '');
                jobDiv.appendChild(meta);

                if (job.info) {
                    const info = document.createElement('div');
                    info.textContent = job.info;
                    jobDiv.appendChild(info);
                }

                if (job.worker_pid) {
                    const worker = document.createElement('div');
                    worker.className = 'job-worker';
                    let workerText = 'Worker PID ' + job.worker_pid;
                    if (job.worker_running) {
                        workerText += ' (läuft)';
                    } else if (job.worker_unknown) {
                        workerText += ' (Status unbekannt)';
                    } else {
                        workerText += ' (beendet)';
                    }
                    if (job.worker_started_at) {
                        workerText += ' seit ' + job.worker_started_at;
                    }
                    worker.textContent = workerText;
                    jobDiv.appendChild(worker);
                }

                if (job.error_message) {
                    const error = document.createElement('div');
                    error.className = 'job-error';
                    error.textContent = job.error_message;
                    jobDiv.appendChild(error);
                }

                wrap.appendChild(jobDiv);
            });

            container.appendChild(wrap);
        });
    }

    function refreshThumbnail(mediaId, token) {
        const img = document.querySelector('img[data-media-id="' + mediaId + '"]');
        if (!img) return;
        const base = img.getAttribute('data-base-src') || img.src;
        const separator = base.includes('?') ? '&' : '?';
        const bust = token || Date.now();
        img.src = base + separator + 't=' + encodeURIComponent(bust);
    }

    function updateThumbsOnDone(oldState, newState) {
        const previous = {};
        Object.entries(oldState || {}).forEach(([mid, payload]) => {
            if (payload.jobs && payload.jobs[0]) {
                previous[mid] = payload.jobs[0].status;
            }
        });
        Object.entries(newState || {}).forEach(([mid, payload]) => {
            if (payload.jobs && payload.jobs[0]) {
                const latest = payload.jobs[0];
                if (latest.status === 'done' && previous[mid] !== 'done') {
                    refreshThumbnail(mid, latest.version_token || latest.updated_at || latest.id);
                }
            }
        });
    }

    async function pollJobs() {
        if (!visibleIds || visibleIds.length === 0) {
            return;
        }
        const params = new URLSearchParams(window.location.search);
        params.set('ajax', 'forge_jobs');
        params.set('ids', visibleIds.join(','));
        try {
            const response = await fetch('media.php?' + params.toString(), { cache: 'no-store' });
            if (!response.ok) {
                setTimeout(pollJobs, 4000);
                return;
            }
            const data = await response.json();
            const jobs = data.jobs || {};
            updateThumbsOnDone(jobState, jobs);
            jobState = jobs;
            renderJobs(jobState);
        } catch (e) {
            console.warn('Forge-Job-Polling fehlgeschlagen', e);
        } finally {
            setTimeout(pollJobs, 4000);
        }
    }

    renderJobs(jobState);
    pollJobs();
</script>

</body>
</html>

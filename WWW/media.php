<?php
declare(strict_types=1);

$config = require __DIR__ . '/../CONFIG/config.php';
require_once __DIR__ . '/../SCRIPTS/operations.php';

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

        if ($action !== 'forge_regen' || $mediaId <= 0) {
            throw new RuntimeException('Ungültige Aktion.');
        }

        $logLines = [];
        [$logFile, $logger] = sv_create_operation_log($config, 'forge_regen', $logLines, 10);
        $result   = sv_run_forge_regen_replace($pdo, $config, $mediaId, $logger);
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
            'media_id'  => $mediaId,
            'job_id'    => $jobId,
            'status'    => $status,
            'log_file'  => $logFile,
            'worker_pid'=> $result['worker_pid'] ?? null,
        ]);
    } catch (Throwable $e) {
        $actionSuccess = false;
        $actionMessage = 'Aktion fehlgeschlagen: ' . $e->getMessage();
    }
}

$page    = max(1, (int)($_GET['p'] ?? 1));
$perPage = 200; // Grid-tauglich, bei Bedarf anpassen
$offset  = ($page - 1) * $perPage;

$pathFilter   = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? 'alle';

$where  = ['m.type = :type'];
$params = [':type' => 'image'];

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

$whereSql = implode(' AND ', $where);

/* Gesamtanzahl für Pagination */
$countSql = 'SELECT COUNT(*) AS cnt FROM media m WHERE ' . $whereSql;
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

/* Daten holen */
$listSql = 'SELECT m.id, m.path, m.type, m.is_missing, m.has_nsfw, m.rating, m.created_at, m.imported_at, m.updated_at
            FROM media m
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

$pages      = max(1, (int)ceil($total / $perPage));
$mediaIds   = array_map('intval', array_column($rows, 'id'));
$jobsData   = sv_fetch_forge_jobs_grouped($pdo, $mediaIds, 6);
$prefillKey = htmlspecialchars((string)($_GET['internal_key'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>SuperVisOr Medien</title>
    <style>
        body {
            font-family: system-ui, -apple-system, sans-serif;
            font-size: 14px;
            margin: 10px;
        }
        h1 {
            font-size: 20px;
            margin-bottom: 10px;
        }
        form.filter {
            margin-bottom: 10px;
        }
        .meta {
            font-size: 12px;
            color: #555;
        }
        .layout {
            display: grid;
            grid-template-columns: 3fr 1fr;
            gap: 12px;
        }
        .gallery {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .item {
            width: 200px;
            box-sizing: border-box;
            border: 1px solid #ddd;
            padding: 4px;
            border-radius: 4px;
            background: #fafafa;
            overflow: hidden;
        }
        .item.missing {
            opacity: 0.4;
        }
        .thumb-wrap {
            width: 100%;
            aspect-ratio: 1/1;
            background: #ccc;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .thumb-wrap img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .title {
            font-size: 11px;
            word-break: break-all;
            margin-top: 4px;
        }
        .badges {
            font-size: 11px;
            margin-top: 2px;
        }
        .badge {
            display: inline-block;
            padding: 1px 4px;
            border-radius: 3px;
            background: #eee;
            margin-right: 3px;
        }
        .badge.nsfw {
            background: #c62828;
            color: #fff;
        }
        .pager {
            margin: 10px 0;
            font-size: 13px;
        }
        .pager a {
            margin-right: 5px;
        }
        .action-message {
            margin: 10px 0;
            padding: 8px;
            border-radius: 4px;
        }
        .action-message.success {
            background: #e8f5e9;
            border: 1px solid #a5d6a7;
        }
        .action-message.error {
            background: #ffebee;
            border: 1px solid #ef9a9a;
        }
        .forge-form {
            margin-top: 6px;
        }
        .forge-form button {
            padding: 4px 6px;
            font-size: 12px;
            cursor: pointer;
        }
        .jobs-panel {
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 8px;
            background: #fff;
            max-height: calc(100vh - 120px);
            overflow: auto;
        }
        .job-entry {
            border-bottom: 1px solid #eee;
            padding: 6px 0;
            font-size: 12px;
        }
        .job-entry:last-child {
            border-bottom: none;
        }
        .job-header {
            display: flex;
            justify-content: space-between;
            font-weight: 600;
        }
        .job-status {
            padding: 2px 4px;
            border-radius: 3px;
            font-size: 11px;
        }
        .status-queued { background: #fff3e0; }
        .status-running { background: #e3f2fd; }
        .status-done { background: #e8f5e9; }
        .status-error { background: #ffebee; }
        .job-meta { color: #555; }
        .job-error { color: #c62828; }
        .job-worker { color: #2e7d32; }
        .help-text { font-size: 11px; color: #666; margin-top: 4px; }
        @media (max-width: 1100px) {
            .layout { grid-template-columns: 1fr; }
            .jobs-panel { max-height: none; }
        }
    </style>
</head>
<body>

<h1>SuperVisOr Medien</h1>

<div class="meta">
    Gesamt: <?= (int)$total ?> Bilder
    <?php if (!$showAdult): ?>
        | FSK18 ausgeblendet
    <?php else: ?>
        | FSK18 sichtbar
    <?php endif; ?>
</div>

<form method="get" class="filter">
    <label>
        Status:
        <select name="status">
            <option value="alle"   <?= $statusFilter === 'alle'   ? 'selected' : '' ?>>alle</option>
            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>nur vorhandene</option>
            <option value="missing"<?= $statusFilter === 'missing'? 'selected' : '' ?>>nur fehlende</option>
        </select>
    </label>
    <label>
        Pfad enthält:
        <input type="text" name="q" size="40"
               value="<?= htmlspecialchars($pathFilter, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    </label>
    <input type="hidden" name="adult" value="<?= $showAdult ? '1' : '0' ?>">
    <button type="submit">Anzeigen</button>
</form>

<div class="meta">
    FSK18-Link: <code>?adult=1</code> oder <code>?18=True</code>
</div>

<div class="jobs-panel" id="scan-jobs-panel">
    <h3>Scan-Jobs (asynchron)</h3>
    <div class="help-text">Status der scan_path-Queue für aktuelle Filter.</div>
    <div id="scan-jobs-list">Lade Scan-Jobs ...</div>
</div>

<?php if ($actionMessage !== null): ?>
    <div class="action-message <?= $actionSuccess ? 'success' : 'error' ?>">
        <?= htmlspecialchars($actionMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    </div>
<?php endif; ?>

<div class="pager">
    Seite <?= (int)$page ?> / <?= (int)$pages ?> |
    <?php if ($page > 1): ?>
        <a href="?<?= http_build_query(['p' => $page - 1, 'q' => $pathFilter, 'status' => $statusFilter, 'adult' => $showAdult ? '1' : '0']) ?>">« zurück</a>
    <?php endif; ?>
    <?php if ($page < $pages): ?>
        <a href="?<?= http_build_query(['p' => $page + 1, 'q' => $pathFilter, 'status' => $statusFilter, 'adult' => $showAdult ? '1' : '0']) ?>">weiter »</a>
    <?php endif; ?>
</div>

<div class="layout">
    <div>
        <div class="gallery">
            <?php foreach ($rows as $row):
                $id        = (int)$row['id'];
                $path      = (string)$row['path'];
                $isMissing = (int)($row['is_missing'] ?? 0) === 1;
                $hasNsfw   = (int)($row['has_nsfw'] ?? 0) === 1;
                $rating    = (int)($row['rating'] ?? 0);

                $thumbUrl  = 'thumb.php?id=' . $id;
                $detailUrl = sv_media_view_url($id, $showAdult);
                ?>
                <div class="item<?= $isMissing ? ' missing' : '' ?>" data-media-id="<?= $id ?>">
                    <div class="thumb-wrap">
                        <?php if ($isMissing): ?>
                            <span>fehlend</span>
                        <?php else: ?>
                            <a href="<?= htmlspecialchars($detailUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank">
                                <img
                                    src="<?= htmlspecialchars($thumbUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                    data-base-src="<?= htmlspecialchars($thumbUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                    data-media-id="<?= $id ?>"
                                    loading="lazy"
                                    alt="ID <?= $id ?>">
                            </a>
                        <?php endif; ?>
                    </div>
                    <div class="title">
                        ID <?= $id ?><br>
                        <?= htmlspecialchars(basename($path), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </div>
                    <div class="badges">
                        <?php if ($hasNsfw): ?>
                            <span class="badge nsfw">FSK18</span>
                        <?php endif; ?>
                        <?php if ($rating > 0): ?>
                            <span class="badge">Rating <?= $rating ?></span>
                        <?php endif; ?>
                        <?php if ($isMissing): ?>
                            <span class="badge">missing</span>
                        <?php endif; ?>
                    </div>
                    <form method="post" class="forge-form">
                        <input type="hidden" name="action" value="forge_regen">
                        <input type="hidden" name="media_id" value="<?= $id ?>">
                        <input type="hidden" name="internal_key" value="<?= $prefillKey ?>">
                        <button type="submit">Forge Regen</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="pager">
            Seite <?= (int)$page ?> / <?= (int)$pages ?> |
            <?php if ($page > 1): ?>
                <a href="?<?= http_build_query(['p' => $page - 1, 'q' => $pathFilter, 'status' => $statusFilter, 'adult' => $showAdult ? '1' : '0']) ?>">« zurück</a>
            <?php endif; ?>
            <?php if ($page < $pages): ?>
                <a href="?<?= http_build_query(['p' => $page + 1, 'q' => $pathFilter, 'status' => $statusFilter, 'adult' => $showAdult ? '1' : '0']) ?>">weiter »</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="jobs-panel">
        <h2>Forge Jobs</h2>
        <div class="help-text">Anzeigen für die sichtbaren Medien. Aktualisiert alle 4 Sekunden.</div>
        <div id="forge-jobs"></div>
    </div>
</div>

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
                    refreshThumbnail(mid, latest.updated_at || latest.id);
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

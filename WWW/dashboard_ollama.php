<?php
declare(strict_types=1);

require_once __DIR__ . '/../SCRIPTS/common.php';
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

$internalAccess = sv_internal_access_result($config, 'dashboard_ollama', ['allow_loopback_bypass' => true]);

$runtimeStatus = sv_ollama_read_runtime_global_status($config);
$counts = [
    'queued' => (int)($runtimeStatus['queue_queued'] ?? 0),
    'pending' => max(0, (int)($runtimeStatus['queue_pending'] ?? 0) - (int)($runtimeStatus['queue_queued'] ?? 0)),
    'running' => (int)($runtimeStatus['queue_running'] ?? 0),
    'done' => 0,
    'error' => 0,
    'cancelled' => 0,
];

sv_ui_header('Ollama-Dashboard', 'ollama');
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Ollama-Dashboard</h1>
        <div class="hint">Fast-Path Dashboard (Runtime-Dateien zuerst, keine DB-Queries beim Laden).</div>
    </div>
</div>

<?php if (empty($internalAccess['ok'])): ?>
    <div class="panel">
        <div class="action-note error">
            Zugriff eingeschränkt: <?= htmlspecialchars((string)($internalAccess['reason_code'] ?? 'forbidden'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </div>
    </div>
<?php endif; ?>

<div class="ollama-dashboard" data-ollama-dashboard data-endpoint="ollama.php" data-poll-interval="10000" data-heartbeat-stale="180">
    <div class="panel">
        <div class="status-pills" style="margin-bottom:12px; display:flex; gap:8px; flex-wrap:wrap;">
            <span class="pill">queued <span data-status-count="queued"><?= $counts['queued'] ?></span></span>
            <span class="pill">pending <span data-status-count="pending"><?= $counts['pending'] ?></span></span>
            <span class="pill">running <span data-status-count="running"><?= $counts['running'] ?></span></span>
            <span class="pill">done <span data-status-count="done">0</span></span>
            <span class="pill">error <span data-status-count="error">0</span></span>
            <span class="pill">cancelled <span data-status-count="cancelled">0</span></span>
            <span class="pill">läuft <span data-ollama-running><?= (int)$counts['running'] ?></span></span>
            <span class="pill">max <span data-ollama-max-concurrency><?= (int)sv_ollama_max_concurrency($config) ?></span></span>
            <span class="pill">gesperrt <span data-ollama-runner-locked>–</span></span>
            <span class="pill">Worker aktiv <span data-ollama-worker-running>–</span></span>
            <span class="pill">Grund <span data-ollama-worker-reason>–</span></span>
        </div>
        <div class="hint small">Logs: <code data-logs-path><?= htmlspecialchars(sv_logs_root($config), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></div>
        <div data-ollama-worker-warning class="action-note error is-hidden"></div>
        <div data-ollama-system-status></div>
    </div>

    <div class="panel" data-ollama-message>
        <div class="action-feedback-title">Status</div>
        <div>Einreihen/Batch/Abbrechen/Löschen werden über <code>ollama.php</code> ausgelöst.</div>
    </div>

    <div class="panel" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
        <button class="btn btn--primary" type="button" data-ollama-quick-enqueue>Alles einreihen</button>
        <label>Batch <input type="number" min="1" max="50" value="5" data-ollama-run-batch></label>
        <label>Max Sekunden <input type="number" min="1" max="120" value="20" data-ollama-run-seconds></label>
        <button class="btn btn--secondary" type="button" data-ollama-run>Batch starten</button>
        <button class="btn btn--ghost" type="button" data-ollama-auto-run aria-pressed="false">Auto-Lauf: Aus</button>
    </div>

    <form class="panel ollama-enqueue" data-ollama-enqueue>
        <label>Mode
            <select name="mode">
                <option value="all">all</option>
                <option value="caption">caption</option>
                <option value="title">title</option>
                <option value="prompt_eval">prompt_eval</option>
                <option value="tags_normalize">tags_normalize</option>
                <option value="quality">quality</option>
                <option value="nsfw_classify">nsfw_classify</option>
                <option value="prompt_recon">prompt_recon</option>
                <option value="embed">embed</option>
            </select>
        </label>
        <label>Limit <input type="number" name="limit" min="1" max="500" value="50"></label>
        <label>Seit <input type="date" name="since"></label>
        <label><input type="checkbox" name="missing_title" value="1"> missing_title</label>
        <label><input type="checkbox" name="missing_caption" value="1"> missing_caption</label>
        <label><input type="checkbox" name="all" value="1"> all</label>
        <button class="btn btn--primary" type="submit">Einreihen</button>
    </form>

    <div class="panel">
        <div class="hint">Job-Tabelle wird nur über explizite Detail-Aktionen geladen. Default bleibt DB-frei.</div>
        <table class="table" data-ollama-jobs>
            <thead>
            <tr>
                <th>ID</th>
                <th class="sortable" data-sort-key="status">Status</th>
                <th class="sortable" data-sort-key="progress">Fortschritt</th>
                <th class="sortable" data-sort-key="heartbeat">Heartbeat</th>
                <th>Fehler</th>
                <th>Model</th>
                <th>Stage</th>
                <th>Aktionen</th>
            </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>
<?php
sv_ui_footer();

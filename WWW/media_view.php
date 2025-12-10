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
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<pre>DB-Fehler: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
    exit;
}

function sv_clamp_int(int $value, int $min, int $max, int $default): int
{
    if ($value < $min || $value > $max) {
        return $default;
    }

    return $value;
}

function sv_normalize_enum(?string $value, array $allowed, string $default): string
{
    if ($value === null) {
        return $default;
    }

    return in_array($value, $allowed, true) ? $value : $default;
}

function sv_normalize_adult_flag(array $input): bool
{
    $adultParam = $input['adult'] ?? null;
    $altParam   = $input['18']    ?? null;

    if (is_string($adultParam)) {
        $candidate = strtolower(trim($adultParam));
        if ($candidate === '1') {
            return true;
        }
        if ($candidate === '0') {
            return false;
        }
    }

    if (is_string($altParam)) {
        $candidate = strtolower(trim($altParam));
        if ($candidate === 'true' || $candidate === '1') {
            return true;
        }
    }

    return false;
}

function sv_limit_string(string $value, int $maxLen): string
{
    if ($maxLen <= 0) {
        return '';
    }

    $trimmed = trim($value);

    if (mb_strlen($trimmed) <= $maxLen) {
        return $trimmed;
    }

    return mb_substr($trimmed, 0, $maxLen);
}

$showAdult = sv_normalize_adult_flag($_GET);

$actionMessage = null;
$actionSuccess = null;
$actionLogs    = [];
$actionLogFile = null;

$id = isset($_GET['id']) ? sv_clamp_int((int)$_GET['id'], 1, 1_000_000_000, 0) : 0;
if ($id <= 0) {
    http_response_code(400);
    echo 'Ungültige ID';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        sv_require_internal_access($config, 'media_action');

        $postId = isset($_POST['media_id']) ? (int)$_POST['media_id'] : 0;
        if ($postId !== $id) {
            throw new RuntimeException('Media-ID stimmt nicht überein.');
        }

        $action = is_string($_POST['action'] ?? null) ? trim($_POST['action']) : '';

        if ($action === 'rebuild_prompt') {
            [$actionLogFile, $logger] = sv_create_operation_log($config, 'prompts_single', $actionLogs, 10);
            $result = sv_run_prompt_rebuild_single($pdo, $config, $id, $logger);
            $processed     = (int)($result['processed'] ?? 0);
            $skipped       = (int)($result['skipped'] ?? 0);
            $errors        = (int)($result['errors'] ?? 0);
            $actionSuccess = $errors === 0;
            if ($actionSuccess && $processed > 0) {
                $actionMessage = 'Prompt-Rebuild für dieses Medium abgeschlossen.';
            } elseif ($actionSuccess && $skipped > 0) {
                $actionMessage = 'Prompt-Rebuild übersprungen (Status/Datei prüfen).';
            } else {
                $actionMessage = 'Prompt-Rebuild fehlgeschlagen.';
            }
        } elseif ($action === 'forge_regen') {
            [$actionLogFile, $logger] = sv_create_operation_log($config, 'forge_regen', $actionLogs, 10);
            try {
                $dispatchNow = sv_forge_endpoint_config($config) !== null;
                $result = sv_queue_forge_regeneration($pdo, $config, $id, $dispatchNow, $logger);
                $actionSuccess = true;
                $statusLabel = htmlspecialchars((string)($result['status'] ?? 'queued'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $actionMessage = 'Forge-Job #' . (int)($result['job_id'] ?? 0) . ' erstellt (Status: ' . $statusLabel . ').';
                if (!empty($result['dispatched']) && ($result['status'] ?? '') === 'running') {
                    $actionMessage .= ' Sofort-Dispatch ausgelöst.';
                } elseif (!$dispatchNow) {
                    $actionMessage .= ' Dispatch übersprungen (keine Forge-Konfiguration).';
                }
            } catch (Throwable $e) {
                $actionSuccess = false;
                $actionMessage = 'Forge-Regeneration nicht möglich: ' . $e->getMessage();
            }
        } elseif ($action === 'logical_delete') {
            $logger       = sv_operation_logger(null, $actionLogs);
            $result       = sv_mark_media_missing($pdo, $id, $logger);
            $actionSuccess = true;
            $actionMessage = $result['changed']
                ? 'Medium als missing markiert.'
                : 'Medium war bereits als missing markiert.';
        } else {
            throw new RuntimeException('Unbekannte Aktion.');
        }
    } catch (Throwable $e) {
        $actionSuccess = false;
        $actionMessage = 'Aktion fehlgeschlagen: ' . $e->getMessage();
    }
}

$mediaStmt = $pdo->prepare('SELECT * FROM media WHERE id = :id');
$mediaStmt->execute([':id' => $id]);
$media = $mediaStmt->fetch(PDO::FETCH_ASSOC);

if (!$media) {
    http_response_code(404);
    echo 'Eintrag nicht gefunden';
    exit;
}

if (!$showAdult && (int)($media['has_nsfw'] ?? 0) === 1) {
    http_response_code(403);
    echo 'FSK18-Eintrag ausgeblendet. adult=1 anhängen, um anzuzeigen.';
    exit;
}

$promptStmt = $pdo->prepare('SELECT * FROM prompts WHERE media_id = :id ORDER BY id DESC LIMIT 1');
$promptStmt->execute([':id' => $id]);
$prompt = $promptStmt->fetch(PDO::FETCH_ASSOC) ?: null;

$metaStmt = $pdo->prepare('SELECT source, meta_key, meta_value FROM media_meta WHERE media_id = :id ORDER BY source, meta_key');
$metaStmt->execute([':id' => $id]);
$metaRows = $metaStmt->fetchAll(PDO::FETCH_ASSOC);

$tagStmt = $pdo->prepare('SELECT t.name, t.type, mt.confidence FROM media_tags mt JOIN tags t ON t.id = mt.tag_id WHERE mt.media_id = :id ORDER BY t.type, t.name');
$tagStmt->execute([':id' => $id]);
$tags = $tagStmt->fetchAll(PDO::FETCH_ASSOC);

$consistencyStatus = sv_media_consistency_status($pdo, $id);
$issueReport = sv_collect_integrity_issues($pdo, [$id]);
$mediaIssues = $issueReport['by_media'][$id] ?? [];

$groupedMeta = [];
foreach ($metaRows as $meta) {
    $src = (string)$meta['source'];
    $groupedMeta[$src][] = [
        'key'   => (string)$meta['meta_key'],
        'value' => $meta['meta_value'],
    ];
}

$allowedTypes  = ['all', 'image', 'video'];
$allowedPrompt = ['all', 'with', 'without'];
$allowedMeta   = ['all', 'with', 'without'];
$allowedStatus = ['all', 'active', 'archived', 'deleted'];
$allowedIncomplete = ['none', 'prompt', 'tags', 'meta', 'any'];

$typeFilter      = sv_normalize_enum($_GET['type'] ?? null, $allowedTypes, 'all');
$hasPromptFilter = sv_normalize_enum($_GET['has_prompt'] ?? null, $allowedPrompt, 'all');
$hasMetaFilter   = sv_normalize_enum($_GET['has_meta'] ?? null, $allowedMeta, 'all');
$pathFilter      = sv_limit_string((string)($_GET['q'] ?? ''), 200);
$statusFilter    = sv_normalize_enum($_GET['status'] ?? null, $allowedStatus, 'all');
$minRating       = sv_clamp_int((int)($_GET['min_rating'] ?? 0), 0, 3, 0);
$incompleteFilter = sv_normalize_enum($_GET['incomplete'] ?? null, $allowedIncomplete, 'none');
$pageParam       = sv_clamp_int((int)($_GET['p'] ?? 1), 1, 10000, 1);

$baseParams = [
    'type'       => $typeFilter,
    'has_prompt' => $hasPromptFilter,
    'has_meta'   => $hasMetaFilter,
    'q'          => $pathFilter,
    'status'     => $statusFilter,
    'min_rating' => $minRating,
    'incomplete' => $incompleteFilter,
    'p'          => $pageParam,
    'adult'      => $showAdult ? '1' : '0',
];

$filteredParams = array_filter($baseParams, static function ($value) {
    return $value !== null && $value !== '';
});
$backLink = 'mediadb.php';
if ($filteredParams !== []) {
    $backLink .= '?' . http_build_query($filteredParams);
}

$navCond = !$showAdult ? ' AND (has_nsfw IS NULL OR has_nsfw = 0)' : '';
$prevStmt = $pdo->prepare('SELECT id FROM media WHERE id < :id' . $navCond . ' ORDER BY id DESC LIMIT 1');
$nextStmt = $pdo->prepare('SELECT id FROM media WHERE id > :id' . $navCond . ' ORDER BY id ASC LIMIT 1');
$prevStmt->execute([':id' => $id]);
$nextStmt->execute([':id' => $id]);
$prevId = $prevStmt->fetchColumn();
$nextId = $nextStmt->fetchColumn();

function sv_meta_value(?string $value, int $maxLen = 300): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $trimmed = trim($value);
    if (mb_strlen($trimmed) <= $maxLen) {
        return $trimmed;
    }
    return mb_substr($trimmed, 0, $maxLen - 1) . '…';
}

function sv_date_field($value): string
{
    if ($value === null || $value === '') {
        return '-';
    }
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$promptExists   = $prompt !== null;
$promptText     = trim((string)($prompt['prompt'] ?? ''));
$needsRebuild   = !$consistencyStatus['prompt_complete'];
$negativePrompt = trim((string)($prompt['negative_prompt'] ?? ''));
$showRebuildButton = $needsRebuild || (!empty($prompt['source_metadata']) && $promptText !== '');

$promptParams = [];
if ($promptExists) {
    if (($prompt['model'] ?? '') !== '') {
        $promptParams['Model'] = (string)$prompt['model'];
    }
    if (($prompt['sampler'] ?? '') !== '') {
        $promptParams['Sampler'] = (string)$prompt['sampler'];
    }
    if ($prompt['steps'] !== null) {
        $promptParams['Steps'] = (string)$prompt['steps'];
    }
    if ($prompt['cfg_scale'] !== null) {
        $promptParams['CFG Scale'] = (string)$prompt['cfg_scale'];
    }
    if (($prompt['seed'] ?? '') !== '') {
        $promptParams['Seed'] = (string)$prompt['seed'];
    }
    if ($prompt['width'] !== null || $prompt['height'] !== null) {
        $promptParams['Size'] = trim((string)($prompt['width'] ?? '-')) . ' × ' . trim((string)($prompt['height'] ?? '-'));
    }
    if (($prompt['scheduler'] ?? '') !== '') {
        $promptParams['Scheduler'] = (string)$prompt['scheduler'];
    }
}

$type = (string)$media['type'];
$canForgeRegen = $type === 'image' && $consistencyStatus['prompt_complete'];
$thumbUrl = 'thumb.php?' . http_build_query(['id' => $id, 'adult' => $showAdult ? '1' : '0']);
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Media #<?= (int)$id ?></title>
    <style>
        body {
            font-family: system-ui, -apple-system, sans-serif;
            font-size: 14px;
            margin: 12px;
            line-height: 1.5;
        }
        h1 {
            margin-bottom: 6px;
        }
        .nav a {
            margin-right: 8px;
        }
        .media-block {
            margin-bottom: 12px;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: #fafafa;
        }
        .prompt-block {
            margin-bottom: 12px;
        }
        textarea {
            width: 100%;
            min-height: 80px;
            font-family: ui-monospace, SFMono-Regular, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 13px;
            padding: 6px;
        }
        table.meta {
            border-collapse: collapse;
            width: 100%;
        }
        table.meta th,
        table.meta td {
            border: 1px solid #ddd;
            padding: 6px;
            text-align: left;
        }
        table.meta th {
            background: #f0f0f0;
            width: 200px;
        }
        .meta-group {
            margin-bottom: 12px;
        }
        .meta-group h3 {
            margin-bottom: 4px;
            font-size: 15px;
        }
        .meta-entry {
            padding: 4px 0;
            border-bottom: 1px solid #eee;
        }
        .meta-entry:last-child {
            border-bottom: none;
        }
        .placeholder {
            padding: 20px;
            text-align: center;
            background: #f5f5f5;
            border: 1px dashed #bbb;
        }
        .tags {
            margin: 6px 0 10px;
        }
        .tag {
            display: inline-block;
            padding: 4px 6px;
            margin: 2px;
            border-radius: 4px;
            background: #e0e0e0;
            font-size: 12px;
        }
        .tag-type-content { background: #bbdefb; }
        .tag-type-style { background: #ffe0b2; }
        .tag-type-character { background: #f8bbd0; }
        .tag-type-nsfw { background: #ef9a9a; }
        .tag-type-technical { background: #c5e1a5; }
        .tag-type-other { background: #d7ccc8; }
        .consistency {
            margin: 12px 0;
            padding: 10px;
            background: #eef6ff;
            border: 1px solid #cfdffa;
            border-radius: 4px;
        }
        .consistency h2 {
            margin-top: 0;
            margin-bottom: 6px;
        }
        .consistency-badges {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .consistency-badge {
            padding: 6px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        .consistency-badge.ok {
            background: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #c8e6c9;
        }
        .consistency-badge.warn {
            background: #fff8e1;
            color: #f57f17;
            border: 1px solid #ffecb3;
        }
        .consistency-badge.error {
            background: #ffebee;
            color: #c62828;
            border: 1px solid #ffcdd2;
        }
        .actions {
            margin-top: 12px;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: #f9f9f9;
        }
        .actions button {
            margin-right: 8px;
            padding: 6px 10px;
        }
        .action-note {
            font-size: 12px;
            color: #555;
            margin-top: 6px;
        }
        .issues-block ul {
            margin: 0 0 8px 16px;
            padding: 0;
        }
        .issues-block li {
            margin: 4px 0;
        }
    </style>
</head>
<body>

<div class="nav">
    <a href="<?= htmlspecialchars($backLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">« Zurück zur Übersicht</a>
    <?php if ($prevId !== false): ?>
        <a href="media_view.php?<?= http_build_query(array_merge($filteredParams, ['id' => (int)$prevId])) ?>">« Vorheriges</a>
    <?php endif; ?>
    <?php if ($nextId !== false): ?>
        <a href="media_view.php?<?= http_build_query(array_merge($filteredParams, ['id' => (int)$nextId])) ?>">Nächstes »</a>
    <?php endif; ?>
</div>

<h1>Media #<?= (int)$id ?> (<?= htmlspecialchars($type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)</h1>

<?php
$promptBadgeClass = $consistencyStatus['prompt_complete'] ? 'ok' : ($consistencyStatus['prompt_present'] ? 'warn' : 'error');
$promptLabel = $consistencyStatus['prompt_complete']
    ? 'Prompt vollständig'
    : ($consistencyStatus['prompt_present'] ? 'Prompt unvollständig' : 'Prompt fehlt');

$tagBadgeClass  = $consistencyStatus['has_tags'] ? 'ok' : 'error';
$tagLabel       = $consistencyStatus['has_tags'] ? 'Tags vorhanden' : 'Keine Tags';

$metaBadgeClass = $consistencyStatus['has_meta'] ? 'ok' : 'warn';
$metaLabel      = $consistencyStatus['has_meta'] ? 'Metadaten vorhanden' : 'Metadaten fehlen';
?>

<div class="consistency">
    <h2>Konsistenz</h2>
    <div class="consistency-badges">
        <span class="consistency-badge <?= htmlspecialchars($promptBadgeClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
              title="Prompt-Vollständigkeit">
            <?= htmlspecialchars($promptLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </span>
        <span class="consistency-badge <?= htmlspecialchars($tagBadgeClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
              title="Tags für dieses Medium">
            <?= htmlspecialchars($tagLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </span>
        <span class="consistency-badge <?= htmlspecialchars($metaBadgeClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
              title="Metadaten-Einträge">
            <?= htmlspecialchars($metaLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </span>
    </div>
</div>

<?php if ($mediaIssues !== []): ?>
<div class="consistency issues-block">
    <h3>Integritätsprobleme</h3>
    <ul>
        <?php foreach (array_slice($mediaIssues, 0, 3) as $issue): ?>
            <li>
                <strong><?= htmlspecialchars(ucfirst((string)$issue['type']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>:</strong>
                <?= htmlspecialchars((string)$issue['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php if (count($mediaIssues) > 3): ?>
        <details>
            <summary>Weitere Probleme einblenden</summary>
            <ul>
                <?php foreach ($mediaIssues as $issue): ?>
                    <li>
                        <strong><?= htmlspecialchars(ucfirst((string)$issue['type']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>:</strong>
                        <?= htmlspecialchars((string)$issue['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="media-block">
    <?php if ($type === 'image'): ?>
        <div>
            <img src="<?= htmlspecialchars($thumbUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="Vorschau" style="max-width: 100%; height: auto;">
        </div>
    <?php else: ?>
        <div class="placeholder">
            <strong>Video (kein Player)</strong><br>
            <div><?= htmlspecialchars((string)($media['path'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <div>Auflösung: <?= htmlspecialchars((string)($media['width'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> × <?= htmlspecialchars((string)($media['height'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <div>Dauer: <?= htmlspecialchars((string)($media['duration'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> Sekunden | FPS: <?= htmlspecialchars((string)($media['fps'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        </div>
    <?php endif; ?>
</div>

<?php if ($promptExists): ?>
<div class="prompt-block">
    <h2>Prompt</h2>
    <h3>Prompt</h3>
    <textarea readonly><?= htmlspecialchars($promptText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
    <?php if ($negativePrompt !== ''): ?>
        <h3>Negativer Prompt</h3>
        <textarea readonly><?= htmlspecialchars($negativePrompt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
    <?php endif; ?>
    <?php if ($promptParams !== []): ?>
        <h3>Parameter</h3>
        <table class="meta">
            <tbody>
            <?php foreach ($promptParams as $label => $value): ?>
                <tr><th><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th><td><?= htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php if (!empty($prompt['source_metadata'])): ?>
        <h3>Roh-Prompt/Metadaten</h3>
        <textarea readonly><?= htmlspecialchars((string)$prompt['source_metadata'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="prompt-block">
    <h2>Prompt</h2>
    <p><strong>Kein Prompt gefunden – Rebuild empfohlen.</strong></p>
</div>
<?php endif; ?>

<div class="media-block">
    <h2>Tags</h2>
    <?php if ($tags === []): ?>
        <p>Keine Tags gespeichert.</p>
    <?php else: ?>
        <div class="tags">
            <?php foreach ($tags as $tag):
                $tagType = preg_replace('~[^a-z0-9_-]+~i', '', (string)($tag['type'] ?? 'other')) ?: 'other';
                $conf = isset($tag['confidence']) ? number_format((float)$tag['confidence'], 2) : null;
                ?>
                <span class="tag tag-type-<?= htmlspecialchars($tagType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <?= htmlspecialchars((string)$tag['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= $conf !== null ? ' (' . htmlspecialchars($conf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ')' : '' ?>
                </span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="actions">
    <h2>Aktionen</h2>
    <?php if ($actionMessage !== null): ?>
        <div style="padding:8px; border:1px solid <?= $actionSuccess ? '#4caf50' : '#e53935' ?>; background: <?= $actionSuccess ? '#e8f5e9' : '#ffebee' ?>; margin-bottom:10px;">
            <strong><?= $actionSuccess ? 'OK' : 'Fehler' ?>:</strong>
            <?= htmlspecialchars($actionMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            <?php if ($actionLogFile): ?>
                <div>Logdatei: <?= htmlspecialchars((string)$actionLogFile, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($actionLogs !== []): ?>
                <details style="margin-top:6px;">
                    <summary>Details</summary>
                    <pre style="white-space:pre-wrap; background:#f6f8fa; padding:6px;"><?= htmlspecialchars(implode("\n", $actionLogs), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
                </details>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($showRebuildButton): ?>
        <form method="post" style="display:inline-block; margin-right:8px;">
            <input type="hidden" name="media_id" value="<?= (int)$id ?>">
            <input type="hidden" name="action" value="rebuild_prompt">
            <button type="submit">Prompt neu aufbauen</button>
        </form>
    <?php endif; ?>

    <?php if ($canForgeRegen): ?>
        <form method="post" style="display:inline-block; margin-right:8px;">
            <input type="hidden" name="media_id" value="<?= (int)$id ?>">
            <input type="hidden" name="action" value="forge_regen">
            <button type="submit">Regen über Forge</button>
        </form>
    <?php endif; ?>

    <form method="post" style="display:inline-block; margin-right:8px;" onsubmit="return confirm('Medium als missing markieren? Dateien bleiben erhalten.');">
        <input type="hidden" name="media_id" value="<?= (int)$id ?>">
        <input type="hidden" name="action" value="logical_delete">
        <button type="submit">Medium logisch löschen</button>
    </form>

    <div class="action-note">Aktionen erfordern Internal-Key und IP-Whitelist. Löschfunktion setzt nur den Status auf missing.</div>
</div>

<div class="media-block">
    <h2>Kern-Metadaten</h2>
    <table class="meta">
        <tr><th>ID</th><td><?= (int)$media['id'] ?></td></tr>
        <tr><th>Typ</th><td><?= htmlspecialchars((string)$media['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td></tr>
        <tr><th>Pfad</th><td><?= htmlspecialchars((string)$media['path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td></tr>
        <tr><th>Quelle</th><td><?= htmlspecialchars((string)($media['source'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td></tr>
        <tr><th>Auflösung</th><td><?= htmlspecialchars((string)($media['width'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> × <?= htmlspecialchars((string)($media['height'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td></tr>
        <tr><th>Dauer</th><td><?= htmlspecialchars((string)($media['duration'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td></tr>
        <tr><th>FPS</th><td><?= htmlspecialchars((string)($media['fps'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td></tr>
        <tr><th>Dateigröße</th><td><?= htmlspecialchars((string)($media['filesize'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td></tr>
        <tr><th>Hash</th><td><?= htmlspecialchars((string)($media['hash'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td></tr>
        <tr><th>Rating</th><td><?= htmlspecialchars((string)($media['rating'] ?? '0'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td></tr>
        <tr><th>FSK18</th><td><?= (int)($media['has_nsfw'] ?? 0) === 1 ? 'ja' : 'nein' ?></td></tr>
        <tr><th>Status</th><td><?= htmlspecialchars((string)($media['status'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td></tr>
        <tr><th>Erstellt</th><td><?= sv_date_field($media['created_at'] ?? null) ?></td></tr>
        <tr><th>Importiert</th><td><?= sv_date_field($media['imported_at'] ?? null) ?></td></tr>
    </table>
</div>

<div class="media-block">
    <h2>Erweiterte Metadaten</h2>
    <?php if ($groupedMeta === []): ?>
        <p>Keine Einträge vorhanden.</p>
    <?php else: ?>
        <?php foreach ($groupedMeta as $source => $entries): ?>
            <div class="meta-group">
                <h3>[<?= htmlspecialchars((string)$source, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>]</h3>
                <?php foreach ($entries as $entry): ?>
                    <div class="meta-entry">
                        <strong><?= htmlspecialchars((string)$entry['key'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong> =
                        <span><?= htmlspecialchars(sv_meta_value($entry['value']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</body>
</html>

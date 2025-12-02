<?php
declare(strict_types=1);

$config = require __DIR__ . '/../CONFIG/config.php';

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

$id = isset($_GET['id']) ? sv_clamp_int((int)$_GET['id'], 1, 1_000_000_000, 0) : 0;
if ($id <= 0) {
    http_response_code(400);
    echo 'Ungültige ID';
    exit;
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

$typeFilter      = sv_normalize_enum($_GET['type'] ?? null, $allowedTypes, 'all');
$hasPromptFilter = sv_normalize_enum($_GET['has_prompt'] ?? null, $allowedPrompt, 'all');
$hasMetaFilter   = sv_normalize_enum($_GET['has_meta'] ?? null, $allowedMeta, 'all');
$pathFilter      = sv_limit_string((string)($_GET['q'] ?? ''), 200);
$statusFilter    = sv_normalize_enum($_GET['status'] ?? null, $allowedStatus, 'all');
$minRating       = sv_clamp_int((int)($_GET['min_rating'] ?? 0), 0, 3, 0);
$pageParam       = sv_clamp_int((int)($_GET['p'] ?? 1), 1, 10000, 1);

$baseParams = [
    'type'       => $typeFilter,
    'has_prompt' => $hasPromptFilter,
    'has_meta'   => $hasMetaFilter,
    'q'          => $pathFilter,
    'status'     => $statusFilter,
    'min_rating' => $minRating,
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

$type = (string)$media['type'];
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

<?php if ($prompt): ?>
<div class="prompt-block">
    <h2>Prompt</h2>
    <textarea readonly><?= htmlspecialchars((string)($prompt['prompt'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
    <?php if (!empty($prompt['negative_prompt'])): ?>
        <h3>Negativer Prompt</h3>
        <textarea readonly><?= htmlspecialchars((string)$prompt['negative_prompt'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
    <?php endif; ?>
    <?php if (!empty($prompt['source_metadata'])): ?>
        <h3>Roh-Prompt/Metadaten</h3>
        <textarea readonly><?= htmlspecialchars((string)$prompt['source_metadata'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="prompt-block">
    <h2>Prompt</h2>
    <p>Kein Prompt gespeichert.</p>
</div>
<?php endif; ?>

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

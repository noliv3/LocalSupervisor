<?php
declare(strict_types=1);

$config = require __DIR__ . '/../CONFIG/config.php';
require_once __DIR__ . '/../SCRIPTS/paths.php';
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

function sv_media_stream_url(int $id, bool $adult, bool $download = false): string
{
    $params = [
        'id'    => $id,
        'adult' => $adult ? '1' : '0',
    ];

    if ($download) {
        $params['dl'] = '1';
    }

    return 'media_stream.php?' . http_build_query($params);
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

$page    = sv_clamp_int((int)($_GET['p'] ?? 1), 1, 10000, 1);
$perPage = 100;
$offset  = ($page - 1) * $perPage;
$issueFilter = isset($_GET['issues']) && ((string)$_GET['issues'] === '1');
$dupeFilter  = isset($_GET['dupes']) && ((string)$_GET['dupes'] === '1');
$dupeHashFilter = sv_limit_string((string)($_GET['dupe_hash'] ?? ''), 64);
if ($dupeHashFilter !== '') {
    $dupeFilter = true;
}

$typeFilter       = $_GET['type'] ?? 'all';
$hasPromptFilter  = $_GET['has_prompt'] ?? 'all';
$hasMetaFilter    = $_GET['has_meta'] ?? 'all';
$pathFilter       = sv_limit_string((string)($_GET['q'] ?? ''), 200);
$statusFilter     = $_GET['status'] ?? 'all';
$minRating        = (int)($_GET['min_rating'] ?? 0);
$incompleteFilter = $_GET['incomplete'] ?? 'none';
$promptQualityFilter = $_GET['prompt_quality'] ?? 'all';

$allowedTypes     = ['all', 'image', 'video'];
$allowedPrompt    = ['all', 'with', 'without'];
$allowedMeta      = ['all', 'with', 'without'];
$allowedStatus    = ['all', 'active', 'archived', 'deleted'];
$allowedIncomplete= ['none', 'prompt', 'tags', 'meta', 'any'];
$allowedQuality   = ['all', 'A', 'B', 'C', 'critical'];
$typeFilter       = sv_normalize_enum($typeFilter, $allowedTypes, 'all');
$hasPromptFilter  = sv_normalize_enum($hasPromptFilter, $allowedPrompt, 'all');
$hasMetaFilter    = sv_normalize_enum($hasMetaFilter, $allowedMeta, 'all');
$statusFilter     = sv_normalize_enum($statusFilter, $allowedStatus, 'all');
$minRating        = sv_clamp_int($minRating, 0, 3, 0);
$incompleteFilter = sv_normalize_enum($incompleteFilter, $allowedIncomplete, 'none');
$promptQualityFilter = sv_normalize_enum($promptQualityFilter, $allowedQuality, 'all');
$promptQualityFilter = $promptQualityFilter === 'critical' ? 'C' : $promptQualityFilter;

$where  = [];
$params = [];

$promptCompleteClause = sv_prompt_core_complete_condition('p');
$latestPromptJoin = 'LEFT JOIN prompts p ON p.id = (SELECT p2.id FROM prompts p2 WHERE p2.media_id = m.id ORDER BY p2.id DESC LIMIT 1)';

if (!$showAdult) {
    $where[] = '(m.has_nsfw IS NULL OR m.has_nsfw = 0)';
}

if ($typeFilter !== 'all') {
    $where[]           = 'm.type = :type';
    $params[':type'] = $typeFilter;
}

if ($pathFilter !== '') {
    $where[]           = 'm.path LIKE :path';
    $params[':path'] = '%' . $pathFilter . '%';
}

if ($statusFilter !== 'all') {
    $where[]              = 'm.status = :status';
    $params[':status'] = $statusFilter;
}

if ($minRating > 0) {
    $where[]               = 'm.rating >= :minRating';
    $params[':minRating'] = $minRating;
}

if ($dupeFilter) {
    $where[] = 'm.hash IN (SELECT hash FROM media WHERE hash IS NOT NULL GROUP BY hash HAVING COUNT(*) > 1)';
}
if ($dupeHashFilter !== '') {
    $where[]              = 'm.hash = :dupe_hash';
    $params[':dupe_hash'] = $dupeHashFilter;
}

$promptCompleteExists = 'EXISTS (SELECT 1 FROM prompts p WHERE p.media_id = m.id AND ' . $promptCompleteClause . ')';

if ($hasPromptFilter === 'with') {
    $where[] = 'EXISTS (SELECT 1 FROM prompts p WHERE p.media_id = m.id)';
} elseif ($hasPromptFilter === 'without') {
    $where[] = 'NOT EXISTS (SELECT 1 FROM prompts p WHERE p.media_id = m.id)';
}

if ($hasMetaFilter === 'with') {
    $where[] = 'EXISTS (SELECT 1 FROM media_meta mm WHERE mm.media_id = m.id)';
} elseif ($hasMetaFilter === 'without') {
    $where[] = 'NOT EXISTS (SELECT 1 FROM media_meta mm WHERE mm.media_id = m.id)';
}

if ($incompleteFilter === 'prompt') {
    $where[] = 'NOT ' . $promptCompleteExists;
} elseif ($incompleteFilter === 'tags') {
    $where[] = 'NOT EXISTS (SELECT 1 FROM media_tags mt WHERE mt.media_id = m.id)';
} elseif ($incompleteFilter === 'meta') {
    $where[] = 'NOT EXISTS (SELECT 1 FROM media_meta mm WHERE mm.media_id = m.id)';
} elseif ($incompleteFilter === 'any') {
    $where[] = '((NOT ' . $promptCompleteExists . ')'
        . ' OR NOT EXISTS (SELECT 1 FROM media_tags mt WHERE mt.media_id = m.id)'
        . ' OR NOT EXISTS (SELECT 1 FROM media_meta mm WHERE mm.media_id = m.id))';
}

if ($promptQualityFilter === 'A') {
    $where[] = '(p.prompt IS NOT NULL AND LENGTH(TRIM(p.prompt)) >= 80)';
} elseif ($promptQualityFilter === 'B') {
    $where[] = '(p.prompt IS NOT NULL AND LENGTH(TRIM(p.prompt)) BETWEEN 40 AND 90)';
} elseif ($promptQualityFilter === 'C') {
    $where[] = '(p.prompt IS NULL OR LENGTH(TRIM(p.prompt)) < 50)';
}

$whereSql = $where === [] ? '1=1' : implode(' AND ', $where);
$issueReport = [];

if ($issueFilter) {
    $idSql = 'SELECT m.id FROM media m ' . $latestPromptJoin . ' WHERE ' . $whereSql . ' ORDER BY m.id DESC';
    $idStmt = $pdo->prepare($idSql);
    $idStmt->execute($params);
    $candidateIds = array_map('intval', $idStmt->fetchAll(PDO::FETCH_COLUMN));

    $issueReport = sv_collect_integrity_issues($pdo, $candidateIds);
    $issueMediaMap = $issueReport['by_media'] ?? [];

    $issueIds = [];
    foreach ($candidateIds as $cid) {
        if (isset($issueMediaMap[$cid])) {
            $issueIds[] = $cid;
        }
    }

    $total = count($issueIds);
    $pages = max(1, (int)ceil($total / $perPage));
    $pageIssueIds = array_slice($issueIds, $offset, $perPage);

    if ($pageIssueIds === []) {
        $rows = [];
    } else {
        $placeholders = implode(',', array_fill(0, count($pageIssueIds), '?'));
        $listSql = 'SELECT m.id, m.path, m.type, m.has_nsfw, m.rating, m.status, m.hash,
           p.prompt AS prompt_text, p.width AS prompt_width, p.height AS prompt_height,
           EXISTS (SELECT 1 FROM prompts p3 WHERE p3.media_id = m.id) AS has_prompt,
            EXISTS (SELECT 1 FROM prompts p4 WHERE p4.media_id = m.id AND ' . $promptCompleteClause . ') AS prompt_complete,
           EXISTS (SELECT 1 FROM media_meta mm WHERE mm.media_id = m.id) AS has_meta,
           EXISTS (SELECT 1 FROM media_tags mt WHERE mt.media_id = m.id) AS has_tags
FROM media m
LEFT JOIN prompts p ON p.id = (SELECT p2.id FROM prompts p2 WHERE p2.media_id = m.id ORDER BY p2.id DESC LIMIT 1)
WHERE m.id IN (' . $placeholders . ')
ORDER BY m.id DESC';
        $listStmt = $pdo->prepare($listSql);
        $listStmt->execute($pageIssueIds);
        $fetched = $listStmt->fetchAll(PDO::FETCH_ASSOC);
        $rows = [];
        $map = [];
        foreach ($fetched as $row) {
            $map[(int)$row['id']] = $row;
        }
        foreach ($pageIssueIds as $pid) {
            if (isset($map[$pid])) {
                $rows[] = $map[$pid];
            }
        }
    }
} elseif ($promptQualityFilter !== 'all') {
    $qualitySql = 'SELECT m.id, m.path, m.type, m.has_nsfw, m.rating, m.status, m.hash,
           p.prompt AS prompt_text, p.width AS prompt_width, p.height AS prompt_height,
           EXISTS (SELECT 1 FROM prompts p3 WHERE p3.media_id = m.id) AS has_prompt,
            EXISTS (SELECT 1 FROM prompts p4 WHERE p4.media_id = m.id AND ' . $promptCompleteClause . ') AS prompt_complete,
           EXISTS (SELECT 1 FROM media_meta mm WHERE mm.media_id = m.id) AS has_meta,
           EXISTS (SELECT 1 FROM media_tags mt WHERE mt.media_id = m.id) AS has_tags
FROM media m
' . $latestPromptJoin . '
WHERE ' . $whereSql . '
ORDER BY m.id DESC';

    $qualityStmt = $pdo->prepare($qualitySql);
    foreach ($params as $k => $v) {
        $qualityStmt->bindValue($k, $v);
    }
    $qualityStmt->execute();
    $candidateRows = $qualityStmt->fetchAll(PDO::FETCH_ASSOC);

    $filteredRows = [];
    foreach ($candidateRows as $row) {
        $quality = sv_prompt_quality_from_text(
            $row['prompt_text'] ?? null,
            isset($row['prompt_width']) ? (int)$row['prompt_width'] : null,
            isset($row['prompt_height']) ? (int)$row['prompt_height'] : null
        );
        if ($quality['quality_class'] === $promptQualityFilter) {
            $row['quality'] = $quality;
            $filteredRows[] = $row;
        }
    }

    $total = count($filteredRows);
    $pages = max(1, (int)ceil($total / $perPage));
    $rows = array_slice($filteredRows, $offset, $perPage);
    $issueReport = sv_collect_integrity_issues($pdo, array_map(static fn ($r) => (int)$r['id'], $rows));
} else {
    $countSql = 'SELECT COUNT(*) FROM media m ' . $latestPromptJoin . ' WHERE ' . $whereSql; 
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $listSql = 'SELECT m.id, m.path, m.type, m.has_nsfw, m.rating, m.status, m.hash,
           p.prompt AS prompt_text, p.width AS prompt_width, p.height AS prompt_height,
           EXISTS (SELECT 1 FROM prompts p3 WHERE p3.media_id = m.id) AS has_prompt,
            EXISTS (SELECT 1 FROM prompts p4 WHERE p4.media_id = m.id AND ' . $promptCompleteClause . ') AS prompt_complete,
           EXISTS (SELECT 1 FROM media_meta mm WHERE mm.media_id = m.id) AS has_meta,
           EXISTS (SELECT 1 FROM media_tags mt WHERE mt.media_id = m.id) AS has_tags
FROM media m
' . $latestPromptJoin . '
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
    $issueReport = sv_collect_integrity_issues($pdo, array_map(static fn ($r) => (int)$r['id'], $rows));
}

$queryParams = [
    'type'       => $typeFilter,
    'has_prompt' => $hasPromptFilter,
    'has_meta'   => $hasMetaFilter,
    'q'          => $pathFilter,
    'status'     => $statusFilter,
    'min_rating' => $minRating,
    'incomplete' => $incompleteFilter,
    'issues'     => $issueFilter ? '1' : '0',
    'prompt_quality' => $promptQualityFilter,
    'adult'      => $showAdult ? '1' : '0',
];

$issuesByMedia = $issueReport['by_media'] ?? [];

$dupeCounts = [];
$hashes = [];
foreach ($rows as $row) {
    if (!empty($row['hash'])) {
        $hashes[] = (string)$row['hash'];
    }
}
$hashes = array_values(array_unique($hashes));
if ($hashes) {
    $ph = implode(',', array_fill(0, count($hashes), '?'));
    $dupeStmt = $pdo->prepare('SELECT hash, COUNT(*) AS cnt FROM media WHERE hash IN (' . $ph . ') GROUP BY hash HAVING COUNT(*) > 1');
    $dupeStmt->execute($hashes);
    foreach ($dupeStmt->fetchAll(PDO::FETCH_ASSOC) as $dupeRow) {
        $dupeCounts[(string)$dupeRow['hash']] = (int)$dupeRow['cnt'];
    }
}

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
            line-height: 1.4;
        }
        h1 {
            font-size: 20px;
            margin-bottom: 10px;
        }
        form.filter {
            margin-bottom: 10px;
            padding: 8px;
            background: #f7f7f7;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .meta {
            font-size: 12px;
            color: #555;
            margin-bottom: 6px;
        }
        .gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 10px;
        }
        .item {
            border: 1px solid #ddd;
            padding: 6px;
            border-radius: 4px;
            background: #fafafa;
            min-height: 260px;
            box-sizing: border-box;
        }
        .thumb-wrap {
            width: 100%;
            aspect-ratio: 4 / 3;
            background: #ececec;
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
            font-size: 12px;
            word-break: break-all;
            margin-top: 6px;
        }
        .badges {
            font-size: 11px;
            margin-top: 4px;
        }
        .badge {
            display: inline-block;
            padding: 2px 5px;
            border-radius: 3px;
            background: #eee;
            margin-right: 4px;
        }
        .badge.nsfw {
            background: #c62828;
            color: #fff;
        }
        .badge.video {
            background: #1976d2;
            color: #fff;
        }
        .badge.image {
            background: #2e7d32;
            color: #fff;
        }
        .badge.prompt,
        .badge.meta {
            background: #e0e0e0;
            color: #222;
        }
        .badge.prompt.present {
            background: #2e7d32;
            color: #fff;
        }
        .badge.prompt.missing {
            background: #bdbdbd;
            color: #111;
        }
        .badge.prompt.partial {
            background: #fbc02d;
            color: #222;
        }
        .badge.meta.missing {
            background: #ef9a9a;
            color: #222;
        }
        .badge.tag {
            background: #5c6bc0;
            color: #fff;
        }
        .badge.quality {
            background: #e0f2f1;
            color: #00695c;
        }
        .badge.quality-A { background: #c8e6c9; color: #1b5e20; }
        .badge.quality-B { background: #fff3e0; color: #e65100; }
        .badge.quality-C { background: #ffebee; color: #c62828; }
        .badge.issue {
            background: #f57f17;
            color: #fff;
        }
        .badge.dupe {
            background: #6a1b9a;
            color: #fff;
        }
        .badge.dupe a { color: #fff; text-decoration: none; }
        .pager {
            margin: 10px 0;
            font-size: 13px;
        }
        .pager a {
            margin-right: 5px;
        }
        .status {
            color: #555;
            font-size: 11px;
        }
    </style>
</head>
<body>

<h1>SuperVisOr Medien</h1>

<div class="meta">
    Gesamt: <?= (int)$total ?> Einträge
    <?php if (!$showAdult): ?>
        | FSK18 ausgeblendet
    <?php else: ?>
        | FSK18 sichtbar
    <?php endif; ?>
</div>

<form method="get" class="filter">
    <label>
        Typ:
        <select name="type">
            <option value="all"   <?= $typeFilter === 'all' ? 'selected' : '' ?>>alle</option>
            <option value="image" <?= $typeFilter === 'image' ? 'selected' : '' ?>>Bilder</option>
            <option value="video" <?= $typeFilter === 'video' ? 'selected' : '' ?>>Videos</option>
        </select>
    </label>
    <label>
        Prompt:
        <select name="has_prompt">
            <option value="all"     <?= $hasPromptFilter === 'all' ? 'selected' : '' ?>>alle</option>
            <option value="with"    <?= $hasPromptFilter === 'with' ? 'selected' : '' ?>>nur mit Prompt</option>
            <option value="without" <?= $hasPromptFilter === 'without' ? 'selected' : '' ?>>ohne Prompt</option>
        </select>
    </label>
    <label>
        Prompt-Qualität:
        <select name="prompt_quality">
            <option value="all" <?= $promptQualityFilter === 'all' ? 'selected' : '' ?>>alle</option>
            <option value="A"   <?= $promptQualityFilter === 'A' ? 'selected' : '' ?>>A (gut)</option>
            <option value="B"   <?= $promptQualityFilter === 'B' ? 'selected' : '' ?>>B (mittel)</option>
            <option value="C"   <?= $promptQualityFilter === 'C' ? 'selected' : '' ?>>C (kritisch)</option>
        </select>
    </label>
    <label>
        Metadaten:
        <select name="has_meta">
            <option value="all"     <?= $hasMetaFilter === 'all' ? 'selected' : '' ?>>alle</option>
            <option value="with"    <?= $hasMetaFilter === 'with' ? 'selected' : '' ?>>nur mit Meta</option>
            <option value="without" <?= $hasMetaFilter === 'without' ? 'selected' : '' ?>>ohne Meta</option>
        </select>
    </label>
    <label>
        Konsistenz:
        <select name="incomplete">
            <option value="none"    <?= $incompleteFilter === 'none' ? 'selected' : '' ?>>alle</option>
            <option value="prompt"  <?= $incompleteFilter === 'prompt' ? 'selected' : '' ?>>Prompt unvollständig</option>
            <option value="tags"    <?= $incompleteFilter === 'tags' ? 'selected' : '' ?>>ohne Tags</option>
            <option value="meta"    <?= $incompleteFilter === 'meta' ? 'selected' : '' ?>>ohne Metadaten</option>
            <option value="any"     <?= $incompleteFilter === 'any' ? 'selected' : '' ?>>irgendetwas fehlt</option>
        </select>
    </label>
    <label>
        <input type="checkbox" name="issues" value="1" <?= $issueFilter ? 'checked' : '' ?>>
        nur mit Problemen
    </label>
    <label>
        Status:
        <select name="status">
            <option value="all"      <?= $statusFilter === 'all' ? 'selected' : '' ?>>alle</option>
            <option value="active"   <?= $statusFilter === 'active' ? 'selected' : '' ?>>active</option>
            <option value="archived" <?= $statusFilter === 'archived' ? 'selected' : '' ?>>archived</option>
            <option value="deleted"  <?= $statusFilter === 'deleted' ? 'selected' : '' ?>>deleted</option>
        </select>
    </label>
    <label>
        Min. Rating:
        <select name="min_rating">
            <option value="0" <?= $minRating === 0 ? 'selected' : '' ?>>keins</option>
            <option value="1" <?= $minRating === 1 ? 'selected' : '' ?>>≥1</option>
            <option value="2" <?= $minRating === 2 ? 'selected' : '' ?>>≥2</option>
            <option value="3" <?= $minRating === 3 ? 'selected' : '' ?>>≥3</option>
        </select>
    </label>
    <label>
        Pfad enthält:
        <input type="text" name="q" size="40"
               value="<?= htmlspecialchars($pathFilter, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    </label>
    <label>
        <input type="checkbox" name="dupes" value="1" <?= $dupeFilter ? 'checked' : '' ?>> nur Duplikate
    </label>
    <label>
        Hash:
        <input type="text" name="dupe_hash" size="20" value="<?= htmlspecialchars($dupeHashFilter, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    </label>
    <input type="hidden" name="adult" value="<?= $showAdult ? '1' : '0' ?>">
    <button type="submit">Filtern</button>
</form>

<div class="meta">
    FSK18-Link: <code>?adult=1</code> oder <code>?18=True</code>
</div>

<div class="pager">
    Seite <?= (int)$page ?> / <?= (int)$pages ?> |
    <?php if ($page > 1): ?>
        <a href="?<?= http_build_query(array_merge($queryParams, ['p' => $page - 1])) ?>">« zurück</a>
    <?php endif; ?>
    <?php if ($page < $pages): ?>
        <a href="?<?= http_build_query(array_merge($queryParams, ['p' => $page + 1])) ?>">weiter »</a>
    <?php endif; ?>
</div>

<div class="gallery">
    <?php foreach ($rows as $row):
        $id      = (int)$row['id'];
        $path    = (string)$row['path'];
        $type    = (string)$row['type'];
        $hasNsfw = (int)($row['has_nsfw'] ?? 0) === 1;
        $rating  = (int)($row['rating'] ?? 0);
        $status  = (string)($row['status'] ?? '');
        $hash    = (string)($row['hash'] ?? '');
        $dupeCount = ($hash !== '' && isset($dupeCounts[$hash])) ? (int)$dupeCounts[$hash] : 0;
        $hasPrompt = (int)($row['has_prompt'] ?? 0) === 1;
        $promptComplete = (int)($row['prompt_complete'] ?? 0) === 1;
        $hasMeta   = (int)($row['has_meta'] ?? 0) === 1;
        $hasTags   = (int)($row['has_tags'] ?? 0) === 1;
        $hasIssues = isset($issuesByMedia[$id]);

        $qualityData = $row['quality'] ?? sv_prompt_quality_from_text(
            $row['prompt_text'] ?? null,
            isset($row['prompt_width']) ? (int)$row['prompt_width'] : null,
            isset($row['prompt_height']) ? (int)$row['prompt_height'] : null
        );
        $qualityClass = $qualityData['quality_class'];
        $qualityScore = (int)$qualityData['score'];
        $qualityIssues = array_slice($qualityData['issues'] ?? [], 0, 2);

        $thumbUrl = 'thumb.php?' . http_build_query(['id' => $id, 'adult' => $showAdult ? '1' : '0']);
        $detailParams = array_merge($queryParams, ['id' => $id, 'p' => $page]);
        $streamUrl = sv_media_stream_url($id, $showAdult, false);
        ?>
        <div class="item">
            <div class="thumb-wrap">
                <?php if ($type === 'image'): ?>
                    <a href="media_view.php?<?= http_build_query($detailParams) ?>">
                        <img
                            src="<?= htmlspecialchars($thumbUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                            loading="lazy"
                            alt="ID <?= $id ?>">
                    </a>
                <?php else: ?>
                    <a href="media_view.php?<?= http_build_query($detailParams) ?>">Video</a>
                <?php endif; ?>
            </div>
            <div class="title">
                ID <?= $id ?> – <?= htmlspecialchars(basename($path), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </div>
            <div class="badges">
                <span class="badge <?= $type === 'video' ? 'video' : 'image' ?>"><?= $type === 'video' ? 'V' : 'I' ?></span>
                <?php if ($dupeCount > 1): ?>
                    <span class="badge dupe"><a href="?<?= http_build_query(array_merge($queryParams, ['dupes' => '1', 'dupe_hash' => $hash])) ?>">DUPE x<?= (int)$dupeCount ?></a></span>
                <?php endif; ?>
                <?php if ($hasIssues): ?>
                    <span class="badge issue" title="Integritätsprobleme erkannt">!</span>
                <?php endif; ?>
                <?php if ($hasPrompt && $promptComplete): ?>
                    <span class="badge prompt present" title="Prompt vollständig">P</span>
                <?php elseif ($hasPrompt && !$promptComplete): ?>
                    <span class="badge prompt partial" title="Prompt-Daten unvollständig">P*</span>
                <?php else: ?>
                    <span class="badge prompt missing" title="Kein Prompt hinterlegt">P?</span>
                <?php endif; ?>
                <span class="badge quality quality-<?= htmlspecialchars($qualityClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" title="Prompt-Qualität <?= htmlspecialchars($qualityClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> (Score <?= $qualityScore ?>)<?php if ($qualityIssues !== []): ?> – <?= htmlspecialchars(implode(', ', $qualityIssues), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php endif; ?>">PQ:<?= htmlspecialchars($qualityClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <?php if ($hasMeta): ?>
                    <span class="badge meta" title="Metadaten vorhanden">M</span>
                <?php else: ?>
                    <span class="badge meta missing" title="Keine Metadaten gefunden">M?</span>
                <?php endif; ?>
                <?php if ($hasTags): ?>
                    <span class="badge tag" title="Tags gespeichert">T</span>
                <?php endif; ?>
                <?php if ($hasNsfw): ?>
                    <span class="badge nsfw">FSK18</span>
                <?php endif; ?>
                <?php if ($rating > 0): ?>
                    <span class="badge">Rating <?= $rating ?></span>
                <?php endif; ?>
                <?php if ($status !== '' && $status !== 'active'): ?>
                    <span class="badge status">Status <?= htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <?php endif; ?>
            </div>
            <div class="badges">
                <a href="media_view.php?<?= http_build_query($detailParams) ?>">Details</a>
                <?php if ($type === 'image'): ?>
                    | <a href="<?= htmlspecialchars($streamUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank">Original</a>
                <?php elseif ($type === 'video'): ?>
                    | <a href="<?= htmlspecialchars($streamUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank">Pfad</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="pager">
    Seite <?= (int)$page ?> / <?= (int)$pages ?> |
    <?php if ($page > 1): ?>
        <a href="?<?= http_build_query(array_merge($queryParams, ['p' => $page - 1])) ?>">« zurück</a>
    <?php endif; ?>
    <?php if ($page < $pages): ?>
        <a href="?<?= http_build_query(array_merge($queryParams, ['p' => $page + 1])) ?>">weiter »</a>
    <?php endif; ?>
</div>

</body>
</html>

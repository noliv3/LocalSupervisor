<?php
declare(strict_types=1);

require_once __DIR__ . '/../SCRIPTS/common.php';
require_once __DIR__ . '/../SCRIPTS/paths.php';
require_once __DIR__ . '/../SCRIPTS/operations.php';

try {
    $config = sv_load_config();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<pre>CONFIG-Fehler: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
    exit;
}

$configWarning = $config['_config_warning'] ?? null;
$dsn           = $config['db']['dsn'];
$user          = $config['db']['user']     ?? null;
$password      = $config['db']['password'] ?? null;
$options       = $config['db']['options']  ?? [];

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
$viewMode  = (isset($_GET['view']) && $_GET['view'] === 'list') ? 'list' : 'grid';

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
$allowedStatus    = ['all', 'active', 'archived', 'deleted', 'missing', 'deleted_logical'];
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
        $listSql = 'SELECT m.id, m.path, m.type, m.has_nsfw, m.rating, m.status, m.lifecycle_status, m.quality_status, m.hash,
           p.prompt AS prompt_text, p.width AS prompt_width, p.height AS prompt_height,
           EXISTS (SELECT 1 FROM prompts p3 WHERE p3.media_id = m.id) AS has_prompt,
            EXISTS (SELECT 1 FROM prompts p4 WHERE p4.media_id = m.id AND ' . $promptCompleteClause . ') AS prompt_complete,
           EXISTS (SELECT 1 FROM media_meta mm WHERE mm.media_id = m.id) AS has_meta,
           EXISTS (SELECT 1 FROM media_tags mt WHERE mt.media_id = m.id) AS has_tags,
           EXISTS (SELECT 1 FROM media_meta mm2 WHERE mm2.media_id = m.id AND mm2.meta_key = \"scan_stale\") AS scan_stale
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
$qualitySql = 'SELECT m.id, m.path, m.type, m.has_nsfw, m.rating, m.status, m.lifecycle_status, m.quality_status, m.hash,
           m.width, m.height, m.duration, m.fps, m.filesize,
           p.prompt AS prompt_text, p.width AS prompt_width, p.height AS prompt_height,
           EXISTS (SELECT 1 FROM prompts p3 WHERE p3.media_id = m.id) AS has_prompt,
            EXISTS (SELECT 1 FROM prompts p4 WHERE p4.media_id = m.id AND ' . $promptCompleteClause . ') AS prompt_complete,
           EXISTS (SELECT 1 FROM media_meta mm WHERE mm.media_id = m.id) AS has_meta,
           EXISTS (SELECT 1 FROM media_tags mt WHERE mt.media_id = m.id) AS has_tags,
           EXISTS (SELECT 1 FROM media_meta mm2 WHERE mm2.media_id = m.id AND mm2.meta_key = \"scan_stale\") AS scan_stale
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

    $listSql = 'SELECT m.id, m.path, m.type, m.has_nsfw, m.rating, m.status, m.lifecycle_status, m.quality_status, m.hash,
           m.width, m.height, m.duration, m.fps, m.filesize,
           p.prompt AS prompt_text, p.width AS prompt_width, p.height AS prompt_height,
           EXISTS (SELECT 1 FROM prompts p3 WHERE p3.media_id = m.id) AS has_prompt,
            EXISTS (SELECT 1 FROM prompts p4 WHERE p4.media_id = m.id AND ' . $promptCompleteClause . ') AS prompt_complete,
           EXISTS (SELECT 1 FROM media_meta mm WHERE mm.media_id = m.id) AS has_meta,
           EXISTS (SELECT 1 FROM media_tags mt WHERE mt.media_id = m.id) AS has_tags,
           EXISTS (SELECT 1 FROM media_meta mm2 WHERE mm2.media_id = m.id AND mm2.meta_key = \"scan_stale\") AS scan_stale
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
    'type'           => $typeFilter,
    'has_prompt'     => $hasPromptFilter,
    'has_meta'       => $hasMetaFilter,
    'q'              => $pathFilter,
    'status'         => $statusFilter,
    'min_rating'     => $minRating,
    'incomplete'     => $incompleteFilter,
    'issues'         => $issueFilter ? '1' : '0',
    'prompt_quality' => $promptQualityFilter,
    'adult'          => $showAdult ? '1' : '0',
    'dupes'          => $dupeFilter ? '1' : '0',
    'dupe_hash'      => $dupeHashFilter,
    'view'           => $viewMode,
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

$tabConfigs = [
    [
        'label' => 'Alle',
        'overrides' => ['type' => 'all', 'issues' => null, 'prompt_quality' => 'all'],
    ],
    [
        'label' => 'Images',
        'overrides' => ['type' => 'image', 'issues' => null, 'prompt_quality' => 'all'],
    ],
    [
        'label' => 'Videos',
        'overrides' => ['type' => 'video', 'issues' => null, 'prompt_quality' => 'all'],
    ],
    [
        'label' => 'Issues',
        'overrides' => ['issues' => '1', 'prompt_quality' => 'all'],
    ],
    [
        'label' => 'Prompt C/Schwach',
        'overrides' => ['prompt_quality' => 'C', 'issues' => null],
    ],
];

function sv_tab_query(array $base, array $overrides): string
{
    $query = $base;
    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    $query['p'] = 1;
    return http_build_query($query);
}

function sv_tab_active(array $current, array $overrides): bool
{
    foreach ($overrides as $key => $value) {
        $currentValue = $current[$key] ?? null;
        if ($value === null && $currentValue !== null && $currentValue !== '' && $currentValue !== '0') {
            return false;
        }
        if ($value !== null && $currentValue !== $value) {
            return false;
        }
    }

    return true;
}

$paginationBase = array_filter($queryParams, static fn($v) => $v !== '' && $v !== null);
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
            <?php foreach ($paginationBase as $key => $value): if ($key === 'q' || $key === 'p') { continue; } ?>
                <input type="hidden" name="<?= htmlspecialchars((string)$key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" value="<?= htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?php endforeach; ?>
            <input type="hidden" name="p" value="1">
            <input type="text" name="q" value="<?= htmlspecialchars($pathFilter, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="Pfad oder Name" aria-label="Pfad oder Name">
            <button type="submit" class="btn btn-secondary">Suche</button>
        </form>
        <div class="fsk-toggle">
            <a href="?<?= http_build_query(array_merge($paginationBase, ['adult' => '0', 'p' => 1])) ?>" class="<?= $showAdult ? '' : 'active' ?>">FSK18 aus</a>
            <a href="?<?= http_build_query(array_merge($paginationBase, ['adult' => '1', 'p' => 1])) ?>" class="<?= $showAdult ? 'active' : '' ?>">FSK18 an</a>
        </div>
        <div class="compact-status">
            <label>
                <span>Modus:</span>
                <select name="view" form="view-form">
                    <option value="grid" <?= $viewMode === 'grid' ? 'selected' : '' ?>>Card Grid</option>
                    <option value="list" <?= $viewMode === 'list' ? 'selected' : '' ?>>List Mode</option>
                </select>
            </label>
        </div>
    </div>
</header>

<?php if (!empty($configWarning)): ?>
    <div style="margin: 0.5rem 1rem; padding: 0.6rem 0.8rem; background: #fff3cd; color: #7f4e00; border: 1px solid #ffeeba;">
        <?= htmlspecialchars($configWarning, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    </div>
<?php endif; ?>

<div class="quick-filters">
    <?php foreach ($tabConfigs as $tab):
        $tabQuery = sv_tab_query($paginationBase, $tab['overrides']);
        $isActive = sv_tab_active($queryParams, $tab['overrides']);
        ?>
        <a class="filter-tab <?= $isActive ? 'active' : '' ?>" href="?<?= htmlspecialchars($tabQuery, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?= htmlspecialchars($tab['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </a>
    <?php endforeach; ?>
    <div class="quick-filter-spacer"></div>
    <div class="compact-status">
        <label>
            <span>Status:</span>
            <select name="status" form="filters-form">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>alle</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>active</option>
                <option value="archived" <?= $statusFilter === 'archived' ? 'selected' : '' ?>>archived</option>
                <option value="deleted" <?= $statusFilter === 'deleted' ? 'selected' : '' ?>>deleted</option>
            </select>
        </label>
    </div>
</div>

<form id="view-form" method="get" class="hidden-form">
    <?php foreach ($paginationBase as $key => $value): if ($key === 'view' || $key === 'p') { continue; } ?>
        <input type="hidden" name="<?= htmlspecialchars((string)$key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" value="<?= htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <?php endforeach; ?>
    <input type="hidden" name="p" value="1">
</form>

<form id="filters-form" method="get" class="controls">
    <?php foreach ($paginationBase as $key => $value): if (in_array($key, ['type','has_prompt','has_meta','status','min_rating','incomplete','prompt_quality','view','p'], true)) { continue; } ?>
        <input type="hidden" name="<?= htmlspecialchars((string)$key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" value="<?= htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <?php endforeach; ?>
    <input type="hidden" name="p" value="1">
    <label>
        Typ
        <select name="type">
            <option value="all" <?= $typeFilter === 'all' ? 'selected' : '' ?>>alle</option>
            <option value="image" <?= $typeFilter === 'image' ? 'selected' : '' ?>>Bilder</option>
            <option value="video" <?= $typeFilter === 'video' ? 'selected' : '' ?>>Videos</option>
        </select>
    </label>
    <label>
        Prompt
        <select name="has_prompt">
            <option value="all" <?= $hasPromptFilter === 'all' ? 'selected' : '' ?>>alle</option>
            <option value="with" <?= $hasPromptFilter === 'with' ? 'selected' : '' ?>>mit Prompt</option>
            <option value="without" <?= $hasPromptFilter === 'without' ? 'selected' : '' ?>>ohne Prompt</option>
        </select>
    </label>
    <label>
        Metadaten
        <select name="has_meta">
            <option value="all" <?= $hasMetaFilter === 'all' ? 'selected' : '' ?>>alle</option>
            <option value="with" <?= $hasMetaFilter === 'with' ? 'selected' : '' ?>>mit Meta</option>
            <option value="without" <?= $hasMetaFilter === 'without' ? 'selected' : '' ?>>ohne Meta</option>
        </select>
    </label>
    <label>
        Min Rating
        <select name="min_rating">
            <option value="0" <?= $minRating === 0 ? 'selected' : '' ?>>egal</option>
            <option value="1" <?= $minRating === 1 ? 'selected' : '' ?>>1+</option>
            <option value="2" <?= $minRating === 2 ? 'selected' : '' ?>>2+</option>
            <option value="3" <?= $minRating === 3 ? 'selected' : '' ?>>3</option>
        </select>
    </label>
    <label>
        Incomplete
        <select name="incomplete">
            <option value="none" <?= $incompleteFilter === 'none' ? 'selected' : '' ?>>aus</option>
            <option value="prompt" <?= $incompleteFilter === 'prompt' ? 'selected' : '' ?>>Prompt</option>
            <option value="tags" <?= $incompleteFilter === 'tags' ? 'selected' : '' ?>>Tags</option>
            <option value="meta" <?= $incompleteFilter === 'meta' ? 'selected' : '' ?>>Meta</option>
            <option value="any" <?= $incompleteFilter === 'any' ? 'selected' : '' ?>>alles</option>
        </select>
    </label>
    <label>
        Prompt-Qualität
        <select name="prompt_quality">
            <option value="all" <?= $promptQualityFilter === 'all' ? 'selected' : '' ?>>alle</option>
            <option value="A" <?= $promptQualityFilter === 'A' ? 'selected' : '' ?>>A</option>
            <option value="B" <?= $promptQualityFilter === 'B' ? 'selected' : '' ?>>B</option>
            <option value="C" <?= $promptQualityFilter === 'C' ? 'selected' : '' ?>>C</option>
        </select>
    </label>
    <button type="submit">Filter anwenden</button>
    <a class="reset-link" href="?adult=<?= $showAdult ? '1' : '0' ?>">Reset</a>
</form>

<div class="pager compact">
    <span>Seite <?= (int)$page ?> / <?= (int)$pages ?></span>
    <?php if ($page > 1): ?>
        <a href="?<?= http_build_query(array_merge($paginationBase, ['p' => $page - 1])) ?>">« zurück</a>
    <?php else: ?>
        <span class="disabled">« zurück</span>
    <?php endif; ?>
    <?php if ($page < $pages): ?>
        <a href="?<?= http_build_query(array_merge($paginationBase, ['p' => $page + 1])) ?>">weiter »</a>
    <?php else: ?>
        <span class="disabled">weiter »</span>
    <?php endif; ?>
</div>

<?php if ($viewMode === 'grid'): ?>
    <div class="media-grid">
        <?php if ($rows === []): ?>
            <div class="empty">Keine Einträge für diesen Filter.</div>
        <?php endif; ?>
        <?php foreach ($rows as $row):
            $id      = (int)$row['id'];
            $path    = (string)$row['path'];
            $type    = (string)$row['type'];
            $hasNsfw = (int)($row['has_nsfw'] ?? 0) === 1;
            $rating  = (int)($row['rating'] ?? 0);
            $status  = (string)($row['status'] ?? '');
            $lifecycleStatus = (string)($row['lifecycle_status'] ?? '');
            $qualityStatus = (string)($row['quality_status'] ?? '');
            $hash    = (string)($row['hash'] ?? '');
            $dupeCount = ($hash !== '' && isset($dupeCounts[$hash])) ? (int)$dupeCounts[$hash] : 0;
            $hasPrompt = (int)($row['has_prompt'] ?? 0) === 1;
            $promptComplete = (int)($row['prompt_complete'] ?? 0) === 1;
            $hasMeta   = (int)($row['has_meta'] ?? 0) === 1;
            $hasTags   = (int)($row['has_tags'] ?? 0) === 1;
            $scanStale = (int)($row['scan_stale'] ?? 0) === 1;
            $hasIssues = isset($issuesByMedia[$id]);

            $qualityData = $row['quality'] ?? sv_prompt_quality_from_text(
                $row['prompt_text'] ?? null,
                isset($row['prompt_width']) ? (int)$row['prompt_width'] : null,
                isset($row['prompt_height']) ? (int)$row['prompt_height'] : null
            );
            $qualityClass = $qualityData['quality_class'];
            $qualityScore = (int)$qualityData['score'];
            $qualityIssues = array_slice($qualityData['issues'] ?? [], 0, 2);

            $statusVariant = 'clean';
            if ($hasIssues) {
                $statusVariant = 'warn';
            }
            if ($qualityClass === 'C') {
                $statusVariant = 'warn';
            }
            if ($status !== '' && $status !== 'active') {
                $statusVariant = 'bad';
            }
            if ($qualityStatus === SV_QUALITY_BLOCKED) {
                $statusVariant = 'bad';
            } elseif ($lifecycleStatus !== '' && $lifecycleStatus !== SV_LIFECYCLE_ACTIVE) {
                $statusVariant = 'warn';
            }

            $thumbUrl = 'thumb.php?' . http_build_query(['id' => $id, 'adult' => $showAdult ? '1' : '0']);
            $detailParams = array_merge($paginationBase, ['id' => $id, 'p' => $page]);
            $streamUrl = sv_media_stream_url($id, $showAdult, false);
            $mediaWidth  = isset($row['width']) ? (int)$row['width'] : null;
            $mediaHeight = isset($row['height']) ? (int)$row['height'] : null;
            if ($type === 'image') {
                if ($mediaWidth && $mediaHeight) {
                    $resolution = $mediaWidth . '×' . $mediaHeight;
                } elseif (isset($row['prompt_width'], $row['prompt_height']) && $row['prompt_width'] && $row['prompt_height']) {
                    $resolution = (int)$row['prompt_width'] . '×' . (int)$row['prompt_height'];
                } else {
                    $resolution = 'keine Größe';
                }
            } else {
                $resolution = ($mediaWidth && $mediaHeight) ? ($mediaWidth . '×' . $mediaHeight) : 'keine Größe';
            }
            $duration = $type === 'video' && isset($row['duration']) ? (float)$row['duration'] : null;
            ?>
            <article class="media-card status-<?= $statusVariant ?>" data-media-id="<?= $id ?>">
                <div class="card-badges">
                    <?php if ($dupeCount > 1): ?>
                        <span class="pill pill-warn">Dupe x<?= (int)$dupeCount ?></span>
                    <?php endif; ?>
                    <?php if ($hasIssues): ?>
                        <span class="pill pill-warn" title="Integritätsprobleme erkannt">Issues</span>
                    <?php endif; ?>
                    <?php if ($hasNsfw): ?>
                        <span class="pill pill-nsfw">FSK18</span>
                    <?php endif; ?>
                    <?php if ($status !== '' && $status !== 'active'): ?>
                        <span class="pill pill-bad">Status <?= htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <?php endif; ?>
                    <?php if ($lifecycleStatus !== '' && $lifecycleStatus !== SV_LIFECYCLE_ACTIVE): ?>
                        <span class="pill pill-warn" title="Lifecycle"><?= htmlspecialchars($lifecycleStatus, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <?php endif; ?>
                    <?php if ($qualityStatus !== '' && $qualityStatus !== SV_QUALITY_UNKNOWN): ?>
                        <span class="pill pill-muted" title="Quality"><?= htmlspecialchars($qualityStatus, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <?php endif; ?>
                    <?php if ($scanStale): ?>
                        <span class="pill pill-warn" title="Scanner nicht erreichbar, Tags/Rating veraltet">Scan stale</span>
                    <?php endif; ?>
                    <?php if ($rating > 0): ?>
                        <span class="pill">Rating <?= $rating ?></span>
                    <?php endif; ?>
                </div>

                <div class="thumb-wrap">
                    <img
                        src="<?= htmlspecialchars($thumbUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                        data-media-id="<?= $id ?>"
                        loading="lazy"
                        alt="ID <?= $id ?>">
                    <div class="card-actions">
                        <a class="btn btn-secondary" href="media_view.php?<?= http_build_query($detailParams) ?>">Details</a>
                    </div>
                </div>

                <div class="card-info">
                    <div class="info-line">
                        <span class="info-chip"><?= $type === 'video' ? 'Video' : 'Bild' ?></span>
                        <span class="info-chip"><?= htmlspecialchars($resolution, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                        <?php if ($duration !== null): ?>
                            <span class="info-chip"><?= htmlspecialchars(number_format($duration, 1), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>s</span>
                        <?php endif; ?>
                        <span class="info-chip <?= $qualityClass === 'C' ? 'chip-warn' : '' ?>" title="Prompt-Qualität <?= htmlspecialchars($qualityClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> (Score <?= $qualityScore ?><?php if ($qualityIssues !== []): ?> – <?= htmlspecialchars(implode(', ', $qualityIssues), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php endif; ?>)">PQ <?= htmlspecialchars($qualityClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                        <?php if ($hasMeta): ?><span class="info-chip">Meta</span><?php endif; ?>
                        <?php if ($hasTags): ?><span class="info-chip">Tags</span><?php endif; ?>
                        <?php if ($hasPrompt && !$promptComplete): ?><span class="info-chip chip-warn">Prompt unvollständig</span><?php endif; ?>
                        <?php if (!$hasPrompt): ?><span class="info-chip">Kein Prompt</span><?php endif; ?>
                        <?php if ($scanStale): ?><span class="info-chip chip-warn" title="Scanner nicht erreichbar, Daten veraltet">Scan stale</span><?php endif; ?>
                    </div>
                    <div class="info-path" title="<?= htmlspecialchars($path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        ID <?= $id ?> · <?= htmlspecialchars(basename($path), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </div>
                    <div class="info-line">
                        <a class="btn btn-secondary" href="media_view.php?<?= http_build_query($detailParams) ?>">Details</a>
                        <a class="btn btn-primary" href="<?= htmlspecialchars($streamUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener">Original</a>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="panel" style="margin: 1rem;">
        <div class="panel-header">Listenansicht</div>
        <div class="panel-content">
            <?php if ($rows === []): ?>
                <div class="empty">Keine Einträge für diesen Filter.</div>
            <?php else: ?>
                <table style="width:100%; border-collapse: collapse; font-size: 0.9rem;">
                    <thead>
                        <tr style="text-align:left; border-bottom:1px solid #1f2733;">
                            <th>ID</th>
                            <th>Name</th>
                            <th>Typ</th>
                            <th>Prompt</th>
                            <th>Meta</th>
                            <th>Tags</th>
                            <th>Issues</th>
                            <th>Rating</th>
                            <th>Status</th>
                            <th>Aktion</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row):
                        $id      = (int)$row['id'];
                        $path    = (string)$row['path'];
                        $type    = (string)$row['type'];
                        $hasPrompt = (int)($row['has_prompt'] ?? 0) === 1;
                        $promptComplete = (int)($row['prompt_complete'] ?? 0) === 1;
                        $hasMeta   = (int)($row['has_meta'] ?? 0) === 1;
                        $hasTags   = (int)($row['has_tags'] ?? 0) === 1;
                        $hasIssues = isset($issuesByMedia[$id]);
                        $scanStale = (int)($row['scan_stale'] ?? 0) === 1;
                        $rating  = (int)($row['rating'] ?? 0);
                        $status  = (string)($row['status'] ?? '');
                        $detailParams = array_merge($paginationBase, ['id' => $id, 'p' => $page]);
                        ?>
                        <tr style="border-bottom:1px solid #111827;">
                            <td><?= $id ?></td>
                            <td title="<?= htmlspecialchars($path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars(basename($path), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                            <td><?= $hasPrompt ? ($promptComplete ? 'vollständig' : 'teilweise') : 'fehlend' ?></td>
                            <td><?= $hasMeta ? 'ja' : 'nein' ?></td>
                            <td><?= $hasTags ? 'ja' : 'nein' ?></td>
                            <td><?= $hasIssues ? 'ja' : '—' ?></td>
                            <td><?= $rating > 0 ? (int)$rating : '—' ?></td>
                            <td><?= $status !== '' ? htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : 'active' ?><?= $scanStale ? ' (stale)' : '' ?></td>
                            <td><a class="btn btn-secondary" href="media_view.php?<?= http_build_query($detailParams) ?>">Details</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="meta" style="text-align:center; padding:0.6rem; color:#9ca3af;">
    FSK18-Link: <code>?adult=1</code> oder <code>?18=True</code> · Default: Card Grid · Legacy-Grid media.php bleibt unverändert
</div>

<div class="pager compact">
    <span>Seite <?= (int)$page ?> / <?= (int)$pages ?></span>
    <?php if ($page > 1): ?>
        <a href="?<?= http_build_query(array_merge($paginationBase, ['p' => $page - 1])) ?>">« zurück</a>
    <?php else: ?>
        <span class="disabled">« zurück</span>
    <?php endif; ?>
    <?php if ($page < $pages): ?>
        <a href="?<?= http_build_query(array_merge($paginationBase, ['p' => $page + 1])) ?>">weiter »</a>
    <?php else: ?>
        <span class="disabled">weiter »</span>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const viewSelect = document.querySelector('select[name="view"][form="view-form"]');
    const viewForm = document.getElementById('view-form');
    if (viewSelect && viewForm) {
        viewSelect.addEventListener('change', () => viewForm.submit());
    }
});
</script>

</body>
</html>

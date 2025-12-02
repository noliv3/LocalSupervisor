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

function sv_path_to_url(string $path, array $pathsCfg): ?string
{
    $norm = str_replace('\\', '/', $path);

    $map = [];

    if (!empty($pathsCfg['images'])) {
        $map[rtrim(str_replace('\\', '/', $pathsCfg['images']), '/')] = '/bilder';
    }
    if (!empty($pathsCfg['images_18'])) {
        $map[rtrim(str_replace('\\', '/', $pathsCfg['images_18']), '/')] = '/fsk18';
    }
    if (!empty($pathsCfg['videos'])) {
        $map[rtrim(str_replace('\\', '/', $pathsCfg['videos']), '/')] = '/videos';
    }
    if (!empty($pathsCfg['videos_18'])) {
        $map[rtrim(str_replace('\\', '/', $pathsCfg['videos_18']), '/')] = '/videos18';
    }

    foreach ($map as $fsBase => $urlBase) {
        $len = strlen($fsBase);
        if (strncasecmp($norm, $fsBase, $len) === 0) {
            $rel = substr($norm, $len);
            if ($rel === false) {
                $rel = '';
            }
            $rel = ltrim($rel, '/');

            if ($rel === '') {
                return $urlBase . '/';
            }

            $parts = explode('/', $rel);
            $parts = array_map('rawurlencode', $parts);

            return $urlBase . '/' . implode('/', $parts);
        }
    }

    return null;
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

$pathsCfg = $config['paths'] ?? [];

$showAdult = sv_normalize_adult_flag($_GET);

$page    = sv_clamp_int((int)($_GET['p'] ?? 1), 1, 10000, 1);
$perPage = 100;
$offset  = ($page - 1) * $perPage;

$typeFilter      = $_GET['type'] ?? 'all';
$hasPromptFilter = $_GET['has_prompt'] ?? 'all';
$hasMetaFilter   = $_GET['has_meta'] ?? 'all';
$pathFilter      = sv_limit_string((string)($_GET['q'] ?? ''), 200);
$statusFilter    = $_GET['status'] ?? 'all';
$minRating       = (int)($_GET['min_rating'] ?? 0);

$allowedTypes     = ['all', 'image', 'video'];
$allowedPrompt    = ['all', 'with', 'without'];
$allowedMeta      = ['all', 'with', 'without'];
$allowedStatus    = ['all', 'active', 'archived', 'deleted'];
$typeFilter       = sv_normalize_enum($typeFilter, $allowedTypes, 'all');
$hasPromptFilter  = sv_normalize_enum($hasPromptFilter, $allowedPrompt, 'all');
$hasMetaFilter    = sv_normalize_enum($hasMetaFilter, $allowedMeta, 'all');
$statusFilter     = sv_normalize_enum($statusFilter, $allowedStatus, 'all');
$minRating        = sv_clamp_int($minRating, 0, 3, 0);

$where  = [];
$params = [];

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

$whereSql = $where === [] ? '1=1' : implode(' AND ', $where);

$countSql = 'SELECT COUNT(*) FROM media m WHERE ' . $whereSql;
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$listSql = 'SELECT m.id, m.path, m.type, m.has_nsfw, m.rating, m.status,
       EXISTS (SELECT 1 FROM prompts p WHERE p.media_id = m.id) AS has_prompt,
       EXISTS (SELECT 1 FROM media_meta mm WHERE mm.media_id = m.id) AS has_meta
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
$pages = max(1, (int)ceil($total / $perPage));

$queryParams = [
    'type'       => $typeFilter,
    'has_prompt' => $hasPromptFilter,
    'has_meta'   => $hasMetaFilter,
    'q'          => $pathFilter,
    'status'     => $statusFilter,
    'min_rating' => $minRating,
    'adult'      => $showAdult ? '1' : '0',
];

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
        Metadaten:
        <select name="has_meta">
            <option value="all"     <?= $hasMetaFilter === 'all' ? 'selected' : '' ?>>alle</option>
            <option value="with"    <?= $hasMetaFilter === 'with' ? 'selected' : '' ?>>nur mit Meta</option>
            <option value="without" <?= $hasMetaFilter === 'without' ? 'selected' : '' ?>>ohne Meta</option>
        </select>
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
        $hasPrompt = (int)($row['has_prompt'] ?? 0) === 1;
        $hasMeta   = (int)($row['has_meta'] ?? 0) === 1;

        $url = sv_path_to_url($path, $pathsCfg);
        $thumbUrl = 'thumb.php?' . http_build_query(['id' => $id, 'adult' => $showAdult ? '1' : '0']);
        $detailParams = array_merge($queryParams, ['id' => $id, 'p' => $page]);
        ?>
        <div class="item">
            <div class="thumb-wrap">
                <?php if ($type === 'image'): ?>
                    <?php if ($url !== null): ?>
                        <a href="media_view.php?<?= http_build_query($detailParams) ?>">
                            <img
                                src="<?= htmlspecialchars($thumbUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                loading="lazy"
                                alt="ID <?= $id ?>">
                        </a>
                    <?php else: ?>
                        <span>ohne Pfad</span>
                    <?php endif; ?>
                <?php else: ?>
                    <a href="media_view.php?<?= http_build_query($detailParams) ?>">Video</a>
                <?php endif; ?>
            </div>
            <div class="title">
                ID <?= $id ?> – <?= htmlspecialchars(basename($path), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </div>
            <div class="badges">
                <span class="badge <?= $type === 'video' ? 'video' : 'image' ?>"><?= $type === 'video' ? 'V' : 'I' ?></span>
                <?php if ($hasPrompt): ?>
                    <span class="badge prompt">Prompt</span>
                <?php endif; ?>
                <?php if ($hasMeta): ?>
                    <span class="badge meta">Meta</span>
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
                <?php if ($url !== null && $type === 'image'): ?>
                    | <a href="<?= htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank">Original</a>
                <?php elseif ($url !== null && $type === 'video'): ?>
                    | <a href="<?= htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank">Pfad</a>
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

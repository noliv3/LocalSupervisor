<?php
declare(strict_types=1);

$config = require __DIR__ . '/../CONFIG/config.php';
require_once __DIR__ . '/../SCRIPTS/paths.php';

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

function sv_media_stream_url(int $id, bool $adult): string
{
    $params = [
        'id'    => $id,
        'adult' => $adult ? '1' : '0',
    ];

    return 'media_stream.php?' . http_build_query($params);
}

/* FSK18-Flag: nur sichtbar, wenn adult=1 oder 18=true in der URL */
$showAdult =
    (isset($_GET['adult']) && $_GET['adult'] === '1')
    || (isset($_GET['18']) && strcasecmp((string)$_GET['18'], 'true') === 0);

$page    = max(1, (int)($_GET['p'] ?? 1));
$perPage = 200; // Grid-tauglich, bei Bedarf anpassen
$offset  = ($page - 1) * $perPage;

$pathFilter = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? 'alle';

$where  = ['m.type = :type'];
$params = [':type' => 'image'];

if (!$showAdult) {
    $where[] = '(m.has_nsfw IS NULL OR m.has_nsfw = 0)';
}

if ($pathFilter !== '') {
    $where[]        = 'm.path LIKE :path';
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
$listSql = 'SELECT m.id, m.path, m.type, m.is_missing, m.has_nsfw, m.rating, m.created_at, m.imported_at
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

<div class="pager">
    Seite <?= (int)$page ?> / <?= (int)$pages ?> |
    <?php if ($page > 1): ?>
        <a href="?<?= http_build_query(['p' => $page - 1, 'q' => $pathFilter, 'status' => $statusFilter, 'adult' => $showAdult ? '1' : '0']) ?>">« zurück</a>
    <?php endif; ?>
    <?php if ($page < $pages): ?>
        <a href="?<?= http_build_query(['p' => $page + 1, 'q' => $pathFilter, 'status' => $statusFilter, 'adult' => $showAdult ? '1' : '0']) ?>">weiter »</a>
    <?php endif; ?>
</div>

<div class="gallery">
    <?php foreach ($rows as $row):
        $id        = (int)$row['id'];
        $path      = (string)$row['path'];
        $isMissing = (int)($row['is_missing'] ?? 0) === 1;
        $hasNsfw   = (int)($row['has_nsfw'] ?? 0) === 1;
        $rating    = (int)($row['rating'] ?? 0);

        $thumbUrl = 'thumb.php?id=' . $id;
        $streamUrl = sv_media_stream_url($id, $showAdult);
        ?>
        <div class="item<?= $isMissing ? ' missing' : '' ?>">
            <div class="thumb-wrap">
                <?php if ($isMissing): ?>
                    <span>fehlend</span>
                <?php else: ?>
                    <a href="<?= htmlspecialchars($streamUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank">
                        <img
                            src="<?= htmlspecialchars($thumbUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
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

</body>
</html>

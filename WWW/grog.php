<?php
declare(strict_types=1);

$base = dirname(__DIR__);
$config = require $base . '/CONFIG/config.php';

$dsn      = $config['db']['dsn'];
$user     = $config['db']['user']     ?? null;
$password = $config['db']['password'] ?? null;
$options  = $config['db']['options']  ?? [];

try {
    $pdo = new PDO($dsn, $user, $password, $options);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    die("DB-Fehler: " . htmlspecialchars($e->getMessage()));
}

// === FILTER ===
$showNSFW   = ($_GET['nsfw'] ?? 'hide') === 'show';
$source     = $_GET['source'] ?? 'all';
$rating     = $_GET['rating'] ?? 'all';
$tag        = trim($_GET['tag'] ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 48;

// Basis-Query
$sql = "SELECT m.id, m.path, m.has_nsfw, m.rating, m.source, m.created_at
        FROM media m
        WHERE m.type = 'image'";
$params = [];

if (!$showNSFW) {
    $sql .= " AND m.has_nsfw = 0";
}
if ($source !== 'all') {
    $sql .= " AND m.source = :source";
    $params[':source'] = $source;
}
if ($rating !== 'all') {
    $sql .= " AND m.rating = :rating";
    $params[':rating'] = (int)$rating;
}
if ($tag !== '') {
    $sql .= " AND EXISTS (
        SELECT 1 FROM media_tags mt
        JOIN tags t ON t.id = mt.tag_id
        WHERE mt.media_id = m.id AND t.name = :tag
    )";
    $params[':tag'] = $tag;
}

$sqlCount = str_replace('SELECT m.id, m.path, m.has_nsfw, m.rating, m.source, m.created_at', 'SELECT COUNT(*)', $sql);
$total    = (int)$pdo->prepare($sqlCount)->execute($params) ? $pdo->query($sqlCount)->fetchColumn() : 0;
$pages    = max(1, ceil($total / $perPage));

$sql .= " ORDER BY m.id DESC LIMIT " . ($page-1)*$perPage . ", $perPage";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$media = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Tags für Filter-Vorschläge
$popularTags = $pdo->query("
    SELECT t.name, COUNT(*) as cnt
    FROM tags t
    JOIN media_tags mt ON mt.tag_id = t.id
    GROUP BY t.id, t.name
    ORDER BY cnt DESC LIMIT 20
")->fetchAll(PDO::FETCH_COLUMN);

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>SuperVisOr ∞ Gallery</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --bg: #0d1117;
            --card: #161b22;
            --text: #c9d1d9;
            --accent: #58a6ff;
            --red: #f85149;
            --green: #3fb950;
        }
        body {
            margin:0; padding:0; background:var(--bg); color:var(--text);
            font-family: -apple-system,system-ui,sans-serif;
            line-height:1.5;
        }
        header {
            background:#010409; padding:1rem; border-bottom:1px solid #30363d;
            position:sticky; top:0; z-index:10;
        }
        .container { max-width:1400px; margin:auto; padding:0 1rem; }
        .controls {
            display:flex; flex-wrap:wrap; gap:1rem; align-items:center; margin:1.5rem 0;
        }
        select, input, button {
            padding:0.5rem 0.8rem; background:var(--card); border:1px solid #30363d;
            color:var(--text); border-radius:6px; font-size:1rem;
        }
        button { background:var(--accent); border:none; cursor:pointer; font-weight:600; }
        button:hover { opacity:0.9; }
        .grid {
            display:grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap:1rem;
            padding:1rem 0;
        }
        .thumb {
            position:relative; border-radius:8px; overflow:hidden;
            background:#000; box-shadow:0 4px 10px rgba(0,0,0,0.5);
            transition:transform .2s, box-shadow .2s;
        }
        .thumb:hover {
            transform:translateY(-6px);
            box-shadow:0 12px 20px rgba(0,0,0,0.6);
        }
        .thumb img {
            width:100%; height:100%; object-fit:cover; display:block;
        }
        .overlay {
            position:absolute; inset:0;
            background:linear-gradient(transparent 60%, rgba(0,0,0,0.8));
            display:flex; flex-direction:column; justify-content:flex-end;
            padding:1rem 0.75rem; color:white; font-size:0.85rem;
        }
        .badge {
            position:absolute; top:8px; right:8px; padding:2px 8px;
            background:rgba(0,0,0,0.7); border-radius:4px; font-size:0.7rem;
            font-weight:600;
        }
        .nsfw   { background:var(--red); }
        .source { background:#444; }
        .rating { background:#444; }
        .tags { margin-top:4px; font-size:0.75rem; opacity:0.9; }
        .pagination {
            text-align:center; padding:2rem 0;
        }
        .pagination a {
            padding:0.5rem 1rem; margin:0 0.25rem; background:var(--card);
            border-radius:6px; text-decoration:none; color:var(--text);
        }
        .pagination a.active { background:var(--accent); }
        .notice {
            background:#581c1c; border:1px solid #8b3a3a; padding:1rem; border-radius:6px;
            margin:1rem 0; color:#ff7b72;
        }
        footer { text-align:center; padding:2rem; color:#666; font-size:0.9rem; }
    </style>
</head>
<body>

<header>
    <div class="container">
        <h1 style="margin:0; color:#58a6ff;">SuperVisOr ∞</h1>
    </div>
</header>

<div class="container">

    <?php if (!$showNSFW): ?>
        <div class="notice">
            <strong>NSFW ausgeblendet</strong> – <a href="?nsfw=show<?= http_build_query($_GET, '', '&amp;') ?>" style="color:#ff7b72;">Jetzt alles anzeigen</a>
        </div>
    <?php endif; ?>

    <div class="controls">
        <select onchange="location='?nsfw=<?= $showNSFW?'show':'hide' ?>&source='+this.value">
            <option value="all">Alle Quellen</option>
            <option value="comfy" <?= $source==='comfy'?'selected':'' ?>>ComfyUI</option>
            <option value="sd" <?= $source==='sd'?'selected':'' ?>>A1111</option>
            <option value="forge" <?= $source==='forge'?'selected':'' ?>>Forge</option>
            <option value="pinokio" <?= $source==='pinokio'?'selected':'' ?>>Pinokio</option>
        </select>

        <select onchange="location='?nsfw=<?= $showNSFW?'show':'hide' ?>&rating='+this.value">
            <option value="all">Alle Ratings</option>
            <option value="0" <?= $rating==='0'?'selected':'' ?>>Safe</option>
            <option value="1" <?= $rating==='1'?'selected':'' ?>>Questionable</option>
            <option value="2" <?= $rating==='2'?'selected':'' ?>>Explicit</option>
        </select>

        <input type="text" placeholder="Tag suchen…" value="<?= htmlspecialchars($tag) ?>"
               onkeydown="if(event.key==='Enter') location='?tag='+encodeURIComponent(this.value)">
        
        <div style="margin-left:auto;">
            <strong><?= $total ?></strong> Bilder
            <?php if($pages>1): ?> – Seite <?= $page ?>/<?= $pages ?><?php endif; ?>
        </div>
    </div>

    <?php if ($popularTags): ?>
        <div style="margin:1rem 0;">
            Hot Tags:
            <?php foreach($popularTags as $t): ?>
                <a href="?tag=<?= urlencode($t) ?>" style="margin-right:0.5rem; color:#58a6ff;">#<?= htmlspecialchars($t) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="grid">
        <?php foreach ($media as $m): ?>
            <?php
                $path = $m['path'];
                $isNSFW = (bool)$m['has_nsfw'];
                $ratingText = ['Safe','Questionable','Explicit'][$m['rating']] ?? 'Unbekannt';
                $sourceText = strtoupper($m['source'] ?? '???');
            ?>
            <div class="thumb">
                <a href="<?= htmlspecialchars($path) ?>" target="_blank">
                    <img src="<?= htmlspecialchars($path) ?>" loading="lazy" alt="ID <?= $m['id'] ?>">
                </a>
                <div class="overlay">
                    <div>ID <?= $m['id'] ?></div>
                    <div class="tags"><?= $sourceText ?> • <?= $ratingText ?></div>
                </div>
                <?php if ($isNSFW): ?>
                    <div class="badge nsfw">NSFW</div>
                <?php endif; ?>
                <div class="badge source"><?= $sourceText ?></div>
                <div class="badge rating"><?= $m['rating'] ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
        <div class="pagination">
            <?php for($i=1;$i<=$pages;$i++): ?>
                <?php if($i==$page): ?>
                    <a class="active"><?= $i ?></a>
                <?php else: ?>
                    <a href="?page=<?= $i ?>&<?= http_build_query(['nsfw'=>$showNSFW?'show':'hide','source'=>$source,'rating'=>$rating,'tag'=>$tag]) ?>">
                        <?= $i ?>
                    </a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
    <?php endif; ?>

</div>

<footer>
    SuperVisOr – Dein lokaler AI-Media-Gott • <?= date('Y') ?>
</footer>

</body>
</html>
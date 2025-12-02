<?php
declare(strict_types=1);

// Read-only CLI zur Inspektion von Prompts und Metadaten.

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Nur CLI.\n");
    exit(1);
}

$baseDir = realpath(__DIR__ . '/..');
if ($baseDir === false) {
    fwrite(STDERR, "Basisverzeichnis nicht gefunden.\n");
    exit(1);
}

$configFile = $baseDir . '/CONFIG/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "CONFIG/config.php fehlt.\n");
    exit(1);
}

$config = require $configFile;
$dsn      = $config['db']['dsn'];
$user     = $config['db']['user']     ?? null;
$password = $config['db']['password'] ?? null;
$options  = $config['db']['options']  ?? [];

try {
    $pdo = new PDO($dsn, $user, $password, $options);
} catch (Throwable $e) {
    fwrite(STDERR, "DB-Fehler: " . $e->getMessage() . "\n");
    exit(1);
}

$limit = 20;
for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if (strpos($arg, '--limit=') === 0) {
        $limit = max(1, (int)substr($arg, strlen('--limit=')));
        continue;
    }
    if ($arg === '--limit' && isset($argv[$i + 1])) {
        $limit = max(1, (int)$argv[$i + 1]);
        $i++;
        continue;
    }
}

function sv_meta_inspect_truncate(?string $text, int $max = 120): string
{
    if ($text === null || $text === '') {
        return '-';
    }
    $text = trim($text);
    if (mb_strlen($text) <= $max) {
        return $text;
    }

    return mb_substr($text, 0, $max - 3) . '...';
}

$mediaStmt = $pdo->prepare('SELECT id, type, path FROM media ORDER BY id DESC LIMIT ?');
$mediaStmt->bindValue(1, $limit, PDO::PARAM_INT);
$mediaStmt->execute();
$mediaRows = $mediaStmt->fetchAll(PDO::FETCH_ASSOC);

$promptStmt = $pdo->prepare('SELECT prompt FROM prompts WHERE media_id = ? ORDER BY id DESC LIMIT 1');
$metaStmt   = $pdo->prepare('SELECT source, meta_key, meta_value FROM media_meta WHERE media_id = ? ORDER BY source, meta_key');

foreach ($mediaRows as $row) {
    $id   = (int)$row['id'];
    $type = (string)$row['type'];
    $path = (string)$row['path'];

    echo "MEDIA #{$id} ({$type}) path={$path}\n";

    $promptStmt->execute([$id]);
    $promptRow = $promptStmt->fetch(PDO::FETCH_ASSOC);
    $prompt    = $promptRow['prompt'] ?? null;
    echo 'Prompt: "' . sv_meta_inspect_truncate(is_string($prompt) ? $prompt : null) . "\"\n";

    echo "META:\n";
    $metaStmt->execute([$id]);
    $metaRows = $metaStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($metaRows as $meta) {
        $src = (string)$meta['source'];
        $key = (string)$meta['meta_key'];
        $val = $meta['meta_value'];
        $valStr = $val === null ? 'NULL' : (string)$val;
        echo "  [{$src}] {$key} = {$valStr}\n";
    }
    echo "\n";
}

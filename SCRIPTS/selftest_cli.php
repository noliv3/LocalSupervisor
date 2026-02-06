<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Nur CLI erlaubt.\n");
    exit(1);
}

require_once __DIR__ . '/scan_core.php';
require_once __DIR__ . '/thumb_core.php';

$baseTemp = rtrim(sys_get_temp_dir(), '/\\') . '/sv_selftest_' . uniqid();
$dirs = [
    $baseTemp,
    $baseTemp . '/input',
    $baseTemp . '/library/images_sfw',
    $baseTemp . '/library/images_nsfw',
    $baseTemp . '/library/videos_sfw',
    $baseTemp . '/library/videos_nsfw',
    $baseTemp . '/cache/thumbs',
];

foreach ($dirs as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        fwrite(STDERR, "Kann Verzeichnis nicht anlegen: {$dir}\n");
        exit(1);
    }
}

$dbFile = $baseTemp . '/selftest.sqlite';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$logger = static function (string $msg): void {
    fwrite(STDOUT, $msg . PHP_EOL);
};

$allOk = true;

$logsDir = __DIR__ . '/../LOGS';
$logFiles = [
    $logsDir . '/ollama_jobs.jsonl',
    $logsDir . '/system_errors.jsonl',
];
$logsDirWritable = is_dir($logsDir) && is_writable($logsDir);
foreach ($logFiles as $logFile) {
    if (is_file($logFile)) {
        if (!is_writable($logFile)) {
            $logger('FAIL: Logdatei nicht beschreibbar: ' . $logFile);
            $allOk = false;
        } else {
            $logger('OK: Logdatei beschreibbar: ' . $logFile);
        }
        continue;
    }
    if (!$logsDirWritable) {
        $logger('FAIL: Log-Ordner nicht beschreibbar: ' . $logsDir);
        $allOk = false;
    } else {
        $logger('OK: Log-Ordner beschreibbar für ' . basename($logFile));
    }
}

$journalMode = $pdo->query('PRAGMA journal_mode')->fetchColumn();
$journalMode = is_string($journalMode) ? strtolower($journalMode) : '';
if ($journalMode === 'wal') {
    $logger('OK: SQLite journal_mode=wal');
} else {
    $logger('WARN: SQLite journal_mode=' . ($journalMode !== '' ? $journalMode : 'unknown') . ' (Performance degradation risk)');
}

$schemaSql = @file_get_contents(__DIR__ . '/../DB/schema.sql');
if (!is_string($schemaSql) || trim($schemaSql) === '') {
    fwrite(STDERR, "Schema konnte nicht geladen werden.\n");
    exit(1);
}
$pdo->exec($schemaSql);

// Migration locked-Flag für media_tags sicherstellen
$migrationFile = __DIR__ . '/migrations/20260105_001_add_media_tags_locked.php';
if (is_file($migrationFile)) {
    $migration = require $migrationFile;
    if (is_array($migration) && isset($migration['run']) && is_callable($migration['run'])) {
        $migration['run']($pdo);
    }
}

// Testbild erzeugen
$imgPath = $baseTemp . '/input/test.png';
$img = function_exists('imagecreatetruecolor') ? imagecreatetruecolor(320, 240) : null;
if ($img === null) {
    fwrite(STDERR, "GD nicht verfügbar.\n");
    exit(1);
}
$bg  = imagecolorallocate($img, 40, 120, 200);
imagefilledrectangle($img, 0, 0, 319, 239, $bg);
imagepng($img, $imgPath);
imagedestroy($img);
$logger('PNG erzeugt: ' . $imgPath);

// Testvideo erzeugen
$ffmpeg = sv_resolve_ffmpeg_path([]);
$videoPath = $baseTemp . '/input/test.mp4';
$ffmpegAvailable = false;
$which = @shell_exec('command -v ' . escapeshellarg($ffmpeg));
if (is_string($which) && trim($which) !== '') {
    $ffmpegAvailable = is_file(trim($which)) || is_executable(trim($which));
}
if (!$ffmpegAvailable) {
    $probe = @shell_exec(escapeshellarg($ffmpeg) . ' -version 2>&1');
    if (is_string($probe) && trim($probe) !== '') {
        $ffmpegAvailable = true;
    }
}
if ($ffmpegAvailable) {
    $cmd = escapeshellarg($ffmpeg)
        . ' -y -f lavfi -i color=c=black:s=320x240:d=1 -c:v libx264 -pix_fmt yuv420p '
        . escapeshellarg($videoPath) . ' 2>&1';
    @shell_exec($cmd);
    $ffmpegAvailable = is_file($videoPath);
}

if ($ffmpegAvailable) {
    $logger('MP4 erzeugt: ' . $videoPath);
} else {
    $logger('ffmpeg nicht verfügbar, Video-Test wird übersprungen.');
}

$pathsCfg = [
    'images_sfw'  => $baseTemp . '/library/images_sfw',
    'images_nsfw' => $baseTemp . '/library/images_nsfw',
    'videos_sfw'  => $baseTemp . '/library/videos_sfw',
    'videos_nsfw' => $baseTemp . '/library/videos_nsfw',
];
$scannerCfg = [];
$nsfwThreshold = 0.7;

$videoOk = true;

$logger('Importiere PNG …');
$okImage = sv_import_file($pdo, $imgPath, $baseTemp . '/input', $pathsCfg, $scannerCfg, $nsfwThreshold, $logger);
if (!$okImage) {
    $logger('PNG-Import fehlgeschlagen.');
    $allOk = false;
}

if ($ffmpegAvailable) {
    $logger('Importiere MP4 …');
    $okVideo = sv_import_file($pdo, $videoPath, $baseTemp . '/input', $pathsCfg, $scannerCfg, $nsfwThreshold, $logger);
    if (!$okVideo) {
        $logger('MP4-Import fehlgeschlagen.');
        $allOk = false;
        $videoOk = false;
    }
}

$imgCount = (int)$pdo->query("SELECT COUNT(*) FROM media WHERE type = 'image'")->fetchColumn();
if ($imgCount < 1) {
    $logger('Kein Bild in media gefunden.');
    $allOk = false;
}

if ($ffmpegAvailable) {
    $videoRow = $pdo->query("SELECT id, width, height, duration FROM media WHERE type = 'video' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$videoRow) {
        $logger('Kein Video-Eintrag gefunden.');
        $allOk = false;
        $videoOk = false;
    } else {
        $videoId = (int)$videoRow['id'];
        $widthOk = !empty($videoRow['width']);
        $heightOk = !empty($videoRow['height']);
        $durationOk = !empty($videoRow['duration']);
        if (!($widthOk && $heightOk && $durationOk)) {
            $metaStmt = $pdo->prepare("SELECT meta_key FROM media_meta WHERE media_id = ? AND meta_key IN ('video.width', 'video.height')");
            $metaStmt->execute([$videoId]);
            $metaKeys = $metaStmt->fetchAll(PDO::FETCH_COLUMN);
            if (empty($metaKeys)) {
                $logger('Video-Metadaten fehlen (width/height/duration).');
                $allOk = false;
                $videoOk = false;
            }
        }

        if ($videoOk) {
            $thumbCache = $baseTemp . '/cache/thumbs/video_test.jpg';
            $thumbGen = sv_render_video_thumbnail(
                (string)$pdo->query("SELECT path FROM media WHERE id = {$videoId}")->fetchColumn(),
                $thumbCache,
                $ffmpeg,
                isset($videoRow['duration']) ? (float)$videoRow['duration'] : null,
                $logger
            );
            if (!$thumbGen || !is_file($thumbCache) || (int)filesize($thumbCache) <= 0) {
                $logger('Video-Thumbnail konnte nicht erzeugt werden.');
                $allOk = false;
            } else {
                $logger('Video-Thumbnail OK: ' . $thumbCache);
            }
        }
    }
}

// Tagging-Parser-Checks
$fixtureDotted = [
    'modules.nsfw_scanner' => ['hentai' => 0.9],
    'modules.tagging' => ['tags' => [['label' => 'sunset', 'confidence' => 0.8]]],
];
$fixtureNested = [
    'modules' => [
        'nsfw_scanner' => ['porn' => 0.5],
        'tagging' => [
            'tags' => [
                ['label' => 'tree', 'score' => 0.6],
                'mountain',
            ],
        ],
    ],
];

$parsedDotted = sv_interpret_scanner_response($fixtureDotted, $logger);
$parsedNested = sv_interpret_scanner_response($fixtureNested, $logger);

if (($parsedDotted['tags'] ?? []) === [] || $parsedDotted['nsfw_score'] === null) {
    $logger('Parser Dotted fehlgeschlagen.');
    $allOk = false;
}
if (($parsedNested['tags'] ?? []) === [] || $parsedNested['nsfw_score'] === null) {
    $logger('Parser Nested fehlgeschlagen.');
    $allOk = false;
}

$ffmpegMissing = !$ffmpegAvailable;
if ($allOk && !$ffmpegMissing) {
    $logger('Selftest erfolgreich.');
    exit(0);
}

if ($ffmpegMissing && $allOk) {
    $logger('Selftest teilweise erfolgreich (Video-Teil übersprungen).');
    exit(2);
}

exit(1);

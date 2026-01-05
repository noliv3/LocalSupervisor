<?php
declare(strict_types=1);

require_once __DIR__ . '/../SCRIPTS/common.php';
require_once __DIR__ . '/../SCRIPTS/security.php';
require_once __DIR__ . '/../SCRIPTS/operations.php';
require_once __DIR__ . '/../SCRIPTS/paths.php';

try {
    $config = sv_load_config();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<pre>CONFIG-Fehler: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
    exit;
}

$configWarning     = $config['_config_warning'] ?? null;
$hasInternalAccess = sv_validate_internal_access($config, 'media_view', false);

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

$ajaxAction = isset($_GET['ajax']) && is_string($_GET['ajax']) ? trim((string)$_GET['ajax']) : null;
if ($ajaxAction === 'forge_jobs') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $jobs = sv_fetch_forge_jobs_for_media($pdo, $id, 10, $config);
        echo json_encode([
            'server_time' => date('c'),
            'jobs'        => $jobs,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
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
                $overrides = [];

                $modeValue = $_POST['_sv_mode'] ?? '';
                if (is_array($modeValue)) {
                    $modeValue = end($modeValue) ?: '';
                }
                $modeRaw = is_string($modeValue) ? trim($modeValue) : '';
                if ($modeRaw !== '') {
                    $overrides['_sv_mode'] = $modeRaw;
                }

                $manualPrompt  = sv_limit_string((string)($_POST['_sv_manual_prompt'] ?? ''), 2000);
                $manualNegRaw  = sv_limit_string((string)($_POST['_sv_manual_negative'] ?? ''), 2000);
                if ($manualPrompt !== '') {
                    $overrides['_sv_manual_prompt'] = $manualPrompt;
                    $overrides['manual_prompt']      = $manualPrompt; // kompatibel zum bestehenden Pfad
                }
                if (array_key_exists('_sv_manual_negative', $_POST)) {
                    $overrides['_sv_manual_negative'] = $manualNegRaw;
                    $overrides['manual_negative_prompt'] = $manualNegRaw;
                }

                if (!empty($_POST['_sv_use_hybrid'])) {
                    $overrides['use_hybrid'] = true;
                }

                if (!empty($_POST['_sv_negative_allow_empty'])) {
                    $overrides['_sv_negative_allow_empty'] = true;
                    $overrides['allow_empty_negative']     = true;
                }

                $seedRaw = isset($_POST['_sv_seed']) ? trim((string)$_POST['_sv_seed']) : '';
                if ($seedRaw !== '' && is_numeric($seedRaw)) {
                    $overrides['_sv_seed'] = (string)$seedRaw;
                    $overrides['seed']     = $seedRaw;
                }

                $stepsRaw = isset($_POST['_sv_steps']) ? trim((string)$_POST['_sv_steps']) : '';
                if ($stepsRaw !== '' && ctype_digit($stepsRaw)) {
                    $overrides['_sv_steps'] = $stepsRaw;
                    $overrides['steps']     = (int)$stepsRaw;
                }

                $denoiseRaw = isset($_POST['_sv_denoise']) ? trim((string)$_POST['_sv_denoise']) : '';
                if ($denoiseRaw !== '' && is_numeric($denoiseRaw)) {
                    $overrides['_sv_denoise'] = $denoiseRaw;
                    $overrides['denoising_strength'] = (float)$denoiseRaw;
                }

                $samplerRaw = sv_limit_string((string)($_POST['_sv_sampler'] ?? ''), 100);
                if ($samplerRaw !== '') {
                    $overrides['_sv_sampler'] = $samplerRaw;
                    $overrides['sampler']     = $samplerRaw;
                }

                $schedulerRaw = sv_limit_string((string)($_POST['_sv_scheduler'] ?? ''), 100);
                if ($schedulerRaw !== '') {
                    $overrides['_sv_scheduler'] = $schedulerRaw;
                    $overrides['scheduler']     = $schedulerRaw;
                }

                $modelRaw = sv_limit_string((string)($_POST['_sv_model'] ?? ''), 200);
                if ($modelRaw !== '') {
                    $overrides['_sv_model'] = $modelRaw;
                    $overrides['model']     = $modelRaw;
                }

                $result = sv_run_forge_regen_replace($pdo, $config, $id, $logger, $overrides);
                $actionSuccess = true;
                $jobId = (int)($result['job_id'] ?? 0);
                $actionMessage = 'Forge-Regenerations-Job #' . $jobId . ' wurde in die Warteschlange gestellt.';
                if (!empty($result['resolved_model'])) {
                    $actionMessage .= ' Modell: ' . htmlspecialchars((string)$result['resolved_model'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '.';
                }
                if (!empty($result['regen_plan']['fallback_used'])) {
                    $actionMessage .= ' Hinweis: Prompt-Fallback angewendet.';
                }
                if (!empty($result['regen_plan']['tag_prompt_used'])) {
                    $actionMessage .= ' Tag-basierte Rekonstruktion aktiv.';
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
$versions = sv_get_media_versions($pdo, $id);

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
$promptQuality = sv_analyze_prompt_quality($prompt, $tags);
$promptQualityIssues = array_slice($promptQuality['issues'] ?? [], 0, 3);

$type = (string)$media['type'];
$isMissing = (int)($media['is_missing'] ?? 0) === 1 || (string)($media['status'] ?? '') === 'missing';
$hasFileIssue = array_reduce($mediaIssues, static function (bool $carry, array $issue): bool {
    return $carry || ((string)($issue['type'] ?? '') === 'file');
}, false);
$showForgeButton = $type === 'image';
$forgeInfoNotes = [];
if (!$hasInternalAccess) {
    $forgeInfoNotes[] = 'Internal-Key erforderlich; ohne gültigen Key schlägt der Request fehl.';
}
if ($isMissing) {
    $forgeInfoNotes[] = 'Medium ist als missing markiert; der Worker versucht dennoch eine Regeneration.';
}
if ($hasFileIssue) {
    $forgeInfoNotes[] = 'Auffälligkeiten beim Dateipfad/Konsistenz vorhanden – Worker kann scheitern.';
}
if (!$consistencyStatus['prompt_complete']) {
    $forgeInfoNotes[] = 'Prompt unvollständig; Fallback/Tag-Rebuild greift in operations.php.';
}
$thumbUrl = 'thumb.php?' . http_build_query(['id' => $id, 'adult' => $showAdult ? '1' : '0']);
$thumbVersion = (string)($media['hash'] ?? '');
if ($thumbVersion === '' && is_file((string)($media['path'] ?? ''))) {
    $thumbVersion = (string)@filemtime((string)$media['path']);
}
if ($thumbVersion !== '') {
    $thumbUrl .= (strpos($thumbUrl, '?') !== false ? '&' : '?') . 'v=' . rawurlencode($thumbVersion);
}

$latestPreview = null;
$latestJobResponse = null;
$latestJobRequest = null;
$latestJobStmt = $pdo->prepare('SELECT id, status, forge_response_json, forge_request_json FROM jobs WHERE type = :type AND media_id = :media_id ORDER BY id DESC LIMIT 1');
$latestJobStmt->execute([':type' => SV_FORGE_JOB_TYPE, ':media_id' => $id]);
$latestJobRow = $latestJobStmt->fetch(PDO::FETCH_ASSOC);
if ($latestJobRow) {
    $latestJobResponse = json_decode((string)($latestJobRow['forge_response_json'] ?? ''), true) ?: [];
    $latestJobRequest  = json_decode((string)($latestJobRow['forge_request_json'] ?? ''), true) ?: [];

    $responseMode = (string)($latestJobResponse['mode'] ?? $latestJobRequest['_sv_mode'] ?? '');
    $responseResult = is_array($latestJobResponse['result'] ?? null) ? $latestJobResponse['result'] : [];
    $newHash = $responseResult['new_hash'] ?? null;
    if ($responseMode === 'replace' && $newHash) {
        $thumbUrl .= (strpos($thumbUrl, '?') !== false ? '&' : '?') . 'v=' . rawurlencode((string)$newHash);
    }

    if ((string)($latestJobRow['status'] ?? '') === 'done' && $responseMode === 'preview') {
        $previewPath = (string)($latestJobResponse['preview_path'] ?? '');
        $previewAllowed = false;
        $previewDataUri = null;
        $previewError   = null;
        $previewVersion = $latestJobResponse['preview_hash'] ?? null;
        if ($previewPath !== '') {
            try {
                sv_assert_media_path_allowed($previewPath, $config['paths'] ?? [], 'forge_preview');
                $previewAllowed = true;
            } catch (Throwable $e) {
                $previewError = $e->getMessage();
            }

            if ($previewAllowed && is_file($previewPath)) {
                if ($previewVersion === null) {
                    $previewVersion = @filemtime($previewPath) ?: null;
                }
                $maxSize = 6 * 1024 * 1024;
                $fileSize = (int)@filesize($previewPath);
                if ($fileSize > 0 && $fileSize <= $maxSize) {
                    $raw = @file_get_contents($previewPath);
                    if ($raw !== false) {
                        $mime = @mime_content_type($previewPath) ?: 'image/jpeg';
                        $previewDataUri = 'data:' . $mime . ';base64,' . base64_encode($raw);
                    }
                } else {
                    $previewError = $fileSize > $maxSize ? 'Preview >6MB, nicht inline geladen.' : null;
        }
    }
}
$manualOverrideActive = false;
if (is_array($latestJobRequest)) {
    $manualOverrideActive = (($latestJobRequest['_sv_prompt_source'] ?? '') === 'manual')
        || (!empty($latestJobRequest['_sv_regen_plan']['manual_prompt']));
}

        $latestPreview = [
            'path'     => $previewPath,
            'hash'     => $latestJobResponse['preview_hash'] ?? null,
            'width'    => $latestJobResponse['preview_width'] ?? null,
            'height'   => $latestJobResponse['preview_height'] ?? null,
            'filesize' => $latestJobResponse['preview_filesize'] ?? null,
            'allowed'  => $previewAllowed,
            'data_uri' => $previewDataUri,
            'error'    => $previewError,
            'version'  => $previewVersion,
            'path'     => ($previewVersion !== null && $previewPath !== '')
                ? ($previewPath . '?v=' . rawurlencode((string)$previewVersion))
                : $previewPath,
        ];
    }
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Media #<?= (int)$id ?></title>
    <link rel="stylesheet" href="mediadb.css">
</head>
<body class="media-view-body" id="media-top">

<div class="page-shell">
    <div class="top-nav">
        <a class="nav-link" href="<?= htmlspecialchars($backLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">« Übersicht</a>
        <div class="nav-spacer"></div>
        <?php if ($prevId !== false): ?>
            <a class="nav-link" href="media_view.php?<?= http_build_query(array_merge($filteredParams, ['id' => (int)$prevId])) ?>">« Vorheriges</a>
        <?php endif; ?>
        <?php if ($nextId !== false): ?>
            <a class="nav-link" href="media_view.php?<?= http_build_query(array_merge($filteredParams, ['id' => (int)$nextId])) ?>">Nächstes »</a>
        <?php endif; ?>
    </div>

    <?php if (!empty($configWarning)): ?>
        <div class="alert-warning" style="margin: 0.5rem 0; padding: 0.6rem 0.8rem; background: #fff3cd; color: #7f4e00; border: 1px solid #ffeeba;">
            <?= htmlspecialchars($configWarning, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <header class="media-header">
        <div class="title-wrap">
            <h1>Media #<?= (int)$id ?></h1>
            <div class="subtitle">Typ: <?= htmlspecialchars($type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        </div>
        <div class="header-info">
            <span class="pill">FSK18: <?= (int)($media['has_nsfw'] ?? 0) === 1 ? 'ja' : 'nein' ?></span>
            <span class="pill">Status: <?= htmlspecialchars((string)($media['status'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        </div>
    </header>

    <?php
    $promptBadgeClass = $consistencyStatus['prompt_complete'] ? 'ok' : ($consistencyStatus['prompt_present'] ? 'warn' : 'error');
    $promptLabel = $consistencyStatus['prompt_complete']
        ? 'Prompt vollständig'
        : ($consistencyStatus['prompt_present'] ? 'Prompt unvollständig' : 'Prompt fehlt');

    $tagBadgeClass  = $consistencyStatus['has_tags'] ? 'ok' : 'error';
    $tagLabel       = $consistencyStatus['has_tags'] ? 'Tags vorhanden' : 'Keine Tags';

    $metaBadgeClass = $consistencyStatus['has_meta'] ? 'ok' : 'warn';
    $metaLabel      = $consistencyStatus['has_meta'] ? 'Metadaten vorhanden' : 'Metadaten fehlen';
    $forgeDisabled  = !$showForgeButton;
    $forgeReason    = $showForgeButton ? null : 'nur images';
    $rebuildDisabled = !$showRebuildButton;
    $rebuildReason   = $showRebuildButton ? null : 'Prompt vollständig';
    $overrideDisabled = !$showForgeButton && !$promptExists;
    $overrideReason   = $overrideDisabled ? 'kein Bild/Prompt' : null;
    ?>

    <div class="media-main-grid">
        <div class="media-left">
            <div class="panel media-visual" id="visual">
                <?php if ($type === 'image'): ?>
                    <div class="preview-grid">
                        <div class="preview-card">
                            <div class="preview-label original">ORIGINAL</div>
                            <div class="preview-frame">
                                <img id="media-preview-thumb" src="<?= htmlspecialchars($thumbUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="Vorschau">
                            </div>
                        </div>
                        <?php if ($latestPreview !== null): ?>
                            <div class="preview-card">
                                <div class="preview-label preview">PREVIEW</div>
                                <?php if ($latestPreview['data_uri']): ?>
                                    <div class="preview-frame">
                                        <img src="<?= htmlspecialchars((string)$latestPreview['data_uri'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="Preview">
                                    </div>
                                <?php else: ?>
                                    <div class="preview-placeholder">
                                        <div class="placeholder-title">Keine Inline-Vorschau</div>
                                        <?php if ($latestPreview['path'] !== ''): ?>
                                            <div class="placeholder-meta">Pfad: <?= htmlspecialchars((string)$latestPreview['path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                        <?php if ($latestPreview['error']): ?>
                                            <div class="placeholder-meta">Hinweis: <?= htmlspecialchars((string)$latestPreview['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                        <?php elseif (!$latestPreview['allowed']): ?>
                                            <div class="placeholder-meta">Preview nicht streambar, Root nicht erlaubt.</div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="preview-meta">
                                    <span><?= htmlspecialchars((string)($latestPreview['width'] ?? '–'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> × <?= htmlspecialchars((string)($latestPreview['height'] ?? '–'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                    <span>Hash: <?= htmlspecialchars((string)($latestPreview['hash'] ?? '–'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                    <?php if ($latestPreview['filesize']): ?><span><?= htmlspecialchars((string)$latestPreview['filesize'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> bytes</span><?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="preview-grid">
                        <div class="preview-card">
                            <div class="preview-label original">THUMB</div>
                            <div class="preview-frame">
                                <img id="media-preview-thumb" src="<?= htmlspecialchars($thumbUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="Video-Thumbnail">
                            </div>
                        </div>
                        <div class="preview-card">
                            <div class="preview-label preview">PLAYER</div>
                            <div class="preview-frame">
                                <video controls preload="metadata" width="100%" src="media_stream.php?<?= http_build_query(['id' => $id, 'adult' => $showAdult ? '1' : '0']) ?>"></video>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="media-infobar">
                    <span class="pill">ID: <?= (int)$media['id'] ?></span>
                    <span class="pill">Typ: <?= htmlspecialchars((string)$media['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <span class="pill">Auflösung: <?= htmlspecialchars((string)($media['width'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> × <?= htmlspecialchars((string)($media['height'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <?php if (!empty($media['duration'])): ?><span class="pill">Dauer: <?= htmlspecialchars(number_format((float)$media['duration'], 1), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>s</span><?php endif; ?>
                    <?php if (!empty($media['fps'])): ?><span class="pill">FPS: <?= htmlspecialchars((string)$media['fps'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
                    <?php if (!empty($media['filesize'])): ?><span class="pill">Size: <?= htmlspecialchars((string)$media['filesize'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> bytes</span><?php endif; ?>
                    <span class="pill">Status: <?= htmlspecialchars((string)($media['status'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                </div>
            </div>
        </div>

        <div class="media-right">
            <div class="panel action-panel">
                <div class="panel-header">Aktionen</div>
                <?php if ($actionMessage !== null): ?>
                    <div class="action-feedback <?= $actionSuccess ? 'success' : 'error' ?>">
                        <div class="action-feedback-title"><?= $actionSuccess ? 'OK' : 'Fehler' ?></div>
                        <div><?= htmlspecialchars($actionMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                        <?php if ($actionLogFile): ?>
                            <div class="action-logfile">Logdatei: <?= htmlspecialchars((string)$actionLogFile, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <?php if ($actionLogs !== []): ?>
                            <details class="action-logdetails">
                                <summary>Details</summary>
                                <pre><?= htmlspecialchars(implode("\n", $actionLogs), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
                            </details>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form id="forge-form" class="forge-control" method="post">
                    <input type="hidden" name="media_id" value="<?= (int)$id ?>">
                    <input type="hidden" name="action" value="forge_regen">
                    <div class="forge-grid">
                        <div class="form-block">
                            <div class="label-row">Mode</div>
                            <div class="radio-row">
                                <label><input type="radio" name="_sv_mode" value="preview" checked> Preview (Standard)</label>
                                <label><input type="radio" name="_sv_mode" value="replace"> Replace sofort</label>
                            </div>
                            <div class="hint">Replace schreibt sofort zurück, Preview bleibt isoliert.</div>
                        </div>
                        <div class="form-block">
                            <div class="label-row">Prompt Quelle</div>
                            <div class="prompt-chip">Effektiv: <?= htmlspecialchars($promptText !== '' ? sv_meta_value($promptText, 160) : 'Kein Prompt', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                            <?php if ($manualOverrideActive): ?>
                                <div class="action-note highlight">Manual override aktiv (zuletzt genutzter Prompt).</div>
                            <?php endif; ?>
                            <textarea class="prompt-input compact" name="_sv_manual_prompt" maxlength="2000" placeholder="Optionaler manueller Prompt"></textarea>
                        </div>
                        <div class="form-block">
                            <div class="label-row">Negativer Prompt</div>
                            <textarea class="prompt-input compact" name="_sv_manual_negative" maxlength="2000" placeholder="Optionaler negativer Prompt"></textarea>
                            <label class="checkbox-inline"><input type="checkbox" name="_sv_negative_allow_empty" value="1"> Leeren negativen Prompt erlauben</label>
                        </div>
                        <div class="form-block two-col">
                            <label>Seed (optional)<input type="number" name="_sv_seed" min="0" step="1" placeholder="Auto"></label>
                            <label>Steps<input type="number" name="_sv_steps" min="1" max="150" step="1" placeholder="auto"></label>
                        </div>
                        <div class="form-block two-col">
                            <label>Denoise<input type="number" name="_sv_denoise" min="0" max="1" step="0.01" placeholder="auto"></label>
                            <label>Sampler
                                <select name="_sv_sampler">
                                    <option value="">Auto</option>
                                    <option value="DPM++ 2M Karras">DPM++ 2M Karras</option>
                                    <option value="Euler a">Euler a</option>
                                    <option value="DPM++ SDE Karras">DPM++ SDE Karras</option>
                                </select>
                            </label>
                        </div>
                        <div class="form-block two-col">
                            <label>Scheduler
                                <select name="_sv_scheduler">
                                    <option value="">Auto</option>
                                    <option value="Karras">Karras</option>
                                    <option value="Normal">Normal</option>
                                    <option value="Exponential">Exponential</option>
                                </select>
                            </label>
                            <label>Model Override<input type="text" name="_sv_model" maxlength="200" placeholder="leer = Default/Fallback"></label>
                        </div>
                        <div class="form-block">
                            <label class="checkbox-inline"><input type="checkbox" name="_sv_use_hybrid" value="1"> Hybrid (Prompt + Tags)</label>
                        </div>
                    </div>
                    <div class="button-stack inline">
                        <button class="btn primary" type="submit" name="_sv_mode" value="preview" <?= $forgeDisabled ? 'disabled' : '' ?>>Preview starten</button>
                        <button class="btn danger" type="submit" name="_sv_mode" value="replace" <?= $forgeDisabled ? 'disabled' : '' ?>>Replace sofort</button>
                    </div>
                </form>

                <div class="button-stack">
                    <button class="btn secondary" type="button" <?= $overrideDisabled ? 'disabled' : '' ?> onclick="document.getElementById('prompt-panel')?.scrollIntoView({ behavior: 'smooth' });">Prompt bearbeiten</button>
                    <?php if ($overrideReason): ?><div class="btn-reason"><?= htmlspecialchars($overrideReason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>

                    <button class="btn muted" type="submit" form="rebuild-form" <?= $rebuildDisabled ? 'disabled' : '' ?>>Prompt neu aufbauen</button>
                    <?php if ($rebuildReason): ?><div class="btn-reason"><?= htmlspecialchars($rebuildReason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>

                    <button class="btn danger" type="submit" form="missing-form">Missing markieren</button>
                    <div class="btn-reason">Status wird nur auf missing gesetzt.</div>
                </div>
                <?php if ($forgeInfoNotes !== []): ?>
                    <div class="action-note">Hinweise: <?= htmlspecialchars(implode(' ', $forgeInfoNotes), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <?php endif; ?>
                <div class="action-note">Alle Aktionen nutzen die bestehende Internal-Key/IP-Prüfung.</div>
            </div>

            <div class="panel status-panel">
                <div class="panel-header">Status</div>
                <div class="status-badges">
                    <span class="status-pill <?= htmlspecialchars($promptBadgeClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($promptLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <span class="status-pill <?= htmlspecialchars($tagBadgeClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($tagLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <span class="status-pill <?= htmlspecialchars($metaBadgeClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($metaLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                </div>
                <div class="quality-row">
                    <span class="quality-pill">Prompt-Qualität: <strong><?= htmlspecialchars((string)$promptQuality['quality_class'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></span>
                    <span class="quality-score">Score <?= (int)$promptQuality['score'] ?></span>
                </div>
                <?php if ($mediaIssues !== []): ?>
                    <div class="issues-list">
                        <div class="issues-title">Issues (<?= count($mediaIssues) ?>)</div>
                        <ul>
                            <?php foreach (array_slice($mediaIssues, 0, 3) as $issue): ?>
                                <li><span class="issue-type"><?= htmlspecialchars(ucfirst((string)$issue['type']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span> <?= htmlspecialchars((string)$issue['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if (count($mediaIssues) > 3): ?>
                            <details>
                                <summary>Mehr anzeigen</summary>
                                <ul>
                                    <?php foreach ($mediaIssues as $issue): ?>
                                        <li><span class="issue-type"><?= htmlspecialchars(ucfirst((string)$issue['type']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span> <?= htmlspecialchars((string)$issue['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="panel forge-panel">
                <div class="panel-header">Forge-Jobs</div>
                <div id="forge-jobs" class="timeline">
                    <div class="job-hint">Jobs werden geladen …</div>
                </div>
                <div class="job-hint">Automatische Aktualisierung aktiv.</div>
            </div>

            <div class="panel versions-panel">
                <div class="panel-header">Versionen</div>
                <?php if ($versions === []): ?>
                    <div class="job-hint">Keine Versionsdaten verfügbar.</div>
                <?php else: ?>
                    <div class="version-grid">
                        <?php foreach ($versions as $version):
                            $versionToken = (string)($version['version_token'] ?? ($version['timestamp'] ?? $version['version_index']));
                            $versionCache = $thumbUrl . (strpos($thumbUrl, '?') !== false ? '&' : '?') . 'v=' . rawurlencode($versionToken);
                            $versionLink = 'media_view.php?' . http_build_query(array_merge($filteredParams, ['id' => (int)$id, 'v' => $versionToken])) . '#visual';
                            ?>
                            <a class="version-tile<?= !empty($version['is_current']) ? ' current' : '' ?>" href="<?= htmlspecialchars($versionLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                <div class="version-thumb">
                                    <img src="<?= htmlspecialchars($versionCache, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="Version <?= (int)$version['version_index'] ?>">
                                </div>
                                <div class="version-label">V<?= (int)$version['version_index'] ?> <?= !empty($version['is_current']) ? '· aktuell' : '' ?></div>
                                <div class="version-meta-small">Modell: <?= htmlspecialchars((string)($version['model_used'] ?? $version['model_requested'] ?? '–'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <details class="panel collapsible" open id="prompt-panel">
        <summary>Prompt &amp; Negative Prompt</summary>
        <div class="tab-bar" role="tablist">
            <button class="tab-button active" data-tab="tab-effective">Effektiv</button>
            <button class="tab-button" data-tab="tab-manual" <?= $overrideDisabled ? 'disabled' : '' ?>>Manuell</button>
            <button class="tab-button" data-tab="tab-tags">Tags</button>
        </div>
        <div class="tab-content active" id="tab-effective">
            <div class="label-row">Effektiver Prompt</div>
            <textarea readonly class="prompt-viewer"><?= htmlspecialchars($promptText ?: 'Kein Prompt gespeichert.', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
            <div class="label-row">Negativer Prompt</div>
            <textarea readonly class="prompt-viewer"><?= htmlspecialchars($negativePrompt ?: '–', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
            <?php if ($promptParams !== []): ?>
                <div class="prompt-params">
                    <?php foreach ($promptParams as $label => $value): ?>
                        <div class="param-row"><span><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><strong><?= htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="tab-content" id="tab-manual">
            <div class="label-row">Manueller Prompt (optional)</div>
            <textarea class="prompt-input" name="_sv_manual_prompt" form="forge-form" maxlength="2000" placeholder="Manueller Prompt eintragen"></textarea>
            <div class="label-row">Negativer Prompt (optional)</div>
            <textarea class="prompt-input" name="_sv_manual_negative" form="forge-form" maxlength="2000" placeholder="Negativer Prompt oder leer lassen"></textarea>
            <div class="checkbox-row">
                <label><input type="checkbox" name="_sv_use_hybrid" value="1" form="forge-form"> Hybrid (Prompt + Tags)</label>
                <label><input type="checkbox" name="_sv_negative_allow_empty" value="1" form="forge-form"> Leeren negativen Prompt erlauben</label>
            </div>
            <div class="tab-hint">Absenden über „Forge Regen“.</div>
        </div>
        <div class="tab-content" id="tab-tags">
            <?php if ($tags === []): ?>
                <div class="tab-hint">Keine Tags vorhanden.</div>
            <?php else: ?>
                <div class="chip-list">
                    <?php foreach ($tags as $tag):
                        $tagType = preg_replace('~[^a-z0-9_-]+~i', '', (string)($tag['type'] ?? 'other')) ?: 'other';
                        $conf = isset($tag['confidence']) ? number_format((float)$tag['confidence'], 2) : null;
                        ?>
                        <span class="chip tag-type-<?= htmlspecialchars($tagType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                            <?= htmlspecialchars((string)$tag['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= $conf !== null ? ' (' . htmlspecialchars($conf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ')' : '' ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </details>

    <details class="panel collapsible" id="tags-panel">
        <summary>Tags</summary>
        <?php if ($tags === []): ?>
            <div class="tab-hint">Keine Tags gespeichert.</div>
        <?php else: ?>
            <div class="chip-list limited" data-limit="30">
                <?php foreach ($tags as $tag):
                    $tagType = preg_replace('~[^a-z0-9_-]+~i', '', (string)($tag['type'] ?? 'other')) ?: 'other';
                    $conf = isset($tag['confidence']) ? number_format((float)$tag['confidence'], 2) : null;
                    ?>
                    <span class="chip tag-type-<?= htmlspecialchars($tagType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <?= htmlspecialchars((string)$tag['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= $conf !== null ? ' (' . htmlspecialchars($conf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ')' : '' ?>
                    </span>
                <?php endforeach; ?>
            </div>
            <?php if (count($tags) > 30): ?><div class="tab-hint">Weitere Tags per „Mehr anzeigen“ sichtbar.</div><?php endif; ?>
        <?php endif; ?>
    </details>

    <details class="panel collapsible" id="meta-panel">
        <summary>Meta</summary>
        <?php if ($groupedMeta === []): ?>
            <div class="tab-hint">Keine Einträge vorhanden.</div>
        <?php else: ?>
            <?php foreach ($groupedMeta as $source => $entries): ?>
                <div class="meta-section">
                    <div class="meta-title">[<?= htmlspecialchars((string)$source, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>]</div>
                    <div class="meta-grid">
                        <?php foreach ($entries as $entry): ?>
                            <div class="meta-row"><span><?= htmlspecialchars((string)$entry['key'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><strong><?= htmlspecialchars(sv_meta_value($entry['value']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </details>

    <details class="panel collapsible" id="logs-panel">
        <summary>Logs</summary>
        <?php if ($actionLogs === []): ?>
            <div class="tab-hint">Keine aktuellen Logeinträge.</div>
        <?php else: ?>
            <pre class="log-viewer"><?= htmlspecialchars(implode("\n", $actionLogs), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
        <?php endif; ?>
    </details>

    <form id="rebuild-form" method="post">
        <input type="hidden" name="media_id" value="<?= (int)$id ?>">
        <input type="hidden" name="action" value="rebuild_prompt">
    </form>
    <form id="missing-form" method="post" onsubmit="return confirm('Medium als missing markieren? Dateien bleiben erhalten.');">
        <input type="hidden" name="media_id" value="<?= (int)$id ?>">
        <input type="hidden" name="action" value="logical_delete">
    </form>
</div>

<script>
(function() {
    const container = document.getElementById('forge-jobs');
    if (!container) return;
    const endpoint = 'media_view.php?<?= http_build_query(array_merge($filteredParams, ['id' => (int)$id, 'ajax' => 'forge_jobs'])) ?>';
    const thumbUrl = <?= json_encode($thumbUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    function statusClass(status) {
        if (!status) return 'status-queued';
        const normalized = status.toLowerCase();
        if (['queued', 'running', 'done', 'error'].includes(normalized)) {
            return 'status-' + normalized;
        }
        return 'status-queued';
    }

    function renderJobs(jobs) {
        if (!jobs || jobs.length === 0) {
            container.innerHTML = '<div class="job-hint">Keine Forge-Jobs vorhanden.</div>';
            return;
        }
        container.innerHTML = '';
        jobs.forEach((job) => {
            const item = document.createElement('div');
            item.className = 'timeline-item';
            const cacheBust = job.version_token || job.updated_at || job.id;
            const thumbSrc = job.replaced ? (thumbUrl + (thumbUrl.includes('?') ? '&' : '?') + 'v=' + encodeURIComponent(cacheBust)) : thumbUrl;
            const samplerLabel = (job.used_sampler || '–') + ' / ' + (job.used_scheduler || '–');
            const decidedMode = job.decided_mode || job.generation_mode || job.mode || 'txt2img';
            const decidedReason = job.decided_reason || '–';
            const promptSource = job.used_prompt_source || job.prompt_source || '–';
            const negativeSource = job.used_negative_source || job.negative_mode || '–';
            const usedSeed = job.used_seed || job.seed || '–';
            const denoise = (job.decided_denoise !== null && job.decided_denoise !== undefined)
                ? job.decided_denoise
                : (job.denoise !== undefined ? job.denoise : '–');
            const modelLine = (job.model || '–') + (job.fallback_model ? ' (Fallback)' : '');
            const formatLine = (job.orig_w || '–') + '×' + (job.orig_h || '–') + ' ' + (job.orig_ext || '–') + ' → ' + (job.out_w || '–') + '×' + (job.out_h || '–') + ' ' + (job.out_ext || '–');
            const attemptLine = job.attempt_index ? ('Attempt ' + job.attempt_index + '/' + (job.attempt_chain ? job.attempt_chain.length : 3)) : 'Attempt –';
            const errorBlock = job.error ? '<div class="job-error">' + job.error + '</div>' : '';
            const detailsId = 'job-details-' + job.id;

            item.innerHTML = '
                <div class="timeline-header">
                    <div class="timeline-title">Job #' + job.id + '</div>
                    <span class="status-badge ' + statusClass(job.status) + '">' + (job.status || 'queued') + '</span>
                </div>
                <div class="timeline-meta">' + (job.created_at || '–') + ' • ' + (job.updated_at || '–') + '</div>
                <div class="timeline-body">
                    <div class="meta-line"><span>Mode</span><strong>' + decidedMode + ' (' + (job.mode || 'preview') + ')</strong><em class="small">' + decidedReason + '</em></div>
                    <div class="meta-line"><span>Seed/Denoise</span><strong>' + usedSeed + ' / ' + ((denoise === undefined || denoise === null) ? '–' : denoise) + '</strong></div>
                    <div class="meta-line"><span>Model</span><strong>' + modelLine + '</strong></div>
                    <div class="meta-line"><span>Sampler/Scheduler</span><strong>' + samplerLabel + ' · ' + attemptLine + '</strong></div>
                    <div class="meta-line"><span>Format</span><strong>' + formatLine + ' [' + ((job.format_preserved === null) ? '–' : (job.format_preserved ? '1:1' : 'konvertiert')) + ']</strong></div>
                    <div class="meta-line"><span>Prompt</span><strong>' + promptSource + '</strong></div>
                    <div class="meta-line"><span>Negativ</span><strong>' + negativeSource + '</strong></div>
                    <div class="meta-line"><span>Output</span><strong>' + (job.output_path || '–') + '</strong></div>
                    <div class="meta-line"><span>Version</span><strong>' + (job.version_token || cacheBust || '–') + '</strong></div>
                    ' + errorBlock + '
                </div>
                <details class="timeline-details" id="' + detailsId + '">
                    <summary>Details</summary>
                    <div class="meta-line"><span>Hash</span><strong>' + (job.old_hash || '–') + ' → ' + (job.new_hash || '–') + '</strong></div>
                    <div class="meta-line"><span>Request</span><strong>' + (job.request_snippet || '–') + '</strong></div>
                    <div class="meta-line"><span>Response</span><strong>' + (job.response_snippet || '–') + '</strong></div>
                </details>
            ';

            if (job.replaced) {
                const preview = document.getElementById('media-preview-thumb');
                if (preview) {
                    preview.src = thumbSrc;
                }
            }
            container.appendChild(item);
        });
    }

    const activeStatuses = ['queued', 'pending', 'created', 'running'];
    let pollTimer = null;

    function renderError(message) {
        container.innerHTML = '<div class="job-hint error">' + message + '</div>';
    }

    function scheduleNext(active) {
        if (pollTimer) {
            clearTimeout(pollTimer);
        }
        if (active) {
            pollTimer = setTimeout(loadJobs, 2000);
        }
    }

    function loadJobs() {
        fetch(endpoint, { headers: { 'Accept': 'application/json' } })
            .then((resp) => {
                if (!resp.ok) {
                    throw new Error('HTTP ' + resp.status);
                }
                return resp.json();
            })
            .then((data) => {
                const jobs = data.jobs || [];
                renderJobs(jobs);
                const active = jobs.some((job) => activeStatuses.includes((job.status || '').toLowerCase()));
                scheduleNext(active);
            })
            .catch((err) => {
                renderError('Job-Status konnte nicht geladen werden (' + err.message + ').');
                scheduleNext(true);
            });
    }

    const forgeForm = document.getElementById('forge-form');
    if (forgeForm) {
        forgeForm.addEventListener('submit', () => {
            if (pollTimer) {
                clearTimeout(pollTimer);
            }
            pollTimer = setTimeout(loadJobs, 2000);
        });
    }

    loadJobs();
})();

(function() {
    const inputs = document.querySelectorAll('textarea[name="_sv_manual_prompt"]');
    inputs.forEach((el) => {
        const indicator = document.createElement('div');
        indicator.className = 'action-note highlight manual-indicator';
        indicator.textContent = 'Manual override aktiv';
        indicator.style.display = el.value.trim() ? 'block' : 'none';
        if (el.parentElement) {
            el.parentElement.appendChild(indicator);
        }
        const update = () => {
            indicator.style.display = el.value.trim() ? 'block' : 'none';
        };
        el.addEventListener('input', update);
    });
})();

(function() {
    const tabs = document.querySelectorAll('.tab-button');
    const contents = document.querySelectorAll('.tab-content');
    if (!tabs.length || !contents.length) return;

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            if (tab.disabled) return;
            tabs.forEach((t) => t.classList.remove('active'));
            contents.forEach((c) => c.classList.remove('active'));
            tab.classList.add('active');
            const target = document.getElementById(tab.dataset.tab || '');
            if (target) {
                target.classList.add('active');
            }
        });
    });
})();
</script>

</body>
</html>

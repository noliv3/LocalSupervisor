<?php
declare(strict_types=1);

require_once __DIR__ . '/../SCRIPTS/common.php';
require_once __DIR__ . '/../SCRIPTS/paths.php';
require_once __DIR__ . '/../SCRIPTS/security.php';
require_once __DIR__ . '/../SCRIPTS/operations.php';
require_once __DIR__ . '/_layout.php';

try {
    $config = sv_load_config();
} catch (Throwable $e) {
    sv_security_error(500, 'config');
}

$configWarning = $config['_config_warning'] ?? null;
$dsn           = $config['db']['dsn'];
$user          = $config['db']['user']     ?? null;
$password      = $config['db']['password'] ?? null;
$options       = $config['db']['options']  ?? [];
$hasInternalAccess = sv_validate_internal_access($config, 'mediadb', false);

try {
    $pdo = new PDO($dsn, $user, $password, $options);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    sv_security_error(500, 'db');
}

$actionMessage = null;
$actionError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        sv_require_internal_access($config, 'mediadb_action');
        $action = is_string($_POST['action'] ?? null) ? trim($_POST['action']) : '';
        $mediaId = isset($_POST['media_id']) ? (int)$_POST['media_id'] : 0;

        if ($mediaId <= 0) {
            throw new RuntimeException('Ungültige Media-ID.');
        }

        if ($action === 'vote_up' || $action === 'vote_down') {
            $voteValue = $action === 'vote_up' ? 1 : -1;
            sv_set_media_meta_value($pdo, $mediaId, 'vote.state', $voteValue);
            sv_audit_log($pdo, 'vote_set', 'media', $mediaId, [
                'state' => $voteValue,
            ]);
            $actionMessage = $voteValue > 0 ? 'Vote gesetzt: up.' : 'Vote gesetzt: down.';
        } elseif ($action === 'checked_toggle') {
            $checkedValue = isset($_POST['checked_value']) && (string)$_POST['checked_value'] === '1' ? 1 : 0;
            sv_set_media_meta_value($pdo, $mediaId, 'curation.checked', $checkedValue);
            if ($checkedValue === 1) {
                sv_set_media_meta_value($pdo, $mediaId, 'curation.checked_at', time());
            }
            sv_audit_log($pdo, 'curation_checked', 'media', $mediaId, [
                'checked' => $checkedValue,
            ]);
            $actionMessage = $checkedValue === 1 ? 'Checked gesetzt.' : 'Checked entfernt.';
        } elseif ($action === 'rescan_job') {
            $logLines = [];
            $logger = sv_operation_logger(null, $logLines);
            $enqueue = sv_enqueue_rescan_media_job($pdo, $config, $mediaId, $logger);
            $worker  = sv_spawn_scan_worker($config, null, 1, $logger, $mediaId);
            $jobId   = (int)($enqueue['job_id'] ?? 0);
            $deduped = (bool)($enqueue['deduped'] ?? false);
            if ($jobId <= 0) {
                throw new RuntimeException('Rescan-Job konnte nicht angelegt werden.');
            }
            sv_audit_log($pdo, 'rescan_start', 'media', $mediaId, [
                'job_id'     => $jobId,
                'worker_pid' => $worker['pid'] ?? null,
                'deduped'    => $deduped,
            ]);
            if ($deduped) {
                $actionMessage = 'Tag-Rescan-Job #' . $jobId . ' existiert bereits (queued/running).';
            } else {
                $actionMessage = 'Tag-Rescan-Job #' . $jobId . ' eingereiht.';
            }
        } else {
            throw new RuntimeException('Unbekannte Aktion.');
        }
    } catch (Throwable $e) {
        $actionError = sv_sanitize_error_message($e->getMessage());
    }
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

function sv_extract_scan_info($rawJson): array
{
    if (!is_string($rawJson) || trim($rawJson) === '') {
        return [
            'error'        => null,
            'tags_written' => null,
        ];
    }

    $decoded = json_decode($rawJson, true);
    if (!is_array($decoded)) {
        return [
            'error'        => null,
            'tags_written' => null,
        ];
    }

    $meta = is_array($decoded['_meta'] ?? null) ? $decoded['_meta'] : [];

    return [
        'error'        => isset($decoded['_error']) && is_string($decoded['_error']) ? (string)$decoded['_error'] : null,
        'tags_written' => isset($meta['tags_written']) ? (int)$meta['tags_written'] : null,
    ];
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
$curationFilter   = $_GET['curation'] ?? 'all';
$voteFilter       = $_GET['vote'] ?? 'any';
$checkedFilter    = $_GET['checked'] ?? 'any';
$lowActivityFilter = $_GET['low_activity'] ?? 'all';

$allowedTypes     = ['all', 'image', 'video'];
$allowedPrompt    = ['all', 'with', 'without'];
$allowedMeta      = ['all', 'with', 'without'];
$allowedStatus    = ['all', 'active', 'archived', 'deleted', 'missing', 'deleted_logical'];
$allowedIncomplete= ['none', 'prompt', 'tags', 'meta', 'any'];
$allowedQuality   = array_merge(['all'], sv_prompt_quality_values());
$allowedCuration  = array_merge(['all'], sv_quality_status_values());
$allowedVote      = ['any', 'up', 'down'];
$allowedChecked   = ['any', 'checked', 'unchecked'];
$allowedLowActivity = ['all', 'low'];
$typeFilter       = sv_normalize_enum($typeFilter, $allowedTypes, 'all');
$hasPromptFilter  = sv_normalize_enum($hasPromptFilter, $allowedPrompt, 'all');
$hasMetaFilter    = sv_normalize_enum($hasMetaFilter, $allowedMeta, 'all');
$statusFilter     = sv_normalize_enum($statusFilter, $allowedStatus, 'all');
$minRating        = sv_clamp_int($minRating, 0, 3, 0);
$incompleteFilter = sv_normalize_enum($incompleteFilter, $allowedIncomplete, 'none');
$promptQualityFilter = sv_normalize_enum($promptQualityFilter, $allowedQuality, 'all');
$curationFilter   = sv_normalize_enum($curationFilter, $allowedCuration, 'all');
$voteFilter       = sv_normalize_enum($voteFilter, $allowedVote, 'any');
$checkedFilter    = sv_normalize_enum($checkedFilter, $allowedChecked, 'any');
$lowActivityFilter = sv_normalize_enum($lowActivityFilter, $allowedLowActivity, 'all');

if (!$hasInternalAccess) {
    $voteFilter = 'any';
    $checkedFilter = 'any';
    $lowActivityFilter = 'all';
}

$where  = [];
$params = [];

$promptCompleteClause = sv_prompt_core_complete_condition('p');
$latestPromptJoin = 'LEFT JOIN prompts p ON p.id = (SELECT p2.id FROM prompts p2 WHERE p2.media_id = m.id ORDER BY p2.id DESC LIMIT 1)';
$activityNow = time();
$activityClicksSql = "(SELECT CAST(meta_value AS INTEGER) FROM media_meta mm WHERE mm.media_id = m.id AND mm.meta_key = 'activity.clicks' ORDER BY mm.id DESC LIMIT 1)";
$activityLastSql = "(SELECT CAST(meta_value AS INTEGER) FROM media_meta mm WHERE mm.media_id = m.id AND mm.meta_key = 'activity.last_click_at' ORDER BY mm.id DESC LIMIT 1)";
$activityBaseSql = "COALESCE($activityLastSql, CAST(strftime('%s', m.created_at) AS INTEGER), 0)";
$activityScoreSql = "COALESCE($activityClicksSql, 0) - CAST((:activity_now - $activityBaseSql) / 86400 AS INTEGER)";
$voteStateSql = "(SELECT CAST(meta_value AS INTEGER) FROM media_meta mmv WHERE mmv.media_id = m.id AND mmv.meta_key = 'vote.state' ORDER BY mmv.id DESC LIMIT 1)";
$checkedStateSql = "(SELECT CAST(meta_value AS INTEGER) FROM media_meta mmc WHERE mmc.media_id = m.id AND mmc.meta_key = 'curation.checked' ORDER BY mmc.id DESC LIMIT 1)";

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

if ($curationFilter !== 'all') {
    if ($curationFilter === SV_QUALITY_UNKNOWN) {
        $where[] = '(m.quality_status IS NULL OR m.quality_status = :quality_status)';
    } else {
        $where[] = 'm.quality_status = :quality_status';
    }
    $params[':quality_status'] = $curationFilter;
}

if ($minRating > 0) {
    $where[]               = 'm.rating >= :minRating';
    $params[':minRating'] = $minRating;
}

if ($hasInternalAccess) {
    $voteStateExpr = 'COALESCE(' . $voteStateSql . ', 0)';
    if ($voteFilter === 'up') {
        $where[] = $voteStateExpr . ' = 1';
    } elseif ($voteFilter === 'down') {
        $where[] = $voteStateExpr . ' = -1';
    }

    $checkedStateExpr = 'COALESCE(' . $checkedStateSql . ', 0)';
    if ($checkedFilter === 'checked') {
        $where[] = $checkedStateExpr . ' = 1';
    } elseif ($checkedFilter === 'unchecked') {
        $where[] = $checkedStateExpr . ' = 0';
    }

    if ($lowActivityFilter === 'low') {
        $where[] = '(' . $activityScoreSql . ') <= 0';
        $params[':activity_now'] = $activityNow;
    }
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
$latestScanCols = "
           (SELECT sr.run_at FROM scan_results sr WHERE sr.media_id = m.id ORDER BY sr.run_at DESC, sr.id DESC LIMIT 1) AS last_scan_at,
           (SELECT sr.scanner FROM scan_results sr WHERE sr.media_id = m.id ORDER BY sr.run_at DESC, sr.id DESC LIMIT 1) AS last_scan_scanner,
           (SELECT sr.raw_json FROM scan_results sr WHERE sr.media_id = m.id ORDER BY sr.run_at DESC, sr.id DESC LIMIT 1) AS last_scan_raw,
";
$activitySelectCols = "
           COALESCE($activityClicksSql, 0) AS activity_clicks,
           COALESCE($activityLastSql, 0) AS activity_last_click_at,
           ($activityScoreSql) AS activity_score,
           COALESCE($voteStateSql, 0) AS vote_state,
           COALESCE($checkedStateSql, 0) AS checked_flag,
";
$baseSelectCols = "m.id, m.path, m.type, m.has_nsfw, m.rating, m.status, m.lifecycle_status, m.quality_status, m.hash,
           m.width, m.height, m.duration, m.fps, m.filesize,
           $latestScanCols
           $activitySelectCols
           p.prompt AS prompt_text, p.width AS prompt_width, p.height AS prompt_height, p.model AS prompt_model,
           EXISTS (SELECT 1 FROM prompts p3 WHERE p3.media_id = m.id) AS has_prompt,
            EXISTS (SELECT 1 FROM prompts p4 WHERE p4.media_id = m.id AND $promptCompleteClause) AS prompt_complete,
           EXISTS (SELECT 1 FROM media_meta mm WHERE mm.media_id = m.id) AS has_meta,
           EXISTS (SELECT 1 FROM media_tags mt WHERE mt.media_id = m.id) AS has_tags,
           EXISTS (SELECT 1 FROM media_meta mm2 WHERE mm2.media_id = m.id AND mm2.meta_key = 'scan_stale') AS scan_stale,
           EXISTS (SELECT 1 FROM jobs j WHERE j.media_id = m.id AND j.type = 'forge_regen' AND j.status IN ('queued','pending','created','running')) AS job_running";

$featureRows = [];
try {
    $featureSql = 'SELECT ' . $baseSelectCols . '
FROM media m
' . $latestPromptJoin . '
WHERE ' . $whereSql . '
ORDER BY activity_score ASC, m.created_at ASC, m.id ASC
LIMIT 10';
    $featureStmt = $pdo->prepare($featureSql);
    foreach ($params as $k => $v) {
        $featureStmt->bindValue($k, $v);
    }
    $featureStmt->bindValue(':activity_now', $activityNow, PDO::PARAM_INT);
    $featureStmt->execute();
    $featureRows = $featureStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $featureRows = [];
}

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
        $listSql = 'SELECT ' . $baseSelectCols . '
FROM media m
' . $latestPromptJoin . '
WHERE m.id IN (' . $placeholders . ')
ORDER BY m.id DESC';
        $listStmt = $pdo->prepare($listSql);
        $listStmt->bindValue(':activity_now', $activityNow, PDO::PARAM_INT);
        foreach ($pageIssueIds as $idx => $pid) {
            $listStmt->bindValue($idx + 1, $pid, PDO::PARAM_INT);
        }
        $listStmt->execute();
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
$qualitySql = 'SELECT ' . $baseSelectCols . '
FROM media m
' . $latestPromptJoin . '
WHERE ' . $whereSql . '
ORDER BY m.id DESC';

    $qualityStmt = $pdo->prepare($qualitySql);
    foreach ($params as $k => $v) {
        $qualityStmt->bindValue($k, $v);
    }
    $qualityStmt->bindValue(':activity_now', $activityNow, PDO::PARAM_INT);
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

    $listSql = 'SELECT ' . $baseSelectCols . '
FROM media m
' . $latestPromptJoin . '
WHERE ' . $whereSql . '
ORDER BY m.id DESC
LIMIT :limit OFFSET :offset';

    $listStmt = $pdo->prepare($listSql);
    foreach ($params as $k => $v) {
        $listStmt->bindValue($k, $v);
    }
    $listStmt->bindValue(':activity_now', $activityNow, PDO::PARAM_INT);
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
    'curation'       => $curationFilter,
    'min_rating'     => $minRating,
    'incomplete'     => $incompleteFilter,
    'vote'           => $voteFilter,
    'checked'        => $checkedFilter,
    'low_activity'   => $lowActivityFilter,
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
        'label' => 'Bilder',
        'overrides' => ['type' => 'image', 'issues' => null, 'prompt_quality' => 'all'],
    ],
    [
        'label' => 'Videos',
        'overrides' => ['type' => 'video', 'issues' => null, 'prompt_quality' => 'all'],
    ],
    [
        'label' => 'Probleme',
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

function sv_render_media_card(array $row, array $context): void
{
    $id      = (int)$row['id'];
    $path    = (string)$row['path'];
    $pathLabel = sv_safe_path_label($path);
    $type    = (string)$row['type'];
    $hasNsfw = (int)($row['has_nsfw'] ?? 0) === 1;
    $rating  = (int)($row['rating'] ?? 0);
    $status  = (string)($row['status'] ?? '');
    $lifecycleStatus = (string)($row['lifecycle_status'] ?? '');
    $qualityStatus = sv_normalize_quality_status((string)($row['quality_status'] ?? ''), SV_QUALITY_UNKNOWN);
    $qualityBadge = 'pill-muted';
    if ($qualityStatus === SV_QUALITY_OK) {
        $qualityBadge = 'pill';
    } elseif ($qualityStatus === SV_QUALITY_REVIEW) {
        $qualityBadge = 'pill-warn';
    } elseif ($qualityStatus === SV_QUALITY_BLOCKED) {
        $qualityBadge = 'pill-bad';
    }
    $qualityLabels = $context['qualityStatusLabels'] ?? [];
    $qualityLabel = $qualityLabels[$qualityStatus] ?? $qualityStatus;
    $hash    = (string)($row['hash'] ?? '');
    $dupeCounts = $context['dupeCounts'] ?? [];
    $dupeCount = ($hash !== '' && isset($dupeCounts[$hash])) ? (int)$dupeCounts[$hash] : 0;
    $hasPrompt = (int)($row['has_prompt'] ?? 0) === 1;
    $promptComplete = (int)($row['prompt_complete'] ?? 0) === 1;
    $hasMeta   = (int)($row['has_meta'] ?? 0) === 1;
    $hasTags   = (int)($row['has_tags'] ?? 0) === 1;
    $scanStale = (int)($row['scan_stale'] ?? 0) === 1;
    $jobRunning = (int)($row['job_running'] ?? 0) === 1;
    $issuesByMedia = $context['issuesByMedia'] ?? [];
    $hasIssues = isset($issuesByMedia[$id]);
    $lastScanAt = trim((string)($row['last_scan_at'] ?? ''));
    $lastScanScanner = (string)($row['last_scan_scanner'] ?? '');
    $scanInfo = sv_extract_scan_info($row['last_scan_raw'] ?? null);
    $lastScanError = $scanInfo['error'] ?? null;
    $scanTagsWritten = $scanInfo['tags_written'] ?? null;
    $promptModel = sv_limit_string((string)($row['prompt_model'] ?? ''), 120);

    $qualityData = $row['quality'] ?? sv_prompt_quality_from_text(
        $row['prompt_text'] ?? null,
        isset($row['prompt_width']) ? (int)$row['prompt_width'] : null,
        isset($row['prompt_height']) ? (int)$row['prompt_height'] : null
    );
    $qualityClass = $qualityData['quality_class'];
    $qualityScore = (int)$qualityData['score'];
    $qualityIssues = array_slice($qualityData['issues'] ?? [], 0, 2);
    $promptBadgeClass = $qualityClass === 'A' ? 'pill' : ($qualityClass === 'B' ? 'pill-muted' : 'pill-warn');

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

    $paginationBase = $context['paginationBase'] ?? [];
    $page = (int)($context['page'] ?? 1);
    $showAdult = !empty($context['showAdult']);
    $detailParams = array_merge($paginationBase, ['id' => $id, 'p' => $page]);
    $detailUrl = 'media_view.php?' . http_build_query($detailParams);
    $thumbUrl = 'thumb.php?' . http_build_query(['id' => $id, 'adult' => $showAdult ? '1' : '0']);
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

    $voteState = (int)($row['vote_state'] ?? 0);
    $checkedFlag = (int)($row['checked_flag'] ?? 0) === 1;
    $activityScore = (int)($row['activity_score'] ?? 0);
    $hasInternalAccess = !empty($context['hasInternalAccess']);
    $iconRescan = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 12a7.5 7.5 0 0 1 12.9-5.1l1.1-1.1V9.5h-3.7l1.4-1.4A6 6 0 1 0 18 12h1.5A7.5 7.5 0 0 1 4.5 12z" fill="currentColor"/></svg>';
    $iconUp = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5l7 7h-4v7H9v-7H5l7-7z" fill="currentColor"/></svg>';
    $iconDown = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19l-7-7h4V5h6v7h4l-7 7z" fill="currentColor"/></svg>';
    $iconCheck = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.2 16.2L5.5 12.5l-1.5 1.5 5.2 5.2L20 8.4 18.5 7z" fill="currentColor"/></svg>';
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
            <span class="<?= htmlspecialchars($qualityBadge, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" title="Curation (Quality-Status)"><?= htmlspecialchars($qualityLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            <span class="<?= htmlspecialchars($promptBadgeClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" title="Prompt-Qualität (A/B/C)">Prompt <?= htmlspecialchars($qualityClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            <?php if ($jobRunning): ?>
                <span class="pill pill-warn">Job läuft</span>
            <?php endif; ?>
            <?php if ($scanStale): ?>
                <span class="pill pill-warn" title="Scanner nicht erreichbar, Tags/Rating veraltet">Scan veraltet</span>
            <?php endif; ?>
            <?php if ($lastScanError): ?>
                <span class="pill pill-bad" title="<?= htmlspecialchars($lastScanError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Scan-Fehler</span>
            <?php elseif ($lastScanAt === ''): ?>
                <span class="pill pill-warn">Scan fehlt</span>
            <?php endif; ?>
            <?php if ($rating > 0): ?>
                <span class="pill">Rating <?= $rating ?></span>
            <?php endif; ?>
            <?php if ($hasInternalAccess && $activityScore <= 0): ?>
                <span class="pill pill-warn">Niedrige Aktivität</span>
            <?php endif; ?>
        </div>

        <div class="thumb-wrap">
            <a href="<?= htmlspecialchars($detailUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <img
                    src="<?= htmlspecialchars($thumbUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                    data-media-id="<?= $id ?>"
                    loading="lazy"
                    alt="ID <?= $id ?>">
            </a>
        </div>

        <div class="card-info">
            <div class="info-line">
                <span class="info-chip"><?= $type === 'video' ? 'Video' : 'Bild' ?></span>
                <span class="info-chip"><?= htmlspecialchars($resolution, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <?php if ($duration !== null): ?>
                    <span class="info-chip"><?= htmlspecialchars(number_format($duration, 1), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>s</span>
                <?php endif; ?>
                <span class="info-chip <?= $qualityClass === 'C' ? 'chip-warn' : '' ?>" title="Prompt-Qualität <?= htmlspecialchars($qualityClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> (Score <?= $qualityScore ?><?php if ($qualityIssues !== []): ?> – <?= htmlspecialchars(implode(', ', $qualityIssues), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php endif; ?>)">Prompt <?= htmlspecialchars($qualityClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <?php if ($promptModel !== ''): ?><span class="info-chip">Model <?= htmlspecialchars($promptModel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
                <?php if ($hasMeta): ?><span class="info-chip">Meta</span><?php endif; ?>
                <?php if ($hasTags): ?><span class="info-chip">Tags</span><?php endif; ?>
                <?php if ($hasPrompt && !$promptComplete): ?><span class="info-chip chip-warn">Prompt unvollständig</span><?php endif; ?>
                <?php if (!$hasPrompt): ?><span class="info-chip">Kein Prompt</span><?php endif; ?>
                <?php if ($scanStale): ?><span class="info-chip chip-warn" title="Scanner nicht erreichbar, Daten veraltet">Scan veraltet</span><?php endif; ?>
                <?php if ($hasInternalAccess): ?>
                    <span class="info-chip">Vote <?= $voteState ?></span>
                    <span class="info-chip"><?= $checkedFlag ? 'Geprüft' : 'Offen' ?></span>
                    <span class="info-chip">Score <?= $activityScore ?></span>
                <?php endif; ?>
            </div>
            <div class="info-line">
                <span class="info-chip">Scan <?= $lastScanAt !== '' ? htmlspecialchars($lastScanAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : 'fehlend' ?></span>
                <?php if ($lastScanScanner !== ''): ?><span class="info-chip">Scanner <?= htmlspecialchars($lastScanScanner, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
                <?php if ($scanTagsWritten !== null): ?><span class="info-chip">Tags <?= (int)$scanTagsWritten ?></span><?php endif; ?>
                <?php if ($lastScanError): ?><span class="info-chip chip-warn" title="<?= htmlspecialchars($lastScanError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Fehler</span><?php endif; ?>
            </div>
            <div class="info-path" title="<?= htmlspecialchars($pathLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                ID <?= $id ?> · <?= htmlspecialchars($pathLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </div>
            <div class="card-actions">
                <a class="btn btn--primary btn--sm" href="<?= htmlspecialchars($streamUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener">Original</a>
                <?php if ($hasInternalAccess): ?>
                    <form method="post" class="inline-form">
                        <input type="hidden" name="action" value="rescan_job">
                        <input type="hidden" name="media_id" value="<?= $id ?>">
                        <button class="btn btn--icon btn--secondary" type="submit" aria-label="Tag-Rescan" title="Tag-Rescan"><?= $iconRescan ?></button>
                    </form>
                    <form method="post" class="inline-form">
                        <input type="hidden" name="action" value="vote_up">
                        <input type="hidden" name="media_id" value="<?= $id ?>">
                        <button class="btn btn--icon <?= $voteState === 1 ? 'btn--primary' : 'btn--secondary' ?>" type="submit" aria-label="Vote hoch" title="Vote hoch"><?= $iconUp ?></button>
                    </form>
                    <form method="post" class="inline-form">
                        <input type="hidden" name="action" value="vote_down">
                        <input type="hidden" name="media_id" value="<?= $id ?>">
                        <button class="btn btn--icon <?= $voteState === -1 ? 'btn--primary' : 'btn--secondary' ?>" type="submit" aria-label="Vote runter" title="Vote runter"><?= $iconDown ?></button>
                    </form>
                    <form method="post" class="inline-form">
                        <input type="hidden" name="action" value="checked_toggle">
                        <input type="hidden" name="media_id" value="<?= $id ?>">
                        <input type="hidden" name="checked_value" value="<?= $checkedFlag ? '0' : '1' ?>">
                        <button class="btn btn--icon <?= $checkedFlag ? 'btn--primary' : 'btn--secondary' ?>" type="submit" aria-label="<?= $checkedFlag ? 'Checked entfernen' : 'Checked setzen' ?>" title="<?= $checkedFlag ? 'Checked entfernen' : 'Checked setzen' ?>"><?= $iconCheck ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </article>
    <?php
}

$paginationBase = array_filter($queryParams, static fn($v) => $v !== '' && $v !== null);
$qualityStatusLabels = sv_quality_status_labels();
$promptQualityLabels = sv_prompt_quality_labels();

$filterChips = [];
if ($pathFilter !== '') {
    $filterChips[] = 'Suche: ' . sv_limit_string($pathFilter, 32);
}
if ($typeFilter !== 'all') {
    $filterChips[] = $typeFilter === 'video' ? 'Typ: Video' : 'Typ: Bild';
}
if ($hasPromptFilter !== 'all') {
    $filterChips[] = $hasPromptFilter === 'with' ? 'Prompt: mit' : 'Prompt: ohne';
}
if ($hasMetaFilter !== 'all') {
    $filterChips[] = $hasMetaFilter === 'with' ? 'Meta: mit' : 'Meta: ohne';
}
if ($statusFilter !== 'all') {
    $statusLabels = [
        'active' => 'aktiv',
        'archived' => 'archiviert',
        'deleted' => 'gelöscht',
        'missing' => 'missing',
        'deleted_logical' => 'gelöscht (logisch)',
    ];
    $filterChips[] = 'Status: ' . ($statusLabels[$statusFilter] ?? $statusFilter);
}
if ($minRating > 0) {
    $filterChips[] = 'Rating ≥ ' . $minRating;
}
if ($incompleteFilter !== 'none') {
    $incompleteLabels = [
        'prompt' => 'Prompt',
        'tags' => 'Tags',
        'meta' => 'Meta',
        'any' => 'alles',
    ];
    $filterChips[] = 'Unvollständig: ' . ($incompleteLabels[$incompleteFilter] ?? $incompleteFilter);
}
if ($promptQualityFilter !== 'all') {
    $filterChips[] = 'Prompt-Qualität: ' . ($promptQualityLabels[$promptQualityFilter] ?? $promptQualityFilter);
}
if ($curationFilter !== 'all') {
    $filterChips[] = 'Curation: ' . ($qualityStatusLabels[$curationFilter] ?? $curationFilter);
}
if ($issueFilter) {
    $filterChips[] = 'Issues';
}
if ($dupeFilter) {
    $filterChips[] = $dupeHashFilter !== '' ? 'Dupe: ' . $dupeHashFilter : 'Dupes';
}
if ($hasInternalAccess) {
    if ($voteFilter !== 'any') {
        $filterChips[] = $voteFilter === 'up' ? 'Vote: up' : 'Vote: down';
    }
    if ($checkedFilter !== 'any') {
        $filterChips[] = $checkedFilter === 'checked' ? 'Geprüft' : 'Offen';
    }
    if ($lowActivityFilter === 'low') {
        $filterChips[] = 'Niedrige Aktivität';
    }
}

$filtersOpen = $filterChips !== [];
?>
<?php sv_ui_header('Medien', 'medien'); ?>
<div class="page-header">
    <div>
        <h1 class="page-title">Medien</h1>
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
            <button type="submit" class="btn btn--secondary btn--sm">Suche</button>
        </form>
        <div class="fsk-toggle">
            <a href="?<?= http_build_query(array_merge($paginationBase, ['adult' => '0', 'p' => 1])) ?>" class="<?= $showAdult ? '' : 'active' ?>">FSK18 aus</a>
            <a href="?<?= http_build_query(array_merge($paginationBase, ['adult' => '1', 'p' => 1])) ?>" class="<?= $showAdult ? 'active' : '' ?>">FSK18 an</a>
        </div>
        <div class="compact-status">
            <label>
                <span>Modus:</span>
                <select name="view" form="view-form">
                    <option value="grid" <?= $viewMode === 'grid' ? 'selected' : '' ?>>Kacheln</option>
                    <option value="list" <?= $viewMode === 'list' ? 'selected' : '' ?>>Liste</option>
                </select>
            </label>
        </div>
    </div>
</div>

<?php if (!empty($configWarning)): ?>
    <div class="banner banner--warn">
        <?= htmlspecialchars($configWarning, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    </div>
<?php endif; ?>

<?php if ($actionMessage !== null || $actionError !== null): ?>
    <div class="banner <?= $actionError ? 'banner--error' : 'banner--success' ?>">
        <?= htmlspecialchars($actionError ?? $actionMessage ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
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
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>aktiv</option>
                <option value="archived" <?= $statusFilter === 'archived' ? 'selected' : '' ?>>archiviert</option>
                <option value="deleted" <?= $statusFilter === 'deleted' ? 'selected' : '' ?>>gelöscht</option>
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

<details class="filters" <?= $filtersOpen ? 'open' : '' ?>>
    <summary>
        Filter
        <?php if ($filterChips !== []): ?>
            <span class="summary-chips">
                <?php foreach ($filterChips as $chip): ?>
                    <span class="filter-chip"><?= htmlspecialchars($chip, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <?php endforeach; ?>
            </span>
        <?php endif; ?>
    </summary>
    <div class="details-body">
        <form id="filters-form" method="get" class="controls">
            <?php foreach ($paginationBase as $key => $value): if (in_array($key, ['type','has_prompt','has_meta','status','curation','min_rating','incomplete','prompt_quality','vote','checked','low_activity','view','p'], true)) { continue; } ?>
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
                Unvollständig
                <select name="incomplete">
                    <option value="none" <?= $incompleteFilter === 'none' ? 'selected' : '' ?>>aus</option>
                    <option value="prompt" <?= $incompleteFilter === 'prompt' ? 'selected' : '' ?>>Prompt</option>
                    <option value="tags" <?= $incompleteFilter === 'tags' ? 'selected' : '' ?>>Tags</option>
                    <option value="meta" <?= $incompleteFilter === 'meta' ? 'selected' : '' ?>>Meta</option>
                    <option value="any" <?= $incompleteFilter === 'any' ? 'selected' : '' ?>>alles</option>
                </select>
            </label>
            <label>
                Curation
                <select name="curation">
                    <option value="all" <?= $curationFilter === 'all' ? 'selected' : '' ?>>alle</option>
                    <?php foreach (sv_quality_status_labels() as $statusValue => $statusLabel): ?>
                        <option value="<?= htmlspecialchars($statusValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $curationFilter === $statusValue ? 'selected' : '' ?>>
                            <?= htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Prompt-Qualität
                <select name="prompt_quality">
                    <option value="all" <?= $promptQualityFilter === 'all' ? 'selected' : '' ?>>alle</option>
                    <?php foreach (sv_prompt_quality_labels() as $qualityValue => $qualityLabel): ?>
                        <option value="<?= htmlspecialchars($qualityValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $promptQualityFilter === $qualityValue ? 'selected' : '' ?>>
                            <?= htmlspecialchars($qualityLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if ($hasInternalAccess): ?>
                <label>
                    Vote
                    <select name="vote">
                        <option value="any" <?= $voteFilter === 'any' ? 'selected' : '' ?>>alle</option>
                        <option value="up" <?= $voteFilter === 'up' ? 'selected' : '' ?>>up</option>
                        <option value="down" <?= $voteFilter === 'down' ? 'selected' : '' ?>>down</option>
                    </select>
                </label>
                <label>
                    Geprüft
                    <select name="checked">
                        <option value="any" <?= $checkedFilter === 'any' ? 'selected' : '' ?>>alle</option>
                        <option value="checked" <?= $checkedFilter === 'checked' ? 'selected' : '' ?>>checked</option>
                        <option value="unchecked" <?= $checkedFilter === 'unchecked' ? 'selected' : '' ?>>unchecked</option>
                    </select>
                </label>
                <label>
                    Niedrige Aktivität
                    <select name="low_activity">
                        <option value="all" <?= $lowActivityFilter === 'all' ? 'selected' : '' ?>>alle</option>
                        <option value="low" <?= $lowActivityFilter === 'low' ? 'selected' : '' ?>>nur low</option>
                    </select>
                </label>
            <?php endif; ?>
            <div class="controls-actions">
                <button type="submit" class="btn btn--primary btn--sm">Filter anwenden</button>
                <a class="reset-link" href="?adult=<?= $showAdult ? '1' : '0' ?>">Reset</a>
            </div>
        </form>
    </div>
</details>

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
    <?php
    $cardContext = [
        'paginationBase' => $paginationBase,
        'page' => $page,
        'showAdult' => $showAdult,
        'qualityStatusLabels' => $qualityStatusLabels,
        'promptQualityLabels' => $promptQualityLabels,
        'issuesByMedia' => $issuesByMedia,
        'dupeCounts' => $dupeCounts,
        'hasInternalAccess' => $hasInternalAccess,
    ];
    ?>
    <?php if ($featureRows !== []): ?>
        <section class="panel">
            <div class="panel-header">Featureview · niedrigste Aktivität</div>
            <div class="media-grid">
                <?php foreach ($featureRows as $row): ?>
                    <?php sv_render_media_card($row, $cardContext); ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
    <div class="media-grid">
        <?php if ($rows === []): ?>
            <div class="empty">Keine Einträge für diesen Filter.</div>
        <?php endif; ?>
        <?php foreach ($rows as $row): ?>
            <?php sv_render_media_card($row, $cardContext); ?>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="panel">
        <div class="panel-header">Listenansicht</div>
        <div class="panel-content">
            <?php if ($rows === []): ?>
                <div class="empty">Keine Einträge für diesen Filter.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Typ</th>
                                <th>Prompt</th>
                                <th>Meta</th>
                            <th>Tags</th>
                            <th>Modell</th>
                            <th>Scan</th>
                            <th>Issues</th>
                            <th>Rating</th>
                            <th>Curation</th>
                            <th>Prompt</th>
                            <th>Status</th>
                                <?php if ($hasInternalAccess): ?>
                                    <th>Vote</th>
                                    <th>Geprüft</th>
                                    <th>Aktivität</th>
                                <?php endif; ?>
                                <th>Aktion</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row):
                            $id      = (int)$row['id'];
                            $path    = (string)$row['path'];
                            $pathLabel = sv_safe_path_label($path);
                            $type    = (string)$row['type'];
                            $qualityStatus = sv_normalize_quality_status((string)($row['quality_status'] ?? ''), SV_QUALITY_UNKNOWN);
                            $qualityLabel = $qualityStatusLabels[$qualityStatus] ?? $qualityStatus;
                            $hasPrompt = (int)($row['has_prompt'] ?? 0) === 1;
                            $promptComplete = (int)($row['prompt_complete'] ?? 0) === 1;
                            $hasMeta   = (int)($row['has_meta'] ?? 0) === 1;
                            $hasTags   = (int)($row['has_tags'] ?? 0) === 1;
                            $hasIssues = isset($issuesByMedia[$id]);
                            $scanStale = (int)($row['scan_stale'] ?? 0) === 1;
                            $jobRunning = (int)($row['job_running'] ?? 0) === 1;
                            $lastScanAt = trim((string)($row['last_scan_at'] ?? ''));
                            $lastScanScanner = (string)($row['last_scan_scanner'] ?? '');
                            $scanInfo = sv_extract_scan_info($row['last_scan_raw'] ?? null);
                            $lastScanError = $scanInfo['error'] ?? null;
                            $rating  = (int)($row['rating'] ?? 0);
                            $status  = (string)($row['status'] ?? '');
                            $promptModel = sv_limit_string((string)($row['prompt_model'] ?? ''), 120);
                            $detailParams = array_merge($paginationBase, ['id' => $id, 'p' => $page]);
                            $promptQualityData = $row['quality'] ?? sv_prompt_quality_from_text(
                                $row['prompt_text'] ?? null,
                                isset($row['prompt_width']) ? (int)$row['prompt_width'] : null,
                                isset($row['prompt_height']) ? (int)$row['prompt_height'] : null
                            );
                            $promptQualityClass = $promptQualityData['quality_class'] ?? 'C';
                            $voteState = (int)($row['vote_state'] ?? 0);
                            $checkedFlag = (int)($row['checked_flag'] ?? 0) === 1;
                            $activityScore = (int)($row['activity_score'] ?? 0);
                            $iconRescan = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 12a7.5 7.5 0 0 1 12.9-5.1l1.1-1.1V9.5h-3.7l1.4-1.4A6 6 0 1 0 18 12h1.5A7.5 7.5 0 0 1 4.5 12z" fill="currentColor"/></svg>';
                            $iconUp = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5l7 7h-4v7H9v-7H5l7-7z" fill="currentColor"/></svg>';
                            $iconDown = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19l-7-7h4V5h6v7h4l-7 7z" fill="currentColor"/></svg>';
                            $iconCheck = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.2 16.2L5.5 12.5l-1.5 1.5 5.2 5.2L20 8.4 18.5 7z" fill="currentColor"/></svg>';
                            ?>
                            <tr>
                                <td><?= $id ?></td>
                                <td title="<?= htmlspecialchars($pathLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($pathLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                <td><?= $hasPrompt ? ($promptComplete ? 'vollständig' : 'teilweise') : 'fehlend' ?></td>
                                <td><?= $hasMeta ? 'ja' : 'nein' ?></td>
                                <td><?= $hasTags ? 'ja' : 'nein' ?></td>
                                <td><?= $promptModel !== '' ? htmlspecialchars($promptModel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '—' ?></td>
                                <td><?= $lastScanAt !== '' ? htmlspecialchars($lastScanAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : 'fehlend' ?><?php if ($lastScanScanner !== ''): ?> (<?= htmlspecialchars($lastScanScanner, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)<?php endif; ?><?php if ($lastScanError): ?> · <span title="<?= htmlspecialchars($lastScanError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Fehler</span><?php endif; ?></td>
                                <td><?= $hasIssues ? 'ja' : '—' ?></td>
                                <td><?= $rating > 0 ? (int)$rating : '—' ?></td>
                                <td><?= htmlspecialchars($qualityLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($promptQualityLabels[$promptQualityClass] ?? $promptQualityClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                <td><?= $status !== '' ? htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : 'aktiv' ?><?= $scanStale ? ' (veraltet)' : '' ?><?= $jobRunning ? ' (Job)' : '' ?></td>
                                <?php if ($hasInternalAccess): ?>
                                    <td><?= $voteState ?></td>
                                    <td><?= $checkedFlag ? 'ja' : 'nein' ?></td>
                                    <td><?= $activityScore ?></td>
                                <?php endif; ?>
                                <td>
                                    <div class="table-actions">
                                        <a class="btn btn--secondary btn--sm" href="media_view.php?<?= http_build_query($detailParams) ?>">Details</a>
                                        <?php if ($hasInternalAccess): ?>
                                            <form method="post" class="inline-form">
                                                <input type="hidden" name="action" value="rescan_job">
                                                <input type="hidden" name="media_id" value="<?= $id ?>">
                                                <button class="btn btn--icon btn--secondary" type="submit" aria-label="Tag-Rescan" title="Tag-Rescan"><?= $iconRescan ?></button>
                                            </form>
                                            <form method="post" class="inline-form">
                                                <input type="hidden" name="action" value="vote_up">
                                                <input type="hidden" name="media_id" value="<?= $id ?>">
                                                <button class="btn btn--icon <?= $voteState === 1 ? 'btn--primary' : 'btn--secondary' ?>" type="submit" aria-label="Vote hoch" title="Vote hoch"><?= $iconUp ?></button>
                                            </form>
                                            <form method="post" class="inline-form">
                                                <input type="hidden" name="action" value="vote_down">
                                                <input type="hidden" name="media_id" value="<?= $id ?>">
                                                <button class="btn btn--icon <?= $voteState === -1 ? 'btn--primary' : 'btn--secondary' ?>" type="submit" aria-label="Vote runter" title="Vote runter"><?= $iconDown ?></button>
                                            </form>
                                            <form method="post" class="inline-form">
                                                <input type="hidden" name="action" value="checked_toggle">
                                                <input type="hidden" name="media_id" value="<?= $id ?>">
                                                <input type="hidden" name="checked_value" value="<?= $checkedFlag ? '0' : '1' ?>">
                                                <button class="btn btn--icon <?= $checkedFlag ? 'btn--primary' : 'btn--secondary' ?>" type="submit" aria-label="<?= $checkedFlag ? 'Checked entfernen' : 'Checked setzen' ?>" title="<?= $checkedFlag ? 'Checked entfernen' : 'Checked setzen' ?>"><?= $iconCheck ?></button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="meta-note">
    FSK18-Link: <code>?adult=1</code> oder <code>?18=True</code> · Default: Kacheln · Legacy-Grid ist nur noch per Direktaufruf erreichbar
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
<?php sv_ui_footer(); ?>

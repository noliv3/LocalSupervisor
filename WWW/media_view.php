<?php
declare(strict_types=1);

require_once __DIR__ . '/../SCRIPTS/common.php';
require_once __DIR__ . '/../SCRIPTS/security.php';
require_once __DIR__ . '/../SCRIPTS/operations.php';
require_once __DIR__ . '/../SCRIPTS/paths.php';
require_once __DIR__ . '/_layout.php';

try {
    $config = sv_load_config();
} catch (Throwable $e) {
    sv_security_error(500, 'config');
}

$configWarning     = $config['_config_warning'] ?? null;
$hasInternalAccess = sv_validate_internal_access($config, 'media_view', false);
$forgeModels       = [];
$forgeModelError   = null;
$forgeModelStatus  = 'unavailable';
$forgeModelSource  = 'none';
$forgeModelAge     = null;

$dsn      = $config['db']['dsn'];
$user     = $config['db']['user']     ?? null;
$password = $config['db']['password'] ?? null;
$options  = $config['db']['options']  ?? [];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    sv_security_error(405, 'Method not allowed.');
}
if ($method === 'POST' && !$hasInternalAccess) {
    $action = is_string($_POST['action'] ?? null) ? trim($_POST['action']) : '';
    if ($action !== 'vote_up') {
        sv_security_error(403, 'Forbidden.');
    }
}

try {
    $pdo = new PDO($dsn, $user, $password, $options);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    sv_apply_sqlite_pragmas($pdo, $config);
    sv_db_ensure_runtime_indexes($pdo);
} catch (Throwable $e) {
    sv_security_error(500, 'db');
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

function sv_decode_meta_json_list(?string $value): array
{
    if ($value === null || trim($value) === '') {
        return [];
    }

    $decoded = json_decode($value, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    return [];
}

$showAdult = sv_normalize_adult_flag($_GET);
$showAdult = $showAdult && $hasInternalAccess;

$actionMessage = null;
$actionSuccess = null;
$actionLogs    = [];
$actionLogFile = null;

if ($hasInternalAccess) {
    try {
        $forgeModelResult = sv_forge_list_models($config, function (string $msg) use (&$actionLogs): void {
            $actionLogs[] = '[Forge Model] ' . $msg;
        });
        $forgeModels      = $forgeModelResult['models'] ?? [];
        $forgeModelStatus = $forgeModelResult['status'] ?? $forgeModelStatus;
        $forgeModelSource = $forgeModelResult['source'] ?? $forgeModelSource;
        $forgeModelAge    = $forgeModelResult['age'] ?? null;
        if (!empty($forgeModelResult['error'])) {
            $forgeModelError = (string)$forgeModelResult['error'];
        }
    } catch (Throwable $e) {
        $forgeModelError = sv_sanitize_error_message($e->getMessage());
    }
} else {
    $forgeModelStatus = 'restricted';
}

$id = isset($_GET['id']) ? sv_clamp_int((int)$_GET['id'], 1, 1_000_000_000, 0) : 0;
if ($id <= 0) {
    http_response_code(400);
    echo 'Ungültige ID';
    exit;
}

$ajaxAction = isset($_GET['ajax']) && is_string($_GET['ajax']) ? trim((string)$_GET['ajax']) : null;
if ($ajaxAction === 'forge_jobs') {
    header('Content-Type: application/json; charset=utf-8');
    sv_require_internal_access($config, 'media_view_forge_jobs');
    try {
        $jobs = sv_fetch_forge_jobs_for_media($pdo, $id, 10, $config);
        echo json_encode([
            'server_time' => date('c'),
            'jobs'        => $jobs,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Fehler']);
    }
    exit;
}
$latestScanAjax = sv_fetch_latest_scan_result($pdo, $id);
if ($ajaxAction === 'rescan_jobs') {
    header('Content-Type: application/json; charset=utf-8');
    sv_require_internal_access($config, 'media_view_rescan_jobs');
    try {
        $jobs = sv_fetch_rescan_jobs_for_media($pdo, $id, 5);
        echo json_encode([
            'server_time' => date('c'),
            'jobs'        => $jobs,
            'latest_scan' => $latestScanAjax,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Fehler']);
    }
    exit;
}

if ($ajaxAction === 'forge_repair_start') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'Methode nicht erlaubt']);
        exit;
    }
    sv_require_internal_access($config, 'media_view_forge_repair_start');
    [$actionLogFile, $logger] = sv_create_operation_log($config, 'forge_repair', $actionLogs, 10);
    try {
        $postId = isset($_POST['media_id']) ? (int)$_POST['media_id'] : 0;
        if ($postId !== $id) {
            throw new RuntimeException('Media-ID stimmt nicht überein.');
        }

        $endpoint = sv_forge_endpoint_config($config, true);
        if ($endpoint === null) {
            throw new RuntimeException('Forge-Dispatch ist deaktiviert oder nicht konfiguriert.');
        }
        $health = sv_forge_healthcheck($endpoint, $logger);
        if (!$health['ok']) {
            sv_audit_log($pdo, 'forge_health_failed', 'media', $id, [
                'http_status' => $health['http_code'] ?? null,
                'target_url'  => $health['target_url'] ?? null,
            ]);
            throw new RuntimeException('Forge-Endpoint nicht erreichbar. Bitte später erneut versuchen.');
        }

        $options = [
            'source'      => sv_limit_string((string)($_POST['source'] ?? ''), 20),
            'goal'        => sv_limit_string((string)($_POST['goal'] ?? ''), 20),
            'intensity'   => sv_limit_string((string)($_POST['intensity'] ?? ''), 20),
            'tech_fix'    => sv_limit_string((string)($_POST['tech_fix'] ?? ''), 30),
            'prompt_edit' => sv_limit_string((string)($_POST['prompt_edit'] ?? ''), 200),
        ];

        $result = sv_run_forge_repair_job($pdo, $config, $id, $options, $logger);
        $jobId = (int)($result['job_id'] ?? 0);
        echo json_encode([
            'ok'      => true,
            'job_id'  => $jobId,
            'message' => 'Repair-Job #' . $jobId . ' wurde in die Warteschlange gestellt.',
            'applied' => [
                'source_used' => $result['repair_plan']['source_used'] ?? null,
                'goal'        => $result['repair_plan']['goal'] ?? null,
                'intensity'   => $result['repair_plan']['intensity'] ?? null,
                'tech_fix'    => $result['repair_plan']['tech_fix'] ?? null,
                'mode'        => $result['repair_plan']['mode'] ?? null,
                'variants'    => $result['repair_plan']['variants'] ?? null,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'ok'      => false,
            'message' => 'Repair nicht möglich: ' . sv_sanitize_error_message($e->getMessage()),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $postId = isset($_POST['media_id']) ? (int)$_POST['media_id'] : 0;
        if ($postId !== $id) {
            throw new RuntimeException('Media-ID stimmt nicht überein.');
        }

        $action = is_string($_POST['action'] ?? null) ? trim($_POST['action']) : '';

        if ($action === 'vote_up') {
            $voteValue = 1;
            sv_set_media_meta_value($pdo, $id, 'vote.state', $voteValue);
            sv_audit_log($pdo, 'vote_set', 'media', $id, [
                'state' => $voteValue,
            ]);
            $actionSuccess = true;
            $actionMessage = $voteValue > 0 ? 'Vote gesetzt: up.' : 'Vote gesetzt: down.';
        } else {
            sv_require_internal_access($config, 'media_action');
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
                $endpoint = sv_forge_endpoint_config($config, true);
                if ($endpoint === null) {
                    throw new RuntimeException('Forge-Dispatch ist deaktiviert oder nicht konfiguriert.');
                }
                $health = sv_forge_healthcheck($endpoint, $logger);
                if (!$health['ok']) {
                    sv_audit_log($pdo, 'forge_health_failed', 'media', $id, [
                        'http_status' => $health['http_code'] ?? null,
                        'target_url'  => $health['target_url'] ?? null,
                    ]);
                    throw new RuntimeException('Forge-Endpoint nicht erreichbar. Bitte später erneut versuchen.');
                }

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
                if (!empty($result['model_source'])) {
                    $actionMessage .= ' Quelle: ' . htmlspecialchars((string)$result['model_source'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '.';
                }
                if (!empty($result['model_status']) && (string)$result['model_status'] !== 'ok') {
                    $actionMessage .= ' Modell-Status: ' . htmlspecialchars((string)$result['model_status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '.';
                }
                if (!empty($result['model_error'])) {
                    $actionMessage .= ' Hinweis: ' . htmlspecialchars((string)$result['model_error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '.';
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
        } elseif ($action === 'toggle_nsfw') {
            [$actionLogFile, $logger] = sv_create_operation_log($config, 'nsfw_toggle', $actionLogs, 5);
            $target = isset($_POST['nsfw_value']) && (string)$_POST['nsfw_value'] === '1';
            $result = sv_set_media_nsfw_status($pdo, $config, $id, $target, $logger);
            $actionSuccess = true;
            $actionMessage = 'NSFW-Status aktualisiert: ' . ($result['old'] ? '1' : '0') . ' → ' . ($result['current'] ? '1' : '0');
        } elseif ($action === 'rescan_job') {
            [$actionLogFile, $logger] = sv_create_operation_log($config, 'rescan_single_job', $actionLogs, 10);
            $enqueue = sv_enqueue_rescan_media_job($pdo, $config, $id, $logger);
            $worker  = sv_spawn_scan_worker($config, null, 1, $logger, $id);
            $jobId   = (int)($enqueue['job_id'] ?? 0);
            $deduped = (bool)($enqueue['deduped'] ?? false);
            $actionSuccess = $jobId > 0;
            $actionMessage = $actionSuccess
                ? 'Rescan-Job #' . $jobId . ' eingereiht.'
                : 'Rescan-Job konnte nicht angelegt werden.';
            if ($deduped) {
                $actionMessage = 'Rescan-Job #' . $jobId . ' existiert bereits (queued/running).';
            }
            if (!empty($worker['pid'])) {
                $actionMessage .= ' Worker PID: ' . (int)$worker['pid'] . '.';
            } elseif (!empty($worker['unknown'])) {
                $actionMessage .= ' Worker-Status unbekannt (Hintergrundstart).';
            }
            if ($actionSuccess) {
                sv_audit_log($pdo, 'rescan_start', 'media', $id, [
                    'job_id'     => $jobId,
                    'worker_pid' => $worker['pid'] ?? null,
                    'deduped'    => $deduped,
                ]);
            }
            } elseif ($action === 'vote_down') {
                $voteValue = -1;
            sv_set_media_meta_value($pdo, $id, 'vote.state', $voteValue);
            sv_audit_log($pdo, 'vote_set', 'media', $id, [
                'state' => $voteValue,
            ]);
            $actionSuccess = true;
            $actionMessage = $voteValue > 0 ? 'Vote gesetzt: up.' : 'Vote gesetzt: down.';
            } elseif ($action === 'checked_toggle') {
            $checkedValue = isset($_POST['checked_value']) && (string)$_POST['checked_value'] === '1' ? 1 : 0;
            sv_set_media_meta_value($pdo, $id, 'curation.checked', $checkedValue);
            if ($checkedValue === 1) {
                sv_set_media_meta_value($pdo, $id, 'curation.checked_at', time());
            }
            sv_audit_log($pdo, 'curation_checked', 'media', $id, [
                'checked' => $checkedValue,
            ]);
            $actionSuccess = true;
            $actionMessage = $checkedValue === 1 ? 'Checked gesetzt.' : 'Checked entfernt.';
            } elseif (in_array($action, ['tag_add', 'tag_remove', 'tag_lock', 'tag_unlock'], true)) {
            [$actionLogFile, $logger] = sv_create_operation_log($config, 'tag_edit', $actionLogs, 10);
            $payload = [
                'name'       => $_POST['tag_name'] ?? '',
                'type'       => $_POST['tag_type'] ?? '',
                'confidence' => isset($_POST['tag_confidence']) ? (float)$_POST['tag_confidence'] : null,
                'lock'       => !empty($_POST['tag_lock_flag']),
            ];
            $tagAction = match ($action) {
                'tag_add'    => 'add',
                'tag_remove' => 'remove',
                'tag_lock'   => 'lock',
                'tag_unlock' => 'unlock',
                default      => 'add',
            };
            $result = sv_update_media_tags($pdo, $id, $tagAction, $payload, $logger);
            $actionSuccess = true;
            $actionMessage = 'Tag-Aktion: ' . $result['action'] . ' (' . ($payload['name'] ?? '') . ')';
            } elseif ($action === 'quality_flag') {
            [$actionLogFile, $logger] = sv_create_operation_log($config, 'quality_flag', $actionLogs, 5);
            $requested = sv_limit_string((string)($_POST['quality_status'] ?? ''), 32);
            $requested = sv_normalize_quality_status($requested, SV_QUALITY_REVIEW);
            $score = isset($_POST['quality_score']) && is_numeric($_POST['quality_score']) ? (float)$_POST['quality_score'] : null;
            $notes = sv_limit_string((string)($_POST['quality_notes'] ?? ''), 500);
            $rule  = sv_limit_string((string)($_POST['quality_rule'] ?? ''), 120);
            $result = sv_set_media_quality_status($pdo, $id, $requested, $score, $notes, $rule, 'internal', $logger);
            $actionSuccess = true;
            $actionMessage = 'Curation-Status gesetzt: ' . $result['previous'] . ' → ' . $result['current'];
            } elseif ($action === 'request_delete') {
            [$actionLogFile, $logger] = sv_create_operation_log($config, 'request_delete', $actionLogs, 5);
            $reason = sv_limit_string((string)($_POST['delete_reason'] ?? ''), 240);
            $result = sv_set_media_lifecycle_status($pdo, $id, SV_LIFECYCLE_PENDING_DELETE, $reason, 'internal', $logger);
            $actionSuccess = true;
            $actionMessage = 'Lifecycle-Status aktualisiert: ' . $result['previous'] . ' → ' . $result['current'];
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
        }
    } catch (Throwable $e) {
        $actionSuccess = false;
        $safeError = sv_sanitize_error_message($e->getMessage());
        $actionMessage = 'Aktion fehlgeschlagen.';
        if ($safeError !== '') {
            $actionMessage .= ' ' . $safeError;
        }
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
    echo 'FSK18-Eintrag ausgeblendet. Interner Zugriff erforderlich.';
    exit;
}

if ($hasInternalAccess && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        sv_inc_media_meta_int($pdo, $id, 'activity.clicks', 1);
        sv_set_media_meta_value($pdo, $id, 'activity.last_click_at', time());
    } catch (Throwable $e) {
        // Aktivitäts-Tracking darf die Ansicht nicht blockieren.
    }
}

$promptStmt = $pdo->prepare('SELECT * FROM prompts WHERE media_id = :id ORDER BY id DESC LIMIT 1');
$promptStmt->execute([':id' => $id]);
$prompt = $promptStmt->fetch(PDO::FETCH_ASSOC) ?: null;

$promptHistory   = [];
$promptHistoryErr = null;
try {
    $histStmt = $pdo->prepare(
        'SELECT ph.*, p.id AS prompt_exists FROM prompt_history ph '
        . 'LEFT JOIN prompts p ON p.id = ph.prompt_id '
        . 'WHERE ph.media_id = :id ORDER BY ph.version DESC, ph.id DESC'
    );
    $histStmt->execute([':id' => $id]);
    $promptHistory = $histStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $promptHistoryErr = $e->getMessage();
}

$latestPromptHistory = $promptHistory[0] ?? null;
$promptHistoryHasIssues = false;
if ($promptHistory !== []) {
    foreach ($promptHistory as $entry) {
        $versionVal = (int)($entry['version'] ?? 0);
        $hasPromptId = !empty($entry['prompt_id']);
        $promptExists = !empty($entry['prompt_exists']);
        if (($hasPromptId && !$promptExists) || $versionVal <= 0) {
            $promptHistoryHasIssues = true;
            break;
        }
    }
}

$promptUpdatedAt = null;
if (is_array($latestPromptHistory) && !empty($latestPromptHistory['created_at'])) {
    $promptUpdatedAt = (string)$latestPromptHistory['created_at'];
}

$latestQualityEvent = null;
try {
    $qualityStmt = $pdo->prepare(
        'SELECT quality_status, quality_score, rule, reason, created_at '
        . 'FROM media_lifecycle_events WHERE media_id = :id AND event_type = :event '
        . 'ORDER BY created_at DESC, id DESC LIMIT 1'
    );
    $qualityStmt->execute([
        ':id'    => $id,
        ':event' => 'quality_eval',
    ]);
    $latestQualityEvent = $qualityStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $latestQualityEvent = null;
}
$curationUpdatedAt = null;
if (is_array($latestQualityEvent) && !empty($latestQualityEvent['created_at'])) {
    $curationUpdatedAt = (string)$latestQualityEvent['created_at'];
}

$metaStmt = $pdo->prepare('SELECT source, meta_key, meta_value FROM media_meta WHERE media_id = :id ORDER BY source, meta_key');
$metaStmt->execute([':id' => $id]);
$metaRows = $metaStmt->fetchAll(PDO::FETCH_ASSOC);
$hasStaleScan = false;

$ollamaTitle = sv_get_media_meta_value($pdo, $id, 'ollama.title');
$ollamaCaption = sv_get_media_meta_value($pdo, $id, 'ollama.caption');
$ollamaPromptScore = sv_get_media_meta_value($pdo, $id, 'ollama.prompt_eval.score');
$ollamaTagsNormalizedRaw = sv_get_media_meta_value($pdo, $id, 'ollama.tags_normalized');
$ollamaTagsNormalized = sv_decode_meta_json_list(is_string($ollamaTagsNormalizedRaw) ? $ollamaTagsNormalizedRaw : null);
$ollamaQualityScore = sv_get_media_meta_value($pdo, $id, 'ollama.quality.score');
$ollamaQualityFlagsRaw = sv_get_media_meta_value($pdo, $id, 'ollama.quality.flags');
$ollamaQualityFlags = sv_decode_meta_json_list(is_string($ollamaQualityFlagsRaw) ? $ollamaQualityFlagsRaw : null);
$ollamaDomainType = sv_get_media_meta_value($pdo, $id, 'ollama.domain.type');
$ollamaDomainConfidence = sv_get_media_meta_value($pdo, $id, 'ollama.domain.confidence');
$ollamaPromptReconPrompt = sv_get_media_meta_value($pdo, $id, 'ollama.prompt_recon.prompt');
$ollamaPromptReconNegative = sv_get_media_meta_value($pdo, $id, 'ollama.prompt_recon.negative');
$ollamaPromptReconConfidence = sv_get_media_meta_value($pdo, $id, 'ollama.prompt_recon.confidence');
$ollamaEmbedModel = sv_get_media_meta_value($pdo, $id, 'ollama.embed.text.model');
$ollamaEmbedDims = sv_get_media_meta_value($pdo, $id, 'ollama.embed.text.dims');
$ollamaEmbedHash = sv_get_media_meta_value($pdo, $id, 'ollama.embed.text.hash');
$ollamaDupeHintsRaw = sv_get_media_meta_value($pdo, $id, 'ollama.dupe_hints.top');
$ollamaDupeHints = [];
$ollamaDupeHintsCount = 0;
$ollamaDupeHintsTopScore = null;
if (is_string($ollamaDupeHintsRaw) && trim($ollamaDupeHintsRaw) !== '') {
    $decodedDupe = json_decode($ollamaDupeHintsRaw, true);
    if (is_array($decodedDupe)) {
        $ollamaDupeHints = $decodedDupe;
        $ollamaDupeHintsCount = count($decodedDupe);
        foreach ($decodedDupe as $entry) {
            if (is_array($entry) && isset($entry['score']) && is_numeric($entry['score'])) {
                $score = (float)$entry['score'];
                if ($ollamaDupeHintsTopScore === null || $score > $ollamaDupeHintsTopScore) {
                    $ollamaDupeHintsTopScore = $score;
                }
            }
        }
    }
}
$ollamaStageVersion = sv_get_media_meta_value($pdo, $id, 'ollama.stage_version');
$ollamaLastRunAt = sv_get_media_meta_value($pdo, $id, 'ollama.last_run_at');

$voteState = (int)(sv_get_media_meta_value($pdo, $id, 'vote.state') ?? 0);
$checkedFlag = (int)(sv_get_media_meta_value($pdo, $id, 'curation.checked') ?? 0) === 1;
$activityClicks = (int)(sv_get_media_meta_value($pdo, $id, 'activity.clicks') ?? 0);
$activityLastClick = sv_get_media_meta_value($pdo, $id, 'activity.last_click_at');
$activityLastTs = is_numeric($activityLastClick) ? (int)$activityLastClick : null;
$createdAtTs = !empty($media['created_at']) ? (int)strtotime((string)$media['created_at']) : 0;
$activityBaseTs = $activityLastTs ?? $createdAtTs ?? 0;
$activityScore = $activityClicks - (int)floor((time() - $activityBaseTs) / 86400);

$tagStmt = $pdo->prepare('SELECT t.name, t.type, mt.confidence, mt.locked FROM media_tags mt JOIN tags t ON t.id = mt.tag_id WHERE mt.media_id = :id ORDER BY t.type, t.name');
$tagStmt->execute([':id' => $id]);
$tags = $tagStmt->fetchAll(PDO::FETCH_ASSOC);
$latestScan = sv_fetch_latest_scan_result($pdo, $id);

$consistencyStatus = sv_media_consistency_status($pdo, $id);
$issueReport = sv_collect_integrity_issues($pdo, [$id]);
$mediaIssues = $issueReport['by_media'][$id] ?? [];
$versions = sv_get_media_versions($pdo, $id);
$requestedAssetType = isset($_GET['asset']) && is_string($_GET['asset']) ? strtolower(trim((string)$_GET['asset'])) : null;
$activeVersionIndex = 0;
foreach ($versions as $idx => $version) {
    $assetSet = sv_prepare_version_asset_set($version, $media);
    $versions[$idx]['_asset_set'] = $assetSet;
    if (!empty($version['is_current'])) {
        $activeVersionIndex = $idx;
    }
}
$requestedVersion = isset($_GET['version']) ? (int)$_GET['version'] : $activeVersionIndex;
if ($requestedVersion >= 0 && $requestedVersion < count($versions)) {
    $activeVersionIndex = $requestedVersion;
}
$activeVersion = $versions[$activeVersionIndex] ?? $versions[0];
$activeAssetSet = $activeVersion['_asset_set'] ?? sv_prepare_version_asset_set($activeVersion, $media);
$activeAssetSelection = sv_select_asset_from_set($activeAssetSet, $requestedAssetType);
$activeAssetWarning = ($requestedAssetType !== null && !in_array($requestedAssetType, $activeAssetSelection['options'], true))
    ? 'Asset-Auswahl nicht verfügbar, verwende ' . sv_asset_label($activeAssetSelection['type']) . '.'
    : null;
$activeUrls = sv_build_asset_urls($id, $showAdult, $activeAssetSelection);
$activePath = (string)($activeAssetSelection['path'] ?? ($activeVersion['output_path'] ?? ($media['path'] ?? '')));
if ($activePath === '') {
    $activePath = (string)($media['path'] ?? '');
}
$activePathLabel = sv_safe_path_label($activePath);
$activeHash = (string)($activeVersion['hash_display'] ?? ($media['hash'] ?? ''));
$activeWidth = $activeVersion['width'] ?? $media['width'] ?? null;
$activeHeight = $activeVersion['height'] ?? $media['height'] ?? null;
$activeModel = $activeVersion['model_used'] ?? $activeVersion['model_requested'] ?? ($prompt['model'] ?? '');
$forgeFallbackModel = sv_forge_fallback_model($config);
$modelDropdownValue = $latestRequestedModel ?? $activeModel;
$resolvedModelLabel = $latestResolvedModel
    ?? $activeVersion['model_used']
    ?? $activeModel
    ?? ($forgeFallbackModel ?: 'Auto');
$selectedModelLabel = $modelDropdownValue !== '' ? $modelDropdownValue : 'Auto';
$forgeEnabled = sv_is_forge_enabled($config);
$forgeDispatchEnabled = sv_is_forge_dispatch_enabled($config);
$forgeModelSourceLabel = match ($forgeModelSource) {
    'cache'        => 'Cache',
    'stale_cache'  => 'Cache (alt)',
    'fallback'     => 'Fallback',
    'restricted'   => 'Internal-Key nötig',
    'disabled'     => 'Forge aus',
    'none'         => 'nicht geladen',
    default        => 'Live',
};
if ($forgeModelAge !== null) {
    $forgeModelSourceLabel .= ' · ' . (int)$forgeModelAge . 's';
}
$forgeModelStatusLabel = match ($forgeModelStatus) {
    'ok'           => 'OK',
    'disabled'     => 'Forge deaktiviert',
    'auth_failed'  => 'Auth fehlgeschlagen',
    'timeout'      => 'Timeout',
    'parse_error'  => 'Parse-Fehler',
    'http_error'   => 'HTTP-Fehler',
    'transport_error' => 'Transport-Fehler',
    'restricted'   => 'kein Zugriff',
    'unavailable'  => 'nicht erreichbar',
    default        => (string)$forgeModelStatus,
};
$activeSampler = $activeVersion['sampler'] ?? ($prompt['sampler'] ?? '');
$activeScheduler = $activeVersion['scheduler'] ?? ($prompt['scheduler'] ?? '');
$activeSeed = $activeVersion['seed'] ?? ($prompt['seed'] ?? '');
$activeSteps = $activeVersion['steps'] ?? ($prompt['steps'] ?? '');
$activeCfg = $activeVersion['cfg_scale'] ?? ($prompt['cfg_scale'] ?? '');
$activeAssetExists = (bool)($activeAssetSelection['exists'] ?? false);
$activeThumbUrl = $activeUrls['thumb'] ?? '';
$activeStreamUrl = $activeUrls['stream'] ?? '';
$secondaryAssetSelection = null;
foreach ($activeAssetSelection['options'] as $candidate) {
    if ($candidate !== $activeAssetSelection['type']) {
        $secondaryAssetSelection = sv_select_asset_from_set($activeAssetSet, $candidate);
        break;
    }
}
$secondaryAssetUrls = $secondaryAssetSelection ? sv_build_asset_urls($id, $showAdult, $secondaryAssetSelection) : [];
$versionCompareA = isset($_GET['compare_a']) ? (int)$_GET['compare_a'] : $activeVersionIndex;
$versionCompareB = isset($_GET['compare_b']) ? (int)$_GET['compare_b'] : $activeVersionIndex;
if ($versionCompareA < 0 || $versionCompareA >= count($versions)) {
    $versionCompareA = $activeVersionIndex;
}
if ($versionCompareB < 0 || $versionCompareB >= count($versions)) {
    $versionCompareB = $activeVersionIndex;
}
$compareVersionA = $versions[$versionCompareA] ?? null;
$compareVersionB = $versions[$versionCompareB] ?? null;
$compareAssetTypeA = isset($_GET['compare_asset_a']) && is_string($_GET['compare_asset_a']) ? strtolower(trim((string)$_GET['compare_asset_a'])) : null;
$compareAssetTypeB = isset($_GET['compare_asset_b']) && is_string($_GET['compare_asset_b']) ? strtolower(trim((string)$_GET['compare_asset_b'])) : null;
$compareAssetSelectionA = $compareVersionA ? sv_select_asset_from_set($compareVersionA['_asset_set'] ?? sv_prepare_version_asset_set($compareVersionA, $media), $compareAssetTypeA) : null;
$compareAssetSelectionB = $compareVersionB ? sv_select_asset_from_set($compareVersionB['_asset_set'] ?? sv_prepare_version_asset_set($compareVersionB, $media), $compareAssetTypeB) : null;
$compareAssetUrlsA = $compareAssetSelectionA ? sv_build_asset_urls($id, $showAdult, $compareAssetSelectionA) : [];
$compareAssetUrlsB = $compareAssetSelectionB ? sv_build_asset_urls($id, $showAdult, $compareAssetSelectionB) : [];
$compareIdenticalSources = $compareAssetSelectionA && $compareAssetSelectionB
    ? (($compareAssetSelectionA['path'] ?? '') !== '' && ($compareAssetSelectionA['path'] ?? '') === ($compareAssetSelectionB['path'] ?? ''))
    : false;
$versionDiff = [];
if ($compareVersionA && $compareVersionB && $versionCompareA !== $versionCompareB) {
    $fields = ['model_used', 'sampler', 'scheduler', 'steps', 'cfg_scale', 'seed', 'width', 'height', 'hash_display', 'prompt_category', 'mode'];
    foreach ($fields as $field) {
        $aVal = $compareVersionA[$field] ?? ($compareVersionA['model_requested'] ?? null);
        $bVal = $compareVersionB[$field] ?? ($compareVersionB['model_requested'] ?? null);
        if ($aVal !== $bVal) {
            $versionDiff[] = [$field, $aVal, $bVal];
        }
    }
}

$groupedMeta = [];
foreach ($metaRows as $meta) {
    $src = (string)$meta['source'];
    if ((string)$meta['meta_key'] === 'scan_stale') {
        $hasStaleScan = true;
    }
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

function sv_simple_line_diff(string $old, string $new): array
{
    $oldLines = explode("\n", str_replace(["\r\n", "\r"], "\n", $old));
    $newLines = explode("\n", str_replace(["\r\n", "\r"], "\n", $new));
    $max = max(count($oldLines), count($newLines));
    $diff = [];
    for ($i = 0; $i < $max; $i++) {
        $o = $oldLines[$i] ?? '';
        $n = $newLines[$i] ?? '';
        if ($o === $n) {
            $diff[] = ['type' => 'same', 'value' => $o];
        } else {
            if ($o !== '') {
                $diff[] = ['type' => 'remove', 'value' => $o];
            }
            if ($n !== '') {
                $diff[] = ['type' => 'add', 'value' => $n];
            }
        }
    }
    return $diff;
}

function sv_prepare_version_asset_set(array $version, array $media): array
{
    $jobId = isset($version['job_id']) ? (int)$version['job_id'] : null;
    $mode  = strtolower((string)($version['mode'] ?? ''));
    $primaryType = $jobId === null ? 'baseline' : ($mode === 'preview' ? 'preview' : 'output');

    $variants = [];
    $outputPath = (string)($version['output_path'] ?? ($media['path'] ?? ''));
    if ($outputPath !== '') {
        $variants[$primaryType] = $outputPath;
    }
    if ($jobId === null && ($media['path'] ?? '') !== '') {
        $variants['baseline'] = (string)$media['path'];
        $primaryType = 'baseline';
    }
    if ($jobId !== null && !empty($version['backup_path'])) {
        $variants['backup'] = (string)$version['backup_path'];
    }
    if (!isset($variants[$primaryType]) && $variants !== []) {
        $primaryType = array_keys($variants)[0];
    }

    return [
        'job_id'        => $jobId,
        'primary_type'  => $primaryType,
        'variants'      => $variants,
        'version_token' => $version['version_token'] ?? null,
        'status'        => $version['status'] ?? null,
    ];
}

function sv_select_asset_from_set(array $assetSet, ?string $requestedType = null): array
{
    $type = $assetSet['primary_type'] ?? 'baseline';
    if ($requestedType !== null && isset($assetSet['variants'][$requestedType])) {
        $type = $requestedType;
    } elseif (!isset($assetSet['variants'][$type])) {
        $keys = array_keys($assetSet['variants']);
        $type = $keys[0] ?? $type;
    }

    $path = $assetSet['variants'][$type] ?? '';

    return [
        'type'          => $type,
        'path'          => $path,
        'job_id'        => $assetSet['job_id'] ?? null,
        'version_token' => $assetSet['version_token'] ?? null,
        'status'        => $assetSet['status'] ?? null,
        'exists'        => is_string($path) && $path !== '' && is_file($path),
        'options'       => array_keys($assetSet['variants']),
    ];
}

function sv_asset_label(string $type): string
{
    return match ($type) {
        'preview' => 'Preview',
        'backup'  => 'Backup',
        'output'  => 'Output',
        default   => 'Baseline',
    };
}

function sv_build_asset_urls(int $mediaId, bool $adult, array $selection): array
{
    $params = ['adult' => $adult ? '1' : '0'];
    if (!empty($selection['job_id'])) {
        $params['job_id'] = (int)$selection['job_id'];
        $params['asset']  = (string)$selection['type'];
    } else {
        $params['id'] = $mediaId;
    }

    if (!empty($selection['version_token'])) {
        $params['v'] = (string)$selection['version_token'];
    }

    $query = http_build_query($params);

    return [
        'thumb'  => 'thumb.php?' . $query,
        'stream' => 'media_stream.php?' . $query,
    ];
}

$promptExists   = $prompt !== null;
$promptText     = trim((string)($prompt['prompt'] ?? ''));
$needsRebuild   = !$consistencyStatus['prompt_complete'];
$negativePrompt = trim((string)($prompt['negative_prompt'] ?? ''));
$showRebuildButton = $needsRebuild || (!empty($prompt['source_metadata']) && $promptText !== '');

$displayPromptText    = trim((string)($activeVersion['prompt'] ?? $promptText));
$displayNegativePrompt = trim((string)($activeVersion['negative_prompt'] ?? $negativePrompt));
$promptParams = [];
$paramModel     = $activeModel ?: ($prompt['model'] ?? '');
$paramSampler   = $activeSampler ?: ($prompt['sampler'] ?? '');
$paramScheduler = $activeScheduler ?: ($prompt['scheduler'] ?? '');
$paramSteps     = $activeSteps ?: ($prompt['steps'] ?? null);
$paramCfg       = $activeCfg ?: ($prompt['cfg_scale'] ?? null);
$paramSeed      = $activeSeed ?: ($prompt['seed'] ?? '');
$paramWidth     = $activeWidth ?? ($prompt['width'] ?? null);
$paramHeight    = $activeHeight ?? ($prompt['height'] ?? null);

if (($paramModel ?? '') !== '') {
    $promptParams['Model'] = (string)$paramModel;
}
if (($paramSampler ?? '') !== '') {
    $promptParams['Sampler'] = (string)$paramSampler;
}
if ($paramSteps !== null && $paramSteps !== '') {
    $promptParams['Steps'] = (string)$paramSteps;
}
if ($paramCfg !== null && $paramCfg !== '') {
    $promptParams['CFG Scale'] = (string)$paramCfg;
}
if (($paramSeed ?? '') !== '') {
    $promptParams['Seed'] = (string)$paramSeed;
}
if ($paramWidth !== null || $paramHeight !== null) {
    $promptParams['Size'] = trim((string)($paramWidth ?? '-')) . ' × ' . trim((string)($paramHeight ?? '-'));
}
if (($paramScheduler ?? '') !== '') {
    $promptParams['Scheduler'] = (string)$paramScheduler;
}
$promptQuality = sv_analyze_prompt_quality($prompt, $tags);
$promptQualityIssues = array_slice($promptQuality['issues'] ?? [], 0, 3);
$promptQualityLabels = sv_prompt_quality_labels();
$qualityStatusLabels = sv_quality_status_labels();

$lifecycleStatus = (string)($media['lifecycle_status'] ?? SV_LIFECYCLE_ACTIVE);
$lifecycleReason = (string)($media['lifecycle_reason'] ?? '');
$qualityStatus   = sv_normalize_quality_status((string)($media['quality_status'] ?? ''), SV_QUALITY_UNKNOWN);
$qualityScore    = $media['quality_score'] ?? null;
$qualityNotes    = (string)($media['quality_notes'] ?? '');
$qualityLabel    = $qualityStatusLabels[$qualityStatus] ?? $qualityStatus;
$qualityBadgeClass = match ($qualityStatus) {
    SV_QUALITY_OK      => 'pill',
    SV_QUALITY_REVIEW  => 'pill-warn',
    SV_QUALITY_BLOCKED => 'pill-bad',
    default            => 'pill-muted',
};
$promptQualityClass = (string)($promptQuality['quality_class'] ?? 'C');
$promptQualityBadgeClass = $promptQualityClass === 'A'
    ? 'pill'
    : ($promptQualityClass === 'B' ? 'pill-muted' : 'pill-warn');

$compareFromId = (int)($_GET['compare_from'] ?? 0);
$compareToId   = (int)($_GET['compare_to'] ?? 0);
$compareResult = null;
if ($compareFromId > 0 && $compareToId > 0 && $promptHistory !== []) {
    $from = null;
    $to   = null;
    foreach ($promptHistory as $entry) {
        $entryId = (int)($entry['id'] ?? 0);
        if ($entryId === $compareFromId) {
            $from = $entry;
        }
        if ($entryId === $compareToId) {
            $to = $entry;
        }
    }
    if ($from && $to) {
        $fromText = (string)($from['prompt'] ?? '');
        if (trim($from['raw_text'] ?? '') !== '') {
            $fromText = (string)$from['raw_text'];
        }
        $toText = (string)($to['prompt'] ?? '');
        if (trim($to['raw_text'] ?? '') !== '') {
            $toText = (string)$to['raw_text'];
        }
        $compareResult = [
            'from' => $from,
            'to'   => $to,
            'diff' => sv_simple_line_diff($fromText, $toText),
        ];
    }
}

$actionLogsSafe = array_map(
    static fn (string $line): string => sv_sanitize_error_message($line, 400),
    $actionLogs
);

$type = (string)$media['type'];
$isMissing = (int)($media['is_missing'] ?? 0) === 1 || (string)($media['status'] ?? '') === 'missing';
$hasFileIssue = array_reduce($mediaIssues, static function (bool $carry, array $issue): bool {
    return $carry || ((string)($issue['type'] ?? '') === 'file');
}, false);
$fileTitle = $media['path'] ? basename((string)$media['path']) : ('Media #' . $id);
$nsfwFlag = (int)($media['has_nsfw'] ?? 0) === 1;
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
$thumbUrl = $activeThumbUrl;
$thumbHasVersion = strpos($thumbUrl, 'v=') !== false;
$thumbVersion = (string)($activeAssetSelection['version_token'] ?? $activeVersion['version_token'] ?? $activeHash ?? '');
if ($thumbVersion === '' && is_file($activePath)) {
    $thumbVersion = (string)@filemtime($activePath);
}
if (!$thumbHasVersion && $thumbVersion !== '') {
    $thumbUrl .= (strpos($thumbUrl, '?') !== false ? '&' : '?') . 'v=' . rawurlencode($thumbVersion);
}

$latestPreview = null;
$latestJobResponse = null;
$latestJobRequest = null;
$latestJobStatus = null;
$latestJobError = null;
$latestJobUpdated = null;
$latestRequestedModel = null;
$latestResolvedModel = null;
$latestModelSource = null;
$latestModelStatus = null;
$latestModelError = null;
$manualOverrideActive = false;
$latestJobStmt = $pdo->prepare('SELECT id, status, forge_response_json, forge_request_json, error_message, updated_at FROM jobs WHERE type = :type AND media_id = :media_id ORDER BY id DESC LIMIT 1');
$latestJobStmt->execute([':type' => SV_FORGE_JOB_TYPE, ':media_id' => $id]);
$latestJobRow = $latestJobStmt->fetch(PDO::FETCH_ASSOC);
if ($latestJobRow) {
    $latestJobId = (int)$latestJobRow['id'];
    $latestJobResponse = json_decode((string)($latestJobRow['forge_response_json'] ?? ''), true) ?: [];
    $latestJobRequest  = json_decode((string)($latestJobRow['forge_request_json'] ?? ''), true) ?: [];
    $latestJobStatus   = (string)($latestJobRow['status'] ?? '');
    $latestJobError    = isset($latestJobRow['error_message']) ? sv_limit_string((string)$latestJobRow['error_message'], 240) : null;
    $latestJobUpdated  = (string)($latestJobRow['updated_at'] ?? '');
    $latestRequestedModel = $latestJobRequest['_sv_requested_model'] ?? null;
    $latestResolvedModel  = $latestJobResponse['result']['model']
        ?? ($latestJobRequest['model'] ?? null);
    $latestModelSource = $latestJobResponse['result']['model_source'] ?? ($latestJobRequest['_sv_model_source'] ?? null);
    $latestModelStatus = $latestJobResponse['result']['model_status'] ?? ($latestJobRequest['_sv_model_status'] ?? null);
    $latestModelError  = $latestJobResponse['result']['model_error'] ?? ($latestJobRequest['_sv_model_error'] ?? null);

    $responseMode = (string)($latestJobResponse['mode'] ?? $latestJobRequest['_sv_mode'] ?? '');
    $responseResult = is_array($latestJobResponse['result'] ?? null) ? $latestJobResponse['result'] : [];
    if ((string)($latestJobRow['status'] ?? '') === 'done' && $responseMode === 'preview') {
        $previewPath = (string)($latestJobResponse['preview_path'] ?? '');
        $previewAllowed = false;
        $previewError   = null;
        $previewVersion = $latestJobResponse['preview_hash'] ?? null;
        if ($previewPath !== '') {
            try {
                sv_assert_stream_path_allowed($previewPath, $config, 'forge_preview', true, true);
                $previewAllowed = true;
            } catch (Throwable $e) {
                $previewError = $e->getMessage();
            }

            if ($previewAllowed && is_file($previewPath)) {
                $previewVersion = $previewVersion ?? (@filemtime($previewPath) ?: null);
            }
        }
        $previewUrls = null;
        if ($previewAllowed) {
            $previewSelection = [
                'job_id'        => $latestJobId,
                'type'          => 'preview',
                'version_token' => $previewVersion,
            ];
            $previewUrls = sv_build_asset_urls($id, $showAdult, $previewSelection);
        }

        $manualOverrideActive = false;
        if (is_array($latestJobRequest)) {
            $manualOverrideActive = (($latestJobRequest['_sv_prompt_source'] ?? '') === 'manual')
                || (!empty($latestJobRequest['_sv_regen_plan']['manual_prompt']));
        }

        $latestPreview = [
            'job_id'      => $latestJobId,
            'path'        => ($previewVersion !== null && $previewPath !== '')
                ? ($previewPath . '?v=' . rawurlencode((string)$previewVersion))
                : $previewPath,
            'source_path' => $previewPath,
            'hash'     => $latestJobResponse['preview_hash'] ?? null,
            'width'    => $latestJobResponse['preview_width'] ?? null,
            'height'   => $latestJobResponse['preview_height'] ?? null,
            'filesize' => $latestJobResponse['preview_filesize'] ?? null,
            'allowed'  => $previewAllowed,
            'error'    => $previewError,
            'version'  => $previewVersion,
            'urls'     => $previewUrls ?? [],
        ];
    }
}
$rescanJobs = sv_fetch_rescan_jobs_for_media($pdo, $id, 5);
$rescanLastError = null;
$activeRescanJob = null;
foreach ($rescanJobs as $job) {
    if ($activeRescanJob === null) {
        $activeRescanJob = $job;
    }
    if (!empty($job['error'])) {
        $rescanLastError = $job['error'];
        break;
    }
}
$latestScanRunAt       = (string)($latestScan['run_at'] ?? '');
$latestScanScanner     = (string)($latestScan['scanner'] ?? '');
$latestScanNsfw        = $latestScan['nsfw_score'] ?? null;
$latestScanRating      = $latestScan['rating'] ?? null;
$latestScanHasNsfw     = $latestScan['has_nsfw'] ?? null;
$latestScanTagsWritten = $latestScan['tags_written'] ?? null;
$latestScanError       = $latestScan['error'] ?? null;
$latestScanErrorCode   = $latestScan['error_code'] ?? null;
$latestScanHttpStatus  = $latestScan['http_status'] ?? null;
$latestScanEndpoint    = $latestScan['endpoint'] ?? null;
$latestScanResponseType = $latestScan['response_type_detected'] ?? null;
$latestScanBodySnippet = $latestScan['body_snippet'] ?? null;
$latestScanMetaText    = $latestScan
    ? ('Scanner: ' . ($latestScanScanner !== '' ? $latestScanScanner : 'unknown')
        . ' · NSFW: ' . ($latestScanNsfw !== null ? $latestScanNsfw : '–')
        . ' · Rating: ' . ($latestScanRating !== null ? $latestScanRating : '–')
        . ' · Flag: ' . ($latestScanHasNsfw === null ? '–' : ((int)$latestScanHasNsfw === 1 ? 'NSFW' : 'SFW')))
    : 'Kein Eintrag';
$latestScanTagsText    = $latestScanTagsWritten !== null ? ((int)$latestScanTagsWritten . ' Tags') : '–';
$downloadUrl = $activeStreamUrl !== '' ? $activeStreamUrl . (str_contains($activeStreamUrl, '?') ? '&' : '?') . 'dl=1' : '';
$metaResolution = ($activeWidth && $activeHeight) ? ($activeWidth . '×' . $activeHeight) : '–';
$metaSize = !empty($media['filesize']) ? (string)$media['filesize'] : '–';
$metaDuration = !empty($media['duration']) ? number_format((float)$media['duration'], 1) . 's' : '–';
$metaFps = !empty($media['fps']) ? (string)$media['fps'] : '–';
$metaScanner = $latestScanScanner !== '' ? $latestScanScanner : '–';
$metaScanAt = $latestScanRunAt !== '' ? $latestScanRunAt : '–';
?>
<?php sv_ui_header('Medium #' . (int)$id, 'medien'); ?>
<div class="media-view-shell" id="media-top">
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
        <div class="banner banner--warn">
            <?= htmlspecialchars($configWarning, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <header class="media-header">
        <div class="title-wrap">
            <h1><?= htmlspecialchars($fileTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
            <div class="subtitle">ID <?= (int)$id ?> • Typ: <?= htmlspecialchars($type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        </div>
        <div class="header-info">
            <?php if ($hasInternalAccess): ?>
                <form method="post" class="nsfw-toggle-form">
                    <input type="hidden" name="media_id" value="<?= (int)$id ?>">
                    <input type="hidden" name="action" value="toggle_nsfw">
                    <label>FSK18
                        <select name="nsfw_value">
                            <option value="0" <?= !$nsfwFlag ? 'selected' : '' ?>>nein</option>
                            <option value="1" <?= $nsfwFlag ? 'selected' : '' ?>>ja</option>
                        </select>
                    </label>
                    <button class="btn btn--secondary btn--sm" type="submit">Speichern</button>
                </form>
            <?php else: ?>
                <div class="nsfw-toggle-form">
                    <strong>FSK18:</strong> <?= $nsfwFlag ? 'ja' : 'nein' ?>
                </div>
            <?php endif; ?>
            <div class="badge-row">
                <span class="pill">Status: <?= htmlspecialchars((string)($media['status'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <span class="pill">Lifecycle: <?= htmlspecialchars($lifecycleStatus, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <span class="<?= htmlspecialchars($qualityBadgeClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Curation: <?= htmlspecialchars($qualityLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <span class="<?= htmlspecialchars($promptQualityBadgeClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Prompt: <?= htmlspecialchars($promptQualityClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <?php if ($hasStaleScan): ?><span class="pill pill-warn">Scan veraltet</span><?php endif; ?>
                <?php if (!empty($media['rating'])): ?><span class="pill">Rating <?= htmlspecialchars((string)$media['rating'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
                <?php if ($hasInternalAccess): ?>
                    <span class="pill">Vote <?= $voteState ?></span>
                    <span class="pill"><?= $checkedFlag ? 'Geprüft' : 'Offen' ?></span>
                <?php endif; ?>
            </div>
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
    $iconRescan = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 12a7.5 7.5 0 0 1 12.9-5.1l1.1-1.1V9.5h-3.7l1.4-1.4A6 6 0 1 0 18 12h1.5A7.5 7.5 0 0 1 4.5 12z" fill="currentColor"/></svg>';
    $iconUp = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5l7 7h-4v7H9v-7H5l7-7z" fill="currentColor"/></svg>';
    $iconDown = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19l-7-7h4V5h6v7h4l-7 7z" fill="currentColor"/></svg>';
    $iconCheck = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.2 16.2L5.5 12.5l-1.5 1.5 5.2 5.2L20 8.4 18.5 7z" fill="currentColor"/></svg>';
    ?>

    
    <div class="tab-bar" data-tab-group="media" data-tab-persist="hash">
        <button class="tab-button" data-tab="tab-preview" data-tab-default="true">Vorschau</button>
        <?php if ($type === 'video'): ?>
            <button class="tab-button" data-tab="tab-video">Video</button>
        <?php endif; ?>
        <button class="tab-button" data-tab="tab-tags">Tags</button>
        <button class="tab-button" data-tab="tab-prompt">Prompt</button>
        <button class="tab-button" data-tab="tab-jobs">Scan/Jobs</button>
        <button class="tab-button" data-tab="tab-versions">Versionen</button>
    </div>

    <div class="tab-content" id="tab-preview" data-tab-panel="media">
        <div class="media-main-grid">
            <div class="media-left">
                <div class="panel media-visual" id="visual">
                    <div class="version-switch">
                        <label>Version auswählen
                            <select id="version-select">
                                <?php foreach ($versions as $idx => $version): ?>
                                    <?php
                                    $versionAssetSet = $version['_asset_set'] ?? sv_prepare_version_asset_set($version, $media);
                                    $versionAssetLabel = sv_asset_label($versionAssetSet['primary_type'] ?? 'baseline');
                                    $versionJobLabel = !empty($version['job_id']) ? (' · Job #' . (int)$version['job_id']) : '';
                                    ?>
                                    <option value="<?= (int)$idx ?>" <?= $idx === $activeVersionIndex ? 'selected' : '' ?>>
                                        <?= htmlspecialchars('V' . (int)$version['version_index'] . ' · ' . ($version['status'] ?? '') . ' · ' . $versionAssetLabel . $versionJobLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <div class="version-meta">
                            Aktiv: <?= htmlspecialchars(sv_asset_label((string)$activeAssetSelection['type']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            <?= $activeAssetSelection['job_id'] ? ' · Job #' . (int)$activeAssetSelection['job_id'] : '' ?>
                            <?= !empty($activeVersion['status']) ? ' · ' . htmlspecialchars((string)$activeVersion['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
                        </div>
                        <?php if ($activeAssetWarning): ?>
                            <div class="version-meta hint"><?= htmlspecialchars($activeAssetWarning, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <?php if (count($activeAssetSelection['options']) > 1): ?>
                            <div class="version-meta">
                                <label>Asset
                                    <select id="asset-select">
                                        <?php foreach ($activeAssetSelection['options'] as $option): ?>
                                            <option value="<?= htmlspecialchars((string)$option, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $option === $activeAssetSelection['type'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars(sv_asset_label((string)$option), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($type === 'image'): ?>
                        <div class="preview-grid">
                            <div class="preview-card">
                                <div class="preview-label original">AKTIV: <?= htmlspecialchars(sv_asset_label((string)$activeAssetSelection['type']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                <div class="preview-frame">
                                    <?php if ($activeAssetExists): ?>
                                        <img id="media-preview-thumb" class="full-preview" src="<?= htmlspecialchars($thumbUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="Vorschau" data-full-info="<?= htmlspecialchars(json_encode([
                                            'path'   => $activePathLabel,
                                            'hash'   => $activeHash,
                                            'width'  => $activeWidth,
                                            'height' => $activeHeight,
                                            'size'   => (int)($media['filesize'] ?? 0),
                                        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                    <?php else: ?>
                                        <div class="preview-placeholder">
                                            <div class="placeholder-title">Asset nicht gefunden</div>
                                            <?php if (!empty($activeAssetSelection['path'])): ?>
                                                <div class="placeholder-meta"><?= htmlspecialchars(sv_safe_path_label((string)$activeAssetSelection['path']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="preview-meta">
                                    <span><?= htmlspecialchars($activePathLabel !== '' ? $activePathLabel : '–', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                    <span><?= htmlspecialchars(sv_asset_label((string)$activeAssetSelection['type']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                    <?php if ($activeAssetSelection['job_id']): ?><span>Job #<?= (int)$activeAssetSelection['job_id'] ?></span><?php endif; ?>
                                    <?php if (!$activeAssetExists): ?><span class="pill pill-warn">Asset fehlt</span><?php endif; ?>
                                </div>
                            </div>
                            <?php if ($secondaryAssetSelection !== null): ?>
                                <?php $secondaryLabel = sv_asset_label((string)$secondaryAssetSelection['type']); ?>
                                <div class="preview-card">
                                    <div class="preview-label preview"><?= htmlspecialchars($secondaryLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                    <div class="preview-frame">
                                        <?php if (!empty($secondaryAssetUrls['thumb'])): ?>
                                            <img src="<?= htmlspecialchars((string)$secondaryAssetUrls['thumb'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="<?= htmlspecialchars($secondaryLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                        <?php else: ?>
                                            <div class="preview-placeholder">
                                                <div class="placeholder-title">Kein Bild verfügbar</div>
                                                <?php if (!empty($secondaryAssetSelection['path'])): ?>
                                                    <div class="placeholder-meta"><?= htmlspecialchars(sv_safe_path_label((string)$secondaryAssetSelection['path']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="preview-meta">
                                        <span><?= htmlspecialchars($secondaryLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                        <?php if (!empty($secondaryAssetSelection['job_id'])): ?><span>Job #<?= (int)$secondaryAssetSelection['job_id'] ?></span><?php endif; ?>
                                        <?php if (!empty($secondaryAssetSelection['path'])): ?><span class="pill pill-muted" title="<?= htmlspecialchars(sv_safe_path_label((string)$secondaryAssetSelection['path']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Pfad</span><?php endif; ?>
                                    </div>
                                </div>
                            <?php elseif ($latestPreview !== null): ?>
                                <div class="preview-card">
                                    <div class="preview-label preview">PREVIEW</div>
                                    <div class="preview-frame">
                                        <?php if (!empty($latestPreview['urls']['thumb'])): ?>
                                            <img src="<?= htmlspecialchars((string)$latestPreview['urls']['thumb'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="Preview">
                                        <?php else: ?>
                                            <div class="preview-placeholder">
                                                <div class="placeholder-title">Keine Inline-Vorschau</div>
                                                <?php if ($latestPreview['path'] !== ''): ?>
                                                    <div class="placeholder-meta">Pfad: <?= htmlspecialchars(sv_safe_path_label((string)$latestPreview['path']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                                <?php endif; ?>
                                                <?php if ($latestPreview['error']): ?>
                                                    <div class="placeholder-meta">Hinweis: <?= htmlspecialchars(sv_sanitize_error_message((string)$latestPreview['error']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                                <?php elseif (!$latestPreview['allowed']): ?>
                                                    <div class="placeholder-meta">Preview nicht streambar, Root nicht erlaubt.</div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
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
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="media-right">
                <div class="panel meta-panel">
                    <div class="panel-header">Meta</div>
                    <div class="media-meta-grid">
                        <div class="media-meta-item"><span>Pfad</span><strong><?= htmlspecialchars($activePathLabel !== '' ? $activePathLabel : '–', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></div>
                        <div class="media-meta-item"><span>Hash</span><strong><?= htmlspecialchars($activeHash !== '' ? $activeHash : '–', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></div>
                        <div class="media-meta-item"><span>Auflösung</span><strong><?= htmlspecialchars($metaResolution, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></div>
                        <div class="media-meta-item"><span>Size</span><strong><?= htmlspecialchars($metaSize, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></div>
                        <div class="media-meta-item"><span>Dauer</span><strong><?= htmlspecialchars($metaDuration, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></div>
                        <div class="media-meta-item"><span>FPS</span><strong><?= htmlspecialchars($metaFps, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></div>
                        <div class="media-meta-item"><span>Scanner</span><strong><?= htmlspecialchars($metaScanner, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></div>
                        <div class="media-meta-item"><span>Letzter Scan</span><strong><?= htmlspecialchars($metaScanAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($type === 'video'): ?>
        <div class="tab-content" id="tab-video" data-tab-panel="media">
            <div class="panel">
                <div class="panel-header">Video</div>
                <div class="video-tool" data-video-tool>
                    <div class="video-player">
                        <video id="media-video" controls preload="metadata" poster="<?= htmlspecialchars($thumbUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" src="<?= htmlspecialchars($activeStreamUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></video>
                    </div>
                    <div class="video-controls">
                        <label>Speed
                            <select data-video-speed>
                                <option value="0.5">0.5×</option>
                                <option value="1" selected>1×</option>
                                <option value="1.25">1.25×</option>
                                <option value="1.5">1.5×</option>
                                <option value="2">2×</option>
                            </select>
                        </label>
                        <button type="button" class="btn btn--ghost btn--sm" data-video-loop aria-pressed="false">Loop</button>
                        <label>Jump (Sek.)
                            <input type="number" min="0" step="1" value="0" data-video-jump>
                        </label>
                        <button type="button" class="btn btn--secondary btn--sm" data-video-jump-btn>Springen</button>
                        <button type="button" class="btn btn--secondary btn--sm" data-video-snapshot>Frame Screenshot</button>
                        <?php if ($downloadUrl !== ''): ?>
                            <a class="btn btn--primary btn--sm" href="<?= htmlspecialchars($downloadUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Download</a>
                        <?php endif; ?>
                    </div>
                    <canvas class="is-hidden"></canvas>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="tab-content" id="tab-tags" data-tab-panel="media">
        <div class="panel">
            <div class="panel-header">Tags</div>
            <?php if ($tags === []): ?>
                <div class="tab-hint">Keine Tags gespeichert.</div>
            <?php else: ?>
                <div class="chip-list">
                    <?php foreach ($tags as $tag):
                        $tagType = preg_replace('~[^a-z0-9_-]+~i', '', (string)($tag['type'] ?? 'other')) ?: 'other';
                        $conf = isset($tag['confidence']) ? number_format((float)$tag['confidence'], 2) : null;
                        $locked = (int)($tag['locked'] ?? 0) === 1;
                        ?>
                        <span class="chip tag-type-<?= htmlspecialchars($tagType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                            <?= htmlspecialchars((string)$tag['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= $conf !== null ? ' (' . htmlspecialchars($conf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ')' : '' ?><?= $locked ? ' · locked' : '' ?>
                        </span>
                    <?php endforeach; ?>
                </div>
                <?php if (count($tags) > 30): ?><div class="tab-hint">Weitere Tags per „Mehr anzeigen“ sichtbar.</div><?php endif; ?>
            <?php endif; ?>
            <?php if ($hasInternalAccess): ?>
                <form method="post" class="stacked tag-form">
                    <input type="hidden" name="media_id" value="<?= (int)$id ?>">
                    <input type="hidden" name="action" value="tag_add" id="tag-action-field">
                    <label>Tag-Name<input type="text" name="tag_name" maxlength="120" required></label>
                    <label>Typ (optional)<input type="text" name="tag_type" maxlength="64" placeholder="content/style/meta"></label>
                    <label>Confidence<input type="number" step="0.01" min="0" max="1" name="tag_confidence" placeholder="1.0"></label>
                    <label class="checkbox-inline"><input type="checkbox" name="tag_lock_flag" value="1"> sofort sperren</label>
                    <div class="button-stack inline">
                        <button class="btn btn--secondary btn--sm tag-action" type="submit" data-action="tag_add">Tag hinzufügen/aktualisieren</button>
                        <button class="btn btn--ghost btn--sm tag-action" type="submit" data-action="tag_lock">Lock</button>
                        <button class="btn btn--ghost btn--sm tag-action" type="submit" data-action="tag_unlock">Unlock</button>
                        <button class="btn btn--danger btn--sm tag-action" type="submit" data-action="tag_remove">Entfernen</button>
                    </div>
                </form>
            <?php else: ?>
                <div class="tab-hint">Tags bearbeiten ist im Public-Modus deaktiviert.</div>
            <?php endif; ?>
        </div>

        <div class="panel">
            <div class="panel-header">OLLAMA Ergebnisse</div>
            <div class="meta-grid">
                <div class="meta-row"><span>Titel</span><strong><?= htmlspecialchars($ollamaTitle !== null && $ollamaTitle !== '' ? (string)$ollamaTitle : '–', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></div>
                <div class="meta-row"><span>Caption</span><strong><?= htmlspecialchars($ollamaCaption !== null && $ollamaCaption !== '' ? (string)$ollamaCaption : '–', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></div>
                <div class="meta-row"><span>Prompt-Eval</span><strong><?= htmlspecialchars($ollamaPromptScore !== null && $ollamaPromptScore !== '' ? (string)$ollamaPromptScore : '–', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></div>
                <div class="meta-row"><span>Quality</span><strong><?= htmlspecialchars($ollamaQualityScore !== null && $ollamaQualityScore !== '' ? (string)$ollamaQualityScore : '–', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></div>
                <div class="meta-row"><span>Domain</span><strong><?= htmlspecialchars($ollamaDomainType !== null && $ollamaDomainType !== '' ? (string)$ollamaDomainType : '–', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= $ollamaDomainConfidence !== null && $ollamaDomainConfidence !== '' ? ' (' . htmlspecialchars((string)$ollamaDomainConfidence, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ')' : '' ?></strong></div>
                <div class="meta-row"><span>Embed</span><strong><?= ($ollamaEmbedModel && $ollamaEmbedDims && $ollamaEmbedHash) ? 'vorhanden' : '–' ?></strong></div>
                <div class="meta-row"><span>Stage-Version</span><strong><?= htmlspecialchars($ollamaStageVersion !== null && $ollamaStageVersion !== '' ? (string)$ollamaStageVersion : '–', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></div>
                <div class="meta-row"><span>Last Run</span><strong><?= htmlspecialchars($ollamaLastRunAt !== null && $ollamaLastRunAt !== '' ? (string)$ollamaLastRunAt : '–', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></div>
            </div>

            <div class="meta-section">
                <div class="meta-title">Tags normalisiert</div>
                <?php if ($ollamaTagsNormalized === []): ?>
                    <div class="tab-hint">Keine Tags normalisiert.</div>
                <?php else: ?>
                    <div class="chip-list">
                        <?php foreach ($ollamaTagsNormalized as $tag): ?>
                            <?php if (!is_string($tag) || trim($tag) === '') { continue; } ?>
                            <span class="chip"><?= htmlspecialchars($tag, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="meta-section">
                <div class="meta-title">Quality Flags</div>
                <?php if ($ollamaQualityFlags === []): ?>
                    <div class="tab-hint">Keine Flags vorhanden.</div>
                <?php else: ?>
                    <div class="chip-list">
                        <?php foreach ($ollamaQualityFlags as $flag): ?>
                            <?php if (!is_string($flag) || trim($flag) === '') { continue; } ?>
                            <span class="chip"><?= htmlspecialchars($flag, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="meta-section">
                <div class="meta-title">Prompt Recon</div>
                <div class="meta-grid">
                    <div class="meta-row"><span>Prompt</span><strong><?= htmlspecialchars($ollamaPromptReconPrompt !== null && $ollamaPromptReconPrompt !== '' ? sv_meta_value((string)$ollamaPromptReconPrompt, 220) : '–', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></div>
                    <div class="meta-row"><span>Negative</span><strong><?= htmlspecialchars($ollamaPromptReconNegative !== null && $ollamaPromptReconNegative !== '' ? sv_meta_value((string)$ollamaPromptReconNegative, 220) : '–', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></div>
                    <div class="meta-row"><span>Confidence</span><strong><?= htmlspecialchars($ollamaPromptReconConfidence !== null && $ollamaPromptReconConfidence !== '' ? (string)$ollamaPromptReconConfidence : '–', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></div>
                </div>
            </div>

            <div class="meta-section">
                <div class="meta-title">Dupe Hints</div>
                <div class="meta-grid">
                    <div class="meta-row"><span>TopK</span><strong><?= $ollamaDupeHintsCount > 0 ? (string)$ollamaDupeHintsCount : '–' ?></strong></div>
                    <div class="meta-row"><span>Top Score</span><strong><?= $ollamaDupeHintsTopScore !== null ? htmlspecialchars(number_format((float)$ollamaDupeHintsTopScore, 3), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '–' ?></strong></div>
                </div>
            </div>

            <div class="meta-section">
                <div class="meta-title">Embed Details</div>
                <div class="meta-grid">
                    <div class="meta-row"><span>Model</span><strong><?= htmlspecialchars($ollamaEmbedModel !== null && $ollamaEmbedModel !== '' ? (string)$ollamaEmbedModel : '–', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></div>
                    <div class="meta-row"><span>Dims</span><strong><?= htmlspecialchars($ollamaEmbedDims !== null && $ollamaEmbedDims !== '' ? (string)$ollamaEmbedDims : '–', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></div>
                    <div class="meta-row"><span>Hash</span><strong><?= htmlspecialchars($ollamaEmbedHash !== null && $ollamaEmbedHash !== '' ? (string)$ollamaEmbedHash : '–', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></div>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header">Meta</div>
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
        </div>
    </div>

    <div class="tab-content" id="tab-prompt" data-tab-panel="media">
        <div class="panel" id="prompt-panel">
            <div class="panel-header">Prompt &amp; Negative Prompt</div>
            <div class="tab-bar" data-tab-group="prompt">
                <button class="tab-button" data-tab="prompt-effective" data-tab-default="true">Effektiv</button>
                <button class="tab-button" data-tab="prompt-manual" <?= $overrideDisabled ? 'disabled' : '' ?>>Manuell</button>
                <button class="tab-button" data-tab="prompt-tags">Tags</button>
            </div>
            <div class="tab-content" id="prompt-effective" data-tab-panel="prompt">
                <div class="label-row">Effektiver Prompt</div>
                <textarea readonly class="prompt-viewer"><?= htmlspecialchars($displayPromptText ?: 'Kein Prompt gespeichert.', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
                <div class="label-row">Negativer Prompt</div>
                <textarea readonly class="prompt-viewer"><?= htmlspecialchars($displayNegativePrompt ?: '–', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
                <?php if ($promptParams !== []): ?>
                    <div class="prompt-params">
                        <?php foreach ($promptParams as $label => $value): ?>
                            <div class="param-row"><span><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><strong><?= htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="tab-content" id="prompt-manual" data-tab-panel="prompt">
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
            <div class="tab-content" id="prompt-tags" data-tab-panel="prompt">
                <?php if ($tags === []): ?>
                    <div class="tab-hint">Keine Tags vorhanden.</div>
                <?php else: ?>
                    <div class="chip-list">
                        <?php foreach ($tags as $tag):
                            $tagType = preg_replace('~[^a-z0-9_-]+~i', '', (string)($tag['type'] ?? 'other')) ?: 'other';
                            $conf = isset($tag['confidence']) ? number_format((float)$tag['confidence'], 2) : null;
                            $locked = (int)($tag['locked'] ?? 0) === 1;
                            ?>
                            <span class="chip tag-type-<?= htmlspecialchars($tagType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                <?= htmlspecialchars((string)$tag['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= $conf !== null ? ' (' . htmlspecialchars($conf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ')' : '' ?><?= $locked ? ' · locked' : '' ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="tab-content" id="tab-jobs" data-tab-panel="media">
        <?php if ($hasInternalAccess): ?>
        <div class="panel action-panel">
            <div class="panel-header">Aktionen</div>
            <?php if ($actionMessage !== null): ?>
                <div class="action-feedback <?= $actionSuccess ? 'success' : 'error' ?>">
                    <div class="action-feedback-title"><?= $actionSuccess ? 'OK' : 'Fehler' ?></div>
                    <div><?= htmlspecialchars($actionMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <?php if ($actionLogFile): ?>
                        <div class="action-logfile">Logdatei: <?= htmlspecialchars(sv_safe_path_label((string)$actionLogFile), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($actionLogsSafe !== []): ?>
                        <details class="action-logdetails">
                            <summary>Details</summary>
                            <pre><?= htmlspecialchars(implode("\n", $actionLogsSafe), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($hasStaleScan): ?>
                <div class="action-feedback error">
                    <div class="action-feedback-title">Scan veraltet</div>
                    <div>Scanner war beim letzten Forge-Lauf nicht erreichbar. Tags/Rating sind möglicherweise veraltet.</div>
                </div>
            <?php endif; ?>

            <div class="action-feedback">
                <div class="action-feedback-title">Repair</div>
                <div class="button-stack inline">
                    <button class="btn btn--primary" type="button" id="forge-repair-open" <?= $forgeDisabled ? 'disabled' : '' ?>>Repair</button>
                </div>
                <div class="hint small">Öffnet das kompakte Repair-Panel (5 Controls + Start).</div>
            </div>

            <div class="action-feedback">
                <div class="action-feedback-title">Operator Quick Actions</div>
                <div class="button-stack inline">
                    <form method="post">
                        <input type="hidden" name="media_id" value="<?= (int)$id ?>">
                        <input type="hidden" name="action" value="rescan_job">
                        <button class="btn btn--secondary btn--sm" type="submit">Tag-Rescan</button>
                    </form>
                    <form method="post">
                        <input type="hidden" name="media_id" value="<?= (int)$id ?>">
                        <input type="hidden" name="action" value="vote_up">
                        <button class="btn btn--icon <?= $voteState === 1 ? 'btn--primary' : 'btn--secondary' ?>" type="submit" aria-label="Vote hoch" title="Vote hoch"><?= $iconUp ?></button>
                    </form>
                    <form method="post">
                        <input type="hidden" name="media_id" value="<?= (int)$id ?>">
                        <input type="hidden" name="action" value="vote_down">
                        <button class="btn btn--icon <?= $voteState === -1 ? 'btn--primary' : 'btn--secondary' ?>" type="submit" aria-label="Vote runter" title="Vote runter"><?= $iconDown ?></button>
                    </form>
                    <form method="post">
                        <input type="hidden" name="media_id" value="<?= (int)$id ?>">
                        <input type="hidden" name="action" value="checked_toggle">
                        <input type="hidden" name="checked_value" value="<?= $checkedFlag ? '0' : '1' ?>">
                        <button class="btn btn--icon <?= $checkedFlag ? 'btn--primary' : 'btn--secondary' ?>" type="submit" aria-label="<?= $checkedFlag ? 'Checked entfernen' : 'Checked setzen' ?>" title="<?= $checkedFlag ? 'Checked entfernen' : 'Checked setzen' ?>"><?= $iconCheck ?></button>
                    </form>
                </div>
            </div>

            <form id="forge-form" class="forge-control" method="post">
                <input type="hidden" name="media_id" value="<?= (int)$id ?>">
                <input type="hidden" name="action" value="forge_regen">
                <div class="forge-grid">
                    <div class="form-block">
                        <div class="label-row">Verfahren (Schritt 1)</div>
                        <div class="radio-row vertical">
                            <label><input type="radio" name="_sv_recreate_strategy" value="img2img" checked> Img2img (aktuelle Version als Input)</label>
                            <label><input type="radio" name="_sv_recreate_strategy" value="prompt_only"> Prompt-only (txt2img)</label>
                            <label><input type="radio" name="_sv_recreate_strategy" value="tags" <?= $tags === [] ? 'disabled' : '' ?>> Tags-to-Prompt<?= $tags === [] ? ' (keine Tags)' : '' ?></label>
                            <label><input type="radio" name="_sv_recreate_strategy" value="hybrid"> Hybrid (Prompt + Tags gedrosselt)</label>
                        </div>
                        <div class="hint">Strategie steuert Prompt-Quelle und Mode-Entscheidung.</div>
                    </div>
                    <div class="form-block">
                        <div class="label-row">Mode (Schritt 2)</div>
                        <div class="radio-row">
                            <label><input type="radio" name="_sv_mode" value="preview" checked> Preview (Standard)</label>
                            <label><input type="radio" name="_sv_mode" value="replace"> Replace sofort</label>
                        </div>
                        <div class="hint">Replace schreibt sofort zurück, Preview bleibt isoliert.</div>
                    </div>
                    <div class="form-block">
                        <div class="label-row">Prompt Quelle</div>
                        <div class="prompt-chip">Effektiv: <?= htmlspecialchars($displayPromptText !== '' ? sv_meta_value($displayPromptText, 160) : 'Kein Prompt', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
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
                        <label>Seed (optional)<input type="number" name="_sv_seed" min="0" step="1" placeholder="Auto" value="<?= htmlspecialchars((string)$activeSeed, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></label>
                        <label>Steps<input type="number" name="_sv_steps" min="1" max="150" step="1" placeholder="auto" value="<?= htmlspecialchars((string)$activeSteps, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></label>
                    </div>
                    <div class="form-block two-col">
                        <label>Denoise<input type="number" name="_sv_denoise" min="0" max="1" step="0.01" placeholder="auto"></label>
                        <label>Sampler
                            <select name="_sv_sampler">
                                <option value="">Auto</option>
                                <option value="DPM++ 2M Karras" <?= $activeSampler === 'DPM++ 2M Karras' ? 'selected' : '' ?>>DPM++ 2M Karras</option>
                                <option value="Euler a" <?= $activeSampler === 'Euler a' ? 'selected' : '' ?>>Euler a</option>
                                <option value="DPM++ SDE Karras" <?= $activeSampler === 'DPM++ SDE Karras' ? 'selected' : '' ?>>DPM++ SDE Karras</option>
                            </select>
                        </label>
                    </div>
                    <div class="form-block two-col">
                        <label>Scheduler
                            <select name="_sv_scheduler">
                                <option value="">Auto</option>
                                <option value="Karras" <?= $activeScheduler === 'Karras' ? 'selected' : '' ?>>Karras</option>
                                <option value="Normal" <?= $activeScheduler === 'Normal' ? 'selected' : '' ?>>Normal</option>
                                <option value="Exponential" <?= $activeScheduler === 'Exponential' ? 'selected' : '' ?>>Exponential</option>
                            </select>
                        </label>
                        <label>Model Override
                            <select name="_sv_model" <?= $hasInternalAccess ? '' : 'disabled' ?>>
                                <option value="">Auto (Healthcheck / Fallback)</option>
                                <?php foreach ($forgeModels as $model): ?>
                                    <?php $name = (string)($model['name'] ?? ''); if ($name === '') { continue; } ?>
                                    <option value="<?= htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $name === $modelDropdownValue ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                        <?php if (!empty($model['title'])): ?> (<?= htmlspecialchars((string)$model['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)<?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if ($modelDropdownValue !== '' && !array_filter($forgeModels, fn($m) => isset($m['name']) && $m['name'] === $modelDropdownValue)): ?>
                                    <option value="<?= htmlspecialchars((string)$modelDropdownValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" selected>Manuell: <?= htmlspecialchars((string)$modelDropdownValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                                <?php endif; ?>
                            </select>
                        </label>
                        <div class="hint">Auto/Resolved: <?= htmlspecialchars($resolvedModelLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= $forgeFallbackModel ? ' · Fallback: ' . htmlspecialchars($forgeFallbackModel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?></div>
                        <?php if ($forgeModelError !== null): ?>
                            <div class="action-note error">Modelliste nicht geladen: <?= htmlspecialchars($forgeModelError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="form-block">
                        <label class="checkbox-inline"><input type="checkbox" name="_sv_use_hybrid" value="1"> Hybrid (Prompt + Tags)</label>
                    </div>
                </div>
                <div class="panel-subsection compact">
                    <div class="meta-line"><span>Forge</span><strong><?= $forgeEnabled ? 'aktiv' : 'deaktiviert' ?></strong><em class="small"><?= $forgeDispatchEnabled ? 'Dispatch an' : 'Dispatch aus' ?></em></div>
                    <div class="meta-line"><span>Quelle</span><strong><?= htmlspecialchars($forgeModelSourceLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong><em class="small">Status: <?= htmlspecialchars($forgeModelStatusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></em></div>
                    <div class="meta-line"><span>Gewählt</span><strong><?= htmlspecialchars($selectedModelLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></div>
                    <div class="meta-line"><span>Resolved</span><strong><?= htmlspecialchars($resolvedModelLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong><?php if (!empty($latestModelStatus)): ?><em class="small">Job: <?= htmlspecialchars((string)$latestModelStatus, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></em><?php endif; ?></div>
                    <?php if (!empty($latestModelSource)): ?><div class="hint small">Letzte Quelle: <?= htmlspecialchars((string)$latestModelSource, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
                    <?php if (!empty($latestModelError)): ?><div class="action-note error">Job-Model-Hinweis: <?= htmlspecialchars((string)$latestModelError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
                    <?php if ($latestJobError): ?><div class="action-note error">Letzter Forge-Fehler<?= $latestJobUpdated ? ' (' . htmlspecialchars($latestJobUpdated, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ')' : '' ?>: <?= htmlspecialchars($latestJobError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
                </div>
                <div class="button-stack inline">
                    <button class="btn btn--primary" type="submit" name="_sv_mode" value="preview" <?= $forgeDisabled ? 'disabled' : '' ?>>Preview starten</button>
                    <button class="btn btn--danger" type="submit" name="_sv_mode" value="replace" <?= $forgeDisabled ? 'disabled' : '' ?>>Replace sofort</button>
                </div>
            </form>

            <div id="forge-repair-modal" class="repair-modal is-hidden" data-endpoint="media_view.php?<?= http_build_query(array_merge($filteredParams, ['id' => (int)$id, 'ajax' => 'forge_repair_start'])) ?>">
                <div class="repair-modal__content panel" role="dialog" aria-modal="true" aria-labelledby="forge-repair-title">
                    <div class="panel-header" id="forge-repair-title">Repair</div>
                    <form id="forge-repair-form" class="repair-form">
                        <input type="hidden" name="media_id" value="<?= (int)$id ?>">
                        <div class="repair-grid">
                            <label>Quelle
                                <select name="source">
                                    <option value="auto" selected>auto</option>
                                    <option value="prompt">prompt</option>
                                    <option value="tags">tags</option>
                                    <option value="minimal">minimal</option>
                                </select>
                            </label>
                            <label>Ziel
                                <select name="goal">
                                    <option value="repair" selected>repair</option>
                                    <option value="rebuild">rebuild</option>
                                    <option value="vary">vary</option>
                                </select>
                            </label>
                            <label>Intensität
                                <select name="intensity">
                                    <option value="fast">fast</option>
                                    <option value="normal" selected>normal</option>
                                    <option value="strong">strong</option>
                                </select>
                            </label>
                            <label>Technik-Fix
                                <select name="tech_fix">
                                    <option value="none" selected>none</option>
                                    <option value="sampler_compat">sampler_compat</option>
                                    <option value="black_reset">black_reset</option>
                                    <option value="universal_negative">universal_negative</option>
                                    <option value="normalize_size">normalize_size</option>
                                </select>
                            </label>
                            <label>Prompt-Änderung
                                <input type="text" name="prompt_edit" maxlength="200" placeholder="replace: banana -> apple">
                            </label>
                        </div>
                        <div class="button-stack inline">
                            <button class="btn btn--primary" type="submit" id="forge-repair-start">Start</button>
                        </div>
                        <div class="hint small">Klick außerhalb des Panels schließt den Dialog.</div>
                        <div id="forge-repair-status" class="action-note is-hidden"></div>
                    </form>
                </div>
            </div>

            <div class="button-stack">
                <button class="btn btn--secondary" type="button" <?= $overrideDisabled ? 'disabled' : '' ?> onclick="document.getElementById('prompt-panel')?.scrollIntoView({ behavior: 'smooth' });">Prompt bearbeiten</button>
                <?php if ($overrideReason): ?><div class="btn-reason"><?= htmlspecialchars($overrideReason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>

                <button class="btn btn--ghost" type="submit" form="rebuild-form" <?= $rebuildDisabled ? 'disabled' : '' ?>>Prompt neu aufbauen</button>
                <?php if ($rebuildReason): ?><div class="btn-reason"><?= htmlspecialchars($rebuildReason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>

                <button class="btn btn--danger" type="submit" form="missing-form">Missing markieren</button>
                <div class="btn-reason">Status wird nur auf missing gesetzt.</div>
            </div>
            <div class="panel-subsection">
                <div class="subheader">Curation (Quality-Status)</div>
                <div class="hint">Quality-Status (unknown/ok/review/blocked) bewertet die operative Freigabe. Prompt-Qualität (A/B/C) bleibt separat.</div>
                <form method="post" class="stacked">
                    <input type="hidden" name="media_id" value="<?= (int)$id ?>">
                    <input type="hidden" name="action" value="quality_flag">
                    <label>Status
                        <select name="quality_status">
                            <?php foreach ($qualityStatusLabels as $statusValue => $statusLabel): ?>
                                <option value="<?= htmlspecialchars($statusValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $qualityStatus === $statusValue ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Score (optional)<input type="number" step="0.01" name="quality_score" value="<?= htmlspecialchars((string)($qualityScore ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></label>
                    <label>Regel/Quelle<input type="text" name="quality_rule" maxlength="120" placeholder="Policy/Regel"></label>
                    <label>Notiz<textarea name="quality_notes" maxlength="500" placeholder="Begründung/Review-Hinweis"><?= htmlspecialchars($qualityNotes, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea></label>
                    <button class="btn btn--secondary" type="submit">Curation speichern</button>
                </form>
                <?php if ($curationUpdatedAt !== null): ?>
                    <div class="hint">Letzte Änderung: <?= htmlspecialchars($curationUpdatedAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <?php endif; ?>
            </div>

            <div class="panel-subsection">
                <div class="subheader">Delete/Review</div>
                <form method="post" class="stacked">
                    <input type="hidden" name="media_id" value="<?= (int)$id ?>">
                    <input type="hidden" name="action" value="request_delete">
                    <label>Grund<textarea name="delete_reason" maxlength="240" placeholder="Warum pending_delete?"><?= htmlspecialchars($lifecycleReason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea></label>
                    <button class="btn btn--danger" type="submit">Lifecycle auf pending_delete setzen</button>
                </form>
            </div>
            <div class="panel-subsection">
                <div class="subheader">Tags &amp; Rescan</div>
                <div class="rescan-summary" id="rescan-summary" data-rescan-summary data-endpoint="media_view.php?<?= http_build_query(array_merge($filteredParams, ['id' => (int)$id, 'ajax' => 'rescan_jobs'])) ?>" data-internal="<?= $hasInternalAccess ? 'true' : 'false' ?>">
                    <div class="meta-line">
                        <span>Letzter Scan</span>
                        <strong id="scan-run-at"><?= $latestScanRunAt !== '' ? htmlspecialchars($latestScanRunAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '—' ?></strong>
                        <em class="small" id="scan-meta"><?= htmlspecialchars($latestScanMetaText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></em>
                    </div>
                    <div class="meta-line">
                        <span>Tags</span>
                        <strong id="scan-tags"><?= htmlspecialchars($latestScanTagsText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                        <em class="small">locked=1 bleibt geschützt</em>
                    </div>
                    <div class="meta-line <?= $latestScanErrorCode !== null ? '' : 'is-hidden' ?>" id="scan-error-code-line">
                        <span>Error-Code</span>
                        <strong id="scan-error-code"><?= htmlspecialchars((string)($latestScanErrorCode ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="meta-line <?= $latestScanHttpStatus !== null ? '' : 'is-hidden' ?>" id="scan-http-status-line">
                        <span>HTTP-Status</span>
                        <strong id="scan-http-status"><?= htmlspecialchars((string)($latestScanHttpStatus ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="meta-line <?= $latestScanEndpoint !== null && $latestScanEndpoint !== '' ? '' : 'is-hidden' ?>" id="scan-endpoint-line">
                        <span>Endpoint</span>
                        <strong id="scan-endpoint"><?= htmlspecialchars((string)($latestScanEndpoint ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="meta-line <?= $latestScanResponseType !== null && $latestScanResponseType !== '' ? '' : 'is-hidden' ?>" id="scan-response-type-line">
                        <span>Response</span>
                        <strong id="scan-response-type"><?= htmlspecialchars((string)($latestScanResponseType ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="meta-line <?= $latestScanBodySnippet !== null && $latestScanBodySnippet !== '' ? '' : 'is-hidden' ?>" id="scan-body-snippet-line">
                        <span>Body-Snippet</span>
                        <strong id="scan-body-snippet"><?= htmlspecialchars($latestScanBodySnippet !== null ? sv_meta_value((string)$latestScanBodySnippet, 300) : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="job-error inline <?= $latestScanError ? '' : 'is-hidden' ?>" id="scan-error"><?= htmlspecialchars((string)($latestScanError ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <div class="hint small <?= ($latestScanRunAt === '' && $latestScanError === null) ? '' : 'is-hidden' ?>" id="scan-empty">Kein Scan-Ergebnis gespeichert.</div>
                    <div class="meta-line" id="rescan-job-line">
                        <?php if ($activeRescanJob): ?>
                            <?php
                            $status = strtolower((string)$activeRescanJob['status']);
                            $jobStart = (string)($activeRescanJob['started_at'] ?? $activeRescanJob['created_at'] ?? '');
                            $jobFinish = (string)($activeRescanJob['finished_at'] ?? $activeRescanJob['completed_at'] ?? '');
                            $jobAge = (string)($activeRescanJob['age_label'] ?? '');
                            $jobStuck = !empty($activeRescanJob['stuck']);
                            ?>
                            <span>Rescan Job</span><strong><?= htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong><em class="small"><?= htmlspecialchars($jobStart, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= $jobFinish !== '' ? ' → ' . htmlspecialchars($jobFinish, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?><?= $jobAge !== '' ? ' · Age ' . htmlspecialchars($jobAge, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?></em>
                            <?php if ($jobStuck): ?><span class="status-badge status-error">stuck</span><?php endif; ?>
                            <?php if (!empty($activeRescanJob['error'])): ?><div class="job-error inline"><?= htmlspecialchars((string)$activeRescanJob['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
                        <?php else: ?>
                            <span>Rescan Job</span><strong>none</strong><em class="small">Kein Eintrag</em>
                        <?php endif; ?>
                    </div>
                    <div class="job-error inline <?= $rescanLastError !== null ? '' : 'is-hidden' ?>" id="rescan-last-error"><?= $rescanLastError !== null ? 'Letzter Fehler: ' . htmlspecialchars($rescanLastError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?></div>
                    <div class="hint small" id="rescan-poll-meta">Polling aktiv.</div>
                </div>
                <form method="post" class="stacked">
                    <input type="hidden" name="media_id" value="<?= (int)$id ?>">
                    <input type="hidden" name="action" value="rescan_job">
                    <button class="btn btn--primary" id="rescan-button" type="submit" <?= $hasInternalAccess ? '' : 'disabled' ?>>Tag-Rescan (Job)</button>
                    <div class="hint">Stellt einen Rescan als Job in die Queue (locked=1 bleibt geschützt).</div>
                </form>
            </div>
            <?php if ($forgeInfoNotes !== []): ?>
                <div class="action-note">Hinweise: <?= htmlspecialchars(implode(' ', $forgeInfoNotes), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <?php endif; ?>
            <div class="action-note">Alle Aktionen nutzen die bestehende Internal-Key/IP-Prüfung.</div>
        </div>
        <?php endif; ?>

        <div class="panel status-panel">
            <div class="panel-header">Status</div>
            <div class="status-badges">
                <span class="status-pill <?= htmlspecialchars($promptBadgeClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($promptLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <span class="status-pill <?= htmlspecialchars($tagBadgeClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($tagLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <span class="status-pill <?= htmlspecialchars($metaBadgeClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($metaLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            </div>
            <div class="quality-row">
                <span class="quality-pill">Prompt-Qualität: <strong><?= htmlspecialchars($promptQualityClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></span>
                <span class="quality-score">Score <?= (int)$promptQuality['score'] ?></span>
                <?php if ($promptUpdatedAt !== null): ?><span class="quality-score">Letzte Änderung <?= htmlspecialchars($promptUpdatedAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
            </div>
            <div class="lifecycle-row">
                <div>Lifecycle: <strong><?= htmlspecialchars($lifecycleStatus, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></div>
                <?php if ($lifecycleReason !== ''): ?><div class="hint">Grund: <?= htmlspecialchars($lifecycleReason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
            </div>
            <div class="lifecycle-row">
                <div>Curation (Quality-Status): <strong><?= htmlspecialchars($qualityLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong><?php if ($qualityScore !== null): ?> (Score <?= htmlspecialchars((string)$qualityScore, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)<?php endif; ?></div>
                <?php if ($qualityNotes !== ''): ?><div class="hint">Notiz: <?= htmlspecialchars($qualityNotes, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
                <?php if ($curationUpdatedAt !== null): ?><div class="hint">Letzte Änderung: <?= htmlspecialchars($curationUpdatedAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
                <div class="hint">Curation ≠ Prompt-Qualität; beide werden separat bewertet.</div>
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
                                <?php foreach (array_slice($mediaIssues, 3) as $issue): ?>
                                    <li><span class="issue-type"><?= htmlspecialchars(ucfirst((string)$issue['type']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span> <?= htmlspecialchars((string)$issue['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="panel rescan-panel">
            <div class="panel-header">Rescan-Jobs</div>
            <?php if ($rescanJobs === []): ?>
                <div class="job-hint">Keine Rescan-Jobs gefunden.</div>
            <?php else: ?>
                <div class="timeline">
                    <?php foreach ($rescanJobs as $job): ?>
                        <div class="timeline-item">
                            <div class="timeline-header">
                                <div class="timeline-title">Rescan #<?= (int)$job['id'] ?></div>
                                <span class="status-badge status-<?= htmlspecialchars((string)$job['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars((string)$job['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                <?php if (!empty($job['stuck'])): ?>
                                    <span class="status-badge status-error">stuck</span>
                                <?php endif; ?>
                            </div>
                            <div class="timeline-meta"><?= htmlspecialchars((string)($job['created_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> • <?= htmlspecialchars((string)($job['updated_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                            <?php if (!empty($job['error'])): ?>
                                <div class="job-error"><?= htmlspecialchars((string)$job['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                            <?php endif; ?>
                            <?php if ($job['tags_written'] !== null): ?><div class="meta-line"><span>Tags</span><strong><?= (int)$job['tags_written'] ?></strong><em class="small">unlocked ersetzt</em></div><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="panel forge-panel">
            <div class="panel-header">Forge-Jobs</div>
            <div id="forge-jobs" class="timeline" data-forge-jobs data-endpoint="media_view.php?<?= http_build_query(array_merge($filteredParams, ['id' => (int)$id, 'ajax' => 'forge_jobs'])) ?>" data-thumb="<?= htmlspecialchars($thumbUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <div class="job-hint">Forge-Jobs laden...</div>
            </div>
            <div class="hint small" id="forge-poll-meta">Polling aktiv.</div>
        </div>
        <?php if ($hasInternalAccess): ?>
            <div class="panel">
                <div class="panel-header">Logs</div>
                <?php if ($actionLogsSafe === []): ?>
                    <div class="tab-hint">Keine aktuellen Logeinträge.</div>
                <?php else: ?>
                    <pre class="log-viewer"><?= htmlspecialchars(implode("\n", $actionLogsSafe), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="tab-content" id="tab-versions" data-tab-panel="media">
        <div class="panel versions-panel">
            <div class="panel-header">Versionen</div>
            <?php if ($versions === []): ?>
                <div class="job-hint">Keine Versionen gefunden.</div>
            <?php else: ?>
                <div class="version-grid">
                    <?php foreach ($versions as $idx => $version): ?>
                        <?php
                        $assetSet = $version['_asset_set'] ?? sv_prepare_version_asset_set($version, $media);
                        $primarySelection = sv_select_asset_from_set($assetSet, $assetSet['primary_type'] ?? null);
                        $primaryUrls = sv_build_asset_urls($id, $showAdult, $primarySelection);
                        $versionLinkParams = array_merge($filteredParams, ['id' => (int)$id, 'version' => (int)$idx, 'asset' => $primarySelection['type']]);
                        $versionLink = 'media_view.php?' . http_build_query($versionLinkParams) . '#visual';
                        $versionAssetLabel = sv_asset_label((string)$primarySelection['type']);
                        ?>
                        <a class="version-tile<?= !empty($version['is_current']) ? ' current' : '' ?>" href="<?= htmlspecialchars($versionLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                            <div class="version-thumb">
                                <img src="<?= htmlspecialchars((string)$primaryUrls['thumb'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="Version <?= (int)$version['version_index'] ?>">
                            </div>
                            <div class="version-label">V<?= (int)$version['version_index'] ?> <?= !empty($version['is_current']) ? '· aktuell' : '' ?> · <?= htmlspecialchars($versionAssetLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                            <div class="version-meta-small">Modell: <?= htmlspecialchars((string)($version['model_used'] ?? $version['model_requested'] ?? '–'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="panel compare-panel">
            <div class="panel-header">Compare Versionen</div>
            <form method="get" class="compare-form">
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <?php foreach ($filteredParams as $k => $v): ?>
                    <input type="hidden" name="<?= htmlspecialchars((string)$k, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" value="<?= htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <?php endforeach; ?>
                <label>Version A
                    <select name="compare_a">
                        <?php foreach ($versions as $idx => $version): ?>
                            <option value="<?= (int)$idx ?>" <?= $idx === $versionCompareA ? 'selected' : '' ?>>V<?= (int)$version['version_index'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php if ($compareAssetSelectionA): ?>
                    <label>Asset A
                        <select name="compare_asset_a">
                            <?php foreach ($compareAssetSelectionA['options'] as $option): ?>
                                <option value="<?= htmlspecialchars((string)$option, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $option === $compareAssetSelectionA['type'] ? 'selected' : '' ?>><?= htmlspecialchars(sv_asset_label((string)$option), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endif; ?>
                <label>Version B
                    <select name="compare_b">
                        <?php foreach ($versions as $idx => $version): ?>
                            <option value="<?= (int)$idx ?>" <?= $idx === $versionCompareB ? 'selected' : '' ?>>V<?= (int)$version['version_index'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php if ($compareAssetSelectionB): ?>
                    <label>Asset B
                        <select name="compare_asset_b">
                            <?php foreach ($compareAssetSelectionB['options'] as $option): ?>
                                <option value="<?= htmlspecialchars((string)$option, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $option === $compareAssetSelectionB['type'] ? 'selected' : '' ?>><?= htmlspecialchars(sv_asset_label((string)$option), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endif; ?>
                <button class="btn btn--secondary" type="submit">Vergleichen</button>
            </form>
            <?php if ($compareVersionA && $compareVersionB): ?>
                <div class="compare-summary">V<?= (int)$compareVersionA['version_index'] ?> (<?= htmlspecialchars(sv_asset_label((string)($compareAssetSelectionA['type'] ?? 'baseline')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>) ⇄ V<?= (int)$compareVersionB['version_index'] ?> (<?= htmlspecialchars(sv_asset_label((string)($compareAssetSelectionB['type'] ?? 'baseline')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)</div>
                <?php if ($compareIdenticalSources): ?>
                    <div class="action-note highlight">Identische Quelle gewählt – Vergleiche können identisch aussehen.</div>
                <?php endif; ?>
                <div class="preview-grid compare-visuals">
                    <div class="preview-card">
                        <div class="preview-label original">Quelle A</div>
                        <div class="preview-frame">
                            <?php if (!empty($compareAssetUrlsA['thumb'])): ?>
                                <img src="<?= htmlspecialchars((string)$compareAssetUrlsA['thumb'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="Compare A">
                            <?php else: ?>
                                <div class="preview-placeholder">
                                    <div class="placeholder-title">Kein Bild verfügbar</div>
                                    <?php if (!empty($compareAssetSelectionA['path'] ?? '')): ?><div class="placeholder-meta"><?= htmlspecialchars(sv_safe_path_label((string)$compareAssetSelectionA['path']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="preview-meta">
                            <span><?= htmlspecialchars(sv_asset_label((string)($compareAssetSelectionA['type'] ?? 'baseline')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                            <?php if (!empty($compareAssetSelectionA['job_id'] ?? null)): ?><span>Job #<?= (int)$compareAssetSelectionA['job_id'] ?></span><?php endif; ?>
                        </div>
                    </div>
                    <div class="preview-card">
                        <div class="preview-label preview">Quelle B</div>
                        <div class="preview-frame">
                            <?php if (!empty($compareAssetUrlsB['thumb'])): ?>
                                <img src="<?= htmlspecialchars((string)$compareAssetUrlsB['thumb'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="Compare B">
                            <?php else: ?>
                                <div class="preview-placeholder">
                                    <div class="placeholder-title">Kein Bild verfügbar</div>
                                    <?php if (!empty($compareAssetSelectionB['path'] ?? '')): ?><div class="placeholder-meta"><?= htmlspecialchars(sv_safe_path_label((string)$compareAssetSelectionB['path']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="preview-meta">
                            <span><?= htmlspecialchars(sv_asset_label((string)($compareAssetSelectionB['type'] ?? 'baseline')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                            <?php if (!empty($compareAssetSelectionB['job_id'] ?? null)): ?><span>Job #<?= (int)$compareAssetSelectionB['job_id'] ?></span><?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php if ($versionDiff === []): ?>
                    <div class="job-hint">Keine Unterschiede in Kernparametern.</div>
                <?php else: ?>
                    <table class="compare-table">
                        <thead><tr><th>Feld</th><th>Version A</th><th>Version B</th></tr></thead>
                        <tbody>
                            <?php foreach ($versionDiff as [$field, $aVal, $bVal]): ?>
                                <tr>
                                    <td><?= htmlspecialchars($field, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string)($aVal ?? '–'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string)($bVal ?? '–'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php else: ?>
                <div class="job-hint">Bitte Versionen auswählen.</div>
            <?php endif; ?>
        </div>

        <div class="panel history-panel">
            <div class="panel-header">Prompt-Historie</div>
            <?php if ($promptHistoryErr === null && $latestPromptHistory !== null): ?>
                <?php
                $latestHasLink = !empty($latestPromptHistory['prompt_id']) && !empty($latestPromptHistory['prompt_exists']);
                $latestTimestamp = (string)($latestPromptHistory['created_at'] ?? '');
                $latestSource = (string)($latestPromptHistory['source'] ?? '');
                ?>
                <div class="job-hint">
                    Letzte Historie: <strong><?= htmlspecialchars($latestTimestamp !== '' ? $latestTimestamp : '–', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    • Quelle: <strong><?= htmlspecialchars($latestSource !== '' ? $latestSource : '–', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    • Version-Link: <strong><?= $latestHasLink ? 'ja' : 'nein' ?></strong>
                    <?php if ($promptHistoryHasIssues): ?>
                        <span class="status-badge status-error">Inkonsistenz</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($promptHistoryErr !== null): ?>
                <div class="action-note">Historie nicht verfügbar: <?= htmlspecialchars($promptHistoryErr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <?php elseif ($promptHistory === []): ?>
                <div class="job-hint">Keine Historie gefunden.</div>
            <?php else: ?>
                <div class="history-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Version</th>
                                <th>Quelle</th>
                                <th>Zeit</th>
                                <th>Modell</th>
                                <th>Sampler</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($promptHistory as $entry): ?>
                                <tr>
                                    <td>#<?= (int)($entry['version'] ?? 0) ?></td>
                                    <td><?= htmlspecialchars((string)($entry['source'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string)($entry['created_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string)($entry['model'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string)($entry['sampler'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td class="history-actions">
                                        <a class="btn btn--xs btn--secondary" href="#history-<?= (int)$entry['id'] ?>">anzeigen</a>
                                        <a class="btn btn--xs btn--ghost" href="<?= htmlspecialchars('media_view.php?' . http_build_query(array_merge($filteredParams, ['id' => $id, 'compare_from' => (int)$entry['id'], 'compare_to' => $compareToId > 0 ? $compareToId : $entry['id']])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">als A vergleichen</a>
                                        <a class="btn btn--xs btn--ghost" href="<?= htmlspecialchars('media_view.php?' . http_build_query(array_merge($filteredParams, ['id' => $id, 'compare_from' => $compareFromId > 0 ? $compareFromId : $entry['id'], 'compare_to' => (int)$entry['id']])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">als B vergleichen</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($compareResult): ?>
                    <div class="panel-subsection">
                        <div class="subheader">Vergleich</div>
                        <div class="hint">Von #<?= (int)($compareResult['from']['version'] ?? 0) ?> nach #<?= (int)($compareResult['to']['version'] ?? 0) ?></div>
                        <pre class="diff-view"><?php foreach ($compareResult['diff'] as $line):
                            $prefix = $line['type'] === 'add' ? '+' : ($line['type'] === 'remove' ? '-' : ' ');
                            ?>
<?= htmlspecialchars($prefix . ' ' . $line['value'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n" ?>
<?php endforeach; ?></pre>
                    </div>
                <?php endif; ?>
                <?php foreach ($promptHistory as $entry): ?>
                    <details class="history-entry" id="history-<?= (int)$entry['id'] ?>">
                        <summary>Version #<?= (int)($entry['version'] ?? 0) ?> • <?= htmlspecialchars((string)($entry['source'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> • <?= htmlspecialchars((string)($entry['created_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></summary>
                        <div class="history-meta">Modell: <?= htmlspecialchars((string)($entry['model'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> | Sampler: <?= htmlspecialchars((string)($entry['sampler'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> | Steps: <?= htmlspecialchars((string)($entry['steps'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                        <div class="history-meta">Seed: <?= htmlspecialchars((string)($entry['seed'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> | Size: <?= htmlspecialchars((string)($entry['width'] ?? '-') . '×' . (string)($entry['height'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                        <?php if (!empty($entry['negative_prompt'])): ?>
                            <div class="history-negative"><strong>Negative</strong><br><?= nl2br(htmlspecialchars((string)$entry['negative_prompt'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></div>
                        <?php endif; ?>
                        <div class="history-positive"><strong>Prompt</strong><br><?= nl2br(htmlspecialchars((string)($entry['prompt'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></div>
                        <?php if (!empty($entry['raw_text'])): ?>
                            <details><summary>Raw</summary><pre><?= htmlspecialchars((string)$entry['raw_text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre></details>
                        <?php endif; ?>
                        <?php if (!empty($entry['source_metadata'])): ?>
                            <details><summary>Source Metadata</summary><pre><?= htmlspecialchars((string)$entry['source_metadata'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre></details>
                        <?php endif; ?>
                    </details>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
<div id="fullscreen-viewer" class="lightbox is-hidden">
        <div class="lightbox-inner">
            <button class="lightbox-close" type="button" aria-label="Schließen">×</button>
            <img src="<?= htmlspecialchars($activeStreamUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="Fullscreen">
            <div class="lightbox-meta">
                <div>Maße: <?= htmlspecialchars((string)($activeWidth ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> × <?= htmlspecialchars((string)($activeHeight ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <?php if (!empty($media['filesize'])): ?><div>Size: <?= htmlspecialchars((string)$media['filesize'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> bytes</div><?php endif; ?>
                <?php if ($activeHash !== ''): ?><div>Hash: <?= htmlspecialchars($activeHash, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
                <?php if ($activePathLabel !== ''): ?><div class="path-info">Pfad: <?= htmlspecialchars($activePathLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php sv_ui_footer(); ?>

<?php
declare(strict_types=1);

require_once __DIR__ . '/../SCRIPTS/common.php';
require_once __DIR__ . '/../SCRIPTS/operations.php';
require_once __DIR__ . '/../SCRIPTS/security.php';
require_once __DIR__ . '/../SCRIPTS/ollama_jobs.php';

header('Content-Type: application/json; charset=utf-8');

$respond = static function (int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

try {
    $config = sv_load_config();
    $access = sv_internal_access_result($config, 'ollama_internal', ['allow_loopback_bypass' => true]);
    if (empty($access['ok'])) {
        $status = $access['status'] ?? 'forbidden';
        $httpCode = $status === 'config_failed' ? 500 : 403;
        $respond($httpCode, [
            'ok' => false,
            'status' => $status,
            'reason_code' => $access['reason_code'] ?? 'forbidden',
        ]);
    }
    $pdo = sv_open_pdo($config);
} catch (Throwable $e) {
    $respond(500, ['ok' => false, 'error' => $e->getMessage()]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond(405, ['ok' => false, 'error' => 'POST required.']);
}

$rawBody = file_get_contents('php://input');
$jsonBody = null;
if (is_string($rawBody) && trim($rawBody) !== '') {
    $jsonBody = json_decode($rawBody, true);
}

$action = $_POST['action'] ?? ($jsonBody['action'] ?? null);
$action = is_string($action) ? trim($action) : '';
if ($action === '') {
    $respond(400, ['ok' => false, 'error' => 'Missing action.']);
}

$migrateActions = ['enqueue', 'run_once', 'cancel', 'delete', 'job_status', 'status'];
if (in_array($action, $migrateActions, true)) {
    try {
        sv_run_migrations_if_needed($pdo, $config);
    } catch (Throwable $e) {
        $respond(500, ['ok' => false, 'error' => 'Migration fehlgeschlagen: ' . $e->getMessage()]);
    }
}

$logsPath = sv_logs_root($config);

if ($action === 'status') {
    $jobTypes = sv_ollama_job_types();
    $placeholders = implode(',', array_fill(0, count($jobTypes), '?'));
    $stmt = $pdo->prepare('SELECT status, COUNT(*) AS cnt FROM jobs WHERE type IN (' . $placeholders . ') GROUP BY status');
    $stmt->execute($jobTypes);
    $counts = [
        'queued' => 0,
        'pending' => 0,
        'running' => 0,
        'done' => 0,
        'error' => 0,
        'cancelled' => 0,
    ];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $status = (string)($row['status'] ?? '');
        if (array_key_exists($status, $counts)) {
            $counts[$status] = (int)($row['cnt'] ?? 0);
        }
    }

    $errStmt = $pdo->prepare(
        'SELECT id, media_id, type, error_message, updated_at FROM jobs WHERE type IN (' . $placeholders . ') AND status = "error" ORDER BY updated_at DESC LIMIT 5'
    );
    $errStmt->execute($jobTypes);
    $errors = $errStmt->fetchAll(PDO::FETCH_ASSOC);

    $modeCounts = [];
    $modeList = ['caption', 'title', 'prompt_eval', 'tags_normalize', 'quality', 'nsfw_classify', 'prompt_recon', 'embed', 'dupe_hints'];
    foreach ($modeList as $mode) {
        $modeCounts[$mode] = [
            'queued' => 0,
            'pending' => 0,
            'running' => 0,
            'done' => 0,
            'error' => 0,
            'cancelled' => 0,
        ];
    }
    $modeStmt = $pdo->prepare(
        'SELECT type, status, COUNT(*) AS cnt FROM jobs WHERE type IN (' . $placeholders . ') GROUP BY type, status'
    );
    $modeStmt->execute($jobTypes);
    foreach ($modeStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $jobType = (string)($row['type'] ?? '');
        $status = (string)($row['status'] ?? '');
        if (!isset($counts[$status])) {
            continue;
        }
        try {
            $mode = sv_ollama_mode_for_job_type($jobType);
        } catch (Throwable $e) {
            continue;
        }
        if (isset($modeCounts[$mode])) {
            $modeCounts[$mode][$status] = (int)($row['cnt'] ?? 0);
        }
    }

    $currentPid = function_exists('getmypid') ? (int)getmypid() : null;
    $workerState = sv_ollama_worker_running_state($config, $currentPid, 30);

    $respond(200, [
        'ok' => true,
        'counts' => $counts,
        'mode_counts' => $modeCounts,
        'last_errors' => $errors,
        'worker_running' => !empty($workerState['running']),
        'worker_reason_code' => $workerState['reason_code'] ?? null,
        'worker_source' => $workerState['source'] ?? null,
        'worker_pid' => $workerState['pid'] ?? null,
        'logs_path' => $logsPath,
    ]);
}

if ($action === 'cancel') {
    $jobId = $_POST['job_id'] ?? ($jsonBody['job_id'] ?? null);
    $jobId = $jobId !== null ? (int)$jobId : 0;
    if ($jobId <= 0) {
        sv_audit_log($pdo, 'ollama_job_error', 'jobs', $jobId > 0 ? $jobId : null, [
            'reason_code' => 'invalid_job_id',
            'action' => $action,
        ]);
        $respond(400, ['ok' => false, 'error' => 'Invalid job_id.']);
    }

    $job = sv_ollama_fetch_job_control($pdo, $jobId);
    if ($job === []) {
        sv_audit_log($pdo, 'ollama_job_error', 'jobs', $jobId, [
            'reason_code' => 'job_not_found',
            'action' => $action,
        ]);
        $respond(404, ['ok' => false, 'error' => 'Job not found.']);
    }

    sv_ollama_request_cancel($pdo, $jobId);
    $job = sv_ollama_fetch_job_control($pdo, $jobId);

    $respond(200, [
        'ok' => true,
        'job' => [
            'id' => $jobId,
            'status' => $job['status'] ?? null,
            'cancel_requested' => isset($job['cancel_requested']) ? (int)$job['cancel_requested'] : 0,
        ],
    ]);
}

if ($action === 'delete') {
    $mediaId = $_POST['media_id'] ?? ($jsonBody['media_id'] ?? null);
    $mode = $_POST['mode'] ?? ($jsonBody['mode'] ?? null);
    $mediaId = $mediaId !== null ? (int)$mediaId : 0;
    $mode = is_string($mode) ? trim($mode) : '';
    if ($mediaId <= 0) {
        $respond(400, ['ok' => false, 'error' => 'Invalid media_id.']);
    }
    if ($mode === '') {
        $respond(400, ['ok' => false, 'error' => 'Missing mode.']);
    }

    $allowedModes = ['caption', 'title', 'prompt_eval', 'tags_normalize', 'quality', 'nsfw_classify', 'prompt_recon', 'embed', 'dupe_hints'];
    if (!in_array($mode, $allowedModes, true)) {
        $respond(400, ['ok' => false, 'error' => 'Invalid mode.']);
    }

    $pdo->beginTransaction();
    try {
        $jobDelete = [
            'deleted' => 0,
            'blocked' => [],
            'cancel_requested_set' => 0,
        ];
        if ($mode !== 'dupe_hints') {
            $jobDelete = sv_ollama_delete_jobs($pdo, $mediaId, $mode);
        }

        if (!empty($jobDelete['blocked'])) {
            $pdo->rollBack();
            $respond(409, [
                'ok' => false,
                'error' => 'Jobs are still running or queued.',
                'job_delete' => $jobDelete,
            ]);
        }

        $resultDelete = sv_ollama_delete_results($pdo, $mediaId, $mode);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $respond(200, [
        'ok' => true,
        'job_delete' => $jobDelete,
        'result_delete' => $resultDelete,
    ]);
}

if ($action === 'job_status') {
    $jobId = $_POST['job_id'] ?? ($jsonBody['job_id'] ?? null);
    $jobId = $jobId !== null ? (int)$jobId : 0;
    if ($jobId <= 0) {
        sv_audit_log($pdo, 'ollama_job_error', 'jobs', $jobId > 0 ? $jobId : null, [
            'reason_code' => 'invalid_job_id',
            'action' => $action,
        ]);
        $respond(400, ['ok' => false, 'error' => 'Invalid job_id.']);
    }

    $job = sv_ollama_fetch_job_control($pdo, $jobId);
    if ($job === []) {
        sv_audit_log($pdo, 'ollama_job_error', 'jobs', $jobId, [
            'reason_code' => 'job_not_found',
            'action' => $action,
        ]);
        $respond(404, ['ok' => false, 'error' => 'Job not found.']);
    }

    $respond(200, [
        'ok' => true,
        'job' => [
            'id' => $jobId,
            'status' => $job['status'] ?? null,
            'progress_bits' => isset($job['progress_bits']) ? (int)$job['progress_bits'] : 0,
            'progress_bits_total' => isset($job['progress_bits_total']) ? (int)$job['progress_bits_total'] : 0,
            'heartbeat_at' => $job['heartbeat_at'] ?? null,
            'last_error_code' => $job['last_error_code'] ?? null,
        ],
        'logs_path' => $logsPath,
    ]);
}

if ($action === 'run_once') {
    $limit = $_POST['limit'] ?? ($jsonBody['limit'] ?? null);
    $limit = $limit !== null ? (int)$limit : null;
    $logs = [];
    $logger = static function (string $message) use (&$logs): void {
        $logs[] = $message;
    };

    $lock = sv_ollama_acquire_aux_lock($config, 'ollama_run_once.lock', 'web:internal_ollama.php', 'run_once_lock');
    if (empty($lock['ok'])) {
        $respond(200, [
            'ok' => false,
            'status' => $lock['status'] ?? 'locked',
            'reason' => $lock['reason'] ?? 'runner_locked',
            'reason_code' => $lock['reason_code'] ?? ($lock['reason'] ?? 'runner_locked'),
            'locked' => (bool)($lock['locked'] ?? false),
            'running' => false,
            'path' => $lock['path'] ?? null,
        ]);
    }

    try {
        sv_ollama_watchdog_stale_running($pdo, $config, 10, 'requeue');
        $maxConcurrency = sv_ollama_max_concurrency($config);
        $running = sv_ollama_running_job_count($pdo);
        if ($running >= $maxConcurrency) {
            $respond(200, [
                'ok' => false,
                'status' => 'busy',
                'reason_code' => 'concurrency_limit',
                'running' => $running,
                'max_concurrency' => $maxConcurrency,
            ]);
        }

        $summary = sv_process_ollama_job_batch($pdo, $config, $limit, $logger, null);
        $respond(200, [
            'ok' => true,
            'status' => 'started',
            'reason_code' => 'batch_complete',
            'running' => false,
            'summary' => $summary,
            'logs' => $logs,
        ]);
    } finally {
        sv_ollama_release_aux_lock($lock['handle']);
    }
}

if ($action === 'enqueue') {
    $modeArg = $_POST['mode'] ?? ($jsonBody['mode'] ?? 'all');
    $modeArg = is_string($modeArg) ? trim($modeArg) : 'all';
    $allowedModes = ['caption', 'title', 'prompt_eval', 'tags_normalize', 'quality', 'nsfw_classify', 'prompt_recon', 'embed', 'all'];
    if (!in_array($modeArg, $allowedModes, true)) {
        $respond(400, ['ok' => false, 'error' => 'Invalid mode.']);
    }

    $filters = $_POST['filters'] ?? ($jsonBody['filters'] ?? []);
    if (is_string($filters)) {
        $decoded = json_decode($filters, true);
        if (is_array($decoded)) {
            $filters = $decoded;
        }
    }
    if (!is_array($filters)) {
        $filters = [];
    }

    $limit = isset($filters['limit']) ? (int)$filters['limit'] : 50;
    $since = isset($filters['since']) && is_string($filters['since']) ? trim($filters['since']) : null;
    $allFlag = !empty($filters['all']);
    $missingTitle = !empty($filters['missing_title']);
    $missingCaption = !empty($filters['missing_caption']);
    $mediaIdFilter = isset($filters['media_id']) ? (int)$filters['media_id'] : 0;
    $force = !empty($filters['force']);

    if ($limit <= 0) {
        $limit = 50;
    }

    $buildCandidateQuery = static function (string $mode, bool $allFlag, ?string $since) use ($pdo, $limit): PDOStatement {
        $sql = 'SELECT m.id FROM media m';
        $params = [];
        $conditions = [];
        $imageRequired = sv_ollama_mode_requires_image($mode);

        if ($mode === 'prompt_recon') {
            $sql .= ' LEFT JOIN media_meta mm_prompt ON mm_prompt.media_id = m.id AND mm_prompt.meta_key = :prompt_key';
            $sql .= ' LEFT JOIN media_meta mm_caption ON mm_caption.media_id = m.id AND mm_caption.meta_key = :caption_key';
            $sql .= ' LEFT JOIN media_meta mm_tags ON mm_tags.media_id = m.id AND mm_tags.meta_key = :tags_key';
            $params[':prompt_key'] = 'ollama.prompt_recon.prompt';
            $params[':caption_key'] = 'ollama.caption';
            $params[':tags_key'] = 'ollama.tags_normalized';
        } elseif (!$allFlag || $mode === 'nsfw_classify') {
            if ($mode === 'caption' || $mode === 'title' || $mode === 'tags_normalize' || $mode === 'quality' || $mode === 'nsfw_classify') {
                $sql .= ' LEFT JOIN media_meta mm ON mm.media_id = m.id AND mm.meta_key = :meta_key';
                $params[':meta_key'] = $mode === 'quality'
                    ? 'ollama.quality.score'
                    : ($mode === 'nsfw_classify'
                        ? 'ollama.nsfw.score'
                        : ($mode === 'caption' ? 'ollama.caption' : ($mode === 'title' ? 'ollama.title' : 'ollama.tags_normalized')));
            } elseif ($mode !== 'embed') {
                $sql .= ' LEFT JOIN ollama_results o ON o.media_id = m.id AND o.mode = :mode';
                $params[':mode'] = $mode;
            }
        }
        if ($imageRequired) {
            $sql .= ' LEFT JOIN media_meta mm_too_large ON mm_too_large.media_id = m.id AND mm_too_large.meta_key = :too_large_key';
            $params[':too_large_key'] = 'ollama.too_large_for_vision';
            $conditions[] = 'm.type = "image"';
            $conditions[] = 'mm_too_large.id IS NULL';
        }
        if ($mode === 'prompt_recon') {
            $conditions[] = 'mm_prompt.id IS NULL';
            $conditions[] = '(mm_caption.id IS NOT NULL OR mm_tags.id IS NOT NULL)';
        } elseif (!$allFlag || $mode === 'nsfw_classify') {
            if ($mode === 'caption' || $mode === 'title' || $mode === 'tags_normalize' || $mode === 'quality' || $mode === 'nsfw_classify') {
                $conditions[] = '(mm.id IS NULL OR TRIM(COALESCE(mm.meta_value, "")) = "")';
            } elseif ($mode !== 'embed') {
                $conditions[] = 'o.id IS NULL';
            }
        }
        if ($since !== null && $since !== '') {
            $conditions[] = 'm.imported_at >= :since';
            $params[':since'] = $since;
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY m.id ASC LIMIT :limit';

        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

        return $stmt;
    };

    $selectCandidates = static function (string $mode) use ($pdo, $buildCandidateQuery, $allFlag, $missingTitle, $missingCaption, $since, $mediaIdFilter, $force): array {
        $modeMissing = true;
        $hasMissingFilters = $missingTitle || $missingCaption;

        if ($mediaIdFilter > 0) {
            $stmt = $pdo->prepare('SELECT id FROM media WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $mediaIdFilter]);
            $exists = (int)$stmt->fetchColumn();
            if ($exists <= 0) {
                return [];
            }
            if ($force) {
                return [$mediaIdFilter];
            }
        }

        if ($allFlag) {
            $modeMissing = false;
        } elseif ($mode === 'title') {
            $modeMissing = $hasMissingFilters ? $missingTitle : true;
        } elseif ($mode === 'caption') {
            $modeMissing = $hasMissingFilters ? $missingCaption : true;
        } elseif ($mode === 'prompt_eval') {
            $modeMissing = true;
        } elseif ($mode === 'tags_normalize') {
            $modeMissing = true;
        } elseif ($mode === 'quality') {
            $modeMissing = true;
        } elseif ($mode === 'embed') {
        } elseif ($mode === 'prompt_recon') {
            $modeMissing = true;
        } elseif ($mode === 'nsfw_classify') {
            $modeMissing = true;
        }

        if ($mediaIdFilter > 0) {
            return [$mediaIdFilter];
        }

        $stmt = $buildCandidateQuery($mode, !$modeMissing ? true : false, $since);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    };

    $modes = $modeArg === 'all'
        ? ['caption', 'title', 'prompt_eval', 'tags_normalize', 'quality', 'nsfw_classify', 'prompt_recon', 'embed']
        : [$modeArg];
    $logs = [];

    $logger = static function (string $message) use (&$logs): void {
        $logs[] = $message;
    };

    $enqueueResult = sv_ollama_enqueue_jobs_with_autostart(
        $pdo,
        $config,
        'internal_enqueue',
        static function () use ($modes, $selectCandidates, $pdo, $config, $logger, $allFlag): array {
            $summary = [
                'candidates' => 0,
                'enqueued' => 0,
                'skipped' => 0,
                'already' => 0,
            ];

            foreach ($modes as $mode) {
                $candidateIds = $selectCandidates($mode);

                foreach ($candidateIds as $candidateId) {
                    $candidateId = (int)$candidateId;
                    if ($candidateId <= 0) {
                        continue;
                    }
                    $summary['candidates']++;

                    $payload = [];
                    if ($mode === 'prompt_eval') {
                        $promptInfo = sv_ollama_fetch_prompt($pdo, $config, $candidateId);
                        $prompt = $promptInfo['prompt'] ?? null;
                        if (!is_string($prompt) || trim($prompt) === '') {
                            $logger('Prompt-Eval übersprungen (kein Prompt): Media ' . $candidateId . '.');
                            $summary['skipped']++;
                            continue;
                        }
                        $payload['prompt'] = $prompt;
                        $payload['prompt_source'] = $promptInfo['source'] ?? null;
                    }
                    if ($mode === 'embed') {
                        $candidate = sv_ollama_embed_candidate($pdo, $config, $candidateId, $allFlag);
                        if (empty($candidate['eligible'])) {
                            $reason = isset($candidate['reason']) ? (string)$candidate['reason'] : 'Embed übersprungen.';
                            $logger('Embed übersprungen (Media ' . $candidateId . '): ' . $reason);
                            $summary['skipped']++;
                            continue;
                        }
                    }

                    try {
                        $result = sv_enqueue_ollama_job($pdo, $config, $candidateId, $mode, $payload, $logger);
                        if (!empty($result['deduped'])) {
                            $summary['already']++;
                        } else {
                            $summary['enqueued']++;
                        }
                    } catch (Throwable $e) {
                        $logger('Enqueue-Fehler (Media ' . $candidateId . '): ' . $e->getMessage());
                        $summary['skipped']++;
                    }
                }
            }

            return [
                'summary' => $summary,
            ];
        }
    );

    $summary = $enqueueResult['enqueue']['summary'] ?? [
        'candidates' => 0,
        'enqueued' => 0,
        'skipped' => 0,
        'already' => 0,
    ];
    $autoStart = $enqueueResult['autostart'] ?? [];

    $respond(200, [
        'ok' => true,
        'summary' => $summary,
        'logs' => $logs,
        'autostart' => $autoStart,
    ]);
}

$respond(400, ['ok' => false, 'error' => 'Unknown action.']);

<?php
declare(strict_types=1);

require_once __DIR__ . '/operations.php';

function sv_jobs_columns(PDO $pdo): array
{
    $columns = [];
    try {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $stmt = $pdo->query('SHOW COLUMNS FROM jobs');
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($rows as $row) {
                if (isset($row['Field'])) {
                    $columns[] = strtolower((string)$row['Field']);
                }
            }
        } else {
            $stmt = $pdo->query('PRAGMA table_info(jobs)');
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($rows as $row) {
                if (isset($row['name'])) {
                    $columns[] = strtolower((string)$row['name']);
                }
            }
        }
    } catch (Throwable $e) {
        $columns = [];
    }

    return array_unique($columns);
}

function sv_jobs_prune(PDO $pdo, array $opts): array
{
    $typePrefix = isset($opts['type_prefix']) && is_string($opts['type_prefix'])
        ? trim($opts['type_prefix'])
        : null;
    $jobTypes = $opts['job_types'] ?? [];
    $statuses = $opts['statuses'] ?? 'all';
    $scope = isset($opts['scope']) && is_string($opts['scope']) ? $opts['scope'] : 'all';
    $mediaId = isset($opts['media_id']) ? (int)$opts['media_id'] : null;
    $includeRunning = !empty($opts['include_running']);
    $forceRunning = !empty($opts['force_running']);
    $dryRun = !empty($opts['dry_run']);

    $jobTypes = is_array($jobTypes) ? array_values(array_filter(array_map('strval', $jobTypes))) : [];
    $typePrefix = $typePrefix !== '' ? $typePrefix : null;

    if ($typePrefix === null && $jobTypes === []) {
        throw new InvalidArgumentException('Job-Type-Filter fehlt.');
    }

    $where = [];
    $params = [];
    if ($typePrefix !== null && $jobTypes !== []) {
        $placeholders = implode(',', array_fill(0, count($jobTypes), '?'));
        $where[] = '(type LIKE ? OR type IN (' . $placeholders . '))';
        $params[] = $typePrefix . '%';
        $params = array_merge($params, $jobTypes);
    } elseif ($typePrefix !== null) {
        $where[] = 'type LIKE ?';
        $params[] = $typePrefix . '%';
    } elseif ($jobTypes !== []) {
        $placeholders = implode(',', array_fill(0, count($jobTypes), '?'));
        $where[] = 'type IN (' . $placeholders . ')';
        $params = array_merge($params, $jobTypes);
    }

    if ($statuses !== 'all') {
        if (is_string($statuses)) {
            $statuses = [$statuses];
        }
        $statuses = is_array($statuses) ? array_values(array_filter(array_map('strval', $statuses))) : [];
        if ($statuses !== []) {
            $placeholders = implode(',', array_fill(0, count($statuses), '?'));
            $where[] = 'status IN (' . $placeholders . ')';
            $params = array_merge($params, $statuses);
        }
    }

    if ($scope === 'media_id') {
        if (empty($mediaId)) {
            throw new InvalidArgumentException('Media-ID fehlt.');
        }
        $where[] = 'media_id = ?';
        $params[] = $mediaId;
    }

    $whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $stmt = $pdo->prepare('SELECT id, status FROM jobs ' . $whereClause);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $matchedCount = count($rows);
    $runningIds = [];
    $deleteIds = [];
    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $status = (string)($row['status'] ?? '');
        if ($status === 'running') {
            $runningIds[] = $id;
        } else {
            $deleteIds[] = $id;
        }
    }

    $blockedRunningCount = 0;
    if ($runningIds !== [] && !$includeRunning && !$forceRunning) {
        $blockedRunningCount = count($runningIds);
    } elseif ($runningIds !== [] && ($includeRunning || $forceRunning)) {
        $deleteIds = array_merge($deleteIds, $runningIds);
    }

    $updatedCount = 0;
    if ($forceRunning && $runningIds !== []) {
        $columns = sv_jobs_columns($pdo);
        if (in_array('cancel_requested', $columns, true)) {
            $cancelWhere = $where;
            $cancelParams = $params;
            $cancelWhere[] = 'status = ?';
            $cancelParams[] = 'running';
            $cancelSql = 'UPDATE jobs SET cancel_requested = 1 WHERE ' . implode(' AND ', $cancelWhere);
            $cancelStmt = $pdo->prepare($cancelSql);
            $cancelStmt->execute($cancelParams);
            $updatedCount += $cancelStmt->rowCount();
        }

        if (in_array('heartbeat_at', $columns, true)) {
            $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $threshold = (int)(defined('SV_JOB_STUCK_MINUTES') ? SV_JOB_STUCK_MINUTES : 30);
            $staleWhere = $where;
            $staleParams = $params;
            $staleWhere[] = 'status = ?';
            $staleParams[] = 'running';
            if ($driver === 'mysql') {
                $staleWhere[] = 'heartbeat_at IS NOT NULL AND heartbeat_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)';
                $staleParams[] = $threshold;
            } else {
                $staleWhere[] = "heartbeat_at IS NOT NULL AND heartbeat_at < datetime('now', ?)";
                $staleParams[] = '-' . $threshold . ' minutes';
            }

            $setParts = ['status = "cancelled"'];
            if (in_array('cancelled_at', $columns, true)) {
                $setParts[] = $driver === 'mysql' ? 'cancelled_at = NOW()' : "cancelled_at = datetime('now')";
            }
            if (in_array('updated_at', $columns, true)) {
                $setParts[] = $driver === 'mysql' ? 'updated_at = NOW()' : "updated_at = datetime('now')";
            }
            $staleSql = 'UPDATE jobs SET ' . implode(', ', $setParts) . ' WHERE ' . implode(' AND ', $staleWhere);
            $staleStmt = $pdo->prepare($staleSql);
            $staleStmt->execute($staleParams);
            $updatedCount += $staleStmt->rowCount();
        }
    }

    $deletedCount = 0;
    if (!$dryRun && $deleteIds !== []) {
        $chunks = array_chunk($deleteIds, 500);
        foreach ($chunks as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $deleteStmt = $pdo->prepare('DELETE FROM jobs WHERE id IN (' . $placeholders . ')');
            $deleteStmt->execute($chunk);
            $deletedCount += $deleteStmt->rowCount();
        }
    }

    return [
        'matched_count' => $matchedCount,
        'deleted_count' => $deletedCount,
        'updated_count' => $updatedCount,
        'blocked_running_count' => $blockedRunningCount,
        'dry_run' => $dryRun,
    ];
}

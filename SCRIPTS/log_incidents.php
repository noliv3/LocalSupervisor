<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/logging.php';

function sv_log_incidents_analyze_day(array $config, string $date, array $options = []): array
{
    $day = sv_log_incidents_normalize_date($date);
    $logsRoot = sv_logs_root($config);
    $files = sv_log_incidents_discover_files($logsRoot);
    $maxLines = isset($options['max_lines']) ? max(500, (int)$options['max_lines']) : 50000;

    $parsedEvents = [];
    $jobs = [];
    $stats = [
        'date' => $day,
        'source_files' => count($files),
        'lines_read' => 0,
        'lines_skipped' => 0,
        'events_error' => 0,
        'unknown_errors' => 0,
    ];

    foreach ($files as $filePath) {
        $lineNo = 0;
        $handle = @fopen($filePath, 'r');
        if ($handle === false) {
            continue;
        }

        while (($line = fgets($handle)) !== false) {
            $lineNo++;
            $stats['lines_read']++;
            if ($stats['lines_read'] > $maxLines) {
                break 2;
            }

            $event = sv_log_incidents_parse_line($line, $filePath, $lineNo, $day);
            if ($event === null) {
                $stats['lines_skipped']++;
                continue;
            }
            if (!sv_log_incidents_event_matches_day((string)($event['ts'] ?? ''), $day)) {
                $stats['lines_skipped']++;
                continue;
            }

            $normalized = sv_log_incidents_normalize_event($event);
            if ($normalized === null) {
                continue;
            }

            $stats['events_error']++;
            if ($normalized['error_code'] === 'UNKNOWN_ERROR') {
                $stats['unknown_errors']++;
            }

            $jobId = $normalized['job_id'];
            if ($jobId !== null) {
                if (!isset($jobs[$jobId])) {
                    $jobs[$jobId] = ['statuses' => [], 'has_result' => false];
                }
                $jobs[$jobId]['statuses'][$normalized['run_status']] = true;
                if ($normalized['run_status'] === 'success' || $normalized['run_status'] === 'retry_recovered') {
                    $jobs[$jobId]['has_result'] = true;
                }
            }

            $parsedEvents[] = $normalized;
        }

        fclose($handle);
    }

    $incidents = sv_log_incidents_group($parsedEvents, $jobs);
    usort($incidents, static function (array $a, array $b): int {
        if ((int)$a['score'] !== (int)$b['score']) {
            return (int)$b['score'] <=> (int)$a['score'];
        }
        $impactA = ((int)$a['affected_jobs_count']) + ((int)$a['affected_media_count']);
        $impactB = ((int)$b['affected_jobs_count']) + ((int)$b['affected_media_count']);
        if ($impactA !== $impactB) {
            return $impactB <=> $impactA;
        }

        return ((int)$b['count']) <=> ((int)$a['count']);
    });

    $top = array_slice($incidents, 0, 10);
    $summary = sv_log_incidents_summary($parsedEvents, $incidents, $jobs, $day);

    return [
        'summary' => $summary,
        'top_incidents' => $top,
        'gpt_textbox_text' => sv_log_incidents_build_gpt_text($day, $summary, $top),
        'debug_stats' => $stats,
    ];
}

function sv_log_incidents_normalize_date(string $date): string
{
    $trimmed = trim($date);
    if ($trimmed === '') {
        return gmdate('Y-m-d');
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $trimmed, new DateTimeZone('UTC'));
    if (!$dt instanceof DateTimeImmutable) {
        return gmdate('Y-m-d');
    }

    return $dt->format('Y-m-d');
}

function sv_log_incidents_discover_files(string $logsRoot): array
{
    $patterns = [
        $logsRoot . DIRECTORY_SEPARATOR . '*.jsonl',
        $logsRoot . DIRECTORY_SEPARATOR . '*.log',
        $logsRoot . DIRECTORY_SEPARATOR . '*.out.log',
        $logsRoot . DIRECTORY_SEPARATOR . '*.err.log',
    ];

    $files = [];
    foreach ($patterns as $pattern) {
        $matches = glob($pattern);
        if (is_array($matches)) {
            foreach ($matches as $path) {
                if (!is_file($path)) {
                    continue;
                }
                $base = basename($path);
                if (str_ends_with($base, '.old')) {
                    continue;
                }
                $files[$path] = $path;
            }
        }
    }

    ksort($files, SORT_NATURAL);
    return array_values($files);
}

function sv_log_incidents_parse_line(string $line, string $sourceFile, int $lineNo, string $day): ?array
{
    $raw = rtrim($line, "\r\n");
    if (trim($raw) === '') {
        return null;
    }

    $decoded = null;
    if (str_starts_with(ltrim($raw), '{')) {
        $try = json_decode($raw, true);
        if (is_array($try)) {
            $decoded = $try;
        }
    }

    $sourceBase = basename($sourceFile);
    $service = sv_log_incidents_service_from_filename($sourceBase);
    $ts = null;
    $level = null;
    $message = $raw;
    $jobId = null;
    $mediaId = null;
    $requestId = null;
    $component = null;

    if (is_array($decoded)) {
        $ts = isset($decoded['ts']) && is_string($decoded['ts']) ? sv_log_incidents_iso_utc($decoded['ts']) : null;
        $level = isset($decoded['level']) && is_string($decoded['level']) ? strtolower(trim($decoded['level'])) : null;
        $service = (string)($decoded['service'] ?? $decoded['worker_type'] ?? $service);
        $component = isset($decoded['component']) && is_string($decoded['component']) ? $decoded['component'] : null;
        $requestId = isset($decoded['request_id']) && is_string($decoded['request_id']) ? $decoded['request_id'] : null;

        $context = (isset($decoded['context']) && is_array($decoded['context'])) ? $decoded['context'] : [];
        if ($component === null && isset($context['component']) && is_string($context['component'])) {
            $component = $context['component'];
        }

        $parts = [];
        foreach (['message', 'event', 'state'] as $key) {
            if (isset($decoded[$key]) && is_scalar($decoded[$key])) {
                $parts[] = (string)$decoded[$key];
            }
        }
        if (isset($context['error']) && is_scalar($context['error'])) {
            $parts[] = (string)$context['error'];
        }
        if ($parts !== []) {
            $message = implode(' | ', $parts);
        }

        foreach (['job_id', 'jobId'] as $jobKey) {
            if (isset($decoded[$jobKey]) && is_numeric($decoded[$jobKey])) {
                $jobId = (int)$decoded[$jobKey];
            }
            if (isset($context[$jobKey]) && is_numeric($context[$jobKey])) {
                $jobId = (int)$context[$jobKey];
            }
        }
        foreach (['media_id', 'mediaId'] as $mediaKey) {
            if (isset($decoded[$mediaKey]) && is_numeric($decoded[$mediaKey])) {
                $mediaId = (int)$decoded[$mediaKey];
            }
            if (isset($context[$mediaKey]) && is_numeric($context[$mediaKey])) {
                $mediaId = (int)$context[$mediaKey];
            }
        }
    }

    if ($ts === null && preg_match('/(\d{4}-\d{2}-\d{2}[T ][^\s]+)/', $raw, $m) === 1) {
        $ts = sv_log_incidents_iso_utc($m[1]);
    }

    if ($ts === null) {
        $ts = $day . 'T00:00:00Z';
    }

    if ($jobId === null && preg_match('/\bjob(?:_id|\s*id)?\s*[:=#]?\s*(\d{1,10})\b/i', $raw, $m) === 1) {
        $jobId = (int)$m[1];
    }
    if ($mediaId === null && preg_match('/\bmedia(?:_id|\s*id)?\s*[:=#]?\s*(\d{1,10})\b/i', $raw, $m) === 1) {
        $mediaId = (int)$m[1];
    }

    return [
        'ts' => $ts,
        'service' => $service !== '' ? $service : 'unknown_service',
        'component' => $component,
        'level' => $level,
        'message' => $message,
        'job_id' => $jobId,
        'media_id' => $mediaId,
        'request_id' => $requestId,
        'raw_line' => $raw,
        'source_file' => $sourceBase,
        'line_no' => $lineNo,
    ];
}


function sv_log_incidents_event_matches_day(string $ts, string $day): bool
{
    if ($ts === '' || $day === '') {
        return false;
    }

    return str_starts_with($ts, $day . 'T');
}

function sv_log_incidents_iso_utc(string $value): ?string
{
    try {
        $dt = new DateTimeImmutable($value);
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    } catch (Throwable $e) {
        return null;
    }
}

function sv_log_incidents_service_from_filename(string $filename): string
{
    $name = preg_replace('/\.(jsonl|log)$/i', '', $filename);
    if (!is_string($name) || $name === '') {
        return 'unknown_service';
    }

    return preg_replace('/[^a-z0-9_\-]+/i', '_', strtolower($name)) ?: 'unknown_service';
}

function sv_log_incidents_normalize_event(array $event): ?array
{
    $message = strtolower((string)($event['message'] ?? ''));
    if ($message === '') {
        return null;
    }

    $mapping = sv_log_incidents_map_error($message);
    if ($mapping === null) {
        return null;
    }

    $fingerprint = sv_log_incidents_fingerprint($mapping['fingerprint_seed'] ?? $message);

    return array_merge($event, [
        'error_code' => $mapping['error_code'],
        'fingerprint' => $fingerprint,
        'severity' => $mapping['severity'],
        'actionability' => $mapping['actionability'],
        'retryable' => $mapping['retryable'],
        'run_status' => $mapping['run_status'],
    ]);
}

function sv_log_incidents_map_error(string $message): ?array
{
    if (strpos($message, 'deadline exceeded') !== false) {
        return ['error_code' => 'OLLAMA_TIMEOUT', 'severity' => 'P2', 'actionability' => 'external', 'retryable' => true, 'run_status' => 'failed_transient', 'fingerprint_seed' => 'ollama timeout deadline_exceeded'];
    }
    if (strpos($message, 'parse_error: title missing[path]') !== false) {
        return ['error_code' => 'OLLAMA_PARSE_TITLE_PATH_MISSING', 'severity' => 'P1', 'actionability' => 'data', 'retryable' => false, 'run_status' => 'completed_no_result', 'fingerprint_seed' => 'ollama parse title_missing_path'];
    }
    if (strpos($message, 'allowed memory size') !== false && strpos($message, 'exhausted') !== false) {
        return ['error_code' => 'PHP_MEMORY_EXHAUSTED', 'severity' => 'P0', 'actionability' => 'infra', 'retryable' => false, 'run_status' => 'failed_fatal', 'fingerprint_seed' => 'php fatal memory_exhausted'];
    }
    if (strpos($message, 'healthcheck fehlgeschlagen') !== false) {
        return ['error_code' => 'SERVER_HEALTHCHECK_FAILED', 'severity' => 'P1', 'actionability' => 'infra', 'retryable' => true, 'run_status' => 'blocked_dependency', 'fingerprint_seed' => 'server healthcheck failed'];
    }
    if (strpos($message, 'php-server konnte nicht gestartet werden') !== false) {
        return ['error_code' => 'SERVER_START_FAILED', 'severity' => 'P1', 'actionability' => 'infra', 'retryable' => false, 'run_status' => 'failed_final', 'fingerprint_seed' => 'server start failed'];
    }
    if (strpos($message, 'http 403') !== false && strpos($message, '/check') !== false) {
        return ['error_code' => 'SCANNER_HTTP_403_CHECK', 'severity' => 'P2', 'actionability' => 'external', 'retryable' => true, 'run_status' => 'blocked_dependency', 'fingerprint_seed' => 'scanner http_403 check'];
    }
    if (strpos($message, 'ollama-preflight: modelle fehlen') !== false) {
        return ['error_code' => 'OLLAMA_MODELS_MISSING', 'severity' => 'P1', 'actionability' => 'config', 'retryable' => false, 'run_status' => 'blocked_dependency', 'fingerprint_seed' => 'ollama models_missing'];
    }

    if (
        strpos($message, 'error') !== false
        || strpos($message, 'fatal') !== false
        || strpos($message, 'failed') !== false
        || strpos($message, 'exception') !== false
        || strpos($message, 'timeout') !== false
    ) {
        return ['error_code' => 'UNKNOWN_ERROR', 'severity' => 'P3', 'actionability' => 'unknown', 'retryable' => false, 'run_status' => 'failed_final', 'fingerprint_seed' => $message];
    }

    return null;
}

function sv_log_incidents_fingerprint(string $seed): string
{
    $fp = strtolower($seed);
    $fp = preg_replace('/\b\d+\b/', '{n}', $fp) ?? $fp;
    $fp = preg_replace('/\b[0-9a-f]{8,}\b/i', '{id}', $fp) ?? $fp;
    $fp = preg_replace('/\b\d{4}-\d{2}-\d{2}[t\s]\d{2}:\d{2}:\d{2}(?:\.\d+)?z?\b/i', '{ts}', $fp) ?? $fp;
    $fp = preg_replace('~(?:[a-z]:)?[/\\][^\s]+~i', '{path}', $fp) ?? $fp;
    $fp = preg_replace('/\s+/', ' ', $fp) ?? $fp;

    return trim($fp);
}

function sv_log_incidents_group(array $events, array $jobs): array
{
    $groups = [];
    foreach ($events as $event) {
        $key = $event['error_code'] . '|' . $event['fingerprint'];
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'error_code' => $event['error_code'],
                'fingerprint' => $event['fingerprint'],
                'severity' => $event['severity'],
                'actionability' => $event['actionability'],
                'count' => 0,
                'affected_jobs' => [],
                'affected_media' => [],
                'first_seen' => $event['ts'],
                'last_seen' => $event['ts'],
                'recovered_count' => 0,
                'final_failed_count' => 0,
                'sample_lines' => [],
                'source_services' => [],
                'score' => 0,
            ];
        }

        $groups[$key]['count']++;
        $groups[$key]['first_seen'] = min((string)$groups[$key]['first_seen'], (string)$event['ts']);
        $groups[$key]['last_seen'] = max((string)$groups[$key]['last_seen'], (string)$event['ts']);

        if ($event['job_id'] !== null) {
            $groups[$key]['affected_jobs'][(int)$event['job_id']] = true;
            if (isset($jobs[(int)$event['job_id']])) {
                if (isset($jobs[(int)$event['job_id']]['statuses']['retry_recovered'])) {
                    $groups[$key]['recovered_count']++;
                }
            }
        }
        if ($event['media_id'] !== null) {
            $groups[$key]['affected_media'][(int)$event['media_id']] = true;
        }

        if (in_array($event['run_status'], ['failed_final', 'failed_fatal', 'completed_no_result', 'blocked_dependency', 'invalid_input'], true)) {
            $groups[$key]['final_failed_count']++;
        }

        if (count($groups[$key]['sample_lines']) < 3) {
            $groups[$key]['sample_lines'][] = $event['raw_line'];
        }

        $groups[$key]['source_services'][(string)$event['service']] = true;
    }

    $result = [];
    foreach ($groups as $group) {
        $group['affected_jobs_count'] = count($group['affected_jobs']);
        $group['affected_media_count'] = count($group['affected_media']);
        unset($group['affected_jobs'], $group['affected_media']);

        $group['source_services'] = array_values(array_keys($group['source_services']));
        $group['score'] = sv_log_incidents_score_group($group);
        $result[] = $group;
    }

    return $result;
}

function sv_log_incidents_score_group(array $group): int
{
    $score = 0;
    $code = (string)($group['error_code'] ?? '');

    if ($code === 'PHP_MEMORY_EXHAUSTED') {
        $score += 100;
    }
    if ($code === 'SERVER_START_FAILED' || $code === 'SERVER_HEALTHCHECK_FAILED') {
        $score += 80;
    }
    if ($code === 'OLLAMA_PARSE_TITLE_PATH_MISSING') {
        $score += 70;
    }
    if ($code === 'OLLAMA_TIMEOUT') {
        $score += 30;
    }

    $score += ((int)$group['final_failed_count'] * 60);
    if (((int)$group['affected_jobs_count'] + (int)$group['affected_media_count']) >= 3) {
        $score += 10;
    }
    $score -= ((int)$group['recovered_count'] * 40);

    return $score;
}

function sv_log_incidents_summary(array $events, array $incidents, array $jobs, string $date): array
{
    $statuses = [
        'failed_fatal' => 0,
        'completed_no_result' => 0,
        'retry_recovered' => 0,
        'failed_final' => 0,
    ];
    foreach ($events as $event) {
        $status = (string)$event['run_status'];
        if (isset($statuses[$status])) {
            $statuses[$status]++;
        }
    }

    $services = [];
    foreach ($events as $event) {
        $services[(string)$event['service']] = true;
    }

    $safeJobs = 0;
    foreach ($jobs as $job) {
        $states = array_keys($job['statuses']);
        $hasResult = !empty($job['has_result']);
        if ($hasResult && $states === ['success']) {
            $safeJobs++;
        }
    }

    return [
        'date' => $date,
        'services' => array_values(array_keys($services)),
        'error_events' => count($events),
        'incident_groups' => count($incidents),
        'failed_fatal' => $statuses['failed_fatal'],
        'completed_no_result' => $statuses['completed_no_result'],
        'retry_recovered' => $statuses['retry_recovered'],
        'failed_final' => $statuses['failed_final'],
        'success_jobs' => $safeJobs,
        'total_jobs_observed' => count($jobs),
    ];
}

function sv_log_incidents_build_gpt_text(string $date, array $summary, array $top): string
{
    $lines = [];
    $lines[] = '# Tagesbericht Log-Incidents ' . $date;
    $lines[] = '';
    $lines[] = '## Kontext';
    $lines[] = '- Zeitraum: ' . $date . ' 00:00-23:59 UTC';
    $lines[] = '- Services: ' . implode(', ', $summary['services'] ?: ['n/a']);
    $lines[] = '- Erfolgsdefinition: success nur bei technischem + fachlichem Erfolg mit validem Ergebnis; fehlendes Ergebnis => completed_no_result';
    $lines[] = '';
    $lines[] = '## Top 10 Incidents (aggregiert)';

    if ($top === []) {
        $lines[] = '- Keine Incident-Gruppen im Zeitraum.';
    } else {
        foreach ($top as $idx => $incident) {
            $lines[] = sprintf(
                '%d. [%s] %s | count=%d | impact_jobs=%d | impact_media=%d | recovered=%d | final_failed=%d',
                $idx + 1,
                (string)$incident['severity'],
                (string)$incident['error_code'],
                (int)$incident['count'],
                (int)$incident['affected_jobs_count'],
                (int)$incident['affected_media_count'],
                (int)$incident['recovered_count'],
                (int)$incident['final_failed_count']
            );
        }
    }

    $lines[] = '';
    $lines[] = '## Zusammenfassung';
    $lines[] = '- Fatal: ' . (int)$summary['failed_fatal'];
    $lines[] = '- Final failed: ' . (int)$summary['failed_final'];
    $lines[] = '- Completed no result: ' . (int)$summary['completed_no_result'];
    $lines[] = '- Retry recovered: ' . (int)$summary['retry_recovered'];
    $lines[] = '';
    $lines[] = '## Analyseauftrag';
    $lines[] = '- Nenne 3 wahrscheinlichste Ursachen für die Top-Incidents.';
    $lines[] = '- Nenne 3 konkrete Fixes mit Reihenfolge und Owner-Rolle.';
    $lines[] = '- Definiere Messpunkte (SLO/SLA, Error-Budget, Recovery-Time) zur Verifikation.';

    return implode("\n", $lines);
}

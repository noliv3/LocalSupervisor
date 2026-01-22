<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Nur CLI erlaubt.\n");
    exit(1);
}

require_once __DIR__ . '/ollama_jobs.php';

try {
    $config = sv_load_config();
    $pdo    = sv_open_pdo($config);
} catch (Throwable $e) {
    fwrite(STDERR, "Init-Fehler: " . $e->getMessage() . "\n");
    exit(1);
}

$limit = 50;
$modeArg = 'all';
$since = null;
$allFlag = false;
$missingTitle = false;
$missingCaption = false;

foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $limit = (int)substr($arg, 8);
    } elseif (strpos($arg, '--since=') === 0) {
        $since = trim(substr($arg, 8));
    } elseif ($arg === '--all') {
        $allFlag = true;
    } elseif ($arg === '--missing-title') {
        $missingTitle = true;
    } elseif ($arg === '--missing-caption') {
        $missingCaption = true;
    } elseif (strpos($arg, '--mode=') === 0) {
        $modeArg = trim(substr($arg, 7));
    }
}

$modeArg = $modeArg === '' ? 'all' : $modeArg;
$allowedModes = ['caption', 'title', 'prompt_eval', 'all'];
if (!in_array($modeArg, $allowedModes, true)) {
    fwrite(STDERR, "Ungültiger --mode-Wert. Erlaubt: caption|title|prompt_eval|all\n");
    exit(1);
}

$logger = function (string $msg): void {
    fwrite(STDOUT, $msg . PHP_EOL);
};

if ($limit <= 0) {
    $limit = 50;
}

$buildCandidateQuery = static function (string $mode, bool $allFlag, ?string $since) use ($pdo, $limit): PDOStatement {
    $sql = 'SELECT m.id FROM media m';
    $params = [];

    if (!$allFlag) {
        $sql .= ' LEFT JOIN ollama_results o ON o.media_id = m.id AND o.mode = :mode';
        $params[':mode'] = $mode;
    }

    $conditions = [];
    if (!$allFlag) {
        $conditions[] = 'o.id IS NULL';
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

$selectCandidates = static function (string $mode) use ($buildCandidateQuery, $allFlag, $missingTitle, $missingCaption, $since): array {
    $modeMissing = true;
    $hasMissingFilters = $missingTitle || $missingCaption;

    if ($allFlag) {
        $modeMissing = false;
    } elseif ($mode === 'title') {
        $modeMissing = $hasMissingFilters ? $missingTitle : true;
    } elseif ($mode === 'caption') {
        $modeMissing = $hasMissingFilters ? $missingCaption : true;
    } elseif ($mode === 'prompt_eval') {
        $modeMissing = true;
    }

    $stmt = $buildCandidateQuery($mode, !$modeMissing ? true : false, $since);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
};

$modes = $modeArg === 'all' ? ['caption', 'title', 'prompt_eval'] : [$modeArg];

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

$line = sprintf(
    'Kandidaten: %d | Enqueued: %d | Bereits: %d | Übersprungen: %d',
    $summary['candidates'],
    $summary['enqueued'],
    $summary['already'],
    $summary['skipped']
);
$logger($line);

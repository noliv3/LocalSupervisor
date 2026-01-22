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
$offset = 0;
$mediaId = null;
$force = false;
$typeArg = 'both';

foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $limit = (int)substr($arg, 8);
    } elseif (strpos($arg, '--offset=') === 0) {
        $offset = (int)substr($arg, 9);
    } elseif (strpos($arg, '--media-id=') === 0) {
        $mediaId = (int)substr($arg, 11);
    } elseif (strpos($arg, '--force=') === 0) {
        $force = (int)substr($arg, 8) === 1;
    } elseif (strpos($arg, '--type=') === 0) {
        $typeArg = trim(substr($arg, 7));
    }
}

$types = [];
if ($typeArg === 'caption') {
    $types = [SV_JOB_TYPE_OLLAMA_CAPTION];
} elseif ($typeArg === 'title') {
    $types = [SV_JOB_TYPE_OLLAMA_TITLE];
} elseif ($typeArg === 'both' || $typeArg === '') {
    $types = [SV_JOB_TYPE_OLLAMA_CAPTION, SV_JOB_TYPE_OLLAMA_TITLE];
} else {
    fwrite(STDERR, "Ungültiger --type-Wert. Erlaubt: caption|title|both\n");
    exit(1);
}

$logger = function (string $msg): void {
    fwrite(STDOUT, $msg . PHP_EOL);
};

$findCandidates = static function (PDO $pdo, string $metaKey, ?int $limit, ?int $offset, ?int $mediaId, bool $force): array {
    if ($mediaId !== null && $mediaId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM media WHERE id = :id');
        $stmt->execute([':id' => $mediaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return [];
        }

        if ($force) {
            return [$mediaId];
        }

        $metaStmt = $pdo->prepare('SELECT 1 FROM media_meta WHERE media_id = :media_id AND meta_key = :meta_key LIMIT 1');
        $metaStmt->execute([
            ':media_id' => $mediaId,
            ':meta_key' => $metaKey,
        ]);
        if ($metaStmt->fetchColumn()) {
            return [];
        }

        return [$mediaId];
    }

    if ($limit === null || $limit <= 0) {
        $limit = 50;
    }
    if ($offset === null || $offset < 0) {
        $offset = 0;
    }

    if ($force) {
        $stmt = $pdo->prepare('SELECT id FROM media ORDER BY id ASC LIMIT :limit OFFSET :offset');
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    }

    $stmt = $pdo->prepare(
        'SELECT m.id FROM media m '
        . 'LEFT JOIN media_meta mm ON mm.media_id = m.id AND mm.meta_key = :meta_key '
        . 'WHERE mm.id IS NULL '
        . 'ORDER BY m.id ASC LIMIT :limit OFFSET :offset'
    );
    $stmt->bindValue(':meta_key', $metaKey, PDO::PARAM_STR);
    $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
};

$total = 0;
$enqueued = 0;
$deduped = 0;

foreach ($types as $jobType) {
    $metaKey = sv_ollama_job_meta_key($jobType);
    $candidateIds = $findCandidates($pdo, $metaKey, $limit, $offset, $mediaId, $force);

    foreach ($candidateIds as $candidateId) {
        $candidateId = (int)$candidateId;
        if ($candidateId <= 0) {
            continue;
        }
        $total++;
        try {
            $result = sv_enqueue_ollama_job($pdo, $config, $candidateId, $jobType, [
                'force' => $force ? 1 : 0,
            ], $logger);
            if (!empty($result['deduped'])) {
                $deduped++;
            } else {
                $enqueued++;
            }
        } catch (Throwable $e) {
            $logger('Enqueue-Fehler (Media ' . $candidateId . '): ' . $e->getMessage());
        }
    }
}

$line = sprintf(
    'Kandidaten: %d | Enqueued: %d | Dedupe: %d',
    $total,
    $enqueued,
    $deduped
);
$logger($line);

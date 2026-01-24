<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Nur CLI erlaubt.\n");
    exit(1);
}

require_once __DIR__ . '/ollama_jobs.php';

try {
    $config = sv_load_config();
    $pdo = sv_open_pdo($config);
} catch (Throwable $e) {
    fwrite(STDERR, 'Init-Fehler: ' . $e->getMessage() . "\n");
    exit(1);
}

$limit = 200;
$topk = 8;
$threshold = 0.92;
$domainSplit = true;
$dryRun = false;

foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $limit = (int)substr($arg, 8);
    } elseif (strpos($arg, '--topk=') === 0) {
        $topk = (int)substr($arg, 7);
    } elseif (strpos($arg, '--threshold=') === 0) {
        $threshold = (float)substr($arg, 12);
    } elseif (strpos($arg, '--domain-split=') === 0) {
        $domainSplit = (int)substr($arg, 15) === 1;
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    }
}

if ($limit <= 0) {
    $limit = 200;
}
if ($topk <= 0) {
    $topk = 8;
}
if ($threshold <= 0.0) {
    $threshold = 0.92;
}

$logger = static function (string $msg): void {
    fwrite(STDOUT, $msg . PHP_EOL);
};

$seedStmt = $pdo->prepare(
    'SELECT mm.media_id, mm.meta_value AS vector_id '
    . 'FROM media_meta mm '
    . 'LEFT JOIN media_meta mh ON mh.id = ('
    . '  SELECT id FROM media_meta WHERE media_id = mm.media_id AND meta_key = :dupe_key ORDER BY id DESC LIMIT 1'
    . ') '
    . 'WHERE mm.id = ('
    . '  SELECT id FROM media_meta WHERE media_id = mm.media_id AND meta_key = :vector_key ORDER BY id DESC LIMIT 1'
    . ') '
    . 'AND mh.id IS NULL '
    . 'ORDER BY mm.media_id ASC LIMIT :limit'
);
$seedStmt->bindValue(':dupe_key', 'ollama.dupe_hints.top', PDO::PARAM_STR);
$seedStmt->bindValue(':vector_key', 'ollama.embed.text.vector_id', PDO::PARAM_STR);
$seedStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$seedStmt->execute();
$seeds = $seedStmt->fetchAll(PDO::FETCH_ASSOC);

if ($seeds === []) {
    $logger('Keine Kandidaten gefunden.');
    exit(0);
}

$vectorStmt = $pdo->prepare(
    'SELECT id, media_id, model, dims, vector_json FROM ollama_vectors WHERE id = :id LIMIT 1'
);

$summary = [
    'processed' => 0,
    'written' => 0,
    'skipped' => 0,
];

$stageVersion = 'stage6_dupe_hints_v1';

foreach ($seeds as $seed) {
    $mediaId = (int)($seed['media_id'] ?? 0);
    $vectorId = (int)($seed['vector_id'] ?? 0);
    if ($mediaId <= 0 || $vectorId <= 0) {
        $summary['skipped']++;
        continue;
    }

    $vectorStmt->execute([':id' => $vectorId]);
    $vectorRow = $vectorStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($vectorRow)) {
        $logger('Vector fehlt für Media ' . $mediaId . '.');
        $summary['skipped']++;
        continue;
    }

    $model = (string)($vectorRow['model'] ?? '');
    $dims = (int)($vectorRow['dims'] ?? 0);
    $vectorJson = (string)($vectorRow['vector_json'] ?? '');
    if ($model === '' || $dims <= 0 || $vectorJson === '') {
        $logger('Ungültiger Vector für Media ' . $mediaId . '.');
        $summary['skipped']++;
        continue;
    }

    $seedVector = sv_ollama_vector_decode($vectorJson);
    if ($seedVector === null || count($seedVector) !== $dims) {
        $logger('Vector-Decode fehlgeschlagen für Media ' . $mediaId . '.');
        $summary['skipped']++;
        continue;
    }

    $seedDomainType = null;
    if ($domainSplit) {
        $domainRaw = sv_get_media_meta_value($pdo, $mediaId, 'ollama.domain.type');
        if (is_string($domainRaw) && trim($domainRaw) !== '') {
            $seedDomainType = trim($domainRaw);
        }
    }

    $candidateSql =
        'SELECT mm.media_id, ov.vector_json '
        . 'FROM media_meta mm '
        . 'JOIN ollama_vectors ov ON ov.id = CAST(mm.meta_value AS INTEGER) '
        . 'LEFT JOIN media_meta md ON md.id = ('
        . '  SELECT id FROM media_meta WHERE media_id = mm.media_id AND meta_key = :domain_key ORDER BY id DESC LIMIT 1'
        . ') '
        . 'WHERE mm.id = ('
        . '  SELECT id FROM media_meta WHERE media_id = mm.media_id AND meta_key = :vector_key ORDER BY id DESC LIMIT 1'
        . ') '
        . 'AND ov.model = :model AND ov.dims = :dims '
        . 'AND mm.media_id != :seed_id';

    $params = [
        ':domain_key' => 'ollama.domain.type',
        ':vector_key' => 'ollama.embed.text.vector_id',
        ':model' => $model,
        ':dims' => $dims,
        ':seed_id' => $mediaId,
    ];

    if ($seedDomainType !== null) {
        $candidateSql .= ' AND md.meta_value = :domain_type';
        $params[':domain_type'] = $seedDomainType;
    }

    $candidateSql .= ' ORDER BY mm.media_id ASC';
    $candidateStmt = $pdo->prepare($candidateSql);
    $candidateStmt->execute($params);
    $candidates = $candidateStmt->fetchAll(PDO::FETCH_ASSOC);

    $matches = [];
    $comparedCount = 0;

    foreach ($candidates as $candidate) {
        $candidateId = (int)($candidate['media_id'] ?? 0);
        $candidateVectorJson = (string)($candidate['vector_json'] ?? '');
        if ($candidateId <= 0 || $candidateVectorJson === '') {
            continue;
        }

        $candidateVector = sv_ollama_vector_decode($candidateVectorJson);
        if ($candidateVector === null || count($candidateVector) !== $dims) {
            continue;
        }

        $comparedCount++;
        $score = sv_ollama_cosine_similarity($seedVector, $candidateVector);
        if ($score === null || $score < $threshold) {
            continue;
        }

        $matches[] = [
            'media_id' => $candidateId,
            'score' => round($score, 6),
            'reason' => 'embedding_text_cosine',
        ];
    }

    $topMatches = sv_ollama_select_topk($matches, $topk);
    $summary['processed']++;

    if (!$dryRun) {
        $topJson = sv_ollama_encode_json($topMatches);
        if ($topJson === null) {
            $logger('JSON-Encode fehlgeschlagen für Media ' . $mediaId . '.');
            $summary['skipped']++;
            continue;
        }

        $meta = [
            'built_from_vector_id' => $vectorId,
            'model' => $model,
            'dims' => $dims,
            'compared_count' => $comparedCount,
            'ts' => date('c'),
        ];
        $metaJson = sv_ollama_encode_json($meta) ?? '{}';

        sv_set_media_meta_value($pdo, $mediaId, 'ollama.dupe_hints.top', $topJson, 'ollama');
        sv_set_media_meta_value($pdo, $mediaId, 'ollama.dupe_hints.threshold', (string)$threshold, 'ollama');
        sv_set_media_meta_value($pdo, $mediaId, 'ollama.dupe_hints.meta', $metaJson, 'ollama');
        sv_set_media_meta_value($pdo, $mediaId, 'ollama.last_run_at', date('c'), 'ollama');
        sv_set_media_meta_value($pdo, $mediaId, 'ollama.stage_version', $stageVersion, 'ollama');

        $summary['written']++;
    }

    $logger(sprintf(
        'Media %d: %d Treffer (Top %d), %d verglichen.%s',
        $mediaId,
        count($topMatches),
        $topk,
        $comparedCount,
        $dryRun ? ' (dry-run)' : ''
    ));
}

$logger(sprintf(
    'Verarbeitet: %d | Geschrieben: %d | Übersprungen: %d',
    $summary['processed'],
    $summary['written'],
    $summary['skipped']
));

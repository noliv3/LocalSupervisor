<?php
// Stellt eindeutige Versionierung für prompt_history her und räumt Duplikate auf.

declare(strict_types=1);

$migration = [
    'version'     => '20260720_001_prompt_history_unique',
    'description' => 'Deduplicate prompt_history versions and add unique index',
];

$migration['run'] = function (PDO $pdo) use (&$migration): void {
    try {
        $pdo->beginTransaction();
    } catch (Throwable $e) {
        // Falls Transaktionen nicht unterstützt werden, weiter ohne expliziten Begin.
    }

    try {
        $mediaIds = $pdo->query('SELECT DISTINCT media_id FROM prompt_history')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($mediaIds as $mediaId) {
            $mediaId = (int)$mediaId;
            $stmt = $pdo->prepare('SELECT id FROM prompt_history WHERE media_id = :mid ORDER BY created_at ASC, id ASC');
            $stmt->execute([':mid' => $mediaId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $version = 1;
            foreach ($rows as $row) {
                $rowId = (int)($row['id'] ?? 0);
                if ($rowId <= 0) {
                    continue;
                }

                $update = $pdo->prepare('UPDATE prompt_history SET version = :v WHERE id = :id');
                $update->execute([':v' => $version, ':id' => $rowId]);

                $version++;
            }
        }

        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_prompt_history_media_version ON prompt_history(media_id, version)');

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
};

return $migration;

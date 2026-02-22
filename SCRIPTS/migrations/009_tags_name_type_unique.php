<?php
// Erlaubt gleiche Tag-Namen mit unterschiedlichen Typen (UNIQUE auf name+type).

declare(strict_types=1);

$migration = [
    'version' => '009_tags_name_type_unique',
    'description' => 'Adjust tags unique constraint to (name, type)',
];

$migration['run'] = function (PDO $pdo) use (&$migration): void {
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        $indexRows = $pdo->query("PRAGMA index_list('tags')")->fetchAll(PDO::FETCH_ASSOC);
        $hasNameType = false;
        foreach ($indexRows as $indexRow) {
            if (empty($indexRow['name']) || (int)($indexRow['unique'] ?? 0) !== 1) {
                continue;
            }
            $indexName = (string)$indexRow['name'];
            $infoRows = $pdo->query("PRAGMA index_info('{$indexName}')")->fetchAll(PDO::FETCH_ASSOC);
            $columns = array_map(static fn ($row) => (string)($row['name'] ?? ''), $infoRows);
            if ($columns === ['name', 'type']) {
                $hasNameType = true;
                break;
            }
        }

        if ($hasNameType) {
            return;
        }

        $pdo->exec('PRAGMA foreign_keys=OFF');
        try {
            $pdo->beginTransaction();
        } catch (Throwable $e) {
            // Weiter ohne expliziten Begin.
        }

        try {
            $pdo->exec('CREATE TABLE tags_new (
                id      INTEGER PRIMARY KEY AUTOINCREMENT,
                name    TEXT NOT NULL,
                type    TEXT NOT NULL DEFAULT \'content\',
                locked  INTEGER NOT NULL DEFAULT 0
            )');
            $pdo->exec('INSERT INTO tags_new (id, name, type, locked) SELECT id, name, type, locked FROM tags');
            $pdo->exec('DROP TABLE tags');
            $pdo->exec('ALTER TABLE tags_new RENAME TO tags');
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_tags_name_type ON tags(name, type)');

            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            $pdo->exec('PRAGMA foreign_keys=ON');
        }

        return;
    }

    if ($driver === 'mysql') {
        $indexStmt = $pdo->query('SHOW INDEX FROM tags');
        $indexes = $indexStmt ? $indexStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $uniqueIndexes = [];
        foreach ($indexes as $index) {
            $keyName = (string)($index['Key_name'] ?? '');
            $column = (string)($index['Column_name'] ?? '');
            $nonUnique = (int)($index['Non_unique'] ?? 1);
            if ($nonUnique !== 0 || $keyName === '') {
                continue;
            }
            if (!isset($uniqueIndexes[$keyName])) {
                $uniqueIndexes[$keyName] = [];
            }
            $uniqueIndexes[$keyName][] = $column;
        }

        foreach ($uniqueIndexes as $columns) {
            if ($columns === ['name', 'type']) {
                return;
            }
        }

        foreach ($uniqueIndexes as $name => $columns) {
            if ($columns === ['name']) {
                $pdo->exec("ALTER TABLE tags DROP INDEX `{$name}`");
                break;
            }
        }

        $pdo->exec('ALTER TABLE tags ADD UNIQUE INDEX idx_tags_name_type (name, type)');
    }
};

return $migration;

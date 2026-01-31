<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

/**
 * Baut eine PDO-Verbindung auf und liefert Metadaten zur DSN-Redaktion.
 *
 * @return array{pdo: PDO, driver: string, dsn: string, redacted_dsn: string}
 */
function sv_db_connect(array $config): array
{
    if (!isset($config['db']['dsn'])) {
        throw new RuntimeException('DB-DSN in config.php fehlt.');
    }

    $dsn      = (string)$config['db']['dsn'];
    $user     = $config['db']['user']     ?? null;
    $password = $config['db']['password'] ?? null;
    $options  = $config['db']['options']  ?? [];

    if (!isset($options[PDO::ATTR_ERRMODE])) {
        $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
    }

    $redactedDsn = sv_db_redact_dsn($dsn);

    if (str_starts_with($dsn, 'sqlite:')) {
        $sqlitePath = substr($dsn, 7);
        if ($sqlitePath !== '' && $sqlitePath !== ':memory:' && !str_starts_with($sqlitePath, 'file:')) {
            $dir = dirname($sqlitePath);
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
                    throw new RuntimeException('SQLite-Verzeichnis fehlt und konnte nicht angelegt werden: ' . $dir);
                }
            }
        }
    }

    try {
        $pdo    = new PDO($dsn, $user, $password, $options);
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        sv_apply_sqlite_pragmas($pdo, $config);
        sv_db_ensure_runtime_indexes($pdo);
    } catch (Throwable $e) {
        throw new RuntimeException(
            'DB-Verbindung fehlgeschlagen (' . $redactedDsn . '): ' . $e->getMessage(),
            0,
            $e
        );
    }

    return [
        'pdo'           => $pdo,
        'driver'        => $driver,
        'dsn'           => $dsn,
        'redacted_dsn'  => $redactedDsn,
    ];
}

function sv_apply_sqlite_pragmas(PDO $pdo, array $config): void
{
    try {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver !== 'sqlite') {
            return;
        }

        $sqliteCfg = $config['db']['sqlite'] ?? [];
        $busyTimeout = isset($sqliteCfg['busy_timeout_ms']) ? (int)$sqliteCfg['busy_timeout_ms'] : 5000;
        if ($busyTimeout > 0) {
            $pdo->exec('PRAGMA busy_timeout = ' . $busyTimeout);
        }

        $journalMode = isset($sqliteCfg['journal_mode']) && is_string($sqliteCfg['journal_mode'])
            ? strtoupper(trim($sqliteCfg['journal_mode']))
            : '';
        if ($journalMode !== '') {
            $pdo->exec('PRAGMA journal_mode = ' . $journalMode);
        }
    } catch (Throwable $e) {
        // Pragmas sind optional; Fehler sollen den Verbindungsaufbau nicht blockieren.
    }
}

function sv_db_ensure_runtime_indexes(PDO $pdo): void
{
    try {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver !== 'sqlite') {
            return;
        }

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_jobs_status_created ON jobs(status, created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_media_tags_media_locked ON media_tags(media_id, locked)');
    } catch (Throwable $e) {
        // Index-Setup ist optional; Fehler sollen Runtime-Zugriff nicht blockieren.
    }
}

function sv_db_redact_dsn(string $dsn): string
{
    $dsn = trim($dsn);
    if ($dsn === '') {
        return '<leer>';
    }

    if (str_starts_with($dsn, 'sqlite:')) {
        $path = substr($dsn, 7);
        if ($path === ':memory:') {
            return 'sqlite:<memory>';
        }
        $base = sv_base_dir();
        $path = str_replace('\\', '/', $path);
        $path = str_replace($base, '<base>', $path);
        if (strlen($path) > 80) {
            $path = '…' . substr($path, -77);
        }
        return 'sqlite:' . $path;
    }

    $parts  = explode(':', $dsn, 2);
    $driver = $parts[0] ?? '';
    $rest   = $parts[1] ?? '';

    $kv = [];
    foreach (explode(';', $rest) as $segment) {
        if ($segment === '') {
            continue;
        }
        [$k, $v] = array_pad(explode('=', $segment, 2), 2, '');
        $k       = trim($k);
        $v       = trim($v);
        if ($k === '') {
            continue;
        }
        if (in_array(strtolower($k), ['password', 'pwd'], true)) {
            $v = '***';
        }
        $kv[] = $k . '=' . $v;
    }

    return $driver . ':' . implode(';', $kv);
}

function sv_db_filter_schema_sql(string $schemaSql, string $driver): string
{
    if ($driver === 'sqlite') {
        return $schemaSql;
    }

    $lines = preg_split('~\\R~u', $schemaSql);
    $filtered = [];
    foreach ($lines as $line) {
        $trimmed = ltrim($line);
        if (stripos($trimmed, 'pragma ') === 0) {
            continue;
        }
        $filtered[] = $line;
    }

    return implode(PHP_EOL, $filtered);
}

/**
 * Erwartete Kern-Tabellen und Spalten für die DB-Konsistenzprüfung.
 *
 * @return array<string, string[]>
 */
function sv_db_expected_schema(): array
{
    return [
        'media' => [
            'id', 'path', 'type', 'source', 'width', 'height', 'duration', 'fps', 'filesize', 'hash',
            'created_at', 'imported_at', 'rating', 'has_nsfw', 'parent_media_id', 'status',
            'lifecycle_status', 'lifecycle_reason', 'quality_status', 'quality_score', 'quality_notes', 'deleted_at',
            'status_vote', 'is_active', 'is_deleted',
        ],
        'tags' => ['id', 'name', 'type', 'locked'],
        'media_tags' => ['media_id', 'tag_id', 'confidence', 'locked'],
        'scan_results' => ['id', 'media_id', 'scanner', 'run_at', 'nsfw_score', 'flags', 'raw_json'],
        'prompts' => [
            'id', 'media_id', 'prompt', 'negative_prompt', 'model', 'sampler', 'cfg_scale', 'steps', 'seed',
            'width', 'height', 'scheduler', 'sampler_settings', 'loras', 'controlnet', 'source_metadata',
        ],
        'prompt_history' => [
            'id', 'media_id', 'prompt_id', 'version', 'source', 'created_at', 'prompt', 'negative_prompt', 'model',
            'sampler', 'cfg_scale', 'steps', 'seed', 'width', 'height', 'scheduler', 'sampler_settings', 'loras',
            'controlnet', 'source_metadata', 'raw_text',
        ],
        'jobs' => [
            'id', 'media_id', 'prompt_id', 'type', 'status', 'created_at', 'updated_at',
            'forge_request_json', 'forge_response_json', 'payload_json', 'error_message',
            'last_error_code', 'heartbeat_at', 'progress_bits', 'progress_bits_total',
            'cancel_requested', 'cancelled_at',
            'worker_pid', 'worker_owner', 'stage', 'stage_changed_at',
        ],
        'media_lifecycle_events' => [
            'id', 'media_id', 'event_type', 'from_status', 'to_status', 'quality_status', 'quality_score',
            'rule', 'reason', 'actor', 'created_at',
        ],
        'collections' => ['id', 'name', 'description', 'created_at'],
        'collection_media' => ['collection_id', 'media_id'],
        'import_log' => ['id', 'path', 'status', 'message', 'created_at'],
        'schema_migrations' => ['id', 'version', 'applied_at', 'description'],
        'consistency_log' => ['id', 'check_name', 'severity', 'message', 'created_at'],
        'audit_log' => ['id', 'action', 'entity_type', 'entity_id', 'details_json', 'actor_ip', 'actor_key', 'created_at'],
        'media_meta' => ['id', 'media_id', 'source', 'meta_key', 'meta_value', 'created_at'],
    ];
}

/**
 * Vergleicht den aktuellen DB-Zustand mit dem erwarteten Schema.
 *
 * @return array{missing_tables: string[], missing_columns: array<string, string[]>, ok_tables: string[]}
 */
function sv_db_diff_schema(PDO $pdo, string $driver, ?array $expectedSchema = null): array
{
    $expectedSchema = $expectedSchema ?? sv_db_expected_schema();
    $missingTables  = [];
    $missingColumns = [];
    $okTables       = [];

    foreach ($expectedSchema as $table => $expectedColumns) {
        if (!sv_db_table_exists($pdo, $driver, $table)) {
            $missingTables[] = $table;
            continue;
        }

        $actualColumns = sv_db_list_columns($pdo, $driver, $table);
        $missing       = array_values(array_diff(
            array_map('strtolower', $expectedColumns),
            array_map('strtolower', $actualColumns)
        ));

        if ($missing !== []) {
            $missingColumns[$table] = $missing;
        } else {
            $okTables[] = $table;
        }
    }

    return [
        'missing_tables'  => $missingTables,
        'missing_columns' => $missingColumns,
        'ok_tables'       => $okTables,
    ];
}

function sv_db_table_exists(PDO $pdo, string $driver, string $table): bool
{
    try {
        if ($driver === 'mysql') {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t LIMIT 1'
            );
            $stmt->execute([':t' => $table]);
            return (bool)$stmt->fetchColumn();
        }

        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :t");
        $stmt->execute([':t' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function sv_db_list_columns(PDO $pdo, string $driver, string $table): array
{
    if ($driver === 'mysql') {
        $stmt = $pdo->prepare('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static function (array $row): string {
            return strtolower((string)($row['Field'] ?? ''));
        }, $rows);
    }

    $stmt = $pdo->prepare('PRAGMA table_info(' . $table . ')');
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return array_map(static function (array $row): string {
        return strtolower((string)($row['name'] ?? ''));
    }, $rows);
}

function sv_db_ensure_schema_migrations(PDO $pdo): void
{
    $pdo->exec(
        <<<SQL
CREATE TABLE IF NOT EXISTS schema_migrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    version TEXT NOT NULL UNIQUE,
    applied_at TEXT NOT NULL,
    description TEXT
);
SQL
    );
}

function sv_db_load_applied_versions(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT version FROM schema_migrations');
    $versions = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) as $version) {
        $versions[(string)$version] = true;
    }
    return $versions;
}

/**
 * @return list<array{version: string, description?: string, run: callable}>
 */
function sv_db_load_migrations(string $migrationDir): array
{
    $files = glob($migrationDir . '/*.php');
    sort($files, SORT_NATURAL);

    $migrations = [];
    foreach ($files as $file) {
        $migration = require $file;

        if (!is_array($migration) || empty($migration['version']) || !isset($migration['run'])) {
            fwrite(STDERR, "Ungültige Migrationsdatei: {$file}\n");
            exit(1);
        }

        $baseName = basename($file, '.php');
        if ($baseName !== $migration['version']) {
            fwrite(
                STDERR,
                "Versionsstring passt nicht zum Dateinamen ({$baseName}): {$migration['version']}\n"
            );
            exit(1);
        }

        if (!is_callable($migration['run'])) {
            fwrite(STDERR, "Migration besitzt keine ausführbare run()-Funktion: {$file}\n");
            exit(1);
        }

        $migrations[] = $migration;
    }

    return $migrations;
}

function sv_db_record_version(PDO $pdo, string $version, string $description): void
{
    $stmt = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = ? LIMIT 1');
    $stmt->execute([$version]);
    if ($stmt->fetchColumn() !== false) {
        return;
    }

    $insert = $pdo->prepare(
        'INSERT INTO schema_migrations (version, applied_at, description) VALUES (?, ?, ?)'
    );
    $insert->execute([
        $version,
        date('c'),
        $description,
    ]);
}

<?php
/** Read-only structural snapshot for an isolated upgrade rehearsal; excludes business rows. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--export-structure-only'
    || getenv('COREFLUX_PRODUCTION_PREFLIGHT_ROOT') !== '/home/1516771.cloudwaysapps.com/grcudkpvcd/public_html'
    || realpath(getcwd()) !== getenv('COREFLUX_PRODUCTION_PREFLIGHT_ROOT')) {
    throw new RuntimeException('Exact production inspection scope required.');
}
$root = (string) getenv('COREFLUX_PRODUCTION_PREFLIGHT_ROOT');
require_once $root . '/core/db.php';
$db = getDB();
if (!$db || $db->query('SELECT DATABASE()')->fetchColumn() !== 'grcudkpvcd') {
    throw new RuntimeException('Database identity differs.');
}
$db->exec('SET SESSION TRANSACTION READ ONLY');
$db->beginTransaction();
$identifier = static function (string $name): string {
    if (!preg_match('/^[A-Za-z0-9_]+$/D', $name)) throw new RuntimeException('Unsupported schema identifier.');
    return '`' . $name . '`';
};
$metadata = static function () use ($db, $identifier): array {
    $objects = [];
    foreach ($db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as [$name, $kind]) {
        if (!in_array($kind, ['BASE TABLE', 'VIEW'], true)) throw new RuntimeException('Unexpected object type.');
        $row = $db->query('SHOW CREATE ' . ($kind === 'VIEW' ? 'VIEW ' : 'TABLE ') . $identifier($name))->fetch(PDO::FETCH_NUM);
        // Counters change under ordinary writes and are not structural evidence.
        $sql = preg_replace('/\bAUTO_INCREMENT=\d+\b/', 'AUTO_INCREMENT=1', (string) $row[1]);
        $objects[$name] = ['kind' => $kind, 'sql' => $sql, 'sha256' => hash('sha256', $sql)];
    }
    ksort($objects, SORT_STRING);
    $triggers = [];
    foreach ($db->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_ASSOC) as $trigger) {
        $name = (string) $trigger['Trigger'];
        $row = $db->query('SHOW CREATE TRIGGER ' . $identifier($name))->fetch(PDO::FETCH_ASSOC);
        $sql = (string) $row['SQL Original Statement'];
        $triggers[$name] = ['sql' => $sql, 'sql_mode' => $row['sql_mode'], 'sha256' => hash('sha256', $sql)];
    }
    ksort($triggers, SORT_STRING);
    return ['objects' => $objects, 'triggers' => $triggers];
};
try {
    $before = $metadata();
    $ledger = $db->query('SELECT filename, sha256, last_error IS NOT NULL AS failed FROM _migrations ORDER BY filename')->fetchAll(PDO::FETCH_ASSOC);
    $legacy = $db->query('SELECT file_path, checksum_sha FROM coreflux_migrations ORDER BY file_path')->fetchAll(PDO::FETCH_ASSOC);
    $migrationSources = [];
    $paths = glob($root . '/core/migrations/*.sql') ?: [];
    foreach (glob($root . '/modules/*/migrations/*.sql') ?: [] as $file) $paths[] = $file;
    foreach ($paths as $file) {
        if (is_link($file) || !str_starts_with((string) realpath($file), $root . '/')) throw new RuntimeException('Unsafe migration source.');
        $bytes = file_get_contents($file);
        if ($bytes === false) throw new RuntimeException('Unreadable migration source.');
        $migrationSources[substr($file, strlen($root) + 1)] = [
            'sha256' => hash('sha256', $bytes),
            'normalized_sha256' => hash('sha256', str_replace("\r\n", "\n", $bytes)),
            'git_blob' => hash('sha1', 'blob ' . strlen($bytes) . "\0" . $bytes),
        ];
    }
    ksort($migrationSources, SORT_STRING);
    $routineCount = (int) $db->query('SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()')->fetchColumn();
    $eventCount = (int) $db->query('SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()')->fetchColumn();
    if ($metadata() !== $before) throw new RuntimeException('Schema changed during the inspection; no snapshot emitted.');
    $result = ['schema_version' => 1, 'observed_at' => gmdate('c'), 'database' => 'grcudkpvcd',
        'server_version' => $db->query('SELECT VERSION()')->fetchColumn(),
        'sql_mode' => $db->query('SELECT @@SESSION.sql_mode')->fetchColumn(),
        'database_defaults' => $db->query('SELECT DEFAULT_CHARACTER_SET_NAME,DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=DATABASE()')->fetch(PDO::FETCH_ASSOC),
        'schema' => $before, 'canonical_ledger' => $ledger, 'legacy_ledger' => $legacy,
        'migration_sources' => $migrationSources, 'routine_count' => $routineCount, 'event_count' => $eventCount,
        'business_rows_exported' => 0, 'credentials_exported' => false, 'database_writes' => false,
        'limitation' => 'Structure and migration history only; not a consistent populated backup or data-conversion proof.'];
    $db->rollBack();
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    throw $error;
}

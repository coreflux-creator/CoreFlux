<?php
/** Reproduce the core-before-module migration order and legacy placement audit schema. */
declare(strict_types=1);
$inCi = getenv('GITHUB_ACTIONS') === 'true' && getenv('CI') === 'true';
$local = getenv('COREFLUX_DELIVERY_LOCAL_FIXTURE') === '1'
    && in_array('--confirm-disposable-fixture', $argv, true)
    && (string)getenv('COREFLUX_DELIVERY_TEST_PASSWORD') !== '';
if (!$inCi && !$local) throw new RuntimeException('Explicit disposable fixture configuration required');
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__ . '/../core/migrate.php';
require_once __DIR__ . '/../modules/placements/lib/placements.php';
$password = $inCi ? 'root' : getenv('COREFLUX_DELIVERY_TEST_PASSWORD');
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', $password, $options);
$db = 'coreflux_qschema_' . ($inCi ? 'ci' : gmdate('YmdHis') . '_' . getmypid());
$pdo->exec('CREATE DATABASE ' . $db);
$pdo->exec('USE ' . $db);
$pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
$migrate = static function(string $path) use (&$pdo): void {
    foreach (coreflux_split_sql_statements(file_get_contents(__DIR__ . '/../' . $path)) as $sql) $pdo->exec($sql);
};
$passed = 0;
$check = static function(bool $ok, string $label) use (&$passed): void {
    if (!$ok) throw new RuntimeException($label);
    $passed++;
    echo "PASS $label\n";
};
$fails = static function(callable $action): bool { try { $action(); return false; } catch (PDOException $e) { return true; } };
$repair = 'modules/time/migrations/012_quanta_source_enums.sql';
$migrate('core/migrations/147_quanta_time_integration.sql');
$migrate($repair); // No time module installed: safe no-op.
$migrate('modules/time/migrations/001_init.sql');
$insert = static function(string $source) use (&$pdo): void {
    $pdo->prepare('INSERT INTO time_entries(tenant_id,placement_id,person_id,period_id,work_date,category,hours,source) VALUES(999,13,15,1,"2026-10-19","regular_billable",8,?)')->execute([$source]);
};
$insert('manual_entry');
$check($fails(fn() => $insert('quanta')), 'original core-before-module order reproduces rejected Quanta source');
$migrate($repair);
$insert('quanta');
$check((int)$pdo->query("SELECT COUNT(*) FROM time_entries WHERE source='quanta'")->fetchColumn() === 1, 'module migration enables real Quanta source inserts');
$check($pdo->query('SELECT source FROM time_entries ORDER BY id LIMIT 1')->fetchColumn() === 'manual_entry', 'existing time source is preserved');
$columns = static fn(): array => $pdo->query("SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,COLLATION_NAME,COLUMN_COMMENT FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='time_entries' AND column_name IN ('source','source_system') ORDER BY column_name")->fetchAll(PDO::FETCH_ASSOC);
$after = $columns();
$migrate($repair);
$check($columns() === $after, 'migration replay leaves repaired schema unchanged');

// Preserve installations with extra integrations, custom defaults and nullable provenance.
$pdo->exec("UPDATE time_entries SET source='manual_entry' WHERE source='quanta'");
$pdo->exec("ALTER TABLE time_entries MODIFY source ENUM('manual_entry','custom_feed') CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'custom_feed' COMMENT 'original source'");
$pdo->exec("ALTER TABLE time_entries ADD source_system ENUM('manual','legacy') CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL COMMENT 'source origin'");
$before = $columns();
$migrate($repair);
$after = $columns();
foreach ($after as $i => $column) {
    $check($column['COLUMN_TYPE'] === substr($before[$i]['COLUMN_TYPE'], 0, -1) . ",'quanta')", $column['COLUMN_NAME'] . ' retains custom enum members');
    unset($column['COLUMN_TYPE'], $before[$i]['COLUMN_TYPE']);
    $check($column === $before[$i], $column['COLUMN_NAME'] . ' retains default, nullability, collation and comment');
}
$insert('custom_feed');
$insert('quanta');
$pdo->exec("UPDATE time_entries SET source_system='quanta' WHERE source='quanta'");
$check((int)$pdo->query("SELECT COUNT(*) FROM time_entries WHERE source='quanta' AND source_system='quanta'")->fetchColumn() === 1, 'both source enums accept Quanta after repair');
$after = $columns();
$migrate($repair);
$check($columns() === $after, 'custom enum repair is idempotent');
$pdo->exec('ALTER TABLE time_entries MODIFY source VARCHAR(32) NOT NULL DEFAULT "manual_entry", MODIFY source_system VARCHAR(32) NULL DEFAULT NULL');
$after = $columns();
$migrate($repair);
$check($columns() === $after, 'varchar source columns are not narrowed to enums');

// Actual placement audit calls must work against the legacy schema deployed in staging.
$pdo->exec('CREATE TABLE audit_log(id BIGINT AUTO_INCREMENT PRIMARY KEY,tenant_id BIGINT,actor_user_id BIGINT,event VARCHAR(100),target_id BIGINT,meta_json LONGTEXT,ip_address VARCHAR(45),created_at DATETIME)');
$_SESSION = ['tenant_id' => 17, 'user' => ['id' => 73, 'email' => 'synthetic@example.com']];
setRequestTenantId(999);
$_SERVER['HTTP_X_REQUEST_ID'] = 'synthetic-audit-request';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
placementsAudit('placements.rate.approved', ['rate_id' => 1], 13);
$row = $pdo->query('SELECT * FROM audit_log')->fetch(PDO::FETCH_ASSOC);
$check(is_array($row) && (int)$row['tenant_id'] === 999 && (int)$row['actor_user_id'] === 73 && (int)$row['target_id'] === 13, 'legacy audit records request-scoped tenant, actor and placement');
$meta = json_decode($row['meta_json'], true, 512, JSON_THROW_ON_ERROR);
$check($row['event'] === 'placements.rate.approved' && $meta['rate_id'] === 1 && $meta['request_id'] === 'synthetic-audit-request' && $meta['source'] === 'placements', 'legacy audit retains event, business metadata and request correlation');
$pdo->exec('ALTER TABLE audit_log ADD request_id VARCHAR(80), ADD source VARCHAR(80), ADD actor_email VARCHAR(255)');
// A fresh connection represents the next request and avoids the intentional column cache.
$legacyPdo = $pdo;
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $db . ';charset=utf8mb4', 'root', $password, $options);
placementsAudit('placements.status.changed', ['status' => 'active'], 13);
$row = $pdo->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$check($row['request_id'] === 'synthetic-audit-request' && $row['source'] === 'placements' && $row['actor_email'] === 'synthetic@example.com', 'expanded audit schema also receives canonical correlation columns');
echo "PASS {$passed} Quanta schema and placement audit checks\n";

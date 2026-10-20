<?php
/** Disposable, isolated CI schema. Never uses application connection configuration. */
declare(strict_types=1);
$inCi = getenv('GITHUB_ACTIONS') === 'true' && getenv('CI') === 'true';
$local = getenv('COREFLUX_RECORD_NUMBERS_LOCAL_FIXTURE') === '1'
    && in_array('--confirm-disposable-fixture', $argv, true)
    && (string) getenv('COREFLUX_RECORD_NUMBERS_TEST_PASSWORD') !== '';
if (!$inCi && !$local) throw new RuntimeException('Explicit disposable fixture configuration required');
require_once __DIR__ . '/../core/record_numbers.php';
require_once __DIR__ . '/../core/integrations/staffing_field_contract.php';
require_once __DIR__ . '/../core/migrate.php';
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', $inCi ? 'root' : getenv('COREFLUX_RECORD_NUMBERS_TEST_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
// No DROP: an existing fixture is an error, never a request to replace a database.
$fixture = $inCi ? 'coreflux_record_numbers_ci' : 'coreflux_record_numbers_local_' . gmdate('YmdHis') . '_' . getmypid();
$pdo->exec('CREATE DATABASE ' . $fixture);
$pdo->exec('USE ' . $fixture);
$pass = 0;
$check = static function (bool $ok, string $label) use (&$pass): void {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS {$label}\n"; $pass++;
};
$fails = static function (callable $call): bool {
    try { $call(); return false; } catch (Throwable $e) { return true; }
};
$apply = static function (string $file) use ($pdo): void {
    foreach (coreflux_split_sql_statements(file_get_contents(__DIR__ . '/../' . $file)) as $sql) $pdo->exec($sql);
};
foreach (coreRecordNumberDefinitions() as $definition) {
    $table = $definition['table'];
    $pdo->exec("CREATE TABLE {$table} (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED NOT NULL, name VARCHAR(50)) ENGINE=InnoDB");
    $pdo->exec("INSERT INTO {$table} (id,tenant_id,name) VALUES (333,1,'before migration'),(444,2,'other tenant')");
}
$apply('core/migrations/156_record_numbers.sql');
$moduleMigrations = ['modules/people/migrations/008_record_numbers.sql', 'modules/placements/migrations/007_record_numbers.sql', 'modules/staffing/migrations/007_record_numbers.sql', 'modules/ap/migrations/026_record_numbers.sql'];
foreach ($moduleMigrations as $migration) $apply($migration);
foreach (coreRecordNumberDefinitions() as $type => $definition) {
    $field = $definition['field']; $table = $definition['table'];
    $before = coreRecordNumbersAttach($pdo, 1, $type, [['id' => 333]])[0];
    $check($before[$field] === $definition['prefix'] . '333', $type . ' backfilled without renumbering');
    $check($fails(fn () => coreRecordNumbersAttach($pdo, 2, $type, [['id' => 333]])), $type . ' cross-tenant lookup blocked');
    $check($fails(fn () => coreRecordNumbersAttach($pdo, 1, $type, [['id' => 444, 'tenant_id' => 2]])), $type . ' mismatched owner rejected');
    $pdo->exec("INSERT INTO {$table} (tenant_id,name) VALUES (1,'new')");
    $id = (int) $pdo->lastInsertId();
    $new = coreRecordNumbersAttach($pdo, 1, $type, [['id' => $id]])[0];
    $check($new[$field] === $definition['prefix'] . $id, $type . ' direct insert triggers number');
    $pdo->exec("UPDATE {$table} SET name = 'renamed' WHERE id = {$id}");
    $check(coreRecordNumbersAttach($pdo, 1, $type, [['id' => $id]])[0][$field] === $new[$field], $type . ' rename preserves reference');
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO {$table} (tenant_id,name) VALUES (1,'rollback')");
    $rolled = (int) $pdo->lastInsertId();
    $pdo->rollBack();
    $check($fails(fn () => coreRecordNumbersAttach($pdo, 1, $type, [['id' => $rolled]])), $type . ' failed transaction leaves no registry row');
}
$check(coreRecordNumbersAttach($pdo, 1, 'person', []) === [], 'empty batch');
$related = coreRecordNumbersAttachRelated($pdo, 1, 'person', [['id' => 1, 'person_id' => 444]], 'person_id');
$check($related[0]['person_number'] === null, 'unresolved related record never borrows another tenant number');
$pdo->beginTransaction();
coreRecordNumbersMerge($pdo, 1, 'person', 445, 333, 7);
$pdo->rollBack();
$check((int) $pdo->query("SELECT canonical_record_id FROM core_record_numbers WHERE tenant_id=1 AND entity_type='person' AND record_id=333")->fetchColumn() === 333, 'merge rollback preserves original identity');
$pdo->beginTransaction();
coreRecordNumbersMerge($pdo, 1, 'person', 445, 333, 7);
$pdo->commit();
foreach ($moduleMigrations as $migration) $apply($migration);
$search = $pdo->prepare('SELECT p.id FROM people p WHERE p.tenant_id = :tenant AND ' . coreRecordNumberSearchSql('person', 'p', 'q'));
$search->execute(['tenant' => 1, 'q' => 'P-333']);
$check($search->fetchColumn() == 445, 'retired reference resolves to survivor after migration replay');
$search->execute(['tenant' => 2, 'q' => 'P-333']);
$check($search->fetchColumn() === false, 'alias search cannot cross tenant');
$check(coreRecordNumbersAttach($pdo, 1, 'person', [['id' => 333]])[0]['person_number'] === 'P-333', 'retired number remains reserved');
$pdo->exec('DELETE FROM people WHERE id=333');
$check($fails(fn () => $pdo->exec("INSERT INTO people(id,tenant_id,name) VALUES (333,1,'recycled')")), 'deleted ID cannot recycle its number');
$check($pdo->query('SELECT id FROM people WHERE id=333')->fetchColumn() === false, 'failed reservation rolls back source insert');
$pdo->beginTransaction();
$check($fails(fn () => coreRecordNumbersMerge($pdo, 1, 'person', 445, 444, 7)), 'foreign-tenant merge refused');
$pdo->rollBack();
$batch = [];
for ($i = 0; $i < 510; $i++) {
    $pdo->exec("INSERT INTO people(tenant_id,name) VALUES (1,'batch')");
    $batch[] = ['id' => $pdo->lastInsertId()];
}
$check(count(coreRecordNumbersAttach($pdo, 1, 'person', $batch)) === 510, 'number reads span bounded batches');
$apply('core/migrations/008_export_templates.sql');
$apply('core/migrations/120_people_placements_export_template_presets.sql');
$apply('core/migrations/121_staffing_clients_export_template_preset.sql');
$pdo->exec("INSERT INTO export_templates(scope,tenant_id,dataset,name,column_mappings_json) VALUES('tenant',1,'people_directory','Custom','[]')");
$apply('core/migrations/157_record_number_export_presets.sql');
$first = $pdo->query('SELECT id,column_mappings_json FROM export_templates ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
$apply('core/migrations/157_record_number_export_presets.sql');
$check($first === $pdo->query('SELECT id,column_mappings_json FROM export_templates ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR), 'export preset replay is idempotent');
$check($pdo->query("SELECT column_mappings_json FROM export_templates WHERE scope='tenant'")->fetchColumn() === '[]', 'tenant export template is preserved');
$inventory = staffingFieldContractInventory($pdo, 1);
$check($inventory['activates_sync'] === false && count($inventory['fields']) >= 20, 'actual schema inventoried without enabling sync');
$check(in_array('time_entries', $inventory['unverified_tables'], true), 'missing tables explicitly reported');
$pdo->exec("CREATE TABLE people_custom_field_defs (id INT PRIMARY KEY, tenant_id INT, field_key VARCHAR(50), field_label VARCHAR(50), field_type VARCHAR(30), required INT, pii INT, visible_to_roles_json TEXT, deleted_at DATETIME)");
$pdo->exec("INSERT INTO people_custom_field_defs VALUES (1,1,'badge_code','Badge','text',0,0,NULL,NULL),(2,2,'private_code','Other tenant','text',0,0,NULL,NULL),(3,1,'retired','Archived','text',0,0,NULL,NOW())");
$custom = staffingFieldContractCustomDefinitions($pdo, 1, 'people');
$check(count($custom) === 1 && $custom[0]['field_key'] === 'badge_code', 'custom inventory respects owner and archived status');
require_once __DIR__ . '/../core/custom_fields.php';
$check(!customFieldUserCanViewDefinition(['role' => 'employee'], ['visible_to_roles_json' => '["tenant_admin"]']), 'custom metadata obeys field visibility');
$check(customFieldUserCanViewDefinition(['role' => 'tenant_admin'], ['visible_to_roles_json' => '["tenant_admin"]']), 'authorized custom metadata visible');
echo "Record numbers MariaDB: {$pass} passed\n";

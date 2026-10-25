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
$fails(fn () => $pdo->exec("INSERT IGNORE INTO people(id,tenant_id,name) VALUES (333,1,'recycled through ignore')"));
$check($pdo->query('SELECT id FROM people WHERE id=333')->fetchColumn() === false, 'INSERT IGNORE cannot recycle a retired reference');
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
$workflowFields=array_values(array_filter($inventory['fields'],static fn($field)=>$field['kind']==='workflow_payload_field'));
$check(count($workflowFields)>100 && count(array_filter($workflowFields,static fn($field)=>$field['core_schema_status']!=='container_unverified'))===0,
    'missing workflow storage columns never appear as verified payload schemas');
$apply('core/migrations/171_staffing_onboarding.sql');
$pdo->exec("INSERT INTO staffing_onboarding_cases(id,tenant_id,people_tenant_id,person_id,placements_tenant_id,placement_id,tasks_json,created_by_user_id) VALUES('metadata-only',1,1,1,1,1,'PRIVATE_RECORD_SENTINEL',1)");
$workflowInventory=staffingFieldContractInventory($pdo,1);
$workflowByKey=array_column($workflowInventory['fields'],null,'key');
$check($workflowByKey['quanta_onboarding_submission.id']['core_schema_status']==='verified_container_present'
    && $workflowByKey['purepayroll_result.run_id']['core_schema_status']==='container_unverified',
    'workflow export distinguishes installed evidence storage from missing payroll storage');
$check($workflowByKey['staffing_onboarding_cases.tasks_json']['disposition']==='restricted'
    && $workflowByKey['staffing_onboarding_cases.plan_version']['write_policy']==='specialized_workflow_only'
    && !str_contains(json_encode($workflowInventory),'PRIVATE_RECORD_SENTINEL'),
    'dictionary reads schema only and keeps raw workflow evidence and revisions out of generic mappings');
$references = array_column(array_filter($inventory['fields'], static fn ($field) => $field['kind'] === 'business_reference'), null, 'entity_type');
$check(count($references) === 5 && $references['person']['owner'] === 'core'
    && $references['person']['write_policy'] === 'assigned_by_core; imports_may_lookup_but_never_assign', 'exchange metadata retains canonical number ownership');
$companyMapping = $references['company']['remote_contracts'];
$clientMapping = $references['client']['remote_contracts'];
$check($companyMapping['quanta']['path'] === 'end_client' && $clientMapping['quanta']['path'] === 'client'
    && $companyMapping['purepay']['status'] === 'unverified', 'export distinguishes end-client company from billing client and unsupported provider company');
$personMappings = $references['person']['remote_contracts'];
$check(array_column($personMappings['quanta']['mappings'], 'route') === [
        '/api/v1/integrations/coreflux/assignments', '/api/v1/integrations/coreflux/person-links',
        '/api/v1/integrations/coreflux/worker-profiles',
    ] && array_column($personMappings['quanta']['mappings'], 'path') === ['person', 'person', 'person']
    && array_column($personMappings['purepayroll']['mappings'], 'path') === ['person', 'components[].person', 'reference', 'person']
    && array_column($personMappings['purepayroll']['mappings'], 'route') === [
        '/api/v1/integrations/coreflux/people/inputs', '/api/v1/integrations/coreflux/payroll/inputs',
        '/api/v1/integrations/coreflux/links', '/api/v1/integrations/coreflux/assignments/inputs',
    ]
    && $personMappings['quanta']['path'] === null && $personMappings['purepayroll']['path'] === null,
    'dictionary export retains profile, time, link and assignment identities without inventing one flattened field');
$pdo->exec("CREATE TABLE people_custom_field_defs (id INT PRIMARY KEY, tenant_id INT, field_key VARCHAR(50), field_label VARCHAR(50), field_type VARCHAR(30), required INT, pii INT, visible_to_roles_json TEXT, deleted_at DATETIME)");
$pdo->exec("INSERT INTO people_custom_field_defs VALUES (1,1,'badge_code','Badge','text',0,0,NULL,NULL),(2,2,'private_code','Other tenant','text',0,0,NULL,NULL),(3,1,'retired','Archived','text',0,0,NULL,NOW())");
$custom = staffingFieldContractCustomDefinitions($pdo, 1, 'people');
$check(count($custom) === 1 && $custom[0]['field_key'] === 'badge_code', 'custom inventory respects owner and archived status');
$personCustom = staffingFieldContractCustomField($custom[0], 1, 'people');
$check($personCustom['core_path'] === 'people.custom.badge_code'
    && $personCustom['field_key_source'] === 'field_key', 'dedicated person field key remains the verified lookup path');
$pdo->exec("CREATE TABLE custom_fields (id INT PRIMARY KEY, tenant_id INT, module VARCHAR(30), label VARCHAR(80), type VARCHAR(30), is_active INT)");
$pdo->exec("INSERT INTO custom_fields VALUES (1,1,'placements','Site badge','text',1),(2,1,'placements','Site badge','text',1),(3,2,'placements','Other tenant','text',1),(4,1,'companies','Company badge','text',1),(5,1,'placements','Retired badge','text',0)");
$legacy = staffingFieldContractCustomDefinitions($pdo, 1, 'placements');
$check(count($legacy) === 2 && $legacy[0]['field_key'] === null && $legacy[0]['field_key_source'] === null,
    'legacy labels are not exported as keys; tenant, entity and active filters still apply');
$legacyFields = array_map(static fn($row) => staffingFieldContractCustomField($row, 1, 'placements'), $legacy);
$check($legacyFields[0]['key'] !== $legacyFields[1]['key'] && $legacyFields[0]['core_path'] === null
    && $legacyFields[0]['field_key_status'] === 'stable_key_review_required',
    'duplicate display labels have distinct definition identities and no invented lookup path');
$pdo->exec("UPDATE custom_fields SET label='Renamed badge' WHERE id=1");
$renamed = staffingFieldContractCustomField(staffingFieldContractCustomDefinitions($pdo, 1, 'placements')[0], 1, 'placements');
$check($renamed['key'] === $legacyFields[0]['key']
    && $renamed['definition_identity'] === $legacyFields[0]['definition_identity']
    && $renamed['label'] === 'Renamed badge', 'legacy label rename preserves the exported definition identity');
$pdo->exec("ALTER TABLE custom_fields ADD field_name VARCHAR(80) NULL, ADD field_label VARCHAR(80) NULL");
$pdo->exec("UPDATE custom_fields SET field_name='site_badge',field_label='Display badge' WHERE id=1");
$modern = staffingFieldContractCustomField(staffingFieldContractCustomDefinitions($pdo, 1, 'placements')[0], 1, 'placements');
$check($modern['core_path'] === 'placements.custom.site_badge' && $modern['field_key_source'] === 'field_name'
    && $modern['definition_identity'] === $legacyFields[0]['definition_identity'],
    'dedicated legacy key restores a lookup path without changing definition identity');
$pdo->exec("UPDATE custom_fields SET field_label='Localized display' WHERE id=1");
$modernRenamed = staffingFieldContractCustomField(staffingFieldContractCustomDefinitions($pdo, 1, 'placements')[0], 1, 'placements');
$check($modernRenamed['core_path'] === $modern['core_path'] && $modernRenamed['key'] === $modern['key'],
    'modern display label cannot rename the mapping key');
require_once __DIR__ . '/../core/custom_fields.php';
$check(!customFieldUserCanViewDefinition(['role' => 'employee'], ['visible_to_roles_json' => '["tenant_admin"]']), 'custom metadata obeys field visibility');
$check(customFieldUserCanViewDefinition(['role' => 'tenant_admin'], ['visible_to_roles_json' => '["tenant_admin"]']), 'authorized custom metadata visible');
echo "Record numbers MariaDB: {$pass} passed\n";

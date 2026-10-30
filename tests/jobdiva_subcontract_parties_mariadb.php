<?php
/** Real graph DDL; synthetic assignments in an explicitly disposable database. */
declare(strict_types=1);
$inCi = getenv('GITHUB_ACTIONS') === 'true' && getenv('CI') === 'true';
$local = getenv('COREFLUX_DELIVERY_LOCAL_FIXTURE') === '1'
    && in_array('--confirm-disposable-fixture', $argv, true)
    && (string) getenv('COREFLUX_DELIVERY_TEST_PASSWORD') !== '';
if (!$inCi && !$local) throw new RuntimeException('Explicit disposable fixture configuration required');
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__ . '/../core/jobdiva/sync.php';
require_once __DIR__ . '/../core/migrate.php';
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root',
    $inCi ? 'root' : getenv('COREFLUX_DELIVERY_TEST_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$db = 'coreflux_jobdiva_subcontract_' . ($inCi ? 'ci' : gmdate('YmdHis') . '_' . getmypid());
$pdo->exec('CREATE DATABASE ' . $db);
$pdo->exec('USE ' . $db);
$pdo->exec('CREATE TABLE placements(id BIGINT PRIMARY KEY,tenant_id BIGINT,engagement_type VARCHAR(30),deleted_at DATETIME)');
$pdo->exec('CREATE TABLE placement_corp_details(tenant_id BIGINT,placement_id BIGINT,company_id BIGINT)');
$pdo->exec("INSERT INTO placements VALUES(1,10,'w2',NULL),(2,10,'c2c',NULL),(3,20,'c2c',NULL)");
foreach (coreflux_split_sql_statements(file_get_contents(__DIR__ . '/../core/migrations/126_staffing_economic_graph.sql')) as $sql) {
    if (preg_match('/CREATE TABLE IF NOT EXISTS placement_economic_(parties|obligations)\s*\(/', $sql)) $pdo->exec($sql);
}
$pdo->exec('CREATE TABLE placement_rates(id BIGINT PRIMARY KEY,approved_at DATETIME,economics_snapshot_json JSON)');
$pdo->exec('INSERT INTO placement_rates VALUES(1,"2026-10-01 12:00:00",\'{"parties":[{"source_ref":"jobdiva:unresolved:subcontract_company:0"}]}\')');
$passed = 0;
$check = static function (bool $ok, string $label) use (&$passed): void {
    if (!$ok) throw new RuntimeException($label);
    $passed++; echo "PASS $label\n";
};
$payload = static fn(string $type, mixed $id): array => ['_jd_contract' => ['engagement_type' => $type, 'corporation_id' => $id]];
$run = static fn(array $jd, int $placement = 1, int $tenant = 10): array =>
    jobdivaSyncUpsertSourceAllocationParties($tenant, $placement, '2026-10-01', null, $jd);
$seed = static fn(string $id, int $placement = 1, int $tenant = 10, array $extra = []): int =>
    placementEconomicsUpsertParty($tenant, $placement, array_replace([
        'source_ref' => 'jobdiva:unresolved:subcontract_company:' . $id,
        'source_type' => 'integration', 'source_system' => 'jobdiva', 'source_external_id' => $id,
        'source_managed' => 1, 'role' => 'unresolved_c2c_vendor', 'display_name' => 'Synthetic unresolved vendor ' . $id,
        'money_flow' => 'informational', 'settlement_channel' => 'none',
    ], $extra));
$party = static function (int $id) use ($pdo): array {
    return $pdo->query('SELECT * FROM placement_economic_parties WHERE id=' . $id)->fetch(PDO::FETCH_ASSOC);
};
$rows = static fn(): array => $pdo->query('SELECT * FROM placement_economic_parties ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
foreach ([0, '0', ' 0 ', '0.00', '000', null, 'null', false] as $value) {
    $check(jobdivaAssignmentCompanyId($value) === '', 'absent subcontract identity is not a company');
}
$check(jobdivaAssignmentCompanyId(' 001234 ') === '001234' && jobdivaAssignmentCompanyId('REF-301') === 'REF-301', 'real provider identities remain opaque');
$contract = jobdivaAssignmentContractBuild([['Start ID' => '101', 'SALARY' => [[
    'EMPLOYMENT_CATEGORY' => 'Hourly Employee', 'SUBCONTRACT_COMPANYID' => 0,
]]]], [], '101');
$check($contract['corporation_id'] === '' && $contract['engagement_type'] === 'w2', 'assignment adapter removes the zero sentinel without changing W2 classification');
$preview = jobdivaContractProjectionBuild($payload('c2c', '0'));
$payeeCheck = array_column($preview['checks'], null, 'code')['c2c_payee'];
$check(!$preview['complete'] && $payeeCheck['status'] === 'blocked', 'legacy C2C zero sentinel cannot pass vendor-identity readiness');
$missingContract = jobdivaAssignmentContractBuild([['Start ID' => '102', 'SALARY' => [['EMPLOYMENT_CATEGORY' => 'Subcontract']]]], [], '102');
$check(!array_key_exists('corporation_id', $missingContract), 'missing source field stays distinct from explicit no-company evidence');
$check(jobdivaSyncResolveSubcontractCompany(10, '0') === null, 'zero cannot trigger a company lookup or vendor creation');
$check(jobdivaAssignmentSubcontractCompanyId($payload('c2c', '42')) === '42'
    && jobdivaAssignmentSubcontractCompanyId($payload('w2', '42')) === ''
    && jobdivaAssignmentSubcontractCompanyId($payload('1099', '42')) === ''
    && jobdivaAssignmentSubcontractCompanyId($payload('', '42')) === '', 'prefetch only resolves payees with explicit C2C evidence');
$zero = $seed('0'); $otherPlacement = $seed('0', 2); $otherTenant = $seed('0', 3, 20);
$pdo->exec('INSERT INTO placement_economic_obligations(tenant_id,placement_id,economic_party_id,source_type,source_ref_id,amount,currency,status) VALUES(10,1,' . $zero . ',"manual",101,25,"USD","paid")');
$history = [$pdo->query('SELECT * FROM placement_rates')->fetchAll(), $pdo->query('SELECT * FROM placement_economic_obligations')->fetchAll()];
$run($payload('w2', '0'));
$check((int) $party($zero)['active'] === 0, 'W2 replay retires the old zero-sentinel review row');
$check((int) $party($otherPlacement)['active'] === 1 && (int) $party($otherTenant)['active'] === 1, 'repair is isolated to its workspace and placement');
$before = $rows(); $run($payload('w2', '12345'));
$check($rows() === $before, 'a stale nonzero vendor on W2 creates no subcontract party');
$pdo->exec('UPDATE placements SET engagement_type="c2c" WHERE id=1');
$run($payload('c2c', '42'));
$c2c = (int) $pdo->query('SELECT id FROM placement_economic_parties WHERE tenant_id=10 AND placement_id=1 AND source_external_id="42"')->fetchColumn();
$check($c2c > 0 && (int) $party($c2c)['active'] === 1, 'real unresolved C2C vendor remains visible for review');
$before = $rows(); $run($payload('c2c', '42'));
$check($rows() === $before, 'identical C2C replay keeps the same party identity');
$run($payload('c2c', '43'));
$check((int) $party($c2c)['active'] === 0, 'changed vendor retires the old review row without deleting it');
$manual = $seed('43');
$pdo->exec('UPDATE placement_economic_parties SET source_managed=0,display_name="Manual review" WHERE id=' . $manual);
$before = $party($manual); $run($payload('c2c', '43'));
$check($party($manual) === $before, 'same-key manual ownership cannot be overwritten by source replay');
$unowned = $seed('44');
$pdo->exec('UPDATE placement_economic_parties SET source_system=NULL WHERE id=' . $unowned);
$before = $party($unowned); $run($payload('c2c', '44'));
$check($party($unowned) === $before, 'unknown provenance is not treated as a JobDiva-owned row');
$terms = $seed('45');
$pdo->exec('UPDATE placement_economic_parties SET payment_terms_overridden=1,payment_terms="NET45" WHERE id=' . $terms);
$settlement = $seed('46', 1, 10, ['money_flow' => 'payable', 'settlement_channel' => 'ap']);
$before = [$party($manual), $party($unowned), $party($terms), $party($settlement)];
$run($payload('c2c', '0'));
$check([$party($manual), $party($unowned), $party($terms), $party($settlement)] === $before, 'zero retirement preserves manual terms, ownership and actual settlement parties');
$review = $seed('47');
$before = $rows(); $run($payload('', '0')); $run($payload('W2 or C2C', '0'));
$check($rows() === $before, 'missing or conflicting classification cannot retire review evidence');
$before = $rows(); $run($payload('w2', '0'));
$check($rows() === $before, 'a source classification disagreeing with Core cannot change review evidence');
$partial = $payload('c2c', ''); unset($partial['_jd_contract']['corporation_id']);
$before = $rows(); $run($partial);
$check($rows() === $before, 'a partial C2C payload without a subcontract field cannot retire existing evidence');
$run($payload('c2c', ''));
$check((int) $party($review)['active'] === 0, 'explicitly removed subcontract ID retires only its old source review row');
$run($payload('c2c', '48'));
$resolved = (int) $pdo->query('SELECT id FROM placement_economic_parties WHERE tenant_id=10 AND placement_id=1 AND source_external_id="48"')->fetchColumn();
$pdo->exec('INSERT INTO placement_corp_details VALUES(10,1,100)');
$run($payload('c2c', '48'));
$check((int) $party($resolved)['active'] === 0, 'resolved C2C payee retires its unresolved review row');
$check([$pdo->query('SELECT * FROM placement_rates')->fetchAll(), $pdo->query('SELECT * FROM placement_economic_obligations')->fetchAll()] === $history,
    'approved snapshots and paid obligations retain original content and party references');
$pdo->exec('USE information_schema'); $pdo->exec('DROP DATABASE ' . $db);
echo "$passed passed\n";

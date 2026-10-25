<?php
/** Native company service/write paths against the fully migrated disposable CI database. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'coreaccounting'
    || getenv('COREFLUX_STANDALONE_DATABASE') !== 'coreaccounting_ci'
    || !in_array('--confirm-disposable-fixture', $argv, true)) exit(2);
require_once __DIR__ . '/../modules/people/lib/companies.php';
require_once __DIR__ . '/../modules/placements/lib/economics.php';
$pdo = getDB();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'coreaccounting_ci') throw new RuntimeException('Disposable database required');
$parent = (int) $pdo->query('SELECT MIN(id) FROM tenants WHERE id <> 999')->fetchColumn();
if ($parent <= 0 || (int) $pdo->query('SELECT COUNT(*) FROM tenants WHERE id=999 AND is_simulation=1')->fetchColumn() !== 1)
    throw new RuntimeException('Expected disposable tenants are missing');
$checks = 0;
$check = static function(bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    $checks++; echo "PASS $label\n";
};
$row = static function(string $table, int $id) use ($pdo): array {
    $s = $pdo->prepare('SELECT * FROM '.$table.' WHERE id=?'); $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC);
};
$scope = static function(string $companies, string $people) use ($pdo): void {
    $s = $pdo->prepare('INSERT INTO tenant_module_scope(tenant_id,module_key,scope_mode) VALUES(999,?,?)
        ON DUPLICATE KEY UPDATE scope_mode=VALUES(scope_mode)');
    $s->execute(['companies', $companies]); $s->execute(['people', $people]);
};
$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE tenants SET tenant_type="sub",parent_id=? WHERE id=999')->execute([$parent]);
    $parentCompany = companiesUpsertByName($parent, 'CI directory owner shared', [], ['client']);
    $childCompany = companiesUpsertByName(999, 'CI directory owner isolated', [], ['vendor']);
    $childBefore = $row('companies', $childCompany);
    setRequestTenantId(999);
    $scope('shared', 'isolated');
    setRequestModuleScope('people'); // Both direct People URLs and the central router begin here.
    $owner = companiesUseDirectoryScope();
    $check($owner === $parent && currentTenantId() === 999 && currentModuleKey() === 'companies',
        'company owner is shared while authentication remains in the active child workspace');
    $check(effectiveTenantIdForModule('people') === 999, 'company scope does not change the People policy');
    $listed = companiesList(['q' => 'CI directory owner', 'per_page' => 200]);
    $check(array_map('intval', array_column($listed['rows'], 'id')) === [$parentCompany] && $listed['total'] === 1,
        'list and count agree on the company owner even when People is isolated');
    $shared = companiesGet($parentCompany);
    $check($shared !== null && (int) $shared['tenant_id'] === $parent && companiesGet($childCompany) === null,
        'detail accepts the configured company directory and rejects another directory');
    $numberRows = $pdo->query('SELECT * FROM core_record_numbers ORDER BY tenant_id,entity_type,record_id')->fetchAll(PDO::FETCH_ASSOC);
    companiesList(['q' => 'CI directory owner']); companiesGet($parentCompany);
    $check($pdo->query('SELECT * FROM core_record_numbers ORDER BY tenant_id,entity_type,record_id')->fetchAll(PDO::FETCH_ASSOC) === $numberRows,
        'repeated directory reads preserve existing canonical identities');
    $created = scopedInsert('companies', ['tenant_id' => $owner, 'name' => 'CI directory owner created']);
    companiesAddRole($created, 'client');
    $check((int) companiesGet($created)['tenant_id'] === $parent, 'native create writes where its response can read the new company');
    $upsert = companiesUpsertByName($owner, 'CI directory owner created', ['city' => 'Test City'], ['vendor']);
    $check($upsert === $created && companiesGet($created)['city'] === 'Test City', 'upsert reuses that exact shared company');
    $check(scopedUpdate('companies', $created, ['industry' => 'Integration QA']) === 1
        && scopedUpdate('companies', $childCompany, ['industry' => 'Wrong owner']) === 0,
        'header and bulk update primitives cannot write outside the company directory');
    $contact = scopedInsert('company_contacts', ['tenant_id' => $owner, 'company_id' => $created, 'name' => 'Synthetic contact']);
    $address = scopedInsert('company_addresses', ['tenant_id' => $owner, 'company_id' => $created, 'kind' => 'worksite', 'line1' => 'Synthetic site', 'city' => 'Test City']);
    $check((int) $row('company_contacts', $contact)['tenant_id'] === $parent
        && (int) $row('company_addresses', $address)['tenant_id'] === $parent,
        'contacts and addresses are stored in their parent company directory');
    $check(scopedUpdate('company_addresses', $address, ['city' => 'Updated City']) === 1
        && companyAddresses($created)[0]['city'] === 'Updated City', 'address edits target that same directory');
    $check($row('companies', $childCompany) === $childBefore, 'isolated company remains byte-for-byte unchanged');

    $scope('isolated', 'shared');
    setRequestModuleScope('people');
    $owner = companiesUseDirectoryScope();
    $check($owner === 999 && effectiveTenantIdForModule('people') === $parent && currentTenantId() === 999,
        'inverse sharing policy resolves Companies independently of People');
    $check(companiesGet($parentCompany) === null && companiesGet($childCompany) !== null,
        'shared People does not expose parent company details when Companies is isolated');
    $listed = companiesList(['q' => 'CI directory owner', 'role' => 'vendor']);
    $check(array_map('intval', array_column($listed['rows'], 'id')) === [$childCompany] && $listed['total'] === 1,
        'role-filtered typeahead and count use the isolated company owner');
    $sharedBefore = $row('companies', $created);
    $check(scopedUpdate('companies', $created, ['industry' => 'Wrong owner']) === 0
        && scopedDelete('company_addresses', $address) === 0, 'inverse policy blocks parent company edits and address deletion');
    $check($row('companies', $created) === $sharedBefore && count(companyAddresses($created)) === 1,
        'rejected writes preserve parent company and address rows');
    $isolatedCreated = scopedInsert('companies', ['tenant_id' => $owner, 'name' => 'CI directory owner child created']);
    $check((int) companiesGet($isolatedCreated)['tenant_id'] === 999, 'isolated create stays in the selected company directory');
    $s = $pdo->prepare('UPDATE companies SET deleted_at=NOW() WHERE id=? AND tenant_id=?');
    $s->execute([$isolatedCreated, $owner]);
    $check(companiesGet($isolatedCreated) === null && $row('companies', $created) === $sharedBefore,
        'soft deletion affects only the isolated company');

    // A company lookup must not move subsequent placement writes into its catalog.
    foreach ([['shared', 'isolated'], ['isolated', 'shared'], ['shared', 'shared']] as [$companyMode, $placementMode]) {
        $scope($companyMode, 'isolated');
        $pdo->prepare('INSERT INTO tenant_module_scope(tenant_id,module_key,scope_mode) VALUES(999,"placements",?)
            ON DUPLICATE KEY UPDATE scope_mode=VALUES(scope_mode)')->execute([$placementMode]);
        setRequestModuleScope('placements');
        $companyOwner = $companyMode === 'shared' ? $parent : 999;
        $placementOwner = $placementMode === 'shared' ? $parent : 999;
        $selected = $companyMode === 'shared' ? $parentCompany : $childCompany;
        $foreign = $companyMode === 'shared' ? $childCompany : $parentCompany;
        $label = "$companyMode companies / $placementMode placements";
        $check(companiesDirectoryTenantId() === $companyOwner
            && companiesGet($selected, companiesDirectoryTenantId()) !== null
            && companiesGet($foreign, companiesDirectoryTenantId()) === null,
            "$label: selection matches the company directory and rejects another directory");
        $patch = placementsResolveCompanyRoles($placementOwner, ['end_client_company_id' => $selected], [], null, 999);
        $check($patch['end_client_company_id'] === $selected
            && $patch['end_client_name'] === $row('companies', $selected)['name'],
            "$label: overview and bulk edits resolve the same selected end client");
        $name = "CI placement company $companyMode $placementMode";
        $newCompany = companiesUpsertByName(companiesDirectoryTenantId(), $name, [], ['vendor', 'referrer']);
        $replay = companiesUpsertByName(companiesDirectoryTenantId(), $name, [], ['vendor', 'referrer']);
        $check($newCompany === $replay && (int) $row('companies', $newCompany)['tenant_id'] === $companyOwner,
            "$label: create-on-the-fly and replay retain one company in the selected directory");
        $vendor = placementEconomicsEnsureVendor($placementOwner, $name, $newCompany, 'w9_business', 'NET30', false, null, 999);
        $check((int) $row('ap_vendors_index', (int) $vendor['id'])['tenant_id'] === $placementOwner && (int) $vendor['company_id'] === $newCompany,
            "$label: manual payable resolves that company without moving the vendor");
        $check(currentTenantId() === 999 && currentModuleKey() === 'placements'
            && effectiveTenantIdForRequest() === $placementOwner,
            "$label: company operations preserve authentication and placement write scope");
        $person = scopedInsert('people', ['first_name' => 'Synthetic', 'last_name' => 'Directory',
            'email_primary' => "ci-directory-$companyMode-$placementMode@example.invalid"]);
        $placement = scopedInsert('placements', ['person_id' => $person, 'title' => 'CI directory placement',
            'start_date' => '2026-10-01', 'end_client_company_id' => $selected, 'end_client_name' => $patch['end_client_name']]);
        $check((int) $row('placements', $placement)['tenant_id'] === $placementOwner,
            "$label: subsequent native placement insert uses its own directory");
        $pdo->prepare('UPDATE companies SET deleted_at=NOW() WHERE id=? AND tenant_id=?')->execute([$newCompany, $companyOwner]);
        $check(companiesGet($newCompany, companiesDirectoryTenantId()) === null
            && currentModuleKey() === 'placements', "$label: deleted selection is rejected without scope changes");
    }
    $check($pdo->inTransaction(), 'native directory operations do not commit the fixture transaction');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    clearRequestModuleScope(); setRequestTenantId(null);
}
echo "Native company directory owner: $checks passed\n";

<?php
/** Mixed directory ownership through real native reads and graph reconciliation. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'coreaccounting'
    || getenv('COREFLUX_STANDALONE_DATABASE') !== 'coreaccounting_ci'
    || !in_array('--confirm-disposable-fixture', $argv, true)) exit(2);
require_once __DIR__ . '/../modules/placements/lib/economics.php';
$pdo = getDB();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'coreaccounting_ci') throw new RuntimeException('Disposable database required');
$parent = (int) $pdo->query('SELECT MIN(id) FROM tenants WHERE id <> 999')->fetchColumn();
if ($parent <= 0 || (int) $pdo->query('SELECT COUNT(*) FROM tenants WHERE id=999 AND is_simulation=1')->fetchColumn() !== 1)
    throw new RuntimeException('Disposable tenants missing');
$checks = 0;
$check = static function(bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    $checks++; echo "PASS $label\n";
};
$snapshot = static function() use ($pdo): array {
    $all = [];
    foreach (['companies','company_roles','ap_vendors_index','placements','placement_client_chain',
        'placement_referrals','placement_corp_details','staffing_operating_cycles','placement_economic_parties',
        'placement_economic_obligations','placement_rates','core_record_numbers','external_entity_mappings'] as $table) {
        $order = match ($table) {
            'placement_corp_details' => 'placement_id',
            'core_record_numbers' => 'tenant_id,entity_type,record_id',
            default => 'id',
        };
        $all[$table] = $pdo->query('SELECT * FROM '.$table.' ORDER BY '.$order)->fetchAll(PDO::FETCH_ASSOC);
    }
    return $all;
};
$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE tenants SET tenant_type="sub",parent_id=? WHERE id=999')->execute([$parent]);
    $scope = $pdo->prepare('INSERT INTO tenant_module_scope(tenant_id,module_key,scope_mode) VALUES(999,?,?)
        ON DUPLICATE KEY UPDATE scope_mode=VALUES(scope_mode)');
    $scope->execute(['companies','shared']); $scope->execute(['placements','isolated']);
    setRequestTenantId(999); setRequestModuleScope('placements');
    $bill = companiesUpsertByName($parent, 'CI graph billing company', ['payment_terms_days'=>45], ['client']);
    $end = companiesUpsertByName($parent, 'CI graph actual recipient', [], ['client']);
    $foreign = companiesUpsertByName(999, 'CI graph private company', [], ['vendor']);
    $client = scopedInsert('staffing_clients', ['name'=>'CI graph bill-to','company_id'=>$bill,'payment_terms_days'=>45]);
    $person = scopedInsert('people', ['first_name'=>'Synthetic','last_name'=>'Graph','email_primary'=>'ci-graph-owner@example.invalid']);
    $placement = scopedInsert('placements', ['person_id'=>$person,'title'=>'CI shared-company graph',
        'start_date'=>'2026-10-01','engagement_type'=>'c2c','client_id'=>$client,
        'end_client_company_id'=>$end,'end_client_name'=>'Old copied label']);
    $pdo->prepare('INSERT INTO placement_corp_details(tenant_id,placement_id,corp_legal_name) VALUES(999,?,?)')
        ->execute([$placement,'CI graph new corporation']);
    $chain = scopedInsert('placement_client_chain', ['placement_id'=>$placement,'position'=>1,
        'party_name'=>'CI graph new prime','party_role'=>'prime_vendor','portal_fee_flat'=>10,'is_payable'=>1]);
    $referral = scopedInsert('placement_referrals', ['placement_id'=>$placement,'referrer_type'=>'vendor',
        'referrer_vendor_name'=>'CI graph new referrer','fee_basis'=>'per_hour','fee_flat'=>1,'start_date'=>'2026-10-01']);
    $read = placementGet($placement);
    $check($read['end_client_company_name'] === 'CI graph actual recipient', 'placement detail resolves the shared canonical company');
    $list = placementsList(['q'=>'CI graph actual recipient']);
    $check($list['total'] === 1 && (int)$list['rows'][0]['id'] === $placement
        && $list['rows'][0]['end_client_display_name'] === 'CI graph actual recipient',
        'list search count and display use the shared company rather than a stale copied name');
    $result = placementEconomicsReconcile(999, $placement);
    $check($result['available'] && $result['errors'] === [], 'complete C2C economic graph reconciles with shared companies');
    $parties = array_column(placementEconomicsParties(999, $placement), null, 'source_ref');
    $check((int)$parties['placement:bill_to']['company_id'] === $bill
        && (int)$parties['placement:bill_to']['client_id'] === $client
        && $parties['placement:bill_to']['payment_terms'] === 'NET45', 'receivable retains the exact bill-to company and terms');
    $check((int)$parties['placement:work_recipient']['company_id'] === $end
        && $parties['placement:work_recipient']['settlement_channel'] === 'none', 'work recipient remains distinct from the debtor');
    foreach (['corp:'.$placement, 'chain:'.$chain, 'referral:'.$referral] as $ref) {
        $party = $parties[$ref];
        $company = companiesGet((int)$party['company_id'], $parent);
        $vendor = $pdo->prepare('SELECT tenant_id,company_id FROM ap_vendors_index WHERE id=?');
        $vendor->execute([(int)$party['ap_vendor_id']]); $v = $vendor->fetch(PDO::FETCH_ASSOC);
        $check($company !== null && (int)$v['tenant_id'] === 999 && (int)$v['company_id'] === (int)$company['id'],
            "$ref creates a shared company linked to the placement-owned vendor");
    }
    $refCompany = (int)$parties['referral:'.$referral]['company_id'];
    $pdo->prepare('UPDATE companies SET name=? WHERE id=?')->execute(['CI graph canonical referrer',$refCompany]);
    $referrals = placementReferrals($placement);
    $check($referrals[0]['referrer_name'] === 'CI graph canonical referrer'
        && (int)$referrals[0]['canonical_company_id'] === $refCompany, 'referral read follows canonical company identity after a rename');
    $ids = array_map(static fn(array $p): array => [(int)$p['id'],(int)$p['company_id'],(int)$p['ap_vendor_id']], $parties);
    $check(placementEconomicsReconcile(999, $placement)['available'], 'graph replay succeeds');
    $replay = array_column(placementEconomicsParties(999, $placement), null, 'source_ref');
    $check(array_map(static fn(array $p): array => [(int)$p['id'],(int)$p['company_id'],(int)$p['ap_vendor_id']], $replay) === $ids,
        'replay preserves all party company and vendor identities');
    $check($replay['referral:'.$referral]['display_name'] === 'CI graph canonical referrer', 'reconciliation refreshes the shared referral name');
    $badChain = scopedInsert('placement_client_chain', ['placement_id'=>$placement,'position'=>2,
        'party_name'=>'CI graph private company','party_role'=>'prime_vendor','company_id'=>$foreign,'is_payable'=>1]);
    $before = $snapshot();
    $failed = placementEconomicsReconcile(999, $placement);
    $check(!$failed['available'] && $failed['written'] === 0 && $failed['source_refs'] === [], 'late foreign company fails the graph rebuild');
    $check($snapshot() === $before && $pdo->inTransaction(), 'failure rolls back earlier company vendor cycle and graph writes without committing the caller');
    $pdo->prepare('DELETE FROM placement_client_chain WHERE id=?')->execute([$badChain]);
    $pdo->prepare('UPDATE companies SET deleted_at=NOW() WHERE id=?')->execute([$bill]);
    $before = $snapshot(); $failed = placementEconomicsReconcile(999, $placement);
    $check(!$failed['available'] && $snapshot() === $before, 'deleted debtor holds reconciliation without retiring the existing receivable');
    $pdo->prepare('UPDATE companies SET deleted_at=NULL WHERE id=?')->execute([$bill]);
    $check(currentTenantId() === 999 && currentModuleKey() === 'placements', 'graph reads and reconciliation preserve request context');

    // A different viewer cannot retarget the intrinsic owner of a shared graph.
    $parentPerson = scopedInsert('people', ['tenant_id'=>$parent,'first_name'=>'Synthetic','last_name'=>'Parent',
        'email_primary'=>'ci-parent-graph@example.invalid']);
    $parentClient = scopedInsert('staffing_clients', ['tenant_id'=>$parent,'name'=>'CI parent bill-to','company_id'=>$bill]);
    $parentPlacement = scopedInsert('placements', ['tenant_id'=>$parent,'person_id'=>$parentPerson,'title'=>'CI parent graph',
        'start_date'=>'2026-10-01','engagement_type'=>'w2','client_id'=>$parentClient,'end_client_company_id'=>$end]);
    setRequestTenantId($parent);
    $check(placementEconomicsReconcile($parent, $parentPlacement)['available'], 'parent graph is built in its own directory');
    $before = $pdo->query('SELECT * FROM placement_economic_parties ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $scope->execute(['placements','shared']); $scope->execute(['companies','isolated']); setRequestTenantId(999);
    $check(placementEconomicsReconcile(999, $parentPlacement)['available'], 'shared graph remains readable from a child with an isolated company catalog');
    $check($pdo->query('SELECT * FROM placement_economic_parties ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $before,
        'changing the viewing workspace does not retarget or remove shared graph participants');
    $check($pdo->inTransaction(), 'successful nested graph reconciliation never commits the fixture');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    clearRequestModuleScope(); setRequestTenantId(null);
}
echo "Placement company graph owner: $checks passed\n";

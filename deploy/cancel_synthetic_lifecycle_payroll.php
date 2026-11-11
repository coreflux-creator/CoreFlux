<?php
/** Cancel the original held demo draft through Core's native approval endpoint. */
declare(strict_types=1);
if (($argv[1] ?? '') !== '--cancel-original-synthetic-held-draft') exit(2);
require __DIR__ . '/thunderhawk_lifecycle_native.php';
require_once ThunderhawkLifecycleNative::ROOT . '/core/integrations/purepay_payroll_reservation_review.php';
$h = new ThunderhawkLifecycleNative('payroll_cancel');
$error = null;
$authority = 'cpa_92285a297913dd5feab0e0a3ed148ba14866619c2a01e790e0394a5c57931954';
$input = 'cpi_255117c910a9437a565ed773d63cc32857b901b132926b88790b458040d09bd9';
$run = 'run_core_6ee5f0d01ee1a7ffa5316b44e2ef3f2927e1b01e43971ced17bb2a5024df7a06';
try {
    $repair = json_decode(file_get_contents(ThunderhawkLifecycleNative::PRIVATE . '/synthetic-time-projection-reconcile-20261111.json'), true, 64, JSON_THROW_ON_ERROR);
    $h->expect(($repair['passed'] ?? false) && ($repair['source_entry_unchanged'] ?? false)
        && ($repair['reservation_unchanged'] ?? false), 'Original source projection repair passed before cancellation');
    $h->expect(purepayCoreApiBase() === 'https://purepay.online:19443/api/v1', 'Only the isolated Pure provider is selected');
    foreach ([2,999,2003] as $other) $h->expect(!purepayPayrollAuthorityEnabled($other), 'Other company reservation changes remain disabled: ' . $other);
    $source = $h->pdo->query('SELECT * FROM time_entries WHERE tenant_id=2002 AND id=14')->fetch(PDO::FETCH_ASSOC);
    $week = $h->pdo->query('SELECT * FROM staffing_timesheets WHERE tenant_id=2002 AND id=7')->fetch(PDO::FETCH_ASSOC);
    $artifact = $h->pdo->query("SELECT * FROM artifact_objects WHERE tenant_id=2002 AND id='92419366-4b21-4f06-a946-8a8c1fc8a55b'")->fetch(PDO::FETCH_ASSOC);
    $original = purepayPayrollAuthorityRead($h->pdo, 2002, $authority);
    $claim = $h->pdo->query('SELECT * FROM payroll_source_claims WHERE time_tenant_id=2002 AND time_entry_id=14')->fetch(PDO::FETCH_ASSOC);
    $delivery = integrationDeliveryGet($h->pdo, 2002, 93);
    $h->expect($source && (int)$source['person_id'] === 368 && (int)$source['placement_id'] === 874
        && $source['status'] === 'approved' && (float)$source['hours'] === 1.0 && (int)$source['approved_by_user_id'] === 71
        && $source['payroll_extracted_at'] === null && $week['status'] === 'approved' && (int)$week['approved_by_user_id'] === 71
        && $artifact['status'] === 'approved' && (int)$artifact['version'] === 5, 'Exactly the repaired original approved synthetic hour is selected');
    $h->expect($original['state'] === 'held' && $claim && $claim['authority_id'] === $authority
        && $delivery['state'] === 'delivered' && $delivery['remote_id'] === $input, 'Original input and reservation remain held');
    $h->actor(['people'=>'read','placements'=>'read','time'=>'read','payroll'=>'admin','integrations'=>'admin','accounting'=>'admin']);
    $body = ['delivery_id'=>93,'review_hash'=>$delivery['review_hash'],'authority_id'=>$authority];
    [$status,$history] = $h->request('/api/purepay_core.php?action=payroll_reservation_history', $body, 'POST');
    $h->expect($status === 200 && ($history['can_release'] ?? false) && ($history['has_held'] ?? false), 'Native operator is authorized to review this cancellation');
    [$status,$before] = $h->request('/api/purepay_core.php?action=payroll_reservation_reconcile', $body, 'POST');
    $h->expect($status === 200 && $before['authority']['state'] === 'held' && $before['provider_receipt']['state'] === 'allocated'
        && $before['provider_receipt']['allocation']['run_id'] === $run && $before['provider_receipt']['allocation']['run_state'] === 'draft', 'Provider confirms the original held draft');
    $h->report['before'] = $before;
    [$status] = $h->request('/api/purepay_core.php?action=payroll_reservation_release', $body, 'POST');
    $h->expect($status === 422, 'Cancellation without explicit confirmation is refused');
    $wrong = $body; $wrong['review_hash'] = str_repeat('0',64); $wrong['confirmed'] = true;
    [$status] = $h->request('/api/purepay_core.php?action=payroll_reservation_release', $wrong, 'POST');
    $h->expect($status === 422, 'A stale input review cannot cancel the reservation');
    [$status,$unchanged] = $h->request('/api/purepay_core.php?action=payroll_reservation_reconcile', $body, 'POST');
    $h->expect($status === 200 && $unchanged === $before && purepayPayrollAuthorityRead($h->pdo,2002,$authority) === $original,
        'Refused requests preserve the original provider draft and Core reservation');
    $release = $body + ['confirmed'=>true];
    $h->checkpoint('cancellation_sending');
    [$status,$released] = $h->request('/api/purepay_core.php?action=payroll_reservation_release', $release, 'POST');
    $receipt = $released['provider_receipt'] ?? [];
    $h->expect($status === 200 && $released['authority']['state'] === 'released'
        && ($receipt['state'] ?? null) === 'released' && ($receipt['release_confirmed'] ?? false)
        && ($receipt['provider_usable'] ?? true) === false && ($receipt['allocation']['run_id'] ?? null) === $run
        && ($receipt['allocation']['run_state'] ?? null) === 'void' && is_string($receipt['allocation']['fence_id'] ?? null),
        'Provider permanently voids the original draft before Core releases its source');
    $h->report['release'] = $released; $h->checkpoint('cancellation_received');
    $saved = purepayPayrollAuthorityRead($h->pdo,2002,$authority);
    $h->expect($saved['released_at'] !== null && is_string($saved['release_receipt_ct'])
        && !str_contains($saved['release_receipt_ct'], 'release_confirmed')
        && integrationDeliveryHash(json_decode((string)decryptField($saved['release_receipt_ct']),true)) === integrationDeliveryHash($receipt), 'Cancellation proof is retained encrypted at rest');
    $immutableBefore=$original; $immutableAfter=$saved;
    foreach(['state','released_at','release_receipt_ct'] as $field) unset($immutableBefore[$field],$immutableAfter[$field]);
    $h->expect($immutableBefore === $immutableAfter, 'The original grant and all identity bindings remain unchanged');
    $h->expect((int)$h->pdo->query('SELECT COUNT(*) FROM payroll_source_claims WHERE time_tenant_id=2002 AND time_entry_id=14')->fetchColumn() === 0,
        'Only the original synthetic source claim is released');
    foreach(['release','reconcile','send'] as $action){
        [$status,$replay] = $h->request('/api/purepay_core.php?action=payroll_reservation_' . $action, $release, 'POST');
        $h->expect($status === 200 && $replay === $released, 'Reservation ' . $action . ' replay preserves the cancellation proof');
    }
    $h->expect(purepayPayrollAuthorityRead($h->pdo,2002,$authority) === $saved, 'Replay cannot reopen or change the released Core reservation');
    [$status,$observation] = $h->request('/api/purepay_core.php?action=payroll_result_refresh',$body,'POST');
    $r=$observation['result']??[];
    $h->expect($status === 200 && ($r['run_id']??null)===$run && ($r['input_id']??null)===$input
        && ($r['status']??null)==='void' && ($r['execution_hold']??false)===true && ($r['totals']['source_gross']??null)==='50.00',
        'Core observes the void result against the original one-hour source');
    [$status,$replay] = $h->request('/api/purepay_core.php?action=payroll_result_refresh',$body,'POST');
    $h->expect($status===200 && ($replay['replayed']??false) && $replay['id']===$observation['id'], 'Void-result replay reuses the same observation');
    $h->report['observation']=$observation;
    $h->expect($source === $h->pdo->query('SELECT * FROM time_entries WHERE tenant_id=2002 AND id=14')->fetch(PDO::FETCH_ASSOC)
        && $week === $h->pdo->query('SELECT * FROM staffing_timesheets WHERE tenant_id=2002 AND id=7')->fetch(PDO::FETCH_ASSOC)
        && $artifact === $h->pdo->query("SELECT * FROM artifact_objects WHERE tenant_id=2002 AND id='92419366-4b21-4f06-a946-8a8c1fc8a55b'")->fetch(PDO::FETCH_ASSOC),
        'Cancellation preserves original time, rates, approval and the corrected weekly artifact');
    $h->report += ['authority_id'=>$authority,'input_id'=>$input,'run_id'=>$run,'source_time_unchanged'=>true,
        'permanent_cancellation_verified'=>true,'source_claim_released'=>true,'replay_verified'=>true];
} catch(Throwable $e) { $error=$e; }
$h->finish($error,['payroll_provider_results','rbac_bridge_audit']);

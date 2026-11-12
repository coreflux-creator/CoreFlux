<?php
/** Native payroll obligation evidence; never resolve a recorded earning through current party identity. */
declare(strict_types=1);

function placementPayrollObligationIdentity(array $row): array
{
    $out = [];
    foreach (['id','tenant_id','placement_id','economic_party_id','source_ref_id','payroll_ref_id','ap_bill_id','ar_invoice_id'] as $field) {
        $out[$field] = (int)($row[$field] ?? 0);
    }
    foreach (['source_type','period_start','period_end','currency'] as $field) $out[$field] = (string)($row[$field] ?? '');
    foreach (['quantity'=>4,'basis_amount'=>2,'amount'=>2] as $field=>$scale) {
        if (!isset($row[$field]) || !is_numeric($row[$field]) || !is_finite((float)$row[$field])) throw new RuntimeException('Invalid payroll obligation amount.',409);
        $out[$field] = number_format((float)$row[$field],$scale,'.','');
    }
    return $out;
}

function placementPayrollReceipt(PDO $pdo, int $tenant, int $id): ?array
{
    $q=$pdo->prepare('SELECT * FROM placement_payroll_recipient_receipts WHERE tenant_id=? AND obligation_id=?');
    $q->execute([$tenant,$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}

function placementPayrollReceiptEvidence(array $obligation, ?array $receipt): array
{
    if (!$receipt) throw new RuntimeException('This historical earning has no saved payroll recipient. Review its original payroll evidence before adoption; the current placement cannot establish it.',409);
    $raw=(string)$receipt['receipt_json'];$evidence=json_decode($raw,true);
    if (!is_array($evidence) || !hash_equals((string)$receipt['receipt_sha256'],hash('sha256',$raw))
        || ($evidence['version']??null)!==1 || ($evidence['source']??null)!==placementPayrollObligationIdentity($obligation)
        || (int)$receipt['tenant_id']!==(int)$obligation['tenant_id'] || (int)$receipt['obligation_id']!==(int)$obligation['id']) {
        throw new RuntimeException('The saved payroll recipient or earning evidence differs. Review the original source before continuing.',409);
    }
    foreach (['employee_tenant_id','employee_id','payroll_profile_id'] as $field) {
        if ((int)($evidence['recipient'][$field]??0)<=0 || (int)$evidence['recipient'][$field]!==(int)$receipt[$field]) throw new RuntimeException('The saved payroll recipient identity differs.',409);
    }
    return $evidence;
}

/** Validate current eligibility using the exact saved employment/profile, without rematching a person or account. */
function placementPayrollReceiptEmployee(int $tenant, array $obligation, ?array $receipt): array
{
    if ($tenant!==(int)$obligation['tenant_id']) throw new RuntimeException('The payroll earning belongs to another company.',409);
    $evidence=placementPayrollReceiptEvidence($obligation,$receipt);$recipient=$evidence['recipient'];
    if ((int)$recipient['employee_tenant_id']!==effectiveTenantIdForModule('people',$tenant)) throw new RuntimeException('The saved payroll recipient directory changed; review this earning.',409);
    $q=getDB()->prepare('SELECT e.id AS employee_id,pp.id AS profile_id FROM people_employees e
        JOIN payroll_profiles pp ON pp.employee_id=e.id AND pp.tenant_id=? AND pp.id=? AND pp.enabled=1
        WHERE e.tenant_id=? AND e.id=? AND e.status IN ("active","on_leave")');
    $q->execute([$tenant,$recipient['payroll_profile_id'],$recipient['employee_tenant_id'],$recipient['employee_id']]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('The saved payroll recipient employment or payroll profile is no longer enabled. Review this earning; it cannot be redirected.',409);
    return $row;
}

/** Own the obligation and its identity receipt in the same transaction/savepoint. */
function placementPayrollWriteObligation(int $tenant,int $placement,int $partyId,string $sourceType,int $sourceId,array $values): int
{
    $pdo=getDB();$owns=!$pdo->inTransaction();$save='payroll_recipient_'.bin2hex(random_bytes(8));
    if($owns)$pdo->beginTransaction();else $pdo->exec('SAVEPOINT '.$save);
    try {
        $q=$pdo->prepare('SELECT id FROM placements WHERE tenant_id=? AND id=? AND deleted_at IS NULL FOR UPDATE');
        $q->execute([$tenant,$placement]);if(!$q->fetchColumn())throw new RuntimeException('Placement not found.',404);
        $q=$pdo->prepare('SELECT * FROM placement_economic_obligations WHERE tenant_id=? AND economic_party_id=? AND source_type=? AND source_ref_id=? FOR UPDATE');
        $q->execute([$tenant,$partyId,$sourceType,$sourceId]);$prior=$q->fetch(PDO::FETCH_ASSOC)?:null;
        $receipt=$prior?placementPayrollReceipt($pdo,$tenant,(int)$prior['id']):null;
        if ($prior && (!empty($prior['payroll_ref_id']) || $receipt)) {
            placementPayrollReceiptEvidence($prior,$receipt);
            $requested=['id'=>$prior['id'],'tenant_id'=>$tenant,'placement_id'=>$placement,'economic_party_id'=>$partyId,'source_type'=>$sourceType,'source_ref_id'=>$sourceId]
                +array_intersect_key($values,array_flip(['period_start','period_end','quantity','basis_amount','amount','currency','payroll_ref_id','ap_bill_id','ar_invoice_id']))
                +['quantity'=>0,'basis_amount'=>0,'amount'=>0,'currency'=>'USD'];
            if (placementPayrollObligationIdentity($requested)!==placementPayrollObligationIdentity($prior)
                || !in_array($prior['status'],['payroll','paid'],true) || !in_array($values['status']??'',[$prior['status'],'payroll'],true)) {
                throw new RuntimeException('This source already has a saved payroll earning. Preserve its amount, recipient and original run; use a separate correction.',409);
            }
            $id=(int)$prior['id'];
        } else {
            $payroll=!empty($values['payroll_ref_id']) || ($values['status']??'')==='payroll';
            $employee=null;$party=null;
            if ($payroll) {
                if (($values['status']??'')!=='payroll' || ($values['currency']??'')!=='USD' || (int)($values['payroll_ref_id']??0)<=0
                    || !empty($values['ap_bill_id']) || !empty($values['ar_invoice_id'])) throw new RuntimeException('Review the native payroll destination and explicit USD currency.',409);
                $q=$pdo->prepare('SELECT id FROM payroll_runs WHERE tenant_id=? AND id=? AND status="draft" FOR UPDATE');
                $q->execute([$tenant,$values['payroll_ref_id']]);if(!$q->fetchColumn())throw new RuntimeException('Choose a draft payroll run in this company.',409);
                $q=$pdo->prepare('SELECT * FROM placement_economic_parties WHERE tenant_id=? AND placement_id=? AND id=? AND active=1 FOR UPDATE');
                $q->execute([$tenant,$placement,$partyId]);$party=$q->fetch(PDO::FETCH_ASSOC);
                if (!$party || $party['settlement_channel']!=='payroll') throw new RuntimeException('The payroll participant needs review.',409);
                if (isset($values['payroll_source_party'])) {
                    foreach (['person_id','user_id','ap_vendor_id','settlement_channel'] as $field) {
                        if ((string)($party[$field]??'')!==(string)($values['payroll_source_party'][$field]??'')) throw new RuntimeException('The approved payroll recipient changed before settlement. Refresh and review it.',409);
                    }
                }
                $employee=placementEconomicsPayrollEmployee($tenant,$party,true);
                if (!$employee) throw new RuntimeException('Choose an unambiguous payroll recipient and enabled profile.',409);
                if (isset($values['payroll_recipient']) && ((int)$values['payroll_recipient']['employee_id']!==(int)$employee['employee_id']
                    || (int)$values['payroll_recipient']['profile_id']!==(int)$employee['profile_id'])) throw new RuntimeException('The payroll recipient changed before settlement. Refresh and review it.',409);
            }
            $id=placementEconomicsStoreObligation($tenant,$placement,$partyId,$sourceType,$sourceId,$values);
            if ($payroll) {
                $q=$pdo->prepare('SELECT * FROM placement_economic_obligations WHERE tenant_id=? AND id=?');$q->execute([$tenant,$id]);$obligation=$q->fetch(PDO::FETCH_ASSOC);
                $recipient=['employee_tenant_id'=>(int)$employee['employee_tenant_id'],'employee_id'=>(int)$employee['employee_id'],'payroll_profile_id'=>(int)$employee['profile_id']];
                $raw=json_encode(['version'=>1,'source'=>placementPayrollObligationIdentity($obligation),'recipient'=>$recipient,
                    'original_party'=>['person_id'=>(int)($party['person_id']??0),'user_id'=>(int)($party['user_id']??0),'settlement_channel'=>'payroll']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
                $pdo->prepare('INSERT INTO placement_payroll_recipient_receipts(tenant_id,obligation_id,employee_tenant_id,employee_id,payroll_profile_id,receipt_json,receipt_sha256) VALUES(?,?,?,?,?,?,?)')
                    ->execute([$tenant,$id,$recipient['employee_tenant_id'],$recipient['employee_id'],$recipient['payroll_profile_id'],$raw,hash('sha256',$raw)]);
            }
        }
        if($owns)$pdo->commit();else $pdo->exec('RELEASE SAVEPOINT '.$save);
        return $id;
    } catch(Throwable $e) {
        if($pdo->inTransaction()) {if($owns)$pdo->rollBack();else{$pdo->exec('ROLLBACK TO SAVEPOINT '.$save);$pdo->exec('RELEASE SAVEPOINT '.$save);}}
        throw $e;
    }
}

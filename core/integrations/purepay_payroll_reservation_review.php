<?php
/** Operator-facing, input-scoped reservation lifecycle. Never creates or executes payroll. */
declare(strict_types=1);
require_once __DIR__.'/purepay_payroll_results.php';

function purepayPayrollReservationAccess(PDO $pdo,int $tenant,string $instance,array $owners,int $deliveryId,string $hash,bool $write=false): array
{
    [$delivery,$payload]=purepayPayrollInputEnvelope($pdo,$tenant,$deliveryId,$hash,$instance,$owners);
    if($delivery['state']!=='delivered')throw new RuntimeException('Confirm the input receipt before reserving payroll source.');
    $access=purepayCoreLinkAccess($pdo,$tenant,$instance,'person',false);
    if((string)$access['connection']['id']!==$delivery['connection_ref']||$access['review']['organization']['id']!==$payload['organization_id'])
        throw new RuntimeException('Restore the original provider company connection.');
    if(($access['review']['payroll_authority_contract_version']??null)!==PUREPAY_PAYROLL_AUTHORITY_VERSION)
        throw new RuntimeException('The provider source reservation contract is unavailable.');
    if($write&&(!purepayPayrollAuthorityEnabled($tenant)||!in_array('write:payroll_authority',$access['review']['capabilities'],true)))
        throw new RuntimeException('Source reservation changes are not enabled for this connection.');
    return [$delivery,$payload,$access];
}

/** Local history is available after a lost response and does not silently resend anything. */
function purepayPayrollReservationHistory(PDO $pdo,int $tenant,string $instance,array $owners,int $deliveryId,string $hash,?string $before=null): array
{
    if($before!==null&&!preg_match('/^cpa_[a-f0-9]{64}$/D',$before))throw new InvalidArgumentException('Invalid reservation history page.');
    [$delivery,$payload,$access]=purepayPayrollReservationAccess($pdo,$tenant,$instance,$owners,$deliveryId,$hash);
    $sql='SELECT id FROM payroll_source_authorities WHERE tenant_id=? AND input_delivery_id=?';$params=[$tenant,$deliveryId];
    if($before!==null){$sql.=' AND id<?';$params[]=$before;}
    $s=$pdo->prepare($sql.' ORDER BY id DESC LIMIT 26');$s->execute($params);$rows=$s->fetchAll(PDO::FETCH_COLUMN);$items=[];
    foreach(array_slice($rows,0,25) as $id){
        $row=purepayPayrollResultAuthority($pdo,$tenant,$instance,$owners,$delivery,$payload,$id);
        $items[]=purepayPayrollAuthorityView($row);
    }
    $s=$pdo->prepare('SELECT COUNT(*) FROM payroll_source_authorities WHERE tenant_id=? AND input_delivery_id=? AND state="held"');
    $s->execute([$tenant,$deliveryId]);$hasHeld=(int)$s->fetchColumn()>0;
    purepayCoreLinkAccessUnchanged($pdo,$tenant,$access);
    return ['tenant_id'=>$tenant,'delivery_id'=>$deliveryId,'organization'=>$access['review']['organization'],
        'changes_enabled'=>purepayPayrollAuthorityEnabled($tenant)&&in_array('write:payroll_authority',$access['review']['capabilities'],true),
        'has_held'=>$hasHeld,'items'=>$items,'next_before'=>count($rows)>25?$rows[24]:null];
}

function purepayPayrollReservationReserve(PDO $pdo,int $tenant,int $actor,string $instance,array $owners,int $deliveryId,string $hash,string $request,?callable $audit=null): array
{
    purepayPayrollReservationAccess($pdo,$tenant,$instance,$owners,$deliveryId,$hash,true);
    return purepayPayrollAuthorityReserve($pdo,$tenant,$actor,$instance,$owners,$deliveryId,$hash,$request,$audit);
}

/** Every action revalidates the original input and all server-resolved directory owners. */
function purepayPayrollReservationAct(PDO $pdo,int $tenant,int $actor,string $instance,array $owners,int $deliveryId,string $hash,string $authorityId,string $action,?callable $audit=null): array
{
    if(!in_array($action,['send','reconcile','release'],true))throw new InvalidArgumentException('Unknown reservation action.');
    [$delivery,$payload]=purepayPayrollReservationAccess($pdo,$tenant,$instance,$owners,$deliveryId,$hash,$action!=='reconcile');
    purepayPayrollResultAuthority($pdo,$tenant,$instance,$owners,$delivery,$payload,$authorityId);
    $receipt=match($action){
        'send'=>purepayPayrollAuthoritySend($pdo,$tenant,$instance,$authorityId),
        'reconcile'=>purepayPayrollAuthorityReconcile($pdo,$tenant,$instance,$authorityId),
        'release'=>purepayPayrollAuthorityRelease($pdo,$tenant,$actor,$instance,$authorityId,$audit),
    };
    return ['authority'=>purepayPayrollAuthorityView(purepayPayrollAuthorityRead($pdo,$tenant,$authorityId)),'provider_receipt'=>$receipt];
}

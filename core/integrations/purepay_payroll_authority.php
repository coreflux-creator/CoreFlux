<?php
/** Durable Core source reservation. Provider grant/release transport is a separate step. */
declare(strict_types=1);
require_once __DIR__.'/purepay_payroll_inputs.php';

const PUREPAY_PAYROLL_AUTHORITY_VERSION='2026-10-21.1';

function purepayPayrollAuthoritySchema(PDO $pdo): void
{
    $names=['cf_payroll_source_time_update','cf_payroll_source_time_delete','cf_payroll_source_rate_update','cf_payroll_source_rate_delete'];
    $s=$pdo->prepare('SELECT trigger_name FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name IN (?,?,?,?)');
    $s->execute($names);
    if(count($s->fetchAll(PDO::FETCH_COLUMN))!==4)throw new RuntimeException('Apply Core 166, Time 011 and Placements 008 before reserving payroll source.');
}

function purepayPayrollAuthorityRead(PDO $pdo,int $tenant,string $id,bool $lock=false): array
{
    if($tenant<=0 || !preg_match('/^cpa_[a-f0-9]{64}$/D',$id))throw new InvalidArgumentException('A payroll source reservation is required.');
    $s=$pdo->prepare('SELECT * FROM payroll_source_authorities WHERE tenant_id=:t AND id=:i'.($lock?' FOR UPDATE':''));
    $s->execute(['t'=>$tenant,'i'=>$id]);$row=$s->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException('The payroll source reservation was not found in this company.');
    return $row;
}

function purepayPayrollAuthorityView(array $row): array
{
    $payload=json_decode((string)decryptField($row['payload_ct']),true,64,JSON_THROW_ON_ERROR);
    return $payload['authority']+['state'=>$row['state'],'created_at'=>$row['created_at'],'released_at'=>$row['released_at']];
}

/**
 * Internal service boundary: callers must resolve owners and authorize payroll-create.
 * No API calls this until reviewed provider grant/release handling is implemented.
 * A request ID is generated for a review and retained on retry, never an expiry.
 */
function purepayPayrollAuthorityReserve(PDO $pdo,int $tenant,int $actor,string $instance,array $owners,int $deliveryId,string $reviewHash,string $requestId,?callable $audit=null): array
{
    if(getenv('COREFLUX_PUREPAY_PAYROLL_AUTHORITY')!=='1')throw new RuntimeException('Core payroll source reservations are not enabled in this environment.');
    if($actor<=0 || !preg_match('/^[a-f0-9]{64}$/D',$requestId))throw new InvalidArgumentException('A reviewed payroll source request is required.');
    if($pdo->inTransaction())throw new LogicException('Source reservation requires its own committed transaction.');
    purepayPayrollAuthoritySchema($pdo);
    [$delivery,$payload,$number]=purepayPayrollInputEnvelope($pdo,$tenant,$deliveryId,$reviewHash,$instance,$owners);
    if($delivery['state']!=='delivered')throw new RuntimeException('Confirm the provider input receipt before reserving its source time.');
    $access=purepayCoreLinkAccess($pdo,$tenant,$instance,'person',false);
    if((string)$access['connection']['id']!==$delivery['connection_ref'] || $access['review']['organization']['id']!==$payload['organization_id'])throw new RuntimeException('This input belongs to a different provider connection.');
    $body=$payload['body'];$contentHash=integrationDeliveryHash($body);
    $inputId='cpi_'.integrationDeliveryHash([$payload['organization_id'],$contentHash]);
    if($delivery['remote_id']!==$inputId || $delivery['remote_receipt_id']!==$inputId)throw new RuntimeException('The input receipt does not match the reviewed packet.');
    $id='cpa_'.integrationDeliveryHash([$instance,$tenant,$requestId]);
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $pdo->beginTransaction();
    try {
        purepayCoreConnection($pdo,$tenant,true);
        purepayCoreLinkAccessUnchanged($pdo,$tenant,$access);
        $s=$pdo->prepare('SELECT * FROM integration_deliveries WHERE tenant_id=:t AND id=:i FOR UPDATE');
        $s->execute(['t'=>$tenant,'i'=>$deliveryId]);$locked=$s->fetch(PDO::FETCH_ASSOC);
        if(!$locked || $locked['state']!=='delivered' || !hash_equals((string)$locked['payload_ct'],(string)$delivery['payload_ct']) || $locked['remote_receipt_id']!==$inputId)throw new RuntimeException('The input receipt changed before source reservation.');
        $s=$pdo->prepare('SELECT * FROM payroll_source_authorities WHERE tenant_id=:t AND request_id=:r FOR UPDATE');
        $s->execute(['t'=>$tenant,'r'=>$requestId]);$existing=$s->fetch(PDO::FETCH_ASSOC);
        if($existing) {
            if($existing['id']!==$id || (int)$existing['input_delivery_id']!==$deliveryId || $existing['content_hash']!==$contentHash)throw new RuntimeException('The source request is already bound to another packet.');
            $result=purepayPayrollAuthorityView($existing);$pdo->commit();return $result;
        }
        $ids=array_column(array_column($body['components'],'source'),'time_entry_id');sort($ids,SORT_NUMERIC);
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $s=$pdo->prepare('SELECT * FROM time_entries WHERE tenant_id=? AND id IN ('.$marks.') ORDER BY id FOR UPDATE');
        $s->execute(array_merge([$owners['time']],$ids));$entries=$s->fetchAll(PDO::FETCH_ASSOC);
        if(count($entries)!==count($ids))throw new RuntimeException('A reviewed time entry is missing.');
        $rateIds=array_values(array_unique(array_column($entries,'rate_snapshot_id')));sort($rateIds,SORT_NUMERIC);
        $s=$pdo->prepare('SELECT id FROM placement_rates WHERE tenant_id=? AND id IN ('.implode(',',array_fill(0,count($rateIds),'?')).') ORDER BY id FOR UPDATE');
        $s->execute(array_merge([$owners['placements']],$rateIds));
        if(count($s->fetchAll(PDO::FETCH_COLUMN))!==count($rateIds))throw new RuntimeException('An approved rate snapshot is missing.');
        // Current-read locks precede the consistent source rebuild and claim insert.
        $placementIds=array_values(array_unique(array_column($entries,'placement_id')));sort($placementIds,SORT_NUMERIC);
        foreach([['people','person',$owners['people'],[(int)$delivery['entity_id']]],['placements','placement',$owners['placements'],$placementIds]]as[$table,$kind,$owner,$recordIds]) {
            $place=implode(',',array_fill(0,count($recordIds),'?'));
            $s=$pdo->prepare('SELECT id FROM '.$table.' WHERE tenant_id=? AND id IN ('.$place.') ORDER BY id FOR UPDATE');$s->execute(array_merge([$owner],$recordIds));$s->fetchAll();
            $s=$pdo->prepare('SELECT record_id FROM core_record_numbers WHERE tenant_id=? AND entity_type=? AND record_id IN ('.$place.') ORDER BY record_id FOR UPDATE');$s->execute(array_merge([$owner,$kind],$recordIds));$s->fetchAll();
            $s=$pdo->prepare('SELECT id FROM integration_deliveries WHERE tenant_id=? AND provider="purepay" AND connection_ref=? AND entity_type=? AND entity_tenant_id=? AND entity_id IN ('.$place.') AND operation="record.link" ORDER BY id FOR UPDATE');
            $s->execute(array_merge([$tenant,(string)$access['connection']['id'],$kind,$owner],$recordIds));$s->fetchAll();
        }
        $fresh=purepayPayrollInputBuild($pdo,$tenant,$instance,$owners,$access,$number,$body['period_start'],$body['period_end']);
        if(integrationDeliveryHash($fresh['body'])!==$contentHash)throw new RuntimeException('Core approval, source time, rates or links changed. Review a fresh input packet.');
        $authority=['contract_version'=>PUREPAY_PAYROLL_AUTHORITY_VERSION,'id'=>$id,'instance_id'=>$instance,
            'organization_id'=>$payload['organization_id'],'time_tenant_id'=>$owners['time'],'input_id'=>$inputId,'content_hash'=>$contentHash,
            'components'=>count($body['components']),'source_time_reserved'=>true];
        $cipher=encryptField(integrationDeliveryJson(['authority'=>$authority,'owners'=>$owners,'packet'=>$body]));
        $s=$pdo->prepare('INSERT INTO payroll_source_authorities(id,tenant_id,request_id,input_delivery_id,connection_id,organization_id,instance_id,time_tenant_id,input_id,content_hash,payload_ct,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
        $s->execute([$id,$tenant,$requestId,$deliveryId,(int)$access['connection']['id'],$payload['organization_id'],$instance,$owners['time'],$inputId,$contentHash,$cipher,$actor]);
        foreach($entries as$entry) {
            $s=$pdo->prepare('SELECT authority_id FROM payroll_source_claims WHERE time_tenant_id=? AND time_entry_id=? FOR UPDATE');
            $s->execute([$owners['time'],$entry['id']]);
            if($s->fetchColumn()!==false)throw new RuntimeException('Some hours are already reserved for another payroll input. Review its reservation.');
            $pdo->prepare('INSERT INTO payroll_source_claims(time_tenant_id,time_entry_id,authority_id,placement_tenant_id,rate_snapshot_id) VALUES(?,?,?,?,?)')->execute([$owners['time'],$entry['id'],$id,$owners['placements'],$entry['rate_snapshot_id']]);
        }
        if($audit)$audit('purepay_payroll_source_reserved',['authority_id'=>$id,'input_delivery_id'=>$deliveryId,'components'=>count($entries)]);
        $result=purepayPayrollAuthorityView(purepayPayrollAuthorityRead($pdo,$tenant,$id));
        $pdo->commit();return $result;
    } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

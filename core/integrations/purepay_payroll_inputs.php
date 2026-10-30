<?php
/** Approved source snapshots for PurePayroll review; never creates payroll or marks time extracted. */
declare(strict_types=1);
require_once __DIR__.'/delivery_enablement.php';
require_once __DIR__.'/purepay_links.php';

const PUREPAY_PAYROLL_INPUT_VERSION='2026-10-21.1';

function purepayPayrollInputsEnabled(?int $tenant=null): bool
{
    return (getenv('COREFLUX_PUREPAY_PAYROLL_INPUT_DELIVERY')==='1') && integrationDeliveryTenantAllowed($tenant);
}

function purepayPayrollInputDate(string $value): DateTimeImmutable
{
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    if(!$date || $date->format('Y-m-d')!==$value)throw new InvalidArgumentException('Choose a valid payroll input period.');
    return $date;
}

/** Keep SQL decimals as strings; no binary floating point or employee default-rate substitution. */
function purepayPayrollInputDecimal(mixed $value,int $scale,int $digits,bool $positive=false): string
{
    if(!is_string($value) || !preg_match('/^(0|[1-9][0-9]{0,'.($digits-1).'})\.[0-9]{'.$scale.'}$/D',$value)
        || ($positive && preg_match('/^0\.0+$/D',$value)))throw new RuntimeException('An approved time or rate value is not a valid payroll decimal.');
    return $value;
}

/** A delivered, immutable identity match is required before any source data can leave Core. */
function purepayPayrollInputLink(PDO $pdo,int $tenant,array $connection,array $source,string $org): array
{
    $s=$pdo->prepare('SELECT * FROM integration_deliveries WHERE tenant_id=:t AND provider="purepay" AND connection_ref=:c
        AND entity_type=:k AND entity_tenant_id=:o AND entity_id=:i AND operation="record.link" AND state="delivered" LIMIT 2');
    $s->execute(['t'=>$tenant,'c'=>(string)$connection['id'],'k'=>$source['reference']['entity_type'],'o'=>$source['entity_tenant_id'],'i'=>$source['entity_id']]);
    $rows=$s->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)!==1)throw new RuntimeException('Confirm the employee and assignment record links before reviewing payroll inputs.');
    $row=$rows[0];$payload=json_decode((string)decryptField($row['payload_ct']),true,64,JSON_THROW_ON_ERROR);
    $body=$payload['body'];
    if($payload['organization_id']!==$org || integrationDeliveryHash($body['reference'])!==integrationDeliveryHash($source['reference']) || $body['provider_id']!==$row['remote_id'])throw new RuntimeException('A payroll record link no longer matches the current Core identity.');
    $receipt=purepayCoreLinkReceipt(['id'=>$row['remote_receipt_id'],'organization_id'=>$org,'reference'=>$body['reference'],
        'provider_id'=>$row['remote_id'],'provider_record_version'=>$body['expected_record_version']],$org,$body);
    return ['id'=>$receipt['id'],'provider_id'=>$receipt['provider_id']];
}

/** Caller resolves every owner through module scope. No directory or time owner comes from the browser. */
function purepayPayrollInputBuild(PDO $pdo,int $tenant,string $instance,array $owners,array $access,string $number,string $start,string $end): array
{
    $from=purepayPayrollInputDate($start);$to=purepayPayrollInputDate($end);
    if($to<$from || $from->diff($to)->days>62)throw new InvalidArgumentException('Review no more than 63 days at a time.');
    foreach(['time','people','placements']as$module)if(!isset($owners[$module]) || !is_int($owners[$module]) || $owners[$module]<=0)throw new InvalidArgumentException('Authorized module owners are required.');
    $person=purepayCoreLinkSource($pdo,$instance,$owners['people'],'person',$number);
    $org=$access['review']['organization']['id'];
    $personLink=purepayPayrollInputLink($pdo,$tenant,$access['connection'],$person,$org);
    $s=$pdo->prepare('SELECT * FROM time_entries WHERE tenant_id=:t AND person_id=:p AND work_date BETWEEN :s AND :e AND status="approved" ORDER BY id LIMIT 201 FOR UPDATE');
    $s->execute(['t'=>$owners['time'],'p'=>$person['entity_id'],'s'=>$start,'e'=>$end]);$entries=$s->fetchAll(PDO::FETCH_ASSOC);
    if(!$entries)throw new RuntimeException('No approved time was found for this person and period.');
    if(count($entries)>200)throw new RuntimeException('This review exceeds 200 time entries. Choose a shorter period.');
    require_once __DIR__.'/../quanta/time_current.php';
    quantaTimeAssertCurrent($pdo,$owners['time'],array_column($entries,'id'));
    $components=[];$sourceState=[];
    foreach($entries as$entry) {
        if(empty($entry['approved_at']) || !empty($entry['superseded_by_id']) || !empty($entry['payroll_extracted_at'])
            || !empty($entry['payroll_extracted_ref']) || (isset($entry['payable']) && (int)$entry['payable']!==1))throw new RuntimeException('An entry is not payable, no longer approved, or already extracted for payroll. Review its source before continuing.');
        $s=$pdo->prepare('SELECT n.record_number FROM placements p JOIN core_record_numbers n ON n.tenant_id=p.tenant_id AND n.entity_type="placement" AND n.record_id=p.id AND n.canonical_record_id=p.id
            WHERE p.tenant_id=:t AND p.id=:p AND p.person_id=:person AND p.deleted_at IS NULL AND p.engagement_type="w2" LIMIT 2');
        $s->execute(['t'=>$owners['placements'],'p'=>$entry['placement_id'],'person'=>$person['entity_id']]);$numbers=$s->fetchAll(PDO::FETCH_COLUMN);
        if(count($numbers)!==1)throw new RuntimeException('An approved entry has no current W-2 placement in the authorized directory.');
        $placement=purepayCoreLinkSource($pdo,$instance,$owners['placements'],'placement',$numbers[0]);
        $placementLink=purepayPayrollInputLink($pdo,$tenant,$access['connection'],$placement,$org);
        $s=$pdo->prepare('SELECT * FROM placement_rates WHERE tenant_id=:t AND placement_id=:p AND id=:r AND approved_at IS NOT NULL');
        $s->execute(['t'=>$owners['placements'],'p'=>$entry['placement_id'],'r'=>$entry['rate_snapshot_id']]);$rate=$s->fetch(PDO::FETCH_ASSOC);
        if(!$rate)throw new RuntimeException('An approved entry is missing its frozen placement pay rate.');
        $multiplier=match($entry['category']) {
            'regular_billable','regular_nonbillable','holiday','vacation','sick','bereavement'=>'1.00',
            'OT_billable','OT_nonbillable'=>$rate['ot_multiplier'],
            default=>throw new RuntimeException('A time category needs an explicit payroll earning mapping before export.'),
        };
        $source=['time_entry_id'=>(int)$entry['id'],'revision'=>integrationDeliveryHash($entry),'work_date'=>$entry['work_date'],
            'category'=>$entry['category'],'hours'=>purepayPayrollInputDecimal($entry['hours'],2,4,true),
            'source_system'=>$entry['source_system']??$entry['source'],'source_entry_id'=>null,'source_component'=>null,'source_revision'=>null,'source_workspace_id'=>null];
        $upstream=null;
        if($source['source_system']==='quanta' || $entry['source']==='quanta') {
            $source['source_system']='quanta';
            $s=$pdo->prepare('SELECT * FROM quanta_time_imports WHERE tenant_id=:t AND time_entry_id=:i');
            $s->execute(['t'=>$owners['time'],'i'=>$entry['id']]);$upstream=$s->fetch(PDO::FETCH_ASSOC);
            // Legacy imports have no persisted workspace identity. Never infer their origin from today's connection.
            if(!$upstream || empty($upstream['source_workspace_id']))throw new RuntimeException('This Quanta import has no recorded source workspace. Verify and refresh its provenance before payroll export.');
            $source['source_entry_id']=$upstream['quanta_entry_id'];$source['source_component']=$upstream['component'];
            $source['source_revision']=$upstream['source_hash'];$source['source_workspace_id']=$upstream['source_workspace_id'];
        }
        $components[]=['person'=>$person['reference'],'placement'=>$placement['reference'],
            'employee_id'=>$personLink['provider_id'],'assignment_id'=>$placementLink['provider_id'],
            'person_link_id'=>$personLink['id'],'placement_link_id'=>$placementLink['id'],'source'=>$source,
            'rate'=>['snapshot_id'=>(int)$rate['id'],'revision'=>integrationDeliveryHash($rate),
                'pay_rate'=>purepayPayrollInputDecimal($rate['pay_rate'],4,6),'pay_rate_unit'=>$rate['pay_rate_unit'],'currency'=>$rate['currency'],
                'multiplier'=>purepayPayrollInputDecimal($multiplier,2,2,true)]];
        $sourceState[]=[$entry,$rate,$placement,$upstream,$placementLink];
    }
    $body=['contract_version'=>PUREPAY_PAYROLL_INPUT_VERSION,'instance_id'=>$instance,'time_tenant_id'=>$owners['time'],
        'period_start'=>$start,'period_end'=>$end,'components'=>$components];
    if(strlen(integrationDeliveryJson($body))>60000)throw new RuntimeException('The input packet is too large. Choose a shorter period.');
    return ['person'=>$person,'body'=>$body,'source_hash'=>integrationDeliveryHash([$owners,$person,$personLink,$sourceState])];
}

function purepayPayrollInputSnapshot(PDO $pdo,int $tenant,string $instance,array $owners,string $number,string $start,string $end): array
{
    $access=purepayCoreLinkAccess($pdo,$tenant,$instance,'person',false);
    if(($access['review']['payroll_input_contract_version']??null)!==PUREPAY_PAYROLL_INPUT_VERSION
        || !in_array('write:payroll_inputs',$access['review']['capabilities'],true))throw new RuntimeException('The provider needs the current payroll input contract and explicit input-review capability.');
    if($pdo->inTransaction())throw new LogicException('Payroll source review needs its own consistent read.');
    $pdo->beginTransaction();
    try {$build=purepayPayrollInputBuild($pdo,$tenant,$instance,$owners,$access,$number,$start,$end);$pdo->commit();}
    catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    purepayCoreLinkAccessUnchanged($pdo,$tenant,$access);
    $context=['provider'=>'purepayroll','connection_ref'=>(string)$access['connection']['id'],'binding_hash'=>$access['review']['review_hash'],
        'entity_type'=>'person','entity_tenant_id'=>$owners['people'],'entity_id'=>$build['person']['entity_id'],'operation'=>'payroll.inputs',
        'source_hash'=>$build['source_hash'],'remote_hash'=>integrationDeliveryHash($access['review'])];
    return $build+['access'=>$access,'context'=>$context,'payload'=>['organization_id'=>$access['review']['organization']['id'],
        'owners'=>$owners,'body'=>$build['body']]];
}

function purepayPayrollInputPreview(PDO $pdo,int $tenant,int $actor,string $instance,array $owners,string $number,string $start,string $end): array
{
    $snapshot=purepayPayrollInputSnapshot($pdo,$tenant,$instance,$owners,$number,$start,$end);
    $row=integrationDeliveryPreview($pdo,$tenant,$actor,$snapshot['context'],$snapshot['payload']);
    return ['person'=>$snapshot['person']['reference'],'packet'=>$snapshot['body'],'organization'=>$snapshot['access']['review']['organization'],
        'delivery_enabled'=>purepayPayrollInputsEnabled($tenant),'delivery'=>integrationDeliverySummary($row)];
}

function purepayPayrollInputEnvelope(PDO $pdo,int $tenant,int $id,string $hash,string $instance,array $owners): array
{
    $row=integrationDeliveryGet($pdo,$tenant,$id);
    if($row['provider']!=='purepayroll' || $row['operation']!=='payroll.inputs' || $row['entity_type']!=='person' || !hash_equals($row['review_hash'],$hash))throw new InvalidArgumentException('A current payroll input review is required.');
    $payload=json_decode((string)decryptField($row['payload_ct']),true,64,JSON_THROW_ON_ERROR);
    if($payload['owners']!==integrationDeliveryCanonical($owners) || $payload['body']['instance_id']!==$instance || (int)$row['entity_tenant_id']!==$owners['people'])throw new RuntimeException('The input review belongs to different module owners.');
    $number=$payload['body']['components'][0]['person']['number'];
    $person=purepayCoreLinkSource($pdo,$instance,$owners['people'],'person',$number);
    if($person['entity_id']!==(int)$row['entity_id'])throw new RuntimeException('The input person identity changed.');
    return [$row,$payload,$number];
}

function purepayPayrollInputReceipt(array $receipt,string $org,array $body): array
{
    $hash=integrationDeliveryHash($body);$id='cpi_'.integrationDeliveryHash([$org,$hash]);
    if(($receipt['id']??null)!==$id || ($receipt['organization_id']??null)!==$org || ($receipt['content_hash']??null)!==$hash
        || ($receipt['state']??null)!=='received_for_review' || ($receipt['payroll_created']??null)!==false
        || ($receipt['source_time_reserved']??null)!==false || ($receipt['components']??null)!==count($body['components']))throw new RuntimeException('The provider receipt does not match this payroll input packet.');
    return $receipt;
}

function purepayPayrollInputHistory(PDO $pdo,int $tenant,string $instance,array $owners,string $number,?int $before=null): array
{
    $access=purepayCoreLinkAccess($pdo,$tenant,$instance,'person',false);
    $person=purepayCoreLinkSource($pdo,$instance,$owners['people'],'person',$number);
    if($before!==null && $before<=0)throw new InvalidArgumentException('Invalid input history page.');
    $sql='SELECT * FROM integration_deliveries WHERE tenant_id=:t AND provider="purepayroll" AND connection_ref=:c AND operation="payroll.inputs" AND entity_type="person" AND entity_tenant_id=:o AND entity_id=:i';
    $params=['t'=>$tenant,'c'=>(string)$access['connection']['id'],'o'=>$owners['people'],'i'=>$person['entity_id']];
    if($before!==null){$sql.=' AND id<:before';$params['before']=$before;}
    $s=$pdo->prepare($sql.' ORDER BY id DESC LIMIT 26');$s->execute($params);$rows=$s->fetchAll(PDO::FETCH_ASSOC);$items=[];
    foreach(array_slice($rows,0,25)as$row) {
        [$row,$payload]=purepayPayrollInputEnvelope($pdo,$tenant,(int)$row['id'],$row['review_hash'],$instance,$owners);
        if($payload['organization_id']!==$access['review']['organization']['id'])throw new RuntimeException('Input history belongs to another provider organization.');
        $items[]=['person'=>$person['reference'],'packet'=>$payload['body'],'organization'=>$access['review']['organization'],
            'delivery_enabled'=>purepayPayrollInputsEnabled($tenant),'delivery'=>integrationDeliverySummary($row)];
    }
    purepayCoreLinkAccessUnchanged($pdo,$tenant,$access);
    return ['items'=>$items,'next_before'=>count($rows)>25?(int)$rows[24]['id']:null];
}

function purepayPayrollInputComplete(PDO $pdo,int $tenant,array $row,array $receipt,?string $claim,?callable $audit): array
{
    // The shared completion routine stores an immutable provider ID/receipt pair and fences the attempt.
    return purepayCoreLinkComplete($pdo,$tenant,$row,['provider_id'=>$receipt['id'],'id'=>$receipt['id']],$claim,$audit);
}

function purepayPayrollInputSend(PDO $pdo,int $tenant,int $actor,string $instance,array $owners,int $id,string $hash,?callable $audit=null): array
{
    if(!purepayPayrollInputsEnabled($tenant))throw new RuntimeException('Payroll input delivery is not enabled in this environment.');
    [$row,$payload,$number]=purepayPayrollInputEnvelope($pdo,$tenant,$id,$hash,$instance,$owners);
    if($row['state']==='delivered')return integrationDeliverySummary($row);
    if(!in_array($row['state'],['preview','approved'],true))throw new RuntimeException('Check the receipt for this input attempt before continuing.');
    $snapshot=purepayPayrollInputSnapshot($pdo,$tenant,$instance,$owners,$number,$payload['body']['period_start'],$payload['body']['period_end']);
    if(integrationDeliveryHash($snapshot['payload'])!==integrationDeliveryHash($payload))throw new RuntimeException('Approved time, rates or links changed. Review a fresh input packet.');
    if($row['state']==='preview')integrationDeliveryApprove($pdo,$tenant,$id,$actor,$hash);
    $claim=integrationDeliveryClaim($pdo,$tenant,$id,array_intersect_key($snapshot['context'],array_flip(['binding_hash','source_hash','remote_hash'])));
    try {
        purepayCoreLinkAccessUnchanged($pdo,$tenant,$snapshot['access']);
        $receipt=purepayCall($snapshot['access']['key'],'POST',PUREPAY_CORE_PATH.'/payroll/inputs',$payload['body'],25,integrationDeliveryHash($payload['body']),purepayCoreApiBase());
        return purepayPayrollInputComplete($pdo,$tenant,$row,purepayPayrollInputReceipt($receipt,$payload['organization_id'],$payload['body']),$claim['claim'],$audit);
    } catch(Throwable $e) {
        $live=integrationDeliveryGet($pdo,$tenant,$id);
        if($live['state']==='delivered')return integrationDeliverySummary($live);
        if($live['state']==='sending')integrationDeliveryFinish($pdo,$tenant,$id,$claim['claim'],'uncertain',null,'payroll_input_receipt_unconfirmed');
        return integrationDeliverySummary(integrationDeliveryGet($pdo,$tenant,$id));
    }
}

function purepayPayrollInputReconcile(PDO $pdo,int $tenant,string $instance,array $owners,int $id,string $hash,?callable $audit=null): array
{
    [$row,$payload]=purepayPayrollInputEnvelope($pdo,$tenant,$id,$hash,$instance,$owners);
    if($row['state']==='delivered')return integrationDeliverySummary($row);
    if(!in_array($row['state'],['sending','uncertain'],true))throw new RuntimeException('Only an attempted input packet can be reconciled.');
    $access=purepayCoreLinkAccess($pdo,$tenant,$instance,'person',false);
    if((string)$access['connection']['id']!==$row['connection_ref'] || $access['review']['organization']['id']!==$payload['organization_id'])throw new RuntimeException('This input attempt belongs to another provider connection.');
    $remote='cpi_'.integrationDeliveryHash([$payload['organization_id'],integrationDeliveryHash($payload['body'])]);
    $receipt=purepayPayrollInputReceipt(purepayCoreGet($access['key'],'/payroll/inputs/'.$remote),$payload['organization_id'],$payload['body']);
    purepayCoreLinkAccessUnchanged($pdo,$tenant,$access);
    return purepayPayrollInputComplete($pdo,$tenant,$row,$receipt,null,$audit);
}

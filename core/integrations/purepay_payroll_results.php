<?php
/** Attributable provider ledger observations; never payroll execution or GL posting. */
declare(strict_types=1);
require_once __DIR__.'/purepay_payroll_authority.php';

const PUREPAY_PAYROLL_RESULT_VERSION='2026-10-22.1';
const PUREPAY_PAYROLL_RESULT_EE=['federal_wh','social_security','medicare','state_wh','local_wh','sdi_pfl','other_deductions'];
const PUREPAY_PAYROLL_RESULT_ER=['employer_fica','employer_futa','employer_suta','employer_match'];
const PUREPAY_PAYROLL_RESULT_AMOUNTS=['source_gross','bonus','gross','reimbursements',...PUREPAY_PAYROLL_RESULT_EE,'garnishments','net',...PUREPAY_PAYROLL_RESULT_ER,'funding_required'];

function purepayPayrollResultRefuse(): never
{
    throw new RuntimeException('The payroll result does not reconcile to its original source and figures. No result was saved.');
}

function purepayPayrollResultCents(mixed $amount): int
{
    if(!is_string($amount) || !preg_match('/^(0|[1-9][0-9]{0,7})\.[0-9]{2}$/D',$amount))purepayPayrollResultRefuse();
    return (int)str_replace('.','',$amount);
}

function purepayPayrollResultAmount(int $cents): string
{
    if($cents<0 || $cents>9999999999)purepayPayrollResultRefuse();
    return intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);
}

/** Integer arithmetic, including a bound before multiplication can overflow. */
function purepayPayrollResultComponent(array $component): int
{
    $source=$component['source'];$rate=$component['rate'];
    $regular=['regular_billable','regular_nonbillable','holiday','vacation','sick','bereavement'];
    if($rate['currency']!=='USD' || $rate['pay_rate_unit']!=='hour'
        || !in_array($source['category'],[...$regular,'OT_billable','OT_nonbillable'],true)
        || (in_array($source['category'],$regular,true) && $rate['multiplier']!=='1.00'))purepayPayrollResultRefuse();
    $values=[$source['hours'],$rate['pay_rate'],$rate['multiplier']];
    $patterns=['/^(0|[1-9][0-9]{0,3})\.[0-9]{2}$/D','/^(0|[1-9][0-9]{0,5})\.[0-9]{4}$/D','/^(0|[1-9][0-9]?)\.[0-9]{2}$/D'];
    $product=1;
    foreach($values as$i=>$value) {
        if(!is_string($value) || !preg_match($patterns[$i],$value))purepayPayrollResultRefuse();
        $integer=(int)str_replace('.','',$value);
        if($integer<=0 || $product>intdiv(PHP_INT_MAX-500000,$integer))purepayPayrollResultRefuse();
        $product*=$integer;
    }
    $cents=intdiv($product+500000,1000000); // hours(2) * rate(4) * multiplier(2) -> cents, half up
    purepayPayrollResultAmount($cents);
    return $cents;
}

function purepayPayrollResultDate(mixed $value): string
{
    if(!is_string($value) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$value))purepayPayrollResultRefuse();
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    if(!$date || $date->format('Y-m-d')!==$value)purepayPayrollResultRefuse();
    return $value;
}

/** Compare against the original encrypted Core packet, not today's mutable rates. */
function purepayPayrollResultValidate(array $data,array $authority,array $packet): array
{
    $receipt=purepayPayrollAuthorityReceipt($data['authority_receipt']??[],$authority);
    $allocation=$receipt['allocation']??null;
    if(!$allocation || !$receipt['payroll_created'] || ($data['contract_version']??null)!==PUREPAY_PAYROLL_RESULT_VERSION
        || ($data['organization_id']??null)!==$authority['organization_id'] || ($data['run_id']??null)!==$allocation['run_id']
        || ($data['input_id']??null)!==$authority['input_id'] || ($data['content_hash']??null)!==integrationDeliveryHash($packet)
        || $authority['content_hash']!==integrationDeliveryHash($packet) || ($data['period_start']??null)!==$packet['period_start']
        || ($data['period_end']??null)!==$packet['period_end'] || ($data['status']??null)!==$allocation['run_state']
        || ($data['archived']??null)!==$allocation['archived'] || !is_bool($data['execution_hold']??null)
        || ($receipt['state']==='released' && $data['execution_hold']!==true)
        || !in_array($data['calculation_mode']??null,['manual','native'],true) || ($data['currency']??null)!=='USD'
        || ($data['amount_basis']??null)!=='provider_payroll_ledger' || ($data['delivery_confirmation']??null)!=='not_evaluated'
        || !is_array($data['lines']??null) || !array_is_list($data['lines']) || count($data['lines'])<1 || count($data['lines'])>200) purepayPayrollResultRefuse();
    $payDate=purepayPayrollResultDate($data['pay_date']??null);
    if($payDate<$packet['period_end'])purepayPayrollResultRefuse();
    $expected=[];
    foreach($packet['components']as$c)$expected[$c['employee_id']][$c['source']['time_entry_id']]=$c;
    $lines=[];$totals=array_fill_keys(PUREPAY_PAYROLL_RESULT_AMOUNTS,0);$seen=[];
    foreach($data['lines']as$line) {
        if(!is_array($line) || !is_string($line['employee_id']??null) || !isset($expected[$line['employee_id']]) || isset($seen[$line['employee_id']])
            || !is_array($line['components']??null) || !array_is_list($line['components']) || !is_array($line['amounts']??null))purepayPayrollResultRefuse();
        $eid=$line['employee_id'];$seen[$eid]=true;$components=[];$componentSeen=[];$gross=0;
        foreach($line['components']as$c) {
            $original=$c['input_component']??null;$id=$original['source']['time_entry_id']??null;
            if(!is_int($id) || !isset($expected[$eid][$id]) || isset($componentSeen[$id])
                || integrationDeliveryHash($original)!==integrationDeliveryHash($expected[$eid][$id]))purepayPayrollResultRefuse();
            $componentSeen[$id]=true;$amount=purepayPayrollResultComponent($expected[$eid][$id]);
            if(purepayPayrollResultCents($c['gross']??null)!==$amount)purepayPayrollResultRefuse();
            $gross+=$amount;$components[]=['input_component'=>$expected[$eid][$id],'gross'=>purepayPayrollResultAmount($amount)];
        }
        if(count($componentSeen)!==count($expected[$eid]))purepayPayrollResultRefuse();
        $n=[];foreach(PUREPAY_PAYROLL_RESULT_AMOUNTS as$k)$n[$k]=purepayPayrollResultCents($line['amounts'][$k]??null);
        if($n['source_gross']!==$gross || $n['gross']!==$gross+$n['bonus']
            || $n['net']!==$n['gross']-array_sum(array_intersect_key($n,array_flip(PUREPAY_PAYROLL_RESULT_EE)))+$n['reimbursements']
            || $n['funding_required']!==$n['gross']+array_sum(array_intersect_key($n,array_flip(PUREPAY_PAYROLL_RESULT_ER)))+$n['reimbursements']
            || $n['garnishments']>$n['other_deductions'])purepayPayrollResultRefuse();
        $amounts=[];foreach($n as$k=>$v){$amounts[$k]=purepayPayrollResultAmount($v);$totals[$k]+=$v;}
        $lines[]=['employee_id'=>$eid,'components'=>$components,'amounts'=>$amounts];
    }
    if(count($seen)!==count($expected))purepayPayrollResultRefuse();
    foreach($totals as$k=>$v){if(purepayPayrollResultCents($data['totals'][$k]??null)!==$v)purepayPayrollResultRefuse();$totals[$k]=purepayPayrollResultAmount($v);}
    $out=['contract_version'=>PUREPAY_PAYROLL_RESULT_VERSION,'organization_id'=>$authority['organization_id'],'authority_receipt'=>$receipt,
        'run_id'=>$allocation['run_id'],'input_id'=>$authority['input_id'],'content_hash'=>$authority['content_hash'],
        'period_start'=>$packet['period_start'],'period_end'=>$packet['period_end'],'pay_date'=>$payDate,'status'=>$allocation['run_state'],
        'archived'=>$allocation['archived'],'execution_hold'=>$data['execution_hold'],'calculation_mode'=>$data['calculation_mode'],
        'currency'=>'USD','amount_basis'=>'provider_payroll_ledger','delivery_confirmation'=>'not_evaluated','lines'=>$lines,'totals'=>$totals];
    $hash=integrationDeliveryHash($out);$reported=$data['observed_at']??null;
    if(($data['result_hash']??null)!==$hash || !is_string($reported) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?\+00:00$/D',$reported))purepayPayrollResultRefuse();
    try {new DateTimeImmutable($reported);$errors=DateTimeImmutable::getLastErrors();if($errors && ($errors['warning_count'] || $errors['error_count']))purepayPayrollResultRefuse();} catch(Throwable $e){purepayPayrollResultRefuse();}
    // Whitelist comparison also rejects unknown fields instead of retaining PII.
    $out+=['result_hash'=>$hash,'observed_at'=>$reported];
    if(integrationDeliveryHash($data)!==integrationDeliveryHash($out))purepayPayrollResultRefuse();
    return $out;
}

function purepayPayrollResultAuthority(PDO $pdo,int $tenant,string $instance,array $owners,array $delivery,array $payload,string $id): array
{
    $row=purepayPayrollAuthorityRead($pdo,$tenant,$id);
    $original=json_decode((string)decryptField($row['payload_ct']),true,64,JSON_THROW_ON_ERROR);
    if($delivery['state']!=='delivered' || $delivery['remote_id']!==$row['input_id'] || $row['instance_id']!==$instance || (int)$row['input_delivery_id']!==(int)$delivery['id']
        || $original['owners']!==integrationDeliveryCanonical($owners) || integrationDeliveryHash($original['packet'])!==integrationDeliveryHash($payload['body'])
        || $row['organization_id']!==$payload['organization_id'])throw new RuntimeException('The result belongs to a different input or source owner.');
    return $row;
}

/** Each unique provider observation is immutable and encrypted. No claims/GL mutation. */
function purepayPayrollResultRefresh(PDO $pdo,int $tenant,int $actor,string $instance,array $owners,int $deliveryId,string $reviewHash,string $authorityId,?callable $audit=null): array
{
    if($actor<=0 || $pdo->inTransaction())throw new LogicException('Result review requires an authorized reviewer and its own transaction.');
    [$delivery,$payload]=purepayPayrollInputEnvelope($pdo,$tenant,$deliveryId,$reviewHash,$instance,$owners);
    $row=purepayPayrollResultAuthority($pdo,$tenant,$instance,$owners,$delivery,$payload,$authorityId);
    $access=purepayPayrollAuthorityAccess($pdo,$tenant,$instance,$row,false);
    $data=purepayPayrollResultValidate(purepayCoreGet($access['key'],'/payroll/authorities/'.$authorityId.'/result'),purepayPayrollAuthorityBody($row),$payload['body']);
    $pdo->beginTransaction();
    try {
        purepayCoreConnection($pdo,$tenant,true);purepayCoreLinkAccessUnchanged($pdo,$tenant,$access);
        $locked=purepayPayrollAuthorityRead($pdo,$tenant,$authorityId,true);
        if($locked['payload_ct']!==$row['payload_ct'])throw new RuntimeException('The original reservation changed during result review.');
        $s=$pdo->prepare('SELECT id FROM payroll_provider_results WHERE tenant_id=? AND authority_id=? AND result_hash=?');
        $s->execute([$tenant,$authorityId,$data['result_hash']]);$id=$s->fetchColumn();
        $replayed=$id!==false;
        if(!$replayed) {
            $pdo->prepare('INSERT INTO payroll_provider_results(tenant_id,authority_id,input_delivery_id,connection_id,result_hash,payload_ct,observed_by) VALUES(?,?,?,?,?,?,?)')
                ->execute([$tenant,$authorityId,$deliveryId,(int)$row['connection_id'],$data['result_hash'],encryptField(integrationDeliveryJson($data)),$actor]);
            $id=$pdo->lastInsertId();
            if($audit)$audit();
        }
        $pdo->commit();return ['id'=>(int)$id,'replayed'=>$replayed,'result'=>$data];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** Explicit page of authorities and saved observations; no implicit provider fetch. */
function purepayPayrollResultHistory(PDO $pdo,int $tenant,string $instance,array $owners,int $deliveryId,string $reviewHash,?int $before=null): array
{
    if($before!==null && $before<=0)throw new InvalidArgumentException('Invalid result page.');
    [$delivery,$payload]=purepayPayrollInputEnvelope($pdo,$tenant,$deliveryId,$reviewHash,$instance,$owners);
    $access=purepayCoreLinkAccess($pdo,$tenant,$instance,'person',false);
    if((int)$access['connection']['id']!==(int)$delivery['connection_ref'] || $access['review']['organization']['id']!==$payload['organization_id'])throw new RuntimeException('Restore the original provider connection.');
    $s=$pdo->prepare('SELECT id,state FROM payroll_source_authorities WHERE tenant_id=? AND input_delivery_id=? ORDER BY created_at,id LIMIT 51');
    $s->execute([$tenant,$deliveryId]);$authorities=$s->fetchAll(PDO::FETCH_ASSOC);
    foreach(array_slice($authorities,0,50)as$row)purepayPayrollResultAuthority($pdo,$tenant,$instance,$owners,$delivery,$payload,$row['id']);
    $sql='SELECT * FROM payroll_provider_results WHERE tenant_id=? AND input_delivery_id=?';$params=[$tenant,$deliveryId];
    if($before!==null){$sql.=' AND id<?';$params[]=$before;}
    $s=$pdo->prepare($sql.' ORDER BY id DESC LIMIT 26');$s->execute($params);$rows=$s->fetchAll(PDO::FETCH_ASSOC);$items=[];
    foreach(array_slice($rows,0,25)as$row) {
        $authority=purepayPayrollResultAuthority($pdo,$tenant,$instance,$owners,$delivery,$payload,$row['authority_id']);
        $data=purepayPayrollResultValidate(json_decode((string)decryptField($row['payload_ct']),true,64,JSON_THROW_ON_ERROR),purepayPayrollAuthorityBody($authority),$payload['body']);
        if($data['result_hash']!==$row['result_hash'] || (int)$row['connection_id']!==(int)$access['connection']['id'])purepayPayrollResultRefuse();
        $items[]=['id'=>(int)$row['id'],'saved_at'=>$row['created_at'],'result'=>$data];
    }
    purepayCoreLinkAccessUnchanged($pdo,$tenant,$access);
    return ['authorities'=>array_slice($authorities,0,50),'more_authorities'=>count($authorities)>50,'items'=>$items,'next_before'=>count($rows)>25?(int)$rows[24]['id']:null];
}

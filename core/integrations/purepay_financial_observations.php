<?php
/** Retain reviewed AP/AR source evidence; never mutate obligations or execution state. */
declare(strict_types=1);
require_once __DIR__.'/../purepay_service.php';
require_once __DIR__.'/purepay_financial_observation_contract.php';

function purepayFinancialObservationRequest(string $instance,string $kind,string $id,?int $coreId=null): void
{
    if(!preg_match('/^[a-f0-9-]{36}$/D',$instance) || !in_array($kind,['bill','invoice'],true)
        || !preg_match('/^[A-Za-z0-9_-]{1,255}$/D',$id) || ($coreId!==null && $coreId<=0))
        throw new InvalidArgumentException('Choose a provider bill or invoice and an optional Core record.');
}

function purepayFinancialObservationCore(PDO $pdo,int $tenant,string $kind,?int $id,bool $lock=false): ?array
{
    if($id===null)return null;
    $table=$kind==='bill'?'ap_bills':'billing_invoices';$number=$kind==='bill'?'bill_number':'invoice_number';
    $q=$pdo->prepare('SELECT id,tenant_id,'.$number.' AS number,currency,status,subtotal,tax_total,total,amount_paid,amount_due
        FROM '.$table.' WHERE tenant_id=? AND id=?'.($lock?' FOR UPDATE':''));
    $q->execute([$tenant,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException('The selected Core record is unavailable in this company.');
    $row['id']=(int)$row['id'];$row['tenant_id']=(int)$row['tenant_id'];
    foreach(['subtotal','tax_total','total','amount_paid','amount_due'] as $field)
        if(!purepayObservationDecimal($row[$field]))throw new RuntimeException('The Core record has unsupported amounts.');
    if(!is_string($row['currency']) || !preg_match('/^[A-Z]{3}$/D',$row['currency']))throw new RuntimeException('The Core record needs a currency review.');
    return $row;
}

function purepayFinancialObservationContext(int $tenant,string $instance,array $binding): array
{
    return ['tenant_id'=>$tenant,'instance_id'=>$instance,'connection_id'=>(int)$binding['connection_id'],
        'organization_id'=>$binding['remote_organization_id'],'api_base'=>$binding['remote_api_base']];
}

function purepayFinancialObservationCaptureHash(array $read): string
{
    return hash('sha256',purepayFinancialObservationJson([$read['context'],$read['observation']['observation_hash'],$read['comparison']]));
}

function purepayFinancialObservationRead(PDO $pdo,int $tenant,string $instance,string $kind,string $id,?int $coreId=null): array
{
    purepayFinancialObservationRequest($instance,$kind,$id,$coreId);
    if($pdo->inTransaction())throw new LogicException('Source read must precede the capture transaction.');
    $binding=purepayFinancialRequire($pdo,$tenant,null,true);
    $connection=purepayFinancialConnection($pdo,$tenant);
    $data=purepayFinancialObservationValidate(purepayCall(purepayFinancialKey($connection),'GET',
        '/financial-observations/'.($kind==='bill'?'bills':'invoices').'/'.rawurlencode($id)),$binding['remote_organization_id'],$kind,$id);
    $stamp=(new DateTimeImmutable($data['observed_at']))->getTimestamp();
    if($stamp<time()-300 || $stamp>time()+60)throw new RuntimeException('The source observation is stale. Refresh it.');
    $core=purepayFinancialObservationCore($pdo,$tenant,$kind,$coreId);
    if(purepayFinancialRequire($pdo,$tenant)['binding_hash']!==$binding['binding_hash'])throw new RuntimeException('The company connection changed. Review again.');
    $read=['tenant_id'=>$tenant,'organization'=>['id'=>$binding['remote_organization_id'],'name'=>$binding['remote_organization_name']],
        'context'=>purepayFinancialObservationContext($tenant,$instance,$binding),'observation'=>$data,
        'comparison'=>purepayFinancialObservationCompare($data,$core)];
    $read['capture_hash']=purepayFinancialObservationCaptureHash($read);
    $read['access_hash']=$binding['binding_hash'];
    $read['review_hash']=hash('sha256',$read['capture_hash'].$read['access_hash']);
    return $read;
}

function purepayFinancialObservationSaved(array $row,array $context): array
{
    $saved=json_decode((string)decryptField($row['payload_ct']),true,64,JSON_THROW_ON_ERROR);
    if(!is_array($saved) || purepayFinancialObservationJson($saved['context']??null)!==purepayFinancialObservationJson($context)
        || (int)$row['tenant_id']!==$context['tenant_id'] || (int)$row['connection_id']!==$context['connection_id']
        || $row['organization_id']!==$context['organization_id'])throw new RuntimeException('Saved evidence belongs to a different company or installation.');
    $data=purepayFinancialObservationValidate($saved['observation'],$context['organization_id'],$row['document_kind'],$row['document_id']);
    $core=$saved['comparison']['core_record']??null;
    if(($core!==null && ($core['tenant_id']??null)!==$context['tenant_id'])
        || purepayFinancialObservationJson(purepayFinancialObservationCompare($data,$core))!==purepayFinancialObservationJson($saved['comparison'])
        || !hash_equals($row['capture_hash'],purepayFinancialObservationCaptureHash($saved))
        || ($saved['capture_hash']??null)!==$row['capture_hash'])throw new RuntimeException('Saved financial evidence is inconsistent.');
    return $saved;
}

function purepayFinancialObservationSave(PDO $pdo,int $tenant,int $actor,string $instance,string $kind,string $id,?int $coreId,string $reviewHash,callable $audit): array
{
    if($actor<=0 || !preg_match('/^[a-f0-9]{64}$/D',$reviewHash))throw new InvalidArgumentException('Review and confirm the current financial evidence.');
    $read=purepayFinancialObservationRead($pdo,$tenant,$instance,$kind,$id,$coreId);
    if(!hash_equals($read['review_hash'],$reviewHash))throw new RuntimeException('Source figures, Core record or company connection changed. Review again before saving.');
    $pdo->beginTransaction();
    try {
        // Serializes same-company saves with key rotation. No network calls under locks.
        $binding=purepayFinancialRequire($pdo,$tenant);
        if($binding['binding_hash']!==$read['access_hash'] || purepayFinancialObservationJson(purepayFinancialObservationCore($pdo,$tenant,$kind,$coreId,true))
            !==purepayFinancialObservationJson($read['comparison']['core_record']))throw new RuntimeException('The reviewed local context changed. Review again.');
        $q=$pdo->prepare('SELECT * FROM purepay_financial_observations WHERE tenant_id=? AND connection_id=? AND capture_hash=?');
        $q->execute([$tenant,$binding['connection_id'],$read['capture_hash']]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if($row){$saved=purepayFinancialObservationSaved($row,$read['context']);$captureId=(int)$row['id'];}
        else {
            $payload=purepayFinancialObservationJson($read);
            if(strlen($payload)>8000000)throw new RuntimeException('The complete capture exceeds the storage limit.');
            $pdo->prepare('INSERT INTO purepay_financial_observations(tenant_id,connection_id,organization_id,document_kind,document_id,capture_hash,payload_ct,observed_by) VALUES(?,?,?,?,?,?,?,?)')
                ->execute([$tenant,$binding['connection_id'],$binding['remote_organization_id'],$kind,$id,$read['capture_hash'],encryptField($payload),$actor]);
            $captureId=(int)$pdo->lastInsertId();$saved=$read;
            $audit(['capture_id'=>$captureId,'kind'=>$kind,'document_id'=>$id,'capture_hash'=>$read['capture_hash']]);
        }
        $pdo->commit();return ['id'=>$captureId,'replayed'=>(bool)$row,'saved'=>$saved];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function purepayFinancialObservationHistory(PDO $pdo,int $tenant,string $instance,string $kind,string $id,?int $before=null): array
{
    purepayFinancialObservationRequest($instance,$kind,$id);
    if($before!==null && $before<=0)throw new InvalidArgumentException('Invalid financial history page.');
    $binding=purepayFinancialRequire($pdo,$tenant,null,true);$context=purepayFinancialObservationContext($tenant,$instance,$binding);
    $params=[$tenant,$binding['connection_id'],$kind,$id];if($before!==null)$params[]=$before;
    $q=$pdo->prepare('SELECT id FROM purepay_financial_observations WHERE tenant_id=? AND connection_id=? AND document_kind=? AND document_id=?'
        .($before!==null?' AND id<?':'').' ORDER BY id DESC LIMIT 2');$q->execute($params);$ids=$q->fetchAll(PDO::FETCH_COLUMN);$items=[];
    if($ids) {
        $q=$pdo->prepare('SELECT * FROM purepay_financial_observations WHERE tenant_id=? AND connection_id=? AND id=?');
        $q->execute([$tenant,$binding['connection_id'],$ids[0]]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new RuntimeException('History changed. Refresh it.');
        $items[]=['id'=>(int)$row['id'],'saved_at'=>$row['created_at'],'saved'=>purepayFinancialObservationSaved($row,$context)];
    }
    if(purepayFinancialRequire($pdo,$tenant)['binding_hash']!==$binding['binding_hash'])throw new RuntimeException('The company connection changed.');
    return ['tenant_id'=>$tenant,'organization'=>['id'=>$binding['remote_organization_id'],'name'=>$binding['remote_organization_name']],
        'kind'=>$kind,'document_id'=>$id,'items'=>$items,'next_before'=>count($ids)>1?(int)$ids[0]:null];
}

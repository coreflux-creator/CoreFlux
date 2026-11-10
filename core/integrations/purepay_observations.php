<?php
/** Compare and retain provider-operated payroll observations without initiating payroll. */
declare(strict_types=1);
require_once __DIR__.'/purepay_payroll_inputs.php';
require_once __DIR__.'/purepay_observation_contract.php';

function purepayObservationOwners(array $owners): array
{
    if(array_diff(array_keys($owners),['people','placements','time']))throw new InvalidArgumentException('Invalid payroll observation owners.');
    foreach(['people','placements','time'] as $module)
        if(!isset($owners[$module]) || !is_int($owners[$module]) || $owners[$module]<=0)throw new InvalidArgumentException('Authorized payroll observation owners are required.');
    return integrationDeliveryCanonical($owners);
}

function purepayObservationAccess(PDO $pdo,int $tenant,string $instance): array
{
    $access=purepayCoreLinkAccess($pdo,$tenant,$instance,'person',false);
    if(($access['review']['payroll_observation_contract_version']??null)!==PUREPAY_OBSERVATION_VERSION)
        throw new RuntimeException('This provider does not yet support the reviewed payroll observation contract.');
    return $access;
}

function purepayObservationCursor(?string $cursor): void
{
    if($cursor!==null && ($cursor==='' || strlen($cursor)>512 || !preg_match('/^[A-Za-z0-9_+=\/-]+$/D',$cursor)))
        throw new InvalidArgumentException('Invalid payroll observation page.');
}

/** One explicit page. A rejected oversized page must never be presented as complete. */
function purepayObservationPage(PDO $pdo,int $tenant,string $instance,int $limit=25,?string $cursor=null): array
{
    if($limit<1 || $limit>100)throw new InvalidArgumentException('Choose a payroll page size from 1 to 100.');
    purepayObservationCursor($cursor);
    $access=purepayObservationAccess($pdo,$tenant,$instance);
    $org=$access['review']['organization'];
    $page=purepayCoreGet($access['key'],'/payroll/observations?'.http_build_query(array_filter(
        ['limit'=>$limit,'cursor'=>$cursor],static fn($v)=>$v!==null)));
    purepayCoreLinkAccessUnchanged($pdo,$tenant,$access);
    purepayObservationKeys($page,['contract_version','organization_id','items','next_cursor','consistency']);
    if($page['contract_version']!==PUREPAY_OBSERVATION_VERSION || $page['organization_id']!==$org['id']
        || $page['consistency']!==PUREPAY_OBSERVATION_CONSISTENCY || !is_array($page['items'])
        || !array_is_list($page['items']) || count($page['items'])>$limit
        || ($page['next_cursor']!==null && !is_string($page['next_cursor'])))purepayObservationRefuse();
    purepayObservationCursor($page['next_cursor']);
    if($cursor!==null && $page['next_cursor']===$cursor)purepayObservationRefuse();
    $ids=[];$lineCount=0;$sourceCount=0;
    foreach($page['items'] as $item) {
        if(!is_array($item))purepayObservationRefuse();
        purepayObservationValidate($item,$org['id']);
        if(isset($ids[$item['run_id']]))purepayObservationRefuse();
        $ids[$item['run_id']]=true;$lineCount+=count($item['lines']);
        foreach($item['lines'] as $line)$sourceCount+=purepayObservationReferenceCount($line['source_time_lineage']);
    }
    if($lineCount>5000 || $sourceCount>10000)purepayObservationRefuse();
    return $page+['tenant_id'=>$tenant,'organization'=>$org];
}

/** Only completed local identity receipts can annotate a native employee. No name matching. */
function purepayObservationPeople(PDO $pdo,int $tenant,string $instance,array $owners,array $access,array $data): array
{
    $counts=[];
    foreach($data['lines'] as $line)if($line['employee_id']!==null)
        $counts[$line['employee_id']]=($counts[$line['employee_id']]??0)+1;
    ksort($counts,SORT_STRING);$candidates=[];
    foreach(array_chunk(array_keys($counts),200) as $chunk) {
        $sql='SELECT * FROM integration_deliveries WHERE tenant_id=? AND provider="purepay" AND connection_ref=?
            AND entity_type="person" AND entity_tenant_id=? AND operation="record.link" AND state="delivered"
            AND remote_id IN ('.implode(',',array_fill(0,count($chunk),'?')).') ORDER BY id LIMIT 401';
        $s=$pdo->prepare($sql);$s->execute([$tenant,(string)$access['connection']['id'],$owners['people'],...$chunk]);
        $rows=$s->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)>400)throw new RuntimeException('Employee links are ambiguous. Reconcile them before saving this observation.');
        foreach($rows as $row)$candidates[$row['remote_id']][]=$row;
    }
    $items=[];$proof=[];
    foreach($counts as $employee=>$count) {
        $item=['employee_id'=>(string)$employee,'person'=>null,'link_status'=>'unlinked','source_line_count'=>$count];
        $matches=$candidates[$employee]??[];
        if(count($matches)>1)$item['link_status']='ambiguous';
        elseif(count($matches)===1) {
            $row=$matches[0];
            try {
                $payload=json_decode((string)decryptField($row['payload_ct']),true,64,JSON_THROW_ON_ERROR);
                $reference=$payload['body']['reference']??null;
                if(!is_array($reference) || ($payload['organization_id']??null)!==$access['review']['organization']['id']
                    || ($reference['instance_id']??null)!==$instance || ($reference['directory_tenant_id']??null)!==$owners['people']
                    || ($reference['entity_type']??null)!=='person' || !is_string($reference['number']??null))throw new RuntimeException('Invalid link');
                $source=purepayCoreLinkSource($pdo,$instance,$owners['people'],'person',$reference['number']);
                $link=purepayPayrollInputLink($pdo,$tenant,$access['connection'],$source,$access['review']['organization']['id']);
                if($link['provider_id']!==(string)$employee)throw new RuntimeException('Changed link');
                $item['person']=$source['reference'];$item['link_status']='linked';
                $proof[]=['employee_id'=>(string)$employee,'source_hash'=>integrationDeliveryHash($source),'link'=>$link];
            } catch(RuntimeException|InvalidArgumentException|JsonException $e) {$item['link_status']='requires_review';}
        }
        $items[]=$item;
    }
    return ['items'=>$items,'source_hash'=>integrationDeliveryHash([$items,$proof])];
}

function purepayObservationRead(PDO $pdo,int $tenant,string $instance,array $owners,string $runId): array
{
    $owners=purepayObservationOwners($owners);
    if(!purepayObservationToken($runId,true))throw new InvalidArgumentException('Choose an existing provider payroll run.');
    $access=purepayObservationAccess($pdo,$tenant,$instance);
    $data=purepayObservationValidate(purepayCoreGet($access['key'],'/payroll/observations/'.$runId),
        $access['review']['organization']['id'],$runId);
    $stamp=(new DateTimeImmutable($data['observed_at']))->getTimestamp();
    if($stamp<time()-300 || $stamp>time()+60)throw new RuntimeException('The provider observation is stale. Refresh it before continuing.');
    if($pdo->inTransaction())throw new LogicException('Payroll observation requires its own local read.');
    $pdo->beginTransaction();
    try {$people=purepayObservationPeople($pdo,$tenant,$instance,$owners,$access,$data);$pdo->commit();}
    catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    purepayCoreLinkAccessUnchanged($pdo,$tenant,$access);
    $context=['tenant_id'=>$tenant,'instance_id'=>$instance,'owners'=>$owners,
        'connection_id'=>(int)$access['connection']['id'],'organization_id'=>$access['review']['organization']['id'],
        'api_base'=>purepayCoreApiBase()];
    return ['tenant_id'=>$tenant,'organization'=>$access['review']['organization'],'observation'=>$data,
        'comparison'=>['totals'=>purepayObservationTotals($data),'people'=>$people['items'],
            'source_time_match'=>'not_evaluated','cash_delivery'=>'not_evaluated','ledger_posted'=>false],
        'context'=>$context,'source_hash'=>$people['source_hash'],
        'access_version'=>['revision'=>(int)$access['connection']['revision'],'review_hash'=>$access['review']['review_hash']],
        'review_hash'=>integrationDeliveryHash([$context,$access['review']['review_hash'],$data['observation_hash'],$people['source_hash']])];
}

/** Explicit reviewed capture. The only writes are encrypted observation and its audit entry. */
function purepayObservationSave(PDO $pdo,int $tenant,int $actor,string $instance,array $owners,string $runId,string $reviewHash,?callable $audit=null): array
{
    if($actor<=0 || !preg_match('/^[a-f0-9]{64}$/D',$reviewHash) || $pdo->inTransaction())throw new InvalidArgumentException('A current payroll observation review is required.');
    $read=purepayObservationRead($pdo,$tenant,$instance,$owners,$runId);
    if(!hash_equals($read['review_hash'],$reviewHash))throw new RuntimeException('The payroll observation, Core link, or connection changed. Review it again before saving.');
    $serialized=integrationDeliveryJson($read);
    if(strlen($serialized)>8*1024*1024)throw new RuntimeException('This payroll observation exceeds Core\'s capture size limit. No partial capture was saved.');
    $pdo->beginTransaction();
    try {
        $connection=purepayCoreConnection($pdo,$tenant,true);
        if(!$connection || $connection['status']!=='active' || (int)$connection['id']!==$read['context']['connection_id']
            || (int)$connection['revision']!==$read['access_version']['revision']
            || $connection['binding_hash']!==$read['access_version']['review_hash']
            || $connection['remote_organization_id']!==$read['context']['organization_id']
            || $connection['core_instance_id']!==$instance || $connection['remote_api_base']!==$read['context']['api_base'])
            throw new RuntimeException('The provider binding changed. Review it again before saving.');
        $s=$pdo->prepare('SELECT * FROM payroll_native_observations WHERE tenant_id=? AND connection_id=? AND run_id=? AND observation_hash=? AND core_source_hash=?');
        $s->execute([$tenant,$connection['id'],$runId,$read['observation']['observation_hash'],$read['source_hash']]);
        $existing=$s->fetch(PDO::FETCH_ASSOC);
        if($existing) {
            $saved=purepayObservationSaved($existing,$read['context']);
            $id=(int)$existing['id'];
        } else {
            $pdo->prepare('INSERT INTO payroll_native_observations(tenant_id,connection_id,organization_id,run_id,observation_hash,core_source_hash,payload_ct,observed_by) VALUES(?,?,?,?,?,?,?,?)')
                ->execute([$tenant,$connection['id'],$read['context']['organization_id'],$runId,$read['observation']['observation_hash'],
                    $read['source_hash'],encryptField($serialized),$actor]);
            $id=(int)$pdo->lastInsertId();$saved=$read;
            if($audit)$audit();
        }
        $pdo->commit();return ['id'=>$id,'replayed'=>(bool)$existing,'saved'=>$saved];
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function purepayObservationSaved(array $row,array $context): array
{
    $saved=json_decode((string)decryptField($row['payload_ct']),true,64,JSON_THROW_ON_ERROR);
    if(!is_array($saved) || !is_array($saved['observation']??null)
        || integrationDeliveryHash($saved['context']??null)!==integrationDeliveryHash($context) || (int)$row['tenant_id']!==$context['tenant_id']
        || (int)$row['connection_id']!==$context['connection_id'] || $row['organization_id']!==$context['organization_id'])
        throw new RuntimeException('This saved observation belongs to another provider or directory context.');
    $data=purepayObservationValidate($saved['observation']??[],$context['organization_id'],$row['run_id']);
    if($row['observation_hash']!==$data['observation_hash'] || ($saved['source_hash']??null)!==$row['core_source_hash']
        || integrationDeliveryHash($saved['comparison']['totals']??null)!==integrationDeliveryHash(purepayObservationTotals($data))
        || ($saved['comparison']['source_time_match']??null)!=='not_evaluated'
        || ($saved['comparison']['cash_delivery']??null)!=='not_evaluated' || ($saved['comparison']['ledger_posted']??null)!==false)
        throw new RuntimeException('Saved payroll observation evidence is inconsistent.');
    return $saved;
}

function purepayObservationHistory(PDO $pdo,int $tenant,string $instance,array $owners,string $runId,?int $before=null): array
{
    $owners=purepayObservationOwners($owners);
    if(!purepayObservationToken($runId,true) || ($before!==null && $before<=0))throw new InvalidArgumentException('Choose a payroll observation history page.');
    $access=purepayObservationAccess($pdo,$tenant,$instance);
    $context=['tenant_id'=>$tenant,'instance_id'=>$instance,'owners'=>$owners,'connection_id'=>(int)$access['connection']['id'],
        'organization_id'=>$access['review']['organization']['id'],'api_base'=>purepayCoreApiBase()];
    // Captures can be large. Page identities first so even the look-ahead row
    // does not load another encrypted payroll into memory.
    $s=$pdo->prepare('SELECT id FROM payroll_native_observations WHERE tenant_id=? AND connection_id=? AND run_id=?'
        .($before===null?'':' AND id<?').' ORDER BY id DESC LIMIT 2');
    $params=[$tenant,$access['connection']['id'],$runId];if($before!==null)$params[]=$before;
    $s->execute($params);$ids=$s->fetchAll(PDO::FETCH_COLUMN);$items=[];
    if($ids) {
        $s=$pdo->prepare('SELECT * FROM payroll_native_observations WHERE tenant_id=? AND connection_id=? AND run_id=? AND id=?');
        $s->execute([$tenant,$access['connection']['id'],$runId,$ids[0]]);$row=$s->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new RuntimeException('The saved observation changed. Refresh its history.');
        $items[]=['id'=>(int)$row['id'],'saved_at'=>$row['created_at'],'saved'=>purepayObservationSaved($row,$context)];
    }
    purepayCoreLinkAccessUnchanged($pdo,$tenant,$access);
    return ['tenant_id'=>$tenant,'organization'=>$access['review']['organization'],'run_id'=>$runId,
        'items'=>$items,'next_before'=>count($ids)>1?(int)$ids[0]:null];
}

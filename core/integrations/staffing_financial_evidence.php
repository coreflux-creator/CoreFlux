<?php
/** Reviewed workbook support for an exact historical invoice-line association. */
declare(strict_types=1);
require_once __DIR__.'/staffing_workbook.php';
require_once __DIR__.'/purepay_financial_line_links.php';
require_once __DIR__.'/delivery.php';

function staffingEvidenceContext(int $tenant,string $instance,array $binding,array $owners): array
{
    purepayFinancialLineOwners($owners);
    return purepayFinancialObservationContext($tenant,$instance,$binding)+['owners'=>integrationDeliveryCanonical($owners)];
}

function staffingEvidenceAssociation(PDO $pdo,array $context,int $id,bool $lock=false): array
{
    if($id<=0)throw new InvalidArgumentException('Choose a reviewed invoice-line association.');
    $q=$pdo->prepare('SELECT * FROM purepay_financial_line_links WHERE tenant_id=? AND connection_id=? AND id=?'.($lock?' FOR UPDATE':''));
    $q->execute([$context['tenant_id'],$context['connection_id'],$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new PurePayFinancialLineReviewException('The reviewed association is unavailable in this company.');
    $financialContext=array_diff_key($context,['owners'=>true]);
    $saved=purepayFinancialLineSaved($row,$financialContext,$context['owners']);$link=$saved['saved'];
    if($link['source']['kind']!=='invoice')throw new PurePayFinancialLineReviewException('This workbook billing review applies to an invoice line.');
    $target=purepayFinancialLineTarget($pdo,$context['instance_id'],$context['owners'],$link['target']['placement']['number'],$lock);
    foreach(['placement','person']as$key)if(integrationDeliveryHash($target[$key])!==integrationDeliveryHash($link['target'][$key]))
        throw new PurePayFinancialLineReviewException('The placement identity differs from this historical association.');
    $capture=purepayFinancialLineCapture($pdo,$context['tenant_id'],$financialContext,$link['capture_id'],$lock);
    $source=purepayFinancialLineSource($capture,$link['source']['line_id']);
    if(integrationDeliveryHash($source)!==integrationDeliveryHash($link['source']))throw new PurePayFinancialLineReviewException('The saved source line changed.');
    return['id'=>$id,'association_hash'=>$row['link_hash'],'subject_hash'=>$row['subject_hash'],'capture_id'=>$link['capture_id'],
        'source'=>$source,'target'=>$target];
}

function staffingEvidenceSource(PDO $pdo,int $tenant,int $id,string $hash): array
{
    $q=$pdo->prepare('SELECT * FROM staffing_workbook_sources WHERE tenant_id=? AND id=?');$q->execute([$tenant,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row||!hash_equals($hash,$row['file_sha256']))throw new RuntimeException('The retained workbook source is unavailable.');
    $bytes=decryptField($row['content_ct']);
    if(!is_string($bytes)||strlen($bytes)!==(int)$row['file_bytes']||strlen($bytes)>STAFFING_WORKBOOK_MAX_BYTES||!hash_equals($hash,hash('sha256',$bytes)))throw new RuntimeException('The retained workbook bytes differ.');
    return['id'=>$id,'file_name'=>staffingWorkbookFileName($row['file_name']),'bytes'=>$bytes];
}

function staffingEvidenceSaved(PDO $pdo,array $row,array $context): array
{
    $saved=json_decode((string)decryptField($row['payload_ct']),true,64,JSON_THROW_ON_ERROR);
    if(!is_array($saved)||($saved['contract_version']??null)!==STAFFING_WORKBOOK_VERSION
        ||integrationDeliveryHash($saved['context']??null)!==integrationDeliveryHash($context)
        ||(int)$row['tenant_id']!==$context['tenant_id']||(int)$row['connection_id']!==$context['connection_id']
        ||(int)$row['association_id']!==($saved['association_id']??null)||$row['action']!==($saved['action']??null)
        ||$row['request_id']!==($saved['request_id']??null)||$row['request_hash']!==($saved['request_hash']??null)
        ||(int)$row['reviewed_by']!==($saved['reviewed_by']??null)
        ||($row['previous_id']===null?null:(int)$row['previous_id'])!==($saved['previous_id']??null)
        ||($row['source_id']===null?null:(int)$row['source_id'])!==($saved['source_id']??null)
        ||!hash_equals($row['evidence_hash'],integrationDeliveryHash($saved)))throw new RuntimeException('Saved workbook review evidence is inconsistent.');
    if($saved['action']==='record'){
        if(!is_array($saved['workbook']??null)||!is_int($saved['source_id'])||$saved['source_id']<=0)throw new RuntimeException('Saved workbook review has no retained source.');
        $workbook=$saved['workbook'];staffingEvidenceSource($pdo,$context['tenant_id'],$saved['source_id'],$workbook['file_sha256']);
        foreach(['original_client_approval_proven','provider_dates_changed','time_entries_matched','cash_delivery_proven','blank_means_zero']as$flag)
            if(($workbook[$flag]??null)!==false)throw new RuntimeException('Workbook review contains unsupported authority claims.');
    }elseif($saved['action']!=='withdraw'||$saved['source_id']!==null||$saved['workbook']!==null||trim($saved['reason'])==='')throw new RuntimeException('Saved withdrawal is inconsistent.');
    return['id'=>(int)$row['id'],'reviewed_at'=>$row['created_at'],'saved'=>$saved];
}

function staffingEvidenceLatest(PDO $pdo,array $context,int $associationId,bool $lock=false): ?array
{
    $q=$pdo->prepare('SELECT * FROM staffing_financial_evidence WHERE tenant_id=? AND connection_id=? AND association_id=? ORDER BY id DESC LIMIT 1'.($lock?' FOR UPDATE':''));
    $q->execute([$context['tenant_id'],$context['connection_id'],$associationId]);$row=$q->fetch(PDO::FETCH_ASSOC);
    return$row?staffingEvidenceSaved($pdo,$row,$context):null;
}

function staffingEvidenceInput(string $action,?string $bytes,?string $name,?array $selection,string $reason): ?array
{
    if(!in_array($action,['record','withdraw'],true)||strlen($reason)>2000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$reason))throw new InvalidArgumentException('Choose a workbook review or withdrawal and a short explanation.');
    if($action==='record'){
        if($bytes===null||$name===null||$selection===null)throw new InvalidArgumentException('Choose the workbook and its source cells.');
        return staffingWorkbookRead($bytes,$name,$selection);
    }
    if($bytes!==null||$name!==null||$selection!==null||trim($reason)==='')throw new InvalidArgumentException('Explain the withdrawal without submitting a replacement workbook.');
    return null;
}

function staffingEvidencePlan(PDO $pdo,int $tenant,string $instance,array $owners,int $associationId,string $action,?array $workbook,string $reason): array
{
    if($pdo->inTransaction())throw new LogicException('Workbook review requires a fresh read outside database locks.');
    $binding=purepayFinancialRequire($pdo,$tenant,null,true);$context=staffingEvidenceContext($tenant,$instance,$binding,$owners);
    $association=staffingEvidenceAssociation($pdo,$context,$associationId);
    $q=$pdo->prepare('SELECT MAX(id) FROM purepay_financial_line_links WHERE tenant_id=? AND connection_id=? AND subject_hash=?');
    $q->execute([$tenant,$context['connection_id'],$association['subject_hash']]);
    if((int)$q->fetchColumn()!==$associationId&&$action==='record')throw new PurePayFinancialLineReviewException('This association has been superseded. Review the current source line first.');
    // Withdrawing old support does not require an unchanged external document.
    if($action==='record'){
        $fresh=purepayFinancialObservationRead($pdo,$tenant,$instance,'invoice',$association['source']['document_id']);
        if(integrationDeliveryHash($fresh['context'])!==integrationDeliveryHash(array_diff_key($context,['owners'=>true]))
            ||$fresh['observation']['observation_hash']!==$association['source']['observation_hash'])throw new PurePayFinancialLineReviewException('The provider document changed. Save and associate its current capture first.');
    }
    $prior=staffingEvidenceLatest($pdo,$context,$associationId);
    if($action==='withdraw'&&($prior['saved']['action']??null)!=='record')throw new PurePayFinancialLineReviewException('There is no active workbook review to withdraw.');
    $current=purepayFinancialRequire($pdo,$tenant);if($current['binding_hash']!==$binding['binding_hash'])throw new PurePayFinancialLineReviewException('The financial company connection changed.');
    $requestHash=integrationDeliveryHash([STAFFING_WORKBOOK_VERSION,$context,$associationId,$action,$workbook,$reason]);
    $reviewHash=integrationDeliveryHash([$requestHash,$binding['binding_hash'],$association,$prior['id']??null]);
    return['tenant_id'=>$tenant,'context'=>$context,'association'=>$association,'action'=>$action,'workbook'=>$workbook,'reason'=>$reason,
        'previous'=>$prior,'request_hash'=>$requestHash,'review_hash'=>$reviewHash,'access_hash'=>$binding['binding_hash']];
}

function staffingEvidencePreview(PDO $pdo,int $tenant,string $instance,array $owners,int $associationId,string $action,?string $bytes,?string $name,?array $selection,string $reason): array
{
    $workbook=staffingEvidenceInput($action,$bytes,$name,$selection,$reason);
    $plan=staffingEvidencePlan($pdo,$tenant,$instance,$owners,$associationId,$action,$workbook,$reason);
    unset($plan['context'],$plan['access_hash'],$plan['request_hash']);return$plan;
}

function staffingEvidenceSave(PDO $pdo,int $tenant,int $actor,string $instance,array $owners,int $associationId,string $action,?string $bytes,?string $name,?array $selection,string $reason,string $requestId,string $reviewHash,callable $audit): array
{
    if($actor<=0||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$requestId)||!preg_match('/^[a-f0-9]{64}$/D',$reviewHash))throw new InvalidArgumentException('Confirm the current workbook review before saving.');
    if($pdo->inTransaction())throw new LogicException('Workbook save requires its own transaction.');
    $workbook=staffingEvidenceInput($action,$bytes,$name,$selection,$reason);
    $binding=purepayFinancialRequire($pdo,$tenant);$context=staffingEvidenceContext($tenant,$instance,$binding,$owners);
    $requestHash=integrationDeliveryHash([STAFFING_WORKBOOK_VERSION,$context,$associationId,$action,$workbook,$reason]);
    $q=$pdo->prepare('SELECT * FROM staffing_financial_evidence WHERE tenant_id=? AND request_id=?');$q->execute([$tenant,$requestId]);$existing=$q->fetch(PDO::FETCH_ASSOC);
    if($existing){
        if(!hash_equals($existing['request_hash'],$requestHash))throw new PurePayFinancialLineReviewException('This request already saved different evidence. Start a new review.');
        $receipt=staffingEvidenceSaved($pdo,$existing,$context);$current=staffingEvidenceLatest($pdo,$context,$associationId);
        return['tenant_id'=>$tenant,'replayed'=>true,'receipt'=>$receipt,'is_current'=>$current['id']===$receipt['id'],'current_action'=>$current['saved']['action']];
    }
    $plan=staffingEvidencePlan($pdo,$tenant,$instance,$owners,$associationId,$action,$workbook,$reason);
    if(!hash_equals($plan['review_hash'],$reviewHash))throw new PurePayFinancialLineReviewException('The source, association or review history changed. Review again.');
    $pdo->beginTransaction();
    try{
        $binding=purepayFinancialRequire($pdo,$tenant);$context=staffingEvidenceContext($tenant,$instance,$binding,$owners);
        $association=staffingEvidenceAssociation($pdo,$context,$associationId,true);$prior=staffingEvidenceLatest($pdo,$context,$associationId,true);
        $q=$pdo->prepare('SELECT MAX(id) FROM purepay_financial_line_links WHERE tenant_id=? AND connection_id=? AND subject_hash=?');$q->execute([$tenant,$context['connection_id'],$association['subject_hash']]);
        if($binding['binding_hash']!==$plan['access_hash']||integrationDeliveryHash($association)!==integrationDeliveryHash($plan['association'])
            ||($prior['id']??null)!==($plan['previous']['id']??null)||((int)$q->fetchColumn()!==$associationId&&$action==='record'))throw new PurePayFinancialLineReviewException('The reviewed evidence changed before saving. Review again.');
        $sourceId=null;
        if($workbook!==null){
            $q=$pdo->prepare('SELECT id FROM staffing_workbook_sources WHERE tenant_id=? AND file_sha256=? FOR UPDATE');$q->execute([$tenant,$workbook['file_sha256']]);$sourceId=$q->fetchColumn();
            if($sourceId!==false){$sourceId=(int)$sourceId;staffingEvidenceSource($pdo,$tenant,$sourceId,$workbook['file_sha256']);}
            else{
                $pdo->prepare('INSERT INTO staffing_workbook_sources(tenant_id,file_sha256,file_name,file_bytes,content_ct,uploaded_by) VALUES(?,?,?,?,?,?)')
                    ->execute([$tenant,$workbook['file_sha256'],$name,strlen($bytes),encryptField($bytes),$actor]);$sourceId=(int)$pdo->lastInsertId();
            }
        }
        $saved=['contract_version'=>STAFFING_WORKBOOK_VERSION,'context'=>$context,'association_id'=>$associationId,'association_hash'=>$association['association_hash'],
            'capture_id'=>$association['capture_id'],'placement'=>$association['target']['placement'],'person'=>$association['target']['person'],
            'action'=>$action,'source_id'=>$sourceId,'workbook'=>$workbook,'reason'=>$reason,'previous_id'=>$prior['id']??null,
            'request_id'=>$requestId,'request_hash'=>$plan['request_hash'],'reviewed_by'=>$actor];
        $json=integrationDeliveryJson($saved);if(strlen($json)>200000)throw new RuntimeException('The workbook evidence exceeds the review size limit.');
        $pdo->prepare('INSERT INTO staffing_financial_evidence(tenant_id,connection_id,association_id,previous_id,source_id,action,request_id,request_hash,evidence_hash,payload_ct,reviewed_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$tenant,$context['connection_id'],$associationId,$prior['id']??null,$sourceId,$action,$requestId,$plan['request_hash'],integrationDeliveryHash($saved),encryptField($json),$actor]);
        $id=(int)$pdo->lastInsertId();$audit(['evidence_id'=>$id,'association_id'=>$associationId,'action'=>$action,'source_sha256'=>$workbook['file_sha256']??null]);
        $q=$pdo->prepare('SELECT * FROM staffing_financial_evidence WHERE tenant_id=? AND id=?');$q->execute([$tenant,$id]);$receipt=staffingEvidenceSaved($pdo,$q->fetch(PDO::FETCH_ASSOC),$context);
        $pdo->commit();return['tenant_id'=>$tenant,'replayed'=>false,'receipt'=>$receipt,'is_current'=>true,'current_action'=>$action];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
}

function staffingEvidenceHistory(PDO $pdo,int $tenant,string $instance,array $owners,int $associationId,?int $before=null): array
{
    if($before!==null&&$before<=0)throw new InvalidArgumentException('Choose a valid review history page.');
    $binding=purepayFinancialRequire($pdo,$tenant);$context=staffingEvidenceContext($tenant,$instance,$binding,$owners);
    staffingEvidenceAssociation($pdo,$context,$associationId);$latest=staffingEvidenceLatest($pdo,$context,$associationId);
    $q=$pdo->prepare('SELECT id FROM staffing_financial_evidence WHERE tenant_id=? AND connection_id=? AND association_id=?'.($before===null?'':' AND id<?').' ORDER BY id DESC LIMIT 6');
    $params=[$tenant,$context['connection_id'],$associationId];if($before!==null)$params[]=$before;$q->execute($params);$ids=$q->fetchAll(PDO::FETCH_COLUMN);$more=count($ids)>5;$ids=array_slice($ids,0,5);$items=[];
    foreach($ids as$id){$q=$pdo->prepare('SELECT * FROM staffing_financial_evidence WHERE tenant_id=? AND id=?');$q->execute([$tenant,$id]);$items[]=staffingEvidenceSaved($pdo,$q->fetch(PDO::FETCH_ASSOC),$context);}
    if(purepayFinancialRequire($pdo,$tenant)['binding_hash']!==$binding['binding_hash'])throw new PurePayFinancialLineReviewException('The company connection changed.');
    return['tenant_id'=>$tenant,'association_id'=>$associationId,'current_id'=>$latest['id']??null,'current_action'=>$latest['saved']['action']??null,
        'items'=>$items,'next_before'=>$more?(int)end($ids):null];
}

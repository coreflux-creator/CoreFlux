<?php
/** Human-reviewed source-line attribution. No future-item mapping, billing, time or financial execution. */
declare(strict_types=1);
require_once __DIR__.'/purepay_financial_observations.php';
require_once __DIR__.'/identity.php';

final class PurePayFinancialLineReviewException extends RuntimeException {}

function purepayFinancialLineOwners(array $owners): void
{
    if(count($owners)!==2)throw new InvalidArgumentException('Authorized source directories are required.');
    foreach(['people','placements']as$key)if(!is_int($owners[$key]??null)||$owners[$key]<=0)
        throw new InvalidArgumentException('Authorized source directories are required.');
}

function purepayFinancialLineTarget(PDO $pdo,string $instance,array $owners,string $number,bool $lock=false): array
{
    purepayFinancialLineOwners($owners);
    $reference=integrationRecordReference($instance,$owners['placements'],'placement',$number);
    $q=$pdo->prepare('SELECT p.id,p.person_id,p.title,p.status,p.start_date,COALESCE(p.actual_end_date,p.end_date) AS end_date,
        pn.record_number AS person_number,pe.first_name,pe.last_name
        FROM placements p JOIN core_record_numbers n ON n.tenant_id=p.tenant_id AND n.entity_type="placement"
            AND n.record_id=p.id AND n.canonical_record_id=p.id
        JOIN people pe ON pe.id=p.person_id AND pe.tenant_id=? AND pe.deleted_at IS NULL
        JOIN core_record_numbers pn ON pn.tenant_id=pe.tenant_id AND pn.entity_type="person"
            AND pn.record_id=pe.id AND pn.canonical_record_id=pe.id
        WHERE p.tenant_id=? AND n.record_number=? AND p.deleted_at IS NULL LIMIT 2'.($lock?' FOR UPDATE':''));
    $q->execute([$owners['people'],$owners['placements'],$number]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)!==1)throw new PurePayFinancialLineReviewException('The placement or person is unavailable in the authorized directories.');
    $r=$rows[0];
    return ['placement'=>$reference,'placement_id'=>(int)$r['id'],
        'person'=>integrationRecordReference($instance,$owners['people'],'person',$r['person_number']),
        'person_id'=>(int)$r['person_id'],'person_name'=>trim($r['first_name'].' '.$r['last_name']),
        'title'=>$r['title'],'status'=>$r['status'],'start_date'=>$r['start_date'],'end_date'=>$r['end_date']];
}

function purepayFinancialLineCapture(PDO $pdo,int $tenant,array $context,int $id,bool $lock=false): array
{
    if($id<=0)throw new InvalidArgumentException('Choose a saved financial capture.');
    $q=$pdo->prepare('SELECT * FROM purepay_financial_observations WHERE tenant_id=? AND connection_id=? AND id=?'.($lock?' FOR UPDATE':''));
    $q->execute([$tenant,$context['connection_id'],$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new PurePayFinancialLineReviewException('The financial capture is unavailable in this company.');
    return purepayFinancialObservationSaved($row,$context);
}

function purepayFinancialLineSource(array $saved,string $lineId): array
{
    if($lineId===''||strlen($lineId)>255||preg_match('/[\x00-\x1f\x7f]/',$lineId))throw new InvalidArgumentException('Choose an exact source line ID.');
    $data=$saved['observation'];
    $matches=array_values(array_filter($data['document']['line_items'],static fn($line)=>$line['line_id']===$lineId));
    if(count($matches)!==1)throw new PurePayFinancialLineReviewException('This capture has no unique line with that source ID.');
    return ['kind'=>$data['kind'],'document_id'=>$data['document_id'],'line_id'=>$lineId,
        'document_currency'=>$data['document']['currency'],'customer_id'=>$data['document']['customer_id'],
        'vendor_id'=>$data['document']['vendor_id'],'observation_hash'=>$data['observation_hash'],
        'observed_at'=>$data['observed_at'],'line'=>$matches[0]];
}

function purepayFinancialLineSubject(array $context,array $source): string
{
    return hash('sha256',purepayFinancialObservationJson([$context,$source['kind'],$source['document_id'],$source['line_id']]));
}

function purepayFinancialLineHash(array $link): string
{
    $source=$link['source'];unset($source['observed_at']);
    return hash('sha256',purepayFinancialObservationJson([$link['context'],$link['owners'],$link['capture_id'],$source,$link['target']]));
}

function purepayFinancialLineSaved(array $row,array $context,array $owners): array
{
    $saved=json_decode((string)decryptField($row['payload_ct']),true,64,JSON_THROW_ON_ERROR);
    foreach(['future_item_rule','time_entries_matched','currency_verified','ledger_posted','provider_changed']as$flag)
        if(($saved[$flag]??null)!==false)throw new PurePayFinancialLineReviewException('The saved association contains unsupported authority claims.');
    if(purepayFinancialObservationJson($saved['context']??null)!==purepayFinancialObservationJson($context)
        ||purepayFinancialObservationJson($saved['owners']??null)!==purepayFinancialObservationJson($owners)
        ||(int)$row['tenant_id']!==$context['tenant_id']||(int)$row['connection_id']!==$context['connection_id']
        ||(int)$row['capture_id']!==($saved['capture_id']??null)
        ||(int)$row['placement_tenant_id']!==$owners['placements']||(int)$row['placement_id']!==($saved['target']['placement_id']??null)
        ||($saved['basis']??null)!=='reviewer_confirmed_document_line'||($saved['subject_hash']??null)!==$row['subject_hash']
        ||($saved['link_hash']??null)!==$row['link_hash']||!hash_equals($row['link_hash'],purepayFinancialLineHash($saved))
        ||!hash_equals($row['subject_hash'],purepayFinancialLineSubject($context,$saved['source'])))
        throw new PurePayFinancialLineReviewException('The saved association differs from its original company and review.');
    return ['id'=>(int)$row['id'],'reviewed_by'=>(int)$row['reviewed_by'],'reviewed_at'=>$row['created_at'],'saved'=>$saved];
}

function purepayFinancialLinePreview(PDO $pdo,int $tenant,string $instance,array $owners,int $captureId,string $lineId,string $number): array
{
    purepayFinancialLineOwners($owners);
    if($pdo->inTransaction())throw new LogicException('Review requires a fresh provider read outside database locks.');
    $binding=purepayFinancialRequire($pdo,$tenant,null,true);
    $context=purepayFinancialObservationContext($tenant,$instance,$binding);
    $capture=purepayFinancialLineCapture($pdo,$tenant,$context,$captureId);
    $source=purepayFinancialLineSource($capture,$lineId);
    $fresh=purepayFinancialObservationRead($pdo,$tenant,$instance,$source['kind'],$source['document_id']);
    if($fresh['context']!==$context||$fresh['observation']['observation_hash']!==$source['observation_hash'])
        throw new PurePayFinancialLineReviewException('The source document changed. Save and review a new capture before associating its lines.');
    $target=purepayFinancialLineTarget($pdo,$instance,$owners,$number);
    // A period label is never converted to dates. Explicit conflicting dates must be resolved first.
    $start=$source['line']['service_start'];$end=$source['line']['service_end'];
    if($start!==null&&$end!==null&&$start>$end)throw new PurePayFinancialLineReviewException('The source service dates are reversed.');
    foreach([$start,$end]as$date)if($date!==null&&(($target['start_date']!==null&&$date<$target['start_date'])
        ||($target['end_date']!==null&&$date>$target['end_date'])))
        throw new PurePayFinancialLineReviewException('Recorded service dates fall outside this placement. Resolve the source dates or placement first.');
    $link=['tenant_id'=>$tenant,'context'=>$context,'owners'=>$owners,'capture_id'=>$captureId,'source'=>$source,'target'=>$target,
        'basis'=>'reviewer_confirmed_document_line','future_item_rule'=>false,'time_entries_matched'=>false,
        'currency_verified'=>false,'ledger_posted'=>false,'provider_changed'=>false];
    $link['subject_hash']=purepayFinancialLineSubject($context,$source);$link['link_hash']=purepayFinancialLineHash($link);
    $q=$pdo->prepare('SELECT * FROM purepay_financial_line_links WHERE tenant_id=? AND connection_id=? AND subject_hash=? ORDER BY id DESC LIMIT 1');
    $q->execute([$tenant,$context['connection_id'],$link['subject_hash']]);$row=$q->fetch(PDO::FETCH_ASSOC);
    $prior=$row?purepayFinancialLineSaved($row,$context,$owners):null;
    if($prior&&$prior['saved']['target']['placement_id']!==$target['placement_id'])
        throw new PurePayFinancialLineReviewException('This source line is already associated with another placement. Review the conflicting association first.');
    $current=purepayFinancialRequire($pdo,$tenant);
    if($current['binding_hash']!==$binding['binding_hash'])throw new PurePayFinancialLineReviewException('The financial company connection changed.');
    return $link+['access_hash'=>$binding['binding_hash'],'review_hash'=>hash('sha256',$link['link_hash'].$binding['binding_hash']),
        'existing_link'=>$prior];
}

function purepayFinancialLineSave(PDO $pdo,int $tenant,int $actor,string $instance,array $owners,int $captureId,string $lineId,string $number,string $reviewHash,callable $audit): array
{
    if($actor<=0||!preg_match('/^[a-f0-9]{64}$/D',$reviewHash))throw new InvalidArgumentException('A confirmed current source-line review is required.');
    $preview=purepayFinancialLinePreview($pdo,$tenant,$instance,$owners,$captureId,$lineId,$number);
    if(!hash_equals($preview['review_hash'],$reviewHash))throw new PurePayFinancialLineReviewException('The source, placement or connection changed. Review again.');
    $pdo->beginTransaction();
    try {
        $binding=purepayFinancialRequire($pdo,$tenant);
        $capture=purepayFinancialLineCapture($pdo,$tenant,$preview['context'],$captureId,true);
        $source=purepayFinancialLineSource($capture,$lineId);
        $target=purepayFinancialLineTarget($pdo,$instance,$owners,$number,true);
        if($binding['binding_hash']!==$preview['access_hash']||purepayFinancialObservationJson($source)!==purepayFinancialObservationJson($preview['source'])
            ||purepayFinancialObservationJson($target)!==purepayFinancialObservationJson($preview['target']))
            throw new PurePayFinancialLineReviewException('The local review changed. Refresh before associating the source line.');
        $q=$pdo->prepare('SELECT * FROM purepay_financial_line_links WHERE tenant_id=? AND connection_id=? AND subject_hash=? ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $q->execute([$tenant,$binding['connection_id'],$preview['subject_hash']]);$row=$q->fetch(PDO::FETCH_ASSOC);
        $prior=$row?purepayFinancialLineSaved($row,$preview['context'],$owners):null;
        if($prior&&$prior['saved']['target']['placement_id']!==$target['placement_id'])throw new PurePayFinancialLineReviewException('A conflicting placement association was saved first.');
        $q=$pdo->prepare('SELECT * FROM purepay_financial_line_links WHERE tenant_id=? AND connection_id=? AND link_hash=?');
        $q->execute([$tenant,$binding['connection_id'],$preview['link_hash']]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if($row)$result=purepayFinancialLineSaved($row,$preview['context'],$owners);
        else {
            $saved=$preview;unset($saved['access_hash'],$saved['review_hash'],$saved['existing_link']);
            $payload=purepayFinancialObservationJson($saved);
            if(strlen($payload)>4200000)throw new PurePayFinancialLineReviewException('The source line exceeds the association evidence limit.');
            $pdo->prepare('INSERT INTO purepay_financial_line_links(tenant_id,connection_id,capture_id,subject_hash,link_hash,placement_tenant_id,placement_id,payload_ct,reviewed_by) VALUES(?,?,?,?,?,?,?,?,?)')
                ->execute([$tenant,$binding['connection_id'],$captureId,$preview['subject_hash'],$preview['link_hash'],$owners['placements'],$target['placement_id'],encryptField($payload),$actor]);
            $id=(int)$pdo->lastInsertId();$audit(['link_id'=>$id,'capture_id'=>$captureId,'link_hash'=>$preview['link_hash']]);
            $q=$pdo->prepare('SELECT * FROM purepay_financial_line_links WHERE tenant_id=? AND id=?');$q->execute([$tenant,$id]);
            $result=purepayFinancialLineSaved($q->fetch(PDO::FETCH_ASSOC),$preview['context'],$owners);
        }
        $pdo->commit();return ['tenant_id'=>$tenant,'replayed'=>(bool)$row,'link'=>$result];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function purepayFinancialLineHistory(PDO $pdo,int $tenant,string $instance,array $owners,int $captureId,string $lineId,?int $before=null): array
{
    purepayFinancialLineOwners($owners);
    if($before!==null&&$before<=0)throw new InvalidArgumentException('Choose a valid association history page.');
    $binding=purepayFinancialRequire($pdo,$tenant,null,true);
    $context=purepayFinancialObservationContext($tenant,$instance,$binding);
    $capture=purepayFinancialLineCapture($pdo,$tenant,$context,$captureId);
    $source=purepayFinancialLineSource($capture,$lineId);
    $params=[$tenant,$binding['connection_id'],purepayFinancialLineSubject($context,$source)];
    if($before!==null)$params[]=$before;
    $q=$pdo->prepare('SELECT id FROM purepay_financial_line_links WHERE tenant_id=? AND connection_id=? AND subject_hash=?'
        .($before!==null?' AND id<?':'').' ORDER BY id DESC LIMIT 2');$q->execute($params);$ids=$q->fetchAll(PDO::FETCH_COLUMN);$items=[];
    if($ids){
        $q=$pdo->prepare('SELECT * FROM purepay_financial_line_links WHERE tenant_id=? AND connection_id=? AND id=?');
        $q->execute([$tenant,$binding['connection_id'],$ids[0]]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new PurePayFinancialLineReviewException('Association history changed. Refresh it.');
        $items[]=purepayFinancialLineSaved($row,$context,$owners);
    }
    if(purepayFinancialRequire($pdo,$tenant)['binding_hash']!==$binding['binding_hash'])throw new PurePayFinancialLineReviewException('The financial connection changed.');
    return ['tenant_id'=>$tenant,'capture_id'=>$captureId,'line_id'=>$lineId,'items'=>$items,
        'next_before'=>count($ids)>1?(int)$ids[0]:null,'historical_evidence'=>true];
}

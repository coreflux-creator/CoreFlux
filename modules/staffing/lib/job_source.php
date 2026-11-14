<?php
/** Exact JobDiva ownership, preserved Core edits and reviewed source changes. */
declare(strict_types=1);
require_once __DIR__.'/job_values.php';
require_once __DIR__.'/../../people/lib/company_source_ownership.php';
require_once __DIR__.'/../../../core/integrations/stable_mapping.php';

function staffingJobSourceState(array $row): array
{
    if(($row['coreflux_source_state']??null)===null)return['version'=>1,'revision'=>0,'source'=>null,'owned'=>staffingJobNativeFields(),'proposed'=>[]];
    $state=json_decode($row['coreflux_source_state'],true,64,JSON_THROW_ON_ERROR);
    if(!is_array($state)||($state['version']??null)!==1||!is_int($state['revision']??null)||$state['revision']<0
        ||!is_array($state['owned']??null)||!array_is_list($state['owned'])||array_filter($state['owned'],static fn($f)=>!is_string($f))
        ||array_diff($state['owned'],staffingJobNativeFields())||!is_array($state['proposed']??null)||!array_key_exists('source',$state))throw new RuntimeException('Review the stored job ownership.',409);
    if($state['source']!==null){
        $s=$state['source'];
        if(!is_array($s)||array_diff(array_keys($s),['system','workspace_id','external_id'])||($s['system']??null)!=='jobdiva'||!is_int($s['workspace_id']??null)||$s['workspace_id']<=0||!is_string($s['external_id']??null)||trim($s['external_id'])===''
            ||($row['source_system']??null)!=='jobdiva'||($row['external_id']??null)!==$s['external_id'])throw new RuntimeException('The job source identity changed. Review its retained link.',409);
    }
    if($state['proposed'])staffingJobValidateNative($state['proposed']);
    return$state;
}

function staffingJobSourcePersist(PDO $pdo,int $owner,int $id,array $state): void
{
    if(!$pdo->inTransaction())throw new LogicException('Job ownership requires a transaction.');
    $q=$pdo->prepare('UPDATE staffing_jobs SET coreflux_source_state=? WHERE tenant_id=? AND id=?');
    $q->execute([json_encode($state,JSON_THROW_ON_ERROR),$owner,$id]);
}

function staffingJobRevision(array $scope,array $clientScope,array $row): string
{
    return mappingHash(['scope'=>$scope,'client_scope'=>$clientScope,'job_id'=>(int)$row['id'],'values'=>staffingJobComparable($row),'source_system'=>$row['source_system'],'external_id'=>$row['external_id'],'state'=>staffingJobSourceState($row)]);
}

function staffingJobSourceClaim(PDO $pdo,int $owner,array $row,array $fields): void
{
    $state=staffingJobSourceState($row);$state['owned']=array_values(array_unique([...$state['owned'],...$fields]));sort($state['owned']);$state['revision']++;
    staffingJobSourcePersist($pdo,$owner,(int)$row['id'],$state);
}

/** Mapping Studio must use the same ownership boundary as the job mirror. */
function staffingJobMappedSourceWrite(int $workspace,int $expectedOwner,int $id,array $set): array
{
    $pdo=getDB();return companyRelationshipAtomic($pdo,static function()use($pdo,$workspace,$expectedOwner,$id,$set):array{
        $scope=companyRelationshipScope($pdo,$workspace,'staffing');
        if($scope['owner']!==$expectedOwner)throw new RuntimeException('The job directory changed. Refresh this source mapping.',409);
        $q=$pdo->prepare('SELECT * FROM staffing_jobs WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([$scope['owner'],$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        $q=$pdo->prepare('SELECT tenant_id,external_id FROM external_entity_mappings WHERE source_system="jobdiva" AND internal_entity_type="staffing_job" AND internal_entity_id=? FOR UPDATE');$q->execute([$id]);$links=$q->fetchAll(PDO::FETCH_ASSOC);
        if(!$row||$row['source_system']!=='jobdiva'||count($links)!==1||(int)$links[0]['tenant_id']!==$workspace||$links[0]['external_id']!==$row['external_id'])throw new RuntimeException('An exact job source identity is required before applying mapped fields.',409);
        $values=array_filter($set,static fn($v)=>$v!==null&&$v!=='');
        $saved=staffingJobSourceProject($pdo,$workspace,$scope['owner'],$row['external_id'],$row,$values,null);
        $owned=staffingJobSourceState($row)['owned'];$before=staffingJobComparable($row);$after=staffingJobComparable($saved);$written=0;
        foreach($values as$field=>$value)if($before[$field]!==$after[$field])$written++;
        return['written'=>$written,'skipped'=>array_values(array_intersect(array_keys($values),$owned))];
    });
}

/** Missing/null source values are not clear instructions. New jobs can follow observed fields. */
function staffingJobSourceProject(PDO $pdo,int $workspace,int $owner,string $external,?array $existing,array $values,?int $actor): array
{
    if(!$pdo->inTransaction())throw new LogicException('A source job requires a transaction.');
    if($values)$values=staffingJobValidateNative($values);
    $source=['system'=>'jobdiva','workspace_id'=>$workspace,'external_id'=>$external];
    if($existing){
        if((int)$existing['tenant_id']!==$owner||$existing['source_system']!=='jobdiva'||$existing['external_id']!==$external)throw new RuntimeException('The job source identity does not match exactly.',409);
        $state=staffingJobSourceState($existing);
        if($state['source']!==null&&$state['source']!==$source)throw new RuntimeException('This job belongs to another source connection.',409);
    }else$state=['version'=>1,'revision'=>0,'source'=>null,'owned'=>[],'proposed'=>[]];
    $beforeState=$state;$state['source']=$source;$state['proposed']=array_replace($state['proposed'],$values);$patch=[];
    $current=$existing?staffingJobComparable($existing):[];
    foreach($values as$field=>$value)if(!in_array($field,$state['owned'],true)&&(!array_key_exists($field,$current)||$current[$field]!==$value))$patch[$field]=$value;
    if(!$existing){
        $insert=$patch+['tenant_id'=>$owner,'source_system'=>'jobdiva','external_id'=>$external,'title'=>'JobDiva Job '.$external,'status'=>'open','created_by_user_id'=>$actor];
        $q=$pdo->prepare('INSERT INTO staffing_jobs (`'.implode('`,`',array_keys($insert)).'`) VALUES('.implode(',',array_fill(0,count($insert),'?')).')');$q->execute(array_values($insert));$id=(int)$pdo->lastInsertId();
    }else{
        $id=(int)$existing['id'];
        if($patch){$q=$pdo->prepare('UPDATE staffing_jobs SET '.implode(',',array_map(static fn($k)=>'`'.$k.'`=?',array_keys($patch))).' WHERE tenant_id=? AND id=?');$q->execute([...array_values($patch),$owner,$id]);}
    }
    if(!$existing||$patch||$state!==$beforeState){
        $state['revision']++;staffingJobSourcePersist($pdo,$owner,$id,$state);
        companySourceAudit($pdo,$workspace,$owner,'staffing_jobs',$id,'staffing.job.source_observed',array_keys($values),$actor,['source'=>$source,'revision'=>$state['revision'],'observed_values'=>$values,'applied_fields'=>array_keys($patch),'held_fields'=>array_values(array_intersect(array_keys($values),$state['owned']))]);
    }
    $q=$pdo->prepare('SELECT * FROM staffing_jobs WHERE tenant_id=? AND id=?');$q->execute([$owner,$id]);return$q->fetch(PDO::FETCH_ASSOC);
}

/** Provider evidence is visible only in the workspace holding that connection. */
function staffingJobSourceReview(int $workspace,int $id,bool $lock=false): array
{
    $pdo=getDB();$scope=companyRelationshipScope($pdo,$workspace,'staffing',$lock);$clients=companyRelationshipScope($pdo,$workspace,'placements',$lock);$suffix=$lock?' FOR UPDATE':'';
    $q=$pdo->prepare('SELECT * FROM staffing_jobs WHERE tenant_id=? AND id=?'.$suffix);$q->execute([$scope['owner'],$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException('Job not found in this directory.',404);
    $state=staffingJobSourceState($row);$source=$state['source'];$visible=$source!==null&&$source['workspace_id']===$workspace;$proposed=$visible?$state['proposed']:[];
    $references=[];
    foreach(['company_id'=>['companies',$scope['company_owner']],'client_id'=>['staffing_clients',$clients['owner']]]as$field=>[$table,$owner]){
        foreach(array_unique(array_filter([$row[$field]??null,$proposed[$field]??null]))as$ref){$q=$pdo->prepare('SELECT id,name FROM '.$table.' WHERE tenant_id=? AND id=?'.$suffix);$q->execute([$owner,$ref]);$record=$q->fetch(PDO::FETCH_ASSOC);if($record)$references[$field][(string)$ref]=$record['name'];}
    }
    $revision=staffingJobRevision($scope,$clients,$row);
    return['job_id'=>$id,'current'=>staffingJobComparable($row),'owned_fields'=>$state['owned'],'source'=>$visible?$source:null,'proposed'=>$proposed,'references'=>$references,'edit_revision'=>$revision,'review_token'=>mappingHash(['revision'=>$revision,'references'=>$references,'source'=>$visible?$source:null,'proposed'=>$proposed])];
}

/** A reviewed decision is atomic and repeatable; a retry never reapplies old values. */
function staffingJobSourceDecide(int $workspace,int $id,array $fields,string $mode,string $token,int $actor): array
{
    if($actor<=0||!in_array($mode,['use_once','follow_source','keep_core'],true)||!$fields||!array_is_list($fields)||array_filter($fields,static fn($f)=>!is_string($f))||count(array_unique($fields))!==count($fields)||array_diff($fields,staffingJobNativeFields()))throw new InvalidArgumentException('Choose the job fields and ownership decision.');
    sort($fields);$digest=mappingHash(['fields'=>$fields,'mode'=>$mode]);$pdo=getDB();
    return companyRelationshipAtomic($pdo,static function()use($pdo,$workspace,$id,$fields,$mode,$token,$actor,$digest):array{
        $scope=companyRelationshipScope($pdo,$workspace,'staffing');
        $review=staffingJobSourceReview($workspace,$id,true);
        $q=$pdo->prepare('SELECT meta_json FROM audit_log WHERE tenant_id=? AND target_id=? AND event="staffing.job.source_reviewed" AND JSON_UNQUOTE(JSON_EXTRACT(meta_json,"$.review_token"))=? ORDER BY id DESC LIMIT 1 FOR UPDATE');$q->execute([$workspace,$id,$token]);$receipt=$q->fetchColumn();
        if($receipt!==false){$receipt=json_decode($receipt,true,32,JSON_THROW_ON_ERROR);if(!hash_equals($receipt['request_digest'],$digest))throw new RuntimeException('This review already saved a different decision.',409);return$receipt['result']+['replayed'=>true];}
        if($token===''||!hash_equals($review['review_token'],$token))throw new RuntimeException('The job, source or sharing policy changed. Refresh the review.',409);
        if(!$review['source']||array_diff($fields,array_keys($review['proposed'])))throw new InvalidArgumentException('Choose observed job fields from this workspace source.');
        require_once __DIR__.'/job_write.php';
        $patch=array_intersect_key($mode==='keep_core'?$review['current']:$review['proposed'],array_flip($fields));
        $saved=staffingJobSaveNative($workspace,$id,$patch,$actor,$review['edit_revision']);$state=staffingJobSourceState($saved);
        if($mode==='follow_source'){$state['owned']=array_values(array_diff($state['owned'],$fields));$state['revision']++;staffingJobSourcePersist($pdo,$scope['owner'],$id,$state);}
        $result=['job_id'=>$id,'fields'=>$fields,'mode'=>$mode];
        companySourceAudit($pdo,$workspace,$scope['owner'],'staffing_jobs',$id,'staffing.job.source_reviewed',$fields,$actor,
            ['review_token'=>$token,'request_digest'=>$digest,'result'=>$result,'source'=>$review['source'],'edit_revision'=>$review['edit_revision'],
                'observed_values'=>array_intersect_key($review['proposed'],array_flip($fields)),
                'previous_values'=>array_intersect_key($review['current'],array_flip($fields))]);
        return$result+['replayed'=>false];
    });
}

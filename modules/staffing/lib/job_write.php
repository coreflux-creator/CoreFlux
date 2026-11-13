<?php
/** Native job edits: endpoint authorizes; one transaction resolves and saves every reference. */
declare(strict_types=1);
require_once __DIR__.'/jobs.php';
require_once __DIR__.'/../../people/lib/company_relationships.php';
require_once __DIR__.'/../../people/lib/company_source_ownership.php';

function staffingJobNativeFields(): array
{
    return ['client_id','company_id','title','status','description','department','location_city','location_state','location_country','remote_policy','opened_at','closed_at'];
}

function staffingJobSaveNative(int $workspace,?int $id,array $patch,int $actor): array
{
    if($actor<=0||($id!==null&&$id<=0)||!$patch||array_diff(array_keys($patch),staffingJobNativeFields()))throw new InvalidArgumentException('Choose the job fields to save.');
    $pdo=getDB();return companyRelationshipAtomic($pdo,static function()use($pdo,$workspace,$id,$patch,$actor):array{
        $scope=companyRelationshipScope($pdo,$workspace,'staffing');$owner=$scope['owner'];
        $clientScope=companyRelationshipScope($pdo,$workspace,'placements');$before=[];
        if($id!==null){$q=$pdo->prepare('SELECT * FROM staffing_jobs WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([$owner,$id]);$before=$q->fetch(PDO::FETCH_ASSOC);if(!$before)throw new RuntimeException('Job not found in this directory.',404);}
        foreach(['company_id','client_id']as$field)if(array_key_exists($field,$patch))$patch[$field]=companyRelationshipId($patch[$field]);
        if(array_key_exists('title',$patch)){$patch['title']=trim((string)$patch['title']);if($patch['title']==='')throw new InvalidArgumentException('A job title is required.');}
        if($id===null&&empty($patch['title']))throw new InvalidArgumentException('A job title is required.');
        if(array_key_exists('status',$patch))$patch['status']=staffingJobNormalizeStatus((string)$patch['status']);
        foreach(['remote_policy','opened_at','closed_at','location_country']as$field)if(array_key_exists($field,$patch)&&$patch[$field]==='')$patch[$field]=null;
        $after=array_replace($before,$patch);
        if(!empty($after['company_id'])&&($id===null||array_key_exists('company_id',$patch)))companyRelationshipCurrent($pdo,$scope,(int)$after['company_id']);
        if(!empty($after['client_id'])&&($id===null||array_key_exists('client_id',$patch))){
            $q=$pdo->prepare('SELECT * FROM staffing_clients WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([$clientScope['owner'],$after['client_id']]);$client=$q->fetch(PDO::FETCH_ASSOC);
            if(!$client)throw new InvalidArgumentException('Choose a client from this workspace client directory.');
            if(($id===null||($before['client_id']??null)!=$after['client_id'])&&$client['status']!=='active')throw new InvalidArgumentException('Choose an active client.');
            if(!empty($client['company_id']))companyRelationshipCurrent($pdo,$clientScope,(int)$client['company_id']);
        }
        if($id===null){
            $patch+=['tenant_id'=>$owner,'status'=>'open','source_system'=>'manual','created_by_user_id'=>$actor];
            $q=$pdo->prepare('INSERT INTO staffing_jobs (`'.implode('`,`',array_keys($patch)).'`) VALUES('.implode(',',array_fill(0,count($patch),'?')).')');$q->execute(array_values($patch));$id=(int)$pdo->lastInsertId();
        }else{
            $q=$pdo->prepare('UPDATE staffing_jobs SET '.implode(',',array_map(static fn($key)=>'`'.$key.'`=?',array_keys($patch))).' WHERE tenant_id=? AND id=?');$q->execute([...array_values($patch),$owner,$id]);
        }
        $q=$pdo->prepare('SELECT * FROM staffing_jobs WHERE tenant_id=? AND id=?');$q->execute([$owner,$id]);$saved=$q->fetch(PDO::FETCH_ASSOC);
        companySourceAudit($pdo,$workspace,$owner,'staffing_jobs',$id,'staffing.job.native_saved',array_keys($patch),$actor,
            ['before'=>array_intersect_key($before,array_flip(staffingJobNativeFields())),'after'=>array_intersect_key($saved,array_flip(staffingJobNativeFields()))]);
        return$saved;
    });
}

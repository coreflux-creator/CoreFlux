<?php
/** Actual job bridge and canonical MariaDB tables; caller owns the outer rollback. */
(static function()use($pdo,$parent,$child,$actor,$check,$fails):void{
    $pdo->exec('SAVEPOINT job_reference_fixture');$shadow=false;
    $insert=static function(string $table,array $data)use($pdo):int{
        $q=$pdo->prepare('INSERT INTO '.$table.' (`'.implode('`,`',array_keys($data)).'`) VALUES('.implode(',',array_fill(0,count($data),'?')).')');$q->execute(array_values($data));return(int)$pdo->lastInsertId();
    };
    $read=static function(string $table,int $id)use($pdo):array{$q=$pdo->prepare('SELECT * FROM '.$table.' WHERE id=?');$q->execute([$id]);return$q->fetch(PDO::FETCH_ASSOC)?:[];};
    try{
        foreach(['shared/shared','shared/isolated','isolated/isolated']as$mode){
            [$companies,$jobs]=explode('/',$mode);$owner=$jobs==='shared'?$parent:$child;$companyOwner=$companies==='shared'?$parent:$child;
            foreach(['companies'=>$companies,'placements'=>$jobs,'staffing'=>$jobs]as$module=>$sharing)$pdo->prepare('INSERT INTO tenant_module_scope(tenant_id,module_key,scope_mode) VALUES(?,?,?) ON DUPLICATE KEY UPDATE scope_mode=VALUES(scope_mode)')->execute([$child,$module,$sharing]);
            $external='reference-job-'.$mode;$companyExternal='reference-company-'.$mode;
            $company=$insert('companies',['tenant_id'=>$companyOwner,'name'=>'Reference company '.$mode]);
            mappingBindStable($child,'jobdiva','company',$companyExternal,$company,[],'pull',$actor);
            $client=$insert('staffing_clients',['tenant_id'=>$owner,'name'=>'Reference client '.$mode,'company_id'=>$company,'status'=>'active']);
            $person=$insert('people',['tenant_id'=>$owner,'first_name'=>'Synthetic','last_name'=>'Job reference '.$mode,'email_primary'=>'job-reference-'.str_replace('/','-',$mode).'@example.invalid']);
            $otherJob=$insert('staffing_jobs',['tenant_id'=>$owner,'title'=>'Reviewed other job '.$mode]);
            $cases=[];
            foreach(['follow','owned','clear','manual','foreign','deleted']as$case){
                $start='reference-start-'.$mode.'-'.$case;
                $cases[$case]=$insert('placements',['tenant_id'=>$owner,'person_id'=>$person,'title'=>'Synthetic reference '.$case,'start_date'=>'2026-11-01',
                    'external_id'=>$case==='manual'?'manual-reference-'.$mode:'jd:'.$start,'jobdiva_job_id'=>$external,
                    'staffing_job_id'=>in_array($case,['follow','owned','foreign','deleted'],true)?$otherJob:null,
                    'coreflux_overridden_fields'=>in_array($case,['owned','clear'],true)?'["staffing_job_id"]':null,
                    'deleted_at'=>$case==='deleted'?'2026-11-01 00:00:00':null]);
                if($case!=='manual')mappingBindStable($case==='foreign'?$parent:$child,'jobdiva','placement',$start,$cases[$case],['id'=>$start,'jobId'=>$external],'pull',$actor);
            }
            $before=[];foreach($cases as$case=>$id)$before[$case]=$read('placements',$id);
            $job=jobdivaBridgeStaffingJobFromPayload($child,$external,['title'=>'Observed job '.$mode,'companyId'=>$companyExternal],$actor);
            $check($job!==null,$mode.': actual job mirror bridge succeeds');
            $check((int)$read('staffing_jobs',$job)['client_id']===$client&&count($read('staffing_clients',$client))>0,$mode.': job resolves the existing client in the current shared or isolated directory');
            $after=$read('placements',$cases['follow']);$prior=$before['follow'];unset($after['staffing_job_id'],$after['updated_at'],$prior['staffing_job_id'],$prior['updated_at']);
            $check((int)$read('placements',$cases['follow'])['staffing_job_id']===$job&&$after===$prior,$mode.': same-workspace exact Start updates only its unowned job reference');
            foreach(['owned','clear','manual','foreign','deleted']as$case)$check($read('placements',$cases[$case])===$before[$case],$mode.': '.$case.' placement is unchanged by job refresh');
            $q=$pdo->prepare('SELECT meta_json FROM audit_log WHERE tenant_id=? AND event="placement.job.source_linked" AND target_id=?');$q->execute([$child,$cases['follow']]);$receipts=$q->fetchAll(PDO::FETCH_COLUMN);$receipt=json_decode($receipts[0],true,64,JSON_THROW_ON_ERROR);
            $check(count($receipts)===1&&$receipt['before']['staffing_job_id']===$otherJob&&$receipt['after']['staffing_job_id']===$job&&$receipt['source']['source_workspace_id']===$child&&$receipt['job_source']['external_id']===$external,$mode.': required audit records both source identities and previous/current job links');
            $snapshot=$read('placements',$cases['follow']);
            $check(staffingJobLinkPlacementsByJobDivaId($child,$external,$job,$actor)===0&&$read('placements',$cases['follow'])===$snapshot,$mode.': exact reference replay makes no second write');
            $q->execute([$child,$cases['follow']]);$check(count($q->fetchAll(PDO::FETCH_COLUMN))===1,$mode.': replay creates no duplicate reference receipt');
            $check($fails(fn()=>staffingJobLinkPlacementsByJobDivaId($child,'wrong-source',$job,$actor),409),$mode.': job ID cannot substitute for a mismatched provider identity');
            // Every changed placement needs a durable audit, in the same transaction.
            $pdo->prepare('UPDATE placements SET staffing_job_id=NULL WHERE tenant_id=? AND id=?')->execute([$owner,$cases['follow']]);$beforeAudit=$read('placements',$cases['follow']);
            $auditDdl=$pdo->query('SHOW CREATE TABLE audit_log')->fetch(PDO::FETCH_NUM)[1];
            $auditDdl=preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$auditDdl,1);
            $auditDdl=str_replace('`audit_log` (','`audit_log` (`required_fixture_value` INT NOT NULL,',$auditDdl);
            $pdo->exec($auditDdl);$shadow=true;
            $check($fails(fn()=>staffingJobLinkPlacementsByJobDivaId($child,$external,$job,$actor))&&$read('placements',$cases['follow'])===$beforeAudit,$mode.': failed audit rolls back the link instead of reporting success');
            $pdo->exec('DROP TEMPORARY TABLE audit_log');$shadow=false;
            // Do not choose an arbitrary client when its company has competing active profiles.
            $duplicate=$insert('staffing_clients',['tenant_id'=>$owner,'name'=>'Ambiguous reference client '.$mode,'company_id'=>$company,'status'=>'active']);$beforeJob=$read('staffing_jobs',$job);
            $check($fails(fn()=>staffingJobEnsureFromJobDivaPayload($child,$external,['title'=>'Must not partially change','companyId'=>$companyExternal],$actor),409)&&$read('staffing_jobs',$job)===$beforeJob,$mode.': ambiguous active client identity refuses the complete job update');
            $pdo->prepare('UPDATE staffing_clients SET status="inactive" WHERE tenant_id=? AND id IN(?,?)')->execute([$owner,$client,$duplicate]);
            $new=staffingJobEnsureFromJobDivaPayload($child,$external.'-inactive',['title'=>'No active client','companyId'=>$companyExternal],$actor);
            $check($new['client_id']===null&&(int)$new['company_id']===$company,$mode.': inactive client is not assigned to a new job and company identity is retained');
            // Fail after job and mapping creation: the bridge must roll those back too.
            $badExternal=$external.'-bad-ownership';$bad=$insert('placements',['tenant_id'=>$owner,'person_id'=>$person,'title'=>'Malformed ownership','start_date'=>'2026-11-01','jobdiva_job_id'=>$badExternal,'coreflux_overridden_fields'=>'{}']);
            $result=jobdivaBridgeStaffingJobFromPayload($child,$badExternal,['title'=>'Must roll back'],$actor);
            $q=$pdo->prepare('SELECT COUNT(*) FROM staffing_jobs WHERE tenant_id=? AND external_id=?');$q->execute([$owner,$badExternal]);$jobsLeft=(int)$q->fetchColumn();
            $q=$pdo->prepare('SELECT COUNT(*) FROM external_entity_mappings WHERE tenant_id=? AND internal_entity_type="staffing_job" AND external_id=?');$q->execute([$child,$badExternal]);
            $check($result===null&&$jobsLeft===0&&(int)$q->fetchColumn()===0&&$read('placements',$bad)['staffing_job_id']===null,$mode.': failed placement linking rolls back the new job and its source binding together');
        }
    }finally{
        if($shadow)$pdo->exec('DROP TEMPORARY TABLE audit_log');
        $pdo->exec('ROLLBACK TO SAVEPOINT job_reference_fixture');$pdo->exec('RELEASE SAVEPOINT job_reference_fixture');
    }
})();

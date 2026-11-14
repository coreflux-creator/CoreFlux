<?php
// Runs in the existing disposable native-session fixture and its tenant cleanup.
$job=staffingJobEnsureFromJobDivaPayload($tenant,'race-owned-job',['title'=>'Original source title','city'=>'Hamilton'],$actor);$jobId=(int)$job['id'];
$review=staffingJobSourceReview($tenant,$jobId);
$pdo->beginTransaction();
staffingJobSaveNative($tenant,$jobId,['title'=>'Concurrent Core title'],$actor,$review['edit_revision']);
$worker=$spawn(['mode'=>'native','entity'=>'job_source','workspace'=>$tenant,'actor'=>$actor,'external'=>'race-owned-job','payload'=>['title'=>'Concurrent source title','city'=>'Princeton']]);
$check($waiting($worker),'Source job writer is observed waiting on the uncommitted native ownership decision');
$pdo->commit();$result=$finish($worker);$row=$read('staffing_jobs',$jobId);
$check($result['ok']&&$row['title']==='Concurrent Core title'&&$row['location_city']==='Princeton'&&staffingJobSourceState($row)['proposed']['title']==='Concurrent source title','Waiting source job preserves the committed Core title and still updates an unowned city');
$review=staffingJobSourceReview($tenant,$jobId);
$pdo->beginTransaction();staffingJobEnsureFromJobDivaPayload($tenant,'race-owned-job',['city'=>'New source city'],$actor);
$worker=$spawn(['mode'=>'native','entity'=>'job_edit','workspace'=>$tenant,'actor'=>$actor,'job_id'=>$jobId,'revision'=>$review['edit_revision'],'patch'=>['location_city'=>'Stale browser city']]);
$check($waiting($worker),'Native job editor is observed waiting on an uncommitted source update');
$pdo->commit();$result=$finish($worker);
$check(!$result['ok']&&(int)$result['code']===409&&$read('staffing_jobs',$jobId)['location_city']==='New source city','Waiting stale native editor cannot replace the newly committed source value');
$review=staffingJobSourceReview($tenant,$jobId);
$pdo->beginTransaction();$pdo->prepare('SELECT id FROM tenants WHERE id=? FOR UPDATE')->execute([$tenant]);
$input=['mode'=>'native','entity'=>'job_decide','workspace'=>$tenant,'actor'=>$actor,'job_id'=>$jobId,'review_token'=>$review['review_token'],'fields'=>['title'],'decision'=>'use_once'];
$a=$spawn($input);$b=$spawn($input);$check($waiting($a)&&$waiting($b),'Two independent source reviewers wait on the same job decision');
$pdo->commit();$first=$finish($a);$second=$finish($b);
$check($first['ok']&&$second['ok']&&$first['result']['replayed']!==$second['result']['replayed']&&$read('staffing_jobs',$jobId)['title']==='Concurrent source title','Concurrent identical decisions create one write and one replay receipt');

// The independent job mirror must honor the same placement ownership as the Start importer.
$job=jobdivaBridgeStaffingJobFromPayload($tenant,'race-placement-job',['title'=>'Source association job'],$actor,true);$jobId=$job;
$pdo->prepare('INSERT INTO people(tenant_id,first_name,last_name,email_primary) VALUES(?,"Synthetic","Job association race","job-association-race@example.invalid")')->execute([$tenant]);$person=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO placements(tenant_id,person_id,title,start_date,external_id,jobdiva_job_id) VALUES(?,?,"Job reference race","2026-11-01","jd:race-job-start","race-placement-job")')->execute([$tenant,$person]);$placement=(int)$pdo->lastInsertId();
mappingBindStable($tenant,'jobdiva','placement','race-job-start',$placement,['id'=>'race-job-start','jobId'=>'race-placement-job'],'pull',$actor);
$sourceInput=['mode'=>'native','entity'=>'job_links','workspace'=>$tenant,'actor'=>$actor,'job_id'=>$jobId,'external'=>'race-placement-job'];
$nativeInput=['mode'=>'hold','entity'=>'native_placement','workspace'=>$tenant,'actor'=>$actor,'placement_id'=>$placement,'patch'=>['staffing_job_id'=>null]];
foreach(['COMMIT','ROLLBACK']as$decision){
    $pdo->prepare('UPDATE placements SET staffing_job_id=NULL,coreflux_overridden_fields=NULL WHERE tenant_id=? AND id=?')->execute([$tenant,$placement]);
    $native=$spawn($nativeInput);$saved=$line($workers[$native]['pipes'][1]);$check(!empty($saved['saved']),'Native job-link clear holds its ownership before '.$decision);
    $mirror=$spawn($sourceInput);$check($waiting($mirror),'Job mirror waits for the native placement job choice before '.$decision);
    fwrite($workers[$native]['pipes'][0],$decision.PHP_EOL);fflush($workers[$native]['pipes'][0]);$written=$finish($native);$mirrored=$finish($mirror);$row=$read('placements',$placement);
    $check($written['ok']&&$mirrored['ok']&&($decision==='COMMIT'?($row['staffing_job_id']===null&&$mirrored['result']['written']===0):((int)$row['staffing_job_id']===$jobId&&$mirrored['result']['written']===1)),
        'Waiting job mirror respects committed clear and can link after rolled-back clear: '.$decision);
}
$pdo->prepare('UPDATE placements SET staffing_job_id=NULL,coreflux_overridden_fields=NULL WHERE tenant_id=? AND id=?')->execute([$tenant,$placement]);
$mirror=$spawn($sourceInput+['snapshot'=>true]);$check(!empty($line($workers[$mirror]['pipes'][1])['snapshot']),'Job mirror has a confirmed older transaction snapshot');
$native=$spawn(array_replace($nativeInput,['mode'=>'native']));$written=$finish($native);
fwrite($workers[$mirror]['pipes'][0],'CONTINUE'.PHP_EOL);fflush($workers[$mirror]['pipes'][0]);$mirrored=$finish($mirror);
$check($written['ok']&&$mirrored['ok']&&$mirrored['result']['written']===0&&$read('placements',$placement)['staffing_job_id']===null,'Older mirror snapshot still reads the current committed Core ownership');
$pdo->prepare('UPDATE placements SET staffing_job_id=NULL,coreflux_overridden_fields=NULL WHERE tenant_id=? AND id=?')->execute([$tenant,$placement]);
$mirror=$spawn(array_replace($sourceInput,['mode'=>'hold']));$saved=$line($workers[$mirror]['pipes'][1]);$check(!empty($saved['saved']),'Source job link holds its write and audit together');
$native=$spawn(array_replace($nativeInput,['mode'=>'native']));$check($waiting($native),'Native placement editor waits for the source reference writer');
fwrite($workers[$mirror]['pipes'][0],'COMMIT'.PHP_EOL);fflush($workers[$mirror]['pipes'][0]);$mirrored=$finish($mirror);$written=$finish($native);
$check($mirrored['ok']&&$written['ok']&&$read('placements',$placement)['staffing_job_id']===null&&in_array('staffing_job_id',placementOwnershipFields($read('placements',$placement)['coreflux_overridden_fields']),true),'Later native clear takes ownership after the source transaction commits');

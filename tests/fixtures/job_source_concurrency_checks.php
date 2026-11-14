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

<?php
declare(strict_types=1);
define('CORE_WRITER_LIBRARY_ONLY',true);
require_once __DIR__.'/../deploy/inspect_full_core_writers.php';
require_once __DIR__.'/../deploy/full_core_writer_policy.php';
$checks=0;
$check=static function(bool $ok,string $why)use(&$checks):void{if(!$ok)throw new RuntimeException($why);$checks++;};
$refuse=static function(callable $fn,string $why)use($check):void{try{$fn();}catch(Throwable){$check(true,$why);return;}$check(false,$why);};
$p=static fn(int $pid,int $parent,int $uid,string $command):array=>['pid'=>$pid,'ppid'=>$parent,'start_ticks'=>(string)($pid*10),
    'uids'=>[$uid,$uid,$uid,$uid],'command_title_sha256'=>hash('sha256',$command),'core_reference'=>false];
$empty=['available'=>true,'exit'=>0,'nonempty_lines'=>0,'stdout_bytes'=>0,'stdout_sha256'=>hash('sha256','')];
$schedules=['user_timers'=>$empty,'running_user_services'=>$empty,'queued_at_jobs'=>$empty];
$listener=$p(10,1,0,'sshd: listener');$master=$p(20,1,0,'php-fpm master');
$processes=[$p(101,100,1004,'sshd: coreapp@notty'),$p(102,101,1004,'worker-app'),
    $p(201,200,1003,'sshd: coremaster@notty'),$p(202,201,1003,'worker-master'),$p(301,20,1004,'php-fpm: pool core')];
$report=['version'=>1,'root'=>CORE_WRITER_ROOT,'scope'=>'native-writer-preparation-evidence',
    'owner_uid'=>1003,'observer_pid'=>202,'boot_id'=>'host-boot','registry_sha256'=>str_repeat('f',64),'unresolved'=>[],
    'current_user_cron'=>$empty,'processes'=>$processes,
    'ancestors'=>[$listener,$master,$p(100,10,0,'sshd: coreapp [priv]'),$p(200,10,0,'sshd: coremaster [priv]')],
    'observed_at_unix'=>microtime(true)]+$schedules;
$policy=['version'=>1,'root'=>CORE_WRITER_ROOT,'operation'=>str_repeat('a',32),'candidate_commit'=>str_repeat('b',40),
    'boot_id'=>'host-boot','registry_sha256'=>str_repeat('f',64),'reviewed_limits'=>[],
    'schedulers'=>['1003'=>$schedules,'1004'=>$schedules], 'ssh_listener'=>$listener,'pool_master'=>$master,'pool_name'=>'core',
    'owners'=>['1003'=>['ssh_user'=>'coremaster'],'1004'=>['ssh_user'=>'coreapp']],'retained_processes'=>[]];
$runtime=['operation'=>$policy['operation'],'candidate_commit'=>$policy['candidate_commit'],'owner_uid'=>1004,
    'owner_pid'=>102,'owner_start_ticks'=>'1020','pool_pids'=>[301],
    'held_cron_sha256'=>['1003'=>hash('sha256',''),'1004'=>hash('sha256','')],
    'worker_commands'=>['1003'=>hash('sha256','worker-master'),'1004'=>hash('sha256','worker-app')],'database_children'=>[]];
$classify=static function(array $r,array $pol,array $run):array{
    $bytes=json_encode($pol,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    return coreWriterPolicyClassify($r,$bytes,hash('sha256',$bytes),$run);
};
$check($classify($report,$policy,$runtime)['unmanaged_writers']===0,'Independent SSH and authenticated pool accepted.');
$bad=$report;$bad['processes'][]=$p(777,10,1004,'php cron.php');
$refuse(fn()=>$classify($bad,$policy,$runtime),'Unknown application CLI rejected.');
$bad=$report;$bad['processes'][]=$p(777,20,1004,'php-fpm: pool core');
$refuse(fn()=>$classify($bad,$policy,$runtime),'Pool title alone is not authority.');
$bad=$report;$bad['processes'][4]['ppid']=999;
$refuse(fn()=>$classify($bad,$policy,$runtime),'Authenticated pool PID must descend from pinned master.');
$bad=$report;$bad['processes'][1]['start_ticks']='999';
$refuse(fn()=>$classify($bad,$policy,$runtime),'Reused active-owner PID rejected.');
$bad=$report;$bad['ancestors'][2]['ppid']=99;
$refuse(fn()=>$classify($bad,$policy,$runtime),'Unpinned SSH ancestry rejected.');
$bad=$report;$bad['ancestors'][0]['start_ticks']='999';
$refuse(fn()=>$classify($bad,$policy,$runtime),'Reused listener PID rejected.');
$bad=$report;$bad['ancestors'][0]['command_title_sha256']=hash('sha256','sshd: listener 1 of 10 startups');
$check($classify($bad,$policy,$runtime)['unmanaged_writers']===0,'Listener connection count may change without changing identity.');
foreach(['boot_id'=>'other','registry_sha256'=>str_repeat('e',64),'owner_uid'=>0]as$key=>$value){
    $bad=$report;$bad[$key]=$value;$refuse(fn()=>$classify($bad,$policy,$runtime),'Host, registry or native owner mismatch rejected.');
}
$bad=$report;$bad['current_user_cron']['stdout_sha256']=str_repeat('e',64);
$refuse(fn()=>$classify($bad,$policy,$runtime),'Changed held cron rejected.');
$bad=$report;$bad['unresolved']=['process-unreadable-or-changed:11'];$pol=$policy;$pol['reviewed_limits']=$bad['unresolved'];$pol['platform_scope_evidence']='review';
$refuse(fn()=>$classify($bad,$pol,$runtime),'Process visibility cannot be waived by a policy.');
$bad=$report;$bad['unresolved']=['registry-unreadable'];$pol=$policy;$pol['reviewed_limits']=$bad['unresolved'];
$refuse(fn()=>$classify($bad,$pol,$runtime),'Registry exception needs platform scope evidence.');
$pol['platform_scope_evidence']='Reviewed platform-only certificate cron';
$check($classify($bad,$pol,$runtime)['registry_unchanged'],'Exact reviewed registry limitation accepted.');
$bad=$report;$bad['user_timers']['nonempty_lines']=1;$pol=$policy;$pol['schedulers']['1003']['user_timers']=$bad['user_timers'];
$refuse(fn()=>$classify($bad,$pol,$runtime),'Matching policy cannot authorize an unheld timer.');
$bad=$report;$bad['processes'][]=$p(700,1,1003,'unrelated-server');$pol=$policy;
$pol['retained_processes']=[$bad['processes'][5]+['reason'=>'Separate hosted application']];
$check($classify($bad,$pol,$runtime)['unmanaged_writers']===0,'Exact reviewed unrelated process preserved.');
$bad['processes'][5]['start_ticks']='999';
$refuse(fn()=>$classify($bad,$pol,$runtime),'Retained process PID reuse rejected.');
$bad=$report;$bad['processes'][]=$p(700,1,1003,'unrelated-server');$bad['processes'][5]['core_reference']=true;
$refuse(fn()=>$classify($bad,$pol,$runtime),'Core reference cannot use unrelated-process exemption.');
$run=$runtime;$run['database_children']=['timeout'=>['parent'=>'owner','command_title_sha256'=>hash('sha256','timeout')],
    'client'=>['parent'=>'timeout','command_title_sha256'=>hash('sha256','dump')]];
$bad=$report;$bad['processes'][]=$p(401,102,1004,'timeout');$bad['processes'][]=$p(402,401,1004,'dump');
$check($classify($bad,$policy,$run)['unmanaged_writers']===0,'Exact direct native transfer descendants accepted.');
$bad['processes'][6]['ppid']=101;
$refuse(fn()=>$classify($bad,$policy,$run),'Sibling database process cannot claim active transfer.');
$bad=$report;$bad['processes'][]=$p(401,102,1004,'timeout');$bad['processes'][]=$p(402,102,1004,'timeout');
$refuse(fn()=>$classify($bad,$policy,$run),'Duplicated permitted transfer process rejected.');
$run['database_children']=array_reverse($run['database_children'],true);
$refuse(fn()=>$classify($report,$policy,$run),'Noncanonical transfer roles rejected.');
$bad=$report;$bad['ancestors'][]=array_replace($processes[1],['start_ticks'=>'999']);
$refuse(fn()=>$classify($bad,$policy,$runtime),'Conflicting ancestry identity rejected.');
$run=$runtime;$run['owner_pid']=202;
$refuse(fn()=>$classify($report,$policy,$run),'Self-attestation rejected.');
$run=$runtime;unset($run['worker_commands']);
$refuse(fn()=>$classify($report,$policy,$run),'Transport-bound command hashes required.');
$bytes=json_encode($policy);$refuse(fn()=>coreWriterPolicyClassify($report,$bytes,str_repeat('0',64),$runtime),'Changed reviewed policy bytes rejected.');
echo "full_core_writer_policy_smoke: $checks checks passed\n";

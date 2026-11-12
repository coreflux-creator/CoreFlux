<?php
/** One native, draft-only synthetic time-correction case in the isolated Core pilot. */
declare(strict_types=1);
$root='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
$private=dirname($root).'/private_html/thunderhawk-pilot-20261030';
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--create-isolated-lifecycle-draft'
    ||getenv('COREFLUX_ENV')!=='staging'||realpath(getcwd())!==$root||posix_geteuid()!==1003
    ||is_link($private)||realpath($private)!==$private||fileowner($private)!==1003||(fileperms($private)&0777)!==0700)exit(2);
require_once $root.'/core/quanta/time_control_delivery.php';
require_once $root.'/core/memberships.php';require_once $root.'/core/rbac/permissions.php';
$pdo=getDB();
if(!$pdo||!COREFLUX_STAGING||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'
    ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
    ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn()!==1)exit(2);
setRequestTenantId(2002);
if(quantaTimeControlOwners(2002)!==['people'=>2002,'placements'=>2002,'companies'=>2002,'ap'=>2002])exit(2);
if(!quantaTimeControlEnabled(2002))exit(2);
foreach([2,999,2003] as $tenant)if(quantaTimeControlEnabled($tenant))exit(2);
$pins=json_decode(file_get_contents(__DIR__.'/correction-runtime-pins.json'),true,32,JSON_THROW_ON_ERROR);
foreach($pins as $file=>$expected){
    $path=is_file($root.'/'.$file)?$root.'/'.$file:$root.'/'.str_replace('Placements','placements',$file);
    if(hash('sha256',str_replace("\r\n","\n",file_get_contents($path)))!==$expected)exit(2);
}
$workerEmail='thunderhawk-correction-20261112@example.com';
$actorEmail='core-lifecycle-operator-20261112-correction@example.invalid';
$marker='SYNTH-CORRECTION-20261112-correction';
$state=$private.'/synthetic-lifecycle-20261112-correction.json';
if(file_exists($state)||is_link($state))throw new RuntimeException('Existing case: inspect its saved outcome before continuing; never repeat creation.');
foreach([['placements','external_id',$marker],['people','email_primary',$workerEmail],['users','email',$actorEmail]] as [$table,$field,$value]){
    $q=$pdo->prepare('SELECT COUNT(*) FROM '.$table.' WHERE '.$field.'=?');$q->execute([$value]);
    if((int)$q->fetchColumn()!==0)throw new RuntimeException('Reserved synthetic identity already exists');
}
// The normal W-2 create route also fills missing employee metadata. Bound that
// existing pilot-only effect before invoking it; no pay, tax or bank data is added.
$bridge=$pdo->query("SELECT p.id,p.first_name,p.last_name,p.email_primary FROM people p
    LEFT JOIN people_employees pe ON pe.tenant_id=p.tenant_id AND
    ((p.email_primary IS NOT NULL AND p.email_primary!='' AND pe.work_email=p.email_primary)
      OR (pe.legal_first_name=p.first_name AND pe.legal_last_name=p.last_name))
    WHERE p.tenant_id=2002 AND UPPER(p.classification)='W2' AND p.status='active' AND pe.id IS NULL")->fetchAll(PDO::FETCH_ASSOC);
if(count($bridge)!==0)throw new RuntimeException('Pilot employee backfill inventory changed');
$entities=$pdo->query('SELECT id FROM accounting_entities WHERE tenant_id=2002 AND active=1')->fetchAll(PDO::FETCH_COLUMN);
if(array_map('intval',$entities)!==[127])throw new RuntimeException('Pilot legal entity changed');
$snapshot=static function()use($pdo):array{
    $tables=[];
    foreach($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table){
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))throw new RuntimeException('Unexpected table name');
        $rows=[];
        $q=$pdo->query('SELECT * FROM `'.$table.'`');
        while($row=$q->fetch(PDO::FETCH_ASSOC))$rows[]=hash('sha256',serialize($row));
        sort($rows,SORT_STRING);$tables[$table]=$rows;
    }
    ksort($tables);return $tables;
};
$before=$snapshot();$featureHash=hash_file('sha256',dirname($root).'/private_html/coreflux.thunderhawk-pilot.php');
$report=['checked_at'=>gmdate('c'),'passed'=>false,'phase'=>'prepared','case'=>$marker,
    'tenant_id'=>2002,'synthetic'=>true,'browser_acceptance'=>false,'provider_writes'=>false,
    'isolated_time_control_enabled'=>true,'production_changed'=>false,'arabella_changed'=>false,
    'real_payroll_payments_postings'=>false,'checks'=>[],'requests'=>[]];
$write=static function()use($state,&$report):void{
    if(file_put_contents($state,json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT),LOCK_EX)===false)throw new RuntimeException('Case checkpoint failed');
};
$old=umask(0077);$f=fopen($state,'x');umask($old);
if(!$f)throw new RuntimeException('Case claim failed');fclose($f);$write();
$beforePath=$private.'/synthetic-lifecycle-20261112-correction-before.private.json';
$old=umask(0077);try{file_put_contents($beforePath,json_encode($before,JSON_THROW_ON_ERROR),LOCK_EX);}finally{umask($old);}
$expect=static function(bool $ok,string $label)use(&$report):void{
    if(!$ok){$report['failed_check']=$label;throw new RuntimeException($label);}$report['checks'][]=$label;
};
$request=static function(string $path,?array $body,?string $cookie,int $tenant=2002,bool $form=false)use($private,&$report):array{
    $ch=curl_init('https://phpstack-1516771-6707602.cloudwaysapps.com'.$path);
    $headers=['Accept: application/json','X-CoreFlux-Tenant-Id: '.$tenant];
    $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>45,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2];
    if($body!==null){$options[CURLOPT_POST]=true;$options[CURLOPT_POSTFIELDS]=$form?http_build_query($body):json_encode((object)$body,JSON_THROW_ON_ERROR);
        $headers[]='Content-Type: '.($form?'application/x-www-form-urlencoded':'application/json');}
    if($cookie!==null){$options[CURLOPT_COOKIEFILE]=$cookie;$options[CURLOPT_COOKIEJAR]=$cookie;}
    $options[CURLOPT_HTTPHEADER]=$headers;curl_setopt_array($ch,$options);$raw=curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$location=(string)curl_getinfo($ch,CURLINFO_REDIRECT_URL);curl_close($ch);
    $report['requests'][]=['path'=>$path,'tenant'=>$tenant,'status'=>$status];
    if($raw===false)throw new RuntimeException('Native request outcome uncertain; inspect saved case');
    if($status>=400){$old=umask(0077);try{file_put_contents($private.'/synthetic-lifecycle-last-response.private.txt',$raw);}finally{umask($old);}}
    return [$status,json_decode($raw,true),$location];
};
$actor=null;$mid=null;$cookie=null;
try{
    $report['phase']='operator';$write();
    $password=bin2hex(random_bytes(32));$hash=password_hash($password,PASSWORD_DEFAULT);
    $pdo->beginTransaction();
    try{
        $pdo->prepare('INSERT INTO users(tenant_id,name,email,password,password_hash,role,is_active,is_global_admin) VALUES(2002,"Synthetic time-correction operator",?,?,?,"admin",1,0)')->execute([$actorEmail,$hash,$hash]);
        $actor=(int)$pdo->lastInsertId();
        $mid=(int)provisionMembership($actor,2002,'admin',['is_primary'=>true,'persona_label'=>'Synthetic time-correction operator','status'=>'active'])['membership_id'];
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$actor=null;throw $e;}
    $report['operator_id']=$actor;$report['operator_membership_id']=$mid;$write();
    foreach(['people','placements'] as $module)RBACResolver::grantModule($mid,$module,'admin',[2002],4);
    $cookie=tempnam($private,'lifecycle-cookie-');if(!$cookie||!chmod($cookie,0600))throw new RuntimeException('Private session unavailable');
    [$status,,$location]=$request('/login.php',['username'=>$actorEmail,'password'=>$password,'redirect'=>'spa'],$cookie,2002,true);unset($password,$hash);
    $expect($status===302&&!str_contains($location,'error='),'Native test-operator login succeeded');
    $expect($request('/api/placements/placements?id=864',null,$cookie,2)[0]===403,'Operator cannot enter real Thunderhawk');
    $body=['new_person'=>['first_name'=>'Synthetic','last_name'=>'Lifecycle','email_primary'=>$workerEmail],
        'external_id'=>$marker,'title'=>'Synthetic time-correction test — no real work or payment','status'=>'draft',
        'start_date'=>'2026-11-09','end_date'=>'2026-11-30','engagement_type'=>'w2',
        'worksite_city'=>'Hamilton','worksite_state'=>'NJ','worksite_country'=>'US','remote_policy'=>'remote',
        'branch'=>'Synthetic integration test','service_line'=>'Pilot only','department'=>'Synthetic',
        'cost_center'=>'SYNTHETIC','accounting_entity_id'=>127,'client_id'=>1280,'end_client_company_id'=>1902,
        'notes'=>'Isolated simulation fixture. Synthetic worker and draft rates only. No real payroll, invoices or payments.'];
    $report['phase']='create_requested';$write();
    [$status,$created]=$request('/api/placements/placements',$body,$cookie);
    $expect($status===201&&($created['person_created']??false)===true,'Combined native person and draft placement created');
    $person=(int)($created['person']['id']??0);$placement=(int)($created['placement']['id']??0);
    $expect($person>0&&$placement>0&&$person!==358&&$placement!==864,'Synthetic case has separate internal IDs');
    $report['person_id']=$person;$report['placement_id']=$placement;$report['phase']='draft_created';$write();
    $expect(($created['placement']['status']??null)==='draft'&&($created['person']['email_primary']??null)===$workerEmail,'Created case is the expected draft');
    [$status,$rate]=$request('/api/placements/rates?placement_id='.$placement,
        ['effective_from'=>'2026-11-09','effective_to'=>'2026-12-01','bill_rate'=>100,'pay_rate'=>50,'currency'=>'USD'],$cookie);
    $expect($status===201&&(int)($rate['id']??0)>0,'Synthetic rate saved as an unapproved draft');
    $report['rate_id']=(int)$rate['id'];$report['phase']='draft_rate_created';$write();
    [$status,$detail]=$request('/api/placements/placements?id='.$placement,null,$cookie);
    $expect($status===200&&($detail['placement']['status']??null)==='draft','Native detail returns the draft');
    $q=$pdo->prepare('SELECT entity_type,record_id,record_number,canonical_record_id FROM core_record_numbers WHERE tenant_id=2002 AND ((entity_type="person" AND record_id=?) OR (entity_type="placement" AND record_id=?)) ORDER BY entity_type');
    $q->execute([$person,$placement]);$numbers=$q->fetchAll(PDO::FETCH_ASSOC);
    $expect(count($numbers)===2,'Both permanent business numbers allocated');
    foreach($numbers as $number){$expect((int)$number['record_id']===(int)$number['canonical_record_id'],'Synthetic canonical ID preserved');$report[$number['entity_type'].'_number']=$number['record_number'];}
    $q=$pdo->prepare('SELECT COUNT(*) FROM placement_rates WHERE tenant_id=2002 AND placement_id=? AND id=? AND approved_at IS NULL');$q->execute([$placement,$report['rate_id']]);
    $expect((int)$q->fetchColumn()===1,'No rate approval or financial posting occurred');
    $report['dimension_readiness']=$detail['dimension_readiness']??null;
    $q=$pdo->prepare('SELECT id FROM people_employees WHERE tenant_id=2002 AND work_email=?');$q->execute([$workerEmail]);
    $report['employee_id']=(int)$q->fetchColumn();$expect($report['employee_id']>0,'New synthetic employee metadata is identifiable');
    $report['phase']='prepared';$report['passed']=true;
}catch(Throwable $e){$report['failure_class']=get_class($e);$report['failure_line']=$e->getLine();$report['passed']=false;}
finally{
    if($actor!==null){
        try{
            foreach(['people','placements'] as $module)RBACResolver::revokeModule($mid,$module,4);
            deactivateMembership($actor,2002);
            $pdo->prepare('UPDATE users SET is_active=0,password=NULL,password_hash=NULL WHERE id=? AND tenant_id=2002 AND email=?')->execute([$actor,$actorEmail]);
            $q=$pdo->prepare('SELECT COUNT(*) FROM membership_module_access WHERE membership_id=?');$q->execute([$mid]);
            $report['operator_retired']=(int)$q->fetchColumn()===0;
            $q=$pdo->prepare('SELECT COUNT(*) FROM users WHERE id=? AND tenant_id=2002 AND is_active=0 AND password IS NULL AND password_hash IS NULL');$q->execute([$actor]);
            $report['operator_retired']=$report['operator_retired']&&(int)$q->fetchColumn()===1;
            if(!$report['operator_retired'])$report['passed']=false;
        }catch(Throwable $e){$report['operator_retired']=false;$report['passed']=false;}
    }
    if($cookie!==null&&is_file($cookie)){
        try{$request('/logout.php',null,$cookie);}catch(Throwable $e){$report['logout_uncertain']=true;}
        $report['private_session_removed']=unlink($cookie);if(!$report['private_session_removed'])$report['passed']=false;
    }
}
try{
    $after=$snapshot();$expect(array_keys($before)===array_keys($after),'Database schema unchanged');
    $allowed=['users','tenant_memberships','user_tenants','membership_audit','audit_log','people','people_employees',
        'placements','placement_rates','core_record_numbers','placement_economic_parties','staffing_operating_cycles'];
    $changed=[];
    foreach($before as $table=>$hashes){
        if($hashes===$after[$table])continue;
        $expect(in_array($table,$allowed,true),'Only fixture and access metadata changed: '.$table);
        $oldCounts=array_count_values($hashes);$newCounts=array_count_values($after[$table]);
        foreach($oldCounts as $hash=>$count)$expect(($newCounts[$hash]??0)>=$count,'Every original row preserved in '.$table);
        $changed[$table]=['before'=>count($hashes),'after'=>count($after[$table])];
    }
    $report['changed_tables']=$changed;$report['unchanged_tables']=count($before)-count($changed);
    $expect(hash_file('sha256',dirname($root).'/private_html/coreflux.thunderhawk-pilot.php')===$featureHash,'Operational switches unchanged');
    $expect(($changed['people']['after']??0)-($changed['people']['before']??0)===1,'Exactly one synthetic person added');
    $expect(($changed['placements']['after']??0)-($changed['placements']['before']??0)===1,'Exactly one synthetic placement added');
    $expect(($changed['people_employees']['after']??0)-($changed['people_employees']['before']??0)===1,'Native W-2 bridge added only the new synthetic employee record');
    $report['existing_employee_records_unchanged']=true;
}catch(Throwable $e){$report['passed']=false;$report['preservation_failure_class']=get_class($e);$report['preservation_failure_line']=$e->getLine();}
$report['checks_passed']=count($report['checks']);$write();
echo json_encode($report,JSON_THROW_ON_ERROR)."\n";exit($report['passed']?0:1);

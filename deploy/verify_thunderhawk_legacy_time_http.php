<?php
/** Exercise installed native login and historical review, never provider delivery. */
declare(strict_types=1);
$root='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
$private=dirname($root).'/private_html/thunderhawk-pilot-20261030';
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--verify-native-legacy-time-http'||getenv('COREFLUX_ENV')!=='staging'
    ||realpath(getcwd())!==$root||realpath($private)!==$private||posix_geteuid()!==1003)exit(2);
$package=json_decode(file_get_contents(__DIR__.'/core-legacy-time-package.json'),true,64,JSON_THROW_ON_ERROR);
$installed=json_decode(file_get_contents($private.'/legacy-time-core-install-result.json'),true,32,JSON_THROW_ON_ERROR);
if(!$installed['passed']||$installed['source_commit']!=='6c83324bea0d7c4dcf8733316759a9ef0c784b1e'
    ||$package['source_commit']!==$installed['source_commit']||$package['root']!==$root)exit(2);
foreach($package['files'] as $relative=>$file)if(hash_file('sha256',$root.'/'.$relative)!==$file['sha256'])exit(2);
require_once $root.'/core/quanta/legacy_time_delivery.php';
require_once $root.'/core/memberships.php';require_once $root.'/core/rbac/permissions.php';
$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!COREFLUX_STAGING
    ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
    ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn()!==1)exit(2);
foreach([2002,2,999,2003] as $tenant)if(quantaTimeControlEnabled($tenant))exit(2);
if((int)$pdo->query('SELECT COUNT(*) FROM integration_deliveries WHERE tenant_id=2002 AND operation="time.legacy"')->fetchColumn()!==0)exit(2);
$snapshot=static function()use($pdo):array {
    $tables=[];
    foreach($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table){
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))throw new RuntimeException('Unexpected table');
        $hashes=[];$q=$pdo->query('SELECT * FROM `'.$table.'`');
        while($row=$q->fetch(PDO::FETCH_ASSOC))$hashes[]=hash('sha256',serialize($row));
        sort($hashes,SORT_STRING);$tables[$table]=$hashes;
    }
    ksort($tables);return $tables;
};
$before=$snapshot();$actor=null;$mid=null;$cookie=null;$record=null;$phase='setup';$deliveries=[];
$report=['checked_at'=>gmdate('c'),'source_commit'=>$installed['source_commit'],'passed'=>false,'checks'=>[],
    'requests'=>[],'browser_test'=>false,'provider_writes'=>false,'operational_policy_enabled'=>false,
    'production_changed'=>false,'arabella_changed'=>false,'real_payroll_payments_postings'=>false];
$expect=static function(bool $ok,string $label)use(&$report):void {
    if(!$ok){$report['failed_check']=$label;throw new RuntimeException($label);}$report['checks'][]=$label;
};
$base='https://phpstack-1516771-6707602.cloudwaysapps.com';
$request=static function(string $path,?array $body,?string $session,int $tenant=2002,bool $form=false)use($base,$private,&$report):array {
    $ch=curl_init($base.$path);$headers=['Accept: application/json','X-CoreFlux-Tenant-Id: '.$tenant];
    $opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>50,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2];
    if($body!==null){$opts[CURLOPT_POST]=true;$opts[CURLOPT_POSTFIELDS]=$form?http_build_query($body):json_encode((object)$body,JSON_THROW_ON_ERROR);
        $headers[]='Content-Type: '.($form?'application/x-www-form-urlencoded':'application/json');}
    if($session!==null){$opts[CURLOPT_COOKIEFILE]=$session;$opts[CURLOPT_COOKIEJAR]=$session;}
    $opts[CURLOPT_HTTPHEADER]=$headers;curl_setopt_array($ch,$opts);$raw=curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$location=(string)curl_getinfo($ch,CURLINFO_REDIRECT_URL);curl_close($ch);
    if($raw===false)throw new RuntimeException('Native HTTP transport failed');
    $report['requests'][]=['path'=>$path,'tenant_id'=>$tenant,'status'=>$status];
    if($status>=400){$mask=umask(0077);try{file_put_contents($private.'/legacy-http-last-response.private.txt',$raw);}finally{umask($mask);}}
    return [$status,json_decode($raw,true),$location];
};
$call=static fn(string $action,array $body=[],?string $session=null,int $tenant=2002)=>$request('/api/quanta.php?action='.$action,$body,$session,$tenant);
try{
    $expect($call('legacy_time_inventory')[0]===401,'Anonymous historical review denied');
    $email='core-legacy-http-'.bin2hex(random_bytes(8)).'@example.invalid';$password=bin2hex(random_bytes(32));$hash=password_hash($password,PASSWORD_DEFAULT);
    $pdo->beginTransaction();
    try{
        $pdo->prepare('INSERT INTO users(tenant_id,name,email,password,password_hash,role,is_active,is_global_admin) VALUES(2002,"Synthetic historical time reviewer",?,?,?,"admin",1,0)')->execute([$email,$hash,$hash]);
        $actor=(int)$pdo->lastInsertId();$mid=(int)provisionMembership($actor,2002,'admin',
            ['is_primary'=>true,'persona_label'=>'Synthetic historical time reviewer','status'=>'active'])['membership_id'];$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$actor=null;throw $e;}
    $record=$private.'/legacy-http-actor-'.$actor.'.private.json';$mask=umask(0077);
    try{file_put_contents($record,json_encode(['user_id'=>$actor,'membership_id'=>$mid,'tenant_id'=>2002,'retired'=>false]));
        file_put_contents($private.'/legacy-http-before-'.$actor.'.private.json',json_encode($before,JSON_THROW_ON_ERROR));}finally{umask($mask);}
    $cookie=tempnam($private,'legacy-http-cookie-');if(!$cookie||!chmod($cookie,0600))throw new RuntimeException('Private cookie unavailable');
    $phase='native_login';[$status,,$location]=$request('/login.php',['username'=>$email,'password'=>$password,'redirect'=>'spa'],$cookie,2002,true);unset($password,$hash);
    $expect($status===302&&!str_contains($location,'error='),'Synthetic native login succeeded');
    $phase='permission_checks';$expect($call('legacy_time_pending',[],$cookie)[0]===403,'Ungraded integration access denied');
    RBACResolver::grantModule($mid,'integrations','admin',[2002],$actor);
    $expect($call('legacy_time_pending',[],$cookie)[0]===403,'Integration admin without time access denied');
    RBACResolver::grantModule($mid,'people','read',[2002],$actor);RBACResolver::grantModule($mid,'placements','read',[2002],$actor);
    RBACResolver::grantModule($mid,'time','read',[2002],$actor);
    $expect($call('legacy_time_pending',[],$cookie)[0]===403,'Time read access cannot review historical links');
    RBACResolver::grantModule($mid,'time','write',[2002],$actor);
    [$status,$pending]=$call('legacy_time_pending',[],$cookie);$expect($status===200&&($pending['rows']??null)===[],'Authorized reviewer reads empty recovery queue');
    $expect($call('legacy_time_pending',[],$cookie,999)[0]===403,'Foreign pinned company denied');
    setRequestTenantId(2002);$sites=array_values(array_unique(array_filter(array_column(quantaRoutes(2002),'worksite_id'))));
    $expect(count($sites)===1,'Pilot has the expected single worksite');
    $phase='review';[$status,$inventory]=$call('legacy_time_inventory',['person_id'=>358,'worksite_id'=>$sites[0]],$cookie);
    $expect($status===200&&($inventory['remaining']??null)===12&&($inventory['complete']??null)===true&&count($inventory['rows']??[])===12,'Native inventory contains all twelve original imports');
    $hours=[];
    foreach($inventory['rows'] as $item){
        [$status,$preview]=$call('legacy_time_preview',['source_entry_id'=>$item['source_entry_id']],$cookie);
        $expect($status===200&&($preview['status']??null)==='preview'&&($preview['delivery_enabled']??null)===false
            &&($preview['person_number']??null)==='P-358'&&($preview['placement_number']??null)==='PL-864'
            &&($preview['delivery']['state']??null)==='preview','Reviewed source retains identity and held delivery: '.$item['source_entry_id']);
        $id=(int)$preview['delivery']['id'];$deliveries[]=$id;$hours[$preview['work_date']]=($hours[$preview['work_date']]??0)+$preview['hours'];
        $row=integrationDeliveryGet($pdo,2002,$id);$payload=json_decode((string)decryptField($row['payload_ct']),true,64,JSON_THROW_ON_ERROR);
        $expect($payload['body']['source_entry_id']===$item['source_entry_id']&&$payload['body']['expected_version']===0
            &&$row['created_by']==$actor&&$row['attempted_at']===null&&$row['reviewed_by']===null,'Encrypted original proof is unattempted: '.$item['source_entry_id']);
    }
    [$status,$replay]=$call('legacy_time_preview',['source_entry_id'=>$inventory['rows'][0]['source_entry_id']],$cookie);
    $expect($status===200&&(int)($replay['delivery']['id']??0)===$deliveries[0],'Repeated review reuses the original delivery');
    $envelope=['delivery_id'=>$deliveries[0],'review_hash'=>$replay['delivery']['review_hash']];
    $expect($call('legacy_time_send',$envelope+['confirm_existing_time'=>false],$cookie)[0]===422,'Sending requires literal confirmation');
    $expect($call('legacy_time_send',$envelope+['confirm_existing_time'=>true],$cookie)[0]===409,'Confirmed send remains disabled by the operational control');
    $expect($call('legacy_time_receipt',['delivery_id'=>$deliveries[0],'review_hash'=>str_repeat('0',64)],$cookie)[0]===422,'Altered receipt review rejected');
    [$status,$receipt]=$call('legacy_time_receipt',$envelope,$cookie);
    $expect($status===200&&($receipt['receipt_state']??null)==='preview'&&(int)$receipt['delivery']['id']===$deliveries[0],'Unsent receipt remains a preview without provider delivery');
    RBACResolver::grantModule($mid,'time','read',[2002],$actor);
    $expect($call('legacy_time_inventory',['person_id'=>358,'worksite_id'=>$sites[0]],$cookie)[0]===403,'Same native session loses review access on downgrade');
    $report['reviewed_entries']=count($deliveries);$report['reviewed_hours_by_date']=$hours;$report['local_preview_delivery_ids']=$deliveries;
    $expect(count(array_unique($deliveries))===12,'Twelve distinct source entries have twelve local reviews');
    $report['passed']=true;
}catch(Throwable $e){$report['failed_phase']=$phase;$report['failure_class']=get_class($e);$report['failure_line']=$e->getLine();}
finally{
    if($actor!==null){
        try{
            $pdo->beginTransaction();foreach(['integrations','people','placements','time'] as $module)RBACResolver::revokeModule($mid,$module,$actor);
            deactivateMembership($actor,2002);
            $pdo->prepare('UPDATE users SET is_active=0,password=NULL,password_hash=NULL WHERE tenant_id=2002 AND id=? AND email=?')->execute([$actor,$email]);$pdo->commit();
            $q=$pdo->prepare('SELECT COUNT(*) FROM membership_module_access WHERE membership_id=?');$q->execute([$mid]);$clean=(int)$q->fetchColumn()===0;
            $q=$pdo->prepare("SELECT COUNT(*) FROM users u JOIN tenant_memberships tm ON tm.user_id=u.id AND tm.tenant_id=u.tenant_id
                JOIN user_tenants ut ON ut.user_id=u.id AND ut.tenant_id=u.tenant_id WHERE u.id=? AND u.tenant_id=2002 AND u.email=?
                AND u.is_active=0 AND u.is_global_admin=0 AND u.password IS NULL AND u.password_hash IS NULL AND tm.id=? AND tm.status='revoked' AND ut.status='inactive'");
            $q->execute([$actor,$email,$mid]);$report['synthetic_actor_retired']=$clean&&(int)$q->fetchColumn()===1;$report['synthetic_actor_id']=$actor;
            file_put_contents($record,json_encode(['user_id'=>$actor,'membership_id'=>$mid,'tenant_id'=>2002,'retired'=>$report['synthetic_actor_retired']]));
            if(!$report['synthetic_actor_retired'])$report['passed']=false;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$report['passed']=false;$report['retirement_failed']=true;}
    }
    if($cookie!==null&&is_file($cookie)){
        try{$request('/logout.php',null,$cookie);}catch(Throwable $e){$report['logout_transport_failed']=true;}
        $report['private_session_removed']=unlink($cookie);if(!$report['private_session_removed'])$report['passed']=false;
    }
}
try{
    $after=$snapshot();$changed=[];$allowed=['users','tenant_memberships','user_tenants','membership_audit','audit_log','integration_deliveries','integration_delivery_subjects'];
    $expect(array_keys($before)===array_keys($after),'No schema tables added or removed');
    foreach($before as $table=>$hashes){
        if($after[$table]===$hashes)continue;
        $changed[$table]=['before'=>count($hashes),'after'=>count($after[$table])];
        $expect(in_array($table,$allowed,true),'Only authorized review or synthetic access tables changed: '.$table);
        $expected=array_count_values($hashes);$current=array_count_values($after[$table]);
        foreach($expected as $hash=>$count)$expect(($current[$hash]??0)>=$count,'Existing rows preserved in '.$table);
    }
    $report['changed_tables']=$changed;$report['unchanged_tables']=count($before)-count($changed);
    $new=$pdo->prepare('SELECT * FROM integration_deliveries WHERE tenant_id=2002 AND operation="time.legacy"');$new->execute();$rows=$new->fetchAll(PDO::FETCH_ASSOC);
    $expect(count($rows)===count($deliveries),'No duplicate local historical requests');
    foreach($rows as $row)$expect(in_array((int)$row['id'],$deliveries,true)&&$row['state']==='preview'&&$row['attempted_at']===null
        &&$row['remote_id']===null&&$row['remote_receipt_id']===null&&$row['created_by']==$actor,'Historical request is still unattempted');
    $expect(hash_file('sha256',dirname($root).'/private_html/coreflux.thunderhawk-pilot.php')===$package['feature_sha256'],'Operational feature configuration unchanged');
}catch(Throwable $e){$report['passed']=false;$report['preservation_failure_class']=get_class($e);$report['preservation_failure_line']=$e->getLine();}
$report['checks_passed']=count($report['checks']);
$mask=umask(0077);try{file_put_contents($private.'/legacy-time-native-http-result.json',json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));}finally{umask($mask);}
echo json_encode($report,JSON_THROW_ON_ERROR)."\n";exit($report['passed']?0:1);

<?php
/** One synthetic operator and browser-created employment in the isolated pilot. */
declare(strict_types=1);
const ES_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const ES_PRIVATE='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
const ES_STATE=ES_PRIVATE.'/employment-setup-browser-20261112.json';
const ES_CREDENTIAL=ES_PRIVATE.'/employment-setup-browser-credential.private.json';
const ES_BEFORE=ES_PRIVATE.'/employment-setup-browser-before.private.json';
const ES_EMAIL='core-employment-setup-20261112@example.invalid';
const ES_PERSON='synthetic-employment-setup-20261112@example.invalid';
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='staging'||getcwd()!==ES_ROOT||posix_geteuid()!==1003
    ||realpath(ES_PRIVATE)!==ES_PRIVATE||(fileperms(ES_PRIVATE)&0777)!==0700||!in_array($argv[1]??'',['prepare','finish'],true))exit(2);
require_once ES_ROOT.'/core/db.php';
require_once ES_ROOT.'/core/memberships.php';
require_once ES_ROOT.'/core/rbac/permissions.php';
require_once ES_ROOT.'/modules/people/lib/employment_setup.php';
$pdo=getDB();
if(!COREFLUX_STAGING||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
    ||COREFLUX_INTEGRATION_DELIVERY_TENANTS!=='2002'||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn()!==1)exit(2);
setRequestTenantId(2002);setRequestModuleScope('people');
if(peopleEmploymentOwner(2002)!==2002)exit(2);
$pins=json_decode(file_get_contents(__DIR__.'/employment-setup-runtime-pins.json'),true,64,JSON_THROW_ON_ERROR);
foreach($pins as$file=>$hash)if(hash('sha256',str_replace("\r\n","\n",file_get_contents(ES_ROOT.'/'.$file)))!==$hash)throw new RuntimeException('Runtime changed: '.$file);
function esSave(string $path,array $value,bool $new=false):void{
    if(is_link($path)||realpath(dirname($path))!==ES_PRIVATE)throw new RuntimeException('Invalid private file');
    $old=umask(0077);try{$f=fopen($path,$new?'x':'w');if(!$f)throw new RuntimeException('Cannot save checkpoint');
        $bytes=json_encode($value,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);if(fwrite($f,$bytes)!==strlen($bytes)||!fflush($f)||!fsync($f))throw new RuntimeException('Incomplete checkpoint');fclose($f);
    }finally{umask($old);}
}
function esSnapshot(PDO $pdo):array{
    $out=[];foreach($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN)as$table){
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))throw new RuntimeException('Unexpected table');$rows=[];
        foreach($pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC)as$row)$rows[]=hash('sha256',serialize($row));
        sort($rows,SORT_STRING);$out[$table]=$rows;
    }ksort($out);return $out;
}
function esExpect(bool $ok,string $message,array &$report):void{
    if(!$ok){$report['failed_check']=$message;throw new RuntimeException($message);}$report['checks'][]=$message;
}
if($argv[1]==='prepare'){
    if(file_exists(ES_STATE)||file_exists(ES_CREDENTIAL)||file_exists(ES_BEFORE))throw new RuntimeException('Inspect the existing browser fixture; never repeat preparation');
    $s=$pdo->prepare('SELECT COUNT(*) FROM users WHERE email=?');$s->execute([ES_EMAIL]);if((int)$s->fetchColumn())throw new RuntimeException('Reserved operator exists');
    $s=$pdo->prepare('SELECT COUNT(*) FROM people WHERE tenant_id=2002 AND email_primary=?');$s->execute([ES_PERSON]);if((int)$s->fetchColumn())throw new RuntimeException('Reserved synthetic person exists');
    $before=esSnapshot($pdo);esSave(ES_BEFORE,$before,true);
    $report=['checked_at'=>gmdate('c'),'status'=>'preparing','passed'=>false,'tenant_id'=>2002,'checks'=>[],
        'production_changed'=>false,'arabella_changed'=>false,'real_payroll_payments_postings'=>false,
        'feature_sha256'=>hash_file('sha256',dirname(ES_ROOT).'/private_html/coreflux.thunderhawk-pilot.php')];
    esSave(ES_STATE,$report,true);$password=bin2hex(random_bytes(32));$hash=password_hash($password,PASSWORD_DEFAULT);
    $pdo->beginTransaction();
    try{
        $pdo->prepare('INSERT INTO users(tenant_id,name,email,password,password_hash,role,is_active,is_global_admin) VALUES(2002,"Synthetic employment browser operator",?,?,?,"admin",1,0)')->execute([ES_EMAIL,$hash,$hash]);
        $actor=(int)$pdo->lastInsertId();
        $membership=(int)provisionMembership($actor,2002,'admin',['is_primary'=>true,'persona_label'=>'Synthetic employment browser operator','status'=>'active'])['membership_id'];
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $report['actor_id']=$actor;$report['membership_id']=$membership;$report['status']='operator_created';esSave(ES_STATE,$report);
    RBACResolver::grantModule($membership,'people','write',[2002],4);
    esExpect(RBACResolver::can($actor,2002,'people','write',2002),'Synthetic operator can manage only the pilot People workflow',$report);
    foreach(['placements','time','payroll','billing','ap','accounting','treasury','integrations']as$module)
        esExpect(!RBACResolver::can($actor,2002,$module,'read'),'No '.$module.' access granted',$report);
    esSave(ES_CREDENTIAL,['username'=>ES_EMAIL,'password'=>$password],true);unset($password,$hash);
    $report['status']='browser_ready';esSave(ES_STATE,$report);
    echo json_encode(['status'=>'browser_ready','actor_id'=>$actor,'membership_id'=>$membership,'credential_printed'=>false]);exit;
}
if(!is_file(ES_STATE)||is_link(ES_STATE)||!is_file(ES_BEFORE))throw new RuntimeException('No prepared browser fixture');
$report=json_decode(file_get_contents(ES_STATE),true,64,JSON_THROW_ON_ERROR);
if(($report['status']??'')==='complete')throw new RuntimeException('Browser acceptance already completed');
$actor=(int)($report['actor_id']??0);$membership=(int)($report['membership_id']??0);
if($actor<=0||$membership<=0)throw new RuntimeException('Inspect incomplete operator preparation');
try{
    $s=$pdo->prepare('SELECT id,status,classification FROM people WHERE tenant_id=2002 AND email_primary=? AND source="Synthetic employment setup acceptance"');$s->execute([ES_PERSON]);$people=$s->fetchAll(PDO::FETCH_ASSOC);
    esExpect(count($people)===1,'Browser created exactly one identified synthetic person',$report);$personId=(int)$people[0]['id'];
    $link=peopleEmploymentLink($pdo,2002,$personId);esExpect($link!==null,'New person has its own permanent employment identity',$report);
    $employeeId=(int)$link['employee_id'];$ctx=peopleEmploymentSetupContext(2002,$employeeId);
    esExpect($ctx['employee']['status']==='active'&&$ctx['employee']['employment_type']==='full_time'&&$ctx['employee']['flsa_class']==='non_exempt','Browser activation saved the explicit employment classifications',$report);
    esExpect($ctx['employee']['hire_date']==='2026-11-12'&&$ctx['employee']['start_date']==='2026-11-12'
        &&$ctx['person']['employment_type']==='full_time'&&$ctx['person']['hire_date']==='2026-11-12','Person and employment agree on the reviewed dates and type',$report);
    $history=peopleEmploymentSetupHistory(2002,$employeeId);
    esExpect(count($history)===2&&$history[0]['kind']==='activation'&&$history[1]['kind']==='setup_update','Partial setup and activation each have a saved review',$report);
    esExpect($history[0]['before']['status']==='pending'&&$history[1]['before']['job_title']===null
        &&$history[1]['after']['job_title']==='Synthetic integration analyst','Saved history retains the original pending facts',$report);
    $s=$pdo->prepare('SELECT COUNT(*) FROM people_employment_history WHERE tenant_id=2002 AND employee_id=? AND event_type="hire"');$s->execute([$employeeId]);
    esExpect((int)$s->fetchColumn()===1,'Exactly one hire event records the activation',$report);
    $report['person_id']=$personId;$report['person_number']=$ctx['person']['person_number'];$report['employee_id']=$employeeId;
    $report['employment_number']=$ctx['employee']['employee_number'];$report['review_ids']=array_map(static fn($r)=>(int)$r['id'],$history);
    $report['browser_workflow_verified']=true;
}catch(Throwable $e){$report['failure_class']=get_class($e);$report['failure_line']=$e->getLine();}
try{
    RBACResolver::revokeModule($membership,'people',4);deactivateMembership($actor,2002);
    $pdo->prepare('UPDATE users SET is_active=0,password=NULL,password_hash=NULL WHERE tenant_id=2002 AND id=? AND email=?')->execute([$actor,ES_EMAIL]);
    $s=$pdo->prepare('SELECT COUNT(*) FROM membership_module_access WHERE membership_id=?');$s->execute([$membership]);esExpect((int)$s->fetchColumn()===0,'Temporary grants removed',$report);
    $s=$pdo->prepare('SELECT COUNT(*) FROM users WHERE id=? AND tenant_id=2002 AND email=? AND is_active=0 AND password IS NULL AND password_hash IS NULL');$s->execute([$actor,ES_EMAIL]);esExpect((int)$s->fetchColumn()===1,'Synthetic operator retired',$report);
    if(is_file(ES_CREDENTIAL))esExpect(!is_link(ES_CREDENTIAL)&&unlink(ES_CREDENTIAL),'One-time browser credential removed',$report);
    $before=json_decode(file_get_contents(ES_BEFORE),true,64,JSON_THROW_ON_ERROR);$after=esSnapshot($pdo);
    esExpect(array_keys($before)===array_keys($after),'Schema unchanged during browser acceptance',$report);
    $allowed=['users','tenant_memberships','user_tenants','membership_audit','audit_log','rbac_bridge_audit','people','people_employees','people_employment_links','core_record_numbers','people_change_log','people_employment_reviews','people_employment_history'];$added=[];
    foreach($before as$table=>$rows){
        if($rows===$after[$table])continue;
        esExpect(in_array($table,$allowed,true),'Only intended synthetic records added to '.$table,$report);
        $counts=array_count_values($after[$table]);foreach(array_count_values($rows)as$hash=>$n)if(($counts[$hash]??0)<$n)throw new RuntimeException('Original row changed in '.$table);
        if($table==='rbac_bridge_audit')foreach($pdo->query('SELECT * FROM rbac_bridge_audit')->fetchAll(PDO::FETCH_ASSOC)as$row){
            if(!in_array(hash('sha256',serialize($row)),$rows,true))esExpect((int)$row['tenant_id']===2002&&(int)$row['user_id']===$actor,'Permission comparison audit belongs to the synthetic operator',$report);
        }
        $added[$table]=count($after[$table])-count($rows);
    }
    esExpect(hash_file('sha256',dirname(ES_ROOT).'/private_html/coreflux.thunderhawk-pilot.php')===$report['feature_sha256'],'Operational switches unchanged',$report);
    $report['tables_verified']=count($after);$report['original_rows_preserved']=array_sum(array_map('count',$before));$report['added_rows']=$added;
    $report['operator_retired']=true;$report['passed']=!isset($report['failure_class']);$report['status']=$report['passed']?'complete':'failed';
}catch(Throwable $e){$report['cleanup_failure_class']=get_class($e);$report['cleanup_failure_line']=$e->getLine();$report['passed']=false;$report['status']='needs_inspection';}
$report['finished_at']=gmdate('c');esSave(ES_STATE,$report);echo json_encode($report,JSON_THROW_ON_ERROR);exit($report['passed']?0:1);

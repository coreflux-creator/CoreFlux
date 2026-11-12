<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('GITHUB_ACTIONS')!=='true'||getenv('CI')!=='true'
    ||getenv('COREFLUX_ENV')!=='staging'||getenv('DB_NAME')!=='coreaccounting_ci'||!extension_loaded('curl')){
    fwrite(STDERR,"Disposable CI database and loopback HTTP required.\n");exit(2);
}
putenv('CF_RBAC_BRIDGE_MODE=dual');
require_once __DIR__.'/../core/custom_field_actions.php';
require_once __DIR__.'/../core/memberships.php';
require_once __DIR__.'/../core/rbac/permissions.php';
$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')throw new RuntimeException('Wrong fixture database');
$tenant=900061;$root=dirname(__DIR__);$actor=null;$membership=null;$server=null;
if($pdo->query('SELECT id FROM tenants WHERE id='.$tenant)->fetchColumn())throw new RuntimeException('Fixture company already exists');
$directory=sys_get_temp_dir().'/coreflux-custom-http-'.bin2hex(random_bytes(12));
if(!mkdir($directory,0700))throw new RuntimeException('Private fixture directory unavailable');
$cookie=$directory.'/cookie.private';$checks=[];$passed=false;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks[]=$label;};
try{
    $pdo->prepare('INSERT INTO tenants(id,name,is_active,is_simulation,tenant_type) VALUES(?,"Synthetic custom fields HTTP",1,1,"master")')->execute([$tenant]);
    $email='custom-http-'.bin2hex(random_bytes(10)).'@example.invalid';$password=bin2hex(random_bytes(32));$hash=password_hash($password,PASSWORD_DEFAULT);
    $pdo->prepare('INSERT INTO users(tenant_id,name,email,password,password_hash,role,is_active,is_global_admin) VALUES(?,"Synthetic custom editor",?,?,?,"admin",1,0)')->execute([$tenant,$email,$hash,$hash]);
    $actor=(int)$pdo->lastInsertId();
    $membership=(int)provisionMembership($actor,$tenant,'admin',['is_primary'=>true,'status'=>'active'])['membership_id'];
    $pdo->prepare('INSERT INTO people(tenant_id,first_name,last_name,email_primary) VALUES(?,"Synthetic","Custom field worker",?)')->execute([$tenant,$email]);
    $person=(int)$pdo->lastInsertId();
    $personBefore=$pdo->query('SELECT * FROM people WHERE id='.$person)->fetch();
    $pdo->prepare('INSERT INTO companies(tenant_id,name) VALUES(?,"Synthetic custom company")')->execute([$tenant]);
    $company=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO placements(tenant_id,person_id,title,start_date) VALUES(?,?,"Synthetic custom placement","2026-11-12")')->execute([$tenant,$person]);
    $placement=(int)$pdo->lastInsertId();
    $companyBefore=$pdo->query('SELECT * FROM companies WHERE id='.$company)->fetch();
    $placementBefore=$pdo->query('SELECT * FROM placements WHERE id='.$placement)->fetch();
    $pii=customFieldDefinitionAction($tenant,'people','create',0,['field_key'=>'private_note','field_label'=>'Private note','field_type'=>'text','pii'=>true],$actor,true);
    customFieldValuesAction($tenant,'people',$person,['private_note'=>'private synthetic value'],['id'=>$actor,'role'=>'admin'],true);
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('Loopback port unavailable');
    $port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
    putenv('COREFLUX_CUSTOM_FIELDS_HTTP_FIXTURE=1');putenv('COREFLUX_CUSTOM_FIELDS_HTTP_PORT='.$port);
    $server=proc_open([PHP_BINARY,'-d','session.save_path='.$directory,'-d','display_errors=0','-S','127.0.0.1:'.$port,'-t',$root,__DIR__.'/fixtures/staffing_custom_fields_http_router.php'],
        [0=>['pipe','r'],1=>['file',$directory.'/http.log','a'],2=>['file',$directory.'/http.log','a']],$pipes,$root);
    if(!is_resource($server))throw new RuntimeException('Loopback server unavailable');fclose($pipes[0]);
    $request=static function(string $path,string $method='GET',?array $body=null,bool $signed=true,int $pin=900061,bool $form=false)use($port,$cookie):array{
        $curl=curl_init('http://127.0.0.1:'.$port.$path);
        $headers=['Accept: application/json','X-CoreFlux-Tenant-Id: '.$pin];
        $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>1,CURLOPT_TIMEOUT=>15,CURLOPT_CUSTOMREQUEST=>$method];
        if($body!==null){$options[CURLOPT_POSTFIELDS]=$form?http_build_query($body):json_encode((object)$body,JSON_THROW_ON_ERROR);$headers[]='Content-Type: '.($form?'application/x-www-form-urlencoded':'application/json');}
        if($signed){$options[CURLOPT_COOKIEFILE]=$cookie;$options[CURLOPT_COOKIEJAR]=$cookie;}
        $options[CURLOPT_HTTPHEADER]=$headers;curl_setopt_array($curl,$options);$raw=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);$location=(string)curl_getinfo($curl,CURLINFO_REDIRECT_URL);curl_close($curl);
        return[$status,is_string($raw)?json_decode($raw,true):null,$location];
    };
    $ready=false;for($i=0;$i<40;$i++){if($request('/fixture-ready','GET',null,false)[0]===200){$ready=true;break;}if(!proc_get_status($server)['running'])throw new RuntimeException('Loopback server stopped');usleep(100000);}
    $check($ready,'Native loopback server ready');
    $defs='/api/v1/people/custom-field-definitions';$values='/api/v1/people/custom-field-values/'.$person;
    foreach([$defs,$values,'/modules/people/api/custom_fields.php','/api/custom_field_values.php?entity_type=people&record_id='.$person]as$path)$check($request($path,'GET',null,false)[0]===401,'Anonymous route denied '.$path);
    [$status,,$location]=$request('/login.php','POST',['username'=>$email,'password'=>$password,'redirect'=>'spa'],true,$tenant,true);unset($password,$hash);
    $check($status===302&&!str_contains($location,'error='),'Native login creates the operator session');
    $check($request($defs)[0]===403&&$request($values)[0]===403,'Ungrant­ed operator cannot read definitions or values');
    RBACResolver::grantModule($membership,'people','read',[$tenant],$actor);
    [$status,$body]=$request($values);$check($status===200&&($body['values']??null)===[],'People reader cannot see sensitive values');
    [$status,$body]=$request($defs);$check($status===200&&($body['definitions'][0]['sensitive_hidden']??false)===true,'Sensitive definition does not expose choices as a read-only user');
    $definition=['field_key'=>'approval_flag','field_label'=>'Approval flag','field_type'=>'boolean'];
    $check($request($defs,'POST',$definition)[0]===403&&$request($values,'POST',['values'=>['approval_flag'=>false]])[0]===403,'Read-only directory permission denies both definition and value writes');
    RBACResolver::grantModule($membership,'people','write',[$tenant],$actor);
    [$status,$body]=$request($defs,'POST',$definition);$check($status===201&&($body['id']??0)>0,'Authorized definition creation succeeds through the public router');$flag=(int)$body['id'];
    $check($request($defs,'POST',$definition)[0]===409,'Stable key cannot be recreated as a second definition');
    $check($request($defs.'?id='.$pii,'PATCH',['pii'=>false])[0]===403,'Directory editor cannot remove sensitivity without sensitive permission');
    $check($request($values,'POST',['values'=>['private_note'=>'changed']])[0]===403,'Directory editor cannot change sensitive values');
    $check($request($values,'POST',['values'=>['approval_flag'=>false]])[0]===200,'Explicit false saves through the public value route');
    [$status,$body]=$request($values);$check($status===200&&($body['values'][0]['value']??null)===false,'Readback retains false instead of treating it as missing');
    $check($request('/modules/people/api/custom_field_values.php?person_id='.$person,'PUT',['values'=>['approval_flag'=>'false']])[0]===200,'Legacy People route uses the same typed writer');
    $auditCount=(int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE tenant_id=$tenant AND event='custom_field.value.updated'")->fetchColumn();
    $check($request($values,'POST',['values'=>['approval_flag'=>true,'zz_unknown'=>'bad']])[0]===422,'Unknown second key rejects the entire HTTP batch');
    $check((int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE tenant_id=$tenant AND event='custom_field.value.updated'")->fetchColumn()===$auditCount,'Failed HTTP batch adds no partial audit');
    [$status,$body]=$request($values);$check($status===200&&($body['values'][0]['value']??null)===false,'Failed HTTP batch leaves the original value intact');
    $check($request($defs.'?id='.$flag,'PATCH',['field_type'=>'number'])[0]===422,'Public definition update refuses type reinterpretation');
    $check($request($values,'GET',null,true,999)[0]===403,'Foreign pinned company cannot use the session');
    $check($request('/api/v1/people/custom-field-values/999999999','POST',['values'=>['approval_flag'=>true]])[0]===422,'Foreign or missing record id is checked before writing');
    RBACResolver::grantModule($membership,'people','admin',[$tenant],$actor);
    [$status,$body]=$request($values);$check($status===200&&count($body['values']??[])===2,'Additional sensitive permission admits the protected field');
    $check($request($defs.'?id='.$flag,'DELETE')[0]===200,'Definition archive succeeds through the reviewed permission path');
    $check($request($values,'POST',['values'=>['approval_flag'=>true]])[0]===422,'Archived field cannot receive later values');
    $check((int)$pdo->query('SELECT COUNT(*) FROM people_custom_field_values WHERE field_def_id='.$flag)->fetchColumn()===1,'Archive preserves the existing value row');
    RBACResolver::grantModule($membership,'people','read',[$tenant],$actor);
    $check($request($values,'POST',['values'=>['private_note'=>'changed']])[0]===403,'Permission downgrade applies to the existing signed-in session');
    foreach(['companies'=>$company,'placements'=>$placement] as $entity=>$record){
        $module=$entity==='companies'?'people':'placements';
        RBACResolver::grantModule($membership,$module,'read',[$tenant],$actor);
        $entityDefs='/api/custom_field_definitions.php?entity_type='.$entity;
        $entityValues='/api/custom_field_values.php?entity_type='.$entity.'&record_id='.$record;
        [$status,$body]=$request($entityValues);
        $check($status===200&&$body['owner_tenant_id']===$tenant&&$body['can_manage_values']===false,'Reader gets exact directory and read-only capability '.$entity);
        $check($request($entityValues,'POST',['values'=>['z_flag'=>false]])[0]===403,'Reader cannot bypass disabled controls '.$entity);
        RBACResolver::grantModule($membership,$module,'write',[$tenant],$actor);
        foreach(['a_number'=>'number','z_flag'=>'boolean']as$key=>$type)$check($request($entityDefs,'POST',['field_key'=>$key,'field_label'=>$key,'field_type'=>$type])[0]===201,'Native definition creation '.$entity.'.'.$key);
        [$status,$body]=$request($entityDefs);
        $check($status===200&&$body['can_manage_definitions']===true&&$body['definitions'][0]['field_access']['editable']===true,'Definition capabilities reflect module and field permissions '.$entity);
        $body=['values'=>['z_flag'=>false],'expected_values'=>['z_flag'=>null],'expected_owner_tenant_id'=>$tenant];
        $check($request($entityValues,'POST',$body)[0]===200,'First reviewed value saves '.$entity);
        $body=['values'=>['z_flag'=>true],'expected_values'=>['z_flag'=>false],'expected_owner_tenant_id'=>$tenant];
        $check($request($entityValues,'POST',$body)[0]===200,'Matching original value allows edit '.$entity);
        $auditCount=(int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE tenant_id=$tenant")->fetchColumn();
        $check($request($entityValues,'POST',$body)[0]===200&&(int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE tenant_id=$tenant")->fetchColumn()===$auditCount,'Response-loss retry is an unchanged success '.$entity);
        $body['values']=['a_number'=>'12.5','z_flag'=>null];$body['expected_values']=['a_number'=>null,'z_flag'=>false];
        $check($request($entityValues,'POST',$body)[0]===409,'Stale second field produces a conflict '.$entity);
        [$status,$readback]=$request($entityValues);$byKey=array_column($readback['values'],'value','field_key');
        $check($status===200&&$byKey===['a_number'=>null,'z_flag'=>true]&&(int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE tenant_id=$tenant")->fetchColumn()===$auditCount,'Conflict rolls back all values and audit rows '.$entity);
        $body=['values'=>['z_flag'=>false],'expected_values'=>['z_flag'=>true],'expected_owner_tenant_id'=>999];
        $check($request($entityValues,'POST',$body)[0]===409,'Different original directory refused '.$entity);
        $body['expected_owner_tenant_id']=$tenant;$body['expected_values']=[];
        $check($request($entityValues,'POST',$body)[0]===422,'Incomplete original-value map refused '.$entity);
        $check($request($entityValues,'GET',null,true,999)[0]===403,'Foreign company pin is refused '.$entity);
    }
    $check($pdo->query('SELECT * FROM people WHERE id='.$person)->fetch()===$personBefore,'All custom operations preserve the canonical person and its identity');
    $check($pdo->query('SELECT * FROM companies WHERE id='.$company)->fetch()===$companyBefore&&$pdo->query('SELECT * FROM placements WHERE id='.$placement)->fetch()===$placementBefore,'Company and placement identities and business fields remain unchanged');
    $request('/logout.php');$check($request($values)[0]===401,'Native logout invalidates custom-field access');
    $passed=true;
}catch(Throwable $e){echo json_encode(['failure'=>$e->getMessage(),'line'=>$e->getLine(),'checks'=>$checks],JSON_THROW_ON_ERROR)."\n";}
finally{
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    if($actor!==null){if($membership!==null){RBACResolver::revokeModule($membership,'people',$actor);RBACResolver::revokeModule($membership,'placements',$actor);deactivateMembership($actor,$tenant);}
        $pdo->prepare('UPDATE users SET is_active=0,password=NULL,password_hash=NULL WHERE id=? AND tenant_id=?')->execute([$actor,$tenant]);
        $pdo->prepare('UPDATE tenants SET is_active=0 WHERE id=?')->execute([$tenant]);}
    if(realpath($directory)===$directory)foreach(glob($directory.'/*')?:[]as$file)if(is_file($file)&&!is_link($file)&&realpath(dirname($file))===$directory)unlink($file);
    if(!@rmdir($directory))$passed=false;
}
echo json_encode(['passed'=>$passed,'checks_passed'=>count($checks),'scope'=>'Native login, session, dual permissions, public and legacy custom-field APIs on disposable CI database'],JSON_THROW_ON_ERROR)."\n";
exit($passed?0:1);

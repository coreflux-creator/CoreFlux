<?php
/** Native login/session and API permission checks on the disposable CI database. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('CI') !== 'true'
    || getenv('COREFLUX_ENV') !== 'staging' || getenv('DB_NAME') !== 'coreaccounting_ci'
    || !extension_loaded('curl') || !function_exists('proc_open')) {
    fwrite(STDERR, "Disposable CI database and loopback HTTP process required.\n"); exit(2);
}
putenv('COREFLUX_DATA_KEY=' . base64_encode(random_bytes(32)));
putenv('COREFLUX_INTEGRATION_INSTANCE_ID=381e452a-5d11-4e2e-9daa-e9427501898a');
putenv('COREFLUX_QUANTA_PROFILE_RETURN=0');
putenv('COREFLUX_QUANTA_TIME_ACCESS_DELIVERY=0');
putenv('CF_RBAC_BRIDGE_MODE=dual');
require_once __DIR__ . '/../core/quanta/profile_return_delivery.php';
require_once __DIR__ . '/../core/memberships.php';
require_once __DIR__ . '/../core/rbac/permissions.php';
$pdo = getDB();
if (!$pdo || $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'coreaccounting_ci') throw new RuntimeException('Wrong fixture database');
$tenant = 900011;
if ($pdo->query('SELECT id FROM tenants WHERE id=' . $tenant)->fetchColumn()) throw new RuntimeException('Fixture tenant already exists');
$root = dirname(__DIR__); $directory = sys_get_temp_dir() . '/coreflux-quanta-http-' . bin2hex(random_bytes(12));
if (!mkdir($directory, 0700)) throw new RuntimeException('Private fixture directory unavailable');
$actor = null; $membership = null; $server = null; $cookie = $directory . '/cookie.private';
$report = ['scope'=>'Native Core login/session/dual-RBAC/API on disposable CI database', 'checks'=>[],
    'production_changed'=>false, 'live_provider_contacted'=>false, 'provider_writes'=>0, 'passed'=>false];
$expect = static function(bool $ok, string $label) use (&$report): void {
    if (!$ok) { $report['failed_check'] = $label; throw new RuntimeException($label); }
    $report['checks'][] = $label;
};
$actions = ['profile_baseline_preview','profile_baseline_send','profile_baseline_status',
    'profile_review_list','profile_review_preview','profile_review_apply','profile_review_status',
    'profile_return_list','profile_return_status','profile_return_send','profile_return_resume'];
$reviews = array_slice($actions, 3);
$timeControls=['time_control_context','time_control_preview','time_control_pending','time_control_send','time_control_receipt'];
$timeControls=array_merge($timeControls,['save_routes','delete_route']);
$timeControls=array_merge($timeControls,['legacy_time_inventory','legacy_time_preview','legacy_time_pending','legacy_time_send','legacy_time_receipt']);
$timeControls=array_merge($timeControls,['time_revision_preview','time_revision_apply','time_revision_history','time_revision_recent']);
$actions=array_merge($actions,$timeControls);
$counts = static function() use ($pdo): array {
    $out = [];
    foreach (['people','placements','quanta_profile_baselines','quanta_profile_decisions','quanta_profile_returns',
        'time_entries','quanta_time_revisions','payroll_runs','ap_vendors_index','ap_payments','billing_payments','accounting_journal_entries'] as $table) {
        $out[$table] = (int)$pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
    return $out;
};
try {
    $before = $counts();
    $pdo->prepare('INSERT INTO tenants(id,name,is_active,is_simulation,tenant_type) VALUES(?,"Synthetic contact HTTP permission fixture",1,1,"master")')->execute([$tenant]);
    $email = 'quanta-http-' . bin2hex(random_bytes(10)) . '@example.invalid';
    $password = bin2hex(random_bytes(32)); $hash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->prepare('INSERT INTO users(tenant_id,name,email,password,password_hash,role,is_active,is_global_admin)
        VALUES(?,"Synthetic contact reviewer",?,?,?,"admin",1,0)')->execute([$tenant,$email,$hash,$hash]);
    $actor = (int)$pdo->lastInsertId();
    $membership = (int)provisionMembership($actor,$tenant,'admin',
        ['is_primary'=>true,'persona_label'=>'Synthetic contact reviewer','status'=>'active'])['membership_id'];
    $context = ['workspace'=>['id'=>'synthetic-contact-http','name'=>'Synthetic contact HTTP fixture'],
        'contract_version'=>QUANTA_CORE_CONTRACT_VERSION,
        'capabilities'=>['person_links','assignment_metadata','conditional_assignment_updates','durable_delivery_receipts'],
        'execution_side_effects'=>false];
    $key = 'synthetic-http-key-no-live-provider';
    $pdo->prepare('INSERT INTO quanta_connections(tenant_id,status,api_key_ct,connected_by_user_id) VALUES(?,"active",?,?)')
        ->execute([$tenant,encryptField($key),$actor]);
    $connection = ['id'=>(int)$pdo->lastInsertId(),'binding_revision'=>1];
    quantaWorkspaceBind($pdo,$tenant,$actor,$connection,$key,integrationInstanceId(),$context,
        quantaWorkspaceBindingHash($tenant,$connection['id'],$key,integrationInstanceId(),$context));
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException('Loopback fixture port unavailable');
    $port = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1); fclose($socket);
    $fixturePath = $directory . '/fixture.json';
    file_put_contents($fixturePath,json_encode(['port'=>$port,'context'=>$context], JSON_THROW_ON_ERROR)); chmod($fixturePath,0600);
    putenv('COREFLUX_QUANTA_AUTH_HTTP_FIXTURE=1'); putenv('COREFLUX_QUANTA_AUTH_HTTP_CONFIG=' . $fixturePath);
    $server = proc_open([PHP_BINARY,'-d','session.save_path=' . $directory,'-d','display_errors=0',
        '-S','127.0.0.1:' . $port,'-t',$root,__DIR__ . '/fixtures/quanta_profile_http_router.php'],
        [0=>['pipe','r'],1=>['file',$directory . '/http.log','a'],2=>['file',$directory . '/http.log','a']],$pipes,$root);
    if (!is_resource($server)) throw new RuntimeException('Native HTTP server did not start');
    fclose($pipes[0]);
    $request = static function(string $path, ?array $body, bool $signedIn = true, int $pinned = 900011, bool $form = false, ?string $method = null) use ($port,$cookie,&$report): array {
        $curl = curl_init('http://127.0.0.1:' . $port . $path);
        $headers = ['Accept: application/json', 'X-CoreFlux-Tenant-Id: ' . $pinned, 'User-Agent: CoreProfileSyntheticTest', 'X-Request-Id: synthetic-profile-http'];
        $options = [CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>1,CURLOPT_TIMEOUT=>15];
        if ($body !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $form ? http_build_query($body) : json_encode((object)$body, JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: ' . ($form ? 'application/x-www-form-urlencoded' : 'application/json');
        }
        if ($signedIn) { $options[CURLOPT_COOKIEFILE]=$cookie; $options[CURLOPT_COOKIEJAR]=$cookie; }
        if($method!==null)$options[CURLOPT_CUSTOMREQUEST]=$method;
        $options[CURLOPT_HTTPHEADER]=$headers; curl_setopt_array($curl,$options);
        $raw = curl_exec($curl); $status = (int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
        $location = (string)curl_getinfo($curl,CURLINFO_REDIRECT_URL); curl_close($curl);
        if ($raw === false && $path !== '/fixture-ready') throw new RuntimeException('Loopback request failed');
        if ($path !== '/fixture-ready') $report['requests'][] = ['path'=>$path,'status'=>$status,'pinned_tenant'=>$pinned];
        return [$status, is_string($raw) ? json_decode($raw,true) : null, $location];
    };
    $ready = false;
    for ($attempt=0; $attempt<40; $attempt++) {
        if ($request('/fixture-ready',null,false)[0] === 200) { $ready=true; break; }
        if (!proc_get_status($server)['running']) throw new RuntimeException('Native HTTP server stopped');
        usleep(100000);
    }
    $expect($ready,'Native loopback HTTP server is ready');
    $call = static fn($action,$body=[],$signed=true,$pin=900011) => $request('/api/quanta.php?action=' . $action,$body,$signed,$pin);
    $reads = static fn() => is_file($directory . '/provider-read.log') ? (int)count(file($directory . '/provider-read.log')) : 0;
    foreach ($actions as $action) $expect($call($action,[],false)[0]===401,'Anonymous session denied: ' . $action);
    $expect($reads()===0,'Anonymous requests never reach the provider');
    $employment='/modules/people/api/employment_link.php';
    $setup='/modules/people/api/employment_setup.php';$employees='/modules/people/api/employees.php';
    foreach([$setup.'?employee_id=1',$employees.'?id=1']as$route)$expect($request($route,null,false)[0]===401,'Anonymous employee access denied: '.$route);
    $expect($request($setup.'?action=apply',['confirm_setup'=>true,'review_token'=>'invalid'],false)[0]===401,'Anonymous setup mutation denied');
    $expect($request($employment.'?person_id=1',null,false)[0]===401,'Anonymous employment directory denied');
    $expect($request($employment.'?action=apply',['confirm_link'=>true,'review_token'=>'invalid'],false)[0]===401,'Anonymous employment mutation denied');
    [$status,,$location] = $request('/login.php',['username'=>$email,'password'=>$password,'redirect'=>'spa'],true,$tenant,true);
    unset($password,$hash);
    $expect($status===302 && !str_contains($location,'error='),'Native password login creates a real session cookie');
    foreach ($actions as $action) $expect($call($action)[0]===403,'Ungranted reviewer denied: ' . $action);
    $expect($request($employment.'?person_id=1',null)[0]===403,'Missing people grant cannot read employment links');
    foreach([$setup.'?employee_id=1',$employees.'?id=1']as$route)$expect($request($route,null)[0]===403,'Missing people grant cannot inspect employment: '.$route);
    RBACResolver::grantModule($membership,'integrations','read',[$tenant],$actor);
    RBACResolver::grantModule($membership,'people','write',[$tenant],$actor);
    foreach ($actions as $action) $expect($call($action)[0]===403,'Read-only integration access denied: ' . $action);
    RBACResolver::grantModule($membership,'integrations','admin',[$tenant],$actor);
    RBACResolver::revokeModule($membership,'people',$actor);
    foreach ($actions as $action) $expect($call($action)[0]===403,'Missing people access denied: ' . $action);
    RBACResolver::grantModule($membership,'people','read',[$tenant],$actor);
    $expect($request($employment.'?person_id=0',null)[0]===422,'People read admits employment lookup before validating identity');
    $expect($request($setup.'?employee_id=0',null)[0]===422,'People read admits setup lookup before validating employment');
    $expect($request($employees,['legal_first_name'=>'Blocked','legal_last_name'=>'Creation'])[0]===403,'People read cannot create employment');
    foreach(['preview','apply']as$action)$expect($request($setup.'?action='.$action,[])[0]===403,'People read cannot change employment setup: '.$action);
    $expect($request($setup.'?action=history&employee_id=1',null)[0]===403,'Setup review history requires People manage permission');
    foreach(['preview','apply']as$action)$expect($request($employment.'?action='.$action,[])[0]===403,'People read cannot change employment identity: '.$action);
    foreach ($reviews as $action) $expect($call($action)[0]===403,'Read-only people access cannot review or return: ' . $action);
    $expect($call('profile_baseline_preview',['person_id'=>0])[0]===422,'Snapshot preview admits people-read before validating the chosen person');
    $expect($reads()===0,'All permission denials occur before provider reads');
    RBACResolver::grantModule($membership,'people','write',[$tenant],$actor);
    $expect($request($employment.'?action=preview',[])[0]===422,'People write admits employment review and validates its fields');
    $expect($request($setup.'?action=preview',[])[0]===422,'People write admits setup review and validates its fields');
    foreach([false,1,'true',null]as$confirmation)$expect($request($setup.'?action=apply',['confirm_setup'=>$confirmation,'review_token'=>'invalid'])[0]===422,'Employment setup requires literal confirmation');
    $expect($request($setup.'?employee_id=1',null,true,999)[0]===403,'Foreign pinned company cannot inspect employment setup');
    foreach(['date_of_birth'=>'2000-01-01','ssn'=>'111223333']as$field=>$value)$expect($request($employees,['legal_first_name'=>'Blocked','legal_last_name'=>'PII',$field=>$value])[0]===403,'People write cannot create restricted identity field: '.$field);
    $personRoute='/modules/people/api/people.php';
    $personBody=['first_name'=>'Synthetic','last_name'=>'Private Profile','email_primary'=>'private-profile-http@example.invalid','classification'=>'candidate'];
    foreach(['gender'=>'test','marital_status'=>'test'] as $field=>$value) {
        $expect($request($personRoute,$personBody+[$field=>$value])[0]===403,'People write cannot create private person demographic: '.$field);
    }
    $expect($request($personRoute,$personBody+['user_id'=>$actor])[0]===422,'Person creation cannot silently accept a Core account association');
    $pdo->prepare('INSERT INTO people(tenant_id,first_name,last_name,email_primary,classification,gender,marital_status) VALUES(?,"Synthetic","Private Profile","private-profile-http@example.invalid","candidate","original","original")')->execute([$tenant]);
    $privatePerson=(int)$pdo->lastInsertId();
    foreach(['gender','marital_status'] as $field)foreach(['changed',null,''] as $value) {
        $expect($request($personRoute.'?id='.$privatePerson,[$field=>$value],true,$tenant,false,'PATCH')[0]===403,'Private person change or clear requires private permission: '.$field);
    }
    foreach(['user_id'=>$actor,'worker_class'=>'c2c','worker_class_meta_json'=>'{}','resume_storage_object_id'=>1,'updated_at'=>'2000-01-01 00:00:00','person_number'=>'P-FAKE','future_column'=>'unexpected'] as $field=>$value) {
        $expect($request($personRoute.'?id='.$privatePerson,['preferred_name'=>'Must not partially apply',$field=>$value],true,$tenant,false,'PATCH')[0]===422,'Person editor refuses internal identity/routing/storage field: '.$field);
    }
    [$status,$personView]=$request($personRoute.'?id='.$privatePerson,null);
    $expect($status===200&&!array_intersect(['gender','marital_status'],array_keys($personView['person']??[])),'Ordinary person read omits private demographics');
    $unchanged=$pdo->query('SELECT gender,marital_status,preferred_name,user_id FROM people WHERE id='.$privatePerson)->fetch(PDO::FETCH_ASSOC);
    $expect($unchanged===['gender'=>'original','marital_status'=>'original','preferred_name'=>null,'user_id'=>null],'Rejected mixed person edits leave every original value intact');
    $expect($request($personRoute.'?id='.$privatePerson,['preferred_name'=>'Allowed profile'],true,$tenant,false,'PATCH')[0]===200,'Allowed ordinary person profile edit still works');
    $expect($request($personRoute.'?id='.$privatePerson,['preferred_name'=>'Foreign edit'],true,999,false,'PATCH')[0]===403,'Foreign pinned company cannot edit person profile');
    $privateHistory='/modules/people/api/audit_pii.php?target_person_id='.$privatePerson;
    $expect($request($privateHistory,null)[0]===403,'Ordinary profile writer cannot read private access history');
    $pdo->prepare('INSERT INTO people_employees(tenant_id,employee_number,legal_first_name,legal_last_name,ssn_last4,date_of_birth) VALUES(?,"HTTP-PRIVATE","Synthetic","Employee","3333","2000-01-01")')->execute([$tenant]);
    $privateEmployee=(int)$pdo->lastInsertId();
    [$status,$privateView]=$request($employees.'?id='.$privateEmployee,null);
    $expect($status===200&&!array_intersect(['ssn_last4','ssn_masked','date_of_birth'],array_keys($privateView['employee']??[]))&&($privateView['can_view_pii']??null)===false,'People write sees basic employment without SSN or birth date');
    $expect($request($employees.'?id='.$privateEmployee,['date_of_birth'=>'2001-01-01'],true,$tenant,false,'PATCH')[0]===403,'People write cannot change the birth date through the legacy endpoint');
    foreach(['status'=>'active','job_title'=>'Bypass','employment_type'=>'full_time']as$field=>$value)$expect($request($employees.'?id='.$privateEmployee,[$field=>$value],true,$tenant,false,'PATCH')[0]===409,'Direct employment edit cannot bypass setup review: '.$field);
    $expect($request($employees,['legal_first_name'=>'Blocked','legal_last_name'=>'Activation','status'=>'active'])[0]===409,'Legacy employment creation cannot bypass pending activation review');
    RBACResolver::grantModule($membership,'people','admin',[$tenant],$actor);
    $expect($request($personRoute.'?id='.$privatePerson,['gender'=>'reviewed','marital_status'=>null,'hire_date'=>'2026-12-01'],true,$tenant,false,'PATCH')[0]===200,'Private-authorized person change, explicit clear and employment fact work together');
    [$status,$personView]=$request($personRoute.'?id='.$privatePerson.'&include_pii=1',null);
    $expect($status===200&&($personView['person']['gender']??null)==='reviewed'&&array_key_exists('marital_status',$personView['person'])&&$personView['person']['marital_status']===null,'Private-authorized read returns exact reviewed values');
    [$status,$history]=$request($privateHistory,null);
    $expect($status===200&&count($history['rows']??[])===2,'Private profile changes and reads are available in the native access history');
    foreach($history['rows'] as $receipt)$expect($receipt['record_kind']==='person'&&(int)$receipt['target_person_id']===$privatePerson&&$receipt['target_employee_id']===null&&!str_contains($receipt['fields_json'],'reviewed')&&$receipt['user_agent']==='CoreProfileSyntheticTest'&&$receipt['request_id']==='synthetic-profile-http','Private access history qualifies person identity, retains request context and stores field names only');
    $expect(($personView['person']['hire_date']??null)==='2026-12-01','Caller-owned private edit transaction includes the employment helper');
    $pdo->prepare('INSERT INTO people_pii_access_log(tenant_id,user_id,employee_id,field,action) VALUES(?,?,?,"ssn","view_full")')->execute([$tenant,$actor,$privatePerson]);
    $legacyAudit=(int)$pdo->lastInsertId();
    try {
        [$status,$sameNumber]=$request($privateHistory,null);
        $expect($status===200&&count($sameNumber['rows']??[])===2,'Employee history with the same numeric ID is not a person history match');
        [$status,$legacyHistory]=$request('/modules/people/api/audit_pii.php?event_type=view_full',null);
        $legacyRows=array_values(array_filter($legacyHistory['rows']??[],static fn($row)=>$row['id']==='legacy-employee:'.$legacyAudit));
        $expect($status===200&&count($legacyRows)===1&&$legacyRows[0]['record_kind']==='employee'&&$legacyRows[0]['target_person_id']===null&&(int)$legacyRows[0]['target_employee_id']===$privatePerson,'Original employee history remains readable under its original identity');
    } finally { $pdo->prepare('DELETE FROM people_pii_access_log WHERE id=? AND tenant_id=?')->execute([$legacyAudit,$tenant]); }
    $expect($request($privateHistory,null,true,999)[0]===403,'Foreign company cannot read private profile history');
    $pdo->exec("CREATE TRIGGER cf_profile_audit_failure BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.event='people.private_access' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic private profile audit failure'; END IF; END");
    try {
        $beforePerson=$pdo->query('SELECT * FROM people WHERE id='.$privatePerson)->fetch(PDO::FETCH_ASSOC);
        $expect($request($personRoute.'?id='.$privatePerson,['preferred_name'=>'Must roll back','gender'=>'blocked','hire_date'=>'2026-12-02'],true,$tenant,false,'PATCH')[0]===503,'Private profile update refuses to succeed without its audit');
        $expect($pdo->query('SELECT * FROM people WHERE id='.$privatePerson)->fetch(PDO::FETCH_ASSOC)===$beforePerson,'Audit failure rolls back ordinary and private profile changes together');
        [$status,$blockedRead]=$request($personRoute.'?id='.$privatePerson.'&include_pii=1',null);
        $expect($status===500&&!isset($blockedRead['person']),'Private read releases no person data when access audit fails');
        $createBody=array_replace($personBody,['email_primary'=>'blocked-private-create@example.invalid','gender'=>'blocked']);
        $expect($request($personRoute,$createBody)[0]===500,'Private person creation refuses an unaudited partial success');
        $expect((int)$pdo->query("SELECT COUNT(*) FROM people WHERE tenant_id=900011 AND email_primary='blocked-private-create@example.invalid'")->fetchColumn()===0,'Failed private creation leaves no person record');
    } finally { $pdo->exec('DROP TRIGGER cf_profile_audit_failure'); }
    $expect($request($personRoute.'?id='.$privatePerson,['user_id'=>$actor],true,$tenant,false,'PATCH')[0]===422,'Even a private-data administrator cannot bypass person identity linking');
    $pdo->prepare('DELETE FROM people WHERE id=? AND tenant_id=?')->execute([$privatePerson,$tenant]);
    [$status,$privateView]=$request($employees.'?id='.$privateEmployee,null);
    $expect($status===200&&($privateView['employee']['ssn_masked']??null)==='***-**-3333'&&($privateView['employee']['date_of_birth']??null)==='2000-01-01'&&!isset($privateView['employee']['ssn_cipher'],$privateView['employee']['ssn_hash']),'People admin receives only the permitted identity presentation');
    $pdo->prepare('DELETE FROM people_employees WHERE id=? AND tenant_id=?')->execute([$privateEmployee,$tenant]);
    RBACResolver::grantModule($membership,'people','write',[$tenant],$actor);
    foreach([false,1,'true',null]as$confirmation)$expect($request($employment.'?action=apply',['confirm_link'=>$confirmation,'review_token'=>'invalid'])[0]===422,'Employment link requires literal confirmation');
    $expect($request($employment.'?person_id=1',null,true,999)[0]===403,'Foreign pinned company cannot inspect employment identity');
    foreach (['profile_review_list','profile_return_list'] as $action) {
        [$status,$data] = $call($action);
        $expect($status===200 && ($data['rows']??null)===[],'Authorized native session reads only its empty queue: ' . $action);
    }
    $expect($reads()>0,'Authorized listing reaches only the synthetic provider reader');
    $priorReads=$reads();
    foreach (['profile_review_list','profile_return_list'] as $action) $expect($call($action,[],true,999)[0]===403,'Foreign pinned company denied: ' . $action);
    $expect($reads()===$priorReads,'Foreign company cannot reach provider context');
    foreach (['profile_return_send','profile_return_resume'] as $action) $expect($call($action,[
        'change_id'=>'cpc_' . str_repeat('a',64),'confirm_return'=>false])[0]===422,'Return requires explicit confirmation: ' . $action);
    $expect($call('profile_review_apply',['review_token'=>'invalid','decision'=>'accepted','reason'=>'','confirm_decision'=>false])[0]===422,
        'Core contact decision requires explicit confirmation');
    $expect($call('profile_return_send',['change_id'=>'cpc_' . str_repeat('a',64),'confirm_return'=>true])[0]===409,
        'Even authorized return remains blocked by the disabled pilot gate');
    $priorReads=$reads();
    foreach($timeControls as $action)$expect($call($action)[0]===403,'Integration admin alone cannot manage time setup: '.$action);
    RBACResolver::grantModule($membership,'placements','read',[$tenant],$actor);
    RBACResolver::grantModule($membership,'time','read',[$tenant],$actor);
    foreach($timeControls as $action)$expect($call($action)[0]===403,'Time read permission cannot configure assignment eligibility: '.$action);
    RBACResolver::grantModule($membership,'time','write',[$tenant],$actor);
    [$status,$data]=$call('time_control_pending');
    $expect($status===200&&($data['rows']??null)===[],'Time reviewer can read its local recovery queue without a source catalog');
    foreach(['time_control_context','time_control_preview'] as $action)$expect($call($action,['person_id'=>0,'worksite_id'=>'site-1'])[0]===422,'Authorized setup validates local person selection: '.$action);
    $envelope=['delivery_id'=>1,'review_hash'=>str_repeat('a',64)];
    foreach([false,1,'true',null] as $confirmation)$expect($call('time_control_send',$envelope+['confirm_time_control'=>$confirmation])[0]===422,'Time setup requires literal confirmation');
    $expect($call('time_control_send',$envelope+['confirm_time_control'=>true])[0]===409,'Disabled operational rollout blocks even confirmed time setup');
    [$status,$data]=$call('legacy_time_pending');
    $expect($status===200&&($data['rows']??null)===[],'Time reviewer reads its local historical recovery queue');
    $expect($call('legacy_time_inventory',['person_id'=>0,'worksite_id'=>'site-1'])[0]===422,'Historical inventory requires a local person');
    $expect($call('legacy_time_preview',['source_entry_id'=>'invalid/path'])[0]===422,'Historical review rejects malformed source identity');
    foreach([false,1,'true',null] as $confirmation)$expect($call('legacy_time_send',$envelope+['confirm_existing_time'=>$confirmation])[0]===422,'Historical linking requires literal confirmation');
    $expect($call('legacy_time_send',$envelope+['confirm_existing_time'=>true])[0]===409,'Historical linking stays disabled until rollout');
    foreach(['time_revision_preview','time_revision_history']as$action)$expect($call($action,['source_entry_id'=>'invalid/path'])[0]===422,'Correction route validates source identity: '.$action);
    $revision=['source_entry_id'=>'missing-source','review_hash'=>str_repeat('a',64),'reason'=>'Synthetic correction'];
    foreach([false,1,'true',null]as$confirmation)$expect($call('time_revision_apply',$revision+['confirm_revision'=>$confirmation])[0]===422,'Correction requires literal confirmation before source access');
    $pdo->exec('UPDATE quanta_connections SET status="revoked" WHERE tenant_id='.$tenant);
    foreach(['time_revision_history','time_revision_recent']as$action){
        [$status,$data]=$call($action,['source_entry_id'=>'missing-source']);
        $expect($status===200&&($data['items']??null)===[],'Authorized correction history remains readable with provider disconnected: '.$action);
    }
    $pdo->exec('UPDATE quanta_connections SET status="active" WHERE tenant_id='.$tenant);
    foreach($timeControls as $action)$expect($call($action,[],true,999)[0]===403,'Foreign company cannot manage time setup: '.$action);
    RBACResolver::grantModule($membership,'time','read',[$tenant],$actor);
    foreach($timeControls as $action)$expect($call($action)[0]===403,'Same session loses time setup rights on downgrade: '.$action);
    $expect($reads()===$priorReads,'Time setup permission checks make no provider requests');
    RBACResolver::grantModule($membership,'people','read',[$tenant],$actor);
    foreach ($reviews as $action) $expect($call($action)[0]===403,'Same session loses decision rights after grant downgrade: ' . $action);
    RBACResolver::revokeModule($membership,'integrations',$actor);
    foreach ($actions as $action) $expect($call($action)[0]===403,'Same session loses integration access after revocation: ' . $action);
    $financialContext='/api/staffing_reconciliation.php?action=context';
    $financialRead='/api/staffing_reconciliation.php';$evidence='/api/staffing_financial_evidence.php';
    $pdo->prepare('INSERT INTO ap_vendors_index(tenant_id,vendor_name,tax_id_last4,tax_id_full_ct,payment_account_ct,payment_account_last4,payment_routing_ct,payment_routing_last4,kms_key_version,kms_key_version_payment,default_remit_to_json) VALUES(?,"Synthetic private vendor","3333",?, ?,"0000",?,"1111","synthetic","synthetic",?)')
        ->execute([$tenant,encryptField('111223333'),encryptField('0000000000'),encryptField('011000111'),'{"unknown_secret":"must-not-leak"}']);
    $privateVendor=(int)$pdo->lastInsertId();$vendorRoute='/modules/ap/api/vendors.php?id='.$privateVendor;
    $vendorBefore=$pdo->query('SELECT * FROM ap_vendors_index WHERE id='.$privateVendor)->fetch(PDO::FETCH_ASSOC);
    $expect($request($vendorRoute,null,false)[0]===401,'Anonymous vendor detail is denied');
    $expect($request($vendorRoute,null)[0]===403,'People and time grants do not reveal vendor details');
    $financialCalls=[[$financialContext,null],[$financialRead,[]],[$evidence.'?action=history',[]],[$evidence.'?action=download',[]],[$evidence.'?action=preview',[]],[$evidence.'?action=save',[]]];
    foreach($financialCalls as[$path,$body])$expect($request($path,$body,false)[0]===401,'Anonymous financial review denied: '.$path);
    $expect($request($financialContext,null)[0]===403,'A people/time reviewer alone cannot read financial evidence');
    $financialModules=['accounting','ap','billing','placements','people','time','payroll'];
    foreach($financialModules as$module)RBACResolver::grantModule($membership,$module,'read',[$tenant],$actor);
    [$status,$vendorView]=$request($vendorRoute,null);
    $expect($status===200&&($vendorView['vendor']['vendor_number']??null)==='V-'.$privateVendor&&$vendorView['vendor']['payment_account_last4']==='0000'&&$vendorView['vendor']['tax_id_last4']==='3333','AP reader retains canonical vendor identity and permitted masked values');
    foreach(['tax_id_full_ct','tax_id_full','payment_account_ct','payment_routing_ct','kms_key_version','kms_key_version_payment','default_remit_to_json']as$field)
        $expect(!array_key_exists($field,$vendorView['vendor']??[]),'Vendor detail excludes private or opaque field: '.$field);
    $expect($request($vendorRoute.'&reveal_pii=1',null)[0]===403,'AP read alone cannot reveal a full tax identifier');
    $expect($request($vendorRoute,null,true,999)[0]===403,'Foreign company cannot access vendor detail');
    RBACResolver::grantModule($membership,'ap','admin',[$tenant],$actor);
    [$status,$privateVendorView]=$request($vendorRoute.'&reveal_pii=1',null);
    $expect($status===200&&($privateVendorView['vendor']['tax_id_full']??null)==='111223333'&&!array_intersect(['payment_account_ct','payment_routing_ct','tax_id_full_ct'],array_keys($privateVendorView['vendor'])),'Authorized tax reveal retains its separate path without exposing encrypted payment material');
    RBACResolver::grantModule($membership,'ap','read',[$tenant],$actor);
    $expect($pdo->query('SELECT * FROM ap_vendors_index WHERE id='.$privateVendor)->fetch(PDO::FETCH_ASSOC)===$vendorBefore,'Vendor reads and private reveal preserve the original vendor row');
    $pdo->prepare('DELETE FROM ap_vendors_index WHERE tenant_id=? AND id=?')->execute([$tenant,$privateVendor]);
    require_once __DIR__ . '/fixtures/ap_vendor_directory_http_cases.php';
    apVendorDirectoryHttpCases($pdo,$tenant,$actor,$request,$expect);
    require_once __DIR__ . '/fixtures/company_source_http_cases.php';
    companySourceHttpCases($pdo,$tenant,$actor,$request,$expect);
    require_once __DIR__ . '/fixtures/company_merge_http_cases.php';
    companyMergeHttpCases($pdo,$tenant,$actor,$request,$expect);
    [$status,$context]=$request($financialContext,null);
    $expect($status===200&&$context['tenant_id']===$tenant&&$context['permissions']['workbook_manage']===false,'Financial reader gets scoped report access without bank administration');
    foreach($financialModules as$module){
        RBACResolver::revokeModule($membership,$module,$actor);
        $expect($request($financialContext,null)[0]===403,'Financial context requires the underlying '.$module.' read grant');
        RBACResolver::grantModule($membership,$module,'read',[$tenant],$actor);
    }
    foreach([[$financialRead,[]],[$evidence.'?action=history',[]],[$evidence.'?action=download',[]]]as[$path,$body])
        $expect($request($path,$body)[0]===422,'Reader reaches input validation for read-only evidence: '.$path);
    foreach(['preview','save']as$action)$expect($request($evidence.'?action='.$action,[])[0]===403,'Reader cannot change workbook support: '.$action);
    $expect($request('/api/purepay_connection.php?action=status',null)[0]===403,'Financial report access does not grant connection administration');
    foreach($financialCalls as[$path,$body])$expect($request($path,$body,true,999)[0]===403,'Foreign company pin cannot use financial review: '.$path);
    RBACResolver::grantModule($membership,'accounting','admin',[$tenant],$actor);
    [$status,$context]=$request($financialContext,null);
    $expect($status===200&&$context['permissions']['workbook_manage']===true,'Existing authorized evidence manager retains review controls');
    foreach(['preview','save']as$action)$expect($request($evidence.'?action='.$action,[])[0]===422,'Manager reaches evidence input validation: '.$action);
    RBACResolver::grantModule($membership,'accounting','read',[$tenant],$actor);
    [$status,$context]=$request($financialContext,null);
    $expect($status===200&&$context['permissions']['workbook_manage']===false,'Same session loses evidence management after downgrade');
    $request('/logout.php',null,true);
    $expect($call('profile_return_list')[0]===401,'Native logout invalidates the session');
    $expect(!is_file($directory . '/provider-write.log'),'No provider mutation was attempted');
    $expect($before===$counts(),'Permission checks create no people, placements, decisions, returns or financial records');
    $report['passed']=true;
} catch (Throwable $e) {
    $report['failure_class']=get_class($e); $report['failure_message']=$e->getMessage(); $report['failure_line']=$e->getLine();
} finally {
    if ($actor !== null) {
        try {
            $pdo->beginTransaction();
            if ($membership !== null) {
                foreach (['integrations','people','placements','time','accounting','ap','billing','payroll'] as $module) RBACResolver::revokeModule($membership,$module,$actor);
                deactivateMembership($actor,$tenant);
            }
            $pdo->prepare('UPDATE users SET is_active=0,password=NULL,password_hash=NULL WHERE id=? AND tenant_id=?')->execute([$actor,$tenant]);
            $pdo->prepare('UPDATE quanta_connections SET status="revoked",api_key_ct=NULL WHERE tenant_id=?')->execute([$tenant]);
            $pdo->prepare('UPDATE quanta_workspace_bindings SET state="revoked",revoked_at=NOW() WHERE tenant_id=?')->execute([$tenant]);
            $pdo->prepare('UPDATE tenants SET is_active=0 WHERE id=?')->execute([$tenant]);
            $pdo->commit();
            $q=$pdo->prepare('SELECT COUNT(*) FROM membership_module_access WHERE membership_id=?');
            $q->execute([$membership]); $grantsRemoved=(int)$q->fetchColumn()===0;
            $q=$pdo->prepare("SELECT COUNT(*) FROM users u
                JOIN tenant_memberships tm ON tm.user_id=u.id AND tm.tenant_id=u.tenant_id
                JOIN user_tenants ut ON ut.user_id=u.id AND ut.tenant_id=u.tenant_id
                JOIN tenants t ON t.id=u.tenant_id
                WHERE u.id=? AND u.tenant_id=? AND u.email=? AND u.is_active=0 AND u.is_global_admin=0
                AND u.password IS NULL AND u.password_hash IS NULL AND tm.id=?
                AND tm.status='revoked' AND ut.status='inactive' AND t.is_active=0");
            $q->execute([$actor,$tenant,$email,$membership]);
            $report['synthetic_actor_retired']=$grantsRemoved && (int)$q->fetchColumn()===1;
            $q=$pdo->prepare('SELECT COUNT(*) FROM quanta_connections WHERE tenant_id=? AND (status<>"revoked" OR api_key_ct IS NOT NULL)');
            $q->execute([$tenant]); $connectionsRevoked=(int)$q->fetchColumn()===0;
            $q=$pdo->prepare('SELECT COUNT(*) FROM quanta_workspace_bindings WHERE tenant_id=? AND (state<>"revoked" OR revoked_at IS NULL)');
            $q->execute([$tenant]);
            $report['synthetic_connection_retired']=$connectionsRevoked && (int)$q->fetchColumn()===0;
            if (!$report['synthetic_actor_retired'] || !$report['synthetic_connection_retired']) $report['passed']=false;
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $report['passed']=false; $report['retirement_failed']=true; }
    }
    if (is_resource($server)) { proc_terminate($server); proc_close($server); $report['owned_server_stopped']=true; }
    // This uniquely created directory owns all cookie/session files. No recursive removal.
    if (realpath($directory)===$directory) foreach (glob($directory . '/*') ?: [] as $file) {
        if (is_file($file) && !is_link($file) && realpath(dirname($file))===$directory) unlink($file);
    }
    $report['private_session_files_removed']=@rmdir($directory);
    if (!$report['private_session_files_removed']) $report['passed']=false;
}
$report['checks_passed']=count($report['checks']);
echo json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES) . "\n";
exit($report['passed'] ? 0 : 1);

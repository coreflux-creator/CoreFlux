<?php
/** Real cookie login and contract API round trip, on invented disposable-CI records only. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('CI') !== 'true'
    || getenv('COREFLUX_ENV') !== 'staging' || getenv('DB_NAME') !== 'coreaccounting_ci'
    || !extension_loaded('curl') || !function_exists('proc_open')) exit(2);
putenv('CF_RBAC_BRIDGE_MODE=dual');
require_once __DIR__ . '/../core/jobdiva/sync.php';
require_once __DIR__ . '/../modules/placements/lib/economics_edit.php';
require_once __DIR__ . '/../modules/placements/lib/commercial_ownership.php';
require_once __DIR__ . '/../core/memberships.php';
require_once __DIR__ . '/../core/rbac/permissions.php';
$pdo = getDB();
if (!$pdo || $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'coreaccounting_ci') exit(2);
$tenant = 900012; $foreignTenant = 900013;
if ($pdo->query('SELECT id FROM tenants WHERE id IN (900012,900013)')->fetchColumn()) throw new RuntimeException('Fixture tenants already exist');
$root = dirname(__DIR__); $directory = sys_get_temp_dir() . '/coreflux-contract-http-' . bin2hex(random_bytes(12));
if (!mkdir($directory, 0700)) throw new RuntimeException('Private fixture directory unavailable');
$actor = null; $membership = null; $server = null; $cookie = $directory . '/cookie.private';
$report = ['scope'=>'Native login, dual RBAC, tenant isolation and stored contract HTTP round trip',
    'checks'=>[], 'requests'=>[], 'production_changed'=>false, 'live_provider_contacted'=>false, 'passed'=>false];
$expect = static function(bool $ok, string $label) use (&$report): void {
    if (!$ok) { $report['failed_check'] = $label; throw new RuntimeException($label); }
    $report['checks'][] = $label;
};
// Hash complete rows, not just counts: denials must not change values or identities.
$snapshot = static function(array $tables) use ($pdo): array {
    $out=[];
    foreach ($tables as $table) {
        $rows=$pdo->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
        $encoded=array_map(static fn($row)=>json_encode($row,JSON_THROW_ON_ERROR),$rows); sort($encoded);
        $out[$table]=hash('sha256',json_encode($encoded,JSON_THROW_ON_ERROR));
    }
    return $out;
};
$graphTables=['people','placements','placement_rates','placement_economic_parties','placement_corp_details',
    'staffing_operating_cycles','staffing_clients','companies','external_entity_mappings'];
$financialTables=['accounting_events','accounting_journal_entries','accounting_journal_entry_lines',
    'payroll_runs','ap_payments','billing_payments'];
$seed = static function(int $tid, string $start) use ($pdo): array {
    setRequestTenantId($tid); $_SESSION['tenant_id']=$tid;
    $pdo->prepare('INSERT INTO tenants(id,name,is_active,is_simulation,tenant_type) VALUES(?,?,1,1,"master")')
        ->execute([$tid,'Synthetic contract HTTP ' . $tid]);
    $pdo->prepare('INSERT INTO accounting_entities(tenant_id,code,legal_name,country,base_currency,active)
        VALUES(?,"HTTP","Synthetic contract HTTP LLC","US","USD",1)')->execute([$tid]);
    $person=scopedInsert('people',['first_name'=>'Synthetic HTTP','last_name'=>(string)$tid,'classification'=>'1099',
        'email_primary'=>'contract-'.$tid.'@example.invalid','external_id'=>'jd:'.$start.'2']);
    $company=companiesUpsertByName($tid,'Synthetic HTTP Billing '.$tid,[],['client']);
    $client=staffingClientEnsureForCompany($tid,$company,'Synthetic HTTP Billing '.$tid)['client_id'];
    $placement=scopedInsert('placements',['person_id'=>$person,'title'=>'Synthetic contract before review','status'=>'on_hold',
        'engagement_type'=>'1099','start_date'=>'2026-10-01','end_date'=>'2026-12-31','external_id'=>'jd:'.$start,
        'client_id'=>$client,'end_client_company_id'=>$company,'end_client_name'=>'Synthetic HTTP Billing '.$tid,
        'client_bill_cycle'=>'monthly','client_payment_terms_override'=>'NET30','vendor_pay_cycle'=>'biweekly',
        'vendor_payment_terms_override'=>'NET30','vendor_pwp_enabled'=>0,'worksite_country'=>'US']);
    scopedInsert('placement_rates',['placement_id'=>$placement,'effective_from'=>'2026-10-01','bill_rate'=>100,'pay_rate'=>50,'approved_at'=>'2026-10-01 12:00:00']);
    placementEconomicsReconcile($tid,$placement);
    $q=$pdo->prepare('SELECT id FROM placement_economic_parties WHERE tenant_id=? AND placement_id=? AND source_ref=?');
    $q->execute([$tid,$placement,'placement:bill_to']); $ar=(int)$q->fetchColumn();
    $q->execute([$tid,$placement,'worker:'.$person]); $ap=(int)$q->fetchColumn();
    placementEconomicsApplyNativePartyEdit($tid,$ar,['payment_terms'=>'NET10','cadence'=>'weekly']);
    placementEconomicsApplyNativePartyEdit($tid,$ap,['payment_terms'=>'PWP_NET15','pwp_enabled'=>true,'cadence'=>'weekly']);
    $payload=['startId'=>$start,'candidateId'=>$start.'2','jobId'=>$start.'3','startDate'=>'2026-10-01',
        'title'=>'Synthetic reviewed source title','status'=>'on_hold','endClientName'=>'Synthetic HTTP Billing '.$tid,'worksiteCountry'=>'United States',
        '_jd_contract'=>['start_id'=>$start,'engagement_type'=>'1099','placement_status'=>'on_hold','start_date'=>'2026-10-01','end_date'=>'2026-12-31',
            'client_company_name'=>'Synthetic HTTP Billing '.$tid,'end_client_name'=>'Synthetic HTTP Billing '.$tid,
            'client_bill_cycle'=>'monthly','vendor_pay_cycle'=>'biweekly','client_payment_terms'=>'NET60','vendor_payment_terms'=>'NET30',
            'paid_when_paid'=>false,'worksite_country'=>'United States','worksite_city'=>'Hamilton',
            'bill_rate'=>100,'pay_rate'=>50,'bill_rate_unit'=>'hour','pay_rate_unit'=>'hour','currency'=>'USD']];
    mappingUpsert($tid,'jobdiva','jobdiva_assignment',$start,$placement,$payload);
    return compact('person','company','client','placement','ar','ap','start');
};
try {
    $financialBefore=$snapshot($financialTables);
    $own=$seed($tenant,'99190001'); $foreign=$seed($foreignTenant,'99190011');
    setRequestTenantId($tenant); $_SESSION['tenant_id']=$tenant;
    $foreignBefore=jobdivaPlacementProjectionAuditSnapshot($foreignTenant,$foreign['placement']);
    $email='contract-http-'.bin2hex(random_bytes(10)).'@example.invalid';
    $password=bin2hex(random_bytes(32)); $hash=password_hash($password,PASSWORD_DEFAULT);
    $pdo->prepare('INSERT INTO users(tenant_id,name,email,password,password_hash,role,is_active,is_global_admin)
        VALUES(?,"Synthetic contract reviewer",?,?,?,"admin",1,0)')->execute([$tenant,$email,$hash,$hash]);
    $actor=(int)$pdo->lastInsertId();
    $membership=(int)provisionMembership($actor,$tenant,'admin',['is_primary'=>true,'persona_label'=>'Synthetic contract reviewer','status'=>'active'])['membership_id'];
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
    if (!$socket) throw new RuntimeException('Loopback fixture port unavailable');
    $port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1); fclose($socket);
    putenv('COREFLUX_CONTRACT_HTTP_FIXTURE=1'); putenv('COREFLUX_CONTRACT_HTTP_PORT='.$port);
    $server=proc_open([PHP_BINARY,'-d','session.save_path='.$directory,'-d','display_errors=0',
        '-S','127.0.0.1:'.$port,'-t',$root,__DIR__.'/fixtures/placement_contract_http_router.php'],
        [0=>['pipe','r'],1=>['file',$directory.'/http.log','a'],2=>['file',$directory.'/http.log','a']],$pipes,$root);
    if (!is_resource($server)) throw new RuntimeException('Native HTTP server did not start');
    fclose($pipes[0]);
    $request=static function(string $method,string $path,?array $body=null,bool $signed=true,int $pin=900012,bool $form=false) use ($port,$cookie,&$report): array {
        $ch=curl_init('http://127.0.0.1:'.$port.$path);
        $headers=['Accept: application/json','X-CoreFlux-Tenant-Id: '.$pin];
        $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>1,CURLOPT_TIMEOUT=>20,CURLOPT_CUSTOMREQUEST=>$method];
        if ($body!==null) { $options[CURLOPT_POSTFIELDS]=$form?http_build_query($body):json_encode((object)$body,JSON_THROW_ON_ERROR); $headers[]='Content-Type: '.($form?'application/x-www-form-urlencoded':'application/json'); }
        if ($signed) { $options[CURLOPT_COOKIEFILE]=$cookie; $options[CURLOPT_COOKIEJAR]=$cookie; }
        $options[CURLOPT_HTTPHEADER]=$headers; curl_setopt_array($ch,$options);
        $raw=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $location=(string)curl_getinfo($ch,CURLINFO_REDIRECT_URL); curl_close($ch);
        if ($raw===false && $path!=='/fixture-ready') throw new RuntimeException('Loopback request failed');
        $data=is_string($raw)?json_decode($raw,true):null;
        if ($path!=='/fixture-ready') $report['requests'][]=['method'=>$method,'path'=>$path,'status'=>$status,'pinned_tenant'=>$pin,
            'error'=>is_array($data)?($data['error']??null):null];
        return [$status,$data,$location];
    };
    $ready=false;
    for ($i=0;$i<40;$i++) { if ($request('GET','/fixture-ready',null,false)[0]===200) { $ready=true; break; } if (!proc_get_status($server)['running']) throw new RuntimeException('Native HTTP server stopped'); usleep(100000); }
    $expect($ready,'Native loopback server is ready');
    $economics='/modules/placements/api/economics.php'; $stored='/modules/placements/api/jobdiva_reconciliation.php';
    $reviewPath=$economics.'?action=ownership_review&placement_id='.$own['placement'];
    $releasePath=$economics.'?action=release_ownership&placement_id='.$own['placement'];
    $patchPath=$economics.'?id='.$own['ar'];
    $previewPath=$stored.'?action=stored_preview'; $applyPath=$stored.'?action=stored_apply';
    $release=['confirm'=>'RELEASE_COMMERCIAL_OWNERSHIP','groups'=>['client_terms'],'review_token'=>str_repeat('0',64)];
    $apply=['confirm'=>'APPLY_STORED_JOBDIVA_ASSIGNMENTS','selected_start_ids'=>[$own['start']],'dry_run_token'=>str_repeat('0',64),'per_page'=>20];
    $operations=[['GET',$reviewPath,null],['POST',$releasePath,$release],['PATCH',$patchPath,['payment_terms'=>'NET15']],['POST',$previewPath,[]],['POST',$applyPath,$apply]];
    $unchanged=$snapshot($graphTables);
    foreach ($operations as [$method,$path,$body]) $expect($request($method,$path,$body,false)[0]===401,'Anonymous denied: '.$method.' '.$path);
    [$status,,$location]=$request('POST','/login.php',['username'=>$email,'password'=>$password,'redirect'=>'spa'],true,$tenant,true); unset($password,$hash);
    $expect($status===302&&!str_contains($location,'error='),'Native password login creates a real cookie session');
    foreach ($operations as [$method,$path,$body]) $expect($request($method,$path,$body)[0]===403,'No module grant denied: '.$method.' '.$path);
    RBACResolver::grantModule($membership,'placements','read',[$tenant],$actor);
    [$status,$review]=$request('GET',$reviewPath);
    $expect($status===200&&$review['placement_id']===$own['placement']&&count($review['groups'])===4,'Placement reader sees the four actual ownership groups');
    foreach (array_slice($operations,1) as [$method,$path,$body]) $expect($request($method,$path,$body)[0]===403,'Read grant denies change or stored reconciliation: '.$method.' '.$path);
    $expect($request('GET',$economics.'?action=ownership_review&placement_id='.$foreign['placement'])[0]===404,'Foreign placement ID is not disclosed within the pinned workspace');
    foreach ($operations as [$method,$path,$body]) $expect($request($method,$path,$body,true,$foreignTenant)[0]===403,'Foreign tenant pin denied: '.$method.' '.$path);
    $expect($snapshot($graphTables)===$unchanged,'Anonymous, ungranted, read-only and foreign-tenant denials preserve every graph row');
    RBACResolver::grantModule($membership,'placements','write',[$tenant],$actor);
    [$status,$plan]=$request('POST',$previewPath,[]);
    $expect($status===200&&count($plan['rows'])===1&&$plan['rows'][0]['selectable']===true,'Placement editor previews only its complete saved JobDiva assignment');
    $apply['dry_run_token']=$plan['dry_run_token'];
    $expect($request('POST',$applyPath,$apply)[0]===403,'Placement write alone cannot apply saved source financial fields');
    $release['review_token']=$review['review_token'];
    $expect($request('POST',$releasePath,array_replace($release,['confirm'=>'']))[0]===422,'Ownership release requires explicit confirmation');
    $expect($request('PATCH',$economics.'?id='.$foreign['ar'],['payment_terms'=>'NET15'])[0]===404,'Editor cannot change another tenant participant by ID');
    $expect($request('POST',$economics.'?action=release_ownership&placement_id='.$foreign['placement'],$release)[0]===404,'Editor cannot release another tenant contract by ID');
    $expect($snapshot($graphTables)===$unchanged,'Preview and rejected editor actions preserve graph values and identities');
    [$status,$edited]=$request('PATCH',$patchPath,['payment_terms'=>'NET15']);
    $expect($status===200&&$edited['changed']===true&&$edited['party']['payment_terms']==='NET15','Placement editor saves a native commercial change');
    $row=placementAuditRow($own['placement']);
    $expect($row['client_payment_terms_override']==='NET15'&&in_array('client_payment_terms_override',placementOwnershipFields($row['coreflux_overridden_fields']),true),'Native HTTP edit aligns summary values and Core ownership');
    $unchanged=$snapshot($graphTables);
    $expect($request('POST',$releasePath,$release)[0]===409,'An old ownership review cannot release a newer contract edit');
    RBACResolver::grantModule($membership,'placements','admin',[$tenant],$actor);
    $expect($request('POST',$applyPath,array_replace($apply,['confirm'=>'']))[0]===422,'Saved source apply requires explicit confirmation');
    $expect($request('POST',$applyPath,$apply)[0]===409,'Administrator cannot apply a source preview from before the native edit');
    $expect($snapshot($graphTables)===$unchanged,'Stale reviews and missing confirmations leave all graph rows unchanged');
    [$status,$plan]=$request('POST',$previewPath,[]); $expect($status===200,'Administrator refreshes the saved source review');
    $apply['dry_run_token']=$plan['dry_run_token'];
    [$status,$applied]=$request('POST',$applyPath,$apply);
    $expect($status===200&&$applied['projected']===1&&$applied['retained_core_fields']>=4,'Authorized source apply reports retained Core commercial decisions');
    $row=placementAuditRow($own['placement']);
    $expect($row['client_payment_terms_override']==='NET15'&&$row['worksite_city']==='Hamilton'&&(int)$row['person_id']===$own['person']&&$row['external_id']==='jd:'.$own['start'],'HTTP source apply preserves Core terms and identities while updating an unowned source field');
    [$status,$review]=$request('GET',$reviewPath); $expect($status===200,'Fresh ownership review is available after source apply');
    $release['review_token']=$review['review_token'];
    RBACResolver::grantModule($membership,'placements','write',[$tenant],$actor);
    [$status,$released]=$request('POST',$releasePath,$release);
    $expect($status===200&&$released['released_groups']===['client_terms']&&$released['values_applied']===false,'Editor can release exactly the reviewed ownership group without changing its value');
    $row=placementAuditRow($own['placement']);
    $expect($row['client_payment_terms_override']==='NET15'&&!in_array('client_payment_terms_override',placementOwnershipFields($row['coreflux_overridden_fields']),true),'Release retains current terms and removes only their placement ownership');
    $unchanged=$snapshot($graphTables);
    $expect($request('POST',$releasePath,$release)[0]===409&&$snapshot($graphTables)===$unchanged,'Replaying a consumed ownership review is rejected without changes');
    RBACResolver::grantModule($membership,'placements','admin',[$tenant],$actor);
    [$status,$plan]=$request('POST',$previewPath,[]); $expect($status===200,'Released contract receives a new source preview');
    $apply['dry_run_token']=$plan['dry_run_token'];
    [$status,$applied]=$request('POST',$applyPath,$apply);
    $expect($status===200&&$applied['projected']===1&&placementAuditRow($own['placement'])['client_payment_terms_override']==='NET60','A separately reviewed source apply updates the released client terms');
    $stable=jobdivaPlacementProjectionAuditSnapshot($tenant,$own['placement']);
    [$status,$plan]=$request('POST',$previewPath,[]); $expect($status===200,'Replay uses a fresh source preview'); $apply['dry_run_token']=$plan['dry_run_token'];
    [$status,$applied]=$request('POST',$applyPath,$apply);
    $expect($status===200&&$applied['projected']===1&&jobdivaPlacementProjectionAuditSnapshot($tenant,$own['placement'])===$stable,'Freshly reviewed HTTP replay preserves the business graph');
    $q=$pdo->prepare("SELECT event,actor_user_id FROM audit_log WHERE tenant_id=? AND target_id=? AND event IN ('placement.economic_party.updated','placement.commercial_ownership.released') AND actor_user_id=?");
    $q->execute([$tenant,$own['placement'],$actor]); $events=array_column($q->fetchAll(PDO::FETCH_ASSOC),'event');
    $expect(in_array('placement.economic_party.updated',$events,true)&&in_array('placement.commercial_ownership.released',$events,true),'Native writes record the authenticated actor and workspace in the audit trail');
    $unchanged=$snapshot($graphTables);
    RBACResolver::grantModule($membership,'placements','read',[$tenant],$actor);
    foreach (array_slice($operations,1) as [$method,$path,$body]) $expect($request($method,$path,$body)[0]===403,'Same session immediately loses writes after downgrade: '.$method.' '.$path);
    RBACResolver::revokeModule($membership,'placements',$actor);
    foreach ($operations as [$method,$path,$body]) $expect($request($method,$path,$body)[0]===403,'Same session loses contract access after revocation: '.$method.' '.$path);
    $request('GET','/logout.php');
    $expect($request('GET',$reviewPath)[0]===401,'Native logout invalidates the session');
    $expect($snapshot($graphTables)===$unchanged,'Downgrade, revocation and logout checks make no graph changes');
    $expect(jobdivaPlacementProjectionAuditSnapshot($foreignTenant,$foreign['placement'])===$foreignBefore,'All foreign-tenant business values and identities remain unchanged');
    $expect($snapshot($financialTables)===$financialBefore,'Native contract round trip creates no financial postings, payroll runs or payments');
    $report['passed']=true;
} catch (Throwable $e) {
    $report['failure_class']=get_class($e); $report['failure_message']=$e->getMessage(); $report['failure_line']=$e->getLine();
} finally {
    if ($actor!==null) {
        try {
            $pdo->beginTransaction();
            if ($membership!==null) { RBACResolver::revokeModule($membership,'placements',$actor); deactivateMembership($actor,$tenant); }
            $pdo->prepare('UPDATE users SET is_active=0,password=NULL,password_hash=NULL WHERE id=? AND tenant_id=?')->execute([$actor,$tenant]);
            $pdo->exec('UPDATE tenants SET is_active=0 WHERE id IN (900012,900013)'); $pdo->commit();
            $q=$pdo->prepare("SELECT COUNT(*) FROM users u JOIN tenant_memberships tm ON tm.user_id=u.id AND tm.tenant_id=u.tenant_id
                JOIN user_tenants ut ON ut.user_id=u.id AND ut.tenant_id=u.tenant_id WHERE u.id=? AND u.tenant_id=? AND u.is_active=0
                AND u.password IS NULL AND u.password_hash IS NULL AND tm.id=? AND tm.status='revoked' AND ut.status='inactive'");
            $q->execute([$actor,$tenant,$membership]); $retired=(int)$q->fetchColumn()===1;
            $q=$pdo->prepare('SELECT COUNT(*) FROM membership_module_access WHERE membership_id=?'); $q->execute([$membership]);
            $report['synthetic_actor_retired']=$retired&&(int)$q->fetchColumn()===0;
            if (!$report['synthetic_actor_retired']) $report['passed']=false;
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $report['passed']=false; $report['retirement_failed']=true; }
    }
    if (is_resource($server)) { proc_terminate($server); proc_close($server); $report['owned_server_stopped']=true; }
    if (!$report['passed'] && is_file($directory.'/http.log')) $report['server_log']=substr((string)file_get_contents($directory.'/http.log'),-12000);
    if (realpath($directory)===$directory) foreach (glob($directory.'/*') ?: [] as $file) {
        if (is_file($file)&&!is_link($file)&&realpath(dirname($file))===$directory) unlink($file);
    }
    $report['private_session_files_removed']=@rmdir($directory);
    if (!$report['private_session_files_removed']) $report['passed']=false;
    setRequestTenantId(null);
}
$report['checks_passed']=count($report['checks']);
echo json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
exit($report['passed']?0:1);

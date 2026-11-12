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
$tenant = 900012; $foreignTenant = 900013; $childTenant=900014;
if ($pdo->query('SELECT id FROM tenants WHERE id IN (900012,900013,900014)')->fetchColumn()) throw new RuntimeException('Fixture tenants already exist');
$root = dirname(__DIR__); $directory = sys_get_temp_dir() . '/coreflux-contract-http-' . bin2hex(random_bytes(12));
if (!mkdir($directory, 0700)) throw new RuntimeException('Private fixture directory unavailable');
$actor = null; $membership = null; $childMembership=null; $server = null; $cookie = $directory . '/cookie.private';
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
$graphTables=['people','placements','placement_rates','placement_economic_parties','placement_corp_details','placement_economic_items','placement_economic_obligations',
    'staffing_operating_cycles','payroll_pay_schedules','payroll_pay_cycles','staffing_clients','companies','company_roles','ap_vendors_index','external_entity_mappings'];
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
    // Deliberately do not reconcile: an ordinary reader must not build this graph.
    $bare=scopedInsert('placements',['person_id'=>$own['person'],'title'=>'Synthetic unbuilt contract','status'=>'on_hold',
        'engagement_type'=>'1099','start_date'=>'2026-10-01','client_id'=>$own['client'],
        'end_client_company_id'=>$own['company'],'worksite_country'=>'US']);
    $corpPlacement=scopedInsert('placements',['person_id'=>$own['person'],'title'=>'Synthetic corporate editor','status'=>'on_hold',
        'engagement_type'=>'c2c','start_date'=>'2026-10-01','end_date'=>'2026-12-31','external_id'=>'jd:99190021','worksite_country'=>'US']);
    $payrollPlacement=scopedInsert('placements',['person_id'=>$own['person'],'title'=>'Synthetic payroll schedule','status'=>'on_hold',
        'engagement_type'=>'w2','start_date'=>'2026-10-01','client_id'=>$own['client'],'worksite_country'=>'US']);
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
    $contextPath=$economics.'?placement_id='.$own['placement'];
    $barePath=$economics.'?placement_id='.$bare;
    $refreshPath=$barePath.'&action=reconcile';
    $previewPath=$stored.'?action=stored_preview'; $applyPath=$stored.'?action=stored_apply';
    $release=['confirm'=>'RELEASE_COMMERCIAL_OWNERSHIP','groups'=>['client_terms'],'review_token'=>str_repeat('0',64)];
    $apply=['confirm'=>'APPLY_STORED_JOBDIVA_ASSIGNMENTS','selected_start_ids'=>[$own['start']],'dry_run_token'=>str_repeat('0',64),'per_page'=>20];
    $createPartyPath=$economics.'?action=party&placement_id='.$own['placement'];
    $manualParty=['role'=>'msp','display_name'=>'Synthetic HTTP Manual Participant','settlement_channel'=>'ap','cadence'=>'monthly','payment_terms'=>'NET30','fee_basis'=>'per_invoice','fee_flat'=>5];
    $operations=[['GET',$reviewPath,null],['POST',$releasePath,$release],['PATCH',$patchPath,['payment_terms'=>'NET15']],['POST',$previewPath,[]],['POST',$applyPath,$apply],['GET',$contextPath,null],['POST',$refreshPath,[]],['POST',$createPartyPath,$manualParty],['DELETE',$patchPath,null]];
    $corpPath='/modules/placements/api/corp.php?placement_id='.$corpPlacement;
    $corpBody=['corp_legal_name'=>'Synthetic HTTP Corporate Payee','corp_contact_phone'=>'555-0100'];
    $operations[]=['GET',$corpPath,null];$operations[]=['PUT',$corpPath,$corpBody];
    $itemPath=$economics.'?action=item&placement_id='.$own['placement'];
    $itemBody=['description'=>'Synthetic HTTP one-time charge','settlement_channel'=>'ar','economic_party_id'=>$own['ar'],
        'direction'=>'charge','amount'=>'25.00','currency'=>'USD','apply_on'=>'2026-10-15','request_id'=>'99190001-0000-4000-8000-000000000001'];
    $operations[]=['POST',$itemPath,$itemBody];$operations[]=['DELETE',$economics.'?action=item&id=999999',null];
    $cycles='/modules/placements/api/cycles.php';$cycleReviewPath=$cycles.'?placement_id='.$payrollPlacement;
    $cycleBody=['name'=>'Synthetic HTTP Payroll','purpose'=>'payroll','cadence'=>'biweekly','anchor_date'=>'2026-10-05','settlement_offset_days'=>5,'placement_id'=>$payrollPlacement,'review_token'=>str_repeat('0',64)];
    $cycleAssign=['placement_id'=>$payrollPlacement,'payroll_operating_cycle_id'=>1,'review_token'=>str_repeat('0',64)];
    $operations[]=['GET',$cycleReviewPath,null];$operations[]=['POST',$cycles,$cycleBody];$operations[]=['POST',$cycles.'?action=assign',$cycleAssign];
    $sharedId=(int)placementAuditRow($own['placement'])['billing_operating_cycle_id'];
    $operations[]=['GET',$cycles.'?action=edit_review&id='.$sharedId,null];
    $operations[]=['PATCH',$cycles.'?id='.$sharedId,['name'=>'Synthetic unauthorized schedule edit']];
    $unchanged=$snapshot($graphTables);
    foreach ($operations as [$method,$path,$body]) $expect($request($method,$path,$body,false)[0]===401,'Anonymous denied: '.$method.' '.$path);
    [$status,,$location]=$request('POST','/login.php',['username'=>$email,'password'=>$password,'redirect'=>'spa'],true,$tenant,true); unset($password,$hash);
    $expect($status===302&&!str_contains($location,'error='),'Native password login creates a real cookie session');
    foreach ($operations as [$method,$path,$body]) $expect($request($method,$path,$body)[0]===403,'No module grant denied: '.$method.' '.$path);
    RBACResolver::grantModule($membership,'placements','read',[$tenant],$actor);
    [$status,$review]=$request('GET',$reviewPath);
    $expect($status===200&&$review['placement_id']===$own['placement']&&count($review['groups'])===4,'Placement reader sees the four actual ownership groups');
    $expect(($review['permissions']['release']??null)===false,'Ownership review reports that a reader cannot release fields');
    [$status,$context]=$request('GET',$contextPath);
    $expect($status===200&&count($context['permissions']??[])===7&&!in_array(true,$context['permissions'],true),'Contract context gives reader no editing permissions');
    [$status,$context]=$request('GET',$barePath);
    $expect($status===200&&$context['parties']===[]&&$context['readiness']['missing_receivable_party']===true,'Reading an unbuilt contract reports its missing setup without creating participants');
    foreach ($operations as [$method,$path,$body]) if ($method!=='GET') $expect($request($method,$path,$body)[0]===403,'Read grant denies change or stored reconciliation: '.$method.' '.$path);
    $expect($request('GET',$economics.'?action=ownership_review&placement_id='.$foreign['placement'])[0]===404,'Foreign placement ID is not disclosed within the pinned workspace');
    $expect($request('GET',$economics.'?placement_id='.$foreign['placement'])[0]===404,'Ordinary context does not disclose a foreign placement');
    foreach ($operations as [$method,$path,$body]) $expect($request($method,$path,$body,true,$foreignTenant)[0]===403,'Foreign tenant pin denied: '.$method.' '.$path);
    $expect($snapshot($graphTables)===$unchanged,'Anonymous, ungranted, read-only and foreign-tenant denials preserve every graph row');
    RBACResolver::grantModule($membership,'placements','write',[$tenant],$actor);
    [$status,$context]=$request('GET',$contextPath);
    $expect($status===200&&$context['permissions']['manage']===true&&$context['permissions']['financials_manage']===false&&$context['permissions']['financials_approve']===false,'Placement editing does not grant rate editing or approval');
    [$status,$editorReview]=$request('GET',$reviewPath);
    $expect($status===200&&$editorReview['permissions']['release']===true,'Editor ownership review exposes the authorized release action');
    [$status,$plan]=$request('POST',$previewPath,[]);
    $expect($status===200&&count($plan['rows'])===1&&$plan['rows'][0]['selectable']===true,'Placement editor previews only its complete saved JobDiva assignment');
    $expect(($plan['permissions']['apply']??null)===false,'Source preview withholds apply permission from the placement editor');
    $apply['dry_run_token']=$plan['dry_run_token'];
    $expect($request('POST',$applyPath,$apply)[0]===403,'Placement write alone cannot apply saved source financial fields');
    $release['review_token']=$review['review_token'];
    $expect($request('POST',$releasePath,array_replace($release,['confirm'=>'']))[0]===422,'Ownership release requires explicit confirmation');
    $expect($request('PATCH',$economics.'?id='.$foreign['ar'],['payment_terms'=>'NET15'])[0]===404,'Editor cannot change another tenant participant by ID');
    $expect($request('POST',$economics.'?action=release_ownership&placement_id='.$foreign['placement'],$release)[0]===404,'Editor cannot release another tenant contract by ID');
    $expect($snapshot($graphTables)===$unchanged,'Preview and rejected editor actions preserve graph values and identities');
    $expect($request('POST',$economics.'?placement_id='.$foreign['placement'].'&action=reconcile',[])[0]===404,'An editor cannot refresh another tenant contract');
    $pdo->exec("CREATE TRIGGER cf_contract_http_reconcile_audit BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.event='placement.economics.reconciled' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic refresh audit failure'; END IF; END");
    try { $expect($request('POST',$refreshPath,[])[0]===503,'A failed required audit rejects participant refresh'); }
    finally { $pdo->exec('DROP TRIGGER cf_contract_http_reconcile_audit'); }
    $expect($snapshot($graphTables)===$unchanged,'Failed refresh audit rolls back every participant, cycle and vendor change');
    [$status,$refreshed]=$request('POST',$refreshPath,[]);
    $expect($status===200&&$refreshed['result']['available']===true&&count($refreshed['context']['parties'])>=2,'Explicit editor refresh builds the unbuilt contract participants');
    $q=$pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE tenant_id=? AND target_id=? AND actor_user_id=? AND event='placement.economics.reconciled'");
    $q->execute([$tenant,$bare,$actor]);$expect((int)$q->fetchColumn()===1,'Successful refresh and its authenticated audit commit together exactly once');
    $afterRefresh=$snapshot($graphTables);
    $expect($request('GET',$barePath)[0]===200&&$snapshot($graphTables)===$afterRefresh,'Reading a refreshed contract leaves all business rows unchanged');
    [$status,$edited]=$request('PATCH',$patchPath,['payment_terms'=>'NET15']);
    $expect($status===200&&$edited['changed']===true&&$edited['party']['payment_terms']==='NET15','Placement editor saves a native commercial change');
    $row=placementAuditRow($own['placement']);
    $expect($row['client_payment_terms_override']==='NET15'&&in_array('client_payment_terms_override',placementOwnershipFields($row['coreflux_overridden_fields']),true),'Native HTTP edit aligns summary values and Core ownership');
    $unchanged=$snapshot($graphTables);
    $expect($request('POST',$releasePath,$release)[0]===409,'An old ownership review cannot release a newer contract edit');
    RBACResolver::grantModule($membership,'placements','admin',[$tenant],$actor);
    [$status,$context]=$request('GET',$contextPath);
    $expect($status===200&&!in_array(false,$context['permissions'],true),'Administrator receives the contract permissions authorized by both access layers');
    $expect($request('POST',$applyPath,array_replace($apply,['confirm'=>'']))[0]===422,'Saved source apply requires explicit confirmation');
    $expect($request('POST',$applyPath,$apply)[0]===409,'Administrator cannot apply a source preview from before the native edit');
    $expect($snapshot($graphTables)===$unchanged,'Stale reviews and missing confirmations leave all graph rows unchanged');
    [$status,$plan]=$request('POST',$previewPath,[]); $expect($status===200,'Administrator refreshes the saved source review');
    $expect(($plan['permissions']['apply']??null)===true,'Administrator source preview explicitly permits apply');
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
    $expect($request('POST',$economics.'?action=party&placement_id='.$foreign['placement'],$manualParty)[0]===404,'Participant creation cannot target another tenant placement');
    $expect($request('POST',$createPartyPath,array_replace($manualParty,['cadence'=>'invalid']))[0]===422&&$snapshot($graphTables)===$unchanged,'Invalid participant cadence creates no orphan company or role');
    $pdo->exec("CREATE TRIGGER cf_contract_http_party_audit BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.event='placement.economic_party.added' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic participant create audit failure'; END IF; END");
    try {$expect($request('POST',$createPartyPath,$manualParty)[0]===503,'Required creation audit failure is reported through HTTP');}
    finally {$pdo->exec('DROP TRIGGER cf_contract_http_party_audit');}
    $expect($snapshot($graphTables)===$unchanged,'Failed HTTP creation rolls back all linked participant records');
    [$status,$createdParty]=$request('POST',$createPartyPath,$manualParty);$manualId=(int)($createdParty['id']??0);
    $expect($status===201&&$manualId>0&&!empty($createdParty['context']['available']),'Authorized participant creation returns the saved contract context');
    $q=$pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE tenant_id=? AND target_id=? AND actor_user_id=? AND event='placement.economic_party.added'");
    $q->execute([$tenant,$own['placement'],$actor]);
    $expect((int)$q->fetchColumn()===1,'HTTP participant creation records the authenticated actor once');
    $unchanged=$snapshot($graphTables);
    $expect($request('DELETE',$economics.'?id='.$foreign['ar'],null)[0]===404&&$snapshot($graphTables)===$unchanged,'Participant deletion cannot target another tenant record');
    $pdo->exec("CREATE TRIGGER cf_contract_http_party_audit BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.event='placement.economic_party.removed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic participant remove audit failure'; END IF; END");
    try {$expect($request('DELETE',$economics.'?id='.$manualId,null)[0]===503,'Required removal audit failure is reported through HTTP');}
    finally {$pdo->exec('DROP TRIGGER cf_contract_http_party_audit');}
    $expect($snapshot($graphTables)===$unchanged,'Failed HTTP removal leaves the participant active and unchanged');
    [$status,$removedParty]=$request('DELETE',$economics.'?id='.$manualId,null);
    $expect($status===200&&$removedParty['changed']===true,'Authorized HTTP removal deactivates the manual participant');
    $unchanged=$snapshot($graphTables);[$status,$repeatRemoval]=$request('DELETE',$economics.'?id='.$manualId,null);
    $expect($status===200&&$repeatRemoval['changed']===false&&$snapshot($graphTables)===$unchanged,'Repeated HTTP removal is an unchanged success');
    $q=$pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE tenant_id=? AND target_id=? AND actor_user_id=? AND event='placement.economic_party.removed'");
    $q->execute([$tenant,$own['placement'],$actor]);
    $expect((int)$q->fetchColumn()===1,'Removal retry does not duplicate the authenticated audit receipt');
    $unchanged=$snapshot($graphTables);
    $expect($request('GET','/modules/placements/api/corp.php?placement_id='.$foreign['placement'])[0]===404
        &&$request('PUT','/modules/placements/api/corp.php?placement_id='.$foreign['placement'],$corpBody)[0]===404
        &&$snapshot($graphTables)===$unchanged,'Corporate endpoints enforce placement tenant ownership before reads or writes');
    [$status,$corpReview]=$request('GET',$corpPath);
    $expect($status===200&&$corpReview['corp']===null&&strlen($corpReview['review_token'])===64,'Corporate review supplies a token without creating a payee');
    $corpBody['review_token']=$corpReview['review_token'];
    $pdo->exec("CREATE TRIGGER cf_contract_http_corp_audit BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.event='placement.corp.updated' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic corporate audit failure'; END IF; END");
    try{$expect($request('PUT',$corpPath,$corpBody)[0]===503,'Corporate audit failure is returned as a retryable HTTP error');}
    finally{$pdo->exec('DROP TRIGGER cf_contract_http_corp_audit');}
    $expect($snapshot($graphTables)===$unchanged,'A failed native corporate save rolls back company, vendor, cycle and participant creation');
    [$status,$corpSaved]=$request('PUT',$corpPath,$corpBody);
    $expect($status===200&&$corpSaved['changed']&&$corpSaved['corp']['corp_contact_phone']==='555-0100'
        &&!array_key_exists('corp_ein_ct',$corpSaved['corp']),'Authorized corporate save returns aligned metadata without ciphertext');
    $unchanged=$snapshot($graphTables);
    $expect($request('PUT',$corpPath,array_replace($corpBody,['corp_contact_phone'=>'555-0111']))[0]===409
        &&$snapshot($graphTables)===$unchanged,'A consumed corporate review cannot overwrite the saved details');
    [$status,$corpReview]=$request('GET',$corpPath);
    [$status,$corpSaved]=$request('PUT',$corpPath,['corp_legal_name'=>$corpReview['corp']['corp_legal_name'],'review_token'=>$corpReview['review_token'],'corp_city'=>'Hamilton']);
    $expect($status===200&&$corpSaved['changed']&&$corpSaved['corp']['corp_city']==='Hamilton'&&$corpSaved['corp']['corp_contact_phone']==='555-0100','Partial native HTTP save preserves unsubmitted corporate metadata');
    $unchanged=$snapshot($graphTables);
    [$status,$sameCorp]=$request('PUT',$corpPath,['corp_legal_name'=>$corpSaved['corp']['corp_legal_name'],'review_token'=>$corpSaved['review_token'],'corp_city'=>'Hamilton']);
    $expect($status===200&&!$sameCorp['changed']&&$snapshot($graphTables)===$unchanged,'Fresh identical corporate save is an unchanged HTTP success');
    $q=$pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE tenant_id=? AND target_id=? AND actor_user_id=? AND event='placement.corp.updated'");
    $q->execute([$tenant,$corpPlacement,$actor]);$expect((int)$q->fetchColumn()===2,'Corporate saves audit the authenticated actor once per actual change');
    $unchanged=$snapshot($graphTables);
    $expect($request('POST',$economics.'?action=item&placement_id='.$foreign['placement'],$itemBody)[0]===404
        &&$snapshot($graphTables)===$unchanged,'A native item cannot be created against a foreign placement');
    $pdo->exec("CREATE TRIGGER cf_contract_http_item_audit BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.event IN ('placement.economic_item.added','placement.economic_item.removed') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic item audit failure'; END IF; END");
    try{$expect($request('POST',$itemPath,$itemBody)[0]===503,'One-time item audit failure returns a retryable HTTP error');}
    finally{$pdo->exec('DROP TRIGGER cf_contract_http_item_audit');}
    $expect($snapshot($graphTables)===$unchanged,'Failed native item audit rolls back the charge and leaves the graph unchanged');
    [$status,$item]=$request('POST',$itemPath,$itemBody);$itemId=(int)($item['id']??0);
    $expect($status===201&&$itemId>0&&$item['changed']&&(int)$item['item']['source_managed']===0,'Authorized native HTTP creates one Core-owned item');
    $unchanged=$snapshot($graphTables);[$status,$again]=$request('POST',$itemPath,$itemBody);
    $expect($status===200&&!$again['changed']&&$again['id']===$itemId&&$snapshot($graphTables)===$unchanged,'Exact HTTP retry confirms the original item without duplicating it');
    $expect($request('POST',$itemPath,array_replace($itemBody,['amount'=>'26.00']))[0]===409&&$snapshot($graphTables)===$unchanged,'HTTP request-key reuse cannot replace a saved charge');
    $pdo->exec("CREATE TRIGGER cf_contract_http_item_audit BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.event='placement.economic_item.removed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic item removal audit failure'; END IF; END");
    try{$expect($request('DELETE',$economics.'?action=item&id='.$itemId)[0]===503,'Failed native removal audit is reported to the caller');}
    finally{$pdo->exec('DROP TRIGGER cf_contract_http_item_audit');}
    $expect($snapshot($graphTables)===$unchanged,'Failed HTTP item removal leaves the charge active');
    [$status,$removed]=$request('DELETE',$economics.'?action=item&id='.$itemId);
    $expect($status===200&&$removed['changed']&&$removed['item']['status']==='void','Native item removal preserves a voided history record');
    $unchanged=$snapshot($graphTables);[$status,$removed]=$request('DELETE',$economics.'?action=item&id='.$itemId);
    $expect($status===200&&!$removed['changed']&&$snapshot($graphTables)===$unchanged,'Repeated HTTP item removal is an unchanged success');
    $q=$pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE tenant_id=? AND target_id=? AND actor_user_id=? AND event IN ('placement.economic_item.added','placement.economic_item.removed')");
    $q->execute([$tenant,$own['placement'],$actor]);$expect((int)$q->fetchColumn()===2,'Creation and removal record the authenticated actor exactly once each');
    $operations[]=['DELETE',$economics.'?action=item&id='.$itemId,null];
    $unchanged=$snapshot($graphTables);
    $expect($request('GET',$cycles.'?placement_id='.$foreign['placement'])[0]===404
        &&$request('POST',$cycles,array_replace($cycleBody,['placement_id'=>$foreign['placement']]))[0]===404
        &&$request('POST',$cycles.'?action=assign',array_replace($cycleAssign,['placement_id'=>$foreign['placement']]))[0]===404
        &&$snapshot($graphTables)===$unchanged,'Schedule APIs reject foreign placements before reading or creating schedules');
    [$status,$scheduleReview]=$request('GET',$cycleReviewPath);
    $expect($status===200&&strlen($scheduleReview['review_token'])===64&&$snapshot($graphTables)===$unchanged,'Native schedule review is read-only even for an unbuilt payroll placement');
    $cycleBody['review_token']=$scheduleReview['review_token'];
    $pdo->exec("CREATE TRIGGER cf_contract_http_cycle_audit BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.event='placement.cycles.assigned' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic schedule assignment audit failure'; END IF; END");
    try{$expect($request('POST',$cycles,$cycleBody)[0]===503,'A failed assignment audit rejects the combined create-and-assign request');}
    finally{$pdo->exec('DROP TRIGGER cf_contract_http_cycle_audit');}
    $expect($snapshot($graphTables)===$unchanged,'Assignment audit failure rolls back new payroll schedule bridge graph and ownership');
    [$status,$scheduleSaved]=$request('POST',$cycles,$cycleBody);
    $expect($status===201&&$scheduleSaved['created']&&$scheduleSaved['assignment']['changed']&&$scheduleSaved['payroll_pay_cycle_id']>0,'Native HTTP creates the payroll bridge and assigns its operating schedule atomically');
    $p=placementAuditRow($payrollPlacement);
    $expect((int)$p['payroll_operating_cycle_id']===(int)$scheduleSaved['id']&&(int)$p['payroll_cycle_id']===(int)$scheduleSaved['payroll_pay_cycle_id']&&$p['vendor_pay_cycle']==='biweekly'&&$p['vendor_pay_cycle_anchor']==='2026-10-05','HTTP schedule selection aligns placement cadence anchor and payroll compatibility ID');
    $unchanged=$snapshot($graphTables);[$status,$sameSchedule]=$request('POST',$cycles,$cycleBody);
    $expect($status===200&&!$sameSchedule['changed']&&$sameSchedule['id']===$scheduleSaved['id']&&$snapshot($graphTables)===$unchanged,'Exact native HTTP creation retry keeps the original schedule and assignment');
    $cycleAssign['payroll_operating_cycle_id']=$scheduleSaved['id'];
    [$status,$sameAssignment]=$request('POST',$cycles.'?action=assign',$cycleAssign);
    $expect($status===200&&!$sameAssignment['changed']&&$snapshot($graphTables)===$unchanged,'An already-applied HTTP assignment is an unchanged success');
    $expect($request('POST',$cycles,array_replace($cycleBody,['anchor_date'=>'2026-10-12']))[0]===409&&$snapshot($graphTables)===$unchanged,'A conflicting named payroll schedule cannot change the existing bridge');
    $expect($request('POST',$cycles,array_replace($cycleBody,['name'=>'Synthetic Stale HTTP Payroll']))[0]===409&&$snapshot($graphTables)===$unchanged,'A stale review rolls back a newly named schedule and both payroll records');
    $q=$pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE tenant_id=? AND actor_user_id=? AND event IN ('placement.cycles.assigned','staffing.operating_cycle.created')");
    $q->execute([$tenant,$actor]);$expect((int)$q->fetchColumn()===2,'Successful schedule creation and assignment each record the authenticated actor once');
    $sharedPath=$cycles.'?id='.$scheduleSaved['id'];$sharedReviewPath=$cycles.'?action=edit_review&id='.$scheduleSaved['id'];
    [$status,$sharedReview]=$request('GET',$sharedReviewPath);
    $expect($status===200&&count($sharedReview['placements'])===1&&$sharedReview['payroll']['generated_period_count']===0
        &&$sharedReview['permissions']['manage']===true&&$sharedReview['permissions']['payroll_manage']===false,'Shared impact review identifies its consumers and the missing payroll-management permission');
    $sharedBody=['anchor_date'=>'2026-10-12','settlement_offset_days'=>7,'review_token'=>$sharedReview['review_token'],'confirm'=>'UPDATE_SHARED_SCHEDULE'];
    $unchanged=$snapshot($graphTables);
    $expect($request('PATCH',$sharedPath,$sharedBody)[0]===403&&$snapshot($graphTables)===$unchanged,'Placement management cannot change an existing payroll calendar without its payroll grant');
    $payCyclePath='/modules/payroll/api/cycles.php?id='.$scheduleSaved['payroll_pay_cycle_id'];
    $paySchedulePath='/modules/payroll/api/pay_schedules.php?id='.$sharedReview['payroll']['schedule_id'];
    $expect($request('PATCH',$payCyclePath,['anchor_date_override'=>'2026-10-12'])[0]===403
        &&$request('PATCH',$paySchedulePath,['period_start_anchor'=>'2026-10-12'])[0]===403&&$snapshot($graphTables)===$unchanged,'Both native payroll editor paths require payroll-management permission');
    RBACResolver::grantModule($membership,'payroll','admin',[$tenant],$actor);
    $pdo->exec("CREATE TRIGGER cf_contract_http_shared_cycle_audit BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.event='staffing.operating_cycle.updated' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic shared schedule audit failure'; END IF; END");
    try{$expect($request('PATCH',$sharedPath,$sharedBody)[0]===503,'Failure of the final shared audit rejects the entire multi-record save');}
    finally{$pdo->exec('DROP TRIGGER cf_contract_http_shared_cycle_audit');}
    $expect($snapshot($graphTables)===$unchanged,'Shared audit failure restores payroll bridge placement and participant values');
    [$status,$sharedSaved]=$request('PATCH',$sharedPath,$sharedBody);
    $expect($status===200&&$sharedSaved['changed']&&$sharedSaved['affected_placement_ids']===[$payrollPlacement]
        &&placementAuditRow($payrollPlacement)['vendor_pay_cycle_anchor']==='2026-10-12','The fully authorized shared save changes the reviewed placement calendar');
    $unchanged=$snapshot($graphTables);[$status,$sharedAgain]=$request('PATCH',$sharedPath,$sharedBody);
    $expect($status===200&&!$sharedAgain['changed']&&$snapshot($graphTables)===$unchanged,'An exact shared HTTP retry does not create another edit or audit');
    $expect($request('PATCH',$sharedPath,array_replace($sharedBody,['anchor_date'=>'2026-10-19']))[0]===409&&$snapshot($graphTables)===$unchanged,'A stale shared review cannot apply a second calendar change');
    foreach([['PATCH',$payCyclePath,['anchor_date_override'=>'2026-10-19']],['DELETE',$payCyclePath,null],['PATCH',$paySchedulePath,['period_start_anchor'=>'2026-10-19']],['DELETE',$paySchedulePath,null]] as [$method,$path,$payload]){
        $expect($request($method,$path,$payload)[0]===409&&$snapshot($graphTables)===$unchanged,'A linked payroll editor cannot bypass the shared staffing impact review: '.$method.' '.$path);
    }
    $q=$pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE tenant_id=? AND actor_user_id=? AND event IN ('placement.cycles.shared_updated','staffing.operating_cycle.updated')");$q->execute([$tenant,$actor]);
    $expect((int)$q->fetchColumn()===2,'The successful shared save audits the authenticated actor at the placement and shared definition exactly once');
    $pdo->prepare('INSERT INTO tenants(id,name,parent_id,tenant_type,is_active,is_simulation) VALUES(?,?,?,"sub",1,1)')->execute([$childTenant,'Synthetic shared schedule child',$tenant]);
    $childMembership=(int)provisionMembership($actor,$childTenant,'admin',['is_primary'=>true,'persona_label'=>'Synthetic shared schedule child','status'=>'active'])['membership_id'];
    RBACResolver::grantModule($childMembership,'placements','admin',[$childTenant],$actor);RBACResolver::grantModule($childMembership,'payroll','admin',[$childTenant],$actor);
    [$status,$childReview]=$request('GET',$sharedReviewPath,null,true,$childTenant);$unchanged=$snapshot($graphTables);
    $expect($status===200&&$childReview['permissions']['manage']===true&&$childReview['permissions']['payroll_scope_matches']===false&&$childReview['permissions']['payroll_manage']===false,'A child workspace with private payroll cannot acquire management of its shared placement payroll calendar');
    $childBody=['name'=>'Synthetic shared child reviewed payroll','review_token'=>$childReview['review_token'],'confirm'=>'UPDATE_SHARED_SCHEDULE'];
    $expect($request('PATCH',$sharedPath,$childBody,true,$childTenant)[0]===403&&$snapshot($graphTables)===$unchanged,'Child payroll permission does not authorize editing a different payroll data owner');
    $pdo->prepare('INSERT INTO tenant_module_scope(tenant_id,module_key,scope_mode) VALUES(?,"payroll","shared")')->execute([$childTenant]);
    [$status,$childReview]=$request('GET',$sharedReviewPath,null,true,$childTenant);$childBody['review_token']=$childReview['review_token'];
    $expect($status===200&&$childReview['permissions']['payroll_scope_matches']===true&&$childReview['permissions']['payroll_manage']===true,'Explicitly shared payroll scope aligns the authorized data owner');
    [$status,$childSave]=$request('PATCH',$sharedPath,$childBody,true,$childTenant);
    $expect($status===200&&$childSave['changed']&&$childSave['cycle']['name']==='Synthetic shared child reviewed payroll','Aligned child scope can edit the reviewed parent-owned staffing calendar');
    $unchanged=$snapshot($graphTables);
    $expect($request('PATCH',$payCyclePath,['active'=>0],true,$childTenant)[0]===409&&$request('PATCH',$paySchedulePath,['active'=>0],true,$childTenant)[0]===409&&$snapshot($graphTables)===$unchanged,'Native payroll guards resolve the shared owner before refusing a child-workspace bypass');
    RBACResolver::revokeModule($membership,'payroll',$actor);
    $operations[]=['GET',$sharedReviewPath,null];$operations[]=['PATCH',$sharedPath,$sharedBody];
    $unchanged=$snapshot($graphTables);
    RBACResolver::grantModule($membership,'placements','read',[$tenant],$actor);
    [$status,$context]=$request('GET',$contextPath);
    $expect($status===200&&!in_array(true,$context['permissions'],true),'Same session immediately receives read-only screen permissions after downgrade');
    foreach ($operations as [$method,$path,$body]) if ($method!=='GET') $expect($request($method,$path,$body)[0]===403,'Same session immediately loses writes after downgrade: '.$method.' '.$path);
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
            if ($membership!==null) { RBACResolver::revokeModule($membership,'placements',$actor); RBACResolver::revokeModule($membership,'payroll',$actor); deactivateMembership($actor,$tenant); }
            if ($childMembership!==null) { RBACResolver::revokeModule($childMembership,'placements',$actor); RBACResolver::revokeModule($childMembership,'payroll',$actor); deactivateMembership($actor,$childTenant); }
            $pdo->prepare('UPDATE users SET is_active=0,password=NULL,password_hash=NULL WHERE id=? AND tenant_id=?')->execute([$actor,$tenant]);
            $pdo->exec('UPDATE tenants SET is_active=0 WHERE id IN (900012,900013,900014)'); $pdo->commit();
            $q=$pdo->prepare("SELECT COUNT(*) FROM users u JOIN tenant_memberships tm ON tm.user_id=u.id AND tm.tenant_id=u.tenant_id
                JOIN user_tenants ut ON ut.user_id=u.id AND ut.tenant_id=u.tenant_id WHERE u.id=? AND u.tenant_id=? AND u.is_active=0
                AND u.password IS NULL AND u.password_hash IS NULL AND tm.id=? AND tm.status='revoked' AND ut.status='inactive'");
            $q->execute([$actor,$tenant,$membership]); $retired=(int)$q->fetchColumn()===1;
            $q=$pdo->prepare('SELECT COUNT(*) FROM membership_module_access a JOIN tenant_memberships m ON m.id=a.membership_id WHERE m.user_id=?'); $q->execute([$actor]);
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

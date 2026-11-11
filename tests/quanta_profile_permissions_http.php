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
$counts = static function() use ($pdo): array {
    $out = [];
    foreach (['people','placements','quanta_profile_baselines','quanta_profile_decisions','quanta_profile_returns',
        'payroll_runs','ap_payments','billing_payments','accounting_journal_entries'] as $table) {
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
    $request = static function(string $path, ?array $body, bool $signedIn = true, int $pinned = 900011, bool $form = false) use ($port,$cookie,&$report): array {
        $curl = curl_init('http://127.0.0.1:' . $port . $path);
        $headers = ['Accept: application/json', 'X-CoreFlux-Tenant-Id: ' . $pinned];
        $options = [CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>1,CURLOPT_TIMEOUT=>15];
        if ($body !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $form ? http_build_query($body) : json_encode((object)$body, JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: ' . ($form ? 'application/x-www-form-urlencoded' : 'application/json');
        }
        if ($signedIn) { $options[CURLOPT_COOKIEFILE]=$cookie; $options[CURLOPT_COOKIEJAR]=$cookie; }
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
    [$status,,$location] = $request('/login.php',['username'=>$email,'password'=>$password,'redirect'=>'spa'],true,$tenant,true);
    unset($password,$hash);
    $expect($status===302 && !str_contains($location,'error='),'Native password login creates a real session cookie');
    foreach ($actions as $action) $expect($call($action)[0]===403,'Ungranted reviewer denied: ' . $action);
    RBACResolver::grantModule($membership,'integrations','read',[$tenant],$actor);
    RBACResolver::grantModule($membership,'people','write',[$tenant],$actor);
    foreach ($actions as $action) $expect($call($action)[0]===403,'Read-only integration access denied: ' . $action);
    RBACResolver::grantModule($membership,'integrations','admin',[$tenant],$actor);
    RBACResolver::revokeModule($membership,'people',$actor);
    foreach ($actions as $action) $expect($call($action)[0]===403,'Missing people access denied: ' . $action);
    RBACResolver::grantModule($membership,'people','read',[$tenant],$actor);
    foreach ($reviews as $action) $expect($call($action)[0]===403,'Read-only people access cannot review or return: ' . $action);
    $expect($call('profile_baseline_preview',['person_id'=>0])[0]===422,'Snapshot preview admits people-read before validating the chosen person');
    $expect($reads()===0,'All permission denials occur before provider reads');
    RBACResolver::grantModule($membership,'people','write',[$tenant],$actor);
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
    RBACResolver::grantModule($membership,'people','read',[$tenant],$actor);
    foreach ($reviews as $action) $expect($call($action)[0]===403,'Same session loses decision rights after grant downgrade: ' . $action);
    RBACResolver::revokeModule($membership,'integrations',$actor);
    foreach ($actions as $action) $expect($call($action)[0]===403,'Same session loses integration access after revocation: ' . $action);
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
                foreach (['integrations','people'] as $module) RBACResolver::revokeModule($membership,$module,$actor);
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

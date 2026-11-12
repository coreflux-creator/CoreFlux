<?php
/** Hosted native-session permission checks; business writes are deliberately invalid. */
declare(strict_types=1);
const CONTRACT_HTTP_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const CONTRACT_HTTP_PRIVATE='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
if (PHP_SAPI!=='cli'||posix_geteuid()!==1003||getcwd()!==CONTRACT_HTTP_ROOT||getenv('COREFLUX_ENV')!=='staging'
    ||($argv[1]??'')!=='--verify-isolated-contract-http'||!preg_match('/^[a-f0-9]{64}$/D',$argv[2]??'')) exit(2);
$manifestPath=__DIR__.'/manifest.json';
if (is_link(__DIR__)||realpath(__DIR__)!==__DIR__||dirname(__DIR__)!==CONTRACT_HTTP_PRIVATE
    ||fileowner(__DIR__)!==1003||(fileperms(__DIR__)&0777)!==0700
    ||is_link($manifestPath)||!hash_equals($argv[2],hash_file('sha256',$manifestPath))) exit(2);
$manifest=json_decode(file_get_contents($manifestPath),true,64,JSON_THROW_ON_ERROR);
foreach ($manifest['runtime_hashes'] as $file=>$hash) {
    $path=CONTRACT_HTTP_ROOT.'/'.$file;
    if (is_link($path)||realpath($path)!==$path||hash('sha256',str_replace("\r\n","\n",file_get_contents($path)))!==$hash) exit(2);
}
if (hash_file('sha256',dirname(CONTRACT_HTTP_ROOT).'/private_html/coreflux.thunderhawk-pilot.php')!==$manifest['feature_sha256']) exit(2);
require_once CONTRACT_HTTP_ROOT.'/core/db.php';
require_once CONTRACT_HTTP_ROOT.'/core/integrations/identity.php';
require_once CONTRACT_HTTP_ROOT.'/core/memberships.php';
require_once CONTRACT_HTTP_ROOT.'/core/rbac/permissions.php';
$pdo=getDB();
if (!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!COREFLUX_STAGING
    ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'||COREFLUX_INTEGRATION_DELIVERY_TENANTS!=='2002'
    ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn()!==1) exit(2);
$lock=fopen(CONTRACT_HTTP_PRIVATE.'/contact-install.lock','c');
if (!$lock||!flock($lock,LOCK_EX|LOCK_NB)) exit(2);
$receipt=__DIR__.'/result.json'; $mask=umask(0077); $receiptFile=fopen($receipt,'x'); umask($mask);
if (!$receiptFile) exit(2); fclose($receiptFile);
$report=['checked_at'=>gmdate('c'),'status'=>'running','pid'=>getmypid(),'source_commit'=>$manifest['source_commit'],
    'tenant_id'=>2002,'production_changed'=>false,'browser_test'=>false,'business_writes_requested'=>false,'checks'=>[],'requests'=>[],'passed'=>false];
$save=static function() use (&$report,$receipt): void { file_put_contents($receipt,json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX); };
$save();
$actor=null; $membership=null; $cookie=null;
$expect=static function(bool $ok,string $label) use (&$report): void {
    if (!$ok) { $report['failed_check']=$label; throw new RuntimeException($label); } $report['checks'][]=$label;
};
$snapshot=static function(array $tables) use ($pdo): array {
    $result=[];
    foreach ($tables as $table) {
        $hashes=[]; foreach ($pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC) as $row) $hashes[]=hash('sha256',serialize($row)); sort($hashes);
        $result[$table]=['rows'=>count($hashes),'hash'=>hash('sha256',implode("\n",$hashes))];
    }
    return $result;
};
$tables=['people','placements','placement_rates','placement_economic_parties','placement_corp_details','placement_client_chain',
    'placement_commissions','placement_referrals','placement_economic_items','placement_economic_obligations',
    'staffing_operating_cycles','staffing_clients','companies','external_entity_mappings','accounting_events',
    'accounting_journal_entries','accounting_journal_entry_lines','payroll_runs','ap_payments','billing_payments',
    'time_entries','staffing_timesheets','payroll_source_claims'];
$before=$snapshot($tables);
$request=static function(string $method,string $path,?array $body=null,bool $signed=true,int $pin=2002,bool $form=false) use (&$cookie,&$report): array {
    $ch=curl_init('https://phpstack-1516771-6707602.cloudwaysapps.com'.$path);
    $headers=['Accept: application/json','X-CoreFlux-Tenant-Id: '.$pin];
    $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>25,
        CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2];
    if ($body!==null) { $options[CURLOPT_POSTFIELDS]=$form?http_build_query($body):json_encode((object)$body,JSON_THROW_ON_ERROR); $headers[]='Content-Type: '.($form?'application/x-www-form-urlencoded':'application/json'); }
    if ($signed&&$cookie) { $options[CURLOPT_COOKIEFILE]=$cookie; $options[CURLOPT_COOKIEJAR]=$cookie; }
    $options[CURLOPT_HTTPHEADER]=$headers; curl_setopt_array($ch,$options);
    $raw=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $location=(string)curl_getinfo($ch,CURLINFO_REDIRECT_URL); curl_close($ch);
    $report['requests'][]=['method'=>$method,'path'=>$path,'status'=>$status,'pinned_tenant'=>$pin];
    if ($raw===false) throw new RuntimeException('Hosted HTTP transport failed; inspect the receipt before repeating');
    return [$status,json_decode($raw,true),$location];
};
try {
    $placement=$pdo->query('SELECT id,person_id,status FROM placements WHERE tenant_id=2002 AND id=874')->fetch(PDO::FETCH_ASSOC);
    $expect($placement&&(int)$placement['person_id']===368&&$placement['status']==='ended','Existing retired synthetic placement is identified');
    $party=(int)$pdo->query("SELECT id FROM placement_economic_parties WHERE tenant_id=2002 AND placement_id=874 AND source_ref='placement:bill_to' ORDER BY id LIMIT 1")->fetchColumn();
    $expect($party>0,'Existing synthetic commercial participant is identified');
    $economics='/modules/placements/api/economics.php'; $stored='/modules/placements/api/jobdiva_reconciliation.php';
    $review=$economics.'?action=ownership_review&placement_id=874'; $release=$economics.'?action=release_ownership&placement_id=874';
    $patch=$economics.'?id='.$party; $preview=$stored.'?action=stored_preview'; $apply=$stored.'?action=stored_apply';
    // Invalid confirmations/empty changes are intentional. The hosted verifier
    // must never consume a real review token or submit a valid business edit.
    $operations=[['GET',$review,null],['POST',$release,['confirm'=>'']],['PATCH',$patch,[]],
        ['POST',$preview,['per_page'=>1]],['POST',$apply,['confirm'=>'']]];
    foreach ($operations as [$method,$path,$body]) $expect($request($method,$path,$body,false)[0]===401,'Anonymous denied: '.$method.' '.$path);
    $email='contract-http-'.bin2hex(random_bytes(12)).'@example.invalid'; $password=bin2hex(random_bytes(32)); $hash=password_hash($password,PASSWORD_DEFAULT);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO users(tenant_id,name,email,password,password_hash,role,is_active,is_global_admin) VALUES(2002,"Synthetic contract permission reviewer",?,?,?,"admin",1,0)')->execute([$email,$hash,$hash]);
        $actor=(int)$pdo->lastInsertId();
        $membership=(int)provisionMembership($actor,2002,'admin',['is_primary'=>true,'persona_label'=>'Synthetic contract permission reviewer','status'=>'active'])['membership_id'];
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $actor=null; throw $e; }
    $report['synthetic_actor_id']=$actor; $report['synthetic_membership_id']=$membership; $save();
    $cookie=tempnam(__DIR__,'session-'); if (!$cookie||!chmod($cookie,0600)) throw new RuntimeException('Private session file unavailable');
    [$status,,$location]=$request('POST','/login.php',['username'=>$email,'password'=>$password,'redirect'=>'spa'],true,2002,true); unset($password,$hash);
    $expect($status===302&&!str_contains($location,'error='),'Native hosted login creates a real session');
    foreach ($operations as [$method,$path,$body]) $expect($request($method,$path,$body)[0]===403,'Ungranted user denied: '.$method.' '.$path);
    RBACResolver::grantModule($membership,'placements','read',[2002],$actor);
    [$status,$data]=$request('GET',$review);
    $expect($status===200&&($data['placement_id']??null)===874&&is_array($data['groups']??null),'Hosted reader receives only the requested synthetic ownership review');
    foreach (array_slice($operations,1) as [$method,$path,$body]) $expect($request($method,$path,$body)[0]===403,'Read grant denies writes and stored reconciliation: '.$method.' '.$path);
    foreach ($operations as [$method,$path,$body]) $expect($request($method,$path,$body,true,999)[0]===403,'Foreign tenant pin denied: '.$method.' '.$path);
    $expect($request('GET',$economics.'?action=ownership_review&placement_id=864')[0]===404,'Foreign placement is not disclosed under the simulation pin');
    RBACResolver::grantModule($membership,'placements','write',[2002],$actor);
    [$status,$data]=$request('POST',$preview,['per_page'=>1]);
    $expect($status===200&&is_array($data['rows']??null),'Hosted editor can preview saved assignment evidence');
    $expect($request('POST',$release,['confirm'=>''])[0]===422,'Hosted editor reaches release validation but missing confirmation is refused');
    $expect($request('PATCH',$patch,[])[0]===422,'Hosted editor reaches native change validation but empty edits are refused');
    $expect($request('POST',$apply,['confirm'=>''])[0]===403,'Hosted editor cannot apply source financial fields');
    RBACResolver::grantModule($membership,'placements','admin',[2002],$actor);
    $expect($request('POST',$apply,['confirm'=>''])[0]===422,'Hosted administrator reaches apply validation but missing confirmation is refused');
    $expect($snapshot($tables)===$before,'Permission and validation checks preserve all observed business rows');
    RBACResolver::grantModule($membership,'placements','read',[2002],$actor);
    foreach (array_slice($operations,1) as [$method,$path,$body]) $expect($request($method,$path,$body)[0]===403,'Hosted session loses writes immediately after downgrade: '.$method.' '.$path);
    RBACResolver::revokeModule($membership,'placements',$actor);
    foreach ($operations as [$method,$path,$body]) $expect($request($method,$path,$body)[0]===403,'Hosted session loses all contract access after revocation: '.$method.' '.$path);
    $request('GET','/logout.php'); $expect($request('GET',$review)[0]===401,'Hosted logout invalidates the cookie session');
    $report['passed']=true;
} catch (Throwable $e) {
    $report['failure_class']=get_class($e); $report['failure_message']=$e->getMessage(); $report['failure_line']=$e->getLine();
} finally {
    if ($actor!==null) {
        try {
            $pdo->beginTransaction(); RBACResolver::revokeModule($membership,'placements',$actor); deactivateMembership($actor,2002);
            $pdo->prepare('UPDATE users SET is_active=0,password=NULL,password_hash=NULL WHERE id=? AND tenant_id=2002')->execute([$actor]); $pdo->commit();
            $q=$pdo->prepare("SELECT COUNT(*) FROM users u JOIN tenant_memberships tm ON tm.user_id=u.id AND tm.tenant_id=u.tenant_id
                JOIN user_tenants ut ON ut.user_id=u.id AND ut.tenant_id=u.tenant_id WHERE u.id=? AND u.tenant_id=2002 AND u.is_active=0
                AND u.password IS NULL AND u.password_hash IS NULL AND tm.id=? AND tm.status='revoked' AND ut.status='inactive'");
            $q->execute([$actor,$membership]); $retired=(int)$q->fetchColumn()===1;
            $q=$pdo->prepare('SELECT COUNT(*) FROM membership_module_access WHERE membership_id=?'); $q->execute([$membership]);
            $report['synthetic_actor_retired']=$retired&&(int)$q->fetchColumn()===0;
            if (!$report['synthetic_actor_retired']) $report['passed']=false;
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $report['passed']=false; $report['retirement_failed']=true; }
    }
    if ($cookie&&is_file($cookie)) { $report['private_cookie_removed']=unlink($cookie); if (!$report['private_cookie_removed']) $report['passed']=false; }
    $report['business_rows_unchanged']=$snapshot($tables)===$before;
    $report['business_tables_checked']=count($tables); $report['business_rows_checked']=array_sum(array_column($before,'rows'));
    if (!$report['business_rows_unchanged']) $report['passed']=false;
    $report['checks_passed']=count($report['checks']); $report['status']=$report['passed']?'complete':'failed'; $save();
}
echo json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
exit($report['passed']?0:1);

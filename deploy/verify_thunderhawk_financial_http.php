<?php
/** Native login/API permission and evidence-replay checks with a retired synthetic actor. */
declare(strict_types=1);
$root='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
$private='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--verify-native-financial-http'||getenv('COREFLUX_ENV')!=='staging'
    ||realpath(getcwd())!==$root||realpath($private)!==$private)exit(2);
require_once $root.'/core/integrations/purepay_financial_observations.php';
require_once $root.'/core/integrations/identity.php';
require_once $root.'/core/memberships.php';
require_once $root.'/core/rbac/permissions.php';
$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||COREFLUX_STAGING!==true
    ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'||purepayFinancialPreviewTenant()!==2002
    ||purepayApiBase()!=='https://purepay.online:19443/api/v1'
    ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn()!==1)exit(2);
$report=['checked_at'=>gmdate('c'),'production_changed'=>false,'financial_postings'=>false,'payments_or_payroll_executed'=>false,
    'browser_test'=>false];$actor=null;$mid=null;$cookie=null;$phase='setup';$checks=[];$requests=[];
$expect=static function(bool $ok,string $label)use(&$checks,&$report):void{if(!$ok){$report['failed_check']=$label;throw new RuntimeException($label);}$checks[]=$label;};
$base='https://phpstack-1516771-6707602.cloudwaysapps.com';
$request=static function(string $action,?array $body,?string $cookie,int $tenant=2002)use($base,$private,&$requests):array {
    $ch=curl_init($base.'/api/purepay_financial_observations.php?action='.$action);
    $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>35,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','X-CoreFlux-Tenant-Id: '.$tenant],
        CURLOPT_POSTFIELDS=>json_encode($body??[],JSON_THROW_ON_ERROR)];
    if($cookie!==null){$options[CURLOPT_COOKIEFILE]=$cookie;$options[CURLOPT_COOKIEJAR]=$cookie;}
    curl_setopt_array($ch,$options);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if($raw===false)throw new RuntimeException('Native HTTP transport failed');
    $requests[]=['action'=>$action,'tenant_id'=>$tenant,'status'=>$status];
    if($status>=400){$mask=umask(0077);try{file_put_contents($private.'/financial-http-last-error.private.txt',$raw);}finally{umask($mask);}}
    return [$status,json_decode($raw,true)];
};
$body=['kind'=>'bill','document_id'=>'pay_041311ec10'];
$countBefore=(int)$pdo->query('SELECT COUNT(*) FROM purepay_financial_observations')->fetchColumn();
try {
    $expect($request('read',$body,null)[0]===401,'anonymous API read denied');
    $email='core-finance-http-'.bin2hex(random_bytes(8)).'@example.invalid';$password=bin2hex(random_bytes(32));$hash=password_hash($password,PASSWORD_DEFAULT);
    $pdo->beginTransaction();
    try {
        // Legacy admin satisfies the role gate; the new admin persona has no
        // wildcard, so each module grant is still independently required.
        $pdo->prepare('INSERT INTO users(tenant_id,name,email,password,password_hash,role,is_active,is_global_admin) VALUES(2002,"Synthetic financial API reviewer",?,?,?,"admin",1,0)')->execute([$email,$hash,$hash]);
        $actor=(int)$pdo->lastInsertId();$membership=provisionMembership($actor,2002,'admin',['is_primary'=>true,'persona_label'=>'Synthetic financial API reviewer','status'=>'active']);
        $mid=(int)$membership['membership_id'];$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$actor=null;throw $e;}
    $record=$private.'/financial-http-actor-'.$actor.'.private.json';
    $mask=umask(0077);try{file_put_contents($record,json_encode(['user_id'=>$actor,'membership_id'=>$mid,'tenant_id'=>2002,'email'=>$email,'retired'=>false],JSON_THROW_ON_ERROR));}
    finally{umask($mask);}
    $cookie=tempnam($private,'finance-cookie-');if(!$cookie||!chmod($cookie,0600))throw new RuntimeException('Private session file unavailable');
    $phase='native_login';$ch=curl_init($base.'/login.php');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>35,
        CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_POSTFIELDS=>http_build_query(['username'=>$email,'password'=>$password,'redirect'=>'spa'])]);
    curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$location=(string)curl_getinfo($ch,CURLINFO_REDIRECT_URL);curl_close($ch);unset($ch,$password,$hash);
    $expect($status===302&&!str_contains($location,'error='),'synthetic native login succeeded');
    $phase='permission_checks';
    $expect($request('read',$body,$cookie)[0]===403,'ungranted financial access denied');
    RBACResolver::grantModule($mid,'accounting','admin',[2002],4);
    $expect($request('read',$body,$cookie)[0]===403,'missing AP read denied');
    RBACResolver::grantModule($mid,'ap','read',[2002],4);
    $expect($request('read',$body,$cookie)[0]===403,'missing billing read denied');
    RBACResolver::grantModule($mid,'billing','read',[2002],4);
    [$status,$read]=$request('read',$body,$cookie);
    $expect($status===200&&($read['tenant_id']??null)===2002&&($read['observation']['observation_hash']??null)==='b40b11b2fc5a9994fc9a446af58b265938b53e1d4e2047e08bef625016a39a76','authorized API returns exact source evidence');
    $expect($read['observation']['document']['currency']===null&&$read['comparison']['core_record']===null,'HTTP retains unknown currency and unlinked identity');
    $expect($request('read',$body,$cookie,999)[0]===403,'foreign pinned company denied');
    $expect($request('save',$body+['review_hash'=>$read['review_hash'],'confirmed'=>false],$cookie)[0]===422,'save requires explicit review confirmation');
    $expect($request('save',$body+['review_hash'=>str_repeat('0',64),'confirmed'=>true],$cookie)[0]===422,'stale review refused');
    [$status,$saved]=$request('save',$body+['review_hash'=>$read['review_hash'],'confirmed'=>true],$cookie);
    $expect($status===200&&($saved['replayed']??null)===true,'native API save reuses previously captured evidence');
    [$status,$history]=$request('history',$body,$cookie);
    $expect($status===200&&($history['items'][0]['id']??null)===$saved['id'],'native API history decrypts requested capture');
    RBACResolver::revokeModule($mid,'billing',4);
    $expect($request('history',$body,$cookie)[0]===403,'revoked billing access blocks saved history');
    $expect((int)$pdo->query('SELECT COUNT(*) FROM purepay_financial_observations')->fetchColumn()===$countBefore,'HTTP checks created no duplicate captures');
    $report['passed']=true;
}catch(Throwable $e){$report['passed']=false;$report['failed_phase']=$phase;$report['failure_class']=get_class($e);$report['failure_line']=$e->getLine();}
finally {
    if($actor!==null) {
        try {
            $pdo->beginTransaction();
            foreach(['accounting','ap','billing'] as $module)RBACResolver::revokeModule($mid,$module,4);
            deactivateMembership($actor,2002);
            $pdo->prepare('UPDATE users SET is_active=0,password=NULL,password_hash=NULL WHERE tenant_id=2002 AND id=? AND email=?')->execute([$actor,$email]);
            $pdo->commit();
            $q=$pdo->prepare('SELECT COUNT(*) FROM membership_module_access WHERE membership_id=?');$q->execute([$mid]);
            $grantsRemoved=(int)$q->fetchColumn()===0;
            $q=$pdo->prepare("SELECT COUNT(*) FROM users u JOIN tenant_memberships tm ON tm.user_id=u.id AND tm.tenant_id=u.tenant_id
                JOIN user_tenants ut ON ut.user_id=u.id AND ut.tenant_id=u.tenant_id
                WHERE u.id=? AND u.tenant_id=2002 AND u.email=? AND u.is_active=0 AND u.is_global_admin=0
                AND u.password IS NULL AND u.password_hash IS NULL AND tm.id=? AND tm.status='revoked' AND ut.status='inactive'");
            $q->execute([$actor,$email,$mid]);
            $report['synthetic_actor_retired']=$grantsRemoved&&(int)$q->fetchColumn()===1;
            if(isset($record))file_put_contents($record,json_encode(['user_id'=>$actor,'membership_id'=>$mid,'tenant_id'=>2002,'email'=>$email,'retired'=>$report['synthetic_actor_retired']],JSON_THROW_ON_ERROR));
            $report['synthetic_actor_id']=$actor;
            if(!$report['synthetic_actor_retired'])$report['passed']=false;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$report['passed']=false;$report['retirement_failed']=true;}
    }
    if($cookie!==null&&is_file($cookie)){
        $report['private_session_removed']=unlink($cookie);
        if(!$report['private_session_removed'])$report['passed']=false;
    }
}
$report['checks']=$checks;$report['requests']=$requests;echo json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
exit($report['passed']?0:1);

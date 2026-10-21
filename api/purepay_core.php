<?php
/** Separate Core metadata connection. No payment or payroll execution actions. */
declare(strict_types=1);
require_once __DIR__.'/../core/api_bootstrap.php';
require_once __DIR__.'/../core/integrations/purepay_core.php';
$ctx=api_require_auth();
$user=$ctx['user'];$tenantId=(int)$ctx['tenant_id'];
rbac_legacy_require($user,'accounting.bank.manage');
$action=(string)($_GET['action']??'status');
$method=api_method();
$pdo=getDB();
try {
    if($method==='GET' && $action==='status')api_ok(purepayCoreSummary(purepayCoreConnection($pdo,$tenantId)));
    if($method!=='POST')api_error('Method not allowed',405);
    $body=api_json_body();
    $audit=static function()use($pdo,$tenantId,$user,$action):void {
        // Action and company only; credentials and employee payloads never enter audit metadata.
        $pdo->prepare('INSERT INTO audit_log(tenant_id,actor_user_id,event,target_id,meta_json,ip_address,created_at) VALUES(:t,:u,:e,NULL,:m,:ip,NOW())')
            ->execute(['t'=>$tenantId,'u'=>(int)$user['id'],'e'=>'purepay.core.'.$action,'m'=>json_encode(['action'=>$action]),'ip'=>$_SERVER['REMOTE_ADDR']??null]);
    };
    $instance=integrationInstanceId();
    if($instance===null && $action!=='disconnect')api_error('Configure the permanent Core installation identity before connecting.',422);
    switch($action) {
        case 'connect':
            if(!is_string($body['api_key']??null))api_error('API key required',422);
            purepayCoreConnect($pdo,$tenantId,trim($body['api_key']),$instance,$audit);
            $result=purepayCoreSummary(purepayCoreConnection($pdo,$tenantId));break;
        case 'review': $result=purepayCoreReview($pdo,$tenantId,$instance);break;
        case 'confirm':
            if(($body['confirmed']??null)!==true || !is_string($body['review_hash']??null) || !is_string($body['organization_id']??null))api_error('Review and confirm the provider organization first.',422);
            $result=purepayCoreConfirm($pdo,$tenantId,(int)$user['id'],$instance,$body['review_hash'],$body['organization_id'],$audit);break;
        case 'records':
            $kind=$body['kind']??null;
            if(!is_string($kind) || (isset($body['cursor'])&&!is_string($body['cursor'])))api_error('Invalid record page',422);
            if($kind==='person')rbac_legacy_require($user,'payroll.view');
            $result=purepayCoreRecords($pdo,$tenantId,$instance,$kind,50,$body['cursor']??null);break;
        case 'disconnect': purepayCoreDisconnect($pdo,$tenantId,$audit);$result=['connected'=>false];break;
        default: api_error('Unknown Core connection action',404);
    }
    api_ok($result);
} catch(PDOException $e) { api_error('Core could not save or read the integration state. Check the installed migration and try again.',503); }
catch(InvalidArgumentException|RuntimeException $e) { api_error($e->getMessage(),422); }

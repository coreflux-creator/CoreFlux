<?php
/** Reviewed evidence capture only. Never invokes payment, payroll or ledger writes. */
declare(strict_types=1);
require_once __DIR__.'/../core/api_bootstrap.php';
require_once __DIR__.'/../core/RBAC.php';
require_once __DIR__.'/../core/integrations/identity.php';
require_once __DIR__.'/../core/integrations/purepay_financial_observations.php';
$ctx=api_require_auth();$tenant=(int)$ctx['tenant_id'];$user=$ctx['user'];
if(getenv('COREFLUX_ENV')==='coreaccounting')api_error('Not found',404);
// Bills include linked invoice references. Both sides require permission.
foreach(['accounting.bank.manage','ap.view','billing.view'] as $permission)rbac_legacy_require($user,$permission);
if(api_method()!=='POST')api_error('Method not allowed',405);
$body=api_json_body();$action=(string)($_GET['action']??'');
if(!in_array($action,['read','save','history'],true))api_error('Unknown observation action',404);
if(array_diff(array_keys($body),['kind','document_id','core_id','review_hash','confirmed','before'])
    || !is_string($body['kind']??null) || !is_string($body['document_id']??null)
    || (isset($body['core_id']) && !is_int($body['core_id'])) || (isset($body['before']) && !is_int($body['before'])))api_error('Choose a bill or invoice to review.',422);
try {
    $pdo=getDB();$instance=integrationInstanceId();
    if(!$instance)throw new RuntimeException('Core installation identity is required.');
    if($action==='read')api_ok(purepayFinancialObservationRead($pdo,$tenant,$instance,$body['kind'],$body['document_id'],$body['core_id']??null));
    if($action==='history')api_ok(purepayFinancialObservationHistory($pdo,$tenant,$instance,$body['kind'],$body['document_id'],$body['before']??null));
    if(($body['confirmed']??null)!==true || !is_string($body['review_hash']??null))api_error('Review and confirm the displayed evidence before saving.',422);
    $audit=static function(array $meta)use($pdo,$tenant,$user):void {
        $pdo->prepare('INSERT INTO audit_log(tenant_id,actor_user_id,event,target_id,meta_json,ip_address,created_at) VALUES(?,?,"purepay.financial_observation.saved",?,?,?,NOW())')
            ->execute([$tenant,(int)$user['id'],$meta['capture_id'],json_encode($meta,JSON_THROW_ON_ERROR),$_SERVER['REMOTE_ADDR']??null]);
    };
    api_ok(purepayFinancialObservationSave($pdo,$tenant,(int)$user['id'],$instance,$body['kind'],$body['document_id'],$body['core_id']??null,$body['review_hash'],$audit));
}catch(Throwable $e){api_error('Financial evidence could not be reviewed or saved. Refresh the company connection and check the source and Core records.',422);}

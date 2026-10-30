<?php
/** Review original supplier obligations. No provider writes or financial execution. */
declare(strict_types=1);
require_once __DIR__.'/../core/api_bootstrap.php';
require_once __DIR__.'/../core/RBAC.php';
require_once __DIR__.'/../core/purepay_bill_identity.php';
$ctx=api_require_auth();$user=$ctx['user'];$tenant=(int)$ctx['tenant_id'];
if (getenv('COREFLUX_ENV')==='coreaccounting') api_error('Not found',404);
rbac_legacy_require($user,'ap.view');
rbac_legacy_require($user,'accounting.bank.manage');
$billId=(int)($_GET['bill_id']??0);$method=api_method();$action=(string)($_GET['action']??'status');
try {
    if ($method==='GET' && $action==='status') api_ok(purepayBillSummary(getDB(),$tenant,$billId));
    if ($method!=='POST') api_error('Method not allowed',405);
    $body=api_json_body();$remote=purepayBillId($body['provider_bill_id']??null);
    if ($action==='review') api_ok(purepayBillReview(getDB(),$tenant,$billId,$remote));
    if ($action!=='confirm') api_error('Unknown bill link action',404);
    if (($body['confirmed']??null)!==true || !is_string($body['review_hash']??null)) api_error('Confirm both bill identities after reviewing them.',422);
    $audit=static function(array $meta) use($tenant,$user):void {
        getDB()->prepare('INSERT INTO audit_log(tenant_id,actor_user_id,event,target_id,meta_json,ip_address,created_at)
            VALUES(?,?,"purepay.bill.link_reviewed",?,?,?,NOW())')->execute([$tenant,(int)$user['id'],$meta['core_bill_id'],json_encode($meta),$_SERVER['REMOTE_ADDR']??null]);
    };
    api_ok(purepayBillConfirm(getDB(),$tenant,(int)$user['id'],$billId,$remote,$body['review_hash'],$audit));
} catch (PurePayApiException $e) { api_error($e->getMessage(),422); }
catch (Throwable $e) { api_error('The bill link could not be reviewed or saved. Check the integration setup and try again.',422); }

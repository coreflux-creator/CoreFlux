<?php
/** Original-workspace delivery status and an explicitly reviewed internal-ledger retry. */
declare(strict_types=1);
require_once __DIR__.'/../../../core/api_bootstrap.php';
require_once __DIR__.'/../../../core/RBAC.php';
require_once __DIR__.'/../../staffing/lib/timesheets.php';
require_once __DIR__.'/../lib/native_review.php';
require_once __DIR__.'/../lib/accounting_handoff.php';

$ctx=api_require_auth();
api_require_legacy_permission($ctx,'time.view');
$tenant=staffingTimeWorkspace();$id=(int)api_query('timesheet_id',0);
$canReadAccounting=rbac_legacy_can($ctx['user'],'accounting.je.view');
if ($id<=0) api_error('Choose a timesheet.',422);
header('Cache-Control: private, no-store');
try {
    $status=timeAccountingHandoffStatus($tenant,$id,$canReadAccounting);
    if (!$status) api_error('Accounting handoff not found in this workspace.',404);
    if (api_method()==='GET') api_ok($status);
    if (api_method()!=='POST') api_error('Method not allowed.',405);
    api_require_legacy_permission($ctx,'time.approve');
    api_require_legacy_permission($ctx,'accounting.je.post');
    $body=api_json_body();$revision=$body['review_revision']??null;
    if (!is_string($revision) || !preg_match('/^[a-f0-9]{64}$/D',$revision)) api_error('Review the accounting handoff before retrying.',409);
    $error=timeAccountingHandoffDispatch($tenant,$id,$revision,(int)$ctx['user']['id']);
    if ($error!==null) api_error($error,409);
    api_ok(timeAccountingHandoffStatus($tenant,$id,$canReadAccounting));
} catch(Throwable $e) {
    if ($e instanceof PDOException || !in_array((int)$e->getCode(),[403,404,409,422,503],true)) {
        error_log('Time accounting handoff failed: '.$e->getMessage());
        api_error('Accounting status is unavailable. Reload and try again.',503);
    }
    api_error($e->getMessage(),(int)$e->getCode());
}

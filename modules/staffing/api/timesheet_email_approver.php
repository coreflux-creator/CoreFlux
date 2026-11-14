<?php
/** Authorized dispatch of a complete weekly review to an independent external recipient. */
declare(strict_types=1);
require_once __DIR__.'/../../../core/api_bootstrap.php';
require_once __DIR__.'/../../../core/staffing_email_approval.php';
require_once __DIR__.'/../../../core/mail_bootstrap.php';
require_once __DIR__.'/../../../core/tenant_mail.php';
$ctx=api_require_auth();
api_require_legacy_permission($ctx,'staffing.time.approve');
$method=api_method();
if ($method !== 'POST') api_error('Method not allowed',405);
header('Cache-Control: private, no-store');
$body=api_json_body();$tsId=(int)($body['timesheet_id']??0);
$email=trim((string)($body['approver_email']??''));$name=trim((string)($body['approver_name']??''));
if ($tsId<=0 || !filter_var($email,FILTER_VALIDATE_EMAIL)) api_error('Choose a timesheet and valid approver email.',422);
$tenantId=(int)$ctx['tenant_id'];
try {$tokens=staffingEmailApprovalMint($tenantId,$tsId,$email,$name?:null,72,(int)$ctx['user']['id']);}
catch(Throwable $error) {$failure=staffingEmailApprovalFailure($error);api_error($failure['message'],$failure['status_code']);}
$header=$tokens['header'];
$html=staffingEmailApprovalBodyHtml($header,$tokens['worker_name'].' ('.$tokens['person_number'].')',(float)$tokens['total_hours'],0,$name?:$email,$tokens['approve_url'],$tokens['reject_url'],staffingEmailApprovalBaseUrl().'/modules/staffing/approvals?focus='.$tsId);
$subject='Timesheet awaiting review — '.$tokens['worker_name'].', week of '.$header['period_start'];
$sent=false;$errorMessage=null;
try {
    $sender=cf_tenant_mail_sender($tenantId,'staffing');
    $result=cf_mail_bootstrap()->send($tenantId,'staffing','timesheet_approval_request',[$email],$subject,
        'Review the complete week and confirm your decision: '.$tokens['approve_url']."\n".'To review rejection: '.$tokens['reject_url'],$html,[],[
            'from'=>$sender['from'],'from_name'=>$sender['from_name'],'reply_to'=>$sender['reply_to'],'idempotency_key'=>'staffing-week-token-'.$tokens['token_id'],
        ]);
    $sent=($result['status']??'failed')==='sent' && ($result['driver']??'')!=='log';
    if (!$sent) $errorMessage='The approval email was not delivered. Check email setup before requesting a new link.';
} catch(Throwable $error) {
    error_log('[staffing-email-approval] delivery failed: '.get_class($error));
    $errorMessage='Email delivery could not be confirmed. Check delivery history before requesting another link.';
}
api_ok(['sent'=>$sent,'error'=>$errorMessage,'approver_email'=>$email,'token_id'=>(int)$tokens['token_id'],
    'expires_at'=>$tokens['expires_at'],'timesheet_id'=>$tsId,'timesheet_display_id'=>$header['display_id'],'artifact_id'=>$header['artifact_id']],$sent?201:202);

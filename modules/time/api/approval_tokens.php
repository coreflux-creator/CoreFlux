<?php
/** Client approval links bind the issued set and displayed decision to original time ownership. */
require_once __DIR__.'/../../../core/api_bootstrap.php';
require_once __DIR__.'/../../../core/RBAC.php';
require_once __DIR__.'/../../../core/mail_bootstrap.php';
require_once __DIR__ . '/../../../core/tenant_mail.php';
require_once __DIR__.'/../lib/client_token_review.php';
$method=api_method();$action=$_GET['action']??'';
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');

function timeClientTokenApiFailure(Throwable $error): void
{
    if ($error instanceof PDOException || !($error instanceof RuntimeException || $error instanceof InvalidArgumentException)) {
        error_log('Client time review failed: '.get_class($error).' '.$error->getMessage());
        api_error('The response could not be recorded. Reload and review the time again.',503);
    }
    $status=(int)$error->getCode();
    api_error($error->getMessage(),in_array($status,[403,404,409,410,422,503],true)?$status:422);
}

// Public authority comes from the random link, never a caller-supplied workspace.
if ($method==='GET' && $action==='verify') {
    try {api_ok(timeClientTokenReview((string)($_GET['t']??''),(string)($_GET['a']??'approve')));}
    catch(Throwable $e){timeClientTokenApiFailure($e);}
}
if ($method==='POST' && $action==='respond') {
    $body=api_json_body();
    try {api_ok(timeClientTokenRespond((string)($body['t']??''),(string)($body['action']??''),is_string($body['review_revision']??null)?$body['review_revision']:null,(string)($body['note']??''),$_SERVER['REMOTE_ADDR']??null,(string)($_SERVER['HTTP_USER_AGENT']??'')));}
    catch(Throwable $e){timeClientTokenApiFailure($e);}
}

$ctx=api_require_auth();$user=$ctx['user'];
if ($method==='GET' && $action==='') {
    api_require_legacy_permission($ctx,'time.view');
    $where=['tenant_id=:tenant_id'];$params=[];
    foreach(['placement_id','period_id']as$key)if(!empty($_GET[$key])){$where[]=$key.'=:'.$key;$params[$key]=(int)$_GET[$key];}
    api_ok(['rows'=>staffingTimeQuery('SELECT id,placement_id,period_id,client_approver_email,issued_at,expires_at,response,responded_at,email_status,entries_total_hours FROM time_approval_tokens WHERE '.implode(' AND ',$where).' ORDER BY issued_at DESC LIMIT 200',$params)]);
}
if ($method==='POST' && $action==='issue') {
    api_require_legacy_permission($ctx,'time.tokenized_email.issue');
    $body=api_json_body();$placementId=(int)($body['placement_id']??0);$periodId=(int)($body['period_id']??0);
    try {$issued=timeClientTokenIssue((int)$ctx['tenant_id'],(int)$user['id'],$placementId,$periodId,is_array($body['entry_ids']??null)?$body['entry_ids']:[],(int)($body['ttl_days']??7));}
    catch(Throwable $e){timeClientTokenApiFailure($e);}
    $pdo=getDB();$tokenId=$issued['token_id'];$expiresAt=$issued['expires_at'];$entries=$issued['entries'];$placement=$issued['placement'];
    // Build email body + URLs
    $baseUrl = defined('APP_URL') ? rtrim(APP_URL, '/') : (getenv('APP_URL') ?: 'https://www.corefluxapp.com');
    $approveUrl = "{$baseUrl}/time_approve.php?t={$issued['raw']}&a=approve";
    $rejectUrl  = "{$baseUrl}/time_approve.php?t={$issued['raw']}&a=reject";
    $tokenRow = array_merge(['placement_id' => $placementId, 'expires_at' => $expiresAt], $placement);
    $body = timeTokenBuildEmailBody($tokenRow, $entries, $placement, $approveUrl, $rejectUrl);

    // Send via MailService — sender resolved per tenant (Model B)
    $sender  = cf_tenant_mail_sender((int) $ctx['tenant_id'], 'time');
    $svc     = cf_mail_bootstrap();
    $sendRes = $svc->send(
        (int) $ctx['tenant_id'],
        'time',
        'client_approval_request',
        [$placement['client_approver_email']],
        $body['subject'],
        $body['text'],
        $body['html'],
        [],
        [
            'from'            => $sender['from'],
            'from_name'       => $sender['from_name'],
            'reply_to'        => $sender['reply_to'],
            'idempotency_key' => 'time-token-' . $tokenId,
        ]
    );

    $emailStatus = ($sendRes['status'] ?? 'failed') === 'sent' && ($sendRes['driver'] ?? '') !== 'log' ? 'sent' : 'failed';
    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    $pdo->prepare(
        'UPDATE time_approval_tokens
         SET email_status = :s, provider_message_id = :pmid, email_error = :err
         WHERE tenant_id = :tenant_id AND id = :id'
    )->execute([
        's'    => $emailStatus,
        'pmid' => $sendRes['provider_message_id'] ?? null,
        'err'  => $sendRes['error'] ?? null,
        'id'   => $tokenId, 'tenant_id' => (int)$ctx['tenant_id'],
    ]);

    if ($emailStatus === 'failed') {
        api_ok([
            'token_id'      => $tokenId,
            'email_status'  => $emailStatus,
            'email_error'   => $sendRes['error'] ?? 'unknown',
            'message'       => 'Token created but email delivery failed. You can resend or revoke.',
        ], 202);
    }
    api_ok([
        'token_id'     => $tokenId,
        'email_status' => $emailStatus,
        'expires_at'   => $expiresAt,
    ], 201);
}

if ($method==='POST' && $action==='revoke') {
    api_require_legacy_permission($ctx,'time.tokenized_email.revoke');
    try {timeClientTokenRevoke((int)$ctx['tenant_id'],(int)$user['id'],(int)($_GET['id']??0));api_ok(['ok'=>true]);}
    catch(Throwable $e){timeClientTokenApiFailure($e);}
}
api_error('Method not allowed',405);

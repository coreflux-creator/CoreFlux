<?php
declare(strict_types=1);
require_once __DIR__.'/../../../core/api_bootstrap.php';
require_once __DIR__.'/../../../core/jobdiva/party_review.php';
$ctx=api_require_auth();$user=$ctx['user'];$workspace=(int)$ctx['tenant_id'];$actor=(int)$user['id'];
rbac_legacy_require_any($user,['tenant_admin.integrations','integrations.jobdiva.manage']);
rbac_legacy_require($user,'people.manage');
if(api_method()!=='POST')api_error('Method not allowed',405);
$body=api_json_body();$action=(string)(api_query('action')??'');
try{
    if($action==='preview')api_ok(jobdivaPartyReviewPreview($workspace,$actor,$body));
    if(array_diff(array_keys($body),['review_token','confirm_review'])||!is_string($body['review_token']??null))throw new InvalidArgumentException('Use the exact identity review.');
    if($action==='status')api_ok(jobdivaPartyReviewStatus($workspace,$actor,$body['review_token']));
    if($action==='apply'&&($body['confirm_review']??null)===true)api_ok(jobdivaPartyReviewApply($workspace,$actor,$body['review_token']));
    throw new InvalidArgumentException('Review and confirm both identities before linking.');
}catch(InvalidArgumentException $e){api_error($e->getMessage(),422);}
catch(Throwable $e){$code=(int)$e->getCode();api_error(in_array($code,[404,409],true)?$e->getMessage():'Could not complete the identity review. No partial link was saved.',in_array($code,[404,409],true)?$code:500);}

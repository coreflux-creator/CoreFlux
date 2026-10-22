<?php
declare(strict_types=1);
require_once __DIR__.'/../../../core/api_bootstrap.php';
require_once __DIR__.'/../../../core/jobdiva/person_review.php';
$ctx=api_require_auth(); $user=$ctx['user']; $tenant=(int)$ctx['tenant_id']; $actor=(int)($user['id']??0);
rbac_legacy_require_any($user,['tenant_admin.integrations','integrations.jobdiva.manage']);
rbac_legacy_require($user,'people.view');
if (api_method()!=='POST') api_error('Method not allowed',405);
$body=api_json_body(); $action=(string)(api_query('action')??'');
try {
    if ($action==='preview') api_ok(jobdivaPersonReviewPreview($tenant,$actor,$body));
    if (array_diff(array_keys($body),['review_token','confirm_review']) || !is_string($body['review_token']??null)) throw new InvalidArgumentException('Use the exact candidate review');
    if ($action==='status') api_ok(jobdivaPersonReviewStatus($tenant,$actor,$body['review_token']));
    if ($action==='apply' && ($body['confirm_review']??false)===true) api_ok(jobdivaPersonReviewApply($tenant,$actor,$body['review_token']));
    throw new InvalidArgumentException('Confirm the reviewed identities before linking');
} catch (InvalidArgumentException $e) {api_error($e->getMessage(),422);}
catch (RuntimeException $e) {api_error($e->getMessage(),409);}

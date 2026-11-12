<?php
declare(strict_types=1);
require_once __DIR__.'/../../../core/api_bootstrap.php';
require_once __DIR__.'/../../../core/RBAC.php';
require_once __DIR__.'/../lib/source_rule_review.php';
$ctx=api_require_auth();$user=$ctx['user'];$method=api_method();
$kind=(string)api_query('kind','');$placement=(int)api_query('placement_id',0);
if(!in_array($kind,['chain','referral','commission'],true)||$placement<=0)api_error('Choose a placement and rule set.',422);
rbac_legacy_require($user,'placements.view');
if($kind==='commission')rbac_legacy_require($user,'placements.commissions.view');
$permission=match($kind){'chain'=>'placements.manage','referral'=>'placements.referrals.manage','commission'=>'placements.commissions.manage'};
$tenant=(int)placementsGraphTenantId((int)$ctx['tenant_id']);
try{
    if($method==='GET')api_ok(placementSourceRuleReview($tenant,$placement,$kind)+['can_apply'=>rbac_legacy_can($user,$permission)]);
    if($method==='POST'){rbac_legacy_require($user,$permission);api_ok(placementSourceRuleReplace($tenant,$placement,$kind,api_json_body(),$user));}
}catch(PDOException $e){error_log('[source rule review] '.$e->getCode());api_error('Source rule review is unavailable. Refresh and try again.',503);}
catch(InvalidArgumentException|RuntimeException $e){api_error($e->getMessage(),in_array($e->getCode(),[403,404,409,422,503],true)?$e->getCode():422);}
api_error('Method not allowed',405);

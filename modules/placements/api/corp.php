<?php
/** C2C corporate metadata; encrypted tax values are never returned. */
declare(strict_types=1);
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/corp_details.php';
$ctx=api_require_auth();$user=$ctx['user'];$method=api_method();
$placementId=(int)api_query('placement_id',0);
if($placementId<=0)api_error('placement_id required',400);
$tenantId=(int)placementsGraphTenantId((int)$ctx['tenant_id']);
if(!in_array($method,['GET','PUT','POST'],true))api_error('Method not allowed',405);
rbac_legacy_require($user,$method==='GET'?'placements.corp.view':'placements.corp.manage');
try {
    $result=$method==='GET'?placementCorpReview($tenantId,$placementId):placementCorpSave($tenantId,$placementId,api_json_body(),$user);
    if($method==='GET')placementsAudit('placement.corp.viewed',['placement_id'=>$placementId],$placementId);
    api_ok($result);
}catch(PDOException $e){
    // This route accepts private corporate data; log no query values.
    error_log('[corporate details] Database operation failed: '.$e->getCode());
    api_error('Corporate details could not be saved. Please try again.',503);
}catch(InvalidArgumentException|RuntimeException $e){
    api_error($e->getMessage(),in_array($e->getCode(),[403,404,409,422,503],true)?$e->getCode():422);
}

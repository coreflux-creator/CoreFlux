<?php
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/source_rule_edit.php';
$ctx=api_require_auth(); $user=$ctx['user']; $method=api_method();
if ($method==='GET') {
    rbac_legacy_require($user,'placements.commissions.view');
    $pid=(int)api_query('placement_id',0);
    if($pid<=0) api_error('placement_id required',400);
    if(!placementGet($pid)) api_error('Placement not found',404);
    api_ok(['commissions'=>placementCommissions($pid)]);
}

if (in_array($method,['POST','PATCH','DELETE'],true)) {
    rbac_legacy_require($user,'placements.commissions.manage');
    $tenant=(int)placementsGraphTenantId((int)$ctx['tenant_id']);
    $id=(int)api_query('id',0); $pid=(int)api_query('placement_id',0);
    try {
        if($method!=='POST') {
            if($id<=0) api_error('id required',400);
            $q=getDB()->prepare('SELECT placement_id FROM placement_commissions WHERE tenant_id=? AND id=?');
            $q->execute([$tenant,$id]);$pid=(int)$q->fetchColumn();
            if($pid<=0) api_error('Rule not found',404);
        }
        if($pid<=0) api_error('placement_id required',400);
        $result=placementSourceRuleEdit($tenant,$pid,'commission',match($method){'POST'=>'create','PATCH'=>'update',default=>'delete'},
            $id,$method==='DELETE'?[]:api_json_body(),$user);
        api_ok($result,$method==='POST'?201:200);
    } catch(PDOException $e) {
        error_log('[placement commission edit] '.$e->getCode());
        api_error('The rule could not be saved. Refresh the contract and try again.',503);
    } catch(InvalidArgumentException|RuntimeException $e) {
        api_error($e->getMessage(),in_array($e->getCode(),[403,404,409,422,503],true)?$e->getCode():422);
    }
}
api_error('Method not allowed',405);

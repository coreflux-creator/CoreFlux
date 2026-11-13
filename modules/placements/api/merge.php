<?php
declare(strict_types=1);
require_once __DIR__.'/../../../core/api_bootstrap.php';
require_once __DIR__.'/../../../core/RBAC.php';
require_once __DIR__.'/../lib/merge_review.php';
require_once __DIR__.'/../../../core/jobdiva/mapping_alignment.php';
$ctx=api_require_auth();$user=$ctx['user'];$workspace=(int)$ctx['tenant_id'];$actor=(int)$user['id'];
$method=api_method();$action=(string)api_query('action','candidates');
rbac_legacy_require($user,'placements.manage');rbac_legacy_require($user,'placements.financials.manage');
try{
    if($method==='GET'&&$action==='candidates'){
        $result=JobdivaStoredReviewTransaction::run(static function()use($workspace,$actor):array{
            placementMergeActor(getDB(),$workspace,$actor);
            return jobdivaMappingRepairDuplicatePlacements($workspace,$actor,100,true);
        },true);
        api_ok($result);
    }
    if($method==='POST'&&$action==='preview'){
        $body=api_json_body();$survivor=$body['survivor_id']??null;$retired=$body['retired_id']??null;
        if(!is_int($survivor)||!is_int($retired))throw new InvalidArgumentException('Choose two complete placement IDs.',422);
        api_ok(placementMergeReview($workspace,$actor,$survivor,$retired));
    }
    if($method==='POST'&&$action==='apply')api_ok(placementMergeApply($workspace,$actor,api_json_body()));
}catch(PDOException $e){error_log('[placement consolidation] '.$e->getCode());api_error('The placement review could not be saved. Refresh and try again.',503);}
catch(InvalidArgumentException|RuntimeException $e){api_error($e->getMessage(),in_array($e->getCode(),[403,404,409,422,503],true)?$e->getCode():409);}
api_error('Method not allowed',405);

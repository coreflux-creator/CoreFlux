<?php
declare(strict_types=1);
require_once __DIR__.'/../../../core/api_bootstrap.php';
require_once __DIR__.'/../../../core/RBAC.php';
require_once __DIR__.'/../lib/time_history.php';
$ctx=api_require_auth();
rbac_legacy_require($ctx['user'],'placements.view');
rbac_legacy_require($ctx['user'],'staffing.time.approve');
if (api_method()!=='GET') api_error('Method not allowed',405);
header('Cache-Control: no-store, private');
try {
    $before=(string)api_query('before_id','0');$limit=(string)api_query('limit','50');
    foreach ([$before,$limit] as $value) if (!preg_match('/^(0|[1-9][0-9]*)$/D',$value)||(string)(int)$value!==$value)
        throw new InvalidArgumentException('Use a valid history page.',422);
    api_ok(placementTimeHistory((int)$ctx['tenant_id'],(string)api_query('placement_id',''),(int)$before,(int)$limit));
} catch (PDOException $e) { error_log('[placement time history] '.$e->getCode());api_error('Time history is temporarily unavailable.',503); }
catch (InvalidArgumentException|RuntimeException $e) { api_error($e->getMessage(),in_array($e->getCode(),[403,404,409,422],true)?$e->getCode():409); }

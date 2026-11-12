<?php
declare(strict_types=1);
require_once __DIR__.'/../core/api_bootstrap.php';
require_once __DIR__.'/../core/RBAC.php';
require_once __DIR__.'/../core/sub_tenants.php';
require_once __DIR__.'/../core/integrations/staffing_reconciliation.php';
$ctx=api_require_auth();$tenant=(int)$ctx['tenant_id'];$user=$ctx['user'];
if(getenv('COREFLUX_ENV')==='coreaccounting')api_error('Not found',404);
foreach(['accounting.view','ap.view','billing.view','placements.view','placements.financials.view','people.view','time.view','payroll.view']as$permission)
    rbac_legacy_require($user,$permission);
header('Cache-Control: no-store, private');
$permissions=['workbook_manage'=>rbac_legacy_can($user,'accounting.bank.manage')];
if(api_method()==='GET'&&($_GET['action']??'')==='context')
    api_ok(['tenant_id'=>$tenant,'permissions'=>$permissions]);
if(api_method()!=='POST')api_error('Method not allowed',405);
$body=api_json_body();
if(array_diff(array_keys($body),['placement_number','start','end','payroll_run_id','before'])
    ||!is_string($body['placement_number']??null)||!is_string($body['start']??null)||!is_string($body['end']??null)
    ||(isset($body['payroll_run_id'])&&!is_string($body['payroll_run_id']))||(isset($body['before'])&&!is_int($body['before'])))
    api_error('Choose a placement and work period.',422);
try{
    $owners=[];foreach(['people','placements','time']as$m)$owners[$m]=effectiveTenantIdForModule($m,$tenant)??$tenant;
    $instance=integrationInstanceId();if(!$instance)throw new RuntimeException('Installation identity unavailable');
    $report=staffingReconciliationRead(getDB(),$tenant,$instance,$owners,strtoupper(trim($body['placement_number'])),
        $body['start'],$body['end'],$body['payroll_run_id']??null,$body['before']??null);
    $report['permissions']=$permissions;api_ok($report);
}catch(InvalidArgumentException $e){api_error($e->getMessage(),422);}
catch(Throwable $e){api_error('The comparison could not be read. Check the reviewed company connections, saved evidence and selected period.',422);}

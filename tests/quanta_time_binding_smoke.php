<?php
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/fixtures/quanta_time_binding_checks.php';
$instance='381e452a-5d11-4e2e-9daa-e9427501898a';
$person=integrationRecordReference($instance,1,'person','P-333');
$placement=integrationRecordReference($instance,1,'placement','PL-810');
$bound=['contract_version'=>QUANTA_TIME_BINDING_VERSION,
    'policy_id'=>'cfta_'.integrationDeliveryHash(['a','worker-1','site-1']),'policy_version'=>1,
    'assignment_id'=>quantaCoreRemoteId('a',$placement),'assignment_version'=>2,'work_date'=>'2026-10-07',
    'person'=>$person,'placement'=>$placement,'worker_id'=>'worker-1','worksite_id'=>'site-1'];
$raw=['id'=>'eligibility-entry','worker_id'=>'worker-1','worksite_id'=>'site-1','work_date'=>'2026-10-07',
    'timesheet_status'=>'approved','regular_minutes'=>60,'overtime_minutes'=>0,'doubletime_minutes'=>0,'pto_minutes'=>0,
    'duration_minutes'=>60,'timesheet_id'=>'eligibility-sheet','approved_at'=>'2026-10-10T12:00:00Z',
    'updated_at'=>'2026-10-10T12:00:00Z','dimension_values'=>[],'core_assignment'=>$bound];
$result=quantaTimeBindingChecks(['workspace_id'=>'a','approved'=>$raw,'state'=>$raw]);
foreach ($result['checks'] as $check) echo "PASS {$check}\n";
echo "Quanta time binding: {$result['passed']} passed\n";

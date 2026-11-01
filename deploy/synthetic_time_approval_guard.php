<?php
declare(strict_types=1);

/** Exact existing demo only; this is not a general approval API. */
function syntheticTimeApprovalGuard(array $entry,array $rate,array $snapshot): void
{
    foreach (['id'=>1,'tenant_id'=>999,'person_id'=>15,'placement_id'=>13,'created_by_user_id'=>4] as $key=>$value)
        if ((int)($entry[$key]??0)!==$value) throw new RuntimeException('Demo time identity differs');
    if (($entry['work_date']??null)!=='2026-10-19' || (float)($entry['hours']??0)!==8.0
        || ($entry['source']??null)!=='quanta' || ($entry['source_system']??null)!=='quanta'
        || ($entry['category']??null)!=='regular_billable' || ($entry['hour_type']??null)!=='regular'
        || (int)($entry['timesheet_id']??0)<=0 || !in_array($entry['status']??null,['pending_review','approved'],true))
        throw new RuntimeException('Demo hours or lifecycle differs');
    foreach (['bill_extracted_at','ap_extracted_at','payroll_extracted_at','superseded_by_id'] as $field)
        if (!array_key_exists($field,$entry) || $entry[$field]!==null) throw new RuntimeException('Demo time already consumed');
    if ((int)($rate['id']??0)<=0 || (float)($rate['bill_rate']??0)!==60.0 || (float)($rate['pay_rate']??0)!==30.0
        || ($rate['currency']??null)!=='USD' || ($rate['bill_rate_unit']??null)!=='hour' || ($rate['pay_rate_unit']??null)!=='hour')
        throw new RuntimeException('Approved demo rates differ');
    if ($entry['status']==='approved' && ((int)($entry['approved_by_user_id']??0)!==51
        || ($entry['approved_via']??null)!=='manual' || empty($entry['approved_at'])
        || (int)($entry['rate_snapshot_id']??0)!==(int)$rate['id'])) throw new RuntimeException('Prior approval was not this test');
    if ($entry['status']==='pending_review' && (!empty($entry['approved_by_user_id']) || !empty($entry['approved_at'])))
        throw new RuntimeException('Pending demo retains a prior approval');
    $body=$snapshot['payload']['body']??[];$source=$snapshot['entry']??[];
    if (($snapshot['payload']['workspace_id']??null)!=='0598d4ef-3075-4289-9714-c93f904aa85d'
        || ($body['assignment_id']??null)!=='cfa_33fdaed15cffaba6f9468cba05de1ac9d39651031f2eb5bfdc7079f3a87c45ac'
        || ($body['core_tenant_id']??null)!==999 || ($snapshot['placement_number']??null)!=='PL-13'
        || ($body['components']??null)!==[['entry_id'=>1,'component'=>'regular','status'=>$entry['status']]]
        || ($source['id']??null)!=='d9caaf9a-877b-4f59-89d9-c2b71983a676'
        || ($source['worker_id']??null)!=='cfw_2c5e38cf6059e5d00f16b534feb7e2006c697e0e82b5b009cabcfa86eef0892c'
        || ($source['work_date']??null)!=='2026-10-19' || (float)($source['hours']??0)!==8.0
        || ($source['source_approval']['timesheet_id']??null)!=='8c9e9039-109f-421c-9e7d-585adb8400a7')
        throw new RuntimeException('Original Quanta demo identity differs');
}

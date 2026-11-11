<?php
/** Import exactly the approved synthetic hour, preserving its captured assignment. */
declare(strict_types=1);
if(($argv[1]??'')!=='--import-original-synthetic-hour')exit(2);
require __DIR__.'/thunderhawk_lifecycle_native.php';
$h=new ThunderhawkLifecycleNative('time_import');$error=null;
$source='dcfd06b9-6f30-4ed9-8426-789fc255f194';
try{
    $h->expect((int)$h->pdo->query('SELECT COUNT(*) FROM time_entries WHERE tenant_id=2002 AND person_id=368')->fetchColumn()===0,'The synthetic person has no Core time before import');
    $h->expect((int)$h->pdo->query('SELECT COUNT(*) FROM quanta_time_routes WHERE tenant_id=2002 AND placement_id=874')->fetchColumn()===0,'A captured-assignment route has not already been created');
    $h->actor(['people'=>'read','placements'=>'read','time'=>'write','integrations'=>'admin','accounting'=>'admin']);
    $route=['worker_id'=>'cfw_14ff9fa0959916e822421f01ba8884b4fc667e774a918af3c5c74d814a422574',
        'worksite_id'=>'1bc9011d-12df-458e-b79d-c31f09b9ee09','placement_id'=>874,'effective_from'=>'2026-11-09',
        'effective_to'=>'2026-11-30','dimension_values'=>[],
        'quanta_assignment_id'=>'cfa_e134dbe4453e1e563ba7329c42ba5fd6a82d8e6eac02c2a7a58a1f5c6e55d4dc'];
    $wrong=$route;$wrong['placement_id']=864;
    [$status]=$h->request('/api/quanta.php?action=save_routes',['routes'=>[$wrong]],'POST');
    $h->expect(in_array($status,[400,409,422],true),'A captured assignment cannot be routed to another person placement');
    $h->checkpoint('route_sending');
    [$status,$routes]=$h->request('/api/quanta.php?action=save_routes',['routes'=>[$route]],'POST');
    $h->expect($status===200,'Native routing saved the reviewed assignment');
    $matches=array_values(array_filter($routes['routes']??[],static fn($r)=>(int)$r['placement_id']===874));
    $h->expect(count($matches)===1&&$matches[0]['quanta_assignment_id']===$route['quanta_assignment_id'],'Exactly one captured-assignment route exists');
    $h->report['route_id']=(int)$matches[0]['id'];$h->checkpoint('route_saved');
    $window=['changed_since'=>'2026-11-09','source_status'=>'approved'];
    [$status,$preview]=$h->request('/api/quanta.php?action=preview',$window,'POST');
    $matches=array_values(array_filter($preview['rows']??[],static fn($r)=>$r['id']===$source));
    $row=$matches[0]??[];
    $h->expect($status===200&&count($matches)===1&&($row['status']??null)==='ready'&&($row['person_number']??null)==='P-368'
        &&($row['placement_number']??null)==='PL-874'&&(float)($row['hours']??0)===1.0,'Approved source hour resolves to the original Core person and placement');
    $h->expect(($row['source_approval']['timesheet_id']??null)==='63939bf8-3b68-4188-a097-cf45e9f51016'
        &&($row['core_assignment']['assignment_id']??null)===$route['quanta_assignment_id'],'Source approval and captured assignment are preserved in preview');
    $h->report['source_preview']=$row;$h->checkpoint('import_sending');
    $body=$window+['entry_ids'=>[$source]];
    [$status,$import]=$h->request('/api/quanta.php?action=import',$body,'POST');
    $h->expect($status===200&&($import['inserted']??null)===1&&($import['updated']??null)===0,'Exactly one hour row was imported');
    $h->report['import']=$import;$h->checkpoint('imported');
    [$status,$replay]=$h->request('/api/quanta.php?action=import',$body,'POST');
    $h->expect($status===200&&($replay['inserted']??null)===0&&($replay['updated']??null)===0&&($replay['already_imported']??null)===1,'Import replay creates no duplicate time');
    $h->report['import_replay']=$replay;
    $rows=$h->pdo->query('SELECT id,person_id,placement_id,hours,status,timesheet_id,work_date FROM time_entries WHERE tenant_id=2002 AND person_id=368')->fetchAll(PDO::FETCH_ASSOC);
    $h->expect(count($rows)===1&&(int)$rows[0]['placement_id']===874&&(float)$rows[0]['hours']===1.0
        &&$rows[0]['work_date']==='2026-11-09'&&$rows[0]['status']==='pending_review','Imported source approval does not bypass Core review');
    $h->report['entry_id']=(int)$rows[0]['id'];$h->report['timesheet_id']=(int)$rows[0]['timesheet_id'];
    [$status,$review]=$h->request('/api/quanta.php?action=time_review_preview',['source_entry_id'=>$source],'POST');
    $h->expect($status===200&&($review['state']??null)==='pending_review'&&isset($review['delivery']),'Core offers its pending-review state back to Quanta');
    $h->report['return_review']=$review;$h->checkpoint('return_review_sending');
    $send=['delivery_id'=>(int)$review['delivery']['id'],'review_hash'=>$review['delivery']['review_hash'],'confirm_status'=>true];
    [$status,$sent]=$h->request('/api/quanta.php?action=time_review_send',$send,'POST');
    $h->expect($status===200&&($sent['state']??null)==='delivered','Quanta accepted the Core review state');
    [$status,$replay]=$h->request('/api/quanta.php?action=time_review_send',$send,'POST');
    $h->expect($status===200&&$replay===$sent,'Core review replay preserves the original receipt');
    $h->report['return_delivery']=$sent;$h->report['core_time_status']='pending_review';
}catch(Throwable $e){$error=$e;}
$h->finish($error,['quanta_time_routes','quanta_time_imports','quanta_time_approval_evidence','time_entries','time_periods',
    'staffing_timesheets','artifact_objects','artifact_events','artifact_relationships','integration_deliveries','integration_delivery_subjects']);

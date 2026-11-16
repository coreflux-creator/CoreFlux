<?php
/** Exercise the real authenticated reader with a disposable cached token and fixed transport. */
$pdo->beginTransaction();
try {
    $insert('tenants',['id'=>$workspace,'name'=>'Synthetic exact Start fetch','is_active'=>1,'is_simulation'=>1]);
    $insert('jobdiva_connections',['tenant_id'=>$workspace,'client_id'=>'synthetic','username'=>'synthetic','password_enc'=>encryptField('synthetic-unused'),
        'session_token_enc'=>encryptField('synthetic-cached-token'),'session_token_exp'=>date('Y-m-d H:i:s',time()+3600),'status'=>'connected']);
    $root=['id'=>'99310001','candidate id'=>'99310002','job id'=>'99310003','start date'=>'2026-10-01','startStatus'=>null];
    $returned=[$root];$calls=0;
    $GLOBALS['__jobdiva_transport']=static function($method,$path,$body,$query,$auth,$token)use(&$returned,&$calls):array{
        if($method!=='POST'||$path!=='/apiv2/jobdiva/searchStart'||$body!==['jobId'=>99310003,'candidateid'=>99310002,'maxreturned'=>100,'offset'=>0]
            ||$query!==null||!$auth||$token!=='synthetic-cached-token')throw new RuntimeException('Unexpected exact assignment request');
        $calls++;return ['status'=>200,'body'=>$returned,'headers'=>[]];
    };
    $before=$state();$normal=jobdivaFetchExactAssignmentById($workspace,'99310001',$root);
    $check($normal['status']==='not_assignment'&&$normal['row']===null,'Ordinary exact lookup never treats a blank workflow label as verified');
    $review=jobdivaFetchExactAssignmentById($workspace,'99310001',$root,true);
    $check($review['status']==='contract_review'&&$review['row']===$root&&empty($review['identity']['valid']),'Mapped contract lookup retains the exact unqualified source for financial review');
    foreach(['Offer','Cancelled','unrecognized','Active']as$status){
        $returned=[array_replace($root,['startStatus'=>$status])];$got=jobdivaFetchExactAssignmentById($workspace,'99310001',$root,true);
        $check($got['status']===($status==='Active'?'verified':'not_assignment'),'Mapped lookup preserves the explicit '.$status.' source decision');
    }
    $returned=[$root,array_replace($root,['startStatus'=>'Active'])];$got=jobdivaFetchExactAssignmentById($workspace,'99310001',$root,true);
    $check($got['status']==='not_assignment'&&$got['identity']['reason']==='ambiguous_assignment_response','Competing returned Starts cannot choose a convenient lifecycle');
    $returned=[array_replace($root,['candidate id'=>'99319999'])];$got=jobdivaFetchExactAssignmentById($workspace,'99310001',$root,true);
    $check($got['status']==='not_assignment'&&$got['row']===null,'A changed returned worker cannot enter financial review');
    $check($calls===8&&$before===$state(),'Exact source reads make only their bounded provider requests and preserve business state');
} finally {
    unset($GLOBALS['__jobdiva_transport']);
    if($pdo->inTransaction())$pdo->rollBack();
}

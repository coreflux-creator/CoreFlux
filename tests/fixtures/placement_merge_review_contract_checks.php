<?php
require_once __DIR__.'/../../core/jobdiva/review_contract_refresh.php';
JobdivaStoredReviewTransaction::run(static function()use($pdo,$workspace,$actor,$a,$b,$mb,$payload,$state,$check,$read,$fails):void{
    $pdo->prepare('UPDATE placements SET status="on_hold",coreflux_overridden_fields=? WHERE id=?')->execute(['["title","rates"]',$a]);
    $root=array_replace($payload,['status'=>'Offer accepted']);
    $review=mappingUpsert($workspace,'jobdiva','jobdiva_assignment_review','jd:99510001',99510001,$root);
    $expected=jobdivaReviewContractSnapshot($workspace,(int)$review['id']);$input=jobdivaReviewContractInput($expected);
    $detail=[['Start ID'=>'99510001','Candidate ID'=>$payload['candidateId'],'Job ID'=>$payload['jobId'],'Employment Category'=>'Hourly Employee','W2'=>true,
        'Bill Rate'=>100,'Pay Rate'=>50,'Bill Rate Unit'=>'hour','Pay Rate Unit'=>'hour','Currency'=>'USD','Start Date'=>$payload['startDate'],
        'BILLING'=>[['Approved'=>true,'Actual Start'=>false]],'SALARY'=>[['Approved'=>true]]]];
    $fresh=$input+['_jd_assignment_detail'=>$detail,'_jd_contract'=>jobdivaAssignmentContractBuild($detail,jobdivaAssignmentStripDerivedFacets($input,'99510001'),'99510001')];
    $check((int)$expected['base']['placement']['id']===$a&&(int)$expected['base']['primary_mapping']['id']===(int)$mb['id']&&(int)$expected['base']['primary_mapping']['internal_entity_id']===$b,
        'Uncertain contract review resolves the current survivor and its retained physical primary pair');
    $history=$read($b);$before=$state();$result=jobdivaReviewContractApply($expected,$fresh,null,$actor);$after=$state();
    $check($result['updated']===1&&$read($b)===$history&&$before['placement_rates']===$after['placement_rates']&&$before['time_entries']===$after['time_entries']&&$before['core_record_numbers']===$after['core_record_numbers'],
        'Queued contract updates the survivor without changing historical placement, approved rates, time or permanent numbers');
    $q=$pdo->prepare('SELECT internal_entity_id,external_id FROM external_entity_mappings WHERE id=?');$q->execute([$mb['id']]);$pair=$q->fetch(PDO::FETCH_ASSOC);
    $check((int)$pair['internal_entity_id']===$b&&$pair['external_id']==='jd:99510001','Queued contract preserves the original historical primary provider pair');
    $before=$state();$check($fails(fn()=>jobdivaReviewContractApply($expected,$fresh,null,$actor))&&$before===$state(),'A merged queue response cannot replay over the newly saved source');
},true);
JobdivaStoredReviewTransaction::run(static function()use($workspace,$actor,$make,$payload,$requestFor,$state,$check,$fails):void{
    $left=$make('Queue primary','jd:99510401');$right=$make('Queue history','jd:99510402');
    $primary=mappingUpsert($workspace,'jobdiva','placement','99510401',$left,array_replace($payload,['startId'=>'99510401']));
    $secondary=array_replace($payload,['startId'=>'99510402']);mappingUpsert($workspace,'jobdiva','placement','99510402',$right,$secondary);
    placementMergeApply($workspace,$actor,$requestFor(placementMergeReview($workspace,$actor,$left,$right),(int)$primary['id']));
    $review=mappingUpsert($workspace,'jobdiva','jobdiva_assignment_review','99510402',99510402,$secondary);$before=$state();
    $check($fails(fn()=>jobdivaReviewContractSnapshot($workspace,(int)$review['id']))&&$before===$state(),'A historical secondary review cannot fetch or apply a contract to the current placement');
},true);

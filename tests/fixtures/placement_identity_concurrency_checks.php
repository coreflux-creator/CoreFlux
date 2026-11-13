<?php
/** Included in the guarded independent-process harness. No provider or financial execution. */
$identityRaceState=static function()use($pdo,$tenant,$projectionRows):array{
    $q=$pdo->prepare('SELECT * FROM core_record_numbers WHERE tenant_id=? ORDER BY entity_type,record_id');$q->execute([$tenant]);
    return [$projectionRows(),$q->fetchAll(PDO::FETCH_ASSOC)];
};
foreach(['COMMIT','ROLLBACK']as$decision)foreach(['merge','target_archive','merge_again']as$change){
    $pdo->prepare('INSERT INTO people(tenant_id,first_name,last_name,status,classification) VALUES(?,"Synthetic","Placement history race","active","w2")')->execute([$tenant]);$person=(int)$pdo->lastInsertId();
    $ids=[];foreach(['Historical','Current','Next']as$label){
        $pdo->prepare('INSERT INTO placements(tenant_id,person_id,title,status,engagement_type,start_date,worksite_country) VALUES(?,?,?,"active","w2","2026-10-01","US")')->execute([$tenant,$person,$label.' placement identity race']);$ids[]=(int)$pdo->lastInsertId();
    }
    [$original,$current,$next]=$ids;
    $originalIdentity=placementIdentityResolve($pdo,$tenant,$original);$currentIdentity=placementIdentityResolve($pdo,$tenant,$current);$nextIdentity=placementIdentityResolve($pdo,$tenant,$next);
    if($change!=='merge'){
        $pdo->beginTransaction();coreRecordNumbersMerge($pdo,$tenant,'placement',$current,$original,$actor);
        $pdo->prepare('UPDATE placements SET deleted_at=NOW() WHERE id=?')->execute([$original]);$pdo->commit();
    }
    $pdo->beginTransaction();
    if($change==='merge'){
        coreRecordNumbersMerge($pdo,$tenant,'placement',$current,$original,$actor);
        $pdo->prepare('UPDATE placements SET deleted_at=NOW() WHERE id=?')->execute([$original]);
    }elseif($change==='target_archive')$pdo->prepare('UPDATE placements SET deleted_at=NOW() WHERE id=?')->execute([$current]);
    else{
        coreRecordNumbersMerge($pdo,$tenant,'placement',$next,$current,$actor);
        $pdo->prepare('UPDATE placements SET deleted_at=NOW() WHERE id=?')->execute([$current]);
    }
    $worker=$spawn(['mode'=>'native','entity'=>'placement_history','workspace'=>$tenant,'placement_id'=>$original]);
    $check($waiting($worker),'Historical placement reader waits for '.$change.' '.$decision);
    if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();
    $beforeIdentityRead=$identityRaceState();$observed=$finish($worker);
    if($change==='target_archive'&&$decision==='COMMIT')$check(!$observed['ok'],'Historical reader rejects a committed unavailable survivor');
    else{
        $expectedCurrent=$change==='merge'&&$decision==='ROLLBACK'?null:($change==='merge_again'&&$decision==='COMMIT'?$nextIdentity['source_number']:$currentIdentity['source_number']);
        $check($observed['ok']&&$observed['result']['entity_id']===$original
            &&$observed['result']['reference']['number']===$originalIdentity['source_number']
            &&($observed['result']['current_placement']['number']??null)===$expectedCurrent,
            'Historical reader retains original identity and observes '.$change.' after '.$decision.': '.json_encode($observed));
    }
    $check($beforeIdentityRead===$identityRaceState(),'Historical reader leaves both identity and business history unchanged after '.$change.' '.$decision);
}

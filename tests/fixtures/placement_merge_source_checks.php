<?php
/** Runs after an actual reviewed merge inside the rollback-only canonical fixture. */
require_once __DIR__.'/../../core/jobdiva/live_contract_refresh.php';
require_once __DIR__.'/../../modules/placements/lib/jobdiva_csv_write.php';
require_once __DIR__.'/../../core/jobdiva/source_rate_repair.php';
$context=jobdivaPlacementWriteContext($pdo,$workspace);$sourceState=$state();
$selected=jobdivaPlacementWriteIdentity($pdo,$context,'99510001',$b);
$check((int)$selected['placement']['id']===$a&&(int)$selected['mapping']['id']===(int)$mb['id']
    &&(int)$selected['mapping']['internal_entity_id']===$b&&$selected['source_owner']['placement_id']===$a,
    'Reviewed primary source resolves the survivor while retaining its original physical provider binding');
$check((int)jobdivaPlacementWriteIdentity($pdo,$context,'99510001',$a)['placement']['id']===$a&&$sourceState===$state(),
    'Current and historical selected placement IDs resolve read-only through the same reviewed source');
$nativeSource=placementJobdivaSourceIdentity($pdo,$read($a));
$check($nativeSource['provenance']==='reviewed_merge'&&$nativeSource['mapping_id']===(int)$mb['id']&&$nativeSource['physical_placement_id']===$b,
    'Native ownership review recognizes the primary source retained on an archived family member');
$previewSource=jobdivaAssignmentRepairSnapshot($workspace,(int)$mb['id']);
$check((int)$previewSource['placement']['id']===$a&&$previewSource['source_owner']!==null,
    'Source repair snapshots current survivor and reviewed ownership before the remote lookup');
$csvSource=jobdivaReconciliationFetchExactPlacement($pdo,$context,'99510001');
$check($csvSource['status']==='matched'&&$csvSource['ids']===[$a]&&(int)$csvSource['mapping']['internal_entity_id']===$b,
    'CSV reconciliation targets the current placement and retains the physical source pair');
$check($fails(fn()=>jobdivaAssignmentRepairSnapshot($workspace,(int)$ma['id']))&&$sourceState===$state(),
    'A retained secondary mapping cannot be submitted as the current source owner');
$primaryRate=jobdivaRepairPlacementSourceRate($workspace,$b,$actor,(int)$mb['id']);
$secondaryRate=jobdivaRepairPlacementSourceRate($workspace,$a,$actor,(int)$ma['id']);
$check($primaryRate['id']===$a&&$primaryRate['reason']==='approved_rate_covers_start'&&$secondaryRate['reason']==='historical_source_not_current_owner'&&$sourceState===$state(),
    'Rate repair resolves historical input to the survivor, preserves approved rates and skips secondary sources');
$pdo->exec('SET @fixture_placement_merge_source_history=1');
$historyBefore=$state();
$check($fails(fn()=>JobdivaStoredReviewTransaction::run(static fn()=>jobdivaPlacementBindRetained($workspace,'jd:99510001',$a,$payload+['sourceRefreshNote'=>'History must commit'],$actor)))&&$historyBefore===$state(),
    'Required source observation failure rolls back the refreshed payload and mapping timestamps');
$pdo->exec('SET @fixture_placement_merge_source_history=0');
foreach(['receipt_missing','instance','source_rebound','source_workspace','new_source','alias_missing','survivor_archived','historical_revived','foreign_directory','unrelated_selection']as$case){
    JobdivaStoredReviewTransaction::run(static function()use($pdo,$owner,$workspace,$a,$b,$c,$ma,$mb,$case,$state,$check,$fails,$payload):void{
        if($case==='receipt_missing')$pdo->prepare('DELETE FROM audit_log WHERE tenant_id=? AND event="placement.identity_merged"')->execute([$workspace]);
        if($case==='instance')$pdo->prepare('UPDATE audit_log SET meta_json=JSON_SET(meta_json,"$.instance_id","different-installation") WHERE tenant_id=? AND event="placement.identity_merged"')->execute([$workspace]);
        if($case==='source_rebound')$pdo->prepare('UPDATE external_entity_mappings SET internal_entity_id=? WHERE id=?')->execute([$a,$mb['id']]);
        if($case==='source_workspace')$pdo->prepare('UPDATE external_entity_mappings SET tenant_id=? WHERE id=?')->execute([$owner,$mb['id']]);
        if($case==='new_source')mappingUpsert($workspace,'jobdiva','placement','99510999',$a,$payload);
        if($case==='alias_missing')$pdo->prepare('DELETE FROM core_record_numbers WHERE tenant_id=? AND entity_type="placement" AND record_id=?')->execute([$owner,$b]);
        if($case==='survivor_archived')$pdo->prepare('UPDATE placements SET deleted_at=NOW() WHERE id=?')->execute([$a]);
        if($case==='historical_revived')$pdo->prepare('UPDATE placements SET deleted_at=NULL WHERE id=?')->execute([$b]);
        $ctx=jobdivaPlacementWriteContext($pdo,$workspace);if($case==='foreign_directory')$ctx['placement_owner']=$workspace;
        $before=$state();
        $check($fails(fn()=>jobdivaPlacementWriteIdentity($pdo,$ctx,'99510001',$case==='unrelated_selection'?$c:$a))&&$before===$state(),
            'Retained-source refresh rejects '.$case.' without partial changes');
    },true);
}
JobdivaStoredReviewTransaction::run(static function()use($pdo,$workspace,$owner,$actor,$a,$b,$ma,$mb,$payload,$state,$check,$read):void{
    $before=$state();$secondary=mappingFindInternal($workspace,'jobdiva','placement','99510001');
    $fresh=$payload+['sourceRefreshNote'=>'Synthetic exact source update'];
    $refreshed=jobdivaPlacementBindRetained($workspace,'jd:99510001',$a,$fresh,$actor);
    $check($refreshed['changed']&&(int)$refreshed['id']===(int)$mb['id']&&(int)$refreshed['internal_entity_id']===$b,
        'Refreshing the reviewed source keeps its mapping ID, external ID and original physical placement');
    $check(mappingFindInternal($workspace,'jobdiva','placement','99510001')===$secondary,
        'Primary snapshot refresh leaves secondary source evidence unchanged');
    $protected=array_diff_key($before,array_flip(['external_entity_mappings','entity_sync_history']));
    $check($protected===array_intersect_key($state(),$protected),'Source refresh changes no native placements, rates, time, custom values, permanent aliases or financial records');
    $q=$pdo->prepare('SELECT internal_entity_id,external_id FROM entity_sync_history WHERE tenant_id=? AND source_system="jobdiva" AND internal_entity_type="placement" ORDER BY id DESC LIMIT 1');$q->execute([$workspace]);$history=$q->fetch(PDO::FETCH_ASSOC);
    $check((int)$history['internal_entity_id']===$b&&$history['external_id']==='jd:99510001','Source observation history retains the original physical identity after consolidation');
},true);
JobdivaStoredReviewTransaction::run(static function()use($pdo,$workspace,$actor,$owner,$person,$make,$payload,$requestFor,$check,$fails,$state):void{
    $left=$make('Distinct source current','jd:99510101');$right=$make('Distinct source history','jd:99510102');
    $leftPayload=array_replace($payload,['startId'=>'99510101']);$rightPayload=array_replace($payload,['startId'=>'99510102']);
    $leftMap=mappingUpsert($workspace,'jobdiva','placement','99510101',$left,$leftPayload);
    $primary=mappingUpsert($workspace,'jobdiva','placement','99510102',$right,$rightPayload);
    // A second historical pair on the same physical row must also survive.
    $extra=mappingUpsert($workspace,'jobdiva','placement','jd:99510102',$right,$rightPayload);
    placementMergeApply($workspace,$actor,$requestFor(placementMergeReview($workspace,$actor,$left,$right),(int)$primary['id']));
    $ctx=jobdivaPlacementWriteContext($pdo,$workspace);$before=$state();
    $check($fails(fn()=>jobdivaPlacementWriteIdentity($pdo,$ctx,'99510101'))&&$before===$state(),
        'A distinct secondary Start cannot overwrite the survivor selected for another primary Start');
    $identity=jobdivaPlacementWriteIdentity($pdo,$ctx,'99510102');
    $check((int)$identity['placement']['id']===$left&&$identity['placement']['external_id']==='jd:99510101',
        'Reviewed source ownership can differ from the survivor display ID without rewriting it');
    $updated=jobdivaPlacementBindRetained($workspace,'99510102',$left,$rightPayload+['sourceRefreshNote'=>'Distinct retained source'],$actor);
    $check((int)$updated['internal_entity_id']===$right&&mappingFindInternal($workspace,'jobdiva','placement','jd:99510102')['content_hash']===$extra['content_hash'],
        'Snapshot refresh retains another reviewed pair on the same physical placement');
},true);
JobdivaStoredReviewTransaction::run(static function()use($pdo,$workspace,$actor,$owner,$a,$b,$person,$payload,$check,$read):void{
    mappingUpsert($workspace,'jobdiva','person','jd:99510002',$person,['candidateId'=>'99510002']);
    $beforeRetired=$read($b);
    $fresh=jobdivaAssignmentMarkVerified(array_replace($payload,['employmentType'=>'W2','status'=>'On Hold','title'=>'Ignored source title','worksiteCountry'=>'US']),'99510001','searchStart:criteria_exact');
    $projected=jobdivaProjectorProjectPlacement($workspace,$fresh,$actor,['external_id'=>'99510001','existing_placement_id'=>$b,'person_id'=>$person]);
    $check($projected['projected']&&(int)$projected['placement_id']===$a&&$read($a)['title']==='Historical assignment',
        'Actual ordinary projection updates the survivor and preserves chosen Core-owned fields: '.json_encode($projected['errors']));
    $check($read($b)===$beforeRetired&&(int)mappingFindInternal($workspace,'jobdiva','placement','jd:99510001')['internal_entity_id']===$b,
        'Ordinary projection leaves historical placement contents and provider binding unchanged');
},true);
JobdivaStoredReviewTransaction::run(static function()use($pdo,$workspace,$actor,$owner,$a,$b,$person,$mb,$payload,$check,$read,$state):void{
    mappingUpsert($workspace,'jobdiva','person','jd:99510002',$person,['candidateId'=>'99510002']);
    $exact=['status'=>'verified','row'=>jobdivaAssignmentMarkVerified($payload+['title'=>'Changed provider title'],'99510001','searchStart:criteria_exact')];
    $result=jobdivaAssignmentRepairApply(jobdivaAssignmentRepairSnapshot($workspace,(int)$mb['id']),$exact,$actor);
    $check($result['placement_id']===$a&&$read($b)['deleted_at']!==null&&$read($a)['title']==='Historical assignment',
        'Actual source repair uses the survivor without reviving its retired identity or changing Core-owned values');
    $current=mappingFindInternal($workspace,'jobdiva','placement','jd:99510001');
    $check((int)$current['internal_entity_id']===$b&&(int)$current['id']===(int)$mb['id'],'Source repair preserves the reviewed physical mapping after saving fresh evidence');
},true);

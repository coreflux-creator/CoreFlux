<?php
/** Runs inside the disposable merge fixture and its rollback transaction. */
(static function()use($pdo,$workspace,$owner,$a,$b,$c,$review,$state,$check,$fails):void{
    $before=$state();$history=placementNavigation($workspace,(string)$b,true);
    $check($history['merged']&&$history['source']['id']===$b&&$history['current']['id']===$a
        &&$history['source']['placement_number']===$review['retired_number']&&count($history['family'])===2,
        'Old ID opens original placement details with a separate current placement and complete number family');
    $check((float)$history['source_rates'][0]['bill_rate']===100.0&&$history['source_rates'][0]['approved_at']!==null,
        'Historical rate view retains the original approved amount rather than the current rate');
    $number=placementNavigation($workspace,$review['retired_number'],true);
    $check($number['source']===$history['source']&&$number['current']===$history['current'],
        'Stored permanent number and physical ID open the same historical record');
    $hidden=placementNavigation($workspace,(string)$b,false);
    $check(!$hidden['can_view_financial_history']&&$hidden['source_rates']===[],
        'History without financial access exposes no rate amounts');
    $current=placementNavigation($workspace,(string)$a,true);
    $check(!$current['merged']&&$current['source']['id']===$a&&count($current['family'])===2
        &&(float)$current['source_rates'][0]['bill_rate']===110.0,'Current placement has its own rate history and links to earlier numbers');
    $check($before===$state(),'Current and historical navigation are fully read-only');
    foreach(['PL-0','0','01','1x','PL-1x','-1','1e3','92233720368547758070']as$invalid)
        $check($fails(fn()=>placementNavigation($workspace,$invalid))&&$before===$state(),'Malformed placement number cannot redirect history: '.$invalid);
    JobdivaStoredReviewTransaction::run(static function()use($pdo,$workspace,$c,$check):void{
        $pdo->prepare('UPDATE placements SET deleted_at=NOW() WHERE id=?')->execute([$c]);
        $check(placementNavigation($workspace,(string)$c,true)===null,'Ordinary archived placement does not become a current alias');
    },true);
    JobdivaStoredReviewTransaction::run(static function()use($pdo,$workspace,$check,$a):void{
        $pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key="placements"')->execute([$workspace]);
        $check(placementNavigation($workspace,(string)$a,true)===null,'Fresh placement ownership prevents access through a formerly shared directory');
    },true);
    JobdivaStoredReviewTransaction::run(static function()use($pdo,$owner,$workspace,$a,$b,$c,$check,$fails):void{
        $pdo->prepare('UPDATE core_record_numbers SET aliased_at=NULL WHERE tenant_id=? AND entity_type="placement" AND record_id=?')->execute([$owner,$b]);
        $check($fails(fn()=>placementNavigation($workspace,(string)$b,true)),'Inconsistent history alias is refused instead of guessing a current record');
    },true);
})();

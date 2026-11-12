<?php
declare(strict_types=1);
require_once __DIR__.'/../modules/placements/lib/source_rule_review.php';
$n=0;$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";};
$refused=static function(callable $f,int $code):bool{try{$f();return false;}catch(InvalidArgumentException|RuntimeException $e){return $e->getCode()===$code;}};
$plan=['selectable'=>true,'issues'=>[],'current'=>[
    ['id'=>31,'identity'=>'company:9','has_linked_records'=>true,'retained_participant_settings'=>[]],
    ['id'=>32,'identity'=>'company:8','has_linked_records'=>false,'retained_participant_settings'=>[]],
], 'proposed'=>[['key'=>'proposal-1','identity'=>'company:9'],['key'=>'proposal-2','identity'=>'company:8']]];
$check(placementSourceRuleMatchPlan($plan,['proposal-2'=>0,'proposal-1'=>31])===['proposal-1'=>31,'proposal-2'=>0],'Explicit reuse preserves a linked rule and allows an unrelated unlinked replacement');
foreach([
    [[],422], [['proposal-1'=>31],422], [['proposal-1'=>31,'proposal-2'=>32,'extra'=>0],422],
    [['proposal-1'=>'31','proposal-2'=>32],422], [['proposal-1'=>true,'proposal-2'=>32],422],
    [['proposal-1'=>31,'proposal-2'=>-1],422], [['proposal-1'=>31,'proposal-2'=>999],409],
    [['proposal-1'=>32,'proposal-2'=>0],409], [['proposal-1'=>0,'proposal-2'=>32],409],
]as[$matches,$code])$check($refused(fn()=>placementSourceRuleMatchPlan($plan,$matches),$code),'Invalid, incomplete, foreign or linked-row removal decision is refused');
$same=$plan;$same['proposed'][1]['identity']='company:9';
$check($refused(fn()=>placementSourceRuleMatchPlan($same,['proposal-1'=>31,'proposal-2'=>31]),409),'One existing rule cannot stand for two proposals');
$owned=$plan;$owned['current'][1]['retained_participant_settings']=['cycle_overridden'];
$check($refused(fn()=>placementSourceRuleMatchPlan($owned,['proposal-1'=>31,'proposal-2'=>0]),409),'Separately reviewed settings prevent silent retirement');
$empty=['selectable'=>true,'issues'=>[],'current'=>[$plan['current'][1]],'proposed'=>[]];
$check(placementSourceRuleMatchPlan($empty,[])===[],'Reviewed empty source can retire unlinked rows');
$blocked=$plan;$blocked['selectable']=false;$blocked['issues']=['Resolve source identity.'];
$check($refused(fn()=>placementSourceRuleMatchPlan($blocked,['proposal-1'=>31,'proposal-2'=>32]),409),'Source identity issue prevents apply');
$check(placementSourceRuleHasLinks('chain',['portal_credentials_ct'=>"binary\0bytes"]),'Binary credential content is protected');
$check(!array_intersect(placementSourceRuleSourceFields('chain'),['portal_credentials_ct','vendor_portal_id','contract_storage_object_id']),'Source replacement excludes private and native-linked chain fields');
$check(!in_array('plan_id',placementSourceRuleSourceFields('commission'),true),'Source replacement retains a reused rule plan reference');
$public=placementSourceRulePublicReview(['current'=>[],'__raw_current'=>['secret'],'__source'=>['secret'],'__parties'=>['secret']]);
$check($public===['current'=>[]],'Public review omits private records and raw source payload');
echo "$n source rule review checks passed.\n";

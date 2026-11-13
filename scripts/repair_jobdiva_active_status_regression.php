<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }

// No legacy positional write: restoration always starts with an explicit preview.
$mode=$argv[1]??'';$workspace=$argv[2]??'';$actor=$argv[3]??'';
if (!in_array($mode,['preview','apply'],true) || !preg_match('/^[1-9][0-9]*$/D',$workspace)
    || !preg_match('/^[1-9][0-9]*$/D',$actor) || (string)(int)$workspace!==$workspace || (string)(int)$actor!==$actor
    || count($argv)!==($mode==='preview'?5:10)) {
    fwrite(STDERR,"Usage: preview WORKSPACE_ID ACTOR_ID PLACEMENT_IDS\n"
        ."   or: apply WORKSPACE_ID ACTOR_ID PLACEMENT_IDS REVIEW_TOKEN REQUEST_UUID EXPECTED_RESTORE_COUNT EXPECTED_ACTIVE_TOTAL REASON\n");
    exit(2);
}
try {
    require_once __DIR__.'/../core/jobdiva/active_status_review.php';
    $ids=jobdivaActiveRestoreIds(explode(',',(string)$argv[4]));
    if ($mode==='preview') $result=jobdivaActiveRestoreReview((int)$workspace,(int)$actor,$ids);
    else {
        foreach ([7,8] as $index) if (!preg_match('/^[1-9][0-9]*$/D',$argv[$index]) || (string)(int)$argv[$index]!==$argv[$index]) throw new InvalidArgumentException('Use exact positive reviewed counts.',422);
        $result=jobdivaActiveRestoreApply((int)$workspace,(int)$actor,[
            'confirm'=>'RESTORE_REVIEWED_ACTIVE_STATUS','placement_ids'=>$ids,'review_token'=>$argv[5],
            'request_id'=>$argv[6],'expected_restore_count'=>(int)$argv[7],'expected_active_total'=>(int)$argv[8],'reason'=>$argv[9],
        ]);
    }
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1); }

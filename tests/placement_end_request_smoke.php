<?php
declare(strict_types=1);
require_once __DIR__ . '/../modules/placements/lib/end.php';
$count=0;
$check=static function(bool $ok,string $message)use(&$count):void{
    if(!$ok)throw new RuntimeException($message); $count++; echo "PASS $message\n";
};
$current=['status'=>'active','start_date'=>'2026-11-01','actual_end_date'=>null];
$default=placementEndRequest([],$current,'2026-11-11');
$check($default===['status'=>'ended','actual_end_date'=>'2026-11-11'],'Omitted status and date produce a valid ended placement');
$check(placementEndRequest([],array_replace($current,$default),'2026-11-12')===$default,'A later retry preserves the original actual end date');
$check(placementEndRequest(['actual_end_date'=>'2026-11-10'],$current,'2026-11-11')['actual_end_date']==='2026-11-10','An explicit valid date is retained');
$check(placementEndRequest(['status'=>'cancelled','actual_end_date'=>'2026-10-31'],$current,'2026-11-11')['status']==='cancelled','An unstarted placement can be cancelled before its planned start');
foreach([['status'=>null],['status'=>''],['status'=>'active'],['status'=>false],['status'=>[]],
    ['actual_end_date'=>null],['actual_end_date'=>''],['actual_end_date'=>'2026-02-30'],
    ['actual_end_date'=>'2026-11-10T00:00:00Z'],['actual_end_date'=>[]],['actual_end_date'=>'0000-00-00'],
    ['actual_end_date'=>'2026-10-31'],['actual_end_date'=>"2026-11-11\0"]] as $invalid){
    try{placementEndRequest($invalid,$current,'2026-11-11');}
    catch(InvalidArgumentException $e){$check($e->getCode()===422,'Invalid or cleared terminal state/date is refused');continue;}
    throw new RuntimeException('Invalid end request was accepted');
}
echo "$count placement end request checks passed.\n";

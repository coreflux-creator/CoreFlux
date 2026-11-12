<?php
/** Explicit one-row time contract; source assertions never become Core approval. */
declare(strict_types=1);
require_once __DIR__.'/dates.php';

class JobdivaTimeConflict extends RuntimeException {}

function jobdivaTimeId(mixed $value): string {
    if((!is_string($value)&&!is_int($value))||!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D',(string)$value))throw new InvalidArgumentException('A stable JobDiva time or placement ID is required.');
    return (string)$value;
}
function jobdivaTimeDate(mixed $value): string {
    if(!is_string($value)&&!is_int($value))throw new InvalidArgumentException('A source work date is required.');
    $raw=trim((string)$value);$date=null;
    if(preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ](?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):?[0-5]\d)?)?$/D',$raw))$date=substr($raw,0,10);
    elseif(preg_match('~^(\d{1,2})/(\d{1,2})/(\d{4})$~D',$raw,$m))$date=sprintf('%04d-%02d-%02d',(int)$m[3],(int)$m[1],(int)$m[2]);
    elseif(preg_match('/^(?:[1-9]\d{9}|[1-9]\d{12})$/D',$raw))$date=jobdivaNormaliseDate($raw);
    if($date===null||!checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4)))throw new InvalidArgumentException('Source work date is not a supported calendar date.');
    return $date;
}
function jobdivaTimeHours(mixed $value): string {
    if((!is_string($value)&&!is_int($value)&&!is_float($value))||!preg_match('/^\d{1,2}(?:\.\d{1,2})?$/D',(string)$value)
        ||!is_finite((float)$value)||(float)$value<=0||(float)$value>24)throw new InvalidArgumentException('Source hours must be an explicit positive decimal, at most 24, with no more than two decimal places.');
    return number_format((float)$value,2,'.','');
}
function jobdivaTimeCategory(mixed $value): string {
    if(!is_string($value))throw new InvalidArgumentException('Source hour type requires review.');
    return match(strtolower(trim($value))){
        'regular'=>'regular_billable','overtime','ot'=>'OT_billable','holiday'=>'holiday','vacation','pto'=>'vacation','sick'=>'sick',
        default=>throw new InvalidArgumentException('Unknown or missing JobDiva hour type; review its mapping before import.'),
    };
}
function jobdivaTimeAlias(array $row,array $keys,callable $normalize,string $label): mixed {
    $found=false;$value=null;
    foreach($keys as$key){
        if(!array_key_exists($key,$row)||$row[$key]===null||$row[$key]==='')continue;
        $candidate=$normalize($row[$key]);
        if($found&&$candidate!==$value)throw new InvalidArgumentException('Conflicting source '.$label.' fields require review.');
        $found=true;$value=$candidate;
    }
    if(!$found)throw new InvalidArgumentException('Missing source '.$label.'.');
    return $value;
}
function jobdivaTimeSource(array $row): array {
    if(strlen(json_encode($row,JSON_THROW_ON_ERROR))>262144)throw new InvalidArgumentException('Source time record exceeds its intake limit.');
    foreach(['deleted','isDeleted','is_deleted','voided','cancelled','canceled']as$key){
        if(array_key_exists($key,$row)&&$row[$key]!==null&&!in_array($row[$key],[false,0,'0','false'],true))throw new InvalidArgumentException('Withdrawn or ambiguous source time requires review.');
    }
    if(is_string($row['status']??null)&&in_array(strtolower(trim($row['status'])),['deleted','void','voided','cancelled','canceled','rejected'],true))throw new InvalidArgumentException('Withdrawn or rejected source time requires review.');
    $id=jobdivaTimeAlias($row,['id','timesheetId','timesheet_id','timesheet id','timecardId','timecard_id'],'jobdivaTimeId','time ID');
    $placement=jobdivaTimeAlias($row,['placementId','placement_id','placement id','startId','start_id','start id'],'jobdivaTimeId','placement ID');
    $date=jobdivaTimeAlias($row,['date','workDate','work_date','work date','entryDate','entry_date'],'jobdivaTimeDate','work date');
    $hours=jobdivaTimeAlias($row,['hours','totalHours','total_hours','regularHours','regular_hours'],'jobdivaTimeHours','hours');
    $category=jobdivaTimeAlias($row,['category','hourType','hour_type','type'],'jobdivaTimeCategory','hour type');
    foreach(['overtimeHours','overtime_hours','doubletimeHours','doubletime_hours','ptoHours','pto_hours']as$key){
        if(array_key_exists($key,$row)&&$row[$key]!==null&&$row[$key]!==''&&!in_array($row[$key],[0,0.0,'0','0.0','0.00'],true))throw new InvalidArgumentException('A source row with multiple hour components requires an explicit component mapping.');
    }
    $description=$row['description']??null;
    if($description!==null&&(!is_string($description)||strlen($description)>500||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$description)))throw new InvalidArgumentException('Source time description is invalid or exceeds 500 bytes.');
    return ['external_id'=>$id,'placement_external_id'=>$placement,'work_date'=>$date,'hours'=>$hours,'category'=>$category,'description'=>$description];
}

/** Fields actually owned by this import, excluding Core review and financial evidence. */
function jobdivaTimeOwnedValues(array $entry): array {
    $out=[];
    foreach(['placement_id','person_id','period_id','timesheet_id','work_date','hours','category','hour_type','billable','payable','description','source','source_system','external_id']as$key){
        $value=$entry[$key]??null;
        $out[$key]=$value===null?null:($key==='hours'?number_format((float)$value,2,'.',''):(string)$value);
    }
    return $out;
}

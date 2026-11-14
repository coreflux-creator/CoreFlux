<?php
/** People owns dated employment compensation. Placement-approved rates remain separate. */
declare(strict_types=1);
require_once __DIR__.'/company_relationships.php';

const PEOPLE_COMPENSATION_FIELDS=['pay_type','pay_rate_cents','pay_frequency','currency','bonus_target_cents','effective_from','effective_to','reason','notes'];

function peopleCompensationDate(mixed $value): string
{
    if(!is_string($value)||!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$value,$m)||(int)$m[1]<1000||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))throw new InvalidArgumentException('Choose a valid compensation date.');
    return $value;
}

function peopleCompensationCents(mixed $value): int
{
    if((!is_int($value)&&!is_string($value))||!preg_match('/^(0|[1-9][0-9]*)$/D',(string)$value)||filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]])===false)throw new InvalidArgumentException('Compensation amounts must be nonnegative whole cents.');
    return (int)$value;
}

function peopleCompensationValues(array $input): array
{
    if(array_diff(array_keys($input),PEOPLE_COMPENSATION_FIELDS))throw new InvalidArgumentException('Choose supported compensation fields.');
    foreach(['pay_type','pay_rate_cents','pay_frequency','currency','effective_from']as$f)if(!array_key_exists($f,$input))throw new InvalidArgumentException('Complete the rate, pay type, frequency, currency and effective date.');
    if(!in_array($input['pay_type'],['salary','hourly'],true)||!in_array($input['pay_frequency'],['weekly','biweekly','semimonthly','monthly'],true))throw new InvalidArgumentException('Choose a valid compensation type and pay frequency.');
    if(!is_string($input['currency'])||!preg_match('/^[A-Z]{3}$/D',$input['currency']))throw new InvalidArgumentException('Choose an explicit three-letter compensation currency.');
    $values=['pay_type'=>$input['pay_type'],'pay_rate_cents'=>peopleCompensationCents($input['pay_rate_cents']),'pay_frequency'=>$input['pay_frequency'],'currency'=>$input['currency'],
        'bonus_target_cents'=>isset($input['bonus_target_cents'])?peopleCompensationCents($input['bonus_target_cents']):null,
        'effective_from'=>peopleCompensationDate($input['effective_from']),
        'effective_to'=>isset($input['effective_to'])?peopleCompensationDate($input['effective_to']):null];
    if($values['effective_to']!==null&&$values['effective_to']<$values['effective_from'])throw new InvalidArgumentException('Compensation must end on or after its start date.');
    foreach(['reason'=>80,'notes'=>65535]as$field=>$limit){
        $value=$input[$field]??null;
        if($value!==null&&(!is_string($value)||($field==='notes'?strlen($value):mb_strlen($value))>$limit||str_contains($value,"\0")))throw new InvalidArgumentException('Compensation '.$field.' is invalid or too long.');
        $values[$field]=$value;
    }
    return $values;
}

/** The same current People directory supplies both the employee and its history. */
function peopleCompensationHistory(PDO $pdo,int $workspace,int $employeeId,bool $lock=false): array
{
    if($employeeId<=0)throw new InvalidArgumentException('Choose an employment record.');
    $scope=companyRelationshipScope($pdo,$workspace,'people',$lock);$owner=$scope['owner'];$suffix=$lock?' FOR UPDATE':'';
    $q=$pdo->prepare('SELECT id FROM people_employees WHERE tenant_id=? AND id=?'.$suffix);$q->execute([$owner,$employeeId]);
    if(!$q->fetchColumn())throw new RuntimeException('Employment record is unavailable in this directory.',404);
    $q=$pdo->prepare('SELECT * FROM people_compensation WHERE tenant_id=? AND employee_id=? ORDER BY effective_from,id'.$suffix);$q->execute([$owner,$employeeId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    $revision=hash('sha256',json_encode([$workspace,$owner,$employeeId,$rows],JSON_THROW_ON_ERROR));
    return ['owner_tenant_id'=>$owner,'compensation'=>$rows,'history_revision'=>$revision];
}

/** End dates are inclusive, matching history closure at the day before a new rate. */
function peopleCompensationActive(PDO $pdo,int $workspace,int $employeeId,string $asOfDate): ?array
{
    peopleCompensationDate($asOfDate);
    try{$history=peopleCompensationHistory($pdo,$workspace,$employeeId);}
    catch(RuntimeException $e){if($e->getCode()===404)return null;throw $e;}
    $matches=array_values(array_filter($history['compensation'],static fn($row)=>$row['effective_from']<=$asOfDate&&($row['effective_to']===null||$row['effective_to']>=$asOfDate)));
    if(count($matches)>1)throw new RuntimeException('Compensation history overlaps on this date. Review it before calculating payroll.',409);
    return $matches[0]??null;
}

/** Append a reviewed version; close its predecessor, create and audit atomically. */
function peopleCompensationAppend(PDO $pdo,int $workspace,int $employeeId,int $actor,array $input,string $revision): array
{
    if($actor<=0||!preg_match('/^[a-f0-9]{64}$/D',$revision))throw new InvalidArgumentException('Refresh compensation history before saving a new rate.');
    $values=peopleCompensationValues($input);
    $digest=hash('sha256',json_encode([$workspace,$employeeId,$actor,$values],JSON_THROW_ON_ERROR));
    return companyRelationshipAtomic($pdo,static function()use($pdo,$workspace,$employeeId,$actor,$values,$digest,$revision):array{
        $history=peopleCompensationHistory($pdo,$workspace,$employeeId,true);$owner=$history['owner_tenant_id'];$rows=$history['compensation'];
        $same=array_values(array_filter($rows,static fn($row)=>$row['effective_from']===$values['effective_from']));
        if(count($same)===1){
            $q=$pdo->prepare('SELECT after_hash FROM people_change_log WHERE tenant_id=? AND employee_id=? AND entity="comp" AND entity_id=? AND action="create" AND user_id=? ORDER BY id FOR UPDATE');
            $q->execute([$owner,$employeeId,$same[0]['id'],$actor]);$receipts=$q->fetchAll(PDO::FETCH_COLUMN);
            if(count($receipts)===1&&hash_equals($digest,(string)$receipts[0]))return ['id'=>(int)$same[0]['id'],'replayed'=>true];
        }
        if($same)throw new RuntimeException('A different compensation record already starts on that date.',409);
        if(!hash_equals($history['history_revision'],$revision))throw new RuntimeException('Compensation history changed. Refresh before saving.',409);
        $previous=null;
        foreach($rows as$row){
            peopleCompensationDate($row['effective_from']);if($row['effective_to']!==null)peopleCompensationDate($row['effective_to']);
            if(($row['effective_to']!==null&&$row['effective_to']<$row['effective_from'])||($previous!==null&&($previous['effective_to']===null||$previous['effective_to']>=$row['effective_from'])))throw new RuntimeException('Existing compensation history overlaps or has invalid dates. Review it before adding a rate.',409);
            $previous=$row;
        }
        if($previous&&($values['effective_from']<=$previous['effective_from']||($previous['effective_to']!==null&&$values['effective_from']<=$previous['effective_to'])))throw new RuntimeException('New compensation must follow the latest history. Historical corrections require separate review.',409);
        if($previous&&$previous['effective_to']===null){
            $end=(new DateTimeImmutable($values['effective_from']))->modify('-1 day')->format('Y-m-d');
            $pdo->prepare('UPDATE people_compensation SET effective_to=? WHERE tenant_id=? AND employee_id=? AND id=?')->execute([$end,$owner,$employeeId,$previous['id']]);
            $after=$previous;$after['effective_to']=$end;
            $pdo->prepare('INSERT INTO people_change_log(tenant_id,user_id,employee_id,entity,entity_id,action,fields_changed,before_hash,after_hash) VALUES(?,?,?,"comp",?,"update",?,?,?)')->execute([$owner,$actor,$employeeId,$previous['id'],json_encode(['effective_to']),hash('sha256',json_encode($previous,JSON_THROW_ON_ERROR)),hash('sha256',json_encode($after,JSON_THROW_ON_ERROR))]);
        }
        $fields=implode(',',PEOPLE_COMPENSATION_FIELDS);$placeholders=implode(',',array_fill(0,count(PEOPLE_COMPENSATION_FIELDS),'?'));
        $pdo->prepare('INSERT INTO people_compensation(tenant_id,employee_id,'.$fields.',created_by) VALUES(?,?,'.$placeholders.',?)')->execute([$owner,$employeeId,...array_values($values),$actor]);
        $id=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO people_change_log(tenant_id,user_id,employee_id,entity,entity_id,action,fields_changed,before_hash,after_hash) VALUES(?,?,?,"comp",?,"create",?,?,?)')->execute([$owner,$actor,$employeeId,$id,json_encode(PEOPLE_COMPENSATION_FIELDS),$revision,$digest]);
        return ['id'=>$id,'replayed'=>false];
    });
}

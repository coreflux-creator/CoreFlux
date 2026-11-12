<?php
/** Reviewed schedule selection and creation; callers enforce placement permissions. */
declare(strict_types=1);
require_once __DIR__ . '/ownership.php';
require_once __DIR__ . '/economics.php';

function placementCyclePrimaryPurpose(array $placement): string
{
    return in_array($placement['engagement_type'], ['w2','temp_to_perm','internal'], true) ? 'payroll' : 'ap';
}

function placementCycleDiffers(array $row, array $changes): bool
{
    foreach ($changes as $field=>$value) if (($row[$field] ?? null) !== null || $value !== null) {
        if (($row[$field] ?? null) === null || $value === null || (string)$row[$field] !== (string)$value) return true;
    }
    return false;
}

function placementCycleRequireActive(PDO $pdo, int $tenant, int $id, string $purpose): array
{
    $q=$pdo->prepare('SELECT * FROM staffing_operating_cycles WHERE tenant_id=? AND id=? AND purpose=? AND active=1 FOR UPDATE');
    $q->execute([$tenant,$id,$purpose]);$cycle=$q->fetch(PDO::FETCH_ASSOC);
    if (!$cycle) throw new InvalidArgumentException('Choose an active ' . $purpose . ' schedule in this workspace.',422);
    if (!empty($cycle['payroll_pay_cycle_id'])) {
        $q=$pdo->prepare('SELECT ps.frequency,COALESCE(pc.anchor_date_override,ps.period_start_anchor) AS anchor_date,
            COALESCE(pc.pay_date_offset_days_override,ps.pay_date_offset_days) AS offset_days
            FROM payroll_pay_cycles pc JOIN payroll_pay_schedules ps ON ps.tenant_id=pc.tenant_id AND ps.id=pc.schedule_id
            WHERE pc.tenant_id=? AND pc.id=? AND pc.active=1 AND ps.active=1 FOR UPDATE');
        $q->execute([$tenant,(int)$cycle['payroll_pay_cycle_id']]);$bridge=$q->fetch(PDO::FETCH_ASSOC);
        if (!$bridge || $bridge['frequency'] !== $cycle['cadence'] || $bridge['anchor_date'] !== $cycle['anchor_date']
            || (int)$bridge['offset_days'] !== (int)$cycle['settlement_offset_days']) throw new RuntimeException('This schedule differs from its payroll connection. Review the schedule before assigning it.',409);
    }
    return $cycle;
}

/** Legacy pointers are derived from the selected schedule, not independently owned choices. */
function placementCycleSummary(array $placement, array $cycle): array
{
    $purpose=$cycle['purpose'];
    $updates=[$purpose.'_operating_cycle_id'=>(int)$cycle['id'], $purpose.'_cycle_id'=>empty($cycle['payroll_pay_cycle_id']) ? null : (int)$cycle['payroll_pay_cycle_id']];
    if ($purpose === 'billing' || $purpose === placementCyclePrimaryPurpose($placement)) {
        $prefix=$purpose === 'billing' ? 'client_bill_cycle' : 'vendor_pay_cycle';
        $updates[$prefix]=$cycle['cadence'];$updates[$prefix.'_anchor']=$cycle['anchor_date'];
    }
    return $updates;
}

function placementCycleCanonicalParty(array $placement, array $party, string $purpose): bool
{
    if (!(int)$party['active']) return false;
    if ($purpose === 'billing') return $party['source_type'] === 'placement' && $party['source_ref'] === 'placement:bill_to' && $party['role'] === 'bill_to_client' && $party['settlement_channel'] === 'ar';
    if ($purpose !== placementCyclePrimaryPurpose($placement) || $party['settlement_channel'] !== $purpose) return false;
    return $placement['engagement_type'] === 'c2c'
        ? $party['source_type'] === 'corp' && $party['source_ref'] === 'corp:'.(int)$placement['id']
        : $party['source_type'] === 'worker' && $party['source_ref'] === 'worker:'.(int)$placement['person_id'] && (int)$party['person_id'] === (int)$placement['person_id'];
}

/** Parent lock is already held. The token includes all available choices and current participants. */
function placementCyclePlan(PDO $pdo, array $placement): array
{
    $tenant=(int)$placement['tenant_id'];
    placementOwnershipFields($placement['coreflux_overridden_fields'] ?? null);
    // Creation uses this same ordering before taking any shared schedule locks.
    $q=$pdo->prepare('SELECT id FROM tenants WHERE id=? FOR UPDATE');$q->execute([$tenant]);
    if (!$q->fetchColumn()) throw new RuntimeException('Schedule workspace not found.',404);
    $q=$pdo->prepare('SELECT * FROM placement_economic_parties WHERE tenant_id=? AND placement_id=? ORDER BY id FOR UPDATE');
    $q->execute([$tenant,(int)$placement['id']]);$parties=$q->fetchAll(PDO::FETCH_ASSOC);
    $q=$pdo->prepare('SELECT * FROM staffing_operating_cycles WHERE tenant_id=? ORDER BY id FOR UPDATE');
    $q->execute([$tenant]);$cycles=$q->fetchAll(PDO::FETCH_ASSOC);
    return ['placement'=>$placement,'parties'=>$parties,'cycles'=>$cycles,'review_token'=>hash('sha256',serialize([$placement,$parties,$cycles]))];
}

function placementCycleReview(int $tenant, int $placement): array
{
    return placementWithLockedRecord($tenant,$placement,static function(array $parent, PDO $pdo): array {
        $plan=placementCyclePlan($pdo,$parent);
        return ['placement'=>array_intersect_key($parent,array_flip(['id','tenant_id','billing_operating_cycle_id','ap_operating_cycle_id','payroll_operating_cycle_id','client_bill_cycle','client_bill_cycle_anchor','vendor_pay_cycle','vendor_pay_cycle_anchor'])),
            'cycles'=>$plan['cycles'],'review_token'=>$plan['review_token']];
    });
}

/** Called inside the placement transaction, optionally using the review before a new cycle was created. */
function placementCycleAssignLocked(PDO $pdo, array $parent, array $assignments, string $token, ?array $plan=null): array
{
    $tenant=(int)$parent['tenant_id'];$placement=(int)$parent['id'];$plan ??= placementCyclePlan($pdo,$parent);
    $owned=placementOwnershipFields($parent['coreflux_overridden_fields'] ?? null);
    if (!$assignments) throw new InvalidArgumentException('Choose a schedule to assign.',422);
    $choices=[];
    foreach ($assignments as $field=>$id) {
        $purpose=array_search($field,['billing'=>'billing_operating_cycle_id','ap'=>'ap_operating_cycle_id','payroll'=>'payroll_operating_cycle_id'],true);
        if ($purpose === false || (!is_int($id)&&!is_string($id)) || !preg_match('/^[1-9][0-9]*$/D',(string)$id)) throw new InvalidArgumentException('Choose an active schedule. Returning source ownership requires a separate review.',422);
        $choices[$purpose]=placementCycleRequireActive($pdo,$tenant,(int)$id,$purpose);
    }
    $updates=[];$partyUpdates=[];$ownership=$owned;
    foreach ($choices as $purpose=>$cycle) {
        $summary=placementCycleSummary($parent,$cycle);$updates=array_replace($updates,$summary);
        $ownership=array_merge($ownership,array_diff(array_keys($summary),[$purpose.'_cycle_id']));
        foreach ($plan['parties'] as $party) if (placementCycleCanonicalParty($parent,$party,$purpose)) {
            $next=['operating_cycle_id'=>(int)$cycle['id'],'cycle_overridden'=>1];
            if (placementCycleDiffers($party,$next)) $partyUpdates[(int)$party['id']]=$next;
        }
    }
    $updates['coreflux_overridden_fields']=json_encode(array_values(array_unique($ownership)),JSON_THROW_ON_ERROR);
    if (!placementCycleDiffers($parent,$updates) && !$partyUpdates) return ['changed'=>false,'placement'=>$parent];
    if (!preg_match('/^[a-f0-9]{64}$/D',$token) || !hash_equals($plan['review_token'],$token)) throw new RuntimeException('The reviewed schedules changed. Reload the placement and review the assignment again.',409);
    $sets=array_map(static fn($field)=>$field.'=:'.$field,array_keys($updates));
    $pdo->prepare('UPDATE placements SET '.implode(',',$sets).' WHERE tenant_id=:t AND id=:p')->execute($updates+['t'=>$tenant,'p'=>$placement]);
    foreach ($partyUpdates as $id=>$values) $pdo->prepare('UPDATE placement_economic_parties SET operating_cycle_id=?,cycle_overridden=1 WHERE tenant_id=? AND placement_id=? AND id=?')->execute([$values['operating_cycle_id'],$tenant,$placement,$id]);
    $graph=placementEconomicsReconcile($tenant,$placement);
    if (empty($graph['available']) || !empty($graph['errors'])) throw new RuntimeException('The contract could not retain the selected schedule. Review its participants.',409);
    $q=$pdo->prepare('SELECT * FROM placements WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([$tenant,$placement]);$after=$q->fetch(PDO::FETCH_ASSOC);
    if (!$after || placementCycleDiffers($after,$updates)) throw new RuntimeException('The placement did not retain the selected schedule.',409);
    $q=$pdo->prepare('SELECT * FROM placement_economic_parties WHERE tenant_id=? AND placement_id=? ORDER BY id FOR UPDATE');$q->execute([$tenant,$placement]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $party) foreach ($choices as $purpose=>$cycle) if (placementCycleCanonicalParty($after,$party,$purpose)) {
        if ((int)$party['operating_cycle_id'] !== (int)$cycle['id']) throw new RuntimeException('The payee schedule differs from the placement. Review its schedule ownership.',409);
        // A missing canonical participant may have been created by reconciliation.
        if (!(int)$party['cycle_overridden']) $pdo->prepare('UPDATE placement_economic_parties SET cycle_overridden=1 WHERE tenant_id=? AND placement_id=? AND id=?')->execute([$tenant,$placement,(int)$party['id']]);
    }
    placementRequiredAudit('placement.cycles.assigned',['placement_id'=>$placement,'assignments'=>$updates,'before'=>$parent,'after'=>$after,'participant_changes'=>$partyUpdates],$placement);
    return ['changed'=>true,'placement'=>$after];
}

function placementCycleAssign(int $tenant, int $placement, array $assignments, string $token): array
{
    return placementWithLockedRecord($tenant,$placement,static fn(array $parent,PDO $pdo):array=>placementCycleAssignLocked($pdo,$parent,$assignments,$token));
}

function placementCycleCreateInput(array $body): array
{
    $allowed=['name','purpose','cadence','anchor_date','settlement_offset_days','default_payment_terms','placement_id','review_token'];
    foreach ($body as $field=>$value) if (!in_array($field,$allowed,true) || (!is_scalar($value)&&$value!==null)) throw new InvalidArgumentException('Invalid schedule field: '.$field,422);
    $purpose=(string)($body['purpose']??'');$cadence=(string)($body['cadence']??'');$name=trim((string)($body['name']??''));
    if (!in_array($purpose,['billing','ap','payroll'],true)||!in_array($cadence,['weekly','biweekly','semimonthly','monthly','adhoc'],true)||($purpose==='payroll'&&$cadence==='adhoc')) throw new InvalidArgumentException('Choose a valid schedule purpose and frequency.',422);
    if ($name===''||mb_strlen($name)>($purpose==='payroll'?111:160)) throw new InvalidArgumentException('Enter a schedule name within the allowed length.',422);
    $anchor=$body['anchor_date']??null;
    if ($anchor===''&&$cadence==='adhoc')$anchor=null;
    if ($anchor!==null||$cadence!=='adhoc') {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$anchor);
        if (!$date||$date->format('Y-m-d')!==$anchor) throw new InvalidArgumentException('Choose a valid schedule anchor date.',422);
    }
    $offset=$body['settlement_offset_days']??0;$limit=$purpose==='payroll'?127:32767;
    if (is_bool($offset)||!preg_match('/^-?[0-9]+$/D',(string)$offset)||(int)$offset<-$limit-1||(int)$offset>$limit) throw new InvalidArgumentException('Choose a valid settlement offset in days.',422);
    $terms=$purpose==='ap'?placementEconomicsNormaliseTerms((string)($body['default_payment_terms']??'NET30'),''):null;
    if ($terms===''||($purpose!=='ap'&&!empty($body['default_payment_terms']))) throw new InvalidArgumentException('Choose valid AP schedule terms.',422);
    return ['name'=>$name,'purpose'=>$purpose,'cadence'=>$cadence,'anchor_date'=>$anchor,'settlement_offset_days'=>(int)$offset,'default_payment_terms'=>$terms];
}

/** A native payroll bridge is created only with an explicit anchor; no payroll runs are generated. */
function placementCycleCreateLocked(PDO $pdo, int $tenant, array $values, array $user): array
{
    $q=$pdo->prepare('SELECT id FROM tenants WHERE id=? FOR UPDATE');$q->execute([$tenant]);
    if (!$q->fetchColumn()) throw new RuntimeException('Schedule workspace not found.',404);
    $q=$pdo->prepare('SELECT * FROM staffing_operating_cycles WHERE tenant_id=? AND purpose=? AND name=? FOR UPDATE');$q->execute([$tenant,$values['purpose'],$values['name']]);$existing=$q->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        if ($existing['source_system']!=='coreflux'||!(int)$existing['active']||placementCycleDiffers($existing,$values)) throw new RuntimeException('This schedule name is already in use with different settings.',409);
        $existing=placementCycleRequireActive($pdo,$tenant,(int)$existing['id'],$values['purpose']);
        return ['id'=>(int)$existing['id'],'changed'=>false,'cycle'=>$existing,'payroll_pay_cycle_id'=>$existing['payroll_pay_cycle_id']];
    }
    $bridge=null;
    if ($values['purpose']==='payroll') {
        foreach (['payroll_pay_schedules'=>$values['name'].' schedule','payroll_pay_cycles'=>$values['name']] as $table=>$name) {
            $q=$pdo->prepare('SELECT id FROM '.$table.' WHERE tenant_id=? AND name=? FOR UPDATE');$q->execute([$tenant,$name]);
            if ($q->fetchColumn()) throw new RuntimeException('An existing payroll schedule has this name. Review its connection instead of adopting it by name.',409);
        }
        $pdo->prepare('INSERT INTO payroll_pay_schedules(tenant_id,name,frequency,period_start_anchor,pay_date_offset_days,timezone,active,notes) VALUES(?,?,?,?,?,"America/New_York",1,"Created from staffing operating cycle")')->execute([$tenant,$values['name'].' schedule',$values['cadence'],$values['anchor_date'],$values['settlement_offset_days']]);
        $schedule=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payroll_pay_cycles(tenant_id,name,schedule_id,anchor_date_override,pay_date_offset_days_override,active,notes) VALUES(?,?,?,?,?,1,"Managed by staffing operating cycle")')->execute([$tenant,$values['name'],$schedule,$values['anchor_date'],$values['settlement_offset_days']]);$bridge=(int)$pdo->lastInsertId();
    }
    $pdo->prepare('INSERT INTO staffing_operating_cycles(tenant_id,name,purpose,cadence,anchor_date,settlement_offset_days,default_payment_terms,payroll_pay_cycle_id,source_system,active,created_by_user_id) VALUES(:t,:name,:purpose,:cadence,:anchor_date,:settlement_offset_days,:default_payment_terms,:bridge,"coreflux",1,:actor)')->execute($values+['t'=>$tenant,'bridge'=>$bridge,'actor'=>$user['id']??null]);
    $id=(int)$pdo->lastInsertId();$cycle=placementCycleRequireActive($pdo,$tenant,$id,$values['purpose']);
    placementRequiredAudit('staffing.operating_cycle.created',['cycle_id'=>$id,'after'=>$cycle],$id);
    return ['id'=>$id,'changed'=>true,'cycle'=>$cycle,'payroll_pay_cycle_id'=>$bridge];
}

function placementCycleCreate(int $tenant, array $body, array $user=[]): array
{
    $values=placementCycleCreateInput($body);$placement=$body['placement_id']??null;
    if ($placement!==null&&(!preg_match('/^[1-9][0-9]*$/D',(string)$placement)||is_bool($placement))) throw new InvalidArgumentException('Choose a valid placement.',422);
    if ($placement!==null) return placementWithLockedRecord($tenant,(int)$placement,static function(array $parent,PDO $pdo)use($tenant,$values,$user,$body):array {
        $plan=placementCyclePlan($pdo,$parent);
        $created=placementCycleCreateLocked($pdo,$tenant,$values,$user);
        $assigned=placementCycleAssignLocked($pdo,$parent,[$values['purpose'].'_operating_cycle_id'=>$created['id']],(string)($body['review_token']??''),$plan);
        return array_replace($created,['changed'=>$created['changed']||$assigned['changed'],'created'=>$created['changed'],'assignment'=>$assigned]);
    });
    $pdo=getDB();$owns=!$pdo->inTransaction();$savepoint='native_cycle_'.bin2hex(random_bytes(8));
    if ($owns)$pdo->beginTransaction();else $pdo->exec('SAVEPOINT '.$savepoint);
    try{$result=placementCycleCreateLocked($pdo,$tenant,$values,$user);if($owns)$pdo->commit();else $pdo->exec('RELEASE SAVEPOINT '.$savepoint);return $result;}
    catch(Throwable $error){if($pdo->inTransaction()){if($owns)$pdo->rollBack();else{$pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);$pdo->exec('RELEASE SAVEPOINT '.$savepoint);}}throw $error;}
}

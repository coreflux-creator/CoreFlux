<?php
/** Reviewed edits to a shared Core schedule and its current consumers. */
declare(strict_types=1);
require_once __DIR__ . '/cycle_assignments.php';

function placementCycleSharedTransaction(int $tenant, int $id, callable $callback): array
{
    if ($tenant<=0 || $id<=0) throw new RuntimeException('Schedule not found.',404);
    $pdo=getDB();$owns=!$pdo->inTransaction();$savepoint='shared_cycle_'.bin2hex(random_bytes(8));
    if ($owns) $pdo->beginTransaction(); else $pdo->exec('SAVEPOINT '.$savepoint);
    try {
        // Native assignment locks a parent before the tenant and cycles. Locking
        // this tenant's parents in ID order also prevents new consumers entering
        // the reviewed set while a shared definition is being changed.
        $q=$pdo->prepare('SELECT * FROM placements WHERE tenant_id=? ORDER BY id FOR UPDATE');
        $q->execute([$tenant]);$parents=$q->fetchAll(PDO::FETCH_ASSOC);
        $q=$pdo->prepare('SELECT id FROM tenants WHERE id=? FOR UPDATE');$q->execute([$tenant]);
        if (!$q->fetchColumn()) throw new RuntimeException('Schedule not found.',404);
        $q=$pdo->prepare('SELECT * FROM placement_economic_parties WHERE tenant_id=? ORDER BY placement_id,id FOR UPDATE');
        $q->execute([$tenant]);$parties=$q->fetchAll(PDO::FETCH_ASSOC);
        $q=$pdo->prepare('SELECT * FROM staffing_operating_cycles WHERE tenant_id=? ORDER BY id FOR UPDATE');
        $q->execute([$tenant]);$cycles=$q->fetchAll(PDO::FETCH_ASSOC);$cycle=null;
        foreach ($cycles as $candidate) if ((int)$candidate['id']===$id) $cycle=$candidate;
        if (!$cycle) throw new RuntimeException('Schedule not found.',404);
        $linkedParties=array_values(array_filter($parties,static fn($p)=>(int)$p['operating_cycle_id']===$id));
        $partyParents=array_column($linkedParties,'placement_id');
        $linkedParents=array_values(array_filter($parents,static fn($p)=>in_array($p['id'],$partyParents)
            || (int)$p['billing_operating_cycle_id']===$id || (int)$p['ap_operating_cycle_id']===$id || (int)$p['payroll_operating_cycle_id']===$id));
        if (array_diff($partyParents,array_column($linkedParents,'id'))) throw new RuntimeException('A schedule participant has no placement in this workspace. Review its link first.',409);
        $q=$pdo->prepare('SELECT * FROM placement_corp_details WHERE tenant_id=? ORDER BY placement_id FOR UPDATE');$q->execute([$tenant]);
        $corporations=array_values(array_filter($q->fetchAll(PDO::FETCH_ASSOC),static fn($c)=>in_array($c['placement_id'],array_column($linkedParents,'id'))));
        $bridge=null;
        if (!empty($cycle['payroll_pay_cycle_id'])) {
            $q=$pdo->prepare('SELECT * FROM payroll_pay_cycles WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([$tenant,(int)$cycle['payroll_pay_cycle_id']]);$pc=$q->fetch(PDO::FETCH_ASSOC);
            if (!$pc) throw new RuntimeException('Review the missing payroll connection before editing this schedule.',409);
            $q=$pdo->prepare('SELECT * FROM payroll_pay_schedules WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([$tenant,(int)$pc['schedule_id']]);$ps=$q->fetch(PDO::FETCH_ASSOC);
            if (!$ps) throw new RuntimeException('Review the missing payroll schedule before editing this schedule.',409);
            $q=$pdo->prepare('SELECT * FROM payroll_pay_cycles WHERE tenant_id=? AND schedule_id=? ORDER BY id FOR UPDATE');$q->execute([$tenant,$ps['id']]);$cohorts=$q->fetchAll(PDO::FETCH_ASSOC);
            $q=$pdo->prepare('SELECT id,cycle_id,schedule_id,enabled FROM payroll_profiles WHERE tenant_id=? AND (cycle_id=? OR schedule_id=?) ORDER BY id FOR UPDATE');$q->execute([$tenant,$pc['id'],$ps['id']]);$profiles=$q->fetchAll(PDO::FETCH_ASSOC);
            $q=$pdo->prepare('SELECT id,cycle_id,schedule_id,period_number,period_start,period_end,pay_date,status FROM payroll_pay_periods WHERE tenant_id=? AND (cycle_id=? OR schedule_id=?) ORDER BY id FOR UPDATE');$q->execute([$tenant,$pc['id'],$ps['id']]);$periods=$q->fetchAll(PDO::FETCH_ASSOC);
            $q=$pdo->prepare('SELECT id,default_pay_schedule_id FROM payroll_settings WHERE tenant_id=? AND default_pay_schedule_id=? FOR UPDATE');$q->execute([$tenant,$ps['id']]);$defaults=$q->fetchAll(PDO::FETCH_ASSOC);
            $aliases=array_values(array_filter($cycles,static fn($c)=>(int)$c['payroll_pay_cycle_id']===(int)$pc['id']&&(int)$c['id']!==$id));
            $bridge=compact('pc','ps','cohorts','profiles','periods','defaults','aliases');
        }
        $plan=['cycle'=>$cycle,'placements'=>$linkedParents,'parties'=>$linkedParties,'corporations'=>$corporations,'bridge'=>$bridge];
        $plan['review_token']=hash('sha256',serialize($plan));
        $result=$callback($plan,$pdo);
        if ($owns) $pdo->commit(); else $pdo->exec('RELEASE SAVEPOINT '.$savepoint);
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            if ($owns) $pdo->rollBack(); else {$pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);$pdo->exec('RELEASE SAVEPOINT '.$savepoint);}
        }
        throw $error;
    }
}

function placementCycleSharedPublic(array $plan): array
{
    $bridge=$plan['bridge'];
    return ['cycle'=>$plan['cycle'],'review_token'=>$plan['review_token'],
        'placements'=>array_map(static fn($p)=>array_intersect_key($p,array_flip(['id','title','status','engagement_type','billing_operating_cycle_id','ap_operating_cycle_id','payroll_operating_cycle_id','client_bill_cycle','client_bill_cycle_anchor','vendor_pay_cycle','vendor_pay_cycle_anchor'])),$plan['placements']),
        'participants'=>array_map(static fn($p)=>array_intersect_key($p,array_flip(['id','placement_id','display_name','settlement_channel','operating_cycle_id','cycle_overridden','active'])),$plan['parties']),
        'payroll'=>$bridge===null?null:['cycle_id'=>(int)$bridge['pc']['id'],'schedule_id'=>(int)$bridge['ps']['id'],
            'profile_count'=>count($bridge['profiles']),'generated_period_count'=>count($bridge['periods']),
            'other_cohort_count'=>count($bridge['cohorts'])-1,'other_operating_schedule_count'=>count($bridge['aliases']),
            'is_default'=>count($bridge['defaults'])>0],
        'source_owned'=>$plan['cycle']['source_system']!=='coreflux',
        'effect'=>'Updates this shared definition and linked current placement summaries together. Linked schedule selections become Core-owned. Approved contract snapshots and generated payroll periods stay unchanged.'];
}

function placementCycleSharedReview(int $tenant, int $id): array
{
    return placementCycleSharedTransaction($tenant,$id,static fn(array $plan,PDO $pdo):array=>placementCycleSharedPublic($plan));
}

function placementCycleSharedEdit(int $tenant, int $id, array $body, bool $canManagePayroll=false): array
{
    $allowed=['name','cadence','anchor_date','settlement_offset_days','default_payment_terms','active','review_token','confirm'];
    foreach ($body as $field=>$value) if (!in_array($field,$allowed,true)||(!is_scalar($value)&&$value!==null)) throw new InvalidArgumentException('Invalid shared schedule field: '.$field,422);
    return placementCycleSharedTransaction($tenant,$id,static function(array $plan,PDO $pdo)use($tenant,$id,$body,$canManagePayroll):array{
        $before=$plan['cycle'];$bridge=$plan['bridge'];
        $changes=array_diff_key($body,array_flip(['review_token','confirm']));
        if (!$changes) throw new InvalidArgumentException('Choose a schedule field to change.',422);
        $definition=array_intersect_key($before,array_flip(['name','purpose','cadence','anchor_date','settlement_offset_days','default_payment_terms']));
        $requested=array_replace($definition,array_diff_key($changes,['active'=>true]));
        $values=placementCycleCreateInput($requested);
        if ($before['purpose']==='ap'&&$requested['default_payment_terms']===null) $values['default_payment_terms']=null;
        $active=array_key_exists('active',$changes)?$changes['active']:$before['active'];
        if (!in_array($active,[0,1,'0','1',false,true],true)) throw new InvalidArgumentException('Choose a valid schedule status.',422);
        $values['active']=(int)$active;
        if (!placementCycleDiffers($before,$values)) return ['changed'=>false]+placementCycleSharedPublic($plan);
        if ($before['source_system']!=='coreflux') throw new RuntimeException('This schedule is managed by its source or a standard frequency. Create and assign a separate Core schedule to customize it.',409);
        if (($body['confirm']??'')!=='UPDATE_SHARED_SCHEDULE') throw new InvalidArgumentException('Confirm the change to this shared schedule and its linked placements.',422);
        $token=(string)($body['review_token']??'');
        if (!preg_match('/^[a-f0-9]{64}$/D',$token)||!hash_equals($plan['review_token'],$token)) throw new RuntimeException('The shared schedule or its linked records changed. Review the impact again.',409);
        if (!$values['active']&&($plan['placements']||$plan['parties']||($bridge&&($bridge['profiles']||$bridge['defaults'])))) throw new RuntimeException('This schedule still has linked records. Assign their replacement schedule before deactivating it.',409);
        if ($bridge) {
            if (!$canManagePayroll) throw new RuntimeException('Payroll schedule management permission is required to change this payroll connection.',403);
            if (mb_strlen($values['name'])>111||$values['settlement_offset_days'] < -128||$values['settlement_offset_days']>127) throw new InvalidArgumentException('The linked payroll schedule requires a name of at most 111 characters and an offset between -128 and 127 days.',422);
            $pc=$bridge['pc'];$ps=$bridge['ps'];
            if (count($bridge['cohorts'])!==1||$bridge['aliases']) throw new RuntimeException('The payroll connection is shared with other schedules. Create and assign a separate schedule before changing its definition.',409);
            $effective=['cadence'=>$ps['frequency'],'anchor_date'=>$pc['anchor_date_override']??$ps['period_start_anchor'],'settlement_offset_days'=>$pc['pay_date_offset_days_override']??$ps['pay_date_offset_days'],'active'=>$pc['active']];
            if (placementCycleDiffers($before,$effective)||(int)$ps['active']!==(int)$before['active']) throw new RuntimeException('The staffing and payroll schedules disagree. Review their connection before editing.',409);
            $calendar=array_intersect_key($values,array_flip(['cadence','anchor_date','settlement_offset_days']));
            if (placementCycleDiffers($before,$calendar)&&($bridge['periods']||(int)$pc['next_period_number']!==1||$pc['last_advanced_at']!==null||$pc['last_run_id']!==null)) throw new RuntimeException('This payroll calendar already has generated periods. Create and assign a replacement schedule for the new calendar; existing periods must retain their dates.',409);
            foreach (['payroll_pay_cycles'=>[$values['name'],$pc['id']],'payroll_pay_schedules'=>[$values['name'].' schedule',$ps['id']]] as $table=>[$name,$ownId]) {
                $q=$pdo->prepare('SELECT id FROM '.$table.' WHERE tenant_id=? AND name=? AND id<>? FOR UPDATE');$q->execute([$tenant,$name,$ownId]);
                if ($q->fetchColumn()) throw new RuntimeException('Another payroll schedule already uses that name.',409);
            }
        }
        $q=$pdo->prepare('SELECT id FROM staffing_operating_cycles WHERE tenant_id=? AND purpose=? AND name=? AND id<>? FOR UPDATE');$q->execute([$tenant,$before['purpose'],$values['name'],$id]);
        if ($q->fetchColumn()) throw new RuntimeException('Another schedule already uses that name.',409);
        foreach ($plan['placements'] as $parent) {
            placementOwnershipFields($parent['coreflux_overridden_fields']??null);
            if ($parent['deleted_at']!==null) throw new RuntimeException('A deleted placement still references this schedule. Review its retained schedule history first.',409);
            foreach (['billing','ap','payroll'] as $purpose) if ((int)$parent[$purpose.'_operating_cycle_id']===$id&&$before['purpose']!==$purpose) throw new RuntimeException('A placement uses this schedule for the wrong purpose. Review its assignment first.',409);
            foreach ($plan['parties'] as $party) if ((int)$party['placement_id']===(int)$parent['id']&&(int)$party['active']) {
                $purpose=$party['settlement_channel']==='ar'?'billing':$party['settlement_channel'];
                if ($purpose!==$before['purpose']) throw new RuntimeException('A participant uses this schedule for another payment channel. Review its assignment first.',409);
                if (placementCycleCanonicalParty($parent,$party,$before['purpose'])&&(int)$parent[$before['purpose'].'_operating_cycle_id']!==$id) throw new RuntimeException('A canonical payee and placement select different schedules. Review the assignment first.',409);
            }
        }
        if ($bridge) {
            $pdo->prepare('UPDATE payroll_pay_schedules SET name=?,frequency=?,period_start_anchor=?,pay_date_offset_days=?,active=? WHERE tenant_id=? AND id=?')->execute([$values['name'].' schedule',$values['cadence'],$values['anchor_date'],$values['settlement_offset_days'],$values['active'],$tenant,$bridge['ps']['id']]);
            $pdo->prepare('UPDATE payroll_pay_cycles SET name=?,anchor_date_override=?,pay_date_offset_days_override=?,active=? WHERE tenant_id=? AND id=?')->execute([$values['name'],$values['anchor_date'],$values['settlement_offset_days'],$values['active'],$tenant,$bridge['pc']['id']]);
        }
        $sets=array_map(static fn($field)=>$field.'=:'.$field,array_keys($values));
        $pdo->prepare('UPDATE staffing_operating_cycles SET '.implode(',',$sets).' WHERE tenant_id=:tenant AND id=:id')->execute($values+['tenant'=>$tenant,'id'=>$id]);
        $cycle=array_replace($before,$values);
        $parentChanges=[];
        foreach ($plan['placements'] as $parent) {
            $pid=(int)$parent['id'];$updates=[];
            if ((int)$parent[$before['purpose'].'_operating_cycle_id']===$id) {
                $updates=placementCycleSummary($parent,$cycle);
                $owned=placementOwnershipFields($parent['coreflux_overridden_fields']??null);
                $updates['coreflux_overridden_fields']=json_encode(array_values(array_unique(array_merge($owned,array_diff(array_keys($updates),[$before['purpose'].'_cycle_id'])))),JSON_THROW_ON_ERROR);
                $sets=array_map(static fn($f)=>$f.'=:'.$f,array_keys($updates));
                $pdo->prepare('UPDATE placements SET '.implode(',',$sets).' WHERE tenant_id=:t AND id=:p')->execute($updates+['t'=>$tenant,'p'=>$pid]);
            }
            $pdo->prepare('UPDATE placement_economic_parties SET cycle_overridden=1 WHERE tenant_id=? AND placement_id=? AND operating_cycle_id=? AND active=1')->execute([$tenant,$pid,$id]);
            $result=placementEconomicsReconcile($tenant,$pid);
            if (empty($result['available'])||!empty($result['errors'])) throw new RuntimeException('A linked placement could not retain the shared schedule change. Review its contract.',409);
            $q=$pdo->prepare('SELECT * FROM placements WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([$tenant,$pid]);$after=$q->fetch(PDO::FETCH_ASSOC);
            if (!$after||placementCycleDiffers($after,$updates)) throw new RuntimeException('A placement did not retain the shared schedule.',409);
            $pdo->prepare('UPDATE placement_economic_parties SET cycle_overridden=1 WHERE tenant_id=? AND placement_id=? AND operating_cycle_id=? AND active=1')->execute([$tenant,$pid,$id]);
            $parentChanges[]=['placement_id'=>$pid,'before'=>$parent,'after'=>$after];
            placementRequiredAudit('placement.cycles.shared_updated',['cycle_id'=>$id,'before'=>$parent,'after'=>$after],$pid);
        }
        $q=$pdo->prepare('SELECT * FROM placement_economic_parties WHERE tenant_id=? AND operating_cycle_id=? ORDER BY placement_id,id FOR UPDATE');$q->execute([$tenant,$id]);
        placementRequiredAudit('staffing.operating_cycle.updated',['cycle_id'=>$id,'before'=>$before,'after'=>$cycle,'placements'=>$parentChanges,
            'participants_before'=>$plan['parties'],'participants_after'=>$q->fetchAll(PDO::FETCH_ASSOC),
            'payroll_before'=>$bridge?['cycle'=>$bridge['pc'],'schedule'=>$bridge['ps']]:null,
            'payroll_changes'=>$bridge?array_intersect_key($values,array_flip(['name','cadence','anchor_date','settlement_offset_days','active'])):null],$id);
        return ['changed'=>true,'cycle'=>$cycle,'affected_placement_ids'=>array_map(static fn($p)=>(int)$p['id'],$plan['placements'])];
    });
}

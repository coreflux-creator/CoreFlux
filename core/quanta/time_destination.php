<?php
/** The same destination facts govern source preview and the locked import. */
declare(strict_types=1);

function quantaTimeDestination(int $tenant,array $entry,array $route,array $imports,array $access,bool $lock=false): array
{
    $pdo=getDB();
    if ($tenant<=0 || staffingTimeWorkspace()!==$tenant || ($lock && !$pdo->inTransaction())) throw new LogicException('An authorized time destination is required');
    [$start,$end]=timeWeekBounds($entry['work_date']);$suffix=$lock?' FOR UPDATE':'';
    $prior=$imports[$entry['id']]??[];$header=null;
    if ($prior) {
        $ids=array_values(array_unique(array_map(static fn($r)=>(int)$r['current_timesheet_id'],$prior)));
        if (count($ids)!==1 || $ids[0]<=0) throw new RuntimeException('The existing source spans different review records. Use source correction review.',409);
        $header=staffingTimesheetRow($tenant,$ids[0],$lock);
        if (!$header || (int)$header['person_id']!==(int)$route['person_id'] || $entry['work_date']<$header['period_start'] || $entry['work_date']>$header['period_end']) throw new RuntimeException('The existing source has no matching review week. Use source correction review.',409);
        // Updating unapproved imported values retains their original review calendar.
        $start=$header['period_start'];$end=$header['period_end'];
    } else {
        $q=$pdo->prepare('SELECT * FROM staffing_timesheets WHERE tenant_id=? AND person_id=? AND period_start=?'.staffingTimesheetPrimaryClause().$suffix);
        $q->execute([$tenant,$route['person_id'],$start]);$headers=$q->fetchAll(PDO::FETCH_ASSOC);
        if (count($headers)>1) throw new RuntimeException('The destination week has ambiguous primary records. Review its history.',409);
        $header=$headers[0]??null;
    }
    if ($header && ($header['period_end']!==$end || !empty($header['locked_at']) || $header['status']==='locked')) throw new RuntimeException('The Core review week is locked or has different dates. Review its period history.',409);
    $q=$pdo->prepare('SELECT * FROM time_periods WHERE tenant_id=? AND start_date<=? AND end_date>=? ORDER BY start_date DESC,id DESC'.$suffix);
    $q->execute([$tenant,$entry['work_date'],$entry['work_date']]);$periods=$q->fetchAll(PDO::FETCH_ASSOC);
    if (count($periods)>1) throw new RuntimeException('The work date belongs to overlapping Core time periods. Align those periods before importing.',409);
    $period=$periods[0]??null;
    if ($period && $period['status']!=='open') throw new RuntimeException('The Core time period is locked or closed. Review its correction workflow.',409);
    $mode='primary';
    if ($header && !in_array($header['status'],['draft','submitted','rejected'],true)) {
        if ($prior) throw new RuntimeException('The source already belongs to an approved review. Use its source correction workflow.',409);
        if (!in_array($header['status'],['approved','payroll_ready','billing_ready'],true) || !empty($header['supplement_of_id'])) throw new RuntimeException('The original week cannot accept this supplemental source.',409);
        if (!staffingTimesheetSupplementSchema()) throw new RuntimeException('This week is already approved. Supplemental time requires a system update; its original approval must remain unchanged.',503);
        $mode='supplement';
    }
    $header=$header?staffingTimesheetDecorate($header):null;
    $state=['tenant_id'=>$tenant,'entry'=>$entry,'route'=>array_intersect_key($route,array_flip(['placement_id','person_id','placement_number','person_number','effective_from','effective_to'])),
        'source_binding'=>$access['binding_hash'],'period_start'=>$start,'period_end'=>$end,'header'=>$header,'time_period'=>$period,'mode'=>$mode];
    $reviewHash=integrationDeliveryHash($state);
    return ['mode'=>$mode,'header'=>$header,'period'=>$period,'period_start'=>$start,'period_end'=>$end,'review_hash'=>$reviewHash,
        'view'=>['period_start'=>$start,'period_end'=>$end,'timesheet_id'=>$header?(int)$header['id']:null,
            'timesheet_status'=>$header['status']??null,'supplement_of_id'=>$mode==='supplement'?(int)$header['id']:($header['supplement_of_id']??null),
            'time_period_id'=>$period?(int)$period['id']:null,'mode'=>$mode]];
}

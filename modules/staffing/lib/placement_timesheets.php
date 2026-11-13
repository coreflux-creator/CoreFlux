<?php
/** Placement time views retain the original workspace, header and placement. */
declare(strict_types=1);
require_once __DIR__.'/../../placements/lib/navigation.php';

function staffingPlacementTimesheets(int $workspace, string $requested, ?int $timesheetId, array $filters, callable $canReadPerson, callable $enrich): array
{
    if ($timesheetId!==null && $timesheetId<=0) throw new InvalidArgumentException('Choose an existing timesheet.',422);
    foreach (['period_start','period_end'] as $field) {
        $value=$filters[$field]??'';
        if ($value==='') continue;
        $date=is_string($value)?DateTimeImmutable::createFromFormat('!Y-m-d',$value):false;
        if (!$date || $date->format('Y-m-d')!==$value) throw new InvalidArgumentException('Use a valid timesheet period.',422);
    }
    return JobdivaStoredReviewTransaction::run(static function()use($workspace,$requested,$timesheetId,$filters,$canReadPerson,$enrich):array {
        $identity=placementNavigation($workspace,$requested);
        if (!$identity) throw new RuntimeException('Placement is unavailable in this directory.',404);
        $placement=$identity['source'];$pdo=getDB();
        if (!$canReadPerson($placement['person_id'])) throw new RuntimeException('Timesheet access is not permitted.',403);
        $people=companyRelationshipScope($pdo,$workspace,'people');
        $owner=companyRelationshipScope($pdo,$workspace,'placements')['owner'];
        $context=['workspace_id'=>$workspace,'placement_id'=>$placement['id'],'placement_number'=>$placement['placement_number'],
            'current_placement_id'=>$identity['current']['id'],'read_only'=>$identity['merged']];
        $fields='t.id,t.artifact_id,t.person_id,t.period_start,t.period_end,t.status,t.origin_source,t.origin_system,
            t.created_by_user_id,t.total_hours,t.submitted_at,t.approved_at,t.rejection_reason,p.first_name,p.last_name,p.email_primary';
        if ($timesheetId===null) {
            $where='t.tenant_id=:workspace AND t.person_id=:person';
            $params=['entry_workspace'=>$workspace,'placement'=>$placement['id'],'workspace'=>$workspace,'person'=>$placement['person_id'],'people_owner'=>$people['owner']];
            foreach (['status'=>['t.status','='],'period_start'=>['t.period_start','>='],'period_end'=>['t.period_end','<=']] as $field=>[$column,$op]) {
                if (($filters[$field]??'')==='') continue;
                if (!is_string($filters[$field])) throw new InvalidArgumentException('Use a valid timesheet filter.',422);
                $where.=' AND '.$column.$op.':'.$field;$params[$field]=$filters[$field];
            }
            $q=$pdo->prepare('SELECT '.$fields.',h.placement_hours,h.billable_hours,h.unbilled_billable_hours
                FROM staffing_timesheets t
                JOIN (SELECT timesheet_id,person_id,SUM(hours) placement_hours,SUM(CASE WHEN billable=1 THEN hours ELSE 0 END) billable_hours,
                    SUM(CASE WHEN billable=1 AND status IN ("approved","locked","billing_ready","payroll_ready") AND bill_extracted_at IS NULL THEN hours ELSE 0 END) unbilled_billable_hours
                    FROM time_entries WHERE tenant_id=:entry_workspace AND placement_id=:placement AND status!="superseded" GROUP BY timesheet_id,person_id) h ON h.timesheet_id=t.id AND h.person_id=t.person_id
                LEFT JOIN people p ON p.id=t.person_id AND p.tenant_id=:people_owner AND p.deleted_at IS NULL
                WHERE '.$where.' ORDER BY t.period_start DESC,t.id DESC LIMIT 500');
            $q->execute($params);
            return $context+['rows'=>$enrich($q->fetchAll(PDO::FETCH_ASSOC),$workspace)];
        }
        $q=$pdo->prepare('SELECT '.$fields.' FROM staffing_timesheets t
            LEFT JOIN people p ON p.id=t.person_id AND p.tenant_id=:people_owner AND p.deleted_at IS NULL
            WHERE t.tenant_id=:workspace AND t.id=:id AND t.person_id=:person
                AND EXISTS (SELECT 1 FROM time_entries te WHERE te.tenant_id=t.tenant_id AND te.timesheet_id=t.id AND te.person_id=t.person_id AND te.placement_id=:placement AND te.status!="superseded") FOR UPDATE');
        $q->execute(['people_owner'=>$people['owner'],'workspace'=>$workspace,'id'=>$timesheetId,'person'=>$placement['person_id'],'placement'=>$placement['id']]);
        $header=$q->fetch(PDO::FETCH_ASSOC);
        if (!$header) throw new RuntimeException('Timesheet not found on this placement.',404);
        $q=$pdo->prepare('SELECT te.id,te.placement_id,te.work_date,te.hour_type,te.category,te.hours,te.billable,te.payable,te.description,te.status,
            qi.quanta_entry_id,qi.source_worksite_id,qi.source_worksite_name,qi.source_dimension_values_json,qi.source_approval_json,
            pl.title AS placement_title,COALESCE(pl.end_client_name,"") AS client_name
            FROM time_entries te LEFT JOIN quanta_time_imports qi ON qi.time_entry_id=te.id AND qi.tenant_id=te.tenant_id
            JOIN placements pl ON pl.id=te.placement_id AND pl.tenant_id=:owner
            WHERE te.tenant_id=:workspace AND te.timesheet_id=:id AND te.placement_id=:placement AND te.person_id=:person AND te.status!="superseded"
            ORDER BY te.work_date,te.id FOR UPDATE');
        $q->execute(['owner'=>$owner,'workspace'=>$workspace,'id'=>$timesheetId,'placement'=>$placement['id'],'person'=>$placement['person_id']]);
        $entries=$q->fetchAll(PDO::FETCH_ASSOC);$headers=$enrich([$header],$workspace);
        return $context+['timesheet'=>$headers[0],'entries'=>$entries,'placement_hours'=>array_sum(array_map(static fn($entry)=>(float)$entry['hours'],$entries))];
    },true);
}

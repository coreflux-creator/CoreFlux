<?php
/** Late time is a new review unit; primary-week identities and history survive. */
declare(strict_types=1);

function staffingTimesheetSupplementSchema(): bool
{
    $pdo=getDB();
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite') {
        $columns=array_column($pdo->query('PRAGMA table_info(staffing_timesheets)')->fetchAll(PDO::FETCH_ASSOC),'name');
    } else {
        $columns=$pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='staffing_timesheets' AND COLUMN_NAME IN ('supplement_sequence','supplement_of_id','supplement_review_hash')")->fetchAll(PDO::FETCH_COLUMN);
    }
    return count(array_intersect($columns,['supplement_sequence','supplement_of_id','supplement_review_hash']))===3;
}

/** Compatibility for primary-only installations; no supplements can be created there. */
function staffingTimesheetPrimaryClause(string $alias=''): string
{
    if ($alias!=='' && !preg_match('/^[a-z][a-z0-9_]*$/Di',$alias)) throw new InvalidArgumentException('Invalid timesheet alias');
    return staffingTimesheetSupplementSchema()?' AND '.($alias!==''?$alias.'.':'').'supplement_sequence=0':'';
}

function staffingTimesheetSupplementFields(string $alias='t'): string
{
    if (!preg_match('/^[a-z][a-z0-9_]*$/Di',$alias)) throw new InvalidArgumentException('Invalid timesheet alias');
    return staffingTimesheetSupplementSchema()?$alias.'.supplement_of_id, '.$alias.'.supplement_sequence':'NULL AS supplement_of_id, 0 AS supplement_sequence';
}

/** The caller has locked and reviewed the primary header in this transaction. */
function staffingTimesheetCreateSupplement(int $tenant,int $actor,array $parent,string $reviewHash,array $sourceReviews): array
{
    $pdo=getDB();
    if (!$pdo->inTransaction() || $tenant<=0 || $actor<=0 || !preg_match('/^[a-f0-9]{64}$/D',$reviewHash) || !$sourceReviews) throw new LogicException('A reviewed supplemental intake transaction is required');
    if (!staffingTimesheetSupplementSchema()) throw new RuntimeException('Supplemental time requires a system update. Contact your administrator.',503);
    $current=staffingTimesheetRow($tenant,(int)($parent['id']??0),true);
    if (!$current || (int)$current['tenant_id']!==$tenant || (int)$current['supplement_sequence']!==0 || $current['supplement_of_id']!==null
        || !in_array($current['status'],['approved','payroll_ready','billing_ready'],true) || !empty($current['locked_at'])
        || staffingDimensionSnapshotCanonicalJson($current)!==staffingDimensionSnapshotCanonicalJson(staffingTimesheetDecorate($parent))) {
        throw new RuntimeException('The original week changed. Review the supplemental time again.',409);
    }
    $q=$pdo->prepare('SELECT supplement_sequence FROM staffing_timesheets WHERE tenant_id=? AND person_id=? AND period_start=? ORDER BY supplement_sequence DESC LIMIT 1 FOR UPDATE');
    $q->execute([$tenant,$current['person_id'],$current['period_start']]);$sequence=(int)$q->fetchColumn()+1;
    $pdo->prepare('INSERT INTO staffing_timesheets (tenant_id,artifact_id,person_id,period_start,period_end,status,total_hours,origin_source,origin_system,created_by_user_id,supplement_sequence,supplement_of_id,supplement_review_hash) VALUES (?,?,?,?,?,"draft",0,"quanta_supplement","quanta",?,?,?,?)')->execute([
        $tenant,artifactGenerateUuid(),$current['person_id'],$current['period_start'],$current['period_end'],$actor,$sequence,$current['id'],$reviewHash,
    ]);
    $id=(int)$pdo->lastInsertId();
    $header=staffingTimesheetEnsureArtifact(staffingTimesheetRow($tenant,$id,true),['tenant_id'=>$tenant,'created_by_user_id'=>$actor]);
    staffingTimesheetRecordArtifactEvent($id,'timesheet.supplement_created',$actor,[
        'supplement_of_id'=>(int)$current['id'],'supplement_sequence'=>$sequence,'review_hash'=>$reviewHash,
        'primary_header_hash'=>hash('sha256',staffingDimensionSnapshotCanonicalJson($current)),'source_reviews'=>$sourceReviews,
    ],$tenant);
    return staffingTimesheetDecorate($header);
}

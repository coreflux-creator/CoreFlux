<?php
/** Reviewed, original-workspace CSV intake with immutable source receipts. */
declare(strict_types=1);
require_once __DIR__.'/csv_schema.php';
require_once __DIR__.'/csv_evidence.php';

final class TimeCsvImportRefused extends RuntimeException
{
    public function __construct(public array $result) {parent::__construct('No rows imported because business validation failed.',422);}
}

function timeCsvImportOptions(array $options): array
{
    return ['external_approval'=>!empty($options['external_approval']),'skip_invalid'=>!empty($options['skip_invalid']),'update_existing'=>!empty($options['update_existing'])];
}
function timeCsvImportRequest(string $csv,?array $map,array $options): string
{
    return hash('sha256',staffingDimensionSnapshotCanonicalJson(['source'=>hash('sha256',$csv),'map'=>$map,'options'=>timeCsvImportOptions($options)]));
}

function timeCsvImportState(int $actor,string $csv,?array $map,array $options): array
{
    if (!StaffingNativeTimeEdit::active()) throw new LogicException('CSV review requires the original workspace transaction.');
    if ($actor<=0 || strlen($csv)>10*1024*1024) throw new RuntimeException('Choose an identified importer and a CSV of 10 MB or less.',422);
    timeCsvRequireSchema();$options=timeCsvImportOptions($options);$dry=timeCsvDryRun($csv,$map);
    if (($dry['row_count']??0)<1 || $dry['row_count']>5000) throw new RuntimeException('Use files containing between 1 and 5000 time rows.',422);
    $placements=timeCsvPlacementLookup($dry['rows']);$people=[];$headers=[];$entries=[];$periods=[];$identities=[];$evidence=[];
    foreach($dry['rows']as$number=>&$row) {
        try {
            $row['source_approval']=timeCsvApprovalAssertion($row,$options['external_approval']);
            $row['hours_basis']=(float)($row['hours']??0)>24?'weekly_total':'daily';
            $row['core_review_status']='Pending independent Core review';
            $id=(int)($row['placement_id']??0);$p=$placements['by_id'][$id]??null;
            if (!$p) continue;
            $q=getDB()->prepare('SELECT * FROM placements WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([StaffingNativeTimeEdit::owner('placements'),$id]);$identities[$id]=$q->fetch(PDO::FETCH_ASSOC);
            $q=getDB()->prepare('SELECT * FROM people WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([StaffingNativeTimeEdit::owner('people'),$p['person_id']]);$people[(int)$p['person_id']]=$q->fetch(PDO::FETCH_ASSOC);
            timeNativePlacement($id,timeNativeDate($row['work_date']),(int)$p['person_id']);
            // Preserve the source's assertion even when its reviewer uploaded it.
            // Independence is enforced on the subsequent actual Core decision.
        } catch(RuntimeException|InvalidArgumentException $e) {if ($e instanceof PDOException || (int)$e->getCode()===503) throw $e;timeCsvAddError($dry,(int)$number,$e->getMessage());}
    }
    unset($row);
    foreach(array_keys($people)as$person) {
        $entries[$person]=staffingTimeQuery('SELECT * FROM time_entries WHERE tenant_id=:tenant_id AND person_id=:person ORDER BY id',['person'=>$person]);
        $headers[$person]=staffingTimeQuery('SELECT * FROM staffing_timesheets WHERE tenant_id=:tenant_id AND person_id=:person ORDER BY id',['person'=>$person]);
    }
    foreach($entries as$workerEntries)foreach($workerEntries as$entry)$evidence[(int)$entry['id']]=timeCsvEvidenceForEntry($entry);
    $periods=staffingTimeQuery('SELECT * FROM time_periods WHERE tenant_id=:tenant_id ORDER BY id');
    $dry['error_count']=count($dry['errors']);
    $revision=hash('sha256',staffingDimensionSnapshotCanonicalJson(['workspace'=>staffingTimeWorkspace(),'actor'=>$actor,'request'=>timeCsvImportRequest($csv,$map,$options),
        'people_owner'=>StaffingNativeTimeEdit::owner('people'),'placement_owner'=>StaffingNativeTimeEdit::owner('placements'),
        'people'=>$people,'placements'=>$identities,'entries'=>$entries,'headers'=>$headers,'periods'=>$periods,'evidence'=>$evidence,'preview'=>$dry]));
    return ['dry'=>$dry,'placements'=>$placements,'revision'=>$revision];
}

function timeCsvImportPreview(int $workspace,int $actor,string $csv,?array $map,array $options=[]): array
{
    return StaffingNativeTimeEdit::runForWorkspace($workspace,static function()use($actor,$csv,$map,$options):array {
        $state=timeCsvImportState($actor,$csv,$map,$options);
        return $state['dry']+['review_revision'=>$state['revision'],'workspace_id'=>staffingTimeWorkspace(),'external_approval_is_source_evidence'=>true];
    });
}

function timeCsvImportWriteRow(int $actor,array $row,array $options): array
{
    $date=timeNativeDate($row['work_date']);$hours=(float)$row['hours'];
    if (!is_finite($hours) || $hours<=0 || $hours>168 || round($hours,2)!==$hours) throw new RuntimeException('Use hours between 0.01 and 168 with no more than two decimal places.',422);
    $placement=timeNativePlacement((int)$row['placement_id'],$date);$person=(int)$placement['person_id'];
    $category=timeCsvHourTypeToCategory((string)$row['hour_type']);$source=(string)$row['source_system'];$external=trim((string)($row['external_id']??''))?:null;
    $existing=null;$entryId=(int)($row['entry_id']??0);
    if ($entryId>0) {
        $existing=timeEntryGet($entryId);
        if (!$existing) throw new RuntimeException('The original entry is unavailable in this workspace.',404);
    } elseif ($external!==null) {
        $matches=staffingTimeQuery('SELECT * FROM time_entries WHERE tenant_id=:tenant_id AND source_system=:source AND external_id=:external ORDER BY id',['source'=>$source,'external'=>$external]);
        if (count($matches)>1) throw new RuntimeException('The source row identity is ambiguous. Review the existing entries.',409);
        $existing=$matches[0]??null;
    }
    if (!$existing) {
        $matches=staffingTimeQuery('SELECT * FROM time_entries WHERE tenant_id=:tenant_id AND placement_id=:placement AND person_id=:person AND work_date=:day AND category=:category AND status!="superseded" ORDER BY id',[
            'placement'=>$placement['id'],'person'=>$person,'day'=>$date,'category'=>$category]);
        if (count($matches)>1) throw new RuntimeException('Several entries match this date and type. Use an explicit Entry ID.',409);
        $existing=$matches[0]??null;
    }
    if ($existing) {
        if (!$options['update_existing']) throw new RuntimeException('This row matches existing time. Enable Update existing rows after reviewing it.',409);
        if ((int)$existing['placement_id']!==(int)$placement['id'] || (int)$existing['person_id']!==$person) throw new RuntimeException('The original entry belongs to a different person or placement.',409);
        if ($entryId===0 && $external!==null && $existing['work_date']!==$date) throw new RuntimeException('This source row already belongs to another date.',409);
        staffingTimeAssertEditableEntry($existing);timeNativeAssertNoFinancialHistory($existing);
        if (!in_array($existing['source'],['bulk_upload','manual_entry','client_portal_paste'],true)) throw new RuntimeException('Use the provider correction workflow for this imported source.',409);
        if (!empty($existing['external_id'])) {
            if (($external!==null && $external!==$existing['external_id']) || (!empty($row['_source_system_supplied']) && $source!==$existing['source_system'])) throw new RuntimeException('This entry already has a source identity. Retain it when importing corrections.',409);
            $external=$existing['external_id'];$source=$existing['source_system'];
        }
        if (!empty($existing['timesheet_id'])) {
            $prior=staffingTimesheetRow(staffingTimeWorkspace(),(int)$existing['timesheet_id'],true);
            if (!$prior || (int)$prior['person_id']!==$person) throw new RuntimeException('The original weekly identity changed.',409);
            staffingTimeAssertEditableHeader($prior);
        }
    }
    $bounds=timeCsvWeekBounds($date);
    $week=staffingTimeFind('SELECT COALESCE(SUM(hours),0) AS hours FROM time_entries WHERE tenant_id=:tenant_id AND person_id=:person AND work_date BETWEEN :start AND :end AND status!="superseded" AND id!=:exclude',[
        'person'=>$person,'start'=>$bounds['start'],'end'=>$bounds['end'],'exclude'=>$existing['id']??0]);
    if ((float)$week['hours']+$hours>168) throw new RuntimeException('The imported week would exceed 168 total hours.',422);
    if ($hours<=24) staffingTimesheetAssertDailyHours($person,$date,$hours,(int)($existing['id']??0));
    $period=staffingTimeResolveOpenPeriod($date,$bounds['start'],$bounds['end']);
    $header=staffingTimesheetUpsert($person,$bounds['start'],$bounds['end'],['source'=>'bulk_upload','source_system'=>$source,'created_by_user_id'=>$actor]);
    staffingTimeAssertEditableHeader($header);
    $values=['placement_id'=>(int)$placement['id'],'person_id'=>$person,'period_id'=>$period,'timesheet_id'=>(int)$header['id'],'work_date'=>$date,
        'category'=>$category,'hour_type'=>$row['hour_type'],'hours'=>$hours,'description'=>trim((string)($row['description']??''))?:null,
        'external_id'=>$external,'source_system'=>$source,'source'=>'bulk_upload','status'=>'pending_review','billable'=>in_array($row['hour_type'],['regular','overtime','doubletime'],true)?1:0,
        'payable'=>$row['hour_type']==='unpaid'?0:1,'approved_at'=>null,'approved_by_user_id'=>null,'approved_via'=>null,'client_approver_email'=>null,'rate_snapshot_id'=>null,
        'dimension_snapshot_json'=>null,'dimension_snapshot_hash'=>null,'rejected_reason'=>null];
    if ($existing) {staffingTimeUpdate('time_entries',(int)$existing['id'],$values);$id=(int)$existing['id'];}
    else $id=staffingTimeInsert('time_entries',$values+['created_by_user_id'=>$actor]);
    $entry=timeEntryGet($id);
    timeNativeRequiredAudit($existing?'time.entry.updated':'time.entry.created',$id,['source'=>'bulk_upload','before'=>$existing,'after'=>$entry,'external_approval_is_source_evidence'=>true],$actor);
    return ['entry'=>$entry,'headers'=>array_values(array_unique(array_filter([(int)$header['id'],(int)($existing['timesheet_id']??0)])))];
}

function timeCsvImportCommit(int $workspace,int $actor,string $csv,?array $map,array $options,?string $revision): array
{
    $options=timeCsvImportOptions($options);
    if (!is_string($revision) || !preg_match('/^[a-f0-9]{64}$/D',$revision)) throw new RuntimeException('Preview the complete import before confirming it.',409);
    try {return StaffingNativeTimeEdit::runForWorkspace($workspace,static function()use($actor,$csv,$map,$options,$revision):array {
        timeCsvRequireSchema();$pdo=getDB();$request=timeCsvImportRequest($csv,$map,$options);
        $q=$pdo->prepare('SELECT * FROM time_csv_imports WHERE tenant_id=? AND request_hash=? FOR UPDATE');$q->execute([staffingTimeWorkspace(),$request]);$prior=$q->fetch(PDO::FETCH_ASSOC);
        if ($prior) {
            $saved=json_decode((string)$prior['result_json'],true);
            if (!is_array($saved)) throw new RuntimeException('This import has no completed receipt. Review its history.',409);
            return array_replace($saved,['replayed'=>true,'message'=>'Returned the saved result for this exact file and options. No time was changed.']);
        }
        $state=timeCsvImportState($actor,$csv,$map,$options);$dry=$state['dry'];
        if (!hash_equals($state['revision'],$revision)) throw new RuntimeException('The preview, original time or directory changed. Preview this file again.',409);
        $failure=static fn(array $errors)=>['imported_count'=>0,'skipped_count'=>$dry['row_count'],'errors'=>$errors,'ids'=>[],'timesheet_ids'=>[],'timesheets'=>[],'message'=>'No rows were imported because validation failed.'];
        if (!$options['skip_invalid'] && $dry['errors']) throw new TimeCsvImportRefused($failure($dry['errors']));
        $sourceHash=hash('sha256',$csv);$q=$pdo->prepare('INSERT INTO time_csv_imports (tenant_id,request_hash,source_hash,source_ct,options_json,imported_by_user_id) VALUES (?,?,?,?,?,?)');
        $q->execute([staffingTimeWorkspace(),$request,$sourceHash,base64_encode(encryptField($csv)),json_encode(['column_map'=>$map]+$options,JSON_THROW_ON_ERROR),$actor]);$importId=(int)$pdo->lastInsertId();
        $errors=$dry['errors'];$ids=[];$headers=[];
        foreach($dry['rows']as$number=>$row) {
            if (isset($errors[$number])) continue;
            $pdo->exec('SAVEPOINT time_csv_intake_row');
            try {
                $written=timeCsvImportWriteRow($actor,$row,$options);$entry=$written['entry'];
                $pdo->prepare('UPDATE time_csv_row_evidence SET is_current=0 WHERE tenant_id=? AND time_entry_id=? AND is_current=1')->execute([staffingTimeWorkspace(),$entry['id']]);
                $pdo->prepare('INSERT INTO time_csv_row_evidence (tenant_id,import_id,source_row,time_entry_id,entry_hash,hours_basis,source_approval_json) VALUES (?,?,?,?,?,?,?)')->execute([
                    staffingTimeWorkspace(),$importId,$number,$entry['id'],timeCsvEntryHash($entry),$row['hours_basis'],$row['source_approval']?json_encode($row['source_approval'],JSON_THROW_ON_ERROR):null]);
                $pdo->exec('RELEASE SAVEPOINT time_csv_intake_row');$ids[$number]=(int)$entry['id'];$headers=[...$headers,...$written['headers']];
            } catch(Throwable $e) {
                if ($e instanceof PDOException || (int)$e->getCode()===503 || !($e instanceof RuntimeException || $e instanceof InvalidArgumentException)) throw $e;
                $pdo->exec('ROLLBACK TO SAVEPOINT time_csv_intake_row');$pdo->exec('RELEASE SAVEPOINT time_csv_intake_row');$errors[$number][]=$e->getMessage();
            }
        }
        if (!$options['skip_invalid'] && $errors) throw new TimeCsvImportRefused($failure($errors));
        $headers=array_values(array_unique($headers));$artifacts=[];
        foreach($headers as$id) {$header=timeCsvRefreshTimesheet($id,$actor);$artifacts[]=array_intersect_key($header,array_flip(['id','display_id','artifact_id','status','period_start','period_end','person_id']))+['approval_token_capable'=>true];}
        $result=['import_id'=>$importId,'source_hash'=>$sourceHash,'imported_count'=>count($ids),'skipped_count'=>$dry['row_count']-count($ids),'errors'=>$errors,'ids'=>$ids,'timesheet_ids'=>$headers,'timesheets'=>$artifacts,
            'pre_approved'=>false,'requires_independent_review'=>true,'replayed'=>false,'message'=>'Imported time awaits independent Core review. External approval details are retained as source evidence.'];
        $pdo->prepare('UPDATE time_csv_imports SET result_json=? WHERE tenant_id=? AND id=?')->execute([json_encode($result,JSON_THROW_ON_ERROR),staffingTimeWorkspace(),$importId]);
        timeNativeRequiredAudit('time.bulk.uploaded',$importId,['source_hash'=>$sourceHash,'entry_ids'=>array_values($ids),'source_approval_state'=>'asserted','result'=>$result],$actor);
        return $result;
    });} catch(TimeCsvImportRefused $e) {return $e->result;}
}

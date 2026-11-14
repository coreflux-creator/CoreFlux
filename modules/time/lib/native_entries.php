<?php
/** Native atomic time writes. Callers supply their existing worker authorization. */
declare(strict_types=1);
require_once __DIR__.'/../../staffing/lib/timesheets.php';
require_once __DIR__.'/../../../core/record_numbers.php';

function timeNativeDate(mixed $value): string
{
    $value=trim((string)$value);$date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    if (!$date || $date->format('Y-m-d')!==$value) throw new RuntimeException('work_date must be a valid YYYY-MM-DD date',422);
    return $value;
}

function timeNativeHours(mixed $value): float
{
    if (!is_numeric($value)) throw new RuntimeException('hours must be numeric',422);
    $hours=(float)$value;
    if (!is_finite($hours) || $hours<=0 || $hours>24) throw new RuntimeException('hours must be greater than 0 and no more than 24',422);
    $hours=round($hours,2);
    if ($hours<=0) throw new RuntimeException('hours must be at least 0.01',422);
    return $hours;
}

function timeNativeRequiredAudit(string $event,int $id,array $meta,?int $actor): void
{
    if (!StaffingNativeTimeEdit::active()) throw new LogicException('Time audit requires the protected write transaction.');
    require_once __DIR__.'/../../../core/audit.php';
    $receipt=bin2hex(random_bytes(16));$meta['time_change_id']=$receipt;
    platformAuditLogWrite(staffingTimeWorkspace(),$actor,$event,$id,$meta);
    $q=getDB()->prepare("SELECT id FROM audit_log WHERE tenant_id=? AND actor_user_id<=>? AND event=? AND target_id=? AND JSON_UNQUOTE(JSON_EXTRACT(meta_json,'$.time_change_id'))=? LIMIT 1 FOR UPDATE");
    $q->execute([staffingTimeWorkspace(),$actor,$event,$id,$receipt]);
    if (!$q->fetchColumn()) throw new RuntimeException('The time change could not be recorded. Please try again.',503);
}

function timeNativePlacement(int $id,string $date,?int $person=null): array
{
    if (!StaffingNativeTimeEdit::active()) throw new LogicException('Time placement selection requires the protected transaction.');
    $identity=placementIdentityResolve(getDB(),(int)StaffingNativeTimeEdit::owner('placements'),$id,true);
    if (!$identity) throw new RuntimeException('Placement not found in this workspace directory',404);
    if ($identity['merged']) throw new RuntimeException('This placement was merged. Review its original time and select the current placement for new work.',409);
    $placement=$identity['placement'];$worker=(int)$placement['person_id'];
    if ($person!==null && $person!==$worker) throw new RuntimeException('The original worker does not match this placement',409);
    [$start,$end]=timeWeekBounds($date);
    staffingTimesheetValidateWeek($worker,$start,$end);staffingTimesheetValidatePlacement($id,$worker,$date);
    return $placement;
}

function timeNativeValues(array $body,?array $original=null): array
{
    $date=timeNativeDate($body['work_date']??$original['work_date']??'');
    $hours=timeNativeHours($body['hours']??$original['hours']??null);
    $category=(string)($body['category']??$original['category']??'');
    if (!in_array($category,TIME_CATEGORIES,true)) throw new RuntimeException('Invalid category',422);
    $custom=array_key_exists('custom_category_id',$body)?$body['custom_category_id']:($original['custom_category_id']??null);
    if ($custom!==null && $custom!=='') {
        if (filter_var($custom,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false) throw new RuntimeException('Choose a valid custom category',422);
        $row=staffingTimeFind('SELECT id FROM tenant_time_categories WHERE tenant_id=:tenant_id AND id=:id AND active=1',['id'=>(int)$custom]);
        if (!$row) throw new RuntimeException('Custom category is unavailable in this workspace',422);
        $custom=(int)$custom;
    } else $custom=null;
    return ['work_date'=>$date,'hours'=>$hours,'category'=>$category,'custom_category_id'=>$custom,
        'description'=>array_key_exists('description',$body)?trim((string)$body['description']):($original['description']??null)]+timeCategoryRowMeta($category);
}

function timeNativeDraftHeader(int $person,string $date,int $actor,string $source,bool $correction=false): int
{
    [$start,$end]=timeWeekBounds($date);$header=staffingTimesheetFind($person,$start);
    if (!$header) $header=staffingTimesheetUpsert($person,$start,$end,['source'=>$source,'created_by_user_id'=>$actor]);
    if (in_array($header['status'],['payroll_ready','billing_ready','locked'],true) || (!$correction && $header['status']==='approved')) throw new RuntimeException('This week is complete or feeds downstream work. Use its correction workflow.',409);
    if (!$correction && in_array($header['status'],['submitted','rejected'],true)) $header=staffingTimesheetReopen($actor,(int)$header['id'],'native draft time edited');
    return (int)$header['id'];
}

function timeNativeReconcile(array $headers,int $actor): void
{
    foreach(array_unique(array_filter(array_map('intval',$headers)))as$id) timeReconcileTimesheetHeader($id,$actor);
}

function timeNativeCreate(array $user,array $rows,callable $authorize,int $documentId=0,bool $forceDraft=false): array
{
    if (!$rows) throw new RuntimeException('entries[] required',422);
    if (count($rows)>500) throw new RuntimeException('Too many entries (max 500 per batch)',422);
    // Single-row callers retain their source-ref contract, but consume the same
    // document atomically rather than bypassing the bulk upload replay guard.
    if ($documentId<=0) foreach ($rows as $row) {
        if (is_array($row) && ($row['source']??'')==='ai_inbox') {
            $documentId=(int)($row['source_ref_id']??0);
            if ($documentId<=0) throw new RuntimeException('Uploaded time requires its source document',422);
            break;
        }
    }
    return StaffingNativeTimeEdit::run(static function()use($user,$rows,$authorize,$documentId,$forceDraft):array {
        $actor=(int)$user['id'];$document=null;
        if($documentId>0) {
            $document=staffingTimeFind('SELECT * FROM time_uploaded_documents WHERE tenant_id=:tenant_id AND id=:id',['id'=>$documentId]);
            if(!$document) throw new RuntimeException('Uploaded timesheet document not found',404);
            if($document['extraction_status']==='consumed') throw new RuntimeException('This uploaded timesheet has already been saved',409);
        }
        $ids=[];$headers=[];
        foreach($rows as$body) {
            if(!is_array($body)) throw new RuntimeException('Every entry must be an object',422);
            $values=timeNativeValues($body);$placement=timeNativePlacement((int)($body['placement_id']??0),$values['work_date']);
            $person=(int)$placement['person_id'];$authorize($person);
            $source=(string)($body['source']??'manual_entry');
            if(!in_array($source,['ai_inbox','bulk_upload','manual_entry','client_portal_paste'],true)) throw new RuntimeException('Invalid source',422);
            $ref=$body['source_ref_id']??null;
            if($ref!==null && $ref!=='' && filter_var($ref,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false) throw new RuntimeException('source_ref_id must be a positive integer',422);
            $ref=$ref===null||$ref===''?null:(int)$ref;
            if($documentId>0 && ($source!=='ai_inbox'||$ref!==$documentId)) throw new RuntimeException('Every uploaded entry must reference the same source document',422);
            if($source==='ai_inbox') {
                $origin=$ref?staffingTimeFind('SELECT id,extraction_status FROM time_uploaded_documents WHERE tenant_id=:tenant_id AND id=:id',['id'=>$ref]):null;
                if(!$origin) throw new RuntimeException('Uploaded source document not found in this workspace',404);
                if($origin['extraction_status']==='consumed') throw new RuntimeException('This uploaded timesheet has already been saved',409);
            }
            $status=$forceDraft?'draft':(string)($body['status']??'draft');
            if(!in_array($status,['draft','pending_review'],true)) throw new RuntimeException('New time entries can only be draft or pending_review',422);
            staffingTimesheetAssertDailyHours($person,$values['work_date'],$values['hours']);
            [$start,$end]=timeWeekBounds($values['work_date']);$period=staffingTimeResolveOpenPeriod($values['work_date'],$start,$end);
            $header=timeNativeDraftHeader($person,$values['work_date'],$actor,$source);
            $id=staffingTimeInsert('time_entries',$values+['placement_id'=>(int)$placement['id'],'person_id'=>$person,'period_id'=>$period,'timesheet_id'=>$header,'source'=>$source,'source_ref_id'=>$ref,'status'=>$status,'created_by_user_id'=>$actor]);
            $ids[]=$id;$headers[]=$header;
            timeNativeRequiredAudit('time.entry.created',$id,['after'=>timeEntryGet($id),'batch'=>count($rows)>1],$actor);
        }
        timeNativeReconcile($headers,$actor);
        if($documentId>0) {
            $q=getDB()->prepare('UPDATE time_uploaded_documents SET extraction_status="consumed",consumed_at=NOW(),consumed_entry_count=:count WHERE tenant_id=:tenant_id AND id=:id');
            $q->execute(['count'=>count($ids),'tenant_id'=>staffingTimeWorkspace(),'id'=>$documentId]);
            timeNativeRequiredAudit('time.upload.consumed',$documentId,['before'=>$document,'entry_ids'=>$ids,'entry_count'=>count($ids),'atomic'=>true],$actor);
        }
        return ['ok'=>true,'created'=>count($ids),'entry_ids'=>$ids,'timesheet_ids'=>array_values(array_unique($headers)),'entries'=>array_map('timeEntryGet',$ids),'document_consumed'=>$documentId>0];
    });
}

function timeNativePatch(int $id,array $body,array $user,callable $authorize): array
{
    $allowed=['work_date','category','hours','description','custom_category_id'];
    $body=array_intersect_key($body,array_flip($allowed));
    if(!$body) throw new RuntimeException('No editable fields supplied',422);
    return StaffingNativeTimeEdit::run(static function()use($id,$body,$user,$authorize):array {
        $before=timeEntryGet($id);if(!$before) throw new RuntimeException('Time entry not found',404);
        $authorize((int)$before['person_id']);staffingTimeAssertEditableEntry($before);
        $values=timeNativeValues($body,$before);$person=(int)$before['person_id'];$actor=(int)$user['id'];
        timeNativePlacement((int)$before['placement_id'],$values['work_date'],$person);
        $oldHeader=(int)($before['timesheet_id']??0);
        if($oldHeader>0) {
            $header=staffingTimesheetRow(staffingTimeWorkspace(),$oldHeader,true);
            if(!$header || (int)$header['person_id']!==$person) throw new RuntimeException('Review the original entry worker and timesheet',409);
            staffingTimeAssertEditableHeader($header);
        }
        staffingTimesheetAssertDailyHours($person,$values['work_date'],$values['hours'],$id);
        [$start,$end]=timeWeekBounds($values['work_date']);$period=staffingTimeResolveOpenPeriod($values['work_date'],$start,$end);
        $header=timeNativeDraftHeader($person,$values['work_date'],$actor,(string)$before['source']);
        staffingTimeUpdate('time_entries',$id,$values+['period_id'=>$period,'timesheet_id'=>$header,'status'=>'draft','rejected_reason'=>null]);
        timeNativeReconcile([$oldHeader,$header],$actor);$after=timeEntryGet($id);
        timeNativeRequiredAudit('time.entry.updated',$id,['before'=>$before,'after'=>$after,'fields'=>array_keys($body)],$actor);
        return $after;
    });
}

/** Financial source links remain authoritative even when extraction flags are absent. */
function timeNativeAssertNoFinancialHistory(array $entry): void
{
    if (!StaffingNativeTimeEdit::active()) throw new LogicException('Financial history review requires the protected transaction.');
    $pdo=getDB();$workspace=staffingTimeWorkspace();
    // A parent-owned ledger can retain the original facts after a sharing change.
    // Restrict discovery to the original workspace and its immediate parent.
    $q=$pdo->prepare('SELECT parent_id FROM tenants WHERE id=? FOR UPDATE');$q->execute([$workspace]);
    $owners=array_values(array_unique(array_filter([$workspace,(int)$q->fetchColumn()])));
    $marks=implode(',',array_fill(0,count($owners),'?'));
    foreach([['billing_invoice_lines','billing_invoices','invoice_id'],['ap_bill_lines','ap_bills','bill_id']]as[$lines,$documents,$foreign]) {
        // `time` identifies a downstream bundle, not an individual entry.
        $q=$pdo->prepare('SELECT l.id FROM '.$lines.' l JOIN '.$documents.' d ON d.id=l.'.$foreign.' WHERE d.tenant_id IN ('.$marks.') AND l.source_type="time_entry" AND l.source_ref_id=? FOR UPDATE');
        $q->execute([...$owners,$entry['id']]);
        if($q->fetchColumn())throw new RuntimeException('This time has a linked invoice or vendor bill. Use its downstream correction workflow.',409);
    }
    $q=$pdo->prepare('SELECT id,entries_json,status,consumed_at,consumed_ref_id FROM time_downstream_feed WHERE tenant_id=? AND period_id=? AND placement_id=? FOR UPDATE');
    $q->execute([$workspace,$entry['period_id'],$entry['placement_id']]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC)as$bundle) {
        $payload=json_decode((string)$bundle['entries_json'],true);
        if(!is_array($payload) || !is_array($payload['entry_ids']??null)
            || array_filter($payload['entry_ids'],static fn($id)=>filter_var($id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false)) throw new RuntimeException('Review this period’s downstream source evidence before correcting time.',409);
        if(!in_array((int)$entry['id'],array_map('intval',$payload['entry_ids']),true)) continue;
        if(in_array($bundle['status'],['consumed','locked'],true) || !empty($bundle['consumed_at']) || !empty($bundle['consumed_ref_id'])) throw new RuntimeException('This time has a consumed downstream bundle. Review its downstream correction workflow.',409);
        foreach([['billing_invoice_lines','billing_invoices','invoice_id'],['ap_bill_lines','ap_bills','bill_id']]as[$lines,$documents,$foreign]) {
            $linked=$pdo->prepare('SELECT l.id FROM '.$lines.' l JOIN '.$documents.' d ON d.id=l.'.$foreign.' WHERE d.tenant_id IN ('.$marks.') AND l.source_type IN ("time","time_bundle","economic_party") AND l.source_ref_id=? FOR UPDATE');
            $linked->execute([...$owners,$bundle['id']]);
            if($linked->fetchColumn())throw new RuntimeException('This time bundle has a linked invoice or vendor bill. Use its downstream correction workflow.',409);
        }
        // Even an unconsumed ready bundle carries frozen amounts, and can already
        // have an accrual journal. Ordinary replacement must not leave it payable.
        throw new RuntimeException('Downstream preparation must be reviewed before this time can be corrected.',409);
    }
    $header=(int)($entry['timesheet_id']??0);
    if($header>0) {
        $q=$pdo->prepare('SELECT id FROM accounting_events WHERE tenant_id IN ('.$marks.') AND source_module="staffing" AND (source_record_id=? OR source_record_id LIKE ? OR source_record_id LIKE ?) FOR UPDATE');
        $q->execute([...$owners,(string)$header,$header.':%','timesheet:'.$header.':placement:%']);
        if($q->fetchColumn())throw new RuntimeException('This week has accounting history. Use its downstream correction workflow to preserve the ledger.',409);
    }
}

/** The revision is a comparison token, never an access credential. */
function timeNativeCorrectionContext(int $id): array
{
    if (!StaffingNativeTimeEdit::active()) throw new LogicException('Correction review requires the protected transaction.');
    $entry=timeEntryGet($id);if(!$entry) throw new RuntimeException('Time entry not found',404);
    if($entry['status']!=='approved') throw new RuntimeException('Only approved entries can be corrected',409);
    staffingTimesheetAssertEntryNotSettled($entry);staffingTimeAssertOpenEntryPeriod($entry);
    timeNativeAssertNoFinancialHistory($entry);
    $q=getDB()->prepare('SELECT id FROM quanta_time_imports WHERE tenant_id=? AND time_entry_id=? FOR UPDATE');$q->execute([staffingTimeWorkspace(),$id]);
    if($q->fetchColumn() || ($entry['source']??'')==='quanta' || ($entry['source_system']??'')==='quanta') throw new RuntimeException('Review the corrected source in Quanta time settings. Its original source and approval history must be retained.',409);
    $placement=staffingTimeCurrentPlacement((int)$entry['placement_id'],(int)$entry['person_id']);
    [$start,$end]=timeWeekBounds((string)$entry['work_date']);staffingTimesheetValidateWeek((int)$entry['person_id'],$start,$end);
    $q=getDB()->prepare('SELECT id,first_name,last_name FROM people WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([StaffingNativeTimeEdit::owner('people'),$entry['person_id']]);$worker=$q->fetch(PDO::FETCH_ASSOC);
    $worker=coreRecordNumbersAttach(getDB(),(int)StaffingNativeTimeEdit::owner('people'),'person',[$worker])[0];
    $placement=coreRecordNumbersAttach(getDB(),(int)StaffingNativeTimeEdit::owner('placements'),'placement',[$placement])[0];
    $header=null;$headerId=(int)($entry['timesheet_id']??0);
    if($headerId>0) {
        $header=staffingTimesheetRow(staffingTimeWorkspace(),$headerId,true);
        if(!$header || (int)$header['person_id']!==(int)$entry['person_id'] || in_array($header['status'],['payroll_ready','billing_ready','locked'],true)) throw new RuntimeException('Review or reverse the original downstream timesheet before correcting it',409);
    }
    $revision=hash('sha256',json_encode(['workspace'=>staffingTimeWorkspace(),'people_owner'=>StaffingNativeTimeEdit::owner('people'),'placement_owner'=>StaffingNativeTimeEdit::owner('placements'),'entry'=>$entry,'header'=>$header,'placement'=>$placement,'worker'=>$worker],JSON_THROW_ON_ERROR));
    return ['workspace_id'=>staffingTimeWorkspace(),'entry'=>$entry,'timesheet'=>$header,'worker'=>$worker,
        'placement'=>array_intersect_key($placement,array_flip(['id','placement_number','title','start_date','end_date','actual_end_date'])),
        'revision'=>$revision];
}

function timeNativeCorrectionReview(int $id): array
{
    return StaffingNativeTimeEdit::run(static function()use($id):array {
        return timeNativeCorrectionContext($id)+['custom_categories'=>staffingTimeQuery('SELECT id,label,parent_bucket,is_overtime FROM tenant_time_categories WHERE tenant_id=:tenant_id AND active=1 ORDER BY label,id')];
    });
}

/** Read original and replacement rows without reassigning historical identities. */
function timeNativeEntryHistory(int $id): array
{
    return companyRelationshipAtomic(getDB(),static function()use($id):array {
        $pdo=getDB();$workspace=staffingTimeWorkspace();
        $read=static function(int $entryId)use($pdo,$workspace):?array {
            $q=$pdo->prepare('SELECT * FROM time_entries WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([$workspace,$entryId]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
        };
        $selected=$read($id);if(!$selected) throw new RuntimeException('Time entry not found',404);
        $seen=[];$ancestors=[];$cursor=$selected;
        $sameIdentity=static function(array $row)use($selected):void {
            if((int)$row['person_id']!==(int)$selected['person_id'] || (int)$row['placement_id']!==(int)$selected['placement_id']) throw new RuntimeException('Correction history has inconsistent worker or assignment identities. Review its source evidence.',409);
        };
        while(true) {
            $seen[(int)$cursor['id']]=true;
            $q=$pdo->prepare('SELECT * FROM time_entries WHERE tenant_id=? AND superseded_by_id=? ORDER BY id FOR UPDATE');$q->execute([$workspace,$cursor['id']]);$prior=$q->fetchAll(PDO::FETCH_ASSOC);
            if(!$prior)break;
            if(count($prior)!==1 || isset($seen[(int)$prior[0]['id']]) || count($seen)>=100) throw new RuntimeException('Correction history is ambiguous or cyclic. Review its source evidence.',409);
            $cursor=$prior[0];$sameIdentity($cursor);
            if($cursor['status']!=='superseded')throw new RuntimeException('Correction history has an inconsistent original status. Review its source evidence.',409);
            $ancestors[]=$cursor;
        }
        $rows=[...array_reverse($ancestors),$selected];$cursor=$selected;
        while((int)($cursor['superseded_by_id']??0)>0) {
            if($cursor['status']!=='superseded')throw new RuntimeException('Correction history has an inconsistent original status. Review its source evidence.',409);
            $next=(int)$cursor['superseded_by_id'];
            if(isset($seen[$next]) || count($seen)>=100) throw new RuntimeException('Correction history is ambiguous or cyclic. Review its source evidence.',409);
            $q=$pdo->prepare('SELECT id FROM time_entries WHERE tenant_id=? AND superseded_by_id=? ORDER BY id FOR UPDATE');$q->execute([$workspace,$next]);
            if(array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN))!==[(int)$cursor['id']])throw new RuntimeException('Correction history is ambiguous. Review its source evidence.',409);
            $cursor=$read($next);if(!$cursor)throw new RuntimeException('A correction replacement is unavailable in this workspace. Review its source evidence.',409);
            $sameIdentity($cursor);$seen[$next]=true;$rows[]=$cursor;
        }
        return ['workspace_id'=>$workspace,'entry_id'=>$id,'entries'=>$rows];
    });
}

function timeNativeCorrect(int $id,array $body,array $user): array
{
    $reason=trim((string)($body['correction_reason']??''));
    if($reason==='' || strlen($reason)>500) throw new RuntimeException('Provide a correction reason of no more than 500 characters',422);
    return StaffingNativeTimeEdit::run(static function()use($id,$body,$user,$reason):array {
        $context=timeNativeCorrectionContext($id);$before=$context['entry'];
        if(!is_string($body['review_revision']??null) || !hash_equals($context['revision'],$body['review_revision'])) throw new RuntimeException('The approved time or its assignment changed. Reload the correction review before saving.',409);
        $values=timeNativeValues($body,$before);$person=(int)$before['person_id'];$actor=(int)$user['id'];
        timeNativePlacement((int)$before['placement_id'],$values['work_date'],$person);
        $oldHeader=(int)($before['timesheet_id']??0);
        staffingTimesheetAssertDailyHours($person,$values['work_date'],$values['hours'],$id);
        [$start,$end]=timeWeekBounds($values['work_date']);$period=staffingTimeResolveOpenPeriod($values['work_date'],$start,$end);
        $target=timeNativeDraftHeader($person,$values['work_date'],$actor,'manual_entry',true);
        require_once __DIR__.'/accounting_handoff.php';
        $withdrawn=timeAccountingHandoffWithdraw(staffingTimeWorkspace(),[$oldHeader,$target],$actor,$reason);
        $new=staffingTimeInsert('time_entries',$values+['placement_id'=>(int)$before['placement_id'],'person_id'=>$person,'period_id'=>$period,'timesheet_id'=>$target,'source'=>'manual_entry','status'=>'draft','correction_reason'=>$reason,'created_by_user_id'=>$actor]);
        staffingTimeUpdate('time_entries',$id,['status'=>'superseded','superseded_by_id'=>$new]);
        timeNativeReconcile([$oldHeader,$target],$actor);
        timeNativeRequiredAudit('time.entry.superseded',$id,['before'=>$before,'after'=>timeEntryGet($id),'replacement'=>timeEntryGet($new),'by_entry_id'=>$new,'reason'=>$reason,'cancelled_accounting_handoff_ids'=>$withdrawn],$actor);
        return ['ok'=>true,'workspace_id'=>staffingTimeWorkspace(),'superseded_entry_id'=>$id,'new_entry_id'=>$new,'timesheet_id'=>$target,'original_timesheet_id'=>$oldHeader];
    });
}

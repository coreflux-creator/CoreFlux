<?php
/** Retain source approval assertions separately from an independent Core decision. */
declare(strict_types=1);
require_once __DIR__.'/../../../core/encryption.php';

function timeCsvRequireSchema(): void
{
    $q=getDB()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('time_csv_imports','time_csv_row_evidence')");
    if ((int)$q->fetchColumn()!==2) throw new RuntimeException('Time import needs a system update. Contact your administrator.',503);
}

function timeCsvEntryHash(array $entry): string
{
    $value=[];
    foreach(['person_id','placement_id','period_id','timesheet_id','work_date','hours','category','hour_type','description','source_system','external_id','billable','payable']as$field) {
        $value[$field]=$field==='hours'?number_format((float)($entry[$field]??0),2,'.',''):(string)($entry[$field]??'');
    }
    return hash('sha256',staffingDimensionSnapshotCanonicalJson($value));
}

function timeCsvApprovalAssertion(array $row,bool $required): ?array
{
    $email=trim((string)($row['source_approver_email']??''));$at=trim((string)($row['source_approved_at']??''));$reference=trim((string)($row['source_approval_reference']??''));
    if (!$required && $email==='' && $at==='' && $reference==='') return null;
    if (!filter_var($email,FILTER_VALIDATE_EMAIL) || $reference==='' || strlen($reference)>500) throw new RuntimeException('External approval needs the reviewer email and a source reference of 1 to 500 characters.',422);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D',$at)) throw new RuntimeException('External approval time must include its date, time and timezone, for example 2026-10-20T12:00:00Z.',422);
    $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP',$at);$errors=DateTimeImmutable::getLastErrors();
    if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $date->getTimestamp()>time()+300) throw new RuntimeException('Use a valid external approval timestamp that is not in the future.',422);
    return ['state'=>'asserted','approved_by_email'=>$email,'approved_at'=>$at,'reference'=>$reference,'source_system'=>$row['source_system']??'manual'];
}

/** No source evidence is inferred for older or non-CSV entries. */
function timeCsvEvidenceForEntry(array $entry): ?array
{
    if (($entry['source']??'')!=='bulk_upload') return null;
    $exists=getDB()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='time_csv_row_evidence'")->fetchColumn();
    if (!$exists) return null;
    $rows=staffingTimeQuery('SELECT e.*,i.source_hash,i.imported_by_user_id FROM time_csv_row_evidence e JOIN time_csv_imports i ON i.id=e.import_id AND i.tenant_id=e.tenant_id WHERE e.tenant_id=:tenant_id AND e.time_entry_id=:id AND e.is_current=1 ORDER BY e.id',['id'=>$entry['id']]);
    if (!$rows) return null;
    if (count($rows)!==1) throw new RuntimeException('This entry has conflicting CSV source records. Review its import history.',409);
    $row=$rows[0];$approval=json_decode((string)($row['source_approval_json']??''),true);
    return ['import_id'=>(int)$row['import_id'],'source_row'=>(int)$row['source_row'],'source_hash'=>$row['source_hash'],
        'imported_by_user_id'=>(int)$row['imported_by_user_id'],'hours_basis'=>$row['hours_basis'],
        'matches_source'=>hash_equals($row['entry_hash'],timeCsvEntryHash($entry)),
        'external_approval'=>is_array($approval)?$approval:null,'receipt_revision'=>hash('sha256',staffingDimensionSnapshotCanonicalJson($row))];
}

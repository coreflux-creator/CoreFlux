<?php
/** Atomic, ownership-checked source intake. Approved corrections require separate review. */
declare(strict_types=1);
require_once __DIR__.'/time_contract.php';
require_once __DIR__.'/../encryption.php';
require_once __DIR__.'/../integrations/identity.php';

function jobdivaTimeDirectory(PDO $pdo,int $tenant,string $module): int {
    if(!$pdo->inTransaction()||!in_array($module,['people','placements'],true))throw new LogicException('Locked directory lookup required.');
    $q=$pdo->prepare('SELECT * FROM tenants WHERE id=? AND is_active=1 FOR UPDATE');$q->execute([$tenant]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new JobdivaTimeConflict('Company is unavailable.');
    $q=$pdo->prepare('SELECT scope_mode FROM tenant_module_scope WHERE tenant_id=? AND module_key=? FOR UPDATE');$q->execute([$tenant,$module]);$mode=$q->fetchColumn();
    if($mode===false)$mode=defined('SUBTENANT_MODULE_SCOPE_DEFAULTS')?(SUBTENANT_MODULE_SCOPE_DEFAULTS[$module]??'isolated'):($module==='people'?'shared':'isolated');
    if(!in_array($mode,['shared','isolated'],true))throw new JobdivaTimeConflict('Directory policy requires review.');
    $owner=$row['tenant_type']==='sub'&&!empty($row['parent_id'])&&$mode==='shared'?(int)$row['parent_id']:$tenant;
    if($owner!==$tenant){$q=$pdo->prepare('SELECT id FROM tenants WHERE id=? AND is_active=1 FOR UPDATE');$q->execute([$owner]);if(!$q->fetchColumn())throw new JobdivaTimeConflict('Owning directory is unavailable.');}
    return $owner;
}
function jobdivaTimeIntakeConnection(PDO $pdo,int $tenant,bool $lock=false): array {
    $q=$pdo->prepare('SELECT id,client_id,username,status,sync_config FROM jobdiva_connections WHERE tenant_id=?'.($lock?' FOR UPDATE':''));$q->execute([$tenant]);$row=$q->fetch(PDO::FETCH_ASSOC);
    $config=$row?json_decode((string)$row['sync_config'],true,64,JSON_THROW_ON_ERROR):null;
    if(!$row||$row['status']!=='connected'||($config['time']['source']??null)!=='jobdiva'||!in_array($config['time']['direction']??'off',['pull','two_way'],true))throw new JobdivaTimeConflict('JobDiva does not currently own enabled time intake for this company.');
    return $row+['identity_hash'=>mappingHash(['client_id'=>(string)$row['client_id'],'username'=>(string)$row['username']])];
}
function jobdivaTimePeriodOpen(PDO $pdo,int $tenant,string $date,?int $expectedId=null): void {
    $q=$pdo->prepare('SELECT id,status,start_date,end_date FROM time_periods WHERE tenant_id=? AND (id=? OR (start_date<=? AND end_date>=?)) FOR UPDATE');$q->execute([$tenant,$expectedId??0,$date,$date]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$found=$expectedId===null;
    foreach($rows as$row){
        if($row['status']!=='open')throw new JobdivaTimeConflict('This work date belongs to a locked or closed period. Review the period before importing.');
        if((int)$row['id']===$expectedId){if($date<$row['start_date']||$date>$row['end_date'])throw new JobdivaTimeConflict('Original period does not contain the work date.');$found=true;}
    }
    if(!$found)throw new JobdivaTimeConflict('Original time period is unavailable.');
}
function jobdivaTimeHeaderOpen(PDO $pdo,int $tenant,int $id,int $person,string $date): array {
    $q=$pdo->prepare('SELECT * FROM staffing_timesheets WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([$tenant,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row||(int)$row['person_id']!==$person||$date<$row['period_start']||$date>$row['period_end']||!in_array($row['status'],['draft','rejected'],true)
        ||!empty($row['approved_at'])||!empty($row['approved_by_user_id']))throw new JobdivaTimeConflict('The original week is submitted, approved, locked or unavailable. Review its source correction separately.');
    return $row;
}
function jobdivaTimeApplyRecord(int $tenant,?int $actor,array $payload): array {
    $source=jobdivaTimeSource($payload);$hash=mappingHash($payload);$pdo=getDB();
    if($tenant<=0||($actor!==null&&$actor<=0)||$pdo->inTransaction())throw new LogicException('Time intake requires its own company transaction.');
    $pdo->beginTransaction();
    try{
        $peopleOwner=jobdivaTimeDirectory($pdo,$tenant,'people');$placementOwner=jobdivaTimeDirectory($pdo,$tenant,'placements');
        $connection=jobdivaTimeIntakeConnection($pdo,$tenant,true);
        $q=$pdo->prepare("SELECT * FROM external_entity_mappings WHERE tenant_id=? AND source_system='jobdiva' AND internal_entity_type='placement' AND external_id=? FOR UPDATE");$q->execute([$tenant,$source['placement_external_id']]);$placementMap=$q->fetch(PDO::FETCH_ASSOC);
        if(!$placementMap)throw new JobdivaTimeConflict('placement_unmapped');
        if($placementMap['external_id']!==$source['placement_external_id']||$placementMap['sync_status']!=='ok')throw new JobdivaTimeConflict('Placement source identity requires review.');
        $q=$pdo->prepare('SELECT * FROM placements WHERE tenant_id=? AND id=? AND deleted_at IS NULL FOR UPDATE');$q->execute([$placementOwner,$placementMap['internal_entity_id']]);$placement=$q->fetch(PDO::FETCH_ASSOC);
        if(!$placement||(int)$placement['person_id']<=0)throw new JobdivaTimeConflict('placement_missing_person');
        $q=$pdo->prepare('SELECT p.*,n.record_number AS person_number FROM people p JOIN core_record_numbers n ON n.tenant_id=p.tenant_id AND n.entity_type="person" AND n.record_id=p.id AND n.canonical_record_id=p.id WHERE p.tenant_id=? AND p.id=? AND p.deleted_at IS NULL FOR UPDATE');$q->execute([$peopleOwner,$placement['person_id']]);$person=$q->fetch(PDO::FETCH_ASSOC);
        if(!$person)throw new JobdivaTimeConflict('Placement worker is unavailable in this directory.');
        if($source['work_date']<$placement['start_date']||(!empty($placement['end_date'])&&$source['work_date']>$placement['end_date']))throw new JobdivaTimeConflict('Source work date is outside the placement dates.');
        $q=$pdo->prepare("SELECT * FROM external_entity_mappings WHERE tenant_id=? AND source_system='jobdiva' AND internal_entity_type='time_entry' AND external_id=? FOR UPDATE");$q->execute([$tenant,$source['external_id']]);$mapping=$q->fetch(PDO::FETCH_ASSOC)?:null;
        $prior=null;$entry=null;
        if($mapping){
            if($mapping['external_id']!==$source['external_id']||$mapping['direction']!=='pull'||$mapping['sync_status']!=='ok')throw new JobdivaTimeConflict('The existing time identity or source ownership requires review.');
            $q=$pdo->prepare('SELECT * FROM time_entries WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([$tenant,$mapping['internal_entity_id']]);$entry=$q->fetch(PDO::FETCH_ASSOC);
            $q=$pdo->prepare('SELECT * FROM jobdiva_time_intake WHERE tenant_id=? AND external_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE');$q->execute([$tenant,$source['external_id']]);$prior=$q->fetch(PDO::FETCH_ASSOC);
            if(!$entry||!$prior)throw new JobdivaTimeConflict('This legacy time link needs an explicit ownership review before intake can change it.');
            if((int)$prior['mapping_id']!==(int)$mapping['id']||(int)$prior['time_entry_id']!==(int)$entry['id']||(int)$prior['people_tenant_id']!==$peopleOwner||(int)$prior['placements_tenant_id']!==$placementOwner
                ||(int)$prior['connection_id']!==(int)$connection['id']||!hash_equals($prior['connection_identity_hash'],$connection['identity_hash']))throw new JobdivaTimeConflict('Time source, connection or directory identity changed. Review the existing link.');
            $saved=json_decode(decryptField($prior['source_ct']),true,64,JSON_THROW_ON_ERROR);$savedEntry=json_decode(decryptField($prior['entry_ct']),true,64,JSON_THROW_ON_ERROR);
            if(!is_array($saved)||!is_array($savedEntry)||mappingHash($saved)!==$prior['source_hash']||$mapping['content_hash']!==$prior['source_hash']||mappingHash(json_decode($mapping['payload_snapshot'],true,64,JSON_THROW_ON_ERROR))!==$prior['source_hash']
                ||jobdivaTimeOwnedValues($entry)!==jobdivaTimeOwnedValues($savedEntry))throw new JobdivaTimeConflict('Core time or retained source evidence changed outside this intake. Review the difference first.');
            if((int)$entry['placement_id']!==(int)$placement['id']||(int)$entry['person_id']!==(int)$person['id']||$entry['work_date']!==$source['work_date'])throw new JobdivaTimeConflict('A source date, placement or worker change needs an explicit correction review.');
            if($entry['status']==='superseded'||!empty($entry['superseded_by_id']))throw new JobdivaTimeConflict('The original entry was superseded. Review its current source revision.');
            if(hash_equals($hash,$prior['source_hash'])){$pdo->commit();return ['id'=>(int)$entry['id'],'receipt_id'=>(int)$prior['id'],'replayed'=>true,'source_approval_state'=>'unverified'];}
            if($entry['status']!=='draft'||!empty($entry['approved_at'])||!empty($entry['approved_by_user_id'])||!empty($entry['rate_snapshot_id']))throw new JobdivaTimeConflict('Changed source for submitted or approved time requires correction review and independent reapproval.');
            jobdivaTimePeriodOpen($pdo,$tenant,$source['work_date'],(int)$entry['period_id']);
            jobdivaTimeHeaderOpen($pdo,$tenant,(int)$entry['timesheet_id'],(int)$person['id'],$source['work_date']);
            $periodId=(int)$entry['period_id'];$sheetId=(int)$entry['timesheet_id'];
        }else{
            jobdivaTimePeriodOpen($pdo,$tenant,$source['work_date']);
            $period=jobdivaEnsureTimePeriod($tenant,$source['work_date']);$periodId=(int)$period['id'];
            // A processing period can span more than a week; the artifact always has seven days.
            [$start,$end]=jobdivaTimeWeekBounds($tenant,$source['work_date']);
            $sheetId=jobdivaEnsureStaffingTimesheet($tenant,(int)$person['id'],$start,$end,$actor);
            jobdivaTimeHeaderOpen($pdo,$tenant,$sheetId,(int)$person['id'],$source['work_date']);
        }
        $category=$source['category'];if($placement['engagement_type']==='internal')$category=match($category){'regular_billable'=>'regular_nonbillable','OT_billable'=>'OT_nonbillable',default=>$category};
        $hourMeta=jobdivaHourTypeFromCategory($category);
        $next=['placement_id'=>(int)$placement['id'],'person_id'=>(int)$person['id'],'period_id'=>$periodId,'timesheet_id'=>$sheetId,
            'work_date'=>$source['work_date'],'hours'=>$source['hours'],'category'=>$category]+$hourMeta+['description'=>$source['description'],
            'source'=>'bulk_upload','source_system'=>'jobdiva','external_id'=>$source['external_id'],'created_by_user_id'=>$actor];
        $q=$pdo->prepare('SELECT COALESCE(SUM(hours),0) FROM time_entries WHERE tenant_id=? AND person_id=? AND work_date=? AND status<>"superseded" AND id<>?');$q->execute([$tenant,$person['id'],$source['work_date'],$entry['id']??0]);
        if((float)$q->fetchColumn()+(float)$source['hours']>24.000001)throw new JobdivaTimeConflict('This import would exceed 24 hours for the worker and date.');
        require_once __DIR__.'/../quanta/time_revisions.php';
        $instance=integrationInstanceId();
        if(!$instance)throw new JobdivaTimeConflict('Permanent integration identity is required for time reconciliation.');
        quantaTimeRevisionUnconsumed($pdo,$tenant,$entry??($next+['id'=>0]),integrationRecordReference($instance,$peopleOwner,'person',(string)$person['person_number']));
        $id=jobdivaSyncUpsertTimeEntry($tenant,$next,$source['external_id']);
        $json=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if($mapping){$q=$pdo->prepare('UPDATE external_entity_mappings SET payload_snapshot=?,content_hash=?,last_seen_at=NOW(),last_synced_at=NOW() WHERE tenant_id=? AND id=?');$q->execute([$json,$hash,$tenant,$mapping['id']]);$mappingId=(int)$mapping['id'];}
        else{$q=$pdo->prepare("INSERT INTO external_entity_mappings(tenant_id,source_system,internal_entity_type,external_id,internal_entity_id,payload_snapshot,content_hash,direction,sync_status,last_seen_at,last_synced_at) VALUES(?,'jobdiva','time_entry',?,?,?,?,'pull','ok',NOW(),NOW())");$q->execute([$tenant,$source['external_id'],$id,$json,$hash]);$mappingId=(int)$pdo->lastInsertId();}
        $q=$pdo->prepare('INSERT INTO entity_sync_history(tenant_id,source_system,internal_entity_type,internal_entity_id,external_id,direction,payload_before,payload_after,content_hash_before,content_hash_after,actor_user_id) VALUES(?,"jobdiva","time_entry",?,?,"pull",?,?,?,?,?)');
        $q->execute([$tenant,$id,$source['external_id'],$mapping['payload_snapshot']??null,$json,$mapping['content_hash']??null,$hash,$actor]);
        $q=$pdo->prepare('SELECT * FROM time_entries WHERE tenant_id=? AND id=?');$q->execute([$tenant,$id]);$after=$q->fetch(PDO::FETCH_ASSOC);
        $q=$pdo->prepare('INSERT INTO jobdiva_time_intake(tenant_id,connection_id,connection_identity_hash,external_id,mapping_id,time_entry_id,people_tenant_id,placements_tenant_id,previous_id,source_hash,source_ct,entry_ct,actor_user_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$tenant,$connection['id'],$connection['identity_hash'],$source['external_id'],$mappingId,$id,$peopleOwner,$placementOwner,$prior['id']??null,$hash,encryptField($json),encryptField(json_encode($after,JSON_THROW_ON_ERROR)),$actor]);$receipt=(int)$pdo->lastInsertId();
        jobdivaRefreshStaffingTimesheetTotal($tenant,$sheetId,$actor);
        $q=$pdo->prepare('INSERT INTO audit_log(tenant_id,actor_user_id,event,target_id,meta_json,created_at) VALUES(?,?,"jobdiva.time.intake",?,?,NOW())');
        $q->execute([$tenant,$actor,$id,json_encode(['receipt_id'=>$receipt,'previous_receipt_id'=>$prior['id']??null,'source_hash'=>$hash,'source_approval_state'=>'unverified'],JSON_THROW_ON_ERROR)]);
        $pdo->commit();return ['id'=>$id,'receipt_id'=>$receipt,'replayed'=>false,'source_approval_state'=>'unverified'];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

<?php
/** Reviewed source corrections. No approval, payroll, payment or ledger execution. */
declare(strict_types=1);
require_once __DIR__.'/time_current.php';

function quantaTimeRevisionScope(int $tenant, int $actor, string $id): void
{
    if ($tenant <= 0 || $actor <= 0 || $tenant !== (int)effectiveTenantIdForRequest()
        || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $id)) {
        throw new InvalidArgumentException('Choose time in the current company.');
    }
}

/** Independently of labels, a durable Quanta import cannot become a manual correction. */
function quantaTimeAssertManualCorrection(PDO $pdo, int $tenant, array $entry): void
{
    if (($entry['source']??null)!=='quanta' && ($entry['source_system']??null)!=='quanta'
        && !$pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='quanta_time_imports'")->fetchColumn()) return;
    $s=$pdo->prepare('SELECT id FROM quanta_time_imports WHERE tenant_id=? AND time_entry_id=? LIMIT 1');
    $s->execute([$tenant,(int)$entry['id']]);
    if (($entry['source']??null)==='quanta' || ($entry['source_system']??null)==='quanta' || $s->fetchColumn()) {
        throw new RuntimeException('Review the corrected source in Quanta time settings. Quanta time must keep its original source and approval history.',409);
    }
}

function quantaTimeRevisionRows(PDO $pdo, int $tenant, string $id): array
{
    $s=$pdo->prepare('SELECT * FROM quanta_time_imports WHERE tenant_id=? AND quanta_entry_id=? ORDER BY component FOR UPDATE');
    $s->execute([$tenant,$id]); $imports=$s->fetchAll(PDO::FETCH_ASSOC);
    if (!$imports) throw new RuntimeException('Import this Quanta entry before reviewing a correction.');
    $entries=[];
    foreach ($imports as $import) {
        $s=$pdo->prepare('SELECT * FROM time_entries WHERE tenant_id=? AND id=? FOR UPDATE');
        $s->execute([$tenant,(int)$import['time_entry_id']]); $row=$s->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('An original Core entry is missing. Review its history.');
        $entries[$import['component']]=$row;
    }
    return ['imports'=>$imports,'entries'=>$entries];
}

/** A correction cannot revoke a downstream commitment. Claims also have DB trigger protection. */
function quantaTimeRevisionUnconsumed(PDO $pdo, int $tenant, array $entry): void
{
    foreach (['bill','ap','payroll'] as $target) {
        foreach (['at','ref','by_user_id'] as $suffix) {
            if (!empty($entry[$target.'_extracted_'.$suffix])) throw new RuntimeException('This time was used downstream. Resolve the original document or payroll reservation before correcting it.');
        }
    }
    $s=$pdo->prepare('SELECT authority_id FROM payroll_source_claims WHERE time_tenant_id=? AND time_entry_id=? FOR UPDATE');
    $s->execute([$tenant,(int)$entry['id']]);
    if ($s->fetchColumn()) throw new RuntimeException('This time is reserved for PurePayroll. Confirm its release before correcting it.');
    $s=$pdo->prepare('SELECT id,status,entries_json FROM time_downstream_feed WHERE tenant_id=? AND period_id=? AND placement_id=? AND status<>"superseded" FOR UPDATE');
    $s->execute([$tenant,(int)$entry['period_id'],(int)$entry['placement_id']]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $bundle) {
        $ids=json_decode((string)$bundle['entries_json'],true,64,JSON_THROW_ON_ERROR)['entry_ids']??null;
        if (!is_array($ids) || in_array((int)$entry['id'],array_map('intval',$ids),true)) {
            throw new RuntimeException('This time belongs to a downstream bundle. Resolve that bundle before correcting it.');
        }
    }
}

/** Locks both original and destination weeks/periods; preview itself writes nothing. */
function quantaTimeRevisionSnapshot(PDO $pdo, int $tenant, int $actor, string $id): array
{
    if (!$pdo->inTransaction()) throw new LogicException('Source correction review requires a transaction.');
    quantaTimeRevisionScope($tenant,$actor,$id);
    $access=quantaWorkspaceForDelivery($tenant);
    quantaWorkspaceAssertCurrent($pdo,$tenant,$access,true);
    $state=quantaTimeStateFetch($access['api_key'],$access['context']['workspace']['id'],[$id]);
    if ($state['rows'][$id]['state']!=='approved') throw new RuntimeException('Correct and approve this entry in Quanta before reviewing its revision in Core.');
    $entry=quantaNormalizeEntry($state['rows'][$id]['entry'],[],'approved');
    if (empty($entry['source_approval']['approved_at']) || empty($entry['source_approval']['timesheet_id'])) {
        throw new RuntimeException('The corrected source needs its Quanta approval evidence.');
    }
    $route=quantaRouteFor(quantaRoutes($tenant,true),$entry,quantaWorkerPersonMap(quantaWorkerLinks($tenant,true)),$access);
    // Serialize daily/week capacity and corrections for this person across source entries.
    $personOwner=effectiveTenantIdForModule('people',$tenant)??$tenant;
    $s=$pdo->prepare('SELECT id FROM people WHERE tenant_id=? AND id=? AND deleted_at IS NULL FOR UPDATE');
    $s->execute([$personOwner,(int)$route['person_id']]);
    if (!$s->fetchColumn()) throw new RuntimeException('The routed person is no longer available.');
    $prior=quantaTimeRevisionRows($pdo,$tenant,$id); $active=[]; $headers=[]; $periods=[]; $oldIds=[];
    foreach ($prior['imports'] as $import) {
        if (!(int)$import['is_current']) continue;
        $component=$import['component']; $row=$prior['entries'][$component]; $oldIds[]=(int)$row['id'];
        if ($import['source_workspace_id']!==$access['context']['workspace']['id']
            || $import['worker_id']!==$entry['worker_id'] || (int)$row['person_id']!==(int)$route['person_id']) {
            throw new RuntimeException('A correction must retain the original Quanta company and worker. Review the identity mapping separately.');
        }
        if (!in_array($row['status'],['approved','pending_review','rejected','draft'],true) || !empty($row['superseded_by_id'])
            || $row['source']!=='quanta' || $row['source_system']!=='quanta' || (int)$row['payable']!==1
            || empty($row['timesheet_id']) || (int)$import['timesheet_id']!==(int)$row['timesheet_id']) {
            throw new RuntimeException('The original Core source or weekly link changed. Review its history before correcting it.');
        }
        $proof=json_decode((string)$import['source_approval_json'],true,64,JSON_THROW_ON_ERROR);
        if (!is_array($proof) || !hash_equals((string)$import['source_approval_hash'],hash('sha256',(string)$import['source_approval_json']))) {
            throw new RuntimeException('Original Quanta approval evidence is unavailable.');
        }
        if (isset($proof['core_assignment']) && !isset($entry['core_assignment'])) throw new RuntimeException('A revision cannot remove its captured Core assignment.');
        $dimensions=quantaDimensions(json_decode((string)$import['source_dimension_values_json'],true,64,JSON_THROW_ON_ERROR));
        $original=['id'=>$id,'worker_id'=>$import['worker_id'],'worksite_id'=>$import['source_worksite_id'],
            'work_date'=>$row['work_date'],'description'=>(string)($row['description']??''),'dimension_values'=>$dimensions,
            'dimension_key'=>quantaDimensionKey($dimensions)];
        if (isset($proof['core_assignment'])) $original['core_assignment']=$proof['core_assignment'];
        $oldRoute=['placement_id'=>(int)$row['placement_id'],'person_id'=>(int)$row['person_id']];
        // The hash records the original internal classification, independent of today's placement.
        $matches=false;
        foreach (['w2','internal'] as $classification) {
            $oldRoute['engagement_type']=$classification;
            [$type,$category,$billable]=quantaComponentCategory($component,$classification==='internal');
            if (hash_equals($import['source_hash'],quantaComponentHash($original,$oldRoute,$component,(float)$row['hours']))
                && $row['hour_type']===$type && $row['category']===$category && (int)$row['billable']===$billable) $matches=true;
        }
        if (!$matches) throw new RuntimeException('Core time no longer matches its recorded source revision. Review the discrepancy first.');
        quantaTimeRevisionUnconsumed($pdo,$tenant,$row);
        $active[$component]=$row;
        $headers[(int)$row['timesheet_id']]=null; $periods[(int)$row['period_id']]=null;
    }
    if (!$active) throw new RuntimeException('No current Core source components are available.');
    [$start,$end]=timeWeekBounds($entry['work_date']);
    $s=$pdo->prepare('SELECT * FROM staffing_timesheets WHERE tenant_id=? AND person_id=? AND period_start=? FOR UPDATE');
    $s->execute([$tenant,(int)$route['person_id'],$start]); $destination=$s->fetch(PDO::FETCH_ASSOC)?:null;
    if ($destination) $headers[(int)$destination['id']]=null;
    foreach ($headers as $headerId=>$_) {
        $s=$pdo->prepare('SELECT * FROM staffing_timesheets WHERE tenant_id=? AND id=? FOR UPDATE');
        $s->execute([$tenant,$headerId]); $header=$s->fetch(PDO::FETCH_ASSOC);
        if (!$header || (int)$header['person_id']!==(int)$route['person_id'] || in_array($header['status'],['payroll_ready','billing_ready','locked'],true)) {
            throw new RuntimeException('An affected week is unavailable or locked for downstream work.');
        }
        $headers[$headerId]=$header;
    }
    $s=$pdo->prepare('SELECT * FROM time_periods WHERE tenant_id=? AND start_date<=? AND end_date>=? ORDER BY start_date DESC FOR UPDATE');
    $s->execute([$tenant,$entry['work_date'],$entry['work_date']]); $destinationPeriods=$s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($destinationPeriods as $period) $periods[(int)$period['id']]=$period;
    foreach ($periods as $periodId=>$_) {
        $s=$pdo->prepare('SELECT * FROM time_periods WHERE tenant_id=? AND id=? FOR UPDATE');
        $s->execute([$tenant,$periodId]); $period=$s->fetch(PDO::FETCH_ASSOC);
        if (!$period || $period['status']!=='open') throw new RuntimeException('An affected time period is closed or locked.');
        $periods[$periodId]=$period;
    }
    $s=$pdo->prepare('SELECT id,work_date,hours FROM time_entries WHERE tenant_id=? AND person_id=? AND work_date BETWEEN ? AND ? AND status<>"superseded" ORDER BY id FOR UPDATE');
    $s->execute([$tenant,(int)$route['person_id'],$start,$end]); $capacity=$s->fetchAll(PDO::FETCH_ASSOC); $day=0; $week=0;
    foreach ($capacity as $row) {
        if (in_array((int)$row['id'],$oldIds,true)) continue;
        $week+=(float)$row['hours']; if ($row['work_date']===$entry['work_date']) $day+=(float)$row['hours'];
    }
    if ($day+$entry['hours']>24 || $week+$entry['hours']>168) throw new RuntimeException('The corrected time exceeds daily or weekly capacity.');
    $current=quantaImportLookup($tenant,[$id],true);
    [$decision]=quantaEntryDecision($entry,$route,$current,$access['context']['workspace']['id']);
    if ($decision==='imported') throw new RuntimeException('This source already matches Core. No correction is needed.');
    $snapshot=['tenant_id'=>$tenant,'actor_user_id'=>$actor,'source_entry_id'=>$id,'binding_hash'=>$access['binding_hash'],
        'workspace'=>$access['context']['workspace'],'source'=>$entry,'route'=>quantaTimeRouteEvidence($entry,$route),
        'prior'=>$prior,'headers'=>$headers,'periods'=>$periods,'destination'=>$destination,'capacity'=>$capacity];
    return ['review_hash'=>integrationDeliveryHash($snapshot),'snapshot'=>$snapshot,'entry'=>$entry,'route'=>$route,
        'access'=>$access,'observations'=>$state['observations'],'week_start'=>$start,'week_end'=>$end,'active'=>$active];
}

function quantaTimeRevisionView(array $s): array
{
    return ['source_entry_id'=>$s['source_entry_id'],'workspace'=>$s['workspace'],
        'person_number'=>$s['route']['person_number'],'placement_number'=>$s['route']['placement_number'],
        'before'=>array_values(array_map(static fn(array $row):array=>array_intersect_key($row,array_flip([
            'id','placement_id','timesheet_id','work_date','hours','category','status','approved_at','approved_by_user_id','rate_snapshot_id'])),
            array_filter($s['prior']['entries'],static fn(array $row):bool=>$row['status']!=='superseded'))),
        'after'=>['work_date'=>$s['source']['work_date'],'placement_id'=>$s['route']['placement_id'],
            'components'=>$s['source']['components'],'description'=>$s['source']['description'],
            'source_approval'=>$s['source']['source_approval'],'status'=>'pending_review']];
}

function quantaTimeRevisionPreview(int $tenant,int $actor,string $id): array
{
    $pdo=getDB(); if ($pdo->inTransaction()) throw new LogicException('Correction preview requires its own transaction.');
    $pdo->beginTransaction();
    try { $p=quantaTimeRevisionSnapshot($pdo,$tenant,$actor,$id); $pdo->rollBack();
        return ['review_hash'=>$p['review_hash']]+quantaTimeRevisionView($p['snapshot']);
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
}

function quantaTimeRevisionReceipt(array $row): array
{
    return ['id'=>(int)$row['id'],'review_hash'=>$row['review_hash'],'actor_user_id'=>(int)$row['actor_user_id'],
        'reason'=>$row['reason'],'created_at'=>$row['created_at'],'result'=>json_decode(decryptField($row['after_ct']),true,64,JSON_THROW_ON_ERROR),
        'review'=>quantaTimeRevisionView(json_decode(decryptField($row['before_ct']),true,64,JSON_THROW_ON_ERROR))];
}

function quantaTimeRevisionApply(int $tenant,int $actor,string $id,string $hash,string $reason): array
{
    quantaTimeRevisionScope($tenant,$actor,$id); $reason=trim($reason);
    if (!preg_match('/^[a-f0-9]{64}$/D',$hash) || $reason==='' || mb_strlen($reason)>500) throw new InvalidArgumentException('Provide the original review and a correction reason of 1–500 characters.');
    $request=integrationDeliveryHash([$tenant,$actor,$id,$hash,$reason]); $pdo=getDB();
    if ($pdo->inTransaction()) throw new LogicException('Correction apply requires its own transaction.');
    $pdo->beginTransaction();
    try {
        // Serialize attempts before looking up an absent unique receipt, avoiding
        // competing gap locks. Local receipt recovery does not need an active key.
        $connectionLock=$pdo->prepare('SELECT id FROM quanta_connections WHERE tenant_id=? FOR UPDATE');
        $connectionLock->execute([$tenant]); $connectionLock->fetchAll();
        $s=$pdo->prepare('SELECT * FROM quanta_time_revisions WHERE tenant_id=? AND review_hash=? FOR UPDATE');
        $s->execute([$tenant,$hash]); $receipt=$s->fetch(PDO::FETCH_ASSOC);
        if ($receipt) {
            if (!hash_equals($receipt['request_hash'],$request)) throw new RuntimeException('This review was applied by a different request. Read its history.');
            $pdo->commit(); return quantaTimeRevisionReceipt($receipt);
        }
        $p=quantaTimeRevisionSnapshot($pdo,$tenant,$actor,$id);
        if (!hash_equals($p['review_hash'],$hash)) throw new RuntimeException('The source, Core time, company connection or affected week changed. Review the correction again.');
        $entry=$p['entry']; $route=$p['route']; $before=$p['snapshot'];
        $sheet=staffingTimesheetUpsert((int)$route['person_id'],$p['week_start'],$p['week_end'],
            ['tenant_id'=>$tenant,'source'=>'quanta','source_system'=>'quanta','created_by_user_id'=>$actor]);
        $period=timeOpenPeriodIdForDate($entry['work_date']); $new=[];
        $imports=array_column($before['prior']['imports'],null,'component');
        // Retire all former components before adding replacements; no approved data is overwritten.
        foreach ($p['active'] as $row) scopedUpdate('time_entries',(int)$row['id'],['status'=>'superseded']);
        foreach ($entry['components'] as $component=>$hours) {
            [$type,$category,$billable]=quantaComponentCategory($component,($route['engagement_type']??'')==='internal');
            $new[$component]=scopedInsert('time_entries',['tenant_id'=>$tenant,'placement_id'=>(int)$route['placement_id'],
                'person_id'=>(int)$route['person_id'],'period_id'=>$period,'timesheet_id'=>(int)$sheet['id'],'work_date'=>$entry['work_date'],
                'hour_type'=>$type,'category'=>$category,'billable'=>$billable,'payable'=>1,'hours'=>$hours,'description'=>$entry['description']?:null,
                'source'=>'quanta','source_system'=>'quanta','status'=>'pending_review','correction_reason'=>$reason,'created_by_user_id'=>$actor,
                'external_id'=>hash('sha256',$id.'|'.$component.'|'.$hash)]);
            $data=['quanta_entry_id'=>$id,'component'=>$component,'worker_id'=>$entry['worker_id'],
                ...quantaSourceContext($entry,[]),...quantaTimeSourceFields($p['access'],$actor,'review'),
                'time_entry_id'=>$new[$component],'timesheet_id'=>(int)$sheet['id'],
                'source_hash'=>quantaComponentHash($entry,$route,$component,$hours),'source_timesheet_status'=>'approved',
                ...quantaTimeApprovalFields($entry),'imported_by_user_id'=>$actor];
            if (isset($imports[$component])) {
                $importId=(int)$imports[$component]['id']; scopedUpdate('quanta_time_imports',$importId,$data+['is_current'=>1]);
            } else $importId=quantaTimeImportInsert($pdo,$tenant,$data);
            quantaTimeApprovalRecord($pdo,$tenant,$importId,$actor,$p['access']);
        }
        foreach ($p['active'] as $component=>$row) {
            if (isset($new[$component])) scopedUpdate('time_entries',(int)$row['id'],['superseded_by_id'=>$new[$component]]);
            else scopedUpdate('quanta_time_imports',(int)$imports[$component]['id'],['is_current'=>0]);
        }
        $sheets=array_values(array_unique([...array_keys($before['headers']),(int)$sheet['id']]));
        foreach ($sheets as $sheetId) {
            timeReconcileTimesheetHeader($sheetId,$actor);
            staffingTimesheetRecordArtifactEvent($sheetId,'timesheet.source_corrected',$actor,
                ['source'=>'quanta','source_entry_id'=>$id,'review_hash'=>$hash,'reason'=>$reason,'new_entry_ids'=>array_values($new)],$tenant);
        }
        $after=['new_entry_ids'=>$new,'superseded_entry_ids'=>array_map(static fn(array $r):int=>(int)$r['id'],$p['active']),
            'timesheet_ids'=>$sheets,'status'=>'pending_review'];
        $pdo->prepare('INSERT INTO quanta_time_revisions(tenant_id,source_entry_id,review_hash,request_hash,actor_user_id,reason,before_ct,after_ct,source_ct) VALUES(?,?,?,?,?,?,?,?,?)')
            ->execute([$tenant,$id,$hash,$request,$actor,$reason,encryptField(integrationDeliveryJson($before)),
                encryptField(integrationDeliveryJson($after)),encryptField(integrationDeliveryJson(['entry'=>$entry,'observations'=>$p['observations']]))]);
        $revisionId=(int)$pdo->lastInsertId();
        $s=$pdo->prepare('SELECT * FROM quanta_time_revisions WHERE tenant_id=? AND id=?'); $s->execute([$tenant,$revisionId]);
        $result=quantaTimeRevisionReceipt($s->fetch(PDO::FETCH_ASSOC)); $pdo->commit(); return $result;
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
}

function quantaTimeRevisionHistory(int $tenant,int $actor,string $id,?int $before=null): array
{
    quantaTimeRevisionScope($tenant,$actor,$id);
    if ($before!==null && $before<=0) throw new InvalidArgumentException('Invalid history page.');
    $s=getDB()->prepare('SELECT * FROM quanta_time_revisions WHERE tenant_id=? AND source_entry_id=?'.($before?' AND id<?':'').' ORDER BY id DESC LIMIT 26');
    $s->execute($before?[$tenant,$id,$before]:[$tenant,$id]); $rows=$s->fetchAll(PDO::FETCH_ASSOC);
    return ['items'=>array_map('quantaTimeRevisionReceipt',array_slice($rows,0,25)),'next_before'=>count($rows)>25?(int)$rows[24]['id']:null];
}

/** Receipt recovery survives changed/withdrawn provider time and expired provider connections. */
function quantaTimeRevisionRecent(int $tenant,int $actor,?int $before=null): array
{
    quantaTimeRevisionScope($tenant,$actor,'history');
    if ($before!==null && $before<=0) throw new InvalidArgumentException('Invalid history page.');
    $s=getDB()->prepare('SELECT * FROM quanta_time_revisions WHERE tenant_id=?'.($before?' AND id<?':'').' ORDER BY id DESC LIMIT 26');
    $s->execute($before?[$tenant,$before]:[$tenant]); $rows=$s->fetchAll(PDO::FETCH_ASSOC);
    return ['items'=>array_map('quantaTimeRevisionReceipt',array_slice($rows,0,25)),'next_before'=>count($rows)>25?(int)$rows[24]['id']:null];
}

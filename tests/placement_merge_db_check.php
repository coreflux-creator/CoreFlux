<?php
/** Real canonical placement consolidation, invented records, complete outer rollback. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='coreaccounting'||getenv('COREFLUX_STANDALONE_DATABASE')!=='coreaccounting_ci'||!in_array('--confirm-disposable-fixture',$argv,true))exit(2);
putenv('COREFLUX_DATA_KEY='.base64_encode(random_bytes(32)));putenv('COREFLUX_INTEGRATION_INSTANCE_ID=bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
require_once __DIR__.'/../modules/placements/lib/merge_review.php';
require_once __DIR__.'/../core/integrations/delivery.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$owner=(int)$pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();$workspace=990711;$checks=0;$trigger='fixture_placement_merge_receipt';
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";};
$fails=static function(callable $f):bool{try{$f();return false;}catch(Throwable){return true;}};
$insert=static function(string $table,array $row)use($pdo):int{$pdo->prepare('INSERT INTO '.$table.' (`'.implode('`,`',array_keys($row)).'`) VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));return(int)$pdo->lastInsertId();};
$tables=['tenants','users','tenant_memberships','membership_module_access','tenant_module_scope','people','placements','placement_rates','time_periods','time_entries','core_record_numbers','external_entity_mappings','entity_sync_history','placement_economic_parties','placement_economic_items','placement_economic_obligations','integration_deliveries','custom_fields','custom_values','accounting_events','payroll_runs','ap_payments','audit_log'];
$state=static function()use($pdo,$tables):array{$out=[];foreach($tables as$table){$out[$table]=placementMergeRowsHash($pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC));}return$out;};
$read=static function(int $id)use($pdo):array{$q=$pdo->prepare('SELECT * FROM placements WHERE id=?');$q->execute([$id]);return$q->fetch(PDO::FETCH_ASSOC);};
$beforeFixture=$state();
try{
    if($pdo->query('SELECT id FROM tenants WHERE id=990711')->fetchColumn())throw new RuntimeException('Merge fixture already exists');
    $pdo->exec("CREATE TRIGGER {$trigger} BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF BINARY NEW.event=BINARY 'placement.identity_merged' AND @fixture_placement_merge_receipt=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic merge receipt failure'; END IF; END");
    JobdivaStoredReviewTransaction::run(static function()use($pdo,$owner,$workspace,$check,$fails,$insert,$state,$read):void{
        $insert('tenants',['id'=>$workspace,'parent_id'=>$owner,'tenant_type'=>'sub','name'=>'Synthetic placement merge','is_active'=>1,'is_simulation'=>1]);
        foreach(['people','placements','companies','staffing','accounting']as$module)$insert('tenant_module_scope',['tenant_id'=>$workspace,'module_key'=>$module,'scope_mode'=>'shared']);
        $actor=$insert('users',['tenant_id'=>$owner,'name'=>'Synthetic merge reviewer','email'=>'placement-merge-review@example.invalid','password'=>'disabled-fixture','password_hash'=>'disabled-fixture','role'=>'admin','is_active'=>1]);
        $membership=$insert('tenant_memberships',['user_id'=>$actor,'tenant_id'=>$workspace,'persona_type'=>'admin','status'=>'active']);
        $grant=$insert('membership_module_access',['membership_id'=>$membership,'module_key'=>'placements','access_level'=>'write']);
        $person=$insert('people',['tenant_id'=>$owner,'first_name'=>'Synthetic','last_name'=>'Merge worker','email_primary'=>'placement-merge-worker@example.invalid','classification'=>'w2','status'=>'active']);
        $make=static fn(string $title,string $external):int=>$insert('placements',['tenant_id'=>$owner,'person_id'=>$person,'title'=>$title,'status'=>'active','engagement_type'=>'w2','start_date'=>'2026-10-01','worksite_country'=>'US','external_id'=>$external]);
        $a=$make('Current assignment','retained-current-number');$b=$make('Historical assignment','retained-history-number');$c=$make('Next current assignment','retained-next-number');
        $rateA=$insert('placement_rates',['tenant_id'=>$owner,'placement_id'=>$a,'effective_from'=>'2026-10-01','bill_rate'=>110,'pay_rate'=>60,'approved_at'=>'2026-10-01 12:00:00']);
        $rateB=$insert('placement_rates',['tenant_id'=>$owner,'placement_id'=>$b,'effective_from'=>'2026-10-01','bill_rate'=>100,'pay_rate'=>50,'approved_at'=>'2026-10-01 12:00:00']);
        $custom=$insert('custom_fields',['tenant_id'=>$owner,'module'=>'placements','field_name'=>'synthetic_merge_private','field_label'=>'Synthetic private history','field_type'=>'text','pii'=>1]);
        foreach([[$a,'sensitive-original-A'],[$b,'sensitive-original-B']]as[$id,$value])$insert('custom_values',['tenant_id'=>$owner,'field_id'=>$custom,'record_id'=>$id,'value'=>$value]);
        $period=$insert('time_periods',['tenant_id'=>$workspace,'start_date'=>'2026-10-01','end_date'=>'2026-10-07','label'=>'Synthetic merge period']);
        foreach([[$a,$rateA],[$b,$rateB]]as[$id,$rate])$insert('time_entries',['tenant_id'=>$workspace,'placement_id'=>$id,'person_id'=>$person,'period_id'=>$period,
            'work_date'=>'2026-10-05','category'=>'regular_billable','hours'=>8,'status'=>'approved','rate_snapshot_id'=>$rate,'approved_at'=>'2026-10-08 12:00:00','approved_by_user_id'=>$actor]);
        $payload=['startId'=>'99510001','candidateId'=>'99510002','jobId'=>'99510003','startDate'=>'2026-10-01','status'=>'Active','__cf_jobdiva_source_object'=>'assignment'];
        $ma=mappingUpsert($workspace,'jobdiva','placement','99510001',$a,$payload);$mb=mappingUpsert($workspace,'jobdiva','placement','jd:99510001',$b,$payload);
        $preview=static fn()=>placementMergeReview($workspace,$actor,$a,$b);
        $requestFor=static function(array $review,?int $source=null):array{
            $fields=array_fill_keys(array_keys($review['fields']),'survivor');$fields['title']='retired';
            return ['survivor_id'=>$review['survivor_id'],'retired_id'=>$review['retired_id'],'review_token'=>$review['review_token'],
                'request_id'=>bin2hex(random_bytes(4)).'-0000-4000-8000-'.bin2hex(random_bytes(6)),'reason'=>'Reviewed duplicate of the same assignment',
                'fields'=>$fields,'primary_jobdiva_mapping_id'=>$source,'configuration_placement_id'=>$review['survivor_id'],
                'history_policy'=>'retain_original_rows','confirm'=>'MERGE_REVIEWED_PLACEMENTS'];
        };
        $before=$state();$review=$preview();$request=$requestFor($review,(int)$mb['id']);
        $check($before===$state()&&$review['dry_run'],'Merge preview changes no identities, activity, rates or audit');
        $check($review['placement_owner_id']===$owner&&$review['provider_pairs_retained']===2&&count(array_filter($review['source_options'],static fn($s)=>$s['eligible']))===2,'Shared-directory preview exposes both exact retained source choices');
        $check($review['retained_history']['time_entries']['count']===2&&$review['retained_history']['placement_rates']['count']===2,'Review includes downstream activity on both placements without moving it');
        $check(!str_contains(json_encode($review),'password')&&!str_contains(json_encode($review),'payload_snapshot'),'Public review omits authentication and raw provider snapshots');
        $check($review['custom_values_retained']===2&&!str_contains(json_encode($review),'sensitive-original'),'Private custom values participate without being exposed in merge choices');
        JobdivaStoredReviewTransaction::run(static function()use($pdo,$workspace,$actor,$membership,$request,$preview,$review,$check):void{
            $pdo->prepare('UPDATE tenant_memberships SET last_active_at="2030-01-01 12:00:00" WHERE id=?')->execute([$membership]);
            $check($preview()['review_token']===$review['review_token'],'Ordinary membership activity does not invalidate unchanged permission and record evidence');
            $check(!placementMergeApply($workspace,$actor,$request)['replayed'],'Merge remains usable after an ordinary session activity update');
        },true);
        foreach(['title','approved_rate','new_rate','time','custom_value','custom_definition','source_payload','source_workspace','permission','membership','actor','policy','different_person','archived','number_alias','missing_number','in_flight']as$case)
            JobdivaStoredReviewTransaction::run(static function()use($pdo,$owner,$workspace,$actor,$membership,$grant,$person,$a,$b,$c,$rateA,$custom,$ma,$request,$check,$fails,$state,$insert,$case):void{
                if($case==='title')$pdo->prepare('UPDATE placements SET title="Changed during review" WHERE id=?')->execute([$a]);
                if($case==='approved_rate')$pdo->prepare('UPDATE placement_rates SET pay_rate=61 WHERE id=?')->execute([$rateA]);
                if($case==='new_rate')$insert('placement_rates',['tenant_id'=>$owner,'placement_id'=>$b,'effective_from'=>'2026-11-01','bill_rate'=>120,'pay_rate'=>70]);
                if($case==='time')$pdo->prepare('UPDATE time_entries SET hours=9 WHERE tenant_id=? AND placement_id=?')->execute([$workspace,$b]);
                if($case==='custom_value')$pdo->prepare('UPDATE custom_values SET value="Changed private value" WHERE tenant_id=? AND field_id=? AND record_id=?')->execute([$owner,$custom,$b]);
                if($case==='custom_definition')$pdo->prepare('UPDATE custom_fields SET is_active=0 WHERE id=?')->execute([$custom]);
                if($case==='source_payload')$pdo->prepare('UPDATE external_entity_mappings SET content_hash=? WHERE id=?')->execute([str_repeat('c',64),$ma['id']]);
                if($case==='source_workspace')$pdo->prepare('UPDATE external_entity_mappings SET tenant_id=? WHERE id=?')->execute([$owner,$ma['id']]);
                if($case==='permission')$pdo->prepare('UPDATE membership_module_access SET access_level="read" WHERE id=?')->execute([$grant]);
                if($case==='membership')$pdo->prepare('UPDATE tenant_memberships SET status="revoked" WHERE id=?')->execute([$membership]);
                if($case==='actor')$pdo->prepare('UPDATE users SET is_active=0 WHERE id=?')->execute([$actor]);
                if($case==='policy')$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key="placements"')->execute([$workspace]);
                if($case==='different_person')$pdo->prepare('UPDATE placements SET person_id=0 WHERE id=?')->execute([$b]);
                if($case==='archived')$pdo->prepare('UPDATE placements SET deleted_at=NOW() WHERE id=?')->execute([$b]);
                if($case==='number_alias')$pdo->prepare('UPDATE core_record_numbers SET canonical_record_id=? WHERE tenant_id=? AND entity_type="placement" AND record_id=?')->execute([$c,$owner,$b]);
                if($case==='missing_number')$pdo->prepare('DELETE FROM core_record_numbers WHERE tenant_id=? AND entity_type="placement" AND record_id=?')->execute([$owner,$b]);
                if($case==='in_flight'){
                    $d=integrationDeliveryPreview($pdo,$workspace,$actor,['provider'=>'purepay','connection_ref'=>'synthetic','binding_hash'=>str_repeat('a',64),
                        'entity_type'=>'placement','entity_tenant_id'=>$owner,'entity_id'=>$b,'operation'=>'record.link','source_hash'=>str_repeat('b',64),'remote_hash'=>str_repeat('c',64)],['synthetic'=>true]);
                    $pdo->prepare('UPDATE integration_deliveries SET state="uncertain" WHERE id=?')->execute([$d['id']]);
                }
                $beforeChanged=$state();$check($fails(fn()=>placementMergeApply($workspace,$actor,$request))&&$beforeChanged===$state(),'Changed '.$case.' rejects the merge atomically');
            },true);
        JobdivaStoredReviewTransaction::run(static function()use($pdo,$workspace,$actor,$mb,$ma,$preview,$requestFor,$check,$fails,$state):void{
            $pdo->prepare('UPDATE external_entity_mappings SET payload_snapshot=? WHERE id=?')->execute([json_encode(['candidateId'=>'99510001']),$mb['id']]);
            $fresh=$preview();$candidate=array_values(array_filter($fresh['source_options'],static fn($s)=>$s['mapping_id']===(int)$mb['id']))[0];$unchanged=$state();
            $check(!$candidate['eligible']&&$fails(fn()=>placementMergeApply($workspace,$actor,$requestFor($fresh,(int)$mb['id'])))&&$unchanged===$state(),
                'A candidate-shaped source cannot become the future assignment owner through fresh merge review');
            $accepted=placementMergeApply($workspace,$actor,$requestFor($fresh,(int)$ma['id']));
            $check($accepted['provider_pairs_retained']===2,'Choosing the valid assignment owner retains the rejected historical source pair');
        },true);
        foreach(['field','source','token','confirmation','configuration','history']as$bad){
            $body=$request;
            if($bad==='field')unset($body['fields']['title']);
            if($bad==='source')$body['primary_jobdiva_mapping_id']=PHP_INT_MAX;
            if($bad==='token')$body['review_token']=str_repeat('0',64);
            if($bad==='confirmation')$body['confirm']='';
            if($bad==='configuration')$body['configuration_placement_id']=$b;
            if($bad==='history')$body['history_policy']='move';
            $check($fails(fn()=>placementMergeApply($workspace,$actor,$body))&&$before===$state(),'Invalid '.$bad.' choice cannot consolidate records');
        }
        $pdo->exec('SET @fixture_placement_merge_receipt=1');
        $check($fails(fn()=>placementMergeApply($workspace,$actor,$request))&&$before===$state(),'Required receipt failure rolls back selected values, archive and permanent aliases together');
        $pdo->exec('SET @fixture_placement_merge_receipt=0');
        $originalA=$read($a);$originalB=$read($b);$result=placementMergeApply($workspace,$actor,$request);$after=$state();
        $check(!$result['replayed']&&$read($a)['title']==='Historical assignment'&&$read($b)['deleted_at']!==null,'Confirmed merge applies the chosen title and retires only the selected duplicate');
        $check(!array_diff(placementMergeFields(),placementOwnershipFields($read($a)['coreflux_overridden_fields'])),'Explicit descriptive choices acquire Core ownership');
        $alias=placementIdentityResolve($pdo,$owner,$b,true);
        $check($alias['id']===$a&&$alias['source_id']===$b&&$alias['source_number']===$review['retired_number'],'Old permanent placement number remains a reviewed alias');
        $check(!array_diff_key(array_diff_assoc($originalA,$read($a)),array_flip(['title','coreflux_overridden_fields','updated_at']))
            &&!array_diff_key(array_diff_assoc($originalB,$read($b)),array_flip(['deleted_at','updated_at'])),'Current configuration and retired record contents stay on their original rows');
        $protected=array_diff_key($before,array_flip(['placements','core_record_numbers','audit_log']));
        $check($protected===array_intersect_key($after,$protected),'Approved rates, time, provider pairs, custom values and financial records are unchanged');
        $q=$pdo->prepare('SELECT meta_json FROM audit_log WHERE tenant_id=? AND event="placement.identity_merged"');$q->execute([$workspace]);$receipt=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
        $check($receipt['primary_jobdiva_mapping_id']===(int)$mb['id']&&$receipt['field_choices']['title']==='retired'
            &&json_decode(decryptField(base64_decode($receipt['before_rows_ct'],true)),true,512,JSON_THROW_ON_ERROR)==[$a=>$originalA,$b=>$originalB],
            'Atomic receipt retains the chosen physical source binding and encrypted original rows');
        require __DIR__.'/fixtures/placement_merge_source_checks.php';
        $pdo->prepare('UPDATE placements SET title="Later native edit" WHERE id=?')->execute([$a]);$later=$state();
        $check(placementMergeApply($workspace,$actor,$request)['replayed']&&$later===$state(),'Lost-response retry returns the receipt without repeating the merge or reversing later edits');
        $changed=$request;$changed['reason']='Different merge intent';$check($fails(fn()=>placementMergeApply($workspace,$actor,$changed))&&$later===$state(),'Request ID cannot be reused for a different merge instruction');
        $secondReview=placementMergeReview($workspace,$actor,$c,$a);$second=placementMergeApply($workspace,$actor,$requestFor($secondReview,(int)$mb['id']));
        $check($second['survivor_id']===$c&&placementIdentityResolve($pdo,$owner,$b,true)['id']===$c&&placementIdentityResolve($pdo,$owner,$a,true)['id']===$c,'Successive reviewed merge flattens every retained placement number');
        $finalSource=jobdivaPlacementWriteIdentity($pdo,jobdivaPlacementWriteContext($pdo,$workspace),'99510001',$b);
        $check((int)$finalSource['placement']['id']===$c&&(int)$finalSource['mapping']['internal_entity_id']===$b,'Successive merges refresh the newest survivor through the unchanged original source pair');
        $pdo->prepare('UPDATE membership_module_access SET access_level="none" WHERE id=?')->execute([$grant]);$revoked=$state();
        $check($fails(fn()=>placementMergeApply($workspace,$actor,$request))&&$revoked===$state(),'Receipt recovery requires current management access');
    },true);
    $check($state()===$beforeFixture,'All canonical merge fixtures roll back completely');
}finally{if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('DROP TRIGGER IF EXISTS '.$trigger);RBACResolver::resetCache();}
echo "Placement merge canonical DB: {$checks} passed\n";

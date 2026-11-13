<?php
/** Canonical disposable source-field refresh and rollback acceptance. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='coreaccounting'||getenv('COREFLUX_STANDALONE_DATABASE')!=='coreaccounting_ci'||!in_array('--confirm-disposable-fixture',$argv,true))exit(2);
require_once __DIR__.'/../core/jobdiva/joined_index_refresh.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$owner=(int)$pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();$workspace=990706;$checks=0;$triggers=[];
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";};
$failure=static function(callable $f):string{try{$f();return '';}catch(Throwable $e){return $e->getMessage();}};
$insert=static function(string $table,array $row)use($pdo):int{$pdo->prepare('INSERT INTO '.$table.' (`'.implode('`,`',array_keys($row)).'`) VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));return(int)$pdo->lastInsertId();};
$all=static function(string $table)use($pdo):array{$rows=array_map(static fn($r)=>json_encode($r,JSON_THROW_ON_ERROR),$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC));sort($rows);return$rows;};
$tables=['people','placements','placement_rates','placement_economic_parties','external_entity_mappings','entity_sync_history','core_record_numbers','integration_payload_field_index','audit_log'];
$state=static fn():array=>array_map($all,$tables);
$read=static function(string $table,int $id)use($pdo):array{$q=$pdo->prepare('SELECT * FROM '.$table.' WHERE id=?');$q->execute([$id]);return$q->fetch(PDO::FETCH_ASSOC)?:[];};
try{
    foreach(['audit_log'=>'audit','integration_payload_field_index'=>'index']as$table=>$label){
        $name='fixture_joined_'.$label;
        $guard=$label==='audit'?"BINARY NEW.event=BINARY 'jobdiva.source_fields.refreshed'":"NEW.tenant_id=990706";
        $pdo->exec("CREATE TRIGGER {$name} BEFORE INSERT ON {$table} FOR EACH ROW BEGIN IF {$guard} AND @fixture_joined_{$label}=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic required {$label} failure'; END IF; END");$triggers[]=$name;
    }
    JobdivaStoredReviewTransaction::run(static function()use($pdo,$owner,$workspace,$check,$failure,$insert,$all,$state,$read):void{
        $insert('tenants',['id'=>$workspace,'parent_id'=>$owner,'tenant_type'=>'sub','name'=>'Synthetic joined fields','is_active'=>1,'is_simulation'=>1]);
        foreach(['people','placements','companies','staffing','accounting']as$module)$insert('tenant_module_scope',['tenant_id'=>$workspace,'module_key'=>$module,'scope_mode'=>'shared']);
        $actor=$insert('users',['tenant_id'=>$owner,'name'=>'Synthetic source fields reviewer','email'=>'source-fields@example.invalid','password'=>'fixture-disabled','password_hash'=>'fixture-disabled','role'=>'admin','is_active'=>1]);
        $person=$insert('people',['tenant_id'=>$owner,'first_name'=>'Synthetic','last_name'=>'Source fields','email_primary'=>'source-fields-worker@example.invalid','classification'=>'w2','status'=>'active','external_id'=>'retained-person-number']);
        mappingUpsert($workspace,'jobdiva','person','jd:99198002',$person,['candidateId'=>'99198002']);
        $placement=$insert('placements',['tenant_id'=>$owner,'person_id'=>$person,'title'=>'Retained native title','status'=>'draft','engagement_type'=>'w2','start_date'=>'2026-10-01','external_id'=>'retained-placement-number','coreflux_overridden_fields'=>'["rates","title"]']);
        $payload=['startId'=>'99198001','candidateId'=>'99198002','jobId'=>'99198003','companyId'=>'99198004','contactId'=>'99198005','startDate'=>'2026-10-01','status'=>'Active','title'=>'Original source title'];
        $mapping=mappingUpsert($workspace,'jobdiva','placement','jd:99198001',$placement,$payload);$mappingId=(int)$mapping['id'];
        mappingUpsert($workspace,'jobdiva','jobdiva_candidate','99198002',$person,['id'=>'99198002','firstName'=>'Original source worker']);
        mappingUpsert($owner,'jobdiva','jobdiva_candidate','99198002',$person,['id'=>'99198002','firstName'=>'Foreign workspace decoy']);
        $expected=jobdivaJoinedIndexSnapshot($workspace,$mappingId);
        $check($expected['joined_payload']['_jd_candidate']['firstName']==='Original source worker','Related source fields come from the original workspace, not its shared business owner');
        $check($expected['context']['placement_owner']===$owner&&$expected['mapping']['external_id']==='jd:99198001','Source refresh retains the exact legacy provider pair and current shared directory');
        $fresh=$expected['joined_payload'];$fresh['_jd_candidate']=['id'=>'99198002','firstName'=>'Updated source worker','newField'=>'Useful field'];
        $fresh['_jd_contract']=['start_id'=>'99198001','candidate_id'=>'99198002','job_id'=>'99198003','bill_rate'=>100,'pay_rate'=>50,'currency'=>'USD'];
        foreach(['root','candidate','job','company','contact','contract','detail']as$case){
            $bad=$fresh;
            if($case==='root')$bad['startId']='99198999';
            elseif($case==='contract')$bad['_jd_contract']['candidate_id']='99198999';
            elseif($case==='detail')$bad['_jd_assignment_detail']=[['startId'=>'99198999','candidateId'=>'99198002','jobId'=>'99198003']];
            else$bad['_jd_'.$case]=['id'=>'99198999'];
            $before=$state();$error=$failure(fn()=>jobdivaJoinedIndexApply($expected,$bad,$actor));
            $check($error!==''&&$state()===$before,'Mismatched '.$case.' evidence cannot change snapshots or indexes');
        }
        foreach(['policy','mapping','person','mirror','native']as$case)JobdivaStoredReviewTransaction::run(static function()use($pdo,$workspace,$mappingId,$person,$placement,$expected,$fresh,$actor,$state,$check,$failure,$case):void{
            if($case==='policy')$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key="placements"')->execute([$workspace]);
            if($case==='mapping')$pdo->prepare('UPDATE external_entity_mappings SET sync_status="error" WHERE id=?')->execute([$mappingId]);
            if($case==='person')$pdo->prepare('UPDATE people SET status="bench" WHERE id=?')->execute([$person]);
            if($case==='native')$pdo->prepare('UPDATE placements SET title="Later native edit" WHERE id=?')->execute([$placement]);
            if($case==='mirror')$pdo->prepare('UPDATE external_entity_mappings SET payload_snapshot=? WHERE tenant_id=? AND internal_entity_type="jobdiva_candidate"')->execute([json_encode(['id'=>'99198002','firstName'=>'Concurrent source refresh']),$workspace]);
            $before=$state();$error=$failure(fn()=>jobdivaJoinedIndexApply($expected,$fresh,$actor));
            $check($error!==''&&$state()===$before,'Changed '.$case.' evidence during remote lookup rejects the stale save');
        },true);
        foreach(['index','audit']as$case){
            $before=$state();$pdo->exec('SET @fixture_joined_'.$case.'=1');$error=$failure(fn()=>jobdivaJoinedIndexApply($expected,$fresh,$actor));$pdo->exec('SET @fixture_joined_'.$case.'=0');
            $check(str_contains($error,'Synthetic required '.$case.' failure')&&$state()===$before,'Required '.$case.' failure rolls back the complete snapshot and source-field update: '.$error);
        }
        $nativeBefore=array_map($all,['people','placements','placement_rates','placement_economic_parties','core_record_numbers','entity_sync_history']);
        $savedBefore=$read('external_entity_mappings',$mappingId);$result=jobdivaJoinedIndexApply($expected,$fresh,$actor);$saved=$read('external_entity_mappings',$mappingId);
        $check($result['snapshot_changed']&&!empty($result['sub_records_indexed']['person']),'Exact returned fields and compact financial evidence are saved together');
        $check($saved['content_hash']===mappingHash(json_decode($saved['payload_snapshot'],true,512,JSON_THROW_ON_ERROR)),'Saved evidence and its content fingerprint agree');
        $keys=['tenant_id','external_id','internal_entity_type','internal_entity_id','sync_status','last_seen_at','last_synced_at'];
        $check(array_intersect_key($saved,array_flip($keys))===array_intersect_key($savedBefore,array_flip($keys)),'Enrichment retains provider identities, observation times and sync status');
        $check($nativeBefore===array_map($all,['people','placements','placement_rates','placement_economic_parties','core_record_numbers','entity_sync_history']),'Source-field refresh changes no native profile, placement, rate, approval, number or mapping history');
        $q=$pdo->prepare('SELECT sample_value FROM integration_payload_field_index WHERE tenant_id=? AND integration="jobdiva" AND entity_type="person" AND source_path="firstName"');$q->execute([$workspace]);
        $check($q->fetchColumn()==='Updated source worker','The field picker receives the freshly fetched worker fields');
        $q=$pdo->prepare('SELECT tenant_id,actor_user_id,meta_json FROM audit_log WHERE event="jobdiva.source_fields.refreshed" AND target_id=?');$q->execute([$mappingId]);$audit=$q->fetch(PDO::FETCH_ASSOC);$meta=json_decode($audit['meta_json'],true,512,JSON_THROW_ON_ERROR);
        $check((int)$audit['tenant_id']===$workspace&&(int)$audit['actor_user_id']===$actor&&$meta['directory_owner_id']===$owner&&$meta['source_external_id']==='jd:99198001','Required receipt records actor, original workspace, current owner and retained source pair');
        $before=$state();$check($failure(fn()=>jobdivaJoinedIndexApply($expected,$fresh,$actor))!==''&&$state()===$before,'A stale retry cannot replace later saved evidence');
        $check(str_contains($failure(fn()=>jobdivaRefreshJoinedIndexes($workspace,$actor)),'outside an existing transaction'),'Remote refresh refuses to hold an existing database transaction');
    },true);
    $check(!$pdo->inTransaction()&&!JobdivaStoredReviewTransaction::inScope(),'Synthetic source-field records all roll back');
}finally{if($pdo->inTransaction())$pdo->rollBack();foreach($triggers as$name)$pdo->exec('DROP TRIGGER '.$name);}
echo "$checks joined source refresh checks passed.\n";

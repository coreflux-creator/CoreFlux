<?php
/** Explicit availability decisions on canonical disposable schemas; no provider calls. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='coreaccounting'||getenv('COREFLUX_STANDALONE_DATABASE')!=='coreaccounting_ci'||!in_array('--confirm-disposable-fixture',$argv,true))exit(2);
require_once __DIR__.'/../core/jobdiva/mapping_alignment.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$owner=(int)$pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();$workspace=990704;$checks=0;$trigger=false;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";};
$failure=static function(callable $f):string{try{$f();return '';}catch(Throwable $e){return $e->getMessage();}};
$insert=static function(string $table,array $row)use($pdo):int{$pdo->prepare('INSERT INTO '.$table.' (`'.implode('`,`',array_keys($row)).'`) VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));return(int)$pdo->lastInsertId();};
$all=static function(string $table)use($pdo):array{$rows=$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);$rows=array_map(static fn($r)=>json_encode($r,JSON_THROW_ON_ERROR),$rows);sort($rows);return$rows;};
$state=static fn():array=>array_map($all,['people','placements','core_record_numbers','external_entity_mappings','entity_sync_history','audit_log']);
$read=static function(string $table,int $id)use($pdo):array{$q=$pdo->prepare('SELECT * FROM '.$table.' WHERE id=?');$q->execute([$id]);return$q->fetch(PDO::FETCH_ASSOC)?:[];};
try{
    if($pdo->query('SELECT id FROM tenants WHERE id=990704')->fetchColumn())throw new RuntimeException('Availability fixture already exists');
    $pdo->exec("CREATE TRIGGER fixture_people_availability_audit BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF COALESCE(@fixture_availability_fail,0)=1 AND NEW.event='jobdiva.people_availability_applied' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic availability receipt failure'; END IF; END");$trigger=true;
    JobdivaStoredReviewTransaction::run(static function()use($pdo,$owner,$workspace,$check,$failure,$insert,$all,$state,$read):void{
        $insert('tenants',['id'=>$workspace,'parent_id'=>$owner,'tenant_type'=>'sub','name'=>'Synthetic availability workspace','is_active'=>1,'is_simulation'=>1]);
        foreach(['people','placements','companies']as$module)$insert('tenant_module_scope',['tenant_id'=>$workspace,'module_key'=>$module,'scope_mode'=>'shared']);
        setRequestTenantId($workspace);$_SESSION['tenant_id']=$workspace;setRequestModuleScope('people');
        $actor=$insert('users',['tenant_id'=>$owner,'name'=>'Synthetic availability reviewer','email'=>'availability-review@example.invalid','password'=>'fixture-disabled','password_hash'=>'fixture-disabled','role'=>'admin','is_active'=>1]);
        $make=static function(string $status,?string $placementStatus='ended',bool $prefixed=true)use($insert,$owner,$workspace,$actor):array{
            static $source=99195000;$source++;
            $person=$insert('people',['tenant_id'=>$owner,'user_id'=>$actor,'first_name'=>'Synthetic','last_name'=>'Availability '.$source,'email_primary'=>'availability-'.$source.'@example.invalid','classification'=>'w2','status'=>$status,'external_id'=>'retained-legacy-'.$source]);
            $mapping=mappingUpsert($workspace,'jobdiva','person',($prefixed?'jd:':'').$source,$person,['candidateId'=>(string)$source,'private_marker'=>'DO-NOT-EXPOSE']);
            $placement=$placementStatus===null?null:$insert('placements',['tenant_id'=>$owner,'person_id'=>$person,'title'=>'Synthetic ended history','status'=>$placementStatus,'engagement_type'=>'w2','start_date'=>'2026-01-01','end_date'=>'2026-01-31','worksite_country'=>'US']);
            return[$person,$placement,(int)$mapping['id'],(string)$source];
        };
        [$person,$placement,$mapping,$source]=$make('active');[$bench]=$make('bench','ended',false);[$candidate]=$make('active',null);
        $employee=$insert('people_employees',['tenant_id'=>$owner,'employee_number'=>'SYNTHETIC-AVAILABILITY','user_id'=>$actor,'legal_first_name'=>'Synthetic','legal_last_name'=>'Availability','status'=>'active']);
        $insert('people_employment_links',['tenant_id'=>$owner,'person_id'=>$person,'employee_id'=>$employee,'review_id'=>str_repeat('a',64),'actor_user_id'=>$actor,'origin'=>'review_existing','reason'=>'Synthetic retained employment','review_ct'=>'synthetic-private-receipt']);
        $protected=['placements','placement_rates','people_employees','people_employment_links','users','payroll_profiles','payroll_runs','time_entries','accounting_events','accounting_journal_entries','accounting_journal_entry_lines','billing_payments','ap_payments','external_entity_mappings','entity_sync_history','core_record_numbers'];
        $protectedBefore=array_map($all,$protected);
        $review=static fn():array=>jobdivaPeopleLifecycleReview($workspace,$actor,5000);
        $body=static fn(array $review,array $decisions):array=>['confirm'=>'APPLY_REVIEWED_PEOPLE_AVAILABILITY','review_token'=>$review['review_token'],'request_id'=>substr(bin2hex(random_bytes(4)),0,8).'-0000-4000-8000-'.bin2hex(random_bytes(6)),'reason'=>'Reviewed bench availability','decisions'=>$decisions,'limit'=>5000];
        $before=$state();$r=$review();$ids=array_column($r['rows'],'id');
        $check($state()===$before&&in_array($person,$ids,true)&&in_array($bench,$ids,true)&&!in_array($candidate,$ids,true),'Review uses original-workspace links in shared People and preserves candidate-only and bench records');
        $check(!str_contains(json_encode($r,JSON_THROW_ON_ERROR),'DO-NOT-EXPOSE')&&!str_contains(json_encode($r,JSON_THROW_ON_ERROR),'@example.invalid'),'Public review omits provider payloads, email and private employment details');
        $check(jobdivaPeopleLifecycleReview($owner,$actor)['rows']===[]&&$state()===$before,'Parent workspace cannot borrow child candidate links');
        $limited=jobdivaPeopleLifecycleReview($workspace,$actor,1);
        $check($limited['truncated']&&$limited['checked']===1&&$limited['total']===3,'Bounded preview reports its incomplete coverage');
        $legacy=jobdivaMappingRepairSourcePeopleLifecycle($workspace,$actor,5000,true);
        $check($legacy['updated']===0&&$legacy['inactivated']===0&&$state()===$before,'Ordered alignment discovery never automatically retires workers');
        $check($failure(fn()=>jobdivaMappingRepairSourcePeopleLifecycle($workspace,$actor,5000,false))!==''&&$state()===$before,'Legacy unreviewed apply is rejected without writes');
        foreach(['draft','pending_start','active','on_hold']as$status)JobdivaStoredReviewTransaction::run(static function()use($pdo,$placement,$status,$review,$person,$check):void{
            $pdo->prepare('UPDATE placements SET status=? WHERE id=?')->execute([$status,$placement]);
            $check(!in_array($person,array_column($review()['rows'],'id'),true),'A '.$status.' placement blocks retirement even with a past end date');
        },true);
        JobdivaStoredReviewTransaction::run(static function()use($insert,$workspace,$person,$review,$check):void{
            $insert('placements',['tenant_id'=>$workspace,'person_id'=>$person,'title'=>'Other operating directory','status'=>'active','engagement_type'=>'w2','start_date'=>'2026-10-01','worksite_country'=>'US']);
            $check(!in_array($person,array_column($review()['rows'],'id'),true),'A live placement in another operating directory prevents retirement');
        },true);
        $original=$body($r,[$person=>'inactive',$bench=>'bench']);
        foreach(['confirm'=>null,'review_token'=>'invalid','request_id'=>'invalid','reason'=>'','decisions'=>[],'limit'=>'5000']as$key=>$value){
            $check($failure(fn()=>jobdivaPeopleLifecycleApply($workspace,$actor,array_replace($original,[$key=>$value])))!==''&&$state()===$before,'Malformed '.$key.' cannot mutate availability');
        }
        $check($failure(fn()=>jobdivaPeopleLifecycleApply($workspace,$actor,$body($r,[$candidate=>'inactive'])))!==''&&$state()===$before,'Candidate-only identity cannot be injected into the selected batch');
        $check($failure(fn()=>jobdivaPeopleLifecycleApply($workspace,$actor+1,$original))!==''&&$state()===$before,'A review is bound to the actor who inspected it');
        foreach(['people','placements','person','source','placement','number','competing','quarantine','display']as$change)JobdivaStoredReviewTransaction::run(static function()use($pdo,$workspace,$owner,$person,$placement,$mapping,$source,$original,$actor,$review,$state,$check,$failure,$change):void{
            if(in_array($change,['people','placements'],true))$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key=?')->execute([$workspace,$change]);
            if($change==='person')$pdo->prepare('UPDATE people SET status="bench" WHERE id=?')->execute([$person]);
            if($change==='source')$pdo->prepare('UPDATE external_entity_mappings SET last_error="Changed source evidence" WHERE id=?')->execute([$mapping]);
            if($change==='placement')$pdo->prepare('UPDATE placements SET status="active" WHERE id=?')->execute([$placement]);
            if($change==='number')$pdo->prepare('UPDATE core_record_numbers SET canonical_record_id=? WHERE tenant_id=? AND entity_type="person" AND record_id=?')->execute([$person+1,$owner,$person]);
            if($change==='competing')mappingUpsert($owner,'jobdiva','person',$source,$person,['candidateId'=>$source]);
            if($change==='quarantine')$pdo->prepare('UPDATE external_entity_mappings SET sync_status="error" WHERE id=?')->execute([$mapping]);
            if($change==='display')$pdo->prepare('UPDATE people SET external_id="jd:99999999" WHERE id=?')->execute([$person]);
            $before=$state();$check($failure(fn()=>jobdivaPeopleLifecycleApply($workspace,$actor,$original))!==''&&$state()===$before,'Changed '.$change.' evidence invalidates a pending decision without partial writes');
            if(in_array($change,['number','competing','quarantine','display'],true))$check(!in_array($person,array_column($review()['rows'],'id'),true),'Fresh '.$change.' conflict requires identity review before availability changes');
        },true);
        $pdo->exec('SET @fixture_availability_fail=1');$message=$failure(fn()=>jobdivaPeopleLifecycleApply($workspace,$actor,$body($r,[$person=>'inactive',$bench=>'inactive'])));$pdo->exec('SET @fixture_availability_fail=0');
        $check(str_contains($message,'Synthetic availability receipt failure')&&$state()===$before,'Late receipt failure rolls back the entire multi-person batch and all per-person audits');
        $personBefore=$read('people',$person);$result=jobdivaPeopleLifecycleApply($workspace,$actor,$original);$personAfter=$read('people',$person);
        $check($result['updated']===1&&$result['inactivated']===1&&$result['unchanged']===1&&$read('people',$bench)['status']==='bench','Only the selected changed person is inactivated; deliberate bench status remains');
        foreach(['status','updated_at']as$key){unset($personBefore[$key],$personAfter[$key]);}
        $check($personBefore===$personAfter&&$protectedBefore===array_map($all,$protected),'Availability preserves all person facts, employment, sign-in, historical placements, provider IDs, numbers and financial records');
        $q=$pdo->prepare('SELECT actor_user_id,meta_json FROM audit_log WHERE tenant_id=? AND event="people.availability_reviewed" AND target_id=?');$q->execute([$workspace,$person]);$audit=$q->fetch(PDO::FETCH_ASSOC);$meta=json_decode($audit['meta_json'],true,512,JSON_THROW_ON_ERROR);
        $check((int)$audit['actor_user_id']===$actor&&$meta['directory_owner_id']===$owner&&$meta['source_external_id']==='jd:'.$source&&$meta['before']['status']==='active'&&$meta['after']['status']==='inactive'&&$meta['reason']===$original['reason'],'Required audit retains original source workspace, shared owner, actor, exact identity and reason');
        $before=$state();$check(jobdivaPeopleLifecycleApply($workspace,$actor,$original)['replayed']&&$state()===$before,'Identical retry recovers the receipt without another update or audit');
        $pdo->prepare('UPDATE people SET status="bench" WHERE id=?')->execute([$person]);$before=$state();
        $check(jobdivaPeopleLifecycleApply($workspace,$actor,$original)['replayed']&&$state()===$before,'Lost-response recovery never overwrites a later native availability decision');
        $check($failure(fn()=>jobdivaPeopleLifecycleApply($workspace,$actor,array_replace($original,['reason'=>'Changed meaning'])))!==''&&$state()===$before,'A request ID cannot be reused for a different decision');
        $fresh=$review();$saved=jobdivaPeopleLifecycleApply($workspace,$actor,$body($fresh,[$bench=>'inactive']));
        $check($saved['updated']===1&&$read('people',$bench)['status']==='inactive','A fresh review can explicitly retire an existing bench worker');
        $check($protectedBefore===array_map($all,$protected),'No provider, employment, portal, time, payroll, payment or accounting state is changed');
    },true);
    $check(!$pdo->inTransaction()&&!JobdivaStoredReviewTransaction::inScope(),'Disposable availability fixture rolls back all synthetic business records');
}finally{if($pdo->inTransaction())$pdo->rollBack();if($trigger)$pdo->exec('DROP TRIGGER fixture_people_availability_audit');setRequestTenantId(null);clearRequestModuleScope();}
echo "$checks people availability checks passed.\n";

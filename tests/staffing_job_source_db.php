<?php
/** Canonical MariaDB schema, synthetic jobs, required audit and outer rollback. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('GITHUB_ACTIONS')!=='true'||getenv('CI')!=='true'||getenv('DB_NAME')!=='coreaccounting_ci')exit(2);
require_once __DIR__.'/../core/jobdiva/sync.php';
require_once __DIR__.'/../core/integrations/field_map_apply.php';
require_once __DIR__.'/../modules/staffing/lib/job_write.php';
require_once __DIR__.'/../core/migrate.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$checks=[];$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks[]=$label;};
$fails=static function(callable $f,?int $code=null):bool{try{$f();return false;}catch(Throwable $e){return$code===null||(int)$e->getCode()===$code;}};
$read=static function(int $id)use($pdo):array{$q=$pdo->prepare('SELECT * FROM staffing_jobs WHERE id=?');$q->execute([$id]);return$q->fetch(PDO::FETCH_ASSOC)?:[];};
$all=static fn(string $t):array=>$pdo->query('SELECT * FROM '.$t.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
$ddl=$pdo->query('SHOW CREATE TABLE staffing_jobs')->fetch(PDO::FETCH_NUM)[1];
foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../modules/staffing/migrations/009_job_source_ownership.sql'))as$sql)$pdo->exec($sql);
$check($pdo->query('SHOW CREATE TABLE staffing_jobs')->fetch(PDO::FETCH_NUM)[1]===$ddl,'Job ownership migration replays without schema drift');
$parent=990711;$child=990712;$shadow=false;
if($pdo->query('SELECT id FROM tenants WHERE id IN(990711,990712)')->fetchColumn())throw new RuntimeException('Job ownership fixture already exists');
$protected=[$all('accounting_events'),$all('accounting_journal_entries'),$all('time_entries'),$all('placements')];$pdo->beginTransaction();
try{
    $pdo->exec('INSERT INTO tenants(id,name,is_active,is_simulation,tenant_type) VALUES(990711,"Synthetic job source parent",1,1,"master")');
    $pdo->exec('INSERT INTO tenants(id,name,is_active,is_simulation,tenant_type,parent_id) VALUES(990712,"Synthetic job source child",1,1,"sub",990711)');
    $pdo->exec('INSERT INTO users(tenant_id,name,email,role,is_active,password,password_hash) VALUES(990711,"Synthetic job reviewer","job-source@example.invalid","admin",1,NULL,NULL)');$actor=(int)$pdo->lastInsertId();
    foreach(['shared/shared','shared/isolated','isolated/isolated']as$mode){
        [$companies,$jobs]=explode('/',$mode);$owner=$jobs==='shared'?$parent:$child;
        foreach(['companies'=>$companies,'placements'=>$jobs,'staffing'=>$jobs]as$module=>$sharing)$pdo->prepare('INSERT INTO tenant_module_scope(tenant_id,module_key,scope_mode) VALUES(?,?,?) ON DUPLICATE KEY UPDATE scope_mode=VALUES(scope_mode)')->execute([$child,$module,$sharing]);
        $ext='job-source-'.$mode;$source=['title'=>'Source role '.$mode,'status'=>'closed','country'=>'United States','city'=>'Hamilton','description'=>'Source description','openDate'=>'11/01/2026'];
        $job=staffingJobEnsureFromJobDivaPayload($child,$ext,$source,$actor);$id=(int)$job['id'];
        mappingBindStable($child,'jobdiva','staffing_job',$ext,$id,$source,'pull',$actor);
        $check((int)$job['tenant_id']===$owner&&$job['location_country']==='US'&&$job['opened_at']==='2026-11-01'&&staffingJobSourceState($job)['owned']===[],$mode.': source job uses the current directory and recognized country/date values');
        $again=staffingJobEnsureFromJobDivaPayload($child,$ext,$source,$actor);
        $check($again===$job,$mode.': exact source replay preserves record identity, values and ownership revision');
        staffingJobEnsureFromJobDivaPayload($child,$ext,['description'=>'New description'],$actor);$partial=$read($id);
        $check($partial['title']===$source['title']&&$partial['status']==='closed'&&$partial['location_country']==='US',$mode.': missing source title, status and country do not invent replacements or reopen a job');
        $review=staffingJobSourceReview($child,$id);
        $saved=staffingJobSaveNative($child,$id,['title'=>'Reviewed Core role '.$mode,'location_city'=>'','status'=>'closed'],$actor,$review['edit_revision']);
        $check($fails(fn()=>staffingJobSaveNative($child,$id,['title'=>'Stale form'],$actor,$review['edit_revision']),409)&&$read($id)===$saved,$mode.': an outdated native form cannot overwrite a reviewed edit');
        $check($fails(fn()=>staffingJobSaveNative($child,$id,['title'=>'No revision'],$actor),409),$mode.': updates require the reviewed job version');
        $incoming=['title'=>'Incoming changed role','status'=>'active','city'=>'Princeton','department'=>'Engineering'];
        staffingJobEnsureFromJobDivaPayload($child,$ext,$incoming,$actor);$held=$read($id);$state=staffingJobSourceState($held);
        $check($held['title']===$saved['title']&&$held['status']==='closed'&&$held['location_city']===''&&$held['department']==='Engineering'&&$state['proposed']['location_city']==='Princeton',$mode.': Core edits and intentional clears survive while unowned fields follow source');
        $mapped=staffingJobMappedSourceWrite($child,$owner,$id,['location_city'=>'Mapped city','department'=>'Mapped department']);$mappedRow=$read($id);
        $check($mapped['written']===1&&$mapped['skipped']===['location_city']&&$mappedRow['location_city']===''&&$mappedRow['department']==='Mapped department'&&staffingJobSourceState($mappedRow)['proposed']['location_city']==='Mapped city',$mode.': generalized mapping retains owned values and saves its proposal through the same boundary');
        if($jobs==='isolated'){
            $pdo->prepare('INSERT INTO tenant_integration_field_map(tenant_id,integration,entity_type,internal_field,external_field,source_path,target_module,target_table,target_column,linked_entity,transform,enabled) VALUES(?,"jobdiva","staffing_job",?,"mappedCity","mappedCity","staffing","staffing_jobs","location_city","self","none",1)')->execute([$child,'job_city_'.$companies]);
            $mapped=integrationFieldMapApplyAll($child,'jobdiva','staffing_job',['mappedCity'=>'Mapping Studio city'],['self'=>$id]);
            $check($mapped['errors']===[]&&$mapped['written']===0&&$read($id)['location_city']===''&&staffingJobSourceState($read($id))['proposed']['location_city']==='Mapping Studio city',$mode.': the actual mapping dispatcher cannot bypass Core field ownership');
            $pdo->prepare('DELETE FROM tenant_integration_field_map WHERE tenant_id=? AND entity_type="staffing_job"')->execute([$child]);
            staffingJobMappedSourceWrite($child,$owner,$id,['location_city'=>'Mapped city']);
        }
        $review=staffingJobSourceReview($child,$id);$decision=staffingJobSourceDecide($child,$id,['location_city'],'use_once',$review['review_token'],$actor);
        $check(!$decision['replayed']&&$read($id)['location_city']==='Mapped city'&&in_array('location_city',staffingJobSourceState($read($id))['owned'],true),$mode.': use once applies the observed value and retains Core ownership');
        $newReview=staffingJobSourceReview($child,$id);staffingJobSaveNative($child,$id,['location_city'=>'Later Core edit'],$actor,$newReview['edit_revision']);
        $replay=staffingJobSourceDecide($child,$id,['location_city'],'use_once',$review['review_token'],$actor);
        $check($replay['replayed']&&$read($id)['location_city']==='Later Core edit',$mode.': retry returns the original receipt without reapplying old values');
        $check($fails(fn()=>staffingJobSourceDecide($child,$id,['title'],'use_once',$review['review_token'],$actor),409),$mode.': a completed review cannot be reused for different fields');
        $review=staffingJobSourceReview($child,$id);staffingJobSourceDecide($child,$id,['location_city'],'follow_source',$review['review_token'],$actor);
        staffingJobEnsureFromJobDivaPayload($child,$ext,['city'=>'Next source city'],$actor);
        $check($read($id)['location_city']==='Next source city'&&!in_array('location_city',staffingJobSourceState($read($id))['owned'],true),$mode.': explicit follow decision permits future updates from the exact source');
        $review=staffingJobSourceReview($child,$id);staffingJobSaveNative($child,$id,['location_city'=>'Core again'],$actor,$review['edit_revision']);staffingJobEnsureFromJobDivaPayload($child,$ext,['city'=>'Held again'],$actor);
        $check($read($id)['location_city']==='Core again',$mode.': a later Core edit reclaims a following field');
        $review=staffingJobSourceReview($child,$id);staffingJobSourceDecide($child,$id,['department'],'keep_core',$review['review_token'],$actor);staffingJobEnsureFromJobDivaPayload($child,$ext,['department'=>'Held department'],$actor);
        $check($read($id)['department']==='Mapped department',$mode.': keep Core protects the current value from later refreshes');
        $review=staffingJobSourceReview($child,$id);staffingJobEnsureFromJobDivaPayload($child,$ext,['title'=>'Newest proposal'],$actor);
        $check($fails(fn()=>staffingJobSourceDecide($child,$id,['title'],'use_once',$review['review_token'],$actor),409),$mode.': a changed source proposal invalidates the old review');
        foreach([['country'=>'Unknownland'],['status'=>'banana'],['remotePolicy'=>'sometimes'],['openDate'=>'2026-02-30']]as$bad){$before=$read($id);$check($fails(fn()=>staffingJobEnsureFromJobDivaPayload($child,$ext,$bad,$actor))&&$read($id)===$before,$mode.': invalid source '.key($bad).' is refused without partial changes');}
        $pdo->prepare('INSERT INTO staffing_jobs(tenant_id,title,status,source_system,external_id) VALUES(?,"Historic role","closed","jobdiva",?)')->execute([$owner,$ext.'-legacy']);$legacy=(int)$pdo->lastInsertId();
        staffingJobEnsureFromJobDivaPayload($child,$ext.'-legacy',['title'=>'Legacy proposal','status'=>'open'],$actor);$legacyRow=$read($legacy);
        $check($legacyRow['title']==='Historic role'&&$legacyRow['status']==='closed'&&count(staffingJobSourceState($legacyRow)['owned'])===12,$mode.': pre-ownership jobs default to Core control with proposals retained');
        if($owner===$parent){$other=staffingJobSourceReview($parent,$id);$check($other['source']===null&&$other['proposed']===[]&&$fails(fn()=>staffingJobSourceDecide($parent,$id,['title'],'use_once',$other['review_token'],$actor)),$mode.': sharing a job does not share another workspace provider evidence or review authority');}
        else$check($fails(fn()=>staffingJobSourceReview($parent,$id),404),$mode.': another directory cannot inspect the private job');
        $before=$read($id);$review=staffingJobSourceReview($child,$id);
        $pdo->exec('CREATE TEMPORARY TABLE audit_log (tenant_id INT)');$shadow=true;
        $check($fails(fn()=>staffingJobSaveNative($child,$id,['title'=>'Audit must fail'],$actor,$review['edit_revision']))&&$read($id)===$before,$mode.': missing required audit rolls back native values and ownership');
        $check($fails(fn()=>staffingJobEnsureFromJobDivaPayload($child,$ext,['description'=>'Audit source must fail'],$actor))&&$read($id)===$before,$mode.': missing required audit rolls back source values and proposals');
        $pdo->exec('DROP TEMPORARY TABLE audit_log');$shadow=false;
    }
    $check([$all('accounting_events'),$all('accounting_journal_entries'),$all('time_entries'),$all('placements')]===$protected,'Job source reviews never change financial, time or placement records');
    echo json_encode(['passed'=>true,'checks'=>count($checks),'labels'=>$checks,'provider_calls'=>0,'financial_execution'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
}finally{if($shadow)$pdo->exec('DROP TEMPORARY TABLE audit_log');if($pdo->inTransaction())$pdo->rollBack();}

<?php
/** Canonical-schema aliases and history, with invented records and complete rollback. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='coreaccounting'||getenv('COREFLUX_STANDALONE_DATABASE')!=='coreaccounting_ci'||!in_array('--confirm-disposable-fixture',$argv,true))exit(2);
require_once __DIR__.'/../core/jobdiva/sync.php';
require_once __DIR__.'/../core/integrations/purepay_links.php';
require_once __DIR__.'/../core/integrations/purepay_financial_line_links.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$owner=(int)$pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();$foreign=990710;$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";};
$fails=static function(callable $f):bool{try{$f();return false;}catch(Throwable){return true;}};
$insert=static function(string $table,array $row)use($pdo):int{$pdo->prepare('INSERT INTO '.$table.' (`'.implode('`,`',array_keys($row)).'`) VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));return(int)$pdo->lastInsertId();};
$state=static function()use($pdo):array{$out=[];foreach(['people','placements','placement_rates','core_record_numbers','external_entity_mappings','entity_sync_history','time_entries','accounting_events','accounting_journal_entries','payroll_runs','ap_payments']as$table){$rows=array_map(static fn($r)=>json_encode($r,JSON_THROW_ON_ERROR),$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC));sort($rows);$out[$table]=$rows;}return$out;};
$before=$state();$instance='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$check($fails(fn()=>placementIdentityResolve($pdo,$owner,1,true)),'Locked identity lookup requires a transaction');
try{
    $pdo->beginTransaction();
    $insert('tenants',['id'=>$foreign,'name'=>'Synthetic foreign placement directory','tenant_type'=>'master','is_active'=>1,'is_simulation'=>1]);
    $person=$insert('people',['tenant_id'=>$owner,'first_name'=>'Synthetic','last_name'=>'Placement history','email_primary'=>'placement-history@example.invalid','status'=>'active','classification'=>'w2']);
    $make=static fn(int $tenant,string $title):int=>$insert('placements',['tenant_id'=>$tenant,'person_id'=>$person,'title'=>$title,'status'=>'active','engagement_type'=>'w2','start_date'=>'2026-10-01','worksite_country'=>'US']);
    $a=$make($owner,'Historical assignment');$b=$make($owner,'Current assignment');$c=$make($owner,'Next current assignment');$other=$make($foreign,'Foreign assignment');
    $rate=$insert('placement_rates',['tenant_id'=>$owner,'placement_id'=>$a,'effective_from'=>'2026-10-01','bill_rate'=>100,'pay_rate'=>50,'approved_at'=>'2026-10-01 12:00:00']);
    mappingUpsert($owner,'jobdiva','placement','synthetic-retained-start',$a,['startId'=>'synthetic-retained-start']);
    $physical=placementIdentityResolve($pdo,$owner,$a,true);$number=$physical['source_number'];
    $check(!$physical['merged']&&$physical['id']===$a&&$physical['source_placement']===$physical['placement'],'Current identity contains one physical placement');
    $check(placementNumberIdentityResolve($pdo,$owner,$number,true)===$physical,'Number lookup resolves its stored physical row');
    $check(placementIdentityResolve($pdo,$foreign,$a)===null&&placementNumberIdentityResolve($pdo,$foreign,$number)===null,'Foreign directory cannot borrow a physical row or permanent number');
    $check(placementIdentityResolve($pdo,$owner,PHP_INT_MAX)===null,'Unknown physical placement remains unavailable');
    foreach(['1','PL-0','PL-01','PL-1junk','P-1','pl-1']as$bad)$check($fails(fn()=>placementNumberIdentityResolve($pdo,$owner,$bad)),'Malformed or wrong-kind number is rejected: '.$bad);
    $expected=purepayCoreLinkSource($pdo,$instance,$owner,'placement',$number);
    $check(purepayCorePlacementHistorySource($pdo,$instance,$owner,$a,true)===$expected,'Unmerged historical source preserves the existing delivery evidence shape');
    $pdo->prepare('UPDATE placements SET deleted_at=NOW() WHERE id=?')->execute([$a]);
    $check(placementIdentityResolve($pdo,$owner,$a,true)===null,'Ordinary archive is not a merge');
    coreRecordNumbersMerge($pdo,$owner,'placement',$b,$a,null);
    $merged=placementIdentityResolve($pdo,$owner,$a,true);
    $check($merged['merged']&&$merged['id']===$b&&$merged['source_id']===$a&&$merged['source_number']===$number,'Reviewed alias exposes current and original physical identities separately');
    $snapshot=$state();
    $source=purepayCorePlacementHistorySource($pdo,$instance,$owner,$a,true);
    $check($source['entity_id']===$a&&$source['reference']===$expected['reference']&&$source['record']===$expected['record']
        &&$source['current_placement']['number']===$merged['placement_number'],'Historical provider source never substitutes survivor fields or permanent number');
    $target=purepayFinancialLineTarget($pdo,$instance,['people'=>$owner,'placements'=>$owner],$number,true);
    $check($target['placement_id']===$a&&$target['title']==='Historical assignment'&&$target['current_placement']===$source['current_placement'],'Financial attribution retains the historical row and separate current context');
    $check($fails(fn()=>purepayCoreLinkSource($pdo,$instance,$owner,'placement',$number)),'An alias does not authorize a new operational link on the retired placement');
    $check($state()===$snapshot,'Historical resolution changes no rate, provider pair, time, payment, payroll or ledger row');
    foreach(['active_source','missing_number','missing_alias_stamp','foreign_target','deleted_target','missing_target_number','target_alias','target_alias_stamp']as$case){
        $pdo->exec('SAVEPOINT corrupt_identity');
        if($case==='active_source')$pdo->prepare('UPDATE placements SET deleted_at=NULL WHERE id=?')->execute([$a]);
        if($case==='missing_number')$pdo->prepare('DELETE FROM core_record_numbers WHERE tenant_id=? AND entity_type="placement" AND record_id=?')->execute([$owner,$a]);
        if($case==='missing_alias_stamp')$pdo->prepare('UPDATE core_record_numbers SET aliased_at=NULL WHERE tenant_id=? AND entity_type="placement" AND record_id=?')->execute([$owner,$a]);
        if($case==='foreign_target')$pdo->prepare('UPDATE core_record_numbers SET canonical_record_id=? WHERE tenant_id=? AND entity_type="placement" AND record_id=?')->execute([$other,$owner,$a]);
        if($case==='deleted_target')$pdo->prepare('UPDATE placements SET deleted_at=NOW() WHERE id=?')->execute([$b]);
        if($case==='missing_target_number')$pdo->prepare('DELETE FROM core_record_numbers WHERE tenant_id=? AND entity_type="placement" AND record_id=?')->execute([$owner,$b]);
        if($case==='target_alias')$pdo->prepare('UPDATE core_record_numbers SET canonical_record_id=? WHERE tenant_id=? AND entity_type="placement" AND record_id=?')->execute([$c,$owner,$b]);
        if($case==='target_alias_stamp')$pdo->prepare('UPDATE core_record_numbers SET aliased_at=NOW() WHERE tenant_id=? AND entity_type="placement" AND record_id=?')->execute([$owner,$b]);
        $corrupt=$state();$check($fails(fn()=>placementIdentityResolve($pdo,$owner,$a,true))&&$state()===$corrupt,'Incomplete or conflicting '.$case.' fails without changes');
        $pdo->exec('ROLLBACK TO SAVEPOINT corrupt_identity');$pdo->exec('RELEASE SAVEPOINT corrupt_identity');
    }
    $pdo->prepare('UPDATE placements SET deleted_at=NOW() WHERE id=?')->execute([$b]);
    coreRecordNumbersMerge($pdo,$owner,'placement',$c,$b,null);
    $flattened=placementIdentityResolve($pdo,$owner,$a,true);
    $check($flattened['id']===$c&&$flattened['source_id']===$a&&placementIdentityResolve($pdo,$owner,$b,true)['id']===$c,'Successive merges keep every historical number and resolve the current flattened family');
    $q=$pdo->prepare('SELECT internal_entity_id FROM external_entity_mappings WHERE tenant_id=? AND source_system="jobdiva" AND external_id="synthetic-retained-start"');$q->execute([$owner]);
    $check((int)$q->fetchColumn()===$a,'Successive number merges do not rebind the original provider ID');
    $q=$pdo->prepare('SELECT placement_id,pay_rate FROM placement_rates WHERE id=?');$q->execute([$rate]);$r=$q->fetch(PDO::FETCH_ASSOC);
    $check((int)$r['placement_id']===$a&&(float)$r['pay_rate']===50.0,'Historical approved rate remains on its original placement');
}finally{if($pdo->inTransaction())$pdo->rollBack();}
$check($before===$state(),'Canonical identity fixture rolls back all business and financial changes');
echo "Placement identity canonical DB: {$checks} passed\n";

<?php
/** Real SQL routing migration, review, rollback and competing transaction snapshots. */
declare(strict_types=1);
define('COREFLUX_QROUTES_FIXTURE',true);require __DIR__.'/quanta_time_provenance_mariadb.php';
require_once __DIR__.'/../core/quanta/routes.php';
require_once __DIR__.'/../deploy/quanta_assignment_route_upgrade.php';
$routeBaseline=quantaPilotRouteState($pdo);$routeSql=file_get_contents(__DIR__.'/../core/migrations/188_quanta_assignment_routes.sql');
$routeUpgradeChecks=0;$routeCheck=static function(bool $ok,string $label)use(&$routeUpgradeChecks):void{
    if(!$ok)throw new RuntimeException($label);$routeUpgradeChecks++;echo "PASS $label\n";
};
$routeCheck($fails(fn()=>quantaPilotRouteApply($pdo,$routeSql."\n-- changed",$routeBaseline))&&quantaPilotRouteState($pdo)===$routeBaseline,'unreviewed deployment migration makes no schema change');
$observer->exec('SET SESSION lock_wait_timeout=1');$observer->exec('SET SESSION innodb_lock_wait_timeout=1');
$routeBlocked=static function(string $sql)use($observer):bool{
    try{$observer->query($sql);return false;}catch(PDOException $error){return (int)($error->errorInfo[1]??0)===1205;}
};
$lockedTables=quantaPilotRouteLock($pdo);
$routeCheck(in_array('time_entries',$lockedTables,true)&&$routeBlocked('SELECT COUNT(*) FROM quanta_time_routes'),'deployment fence blocks concurrent route access before DDL');
$routeApplied=quantaPilotRouteApply($pdo,$routeSql,$routeBaseline);
$routeCheck(quantaPilotRouteVerify($pdo,$routeBaseline)===$routeApplied,'actual deployment helper preserves route rows and verifies replacement schema');
$routeCheck($routeBlocked('SELECT COUNT(*) FROM quanta_time_routes')&&$routeBlocked('UPDATE time_entries SET hours=hours WHERE id=1'),'native route and business-write fences survive actual DDL');
$migrate('188_quanta_assignment_routes.sql');
$routeCheck(quantaPilotRouteState($pdo)===$routeApplied,'deployment migration replay is exact');
$pdo->exec("UPDATE quanta_time_routes SET quanta_assignment_id='cfa_".str_repeat('a',64)."',updated_at=updated_at");
$routeCheck($fails(fn()=>quantaPilotRouteRestore($pdo,$routeBaseline)),'schema rollback refuses a newly captured assignment');
$pdo->exec("UPDATE quanta_time_routes SET quanta_assignment_id='',updated_at=updated_at");
$pdo->exec("UPDATE quanta_time_routes SET effective_to='2026-10-31',updated_at=updated_at");
$routeCheck($fails(fn()=>quantaPilotRouteRestore($pdo,$routeBaseline)),'schema rollback refuses changed legacy route data');
$pdo->exec('UPDATE quanta_time_routes SET effective_to=NULL,updated_at=updated_at');
quantaPilotRouteRestore($pdo,$routeBaseline);
$routeCheck(quantaPilotRouteState($pdo)===$routeBaseline,'schema rollback restores exact legacy columns indexes and rows');
$pdo->exec("ALTER TABLE quanta_time_routes ADD COLUMN quanta_assignment_id VARCHAR(68) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''");
quantaPilotRouteRestore($pdo,$routeBaseline);
$routeCheck(quantaPilotRouteState($pdo)===$routeBaseline,'partial column-only migration recovers exactly');
quantaPilotRouteRestore($pdo,$routeBaseline);
$routeCheck(quantaPilotRouteState($pdo)===$routeBaseline,'already recovered schema replay changes nothing');
$routeCheck($routeBlocked('SELECT COUNT(*) FROM quanta_time_routes')&&$routeBlocked('UPDATE time_entries SET hours=hours WHERE id=1'),'native fences survive schema recovery');
quantaPilotRouteUnlock($pdo);
$routeCheck((int)$observer->query('SELECT COUNT(*) FROM quanta_time_routes')->fetchColumn()===count($routeBaseline['rows']),'explicit release restores route access');
echo "$routeUpgradeChecks route deployment/recovery checks passed\n";
putenv('COREFLUX_INTEGRATION_INSTANCE_ID='.$instance);setRequestTenantId(1);
$pdo->exec('ALTER TABLE core_record_numbers ADD canonical_record_id BIGINT');$pdo->exec('UPDATE core_record_numbers SET canonical_record_id=record_id');
$pdo->exec('ALTER TABLE people ADD status VARCHAR(20) DEFAULT "active"');
$pdo->exec('ALTER TABLE placements ADD client_id BIGINT NULL, ADD end_client_company_id BIGINT NULL, ADD worksite_city VARCHAR(100) NULL, ADD worksite_state VARCHAR(100) NULL, ADD worksite_country VARCHAR(2) NULL');
$pdo->exec('ALTER TABLE placements ADD branch VARCHAR(160),ADD department VARCHAR(120),ADD service_line VARCHAR(120),ADD cost_center VARCHAR(120),ADD workers_comp_class VARCHAR(64),ADD remote_policy VARCHAR(6)');
$pdo->exec('CREATE TABLE placement_corp_details(tenant_id BIGINT,placement_id BIGINT,ap_vendor_id BIGINT NULL)');
$pdo->exec('CREATE TABLE audit_log(id BIGINT AUTO_INCREMENT PRIMARY KEY,tenant_id BIGINT,actor_user_id BIGINT,event VARCHAR(128),meta_json LONGTEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
$before=$pdo->query('SELECT * FROM quanta_time_routes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);$times=$pdo->query('SELECT * FROM time_entries ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$migrate('188_quanta_assignment_routes.sql');$migrated=$pdo->query('SELECT * FROM quanta_time_routes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$passed=0;$restored=$migrated;foreach($restored as &$row)unset($row['quanta_assignment_id']);unset($row);
$check($restored===$before&&$migrated[0]['quanta_assignment_id']==='','migration preserves original legacy route and timestamps');
$migrate('188_quanta_assignment_routes.sql');$check($pdo->query('SELECT * FROM quanta_time_routes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$migrated,'migration replay preserves all route data');
foreach([43,44,45] as $id){$pdo->exec('INSERT INTO placements(id,tenant_id,person_id,status,engagement_type,start_date,title) VALUES('.$id.',1,19,"active","w2","2026-10-01","Same client assignment")');
    $pdo->exec('INSERT INTO core_record_numbers VALUES(1,"placement",'.$id.',"PL-'.$id.'",'.$id.')');}
$owners=quantaTimeControlOwners(1);$records=[];$assignmentIds=[];
foreach([42,43,44,45] as $id){$s=quantaMetadataSource($pdo,$instance,$owners,'placement',$id);$assignmentIds[$id]=quantaCoreRemoteId('workspace-a',$s['reference']);
    $records[$assignmentIds[$id]]=$s['assignment']+['id'=>$assignmentIds[$id],'tenant_id'=>'workspace-a','worker_id'=>'worker-1',
        'person_link_id'=>quantaCoreRemoteId('workspace-a',$s['person']),'worksite_id'=>'site-1','version'=>1];
    unset($records[$assignmentIds[$id]]['assignment_context']); // Legacy reporting omissions do not block routing.
}
$quantaContext=$context;$race=false;$mismatch=false;$writes=0;
$GLOBALS['__quanta_transport']=static function($method,$url)use($quantaContext,&$records,&$race,&$mismatch,$observer):array{
    $path=parse_url($url,PHP_URL_PATH);
    if(str_ends_with($path,'/context'))$body=$quantaContext;
    elseif(str_ends_with($path,'/workers'))$body=['items'=>[['id'=>'worker-1','active'=>true]],'has_more'=>false,'next_cursor'=>null];
    elseif(str_ends_with($path,'/worksites'))$body=['items'=>[['id'=>'site-1','active'=>true]],'has_more'=>false,'next_cursor'=>null];
    elseif(str_ends_with($path,'/dimensions'))$body=['items'=>[],'has_more'=>false,'next_cursor'=>null];
    elseif(str_contains($path,'/person-links/')){$assignment=reset($records);$body=['id'=>$assignment['person_link_id'],'tenant_id'=>'workspace-a','worker_id'=>'worker-1','person'=>$assignment['person']];}
    elseif(str_contains($path,'/assignments/')){
        $body=$records[basename($path)];if($mismatch)$body['title']='Pending provider edit';
        if($race){$observer->exec('UPDATE placements SET title="Concurrent Core change" WHERE id=45');$race=false;}
    }else throw new RuntimeException('Unexpected route provider read');
    return ['status'=>200,'body'=>json_encode($body)];
};
$GLOBALS['__quanta_core_write_transport']=static function()use(&$writes){$writes++;throw new RuntimeException('Routing must not write to Quanta');};
$base=['worker_id'=>'worker-1','worksite_id'=>'site-1','dimension_values'=>[],'effective_from'=>'2026-10-01','effective_to'=>null];
$bound=static fn(int $id)=>$base+['placement_id'=>$id,'quanta_assignment_id'=>$assignmentIds[$id]];
$save=static fn(array $rows)=>quantaSaveRoutes(1,7,$rows);
$countRoutes=static fn()=>(int)getDB()->query('SELECT COUNT(*) FROM quanta_time_routes')->fetchColumn();
$check($fails(fn()=>quantaSaveRoutes(2,7,[$bound(43)]))&&$countRoutes()===1,'another company cannot configure these routes');
$check($fails(fn()=>$save([$base+['placement_id'=>43]]))&&$countRoutes()===1,'legacy overlap is still rejected');
$pdo->exec('UPDATE placements SET status="draft" WHERE id=43');
$activationError=null;try{$save([$bound(43)]);}catch(InvalidArgumentException $e){$activationError=$e->getMessage();}
$check(str_contains($activationError??'','Activate the placement')&&$countRoutes()===1,'draft placement cannot receive a route or activate implicitly');
$pdo->exec('UPDATE placements SET status="active" WHERE id=43');
$check($fails(fn()=>$save([array_replace($bound(43),['quanta_assignment_id'=>$assignmentIds[44]])]))&&$countRoutes()===1,'another captured assignment cannot select the local placement');
$mismatch=true;$check($fails(fn()=>$save([$bound(43)])),'unpublished metadata blocks captured routing');$mismatch=false;
$observer->beginTransaction();$observer->query('SELECT COUNT(*) FROM quanta_time_routes')->fetchColumn();
$saved=$save([$bound(43),$bound(44)]);
$check(count($saved)===3&&$countRoutes()===3,'distinct captured assignments coexist with the original legacy route at the same start');
$primary=$pdo;$pdo=$observer;
try{
    $conflict=null;try{$save([array_replace($bound(43),['effective_from'=>'2026-10-08'])]);}catch(InvalidArgumentException $e){$conflict=$e->getMessage();}
    $check(str_contains($conflict??'','Overlapping routes'),'stale transaction snapshot still sees conflicting routes through locking reads');
}
finally{$observer->rollBack();$pdo=$primary;}
$check($countRoutes()===3,'conflicting stale writer leaves the original three routes intact');
$check($fails(fn()=>$save([$bound(43)]))&&$countRoutes()===3,'replaying route creation does not duplicate a route');
$r43=array_values(array_filter($saved,static fn($r)=>(int)$r['placement_id']===43))[0];
$check($fails(fn()=>$save([array_replace($bound(44),['id'=>(int)$r43['id']])])),'captured route identity cannot be retargeted');
$check($fails(fn()=>$save([array_replace($bound(43),['id'=>(int)$r43['id'],'quanta_assignment_id'=>''])])),'captured route cannot turn into a legacy fallback');
$legacyId=(int)$migrated[0]['id'];
$check($fails(fn()=>$save([$bound(42)+['id'=>$legacyId]])),'existing legacy route is not silently relabelled as captured history');
$snapshot=$pdo->query('SELECT * FROM quanta_time_routes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$check($fails(fn()=>$save([$bound(45),$base+['placement_id'=>43]])),'later invalid route rolls back the whole reviewed batch');
$check($snapshot===$pdo->query('SELECT * FROM quanta_time_routes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'failed batch leaves no partial route');
$race=true;$check($fails(fn()=>$save([$bound(45)]))&&$countRoutes()===3,'concurrent Core source mutation fails the locked proof');
$pdo->exec('UPDATE placements SET title="Same client assignment" WHERE id=45');
$pdo->beginTransaction();quantaWorkerLinks(1,true);$primary=$pdo;$pdo=$observer;
try{
    $lockedFailure=null;try{$save([$bound(45)]);}catch(PDOException $e){$lockedFailure=(int)($e->errorInfo[1]??0);}
    $check($lockedFailure===1205,'another route writer waits on the same worker identity lock');
}
finally{$pdo=$primary;$pdo->rollBack();}
$saved=$save([$bound(45)]);$check(count($saved)===4,'the reviewed route can save after the competing worker lock is released');
$r45=array_values(array_filter($saved,static fn($r)=>(int)$r['placement_id']===45))[0];
$edited=$save([array_replace($bound(45),['id'=>(int)$r45['id'],'effective_to'=>'2026-10-31'])]);
$check(count($edited)===4,'date editing preserves the captured assignment and original route ID');
quantaDeleteRoute(1,7,(int)$r45['id']);$check($countRoutes()===3,'route deletion uses the shared serialization path');
$check($writes===0&&$times===$pdo->query('SELECT * FROM time_entries ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'routing changes no provider records, time, approvals, rates or financial extraction');
unset($GLOBALS['__quanta_transport'],$GLOBALS['__quanta_core_write_transport']);echo "$passed assignment route MariaDB checks passed\n";

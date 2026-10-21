<?php
/** Real MariaDB guards and authority creation against the existing disposable input fixture. */
declare(strict_types=1);
define('COREFLUX_AUTHORITY_FIXTURE',true);
require __DIR__.'/purepay_payroll_inputs_mariadb.php';
require_once __DIR__.'/../core/integrations/purepay_payroll_authority.php';
$passed=0;$posts=0;$mode=null;$context['payroll_input_contract_version']=PUREPAY_PAYROLL_INPUT_VERSION;
$GLOBALS['__purepay_transport']=static function($method,$url,$headers,$json)use(&$context,&$receipts,&$posts):array {
    $path=substr(parse_url($url,PHP_URL_PATH),strlen('/api/v1'.PUREPAY_CORE_PATH));
    if($method==='GET' && $path==='/context')return ['status'=>200,'body'=>json_encode($context)];
    if($method==='POST' && $path==='/payroll/inputs') {
        $posts++;$body=json_decode($json,true,64,JSON_THROW_ON_ERROR);$hash=integrationDeliveryHash($body);$id='cpi_'.integrationDeliveryHash(['org-a',$hash]);
        $receipts[$id]=['id'=>$id,'organization_id'=>'org-a','content_hash'=>$hash,'state'=>'received_for_review','payroll_created'=>false,'source_time_reserved'=>false,'components'=>count($body['components'])];
        return ['status'=>200,'body'=>json_encode($receipts[$id])];
    }
    throw new RuntimeException('Unexpected authority transport');
};
$migrate=static function(string $path)use($pdo):void{foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../'.$path))as$sql)$pdo->exec($sql);};
$reserve=static fn(array $packet,string $request,?callable $audit=null):array=>purepayPayrollAuthorityReserve($pdo,1,7,$instance,$owners,(int)$packet['delivery']['id'],$packet['delivery']['review_hash'],$request,$audit);
$request=str_repeat('a',64);
$check($fails(fn()=>$reserve($p,$request)),'source authority defaults off');
putenv('COREFLUX_PUREPAY_PAYROLL_AUTHORITY=1');
$check($fails(fn()=>$reserve($p,$request)),'source authority refuses absent database guards');
$migrate('core/migrations/166_payroll_source_authority.sql');
// Match the real module columns omitted from the minimal input-only fixture.
$pdo->exec('ALTER TABLE time_entries ADD custom_category_id BIGINT NULL,ADD approved_by_user_id BIGINT NULL,ADD approved_via VARCHAR(32) NULL,ADD source_ref_id BIGINT NULL,ADD payroll_extracted_by_user_id BIGINT NULL,ADD bill_extracted_at DATETIME NULL,ADD bill_extracted_ref BIGINT NULL,ADD description VARCHAR(500) NULL');
$pdo->exec('ALTER TABLE placement_rates ADD approved_by_user_id BIGINT NULL,ADD bill_rate DECIMAL(10,4) NULL');
$migrate('modules/time/migrations/011_payroll_source_authority.sql');
$migrate('modules/placements/migrations/008_payroll_source_authority.sql');
purepayPayrollAuthoritySchema($pdo);
// Retire only the synthetic lost-attempt case left by the included fixture.
$pdo->exec('UPDATE integration_deliveries SET state="cancelled" WHERE provider="purepayroll" AND state="uncertain"');
putenv('COREFLUX_PUREPAY_PAYROLL_INPUT_DELIVERY=1');
$p=$preview();
$check($fails(fn()=>$reserve($p,$request)),'unconfirmed input cannot reserve approved source');
$send($p);$initial=$pdo->query('SELECT * FROM time_entries ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$pdo->exec('UPDATE time_entries SET hours=7 WHERE id=1');
$check($fails(fn()=>$reserve($p,$request)) && (int)$pdo->query('SELECT COUNT(*) FROM payroll_source_claims')->fetchColumn()===0,'source drift before reservation leaves no claims');
$pdo->exec('UPDATE time_entries SET hours=5 WHERE id=1');
$pdo->exec('UPDATE placement_rates SET pay_rate=55 WHERE id=1');
$check($fails(fn()=>$reserve($p,$request)),'rate drift before reservation invalidates the reviewed input');
$pdo->exec('UPDATE placement_rates SET pay_rate=52.5000 WHERE id=1');
$pdo->exec('UPDATE time_entries SET status="rejected" WHERE id=1');
$check($fails(fn()=>$reserve($p,$request)),'approval revoked after input delivery cannot authorize a draft');
$pdo->exec('UPDATE time_entries SET status="approved" WHERE id=1');
$pdo->exec('UPDATE time_entries SET payroll_extracted_ref=44 WHERE id=1');
$check($fails(fn()=>$reserve($p,$request)),'Core extraction after input delivery prevents provider reservation');
$pdo->exec('UPDATE time_entries SET payroll_extracted_ref=NULL WHERE id=1');
$check($fails(fn()=>purepayPayrollAuthorityReserve($pdo,2,7,$instance,$owners,(int)$p['delivery']['id'],$p['delivery']['review_hash'],$request)),'another company cannot reserve the input delivery');
$check($fails(fn()=>$reserve($p,$request,static function(){throw new RuntimeException('Synthetic audit failure');})) && (int)$pdo->query('SELECT COUNT(*) FROM payroll_source_claims')->fetchColumn()===0 && (int)$pdo->query('SELECT COUNT(*) FROM payroll_source_authorities')->fetchColumn()===0,'audit failure rolls back the complete authority and claim batch');
$observer->beginTransaction();
$check((int)$observer->query('SELECT COUNT(*) FROM payroll_source_claims')->fetchColumn()===0,'observer establishes an older repeatable-read snapshot');
$authority=$reserve($p,$request,static function()use($observer,$check){
    $observer->exec('SET innodb_lock_wait_timeout=1');
    try {$observer->exec('UPDATE time_entries SET hours=99 WHERE id=1');$check(false,'source lock held during reservation');}
    catch(PDOException $e){$check(($e->errorInfo[1]??null)===1205,'source lock excludes concurrent edits until reservation commits');}
});
$check($authority['state']==='held' && $authority['source_time_reserved'] && $authority['components']===2,'one authority durably reserves every approved component');
$check((int)$pdo->query('SELECT COUNT(*) FROM payroll_source_claims')->fetchColumn()===2,'source claims retain time owner independently from placement owner');
$check($pdo->query('SELECT * FROM time_entries ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$initial,'reservation does not edit approved time or mark it extracted');
$denied=static function(PDO $db,string $sql):bool {try{$db->exec($sql);return false;}catch(PDOException $e){return $e->getCode()==='45000' && str_contains($e->getMessage(),'reserved for PurePayroll');}};
$check($denied($observer,'UPDATE time_entries SET hours=9 WHERE id=1'),'older transaction snapshot cannot bypass a newly committed claim');
$observer->rollBack();
$check($reserve($p,$request)===$authority && $posts===1,'same reviewed request replays without new claims or provider IO');
$check($fails(fn()=>$reserve($p,str_repeat('b',64))) && (int)$pdo->query('SELECT COUNT(*) FROM payroll_source_authorities')->fetchColumn()===1,'second request cannot claim the same hours and rolls back its authority');
$check($fails(fn()=>purepayPayrollAuthorityRead($pdo,2,$authority['id'])),'authority read is company scoped');
foreach([
 'hours=6','category="OT_billable"','status="rejected"','approved_at=NULL','approved_by_user_id=99',
 'rate_snapshot_id=2','superseded_by_id=9','source_system="manual"','payable=0',
 'payroll_extracted_at=NOW()','payroll_extracted_ref=42','person_id=99','placement_id=2','tenant_id=7'
]as$change)$check($denied($pdo,'UPDATE time_entries SET '.$change.' WHERE id=1'),'held source rejects '.$change);
$check($denied($pdo,'DELETE FROM time_entries WHERE id=1'),'held time cannot be deleted');
foreach(['pay_rate=99','pay_rate_unit="day"','currency="CAD"','ot_multiplier=2','approved_at=NULL','approved_by_user_id=99','placement_id=2','tenant_id=22']as$change)
    $check($denied($pdo,'UPDATE placement_rates SET '.$change.' WHERE id=1'),'held rate rejects '.$change);
$check($denied($pdo,'DELETE FROM placement_rates WHERE id=2'),'every reserved placement rate resists deletion');
$pdo->exec('UPDATE time_entries SET bill_extracted_at=NOW(),bill_extracted_ref=42,description="Billing note" WHERE id=1');
$pdo->exec('UPDATE placement_rates SET bill_rate=90 WHERE id=1');
$check((int)$pdo->query('SELECT bill_extracted_ref FROM time_entries WHERE id=1')->fetchColumn()===42,'independent billing changes remain available');
$check($reserve($p,$request)===$authority,'billing updates do not alter the committed payroll authority');
$pdo->exec('UPDATE time_entries SET hours=91 WHERE id=3');
$check((string)$pdo->query('SELECT hours FROM time_entries WHERE id=3')->fetchColumn()==='91.00','another time owner remains editable');
$check($denied($pdo,'UPDATE time_entries SET hours=55 WHERE id IN (1,3)') && (string)$pdo->query('SELECT hours FROM time_entries WHERE id=3')->fetchColumn()==='91.00','mixed bulk update rolls back without partial cross-company edits');
$pdo->exec('UPDATE payroll_source_authorities SET created_at="2000-01-01 00:00:00"');
$check($denied($pdo,'UPDATE time_entries SET hours=8 WHERE id=1'),'elapsed time never releases a source reservation');
foreach(['core/migrations/166_payroll_source_authority.sql','modules/time/migrations/011_payroll_source_authority.sql','modules/placements/migrations/008_payroll_source_authority.sql']as$path)$migrate($path);
$check((int)$pdo->query('SELECT COUNT(*) FROM payroll_source_claims')->fetchColumn()===2 && $denied($pdo,'UPDATE time_entries SET hours=8 WHERE id=1'),'migration replay preserves active reservations and guards');
unset($GLOBALS['__purepay_transport']);putenv('COREFLUX_PUREPAY_PAYROLL_AUTHORITY');putenv('COREFLUX_PUREPAY_PAYROLL_INPUT_DELIVERY');
echo "PurePayroll source authority MariaDB: {$passed} passed\n";

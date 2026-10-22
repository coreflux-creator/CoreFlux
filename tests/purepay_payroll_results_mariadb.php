<?php
/** Real encrypted observation persistence, replay, audit rollback and scope checks. */
declare(strict_types=1);
define('COREFLUX_RESULT_FIXTURE',true);
require __DIR__.'/purepay_payroll_inputs_mariadb.php';
require_once __DIR__.'/../core/integrations/purepay_payroll_results.php';
require_once __DIR__.'/purepay_payroll_result_fixture.php';
foreach(['166_payroll_source_authority.sql','167_payroll_provider_results.sql']as$file)foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../core/migrations/'.$file))as$sql)$pdo->exec($sql);
$passed=0;$mode=null;$audits=0;$reads=0;
$delivery=$pdo->query('SELECT * FROM integration_deliveries WHERE provider="purepayroll" AND state="delivered" ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
[$delivery,$payload]=purepayPayrollInputEnvelope($pdo,1,(int)$delivery['id'],$delivery['review_hash'],$instance,$owners);
$packet=$payload['body'];$content=integrationDeliveryHash($packet);$aid='cpa_'.str_repeat('e',64);
$body=['contract_version'=>PUREPAY_PAYROLL_AUTHORITY_VERSION,'id'=>$aid,'instance_id'=>$instance,'organization_id'=>'org-a','time_tenant_id'=>1,
    'input_id'=>'cpi_'.integrationDeliveryHash(['org-a',$content]),'content_hash'=>$content,'components'=>count($packet['components']),'source_time_reserved'=>true];
$connection=purepayCoreConnection($pdo,1);
$pdo->prepare('INSERT INTO payroll_source_authorities(id,tenant_id,request_id,input_delivery_id,connection_id,organization_id,instance_id,time_tenant_id,input_id,content_hash,payload_ct,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')->execute(
    [$aid,1,str_repeat('f',64),$delivery['id'],$connection['id'],'org-a',$instance,1,$body['input_id'],$content,encryptField(integrationDeliveryJson(['authority'=>$body,'owners'=>integrationDeliveryCanonical($owners),'packet'=>$packet])),7]);
foreach($packet['components']as$c)$pdo->prepare('INSERT INTO payroll_source_claims(time_tenant_id,time_entry_id,authority_id,placement_tenant_id,rate_snapshot_id) VALUES(?,?,?,?,?)')->execute([1,$c['source']['time_entry_id'],$aid,$owners['placements'],$c['rate']['snapshot_id']]);
$wire=payrollResultFixture($body,$packet);$context['payroll_input_contract_version']=PUREPAY_PAYROLL_INPUT_VERSION;$context['payroll_authority_contract_version']=PUREPAY_PAYROLL_AUTHORITY_VERSION;
$GLOBALS['__purepay_transport']=static function($method,$url,$headers,$json)use(&$context,&$wire,&$mode,&$reads,$observer,$aid):array {
    $path=substr(parse_url($url,PHP_URL_PATH),strlen('/api/v1'.PUREPAY_CORE_PATH));
    if($method!=='GET')throw new RuntimeException('Result read attempted a provider write');
    if($path==='/context')return ['status'=>200,'body'=>json_encode($context)];
    if($path!=='/payroll/authorities/'.$aid.'/result')throw new RuntimeException('Unexpected result URL');
    $reads++;
    if($mode==='lost')throw new RuntimeException('Synthetic lost read response');
    if($mode==='absent')return ['status'=>404,'body'=>'{}'];
    if($mode==='rotated')$observer->exec('UPDATE purepay_core_connections SET revision=revision+1 WHERE tenant_id=1');
    $value=$wire;
    if($mode==='bad')$value['lines'][0]['components'][0]['input_component']['placement']['number']='PL-999';
    return ['status'=>200,'body'=>json_encode($value)];
};
$review=purepayCoreReview($pdo,1,$instance);purepayCoreConfirm($pdo,1,7,$instance,$review['review_hash'],'org-a');
$audit=static function()use(&$audits){$audits++;};
$refresh=static fn(?callable $callback=null)=>purepayPayrollResultRefresh($pdo,1,7,$instance,$owners,(int)$delivery['id'],$delivery['review_hash'],$aid,$callback);
$history=static fn(?int $before=null)=>purepayPayrollResultHistory($pdo,1,$instance,$owners,(int)$delivery['id'],$delivery['review_hash'],$before);
$claims=$pdo->query('SELECT * FROM payroll_source_claims ORDER BY time_entry_id')->fetchAll(PDO::FETCH_ASSOC);
$time=$pdo->query('SELECT * FROM time_entries ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$check($history()['items']===[] && count($history()['authorities'])===1,'history exposes the exact source reservation without fetching provider figures');
$check($reads===0,'history has no provider result call');
$saved=$refresh($audit);$check(!$saved['replayed'] && $saved['id']>0 && $audits===1,'first observation is saved and audited');
$dbrow=$pdo->query('SELECT * FROM payroll_provider_results')->fetch(PDO::FETCH_ASSOC);
$check(!str_contains($dbrow['payload_ct'],'"gross"') && integrationDeliveryHash(json_decode((string)decryptField($dbrow['payload_ct']),true))===integrationDeliveryHash($wire),'sensitive result is stored encrypted and intact');
$again=$refresh($audit);$check($again['replayed'] && $again['id']===$saved['id'] && $audits===1,'same result reuses its immutable observation without duplicate audit');
$check(count($history()['items'])===1 && $history()['items'][0]['result']['totals']===$wire['totals'],'reload retrieves validated saved result');
$changed=$wire;$changed['lines'][0]['amounts']['bonus']='10.00';
foreach(['gross','net','funding_required']as$k){$changed['lines'][0]['amounts'][$k]=purepayPayrollResultAmount(purepayPayrollResultCents($changed['lines'][0]['amounts'][$k])+1000);$changed['totals'][$k]=$changed['lines'][0]['amounts'][$k];}
$changed['totals']['bonus']='10.00';$wire=payrollResultRehash($changed);
$check($fails(fn()=>$refresh(static function(){throw new RuntimeException('Synthetic audit failure');})) && (int)$pdo->query('SELECT COUNT(*) FROM payroll_provider_results')->fetchColumn()===1,'audit failure rolls back a new observation');
$changed=$refresh($audit);$check($changed['id']!==$saved['id'] && !$changed['replayed'],'changed payroll figures append another version');
$check($history($changed['id'])['items'][0]['id']===$saved['id'],'history cursor returns the earlier observation');
foreach(['bad','lost','absent']as$mode)$check($fails(fn()=>$refresh()) && (int)$pdo->query('SELECT COUNT(*) FROM payroll_provider_results')->fetchColumn()===2,'unconfirmed '.$mode.' result adds no observation');
$mode='rotated';$check($fails(fn()=>$refresh()) && (int)$pdo->query('SELECT COUNT(*) FROM payroll_provider_results')->fetchColumn()===2,'connection changed during read discards result');
$mode=null;$review=purepayCoreReview($pdo,1,$instance);purepayCoreConfirm($pdo,1,7,$instance,$review['review_hash'],'org-a');
$check($fails(fn()=>purepayPayrollResultRefresh($pdo,2,7,$instance,$owners,(int)$delivery['id'],$delivery['review_hash'],$aid)),'foreign company cannot refresh a result');
$check($fails(fn()=>purepayPayrollResultHistory($pdo,1,$instance,['time'=>2,'people'=>10,'placements'=>20],(int)$delivery['id'],$delivery['review_hash'])),'changed source owner cannot read result history');
$check($fails(fn()=>purepayPayrollResultRefresh($pdo,1,7,'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',$owners,(int)$delivery['id'],$delivery['review_hash'],$aid)),'foreign installation is rejected');
$check($pdo->query('SELECT * FROM payroll_source_claims ORDER BY time_entry_id')->fetchAll(PDO::FETCH_ASSOC)===$claims && $pdo->query('SELECT * FROM time_entries ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$time,'result reads never release reservations or mutate source time');
$check(purepayPayrollAuthorityRead($pdo,1,$aid)['state']==='held','allocated result never releases Core authority');
$bad=encryptField('{"unexpected":"changed"}');$pdo->prepare('UPDATE payroll_provider_results SET payload_ct=? WHERE id=?')->execute([$bad,$saved['id']]);
$check($fails(fn()=>$history()),'damaged saved evidence is refused on reload');
foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../core/migrations/167_payroll_provider_results.sql'))as$sql)$pdo->exec($sql);
$check((int)$pdo->query('SELECT COUNT(*) FROM payroll_provider_results')->fetchColumn()===2,'migration replay retains observations');
unset($GLOBALS['__purepay_transport']);
echo "PurePayroll result MariaDB: {$passed} passed\n";

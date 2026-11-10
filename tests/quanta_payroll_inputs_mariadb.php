<?php
/** Actual Quanta provenance and current-state checks through the held PurePayroll input path. */
declare(strict_types=1);
define('COREFLUX_QPAYROLL_FIXTURE',true);
require __DIR__.'/quanta_time_provenance_mariadb.php';
require_once __DIR__.'/../core/integrations/purepay_payroll_inputs.php';
putenv('COREFLUX_INTEGRATION_INSTANCE_ID='.$instance);
setRequestTenantId(1);
$quantaContext=$context;
foreach(['158_integration_deliveries.sql','162_integration_delivery_receipt.sql','164_purepay_core_connection.sql']as$file)$migrate($file);
$pdo->exec('ALTER TABLE people ADD status VARCHAR(20) DEFAULT "active"');
$pdo->exec('ALTER TABLE placements ADD client_id BIGINT DEFAULT 1');
$pdo->exec('ALTER TABLE core_record_numbers ADD canonical_record_id BIGINT');
$pdo->exec('UPDATE core_record_numbers SET canonical_record_id=record_id');
$pdo->exec('ALTER TABLE time_entries ADD approved_at DATETIME NULL, ADD payable TINYINT DEFAULT 1, ADD payroll_extracted_ref BIGINT NULL');
$pdo->exec('UPDATE time_entries SET approved_at=NOW(),status=IF(id IN(1,2),"approved","pending_review")');
$pdo->exec('CREATE TABLE placement_rates(id BIGINT PRIMARY KEY,tenant_id BIGINT,placement_id BIGINT,pay_rate DECIMAL(10,4),pay_rate_unit VARCHAR(16),currency CHAR(3),ot_multiplier DECIMAL(4,2),approved_at DATETIME)');
$pdo->exec('INSERT INTO placement_rates VALUES(81,1,42,52.5000,"hour","USD",1.50,NOW())');
$remote=$validRemote;$mode='current';$requests=0;$posts=0;$passed=0;
$GLOBALS['__quanta_transport']=static function(string $method,string $url)use(&$mode,&$requests,$remote,$quantaContext):array{
    if(str_ends_with(parse_url($url,PHP_URL_PATH),'/context'))return ['status'=>200,'body'=>json_encode($quantaContext)];
    $requests++;parse_str(parse_url($url,PHP_URL_QUERY),$q);
    $item=$mode==='unavailable'?['id'=>'entry-1','state'=>'unavailable']:['id'=>'entry-1','state'=>'approved','entry'=>$remote];
    return ['status'=>200,'body'=>json_encode(['version'=>1,'workspace_id'=>'workspace-a','request_id'=>$q['request_id'],
        'checked_at'=>gmdate('Y-m-d\TH:i:s\Z'),'data'=>[$item]])];
};
$context=['organization'=>['id'=>'org-a','name'=>'Synthetic A'],'contract_version'=>PUREPAY_CORE_VERSION,'link_contract_version'=>PUREPAY_CORE_LINK_VERSION,
    'payroll_input_contract_version'=>PUREPAY_PAYROLL_INPUT_VERSION,'capabilities'=>['read:coreflux','read:payroll','write:payroll_inputs'],'execution_side_effects'=>false];
$GLOBALS['__purepay_transport']=static function($method,$url,$headers,$json)use($context,&$posts):array{
    if($method==='GET' && str_ends_with(parse_url($url,PHP_URL_PATH),'/context'))return ['status'=>200,'body'=>json_encode($context)];
    if($method==='POST' && str_ends_with(parse_url($url,PHP_URL_PATH),'/payroll/inputs')){
        $posts++;$body=json_decode($json,true,64,JSON_THROW_ON_ERROR);$hash=integrationDeliveryHash($body);
        return ['status'=>200,'body'=>json_encode(['id'=>'cpi_'.integrationDeliveryHash(['org-a',$hash]),'organization_id'=>'org-a',
            'content_hash'=>$hash,'state'=>'received_for_review','payroll_created'=>false,'source_time_reserved'=>false,'components'=>count($body['components'])])];
    }
    throw new RuntimeException('Unexpected provider operation');
};
purepayCoreConnect($pdo,1,'synthetic-input-key',$instance);
$review=purepayCoreReview($pdo,1,$instance);purepayCoreConfirm($pdo,1,7,$instance,$review['review_hash'],'org-a');
$connection=purepayCoreConnection($pdo,1);
foreach([['person',19,'P-19','emp-a'],['placement',42,'PL-42','assignment-a']]as[$kind,$id,$number,$native]){
    $ref=integrationRecordReference($instance,1,$kind,$number);$body=['reference'=>$ref,'provider_id'=>$native,'expected_record_version'=>str_repeat('a',64)];
    $ctx=['provider'=>'purepay','connection_ref'=>(string)$connection['id'],'binding_hash'=>str_repeat('a',64),'entity_type'=>$kind,'entity_tenant_id'=>1,
        'entity_id'=>$id,'operation'=>'record.link','source_hash'=>str_repeat('b',64),'remote_hash'=>str_repeat('c',64)];
    $r=integrationDeliveryPreview($pdo,1,7,$ctx,['organization_id'=>'org-a','body'=>$body]);
    $pdo->prepare('UPDATE integration_deliveries SET state="delivered",remote_id=?,remote_receipt_id=? WHERE id=?')
        ->execute([$native,'cfl_'.integrationDeliveryHash(['org-a',$ref]),$r['id']]);
}
$owners=['time'=>1,'people'=>1,'placements'=>1];
$preview=static fn():array=>purepayPayrollInputPreview($pdo,1,7,$instance,$owners,'P-19','2026-10-01','2026-10-15');
$before=$pdo->query('SELECT * FROM time_entries ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$p=$preview();
$check(count($p['packet']['components'])===2&&$requests===1&&$posts===0,'verified Quanta components build a held payroll review without a provider write');
$components=$p['packet']['components'];
$check($components[0]['source']['source_workspace_id']==='workspace-a'&&$components[0]['source']['source_entry_id']==='entry-1'
    &&$components[0]['source']['hours']==='6.50'&&$components[1]['source']['hours']==='1.50'
    &&$components[1]['rate']['multiplier']==='1.50','original workspace, source IDs, split hours and locked rate survive the payroll packet');
$check($preview()['delivery']['id']===$p['delivery']['id']&&$requests===2,'fresh source-check nonces do not change the durable payroll review identity');
putenv('COREFLUX_PUREPAY_PAYROLL_INPUT_DELIVERY=1');
$send=static fn():array=>purepayPayrollInputSend($pdo,1,7,$instance,$owners,(int)$p['delivery']['id'],$p['delivery']['review_hash']);
$mode='unavailable';$check($fails($send)&&$posts===0,'source approval revoked after review blocks provider transmission');
$mode='current';$result=$send();
$check($result['state']==='delivered'&&$posts===1,'current Quanta approval permits one held input receipt');
$check($send()===$result&&$posts===1,'held input replay does not transmit twice');
$check($before===$pdo->query('SELECT * FROM time_entries ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'held receipt preserves source time and leaves financial extraction unset');
unset($GLOBALS['__quanta_transport'],$GLOBALS['__purepay_transport']);
putenv('COREFLUX_PUREPAY_PAYROLL_INPUT_DELIVERY');putenv('COREFLUX_INTEGRATION_INSTANCE_ID');setRequestTenantId(null);
echo "Quanta to PurePayroll MariaDB: {$passed} passed\n";

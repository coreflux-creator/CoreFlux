<?php
/** Read actual synthetic Quanta API output on stdin. No database/network access. */
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/quanta/time_control_delivery.php';
$packet=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);
$context=$packet['context'];$body=quantaTimeControlBody($packet['request']);$workspace=$context['workspace_id'];
$passed=0;$check=static function(bool $ok,string $message)use(&$passed){if(!$ok)throw new RuntimeException($message);$passed++;echo "PASS $message\n";};
$hashed=$context;unset($hashed['context_hash']);
$check(quantaMetadataRequestHash($hashed)===$context['context_hash'],'PHP verifies native Quanta context including Unicode names');
$check($body['expected_context_hash']===$context['context_hash'],'published request retains original context');
$expected=quantaTimeControlExpected($workspace,$body,$context);
foreach($expected as $field=>$value)$check(integrationDeliveryHash([$value])===integrationDeliveryHash([$packet['response'][$field]??null]),'native acknowledgement agrees on '.$field);
$calls=0;$GLOBALS['__quanta_core_write_transport']=static function($method,$url,$headers,$json)use($packet,$body,&$calls):array{
    $calls++;if($method!=='POST'||!str_ends_with($url,'/time-access')||json_decode($json,true)!==$body)throw new RuntimeException('Unexpected fixture write');
    return ['status'=>201,'body'=>json_encode($packet['response'])];
};
$record=quantaWriteContract('synthetic-key',$workspace,'time.access',$body,$expected,str_repeat('c',64));
$check($record===$packet['response']&&$calls===1,'Core accepts the actual Quanta publication response');
$check(quantaTimeControlReceiptOutcome(['body'=>$body,'expected'=>$expected],$packet['receipt'])==='confirmed','Core confirms the actual durable provider receipt');
unset($GLOBALS['__quanta_core_write_transport']);echo "$passed native time-configuration wire checks passed\n";

<?php
/** Consume the actual isolated provider response through Core's native adapter. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('COREFLUX_BILL_HISTORY_FIXTURE') !== '1') throw new RuntimeException('Explicit isolated contract fixture required');
require_once __DIR__.'/../core/purepay_adapter.php';
$raw=stream_get_contents(STDIN,5000001);
if(strlen($raw)>5000000)throw new RuntimeException('Fixture exceeds the contract bound');
$expected=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
$GLOBALS['__purepay_transport']=static function($method,$url,$headers,$body)use($raw,$expected):array{
    if($method!=='GET'||$body!==null||parse_url($url,PHP_URL_PATH)!=='/api/v1/bills/'.rawurlencode($expected['bill_id']).'/payments')throw new RuntimeException('Unexpected provider IO');
    return ['status'=>200,'body'=>$raw];
};
$rows=purepayCompleteBillPayments('isolated-fixture',$expected['bill_id']);
if($rows!==$expected['items'])throw new RuntimeException('Provider evidence changed in Core');
unset($GLOBALS['__purepay_transport']);
echo json_encode(['accepted_items'=>count($rows),'evidence_preserved'=>true]),"\n";

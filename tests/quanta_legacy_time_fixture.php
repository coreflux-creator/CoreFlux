<?php
/** Consumes an actual Quanta native API packet; no database or provider writes. */
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/quanta/legacy_time_delivery.php';
require_once __DIR__.'/fixtures/staffing_dictionary_checks.php';
$packet=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);$passed=0;
$check=static function(bool $ok,string $label)use(&$passed):void{if(!$ok)throw new RuntimeException($label);$passed++;};
$fails=static function(callable $fn):bool{try{$fn();return false;}catch(Throwable $e){return true;}};
$review=$packet['original_review'];
$snapshot=['status'=>'unchanged','remote'=>$review,'payload'=>['body'=>$review['current']['publication']+['expected_version'=>$review['current']['version']]]];
$parsed=quantaLegacyTimeContext($packet['context'],$snapshot);
staffingDictionaryAssertPayload('quanta_legacy_time','quanta',$parsed['body']);
$check(integrationDeliveryHash($parsed['body'])===integrationDeliveryHash($packet['body']),'native context becomes the exact reviewed request');
foreach($parsed['expected'] as $key=>$value)$check(integrationDeliveryHash([$packet['association'][$key]])===integrationDeliveryHash([$value]),'native association agrees on '.$key);
$check(!isset($packet['source_after_activation']['core_assignment']),'original source payload remains legacy');
foreach(['expected_version'=>'0','confirm_existing_time'=>1,'expected_context_hash'=>'bad','hours'=>8] as $field=>$value)
    $check($fails(fn()=>quantaLegacyTimeBody(array_replace($packet['body'],[$field=>$value]))),'reject altered body '.$field);
$bad=$snapshot;$bad['status']='preview';$check($fails(fn()=>quantaLegacyTimeContext($packet['context'],$bad)),'unpublished Core changes need reconciliation');
$bad=$packet['context'];$bad['workspace_id']='foreign';$check($fails(fn()=>quantaLegacyTimeContext($bad,$snapshot)),'foreign workspace fails');
$bad=$packet['context'];$bad['source_entry']['duration_minutes']++;$check($fails(fn()=>quantaLegacyTimeContext($bad,$snapshot)),'changed source fails');
$bad=$packet['context'];$bad['proof']['review_publication']['core_revision']=str_repeat('f',64);$check($fails(fn()=>quantaLegacyTimeContext($bad,$snapshot)),'different Core proof fails');
$bad=$packet['context'];$bad['context_hash']=str_repeat('f',64);$check($fails(fn()=>quantaLegacyTimeContext($bad,$snapshot)),'altered context hash fails');
$check(quantaLegacyTimeReceiptOutcome($parsed,$packet['receipt'])==='confirmed','native durable receipt confirms only the original proof');
$bad=$packet['receipt'];$bad['response']['proof']['source_revision']=str_repeat('f',64);$check($fails(fn()=>quantaLegacyTimeReceiptOutcome($parsed,$bad)),'changed receipt proof fails');
$bad=$packet['receipt'];$bad['response']['last_delivery_id']='cfd_'.str_repeat('f',64);$check($fails(fn()=>quantaLegacyTimeReceiptOutcome($parsed,$bad)),'foreign receipt identity fails');
$calls=[];$response=$packet['association'];
$GLOBALS['__quanta_core_write_transport']=static function($method,$url,$headers,$json)use(&$calls,$response):array{$calls[]=[$method,$url,json_decode($json,true)];return ['status'=>201,'body'=>json_encode($response)];};
$result=quantaWriteContract('fixture-key',$packet['context']['workspace_id'],'time.legacy',$parsed['body'],$parsed['expected'],str_repeat('a',64));
$check($result===$response&&count($calls)===1&&str_ends_with($calls[0][1],'/legacy-time')&&integrationDeliveryHash($calls[0][2])===integrationDeliveryHash($packet['body']),'only exact historical association endpoint receives the reviewed request');
unset($GLOBALS['__quanta_core_write_transport']);echo json_encode(['passed'=>$passed,'native_wire_verified'=>true,'source_payload_unchanged'=>true])."\n";

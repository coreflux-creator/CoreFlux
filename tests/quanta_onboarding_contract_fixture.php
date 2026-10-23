<?php
/** Isolated cross-language fixture, never a live endpoint. */
declare(strict_types=1);
$origin=(string)getenv('COREFLUX_QUANTA_FIXTURE_ORIGIN');
if(PHP_SAPI!=='cli'||getenv('COREFLUX_QUANTA_CONTRACT_FIXTURE')!=='1'||!preg_match('~^http://127\.0\.0\.1:[1-9][0-9]{0,4}$~D',$origin))throw new RuntimeException('Explicit loopback fixture required');
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/quanta/onboarding_contract.php';
$GLOBALS['__quanta_transport']=$GLOBALS['__quanta_core_write_transport']=static function($method,$url,$headers,$body='')use($origin){
    if(!str_starts_with($url,QUANTA_API_BASE.'/'))throw new RuntimeException('Unexpected fixture route');
    $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$body,'ignore_errors'=>true,'follow_location'=>0,'timeout'=>10]]);
    $raw=file_get_contents($origin.substr($url,strlen('https://helloquanta.app')),false,$context);
    if($raw===false)throw new RuntimeException('Fixture request failed');
    preg_match('/^HTTP\/\S+ ([0-9]{3})/',$http_response_header[0]??'',$match);
    return ['status'=>(int)($match[1]??0),'body'=>$raw];
};
try{
    $raw=stream_get_contents(STDIN,250001);if(strlen($raw)>250000)throw new RuntimeException('Fixture too large');
    $input=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
    if($input['mode']==='publish'){
        quantaOnboardingContext('ck_fixture_write-a','a');
        $plan=quantaOnboardingPlan($input['case'],'381e452a-5d11-4e2e-9daa-e9427501898a','a',$input['assignment'],$input['current']??null);
        $result=quantaWriteContract('ck_fixture_write-a','a',$plan['operation'],$plan['body'],$plan['expected'],$input['delivery_key']);
    }elseif($input['mode']==='validate'){
        $record=quantaGet('ck_fixture_write-a',QUANTA_CORE_CONTRACT_PATH.'/onboarding/'.$input['remote_id']);
        $result=quantaOnboardingSubmissionValidate($input['case'],$record,$record['submissions'][0],'worker-1','381e452a-5d11-4e2e-9daa-e9427501898a','a');
    }else throw new RuntimeException('Unknown fixture operation');
    echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);exit(2);}

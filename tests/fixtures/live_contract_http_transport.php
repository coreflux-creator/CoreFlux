<?php
/** Included only by the guarded loopback router; no live provider is contacted. */
$liveDirectory=dirname((string)getenv('COREFLUX_CONTRACT_HTTP_STORAGE'));
$liveConfig=$liveDirectory.'/jobdiva-live.json';
if (!str_starts_with($liveDirectory,sys_get_temp_dir().'/coreflux-contract-http-') || realpath($liveDirectory)!==$liveDirectory
    || realpath($liveConfig)!==$liveConfig || is_link($liveConfig)) { http_response_code(404); exit; }
$liveFixture=json_decode((string)file_get_contents($liveConfig),true,64,JSON_THROW_ON_ERROR);
$GLOBALS['__jobdiva_transport']=static function($method,$path,$body,$query,$withAuth,$token)use($liveDirectory,$liveFixture):array{
    if(getDB()->inTransaction()||JobdivaStoredReviewTransaction::inScope())throw new LogicException('Provider read held database locks');
    if(!$withAuth||$token!=='synthetic-live-contract-token')throw new LogicException('Unexpected provider credential');
    file_put_contents($liveDirectory.'/jobdiva-live.log',$method.' '.$path."\n",FILE_APPEND|LOCK_EX);
    if($path==='/apiv2/jobdiva/searchStart'&&$method==='POST'){
        if(($body['candidateid']??null)!==99320002&&($body['jobid']??null)!==99320003)throw new LogicException('Missing exact source lookup criteria');
        $rows=[$liveFixture['root']];
        if(($liveFixture['mode']??'')==='wrong_start')$rows[0]['startId']='99329999';
    }elseif($path==='/apiv2/bi/EmployeeAssignmentRecordsDetail'&&$method==='GET'){
        $rows=$liveFixture['rows'];
        if(($liveFixture['mode']??'')==='stale'){
            getDB()->prepare('UPDATE placements SET title="Later committed native title" WHERE tenant_id=900012 AND id=?')->execute([$liveFixture['placement']]);
        }
    }else throw new LogicException('Unexpected provider route: '.$method.' '.$path);
    return ['status'=>200,'body'=>$rows,'headers'=>[]];
};

<?php
/** Permissioned, versioned compensation history. This does not publish provider rates. */
declare(strict_types=1);
require_once __DIR__.'/../../../core/api_bootstrap.php';
require_once __DIR__.'/../lib/compensation.php';
$ctx=api_require_auth();
api_require_legacy_permission($ctx,api_method()==='GET'?'people.view':'people.manage');
try{
    $pdo=getDB();if(!$pdo)api_error('Compensation storage is unavailable.',503);
    $tenant=(int)$ctx['tenant_id'];
    if(api_method()==='GET'){
        $id=filter_var(api_query('employee_id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($id===false)throw new InvalidArgumentException('Choose an employment record.');
        $history=peopleCompensationHistory($pdo,$tenant,$id);
        if((string)api_query('active')==='1')$history['compensation']=peopleCompensationActive($pdo,$tenant,$id,date('Y-m-d'));
        else $history['compensation']=array_reverse($history['compensation']);
        api_ok($history);
    }
    if(api_method()!=='POST')api_error('Method not allowed',405);
    $body=api_json_body();$id=companyRelationshipId($body['employee_id']??null);
    if(!$id||!is_string($body['history_revision']??null))throw new InvalidArgumentException('Choose an employee and refresh compensation history before saving.');
    $revision=$body['history_revision'];unset($body['employee_id'],$body['history_revision']);
    $result=peopleCompensationAppend($pdo,$tenant,$id,(int)$ctx['user']['id'],$body,$revision);
    api_ok($result,$result['replayed']?200:201);
}catch(InvalidArgumentException $e){api_error($e->getMessage(),422);}
catch(RuntimeException $e){$code=(int)$e->getCode();api_error(in_array($code,[404,409],true)?$e->getMessage():'Compensation could not be saved. Refresh and retry.',in_array($code,[404,409],true)?$code:503);}

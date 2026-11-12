<?php
declare(strict_types=1);
require_once __DIR__.'/../../../core/api_bootstrap.php';
require_once __DIR__.'/../lib/employment_setup.php';
$ctx=api_require_auth();$user=$ctx['user'];$tenant=(int)$ctx['tenant_id'];$actor=(int)$user['id'];
api_require_legacy_permission($ctx,'people.view');
try{
    if(api_method()==='GET'){
        $id=filter_var(api_query('employee_id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$id)throw new InvalidArgumentException('Choose an employment record.');
        if(api_query('action')==='history'){
            api_require_legacy_permission($ctx,'people.manage');api_ok(['rows'=>peopleEmploymentSetupHistory($tenant,$id)]);
        }
        $context=peopleEmploymentSetupContext($tenant,$id);$context['can_manage']=rbac_legacy_can($user,'people.manage');api_ok($context);
    }
    if(api_method()!=='POST')api_error('Method not allowed',405);
    api_require_legacy_permission($ctx,'people.manage');$body=api_json_body();
    if(api_query('action')==='preview'){
        if(!is_int($body['employee_id']??null)||$body['employee_id']<=0||!is_array($body['changes']??null)||!is_string($body['reason']??null))throw new InvalidArgumentException('Choose employment changes and a reason.');
        api_ok(peopleEmploymentSetupPreview($tenant,$actor,$body['employee_id'],$body['changes'],$body['reason']));
    }
    if(api_query('action')==='apply'){
        if(($body['confirm_setup']??null)!==true||!is_string($body['review_token']??null))throw new InvalidArgumentException('Confirm the reviewed employment setup.');
        api_ok(peopleEmploymentSetupApply($tenant,$actor,$body['review_token']));
    }
    api_error('Unknown employment setup action',400);
}catch(InvalidArgumentException $e){api_error($e->getMessage(),422);}catch(RuntimeException $e){api_error($e->getMessage(),409);}

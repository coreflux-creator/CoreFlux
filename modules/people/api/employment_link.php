<?php
/** Reviewed canonical employment link. No payroll, invitation or profile overwrite. */
declare(strict_types=1);
require_once __DIR__.'/../../../core/api_bootstrap.php';
require_once __DIR__.'/../../../core/RBAC.php';
require_once __DIR__.'/../lib/employment_links.php';
$ctx=api_require_auth();$user=$ctx['user'];$tenant=(int)$ctx['tenant_id'];$actor=(int)$user['id'];
rbac_legacy_require($user,'people.view');
try {
    if(api_method()==='GET') {
        $id=filter_var(api_query('person_id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!$id)throw new InvalidArgumentException('Choose a person.');
        $result=peopleEmploymentContext($tenant,$id);$result['can_manage']=rbac_legacy_can($user,'people.manage');
        $result['choices']=[];
        if($result['available']&&!$result['link']&&$result['can_manage']){
            $query=trim((string)api_query('q',''));if(mb_strlen($query)>120)throw new InvalidArgumentException('Search must be 120 characters or fewer.');
            $like='%'.strtr($query,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
            $s=getDB()->prepare('SELECT e.id,e.employee_number,e.legal_first_name,e.legal_last_name,e.work_email,e.status
                FROM people_employees e LEFT JOIN people_employment_links l ON l.tenant_id=e.tenant_id AND l.employee_id=e.id
                WHERE e.tenant_id=? AND l.id IS NULL AND (?="" OR CONCAT_WS(" ",e.employee_number,e.legal_first_name,e.legal_last_name,e.work_email) LIKE ? ESCAPE "!")
                ORDER BY e.legal_last_name,e.legal_first_name,e.id LIMIT 501');
            $s->execute([peopleEmploymentOwner($tenant),$query,$like]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
            $result['choices_complete']=count($rows)<=500;$result['choices']=array_slice($rows,0,500);
        }
        api_ok($result);
    }
    if(api_method()!=='POST')api_error('Method not allowed',405);
    rbac_legacy_require($user,'people.manage');$body=api_json_body();$action=(string)api_query('action');
    if($action==='preview'){
        if(!is_int($body['person_id']??null)||$body['person_id']<=0||!array_key_exists('employee_id',$body)
            ||($body['employee_id']!==null&&(!is_int($body['employee_id'])||$body['employee_id']<=0))||!is_string($body['reason']??null))throw new InvalidArgumentException('Choose the person and employment record, and provide a reason.');
        api_ok(peopleEmploymentPreview($tenant,$actor,$body['person_id'],$body['employee_id'],$body['reason']));
    }
    if($action==='apply'){
        if(($body['confirm_link']??null)!==true||!is_string($body['review_token']??null))throw new InvalidArgumentException('Confirm the reviewed employment link.');
        api_ok(peopleEmploymentApply($tenant,$actor,$body['review_token']));
    }
    api_error('Unknown employment action',400);
}catch(InvalidArgumentException $e){api_error($e->getMessage(),422);}
catch(RuntimeException $e){api_error($e->getMessage(),409);}

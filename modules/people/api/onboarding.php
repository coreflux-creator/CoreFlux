<?php
/** Staff case management. Quanta workers use a separate scoped portal contract. */
declare(strict_types=1);
require_once __DIR__.'/../../../core/api_bootstrap.php';
require_once __DIR__.'/../../../core/RBAC.php';
require_once __DIR__.'/../../../core/sub_tenants.php';
require_once __DIR__.'/../../../core/StorageService.php';
require_once __DIR__.'/../lib/onboarding.php';

$ctx=api_require_auth();api_require_legacy_permission($ctx,'people.onboarding.view');
api_require_legacy_permission($ctx,'placements.view');
api_require_legacy_permission($ctx,'people.docs.view');
$tenant=(int)$ctx['tenant_id'];$user=$ctx['user'];
$platform=($ctx['global_role']??'')==='master_admin'||($ctx['role']??'')==='master_admin'||!empty($ctx['is_global_admin']);
$scope=['tenant_id'=>$tenant,'people_tenant_id'=>effectiveTenantIdForModule('people',$tenant)??$tenant,
    'placements_tenant_id'=>effectiveTenantIdForModule('placements',$tenant)??$tenant,'actor_user_id'=>(int)$user['id'],
    'permissions'=>['view'=>true,'manage'=>$platform||rbac_legacy_can($user,'people.onboarding.manage'),
        'review'=>$platform||rbac_legacy_can($user,'people.onboarding.review')]];
$pdo=getDB();$storage=null;
$head=static function(string $key)use(&$storage):?array{$storage??=new \Core\StorageService();return $storage->head($key);};
try{
    if(api_method()==='GET'){
        if(isset($_GET['case_id']))api_ok(['case'=>onboardingView($pdo,$scope,onboardingId($_GET['case_id']),$head),'permissions'=>$scope['permissions']]);
        $personId=filter_var($_GET['person_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!$personId)throw new InvalidArgumentException('Choose a person.');
        $st=$pdo->prepare('SELECT id FROM people WHERE tenant_id=:t AND id=:id AND deleted_at IS NULL');$st->execute(['t'=>$scope['people_tenant_id'],'id'=>$personId]);
        if(!$st->fetchColumn())throw new OnboardingConflict('Person is unavailable in this workspace.');
        $st=$pdo->prepare('SELECT id,title,status FROM placements WHERE tenant_id=:t AND person_id=:p AND deleted_at IS NULL ORDER BY id DESC LIMIT 101');
        $st->execute(['t'=>$scope['placements_tenant_id'],'p'=>$personId]);$placements=$st->fetchAll(PDO::FETCH_ASSOC);
        if(count($placements)>100)throw new OnboardingConflict('This person has more than 100 placements. Narrow the case from a placement before continuing.');
        $placements=coreRecordNumbersAttach($pdo,$scope['placements_tenant_id'],'placement',$placements);
        $st=$pdo->prepare('SELECT id,placement_id,version,updated_at FROM staffing_onboarding_cases WHERE tenant_id=:t AND people_tenant_id=:pt AND placements_tenant_id=:lt AND person_id=:p ORDER BY updated_at DESC LIMIT 101');
        $st->execute(['t'=>$tenant,'pt'=>$scope['people_tenant_id'],'lt'=>$scope['placements_tenant_id'],'p'=>$personId]);$cases=$st->fetchAll(PDO::FETCH_ASSOC);
        if(count($cases)>100)throw new OnboardingConflict('This person has more than 100 onboarding cases. Open a specific case.');
        // Only document metadata needed for selection. Storage keys and signed URLs never leave this endpoint.
        $st=$pdo->prepare('SELECT id,doc_type,file_name,expires_at FROM people_documents WHERE tenant_id=:t AND person_id=:p AND deleted_at IS NULL ORDER BY id DESC LIMIT 101');
        $st->execute(['t'=>$scope['people_tenant_id'],'p'=>$personId]);$documents=$st->fetchAll(PDO::FETCH_ASSOC);
        api_ok(['cases'=>$cases,'placements'=>$placements,'documents'=>array_slice($documents,0,100),'documents_has_more'=>count($documents)>100,'permissions'=>$scope['permissions']]);
    }
    if(api_method()==='POST'){
        // JSON prevents cross-origin HTML form submissions; normal API CORS/auth policy still applies.
        if(!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json'))api_error('JSON request required',415);
        $body=api_json_body();$permission=in_array($body['action']??'',['review','confirm_plan'],true)?'review':'manage';
        api_require_legacy_permission($ctx,'people.onboarding.'.$permission);
        $receipt=onboardingCommand($pdo,$scope,$body,$head);
        api_ok(['receipt'=>$receipt,'case'=>onboardingView($pdo,$scope,$receipt['case_id'],$head),'permissions'=>$scope['permissions']]);
    }
    api_error('Method not allowed',405);
}catch(InvalidArgumentException $e){api_error($e->getMessage(),422);}
catch(OnboardingConflict $e){api_error($e->getMessage(),409);}
catch(Throwable $e){error_log('Onboarding request failed: '.$e->getMessage());api_error('Onboarding is unavailable. Check that the current release and migration 171 are installed.',503);}

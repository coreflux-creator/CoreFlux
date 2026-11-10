<?php
declare(strict_types=1);
require_once __DIR__.'/../../../core/api_bootstrap.php';
require_once __DIR__.'/../../../core/RBAC.php';
require_once __DIR__.'/../../../core/StorageService.php';
require_once __DIR__.'/../../../core/quanta/onboarding_delivery.php';
$ctx=api_require_auth();
foreach(['people.onboarding.view','placements.view','people.docs.view']as$p)api_require_legacy_permission($ctx,$p);
$tenant=(int)$ctx['tenant_id'];$user=$ctx['user'];
$platform=($ctx['global_role']??'')==='master_admin'||($ctx['role']??'')==='master_admin'||!empty($ctx['is_global_admin']);
$scope=['tenant_id'=>$tenant,'people_tenant_id'=>effectiveTenantIdForModule('people',$tenant)??$tenant,
    'placements_tenant_id'=>effectiveTenantIdForModule('placements',$tenant)??$tenant,'actor_user_id'=>(int)$user['id'],
    'permissions'=>['view'=>true,'manage'=>$platform||rbac_legacy_can($user,'people.onboarding.manage'),
        'documents_manage'=>$platform||rbac_legacy_can($user,'people.docs.manage')]];
$storage=null;$head=static function(string $key)use(&$storage):?array{$storage??=new \Core\StorageService();return $storage->head($key);};
try{
    if(api_method()==='GET'){
        $id=onboardingId($_GET['case_id']??null);
        if(($_GET['view']??null)==='document_transfers')api_ok(quantaOnboardingTransfers(getDB(),$scope,$id));
        api_ok(quantaOnboardingStatus($scope,$id,$head));
    }
    if(api_method()!=='POST')api_error('Method not allowed',405);
    if(!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json'))api_error('JSON request required',415);
    api_require_legacy_permission($ctx,'people.onboarding.manage');$body=api_json_body();$action=$body['action']??'';
    $fields=['preview'=>['state'],'send'=>['delivery_id','review_hash'],'check_receipt'=>['delivery_id','review_hash'],
        'import'=>['receipt_id','delivery_id','expected_version'],'import_document'=>['receipt_id','delivery_id','expected_version']];
    if(!isset($fields[$action])||array_diff(array_keys($body),array_merge(['action','case_id'],$fields[$action])))throw new InvalidArgumentException('Unsupported onboarding exchange request');
    $id=onboardingId($body['case_id']??null);
    if($action!=='preview'&&(!is_int($body['delivery_id']??null)||$body['delivery_id']<=0))throw new InvalidArgumentException('Choose a reviewed publication');
    if(in_array($action,['send','check_receipt'],true)&&(!is_string($body['review_hash']??null)||!preg_match('/^[a-f0-9]{64}$/D',$body['review_hash'])))throw new InvalidArgumentException('A current publication review is required');
    if(in_array($action,['import','import_document'],true)&&(!is_int($body['expected_version']??null)||$body['expected_version']<=0||!is_string($body['receipt_id']??null)))throw new InvalidArgumentException('Choose a worker submission from the current case');
    if($action==='import_document'){
        api_require_legacy_permission($ctx,'people.docs.manage');
        quantaOnboardingDocumentAuthority($scope);
        if(!quantaOnboardingDocumentStorageReady())api_error('Document intake requires configured private storage.',503);
        $storage??=new \Core\StorageService();
        if($storage->driver_name()!=='s3')api_error('Document intake requires configured private storage.',503);
    }
    $result=match($action){
        'preview'=>quantaOnboardingPreview($scope,$id,(string)($body['state']??''),$head),
        'send'=>quantaOnboardingSend($scope,$id,$body['delivery_id'],$body['review_hash'],$head),
        'check_receipt'=>quantaOnboardingCheckReceipt($scope,$id,$body['delivery_id'],$body['review_hash'],$head),
        'import'=>quantaOnboardingImport($scope,$id,$body['receipt_id'],$body['delivery_id'],$body['expected_version'],$head),
        'import_document'=>quantaOnboardingImportDocument($scope,$id,$body['receipt_id'],$body['delivery_id'],$body['expected_version'],$storage),
    };
    api_ok(['result'=>$result]);
}catch(InvalidArgumentException $e){api_error($e->getMessage(),422);}
catch(OnboardingConflict $e){api_error($e->getMessage(),409);}
catch(PeopleDocumentConflict $e){api_error($e->getMessage(),409);}
catch(QuantaApiException $e){api_error('Quanta onboarding is unavailable. Check the connected workspace, published version and onboarding permissions.',503);}
catch(Throwable $e){error_log('Onboarding exchange failed: '.$e->getMessage());api_error('The onboarding exchange could not be confirmed. Refresh its status before another change.',503);}

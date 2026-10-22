<?php
/** Separate Core metadata connection. No payment or payroll execution actions. */
declare(strict_types=1);
require_once __DIR__.'/../core/api_bootstrap.php';
require_once __DIR__.'/../core/integrations/purepay_links.php';
require_once __DIR__.'/../core/integrations/purepay_payroll_inputs.php';
require_once __DIR__.'/../core/integrations/purepay_payroll_results.php';
require_once __DIR__.'/../core/integrations/purepay_people_inputs.php';
require_once __DIR__.'/../core/integrations/purepay_assignment_inputs.php';
require_once __DIR__.'/../core/integrations/purepay_people_setup.php';
require_once __DIR__.'/../core/sub_tenants.php';
$ctx=api_require_auth();
$user=$ctx['user'];$tenantId=(int)$ctx['tenant_id'];
rbac_legacy_require($user,'accounting.bank.manage');
$action=(string)($_GET['action']??'status');
$method=api_method();
$pdo=getDB();
// Connection management alone does not grant access to the source directories or payroll.
function purepayCoreApiRecordOwner(array $user,int $tenant,string $kind): int
{
    $permission=match($kind) {
        'person'=>'people.view','placement','client'=>'placements.view','vendor'=>'ap.view',
        default=>throw new InvalidArgumentException('Unknown record type.'),
    };
    rbac_legacy_require($user,$permission);
    if($kind==='person')rbac_legacy_require($user,'payroll.view');
    return effectiveTenantIdForModule(coreRecordNumberDefinition($kind)['module'],$tenant)??$tenant;
}
function purepayCoreApiPayrollOwners(array $user,int $tenant): array
{
    foreach(['payroll.runs.manage','time.view','people.view','placements.view','placements.financials.view']as$permission)rbac_legacy_require($user,$permission);
    $owners=[];
    foreach(['time','people','placements']as$module)$owners[$module]=effectiveTenantIdForModule($module,$tenant)??$tenant;
    return $owners;
}
function purepayCoreApiPeopleOwners(array $user,int $tenant): array
{
    return ['people'=>purepayCoreApiRecordOwner($user,$tenant,'person'),
        'placements'=>purepayCoreApiRecordOwner($user,$tenant,'placement')];
}
function purepayCoreApiAssignmentOwners(array $user,int $tenant): array
{
    foreach(['placements.view','placements.financials.view','people.view','ap.view','payroll.view'] as $permission)rbac_legacy_require($user,$permission);
    $owners=[];
    foreach(['placements','people','ap'] as $module)$owners[$module]=effectiveTenantIdForModule($module,$tenant)??$tenant;
    return $owners;
}
try {
    if($method==='GET' && $action==='status')api_ok(purepayCoreSummary(purepayCoreConnection($pdo,$tenantId)));
    if($method!=='POST')api_error('Method not allowed',405);
    $body=api_json_body();
    $audit=static function()use($pdo,$tenantId,$user,$action,$body):void {
        // Action and company only; credentials and employee payloads never enter audit metadata.
        $metadata=['action'=>$action];
        if(in_array($action,['link_send','link_reconcile','payroll_input_send','payroll_input_reconcile','payroll_result_refresh','people_input_send','people_input_status','assignment_input_send','assignment_input_status'],true))$metadata['delivery_id']=$body['delivery_id'];
        $pdo->prepare('INSERT INTO audit_log(tenant_id,actor_user_id,event,target_id,meta_json,ip_address,created_at) VALUES(:t,:u,:e,NULL,:m,:ip,NOW())')
            ->execute(['t'=>$tenantId,'u'=>(int)$user['id'],'e'=>'purepay.core.'.$action,'m'=>json_encode($metadata),'ip'=>$_SERVER['REMOTE_ADDR']??null]);
    };
    $instance=integrationInstanceId();
    if($instance===null && $action!=='disconnect')api_error('Configure the permanent Core installation identity before connecting.',422);
    switch($action) {
        case 'connect':
            if(!is_string($body['api_key']??null))api_error('API key required',422);
            purepayCoreConnect($pdo,$tenantId,trim($body['api_key']),$instance,$audit);
            $result=purepayCoreSummary(purepayCoreConnection($pdo,$tenantId));break;
        case 'review': $result=purepayCoreReview($pdo,$tenantId,$instance);break;
        case 'confirm':
            if(($body['confirmed']??null)!==true || !is_string($body['review_hash']??null) || !is_string($body['organization_id']??null))api_error('Review and confirm the provider organization first.',422);
            $result=purepayCoreConfirm($pdo,$tenantId,(int)$user['id'],$instance,$body['review_hash'],$body['organization_id'],$audit);break;
        case 'records':
            $kind=$body['kind']??null;
            if(!is_string($kind) || (isset($body['cursor'])&&!is_string($body['cursor'])))api_error('Invalid record page',422);
            purepayCoreApiRecordOwner($user,$tenantId,$kind);
            $result=purepayCoreRecords($pdo,$tenantId,$instance,$kind,50,$body['cursor']??null);break;
        case 'people_setup':
            if(!is_string($body['person_number']??null))api_error('Choose a confirmed Core person number.',422);
            $owner=purepayCoreApiRecordOwner($user,$tenantId,'person');
            rbac_legacy_require($user,'people.docs.view');
            header('Cache-Control: no-store, private');
            $result=purepayPeopleSetupRead($pdo,$tenantId,$instance,$owner,strtoupper(trim($body['person_number'])));break;
        case 'link_preview':
            if(!is_string($body['kind']??null) || !is_string($body['number']??null) || !is_string($body['provider_id']??null))api_error('Select a provider record and enter its Core number.',422);
            $owner=purepayCoreApiRecordOwner($user,$tenantId,$body['kind']);
            $result=purepayCoreLinkPreview($pdo,$tenantId,(int)$user['id'],$instance,$owner,$body['kind'],strtoupper(trim($body['number'])),$body['provider_id']);break;
        case 'link_history':
            if(!is_string($body['kind']??null) || (isset($body['before'])&&!is_int($body['before'])))api_error('Invalid link history page.',422);
            $owner=purepayCoreApiRecordOwner($user,$tenantId,$body['kind']);
            $result=purepayCoreLinkHistory($pdo,$tenantId,$instance,$owner,$body['kind'],$body['before']??null);break;
        case 'link_send':
        case 'link_reconcile':
            if(!is_int($body['delivery_id']??null) || $body['delivery_id']<=0 || !is_string($body['review_hash']??null))api_error('A current link review is required.',422);
            if($action==='link_send' && ($body['confirmed']??null)!==true)api_error('Confirm the two records before linking.',422);
            $delivery=integrationDeliveryGet($pdo,$tenantId,$body['delivery_id']);
            $owner=purepayCoreApiRecordOwner($user,$tenantId,$delivery['entity_type']);
            $result=$action==='link_send'
                ?purepayCoreLinkSend($pdo,$tenantId,(int)$user['id'],$instance,$owner,$body['delivery_id'],$body['review_hash'],$audit)
                :purepayCoreLinkReconcile($pdo,$tenantId,$instance,$owner,$body['delivery_id'],$body['review_hash'],$audit);
            break;
        case 'disconnect': purepayCoreDisconnect($pdo,$tenantId,$audit);$result=['connected'=>false];break;
        case 'assignment_input_preview':
            if(!is_string($body['placement_number']??null) || (isset($body['previous_revision']) && !is_string($body['previous_revision'])))api_error('Choose a Core placement and an optional accepted predecessor.',422);
            $owners=purepayCoreApiAssignmentOwners($user,$tenantId);
            $result=purepayAssignmentInputPreview($pdo,$tenantId,(int)$user['id'],$instance,$owners,strtoupper(trim($body['placement_number'])),$body['previous_revision']??null);break;
        case 'assignment_input_send':
        case 'assignment_input_status':
            if(!is_int($body['delivery_id']??null) || $body['delivery_id']<=0 || !is_string($body['review_hash']??null))api_error('Choose a current placement review.',422);
            if($action==='assignment_input_send' && ($body['confirmed']??null)!==true)api_error('Confirm the complete commercial snapshot before sending.',422);
            $owners=purepayCoreApiAssignmentOwners($user,$tenantId);
            $result=$action==='assignment_input_send'
                ?purepayAssignmentInputSend($pdo,$tenantId,(int)$user['id'],$instance,$owners,$body['delivery_id'],$body['review_hash'],$audit)
                :purepayAssignmentInputStatus($pdo,$tenantId,$instance,$owners,$body['delivery_id'],$body['review_hash'],$audit);break;
        case 'assignment_input_history':
            if(!is_string($body['placement_number']??null) || (isset($body['before']) && !is_int($body['before'])))api_error('Choose a placement number for history.',422);
            $owners=purepayCoreApiAssignmentOwners($user,$tenantId);
            $result=purepayAssignmentInputHistory($pdo,$tenantId,$instance,$owners,strtoupper(trim($body['placement_number'])),$body['before']??null);break;
        case 'people_input_preview':
            if(!is_string($body['person_number']??null) || !is_string($body['placement_number']??null))api_error('Choose a Core person and W-2 placement number.',422);
            $owners=purepayCoreApiPeopleOwners($user,$tenantId);
            $result=purepayPeopleInputPreview($pdo,$tenantId,(int)$user['id'],$instance,$owners,strtoupper(trim($body['person_number'])),strtoupper(trim($body['placement_number'])));break;
        case 'people_input_send':
        case 'people_input_status':
            if(!is_int($body['delivery_id']??null) || $body['delivery_id']<=0 || !is_string($body['review_hash']??null))api_error('Choose a current person review.',422);
            if($action==='people_input_send' && ($body['confirmed']??null)!==true)api_error('Confirm this contact snapshot before sending.',422);
            $owners=purepayCoreApiPeopleOwners($user,$tenantId);
            $result=$action==='people_input_send'
                ?purepayPeopleInputSend($pdo,$tenantId,(int)$user['id'],$instance,$owners,$body['delivery_id'],$body['review_hash'],$audit)
                :purepayPeopleInputStatus($pdo,$tenantId,$instance,$owners,$body['delivery_id'],$body['review_hash'],$audit);break;
        case 'people_input_history':
            if(!is_string($body['person_number']??null) || (isset($body['before'])&&!is_int($body['before'])))api_error('Choose a person number for history.',422);
            $owners=purepayCoreApiPeopleOwners($user,$tenantId);
            $result=purepayPeopleInputHistory($pdo,$tenantId,$instance,$owners,strtoupper(trim($body['person_number'])),$body['before']??null);break;
        case 'payroll_input_preview':
            if(!is_string($body['person_number']??null) || !is_string($body['period_start']??null) || !is_string($body['period_end']??null))api_error('Choose a person number and payroll input period.',422);
            $owners=purepayCoreApiPayrollOwners($user,$tenantId);
            $result=purepayPayrollInputPreview($pdo,$tenantId,(int)$user['id'],$instance,$owners,strtoupper(trim($body['person_number'])),$body['period_start'],$body['period_end']);break;
        case 'payroll_input_send':
        case 'payroll_input_reconcile':
            if(!is_int($body['delivery_id']??null) || $body['delivery_id']<=0 || !is_string($body['review_hash']??null))api_error('A current payroll input review is required.',422);
            if($action==='payroll_input_send' && ($body['confirmed']??null)!==true)api_error('Confirm this input packet before sending.',422);
            $owners=purepayCoreApiPayrollOwners($user,$tenantId);
            $result=$action==='payroll_input_send'
                ?purepayPayrollInputSend($pdo,$tenantId,(int)$user['id'],$instance,$owners,$body['delivery_id'],$body['review_hash'],$audit)
                :purepayPayrollInputReconcile($pdo,$tenantId,$instance,$owners,$body['delivery_id'],$body['review_hash'],$audit);break;
        case 'payroll_input_history':
            if(!is_string($body['person_number']??null) || (isset($body['before'])&&!is_int($body['before'])))api_error('Choose a person number for input history.',422);
            $owners=purepayCoreApiPayrollOwners($user,$tenantId);
            $result=purepayPayrollInputHistory($pdo,$tenantId,$instance,$owners,strtoupper(trim($body['person_number'])),$body['before']??null);break;
        case 'payroll_result_history':
        case 'payroll_result_refresh':
            if(!is_int($body['delivery_id']??null) || $body['delivery_id']<=0 || !is_string($body['review_hash']??null)
                || (isset($body['before'])&&!is_int($body['before'])))api_error('Choose an existing payroll input review.',422);
            $owners=purepayCoreApiPayrollOwners($user,$tenantId);
            if($action==='payroll_result_refresh') {
                if(!is_string($body['authority_id']??null))api_error('Choose a source reservation.',422);
                $result=purepayPayrollResultRefresh($pdo,$tenantId,(int)$user['id'],$instance,$owners,$body['delivery_id'],$body['review_hash'],$body['authority_id'],$audit);
            } else $result=purepayPayrollResultHistory($pdo,$tenantId,$instance,$owners,$body['delivery_id'],$body['review_hash'],$body['before']??null);
            break;
        default: api_error('Unknown Core connection action',404);
    }
    api_ok($result);
} catch(PDOException $e) { api_error('Core could not save or read the integration state. Check the installed migration and try again.',503); }
catch(InvalidArgumentException|RuntimeException $e) { api_error($e->getMessage(),422); }

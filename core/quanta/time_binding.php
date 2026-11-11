<?php
/** Captured assignment identity on Quanta time. Never infer a replacement owner. */
declare(strict_types=1);
require_once __DIR__.'/core_contract.php';

const QUANTA_TIME_BINDING_VERSION = '2026-11-11.1';

function quantaTimeBindingNormalize(mixed $value, array $entry): array
{
    $fields=['contract_version','policy_id','policy_version','assignment_id','assignment_version',
        'work_date','person','placement','worker_id','worksite_id'];
    if (!is_array($value) || array_is_list($value) || array_diff($fields,array_keys($value))
        || array_diff(array_keys($value),$fields) || $value['contract_version']!==QUANTA_TIME_BINDING_VERSION) {
        throw new InvalidArgumentException('Quanta captured assignment is incomplete or unsupported');
    }
    foreach (['policy_version','assignment_version'] as $field) {
        if (!is_int($value[$field]) || $value[$field]<1) throw new InvalidArgumentException('Invalid captured assignment revision');
    }
    foreach (['policy_id'=>'cfta_','assignment_id'=>'cfa_'] as $field=>$prefix) {
        if (!is_string($value[$field]) || !preg_match('/^'.$prefix.'[a-f0-9]{64}$/D',$value[$field])) {
            throw new InvalidArgumentException('Invalid captured assignment identifier');
        }
    }
    foreach (['worker_id','worksite_id'] as $field) {
        if (!is_string($value[$field]) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$value[$field])
            || $value[$field]!==($entry[$field]??null)) throw new InvalidArgumentException('Captured assignment worker or worksite differs from the time entry');
    }
    if (quantaContractDate($value['work_date'])!==($entry['work_date']??null)) throw new InvalidArgumentException('Captured assignment work date differs from the time entry');
    foreach (['person','placement'] as $kind) {
        if (!is_array($value[$kind])) throw new InvalidArgumentException('A captured Core reference is required');
        $value[$kind]=quantaCoreReferenceValidate($value[$kind],$kind);
    }
    if ($value['person']['instance_id']!==$value['placement']['instance_id']) throw new InvalidArgumentException('Captured references belong to different Core installations');
    // Canonical ordering keeps hashes stable across JSON object-key ordering.
    return array_replace(array_fill_keys($fields,null),$value);
}

function quantaTimeBindingAssertWorkspace(array $binding, ?array $access): void
{
    $instance=$access['instance_id']??null;
    $workspace=$access['context']['workspace']['id']??null;
    if (!is_string($instance) || !is_string($workspace)
        || $binding['person']['instance_id']!==$instance || $binding['placement']['instance_id']!==$instance
        || quantaCoreRemoteId($workspace,$binding['placement'])!==$binding['assignment_id']
        || 'cfta_'.integrationDeliveryHash([$workspace,$binding['worker_id'],$binding['worksite_id']])!==$binding['policy_id']) {
        throw new RuntimeException('Captured time belongs to a different or unverified Core/Quanta workspace');
    }
}

function quantaTimeBindingRouteMatches(array $binding, array $route): bool
{
    if(!empty($route['quanta_assignment_id'])&&$route['quanta_assignment_id']!==$binding['assignment_id'])return false;
    foreach (['person','placement'] as $kind) {
        if (empty($route[$kind.'_directory_tenant_id']) || empty($route[$kind.'_number'])
            || (int)$route[$kind.'_directory_tenant_id']!==$binding[$kind]['directory_tenant_id']
            || $route[$kind.'_number']!==$binding[$kind]['number']) return false;
    }
    return true;
}

function quantaTimeRouteEvidence(array $entry, array $route): array
{
    // A newly migrated empty selector is the same historical legacy route.
    if(empty($route['quanta_assignment_id']))unset($route['quanta_assignment_id']);
    // Newly selected directory columns must not invalidate legacy review
    // fingerprints for time that has never carried a captured assignment.
    if (!array_key_exists('core_assignment',$entry)) {
        unset($route['person_directory_tenant_id'],$route['placement_directory_tenant_id']);
    }
    return $route;
}

/** Once imported, a captured assignment cannot disappear or change on refresh. */
function quantaTimeBindingRetained(array $entry, array $prior): bool
{
    if (empty($prior['source_approval_json'])) return true;
    try { $saved=json_decode($prior['source_approval_json'],true,32,JSON_THROW_ON_ERROR); }
    catch (Throwable $e) { return false; }
    if (!is_array($saved)) return false;
    if (!array_key_exists('core_assignment',$saved)) return true;
    return is_array($entry['core_assignment']??null)
        && integrationDeliveryHash($saved['core_assignment'])===integrationDeliveryHash($entry['core_assignment']);
}

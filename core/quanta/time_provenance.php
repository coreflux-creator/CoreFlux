<?php
/** Original Quanta workspace attribution, retained independently of today's connection. */
declare(strict_types=1);
require_once __DIR__.'/workspace.php';
require_once __DIR__.'/time_approval.php';

/** This table uses imported_at, so the generic created_at insert helper does not apply. */
function quantaTimeImportInsert(PDO $pdo,int $tenantId,array $data): int
{
    $columns=['quanta_entry_id','component','worker_id','source_worksite_id','source_worksite_name','source_dimension_values_json',
        'source_workspace_id','source_workspace_binding_hash','source_workspace_verified_by','source_workspace_verified_at','source_workspace_verification',
        'time_entry_id','timesheet_id','source_hash','source_timesheet_status','imported_by_user_id','source_approval_json','source_approval_hash'];
    $data += ['source_approval_json'=>null,'source_approval_hash'=>null];
    if($tenantId<=0 || array_diff($columns,array_keys($data)) || array_diff(array_keys($data),$columns)) throw new InvalidArgumentException('A complete scoped Quanta import is required');
    $pdo->prepare('INSERT INTO quanta_time_imports (tenant_id,'.implode(',',$columns).') VALUES (:tenant_id,:'.implode(',:',$columns).')')
        ->execute(['tenant_id'=>$tenantId]+$data);
    return (int)$pdo->lastInsertId();
}

function quantaTimeSourceFields(array $access,int $actorId,string $method='import'): array
{
    if($actorId<=0 || !in_array($method,['import','review'],true)) throw new InvalidArgumentException('A source reviewer is required');
    $context=quantaContractContextValidate($access['context']);
    return ['source_workspace_id'=>$context['workspace']['id'],
        'source_workspace_binding_hash'=>$access['binding_hash'],
        'source_workspace_verified_by'=>$actorId,'source_workspace_verified_at'=>date('Y-m-d H:i:s'),
        'source_workspace_verification'=>$method];
}

/** A legacy association requires an exact stored import AND current Core time match. */
function quantaTimeSourceMatches(array $entry,array $route,string $component,float $hours,array $prior): bool
{
    [$hourType,$category,$billable]=quantaComponentCategory($component,($route['engagement_type']??'')==='internal');
    return ($prior['source_hash']??null)===quantaComponentHash($entry,$route,$component,$hours)
        && ($prior['source_worksite_id']??null)===$entry['worksite_id']
        && ($prior['worker_id']??null)===$entry['worker_id']
        && (int)($prior['placement_id']??0)===(int)$route['placement_id']
        && (int)($prior['person_id']??0)===(int)$route['person_id']
        && ($prior['work_date']??null)===$entry['work_date']
        && (string)($prior['entry_status']??'')!=='' && $prior['entry_status']!=='superseded'
        && empty($prior['superseded_by_id'])
        && isset($prior['current_hours']) && (float)$prior['current_hours']===$hours
        && ($prior['current_hour_type']??null)===$hourType && ($prior['current_category']??null)===$category
        && (int)($prior['current_billable']??-1)===$billable
        && ($prior['current_source']??null)==='quanta' && ($prior['current_source_system']??null)==='quanta'
        && (string)($prior['current_description']??'')===(string)$entry['description'];
}

function quantaTimeSourceDecision(array $entry,array $route,array $imports,string $workspaceId): ?array
{
    $prior=$imports[$entry['id']]??[];$legacy=false;
    foreach($prior as $row) {
        if(empty($row['source_workspace_id'])) $legacy=true;
        elseif($row['source_workspace_id']!==$workspaceId) return ['conflict','This time belongs to a different Quanta workspace; existing source identity cannot be replaced'];
    }
    if(!$legacy) return null;
    if(count($prior)!==count($entry['components'])) return ['conflict','Historical Quanta components changed; review the original source before associating a workspace'];
    foreach($entry['components'] as $component=>$hours) {
        if(!isset($prior[$component]) || !quantaTimeSourceMatches($entry,$route,$component,$hours,$prior[$component])) {
            return ['conflict','Historical source or Core time changed; workspace attribution requires an exact match'];
        }
    }
    return ['verify_source','Matching historical time has no recorded Quanta workspace. Confirm its original company before associating it.'];
}

function quantaTimeSourceReviewHash(array $access,array $entry,array $route,array $imports): string
{
    return integrationDeliveryHash(['binding_hash'=>$access['binding_hash'],'entry'=>$entry,
        'route'=>$route,'prior'=>$imports[$entry['id']]??[]]);
}

/** Metadata only: never alter approved hours, their frozen rates, or extraction state. */
function quantaTimeSourceAdopt(PDO $pdo,int $tenantId,int $actorId,array $entry,array $route,array $imports,array $access,string $reviewHash): int
{
    quantaWorkspaceAssertCurrent($pdo,$tenantId,$access,true);
    if((quantaTimeSourceDecision($entry,$route,$imports,$access['context']['workspace']['id'])[0]??null)!=='verify_source'
        || !hash_equals(quantaTimeSourceReviewHash($access,$entry,$route,$imports),$reviewHash)) {
        throw new RuntimeException('Historical source review changed. Preview again and confirm the original Quanta company.');
    }
    $fields=quantaTimeSourceFields($access,$actorId,'review');$count=0;
    foreach($imports[$entry['id']] as $row) {
        if(!empty($row['source_workspace_id'])) continue;
        $stmt=$pdo->prepare('UPDATE quanta_time_imports SET source_workspace_id=:source_workspace_id,
            source_workspace_binding_hash=:source_workspace_binding_hash,source_workspace_verified_by=:source_workspace_verified_by,
            source_workspace_verified_at=:source_workspace_verified_at,source_workspace_verification=:source_workspace_verification
            WHERE tenant_id=:tenant AND id=:id AND source_workspace_id IS NULL');
        $stmt->execute($fields+['tenant'=>$tenantId,'id'=>(int)$row['id']]);
        if($stmt->rowCount()!==1) throw new RuntimeException('Historical workspace attribution changed during review');
        $count++;
    }
    return $count;
}

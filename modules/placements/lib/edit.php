<?php
/** Native placement edits; the API authorizes placements.manage before calling. */
declare(strict_types=1);
require_once __DIR__ . '/ownership.php';
require_once __DIR__ . '/economics.php';
require_once __DIR__ . '/rate_approve.php';
require_once __DIR__ . '/approval_contact.php';
require_once __DIR__.'/company_references.php';

function placementApplyNativeEdit(int $id, array $patch, array $user, string $via): array
{
    if (!$patch) throw new InvalidArgumentException('No fields to update.', 422);
    if (!in_array($via, ['patch_status', 'bulk_status', 'bulk_update', 'activate_action', 'approval_contact'], true)) {
        throw new InvalidArgumentException('Unknown placement edit operation.', 422);
    }
    $patch = placementApprovalContactInput($patch, $via === 'approval_contact');
    return placementNativeDirectoryWrite((int)currentTenantId(),(int)effectiveTenantIdForRequest(),static function(array $scope)use($id,$patch,$user,$via):array{
    return placementWithLockedRecord($scope['owner'], $id, static function (array $existing, PDO $pdo) use ($id, $patch, $user, $via, $scope): array {
        $current = placementOwnershipFields($existing['coreflux_overridden_fields'] ?? null);
        foreach ($patch as $field => $value) {
            if (!array_key_exists($field, $existing) || in_array($field, ['id','tenant_id','created_at','updated_at','created_by_user_id','deleted_at','coreflux_overridden_fields'], true)
                || (!is_scalar($value) && $value !== null)) {
                throw new InvalidArgumentException('This placement field cannot be edited: ' . $field, 422);
            }
            if(is_bool($value)&&str_ends_with($field,'_id'))throw new InvalidArgumentException('Choose a valid record number for '.$field.'.',422);
            if (is_bool($value)) $patch[$field] = (int) $value;
        }
        $sourceFamily=placementJobdivaSourceFamily($pdo,$scope['owner'],$id);$sourceMapped=(bool)$sourceFamily['links'];
        $isJobDivaSourced = $sourceMapped || str_starts_with((string) ($existing['external_id'] ?? ''), 'jd:');
        if ($isJobDivaSourced) {
            foreach (['external_id','person_id','jobdiva_job_id'] as $identityField) {
                if (array_key_exists($identityField, $patch) && (string) $patch[$identityField] !== (string) $existing[$identityField]) {
                    throw new RuntimeException('Review the JobDiva identity link before changing this placement identity.', 409);
                }
            }
        }
        $before = placementAuditRow($id) ?? $existing;
        if(array_key_exists('person_id',$patch))$patch['person_id']=placementResolveNativePerson($pdo,$scope,$patch['person_id']);
        if(array_key_exists('accounting_entity_id',$patch))$patch['accounting_entity_id']=placementResolveNativeAccountingEntity($pdo,$scope,$patch['accounting_entity_id']);
        if(array_key_exists('staffing_job_id',$patch))$patch['staffing_job_id']=placementResolveNativeJob($pdo,$scope,$patch['staffing_job_id']);
        $patch = array_replace($patch, placementsResolveCompanyRoles((int) $existing['tenant_id'], $patch, $existing, $user['id'] ?? null, currentTenantId()));
        $fields = array_keys($patch);
        {
            // Native edits establish Core ownership even before a provider link.
            // A contact is one choice: a later source refresh must not combine
            // a Core email with a different source person's display name.
            $ownedFields = $fields;
            if (array_intersect($fields, ['client_approver_name', 'client_approver_email'])) {
                array_push($ownedFields, 'client_approver_name', 'client_approver_email');
            }
            $merged = array_values(array_unique(array_merge($current, $ownedFields)));
            if ($merged !== $current) $patch['coreflux_overridden_fields'] = json_encode($merged, JSON_THROW_ON_ERROR);
        }
        $changed = false;
        foreach ($patch as $field => $value) {
            $prior = $existing[$field] ?? null;
            if (($prior === null) !== ($value === null) || ($prior !== null && (string) $prior !== (string) $value)) $changed = true;
        }
        // Write the patch and its ownership in one statement. Rate promotion,
        // company creation, readiness and graph changes stay in this savepoint.
        if ($changed) scopedUpdate('placements', $id, $patch);
        $newStatus = $patch['status'] ?? null;
        $autoApproved = 0;
        $shouldAutoApproveRates = $newStatus === 'active'
            || ($existing['status'] === 'draft' && in_array($newStatus, ['pending_start','on_hold'], true));
        if ($shouldAutoApproveRates) $autoApproved = placementsAutoApproveDraftRates($id, $user);
        if ($newStatus === 'active') placementsRequireActiveReady($id, (string) ($patch['start_date'] ?? $existing['start_date']), $via);
        if ($changed || $autoApproved > 0) {
            if ($via !== 'approval_contact') {
                $graph = placementEconomicsReconcile((int) $existing['tenant_id'], $id);
                if (!$graph['available'] || $graph['errors']) {
                    throw new RuntimeException('The placement commercial graph could not be updated. Review its company links and try again.', 422);
                }
            }
            $event = $via === 'approval_contact' ? 'placement.approval_contact.updated'
                : (array_key_exists('status', $patch) ? 'placement.status_changed' : 'placement.updated');
            placementRequiredAudit($event, ['id'=>$id, 'fields'=>$fields, 'status'=>$newStatus, 'via'=>$via,
                'rates_auto_approved'=>$autoApproved, 'before'=>$before, 'after'=>placementAuditRow($id)], $id);
            if ($autoApproved > 0) {
                placementRequiredAudit('placement.rates.auto_approved_on_promotion', [
                    'placement_id'=>$id, 'rate_count'=>$autoApproved, 'promoted_status'=>$newStatus, 'via'=>$via,
                ], $id);
            }
        }
        return ['changed'=>$changed || $autoApproved > 0, 'rates_auto_approved'=>$autoApproved];
    });
    });
}

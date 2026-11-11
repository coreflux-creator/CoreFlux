<?php
/** Native placement edits; the API authorizes placements.manage before calling. */
declare(strict_types=1);
require_once __DIR__ . '/ownership.php';
require_once __DIR__ . '/economics.php';
require_once __DIR__ . '/rate_approve.php';

function placementApplyNativeEdit(int $id, array $patch, array $user, string $via): array
{
    if (!$patch) throw new InvalidArgumentException('No fields to update.', 422);
    if (!in_array($via, ['patch_status', 'bulk_status', 'bulk_update', 'activate_action'], true)) {
        throw new InvalidArgumentException('Unknown placement edit operation.', 422);
    }
    return placementWithLockedRecord((int) effectiveTenantIdForRequest(), $id, static function (array $existing, PDO $pdo) use ($id, $patch, $user, $via): array {
        $current = placementOwnershipFields($existing['coreflux_overridden_fields'] ?? null);
        foreach ($patch as $field => $value) {
            if (!array_key_exists($field, $existing) || in_array($field, ['id','tenant_id','created_at','updated_at','created_by_user_id','deleted_at','coreflux_overridden_fields'], true)
                || (!is_scalar($value) && $value !== null)) {
                throw new InvalidArgumentException('This placement field cannot be edited: ' . $field, 422);
            }
            if (is_bool($value)) $patch[$field] = (int) $value;
        }
        $isJobDivaSourced = str_starts_with((string) ($existing['external_id'] ?? ''), 'jd:');
        if ($isJobDivaSourced) {
            foreach (['external_id','person_id','jobdiva_job_id'] as $identityField) {
                if (array_key_exists($identityField, $patch) && (string) $patch[$identityField] !== (string) $existing[$identityField]) {
                    throw new RuntimeException('Review the JobDiva identity link before changing this placement identity.', 409);
                }
            }
        }
        $before = placementAuditRow($id) ?? $existing;
        if (!empty($patch['staffing_job_id'])) {
            $job = scopedFind('SELECT id FROM staffing_jobs WHERE tenant_id=:tenant_id AND id=:id LIMIT 1', ['id'=>(int) $patch['staffing_job_id']]);
            if (!$job) throw new InvalidArgumentException('staffing_job_id not found in this tenant.', 422);
        }
        $patch = array_replace($patch, placementsResolveCompanyRoles((int) $existing['tenant_id'], $patch, $existing, $user['id'] ?? null, currentTenantId()));
        $fields = array_keys($patch);
        if ($isJobDivaSourced) {
            $merged = array_values(array_unique(array_merge($current, $fields)));
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
            $graph = placementEconomicsReconcile((int) $existing['tenant_id'], $id);
            if (!$graph['available'] || $graph['errors']) {
                throw new RuntimeException('The placement commercial graph could not be updated. Review its company links and try again.', 422);
            }
            $event = array_key_exists('status', $patch) ? 'placement.status_changed' : 'placement.updated';
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
}

<?php
/**
 * Placements API — main resource (CRUD + end action)
 *
 * Routes:
 *   GET    /api/placements/placements                  → list
 *   GET    /api/placements/placements?id=N             → get one (full)
 *   POST   /api/placements/placements                  → create draft
 *   PATCH  /api/placements/placements?id=N             → update
 *   POST   /api/placements/placements?action=end&id=N  → set status='ended'
 *
 * SPEC: /app/modules/placements/SPEC.md §6.1
 */

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/placements.php';
require_once __DIR__ . '/../lib/rate_approve.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();

const ALLOWED_STATUS = ['draft','pending_start','active','on_hold','ended','cancelled'];
const ALLOWED_ETYPE  = ['w2','1099','c2c','temp_to_perm','direct_hire','internal'];
const ALLOWED_REMOTE = ['onsite','hybrid','remote'];

if ($method === 'GET') {
    $id = (int) api_query('id', 0);
    if ($id > 0) {
        rbac_legacy_require($user, 'placements.view');
        $row = placementGet($id);
        if (!$row) api_error('Not found', 404);
        api_ok([
            'placement'   => $row,
            'chain'       => placementChain($id),
            'rates'       => placementRates($id),
            'current_rate'=> placementCurrentRate($id),
            'commissions' => placementCommissions($id),
            'referrals'   => placementReferrals($id),
            'documents'   => placementDocuments($id),
        ]);
    }
    rbac_legacy_require($user, 'placements.view');
    api_ok(placementsList([
        'q'               => $_GET['q']               ?? null,
        'status'          => $_GET['status']          ?? null,
        'person_id'       => $_GET['person_id']       ?? null,
        'end_client'      => $_GET['end_client']      ?? null,
        'engagement_type' => $_GET['engagement_type'] ?? null,
        'start_after'     => $_GET['start_after']     ?? null,
        'end_before'      => $_GET['end_before']      ?? null,
        'due_before'      => $_GET['due_before']      ?? null,
        'page'            => $_GET['page']            ?? 1,
        'per_page'        => $_GET['per_page']        ?? 25,
    ]));
}

if ($method === 'POST') {
    $action = $_GET['action'] ?? '';

    // Slice 2: revert a JobDiva-sourced field that was previously overridden
    // in CoreFlux. The next JobDiva pull will then overwrite the column.
    if ($action === 'clear_override') {
        $id = (int) api_query('id', 0);
        if ($id <= 0) api_error('id required', 400);
        rbac_legacy_require($user, 'placements.manage');
        $body = api_json_body();
        $fields = is_array($body['fields'] ?? null) ? $body['fields'] : [];
        if (empty($fields)) api_error('fields[] required', 422);
        $row = placementGet($id);
        if (!$row) api_error('Not found', 404);
        $before = placementAuditRow($id) ?? $row;
        $current = [];
        if (!empty($row['coreflux_overridden_fields'])) {
            $decoded = json_decode((string) $row['coreflux_overridden_fields'], true);
            if (is_array($decoded)) $current = array_values(array_filter(array_map('strval', $decoded)));
        }
        $remaining = array_values(array_diff($current, array_map('strval', $fields)));
        scopedUpdate('placements', $id, [
            'coreflux_overridden_fields' => $remaining === [] ? null : json_encode($remaining),
        ]);
        placementsAudit('placement.override_cleared', ['id' => $id, 'fields' => array_values($fields)], $id, [
            'before' => $before,
            'after' => placementAuditRow($id),
        ]);
        api_ok(['placement' => placementGet($id)]);
    }

    // Bulk status update — flips many placements at once. Built for the
    // post-CSV-import "I just imported 9 drafts, mark them all active"
    // flow. Single-row PATCH is still the canonical edit path; this is
    // strictly an operator-time-saver.
    //
    // POST /api/placements/placements?action=bulk_status
    // body: {"ids": [12,13,14], "status": "active"}
    //
    // Required permission: placements.manage (same as single-row PATCH).
    // Each updated row gets its own `placement.status_changed` audit so
    // the audit trail looks identical to operator-by-operator edits.
    if ($action === 'bulk_status') {
        rbac_legacy_require($user, 'placements.manage');
        $body = api_json_body();
        $ids = is_array($body['ids'] ?? null) ? array_values(array_unique(array_map('intval', $body['ids']))) : [];
        $ids = array_values(array_filter($ids, static fn ($n) => $n > 0));
        $newStatus = (string) ($body['status'] ?? '');
        if (!$ids)                                       api_error('ids[] required', 422);
        if (count($ids) > 500)                           api_error('Too many ids (max 500 per call)', 422);
        if (!in_array($newStatus, ALLOWED_STATUS, true)) {
            api_error('Invalid status', 422, ['allowed' => ALLOWED_STATUS]);
        }
        $updated = 0; $skipped = 0; $results = [];
        foreach ($ids as $pid) {
            // Capture pre-update status/start date for activation readiness
            // and draft-to-non-active rate catch-up.
            $prior = scopedFind(
                'SELECT id, status, start_date FROM placements WHERE tenant_id = :tenant_id AND id = :id AND deleted_at IS NULL',
                ['id' => $pid]
            );
            if (!$prior) {
                $skipped++;
                $results[] = ['id' => $pid, 'ok' => false, 'reason' => 'not_found'];
                continue;
            }
            if ($newStatus === 'active') {
                _placementsRequireActiveReady($pid, (string) $prior['start_date'], 'bulk_status');
            }
            $before = placementAuditRow($pid) ?? $prior;
            $rows = scopedUpdate('placements', $pid, ['status' => $newStatus]);
            if ($rows > 0) {
                $updated++;
                placementsAudit('placement.status_changed', [
                    'id'     => $pid,
                    'status' => $newStatus,
                    'via'    => 'bulk_status',
                ], $pid, [
                    'before' => $before,
                    'after' => placementAuditRow($pid),
                ]);
                $autoApproved = 0;
                if ($newStatus !== 'active'
                    && $prior && (string) $prior['status'] === 'draft'
                    && !in_array($newStatus, ['draft', 'cancelled'], true)) {
                    $autoApproved = placementsAutoApproveDraftRates($pid, $user);
                    if ($autoApproved > 0) {
                        placementsAudit('placement.rates.auto_approved_on_promotion', [
                            'placement_id'    => $pid,
                            'rate_count'      => $autoApproved,
                            'promoted_status' => $newStatus,
                            'via'             => 'bulk_status',
                        ], $pid);
                    }
                }
                $results[] = ['id' => $pid, 'ok' => true, 'rates_auto_approved' => $autoApproved];
            } else {
                $skipped++;
                $results[] = ['id' => $pid, 'ok' => false, 'reason' => 'not_found_or_no_change'];
            }
        }
        api_ok([
            'ok'                   => true,
            'updated'              => $updated,
            'skipped'              => $skipped,
            'status'               => $newStatus,
            'rates_auto_approved'  => array_sum(array_map(
                static fn ($row) => (int) ($row['rates_auto_approved'] ?? 0),
                $results
            )),
            'results'              => $results,
        ]);
    }

    if ($action === 'end') {
        $id = (int) api_query('id', 0);
        if ($id <= 0) api_error('id required', 400);
        rbac_legacy_require($user, 'placements.terminate');
        $body = api_json_body();
        $newStatus = in_array(($body['status'] ?? 'ended'), ['ended', 'cancelled'], true)
                   ? $body['status'] : 'ended';
        $before = placementAuditRow($id);
        $rows = scopedUpdate('placements', $id, [
            'status'           => $newStatus,
            'actual_end_date'  => $body['actual_end_date'] ?? date('Y-m-d'),
        ]);
        if ($rows === 0) api_error('Not found or no change', 404);
        placementsAudit('placement.ended', ['id' => $id, 'status' => $newStatus, 'reason' => $body['reason'] ?? null], $id, [
            'before' => $before,
            'after' => placementAuditRow($id),
        ]);
        api_ok(['ok' => true, 'placement' => placementGet($id)]);
    }

    if ($action === 'activate') {
        $id = (int) api_query('id', 0);
        if ($id <= 0) api_error('id required', 400);
        rbac_legacy_require($user, 'placements.manage');
        $placement = placementGet($id);
        if (!$placement) api_error('Not found', 404);
        if ((string) ($placement['status'] ?? '') === 'active') {
            api_ok(['ok' => true, 'placement' => $placement, 'rates_auto_approved' => 0]);
        }
        $before = placementAuditRow($id) ?? $placement;
        _placementsRequireActiveReady($id, (string) ($placement['start_date'] ?? date('Y-m-d')), 'activate_action');
        $rows = scopedUpdate('placements', $id, ['status' => 'active']);
        if ($rows === 0) api_error('Not found or no change', 404);
        placementsAudit('placement.status_changed', ['id' => $id, 'status' => 'active', 'via' => 'activate_action'], $id, [
            'before' => $before,
            'after' => placementAuditRow($id),
        ]);
        api_ok(['ok' => true, 'placement' => placementGet($id), 'rates_auto_approved' => 0]);
    }

    // Default POST = create
    rbac_legacy_require($user, 'placements.manage');
    $body = api_json_body();
    api_require_fields($body, ['person_id', 'title', 'start_date', 'engagement_type']);
    if (!in_array($body['engagement_type'], ALLOWED_ETYPE, true)) {
        api_error('Invalid engagement_type', 422, ['allowed' => ALLOWED_ETYPE]);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['start_date'])) {
        api_error('start_date must be YYYY-MM-DD', 422);
    }

    // person_id must belong to the same tenant
    $person = scopedFind('SELECT id FROM people WHERE tenant_id = :tenant_id AND id = :id AND deleted_at IS NULL',
        ['id' => (int) $body['person_id']]);
    if (!$person) api_error('person_id not found in this tenant', 422);

    $statusInput = $body['status'] ?? 'draft';
    if ((string) $statusInput === 'active') {
        api_error('Placements cannot be created active. Create as draft/pending_start, approve rates, then activate.', 422);
    }
    $insert = [
        'person_id'        => (int) $body['person_id'],
        'external_id'      => $body['external_id']      ?? null,
        'status'           => in_array($statusInput, ALLOWED_STATUS, true) ? $statusInput : 'draft',
        'start_date'       => $body['start_date'],
        'end_date'         => $body['end_date']         ?? null,
        'due_date'         => $body['due_date']         ?? null,
        'engagement_type'  => $body['engagement_type'],
        'worksite_state'   => $body['worksite_state']   ?? null,
        'worksite_country' => $body['worksite_country'] ?? null,
        'remote_policy'    => placementsNormalizeRemotePolicy($body['remote_policy'] ?? null),
        'title'            => $body['title'],
        'end_client_name'  => $body['end_client_name']  ?? null,
        'end_client_company_id' => !empty($body['end_client_company_id']) ? (int) $body['end_client_company_id'] : null,
        'client_approver_name'  => $body['client_approver_name']  ?? null,
        'client_approver_email' => $body['client_approver_email'] ?? null,
        'notes'            => $body['notes']            ?? null,
        'created_by_user_id' => $user['id'] ?? null,
    ];

    // If a company_id is provided, prefer its canonical name and tag it 'client'.
    // If only a free-text end_client_name is provided, upsert into companies for
    // future picks. Either path leaves us with a clean FK + display string.
    require_once __DIR__ . '/../../people/lib/companies.php';
    if (!empty($insert['end_client_company_id'])) {
        $co = companiesGet((int) $insert['end_client_company_id']);
        if ($co) {
            $insert['end_client_name'] = $co['name'];
            companiesAddRole((int) $co['id'], 'client');
            companiesBumpUsage((int) $co['id']);
        }
    } elseif (!empty($insert['end_client_name'])) {
        $cid = companiesUpsertByName(currentTenantId(), (string) $insert['end_client_name'], [
            'created_by_user_id' => $user['id'] ?? null,
        ], ['client']);
        $insert['end_client_company_id'] = $cid;
        companiesBumpUsage($cid);
    }

    $id = scopedInsert('placements', $insert);

    // Bump end-client typeahead
    if (!empty($body['end_client_name'])) {
        try {
            $pdo = getDB();
            $stmt = $pdo->prepare(
                'INSERT INTO tenant_end_clients (tenant_id, client_name, use_count, last_used_at)
                 VALUES (:tenant_id, :name, 1, NOW())
                 ON DUPLICATE KEY UPDATE use_count = use_count + 1, last_used_at = NOW()'
            );
            $stmt->execute(['tenant_id' => currentTenantId(), 'name' => $body['end_client_name']]);
        } catch (\Throwable $e) { /* non-fatal */ }
    }

    placementsAudit('placement.created', ['id' => $id, 'engagement_type' => $insert['engagement_type']], $id, [
        'after' => placementAuditRow($id),
    ]);
    api_ok(['placement' => placementGet($id)], 201);
}

if ($method === 'PATCH') {
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    rbac_legacy_require($user, 'placements.manage');
    $body = api_json_body();
    foreach (['id','tenant_id','created_at','created_by_user_id','deleted_at','coreflux_overridden_fields'] as $k) unset($body[$k]);
    if (isset($body['engagement_type']) && !in_array($body['engagement_type'], ALLOWED_ETYPE, true)) {
        api_error('Invalid engagement_type', 422);
    }
    if (isset($body['status']) && !in_array($body['status'], ALLOWED_STATUS, true)) {
        api_error('Invalid status', 422);
    }
    if (array_key_exists('remote_policy', $body)) {
        $body['remote_policy'] = placementsNormalizeRemotePolicy($body['remote_policy']);
    }
    if (!$body) api_error('No fields to update', 422);

    // Slice 2: for JobDiva-sourced placements, every field touched by a
    // user PATCH becomes a "coreflux_overridden" field — the JobDiva sync
    // writer respects this list and won't revert the edit on the next
    // pull. Placements created directly in CoreFlux (no `jd:` external_id
    // prefix) are unaffected; their fields don't need protection.
    $existing = placementGet($id);
    if (!$existing) api_error('Not found', 404);
    $before = placementAuditRow($id) ?? $existing;
    if (($body['status'] ?? null) === 'active') {
        _placementsRequireActiveReady(
            $id,
            (string) ($body['start_date'] ?? $existing['start_date']),
            'patch_status'
        );
    }
    $isJobDivaSourced = is_string($existing['external_id'] ?? null)
        && strpos((string) $existing['external_id'], 'jd:') === 0;

    $rows = scopedUpdate('placements', $id, $body);
    if ($rows === 0) api_error('Not found or no change', 404);

    if ($isJobDivaSourced) {
        $current = [];
        if (!empty($existing['coreflux_overridden_fields'])) {
            $decoded = json_decode((string) $existing['coreflux_overridden_fields'], true);
            if (is_array($decoded)) $current = array_values(array_filter(array_map('strval', $decoded)));
        }
        $merged = array_values(array_unique(array_merge($current, array_map('strval', array_keys($body)))));
        if ($merged !== $current) {
            scopedUpdate('placements', $id, ['coreflux_overridden_fields' => json_encode($merged)]);
        }
    }

    if (isset($body['status'])) {
        placementsAudit('placement.status_changed', ['id' => $id, 'status' => $body['status']], $id, [
            'before' => $before,
            'after' => placementAuditRow($id),
        ]);
    } else {
        placementsAudit('placement.updated', ['id' => $id, 'fields' => array_keys($body)], $id, [
            'before' => $before,
            'after' => placementAuditRow($id),
        ]);
    }

    // Auto-approve side effect: when a placement leaves `draft` for any
    // non-terminal state, approve every draft rate row attached to it.
    // Matches the operator mental model — "approving the placement"
    // is one action that includes the rates, not two separate clicks
    // in two separate tabs. Soft-gated by rbac inside the helper so
    // a recruiter without financials.approve doesn't get a free
    // privilege escalation.
    $autoApproved = 0;
    if (isset($body['status'])
        && (string) $body['status'] !== 'active'
        && (string) $existing['status'] === 'draft'
        && !in_array((string) $body['status'], ['draft', 'cancelled'], true)) {
        $autoApproved = placementsAutoApproveDraftRates($id, $user);
        if ($autoApproved > 0) {
            placementsAudit('placement.rates.auto_approved_on_promotion', [
                'placement_id'    => $id,
                'rate_count'      => $autoApproved,
                'promoted_status' => (string) $body['status'],
            ], $id);
        }
    }
    api_ok(['placement' => placementGet($id), 'rates_auto_approved' => $autoApproved]);
}

api_error('Method not allowed', 405);

function _placementsRequireActiveReady(int $placementId, ?string $asOf, string $via): void
{
    $asOf = $asOf ?: date('Y-m-d');
    $placement = placementAuditRow($placementId);
    $rate = placementCurrentRate($placementId, $asOf);
    if ($rate) {
        placementsAudit('placement.activation_rate_verified', [
            'placement_id' => $placementId,
            'rate_id'      => (int) ($rate['id'] ?? 0),
            'as_of'        => $asOf,
            'via'          => $via,
        ], $placementId, [
            'before' => $placement,
            'after' => $placement,
        ]);
        return;
    }

    placementsAudit('placement.activation_blocked_missing_rate', [
        'placement_id' => $placementId,
        'as_of'        => $asOf,
        'via'          => $via,
        'reason'       => 'missing_approved_rate_coverage',
    ], $placementId, [
        'before' => $placement,
        'after' => $placement,
    ]);
    api_error(
        "Placement cannot become active without an approved rate covering {$asOf}. Approve a bill/pay rate first.",
        422,
        ['placement_id' => $placementId, 'as_of' => $asOf, 'rates_auto_approved' => 0]
    );
}

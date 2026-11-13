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
require_once __DIR__ . '/../lib/end.php';
require_once __DIR__ . '/../lib/ownership.php';
require_once __DIR__ . '/../lib/commercial_ownership.php';
require_once __DIR__ . '/../lib/edit.php';
require_once __DIR__.'/../lib/company_references.php';
require_once __DIR__.'/../lib/navigation.php';
require_once __DIR__ . '/../lib/economics.php';
require_once __DIR__ . '/../lib/rate_approve.php';
require_once __DIR__ . '/../../staffing/lib/clients.php';
require_once __DIR__ . '/../../people/lib/people.php';
require_once __DIR__ . '/../../people/lib/audit.php';
require_once __DIR__ . '/../../accounting/lib/accounting.php';
require_once __DIR__ . '/../../staffing/lib/dimensions.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();
$placementTenantId = (int) placementsGraphTenantId((int) $ctx['tenant_id']);

const ALLOWED_STATUS = ['draft','pending_start','active','on_hold','ended','cancelled'];
const ALLOWED_ETYPE  = ['w2','1099','c2c','temp_to_perm','direct_hire','internal','referral'];
const ALLOWED_REMOTE = ['onsite','hybrid','remote'];

if ($method === 'GET') {
    $requested=(string)api_query('number',api_query('id',''));
    if ($requested!==''&&$requested!=='0') {
        rbac_legacy_require($user, 'placements.view');
        try{$identity=placementNavigation((int)$ctx['tenant_id'],$requested,rbac_legacy_can($user,'placements.financials.view'));}
        catch(PDOException $e){api_error('Placement history is temporarily unavailable.',503);}
        catch(InvalidArgumentException|RuntimeException $e){api_error($e->getMessage(),in_array($e->getCode(),[409,422],true)?$e->getCode():409);}
        if(!$identity)api_error('Not found',404);
        $identity['can_view_source_history']=rbac_legacy_can_any($user,['tenant_admin.integrations','integrations.jobdiva.view']);
        // Historical URLs never return a current record's edit controls under an old ID.
        if($identity['merged'])api_ok(['placement'=>null,'identity'=>$identity]);
        $id=$identity['source']['id'];
        $row = placementGet($id);
        if (!$row) api_error('Not found', 404);
        $dimensionReadiness = null;
        try {
            $asOfDate = date('Y-m-d');
            $startDate = trim((string) ($row['start_date'] ?? ''));
            $endDate = trim((string) ($row['actual_end_date'] ?? $row['end_date'] ?? ''));
            if ($startDate !== '' && $asOfDate < $startDate) $asOfDate = $startDate;
            if ($endDate !== '' && $asOfDate > $endDate) $asOfDate = $endDate;
            $dimensionContext = staffingAssignmentDimensionContext(
                (int) $ctx['tenant_id'],
                $id,
                (int) ($row['accounting_entity_id'] ?? 0),
                $asOfDate
            );
            $reportingGaps = (array) ($dimensionContext['reporting_gaps'] ?? $dimensionContext['missing'] ?? []);
            $dimensionReadiness = [
                'ready' => $reportingGaps === [],
                'as_of' => $asOfDate,
                'reporting_gaps' => array_values($reportingGaps),
                'missing_labels' => staffingDimensionMissingLabels($reportingGaps),
                'time_blockers' => staffingDimensionBlockingMissing($dimensionContext, 'time'),
                'billing_blockers' => staffingDimensionBlockingMissing($dimensionContext, 'billing'),
                'ap_blockers' => staffingDimensionBlockingMissing($dimensionContext, 'ap'),
            ];
        } catch (\Throwable $e) {
            error_log('[placements] dimension readiness failed for placement ' . $id . ': ' . $e->getMessage());
            $dimensionReadiness = [
                'ready' => false,
                'as_of' => date('Y-m-d'),
                'reporting_gaps' => [],
                'missing_labels' => [],
                'error' => 'Reporting setup check is temporarily unavailable.',
            ];
        }
        api_ok([
            'identity'    => $identity,
            'placement'   => $row,
            'dimension_readiness' => $dimensionReadiness,
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
        'end_client_company_id' => $_GET['end_client_company_id'] ?? null,
        'engagement_type' => $_GET['engagement_type'] ?? null,
        'start_after'     => $_GET['start_after']     ?? null,
        'end_before'      => $_GET['end_before']      ?? null,
        'due_before'      => $_GET['due_before']      ?? null,
        'sort'            => $_GET['sort']            ?? null,
        'dir'             => $_GET['dir']             ?? null,
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
        if (!array_is_list($fields) || array_filter($fields, static fn($field) => !is_string($field))) api_error('Choose valid field names.', 422);
        if (array_intersect($fields, placementCommercialOwnershipFields())) api_error('Review linked commercial ownership on the Contract tab before returning these fields to JobDiva.', 422);
        if (!is_array($body['review'] ?? null)) api_error('Refresh the placement before returning fields to JobDiva.', 422);
        try { $release = placementClearOverrides($id, $fields, $body['review']); }
        catch (RuntimeException|InvalidArgumentException $e) {
            api_error($e->getMessage(), in_array($e->getCode(), [403,404,409,422,503], true) ? $e->getCode() : 422);
        }
        api_ok(['placement' => placementGet($id)] + $release);
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
        $updated = 0; $skipped = 0; $failed = 0; $results = [];
        $totalAutoApproved = 0;
        foreach ($ids as $pid) {
            try {
                $result = placementApplyNativeEdit($pid, ['status'=>$newStatus], $user, 'bulk_status');
                $totalAutoApproved += $result['rates_auto_approved'];
                if ($result['changed']) $updated++; else $skipped++;
                $results[] = ['id'=>$pid, 'ok'=>true, 'changed'=>$result['changed'], 'rates_auto_approved'=>$result['rates_auto_approved']];
            } catch (\Throwable $e) {
                if ($e->getCode() === 404) $skipped++; else $failed++;
                $results[] = ['id'=>$pid, 'ok'=>false, 'reason'=>$e->getCode() === 404 ? 'not_found' : $e->getMessage()];
            }
        }
        api_ok([
            'ok' => $failed === 0, 'updated' => $updated, 'skipped' => $skipped, 'failed' => $failed,
            'status' => $newStatus, 'rates_auto_approved' => $totalAutoApproved, 'results' => $results,
        ]);
    }

    // Bulk-edit operational placement fields. Status changes stay on the
    // dedicated bulk_status path because activation has additional rate and
    // readiness rules. These fields use the same client bridge, audit trail,
    // economics reconciliation, and JobDiva override contract as a one-row
    // edit, so the next integration sync cannot silently undo the operator.
    if ($action === 'bulk_update') {
        rbac_legacy_require($user, 'placements.manage');
        $body = api_json_body();
        $ids = is_array($body['ids'] ?? null) ? array_values(array_unique(array_map('intval', $body['ids']))) : [];
        $ids = array_values(array_filter($ids, static fn ($n) => $n > 0));
        $field = (string) ($body['field'] ?? '');
        $value = $body['value'] ?? null;
        $allowedFields = ['engagement_type','end_client_company_id','remote_policy','worksite_city','worksite_state','worksite_country'];
        if (!$ids) api_error('ids[] required', 422);
        if (count($ids) > 500) api_error('Too many ids (max 500 per call)', 422);
        if (!in_array($field, $allowedFields, true)) {
            api_error('Invalid bulk field', 422, ['allowed' => $allowedFields]);
        }

        if ($field === 'engagement_type') {
            $value = strtolower(trim((string) $value));
            if (!in_array($value, ALLOWED_ETYPE, true)) {
                api_error('Invalid engagement_type', 422, ['allowed' => ALLOWED_ETYPE]);
            }
        } elseif ($field === 'remote_policy') {
            $raw = trim((string) $value);
            $value = placementsNormalizeRemotePolicy($raw);
            if ($raw !== '' && $value === null) {
                api_error('Invalid remote_policy', 422, ['allowed' => ALLOWED_REMOTE]);
            }
        } elseif ($field === 'worksite_city') {
            try { $value = placementsNormalizeWorksiteCity($value); }
            catch (InvalidArgumentException $e) { api_error($e->getMessage(), 422); }
        } elseif ($field === 'end_client_company_id') {
            $value = (int) $value;
            if ($value <= 0 || !companiesGet($value, placementsCompanyDirectoryTenantId())) api_error('Client company not found', 422);
        } else {
            $value = trim((string) $value);
            if ($value === '') api_error("{$field} cannot be blank", 422);
            if (strlen($value) > 100) api_error("{$field} is too long", 422);
        }

        $updated = 0; $skipped = 0; $failed = 0; $results = [];
        foreach ($ids as $pid) {
            try {
                $result = placementApplyNativeEdit($pid, [$field=>$value], $user, 'bulk_update');
                if ($result['changed']) $updated++; else $skipped++;
                $results[] = ['id'=>$pid, 'ok'=>true, 'changed'=>$result['changed']];
            } catch (\Throwable $e) {
                if ($e->getCode() === 404) $skipped++; else $failed++;
                $results[] = ['id'=>$pid, 'ok'=>false, 'reason'=>$e->getCode() === 404 ? 'not_found' : $e->getMessage()];
            }
        }
        api_ok([
            'ok' => $failed === 0,
            'updated' => $updated,
            'skipped' => $skipped,
            'failed' => $failed,
            'field' => $field,
            'results' => $results,
        ]);
    }

    if ($action === 'end') {
        $id = (int) api_query('id', 0);
        if ($id <= 0) api_error('id required', 400);
        rbac_legacy_require($user, 'placements.terminate');
        $body = api_json_body();
        try {
            $result = placementEnd($id, $body);
        } catch (InvalidArgumentException | RuntimeException $e) {
            api_error($e->getMessage(), in_array($e->getCode(), [403,404,422], true) ? $e->getCode() : 422);
        }
        api_ok(['ok' => true, 'replayed' => !$result['changed'], 'placement' => placementGet($id)]);
    }

    if ($action === 'activate') {
        $id = (int) api_query('id', 0);
        if ($id <= 0) api_error('id required', 400);
        rbac_legacy_require($user, 'placements.manage');
        try { $result = placementApplyNativeEdit($id, ['status'=>'active'], $user, 'activate_action'); }
        catch (InvalidArgumentException | RuntimeException $e) {
            api_error($e->getMessage(), in_array($e->getCode(), [403,404,409,422,503], true) ? $e->getCode() : 422);
        }
        api_ok(['ok'=>true, 'placement'=>placementGet($id), 'rates_auto_approved'=>$result['rates_auto_approved']]);
    }

    // Default POST = create
    rbac_legacy_require($user, 'placements.manage');
    $body = api_json_body();
    try { $body['worksite_city'] = placementsNormalizeWorksiteCity($body['worksite_city'] ?? null); }
    catch (InvalidArgumentException $e) { api_error($e->getMessage(), 422); }
    api_require_fields($body, ['title', 'start_date', 'engagement_type']);
    if (!in_array($body['engagement_type'], ALLOWED_ETYPE, true)) {
        api_error('Invalid engagement_type', 422, ['allowed' => ALLOWED_ETYPE]);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['start_date'])) {
        api_error('start_date must be YYYY-MM-DD', 422);
    }
    foreach (['recruiter_email' => 'recruiter', 'account_manager_email' => 'account manager'] as $field => $label) {
        $email = trim((string) ($body[$field] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            api_error("Enter a valid {$label} email", 422);
        }
    }

    try{$personId=companyRelationshipId($body['person_id']??null)??0;}
    catch(InvalidArgumentException $e){api_error($e->getMessage(),422);}
    $newPersonBody = is_array($body['new_person'] ?? null) ? $body['new_person'] : null;
    if ($personId > 0 && $newPersonBody !== null) {
        api_error('Choose either an existing person or a new person, not both', 422);
    }
    if ($personId <= 0 && $newPersonBody === null) {
        api_error('Choose an existing person or enter a new person', 422, ['fields' => ['person_id', 'new_person']]);
    }

    $newPersonInsert = null;
    if ($newPersonBody !== null) {
        rbac_legacy_require($user, 'people.manage');
        api_require_fields($newPersonBody, ['first_name', 'last_name', 'email_primary']);

        $firstName = trim((string) $newPersonBody['first_name']);
        $lastName = trim((string) $newPersonBody['last_name']);
        $email = strtolower(trim((string) $newPersonBody['email_primary']));
        $phone = trim((string) ($newPersonBody['phone_primary'] ?? ''));
        if ($firstName === '' || $lastName === '') api_error('First and last name are required', 422);
        if (strlen($firstName) > 100 || strlen($lastName) > 100) api_error('Person name is too long', 422);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) api_error('Enter a valid work email', 422);
        if (strlen($email) > 255) api_error('Work email is too long', 422);
        if (strlen($phone) > 50) api_error('Phone number is too long', 422);

        $existingPerson = peopleFindByEmail($email);
        if ($existingPerson) {
            api_error('A person with this email already exists', 409, [
                'conflict_id' => (int) $existingPerson['id'],
                'conflict' => $existingPerson,
            ]);
        }

        $newPersonInsert = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email_primary' => $email,
            'phone_primary' => $phone !== '' ? $phone : null,
            'classification' => placementPersonClassification((string) $body['engagement_type']),
            'status' => 'active',
            'work_auth_status' => 'unknown',
            'requires_sponsorship' => 0,
            'source' => 'placement_create',
            'created_by_user_id' => $user['id'] ?? null,
        ];
    } else {
        // Existing person must belong to the current tenant.
        $person = peopleGet($personId);
        if (!$person) api_error('person_id not found in this tenant', 422);
    }

    $statusInput = $body['status'] ?? 'draft';
    if ((string) $statusInput === 'active') {
        api_error('Placements cannot be created active. Create as draft/pending_start, approve rates, then activate.', 422);
    }

    $accountingTenantId = effectiveTenantIdForModule('accounting', (int) $ctx['tenant_id']) ?? (int) $ctx['tenant_id'];
    try {
        companyRelationshipId($body['accounting_entity_id']??null);
        $assignmentEntityId = accountingValidateActiveEntityId(
            $accountingTenantId,
            $body['accounting_entity_id'] ?? null
        ) ?? (int) accountingDefaultEntity($accountingTenantId)['id'];
    } catch (\Throwable $e) {
        api_error($e->getMessage(), 422);
    }

    $createdPersonId = null;
    try {
        $id=placementNativeDirectoryWrite((int)currentTenantId(),$placementTenantId,static function(array $scope,PDO $pdo)use($newPersonInsert,$personId,$user,$body,$statusInput,$assignmentEntityId,$accountingTenantId,$placementTenantId,&$createdPersonId):int{
        if((int)effectiveTenantIdForRequest()!==$scope['owner'])throw new RuntimeException('The placement request scope changed. Refresh before saving.',409);
        $accountingScope=companyRelationshipScope($pdo,$scope['workspace'],'accounting');
        if($accountingScope['owner']!==$accountingTenantId)throw new RuntimeException('The accounting directory changed. Refresh before saving.',409);
        placementResolveNativeAccountingEntity($pdo,$scope,$assignmentEntityId);
        $jobId=placementResolveNativeJob($pdo,$scope,$body['staffing_job_id']??null);
        if ($newPersonInsert !== null) {
            // Person + placement is one unit of work. A placement failure must
            // never leave an orphaned person in the directory.
            $personId = peopleCreateForPlacement(
                $newPersonInsert,
                (string) $newPersonInsert['classification'],
                isset($user['id']) ? (int) $user['id'] : null
            );
            $createdPersonId = $personId;
            companySourceAudit($pdo,$scope['workspace'],$scope['owner'],'people',$personId,'people.created',['classification'],(int)$user['id'],['classification'=>$newPersonInsert['classification'],'source'=>'placement_create']);
        }

        $q=$pdo->prepare('SELECT id FROM people WHERE tenant_id=? AND id=? AND deleted_at IS NULL FOR UPDATE');$q->execute([$scope['owner'],$personId]);
        if(!$q->fetchColumn())throw new RuntimeException('The selected person is no longer in this placement directory.',409);

        $insert = [
            'person_id'        => $personId,
            'external_id'      => $body['external_id']      ?? null,
            'status'           => in_array($statusInput, ALLOWED_STATUS, true) ? $statusInput : 'draft',
            'start_date'       => $body['start_date'],
            'end_date'         => $body['end_date']         ?? null,
            'due_date'         => $body['due_date']         ?? null,
            'engagement_type'  => $body['engagement_type'],
            'worksite_city'    => placementsNormalizeWorksiteCity($body['worksite_city'] ?? null),
            'worksite_state'   => $body['worksite_state']   ?? null,
            'worksite_country' => $body['worksite_country'] ?? null,
            'remote_policy'    => placementsNormalizeRemotePolicy($body['remote_policy'] ?? null),
            'branch'           => $body['branch']           ?? null,
            'service_line'     => $body['service_line']     ?? null,
            'workers_comp_class' => $body['workers_comp_class'] ?? null,
            'department'       => $body['department']       ?? null,
            'cost_center'      => $body['cost_center']      ?? null,
            'accounting_entity_id' => $assignmentEntityId,
            'staffing_job_id'  => $jobId,
            'title'            => $body['title'],
            'end_client_name'  => $body['end_client_name']  ?? null,
            'end_client_company_id' => !empty($body['end_client_company_id']) ? (int) $body['end_client_company_id'] : null,
            'recruiter_name'        => trim((string) ($body['recruiter_name'] ?? '')) ?: null,
            'recruiter_email'       => strtolower(trim((string) ($body['recruiter_email'] ?? ''))) ?: null,
            'account_manager_name'  => trim((string) ($body['account_manager_name'] ?? '')) ?: null,
            'account_manager_email' => strtolower(trim((string) ($body['account_manager_email'] ?? ''))) ?: null,
            'client_approver_name'  => $body['client_approver_name']  ?? null,
            'client_approver_email' => $body['client_approver_email'] ?? null,
            'notes'            => $body['notes']            ?? null,
            'created_by_user_id' => $user['id'] ?? null,
        ];

        $insert = array_replace($insert, placementsResolveCompanyRoles($placementTenantId, $body, [], $user['id'] ?? null, currentTenantId()));

        $insert['coreflux_overridden_fields']=json_encode(array_values(array_diff(array_keys($insert),['created_by_user_id'])),JSON_THROW_ON_ERROR);
        $id = scopedInsert('placements', $insert);

        // Bump end-client typeahead.
        if (!empty($body['end_client_name'])) {
            try {
                $endClientPdo = getDB();
                $stmt = $endClientPdo->prepare(
                    'INSERT INTO tenant_end_clients (tenant_id, client_name, use_count, last_used_at)
                     VALUES (:tenant_id, :name, 1, NOW())
                     ON DUPLICATE KEY UPDATE use_count = use_count + 1, last_used_at = NOW()'
                );
                $stmt->execute(['tenant_id' => currentTenantId(), 'name' => $body['end_client_name']]);
            } catch (\Throwable $e) { /* non-fatal */ }
        }

        $graph=placementEconomicsReconcile($scope['owner'],$id);
        if(!$graph['available']||$graph['errors'])throw new RuntimeException('The placement commercial graph could not be created. Review the company links and try again.',422);
        placementRequiredAudit('placement.created',['id'=>$id,'engagement_type'=>$insert['engagement_type'],'after'=>placementAuditRow($id)],$id);
        return$id;
        });
    } catch (\Throwable $e) {
        // Close the small race between duplicate-email validation and INSERT.
        // When another request wins, return the same actionable conflict shape.
        if ($newPersonInsert !== null && $e instanceof \PDOException && (string) $e->getCode() === '23000') {
            $conflict = peopleFindByEmail((string) $newPersonInsert['email_primary']);
            if ($conflict) {
                api_error('A person with this email already exists', 409, [
                    'conflict_id' => (int) $conflict['id'],
                    'conflict' => $conflict,
                ]);
            }
        }
        if(!($e instanceof PDOException)&&($e instanceof RuntimeException||$e instanceof InvalidArgumentException)){
            api_error($e->getMessage(),in_array($e->getCode(),[403,404,409,422,503],true)?$e->getCode():422);
        }
        throw $e;
    }

    // Create a stable employment link only for this explicitly created W-2 person.
    // It is intentionally non-fatal and runs after the atomic create commits.
    if ($createdPersonId !== null && ($newPersonInsert['classification'] ?? null) === 'w2') {
        require_once __DIR__ . '/../../people/lib/employees.php';
        require_once __DIR__.'/../../people/lib/employment_links.php';
        try { peopleEmploymentForNewPerson($createdPersonId,(int)$user['id']); }
        catch (\Throwable $e) { error_log('New person employment requires review: '.$createdPersonId); }
    }

    $response = [
        'placement' => placementGet($id),
        'person_created' => $createdPersonId !== null,
    ];
    if ($createdPersonId !== null) $response['person'] = peopleGet($createdPersonId);
    api_ok($response, 201);
}

if ($method === 'PATCH') {
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);
    rbac_legacy_require($user, 'placements.manage');
    $body = api_json_body();
    foreach (['id','tenant_id','created_at','created_by_user_id','deleted_at','coreflux_overridden_fields'] as $k) unset($body[$k]);
    if (array_key_exists('worksite_city', $body)) {
        try { $body['worksite_city'] = placementsNormalizeWorksiteCity($body['worksite_city']); }
        catch (InvalidArgumentException $e) { api_error($e->getMessage(), 422); }
    }
    if (isset($body['engagement_type']) && !in_array($body['engagement_type'], ALLOWED_ETYPE, true)) {
        api_error('Invalid engagement_type', 422);
    }
    if (isset($body['status']) && !in_array($body['status'], ALLOWED_STATUS, true)) {
        api_error('Invalid status', 422);
    }
    if (array_key_exists('remote_policy', $body)) {
        $body['remote_policy'] = placementsNormalizeRemotePolicy($body['remote_policy']);
    }
    if (array_key_exists('accounting_entity_id', $body)) {
        $accountingTenantId = effectiveTenantIdForModule('accounting', (int) $ctx['tenant_id']) ?? (int) $ctx['tenant_id'];
        try {
            companyRelationshipId($body['accounting_entity_id']);
            $body['accounting_entity_id'] = accountingValidateActiveEntityId(
                $accountingTenantId,
                $body['accounting_entity_id']
            ) ?? (int) accountingDefaultEntity($accountingTenantId)['id'];
        } catch (\Throwable $e) {
            api_error($e->getMessage(), 422);
        }
    }
    foreach (['branch', 'service_line', 'workers_comp_class', 'department', 'cost_center'] as $dimensionField) {
        if (array_key_exists($dimensionField, $body)) {
            $body[$dimensionField] = trim((string) $body[$dimensionField]);
            if ($body[$dimensionField] === '') $body[$dimensionField] = null;
        }
    }
    foreach (['recruiter', 'account_manager'] as $ownerPrefix) {
        $nameField = $ownerPrefix . '_name';
        $emailField = $ownerPrefix . '_email';
        if (array_key_exists($nameField, $body)) {
            $body[$nameField] = trim((string) $body[$nameField]) ?: null;
        }
        if (array_key_exists($emailField, $body)) {
            $ownerEmail = strtolower(trim((string) $body[$emailField]));
            if ($ownerEmail !== '' && !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
                api_error('Enter a valid ' . str_replace('_', ' ', $ownerPrefix) . ' email', 422);
            }
            $body[$emailField] = $ownerEmail ?: null;
        }
    }
    if (!$body) api_error('No fields to update', 422);

    try { $result = placementApplyNativeEdit($id, $body, $user, 'patch_status'); }
    catch (InvalidArgumentException | RuntimeException $e) {
        api_error($e->getMessage(), in_array($e->getCode(), [403,404,409,422,503], true) ? $e->getCode() : 422);
    }
    api_ok(['placement'=>placementGet($id), 'rates_auto_approved'=>$result['rates_auto_approved']]);
}

api_error('Method not allowed', 405);

/**
 * Keep People classification aligned with the placement's engagement model.
 * The browser shows the same mapping, but the server remains authoritative.
 */
function placementPersonClassification(string $engagementType): string
{
    return match ($engagementType) {
        '1099' => '1099',
        'c2c' => 'c2c',
        'temp_to_perm' => 'temp',
        'direct_hire' => 'perm',
        'referral' => 'candidate',
        default => 'w2',
    };
}

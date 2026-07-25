<?php
/** Placement economic parties, terms, PWP, and readiness. */

declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/placements.php';
require_once __DIR__ . '/../lib/economics.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
$method = api_method();
$action = (string) ($_GET['action'] ?? '');
$placementId = (int) ($_GET['placement_id'] ?? 0);

function placementEconomicsApiPurpose(string $channel): string
{
    return $channel === 'ar' ? 'billing' : $channel;
}

function placementEconomicsApiCadence(int $tenantId, string $channel, mixed $raw): array
{
    $cadence = strtolower(trim((string) $raw));
    if (!in_array($cadence, ['weekly','biweekly','semimonthly','monthly','adhoc'], true)) {
        api_error('Frequency must be weekly, biweekly, semimonthly, monthly, or adhoc', 422);
    }
    $purpose = placementEconomicsApiPurpose($channel);
    if (!in_array($purpose, ['billing','ap','payroll'], true)) api_error('This participant does not use a payment frequency', 422);
    $cycleId = placementEconomicsEnsureStandardCycle($tenantId, $purpose, $cadence);
    if (!$cycleId) api_error('Could not resolve the requested payment frequency', 422);
    return ['cadence' => $cadence, 'cycle_id' => $cycleId, 'purpose' => $purpose];
}

if ($method === 'GET') {
    rbac_legacy_require($user, 'placements.view');
    if ($placementId <= 0) api_error('placement_id required', 400);
    $context = placementEconomicsContext($tenantId, $placementId, true);
    if (empty($context['available'])) {
        api_error('Staffing economic graph is not available. Apply migration 126.', 409, $context['reconcile'] ?? []);
    }
    api_ok($context);
}

if ($method === 'POST' && $action === 'reconcile') {
    rbac_legacy_require($user, 'placements.manage');
    if ($placementId <= 0) api_error('placement_id required', 400);
    $result = placementEconomicsReconcile($tenantId, $placementId);
    placementsAudit('placement.economics.reconciled', $result, $placementId);
    api_ok(['result' => $result, 'context' => placementEconomicsContext($tenantId, $placementId, false)]);
}

if ($method === 'POST' && $action === 'party') {
    rbac_legacy_require($user, 'placements.manage');
    if ($placementId <= 0) api_error('placement_id required', 400);
    $body = api_json_body();
    api_require_fields($body, ['role', 'display_name', 'settlement_channel']);
    $channel = (string) $body['settlement_channel'];
    if (!in_array($channel, ['ar','ap','payroll','none'], true)) api_error('Invalid settlement_channel', 422);
    $role = (string) $body['role'];
    if (!in_array($role, [
        'client','end_client','vendor','c2c_vendor','worker','employee',
        'msp','prime_vendor','sub_vendor','referrer','commission_recipient',
        'recruiter','account_manager','other',
    ], true)) {
        api_error('Invalid economic party role', 422);
    }

    $companyId = !empty($body['company_id']) ? (int) $body['company_id'] : null;
    $personId = !empty($body['person_id']) ? (int) $body['person_id'] : null;
    $userId = !empty($body['user_id']) ? (int) $body['user_id'] : null;
    $displayName = trim((string) $body['display_name']);
    if ($personId) {
        $person = scopedFind(
            'SELECT id, CONCAT_WS(" ", first_name, last_name) AS name
               FROM people WHERE tenant_id = :tenant_id AND id = :id',
            ['id' => $personId]
        );
        if (!$person) api_error('person_id not found in this tenant', 422);
        $displayName = trim((string) $person['name']) ?: $displayName;
    }
    if ($userId) {
        $memberSt = getDB()->prepare(
            'SELECT u.id, u.name, u.email
               FROM users u
               JOIN ' . membershipReadSourceSql() . ' m ON m.user_id = u.id
              WHERE m.tenant_id = :t AND u.id = :u AND u.is_active = 1
              LIMIT 1'
        );
        $memberSt->execute(['t' => $tenantId, 'u' => $userId]);
        $member = $memberSt->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$member) api_error('user_id is not active in this tenant', 422);
        $displayName = trim((string) ($member['name'] ?: $member['email'])) ?: $displayName;
    }
    if ($channel === 'payroll' && !$userId && !$personId) {
        api_error('Payroll participants must reference an internal user or person', 422);
    }
    if ($channel === 'payroll' && $companyId) {
        api_error('Companies are paid through accounts payable, not payroll', 422);
    }
    if ($channel === 'ap' && $userId) {
        api_error('Internal users are paid through payroll, not accounts payable', 422);
    }
    if ($channel === 'ar' && (!$companyId || !in_array($role, ['client','end_client'], true))) {
        api_error('A client receivable must reference a client company', 422);
    }
    if ($channel === 'ar') {
        $receivable = getDB()->prepare(
            'SELECT id FROM placement_economic_parties
              WHERE tenant_id = :t AND placement_id = :p AND active = 1
                AND settlement_channel = "ar" LIMIT 1'
        );
        $receivable->execute(['t' => $tenantId, 'p' => $placementId]);
        if ($receivable->fetchColumn()) {
            api_error('This placement already has a bill-to client. Edit its frequency and payment terms instead.', 409);
        }
    }
    if ($displayName === '') api_error('display_name required', 422);
    if ($companyId) {
        $company = companiesGet($companyId);
        if (!$company) api_error('company_id not found in this tenant', 422);
        $displayName = (string) $company['name'];
        companiesAddRole($companyId, $channel === 'ar' ? 'client' : 'vendor');
    } elseif ($channel === 'ap' && !$personId) {
        $companyId = companiesUpsertByName($tenantId, $displayName, [
            'created_by_user_id' => $user['id'] ?? null,
        ], ['vendor']);
    }

    $terms = placementEconomicsNormaliseTerms((string) ($body['payment_terms'] ?? 'NET30'));
    if ($channel === 'ar' && placementEconomicsTermsArePwp($terms)) api_error('Paid when paid applies to AP, not client invoices', 422);
    $pwp = $channel === 'ap' && (!empty($body['pwp_enabled']) || placementEconomicsTermsArePwp($terms));
    $frequency = null;
    if ($channel !== 'none') {
        $frequency = placementEconomicsApiCadence(
            $tenantId,
            $channel,
            $body['cadence'] ?? ($channel === 'ar' ? 'monthly' : 'biweekly')
        );
    }
    $vendor = null;
    if ($channel === 'ap') {
        $vendor = placementEconomicsEnsureVendor(
            $tenantId,
            $displayName,
            $companyId,
            $personId ? '1099_individual' : (string) ($body['vendor_type'] ?? 'w9_business'),
            $terms,
            $pwp,
            $placementId
        );
    }
    $feeBasis = (string) ($body['fee_basis'] ?? 'none');
    if ($channel === 'ap' && $feeBasis === 'none' && in_array($role, ['vendor','c2c_vendor','worker'], true)) {
        $placementRow = scopedFind(
            'SELECT engagement_type FROM placements WHERE tenant_id = :tenant_id AND id = :id',
            ['id' => $placementId]
        );
        $labor = getDB()->prepare(
            'SELECT 1 FROM placement_economic_parties
              WHERE tenant_id = :t AND placement_id = :p AND active = 1
                AND money_flow = "payable" AND fee_basis = "pay_rate" LIMIT 1'
        );
        $labor->execute(['t' => $tenantId, 'p' => $placementId]);
        $nonW2 = in_array(strtolower((string) ($placementRow['engagement_type'] ?? '')), ['1099','c2c','direct_hire'], true);
        if ($role === 'c2c_vendor' || $role === 'worker' || ($nonW2 && !$labor->fetchColumn())) {
            $feeBasis = 'pay_rate';
        }
    }
    $sourceRef = $role === 'c2c_vendor'
        ? 'corp:' . $placementId
        : 'manual:' . bin2hex(random_bytes(8));
    $partyId = placementEconomicsUpsertParty($tenantId, $placementId, [
        'source_ref' => $sourceRef,
        'source_type' => $role === 'c2c_vendor' ? 'corp' : 'manual',
        'source_id' => $role === 'c2c_vendor' ? $placementId : null,
        'role' => $role,
        'display_name' => $displayName,
        'company_id' => $companyId,
        'person_id' => $personId,
        'user_id' => $userId,
        'ap_vendor_id' => $vendor['id'] ?? null,
        'money_flow' => $channel === 'ar' ? 'receivable' : ($channel === 'none' ? 'informational' : 'payable'),
        'settlement_channel' => $channel,
        'fee_basis' => $feeBasis,
        'fee_pct' => $body['fee_pct'] ?? null,
        'fee_flat' => $body['fee_flat'] ?? null,
        'payment_terms' => in_array($channel, ['ar','ap'], true) ? $terms : null,
        'pwp_enabled' => $channel === 'ap' ? $pwp : false,
        'operating_cycle_id' => $frequency['cycle_id'] ?? null,
        'effective_from' => $body['effective_from'] ?? null,
        'effective_to' => $body['effective_to'] ?? null,
        'source_managed' => 0,
        'created_by_user_id' => $user['id'] ?? null,
    ]);
    $partyOverrides = ['operating_cycle_id' => $frequency['cycle_id'] ?? null];
    if (in_array($channel, ['ar','ap'], true)) $partyOverrides['payment_terms'] = $terms;
    if ($channel === 'ap') $partyOverrides['pwp_enabled'] = $pwp;
    placementEconomicsUpdateParty($tenantId, $partyId, $partyOverrides);
    if ($role === 'c2c_vendor' && $companyId && $vendor) {
        getDB()->prepare(
            'INSERT INTO placement_corp_details
                (placement_id, tenant_id, company_id, ap_vendor_id, corp_legal_name,
                 payment_terms_override, pwp_enabled)
             VALUES (:p, :t, :c, :v, :name, :terms, :pwp)
             ON DUPLICATE KEY UPDATE
                company_id = VALUES(company_id), ap_vendor_id = VALUES(ap_vendor_id),
                corp_legal_name = VALUES(corp_legal_name),
                payment_terms_override = VALUES(payment_terms_override),
                pwp_enabled = VALUES(pwp_enabled)'
        )->execute([
            'p' => $placementId, 't' => $tenantId, 'c' => $companyId,
            'v' => (int) $vendor['id'], 'name' => $displayName,
            'terms' => $terms, 'pwp' => $pwp ? 1 : 0,
        ]);
        scopedUpdate('placements', $placementId, [
            'vendor_pay_cycle' => $frequency['cadence'] ?? 'biweekly',
            'ap_operating_cycle_id' => $frequency['cycle_id'] ?? null,
            'vendor_payment_terms_override' => $terms,
            'vendor_pwp_enabled' => $pwp ? 1 : 0,
        ]);
    }
    placementsAudit('placement.economic_party.added', [
        'placement_id' => $placementId, 'economic_party_id' => $partyId,
        'role' => $role, 'company_id' => $companyId, 'ap_vendor_id' => $vendor['id'] ?? null,
    ], $placementId);
    api_ok(['id' => $partyId, 'context' => placementEconomicsContext($tenantId, $placementId, false)], 201);
}

if ($method === 'PATCH') {
    rbac_legacy_require($user, 'placements.manage');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $before = scopedFind(
        'SELECT * FROM placement_economic_parties WHERE tenant_id = :tenant_id AND id = :id',
        ['id' => $id]
    );
    if (!$before) api_error('Economic party not found', 404);
    $body = api_json_body();
    if (array_key_exists('cadence', $body)) {
        $frequency = placementEconomicsApiCadence($tenantId, (string) $before['settlement_channel'], $body['cadence']);
        $body['operating_cycle_id'] = $frequency['cycle_id'];
    }
    if (array_key_exists('payment_terms', $body)) {
        $body['payment_terms'] = placementEconomicsNormaliseTerms((string) $body['payment_terms']);
        if ($before['settlement_channel'] === 'ar' && placementEconomicsTermsArePwp((string) $body['payment_terms'])) {
            api_error('Paid when paid applies to AP, not client invoices', 422);
        }
        if ($before['settlement_channel'] === 'ap') {
            $body['pwp_enabled'] = placementEconomicsTermsArePwp((string) $body['payment_terms']);
        }
    }
    if (array_key_exists('operating_cycle_id', $body) && $body['operating_cycle_id'] !== null && $body['operating_cycle_id'] !== '') {
        $cycle = scopedFind(
            'SELECT id, purpose FROM staffing_operating_cycles
              WHERE tenant_id = :tenant_id AND id = :id AND active = 1',
            ['id' => (int) $body['operating_cycle_id']]
        );
        $expectedPurpose = $before['settlement_channel'] === 'ar' ? 'billing' : $before['settlement_channel'];
        if (!$cycle || $cycle['purpose'] !== $expectedPurpose) {
            api_error("Cycle must be an active {$expectedPurpose} cycle", 422);
        }
    }
    if (!placementEconomicsUpdateParty($tenantId, $id, $body)) api_error('No fields changed', 422);
    $placementUpdates = [];
    $cadence = isset($frequency) ? (string) $frequency['cadence'] : null;
    $cycleId = array_key_exists('operating_cycle_id', $body) ? ($body['operating_cycle_id'] ?: null) : null;
    $terms = array_key_exists('payment_terms', $body) ? (string) $body['payment_terms'] : null;
    $sourceType = (string) $before['source_type'];
    $role = (string) $before['role'];
    $channel = (string) $before['settlement_channel'];
    if ($sourceType === 'placement' && $role === 'end_client') {
        if ($cadence !== null) $placementUpdates['client_bill_cycle'] = $cadence;
        if (array_key_exists('operating_cycle_id', $body)) $placementUpdates['billing_operating_cycle_id'] = $cycleId;
        if ($terms !== null) $placementUpdates['client_payment_terms_override'] = $terms;
    }
    if (($sourceType === 'worker' && $channel === 'ap') || $sourceType === 'corp') {
        if ($cadence !== null) $placementUpdates['vendor_pay_cycle'] = $cadence;
        if (array_key_exists('operating_cycle_id', $body)) $placementUpdates['ap_operating_cycle_id'] = $cycleId;
        if ($terms !== null) $placementUpdates['vendor_payment_terms_override'] = $terms;
        if (array_key_exists('pwp_enabled', $body)) $placementUpdates['vendor_pwp_enabled'] = !empty($body['pwp_enabled']) ? 1 : 0;
    }
    if ($sourceType === 'worker' && $channel === 'payroll') {
        if ($cadence !== null) $placementUpdates['vendor_pay_cycle'] = $cadence;
        if (array_key_exists('operating_cycle_id', $body)) $placementUpdates['payroll_operating_cycle_id'] = $cycleId;
    }
    if ($placementUpdates) scopedUpdate('placements', (int) $before['placement_id'], $placementUpdates);
    if ((string) $before['source_type'] === 'corp') {
        $corpUpdates = [];
        $corpParams = ['t' => $tenantId, 'p' => (int) $before['placement_id']];
        if (array_key_exists('payment_terms', $body)) {
            $corpUpdates[] = 'payment_terms_override = :terms';
            $corpParams['terms'] = placementEconomicsNormaliseTerms((string) $body['payment_terms']);
        }
        if (array_key_exists('pwp_enabled', $body)) {
            $corpUpdates[] = 'pwp_enabled = :pwp';
            $corpParams['pwp'] = !empty($body['pwp_enabled']) ? 1 : 0;
        }
        if ($corpUpdates) {
            getDB()->prepare(
                'UPDATE placement_corp_details SET ' . implode(', ', $corpUpdates) .
                ' WHERE tenant_id = :t AND placement_id = :p'
            )->execute($corpParams);
        }
    }
    $after = scopedFind(
        'SELECT * FROM placement_economic_parties WHERE tenant_id = :tenant_id AND id = :id',
        ['id' => $id]
    );
    placementsAudit('placement.economic_party.updated', [
        'placement_id' => (int) $before['placement_id'],
        'economic_party_id' => $id,
        'fields' => array_keys($body),
    ], (int) $before['placement_id'], ['before' => $before, 'after' => $after]);
    api_ok(['ok' => true, 'party' => $after]);
}

if ($method === 'DELETE') {
    rbac_legacy_require($user, 'placements.manage');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $row = scopedFind(
        'SELECT * FROM placement_economic_parties WHERE tenant_id = :tenant_id AND id = :id',
        ['id' => $id]
    );
    if (!$row) api_error('Economic party not found', 404);
    if (!empty($row['source_managed'])) {
        api_error('Remove the source chain/referral/commission record instead of deleting its economic projection.', 409);
    }
    if ((string) $row['source_type'] === 'corp') {
        getDB()->prepare(
            'DELETE FROM placement_corp_details WHERE tenant_id = :t AND placement_id = :p'
        )->execute(['t' => $tenantId, 'p' => (int) $row['placement_id']]);
    }
    placementEconomicsUpdateParty($tenantId, $id, ['active' => 0]);
    placementsAudit('placement.economic_party.removed', [
        'placement_id' => (int) $row['placement_id'], 'economic_party_id' => $id,
    ], (int) $row['placement_id']);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);

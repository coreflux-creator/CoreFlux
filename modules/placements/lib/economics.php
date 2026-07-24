<?php
/**
 * Canonical placement economics graph.
 *
 * Identity lives in companies/people/users. This layer describes how those
 * parties participate in a placement and projects AP recipients into the
 * shared vendor graph. Source-specific placement tables are reconciled here.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../people/lib/companies.php';

function placementEconomicsNormaliseTerms(?string $terms, string $fallback = 'NET30'): string
{
    $raw = strtoupper(trim((string) $terms));
    if ($raw === '') return $fallback;
    $raw = str_replace([' ', '-', '_'], '', $raw);
    if (in_array($raw, ['DUEONRECEIPT', 'RECEIPT', 'IMMEDIATE', 'NET0'], true)) return 'DUE_ON_RECEIPT';
    if (preg_match('/^NET(\d{1,3})$/', $raw, $m)) return 'NET' . (int) $m[1];
    if ($raw === 'PWP' || $raw === 'PAIDWHENPAID' || $raw === 'PAYWHENPAID') return 'PWP';
    if (preg_match('/^(?:PWP|PAIDWHENPAID|PAYWHENPAID)NET(\d{1,3})$/', $raw, $m)) {
        return 'PWP_NET' . (int) $m[1];
    }
    return $fallback;
}

function placementEconomicsTermsArePwp(?string $terms): bool
{
    return str_starts_with(placementEconomicsNormaliseTerms($terms), 'PWP');
}

function placementEconomicsTermsDays(?string $terms, int $fallback = 30): int
{
    $normal = placementEconomicsNormaliseTerms($terms, 'NET' . $fallback);
    if ($normal === 'DUE_ON_RECEIPT') return 0;
    if (preg_match('/NET(\d{1,3})$/', $normal, $m)) return (int) $m[1];
    return 0;
}

function placementEconomicsResolvedTerms(?string $terms, bool $pwp, string $fallback = 'NET30'): string
{
    $normal = placementEconomicsNormaliseTerms($terms, $fallback);
    if (!$pwp || placementEconomicsTermsArePwp($normal)) return $normal;
    return str_starts_with($normal, 'NET') ? 'PWP_' . $normal : 'PWP';
}

function placementEconomicsEnsureVendor(
    int $tenantId,
    string $displayName,
    ?int $companyId,
    string $vendorType,
    ?string $terms = null,
    ?bool $pwp = null,
    ?int $placementId = null
): ?array {
    $displayName = trim($displayName);
    if ($tenantId <= 0 || $displayName === '') return null;
    if (!in_array($vendorType, ['1099_individual','c2c_corp','w9_business','utility','other'], true)) {
        $vendorType = 'other';
    }

    $pdo = getDB();
    $existing = null;
    if ($companyId) {
        $st = $pdo->prepare(
            'SELECT * FROM ap_vendors_index
              WHERE tenant_id = :t AND company_id = :c
              ORDER BY id ASC LIMIT 1'
        );
        $st->execute(['t' => $tenantId, 'c' => $companyId]);
        $existing = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
    if (!$existing) {
        $st = $pdo->prepare(
            'SELECT * FROM ap_vendors_index
              WHERE tenant_id = :t AND vendor_name = :n
              ORDER BY id ASC LIMIT 1'
        );
        $st->execute(['t' => $tenantId, 'n' => $displayName]);
        $existing = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    $normalTerms = placementEconomicsNormaliseTerms(
        $terms,
        (string) ($existing['default_terms'] ?? 'NET30')
    );
    $normalPwp = $pwp === null
        ? (int) ($existing['default_pwp'] ?? (placementEconomicsTermsArePwp($normalTerms) ? 1 : 0))
        : ($pwp ? 1 : 0);

    if ($companyId) {
        try {
            companiesAddRole($companyId, 'vendor');
            companiesBumpUsage($companyId);
        } catch (\Throwable $e) {
            error_log('[placement economics] company vendor role skipped: ' . $e->getMessage());
        }
    }

    if ($existing) {
        $pdo->prepare(
            'UPDATE ap_vendors_index
                SET vendor_name = :name,
                    company_id = COALESCE(:company_id, company_id),
                    vendor_type = :vendor_type,
                    placement_id_last = COALESCE(:placement_id, placement_id_last)
              WHERE tenant_id = :t AND id = :id'
        )->execute([
            'name' => $displayName,
            'company_id' => $companyId,
            'vendor_type' => $vendorType,
            'placement_id' => $placementId,
            't' => $tenantId,
            'id' => (int) $existing['id'],
        ]);
        $vendorId = (int) $existing['id'];
    } else {
        $pdo->prepare(
            'INSERT INTO ap_vendors_index
                (tenant_id, vendor_name, company_id, vendor_type, default_terms,
                 default_pwp, requires_1099, placement_id_last)
             VALUES
                (:t, :name, :company_id, :vendor_type, :terms,
                 :pwp, :requires_1099, :placement_id)'
        )->execute([
            't' => $tenantId,
            'name' => $displayName,
            'company_id' => $companyId,
            'vendor_type' => $vendorType,
            'terms' => $normalTerms,
            'pwp' => $normalPwp,
            'requires_1099' => $vendorType === '1099_individual' ? 1 : 0,
            'placement_id' => $placementId,
        ]);
        $vendorId = (int) $pdo->lastInsertId();
    }

    return [
        'id' => $vendorId,
        'company_id' => $companyId,
        'vendor_name' => $displayName,
        'vendor_type' => $vendorType,
        'default_terms' => $existing
            ? (string) ($existing['default_terms'] ?? 'NET30')
            : $normalTerms,
        'default_pwp' => $existing
            ? (int) ($existing['default_pwp'] ?? 0)
            : $normalPwp,
    ];
}

function placementEconomicsEnsureCycle(
    int $tenantId,
    string $purpose,
    string $cadence,
    ?string $anchorDate = null,
    string $sourceSystem = 'coreflux',
    ?string $externalId = null,
    ?string $name = null
): ?int {
    if (!in_array($purpose, ['billing','ap','payroll'], true)) return null;
    if (!in_array($cadence, ['weekly','biweekly','semimonthly','monthly','adhoc'], true)) return null;
    if ($anchorDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchorDate)) $anchorDate = null;
    $externalId = trim((string) $externalId) ?: $cadence;
    $name = trim((string) $name) ?: sprintf('%s %s', ucfirst($cadence), strtoupper($purpose));
    $pdo = getDB();
    $pdo->prepare(
        'INSERT INTO staffing_operating_cycles
            (tenant_id, purpose, name, cadence, anchor_date, source_system, external_id, active)
         VALUES (:t, :purpose, :name, :cadence, :anchor, :source_system, :external_id, 1)
         ON DUPLICATE KEY UPDATE cadence = VALUES(cadence),
             anchor_date = COALESCE(VALUES(anchor_date), anchor_date), active = 1'
    )->execute([
        't' => $tenantId,
        'purpose' => $purpose,
        'name' => $name,
        'cadence' => $cadence,
        'anchor' => $anchorDate,
        'source_system' => $sourceSystem,
        'external_id' => $externalId,
    ]);
    $st = $pdo->prepare(
        'SELECT id FROM staffing_operating_cycles
          WHERE tenant_id = :t AND purpose = :purpose
            AND source_system = :source_system AND external_id = :external_id
          LIMIT 1'
    );
    $st->execute([
        't' => $tenantId,
        'purpose' => $purpose,
        'source_system' => $sourceSystem,
        'external_id' => $externalId,
    ]);
    return (int) $st->fetchColumn() ?: null;
}

function placementEconomicsEnsureStandardCycle(int $tenantId, string $purpose, string $cadence): ?int
{
    $labels = [
        'billing' => 'client billing',
        'ap' => 'vendor payment',
        'payroll' => 'employee payroll',
    ];
    $anchor = in_array($cadence, ['weekly','biweekly'], true) ? '1970-01-05'
        : (in_array($cadence, ['semimonthly','monthly'], true) ? '1970-01-01' : null);
    return placementEconomicsEnsureCycle(
        $tenantId,
        $purpose,
        $cadence,
        $anchor,
        'standard_cadence',
        $cadence,
        ucfirst($cadence) . ' ' . ($labels[$purpose] ?? $purpose)
    );
}

function placementEconomicsEnsureDerivedCycles(int $tenantId, array &$placement): void
{
    $pdo = getDB();
    $updates = [];
    $allowedCadences = ['weekly','biweekly','semimonthly','monthly','adhoc'];
    $billingCadence = strtolower(trim((string) ($placement['client_bill_cycle'] ?? '')));
    if (!in_array($billingCadence, $allowedCadences, true)) {
        $billingCadence = 'monthly';
        $updates['client_bill_cycle'] = $billingCadence;
    }
    if (empty($placement['billing_operating_cycle_id'])) {
        $updates['billing_operating_cycle_id'] = placementEconomicsEnsureStandardCycle($tenantId, 'billing', $billingCadence);
    }
    $payCadence = strtolower(trim((string) ($placement['vendor_pay_cycle'] ?? '')));
    $engagement = strtolower((string) ($placement['engagement_type'] ?? ''));
    $payPurpose = in_array($engagement, ['w2','temp_to_perm','internal'], true) ? 'payroll' : 'ap';
    if (!in_array($payCadence, $allowedCadences, true)) {
        $personCadence = strtolower(trim((string) ($placement['person_pay_frequency'] ?? '')));
        $payCadence = $payPurpose === 'payroll' && in_array($personCadence, $allowedCadences, true)
            ? $personCadence
            : 'biweekly';
        $updates['vendor_pay_cycle'] = $payCadence;
    }
    $payField = $payPurpose . '_operating_cycle_id';
    if (empty($placement[$payField])) {
        $updates[$payField] = placementEconomicsEnsureStandardCycle($tenantId, $payPurpose, $payCadence);
    }
    $updates = array_filter($updates, static fn($value): bool => !empty($value));
    if (!$updates) return;
    $sets = [];
    $params = ['t' => $tenantId, 'p' => (int) $placement['id']];
    foreach ($updates as $field => $value) {
        $sets[] = "{$field} = :{$field}";
        $params[$field] = $value;
        $placement[$field] = $value;
    }
    $pdo->prepare(
        'UPDATE placements SET ' . implode(', ', $sets) . '
          WHERE tenant_id = :t AND id = :p'
    )->execute($params);
}

function placementEconomicsUpsertParty(int $tenantId, int $placementId, array $party): int
{
    $sourceRef = trim((string) ($party['source_ref'] ?? ''));
    $name = trim((string) ($party['display_name'] ?? ''));
    if ($tenantId <= 0 || $placementId <= 0 || $sourceRef === '' || $name === '') return 0;

    $allowedSources = ['placement','worker','chain','corp','referral','commission','manual','integration'];
    $allowedFlows = ['receivable','payable','informational'];
    $allowedChannels = ['ar','ap','payroll','none'];
    $allowedBasis = [
        'none','pay_rate','portal_fee_pct','portal_fee_flat','per_hour','per_invoice',
        'one_time','pct_bill','pct_margin','net_margin','gross_margin','bill_rate','flat',
    ];
    $sourceType = (string) ($party['source_type'] ?? 'manual');
    $moneyFlow = (string) ($party['money_flow'] ?? 'informational');
    $channel = (string) ($party['settlement_channel'] ?? 'none');
    $basis = (string) ($party['fee_basis'] ?? 'none');
    if (!in_array($sourceType, $allowedSources, true)) $sourceType = 'manual';
    if (!in_array($moneyFlow, $allowedFlows, true)) $moneyFlow = 'informational';
    if (!in_array($channel, $allowedChannels, true)) $channel = 'none';
    if (!in_array($basis, $allowedBasis, true)) $basis = 'none';

    $terms = isset($party['payment_terms']) && trim((string) $party['payment_terms']) !== ''
        ? placementEconomicsNormaliseTerms((string) $party['payment_terms'])
        : null;
    $pdo = getDB();
    $pdo->prepare(
        'INSERT INTO placement_economic_parties
            (tenant_id, placement_id, source_ref, source_type, source_id, role,
             display_name, company_id, person_id, user_id, ap_vendor_id,
             money_flow, settlement_channel, fee_basis, fee_pct, fee_flat,
             payment_terms, pwp_enabled, operating_cycle_id,
             effective_from, effective_to, source_system, source_external_id,
             source_managed, active, created_by_user_id)
         VALUES
            (:t, :p, :source_ref, :source_type, :source_id, :role,
             :display_name, :company_id, :person_id, :user_id, :ap_vendor_id,
             :money_flow, :settlement_channel, :fee_basis, :fee_pct, :fee_flat,
             :payment_terms, :pwp_enabled, :operating_cycle_id,
             :effective_from, :effective_to, :source_system, :source_external_id,
             :source_managed, 1, :created_by_user_id)
         ON DUPLICATE KEY UPDATE
             source_type = VALUES(source_type), source_id = VALUES(source_id),
             role = VALUES(role), display_name = VALUES(display_name),
             company_id = VALUES(company_id), person_id = VALUES(person_id),
             user_id = VALUES(user_id), ap_vendor_id = VALUES(ap_vendor_id),
              money_flow = VALUES(money_flow), settlement_channel = VALUES(settlement_channel),
              fee_basis = VALUES(fee_basis), fee_pct = VALUES(fee_pct), fee_flat = VALUES(fee_flat),
              payment_terms = IF(payment_terms_overridden = 1, payment_terms, VALUES(payment_terms)),
              pwp_enabled = IF(pwp_overridden = 1, pwp_enabled, VALUES(pwp_enabled)),
              operating_cycle_id = IF(cycle_overridden = 1, operating_cycle_id, VALUES(operating_cycle_id)),
             effective_from = VALUES(effective_from), effective_to = VALUES(effective_to),
             source_system = COALESCE(VALUES(source_system), source_system),
             source_external_id = COALESCE(VALUES(source_external_id), source_external_id),
             active = 1'
    )->execute([
        't' => $tenantId,
        'p' => $placementId,
        'source_ref' => $sourceRef,
        'source_type' => $sourceType,
        'source_id' => $party['source_id'] ?? null,
        'role' => (string) ($party['role'] ?? 'other'),
        'display_name' => $name,
        'company_id' => $party['company_id'] ?? null,
        'person_id' => $party['person_id'] ?? null,
        'user_id' => $party['user_id'] ?? null,
        'ap_vendor_id' => $party['ap_vendor_id'] ?? null,
        'money_flow' => $moneyFlow,
        'settlement_channel' => $channel,
        'fee_basis' => $basis,
        'fee_pct' => $party['fee_pct'] ?? null,
        'fee_flat' => $party['fee_flat'] ?? null,
        'payment_terms' => $terms,
        'pwp_enabled' => !empty($party['pwp_enabled']) ? 1 : 0,
        'operating_cycle_id' => $party['operating_cycle_id'] ?? null,
        'effective_from' => $party['effective_from'] ?? null,
        'effective_to' => $party['effective_to'] ?? null,
        'source_system' => $party['source_system'] ?? null,
        'source_external_id' => $party['source_external_id'] ?? null,
        'source_managed' => array_key_exists('source_managed', $party) ? (!empty($party['source_managed']) ? 1 : 0) : 1,
        'created_by_user_id' => $party['created_by_user_id'] ?? null,
    ]);

    $st = $pdo->prepare(
        'SELECT id FROM placement_economic_parties
          WHERE tenant_id = :t AND placement_id = :p AND source_ref = :source_ref LIMIT 1'
    );
    $st->execute(['t' => $tenantId, 'p' => $placementId, 'source_ref' => $sourceRef]);
    return (int) $st->fetchColumn();
}

function placementEconomicsReconcile(int $tenantId, int $placementId, array $options = []): array
{
    $summary = ['available' => true, 'placement_id' => $placementId, 'written' => 0, 'source_refs' => [], 'errors' => []];
    if ($tenantId <= 0 || $placementId <= 0) return $summary;

    try {
        $pdo = getDB();
        $st = $pdo->prepare(
            'SELECT p.*, pe.first_name, pe.last_name, pe.email_primary,
                    pe.pay_frequency AS person_pay_frequency,
                    ec.name AS end_client_company_name,
                    ec.payment_terms_days AS end_client_company_terms_days,
                    sc.payment_terms_days AS staffing_client_terms_days,
                    t.billing_invoice_terms AS tenant_billing_terms
               FROM placements p
          LEFT JOIN people pe ON pe.id = p.person_id AND pe.tenant_id = p.tenant_id
          LEFT JOIN companies ec ON ec.id = p.end_client_company_id AND ec.tenant_id = p.tenant_id
          LEFT JOIN staffing_clients sc ON sc.id = p.client_id AND sc.tenant_id = p.tenant_id
          LEFT JOIN tenants t ON t.id = p.tenant_id
              WHERE p.tenant_id = :t AND p.id = :p LIMIT 1'
        );
        $st->execute(['t' => $tenantId, 'p' => $placementId]);
        $placement = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$placement) {
            $summary['available'] = false;
            $summary['errors'][] = 'placement not found';
            return $summary;
        }

        placementEconomicsEnsureDerivedCycles($tenantId, $placement);

        $sourceSystem = str_starts_with((string) ($placement['external_id'] ?? ''), 'jd:') ? 'jobdiva' : 'coreflux';
        $sourceExternal = $sourceSystem === 'jobdiva' ? substr((string) $placement['external_id'], 3) : null;
        $placementEffectiveTo = $placement['actual_end_date'] ?: ($placement['end_date'] ?? null);
        $apCycleId = !empty($placement['ap_operating_cycle_id']) ? (int) $placement['ap_operating_cycle_id'] : null;
        $billingCycleId = !empty($placement['billing_operating_cycle_id']) ? (int) $placement['billing_operating_cycle_id'] : null;
        $payrollCycleId = !empty($placement['payroll_operating_cycle_id']) ? (int) $placement['payroll_operating_cycle_id'] : null;
        $apCycleTerms = null;
        if ($apCycleId) {
            $cycleTermsSt = $pdo->prepare(
                'SELECT default_payment_terms FROM staffing_operating_cycles
                  WHERE tenant_id = :t AND id = :id AND purpose = "ap" AND active = 1 LIMIT 1'
            );
            $cycleTermsSt->execute(['t' => $tenantId, 'id' => $apCycleId]);
            $apCycleTerms = $cycleTermsSt->fetchColumn() ?: null;
        }
        $defaultTermsRaw = 'NET30';
        foreach ([$options['payment_terms'] ?? null, $placement['vendor_payment_terms_override'] ?? null, $apCycleTerms] as $candidate) {
            if (trim((string) $candidate) !== '') {
                $defaultTermsRaw = (string) $candidate;
                break;
            }
        }
        $defaultTerms = placementEconomicsNormaliseTerms($defaultTermsRaw);
        $clientTermsRaw = '';
        foreach ([$options['client_payment_terms'] ?? null, $placement['client_payment_terms_override'] ?? null] as $candidate) {
            if (trim((string) $candidate) !== '') {
                $clientTermsRaw = (string) $candidate;
                break;
            }
        }
        if ($clientTermsRaw === '') {
            $clientDays = $placement['staffing_client_terms_days'] ?? $placement['end_client_company_terms_days'] ?? null;
            if ($clientDays !== null && $clientDays !== '') {
                $clientTermsRaw = (int) $clientDays === 0 ? 'DUE_ON_RECEIPT' : 'NET' . max(0, (int) $clientDays);
            }
        }
        if ($clientTermsRaw === '') $clientTermsRaw = (string) ($placement['tenant_billing_terms'] ?? 'NET30');
        $clientTerms = placementEconomicsNormaliseTerms($clientTermsRaw, 'NET30');
        $defaultPwp = (array_key_exists('pwp_enabled', $options) && $options['pwp_enabled'] !== null
                ? !empty($options['pwp_enabled'])
                : !empty($placement['vendor_pwp_enabled']))
            || placementEconomicsTermsArePwp($defaultTerms);

        $record = static function (array $party) use (&$summary, $tenantId, $placementId): int {
            $id = placementEconomicsUpsertParty($tenantId, $placementId, $party);
            if ($id > 0) {
                $summary['written']++;
                $summary['source_refs'][] = (string) $party['source_ref'];
            }
            return $id;
        };

        $clientName = trim((string) ($placement['end_client_company_name'] ?: $placement['end_client_name']));
        if ($clientName !== '') {
            $record([
                'source_ref' => 'placement:end_client', 'source_type' => 'placement',
                'source_id' => $placementId, 'role' => 'end_client', 'display_name' => $clientName,
                'company_id' => $placement['end_client_company_id'] ?: null,
                'money_flow' => 'receivable', 'settlement_channel' => 'ar',
                'payment_terms' => $clientTerms, 'pwp_enabled' => 0,
                'operating_cycle_id' => $billingCycleId, 'source_system' => $sourceSystem,
                'source_external_id' => $sourceExternal,
            ]);
        }

        $personName = trim((string) (($placement['first_name'] ?? '') . ' ' . ($placement['last_name'] ?? '')));
        $engagement = strtolower((string) ($placement['engagement_type'] ?? ''));
        if ($personName !== '' && $engagement !== 'c2c') {
            $workerChannel = in_array($engagement, ['w2','temp_to_perm','internal'], true)
                ? 'payroll'
                : 'ap';
            $vendor = null;
            if ($workerChannel === 'ap') {
                $vendor = placementEconomicsEnsureVendor(
                    $tenantId, $personName, null, '1099_individual', $defaultTerms, $defaultPwp, $placementId
                );
            }
            $record([
                'source_ref' => 'worker:' . (int) $placement['person_id'], 'source_type' => 'worker',
                'source_id' => (int) $placement['person_id'], 'role' => 'worker', 'display_name' => $personName,
                'person_id' => (int) $placement['person_id'], 'ap_vendor_id' => $vendor['id'] ?? null,
                'money_flow' => $workerChannel === 'none' ? 'informational' : 'payable',
                'settlement_channel' => $workerChannel, 'fee_basis' => 'pay_rate',
                'payment_terms' => $workerChannel === 'ap' ? $defaultTerms : null,
                'pwp_enabled' => $workerChannel === 'ap' ? ($defaultPwp ? 1 : 0) : 0,
                'operating_cycle_id' => $workerChannel === 'ap' ? $apCycleId : $payrollCycleId,
                'effective_from' => $placement['start_date'] ?? null,
                'effective_to' => $placementEffectiveTo,
                'source_system' => $sourceSystem, 'source_external_id' => $sourceExternal,
            ]);
        }

        $corpSt = $pdo->prepare('SELECT * FROM placement_corp_details WHERE tenant_id = :t AND placement_id = :p LIMIT 1');
        $corpSt->execute(['t' => $tenantId, 'p' => $placementId]);
        $corp = $corpSt->fetch(\PDO::FETCH_ASSOC) ?: null;
        $corpName = trim((string) ($corp['corp_legal_name'] ?? ''));
        if ($corpName === '') $corpName = trim((string) ($corp['corp_name'] ?? ''));
        if ($engagement === 'c2c' && $corp && $corpName !== '') {
            $companyId = !empty($corp['company_id']) ? (int) $corp['company_id'] : null;
            if (!$companyId) {
                $companyId = companiesUpsertByName($tenantId, $corpName, [], ['vendor']);
                $pdo->prepare('UPDATE placement_corp_details SET company_id = :c WHERE tenant_id = :t AND placement_id = :p')
                    ->execute(['c' => $companyId, 't' => $tenantId, 'p' => $placementId]);
            }
            $terms = placementEconomicsNormaliseTerms((string) ($corp['payment_terms_override'] ?? $defaultTerms));
            $pwp = !empty($corp['pwp_enabled']) || placementEconomicsTermsArePwp($terms);
            $vendor = placementEconomicsEnsureVendor($tenantId, $corpName, $companyId, 'c2c_corp', $terms, $pwp, $placementId);
            if ($vendor) {
                $pdo->prepare('UPDATE placement_corp_details SET ap_vendor_id = :v WHERE tenant_id = :t AND placement_id = :p')
                    ->execute(['v' => $vendor['id'], 't' => $tenantId, 'p' => $placementId]);
            }
            $record([
                'source_ref' => 'corp:' . $placementId, 'source_type' => 'corp', 'source_id' => $placementId,
                'role' => 'c2c_vendor', 'display_name' => $corpName, 'company_id' => $companyId,
                'ap_vendor_id' => $vendor['id'] ?? null, 'money_flow' => 'payable',
                'settlement_channel' => 'ap', 'fee_basis' => 'pay_rate',
                'payment_terms' => $terms,
                'pwp_enabled' => $pwp ? 1 : 0,
                'operating_cycle_id' => $apCycleId, 'effective_from' => $placement['start_date'] ?? null,
                'effective_to' => $placementEffectiveTo,
                'source_system' => $sourceSystem, 'source_external_id' => $sourceExternal,
            ]);
        }

        $chainSt = $pdo->prepare(
            'SELECT * FROM placement_client_chain
              WHERE tenant_id = :t AND placement_id = :p ORDER BY position, id'
        );
        $chainSt->execute(['t' => $tenantId, 'p' => $placementId]);
        foreach ($chainSt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $role = (string) $row['party_role'];
            $isClient = in_array($role, ['end_client','direct'], true);
            $isVendorRole = in_array($role, ['vendor','msp','prime_vendor','sub_vendor'], true);
            $hasFee = (float) ($row['portal_fee_pct'] ?? 0) > 0 || (float) ($row['portal_fee_flat'] ?? 0) > 0;
            $isPayable = !$isClient && (!empty($row['is_payable']) || $hasFee);
            $companyId = !empty($row['company_id']) ? (int) $row['company_id'] : null;
            $name = trim((string) ($row['party_name'] ?? ''));
            if ($name === '') continue;
            $sameEndClient = $role === 'end_client' && (
                ($companyId && (int) ($placement['end_client_company_id'] ?? 0) === $companyId)
                || (!$companyId && $clientName !== '' && strcasecmp($name, $clientName) === 0)
            );
            if ($sameEndClient) continue;
            if (!$companyId) {
                $companyRole = $isClient ? 'client' : $role;
                $companyId = companiesUpsertByName($tenantId, $name, [], [$companyRole]);
                $pdo->prepare('UPDATE placement_client_chain SET company_id = :c WHERE tenant_id = :t AND id = :id')
                    ->execute(['c' => $companyId, 't' => $tenantId, 'id' => (int) $row['id']]);
            }
            $terms = placementEconomicsNormaliseTerms((string) ($row['payment_terms_override'] ?? $defaultTerms));
            $pwp = !empty($row['pwp_enabled']) || placementEconomicsTermsArePwp($terms);
            $vendor = ($isPayable || $isVendorRole)
                ? placementEconomicsEnsureVendor($tenantId, $name, $companyId, 'w9_business', $terms, $pwp, $placementId)
                : null;
            $basis = (float) ($row['portal_fee_flat'] ?? 0) > 0 ? 'portal_fee_flat'
                : ((float) ($row['portal_fee_pct'] ?? 0) > 0 ? 'portal_fee_pct' : 'none');
            if ($isClient) continue;
            $record([
                'source_ref' => 'chain:' . (int) $row['id'], 'source_type' => 'chain', 'source_id' => (int) $row['id'],
                'role' => $role, 'display_name' => $name, 'company_id' => $companyId,
                'ap_vendor_id' => $vendor['id'] ?? null,
                'money_flow' => $isPayable ? 'payable' : 'informational',
                'settlement_channel' => $isPayable ? 'ap' : 'none',
                'fee_basis' => $basis, 'fee_pct' => $row['portal_fee_pct'] ?? null,
                'fee_flat' => $row['portal_fee_flat'] ?? null,
                'payment_terms' => $isPayable ? $terms : null,
                'pwp_enabled' => $isPayable ? ($pwp ? 1 : 0) : 0,
                'operating_cycle_id' => $isPayable ? $apCycleId : null,
                'effective_from' => $placement['start_date'] ?? null,
                'effective_to' => $placementEffectiveTo,
                'source_system' => $sourceSystem, 'source_external_id' => $sourceExternal,
            ]);
        }

        $refSt = $pdo->prepare(
            'SELECT r.*, c.name AS company_name,
                    CONCAT_WS(" ", pe.first_name, pe.last_name) AS person_name,
                    u.name AS user_name
               FROM placement_referrals r
          LEFT JOIN companies c ON c.id = r.referrer_company_id AND c.tenant_id = r.tenant_id
          LEFT JOIN people pe ON pe.id = r.referrer_person_id AND pe.tenant_id = r.tenant_id
          LEFT JOIN users u ON u.id = r.referrer_user_id
              WHERE r.tenant_id = :t AND r.placement_id = :p'
        );
        $refSt->execute(['t' => $tenantId, 'p' => $placementId]);
        foreach ($refSt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $type = (string) $row['referrer_type'];
            $name = trim((string) ($row['company_name'] ?: $row['referrer_vendor_name'] ?: $row['person_name'] ?: $row['user_name']));
            if ($name === '') $name = 'Unresolved referrer #' . (int) $row['id'];
            $channel = $type === 'user' ? 'payroll' : 'ap';
            $companyId = !empty($row['referrer_company_id']) ? (int) $row['referrer_company_id'] : null;
            if ($type === 'vendor' && !$companyId && trim((string) ($row['referrer_vendor_name'] ?? '')) !== '') {
                $companyId = companiesUpsertByName($tenantId, (string) $row['referrer_vendor_name'], [], ['referrer','vendor']);
                $pdo->prepare('UPDATE placement_referrals SET referrer_company_id = :c WHERE tenant_id = :t AND id = :id')
                    ->execute(['c' => $companyId, 't' => $tenantId, 'id' => (int) $row['id']]);
            }
            $terms = placementEconomicsNormaliseTerms((string) ($row['payment_terms_override'] ?? $defaultTerms));
            $pwp = !empty($row['pwp_enabled']) || placementEconomicsTermsArePwp($terms);
            $vendorType = $type === 'person' ? '1099_individual' : 'w9_business';
            $vendor = $channel === 'ap'
                ? placementEconomicsEnsureVendor($tenantId, $name, $companyId, $vendorType, $terms, $pwp, $placementId)
                : null;
            $effectiveTo = $row['end_date'] ?? null;
            if (!$effectiveTo && !empty($row['start_date']) && (int) ($row['duration_months'] ?? 0) > 0) {
                try {
                    $effectiveTo = (new \DateTimeImmutable((string) $row['start_date']))
                        ->modify('+' . (int) $row['duration_months'] . ' months')
                        ->modify('-1 day')
                        ->format('Y-m-d');
                } catch (\Throwable $e) {
                    $effectiveTo = null;
                }
            }
            $record([
                'source_ref' => 'referral:' . (int) $row['id'], 'source_type' => 'referral', 'source_id' => (int) $row['id'],
                'role' => 'referrer', 'display_name' => $name, 'company_id' => $companyId,
                'person_id' => $row['referrer_person_id'] ?: null, 'user_id' => $row['referrer_user_id'] ?: null,
                'ap_vendor_id' => $vendor['id'] ?? null, 'money_flow' => 'payable',
                'settlement_channel' => $channel, 'fee_basis' => (string) $row['fee_basis'],
                'fee_pct' => $row['fee_pct'] ?? null, 'fee_flat' => $row['fee_flat'] ?? null,
                'payment_terms' => $channel === 'ap' ? $terms : null,
                'pwp_enabled' => $channel === 'ap' ? ($pwp ? 1 : 0) : 0,
                'operating_cycle_id' => $channel === 'ap' ? $apCycleId : $payrollCycleId,
                'effective_from' => $row['start_date'] ?? null, 'effective_to' => $effectiveTo,
                'source_system' => $sourceSystem, 'source_external_id' => $sourceExternal,
            ]);
        }

        $commSt = $pdo->prepare(
            'SELECT pc.*, u.name AS user_name
               FROM placement_commissions pc
          LEFT JOIN users u ON u.id = pc.user_id
              WHERE pc.tenant_id = :t AND pc.placement_id = :p'
        );
        $commSt->execute(['t' => $tenantId, 'p' => $placementId]);
        foreach ($commSt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $name = trim((string) ($row['user_name'] ?? ''));
            if ($name === '') $name = ucfirst(str_replace('_', ' ', (string) $row['role'])) . ' (recipient not linked)';
            $record([
                'source_ref' => 'commission:' . (int) $row['id'], 'source_type' => 'commission', 'source_id' => (int) $row['id'],
                'role' => 'commission_' . (string) $row['role'], 'display_name' => $name,
                'user_id' => $row['user_id'] ?: null,
                'money_flow' => 'payable', 'settlement_channel' => $row['user_id'] ? 'payroll' : 'none',
                'fee_basis' => (string) $row['basis'], 'fee_pct' => $row['split_pct'] ?? null,
                'fee_flat' => $row['flat_amount'] ?? null, 'operating_cycle_id' => $payrollCycleId,
                'effective_from' => $row['effective_from'] ?? null, 'effective_to' => $row['effective_to'] ?? null,
                'source_system' => $sourceSystem, 'source_external_id' => $sourceExternal,
            ]);
        }

        $managedRefs = array_values(array_unique($summary['source_refs']));
        $managedSourceTypes = ['placement','worker','chain','corp','referral','commission'];
        $params = ['t' => $tenantId, 'p' => $placementId];
        $sourcePh = [];
        foreach ($managedSourceTypes as $i => $sourceType) {
            $key = 's' . $i;
            $sourcePh[] = ':' . $key;
            $params[$key] = $sourceType;
        }
        if ($managedRefs) {
            $ph = [];
            foreach ($managedRefs as $i => $ref) {
                $key = 'r' . $i;
                $ph[] = ':' . $key;
                $params[$key] = $ref;
            }
            $pdo->prepare(
                'UPDATE placement_economic_parties SET active = 0
                  WHERE tenant_id = :t AND placement_id = :p
                    AND source_type IN (' . implode(',', $sourcePh) . ')
                    AND source_ref NOT IN (' . implode(',', $ph) . ')'
            )->execute($params);
        } else {
            $pdo->prepare(
                'UPDATE placement_economic_parties SET active = 0
                  WHERE tenant_id = :t AND placement_id = :p
                    AND source_type IN (' . implode(',', $sourcePh) . ')'
            )->execute($params);
        }
    } catch (\Throwable $e) {
        $summary['available'] = false;
        $summary['errors'][] = $e->getMessage();
        error_log('[placement economics reconcile] ' . $e->getMessage());
    }
    return $summary;
}

function placementEconomicsParties(int $tenantId, int $placementId): array
{
    $st = getDB()->prepare(
        'SELECT ep.*, c.name AS company_name,
                v.vendor_name, v.vendor_type, v.default_terms AS vendor_default_terms,
                COALESCE(v.default_pwp, 0) AS vendor_default_pwp,
                oc.name AS cycle_name, oc.purpose AS cycle_purpose, oc.cadence AS cycle_cadence
           FROM placement_economic_parties ep
      LEFT JOIN companies c ON c.id = ep.company_id AND c.tenant_id = ep.tenant_id
      LEFT JOIN ap_vendors_index v ON v.id = ep.ap_vendor_id AND v.tenant_id = ep.tenant_id
      LEFT JOIN staffing_operating_cycles oc ON oc.id = ep.operating_cycle_id AND oc.tenant_id = ep.tenant_id
          WHERE ep.tenant_id = :t AND ep.placement_id = :p AND ep.active = 1
          ORDER BY FIELD(ep.money_flow, "receivable", "payable", "informational"), ep.id'
    );
    $st->execute(['t' => $tenantId, 'p' => $placementId]);
    return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

function placementEconomicsReceivableContract(int $tenantId, int $placementId, bool $reconcile = true): array
{
    if ($reconcile) placementEconomicsReconcile($tenantId, $placementId);
    $receivables = array_values(array_filter(
        placementEconomicsParties($tenantId, $placementId),
        static fn(array $row): bool => $row['money_flow'] === 'receivable'
            && $row['settlement_channel'] === 'ar'
    ));
    if (count($receivables) === 0) {
        throw new \RuntimeException("Placement #{$placementId} has no client receivable participant.");
    }
    if (count($receivables) > 1) {
        throw new \RuntimeException("Placement #{$placementId} has multiple client receivable participants; choose one bill-to client.");
    }
    $party = $receivables[0];
    $terms = placementEconomicsNormaliseTerms((string) ($party['payment_terms'] ?? 'NET30'));
    return [
        'economic_party_id' => (int) $party['id'],
        'client_name' => (string) $party['display_name'],
        'client_company_id' => !empty($party['company_id']) ? (int) $party['company_id'] : null,
        'payment_terms' => $terms,
        'payment_terms_days' => placementEconomicsTermsDays($terms, 30),
        'operating_cycle_id' => !empty($party['operating_cycle_id']) ? (int) $party['operating_cycle_id'] : null,
        'cadence' => $party['cycle_cadence'] ?? null,
    ];
}

function placementEconomicsContext(int $tenantId, int $placementId, bool $reconcile = true): array
{
    $reconcileResult = $reconcile ? placementEconomicsReconcile($tenantId, $placementId) : ['available' => true];
    if (empty($reconcileResult['available'])) {
        return ['available' => false, 'parties' => [], 'cycles' => [], 'readiness' => [], 'reconcile' => $reconcileResult];
    }
    $pdo = getDB();
    $p = $pdo->prepare(
        'SELECT id, engagement_type, billing_operating_cycle_id, ap_operating_cycle_id,
                payroll_operating_cycle_id, billing_cycle_id, ap_cycle_id, payroll_cycle_id,
                client_bill_cycle, vendor_pay_cycle, client_payment_terms_override,
                vendor_payment_terms_override, vendor_pwp_enabled
           FROM placements WHERE tenant_id = :t AND id = :p LIMIT 1'
    );
    $p->execute(['t' => $tenantId, 'p' => $placementId]);
    $placement = $p->fetch(\PDO::FETCH_ASSOC) ?: [];
    $parties = placementEconomicsParties($tenantId, $placementId);
    $cyclesSt = $pdo->prepare(
        'SELECT * FROM staffing_operating_cycles
          WHERE tenant_id = :t AND active = 1 ORDER BY purpose, name'
    );
    $cyclesSt->execute(['t' => $tenantId]);
    $cycles = $cyclesSt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $payables = array_values(array_filter($parties, static fn(array $r): bool => $r['money_flow'] === 'payable'));
    $receivables = array_values(array_filter($parties, static fn(array $r): bool => $r['settlement_channel'] === 'ar'));
    $apPayables = array_values(array_filter($payables, static fn(array $r): bool => $r['settlement_channel'] === 'ap'));
    $payrollPayables = array_values(array_filter($payables, static fn(array $r): bool => $r['settlement_channel'] === 'payroll'));
    $laborPayables = array_values(array_filter($payables, static fn(array $r): bool => $r['fee_basis'] === 'pay_rate'));
    $engagement = (string) ($placement['engagement_type'] ?? '');
    $requiresLaborPayee = in_array($engagement, ['w2','1099','c2c','temp_to_perm','internal'], true);
    $requiresBilling = $engagement !== 'internal';
    $unresolved = array_values(array_filter($payables, static function (array $r) use ($tenantId): bool {
        if ($r['settlement_channel'] === 'ap') return empty($r['ap_vendor_id']);
        if ($r['settlement_channel'] === 'payroll') {
            if (empty($r['person_id']) && empty($r['user_id'])) return true;
            return placementEconomicsPayrollEmployee($tenantId, $r) === null;
        }
        return true;
    }));
    $missingArSchedules = array_values(array_filter($receivables, static fn(array $r): bool => empty($r['operating_cycle_id'])));
    $missingApSchedules = array_values(array_filter($apPayables, static fn(array $r): bool => empty($r['operating_cycle_id'])));
    $missingPayrollSchedules = array_values(array_filter($payrollPayables, static fn(array $r): bool => empty($r['operating_cycle_id'])));
    $missingArTerms = array_values(array_filter($receivables, static fn(array $r): bool => trim((string) ($r['payment_terms'] ?? '')) === ''));
    $missingApTerms = array_values(array_filter($apPayables, static fn(array $r): bool =>
        trim((string) ($r['payment_terms'] ?? $r['vendor_default_terms'] ?? '')) === ''
    ));
    $model = placementEconomicsModel($tenantId, $placementId, $parties);
    $hasC2cVendor = count(array_filter($parties, static fn(array $r): bool =>
        $r['role'] === 'c2c_vendor' && $r['settlement_channel'] === 'ap' && !empty($r['ap_vendor_id'])
    )) > 0;
    $readiness = [
        'receivable_parties' => count($receivables),
        'payable_parties' => count($payables),
        'ap_payable_parties' => count($apPayables),
        'unresolved_parties' => count($unresolved),
        'missing_receivable_party' => $requiresBilling
            && count($receivables) === 0,
        'multiple_receivable_parties' => $requiresBilling && count($receivables) > 1,
        'missing_approved_rate' => empty($model['available']),
        'missing_payable_party' => $requiresLaborPayee && count($payables) === 0,
        'missing_labor_payee' => $requiresLaborPayee && count($laborPayables) === 0,
        'multiple_labor_payees' => count($laborPayables) > 1,
        'missing_c2c_vendor' => ($placement['engagement_type'] ?? '') === 'c2c' && !$hasC2cVendor,
        'missing_billing_cycle' => $requiresBilling && count($missingArSchedules) > 0,
        'missing_ap_cycle' => count($missingApSchedules) > 0,
        'missing_payroll_cycle' => count($missingPayrollSchedules) > 0,
        'missing_ar_payment_terms' => $requiresBilling && count($missingArTerms) > 0,
        'missing_ap_payment_terms' => count($missingApTerms) > 0,
    ];
    $readiness['ready'] = $readiness['unresolved_parties'] === 0
        && !$readiness['missing_receivable_party']
        && !$readiness['multiple_receivable_parties']
        && !$readiness['missing_approved_rate']
        && !$readiness['missing_payable_party']
        && !$readiness['missing_labor_payee']
        && !$readiness['multiple_labor_payees']
        && !$readiness['missing_c2c_vendor']
        && !$readiness['missing_billing_cycle']
        && !$readiness['missing_ap_cycle']
        && !$readiness['missing_payroll_cycle']
        && !$readiness['missing_ar_payment_terms']
        && !$readiness['missing_ap_payment_terms'];

    return [
        'available' => true,
        'placement' => $placement,
        'parties' => $parties,
        'cycles' => $cycles,
        'model' => $model,
        'readiness' => $readiness,
        'reconcile' => $reconcileResult,
    ];
}

function placementEconomicsModel(int $tenantId, int $placementId, ?array $parties = null): array
{
    $parties = $parties ?? placementEconomicsParties($tenantId, $placementId);
    $st = getDB()->prepare(
        'SELECT * FROM placement_rates
          WHERE tenant_id = :t AND placement_id = :p AND approved_at IS NOT NULL
          ORDER BY effective_from DESC, id DESC LIMIT 1'
    );
    $st->execute(['t' => $tenantId, 'p' => $placementId]);
    $rate = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    if (!$rate) return ['available' => false, 'hourly_lines' => [], 'fixed_lines' => []];

    $billRate = (float) $rate['bill_rate'];
    $payRate = (float) $rate['pay_rate'];
    $baseMargin = max(0, $billRate - $payRate);
    $hourlyLines = [];
    $fixedLines = [];
    foreach ($parties as $party) {
        if (($party['money_flow'] ?? '') !== 'payable') continue;
        $basis = (string) ($party['fee_basis'] ?? 'none');
        $pct = (float) ($party['fee_pct'] ?? 0);
        $flat = (float) ($party['fee_flat'] ?? 0);
        $amount = match ($basis) {
            'pay_rate' => $payRate,
            'portal_fee_pct', 'pct_bill', 'bill_rate' => $billRate * $pct,
            'pct_margin', 'net_margin', 'gross_margin' => $baseMargin * $pct,
            'per_hour' => $flat,
            default => 0.0,
        };
        if ($amount > 0) {
            $hourlyLines[] = [
                'economic_party_id' => (int) $party['id'],
                'name' => (string) $party['display_name'],
                'role' => (string) $party['role'],
                'basis' => $basis,
                'amount' => round($amount, 4),
                'settlement_channel' => (string) $party['settlement_channel'],
            ];
        } elseif (in_array($basis, ['portal_fee_flat','per_invoice','one_time','flat'], true) && $flat > 0) {
            $fixedLines[] = [
                'economic_party_id' => (int) $party['id'],
                'name' => (string) $party['display_name'],
                'role' => (string) $party['role'],
                'basis' => $basis,
                'amount' => round($flat, 2),
                'settlement_channel' => (string) $party['settlement_channel'],
            ];
        }
    }
    $adderPct = (float) ($rate['adder_pct'] ?? 0);
    if ($adderPct > 0) {
        $hourlyLines[] = [
            'economic_party_id' => null, 'name' => 'Rate adder / burden', 'role' => 'rate_adder',
            'basis' => 'pay_rate_pct', 'amount' => round($payRate * $adderPct, 4),
            'settlement_channel' => 'payroll',
        ];
    }
    $backgroundFee = (float) ($rate['background_fee_total'] ?? 0);
    if ($backgroundFee > 0) {
        $fixedLines[] = [
            'economic_party_id' => null, 'name' => 'Background and onboarding costs', 'role' => 'background_cost',
            'basis' => 'fixed', 'amount' => round($backgroundFee, 2), 'settlement_channel' => 'ap',
        ];
    }
    $hourlyCost = array_sum(array_column($hourlyLines, 'amount'));
    $net = $billRate - $hourlyCost;
    return [
        'available' => true,
        'rate_id' => (int) $rate['id'],
        'currency' => (string) ($rate['currency'] ?? 'USD'),
        'bill_rate' => round($billRate, 4),
        'modeled_hourly_cost' => round($hourlyCost, 4),
        'modeled_hourly_margin' => round($net, 4),
        'modeled_margin_pct' => $billRate > 0 ? round($net / $billRate, 6) : 0,
        'fixed_obligations' => round(array_sum(array_column($fixedLines, 'amount')), 2),
        'hourly_lines' => $hourlyLines,
        'fixed_lines' => $fixedLines,
    ];
}

function placementEconomicsPrimaryPayable(int $tenantId, int $placementId): ?array
{
    placementEconomicsReconcile($tenantId, $placementId);
    $st = getDB()->prepare(
        'SELECT ep.*, v.vendor_name, v.vendor_type,
                COALESCE(ep.payment_terms, v.default_terms, t.ap_default_terms, "NET30") AS resolved_payment_terms,
                CASE WHEN ep.pwp_overridden = 1
                     THEN ep.pwp_enabled
                     ELSE GREATEST(ep.pwp_enabled, COALESCE(v.default_pwp, 0))
                 END AS resolved_pwp,
                v.company_id AS vendor_company_id
           FROM placement_economic_parties ep
      LEFT JOIN ap_vendors_index v ON v.id = ep.ap_vendor_id AND v.tenant_id = ep.tenant_id
      LEFT JOIN tenants t ON t.id = ep.tenant_id
          WHERE ep.tenant_id = :t AND ep.placement_id = :p AND ep.active = 1
            AND ep.money_flow = "payable" AND ep.settlement_channel = "ap"
            AND ep.fee_basis = "pay_rate"
          ORDER BY CASE ep.role WHEN "c2c_vendor" THEN 0 WHEN "worker" THEN 1 ELSE 2 END, ep.id
          LIMIT 1'
    );
    $st->execute(['t' => $tenantId, 'p' => $placementId]);
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    if ($row) {
        $row['resolved_pwp'] = !empty($row['resolved_pwp']);
        $row['resolved_payment_terms'] = placementEconomicsResolvedTerms(
            (string) ($row['resolved_payment_terms'] ?? 'NET30'),
            (bool) $row['resolved_pwp']
        );
    }
    return $row ?: null;
}

function placementEconomicsApCharges(
    int $tenantId,
    int $placementId,
    float $hours,
    float $billAmount,
    float $payAmount,
    ?string $asOf = null
): array {
    $asOf = $asOf ?: date('Y-m-d');
    $charges = [];
    foreach (placementEconomicsParties($tenantId, $placementId) as $party) {
        if (($party['money_flow'] ?? '') !== 'payable' || ($party['settlement_channel'] ?? '') !== 'ap') continue;
        if (!empty($party['effective_from']) && strcmp((string) $party['effective_from'], $asOf) > 0) continue;
        if (!empty($party['effective_to']) && strcmp((string) $party['effective_to'], $asOf) < 0) continue;
        if (empty($party['ap_vendor_id'])) continue;

        $basis = (string) ($party['fee_basis'] ?? 'none');
        $pct = (float) ($party['fee_pct'] ?? 0);
        $flat = (float) ($party['fee_flat'] ?? 0);
        if ($basis === 'one_time') {
            $once = getDB()->prepare(
                'SELECT 1 FROM placement_economic_obligations
                  WHERE tenant_id = :t AND economic_party_id = :party
                    AND status IN ("projected","billed","payroll","paid") LIMIT 1'
            );
            $once->execute(['t' => $tenantId, 'party' => (int) $party['id']]);
            if ($once->fetchColumn()) continue;
        }
        $basisAmount = 0.0;
        $amount = match ($basis) {
            'pay_rate' => $payAmount,
            'portal_fee_pct', 'pct_bill', 'bill_rate' => ($basisAmount = $billAmount) * $pct,
            'pct_margin', 'net_margin', 'gross_margin' => ($basisAmount = max(0, $billAmount - $payAmount)) * $pct,
            'per_hour' => ($basisAmount = $hours) * $flat,
            'portal_fee_flat', 'per_invoice', 'one_time', 'flat' => $flat,
            default => 0.0,
        };
        $amount = round($amount, 2);
        if ($amount <= 0) continue;
        $party['calculated_amount'] = $amount;
        $party['calculation_quantity'] = $basis === 'per_hour' || $basis === 'pay_rate' ? $hours : 1;
        $party['calculation_basis_amount'] = round($basisAmount, 2);
        $termsImplyPwp = placementEconomicsTermsArePwp(
            (string) ($party['payment_terms'] ?: $party['vendor_default_terms'] ?: 'NET30')
        );
        $party['resolved_pwp'] = !empty($party['pwp_overridden'])
            ? !empty($party['pwp_enabled']) || $termsImplyPwp
            : !empty($party['pwp_enabled']) || !empty($party['vendor_default_pwp']) || $termsImplyPwp;
        $party['resolved_payment_terms'] = placementEconomicsResolvedTerms(
            (string) ($party['payment_terms'] ?: $party['vendor_default_terms'] ?: 'NET30'),
            (bool) $party['resolved_pwp']
        );
        $charges[] = $party;
    }
    return $charges;
}

/**
 * Calculate non-labor obligations that must be paid through payroll.
 * Base worker wages remain regular/overtime earnings; this function is for
 * employee commissions and internal referral fees layered onto those wages.
 */
function placementEconomicsPayrollCharges(
    int $tenantId,
    int $placementId,
    float $hours,
    float $billAmount,
    float $payAmount,
    ?string $asOf = null
): array {
    $asOf = $asOf ?: date('Y-m-d');
    $charges = [];
    foreach (placementEconomicsParties($tenantId, $placementId) as $party) {
        if (($party['money_flow'] ?? '') !== 'payable' || ($party['settlement_channel'] ?? '') !== 'payroll') continue;
        if (($party['fee_basis'] ?? '') === 'pay_rate' || ($party['role'] ?? '') === 'worker') continue;
        if (!empty($party['effective_from']) && strcmp((string) $party['effective_from'], $asOf) > 0) continue;
        if (!empty($party['effective_to']) && strcmp((string) $party['effective_to'], $asOf) < 0) continue;

        $basis = (string) ($party['fee_basis'] ?? 'none');
        $pct = (float) ($party['fee_pct'] ?? 0);
        $flat = (float) ($party['fee_flat'] ?? 0);
        if ($basis === 'one_time') {
            $once = getDB()->prepare(
                'SELECT 1 FROM placement_economic_obligations
                  WHERE tenant_id = :t AND economic_party_id = :party
                    AND status IN ("projected","billed","payroll","paid") LIMIT 1'
            );
            $once->execute(['t' => $tenantId, 'party' => (int) $party['id']]);
            if ($once->fetchColumn()) continue;
        }
        $basisAmount = 0.0;
        $amount = match ($basis) {
            'portal_fee_pct', 'pct_bill', 'bill_rate' => ($basisAmount = $billAmount) * $pct,
            'pct_margin', 'net_margin', 'gross_margin' => ($basisAmount = max(0, $billAmount - $payAmount)) * $pct,
            'per_hour' => ($basisAmount = $hours) * $flat,
            'portal_fee_flat', 'per_invoice', 'one_time', 'flat' => $flat,
            default => 0.0,
        };
        $amount = round($amount, 2);
        if ($amount <= 0) continue;
        $party['calculated_amount'] = $amount;
        $party['calculation_quantity'] = $basis === 'per_hour' ? $hours : 1;
        $party['calculation_basis_amount'] = round($basisAmount, 2);
        $charges[] = $party;
    }
    return $charges;
}

/** Resolve a payroll-channel economic participant to the canonical employee. */
function placementEconomicsPayrollEmployee(int $tenantId, array $party): ?array
{
    $pdo = getDB();
    $st = $pdo->prepare(
        'SELECT e.id AS employee_id, e.legal_first_name, e.legal_last_name,
                pp.id AS profile_id, pp.cycle_id, pp.schedule_id, pp.enabled
           FROM people_employees e
           JOIN payroll_profiles pp ON pp.tenant_id = e.tenant_id AND pp.employee_id = e.id
      LEFT JOIN people pe ON pe.tenant_id = e.tenant_id AND pe.id = :person_id
          WHERE e.tenant_id = :t AND e.status IN ("active","on_leave") AND pp.enabled = 1
            AND (
                 (:user_id > 0 AND e.user_id = :user_id_match)
                 OR (:person_id_match > 0 AND (
                      (pe.user_id IS NOT NULL AND e.user_id = pe.user_id)
                      OR (pe.email_primary IS NOT NULL AND LOWER(e.personal_email) = LOWER(pe.email_primary))
                 ))
            )
          ORDER BY e.id LIMIT 1'
    );
    $userId = (int) ($party['user_id'] ?? 0);
    $personId = (int) ($party['person_id'] ?? 0);
    $st->execute([
        't' => $tenantId,
        'user_id' => $userId,
        'user_id_match' => $userId,
        'person_id' => $personId ?: null,
        'person_id_match' => $personId,
    ]);
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    return $row ?: null;
}

function placementEconomicsRecordObligation(
    int $tenantId,
    int $placementId,
    int $economicPartyId,
    string $sourceType,
    int $sourceRefId,
    array $values
): int {
    if (!in_array($sourceType, ['time_bundle','time_entry','ar_invoice','manual'], true)) {
        throw new \InvalidArgumentException('Invalid economic obligation source');
    }
    $pdo = getDB();
    $pdo->prepare(
        'INSERT INTO placement_economic_obligations
            (tenant_id, placement_id, economic_party_id, source_type, source_ref_id,
             period_start, period_end, quantity, basis_amount, amount, currency, status, ap_bill_id, payroll_ref_id)
         VALUES
            (:t, :p, :party, :source_type, :source_ref_id,
             :period_start, :period_end, :quantity, :basis_amount, :amount, :currency, :status, :ap_bill_id, :payroll_ref_id)
         ON DUPLICATE KEY UPDATE
             period_start = VALUES(period_start), period_end = VALUES(period_end),
             quantity = VALUES(quantity), basis_amount = VALUES(basis_amount), amount = VALUES(amount),
             currency = VALUES(currency),
             status = IF(status IN ("paid","void"), status, VALUES(status)),
             ap_bill_id = COALESCE(VALUES(ap_bill_id), ap_bill_id),
             payroll_ref_id = COALESCE(VALUES(payroll_ref_id), payroll_ref_id)'
    )->execute([
        't' => $tenantId, 'p' => $placementId, 'party' => $economicPartyId,
        'source_type' => $sourceType, 'source_ref_id' => $sourceRefId,
        'period_start' => $values['period_start'] ?? null,
        'period_end' => $values['period_end'] ?? null,
        'quantity' => $values['quantity'] ?? 0,
        'basis_amount' => $values['basis_amount'] ?? 0,
        'amount' => $values['amount'] ?? 0,
        'currency' => $values['currency'] ?? 'USD',
        'status' => $values['status'] ?? 'projected',
        'ap_bill_id' => $values['ap_bill_id'] ?? null,
        'payroll_ref_id' => $values['payroll_ref_id'] ?? null,
    ]);
    $st = $pdo->prepare(
        'SELECT id FROM placement_economic_obligations
          WHERE tenant_id = :t AND source_type = :source_type
            AND source_ref_id = :source_ref_id AND economic_party_id = :party LIMIT 1'
    );
    $st->execute([
        't' => $tenantId, 'source_type' => $sourceType,
        'source_ref_id' => $sourceRefId, 'party' => $economicPartyId,
    ]);
    return (int) $st->fetchColumn();
}

function placementEconomicsUpdateParty(int $tenantId, int $partyId, array $changes): bool
{
    $allowed = [
        'money_flow','settlement_channel','fee_basis','fee_pct','fee_flat',
        'payment_terms','pwp_enabled','operating_cycle_id','effective_from','effective_to','active',
    ];
    $sets = [];
    $params = ['t' => $tenantId, 'id' => $partyId];
    foreach ($allowed as $field) {
        if (!array_key_exists($field, $changes)) continue;
        $value = $changes[$field];
        if ($field === 'payment_terms' && $value !== null && trim((string) $value) !== '') {
            $value = placementEconomicsNormaliseTerms((string) $value);
        }
        if (in_array($field, ['pwp_enabled','active'], true)) $value = !empty($value) ? 1 : 0;
        $sets[] = "{$field} = :{$field}";
        $params[$field] = $value === '' ? null : $value;
        if ($field === 'payment_terms') {
            $sets[] = 'payment_terms_overridden = :payment_terms_overridden';
            $params['payment_terms_overridden'] = $value === null || $value === '' ? 0 : 1;
        } elseif ($field === 'pwp_enabled') {
            $sets[] = 'pwp_overridden = 1';
        } elseif ($field === 'operating_cycle_id') {
            $sets[] = 'cycle_overridden = :cycle_overridden';
            $params['cycle_overridden'] = $value === null || $value === '' ? 0 : 1;
        }
    }
    if (!$sets) return false;
    $st = getDB()->prepare(
        'UPDATE placement_economic_parties SET ' . implode(', ', $sets) . '
          WHERE tenant_id = :t AND id = :id'
    );
    $st->execute($params);

    return $st->rowCount() > 0;
}

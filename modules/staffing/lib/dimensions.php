<?php
/**
 * Staffing assignment dimension resolver.
 *
 * A placement is the central economic object. Business events reference one
 * placement and inherit its client, worker, job, ownership, organizational,
 * compliance, and legal-entity dimensions here.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../core/sub_tenants.php';

function staffingServiceLineForEngagement(string $engagementType): string
{
    return match (strtolower(trim($engagementType))) {
        'w2', '1099', 'c2c', 'temp_to_perm' => 'contract_staffing',
        'direct_hire' => 'direct_hire',
        'referral' => 'referral',
        'internal' => 'internal',
        default => strtolower(trim($engagementType)),
    };
}

/** Turn internal dimension keys into an operator-facing setup message. */
function staffingDimensionMissingLabels(array $missing): array
{
    $labels = [
        'client' => 'client',
        'placement' => 'assignment',
        'worker' => 'worker',
        'job' => 'job / requisition',
        'recruiter' => 'recruiter',
        'account_manager' => 'account manager',
        'branch' => 'branch / business unit',
        'service_line' => 'service line',
        'work_state' => 'work location / state',
        'wc_class' => 'workers compensation class',
        'department_or_cost_center' => 'department or cost center',
        'legal_entity' => 'legal entity',
        'counterparty_entity' => 'counterparty legal entity',
        'vendor' => 'payable vendor',
        'vendor_ap_link' => 'vendor AP link',
    ];
    return array_values(array_map(
        static fn(string $key): string => $labels[$key] ?? str_replace('_', ' ', $key),
        array_values(array_unique(array_map('strval', $missing)))
    ));
}

/**
 * Return only the assignment gaps that are required for a particular action.
 *
 * The resolver's `missing` list is intentionally broad because it drives data
 * quality reporting. Transaction workflows must be narrower: a missing sales
 * owner should not stop an AP bill, and a missing WC class should not stop a
 * direct-hire invoice. Assignment-owned values still outrank any values passed
 * in by the caller, so required dimensions cannot be re-keyed per transaction.
 *
 * @param list<string> $required
 * @param array<string,mixed> $providedDimensions Non-assignment dimensions,
 *        such as counterparty_entity, that may legitimately be keyed on the JE.
 * @return list<string>
 */
function staffingDimensionMissingForRequirements(
    array $context,
    array $required,
    array $providedDimensions = []
): array {
    $assignmentDimensions = (array) ($context['dimensions'] ?? []);
    if (($context['vendor_dimension'] ?? null) !== null && ($context['vendor_dimension'] ?? '') !== '') {
        $assignmentDimensions['vendor'] = $context['vendor_dimension'];
    }
    $available = array_replace($providedDimensions, $assignmentDimensions);
    $reportingGaps = array_fill_keys(array_map('strval', (array) ($context['missing'] ?? [])), true);
    $assignmentOwned = [
        'client','placement','worker','job','recruiter','account_manager',
        'branch','service_line','work_state','wc_class','department',
        'cost_center','legal_entity','vendor','vendor_ap_link',
        'department_or_cost_center',
    ];

    $missing = [];
    foreach (array_values(array_unique(array_map('strval', $required))) as $key) {
        if ($key === '') continue;
        if (isset($reportingGaps[$key]) && in_array($key, $assignmentOwned, true)) {
            $missing[] = $key;
            continue;
        }
        if ($key === 'vendor_ap_link') {
            if (empty($context['vendor_ap_id'])) $missing[] = $key;
            continue;
        }
        if ($key === 'department_or_cost_center') {
            if (empty($available['department']) && empty($available['cost_center'])) $missing[] = $key;
            continue;
        }
        if (!array_key_exists($key, $available) || $available[$key] === null || $available[$key] === '') {
            $missing[] = $key;
        }
    }
    return array_values(array_unique($missing));
}

/** @return list<string> */
function staffingDimensionRequirementsForPurpose(array $context, string $purpose): array
{
    $engagement = strtolower(trim((string) ($context['placement']['engagement_type'] ?? '')));
    $required = match ($purpose) {
        'time' => ['placement', 'worker', 'legal_entity'],
        'billing', 'ar_accrual' => ['client', 'placement', 'legal_entity'],
        // The AP document owns its vendor. An assignment-linked expense line
        // only needs the assignment vendor when that line's account rules say
        // so (for example, subcontractor cost).
        'ap' => ['placement', 'legal_entity'],
        'ap_accrual' => ['placement', 'vendor', 'legal_entity'],
        default => [],
    };

    if ($purpose === 'time') {
        if ($engagement === 'internal') {
            $required[] = 'department_or_cost_center';
        } else {
            $required[] = 'client';
        }
        if (in_array($engagement, ['w2', 'temp_to_perm'], true)) {
            $required[] = 'work_state';
            $required[] = 'wc_class';
        }
        if (in_array($engagement, ['1099', 'c2c', 'referral'], true)) {
            $required[] = 'vendor';
            $required[] = 'vendor_ap_link';
        }
    }
    if ($purpose === 'billing' && $engagement === 'direct_hire') {
        $required[] = 'recruiter';
    }
    return array_values(array_unique($required));
}

/** @return list<string> */
function staffingDimensionBlockingMissing(
    array $context,
    string $purpose,
    array $additionalRequired = [],
    array $providedDimensions = []
): array {
    return staffingDimensionMissingForRequirements(
        $context,
        array_merge(staffingDimensionRequirementsForPurpose($context, $purpose), $additionalRequired),
        $providedDimensions
    );
}

/**
 * @return array{
 *   dimensions:array<string,int|string>,
 *   vendor_dimension:int|string|null,
 *   vendor_economic_party_id:?int,
 *   vendor_company_id:?int,
 *   vendor_ap_id:?int,
 *   event_entity_id:int,
 *   placement:array<string,mixed>,
 *   missing:list<string>,
 *   reporting_gaps:list<string>
 * }
 */
function staffingAssignmentDimensionContext(
    int $tenantId,
    int $placementId,
    int $eventEntityId,
    string $asOfDate,
    ?int $preferredVendorCompanyId = null
): array {
    if ($tenantId <= 0 || $placementId <= 0) {
        throw new \InvalidArgumentException('tenantId and placementId are required');
    }
    $pdo = getDB();
    $placementsTenantId = effectiveTenantIdForModule('placements', $tenantId) ?? $tenantId;
    $peopleTenantId = effectiveTenantIdForModule('people', $tenantId) ?? $tenantId;
    $accountingTenantId = effectiveTenantIdForModule('accounting', $tenantId) ?? $tenantId;

    $stmt = $pdo->prepare(
        'SELECT p.id, p.external_id, p.person_id, p.engagement_type,
                p.end_client_company_id, p.client_id, p.end_client_name,
                p.staffing_job_id, p.jobdiva_job_id,
                p.recruiter_name, p.recruiter_email,
                p.account_manager_name, p.account_manager_email,
                p.worksite_state, p.branch, p.service_line,
                p.workers_comp_class, p.department, p.cost_center,
                p.accounting_entity_id,
                sc.company_id AS staffing_client_company_id,
                sc.name AS staffing_client_name,
                pe.entity_id AS person_entity_id,
                sj.department AS job_department,
                sj.location_state AS job_location_state
           FROM placements p
      LEFT JOIN people pe
             ON pe.id = p.person_id
            AND pe.tenant_id = :people_tenant_id
      LEFT JOIN staffing_clients sc
             ON sc.id = p.client_id
            AND sc.tenant_id = p.tenant_id
      LEFT JOIN staffing_jobs sj
             ON sj.id = p.staffing_job_id
            AND sj.tenant_id = p.tenant_id
          WHERE p.tenant_id = :placements_tenant_id
            AND p.id = :placement_id
            AND p.deleted_at IS NULL
          LIMIT 1'
    );
    $stmt->execute([
        'people_tenant_id' => $peopleTenantId,
        'placements_tenant_id' => $placementsTenantId,
        'placement_id' => $placementId,
    ]);
    $placement = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$placement) {
        throw new \RuntimeException("Placement #{$placementId} was not found while resolving dimensions");
    }

    $owners = [];
    $ownerStmt = $pdo->prepare(
        "SELECT role, user_id
           FROM placement_commissions
          WHERE tenant_id = :tenant_id
            AND placement_id = :placement_id
            AND role IN ('recruiter','account_manager')
            AND effective_from <= :as_of
            AND (effective_to IS NULL OR effective_to >= :as_of_2)
          ORDER BY effective_from DESC, id DESC"
    );
    $ownerStmt->execute([
        'tenant_id' => $placementsTenantId,
        'placement_id' => $placementId,
        'as_of' => $asOfDate,
        'as_of_2' => $asOfDate,
    ]);
    foreach ($ownerStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $owner) {
        $role = (string) ($owner['role'] ?? '');
        if ($role !== '' && !isset($owners[$role]) && (int) ($owner['user_id'] ?? 0) > 0) {
            $owners[$role] = (int) $owner['user_id'];
        }
    }

    $engagement = strtolower((string) ($placement['engagement_type'] ?? ''));
    $vendorPriority = match ($engagement) {
        'referral' => "CASE WHEN role = 'referrer' AND fee_basis = 'per_hour' THEN 1 ELSE 2 END",
        '1099', 'c2c' => "CASE WHEN fee_basis = 'pay_rate' THEN 1 WHEN role = 'c2c_vendor' THEN 2 ELSE 3 END",
        default => "CASE WHEN fee_basis = 'pay_rate' THEN 1 ELSE 2 END",
    };
    $vendor = null;
    $vendorStmt = $pdo->prepare(
        "SELECT id AS economic_party_id, company_id, person_id, ap_vendor_id, display_name
           FROM placement_economic_parties
          WHERE tenant_id = :tenant_id
            AND placement_id = :placement_id
            AND active = 1
            AND money_flow = 'payable'
            AND settlement_channel = 'ap'
            AND (effective_from IS NULL OR effective_from <= :as_of)
            AND (effective_to IS NULL OR effective_to >= :as_of_2)
          ORDER BY CASE WHEN company_id = :preferred_vendor_company_id THEN 0 ELSE 1 END,
                   {$vendorPriority}, id
          LIMIT 1"
    );
    $vendorStmt->execute([
        'tenant_id' => $placementsTenantId,
        'placement_id' => $placementId,
        'as_of' => $asOfDate,
        'as_of_2' => $asOfDate,
        'preferred_vendor_company_id' => $preferredVendorCompanyId ?? 0,
    ]);
    $vendor = $vendorStmt->fetch(\PDO::FETCH_ASSOC) ?: null;

    // Referral creation can precede an economics reconciliation. Preserve the
    // canonical company relationship as a fallback while integrity checks flag
    // the missing AP projection.
    if (!$vendor && $engagement === 'referral') {
        $refStmt = $pdo->prepare(
            "SELECT NULL AS economic_party_id, referrer_company_id AS company_id,
                    referrer_person_id AS person_id, NULL AS ap_vendor_id,
                    referrer_vendor_name AS display_name
               FROM placement_referrals
              WHERE tenant_id = :tenant_id
                AND placement_id = :placement_id
                AND start_date <= :as_of
                AND (end_date IS NULL OR end_date >= :as_of_2)
              ORDER BY id DESC LIMIT 1"
        );
        $refStmt->execute([
            'tenant_id' => $placementsTenantId,
            'placement_id' => $placementId,
            'as_of' => $asOfDate,
            'as_of_2' => $asOfDate,
        ]);
        $vendor = $refStmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    $canonicalClientCompanyId = (int) ($placement['end_client_company_id'] ?? 0) > 0
        ? (int) $placement['end_client_company_id']
        : ((int) ($placement['staffing_client_company_id'] ?? 0) > 0
            ? (int) $placement['staffing_client_company_id']
            : null);
    $clientDimension = $canonicalClientCompanyId
        ?? (trim((string) ($placement['end_client_name'] ?? '')) !== ''
            ? 'name:' . strtolower(trim((string) $placement['end_client_name']))
            : (trim((string) ($placement['staffing_client_name'] ?? '')) !== ''
                ? 'name:' . strtolower(trim((string) $placement['staffing_client_name']))
                : null));
    $jobDimension = (int) ($placement['staffing_job_id'] ?? 0) > 0
        ? (int) $placement['staffing_job_id']
        : (trim((string) ($placement['jobdiva_job_id'] ?? '')) !== ''
            ? 'jobdiva:' . trim((string) $placement['jobdiva_job_id'])
            : null);
    // Assignment ownership is the canonical reporting relationship. Commission
    // rows describe effective-dated compensation attribution and may include a
    // different or split recipient; use them only to fill a blank assignment.
    $recruiterDimension = trim((string) ($placement['recruiter_email'] ?? ''))
        ?: (trim((string) ($placement['recruiter_name'] ?? '')) ?: ($owners['recruiter'] ?? null));
    $accountManagerDimension = trim((string) ($placement['account_manager_email'] ?? ''))
        ?: (trim((string) ($placement['account_manager_name'] ?? '')) ?: ($owners['account_manager'] ?? null));
    $legalEntity = 0;
    $entityLookup = $pdo->prepare(
        'SELECT id FROM accounting_entities
          WHERE tenant_id = :tenant_id AND id = :id AND active = 1
          LIMIT 1'
    );
    foreach (array_unique([
        (int) ($placement['accounting_entity_id'] ?? 0),
        (int) ($placement['person_entity_id'] ?? 0),
        $eventEntityId,
    ]) as $candidateEntityId) {
        if ($candidateEntityId <= 0) continue;
        $entityLookup->execute([
            'tenant_id' => $accountingTenantId,
            'id' => $candidateEntityId,
        ]);
        if ($entityLookup->fetchColumn()) {
            $legalEntity = $candidateEntityId;
            break;
        }
    }
    $vendorCompanyId = $vendor && (int) ($vendor['company_id'] ?? 0) > 0 ? (int) $vendor['company_id'] : null;
    $vendorPersonId = $vendor && (int) ($vendor['person_id'] ?? 0) > 0 ? (int) $vendor['person_id'] : null;
    $vendorApId = $vendor && (int) ($vendor['ap_vendor_id'] ?? 0) > 0 ? (int) $vendor['ap_vendor_id'] : null;
    $vendorEconomicPartyId = $vendor && (int) ($vendor['economic_party_id'] ?? 0) > 0
        ? (int) $vendor['economic_party_id']
        : null;
    // This dimension identifies the economic party across assignments. The
    // placement_economic_parties id is a relationship row and would fragment
    // one vendor into a different reporting member for every placement.
    $vendorDimension = $vendorCompanyId
        ?? ($vendorPersonId ? 'person:' . $vendorPersonId
            : ($vendorApId ? 'ap_vendor:' . $vendorApId
                : ($vendorEconomicPartyId ? 'economic_party:' . $vendorEconomicPartyId
                    : (trim((string) ($vendor['display_name'] ?? '')) !== ''
                        ? 'name:' . strtolower(trim((string) $vendor['display_name']))
                        : null))));

    $rawDimensions = [
        'client' => $clientDimension ?: null,
        'placement' => $placementId,
        'worker' => (int) ($placement['person_id'] ?? 0) ?: null,
        'job' => $jobDimension,
        'recruiter' => $recruiterDimension ?: null,
        'account_manager' => $accountManagerDimension ?: null,
        'branch' => trim((string) ($placement['branch'] ?? '')) ?: null,
        'service_line' => trim((string) ($placement['service_line'] ?? ''))
            ?: staffingServiceLineForEngagement((string) $placement['engagement_type']),
        'work_state' => trim((string) ($placement['worksite_state'] ?? ''))
            ?: (trim((string) ($placement['job_location_state'] ?? '')) ?: null),
        'wc_class' => trim((string) ($placement['workers_comp_class'] ?? '')) ?: null,
        'department' => trim((string) ($placement['department'] ?? ''))
            ?: (trim((string) ($placement['job_department'] ?? '')) ?: null),
        'cost_center' => trim((string) ($placement['cost_center'] ?? '')) ?: null,
        'legal_entity' => $legalEntity > 0 ? $legalEntity : null,
    ];
    $dimensions = array_filter(
        $rawDimensions,
        static fn(mixed $value): bool => $value !== null && $value !== ''
    );

    $missing = [];
    foreach (['placement','worker','job','recruiter','account_manager','branch','service_line','work_state'] as $key) {
        if (!array_key_exists($key, $dimensions)) $missing[] = $key;
    }
    $hasCanonicalClient = $canonicalClientCompanyId !== null;
    if ($engagement !== 'internal' && !$hasCanonicalClient) $missing[] = 'client';
    $hasAssignmentEntity = (int) ($placement['accounting_entity_id'] ?? 0) > 0
        || (int) ($placement['person_entity_id'] ?? 0) > 0;
    if (!$hasAssignmentEntity) $missing[] = 'legal_entity';
    if (in_array($engagement, ['w2','temp_to_perm'], true) && !array_key_exists('wc_class', $dimensions)) $missing[] = 'wc_class';
    if ($engagement === 'internal' && !isset($dimensions['department']) && !isset($dimensions['cost_center'])) {
        $missing[] = 'department_or_cost_center';
    }
    if (in_array($engagement, ['1099','c2c','referral'], true) && $vendorDimension === null) $missing[] = 'vendor';
    if (in_array($engagement, ['1099','c2c','referral'], true) && $vendorApId === null) $missing[] = 'vendor_ap_link';

    $reportingGaps = array_values(array_unique($missing));
    return [
        'dimensions' => $dimensions,
        'vendor_dimension' => $vendorDimension,
        'vendor_economic_party_id' => $vendorEconomicPartyId,
        'vendor_company_id' => $vendorCompanyId,
        'vendor_ap_id' => $vendorApId,
        'event_entity_id' => $legalEntity,
        'placement' => $placement,
        // `missing` remains for API compatibility. New transaction workflows
        // use staffingDimensionBlockingMissing() and treat this broader list as
        // reporting-quality guidance rather than a universal posting gate.
        'missing' => $reportingGaps,
        'reporting_gaps' => $reportingGaps,
    ];
}

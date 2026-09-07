<?php
/**
 * Diagnose a JobDiva placement-rate write without changing production data.
 *
 * Usage: php scripts/jobdiva_rate_write_probe.php <placement-id> <start-id> <bill-rate> <pay-rate>
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(64);
}

require_once __DIR__ . '/../core/jobdiva/sync.php';

$pdo = getDB();
if (!$pdo) {
    fwrite(STDERR, "No database connection.\n");
    exit(70);
}

if (in_array(($argv[1] ?? ''), ['--roster', '--active-roster'], true)) {
    $tenantId = (int) ($argv[2] ?? 2);
    $activeOnly = ($argv[1] ?? '') === '--active-roster';
    $statusPredicate = $activeOnly
        ? "p.status = 'active'"
        : "p.status IN ('active', 'pending_start', 'on_hold')";
    $stmt = $pdo->prepare(
        "SELECT p.id, p.external_id, p.title, p.status, p.start_date, p.end_date,
                p.engagement_type, p.end_client_name, p.end_client_company_id,
                p.client_bill_cycle, p.client_payment_terms_override,
                p.vendor_pay_cycle, p.vendor_payment_terms_override, p.vendor_pwp_enabled,
                ec.name AS canonical_end_client_name,
                pe.first_name, pe.last_name,
                m.external_id AS mapped_start_id, m.sync_status AS mapping_status,
                m.last_error AS mapping_error, m.payload_snapshot,
                COUNT(pr.id) AS rate_rows
           FROM placements p
           LEFT JOIN people pe
             ON pe.tenant_id = p.tenant_id AND pe.id = p.person_id
           LEFT JOIN external_entity_mappings m
             ON m.tenant_id = p.tenant_id
            AND m.source_system = 'jobdiva'
            AND m.internal_entity_type = 'placement'
            AND m.internal_entity_id = p.id
           LEFT JOIN placement_rates pr
             ON pr.tenant_id = p.tenant_id AND pr.placement_id = p.id
           LEFT JOIN companies ec
             ON ec.tenant_id = p.tenant_id AND ec.id = p.end_client_company_id
          WHERE p.tenant_id = :tenant_id
            AND p.deleted_at IS NULL
            AND {$statusPredicate}
          GROUP BY p.id, p.external_id, p.title, p.status, p.start_date, p.end_date,
                   p.engagement_type, p.end_client_name, p.end_client_company_id,
                   p.client_bill_cycle, p.client_payment_terms_override,
                   p.vendor_pay_cycle, p.vendor_payment_terms_override, p.vendor_pwp_enabled,
                   ec.name,
                   pe.first_name, pe.last_name, m.external_id, m.sync_status,
                   m.last_error, m.payload_snapshot
          ORDER BY p.start_date DESC, p.id DESC"
    );
    $stmt->execute(['tenant_id' => $tenantId]);
    $latestRateStmt = $pdo->prepare(
        'SELECT id, effective_from, effective_to, bill_rate, pay_rate, currency,
                bill_rate_unit, pay_rate_unit, approved_at, adjusted_bill_rate,
                net_to_vendor, background_fee_total, adder_pct, bill_adder_pct,
                bill_adder_flat, bill_discount_pct, bill_discount_flat,
                workers_comp_pct, benefits_load_pct, other_cost_per_hour, other_cost_flat
           FROM placement_rates
          WHERE tenant_id = :tenant_id AND placement_id = :placement_id
          ORDER BY effective_from DESC, id DESC
          LIMIT 1'
    );
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $payload = json_decode((string) ($row['payload_snapshot'] ?? ''), true);
        $contract = is_array($payload['_jd_contract'] ?? null) ? $payload['_jd_contract'] : [];
        unset($row['payload_snapshot']);
        $row['contract_status'] = $contract['placement_status'] ?? null;
        $row['contract_start_id'] = $contract['start_id'] ?? null;
        $row['contract_end_date'] = $contract['end_date'] ?? null;
        $row['contract_billing_approved'] = $contract['approved'] ?? null;
        $row['contract_billing_closed'] = $contract['closed'] ?? null;
        $row['contract_salary_approved'] = $contract['salary_approved'] ?? null;
        $row['contract_salary_closed'] = $contract['salary_closed'] ?? null;
        $row['contract_salary_status'] = $contract['salary_status'] ?? null;
        $row['contract_bill_rate'] = $contract['bill_rate'] ?? null;
        $row['contract_pay_rate'] = $contract['pay_rate'] ?? null;
        $row['contract_bill_rate_in_vms'] = $contract['bill_rate_in_vms'] ?? null;
        $row['contract_net_bill_rate'] = $contract['net_bill_rate'] ?? null;
        $row['contract_client_company_name'] = $contract['client_company_name'] ?? null;
        $row['contract_client_company_id'] = $contract['client_company_id'] ?? null;
        $row['contract_client_bill_cycle'] = $contract['client_bill_cycle'] ?? null;
        $row['contract_vendor_pay_cycle'] = $contract['vendor_pay_cycle'] ?? null;
        $row['contract_client_payment_terms'] = $contract['client_payment_terms'] ?? null;
        $row['contract_vendor_payment_terms'] = $contract['vendor_payment_terms'] ?? null;
        $row['contract_paid_when_paid'] = $contract['paid_when_paid'] ?? null;
        $row['contract_corporation_name'] = $contract['corporation_name'] ?? null;
        $row['contract_corporation_id'] = $contract['corporation_id'] ?? null;
        $row['contract_referral_vendor'] = $contract['referral_vendor'] ?? null;
        $row['contract_referral_fee_amount'] = $contract['referral_fee_amount'] ?? null;
        $row['contract_overheads'] = $contract['overheads'] ?? null;
        $latestRateStmt->execute([
            'tenant_id' => $tenantId,
            'placement_id' => (int) ($row['id'] ?? 0),
        ]);
        $row['current_rate'] = $latestRateStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $row['economic_parties'] = array_map(
            static fn(array $party): array => [
                'role' => $party['role'] ?? null,
                'display_name' => $party['display_name'] ?? null,
                'company_id' => $party['company_id'] ?? null,
                'company_name' => $party['company_name'] ?? null,
                'ap_vendor_id' => $party['ap_vendor_id'] ?? null,
                'vendor_name' => $party['vendor_name'] ?? null,
                'money_flow' => $party['money_flow'] ?? null,
                'settlement_channel' => $party['settlement_channel'] ?? null,
                'fee_basis' => $party['fee_basis'] ?? null,
                'fee_pct' => $party['fee_pct'] ?? null,
                'fee_flat' => $party['fee_flat'] ?? null,
                'payment_terms' => $party['payment_terms'] ?? null,
                'pwp_enabled' => $party['pwp_enabled'] ?? null,
                'cycle_cadence' => $party['cycle_cadence'] ?? null,
                'source_system' => $party['source_system'] ?? null,
                'source_external_id' => $party['source_external_id'] ?? null,
            ],
            placementEconomicsParties($tenantId, (int) ($row['id'] ?? 0))
        );
        $issues = [];
        $sameText = static fn(mixed $a, mixed $b): bool => strtolower(trim((string) $a)) === strtolower(trim((string) $b));
        $sameAmount = static fn(mixed $a, mixed $b): bool => abs((float) $a - (float) $b) < 0.005;
        $sameRatio = static fn(mixed $a, mixed $b): bool => abs((float) $a - (float) $b) < 0.000005;
        if (($row['mapping_status'] ?? '') !== 'ok') $issues[] = 'mapping_not_ok';
        if (($contract['placement_status'] ?? '') !== 'active') $issues[] = 'contract_not_active';
        if (($contract['salary_approved'] ?? null) !== true) $issues[] = 'salary_not_approved';
        if (!$sameText($row['engagement_type'] ?? '', $contract['engagement_type'] ?? '')) {
            $issues[] = 'classification_mismatch';
        }
        $canonicalClient = trim((string) ($row['canonical_end_client_name'] ?? ''));
        if ($canonicalClient === '') $canonicalClient = trim((string) ($row['end_client_name'] ?? ''));
        $contractClient = trim((string) ($contract['client_company_name'] ?? ''));
        if ($contractClient === '' || !$sameText($canonicalClient, $contractClient)) {
            $issues[] = 'client_mismatch';
        }
        if (!empty($contract['end_date_present'])) {
            $expectedEnd = jobdivaNormaliseDate($contract['end_date'] ?? null) ?? '';
            if ((string) ($row['end_date'] ?? '') !== $expectedEnd) $issues[] = 'end_date_mismatch';
        }
        $currentRate = is_array($row['current_rate'] ?? null) ? $row['current_rate'] : [];
        $expectedBill = (float) ($contract['bill_rate'] ?? 0);
        if ($expectedBill <= 0) $expectedBill = (float) ($contract['net_bill_rate'] ?? 0);
        $netBill = (float) ($contract['net_bill_rate'] ?? $contract['bill_rate'] ?? 0);
        $vmsBill = (float) ($contract['bill_rate_in_vms'] ?? 0);
        if ($vmsBill > 0 && $netBill > 0 && $netBill < $vmsBill) $expectedBill = $vmsBill;
        $expectedPay = (float) ($contract['pay_rate'] ?? 0);
        if ($expectedPay <= 0) $expectedPay = (float) ($contract['pay_rate_to_vendor'] ?? 0);
        $row['expected_bill_rate'] = $expectedBill;
        $row['expected_pay_rate'] = $expectedPay;
        $row['expected_client_name'] = $contractClient;
        if ($currentRate === []) {
            $issues[] = 'missing_rate';
        } else {
            if (!$sameAmount($currentRate['bill_rate'] ?? 0, $expectedBill)) $issues[] = 'bill_rate_mismatch';
            if (!$sameAmount($currentRate['pay_rate'] ?? 0, $expectedPay)) $issues[] = 'pay_rate_mismatch';
            $sourceRatios = [
                'payroll_load_pct' => 'adder_pct',
                'workers_comp_pct' => 'workers_comp_pct',
                'benefits_load_pct' => 'benefits_load_pct',
            ];
            foreach ($sourceRatios as $contractField => $rateField) {
                if (!array_key_exists($contractField, $contract)) continue;
                $expectedRatio = jobdivaParsePercent($contract[$contractField]);
                if ($expectedRatio !== null && !$sameRatio($currentRate[$rateField] ?? 0, $expectedRatio)) {
                    $issues[] = $rateField . '_mismatch';
                }
            }
            $sourceAmounts = [
                'background_fee_total' => 'background_fee_total',
                'other_cost_per_hour' => 'other_cost_per_hour',
                'other_cost_flat' => 'other_cost_flat',
            ];
            foreach ($sourceAmounts as $contractField => $rateField) {
                if (!array_key_exists($contractField, $contract)) continue;
                $expectedAmount = jobdivaParseRateAmount($contract[$contractField]);
                if (!$sameAmount($currentRate[$rateField] ?? 0, max(0, $expectedAmount))) {
                    $issues[] = $rateField . '_mismatch';
                }
            }
            if ($vmsBill > 0 && $netBill > 0 && $netBill < $vmsBill) {
                $expectedDiscount = round(($vmsBill - $netBill) / $vmsBill, 6);
                if (!$sameRatio($currentRate['bill_discount_pct'] ?? 0, $expectedDiscount)) {
                    $issues[] = 'bill_discount_pct_mismatch';
                }
            }
        }
        if (!empty($contract['client_bill_cycle'])
            && !$sameText($row['client_bill_cycle'] ?? '', $contract['client_bill_cycle'])) {
            $issues[] = 'billing_frequency_mismatch';
        }
        if (!empty($contract['vendor_pay_cycle'])
            && !$sameText($row['vendor_pay_cycle'] ?? '', $contract['vendor_pay_cycle'])) {
            $issues[] = 'payment_frequency_mismatch';
        }
        if (!empty($contract['client_payment_terms'])
            && !$sameText($row['client_payment_terms_override'] ?? '', $contract['client_payment_terms'])) {
            $issues[] = 'client_payment_terms_mismatch';
        }
        $isExternalLabor = in_array((string) ($contract['engagement_type'] ?? ''), ['c2c', '1099'], true);
        if ($isExternalLabor && !empty($contract['vendor_payment_terms'])
            && !$sameText($row['vendor_payment_terms_override'] ?? '', $contract['vendor_payment_terms'])) {
            $issues[] = 'vendor_payment_terms_mismatch';
        }
        if ($isExternalLabor && array_key_exists('paid_when_paid', $contract)
            && (bool) ($row['vendor_pwp_enabled'] ?? false) !== (bool) $contract['paid_when_paid']) {
            $issues[] = 'paid_when_paid_mismatch';
        }
        $receivables = array_values(array_filter(
            $row['economic_parties'],
            static fn(array $party): bool => ($party['money_flow'] ?? '') === 'receivable'
                && ($party['settlement_channel'] ?? '') === 'ar'
        ));
        if (count($receivables) !== 1) $issues[] = 'receivable_party_mismatch';
        $laborChannel = in_array((string) ($contract['engagement_type'] ?? ''), ['c2c', '1099'], true)
            ? 'ap'
            : 'payroll';
        $laborPayees = array_values(array_filter(
            $row['economic_parties'],
            static fn(array $party): bool => ($party['money_flow'] ?? '') === 'payable'
                && ($party['settlement_channel'] ?? '') === $laborChannel
                && ($party['fee_basis'] ?? '') === 'pay_rate'
        ));
        if (count($laborPayees) !== 1) $issues[] = 'labor_payee_mismatch';
        $unnormalizedPayables = array_values(array_filter(
            $row['economic_parties'],
            static fn(array $party): bool => ($party['money_flow'] ?? '') === 'payable'
                && ($party['settlement_channel'] ?? '') === 'ap'
                && empty($party['ap_vendor_id'])
        ));
        if ($unnormalizedPayables !== []) $issues[] = 'ap_payee_not_normalized';
        $row['audit_issues'] = $issues;
        if (($contract['salary_approved'] ?? null) !== true) {
            $detailRows = is_array($payload['_jd_assignment_detail'] ?? null)
                ? $payload['_jd_assignment_detail']
                : [];
            $matchingRows = jobdivaAssignmentContractRowsForStart(
                $detailRows,
                $payload,
                (string) ($row['mapped_start_id'] ?? '')
            );
            foreach (['BILLING', 'SALARY'] as $section) {
                $facts = [];
                $entries = jobdivaAssignmentContractEntries(
                    jobdivaAssignmentContractSectionRows($matchingRows, $section)
                );
                foreach ($entries as $entry) {
                    $key = (string) ($entry['path'] ?? $entry['key'] ?? '');
                    $value = $entry['value'] ?? null;
                    if ($key === '' || (!is_scalar($value) && $value !== null)) continue;
                    $normalised = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $key));
                    if (!preg_match('/approved|closed|actual|status|start|end|effective|active|terminate|pay|salary|bill/i', $normalised)) {
                        continue;
                    }
                    $facts[$key] = $value;
                }
                $row[strtolower($section) . '_lifecycle'] = $facts;
            }
        }
        $rows[] = $row;
    }
    $issueCounts = [];
    foreach ($rows as $row) {
        foreach ($row['audit_issues'] ?? [] as $issue) {
            $issueCounts[$issue] = ($issueCounts[$issue] ?? 0) + 1;
        }
    }
    ksort($issueCounts);
    echo json_encode([
        'tenant_id' => $tenantId,
        'scope' => $activeOnly ? 'active' : 'current',
        'count' => count($rows),
        'mapping_status_counts' => array_count_values(array_map(
            static fn(array $row): string => (string) ($row['mapping_status'] ?? 'unmapped'),
            $rows
        )),
        'issue_counts' => $issueCounts,
        'clean_count' => count(array_filter(
            $rows,
            static fn(array $row): bool => ($row['audit_issues'] ?? []) === []
        )),
        'rows' => $activeOnly
            ? array_values(array_filter(
                $rows,
                static fn(array $row): bool => ($row['audit_issues'] ?? []) !== []
            ))
            : $rows,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$placementId = (int) ($argv[1] ?? 0);
$startId = trim((string) ($argv[2] ?? ''));
$billRate = (float) ($argv[3] ?? 0);
$payRate = (float) ($argv[4] ?? 0);
if ($placementId <= 0 || $startId === '' || $billRate <= 0 || $payRate <= 0) {
    fwrite(STDERR, "Invalid probe arguments.\n");
    exit(64);
}

$placementStmt = $pdo->prepare(
    'SELECT tenant_id, external_id, person_id, end_client_company_id,
            end_client_name, start_date, end_date, status, engagement_type
       FROM placements
      WHERE id = :id
      LIMIT 1'
);
$placementStmt->execute(['id' => $placementId]);
$placement = $placementStmt->fetch(PDO::FETCH_ASSOC) ?: [];
if ($placement === []) {
    fwrite(STDERR, "Placement not found.\n");
    exit(66);
}

$tenantId = (int) ($placement['tenant_id'] ?? 0);
$mappingStmt = $pdo->prepare(
    "SELECT id, sync_status, updated_at, payload_snapshot
       FROM external_entity_mappings
      WHERE tenant_id = :tenant_id
        AND source_system = 'jobdiva'
        AND internal_entity_type = 'placement'
        AND external_id = :external_id
      ORDER BY id DESC
      LIMIT 1"
);
$mappingStmt->execute(['tenant_id' => $tenantId, 'external_id' => $startId]);
$mapping = $mappingStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$storedPayload = json_decode((string) ($mapping['payload_snapshot'] ?? ''), true);
if (!is_array($storedPayload)) $storedPayload = [];
$storedContract = is_array($storedPayload['_jd_contract'] ?? null)
    ? $storedPayload['_jd_contract']
    : [];

$columns = $pdo->query('SHOW COLUMNS FROM placement_rates')->fetchAll(PDO::FETCH_COLUMN) ?: [];
$readRates = static function () use ($pdo, $tenantId, $placementId): array {
    $stmt = $pdo->prepare(
        'SELECT id, effective_from, effective_to, bill_rate, pay_rate, approved_at,
                created_by_user_id
           FROM placement_rates
          WHERE tenant_id = :tenant_id AND placement_id = :placement_id
          ORDER BY id ASC'
    );
    $stmt->execute(['tenant_id' => $tenantId, 'placement_id' => $placementId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
};

$result = [
    'placement' => [
        'id' => $placementId,
        'external_id' => (string) ($placement['external_id'] ?? ''),
        'start_date' => (string) ($placement['start_date'] ?? ''),
        'engagement_type' => (string) ($placement['engagement_type'] ?? ''),
    ],
    'mapping' => [
        'id' => (int) ($mapping['id'] ?? 0),
        'sync_status' => (string) ($mapping['sync_status'] ?? ''),
        'updated_at' => (string) ($mapping['updated_at'] ?? ''),
        'has_contract' => $storedContract !== [],
        'contract_rates' => [
            'bill_rate' => $storedContract['bill_rate'] ?? null,
            'bill_rate_in_vms' => $storedContract['bill_rate_in_vms'] ?? null,
            'net_bill_rate' => $storedContract['net_bill_rate'] ?? null,
            'pay_rate' => $storedContract['pay_rate'] ?? null,
            'pay_rate_to_vendor' => $storedContract['pay_rate_to_vendor'] ?? null,
        ],
    ],
    'rate_columns' => $columns,
    'before' => $readRates(),
];

$probePayload = $storedPayload;
$probePayload['_jd_contract'] = array_replace($storedContract, [
    'contract_version' => 1,
    'source' => 'EmployeeAssignmentRecordsDetail',
    'start_id' => $startId,
    'start_date' => (string) ($placement['start_date'] ?? ''),
    'engagement_type' => (string) ($placement['engagement_type'] ?? 'w2'),
    'bill_rate' => $billRate,
    'pay_rate' => $payRate,
]);
$probePayload['__cf_force_source_contract'] = true;

try {
    $pdo->beginTransaction();
    $result['writer_returned'] = jobdivaSyncUpsertPlacementRates(
        $tenantId,
        $placementId,
        (string) ($placement['start_date'] ?? ''),
        $probePayload
    );
    $result['during_transaction'] = $readRates();
} catch (Throwable $e) {
    $result['writer_error'] = $e->getMessage();
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
$result['after_rollback'] = $readRates();

$projectorPayload = jobdivaAssignmentMarkVerified(
    $probePayload,
    $startId,
    'EmployeeAssignmentRecordsDetail:rate_probe'
);
$projectorPayload['__cf_jobdiva_expected_start_id'] = $startId;
try {
    $pdo->beginTransaction();
    $result['projector'] = jobdivaProjectorProjectPlacement(
        $tenantId,
        $projectorPayload,
        null,
        [
            'payload_is_enriched' => true,
            'external_id' => $startId,
            'existing_placement_id' => $placementId,
            'person_id' => (int) ($placement['person_id'] ?? 0),
            'end_client_company_id' => (int) ($placement['end_client_company_id'] ?? 0),
            'force_source_contract' => true,
        ]
    );
    $result['after_projector'] = jobdivaPlacementProjectionAuditSnapshot($tenantId, $placementId);
} catch (Throwable $e) {
    $result['projector_error'] = $e->getMessage();
    $result['after_projector_error'] = jobdivaPlacementProjectionAuditSnapshot($tenantId, $placementId);
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

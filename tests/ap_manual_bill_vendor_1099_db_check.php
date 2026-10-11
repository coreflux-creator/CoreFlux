<?php
/** Exercise saved vendor tax classification in the disposable, fully migrated CI database. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'coreaccounting'
    || getenv('COREFLUX_STANDALONE_DATABASE') !== 'coreaccounting_ci'
    || !in_array('--confirm-disposable-fixture', $argv, true)) {
    fwrite(STDERR, "Disposable CoreAccounting CI database only.\n");
    exit(2);
}
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../modules/ap/lib/bill_drafts.php';

$pdo = getDB();
if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'coreaccounting_ci') {
    throw new RuntimeException('Connected database is not the disposable CI database.');
}
$tenantId = (int) $pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();
$entityStmt = $pdo->prepare('SELECT id FROM accounting_entities WHERE tenant_id = :t AND active = 1 ORDER BY id LIMIT 1');
$entityStmt->execute(['t' => $tenantId]);
$entityId = (int) $entityStmt->fetchColumn();
if ($tenantId <= 0 || $entityId <= 0) throw new RuntimeException('The CI tenant and entity were not provisioned.');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS {$message}\n";
};
$base = [
    'entity_id' => $entityId, 'bill_date' => '2026-10-11', 'received_at' => '2026-10-11',
    'due_date' => '2026-11-10', 'currency' => 'USD', 'tax_rate_pct' => 0,
    'lines' => [[
        'item_type' => 'other', 'description' => 'Synthetic vendor service',
        'quantity' => '1', 'unit' => 'each', 'unit_price' => '1.00',
        'gl_expense_account_code' => '6990', 'is_1099_eligible' => true,
    ]],
];
$insert = $pdo->prepare(
    'INSERT INTO ap_vendors_index (tenant_id, vendor_name, vendor_type, requires_1099)
     VALUES (:t, :name, :type, :flag)'
);
$fetch = $pdo->prepare(
    'SELECT b.vendor_name, b.vendor_type, l.is_1099_eligible
       FROM ap_bills b JOIN ap_bill_lines l ON l.bill_id = b.id
      WHERE b.tenant_id = :t AND b.id = :id'
);
$pdo->beginTransaction();
try {
    $insert->execute(['t' => $tenantId, 'name' => 'CI 1099 opted-out individual',
        'type' => '1099_individual', 'flag' => 0]);
    $optedOutId = (int) $pdo->lastInsertId();
    $bill = apCreateManualBill($tenantId, $base + [
        'vendor_id' => $optedOutId, 'vendor_name' => 'CI 1099 opted-out individual',
        'vendor_type' => '1099_individual', 'bill_number' => 'CI-1099-OUT',
    ]);
    $fetch->execute(['t' => $tenantId, 'id' => $bill['id']]);
    $row = $fetch->fetch(PDO::FETCH_ASSOC);
    $assert($row['vendor_type'] === '1099_individual' && (int) $row['is_1099_eligible'] === 0,
        'an individual explicitly marked non-1099 stays excluded even when a line requests inclusion');

    $insert->execute(['t' => $tenantId, 'name' => 'CI 1099 partnership',
        'type' => 'w9_business', 'flag' => 1]);
    $partnershipId = (int) $pdo->lastInsertId();
    $bill = apCreateManualBill($tenantId, $base + [
        'vendor_id' => $partnershipId, 'vendor_name' => 'CI 1099 partnership',
        'vendor_type' => 'w9_business', 'bill_number' => 'CI-1099-PARTNER',
    ]);
    $fetch->execute(['t' => $tenantId, 'id' => $bill['id']]);
    $row = $fetch->fetch(PDO::FETCH_ASSOC);
    $assert($row['vendor_type'] === 'w9_business' && (int) $row['is_1099_eligible'] === 1,
        'a W-9 business explicitly marked 1099 carries the flag to its bill line');

    $excluded = $base;
    $excluded['lines'][0]['is_1099_eligible'] = false;
    $bill = apCreateManualBill($tenantId, $excluded + [
        'vendor_id' => $partnershipId, 'vendor_name' => 'CI 1099 partnership',
        'vendor_type' => 'w9_business', 'bill_number' => 'CI-1099-LINE-EXCLUDED',
    ]);
    $fetch->execute(['t' => $tenantId, 'id' => $bill['id']]);
    $assert((int) $fetch->fetch(PDO::FETCH_ASSOC)['is_1099_eligible'] === 0,
        'an otherwise eligible vendor can have a line-level exclusion');

    $bill = apCreateManualBill($tenantId, $base + [
        'vendor_name' => 'CI 1099 partnership', 'vendor_type' => 'other',
        'bill_number' => 'CI-1099-NAME-ONLY',
    ]);
    $fetch->execute(['t' => $tenantId, 'id' => $bill['id']]);
    $row = $fetch->fetch(PDO::FETCH_ASSOC);
    $assert($row['vendor_type'] === 'w9_business' && (int) $row['is_1099_eligible'] === 1,
        'the CoreOne name-only path reuses a saved classification');
    $vendorCheck = $pdo->prepare('SELECT vendor_type, requires_1099 FROM ap_vendors_index WHERE tenant_id = :t AND id = :id');
    $vendorCheck->execute(['t' => $tenantId, 'id' => $partnershipId]);
    $saved = $vendorCheck->fetch(PDO::FETCH_ASSOC);
    $assert($saved['vendor_type'] === 'w9_business' && (int) $saved['requires_1099'] === 1,
        'manual bill preparation does not overwrite the vendor directory');

    $reconciled = placementEconomicsEnsureVendor(
        $tenantId, 'CI 1099 opted-out individual', null, 'other', null, null, 712345
    );
    $vendorCheck->execute(['t' => $tenantId, 'id' => $optedOutId]);
    $saved = $vendorCheck->fetch(PDO::FETCH_ASSOC);
    $assert($reconciled['vendor_type'] === '1099_individual'
        && $saved['vendor_type'] === '1099_individual' && (int) $saved['requires_1099'] === 0,
        'placement reconciliation preserves an already-reviewed vendor classification');

    $generated = [
        'ap_vendor_id' => $optedOutId,
        'bill' => ['vendor_name' => 'Stale placement payee', 'vendor_type' => 'other',
            'vendor_company_id' => null],
        'lines' => [['placement_id' => 712345, 'description' => 'Synthetic approved time',
            'is_1099_eligible' => 1]],
    ];
    $prepared = apPrepareGeneratedDraftVendor($tenantId, $generated);
    $assert($prepared['bill']['vendor_name'] === 'CI 1099 opted-out individual'
        && $prepared['bill']['vendor_type'] === '1099_individual'
        && (int) $prepared['lines'][0]['is_1099_eligible'] === 0,
        'generated AP labor honors an opted-out saved vendor despite stale placement metadata');

    $generated['ap_vendor_id'] = $partnershipId;
    $generated['bill']['vendor_name'] = 'Old W-9 payee label';
    $generated['bill']['vendor_type'] = 'other';
    $generated['lines'][0]['is_1099_eligible'] = 0;
    $preparedBusiness = apPrepareGeneratedDraftVendor($tenantId, $generated);
    $assert($preparedBusiness['bill']['vendor_name'] === 'CI 1099 partnership'
        && $preparedBusiness['bill']['vendor_type'] === 'w9_business'
        && (int) $preparedBusiness['lines'][0]['is_1099_eligible'] === 1,
        'generated AP labor honors an opted-in business vendor');

    $preparedBusiness['lines'][0]['description'] = 'Synthetic secondary placement fee';
    $secondary = apPrepareGeneratedDraftVendor($tenantId, $preparedBusiness);
    $assert((int) $secondary['lines'][0]['is_1099_eligible'] === 1,
        'generated secondary payee lines use the same reviewed vendor classification');

    $insertGenerated = $pdo->prepare(
        'INSERT INTO ap_bills
            (tenant_id, bill_number, internal_ref, vendor_name, vendor_type, received_at,
             bill_date, due_date, subtotal, total, amount_due, status, source)
         VALUES (:t, :ref, :ref2, :name, :type, "2026-10-11", "2026-10-11",
                 "2026-11-10", 1.00, 1.00, 1.00, "pending_approval", "time_bundle")'
    );
    $insertGeneratedLine = $pdo->prepare(
        'INSERT INTO ap_bill_lines
            (bill_id, line_no, source_type, item_type, description, quantity,
             unit, unit_price, subtotal, total, is_1099_eligible)
         VALUES (:bill, 1, "time", "labor", :description, 1, "hour", 1.00, 1.00, 1.00, :eligible)'
    );
    foreach ([$prepared, $secondary] as $generatedBill) {
        $ref = apNextInternalRef($tenantId);
        $insertGenerated->execute([
            't' => $tenantId, 'ref' => $ref, 'ref2' => $ref,
            'name' => $generatedBill['bill']['vendor_name'],
            'type' => $generatedBill['bill']['vendor_type'],
        ]);
        $generatedBillId = (int) $pdo->lastInsertId();
        $insertGeneratedLine->execute([
            'bill' => $generatedBillId,
            'description' => $generatedBill['lines'][0]['description'],
            'eligible' => $generatedBill['lines'][0]['is_1099_eligible'],
        ]);
        $fetch->execute(['t' => $tenantId, 'id' => $generatedBillId]);
        $savedGenerated = $fetch->fetch(PDO::FETCH_ASSOC);
        $assert($savedGenerated['vendor_name'] === $generatedBill['bill']['vendor_name']
            && (int) $savedGenerated['is_1099_eligible'] === (int) $generatedBill['lines'][0]['is_1099_eligible'],
            'synthetic generated bill and line persist the reviewed vendor decision');
    }

    $nameOnly = $generated;
    unset($nameOnly['ap_vendor_id']);
    $nameOnly['bill']['vendor_name'] = 'CI 1099 partnership';
    $assert(apPrepareGeneratedDraftVendor($tenantId, $nameOnly)['ap_vendor_id'] === $partnershipId,
        'legacy generated drafts can resolve an exact tenant-unique vendor name');
    try {
        apPrepareGeneratedDraftVendor($tenantId + 1, $generated);
        throw new RuntimeException('A vendor from another tenant was accepted');
    } catch (RuntimeException $e) {
        $assert(str_contains($e->getMessage(), 'unavailable'),
            'generated AP vendor IDs cannot cross the tenant boundary');
    }

    $pdo->prepare('UPDATE tenants SET ap_1099_threshold = 1 WHERE id = :t')
        ->execute(['t' => $tenantId]);
    $payment = $pdo->prepare(
        'INSERT INTO ap_payments (tenant_id, vendor_name, pay_date, amount, status, cleared_at)
         VALUES (:t, :name, "2026-10-11", 1.00, "cleared", "2026-10-11 12:00:00")'
    );
    $payment->execute(['t' => $tenantId, 'name' => 'CI 1099 partnership']);
    $paymentId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO ap_payment_allocations (payment_id, bill_id, amount_applied)
                   VALUES (:payment, :bill, 1.00)')
        ->execute(['payment' => $paymentId, 'bill' => $bill['id']]);
    apBuild1099Ledger($tenantId, 2026);
    $ledger = $pdo->prepare(
        'SELECT total_paid, requires_1099_nec FROM ap_1099_ledger
          WHERE tenant_id = :t AND tax_year = 2026 AND vendor_name = :name'
    );
    $ledger->execute(['t' => $tenantId, 'name' => 'CI 1099 partnership']);
    $reported = $ledger->fetch(PDO::FETCH_ASSOC);
    $assert($reported && (float) $reported['total_paid'] === 1.0
        && (int) $reported['requires_1099_nec'] === 1,
        'cleared payment to an explicitly tracked W-9 vendor appears in the 1099 ledger');

    try {
        apCreateManualBill($tenantId, $base + [
            'vendor_id' => $partnershipId, 'vendor_name' => 'CI 1099 opted-out individual',
            'vendor_type' => 'w9_business', 'bill_number' => 'CI-1099-WRONG-NAME',
        ]);
        throw new RuntimeException('A mismatched vendor ID was accepted');
    } catch (InvalidArgumentException $e) {
        $assert(str_contains($e->getMessage(), 'Vendor details changed'),
            'a vendor ID cannot be paired with a different vendor name');
    }
    try {
        apCreateManualBill($tenantId, $base + [
            'vendor_id' => 999999999, 'vendor_name' => 'CI 1099 partnership',
            'vendor_type' => 'w9_business', 'bill_number' => 'CI-1099-UNKNOWN-ID',
        ]);
        throw new RuntimeException('An unavailable vendor ID was accepted');
    } catch (InvalidArgumentException $e) {
        $assert(str_contains($e->getMessage(), 'unavailable'),
            'an unavailable vendor ID is rejected within the tenant');
    }
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}

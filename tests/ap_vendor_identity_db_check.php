<?php
/** Exercise Vendor-ID rename against the disposable, fully migrated CI database. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'coreaccounting'
    || getenv('COREFLUX_STANDALONE_DATABASE') !== 'coreaccounting_ci'
    || !in_array('--confirm-disposable-fixture', $argv, true)) {
    fwrite(STDERR, "Disposable CoreAccounting CI database only.\n");
    exit(2);
}
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../modules/ap/lib/vendor_identity.php';

$pdo = getDB();
if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'coreaccounting_ci') {
    throw new RuntimeException('Connected database is not the disposable CI database.');
}
$tenantId = (int) $pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();
if ($tenantId <= 0) throw new RuntimeException('The CI tenant was not provisioned.');
$otherTenantId = 999;
$oldName = 'CI vendor identity before';
$newName = 'CI vendor identity after';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS {$message}\n";
};
$fetchName = static function (string $table, int $id) use ($pdo): string {
    $stmt = $pdo->prepare("SELECT vendor_name FROM {$table} WHERE id = :id");
    $stmt->execute(['id' => $id]);
    return (string) $stmt->fetchColumn();
};

$pdo->beginTransaction();
try {
    $vendorInsert = $pdo->prepare(
        'INSERT INTO ap_vendors_index (tenant_id, vendor_name, vendor_type, default_terms)
         VALUES (:t, :name, :type, :terms)'
    );
    $vendorInsert->execute(['t' => $tenantId, 'name' => $oldName, 'type' => 'other', 'terms' => 'NET30']);
    $vendorId = (int) $pdo->lastInsertId();
    $vendorInsert->execute(['t' => $otherTenantId, 'name' => $oldName, 'type' => 'other', 'terms' => 'NET30']);
    $otherVendorId = (int) $pdo->lastInsertId();

    $billInsert = $pdo->prepare(
        "INSERT INTO ap_bills
            (tenant_id, bill_number, internal_ref, vendor_name, received_at, bill_date, due_date, total, amount_due)
         VALUES (:t, :number, :ref, :name, '2026-10-11', '2026-10-11', '2026-11-10', 1.00, 1.00)"
    );
    $billInsert->execute(['t' => $tenantId, 'number' => 'CI-VENDOR-RENAME', 'ref' => 'CI-VENDOR-RENAME', 'name' => $oldName]);
    $billId = (int) $pdo->lastInsertId();
    $billInsert->execute(['t' => $otherTenantId, 'number' => 'CI-VENDOR-RENAME-OTHER', 'ref' => 'CI-VENDOR-RENAME-OTHER', 'name' => $oldName]);
    $otherBillId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO ap_payments (tenant_id, vendor_name, pay_date, amount)
         VALUES (:t, :name, '2026-10-11', 1.00)"
    )->execute(['t' => $tenantId, 'name' => $oldName]);
    $paymentId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO ap_recurring_bills
            (tenant_id, vendor_name, description, amount, next_bill_date)
         VALUES (:t, :name, 'CI recurring bill', 1.00, '2026-11-11')"
    )->execute(['t' => $tenantId, 'name' => $oldName]);
    $recurringId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO ap_purchase_orders
            (tenant_id, po_number, vendor_name, issue_date, total)
         VALUES (:t, 'CI-VENDOR-RENAME-PO', :name, '2026-10-11', 1.00)"
    )->execute(['t' => $tenantId, 'name' => $oldName]);
    $poId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO ap_1099_ledger (tenant_id, tax_year, vendor_name, total_paid)
         VALUES (:t, 2026, :name, 1.00)'
    )->execute(['t' => $tenantId, 'name' => $oldName]);
    $taxId = (int) $pdo->lastInsertId();

    $counts = apUpdateVendorIdentity($pdo, $tenantId, $vendorId, $newName, ['default_terms' => 'NET45']);
    foreach (['ap_bills' => $billId, 'ap_payments' => $paymentId,
              'ap_recurring_bills' => $recurringId, 'ap_purchase_orders' => $poId,
              'ap_1099_ledger' => $taxId] as $table => $id) {
        $assert($fetchName($table, $id) === $newName && ($counts[$table] ?? 0) === 1,
            "{$table} follows the Vendor-ID rename");
    }
    foreach (['ap_recurring_bills' => $recurringId, 'ap_purchase_orders' => $poId,
              'ap_1099_ledger' => $taxId] as $table => $id) {
        $stmt = $pdo->prepare("SELECT vendor_id FROM {$table} WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $assert((int) $stmt->fetchColumn() === $vendorId,
            "{$table} gains the stable vendor ID");
    }
    $assert($fetchName('ap_vendors_index', $vendorId) === $newName,
        'the same stable vendor ID has the new name');
    $assert($fetchName('ap_vendors_index', $otherVendorId) === $oldName
        && $fetchName('ap_bills', $otherBillId) === $oldName,
        'another tenant with the same old name is unchanged');

    $terms = $pdo->prepare('SELECT default_terms FROM ap_vendors_index WHERE id = :id');
    $terms->execute(['id' => $vendorId]);
    $assert($terms->fetchColumn() === 'NET45', 'the field update is in the same transaction');
    $audit = $pdo->prepare(
        "SELECT meta_json FROM audit_log
          WHERE tenant_id = :t AND event = 'ap.vendor.renamed' AND target_id = :id
          ORDER BY id DESC LIMIT 1"
    );
    $audit->execute(['t' => $tenantId, 'id' => $vendorId]);
    $meta = json_decode((string) $audit->fetchColumn(), true);
    $assert(($meta['old_name'] ?? '') === $oldName
        && ($meta['new_name'] ?? '') === $newName
        && ($meta['affected_records']['ap_bills'] ?? 0) === 1,
        'the old/new names and affected records are audited');

    $sameName = apUpdateVendorIdentity($pdo, $tenantId, $vendorId, $newName,
        ['default_terms' => 'NET60']);
    $terms->execute(['id' => $vendorId]);
    $assert($sameName === [] && $terms->fetchColumn() === 'NET60'
        && $fetchName('ap_bills', $billId) === $newName,
        'a same-name field update does not repeat the cascade');

    $pdo->prepare(
        "INSERT INTO ap_purchase_orders
            (tenant_id, po_number, vendor_name, vendor_id, issue_date, total)
         VALUES (:t, 'CI-VENDOR-RENAME-CONFLICT', :name, :wrong_id, '2026-10-11', 1.00)"
    )->execute(['t' => $tenantId, 'name' => $newName, 'wrong_id' => $otherVendorId]);
    $conflictingPoId = (int) $pdo->lastInsertId();
    try {
        apUpdateVendorIdentity($pdo, $tenantId, $vendorId, 'CI vendor identity blocked by PO', []);
        throw new RuntimeException('Conflicting purchase-order vendor ID was accepted.');
    } catch (RuntimeException $e) {
        $assert(str_contains($e->getMessage(), 'conflicting vendor ID'),
            'a conflicting purchase-order vendor ID blocks the rename');
    }
    $pdo->prepare('DELETE FROM ap_purchase_orders WHERE id = :id')->execute(['id' => $conflictingPoId]);

    $pdo->prepare('UPDATE ap_1099_ledger SET submitted_to_irs_at = NOW() WHERE id = :id')
        ->execute(['id' => $taxId]);
    try {
        apUpdateVendorIdentity($pdo, $tenantId, $vendorId, 'CI vendor identity blocked', []);
        throw new RuntimeException('Filed 1099 rename was accepted.');
    } catch (RuntimeException $e) {
        $assert(str_contains($e->getMessage(), '1099 form'), 'a filed 1099 blocks the rename');
    }
    $assert($fetchName('ap_vendors_index', $vendorId) === $newName
        && $fetchName('ap_bills', $billId) === $newName,
        'the refused rename leaves vendor and bill unchanged');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}

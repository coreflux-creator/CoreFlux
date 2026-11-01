<?php
/** Rollback-only staging check for CoreOne bill intake on canonical AP. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging') {
    fwrite(STDERR, "This check is restricted to the staging CLI.\n");
    exit(2);
}
require_once __DIR__ . '/../core/accounting/coreone_bills_v1.php';

$tenantId = 0;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--tenant=')) $tenantId = (int) substr($arg, 9);
}
if ($tenantId <= 0) {
    fwrite(STDERR, "Use --tenant=ID with a CoreFlux CI Simulation tenant.\n");
    exit(2);
}
setRequestTenantId($tenantId);
setRequestModuleScope('ap');
$pdo = getDB();
$tenant = $pdo->prepare('SELECT name FROM tenants WHERE id = :id');
$tenant->execute(['id' => $tenantId]);
if (!in_array($tenant->fetchColumn(), ['CoreFlux CI Simulation', "CoreFlux CI Simulation {$tenantId}"], true)) {
    fwrite(STDERR, "This check can only run against a disposable CI Simulation tenant.\n");
    exit(2);
}
$entityStmt = $pdo->prepare(
    'SELECT id, base_currency FROM accounting_entities
      WHERE tenant_id = :t AND active = 1 ORDER BY id LIMIT 1'
);
$entityStmt->execute(['t' => $tenantId]);
$entity = $entityStmt->fetch(PDO::FETCH_ASSOC);
if (!$entity) {
    fwrite(STDERR, "Seed an active synthetic entity first.\n");
    exit(2);
}
$checks = [];
$rejects = static function (callable $action, string $class): bool {
    try { $action(); return false; }
    catch (Throwable $e) { return $e instanceof $class; }
};
$sourceId = 'stage-bill:' . bin2hex(random_bytes(8));
$vendorName = 'Rollback-only CoreOne Vendor ' . bin2hex(random_bytes(4));
$body = [
    'schema_version' => 1, 'source_record_id' => $sourceId,
    'vendor_name' => $vendorName, 'vendor_type' => 'w9_business',
    'bill_number' => 'TEST-' . bin2hex(random_bytes(4)),
    'received_at' => '2026-09-10', 'bill_date' => '2026-09-09',
    'due_date' => '2026-10-09', 'currency' => (string) $entity['base_currency'],
    'tax_rate_pct' => '0',
    'lines' => [['item_type' => 'other', 'description' => 'Rollback-only service',
        'quantity' => '2', 'unit' => 'each', 'unit_price' => '12.50',
        'is_1099_eligible' => false]],
];

try {
    $pdo->beginTransaction();
    $legacy = coreoneV1IssueCredential($tenantId, (int) $entity['id'], 'Old-scope bill fixture', 1, null);
    $legacyCredential = coreoneV1Authenticate('Bearer ' . $legacy['token']);
    $checks['legacy_credential_cannot_prepare_bills'] = $legacyCredential !== null
        && !coreoneV1HasScope($legacyCredential, 'bills:prepare');
    $issued = coreoneV1IssueCredential($tenantId, (int) $entity['id'],
        'Bill fixture', 1, null, ['bills:prepare']);
    $credential = coreoneV1Authenticate('Bearer ' . $issued['token']);
    if (!$credential) throw new RuntimeException('Disposable bill credential did not authenticate.');
    $checks['bill_scope_does_not_grant_journals_or_invoice_drafting'] =
        coreoneV1HasScope($credential, 'bills:prepare')
        && !coreoneV1HasScope($credential, 'journals:write')
        && !coreoneV1HasScope($credential, 'invoices:draft');

    $first = coreoneV1PrepareBill($credential, $body);
    $bill = $first['bill'];
    $checks['first_submission_creates_real_unposted_ap_bill'] = !$first['idempotent_replay']
        && $bill['status'] === 'pending_approval'
        && (int) $bill['entity_id'] === (int) $entity['id']
        && (float) $bill['total'] === 25.0
        && count($bill['lines']) === 1
        && $bill['journal_entry_id'] === null;
    $mapping = $pdo->prepare(
        'SELECT target_id FROM coreone_document_requests
          WHERE tenant_id = :t AND entity_id = :e AND source_type = "ap.bill"
            AND source_record_id = :source_id'
    );
    $mapping->execute(['t' => $tenantId, 'e' => (int) $entity['id'], 'source_id' => $sourceId]);
    $checks['bill_has_one_atomic_source_mapping'] =
        (int) $mapping->fetchColumn() === (int) $bill['id'];
    $replayed = coreoneV1PrepareBill($credential, $body);
    $checks['exact_retry_reuses_same_bill'] = $replayed['idempotent_replay']
        && (int) $replayed['bill']['id'] === (int) $bill['id'];
    $changed = $body;
    $changed['lines'][0]['unit_price'] = '13.00';
    $checks['changed_intent_conflicts_without_editing_bill'] = $rejects(
        static fn() => coreoneV1PrepareBill($credential, $changed),
        CoreOneDocumentConflictException::class
    ) && (float) coreoneV1GetBill($credential, $sourceId)['total'] === 25.0;

    $duplicate = $body;
    $duplicate['source_record_id'] = 'stage-bill:duplicate:' . bin2hex(random_bytes(8));
    $checks['duplicate_vendor_bill_number_is_rejected'] = $rejects(
        static fn() => coreoneV1PrepareBill($credential, $duplicate), DomainException::class
    );
    $checks['duplicate_rejection_leaves_no_mapping_or_partial_bill'] =
        coreoneV1GetBill($credential, $duplicate['source_record_id']) === null
        && $pdo->inTransaction();

    $pdo->prepare(
        'INSERT INTO accounting_entities (tenant_id, code, legal_name, base_currency, active)
         VALUES (:t, :code, "Rollback-only AP entity", :currency, 1)'
    )->execute(['t' => $tenantId, 'code' => 'SIM-BILL-' . bin2hex(random_bytes(4)),
        'currency' => (string) $entity['base_currency']]);
    $secondId = (int) $pdo->lastInsertId();
    $second = coreoneV1IssueCredential($tenantId, $secondId,
        'Second bill fixture', 1, null, ['bills:prepare']);
    $secondCredential = coreoneV1Authenticate('Bearer ' . $second['token']);
    if (!$secondCredential) throw new RuntimeException('Second entity credential did not authenticate.');
    $checks['second_entity_cannot_see_first_bill'] =
        coreoneV1GetBill($secondCredential, $sourceId) === null;
    $checks['same_source_id_cannot_cross_entities'] = $rejects(
        static fn() => coreoneV1PrepareBill($secondCredential, $body),
        CoreOneDocumentConflictException::class
    );
    $secondBody = $body;
    $secondBody['source_record_id'] = 'stage-bill:second:' . bin2hex(random_bytes(8));
    $secondBill = coreoneV1PrepareBill($secondCredential, $secondBody);
    $checks['second_entity_can_prepare_its_own_bill_number'] =
        (int) $secondBill['bill']['entity_id'] === $secondId
        && coreoneV1GetBill($credential, $secondBody['source_record_id']) === null;
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
    $checks['fixture_completed_without_exception'] = false;
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
$checks['rollback_left_no_test_bill'] = coreoneV1GetBill(
    ['tenant_id' => $tenantId, 'entity_id' => (int) $entity['id']], $sourceId
) === null;

$failed = count(array_filter($checks, static fn(bool $ok): bool => !$ok));
foreach ($checks as $name => $passed) echo ($passed ? 'OK  ' : 'FAIL  ') . $name . PHP_EOL;
echo $failed ? "Failed: {$failed}\n" : 'Passed: ' . count($checks) . "\n";
exit($failed ? 1 : 0);

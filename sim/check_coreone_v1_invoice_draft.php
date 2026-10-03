<?php
/** Rollback-only staging check for the CoreOne-to-Billing draft contract. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging') {
    fwrite(STDERR, "This check is restricted to the staging CLI.\n");
    exit(2);
}
require_once __DIR__ . '/../core/accounting/coreone_invoices_v1.php';

$tenantId = 0;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--tenant=')) $tenantId = (int) substr($arg, 9);
}
if ($tenantId <= 0) {
    fwrite(STDERR, "Use --tenant=ID with a CoreFlux CI Simulation tenant.\n");
    exit(2);
}
setRequestTenantId($tenantId);
setRequestModuleScope('billing');
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
$sourceId = 'stage-invoice:' . bin2hex(random_bytes(8));
$body = [
    'schema_version' => 1,
    'source_record_id' => $sourceId,
    'client_name' => 'Rollback-only CoreOne Client ' . bin2hex(random_bytes(4)),
    'issue_date' => date('Y-m-d'),
    'due_date' => date('Y-m-d', strtotime('+30 days')),
    'currency' => (string) $entity['base_currency'],
    'tax_rate_pct' => '0',
    'lines' => [['description' => 'Rollback-only service', 'quantity' => '2',
        'unit' => 'each', 'unit_price' => '12.50', 'taxable' => false]],
];

try {
    $pdo->beginTransaction();
    $old = coreoneV1IssueCredential($tenantId, (int) $entity['id'], 'Old-scope fixture', 1, null);
    $oldCredential = coreoneV1Authenticate('Bearer ' . $old['token']);
    $checks['legacy_credential_retains_journals_but_not_invoices'] = $oldCredential !== null
        && coreoneV1HasScope($oldCredential, 'journals:write')
        && !coreoneV1HasScope($oldCredential, 'invoices:draft');
    $issued = coreoneV1IssueCredential($tenantId, (int) $entity['id'],
        'Invoice fixture', 1, null, ['invoices:draft']);
    $credential = coreoneV1Authenticate('Bearer ' . $issued['token']);
    $checks['invoice_only_credential_has_no_journal_or_report_scope'] = $credential !== null
        && coreoneV1HasScope($credential, 'invoices:draft')
        && !coreoneV1HasScope($credential, 'journals:write')
        && !coreoneV1HasScope($credential, 'reports:read');
    if (!$credential) throw new RuntimeException('Disposable invoice credential did not authenticate.');

    $first = coreoneV1CreateInvoiceDraft($credential, $body);
    $invoice = $first['invoice'];
    $checks['first_submission_creates_real_billing_draft'] = !$first['idempotent_replay']
        && $invoice['status'] === 'draft' && (int) $invoice['entity_id'] === (int) $entity['id']
        && (float) $invoice['total'] === 25.0 && count($invoice['lines']) === 1
        && $invoice['journal_entry_id'] === null;
    $mapping = $pdo->prepare(
        'SELECT target_id FROM coreone_document_requests
          WHERE tenant_id = :t AND entity_id = :e AND source_type = "billing.invoice"
            AND source_record_id = :source_id'
    );
    $mapping->execute(['t' => $tenantId, 'e' => (int) $entity['id'], 'source_id' => $sourceId]);
    $checks['draft_has_one_atomic_source_mapping'] =
        (int) $mapping->fetchColumn() === (int) $invoice['id'];
    $replayed = coreoneV1CreateInvoiceDraft($credential, $body);
    $checks['exact_retry_reuses_same_invoice'] = $replayed['idempotent_replay']
        && (int) $replayed['invoice']['id'] === (int) $invoice['id'];
    $changed = $body;
    $changed['lines'][0]['unit_price'] = '13.00';
    $checks['changed_intent_conflicts_without_editing_invoice'] = $rejects(
        static fn() => coreoneV1CreateInvoiceDraft($credential, $changed),
        CoreOneInvoiceConflictException::class
    ) && (float) coreoneV1GetInvoiceDraft($credential, $sourceId)['total'] === 25.0;

    $pdo->prepare(
        'INSERT INTO accounting_entities (tenant_id, code, legal_name, base_currency, active)
         VALUES (:t, :code, "Rollback-only invoice entity", :currency, 1)'
    )->execute(['t' => $tenantId, 'code' => 'SIM-INV-' . bin2hex(random_bytes(4)),
        'currency' => (string) $entity['base_currency']]);
    $secondId = (int) $pdo->lastInsertId();
    $second = coreoneV1IssueCredential($tenantId, $secondId,
        'Second invoice fixture', 1, null, ['invoices:draft']);
    $secondCredential = coreoneV1Authenticate('Bearer ' . $second['token']);
    if (!$secondCredential) throw new RuntimeException('Second entity credential did not authenticate.');
    $checks['second_entity_cannot_see_first_invoice'] =
        coreoneV1GetInvoiceDraft($secondCredential, $sourceId) === null;
    $checks['same_source_id_cannot_cross_entities'] = $rejects(
        static fn() => coreoneV1CreateInvoiceDraft($secondCredential, $body),
        CoreOneInvoiceConflictException::class
    );
    $secondBody = $body;
    $secondBody['source_record_id'] = 'stage-invoice:second:' . bin2hex(random_bytes(8));
    $secondDraft = coreoneV1CreateInvoiceDraft($secondCredential, $secondBody);
    $checks['second_entity_creates_only_its_own_draft'] =
        (int) $secondDraft['invoice']['entity_id'] === $secondId
        && coreoneV1GetInvoiceDraft($credential, $secondBody['source_record_id']) === null;
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
    $checks['fixture_completed_without_exception'] = false;
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
$checks['rollback_left_no_test_invoice'] = coreoneV1GetInvoiceDraft(
    ['tenant_id' => $tenantId, 'entity_id' => (int) $entity['id']], $sourceId
) === null;

$failed = count(array_filter($checks, static fn(bool $ok): bool => !$ok));
foreach ($checks as $name => $passed) echo ($passed ? 'OK  ' : 'FAIL  ') . $name . PHP_EOL;
echo $failed ? "Failed: {$failed}\n" : 'Passed: ' . count($checks) . "\n";
exit($failed ? 1 : 0);

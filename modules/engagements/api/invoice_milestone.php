<?php
/**
 * Engagements API — invoice a milestone.
 *
 *   POST /modules/engagements/api/invoice_milestone.php?milestone_id=N
 *   Body (optional): {
 *     issue_date?: 'YYYY-MM-DD',     // defaults to today
 *     due_date?:   'YYYY-MM-DD',     // defaults to tenant terms (NET30 etc)
 *     po_number?:  string,
 *     description_override?: string  // line description override
 *   }
 *
 * Flow:
 *   1. Validate milestone is `pending` or `ready_to_invoice` AND belongs
 *      to the caller's tenant.
 *   2. Build a single-line draft invoice via the canonical billing
 *      engine (same shape as POST /api/billing/invoices). Line description
 *      defaults to "{project_name} — {milestone_name}".
 *   3. Call `engagementsMilestoneAttachInvoice()` to flip the milestone
 *      to `invoiced` + link the new billing_invoices.id.
 *
 * Idempotency: when the milestone is already `invoiced` we return the
 * linked invoice instead of creating a duplicate (so a double-click on
 * the UI button doesn't double-bill the customer).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/rbac/legacy_map.php';
require_once __DIR__ . '/../lib/engagements.php';
require_once __DIR__ . '/../../billing/lib/billing.php';
require_once __DIR__ . '/../../ap/lib/ap.php';
require_once __DIR__ . '/../../people/lib/companies.php';

$ctx = api_require_auth();
$tid = (int) $ctx['tenant_id'];
$uid = (int) ($ctx['user']['id'] ?? $ctx['user_id'] ?? 0);

if (api_method() !== 'POST') api_error('POST only', 405);
rbac_legacy_require_any($ctx['user'], ['master_admin', 'tenant_admin', 'admin', 'billing.manage', '*']);

$msId = (int) ($_GET['milestone_id'] ?? 0);
if ($msId <= 0) api_error('milestone_id required', 400);

// 1. Validate milestone.
$pdo = getDB();
$msStmt = $pdo->prepare(
    'SELECT m.*, e.client_name, e.project_name, e.currency, e.entity_id, e.status AS engagement_status
       FROM engagement_milestones m
       JOIN engagements e ON e.id = m.engagement_id
      WHERE m.tenant_id = :t AND m.id = :id LIMIT 1'
);
$msStmt->execute(['t' => $tid, 'id' => $msId]);
$ms = $msStmt->fetch(\PDO::FETCH_ASSOC);
if (!$ms) api_error('Milestone not found', 404);

if ($ms['engagement_status'] === 'archived') {
    api_error('Cannot invoice a milestone on an archived engagement', 409);
}

// Idempotency: if already invoiced, return the existing invoice row.
if (in_array($ms['status'], ['invoiced', 'paid'], true) && $ms['invoice_id']) {
    $inv = $pdo->prepare('SELECT * FROM billing_invoices WHERE id = :id AND tenant_id = :t LIMIT 1');
    $inv->execute(['id' => (int) $ms['invoice_id'], 't' => $tid]);
    $invRow = $inv->fetch(\PDO::FETCH_ASSOC);
    api_ok([
        'invoice'   => $invRow,
        'milestone' => $ms,
        'reused'    => true,
        'note'      => 'Milestone already invoiced — returning existing invoice.',
    ]);
}

if (!in_array($ms['status'], ['pending', 'ready_to_invoice'], true)) {
    api_error("Cannot invoice a milestone in status '{$ms['status']}'", 409);
}

$amount = round((float) $ms['amount'], 2);
if ($amount <= 0) api_error('Milestone amount must be > 0 before invoicing', 422);

$body = json_decode((string) file_get_contents('php://input'), true) ?: [];

// 2. Build the invoice using the same shape as the manual /api/billing/invoices POST.
$taxStmt = $pdo->prepare('SELECT billing_tax_rate_pct, billing_invoice_terms FROM tenants WHERE id = :id');
$taxStmt->execute(['id' => $tid]);
$cfg = $taxStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
$taxPct = (float) ($cfg['billing_tax_rate_pct'] ?? 0);
$netDays = preg_match('/^NET(\d+)$/i', (string) ($cfg['billing_invoice_terms'] ?? 'NET30'), $m) ? (int) $m[1] : 30;
try {
    $clientTerms = $pdo->prepare(
        "SELECT payment_terms_days FROM staffing_clients
          WHERE tenant_id = :t AND name = :n AND payment_terms_days IS NOT NULL LIMIT 1"
    );
    $clientTerms->execute(['t' => $tid, 'n' => $ms['client_name']]);
    $perClient = $clientTerms->fetchColumn();
    if ($perClient !== false && (int) $perClient > 0) $netDays = (int) $perClient;
} catch (\Throwable $_) { /* fall through */ }

$lineDesc = (string) ($body['description_override']
    ?? trim($ms['project_name'] . ' — ' . $ms['name']));

$lines = [[
    'item_type'   => 'fixed_fee',
    'description' => $lineDesc,
    'quantity'    => 1,
    'unit'        => 'each',
    'unit_price'  => $amount,
]];

$computed = billingComputeTax($lines, $taxPct);

$pdo->beginTransaction();
try {
    // Auto-create the unified companies.id for the billed client (mirrors invoices.php:286).
    $clientCompanyId = companiesUpsertByName($tid, $ms['client_name'], [
        'created_by_user_id' => $uid ?: null,
    ], ['client']);
    companiesBumpUsage($clientCompanyId);

    $invId = scopedInsert('billing_invoices', [
        'tenant_id'         => $tid,
        'invoice_number'    => billingNextInvoiceNumber($tid),
        'client_name'       => $ms['client_name'],
        'client_company_id' => $clientCompanyId,
        'entity_id'         => !empty($ms['entity_id']) ? (int) $ms['entity_id'] : null,
        'bill_to_json'      => null,
        'currency'          => (string) ($ms['currency'] ?? 'USD'),
        'issue_date'        => (string) ($body['issue_date'] ?? date('Y-m-d')),
        'due_date'          => (string) ($body['due_date'] ?? date('Y-m-d', strtotime("+{$netDays} days"))),
        'po_number'         => $body['po_number'] ?? null,
        'notes_internal'    => 'Auto-generated from engagement milestone #' . $msId,
        'notes_external'    => null,
        'subtotal'          => $computed['subtotal'],
        'tax_total'         => $computed['tax_total'],
        'total'             => $computed['total'],
        'amount_due'        => $computed['total'],
        'aggregation'       => 'per_client',
        'status'            => 'draft',
        'created_by_user_id'=> $uid ?: null,
    ]);
    $stmt = $pdo->prepare(
        'INSERT INTO billing_invoice_lines
          (invoice_id, line_no, source_type, item_type, description, quantity, unit, unit_price,
           subtotal, tax_rate_pct, tax_amount, total, gl_revenue_account_code)
         VALUES
          (:invoice_id, 1, "engagement_milestone", :item_type, :description, :quantity, :unit, :unit_price,
           :subtotal, :tax_rate_pct, :tax_amount, :total, NULL)'
    );
    $cl = $computed['lines'][0];
    $stmt->execute([
        'invoice_id' => $invId,
        'item_type'  => apNormalizeItemType($cl['item_type'] ?? 'fixed_fee', 'engagement_milestone'),
        'description'=> $cl['description'] ?? $lineDesc,
        'quantity'   => $cl['quantity']    ?? 1,
        'unit'       => $cl['unit']        ?? 'each',
        'unit_price' => $cl['unit_price']  ?? $amount,
        'subtotal'   => $cl['subtotal'],
        'tax_rate_pct'=> $cl['tax_rate_pct'],
        'tax_amount' => $cl['tax_amount'],
        'total'      => $cl['total'],
    ]);

    billingAudit('billing.invoice.created', [
        'invoice_id'    => $invId,
        'source'        => 'engagement_milestone',
        'engagement_id' => (int) $ms['engagement_id'],
        'milestone_id'  => $msId,
    ], $invId);

    // 3. Attach back to the milestone.
    engagementsMilestoneAttachInvoice($tid, $msId, $invId, $uid);

    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    api_error($e->getMessage(), 500);
}

// Re-read for fresh state.
$inv = $pdo->prepare('SELECT * FROM billing_invoices WHERE id = :id AND tenant_id = :t LIMIT 1');
$inv->execute(['id' => $invId, 't' => $tid]);
$invRow = $inv->fetch(\PDO::FETCH_ASSOC);

$msStmt->execute(['t' => $tid, 'id' => $msId]);
$msAfter = $msStmt->fetch(\PDO::FETCH_ASSOC);

api_ok([
    'invoice'    => $invRow,
    'milestone'  => $msAfter,
    'engagement' => engagementsGet($tid, (int) $ms['engagement_id']),
]);

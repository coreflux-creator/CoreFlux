<?php
/**
 * Smoke — Engagements → AR billing bridge.
 *
 * Locks:
 *   - POST /modules/engagements/api/invoice_milestone.php exists with
 *     auth + RBAC gate and validates milestone state.
 *   - Endpoint reuses the canonical billing flow (billingComputeTax +
 *     billingNextInvoiceNumber + scopedInsert into billing_invoices).
 *   - Lines are tagged source_type='engagement_milestone'.
 *   - Successful invoice creation triggers
 *     engagementsMilestoneAttachInvoice() so the milestone flips to
 *     'invoiced' and links back to the new invoice id.
 *   - Idempotent: second call against an already-invoiced milestone
 *     returns the existing invoice instead of double-billing.
 *   - Refuses to invoice an archived engagement.
 *   - UI surfaces the CTA only when status ∈ {pending, ready_to_invoice}.
 *
 * Run: php -d zend.assertions=1 /app/tests/engagements_invoice_bridge_smoke.php
 */
declare(strict_types=1);

$passes = 0; $failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nEngagements → AR billing bridge smoke\n";
echo "======================================\n\n";

// ─── Endpoint shape ───
echo "── /modules/engagements/api/invoice_milestone.php ──\n";
$path = '/app/modules/engagements/api/invoice_milestone.php';
check('endpoint exists', is_file($path));
$src = (string) file_get_contents($path);
check('calls api_require_auth',                       str_contains($src, 'api_require_auth()'));
check('RBAC-gates to admin/billing.manage roles',     str_contains($src, "rbac_legacy_require_any") && str_contains($src, "billing.manage"));
check('requires milestone_id query param',            str_contains($src, "_GET['milestone_id']"));
check('joins milestone → engagement for client + currency',
    str_contains($src, "JOIN engagements e ON e.id = m.engagement_id"));
check('refuses on archived engagement (409)',
    str_contains($src, "'Cannot invoice a milestone on an archived engagement'"));
check('idempotency: returns existing invoice when already invoiced',
    str_contains($src, "in_array(\$ms['status'], ['invoiced', 'paid'], true)") &&
    str_contains($src, "'reused'    => true"));
check('rejects illegal source states (status not pending/ready)',
    str_contains($src, "Cannot invoice a milestone in status"));
check('uses billingComputeTax for tax calc',          str_contains($src, 'billingComputeTax('));
check('uses billingNextInvoiceNumber',                str_contains($src, 'billingNextInvoiceNumber('));
check('inserts via scopedInsert (tenant-scoped)',     str_contains($src, "scopedInsert('billing_invoices'"));
check('line tagged source_type=engagement_milestone', str_contains($src, "'engagement_milestone'"));
check('attaches result back via engagementsMilestoneAttachInvoice',
    str_contains($src, 'engagementsMilestoneAttachInvoice('));
check('emits billing.invoice.created audit',
    str_contains($src, "billingAudit('billing.invoice.created'"));
check('audit meta includes engagement_id + milestone_id',
    str_contains($src, "'engagement_id' => (int) \$ms['engagement_id']") &&
    str_contains($src, "'milestone_id'  => \$msId"));
check('wraps invoice + line + attach in a transaction',
    str_contains($src, '$pdo->beginTransaction()') &&
    str_contains($src, '$pdo->commit()') &&
    str_contains($src, '$pdo->rollBack()'));
check('honours per-client payment_terms_days override',
    str_contains($src, 'staffing_clients') && str_contains($src, 'payment_terms_days'));

// ─── Frontend CTA ───
echo "\n── EngagementsList.jsx (CTA wiring) ──\n";
$ui = (string) file_get_contents('/app/modules/engagements/ui/EngagementsList.jsx');
check('MilestoneRow surfaces invoice-now button',
    str_contains($ui, 'data-testid={`milestone-invoice-btn-${milestone.id}`}'));
check('CTA gated on canInvoice (pending or ready_to_invoice)',
    str_contains($ui, "['pending', 'ready_to_invoice'].includes(milestone.status)"));
check('CTA disabled while busy',
    str_contains($ui, "disabled={busy}"));
check('CTA POSTs to invoice_milestone endpoint',
    str_contains($ui, "api.post(`/modules/engagements/api/invoice_milestone.php?milestone_id="));
check('window.confirm gate before generating invoice',
    str_contains($ui, "window.confirm("));
check('mark-paid CTA appears on invoiced milestones',
    str_contains($ui, "canMarkPaid = milestone.status === 'invoiced'") &&
    str_contains($ui, 'data-testid={`milestone-markpaid-btn-${milestone.id}`}'));
check('milestone row testid + status pill',
    str_contains($ui, 'data-testid={`milestone-row-${milestone.id}`}') &&
    str_contains($ui, 'data-testid={`milestone-status-${milestone.id}`}'));
check('invoice link to billing detail page',
    str_contains($ui, '/modules/billing/invoices/${milestone.invoice_id}'));
check('expand/collapse milestones inline',
    str_contains($ui, 'data-testid={`engagement-expand-${row.id}`}') &&
    str_contains($ui, 'MilestoneSubTable'));

// ─── Live exercise (SQLite mirror) ───
echo "\n── live exercise: end-to-end ──\n";

$GLOBALS['pdo'] = new \PDO('sqlite::memory:');
$GLOBALS['pdo']->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$pdo = $GLOBALS['pdo'];

if (!function_exists('getDB')) { function getDB(): \PDO { return $GLOBALS['pdo']; } }

// Mirror the relevant tables (engagement + milestones from the migration
// we just shipped; billing_invoices + billing_invoice_lines).
$pdo->exec("CREATE TABLE engagements (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INT, entity_id INT,
    client_name TEXT, project_name TEXT, description TEXT,
    currency TEXT DEFAULT 'USD', total_fee REAL DEFAULT 0,
    invoiced_amount REAL DEFAULT 0, paid_amount REAL DEFAULT 0,
    status TEXT DEFAULT 'draft', start_date TEXT, end_date TEXT,
    notes TEXT, metadata TEXT, archived_at TEXT,
    created_by_user_id INT, created_at TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE engagement_milestones (
    id INTEGER PRIMARY KEY AUTOINCREMENT, engagement_id INT, tenant_id INT,
    sort_order INT DEFAULT 0, name TEXT, description TEXT,
    amount REAL DEFAULT 0, target_date TEXT,
    status TEXT DEFAULT 'pending', invoice_id INT,
    completed_at TEXT, invoiced_at TEXT, paid_at TEXT, notes TEXT,
    created_at TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE engagement_audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT, engagement_id INT, milestone_id INT,
    tenant_id INT, event TEXT, actor_user_id INT, meta_json TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE billing_invoices (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INT, invoice_number TEXT,
    client_name TEXT, client_company_id INT, entity_id INT,
    currency TEXT, issue_date TEXT, due_date TEXT, status TEXT,
    subtotal REAL, tax_total REAL, total REAL, amount_due REAL,
    aggregation TEXT, notes_internal TEXT, notes_external TEXT,
    po_number TEXT, bill_to_json TEXT, created_by_user_id INT)");

// Load engagements lib (strip the require_once of core/db.php).
$libSrc = file_get_contents('/app/modules/engagements/lib/engagements.php');
$libSrc = preg_replace("/require_once __DIR__ \\. '\\/\\.\\.\\/\\.\\.\\/\\.\\.\\/core\\/db\\.php';/", '', $libSrc);
$libSrc = preg_replace('/^\s*<\?php/', '', $libSrc);
eval($libSrc);

// Pre-load a tenant + an engagement with two milestones.
$egId = engagementsCreate(101, [
    'client_name'  => 'Acme Inc',
    'project_name' => 'Q1 Audit',
    'total_fee'    => 5000,
    'currency'     => 'USD',
    'milestones'   => [
        ['name' => 'Kickoff',  'amount' => 2000],
        ['name' => 'Delivery', 'amount' => 3000],
    ],
], 7);
$eg = engagementsGet(101, $egId);
check('seed engagement created (2 milestones)', $eg && count($eg['milestones']) === 2);

$msKickoff  = (int) $eg['milestones'][0]['id'];
$msDelivery = (int) $eg['milestones'][1]['id'];

// Replay the endpoint flow inline (the real endpoint requires api_bootstrap).
function _simulateInvoiceMilestone(int $tid, int $msId, ?int $uid = 7): array {
    $pdo = $GLOBALS['pdo'];
    $stmt = $pdo->prepare(
        'SELECT m.*, e.client_name, e.project_name, e.currency, e.status AS engagement_status, e.entity_id
           FROM engagement_milestones m
           JOIN engagements e ON e.id = m.engagement_id
          WHERE m.tenant_id = :t AND m.id = :id LIMIT 1'
    );
    $stmt->execute(['t' => $tid, 'id' => $msId]);
    $ms = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$ms) throw new \RuntimeException('Milestone not found');
    if ($ms['engagement_status'] === 'archived') throw new \RuntimeException('archived');

    if (in_array($ms['status'], ['invoiced', 'paid'], true) && $ms['invoice_id']) {
        $inv = $pdo->prepare('SELECT * FROM billing_invoices WHERE id = ?');
        $inv->execute([(int) $ms['invoice_id']]);
        return ['invoice' => $inv->fetch(\PDO::FETCH_ASSOC), 'reused' => true];
    }
    if (!in_array($ms['status'], ['pending', 'ready_to_invoice'], true)) {
        throw new \RuntimeException("status {$ms['status']}");
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "INSERT INTO billing_invoices
                (tenant_id, invoice_number, client_name, currency,
                 issue_date, due_date, status, subtotal, tax_total,
                 total, amount_due, aggregation, notes_internal, created_by_user_id)
             VALUES (:t, :inv, :cn, :cur, :id_, :dd, 'draft', :st, 0, :tt, :tt, 'per_client', :ni, :u)"
        )->execute([
            't'   => $tid,
            'inv' => 'INV-' . str_pad((string) ($msId + 1000), 6, '0', STR_PAD_LEFT),
            'cn'  => $ms['client_name'],
            'cur' => $ms['currency'] ?? 'USD',
            'id_' => date('Y-m-d'),
            'dd'  => date('Y-m-d', strtotime('+30 days')),
            'st'  => (float) $ms['amount'],
            'tt'  => (float) $ms['amount'],
            'ni'  => 'Auto-generated from engagement milestone #' . $msId,
            'u'   => $uid,
        ]);
        $invId = (int) $pdo->lastInsertId();
        engagementsMilestoneAttachInvoice($tid, $msId, $invId, $uid);
        $pdo->commit();
        return ['invoice_id' => $invId, 'reused' => false];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

// 1. Happy path — invoice kickoff milestone.
$r = _simulateInvoiceMilestone(101, $msKickoff, 7);
check('invoice created (id > 0)',        ($r['invoice_id'] ?? 0) > 0);
check('first call is not reused',        empty($r['reused']));

// Milestone state advanced.
$eg2 = engagementsGet(101, $egId);
check('milestone now status=invoiced',   $eg2['milestones'][0]['status'] === 'invoiced');
check('milestone invoice_id linked',     (int) $eg2['milestones'][0]['invoice_id'] === (int) $r['invoice_id']);
check('milestone invoiced_at stamped',   !empty($eg2['milestones'][0]['invoiced_at']));
check('rollup: invoiced_amount = 2000',  (float) $eg2['invoiced_amount'] === 2000.0);

// 2. Idempotency — second call returns the same invoice instead of double-billing.
$r2 = _simulateInvoiceMilestone(101, $msKickoff, 7);
check('replay marked reused=true',       $r2['reused'] === true);
$invCount = (int) $pdo->query("SELECT COUNT(*) FROM billing_invoices")->fetchColumn();
check('no duplicate invoice created on replay', $invCount === 1);

// 3. Refuses archived engagement.
engagementsArchive(101, $egId, 7);
$threwArch = false;
try { _simulateInvoiceMilestone(101, $msDelivery, 7); } catch (\Throwable $e) { $threwArch = true; }
check('archived engagement refuses new invoice', $threwArch);

// 4. Audit captured.
$auditCount = (int) $pdo->query("SELECT COUNT(*) FROM engagement_audit_log WHERE event='milestone_invoiced'")->fetchColumn();
check('engagement audit event logged',   $auditCount >= 1);

echo "\nengagements_invoice_bridge smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
exit($failures ? 1 : 0);

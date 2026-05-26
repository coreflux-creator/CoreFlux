<?php
/**
 * Billing API — invoices.
 *
 *   GET    /api/billing/invoices               → list with filters
 *   GET    /api/billing/invoices?id=N          → detail (header + lines + payments + token)
 *   POST   /api/billing/invoices               → manual create draft from explicit lines
 *   POST   /api/billing/invoices?action=from-time-bundle
 *          body: {period_id, placement_ids[], aggregation: 'per_placement'|'per_client'}
 *   PATCH  /api/billing/invoices?id=N          → edit draft (status='draft' only)
 *   POST   /api/billing/invoices?action=approve&id=N    → two-eye gate
 *   POST   /api/billing/invoices?action=send&id=N       → issue token + email
 *   POST   /api/billing/invoices?action=void&id=N       → body: {reason}
 *
 * SPEC: /app/modules/billing/SPEC.md §5.1, §9.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/mail_bootstrap.php';
require_once __DIR__ . '/../../../core/tenant_mail.php';
require_once __DIR__ . '/../lib/billing.php';
require_once __DIR__ . '/../lib/invoice_pdf.php';
require_once __DIR__ . '/../../ap/lib/ap.php';   // apNormalizeItemType() — shared item_type vocabulary
require_once __DIR__ . '/../../ap/lib/pwp.php';  // apPwpAutoLinkForArInvoice() — pay-when-paid auto-link

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET' && !empty($_GET['id']) && $action !== 'pdf') {
    rbac_legacy_require($user, 'billing.view');
    $id = (int) $_GET['id'];
    $inv = scopedFind('SELECT * FROM billing_invoices WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$inv) api_error('Not found', 404);
    $pdo = getDB();
    $linesStmt = $pdo->prepare('SELECT * FROM billing_invoice_lines WHERE invoice_id = :id ORDER BY line_no');
    $linesStmt->execute(['id' => $id]);
    $lines = $linesStmt->fetchAll(\PDO::FETCH_ASSOC);
    // tenant-leak-allow: defense-in-depth — caller scoped row by tenant_id before this id-only write
    $allocStmt = $pdo->prepare(
        'SELECT bpa.amount_applied, bpa.applied_at, bp.id AS payment_id, bp.received_at, bp.method, bp.reference, bp.amount AS payment_amount
         FROM billing_payment_allocations bpa
         JOIN billing_payments bp ON bp.id = bpa.payment_id
         WHERE bpa.invoice_id = :id ORDER BY bpa.applied_at DESC'
    );
    $allocStmt->execute(['id' => $id]);
    $allocations = $allocStmt->fetchAll(\PDO::FETCH_ASSOC);
    $tokStmt = $pdo->prepare(
        'SELECT id, token, issued_at, expires_at, last_viewed_at, view_count
         FROM billing_invoice_tokens WHERE invoice_id = :id AND tenant_id = :t ORDER BY id DESC LIMIT 1'
    );
    $tokStmt->execute(['id' => $id, 't' => $tid]);
    $token = $tokStmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    if ($token) {
        $base = defined('APP_URL') ? rtrim(APP_URL, '/') : (getenv('APP_URL') ?: '');
        $token['url'] = "{$base}/billing/invoice.php?t={$token['token']}";
        unset($token['token']); // never expose raw token in authed API beyond URL
    }
    api_ok(['invoice' => $inv, 'lines' => $lines, 'allocations' => $allocations, 'token' => $token]);
}

if ($method === 'GET') {
    rbac_legacy_require($user, 'billing.view');
    $where  = ['tenant_id = :tenant_id'];
    $params = [];
    if (!empty($_GET['client_name'])) { $where[] = 'client_name = :cn';   $params['cn'] = $_GET['client_name']; }
    if (!empty($_GET['status']))      { $where[] = 'status = :st';        $params['st'] = $_GET['status']; }
    if (!empty($_GET['from']))        { $where[] = 'issue_date >= :df';   $params['df'] = $_GET['from']; }
    if (!empty($_GET['to']))          { $where[] = 'issue_date <= :dt';   $params['dt'] = $_GET['to']; }
    if (!empty($_GET['due_before']))  { $where[] = 'due_date < :db';      $params['db'] = $_GET['due_before']; }
    // Sprint 6c — respect the header's multi-entity switcher.
    if (!empty($_GET['entity_id']))   { $where[] = 'entity_id = :eid';    $params['eid'] = (int) $_GET['entity_id']; }
    $perPage = max(1, min(200, (int) ($_GET['per_page'] ?? 50)));
    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $offset  = ($page - 1) * $perPage;

    $rows = scopedQuery(
        'SELECT id, invoice_number, client_name, issue_date, due_date, currency,
                subtotal, tax_total, total, amount_paid, amount_due, status,
                po_number, sent_at, created_at
         FROM billing_invoices WHERE ' . implode(' AND ', $where) . '
         ORDER BY id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
        $params
    );
    $cnt  = scopedQuery('SELECT COUNT(*) AS c FROM billing_invoices WHERE ' . implode(' AND ', $where), $params);
    api_ok(['rows' => $rows, 'total' => (int) ($cnt[0]['c'] ?? 0), 'page' => $page, 'per_page' => $perPage]);
}

if ($method === 'POST' && $action === 'from-time-bundle') {
    rbac_legacy_require($user, 'billing.invoice.draft');
    $body = api_json_body();
    api_require_fields($body, ['period_id', 'placement_ids']);
    $periodId  = (int) $body['period_id'];
    $placementIds = array_values(array_filter(array_map('intval', (array) $body['placement_ids'])));
    $aggregation  = (string) ($body['aggregation'] ?? 'per_placement');
    if (empty($placementIds)) api_error('placement_ids required', 422);

    $drafts = billingBuildDraftFromBundle($tid, $periodId, $placementIds, $aggregation);
    if (empty($drafts)) api_error('No invoices could be built (all bundles had zero billable hours)', 422);

    $pdo = getDB();
    $created = [];
    require_once __DIR__ . '/../../people/lib/companies.php';
    $pdo->beginTransaction();
    try {
        foreach ($drafts as $d) {
            $inv  = $d['invoice'];
            $inv['tenant_id'] = $tid;
            $inv['invoice_number'] = billingNextInvoiceNumber($tid);
            $inv['created_by_user_id'] = $user['id'] ?? null;

            $clientCid = companiesUpsertByName($tid, (string) $inv['client_name'], [
                'created_by_user_id' => $user['id'] ?? null,
            ], ['client']);
            companiesBumpUsage($clientCid);
            $inv['client_company_id'] = $clientCid;

            $invId = scopedInsert('billing_invoices', $inv);

            foreach ($d['lines'] as $l) {
                $l['invoice_id'] = $invId;
                $l['item_type']  = apNormalizeItemType($l['item_type'] ?? null, $l['source_type'] ?? 'time');
                $stmt = $pdo->prepare(
                    'INSERT INTO billing_invoice_lines
                      (invoice_id, line_no, source_type, item_type, source_ref_id, placement_id, rate_snapshot_id,
                       description, quantity, unit, unit_price, subtotal, tax_rate_pct, tax_amount, total)
                     VALUES
                      (:invoice_id, :line_no, :source_type, :item_type, :source_ref_id, :placement_id, :rate_snapshot_id,
                       :description, :quantity, :unit, :unit_price, :subtotal, :tax_rate_pct, :tax_amount, :total)'
                );
                $stmt->execute($l);
            }

            // Mark bundles consumed
            foreach ($d['bundle_ids'] as $bid) {
                $pdo->prepare(
                    'UPDATE time_downstream_feed
                     SET status = "consumed", consumed_at = NOW(),
                         consumed_by_module = "billing", consumed_ref_id = :iid
                     WHERE id = :bid AND tenant_id = :tid AND status = "ready"'
                )->execute(['iid' => $invId, 'bid' => (int) $bid, 'tid' => $tid]);
            }
            billingAudit('billing.invoice.created', [
                'invoice_id' => $invId, 'invoice_number' => $inv['invoice_number'],
                'source' => 'time_bundle', 'period_id' => $periodId,
                'bundle_ids' => $d['bundle_ids'], 'aggregation' => $aggregation,
            ], $invId);

            $created[] = ['id' => $invId, 'invoice_number' => $inv['invoice_number'], 'client_name' => $inv['client_name'], 'total' => $inv['total']];
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // After commit: opportunistically link any matching PWP AP bills (same
    // period + placement). Failures here don't roll back the invoice creation.
    foreach ($created as $c) {
        try {
            $link = apPwpAutoLinkForArInvoice($tid, (int) $c['id'], $user['id'] ?? null);
            if (!empty($link['linked'])) {
                $c['pwp_linked_bill_count'] = count($link['linked']);
            }
        } catch (\Throwable $e) {
            error_log('[billing.invoices.from-time-bundle] PWP auto-link failed for invoice ' . $c['id'] . ': ' . $e->getMessage());
        }
    }
    api_ok(['invoices_created' => $created], 201);
}

if ($method === 'POST' && $action === '') {
    rbac_legacy_require($user, 'billing.invoice.draft');
    $body = api_json_body();
    api_require_fields($body, ['client_name', 'lines']);
    if (empty($body['lines']) || !is_array($body['lines'])) api_error('lines must be a non-empty array', 422);

    $pdo = getDB();
    $taxStmt = $pdo->prepare('SELECT billing_tax_rate_pct, billing_invoice_terms FROM tenants WHERE id = :id');
    $taxStmt->execute(['id' => $tid]);
    $cfg = $taxStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    $taxPct = (float) ($cfg['billing_tax_rate_pct'] ?? 0);
    $netDays = preg_match('/^NET(\d+)$/i', (string) ($cfg['billing_invoice_terms'] ?? 'NET30'), $m) ? (int) $m[1] : 30;

    // Per-client override: if a staffing_clients row exists with a non-null
    // payment_terms_days, use that instead of the tenant-wide default.
    try {
        $clientTerms = $pdo->prepare(
            "SELECT payment_terms_days FROM staffing_clients
              WHERE tenant_id = :t AND name = :n AND payment_terms_days IS NOT NULL LIMIT 1"
        );
        $clientTerms->execute(['t' => $tid, 'n' => (string) ($body['client_name'] ?? '')]);
        $perClient = $clientTerms->fetchColumn();
        if ($perClient !== false && $perClient !== null && (int) $perClient > 0) {
            $netDays = (int) $perClient;
        }
    } catch (\Throwable $_) { /* staffing_clients may not exist yet — fall through */ }

    $computed = billingComputeTax($body['lines'], $taxPct);

    $pdo->beginTransaction();
    try {
        // Resolve/auto-create the unified companies.id for the billed client.
        require_once __DIR__ . '/../../people/lib/companies.php';
        $clientCompanyId = !empty($body['client_company_id']) ? (int) $body['client_company_id'] : null;
        if (!$clientCompanyId) {
            $clientCompanyId = companiesUpsertByName($tid, (string) $body['client_name'], [
                'created_by_user_id' => $user['id'] ?? null,
            ], ['client']);
            companiesBumpUsage($clientCompanyId);
        }
        $invId = scopedInsert('billing_invoices', [
            'tenant_id'         => $tid,
            'invoice_number'    => billingNextInvoiceNumber($tid),
            'client_name'       => (string) $body['client_name'],
            'client_company_id' => $clientCompanyId,
            'entity_id'         => !empty($body['entity_id']) ? (int) $body['entity_id'] : null,
            'bill_to_json'      => isset($body['bill_to']) ? json_encode($body['bill_to']) : null,
            'currency'          => (string) ($body['currency'] ?? 'USD'),
            'issue_date'        => (string) ($body['issue_date'] ?? date('Y-m-d')),
            'due_date'          => (string) ($body['due_date'] ?? date('Y-m-d', strtotime("+{$netDays} days"))),
            'po_number'         => $body['po_number'] ?? null,
            'notes_internal'    => $body['notes_internal'] ?? null,
            'notes_external'    => $body['notes_external'] ?? null,
            'subtotal'          => $computed['subtotal'],
            'tax_total'         => $computed['tax_total'],
            'total'             => $computed['total'],
            'amount_due'        => $computed['total'],
            'aggregation'       => 'per_client',
            'status'            => 'draft',
            'created_by_user_id'=> $user['id'] ?? null,
        ]);
        $line_no = 1;
        foreach ($computed['lines'] as $l) {
            $stmt = $pdo->prepare(
                'INSERT INTO billing_invoice_lines
                  (invoice_id, line_no, source_type, item_type, description, quantity, unit, unit_price,
                   subtotal, tax_rate_pct, tax_amount, total, gl_revenue_account_code)
                 VALUES
                  (:invoice_id, :line_no, "manual", :item_type, :description, :quantity, :unit, :unit_price,
                   :subtotal, :tax_rate_pct, :tax_amount, :total, :gl_rev)'
            );
            $stmt->execute([
                'invoice_id' => $invId, 'line_no' => $line_no++,
                'item_type'  => apNormalizeItemType($l['item_type'] ?? null, 'manual'),
                'description' => $l['description'] ?? '',
                'quantity' => $l['quantity'] ?? 0, 'unit' => $l['unit'] ?? 'each',
                'unit_price' => $l['unit_price'] ?? 0, 'subtotal' => $l['subtotal'],
                'tax_rate_pct' => $l['tax_rate_pct'], 'tax_amount' => $l['tax_amount'],
                'total' => $l['total'],
                'gl_rev' => $l['gl_revenue_account_code'] ?? null,
            ]);
        }
        billingAudit('billing.invoice.created', ['invoice_id' => $invId, 'source' => 'manual'], $invId);
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    api_ok(['id' => $invId], 201);
}

if ($method === 'PATCH') {
    rbac_legacy_require($user, 'billing.invoice.draft');
    $id = (int) ($_GET['id'] ?? 0);
    $row = scopedFind('SELECT * FROM billing_invoices WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    if ($row['status'] !== 'draft') api_error('Only draft invoices can be edited', 409);

    $body = api_json_body();
    $editable = ['client_name','client_company_id','bill_to_json','issue_date','due_date','po_number','notes_internal','notes_external'];
    $sets = []; $binds = ['id' => $id];
    foreach ($editable as $f) {
        if (array_key_exists($f, $body)) {
            $sets[] = "{$f} = :{$f}";
            $binds[$f] = is_array($body[$f]) ? json_encode($body[$f]) : $body[$f];
        }
    }
    if (!$sets) api_error('Nothing to update', 422);
    getDB()->prepare('UPDATE billing_invoices SET ' . implode(',', $sets) . ' WHERE id = :id')->execute($binds);
    billingAudit('billing.invoice.updated', ['invoice_id' => $id, 'fields' => array_keys(array_intersect_key($body, array_flip($editable)))], $id);
    api_ok(['ok' => true]);
}

if ($method === 'POST' && $action === 'approve') {
    rbac_legacy_require($user, 'billing.invoice.approve');
    $id  = (int) ($_GET['id'] ?? 0);
    $row = scopedFind('SELECT * FROM billing_invoices WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    if (!billingTransitionAllowed($row['status'], 'approved')) api_error("Cannot approve from status {$row['status']}", 409);
    if ((int) ($row['created_by_user_id'] ?? 0) === (int) ($user['id'] ?? 0)) {
        api_error('Two-eye control: you cannot approve your own draft.', 403);
    }
    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    getDB()->prepare('UPDATE billing_invoices SET status = "approved", approved_by_user_id = :u, approved_at = NOW() WHERE id = :id')
        ->execute(['u' => $user['id'] ?? null, 'id' => $id]);
    billingAudit('billing.invoice.approved', ['invoice_id' => $id, 'invoice_number' => $row['invoice_number']], $id);
    api_ok(['ok' => true]);
}

if ($method === 'POST' && $action === 'send') {
    rbac_legacy_require($user, 'billing.invoice.send');
    $id  = (int) ($_GET['id'] ?? 0);
    $row = scopedFind('SELECT * FROM billing_invoices WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    if (!billingTransitionAllowed($row['status'], 'sent')) api_error("Cannot send from status {$row['status']}", 409);

    $body = api_json_body();
    $to = trim((string) ($body['to'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) api_error('to (email) required', 422);

    $tok = billingIssueViewToken($tid, $id);
    $sender = cf_tenant_mail_sender($tid, 'billing');
    $svc = cf_mail_bootstrap();

    // Generate the invoice PDF and attach it. If the renderer is missing on
    // this host we still send the email (with the view-online link) and log
    // the failure rather than block the customer notification.
    $attachments = [];
    $pdfError = null;
    try {
        $pdfPath = invoiceRenderPdf($id);
        $attachments[] = [
            'filename' => 'invoice-' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $row['invoice_number']) . '.pdf',
            'path'     => $pdfPath,
            'mime'     => 'application/pdf',
        ];
    } catch (\Throwable $e) {
        $pdfError = $e->getMessage();
    }

    $subject = sprintf('Invoice %s — %s due', $row['invoice_number'], number_format((float) $row['amount_due'], 2) . ' ' . $row['currency']);
    $textBody = sprintf(
        "Hi,\n\nPlease find your invoice %s attached.\n\nAmount due: %s %s\nDue date: %s\n\nView online: %s\n\nThank you.\n",
        $row['invoice_number'], number_format((float) $row['amount_due'], 2), $row['currency'], $row['due_date'], $tok['url']
    );
    $htmlBody = sprintf(
        '<div style="font-family:system-ui,Arial,sans-serif;max-width:560px;margin:0 auto;padding:24px;color:#111">' .
        '<h2 style="margin:0 0 8px">Invoice %s</h2>' .
        '<p style="margin:0 0 16px;color:#555">Amount due: <strong>%s %s</strong> by <strong>%s</strong>.</p>' .
        '<p><a href="%s" style="background:#1f2937;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;display:inline-block">View invoice</a></p>' .
        '<p style="font-size:12px;color:#888">If the button doesn\'t work, copy this link: %s</p>' .
        '</div>',
        htmlspecialchars($row['invoice_number'], ENT_QUOTES, 'UTF-8'),
        htmlspecialchars(number_format((float) $row['amount_due'], 2), ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($row['currency'], ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($row['due_date'], ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($tok['url'], ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($tok['url'], ENT_QUOTES, 'UTF-8')
    );

    $sendRes = $svc->send($tid, 'billing', 'invoice_sent', [$to], $subject, $textBody, $htmlBody, $attachments, [
        'from' => $sender['from'], 'from_name' => $sender['from_name'], 'reply_to' => $sender['reply_to'],
        'idempotency_key' => 'billing-invoice-' . $id,
    ]);

    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    getDB()->prepare('UPDATE billing_invoices SET status = "sent", sent_at = NOW() WHERE id = :id')->execute(['id' => $id]);
    billingAudit('billing.invoice.sent', [
        'invoice_id' => $id, 'invoice_number' => $row['invoice_number'],
        'to' => $to, 'token_id' => $tok['token_id'],
        'email_status' => $sendRes['status'] ?? 'unknown',
        'pdf_attached' => !empty($attachments),
        'pdf_error'    => $pdfError,
    ], $id);

    api_ok([
        'ok' => true, 'token_id' => $tok['token_id'], 'url' => $tok['url'],
        'email_status' => $sendRes['status'] ?? 'unknown',
        'email_error'  => $sendRes['error'] ?? null,
        'pdf_attached' => !empty($attachments),
        'pdf_error'    => $pdfError,
    ]);
}

if ($method === 'GET' && $action === 'pdf' && !empty($_GET['id'])) {
    rbac_legacy_require($user, 'billing.view');
    $id  = (int) $_GET['id'];
    $row = scopedFind('SELECT id, invoice_number FROM billing_invoices WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);

    try {
        $pdfPath = invoiceRenderPdf($id);
    } catch (\Throwable $e) {
        api_error('PDF render failed: ' . $e->getMessage(), 500);
    }

    $fname = 'invoice-' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $row['invoice_number']) . '.pdf';
    $disposition = (($_GET['download'] ?? '0') === '1') ? 'attachment' : 'inline';

    // Stream the bytes directly. We bypass api_ok() because this isn't JSON.
    if (!headers_sent()) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . $disposition . '; filename="' . $fname . '"');
        header('Content-Length: ' . filesize($pdfPath));
        header('Cache-Control: private, max-age=0, must-revalidate');
    }
    readfile($pdfPath);
    exit;
}

if ($method === 'POST' && $action === 'void') {
    rbac_legacy_require($user, 'billing.invoice.void');
    $id  = (int) ($_GET['id'] ?? 0);
    $row = scopedFind('SELECT * FROM billing_invoices WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    if ($row['status'] === 'void') api_error('Already void', 409);
    $body = api_json_body();
    $reason = trim((string) ($body['reason'] ?? ''));
    if ($reason === '') api_error('reason required', 422);

    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        // If no payments allocated, free up consumed bundles back to ready.
        $allocCount = $pdo->prepare('SELECT COUNT(*) FROM billing_payment_allocations WHERE invoice_id = :id');
        $allocCount->execute(['id' => $id]);
        $hasPayments = (int) $allocCount->fetchColumn() > 0;

        if (!$hasPayments) {
            $pdo->prepare(
                'UPDATE time_downstream_feed
                 SET status = "ready", consumed_at = NULL, consumed_by_module = NULL, consumed_ref_id = NULL
                 WHERE tenant_id = :t AND consumed_by_module = "billing" AND consumed_ref_id = :id'
            )->execute(['t' => $tid, 'id' => $id]);
        }

        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare(
            'UPDATE billing_invoices SET status = "void", voided_at = NOW(),
             voided_by_user_id = :u, void_reason = :r WHERE id = :id'
        )->execute(['u' => $user['id'] ?? null, 'r' => $reason, 'id' => $id]);

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    billingAudit('billing.invoice.voided', [
        'invoice_id' => $id, 'invoice_number' => $row['invoice_number'],
        'reason' => $reason, 'had_payments' => $hasPayments,
    ], $id);
    api_ok(['ok' => true, 'bundles_released' => !$hasPayments]);
}

if ($method === 'POST' && $action === 'post') {
    // Post the invoice to GL:
    //   Dr  Accounts Receivable (1100)
    //   Cr  Revenue             (4000)
    //   Cr  Sales Tax Payable   (2100)   [only if tax_total > 0]
    // Idempotent on billing:invoice:<id>:post.
    rbac_legacy_require($user, 'billing.invoice.approve');
    $id  = (int) ($_GET['id'] ?? 0);
    $row = scopedFind('SELECT * FROM billing_invoices WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    if (!in_array($row['status'], ['approved','sent','partially_paid','paid'], true)) {
        api_error("Cannot post from status {$row['status']}", 409);
    }
    require_once __DIR__ . '/../../accounting/lib/accounting.php';
    require_once __DIR__ . '/../../accounting/lib/multi_period.php';
    require_once __DIR__ . '/../../../core/posting_engine/process.php';

    // Multi-period split branch — gated by per-tenant opt-in. When ON,
    // we route the post through the accrual-bridge helper which emits
    // N JEs (one per accounting_period spanned by the underlying
    // work_dates) instead of one JE tied to issue_date. Skips the
    // event-layer entirely because the rule engine assumes a single
    // post-date — out of scope to teach it multi-period batches.
    $settings = accountingSettingsGet($tid);
    if (!empty($settings['multi_period_split_enabled'])) {
        try {
            accountingEnsureAccrualAccounts($tid, $settings);
            $byDate    = accountingBreakdownInvoiceByDate($tid, (int) $id);
            $perPeriod = accountingGroupBreakdownByPeriod($tid, (int) ($row['entity_id'] ?? 0), $byDate);
        } catch (\Throwable $e) {
            api_error('Multi-period split prep failed: ' . $e->getMessage(), 422);
        }
        if (count($perPeriod) > 1) {
            $batch = accountingBuildInvoiceJEBatch($row, $perPeriod, (string) $settings['ar_unbilled_account_code']);
            $pdo_mp = getDB();
            $jeIds = [];
            $pdo_mp->beginTransaction();
            try {
                foreach ($batch as $i => $je) {
                    $res = accountingPostJe($tid, [
                        'posting_date'    => $je['date'],
                        'currency'        => $row['currency'],
                        'source_module'   => 'billing',
                        'source_ref_type' => 'billing_invoice',
                        'source_ref_id'   => $id,
                        // Idempotency key per JE so a retried bulk post
                        // doesn't double-insert any single accrual.
                        'idempotency_key' => sprintf('billing:invoice:%d:post:mp:%d', $id, $i),
                        'memo'            => "Invoice {$row['invoice_number']} — period " . ($i + 1) . '/' . count($batch)
                                          . ($je['is_issue_period'] ? ' (recognition)' : ' (accrual)'),
                        'lines'           => $je['lines'],
                    ], $user['id'] ?? null, true);
                    $jeIds[] = $res['je_id'];
                }
                $pdo_mp->prepare('UPDATE billing_invoices SET journal_entry_id = :j WHERE tenant_id = :t AND id = :id')
                    ->execute(['j' => $jeIds[count($jeIds) - 1], 't' => $tid, 'id' => $id]);
                $pdo_mp->commit();
            } catch (\Throwable $e) {
                if ($pdo_mp->inTransaction()) $pdo_mp->rollBack();
                api_error('Multi-period post failed: ' . $e->getMessage(), 422);
            }
            billingAudit('billing.invoice.posted', [
                'invoice_id' => $id, 'invoice_number' => $row['invoice_number'],
                'journal_entry_ids' => $jeIds,
                'via' => 'multi_period_split',
                'periods_spanned' => count($batch),
            ], $id);
            api_ok([
                'ok' => true,
                'journal_entry_ids' => $jeIds,
                'periods_spanned'   => count($batch),
                'via'               => 'multi_period_split',
            ]);
        }
        // Single-period fall-through: clean monthly cycle, no accrual
        // bridge needed → drop into legacy path for normal posting.
    }

    $subtotal = (float) $row['subtotal'];
    $taxTotal = (float) $row['tax_total'];
    $total    = (float) $row['total'];
    $party    = !empty($row['client_company_id']) ? (int) $row['client_company_id'] : null;

    // Group revenue per gl_revenue_account_code so non-labor lines land in
    // their own account (e.g. 4100 Reimbursable, 4200 Materials, 4300 SOW
    // Fees). Lines without an override fall back to 4000 Revenue.
    $pdo = getDB();
    $linesStmt = $pdo->prepare(
        'SELECT item_type, gl_revenue_account_code, SUM(subtotal) AS s
         FROM billing_invoice_lines WHERE invoice_id = :id
         GROUP BY item_type, gl_revenue_account_code'
    );
    $linesStmt->execute(['id' => $id]);
    $bucketSums = [];
    foreach ($linesStmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
        $code = $r['gl_revenue_account_code'] ?: '4000';
        $bucketSums[$code] = ($bucketSums[$code] ?? 0) + (float) $r['s'];
    }
    if (!$bucketSums) $bucketSums['4000'] = $subtotal;

    $lines = [
        ['account_code' => '1100', 'debit' => $total, 'credit' => 0, 'memo' => "Inv {$row['invoice_number']} / {$row['client_name']}", 'counterparty_company_id' => $party],
    ];
    foreach ($bucketSums as $code => $amt) {
        if (round($amt, 2) <= 0.005) continue;
        $lines[] = ['account_code' => $code, 'debit' => 0, 'credit' => round($amt, 2), 'memo' => "Revenue — {$row['invoice_number']}", 'counterparty_company_id' => $party];
    }
    if ($taxTotal > 0.005) {
        $lines[] = ['account_code' => '2100', 'debit' => 0, 'credit' => $taxTotal, 'memo' => "Sales tax — {$row['invoice_number']}", 'counterparty_company_id' => $party];
    }

    // Sprint 7e — preferred path: emit billing.invoice.sent into the
    // posting engine. Falls back to the legacy direct accountingPostJe()
    // call when no rule has been seeded for this tenant.
    $payloadLines = [];
    foreach ($lines as $l) {
        $payloadLines[] = [
            'account_code' => $l['account_code'],
            'debit'        => (float) ($l['debit']  ?? 0),
            'credit'       => (float) ($l['credit'] ?? 0),
            'description'  => $l['memo'] ?? null,
            'counterparty_company_id' => $l['counterparty_company_id'] ?? null,
        ];
    }
    $eventResult = null; $eventError = null;
    try {
        $eventResult = accountingProcessEvent($tid, [
            'entity_id'        => !empty($row['entity_id']) ? (int) $row['entity_id'] : 0,
            'event_type'       => 'billing.invoice.sent',
            'source_module'    => 'billing',
            'source_record_id' => 'billing_invoice:' . $id,
            'event_date'       => (string) $row['issue_date'],
            'payload'          => [
                'invoice_id'     => (int) $id,
                'invoice_number' => (string) $row['invoice_number'],
                'client_name'    => (string) $row['client_name'],
                'client_company_id' => $party,
                'amount'         => (float) $row['total'],
                'currency'       => (string) $row['currency'],
                'lines'          => $payloadLines,
            ],
        ], $user['id'] ?? null);
    } catch (\Throwable $e) {
        $eventError = $e->getMessage();
    }

    if ($eventResult && ($eventResult['status'] ?? null) === 'posted') {
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare('UPDATE billing_invoices SET journal_entry_id = :j WHERE id = :id')
            ->execute(['j' => $eventResult['journal_entry_id'], 'id' => $id]);
        billingAudit('billing.invoice.posted', [
            'invoice_id' => $id, 'invoice_number' => $row['invoice_number'],
            'journal_entry_id' => (int) $eventResult['journal_entry_id'],
            'accounting_event_id' => (int) ($eventResult['event_id'] ?? 0),
            'idempotent_replay' => !empty($eventResult['idempotent_replay']),
            'via' => 'event_layer',
        ], $id);
        api_ok([
            'ok' => true,
            'journal_entry_id' => (int) $eventResult['journal_entry_id'],
            'je_number' => $eventResult['je_number'] ?? null,
            'idempotent_replay' => !empty($eventResult['idempotent_replay']),
            'accounting_event_id' => (int) ($eventResult['event_id'] ?? 0),
            'via' => 'event_layer',
        ]);
    }

    try {
        $res = accountingPostJe($tid, [
            'posting_date'    => $row['issue_date'],
            'currency'        => $row['currency'],
            'source_module'   => 'billing',
            'source_ref_type' => 'billing_invoice',
            'source_ref_id'   => $id,
            'idempotency_key' => sprintf('billing:invoice:%d:post', $id),
            'memo'            => "Invoice {$row['invoice_number']} / {$row['client_name']}",
            'lines'           => $lines,
        ], $user['id'] ?? null, true);
    } catch (\Throwable $e) {
        api_error('GL post failed: ' . $e->getMessage()
                . ($eventError ? ' | event-layer error: ' . $eventError : ''), 422);
    }

    // Phase-2a: record that the legacy fallback fired — telemetry feeds
    // the discipline dashboard so we can prove zero fallback fires before
    // hard-erroring this path.
    require_once __DIR__ . '/../../../core/module_emission_discipline.php';
    moduleEmissionDisciplineLog('billing', 'billing.invoice.sent', [
        'invoice_id'   => (int) $id,
        'event_error'  => $eventError,
        'event_status' => $eventResult['status'] ?? null,
    ]);

    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    $pdo->prepare('UPDATE billing_invoices SET journal_entry_id = :j WHERE id = :id')
        ->execute(['j' => $res['je_id'], 'id' => $id]);

    // Sprint 7e fallback: write subledger_links + flip event status.
    try {
        $pdo->prepare(
            'INSERT IGNORE INTO accounting_subledger_links
                (tenant_id, source_module, source_record_id, journal_entry_id, link_kind)
             VALUES (:t, "billing", :sr, :je, "primary")'
        )->execute([
            't'  => $tid,
            'sr' => 'billing_invoice:' . $id,
            'je' => (int) $res['je_id'],
        ]);
        if ($eventResult && !empty($eventResult['event_id'])) {
            // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
            $pdo->prepare(
                'UPDATE accounting_events
                    SET status = "posted", journal_entry_id = :je, posted_at = NOW(),
                        error_message = "fallback: legacy direct post (no rule matched)"
                  WHERE id = :id AND status IN ("ignored","failed","received","mapped")'
            )->execute(['je' => (int) $res['je_id'], 'id' => (int) $eventResult['event_id']]);
        }
    } catch (\Throwable $_) { /* tables absent in pre-7b tenants — non-fatal */ }

    billingAudit('billing.invoice.posted', [
        'invoice_id' => $id, 'invoice_number' => $row['invoice_number'],
        'journal_entry_id' => $res['je_id'], 'je_number' => $res['je_number'],
        'idempotent_replay' => $res['idempotent_replay'],
        'via' => 'legacy_direct',
        'event_layer_status' => $eventResult['status'] ?? null,
    ], $id);
    api_ok([
        'ok' => true,
        'journal_entry_id' => $res['je_id'],
        'je_number' => $res['je_number'],
        'idempotent_replay' => $res['idempotent_replay'],
        'via' => 'legacy_direct',
    ]);
}

// =======================================================================
// Post invoice with intercompany revenue split — one entity books AR +
// Due-From-<other>; each other entity books its revenue share + Due-To.
// Idempotency: ic:invoice:<id>
// =======================================================================
if ($method === 'POST' && $action === 'post_with_ic_split') {
    rbac_legacy_require($user, 'billing.invoice.approve');
    rbac_legacy_require($user, 'accounting.je.post');
    $id  = (int) ($_GET['id'] ?? 0);
    $row = scopedFind('SELECT * FROM billing_invoices WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    if (!in_array($row['status'], ['approved','sent','partially_paid','paid'], true)) {
        api_error("Cannot post from status {$row['status']}", 409);
    }
    if (!empty($row['journal_entry_id'])) {
        api_ok([
            'ok' => true,
            'journal_entry_id'      => (int) $row['journal_entry_id'],
            'intercompany_group_id' => $row['intercompany_group_id'] ?? null,
            'idempotent_replay'     => true,
        ]);
    }
    require_once __DIR__ . '/../../accounting/lib/accounting.php';
    require_once __DIR__ . '/../../accounting/lib/intercompany.php';

    $body   = api_json_body();
    $source = $body['source'] ?? null;
    if ($source && !empty($source['entity_id'])) {
        $sourceEntityId = (int) $source['entity_id'];
        $arAccount      = (string) ($source['offset_line']['account_code'] ?? '1100');
    } else {
        $sourceEntityId = !empty($body['entity_id']) ? (int) $body['entity_id']
                                                      : (int) accountingDefaultEntity($tid)['id'];
        $arAccount      = trim((string) ($body['ar_account_code'] ?? '1100'));
    }
    $splits = $body['splits'] ?? [];
    if (!is_array($splits) || !$splits) api_error('splits[] required', 422);

    try {
        $res = intercompanyPostSplit($tid, [
            'posting_date'       => $row['issue_date'],
            'memo'               => "Invoice {$row['invoice_number']} / {$row['client_name']}",
            'idempotency_prefix' => sprintf('ic:invoice:%d', $id),
            'source'             => [
                'entity_id'   => $sourceEntityId,
                'offset_line' => [
                    'account_code' => $arAccount,
                    'amount'       => (float) $row['total'],
                    'side'         => 'debit',
                    'memo'         => "AR {$row['invoice_number']}",
                ],
            ],
            'splits' => array_map(fn ($s) => [
                'entity_id'    => (int) $s['entity_id'],
                'account_code' => (string) $s['account_code'],
                'amount'       => (float) $s['amount'],
                'memo'         => $s['memo'] ?? null,
                'ic_override'  => $s['ic_override'] ?? null,
            ], $splits),
        ], $user['id'] ?? null);
    } catch (\Throwable $e) {
        api_error('GL post failed: ' . $e->getMessage(), 422);
    }

    $sourceLeg = null;
    foreach ($res['jes'] as $leg) if ($leg['role'] === 'source') { $sourceLeg = $leg; break; }
    if (!$sourceLeg) $sourceLeg = $res['jes'][0] ?? null;

    getDB()->prepare(
        'UPDATE billing_invoices SET journal_entry_id = :j, intercompany_group_id = :g WHERE id = :id AND tenant_id = :t'
    )->execute(['j' => $sourceLeg['je_id'], 'g' => $res['group_id'], 'id' => $id, 't' => $tid]);

    billingAudit('billing.invoice.posted_ic', [
        'invoice_id'           => $id, 'invoice_number' => $row['invoice_number'],
        'journal_entry_id'     => (int) $sourceLeg['je_id'],
        'intercompany_group_id'=> $res['group_id'],
        'leg_count'            => count($res['jes']),
    ], $id);

    api_ok(['ok' => true, 'journal_entry_id' => (int) $sourceLeg['je_id'], 'intercompany_group_id' => $res['group_id'], 'jes' => $res['jes']]);
}

api_error('Method not allowed', 405);

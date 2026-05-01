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

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET' && !empty($_GET['id'])) {
    RBAC::requirePermission($user, 'billing.view');
    $id = (int) $_GET['id'];
    $inv = scopedFind('SELECT * FROM billing_invoices WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$inv) api_error('Not found', 404);
    $pdo = getDB();
    $linesStmt = $pdo->prepare('SELECT * FROM billing_invoice_lines WHERE invoice_id = :id ORDER BY line_no');
    $linesStmt->execute(['id' => $id]);
    $lines = $linesStmt->fetchAll(\PDO::FETCH_ASSOC);
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
    RBAC::requirePermission($user, 'billing.view');
    $where  = ['tenant_id = :tenant_id'];
    $params = [];
    if (!empty($_GET['client_name'])) { $where[] = 'client_name = :cn';   $params['cn'] = $_GET['client_name']; }
    if (!empty($_GET['status']))      { $where[] = 'status = :st';        $params['st'] = $_GET['status']; }
    if (!empty($_GET['from']))        { $where[] = 'issue_date >= :df';   $params['df'] = $_GET['from']; }
    if (!empty($_GET['to']))          { $where[] = 'issue_date <= :dt';   $params['dt'] = $_GET['to']; }
    if (!empty($_GET['due_before']))  { $where[] = 'due_date < :db';      $params['db'] = $_GET['due_before']; }
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
    RBAC::requirePermission($user, 'billing.invoice.draft');
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
                $stmt = $pdo->prepare(
                    'INSERT INTO billing_invoice_lines
                      (invoice_id, line_no, source_type, source_ref_id, placement_id, rate_snapshot_id,
                       description, quantity, unit, unit_price, subtotal, tax_rate_pct, tax_amount, total)
                     VALUES
                      (:invoice_id, :line_no, :source_type, :source_ref_id, :placement_id, :rate_snapshot_id,
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
    api_ok(['invoices_created' => $created], 201);
}

if ($method === 'POST' && $action === '') {
    RBAC::requirePermission($user, 'billing.invoice.draft');
    $body = api_json_body();
    api_require_fields($body, ['client_name', 'lines']);
    if (empty($body['lines']) || !is_array($body['lines'])) api_error('lines must be a non-empty array', 422);

    $pdo = getDB();
    $taxStmt = $pdo->prepare('SELECT billing_tax_rate_pct, billing_invoice_terms FROM tenants WHERE id = :id');
    $taxStmt->execute(['id' => $tid]);
    $cfg = $taxStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    $taxPct = (float) ($cfg['billing_tax_rate_pct'] ?? 0);
    $netDays = preg_match('/^NET(\d+)$/i', (string) ($cfg['billing_invoice_terms'] ?? 'NET30'), $m) ? (int) $m[1] : 30;

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
                  (invoice_id, line_no, source_type, description, quantity, unit, unit_price,
                   subtotal, tax_rate_pct, tax_amount, total)
                 VALUES
                  (:invoice_id, :line_no, "manual", :description, :quantity, :unit, :unit_price,
                   :subtotal, :tax_rate_pct, :tax_amount, :total)'
            );
            $stmt->execute([
                'invoice_id' => $invId, 'line_no' => $line_no++,
                'description' => $l['description'] ?? '',
                'quantity' => $l['quantity'] ?? 0, 'unit' => $l['unit'] ?? 'each',
                'unit_price' => $l['unit_price'] ?? 0, 'subtotal' => $l['subtotal'],
                'tax_rate_pct' => $l['tax_rate_pct'], 'tax_amount' => $l['tax_amount'],
                'total' => $l['total'],
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
    RBAC::requirePermission($user, 'billing.invoice.draft');
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
    RBAC::requirePermission($user, 'billing.invoice.approve');
    $id  = (int) ($_GET['id'] ?? 0);
    $row = scopedFind('SELECT * FROM billing_invoices WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    if (!billingTransitionAllowed($row['status'], 'approved')) api_error("Cannot approve from status {$row['status']}", 409);
    if ((int) ($row['created_by_user_id'] ?? 0) === (int) ($user['id'] ?? 0)) {
        api_error('Two-eye control: you cannot approve your own draft.', 403);
    }
    getDB()->prepare('UPDATE billing_invoices SET status = "approved", approved_by_user_id = :u, approved_at = NOW() WHERE id = :id')
        ->execute(['u' => $user['id'] ?? null, 'id' => $id]);
    billingAudit('billing.invoice.approved', ['invoice_id' => $id, 'invoice_number' => $row['invoice_number']], $id);
    api_ok(['ok' => true]);
}

if ($method === 'POST' && $action === 'send') {
    RBAC::requirePermission($user, 'billing.invoice.send');
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

    $sendRes = $svc->send($tid, 'billing', 'invoice_sent', [$to], $subject, $textBody, $htmlBody, [], [
        'from' => $sender['from'], 'from_name' => $sender['from_name'], 'reply_to' => $sender['reply_to'],
        'idempotency_key' => 'billing-invoice-' . $id,
    ]);

    getDB()->prepare('UPDATE billing_invoices SET status = "sent", sent_at = NOW() WHERE id = :id')->execute(['id' => $id]);
    billingAudit('billing.invoice.sent', [
        'invoice_id' => $id, 'invoice_number' => $row['invoice_number'],
        'to' => $to, 'token_id' => $tok['token_id'],
        'email_status' => $sendRes['status'] ?? 'unknown',
    ], $id);

    api_ok([
        'ok' => true, 'token_id' => $tok['token_id'], 'url' => $tok['url'],
        'email_status' => $sendRes['status'] ?? 'unknown',
        'email_error'  => $sendRes['error'] ?? null,
    ]);
}

if ($method === 'POST' && $action === 'void') {
    RBAC::requirePermission($user, 'billing.invoice.void');
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

api_error('Method not allowed', 405);

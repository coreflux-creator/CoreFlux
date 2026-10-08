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
 *   POST   /api/billing/invoices?action=request_approval&id=N → request human review
 *   POST   /api/billing/invoices?action=approve&id=N    → direct or policy-routed approval
 *   POST   /api/billing/invoices?action=send&id=N       → issue token + email
 *   POST   /api/billing/invoices?action=replace_link&id=N → issue a new manual-share link
 *   POST   /api/billing/invoices?action=revoke_link&id=N → disable all invoice links
 *   POST   /api/billing/invoices?action=void&id=N       → body: {reason}
 *
 * SPEC: /app/modules/billing/SPEC.md §5.1, §9.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/mail_bootstrap.php';
require_once __DIR__ . '/../../../core/tenant_mail.php';
require_once __DIR__ . '/../../../core/active_entity.php';
require_once __DIR__ . '/../lib/billing.php';
require_once __DIR__ . '/../lib/invoice_drafts.php';
require_once __DIR__ . '/../lib/invoice_pdf.php';
require_once __DIR__ . '/../lib/entity_delivery.php';
require_once __DIR__ . '/../lib/invoice_delivery.php';
require_once __DIR__ . '/../lib/entity_scope.php';
require_once __DIR__ . '/../lib/workflow.php';
require_once __DIR__ . '/../../ap/lib/ap.php';   // apNormalizeItemType() — shared item_type vocabulary
require_once __DIR__ . '/../../ap/lib/pwp.php';  // apPwpAutoLinkForArInvoice() — pay-when-paid auto-link
require_once __DIR__ . '/../../staffing/lib/clients.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$clientCatalogTenantId = staffingClientCatalogTenantId($tid);
$placementsTenantId = effectiveTenantIdForModule('placements', $tid) ?? $tid;
$method = api_method();
$action = $_GET['action'] ?? '';

function billingInvoiceDefaultRecipient(int $tenantId, array $invoice): array
{
    if (!empty($invoice['bill_to_json'])) {
        $billTo = json_decode((string) $invoice['bill_to_json'], true) ?: [];
        $email = trim((string) ($billTo['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['email' => $email, 'source' => 'invoice bill-to'];
        }
    }

    try {
        $entityId = billingInvoiceDeliveryEntityId($tenantId, $invoice);
        $contact = $entityId ? billingClientContactForEntity($tenantId, $entityId, (string) ($invoice['client_name'] ?? '')) : null;
        $email = trim((string) ($contact['ar_primary_email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['email' => $email, 'source' => 'client contacts'];
        }
    } catch (\Throwable $e) {
        error_log('[billing invoice recipient] ' . $e->getMessage());
    }

    return ['email' => null, 'source' => null];
}

if ($method === 'GET' && !empty($_GET['id']) && $action !== 'pdf') {
    rbac_legacy_require($user, 'billing.view');
    $id = (int) $_GET['id'];
    $inv = scopedFind(
        'SELECT bi.*, je.status AS journal_status,
                e.code AS entity_code, e.legal_name AS entity_name
           FROM billing_invoices bi
           LEFT JOIN accounting_journal_entries je
             ON je.tenant_id = bi.tenant_id AND je.id = bi.journal_entry_id
           LEFT JOIN accounting_entities e
             ON e.tenant_id = bi.tenant_id AND e.id = bi.entity_id
          WHERE bi.tenant_id = :tenant_id AND bi.id = :id',
        ['id' => $id]
    );
    if (!$inv) api_error('Not found', 404);
    $pdo = getDB();
    $linesStmt = $pdo->prepare(
        'SELECT l.*, i.code AS catalog_item_code, i.name AS catalog_item_name
           FROM billing_invoice_lines l
           LEFT JOIN billing_items i ON i.id = l.catalog_item_id AND i.tenant_id = :tenant_id
          WHERE l.invoice_id = :id ORDER BY l.line_no'
    );
    $linesStmt->execute(['id' => $id, 'tenant_id' => $tid]);
    $lines = $linesStmt->fetchAll(\PDO::FETCH_ASSOC);
    // tenant-leak-allow: defense-in-depth — caller scoped row by tenant_id before this id-only write
    $allocStmt = $pdo->prepare(
        'SELECT bpa.amount_applied, bpa.applied_at, bp.id AS payment_id, bp.received_at, bp.method, bp.reference, bp.amount AS payment_amount
         FROM billing_payment_allocations bpa
         JOIN billing_payments bp ON bp.id = bpa.payment_id
         WHERE bpa.invoice_id = :id AND bpa.reversed_at IS NULL
           AND bp.tenant_id = :tenant_id AND bp.voided_at IS NULL
         ORDER BY bpa.applied_at DESC'
    );
    $allocStmt->execute(['id' => $id, 'tenant_id' => $tid]);
    $allocations = $allocStmt->fetchAll(\PDO::FETCH_ASSOC);
    $tokStmt = $pdo->prepare(
        'SELECT id, issued_at, expires_at, revoked_at, last_viewed_at, view_count,
                CASE WHEN revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())
                     THEN 1 ELSE 0 END AS is_active
         FROM billing_invoice_tokens WHERE invoice_id = :id AND tenant_id = :t
         ORDER BY is_active DESC, id DESC LIMIT 1'
    );
    $tokStmt->execute(['id' => $id, 't' => $tid]);
    $token = $tokStmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    $deliveryStmt = $pdo->prepare(
        'SELECT id, delivery_status, delivery_recipient, delivery_started_at,
                delivery_finished_at, delivery_provider_id,
                TIMESTAMPDIFF(SECOND, delivery_started_at, NOW()) AS age_seconds
           FROM billing_invoice_tokens
          WHERE invoice_id = :id AND tenant_id = :t AND delivery_request_id IS NOT NULL
          ORDER BY id DESC LIMIT 1'
    );
    $deliveryStmt->execute(['id' => $id, 't' => $tid]);
    $delivery = $deliveryStmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    $deliveryEntityId = billingInvoiceDeliveryEntityId($tid, $inv);
    api_ok([
        'invoice' => $inv,
        'lines' => $lines,
        'allocations' => $allocations,
        'token' => $token,
        'delivery' => $delivery,
        'capabilities' => ['can_send' => RBAC::hasPermission($user, 'billing.invoice.send')],
        'default_recipient' => billingInvoiceDefaultRecipient($tid, $inv),
        'delivery_sender' => $deliveryEntityId ? billingEntityMailSender($tid, $deliveryEntityId) : null,
    ]);
}

if ($method === 'GET' && $action === '') {
    rbac_legacy_require($user, 'billing.view');
    try {
        $listEntityId = billingEntityFilterId($tid, isset($_GET['entity_id']) ? (string) $_GET['entity_id'] : null);
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    } catch (OutOfBoundsException $e) {
        api_error($e->getMessage(), 404);
    }
    $where  = ['bi.tenant_id = :tenant_id'];
    $params = [];
    if (!empty($_GET['client_name'])) { $where[] = 'bi.client_name = :cn';   $params['cn'] = $_GET['client_name']; }
    if (!empty($_GET['status']))      { $where[] = 'bi.status = :st';        $params['st'] = $_GET['status']; }
    if (!empty($_GET['from']))        { $where[] = 'bi.issue_date >= :df';   $params['df'] = $_GET['from']; }
    if (!empty($_GET['to']))          { $where[] = 'bi.issue_date <= :dt';   $params['dt'] = $_GET['to']; }
    if (!empty($_GET['due_before']))  { $where[] = 'bi.due_date < :db';      $params['db'] = $_GET['due_before']; }
    if (trim((string) ($_GET['q'] ?? '')) !== '') {
        $needle = '%' . trim((string) $_GET['q']) . '%';
        $where[] = '(bi.invoice_number LIKE :q_invoice OR bi.client_name LIKE :q_client)';
        $params['q_invoice'] = $needle;
        $params['q_client'] = $needle;
    }
    if ($listEntityId !== null) { $where[] = 'bi.entity_id = :eid'; $params['eid'] = $listEntityId; }
    $perPage = max(1, min(200, (int) ($_GET['per_page'] ?? 50)));
    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $offset  = ($page - 1) * $perPage;

    $rows = scopedQuery(
        'SELECT bi.id, bi.entity_id, e.code AS entity_code, e.legal_name AS entity_name,
                bi.invoice_number, bi.client_name, bi.issue_date, bi.due_date, bi.currency,
                bi.subtotal, bi.tax_total, bi.total, bi.amount_paid, bi.amount_due, bi.status,
                bi.po_number, bi.bill_to_json, bi.journal_entry_id, bi.sent_at, bi.created_at,
                (SELECT je.status FROM accounting_journal_entries je
                  WHERE je.tenant_id = bi.tenant_id
                    AND je.id = bi.journal_entry_id) AS journal_status
         FROM billing_invoices bi
         LEFT JOIN accounting_entities e ON e.tenant_id = bi.tenant_id AND e.id = bi.entity_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY bi.id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
        $params
    );
    $contactMap = [];
    try {
        foreach (scopedQuery(
            'SELECT entity_id, client_name, ar_primary_email
               FROM billing_client_contacts
              WHERE tenant_id = :tenant_id AND entity_id IS NOT NULL AND ar_primary_email IS NOT NULL'
        ) as $contact) {
            $key = (int) $contact['entity_id'] . ':' . strtolower(trim((string) $contact['client_name']));
            $contactMap[$key] = trim((string) $contact['ar_primary_email']);
        }
    } catch (\Throwable $e) {
        error_log('[billing invoice list recipients] ' . $e->getMessage());
    }
    foreach ($rows as &$invoiceRow) {
        $billTo = !empty($invoiceRow['bill_to_json'])
            ? (json_decode((string) $invoiceRow['bill_to_json'], true) ?: [])
            : [];
        $billToEmail = trim((string) ($billTo['email'] ?? ''));
        $contactKey = (int) ($invoiceRow['entity_id'] ?? 0) . ':' . strtolower(trim((string) $invoiceRow['client_name']));
        $contactEmail = $contactMap[$contactKey] ?? '';
        if ($billToEmail !== '' && filter_var($billToEmail, FILTER_VALIDATE_EMAIL)) {
            $invoiceRow['recipient_email'] = $billToEmail;
            $invoiceRow['recipient_source'] = 'invoice bill-to';
        } elseif ($contactEmail !== '' && filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $invoiceRow['recipient_email'] = $contactEmail;
            $invoiceRow['recipient_source'] = 'client contacts';
        } else {
            $invoiceRow['recipient_email'] = null;
            $invoiceRow['recipient_source'] = null;
        }
        unset($invoiceRow['bill_to_json']);
    }
    unset($invoiceRow);
    $cnt  = scopedQuery('SELECT COUNT(*) AS c FROM billing_invoices bi WHERE ' . implode(' AND ', $where), $params);
    api_ok(['rows' => $rows, 'total' => (int) ($cnt[0]['c'] ?? 0),
        'entity_id' => $listEntityId, 'page' => $page, 'per_page' => $perPage]);
}

if ($method === 'POST' && $action === 'suggest-from-placement') {
    rbac_legacy_require($user, 'billing.invoice.draft');
    $body = api_json_body();
    $placementId = (int) ($body['placement_id'] ?? 0);
    if ($placementId <= 0) api_error('placement_id required', 422);
    try {
        $sug = billingSuggestInvoiceForPlacement($tid, $placementId, $user['id'] ?? null);
    } catch (\Throwable $e) { api_error($e->getMessage(), 422); }
    api_ok($sug);
}

if ($method === 'POST' && $action === 'from-time-entries') {
    rbac_legacy_require($user, 'billing.invoice.draft');
    $body = api_json_body();
    api_require_fields($body, ['time_entry_ids']);
    $entryIds = (array) $body['time_entry_ids'];
    $aggregation = (string) ($body['aggregation'] ?? 'per_day');

    try {
        $drafts = billingBuildDraftFromTimeEntries($tid, $entryIds, $aggregation);
    } catch (\Throwable $e) { api_error($e->getMessage(), 422); }
    if (empty($drafts)) api_error('No invoices could be built (no billable entries)', 422);

    $pdo = getDB();
    $created = [];
    require_once __DIR__ . '/../../people/lib/companies.php';
    cf_begin_transaction();
    try {
        foreach ($drafts as $d) {
            $inv  = $d['invoice'];
            $inv['tenant_id']          = $tid;
            $inv['invoice_number']     = billingNextInvoiceNumber($tid);
            $inv['created_by_user_id'] = $user['id'] ?? null;

            $clientCid = companiesUpsertByName($clientCatalogTenantId, (string) $inv['client_name'], [
                'created_by_user_id' => $user['id'] ?? null,
            ], ['client']);
            companiesBumpUsage($clientCid);
            $inv['client_company_id'] = $clientCid;

            $invId = scopedInsert('billing_invoices', $inv);

            foreach ($d['lines'] as $l) {
                unset($l['_entry_ids']);
                $l['invoice_id'] = $invId;
                $l['item_type']  = apNormalizeItemType($l['item_type'] ?? null, $l['source_type'] ?? 'time_entry');
                $stmt = $pdo->prepare(
                    'INSERT INTO billing_invoice_lines
                      (invoice_id, line_no, source_type, item_type, source_ref_id, placement_id, rate_snapshot_id,
                       description, quantity, unit, unit_price, subtotal, tax_rate_pct, tax_amount, total)
                     VALUES
                      (:invoice_id, :line_no, :source_type, :item_type, :source_ref_id, :placement_id, :rate_snapshot_id,
                       :description, :quantity, :unit, :unit_price, :subtotal, :tax_rate_pct, :tax_amount, :total)'
                );
                $stmt->execute($l);
                if (($l['source_type'] ?? '') === 'economic_item') {
                    placementEconomicsRecordItemObligation(
                        (int) $placementsTenantId,
                        (int) $l['source_ref_id'],
                        'projected',
                        $invId,
                        null,
                        null,
                        $inv['period_start'] ?? null,
                        $inv['period_end'] ?? null
                    );
                }
            }

            $invoiceEntryIds = array_values(array_unique(array_filter(array_map('intval', (array) ($d['entry_ids'] ?? [])))));
            if ($invoiceEntryIds) {
                $entryParams = ['tenant_id' => $tid, 'invoice_id' => $invId, 'user_id' => $user['id'] ?? null];
                $entryPlaceholders = [];
                foreach ($invoiceEntryIds as $entryIndex => $entryId) {
                    $entryKey = 'entry_' . $entryIndex;
                    $entryPlaceholders[] = ':' . $entryKey;
                    $entryParams[$entryKey] = $entryId;
                }
                $stamp = $pdo->prepare(
                    'UPDATE time_entries
                        SET bill_extracted_at = NOW(),
                            bill_extracted_ref = :invoice_id,
                            bill_extracted_by_user_id = :user_id
                      WHERE tenant_id = :tenant_id
                        AND id IN (' . implode(',', $entryPlaceholders) . ')
                        AND bill_extracted_at IS NULL'
                );
                $stamp->execute($entryParams);
                if ($stamp->rowCount() !== count($invoiceEntryIds)) {
                    throw new \DomainException(
                        'Some selected time was already included in another invoice. Refresh and try again.'
                    );
                }
            }

            billingAudit('billing.invoice.created', [
                'invoice_id'      => $invId,
                'invoice_number'  => $inv['invoice_number'],
                'source'          => 'time_entries',
                'aggregation'     => $aggregation,
                'entry_ids'       => $d['entry_ids'],
            ], $invId);

            $created[] = [
                'id' => $invId, 'invoice_number' => $inv['invoice_number'],
                'client_name' => $inv['client_name'], 'total' => $inv['total'],
                'entry_count' => count($d['entry_ids']),
            ];
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof \DomainException) api_error($e->getMessage(), 409);
        error_log('[billing invoice from time] ' . $e->getMessage());
        api_error('Could not create the invoice drafts. Nothing was changed. Try again.', 500);
    }

    api_ok(['invoices_created' => $created], 201);
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
    cf_begin_transaction();
    try {
        foreach ($drafts as $d) {
            $inv  = $d['invoice'];
            $inv['tenant_id'] = $tid;
            $inv['invoice_number'] = billingNextInvoiceNumber($tid);
            $inv['created_by_user_id'] = $user['id'] ?? null;

            $clientCid = companiesUpsertByName($clientCatalogTenantId, (string) $inv['client_name'], [
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
                if (($l['source_type'] ?? '') === 'economic_item') {
                    placementEconomicsRecordItemObligation(
                        (int) $placementsTenantId,
                        (int) $l['source_ref_id'],
                        'projected',
                        $invId,
                        null,
                        null,
                        $inv['period_start'] ?? null,
                        $inv['period_end'] ?? null
                    );
                }
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
    try {
        $created = billingCreateDirectInvoiceDraft($tid, $body, $user['id'] ?? null);
    } catch (\InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    }
    billingAudit('billing.invoice.created', ['invoice_id' => $created['id'], 'source' => 'manual'], $created['id']);
    api_ok(['id' => $created['id']], 201);
}

if ($method === 'PATCH') {
    rbac_legacy_require($user, 'billing.invoice.draft');
    $id = (int) ($_GET['id'] ?? 0);
    $row = scopedFind('SELECT * FROM billing_invoices WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    if ($row['status'] !== 'draft') api_error('Only draft invoices can be edited', 409);

    $body = api_json_body();
    $replaceLines = array_key_exists('lines', $body);
    $computed = null;
    $pdo = getDB();
    if ($replaceLines) {
        if (!is_array($body['lines'])) api_error('lines must be an array', 422);
        $sourceCheck = $pdo->prepare('SELECT COUNT(*) FROM billing_invoice_lines WHERE invoice_id = :id AND source_type <> "manual"');
        $sourceCheck->execute(['id' => $id]);
        if ((int) $sourceCheck->fetchColumn() > 0) api_error('Time-based invoice lines must be rebuilt from their source', 409);
        try {
            $body['lines'] = billingPrepareDirectInvoiceLines($pdo, $tid, $body['lines'], false);
        } catch (\InvalidArgumentException $e) {
            api_error($e->getMessage(), 422);
        }
        $taxPct = $body['tax_rate_pct'] ?? null;
        if ($taxPct === null || $taxPct === '') {
            $taxStmt = $pdo->prepare('SELECT COALESCE(MAX(tax_rate_pct), 0) FROM billing_invoice_lines WHERE invoice_id = :id');
            $taxStmt->execute(['id' => $id]);
            $taxPct = (float) $taxStmt->fetchColumn();
        }
        if (!is_numeric($taxPct) || (float) $taxPct < 0 || (float) $taxPct > 100) api_error('Tax rate must be between 0 and 100', 422);
        $computed = billingComputeTax($body['lines'], (float) $taxPct);
        try {
            billingValidateDirectInvoiceTotal($computed);
        } catch (\InvalidArgumentException $e) {
            api_error($e->getMessage(), 422);
        }
    }

    $editable = ['client_name','client_company_id','entity_id','bill_to_json','currency','issue_date','due_date','po_number','notes_internal','notes_external'];
    $sets = []; $binds = ['id' => $id, 'tenant_scope' => $tid];
    foreach ($editable as $f) {
        if (array_key_exists($f, $body)) {
            $sets[] = "{$f} = :{$f}";
            $binds[$f] = is_array($body[$f]) ? json_encode($body[$f]) : $body[$f];
        }
    }
    if ($computed) {
        foreach (['subtotal','tax_total','total'] as $field) {
            $sets[] = "{$field} = :{$field}";
            $binds[$field] = $computed[$field];
        }
        $sets[] = 'amount_due = :amount_due';
        $binds['amount_due'] = $computed['total'];
    }
    if (!$sets) api_error('Nothing to update', 422);

    cf_begin_transaction();
    try {
        $lockedStmt = $pdo->prepare('SELECT * FROM billing_invoices WHERE tenant_id = :t AND id = :id FOR UPDATE');
        $lockedStmt->execute(['t' => $tid, 'id' => $id]);
        $locked = $lockedStmt->fetch(PDO::FETCH_ASSOC);
        if (!$locked || $locked['status'] !== 'draft') {
            throw new DomainException('Only draft invoices can be edited');
        }
        if (billingInvoiceWorkflowPendingInstanceId($tid, $id) > 0) {
            throw new DomainException('This invoice is awaiting approval. Ask a reviewer to reject it before editing.');
        }
        if (array_key_exists('entity_id', $body) || array_key_exists('currency', $body)) {
            $requestedEntityId = array_key_exists('entity_id', $body)
                ? $body['entity_id'] : $locked['entity_id'];
            if ((!is_int($requestedEntityId) && !is_string($requestedEntityId))
                || !ctype_digit((string) $requestedEntityId) || (int) $requestedEntityId <= 0) {
                throw new InvalidArgumentException('Choose a valid issuing entity');
            }
            $entityStmt = $pdo->prepare(
                'SELECT base_currency FROM accounting_entities
                  WHERE tenant_id = :tenant_id AND id = :id AND active = 1'
            );
            $entityStmt->execute(['tenant_id' => $tid, 'id' => (int) $requestedEntityId]);
            $baseCurrency = $entityStmt->fetchColumn();
            if ($baseCurrency === false) throw new InvalidArgumentException('Issuing entity is not available in this workspace');
            $requestedCurrency = array_key_exists('currency', $body)
                ? $body['currency'] : $locked['currency'];
            if (!is_string($requestedCurrency) || $requestedCurrency !== (string) $baseCurrency) {
                throw new InvalidArgumentException('Invoice currency must match the issuing entity');
            }
        }
        if (array_key_exists('issue_date', $body) || array_key_exists('due_date', $body)) {
            $issueDate = array_key_exists('issue_date', $body)
                ? $body['issue_date'] : $locked['issue_date'];
            $dueDate = array_key_exists('due_date', $body)
                ? $body['due_date'] : $locked['due_date'];
            if (array_key_exists('due_date', $body) && ($dueDate === null || $dueDate === '')) {
                $terms = (string) ($locked['payment_terms'] ?? 'NET30');
                $netDays = $terms === 'DUE_ON_RECEIPT'
                    ? 0 : (preg_match('/^NET([0-9]+)$/D', $terms, $matches) ? (int) $matches[1] : 30);
                $parsedIssue = is_string($issueDate) ? DateTimeImmutable::createFromFormat('!Y-m-d', $issueDate) : false;
                if (!$parsedIssue || $parsedIssue->format('Y-m-d') !== $issueDate) {
                    throw new InvalidArgumentException('Issue date must use YYYY-MM-DD');
                }
                $dueDate = $parsedIssue->modify("+{$netDays} days")->format('Y-m-d');
                $binds['due_date'] = $dueDate;
            }
            foreach (['issue_date' => $issueDate, 'due_date' => $dueDate] as $field => $value) {
                $parsed = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
                if (!$parsed || $parsed->format('Y-m-d') !== $value) {
                    throw new InvalidArgumentException(ucfirst(str_replace('_', ' ', $field)) . ' must use YYYY-MM-DD');
                }
            }
            if ($dueDate < $issueDate) throw new InvalidArgumentException('Due date cannot precede issue date');
        }
        if (array_key_exists('client_name', $body) || array_key_exists('client_company_id', $body)) {
            $clientName = trim((string) ($body['client_name'] ?? $locked['client_name']));
            $clientCompanyId = billingResolveDirectInvoiceClientCompanyId(
                $pdo, $clientCatalogTenantId, $clientName,
                $body['client_company_id'] ?? null, (int) ($user['id'] ?? 0) ?: null
            );
            if (!in_array('client_company_id = :client_company_id', $sets, true)) {
                $sets[] = 'client_company_id = :client_company_id';
            }
            $binds['client_company_id'] = $clientCompanyId;
            if (array_key_exists('client_name', $body)) $binds['client_name'] = $clientName;
        }
        $pdo->prepare('UPDATE billing_invoices SET ' . implode(',', $sets) . ' WHERE tenant_id = :tenant_scope AND id = :id')
            ->execute($binds);
        if ($computed) {
            $pdo->prepare('DELETE FROM billing_invoice_lines WHERE invoice_id = :id')->execute(['id' => $id]);
            billingInsertDirectInvoiceLines($pdo, $id, $computed['lines']);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof InvalidArgumentException) api_error($e->getMessage(), 422);
        if ($e instanceof DomainException) api_error($e->getMessage(), 409);
        throw $e;
    }
    $changedFields = array_keys(array_intersect_key($body, array_flip($editable)));
    if ($computed) $changedFields[] = 'lines';
    billingAudit('billing.invoice.updated', ['invoice_id' => $id, 'fields' => $changedFields], $id);
    api_ok(['ok' => true]);
}

if ($method === 'POST' && $action === 'request_approval') {
    rbac_legacy_require($user, 'billing.invoice.draft');
    if (!RBACResolver::can($user, $tid, 'billing', 'write')) {
        api_error('Billing invoice draft access is required.', 403);
    }
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('Choose a valid invoice.', 422);
    $pdo = getDB();
    $owns = !$pdo->inTransaction();
    $savepoint = $owns ? null : 'billing_request_' . bin2hex(random_bytes(4));
    if ($owns) $pdo->beginTransaction();
    else $pdo->exec('SAVEPOINT ' . $savepoint);
    try {
        $rowStmt = $pdo->prepare('SELECT * FROM billing_invoices
            WHERE tenant_id = :tenant_id AND id = :id FOR UPDATE');
        $rowStmt->execute(['tenant_id' => $tid, 'id' => $id]);
        $invoice = $rowStmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) throw new OutOfBoundsException('Invoice not found.');
        if ($invoice['status'] !== 'draft') {
            throw new DomainException('Only a draft invoice can request approval.');
        }
        $instanceId = billingInvoiceWorkflowPendingInstanceId($tid, $id);
        $requested = false;
        if ($instanceId <= 0) {
            $prior = billingInvoiceWorkflowLatestAttempt($tid, $id);
            if ($prior && $prior['status'] !== WORKFLOW_STATUS_REJECTED) {
                throw new DomainException('This invoice already has a completed approval.');
            }
            $routing = billingInvoiceApprovalRouting($tid, $invoice);
            $blocked = billingInvoiceWorkflowSodBlockedUserIds($invoice, (int) $user['id']);
            if (empty($routing['infrastructure_available']) || empty($routing['workflow_required'])
                || !billingInvoiceHasIndependentApprover($tid, (array) $routing['requirements'], $blocked)
                || billingInvoiceManagedReviewerSnapshot($tid, $blocked) === []) {
                throw new DomainException('Configure an active, independent invoice reviewer before requesting approval.');
            }
            $instanceId = (int) (billingInvoiceWorkflowStart($tid, $id, (int) $user['id']) ?? 0);
            if ($instanceId <= 0) throw new RuntimeException('Invoice approval could not start.');
            billingWorkflowAudit($tid, (int) $user['id'], 'billing.invoice.approval_requested', [
                'invoice_id' => $id, 'workflow_instance_id' => $instanceId, 'source' => 'billing',
            ], $id);
            $requested = true;
        }
        if ($owns) $pdo->commit();
        else $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        api_ok(['ok' => true, 'approval_requested' => $requested,
            'workflow_instance_id' => $instanceId], 202);
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
        elseif (!$owns && $pdo->inTransaction()) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        }
        if ($e instanceof OutOfBoundsException) api_error($e->getMessage(), 404);
        if ($e instanceof DomainException) api_error($e->getMessage(), 409);
        error_log('[billing.invoice.request_approval] ' . $e->getMessage());
        api_error('Invoice approval could not start. Try again or contact an administrator.', 503);
    }
}

if ($method === 'POST' && $action === 'approve') {
    rbac_legacy_require($user, 'billing.invoice.approve');
    $id  = (int) ($_GET['id'] ?? 0);
    $row = scopedFind('SELECT * FROM billing_invoices WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    if (!billingTransitionAllowed($row['status'], 'approved')) api_error("Cannot approve from status {$row['status']}", 409);
    // A configured approval policy owns routing and separation of duties.
    // Without one, an authorized billing user can approve the draft directly.
    try {
        $workflow = billingInvoiceWorkflowAct($tid, $id, (int) ($user['id'] ?? 0), 'approve');
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        $code = str_contains($msg, 'Separation of duties')
            || str_contains($msg, 'not an approver')
            || str_contains($msg, 'approval access is required') ? 403 : 409;
        api_error($msg, $code);
    }
    if (empty($workflow['applied'])) {
        api_error('Could not apply billing invoice approval workflow', 503);
    }
    $updated = $workflow['invoice'] ?? billingInvoiceWorkflowRow($tid, $id) ?? $row;

    // Jaz hook (Slice 3) — enqueue a draft accounting command for the
    // newly approved invoice. Best-effort, no-op when no Jaz wiring;
    // never blocks the approval.
    if (($updated['status'] ?? null) === 'approved') {
        getDB()->prepare(
            'UPDATE placement_economic_obligations SET status = "billed"
              WHERE tenant_id = :tenant_id AND ar_invoice_id = :invoice_id
                AND status = "projected"'
        )->execute(['tenant_id' => $placementsTenantId, 'invoice_id' => $id]);
        require_once __DIR__ . '/../../../core/accounting/command_service.php';
        accountingTryEnqueueDraft($tid, 'invoice', $updated, $user['id'] ?? null);
    }

    api_ok([
        'ok' => true,
        'approved' => ($updated['status'] ?? null) === 'approved',
        'status' => $updated['status'] ?? $row['status'],
        'workflow_instance_id' => $workflow['instance']['id'] ?? ($updated['workflow_instance_id'] ?? null),
        'workflow_status' => $workflow['instance']['status'] ?? null,
    ]);
}

if ($method === 'POST' && $action === 'replace_link') {
    rbac_legacy_require($user, 'billing.invoice.send');
    $id = (int) ($_GET['id'] ?? 0);
    $row = scopedFind('SELECT id FROM billing_invoices WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    $pdo = getDB();
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM billing_invoices
                               WHERE tenant_id = :t AND id = :id FOR UPDATE');
        $stmt->execute(['t' => $tid, 'id' => $id]);
        $locked = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$locked || !empty($locked['opening_cutover_id'])
            || !in_array($locked['status'], ['approved', 'sent', 'partially_paid', 'paid'], true)) {
            throw new DomainException('Only a posted, approved invoice can have a customer link.');
        }
        if (billingDeliveryBlockingAttempt($pdo, $tid, $id)) {
            throw new DomainException('Review the pending invoice email before replacing its customer link.');
        }
        $journalStmt = $pdo->prepare('SELECT entity_id FROM accounting_journal_entries
                                       WHERE tenant_id = :t AND id = :id AND status = "posted"');
        $journalStmt->execute(['t' => $tid, 'id' => (int) $locked['journal_entry_id']]);
        $journalEntityId = $journalStmt->fetchColumn();
        $entityId = billingInvoiceDeliveryEntityId($tid, $locked);
        if (!$entityId || (int) $journalEntityId !== $entityId) {
            throw new DomainException('Invoice legal entity does not match its posted journal.');
        }
        $tok = billingIssueViewToken($tid, $id);
        $oldLinks = billingRevokeInvoiceViewTokens(
            $tid, $id, (int) ($user['id'] ?? 0) ?: null, $tok['token_id']
        );
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof DomainException) api_error($e->getMessage(), 409);
        error_log('[billing invoice link] Replacement failed: ' . $e->getMessage());
        api_error('Could not create a new invoice link.', 500);
    }
    billingAudit('billing.invoice.link_replaced', [
        'invoice_id' => $id, 'invoice_number' => $locked['invoice_number'],
        'token_id' => $tok['token_id'], 'prior_links_revoked' => $oldLinks,
    ], $id);
    api_ok(['ok' => true, 'token_id' => $tok['token_id'], 'url' => $tok['url'],
        'expires_in_days' => 90, 'prior_links_revoked' => $oldLinks]);
}

if ($method === 'POST' && $action === 'revoke_link') {
    rbac_legacy_require($user, 'billing.invoice.send');
    $id = (int) ($_GET['id'] ?? 0);
    $row = scopedFind('SELECT id, invoice_number FROM billing_invoices WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    $reason = trim((string) (api_json_body()['reason'] ?? ''));
    if (strlen($reason) < 3 || strlen($reason) > 500) api_error('Give a short reason (3–500 characters) for disabling the link.', 422);
    $pdo = getDB();
    try {
        $pdo->beginTransaction();
        billingDeliveryLockInvoice($pdo, $tid, $id);
        if (billingDeliveryBlockingAttempt($pdo, $tid, $id)) {
            throw new DomainException('Review the pending invoice email before disabling its customer link.');
        }
        $revoked = billingRevokeInvoiceViewTokens($tid, $id, (int) ($user['id'] ?? 0) ?: null);
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof DomainException) api_error($e->getMessage(), 409);
        error_log('[billing invoice link] Revocation failed: ' . $e->getMessage());
        api_error('Could not disable the invoice link.', 500);
    }
    billingAudit('billing.invoice.links_revoked', [
        'invoice_id' => $id, 'invoice_number' => $row['invoice_number'],
        'token_count' => $revoked, 'reason' => $reason,
    ], $id);
    api_ok(['ok' => true, 'revoked_count' => $revoked]);
}

if ($method === 'POST' && $action === 'resolve_send') {
    rbac_legacy_require($user, 'billing.invoice.send');
    $id = (int) ($_GET['id'] ?? 0);
    $row = scopedFind('SELECT id, invoice_number FROM billing_invoices WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    $body = api_json_body();
    $tokenId = (int) ($body['token_id'] ?? 0);
    $outcome = (string) ($body['outcome'] ?? '');
    $reason = trim((string) ($body['reason'] ?? ''));
    $providerId = trim((string) ($body['provider_message_id'] ?? ''));
    if ($tokenId <= 0 || !in_array($outcome, ['accepted', 'not_accepted'], true)) {
        api_error('Select a delivery attempt and confirm whether the mail provider accepted it.', 422);
    }
    if (strlen($reason) < 10 || strlen($reason) > 500 || strlen($providerId) > 255) {
        api_error('Record a review reason of 10-500 characters and a provider reference under 256 characters.', 422);
    }
    try {
        $revoked = billingDeliveryResolve($tid, $id, $tokenId, $outcome,
            $providerId !== '' ? $providerId : null, (int) ($user['id'] ?? 0) ?: null);
    } catch (\Throwable $e) {
        if ($e instanceof DomainException || $e instanceof InvalidArgumentException) {
            api_error($e->getMessage(), 409);
        }
        error_log('[billing invoice delivery] Resolution failed: ' . $e->getMessage());
        api_error('Could not resolve the invoice delivery. Contact an administrator.', 500);
    }
    billingAudit('billing.invoice.send_resolved', [
        'invoice_id' => $id, 'invoice_number' => $row['invoice_number'],
        'token_id' => $tokenId, 'outcome' => $outcome, 'reason' => $reason,
        'provider_message_id' => $providerId !== '' ? $providerId : null,
        'prior_links_revoked' => $revoked,
    ], $id);
    api_ok(['ok' => true, 'outcome' => $outcome, 'prior_links_revoked' => $revoked]);
}

if ($method === 'POST' && $action === 'send') {
    rbac_legacy_require($user, 'billing.invoice.send');
    $id  = (int) ($_GET['id'] ?? 0);
    $row = scopedFind('SELECT * FROM billing_invoices WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    if (!empty($row['opening_cutover_id'])) api_error('This is an opening receivable, not a new invoice to send.', 409);
    if (!in_array($row['status'], ['approved', 'sent', 'partially_paid', 'paid'], true)) {
        api_error("Cannot send from status {$row['status']}", 409);
    }
    $postedEntry = !empty($row['journal_entry_id']) ? scopedFind(
        'SELECT id, entity_id FROM accounting_journal_entries
          WHERE tenant_id = :tenant_id AND id = :id AND status = "posted"',
        ['id' => (int) $row['journal_entry_id']]
    ) : null;
    if (!$postedEntry) api_error('Post the invoice to the ledger before sending it', 409);
    $entityId = billingInvoiceDeliveryEntityId($tid, $row);
    if (!$entityId || (int) $postedEntry['entity_id'] !== $entityId) {
        api_error('Invoice legal entity does not match its posted journal.', 409);
    }
    $sender = billingEntityMailSender($tid, $entityId);
    if (!$sender['ready']) api_error((string) $sender['reason'], 422);

    $beforeNormalization = $row;
    try {
        $normalization = billingNormalizeStoredInvoiceAmounts($tid, $id);
        $row = $normalization['invoice'];
    } catch (\Throwable $e) {
        api_error('Invoice totals could not be reconciled: ' . $e->getMessage(), 422);
    }
    if (!empty($normalization['changed'])) {
        billingAudit('billing.invoice.amounts_normalized', [
            'invoice_id' => $id,
            'invoice_number' => $row['invoice_number'],
            'line_changes' => (int) ($normalization['line_changes'] ?? 0),
            'before_total' => (float) $beforeNormalization['total'],
            'after_total' => (float) $row['total'],
            'trigger' => 'send',
        ], $id);
    }

    $body = api_json_body();
    $to = trim((string) ($body['to'] ?? ''));
    if ($to === '') {
        $to = (string) (billingInvoiceDefaultRecipient($tid, $row)['email'] ?? '');
    }
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        api_error('No valid invoice recipient. Add a bill-to email or an AR contact for this legal entity.', 422);
    }
    try {
        $svc = cf_mail_bootstrap();
    } catch (\Throwable $e) {
        error_log('[billing invoice delivery] Mail setup failed: ' . $e->getMessage());
        api_error('Invoice email is not configured. No delivery attempt was started.', 503);
    }

    if (!is_bool($body['resend'] ?? false)) api_error('Resend confirmation must be true or false.', 422);
    try {
        $requestId = billingDeliveryRequestId((string) ($body['request_id'] ?? ''));
        $tok = billingDeliveryReserve($tid, $id, $requestId, $to, $body['resend'] ?? false);
    } catch (\Throwable $e) {
        if ($e instanceof InvalidArgumentException) api_error($e->getMessage(), 422);
        if ($e instanceof OutOfBoundsException) api_error('Not found', 404);
        if ($e instanceof DomainException) api_error($e->getMessage(), 409);
        error_log('[billing invoice delivery] Reservation failed: ' . $e->getMessage());
        api_error('Invoice delivery tracking is unavailable. No email was sent.', 503);
    }
    if (!empty($tok['replayed'])) {
        if ($tok['delivery_status'] === 'sent') {
            api_ok(['ok' => true, 'already_sent' => true, 'token_id' => $tok['token_id'],
                'email_status' => 'sent', 'url' => null]);
        }
        api_error('This delivery request is ' . $tok['delivery_status']
            . '. Review the latest attempt before trying to send again.', 409);
    }
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
        "Hi,\n\nYour invoice %s is %s.\n\nAmount due: %s %s\nDue date: %s\n\nView online: %s\n\nThank you.\n",
        $row['invoice_number'],
        $attachments ? 'attached and available online' : 'available online',
        number_format((float) $row['amount_due'], 2), $row['currency'], $row['due_date'], $tok['url']
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

    try {
        $sendRes = $svc->send($tid, 'billing', 'invoice_sent', [$to], $subject, $textBody, $htmlBody, $attachments, [
            'from' => $sender['from'], 'from_name' => $sender['from_name'], 'reply_to' => $sender['reply_to'],
            'idempotency_key' => 'billing-invoice-' . $id . '-request-' . $requestId,
            'outbox_redactions' => [$tok['url'], $tok['token']],
        ]);
    } catch (\Throwable $e) {
        $sendRes = ['status' => 'failed', 'error' => $e->getMessage()];
    }

    if (($sendRes['status'] ?? 'failed') !== 'sent') {
        $mailError = trim((string) ($sendRes['error'] ?? '')) ?: 'The mail provider did not confirm acceptance.';
        try {
            billingDeliveryMarkUncertain($tid, $id, $tok['token_id'], $requestId, $mailError);
        } catch (\Throwable $e) {
            error_log('[billing invoice delivery] Could not mark uncertain attempt: ' . $e->getMessage());
        }
        billingAudit('billing.invoice.send_uncertain', [
            'invoice_id' => $id, 'invoice_number' => $row['invoice_number'],
            'to' => $to, 'token_id' => $tok['token_id'], 'request_id' => $requestId,
            'email_status' => $sendRes['status'] ?? 'failed',
            'email_error' => $mailError,
            'pdf_attached' => !empty($attachments),
            'pdf_error' => $pdfError,
        ], $id);
        api_error('Invoice delivery could not be confirmed. Check the mail provider before another send.', 502, [
            'email_status' => $sendRes['status'] ?? 'failed',
            'invoice_status' => $row['status'],
            'delivery_status' => 'uncertain', 'retryable' => false,
        ]);
    }

    try {
        $priorLinksRevoked = billingDeliveryFinalize($tid, $id, $tok['token_id'], $requestId,
            isset($sendRes['provider_message_id']) ? (string) $sendRes['provider_message_id'] : null,
            (int) ($user['id'] ?? 0) ?: null);
    } catch (\Throwable $e) {
        try {
            billingDeliveryMarkUncertain($tid, $id, $tok['token_id'], $requestId, $e->getMessage());
        } catch (\Throwable $markError) {
            error_log('[billing invoice delivery] Could not mark accepted attempt uncertain: ' . $markError->getMessage());
        }
        error_log('[billing invoice delivery] Finalization failed after provider accepted email: ' . $e->getMessage());
        api_error('The mail provider accepted the invoice, but the ledger did not finish recording delivery. Review this attempt before another send.', 503, [
            'delivery_status' => 'uncertain', 'retryable' => false,
        ]);
    }
    billingAudit($row['status'] === 'approved' ? 'billing.invoice.sent' : 'billing.invoice.resent', [
        'invoice_id' => $id, 'invoice_number' => $row['invoice_number'],
        'to' => $to, 'token_id' => $tok['token_id'],
        'email_status' => $sendRes['status'] ?? 'unknown',
        'pdf_attached' => !empty($attachments),
        'pdf_error'    => $pdfError,
        'prior_links_revoked' => $priorLinksRevoked,
    ], $id);

    api_ok([
        'ok' => true, 'token_id' => $tok['token_id'], 'url' => $tok['url'],
        'email_status' => $sendRes['status'] ?? 'unknown',
        'email_error'  => $sendRes['error'] ?? null,
        'pdf_attached' => !empty($attachments),
        'pdf_error'    => $pdfError,
        'prior_links_revoked' => $priorLinksRevoked,
    ]);
}

if ($method === 'GET' && $action === 'pdf' && !empty($_GET['id'])) {
    rbac_legacy_require($user, 'billing.view');
    $id  = (int) $_GET['id'];
    $row = scopedFind('SELECT id, invoice_number, opening_cutover_id FROM billing_invoices WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    if (!empty($row['opening_cutover_id'])) api_error('The original invoice PDF was not imported with this opening balance.', 409);

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
    cf_begin_transaction();
    try {
        $locked = $pdo->prepare(
            'SELECT * FROM billing_invoices WHERE tenant_id = :t AND id = :id FOR UPDATE'
        );
        $locked->execute(['t' => $tid, 'id' => $id]);
        $row = $locked->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['status'] !== 'draft') {
            throw new \DomainException('Only draft invoices can be voided here. Posted or approved invoices need a coordinated reversal.');
        }
        if (billingInvoiceWorkflowPendingInstanceId($tid, $id) > 0) {
            throw new \DomainException('This invoice is awaiting approval. Ask a reviewer to reject it before voiding.');
        }

        $allocCount = $pdo->prepare('SELECT COUNT(*) FROM billing_payment_allocations WHERE invoice_id = :id');
        $allocCount->execute(['id' => $id]);
        $hasPayments = (int) $allocCount->fetchColumn() > 0;
        $sourceJe = $pdo->prepare(
            'SELECT id FROM accounting_journal_entries
              WHERE tenant_id = :t AND source_module = "billing"
                AND source_ref_type = "billing_invoice" AND source_ref_id = :id
              LIMIT 1'
        );
        $sourceJe->execute(['t' => $tid, 'id' => $id]);
        if ($hasPayments || (float) $row['amount_paid'] > 0 || !empty($row['journal_entry_id']) || $sourceJe->fetchColumn()) {
            throw new \DomainException('This invoice has ledger or payment activity. Reverse that activity through its source workflow before voiding.');
        }

        $pdo->prepare(
            'UPDATE time_downstream_feed
                 SET status = "ready", consumed_at = NULL, consumed_by_module = NULL, consumed_ref_id = NULL
                 WHERE tenant_id = :t AND consumed_by_module = "billing" AND consumed_ref_id = :id'
        )->execute(['t' => $tid, 'id' => $id]);
        $pdo->prepare(
            'UPDATE time_entries
                    SET bill_extracted_at = NULL,
                        bill_extracted_ref = NULL,
                        bill_extracted_by_user_id = NULL
                  WHERE tenant_id = :t AND bill_extracted_ref = :id'
        )->execute(['t' => $tid, 'id' => $id]);

        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare(
            'UPDATE billing_invoices SET status = "void", amount_due = 0, voided_at = NOW(),
             voided_by_user_id = :u, void_reason = :r WHERE id = :id'
        )->execute(['u' => $user['id'] ?? null, 'r' => $reason, 'id' => $id]);

        $pdo->prepare(
            'UPDATE placement_economic_obligations
                    SET status = "void"
                  WHERE tenant_id = :tenant_id AND ar_invoice_id = :invoice_id'
        )->execute(['tenant_id' => $placementsTenantId, 'invoice_id' => $id]);

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof \DomainException) api_error($e->getMessage(), 409);
        throw $e;
    }

    billingAudit('billing.invoice.voided', [
        'invoice_id' => $id, 'invoice_number' => $row['invoice_number'],
        'reason' => $reason, 'had_payments' => $hasPayments,
    ], $id, [
        'before' => $row,
        'after' => scopedFind('SELECT * FROM billing_invoices WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]) ?? $row,
    ]);
    api_ok(['ok' => true, 'bundles_released' => true]);
}

if ($method === 'POST' && $action === 'post') {
    // Post the invoice to GL:
    //   Dr  Accounts Receivable (1100)
    //   Cr  Revenue             (4000)
    //   Cr  Sales Tax Payable   (2100)   [only if tax_total > 0]
    // Idempotent on billing:invoice:<id>:post.
    rbac_legacy_require($user, 'billing.invoice.post');
    $id  = (int) ($_GET['id'] ?? 0);
    $row = scopedFind('SELECT * FROM billing_invoices WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    if (!in_array($row['status'], ['approved','sent','partially_paid','paid'], true)) {
        api_error("Cannot post from status {$row['status']}", 409);
    }
    if (!empty($row['journal_entry_id'])) {
        $existingJe = scopedFind(
            'SELECT id, je_number, status FROM accounting_journal_entries
              WHERE tenant_id = :tenant_id AND id = :id',
            ['id' => (int) $row['journal_entry_id']]
        );
        if (($existingJe['status'] ?? null) !== 'posted') {
            api_error('This invoice already points to a missing or unposted journal entry. Correct that link before retrying.', 409);
        }
        api_ok([
            'ok' => true,
            'journal_entry_id' => (int) $existingJe['id'],
            'je_number' => $existingJe['je_number'],
            'idempotent_replay' => true,
        ]);
    }

    $beforeNormalization = $row;
    try {
        $normalization = billingNormalizeStoredInvoiceAmounts($tid, $id);
        $row = $normalization['invoice'];
    } catch (\Throwable $e) {
        api_error('Invoice totals could not be reconciled: ' . $e->getMessage(), 422);
    }
    if (!empty($normalization['changed'])) {
        billingAudit('billing.invoice.amounts_normalized', [
            'invoice_id' => $id,
            'invoice_number' => $row['invoice_number'],
            'line_changes' => (int) ($normalization['line_changes'] ?? 0),
            'before_total' => (float) $beforeNormalization['total'],
            'after_total' => (float) $row['total'],
            'trigger' => 'post',
        ], $id);
    }
    require_once __DIR__ . '/../../accounting/lib/accounting.php';
    require_once __DIR__ . '/../../accounting/lib/dimensions.php';
    require_once __DIR__ . '/../../accounting/lib/multi_period.php';
    require_once __DIR__ . '/../../../core/posting_engine/process.php';
    require_once __DIR__ . '/../../staffing/lib/dimensions.php';

    // Accrual-at-approval reclassification gate (2026-02). When the
    // tenant has `multi_period_split_enabled=1`, revenue + AR Unbilled
    // were already recognised by `accountingPostBundleAccrual()` at
    // timesheet/bundle approval time. The invoice post becomes a pure
    // RECLASSIFICATION (single JE on issue_date):
    //   Dr  Accounts Receivable (full total)
    //   Cr  AR Unbilled         (subtotal — clears the accrual)
    //   Cr  Sales Tax Payable   (tax, if any)
    // No revenue line — recognition is owned by the bundle accrual.
    // Tenants with the flag OFF keep the legacy "recognise at invoice"
    // flow below (event-layer first, then direct post).
    $settings = accountingSettingsGet($tid);
    $sourceStmt = getDB()->prepare(
        'SELECT COUNT(*) AS line_count,
                SUM(source_type IN ("time", "time_entry", "economic_item")) AS accrued_line_count
           FROM billing_invoice_lines WHERE invoice_id = :id'
    );
    $sourceStmt->execute(['id' => $id]);
    $sourceCounts = $sourceStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $allLinesWereAccrued = (int) ($sourceCounts['line_count'] ?? 0) > 0
        && (int) ($sourceCounts['line_count'] ?? 0) === (int) ($sourceCounts['accrued_line_count'] ?? 0);
    $reclassifyOnly = !empty($settings['multi_period_split_enabled']) && $allLinesWereAccrued;

    $subtotal = (float) $row['subtotal'];
    $taxTotal = (float) $row['tax_total'];
    $total    = (float) $row['total'];
    $party    = !empty($row['client_company_id']) ? (int) $row['client_company_id'] : null;
    try {
        $postingEntity = activeEntityResolveForTenant(
            $tid,
            !empty($row['entity_id']) ? (int) $row['entity_id'] : null
        );
    } catch (\Throwable $e) {
        api_error($e->getMessage(), 422);
    }
    if (!$postingEntity) api_error('An active issuing entity is required before this invoice can post', 422);
    $documentEntityId = (int) $postingEntity['id'];
    if (empty($row['entity_id'])) {
        getDB()->prepare('UPDATE billing_invoices SET entity_id = :entity_id WHERE tenant_id = :tenant_id AND id = :id')
            ->execute(['entity_id' => $documentEntityId, 'tenant_id' => $tid, 'id' => $id]);
        $row['entity_id'] = $documentEntityId;
    }
    $documentDimensions = array_filter([
        'client' => $party,
        'legal_entity' => $documentEntityId > 0 ? $documentEntityId : null,
    ], static fn(mixed $value): bool => $value !== null && $value !== '');

    // Group revenue per gl_revenue_account_code so non-labor lines land in
    // their own account (e.g. 4100 Reimbursable, 4200 Materials, 4300 SOW
    // Fees). Lines without an override fall back to 4000 Revenue.
    $pdo = getDB();
    $linesStmt = $pdo->prepare(
        'SELECT item_type, gl_revenue_account_code, COALESCE(placement_id, 0) AS placement_id,
                SUM(subtotal) AS s
         FROM billing_invoice_lines WHERE invoice_id = :id
         GROUP BY item_type, gl_revenue_account_code, placement_id'
    );
    $linesStmt->execute(['id' => $id]);
    $revenueBuckets = [];
    $placementSubtotals = [];
    $placementAccountCodes = [];
    foreach ($linesStmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
        $code = $r['gl_revenue_account_code'] ?: '4000';
        $placementId = (int) ($r['placement_id'] ?? 0);
        $bucketKey = $placementId . ':' . $code;
        if (!isset($revenueBuckets[$bucketKey])) {
            $revenueBuckets[$bucketKey] = [
                'placement_id' => $placementId,
                'account_code' => $code,
                'amount' => 0.0,
            ];
        }
        $revenueBuckets[$bucketKey]['amount'] += (float) $r['s'];
        $placementSubtotals[$placementId] = ($placementSubtotals[$placementId] ?? 0) + (float) $r['s'];
        if ($placementId > 0) $placementAccountCodes[$placementId][$code] = true;
    }
    if (!$revenueBuckets) {
        $revenueBuckets['0:4000'] = ['placement_id' => 0, 'account_code' => '4000', 'amount' => $subtotal];
        $placementSubtotals[0] = $subtotal;
    }

    $placementContexts = [];
    foreach (array_keys($placementSubtotals) as $placementId) {
        $placementId = (int) $placementId;
        if ($placementId <= 0) continue;
        try {
            $placementContexts[$placementId] = staffingAssignmentDimensionContext(
                $tid,
                $placementId,
                $documentEntityId,
                (string) $row['issue_date']
            );
        } catch (\Throwable $e) {
            api_error("Invoice {$row['invoice_number']} could not resolve reporting dimensions for placement #{$placementId}: " . $e->getMessage(), 422);
        }
        $accountRequired = accountingRequiredDimensionKeysForAccountCodes(
            $tid,
            array_keys($placementAccountCodes[$placementId] ?? [])
        );
        $blockingMissing = staffingDimensionBlockingMissing(
            $placementContexts[$placementId],
            'billing',
            $accountRequired
        );
        if ($blockingMissing) {
            api_error(
                "Complete placement #{$placementId} before posting invoice {$row['invoice_number']}: missing "
                . implode(', ', staffingDimensionMissingLabels($blockingMissing)),
                422
            );
        }
        $placementEntityId = (int) ($placementContexts[$placementId]['event_entity_id'] ?? 0);
        if ($documentEntityId > 0 && $placementEntityId > 0 && $placementEntityId !== $documentEntityId) {
            api_error("Invoice {$row['invoice_number']} contains placement #{$placementId} from a different legal entity", 422);
        }
        $placementClientCompanyId = (int) ($placementContexts[$placementId]['placement']['end_client_company_id'] ?? 0)
            ?: (int) ($placementContexts[$placementId]['placement']['staffing_client_company_id'] ?? 0);
        if ($party && $placementClientCompanyId > 0 && $placementClientCompanyId !== $party) {
            api_error(
                "Invoice {$row['invoice_number']} is for a different client than placement #{$placementId}",
                422
            );
        }
    }

    $lines = [
        ['account_code' => '1100', 'debit' => $total, 'credit' => 0, 'memo' => "Inv {$row['invoice_number']} / {$row['client_name']}", 'counterparty_company_id' => $party, 'dims' => $documentDimensions],
    ];
    foreach ($revenueBuckets as $bucket) {
        $amt = round((float) $bucket['amount'], 2);
        if (abs($amt) <= 0.005) continue;
        $placementId = (int) $bucket['placement_id'];
        $lineDimensions = $placementId > 0
            ? (array) ($placementContexts[$placementId]['dimensions'] ?? [])
            : $documentDimensions;
        $lines[] = [
            'account_code' => (string) $bucket['account_code'],
            'debit' => $amt < 0 ? abs($amt) : 0,
            'credit' => $amt > 0 ? $amt : 0,
            'memo' => "Revenue — {$row['invoice_number']}",
            'counterparty_company_id' => $party,
            'dims' => $lineDimensions,
        ];
    }
    if ($taxTotal > 0.005) {
        $lines[] = ['account_code' => '2100', 'debit' => 0, 'credit' => $taxTotal, 'memo' => "Sales tax — {$row['invoice_number']}", 'counterparty_company_id' => $party, 'dims' => $documentDimensions];
    }

    // When the work was accrued at approval, issue-time posting must clear
    // AR Unbilled instead of recognizing revenue a second time. Split the
    // clearing lines by placement so the balance-sheet roll-forward retains
    // the same assignment trail as the original accrual.
    $reclassLines = [];
    if ($reclassifyOnly) {
        accountingEnsureAccrualAccounts($tid, $settings);
        $arUnbilled = (string) $settings['ar_unbilled_account_code'];
        $reclassLines[] = [
            'account_code' => '1100',
            'debit' => round($total, 2),
            'credit' => 0,
            'memo' => "Inv {$row['invoice_number']} / {$row['client_name']}",
            'counterparty_company_id' => $party,
            'dims' => $documentDimensions,
        ];
        foreach ($placementSubtotals as $placementId => $amount) {
            $amount = round((float) $amount, 2);
            if (abs($amount) <= 0.005) continue;
            $placementId = (int) $placementId;
            $lineDimensions = $placementId > 0
                ? (array) ($placementContexts[$placementId]['dimensions'] ?? [])
                : $documentDimensions;
            $reclassLines[] = [
                'account_code' => $arUnbilled,
                'debit' => $amount < 0 ? abs($amount) : 0,
                'credit' => $amount > 0 ? $amount : 0,
                'memo' => "Clear AR Unbilled — {$row['invoice_number']}",
                'counterparty_company_id' => $party,
                'dims' => $lineDimensions,
            ];
        }
        if ($taxTotal > 0.005) {
            $reclassLines[] = [
                'account_code' => '2100',
                'debit' => 0,
                'credit' => round($taxTotal, 2),
                'memo' => "Sales tax — {$row['invoice_number']}",
                'counterparty_company_id' => $party,
                'dims' => $documentDimensions,
            ];
        }
    }
    $eventPostingLines = $reclassifyOnly ? $reclassLines : $lines;

    // Sprint 7e — preferred path: emit billing.invoice.sent into the
    // posting engine. Falls back to the legacy direct accountingPostJe()
    // call when no rule has been seeded for this tenant.
    $payloadLines = [];
    foreach ($eventPostingLines as $l) {
        $payloadLines[] = [
            'account_code' => $l['account_code'],
            'debit'        => (float) ($l['debit']  ?? 0),
            'credit'       => (float) ($l['credit'] ?? 0),
            'description'  => $l['memo'] ?? null,
            'counterparty_company_id' => $l['counterparty_company_id'] ?? null,
            'dims'          => (array) ($l['dims'] ?? []),
        ];
    }
    $eventResult = null; $eventError = null;
    try {
        $eventResult = accountingProcessEvent($tid, [
            'entity_id'        => $documentEntityId,
            'event_type'       => 'billing.invoice.sent',
            'source_module'    => 'billing',
            'source_record_id' => 'billing_invoice:' . $id,
            'event_date'       => (string) $row['issue_date'],
            'payload'          => [
                'invoice_id'     => (int) $id,
                'invoice_number' => (string) $row['invoice_number'],
                'client_name'    => (string) $row['client_name'],
                'client_company_id' => $party,
                'total'          => (float) $row['total'],
                'amount'         => (float) $row['total'],
                'currency'       => (string) $row['currency'],
                'due_date'       => (string) $row['due_date'],
                'posting_mode'   => $reclassifyOnly ? 'ar_reclassification' : 'invoice_recognition',
                'dimensions'     => $documentDimensions,
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
        $posted = scopedFind('SELECT * FROM billing_invoices WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]) ?? $row;
        billingAudit('billing.invoice.posted', [
            'invoice_id' => $id, 'invoice_number' => $row['invoice_number'],
            'journal_entry_id' => (int) $eventResult['journal_entry_id'],
            'accounting_event_id' => (int) ($eventResult['event_id'] ?? 0),
            'idempotent_replay' => !empty($eventResult['idempotent_replay']),
            'via' => 'event_layer',
        ], $id, [
            'before' => $row,
            'after' => $posted,
        ]);
        api_ok([
            'ok' => true,
            'journal_entry_id' => (int) $eventResult['journal_entry_id'],
            'je_number' => $eventResult['je_number'] ?? null,
            'idempotent_replay' => !empty($eventResult['idempotent_replay']),
            'accounting_event_id' => (int) ($eventResult['event_id'] ?? 0),
            'via' => 'event_layer',
        ]);
    }

    // Reclassification branch (accrual-at-approval model). When the
    // tenant flag is ON, revenue + AR Unbilled were already posted by
    // the bundle-accrual hook at timesheet approval time, so the
    // invoice post is a pure AR reclassification — no revenue, no
    // expense, no multi-period batching. The event layer above receives
    // this same reclassification shape; this branch is its direct-post
    // fallback when no event rule is available.
    if ($reclassifyOnly) {
        $pdo_mp = getDB();
        try {
            $resRc = accountingPostJe($tid, [
                'entity_id'       => $documentEntityId,
                'posting_date'    => $row['issue_date'],
                'currency'        => $row['currency'],
                'source_module'   => 'billing',
                'source_ref_type' => 'billing_invoice',
                'source_ref_id'   => $id,
                'idempotency_key' => sprintf('billing:invoice:%d:post:reclass', $id),
                'memo'            => "Reclassify AR Unbilled → AR — Inv {$row['invoice_number']}",
                'lines'           => $reclassLines,
            ], $user['id'] ?? null, true);
        } catch (\Throwable $e) {
            api_error('AR reclassification post failed: ' . $e->getMessage(), 422);
        }
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo_mp->prepare('UPDATE billing_invoices SET journal_entry_id = :j WHERE id = :id')
            ->execute(['j' => (int) $resRc['je_id'], 'id' => $id]);
        $posted = scopedFind('SELECT * FROM billing_invoices WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]) ?? $row;
        billingAudit('billing.invoice.posted', [
            'invoice_id' => $id, 'invoice_number' => $row['invoice_number'],
            'journal_entry_id' => (int) $resRc['je_id'],
            'idempotent_replay' => (bool) ($resRc['idempotent_replay'] ?? false),
            'via' => 'ar_reclassification',
        ], $id, [
            'before' => $row,
            'after' => $posted,
        ]);
        api_ok([
            'ok' => true,
            'journal_entry_id'  => (int) $resRc['je_id'],
            'je_number'         => $resRc['je_number'] ?? null,
            'idempotent_replay' => (bool) ($resRc['idempotent_replay'] ?? false),
            'via'               => 'ar_reclassification',
        ]);
    }

    try {
        $res = accountingPostJe($tid, [
            'entity_id'       => $documentEntityId,
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
    $posted = scopedFind('SELECT * FROM billing_invoices WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]) ?? $row;

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
    ], $id, [
        'before' => $row,
        'after' => $posted,
    ]);
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
    rbac_legacy_require($user, 'billing.invoice.post');
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
    $posted = scopedFind('SELECT * FROM billing_invoices WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]) ?? $row;

    billingAudit('billing.invoice.posted_ic', [
        'invoice_id'           => $id, 'invoice_number' => $row['invoice_number'],
        'journal_entry_id'     => (int) $sourceLeg['je_id'],
        'intercompany_group_id'=> $res['group_id'],
        'leg_count'            => count($res['jes']),
    ], $id, [
        'before' => $row,
        'after' => $posted,
    ]);

    api_ok(['ok' => true, 'journal_entry_id' => (int) $sourceLeg['je_id'], 'intercompany_group_id' => $res['group_id'], 'jes' => $res['jes']]);
}

api_error('Method not allowed', 405);

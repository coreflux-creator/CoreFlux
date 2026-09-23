<?php
/** Real HTTP + MySQL time-to-cash workflow. Simulation tenant and log-only mail. */
declare(strict_types=1);

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/tenant_scope.php';
require_once __DIR__ . '/../modules/staffing/lib/posting_rules_seed.php';

$tenantId = (int) (getenv('SIM_TENANT_ID') ?: 0);
$base = rtrim((string) getenv('SIM_API_BASE'), '/');
if ($tenantId <= 0 || $base === '' || getenv('SIM_MODE') !== '1') {
    throw new RuntimeException('Simulation tenant and local API are required');
}
$pdo = getDB();
$check = $pdo->prepare('SELECT is_simulation FROM tenants WHERE id = :id');
$check->execute(['id' => $tenantId]);
if ((int) $check->fetchColumn() !== 1) {
    throw new RuntimeException('Refusing to write to a non-simulation tenant');
}
setRequestTenantId($tenantId);
staffingSeedPostingRules($tenantId);

$pdo->prepare(
    "INSERT INTO people (id, tenant_id, first_name, last_name, email_primary, classification, entity_id)
     VALUES (9701, :t, 'Jordan', 'Lee', 'ci-csv-worker@coreflux.test', 'w2', 1)"
)->execute(['t' => $tenantId]);
$pdo->prepare(
    "INSERT INTO companies (id, tenant_id, name) VALUES (9701, :t, 'CSV Billing Client')"
)->execute(['t' => $tenantId]);
$pdo->prepare(
    "INSERT INTO placements
        (id, tenant_id, person_id, status, start_date, engagement_type, title,
         end_client_company_id, end_client_name, accounting_entity_id,
         worksite_state, workers_comp_class, branch)
     VALUES
        (9701, :t, 9701, 'active', '2026-01-01', 'w2', 'Data Consultant',
         9701, 'CSV Billing Client', 1, 'NY', '8810', 'East')"
)->execute(['t' => $tenantId]);
$pdo->prepare(
    "INSERT INTO placement_rates
        (id, tenant_id, placement_id, effective_from, bill_rate, pay_rate,
         adjusted_bill_rate, approved_at)
     VALUES (9701, :t, 9701, '2026-01-01', 100, 60, 100, NOW())"
)->execute(['t' => $tenantId]);

$curl = curl_init();
if ($curl === false) throw new RuntimeException('Could not initialize HTTP client');
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => '',
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_TIMEOUT => 90,
]);

function ciBusinessApi(CurlHandle $curl, string $base, string $method, string $path, ?array $body = null): array
{
    curl_setopt($curl, CURLOPT_URL, $base . $path);
    curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    if ($method === 'POST') {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body ?? [], JSON_THROW_ON_ERROR));
    } else {
        curl_setopt($curl, CURLOPT_POSTFIELDS, null);
        curl_setopt($curl, CURLOPT_HTTPGET, true);
    }
    $raw = curl_exec($curl);
    if ($raw === false) throw new RuntimeException('API request failed: ' . curl_error($curl));
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $json = json_decode((string) $raw, true);
    if (!is_array($json)) throw new RuntimeException("Non-JSON API response ({$status}): " . substr((string) $raw, 0, 500));
    return [$status, $json];
}

function ciBusinessExpect(int $actual, int $expected, array $body, string $step): void
{
    if ($actual !== $expected) {
        throw new RuntimeException("{$step}: expected HTTP {$expected}, got {$actual}: " . json_encode($body));
    }
}

try {
    $ready = false;
    for ($attempt = 0; $attempt < 20; $attempt++) {
        try {
            [$status] = ciBusinessApi($curl, $base, 'GET', '/__ci__/session?actor=uploader');
            if ($status === 200) { $ready = true; break; }
        } catch (RuntimeException $_) {
            usleep(250000);
        }
    }
    if (!$ready) throw new RuntimeException('Local business API did not start');

    $csv = "Placement ID,Work date,Hours\nPL-9701,2026-01-13,6\nPL-9701,2026-01-14,4\n";
    [$status, $dry] = ciBusinessApi($curl, $base, 'POST', '/modules/time/api/csv_import.php?action=dry_run', ['csv' => $csv]);
    ciBusinessExpect($status, 200, $dry, 'CSV preview');
    if (($dry['error_count'] ?? -1) !== 0 || ($dry['row_count'] ?? 0) !== 2) {
        throw new RuntimeException('CSV preview rejected valid placement/date/hours rows: ' . json_encode($dry));
    }

    [$status, $import] = ciBusinessApi($curl, $base, 'POST', '/modules/time/api/csv_import.php?action=commit', ['csv' => $csv]);
    ciBusinessExpect($status, 200, $import, 'CSV import');
    $entryIds = array_values(array_map('intval', $import['ids'] ?? []));
    $timesheetId = (int) ($import['timesheet_ids'][0] ?? 0);
    if (($import['imported_count'] ?? 0) !== 2 || count($entryIds) !== 2
        || count($import['timesheet_ids'] ?? []) !== 1 || $timesheetId <= 0) {
        throw new RuntimeException('CSV did not create two entries in one weekly timesheet: ' . json_encode($import));
    }

    $header = $pdo->prepare('SELECT status, artifact_id, total_hours, origin_source FROM staffing_timesheets WHERE tenant_id = :t AND id = :id');
    $header->execute(['t' => $tenantId, 'id' => $timesheetId]);
    $week = $header->fetch(PDO::FETCH_ASSOC);
    if (!$week || $week['status'] !== 'submitted' || !$week['artifact_id']
        || (float) $week['total_hours'] !== 10.0 || $week['origin_source'] !== 'bulk_upload') {
        throw new RuntimeException('Imported week is not a submitted, first-class timesheet: ' . json_encode($week));
    }

    [$status, $actor] = ciBusinessApi($curl, $base, 'GET', '/__ci__/session?actor=approver');
    ciBusinessExpect($status, 200, $actor, 'Switch to independent approver');
    [$status, $approval] = ciBusinessApi($curl, $base, 'POST', '/modules/staffing/api/timesheets.php?action=bulk_approve', ['ids' => [$timesheetId]]);
    ciBusinessExpect($status, 200, $approval, 'Timesheet approval');
    if (($approval['approved'] ?? 0) !== 1 || !empty($approval['posting_warnings'])) {
        throw new RuntimeException('Imported timesheet was not approved and posted: ' . json_encode($approval));
    }
    $header->execute(['t' => $tenantId, 'id' => $timesheetId]);
    if (($header->fetch(PDO::FETCH_ASSOC)['status'] ?? '') !== 'approved') {
        throw new RuntimeException('Imported timesheet did not reach approved status');
    }

    [$status, $created] = ciBusinessApi($curl, $base, 'POST', '/modules/billing/api/invoices.php?action=from-time-entries', [
        'time_entry_ids' => $entryIds,
        'aggregation' => 'per_placement',
    ]);
    ciBusinessExpect($status, 201, $created, 'Create invoice from imported time');
    $invoices = $created['invoices_created'] ?? [];
    $invoiceId = (int) ($invoices[0]['id'] ?? 0);
    if (count($invoices) !== 1 || $invoiceId <= 0 || (float) ($invoices[0]['total'] ?? 0) !== 1000.0) {
        throw new RuntimeException('Imported hours did not create one $1,000 invoice: ' . json_encode($created));
    }

    [$status, $detail] = ciBusinessApi($curl, $base, 'GET', '/modules/billing/api/invoices.php?id=' . $invoiceId);
    ciBusinessExpect($status, 200, $detail, 'Open invoice');
    $invoice = $detail['invoice'] ?? [];
    $lines = $detail['lines'] ?? [];
    if (($invoice['status'] ?? '') !== 'draft' || (float) ($invoice['total'] ?? 0) !== 1000.0
        || (float) ($invoice['amount_due'] ?? 0) !== 1000.0 || count($lines) !== 2) {
        throw new RuntimeException('Invoice detail lost the imported time or amounts: ' . json_encode($detail));
    }
    $lineRefs = array_map('intval', array_column($lines, 'source_ref_id'));
    sort($lineRefs);
    sort($entryIds);
    if ($lineRefs !== $entryIds || array_column($lines, 'source_type') !== ['time_entry', 'time_entry']
        || array_sum(array_map('floatval', array_column($lines, 'quantity'))) !== 10.0
        || array_sum(array_map('floatval', array_column($lines, 'total'))) !== 1000.0) {
        throw new RuntimeException('Invoice lines do not trace and price both CSV rows: ' . json_encode($lines));
    }
    $entryStmt = $pdo->prepare(
        'SELECT status, timesheet_id, rate_snapshot_id, bill_extracted_ref, bill_extracted_at
           FROM time_entries WHERE tenant_id = ? AND id IN (?, ?) ORDER BY id'
    );
    $entryStmt->execute([$tenantId, ...$entryIds]);
    foreach ($entryStmt->fetchAll(PDO::FETCH_ASSOC) as $entry) {
        if ($entry['status'] !== 'approved' || (int) $entry['timesheet_id'] !== $timesheetId
            || (int) $entry['rate_snapshot_id'] !== 9701 || (int) $entry['bill_extracted_ref'] !== $invoiceId
            || !$entry['bill_extracted_at']) {
            throw new RuntimeException('Imported row lost its approval or invoice link: ' . json_encode($entry));
        }
    }

    [$status, $list] = ciBusinessApi($curl, $base, 'GET', '/modules/billing/api/invoices.php?status=draft');
    ciBusinessExpect($status, 200, $list, 'Find invoice in Billing');
    if (!in_array($invoiceId, array_map('intval', array_column($list['rows'] ?? [], 'id')), true)) {
        throw new RuntimeException('Created invoice is missing from the Billing draft list');
    }

    [$status, $repeat] = ciBusinessApi($curl, $base, 'POST', '/modules/billing/api/invoices.php?action=from-time-entries', [
        'time_entry_ids' => $entryIds,
        'aggregation' => 'per_placement',
    ]);
    if ($status !== 422 || !str_contains(strtolower((string) ($repeat['error'] ?? '')), 'already included')) {
        throw new RuntimeException('Repeat billing did not explain that time is already invoiced: ' . json_encode([$status, $repeat]));
    }

    [$status, $approvedInvoice] = ciBusinessApi($curl, $base, 'POST', '/modules/billing/api/invoices.php?action=approve&id=' . $invoiceId);
    ciBusinessExpect($status, 200, $approvedInvoice, 'Invoice approval');
    if (empty($approvedInvoice['approved']) || ($approvedInvoice['status'] ?? '') !== 'approved') {
        throw new RuntimeException('Invoice draft did not reach approved status: ' . json_encode($approvedInvoice));
    }

    [$status, $delivery] = ciBusinessApi($curl, $base, 'POST', '/modules/billing/api/invoices.php?action=send&id=' . $invoiceId, [
        'to' => 'ci-billing-recipient@coreflux.test',
    ]);
    ciBusinessExpect($status, 200, $delivery, 'Send approved invoice');
    if (($delivery['email_status'] ?? '') !== 'sent' || empty($delivery['token_id'])
        || !empty($delivery['pdf_attached']) || empty($delivery['pdf_error'])
        || !str_contains((string) ($delivery['url'] ?? ''), '/billing/invoice.php?t=')) {
        throw new RuntimeException('Link-only invoice delivery did not produce a customer view link: ' . json_encode($delivery));
    }
    $outbox = $pdo->prepare(
        'SELECT driver, status, to_addresses_json, subject, body_text
           FROM mail_outbox WHERE tenant_id = :t AND module = "billing" AND purpose = "invoice_sent"'
    );
    $outbox->execute(['t' => $tenantId]);
    $message = $outbox->fetch(PDO::FETCH_ASSOC);
    if (!$message || $outbox->fetch(PDO::FETCH_ASSOC) !== false
        || $message['driver'] !== 'log' || $message['status'] !== 'sent'
        || json_decode((string) $message['to_addresses_json'], true) !== ['ci-billing-recipient@coreflux.test']
        || !str_contains((string) $message['body_text'], (string) $delivery['url'])
        || (!empty($delivery['pdf_attached']) && !str_contains((string) $message['body_text'], 'attached'))
        || (empty($delivery['pdf_attached']) && str_contains((string) $message['body_text'], 'attached'))) {
        throw new RuntimeException('Delivered invoice is not accurately recorded in the log-only outbox: ' . json_encode($message));
    }

    [$status, $sentDetail] = ciBusinessApi($curl, $base, 'GET', '/modules/billing/api/invoices.php?id=' . $invoiceId);
    ciBusinessExpect($status, 200, $sentDetail, 'Open sent invoice');
    if (($sentDetail['invoice']['status'] ?? '') !== 'sent' || empty($sentDetail['invoice']['sent_at'])) {
        throw new RuntimeException('Sent invoice did not stay visible in Billing: ' . json_encode($sentDetail));
    }

    [$status, $post] = ciBusinessApi($curl, $base, 'POST', '/modules/billing/api/invoices.php?action=post&id=' . $invoiceId);
    ciBusinessExpect($status, 200, $post, 'Post sent invoice');
    $invoiceJeId = (int) ($post['journal_entry_id'] ?? 0);
    if ($invoiceJeId <= 0) throw new RuntimeException('Invoice posting did not return a journal entry');
    $journal = $pdo->prepare('SELECT status, total_debit, total_credit FROM accounting_journal_entries WHERE tenant_id = :t AND id = :id');
    $journal->execute(['t' => $tenantId, 'id' => $invoiceJeId]);
    $invoiceJe = $journal->fetch(PDO::FETCH_ASSOC);
    if (!$invoiceJe || $invoiceJe['status'] !== 'posted'
        || (float) $invoiceJe['total_debit'] !== 1000.0 || (float) $invoiceJe['total_credit'] !== 1000.0) {
        throw new RuntimeException('Invoice did not post a balanced $1,000 journal: ' . json_encode($invoiceJe));
    }

    $pdo->prepare(
        "INSERT INTO accounting_bank_accounts
            (id, tenant_id, entity_id, name, gl_account_code, currency)
         VALUES (9701, :t, 1, 'CSV test operating bank', '1000', 'USD')"
    )->execute(['t' => $tenantId]);
    $receiptDate = max((string) $sentDetail['invoice']['issue_date'], date('Y-m-d'));
    $bankCsv = "Date,Description,Amount,FITID\n{$receiptDate},ACH CSV Billing Client,1000.00,ci-invoice-{$invoiceId}\n";
    [$status, $bankImport] = ciBusinessApi($curl, $base, 'POST', '/modules/accounting/api/bank_statements.php?action=import_csv&bank_account_id=9701', [
        'csv' => $bankCsv,
    ]);
    ciBusinessExpect($status, 201, $bankImport, 'Import customer receipt');
    if (($bankImport['inserted'] ?? 0) !== 1) {
        throw new RuntimeException('Customer receipt was not imported to the bank feed: ' . json_encode($bankImport));
    }
    $bankLineStmt = $pdo->prepare('SELECT id FROM accounting_bank_statement_lines WHERE tenant_id = :t AND bank_account_id = 9701 AND fitid = :fitid');
    $bankLineStmt->execute(['t' => $tenantId, 'fitid' => 'ci-invoice-' . $invoiceId]);
    $bankLineId = (int) $bankLineStmt->fetchColumn();
    if ($bankLineId <= 0) throw new RuntimeException('Imported customer receipt is missing from the bank feed');

    [$status, $candidates] = ciBusinessApi($curl, $base, 'GET', '/modules/accounting/api/bank_statements.php?action=invoice_candidates&line_id=' . $bankLineId);
    ciBusinessExpect($status, 200, $candidates, 'Find invoice for bank receipt');
    if (!in_array($invoiceId, array_map('intval', array_column($candidates['rows'] ?? [], 'id')), true)) {
        throw new RuntimeException('Sent and posted invoice is not selectable for its bank receipt: ' . json_encode($candidates));
    }

    [$status, $match] = ciBusinessApi($curl, $base, 'POST', '/modules/accounting/api/bank_statements.php?action=match_invoice&line_id=' . $bankLineId, [
        'invoice_id' => $invoiceId,
    ]);
    ciBusinessExpect($status, 200, $match, 'Match receipt to invoice');
    $paymentId = (int) ($match['payment_id'] ?? 0);
    $receiptJeId = (int) ($match['matched_je_id'] ?? 0);
    if ($paymentId <= 0 || $receiptJeId <= 0 || ($match['invoice_status'] ?? '') !== 'paid') {
        throw new RuntimeException('Receipt was not allocated and posted: ' . json_encode($match));
    }

    [$status, $paidDetail] = ciBusinessApi($curl, $base, 'GET', '/modules/billing/api/invoices.php?id=' . $invoiceId);
    ciBusinessExpect($status, 200, $paidDetail, 'Open paid invoice');
    if (($paidDetail['invoice']['status'] ?? '') !== 'paid'
        || (float) ($paidDetail['invoice']['amount_paid'] ?? 0) !== 1000.0
        || (float) ($paidDetail['invoice']['amount_due'] ?? -1) !== 0.0
        || (int) ($paidDetail['invoice']['journal_entry_id'] ?? 0) !== $invoiceJeId) {
        throw new RuntimeException('Collected invoice did not settle to zero: ' . json_encode($paidDetail));
    }
    $payment = $pdo->prepare('SELECT amount, unallocated_amount, external_id FROM billing_payments WHERE tenant_id = :t AND id = :id');
    $payment->execute(['t' => $tenantId, 'id' => $paymentId]);
    $paymentRow = $payment->fetch(PDO::FETCH_ASSOC);
    $allocations = $pdo->prepare('SELECT invoice_id, amount_applied FROM billing_payment_allocations WHERE payment_id = :payment_id');
    $allocations->execute(['payment_id' => $paymentId]);
    $allocation = $allocations->fetch(PDO::FETCH_ASSOC);
    if (!$paymentRow || !$allocation || $allocations->fetch(PDO::FETCH_ASSOC) !== false
        || (float) $paymentRow['amount'] !== 1000.0 || (float) $paymentRow['unallocated_amount'] !== 0.0
        || $paymentRow['external_id'] !== 'bank-line:' . $bankLineId
        || (int) $allocation['invoice_id'] !== $invoiceId || (float) $allocation['amount_applied'] !== 1000.0) {
        throw new RuntimeException('Bank receipt did not create one fully allocated customer payment');
    }
    $bankLine = $pdo->prepare('SELECT match_status, matched_je_id FROM accounting_bank_statement_lines WHERE tenant_id = :t AND id = :id');
    $bankLine->execute(['t' => $tenantId, 'id' => $bankLineId]);
    $matchedLine = $bankLine->fetch(PDO::FETCH_ASSOC);
    if (!$matchedLine || $matchedLine['match_status'] !== 'matched' || (int) $matchedLine['matched_je_id'] !== $receiptJeId) {
        throw new RuntimeException('Collected bank line is still unmatched: ' . json_encode($matchedLine));
    }
    $journal->execute(['t' => $tenantId, 'id' => $receiptJeId]);
    $receiptJe = $journal->fetch(PDO::FETCH_ASSOC);
    if (!$receiptJe || $receiptJe['status'] !== 'posted'
        || (float) $receiptJe['total_debit'] !== 1000.0 || (float) $receiptJe['total_credit'] !== 1000.0) {
        throw new RuntimeException('Collected receipt did not create a balanced journal: ' . json_encode($receiptJe));
    }
    [$status, $unmatched] = ciBusinessApi($curl, $base, 'GET', '/modules/accounting/api/bank_statements.php?bank_account_id=9701&match_status=unmatched');
    ciBusinessExpect($status, 200, $unmatched, 'Check unmatched bank feed');
    if (in_array($bankLineId, array_map('intval', array_column($unmatched['rows'] ?? [], 'id')), true)) {
        throw new RuntimeException('Collected receipt remained in the unmatched bank feed');
    }
    [$status, $duplicateMatch] = ciBusinessApi($curl, $base, 'POST', '/modules/accounting/api/bank_statements.php?action=match_invoice&line_id=' . $bankLineId, [
        'invoice_id' => $invoiceId,
    ]);
    if ($status !== 409 || !str_contains(strtolower((string) ($duplicateMatch['error'] ?? '')), 'already resolved')) {
        throw new RuntimeException('A second bank match was not safely rejected: ' . json_encode([$status, $duplicateMatch]));
    }
    echo "CSV time -> timesheet -> approved invoice -> logged email -> ledger -> bank receipt -> paid invoice passed.\n";
} finally {
    curl_close($curl);
}

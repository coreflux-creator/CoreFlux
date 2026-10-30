<?php
/** Exercise the authenticated invoice send endpoint against a disposable install. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'coreaccounting'
    || getenv('COREFLUX_STANDALONE_DATABASE') !== 'coreaccounting_ci'
    || getenv('COREFLUX_ACCOUNTING_HTTP_FIXTURE_URL') !== 'http://127.0.0.1:8767'
    || strtolower((string) getenv('MAIL_DRIVER')) !== 'log'
    || !in_array('--confirm-disposable-fixture', $argv, true)) {
    fwrite(STDERR, "Disposable CoreAccounting log-only HTTP fixture only.\n");
    exit(2);
}

$root = realpath((string) getenv('COREFLUX_STANDALONE_WEBROOT'));
if ($root === false || !is_file($root . '/modules/billing/api/invoices.php')) {
    throw new RuntimeException('Installed CoreAccounting runtime is required.');
}
require_once $root . '/core/db.php';
require_once $root . '/core/memberships.php';
require_once $root . '/modules/billing/lib/invoice_drafts.php';
require_once $root . '/modules/billing/lib/approval_assignment.php';

$pdo = getDB();
if (!$pdo || (string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'coreaccounting_ci') {
    throw new RuntimeException('Connected database is not the disposable CI database.');
}
$creator = $pdo->query("SELECT id, tenant_id FROM users
    WHERE email = 'ci-admin@coreaccounting.invalid' AND is_active = 1 LIMIT 1")
    ->fetch(PDO::FETCH_ASSOC);
if (!$creator) throw new RuntimeException('Disposable administrator missing.');
$tenantId = (int) $creator['tenant_id'];
$creatorId = (int) $creator['id'];
$entity = $pdo->prepare('SELECT id FROM accounting_entities
    WHERE tenant_id = :tenant AND active = 1 ORDER BY id LIMIT 1');
$entity->execute(['tenant' => $tenantId]);
$entityId = (int) $entity->fetchColumn();
if ($entityId <= 0) throw new RuntimeException('Disposable legal entity missing.');
$GLOBALS['__cf_request_tenant_id'] = $tenantId;
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS {$message}\n";
};

$reviewerEmail = 'ci-delivery-reviewer-' . bin2hex(random_bytes(4)) . '@coreaccounting.invalid';
$pdo->prepare('INSERT INTO users (name, email, password_hash, role, is_active)
    VALUES ("CI Delivery Reviewer", :email, "!no-login-ci-fixture", "tenant_admin", 1)')
    ->execute(['email' => $reviewerEmail]);
$reviewerId = (int) $pdo->lastInsertId();
provisionMembership($reviewerId, $tenantId, 'tenant_admin', [
    'persona_label' => 'CI Delivery Reviewer', 'is_primary' => true,
]);
$draft = billingCreateDirectInvoiceDraft($tenantId, [
    'entity_id' => $entityId,
    'client_name' => 'CI Log-Only Delivery Client',
    'issue_date' => '2026-10-11',
    'due_date' => '2026-11-10',
    'currency' => 'USD',
    'tax_rate_pct' => 0,
    'lines' => [[
        'description' => 'Synthetic delivery endpoint service',
        'quantity' => 1,
        'unit' => 'each',
        'unit_price' => 0.13,
        'item_type' => 'fixed_fee',
        'gl_revenue_account_code' => '4000',
    ]],
], $creatorId);
$invoiceId = (int) $draft['id'];
$approved = billingInvoiceWorkflowAct($tenantId, $invoiceId, $reviewerId);
$assert($invoiceId > 0 && !empty($approved['approved'])
    && billingInvoiceWorkflowRow($tenantId, $invoiceId)['status'] === 'approved',
    'new synthetic invoice was independently approved before HTTP posting');

$pdo->prepare('INSERT INTO billing_entity_mail_settings
    (tenant_id, entity_id, from_name, reply_to, updated_by_user_id)
    VALUES (:tenant, :entity, "CI Delivery Entity", "reply@coreaccounting.invalid", :actor)
    ON DUPLICATE KEY UPDATE reply_to = VALUES(reply_to)')
    ->execute(['tenant' => $tenantId, 'entity' => $entityId, 'actor' => $creatorId]);
$outbox = $pdo->prepare('SELECT COUNT(*) FROM mail_outbox
    WHERE tenant_id = :tenant AND module = "billing" AND purpose = "invoice_sent"');
$outbox->execute(['tenant' => $tenantId]);
$outboxBefore = (int) $outbox->fetchColumn();

$cookie = tempnam(sys_get_temp_dir(), 'ci-delivery-cookie-');
if ($cookie === false) throw new RuntimeException('Could not make disposable cookie jar.');
$base = 'http://127.0.0.1:8767';
$request = static function (string $path, string $method, ?array $body = null) use ($base, $cookie, $tenantId): array {
    $curl = curl_init($base . $path);
    if ($curl === false) throw new RuntimeException('HTTP client unavailable.');
    $headers = ['Accept: application/json', 'X-CoreFlux-Tenant-Id: ' . $tenantId];
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($raw === false) throw new RuntimeException("{$method} {$path}: {$error}");
    return ['status' => $status, 'body' => json_decode((string) $raw, true), 'raw' => (string) $raw];
};
$reportSnapshot = static function () use ($request, $entityId): array {
    $scope = '&entity_id=' . $entityId;
    $queries = [
        'income' => '/modules/accounting/api/reports.php?type=income_statement'
            . $scope . '&from=2026-01-01&to=2026-12-31',
        'balance' => '/modules/accounting/api/reports.php?type=balance_sheet'
            . $scope . '&as_of=2026-12-31',
        'trial' => '/modules/accounting/api/reports.php?type=trial_balance'
            . $scope . '&as_of=2026-12-31',
        'cash' => '/modules/accounting/api/reports.php?type=cash_flow_indirect'
            . $scope . '&from=2026-01-01&to=2026-12-31',
        'controls' => '/modules/accounting/api/source_control_tie_out.php?entity_id='
            . $entityId . '&as_of=2026-12-31',
    ];
    $snapshot = [];
    foreach ($queries as $name => $path) {
        $response = $request($path, 'GET');
        if ($response['status'] !== 200 || !is_array($response['body'])
            || isset($response['body']['data_warning'])) {
            throw new RuntimeException("{$name} report unavailable: HTTP {$response['status']}: "
                . substr($response['raw'], 0, 500));
        }
        $snapshot[$name] = $response['body'];
    }
    return $snapshot;
};
$deltaCents = static fn(array $before, array $after, string $field): int =>
    (int) round(100 * ((float) ($after[$field] ?? 0) - (float) ($before[$field] ?? 0)));
$trialBalance = static function (array $snapshot, string $code): float {
    foreach ($snapshot['trial']['rows'] as $row) {
        if ((string) ($row['code'] ?? '') === $code) return (float) $row['balance_signed'];
    }
    throw new RuntimeException("Trial balance account {$code} is missing.");
};

try {
    $curl = curl_init($base . '/login.php');
    if ($curl === false) throw new RuntimeException('HTTP login client unavailable.');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'username' => 'ci-admin@coreaccounting.invalid',
            'password' => (string) getenv('COREFLUX_INITIAL_ADMIN_PASSWORD'),
        ]),
    ]);
    $login = curl_exec($curl);
    $loginStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $location = (string) curl_getinfo($curl, CURLINFO_REDIRECT_URL);
    $headerBytes = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $loginHeaders = substr((string) $login, 0, $headerBytes);
    curl_close($curl);
    unset($curl);
    $assert($login !== false && $loginStatus === 302 && !str_contains($location, 'error='),
        'disposable administrator signed in over HTTP');
    $cookieJar = (string) file_get_contents($cookie);
    $assert(str_contains($cookieJar, 'PHPSESSID'),
        'HTTP login preserved a session cookie (redirect ' . $location
        . '; set-cookie header ' . (stripos($loginHeaders, 'set-cookie:') !== false ? 'present' : 'absent')
        . '; cookie jar bytes ' . strlen($cookieJar) . ')');
    $beforeReports = $reportSnapshot();

    $posted = $request('/modules/billing/api/invoices.php?action=post&id=' . $invoiceId, 'POST', []);
    $journalId = (int) ($posted['body']['journal_entry_id'] ?? 0);
    if ($posted['status'] !== 200 || $journalId <= 0) {
        throw new RuntimeException('Synthetic invoice posting returned HTTP ' . $posted['status']
            . ': ' . substr($posted['raw'], 0, 500));
    }
    $assert(true, 'approved synthetic invoice posted through the authenticated Billing API');
    $afterReports = $reportSnapshot();
    $assert($deltaCents($beforeReports['income'], $afterReports['income'], 'total_revenue') === 13
        && $deltaCents($beforeReports['income'], $afterReports['income'], 'net_income') === 13
        && $deltaCents($beforeReports['income'], $afterReports['income'], 'total_expense') === 0,
        'new invoice appears once in the scoped income statement');
    $assert($deltaCents($beforeReports['balance'], $afterReports['balance'], 'total_assets') === 13
        && $deltaCents($beforeReports['balance'], $afterReports['balance'], 'total_equity') === 13
        && $deltaCents($beforeReports['balance'], $afterReports['balance'], 'total_liabilities') === 0
        && !empty($afterReports['balance']['balanced']),
        'new receivable and income keep the scoped balance sheet balanced');
    $assert((int) round(100 * ($trialBalance($afterReports, '1100')
            - $trialBalance($beforeReports, '1100'))) === 13
        && (int) round(100 * ($trialBalance($afterReports, '4000')
            - $trialBalance($beforeReports, '4000'))) === 13,
        'trial balance carries the receivable debit and revenue credit');
    $assert($deltaCents($beforeReports['cash'], $afterReports['cash'], 'net_change_in_cash') === 0
        && $deltaCents($beforeReports['cash'], $afterReports['cash'], 'cash_change_from_gl') === 0
        && !empty($afterReports['cash']['balanced']),
        'uncollected invoice does not create cash');
    $arBefore = $beforeReports['controls']['controls']['ar'] ?? [];
    $arAfter = $afterReports['controls']['controls']['ar'] ?? [];
    $assert($deltaCents($arBefore, $arAfter, 'source_due') === 13
        && $deltaCents($arBefore, $arAfter, 'gl_balance') === 13
        && $deltaCents($arBefore, $arAfter, 'difference') === 0
        && !empty($arAfter['matched']),
        'open invoice and posted AR control both rise by thirteen cents');

    $pdo->prepare('INSERT INTO accounting_entities (tenant_id, code, legal_name)
        VALUES (:tenant, :code, "CI Alternate Delivery Entity")')
        ->execute(['tenant' => $tenantId, 'code' => 'CI-ALT-' . bin2hex(random_bytes(3))]);
    $pdo->prepare('UPDATE billing_entity_mail_settings SET reply_to = NULL
        WHERE tenant_id = :tenant AND entity_id = :entity')
        ->execute(['tenant' => $tenantId, 'entity' => $entityId]);
    $missingReplyTo = $request('/modules/billing/api/invoices.php?id=' . $invoiceId, 'GET');
    $missingSender = $missingReplyTo['body']['delivery_sender'] ?? [];
    $assert($missingReplyTo['status'] === 200 && empty($missingSender['ready'])
        && ($missingSender['setup_target'] ?? null) !== 'provider'
        && ($missingSender['reason'] ?? null) === 'Set a reply-to address for this legal entity in Client contacts.',
        'invoice detail prioritizes missing entity reply-to over the log-only provider blocker');
    $missingReplyToAttempt = $request('/modules/billing/api/invoices.php?action=send&id=' . $invoiceId,
        'POST', [
            'to' => 'recipient@coreaccounting.invalid',
            'resend' => false,
            'request_id' => '88eb9c8b-eb97-4696-b398-b79046174000',
        ]);
    $assert($missingReplyToAttempt['status'] === 422
        && str_contains($missingReplyToAttempt['raw'], 'reply-to'),
        'invoice send refuses missing entity reply-to before checking the transport');
    $pdo->prepare('UPDATE billing_entity_mail_settings SET reply_to = :reply_to
        WHERE tenant_id = :tenant AND entity_id = :entity')
        ->execute(['reply_to' => 'reply@coreaccounting.invalid',
            'tenant' => $tenantId, 'entity' => $entityId]);

    $detail = $request('/modules/billing/api/invoices.php?id=' . $invoiceId, 'GET');
    $sender = $detail['body']['delivery_sender'] ?? [];
    $assert($detail['status'] === 200 && empty($sender['ready'])
        && ($sender['setup_target'] ?? null) === 'provider'
        && str_contains((string) ($sender['reason'] ?? ''), 'log-only'),
        'invoice detail explains that log-only mail cannot deliver to a customer');

    $attempt = $request('/modules/billing/api/invoices.php?action=send&id=' . $invoiceId,
        'POST', [
            'to' => 'recipient@coreaccounting.invalid',
            'resend' => false,
            'request_id' => 'a223e456-e89b-42d3-a456-426614174000',
        ]);
    $assert($attempt['status'] === 503 && str_contains($attempt['raw'], 'log-only'),
        'authenticated Send refuses a log-only transport');

    $state = $pdo->prepare('SELECT bi.status, bi.sent_at, je.status AS journal_status
        FROM billing_invoices bi
        JOIN accounting_journal_entries je ON je.tenant_id = bi.tenant_id
            AND je.id = bi.journal_entry_id
        WHERE bi.tenant_id = :tenant AND bi.id = :invoice');
    $state->execute(['tenant' => $tenantId, 'invoice' => $invoiceId]);
    $saved = $state->fetch(PDO::FETCH_ASSOC);
    $tokens = $pdo->prepare('SELECT COUNT(*) FROM billing_invoice_tokens
        WHERE tenant_id = :tenant AND invoice_id = :invoice');
    $tokens->execute(['tenant' => $tenantId, 'invoice' => $invoiceId]);
    $outbox->execute(['tenant' => $tenantId]);
    $assert($saved && $saved['status'] === 'approved' && $saved['sent_at'] === null
        && $saved['journal_status'] === 'posted' && (int) $tokens->fetchColumn() === 0
        && (int) $outbox->fetchColumn() === $outboxBefore,
        'blocked send preserves the posted invoice without a customer link or outbox item');

    $bank = $request('/modules/accounting/api/bank_accounts.php', 'POST', [
        'entity_id' => $entityId,
        'name' => 'CI Synthetic Collection Bank',
        'bank_name' => 'CI Test Bank',
        'gl_account_code' => '1000',
        'currency' => 'USD',
    ]);
    $bankId = (int) ($bank['body']['id'] ?? 0);
    if ($bank['status'] !== 201 || $bankId <= 0) {
        throw new RuntimeException('Synthetic bank setup returned HTTP ' . $bank['status']
            . ': ' . substr($bank['raw'], 0, 500));
    }
    $assert(true, 'new synthetic bank account was created through the authenticated Accounting API');

    $receiptRequest = [
        'bank_account_id' => $bankId,
        'client_name' => 'CI Log-Only Delivery Client',
        'received_at' => '2026-10-11',
        'amount' => 0.05,
        'currency' => 'USD',
        'method' => 'other',
        'reference' => 'CI-SYNTHETIC-PARTIAL-COLLECTION',
        'request_key' => 'ci_collection_' . bin2hex(random_bytes(8)),
        'allocations' => [['invoice_id' => $invoiceId, 'amount' => 0.05]],
    ];
    $receipt = $request('/modules/billing/api/payments.php', 'POST', $receiptRequest);
    $paymentId = (int) ($receipt['body']['id'] ?? 0);
    $receiptJournalId = (int) ($receipt['body']['journal_entry_id'] ?? 0);
    if ($receipt['status'] !== 201 || $paymentId <= 0 || $receiptJournalId <= 0) {
        throw new RuntimeException('Synthetic partial receipt returned HTTP ' . $receipt['status']
            . ': ' . substr($receipt['raw'], 0, 500));
    }
    $assert(true, 'new synthetic partial receipt posted through the authenticated Billing API');
    $collectedReports = $reportSnapshot();
    $assert($deltaCents($afterReports['income'], $collectedReports['income'], 'total_revenue') === 0
        && $deltaCents($afterReports['income'], $collectedReports['income'], 'net_income') === 0,
        'collecting an invoice does not recognize revenue a second time');
    $assert($deltaCents($afterReports['balance'], $collectedReports['balance'], 'total_assets') === 0
        && !empty($collectedReports['balance']['balanced']),
        'partial collection exchanges receivables for cash without changing total assets');
    $assert((int) round(100 * ($trialBalance($collectedReports, '1100')
            - $trialBalance($afterReports, '1100'))) === -5
        && (int) round(100 * ($trialBalance($collectedReports, '1000')
            - $trialBalance($afterReports, '1000'))) === 5,
        'receipt journal moves five cents from AR to bank cash');
    $assert($deltaCents($afterReports['cash'], $collectedReports['cash'], 'net_change_in_cash') === 5
        && $deltaCents($afterReports['cash'], $collectedReports['cash'], 'cash_change_from_gl') === 5
        && !empty($collectedReports['cash']['balanced']),
        'indirect cash flow and bank GL both rise by five cents');
    $arCollected = $collectedReports['controls']['controls']['ar'] ?? [];
    $assert($deltaCents($arAfter, $arCollected, 'source_due') === -5
        && $deltaCents($arAfter, $arCollected, 'gl_balance') === -5
        && $deltaCents($arAfter, $arCollected, 'difference') === 0
        && !empty($arCollected['matched']),
        'open invoice and posted AR control both fall by five cents');

    $invoiceState = $pdo->prepare('SELECT invoice_number, status, amount_due, amount_paid
        FROM billing_invoices WHERE tenant_id = :tenant AND id = :invoice');
    $invoiceState->execute(['tenant' => $tenantId, 'invoice' => $invoiceId]);
    $collectedInvoice = $invoiceState->fetch(PDO::FETCH_ASSOC);
    $assert($collectedInvoice && $collectedInvoice['status'] === 'partially_paid'
        && abs((float) $collectedInvoice['amount_due'] - 0.08) < 0.005
        && abs((float) $collectedInvoice['amount_paid'] - 0.05) < 0.005,
        'the source invoice persists eight cents due and five cents paid');
    $receiptReplay = $request('/modules/billing/api/payments.php', 'POST', $receiptRequest);
    $assert($receiptReplay['status'] === 201
        && !empty($receiptReplay['body']['idempotent_replay'])
        && (int) ($receiptReplay['body']['id'] ?? 0) === $paymentId
        && (int) ($receiptReplay['body']['journal_entry_id'] ?? 0) === $receiptJournalId,
        'identical receipt retry reuses the original payment and journal');
    $replayReports = $reportSnapshot();
    $assert($deltaCents($collectedReports['cash'], $replayReports['cash'], 'net_change_in_cash') === 0
        && $deltaCents($arCollected, $replayReports['controls']['controls']['ar'] ?? [], 'source_due') === 0,
        'receipt retry leaves cash and outstanding AR unchanged');

    $invoiceNumber = (string) $collectedInvoice['invoice_number'];
    $fitid = 'CI-BANK-PARTIAL-' . bin2hex(random_bytes(8));
    $csv = "Date,Description,Amount,Transaction ID\n"
        . "2026-10-11,Partial payment for {$invoiceNumber},0.03,{$fitid}\n";
    $import = $request('/modules/accounting/api/bank_statements.php?action=import_csv&bank_account_id='
        . $bankId, 'POST', ['csv' => $csv]);
    $assert($import['status'] === 201 && (int) ($import['body']['inserted'] ?? 0) === 1,
        'new synthetic bank-feed deposit was imported through the authenticated Accounting API');
    $lineStmt = $pdo->prepare('SELECT id, match_status FROM accounting_bank_statement_lines
        WHERE tenant_id = :tenant AND bank_account_id = :bank AND fitid = :fitid');
    $lineStmt->execute(['tenant' => $tenantId, 'bank' => $bankId, 'fitid' => $fitid]);
    $bankLine = $lineStmt->fetch(PDO::FETCH_ASSOC);
    $lineId = (int) ($bankLine['id'] ?? 0);
    $assert($lineId > 0 && ($bankLine['match_status'] ?? null) === 'unmatched',
        'imported deposit starts as an unmatched bank line');

    $candidates = $request('/modules/accounting/api/bank_statements.php?action=invoice_candidates&line_id='
        . $lineId . '&q=' . rawurlencode($invoiceNumber), 'GET');
    $candidateRows = $candidates['body']['rows'] ?? [];
    $assert($candidates['status'] === 200 && count($candidateRows) === 1
        && (int) $candidateRows[0]['id'] === $invoiceId
        && abs((float) $candidateRows[0]['amount_due'] - 0.08) < 0.005,
        'bank receipt picker finds the posted invoice and its remaining balance');

    $splitPath = '/modules/accounting/api/bank_statements.php?action=split_match_invoices&line_id=' . $lineId;
    $splitRequest = ['allocations' => [['invoice_id' => $invoiceId, 'amount' => 0.03]],
        'account_splits' => []];
    $bankReceipt = $request($splitPath, 'POST', $splitRequest);
    $bankJournalId = (int) ($bankReceipt['body']['matched_je_id'] ?? 0);
    $bankPaymentIds = array_map('intval', $bankReceipt['body']['payment_ids'] ?? []);
    $assert($bankReceipt['status'] === 200 && $bankJournalId > 0 && count($bankPaymentIds) === 1,
        'bank-feed partial match creates one posted journal and one client payment');
    $lineStmt->execute(['tenant' => $tenantId, 'bank' => $bankId, 'fitid' => $fitid]);
    $matchedLine = $lineStmt->fetch(PDO::FETCH_ASSOC);
    $assert($matchedLine && $matchedLine['match_status'] === 'matched',
        'bank-feed deposit is matched rather than left in reconciliation');

    $bankReports = $reportSnapshot();
    $bankAr = $bankReports['controls']['controls']['ar'] ?? [];
    $assert($deltaCents($collectedReports['income'], $bankReports['income'], 'total_revenue') === 0
        && $deltaCents($collectedReports['income'], $bankReports['income'], 'net_income') === 0,
        'bank-feed collection does not recognize invoice revenue again');
    $assert($deltaCents($collectedReports['balance'], $bankReports['balance'], 'total_assets') === 0
        && !empty($bankReports['balance']['balanced'])
        && $deltaCents($collectedReports['cash'], $bankReports['cash'], 'net_change_in_cash') === 3
        && $deltaCents($collectedReports['cash'], $bankReports['cash'], 'cash_change_from_gl') === 3,
        'bank-feed collection raises cash while preserving the balanced balance sheet');
    $assert($deltaCents($arCollected, $bankAr, 'source_due') === -3
        && $deltaCents($arCollected, $bankAr, 'gl_balance') === -3
        && $deltaCents($arCollected, $bankAr, 'difference') === 0
        && !empty($bankAr['matched']),
        'bank-feed collection reduces source AR and its posted GL control together');
    $invoiceState->execute(['tenant' => $tenantId, 'invoice' => $invoiceId]);
    $bankCollectedInvoice = $invoiceState->fetch(PDO::FETCH_ASSOC);
    $assert($bankCollectedInvoice && $bankCollectedInvoice['status'] === 'partially_paid'
        && abs((float) $bankCollectedInvoice['amount_due'] - 0.05) < 0.005
        && abs((float) $bankCollectedInvoice['amount_paid'] - 0.08) < 0.005,
        'invoice persists both receipts and only five cents remains due');
    $bankReplay = $request($splitPath, 'POST', $splitRequest);
    $assert($bankReplay['status'] === 200 && !empty($bankReplay['body']['idempotent_replay'])
        && (int) ($bankReplay['body']['matched_je_id'] ?? 0) === $bankJournalId
        && array_map('intval', $bankReplay['body']['payment_ids'] ?? []) === $bankPaymentIds,
        'repeating the bank match returns the original journal and payment');
    $bankReplayReports = $reportSnapshot();
    $assert($deltaCents($bankReports['cash'], $bankReplayReports['cash'], 'net_change_in_cash') === 0
        && $deltaCents($bankAr, $bankReplayReports['controls']['controls']['ar'] ?? [], 'source_due') === 0,
        'bank match retry leaves cash and outstanding AR unchanged');
} finally {
    @unlink($cookie);
}

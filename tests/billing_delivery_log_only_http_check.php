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
} finally {
    @unlink($cookie);
}

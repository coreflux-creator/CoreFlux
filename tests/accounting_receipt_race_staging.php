<?php
/** Synthetic concurrent bank-receipt acceptance on the isolated staging host. */
declare(strict_types=1);

$mode = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || !in_array($mode, ['--execute', '--child'], true)) {
    fwrite(STDERR, "Run only on isolated CoreFlux staging with --execute.\n");
    exit(2);
}

define('QA_LIFECYCLE_LIBRARY_MODE', true);
require_once __DIR__ . '/accounting_staging_lifecycle.php';

if ($mode === '--child') {
    [, , $action, $lineId, $body64, $cookie, $marker] = array_pad($argv, 7, null);
    if (!in_array($action, ['match_invoice', 'split_match_invoices'], true)
        || (int) $lineId <= 0 || !is_file((string) $cookie) || !is_file((string) $marker)) {
        throw new RuntimeException('Invalid synthetic receipt worker');
    }
    $body = json_decode((string) base64_decode((string) $body64, true), true);
    if (!is_array($body)) throw new RuntimeException('Invalid synthetic receipt body');
    file_put_contents($marker, 'ready');
    try {
        $data = qaRequest('/modules/accounting/api/bank_statements.php?action=' . $action
            . '&line_id=' . (int) $lineId, 'POST', $body, $cookie);
        $result = ['ok' => true, 'data' => $data];
    } catch (Throwable $error) {
        $result = ['ok' => false, 'error' => $error->getMessage()];
    }
    echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
    exit(0);
}

function receiptRaceInvoice(int $entityId, float $price, string $label,
    string $makerCookie, string $reviewerCookie, int $reviewerId): int
{
    $draft = qaRequest('/modules/billing/api/invoices.php', 'POST', [
        'entity_id' => $entityId,
        'client_name' => 'Synthetic Receipt Race ' . $label,
        'issue_date' => '2026-10-07',
        'due_date' => '2026-11-06',
        'currency' => 'USD',
        'tax_rate_pct' => 0,
        'notes_internal' => 'Synthetic receipt concurrency acceptance',
        'lines' => [[
            'description' => 'Invented service', 'quantity' => 1, 'unit' => 'each',
            'unit_price' => $price, 'item_type' => 'fixed_fee',
            'gl_revenue_account_code' => '4000',
        ]],
    ], $makerCookie);
    $id = (int) ($draft['id'] ?? 0);
    if ($id <= 0) throw new RuntimeException('Synthetic race invoice was not created');
    qaRequest('/modules/billing/api/invoices.php?action=request_approval&id=' . $id,
        'POST', [], $makerCookie);
    qaRequest('/modules/billing/api/approval_assignment.php', 'POST', [
        'invoice_id' => $id, 'reviewer_user_ids' => [$reviewerId],
    ], $makerCookie);
    qaRequest('/modules/billing/api/invoices.php?action=approve&id=' . $id,
        'POST', [], $reviewerCookie);
    $posted = qaRequest('/modules/billing/api/invoices.php?action=post&id=' . $id,
        'POST', [], $reviewerCookie);
    if ((int) ($posted['journal_entry_id'] ?? 0) <= 0) {
        throw new RuntimeException('Synthetic race invoice was not posted');
    }
    return $id;
}

function receiptRaceBankLine(int $bankId, string $fitid, float $amount, string $cookie): int
{
    $csv = qaCsv([
        ['Date', 'Description', 'Amount', 'Transaction ID'],
        ['2026-10-07', 'Synthetic receipt race ' . $fitid,
            number_format($amount, 2, '.', ''), $fitid],
    ]);
    $result = qaRequest('/modules/accounting/api/bank_statements.php?action=import_csv&bank_account_id='
        . $bankId, 'POST', ['csv' => $csv], $cookie);
    if ((int) ($result['inserted'] ?? 0) !== 1) {
        throw new RuntimeException('Synthetic race bank line was not imported');
    }
    $line = qaOne(getDB(), 'SELECT id FROM accounting_bank_statement_lines
        WHERE tenant_id = :tenant AND bank_account_id = :bank AND fitid = :fitid',
        ['tenant' => QA_TENANT, 'bank' => $bankId, 'fitid' => $fitid]);
    $id = (int) ($line['id'] ?? 0);
    if ($id <= 0) throw new RuntimeException('Synthetic race bank line was not found');
    return $id;
}

function receiptRaceStart(string $action, int $lineId, array $body,
    string $sourceCookie, array &$temporary): array
{
    $cookie = tempnam(sys_get_temp_dir(), 'cf-receipt-cookie-');
    $marker = tempnam(sys_get_temp_dir(), 'cf-receipt-ready-');
    if (!$cookie || !$marker || !copy($sourceCookie, $cookie)) {
        throw new RuntimeException('Could not prepare synthetic receipt worker');
    }
    array_push($temporary, $cookie, $marker);
    $body64 = base64_encode(json_encode($body, JSON_THROW_ON_ERROR));
    $process = proc_open([PHP_BINARY, __FILE__, '--child', $action, (string) $lineId,
        $body64, $cookie, $marker],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start synthetic receipt worker');
    fclose($pipes[0]);
    return ['process' => $process, 'pipes' => $pipes, 'marker' => $marker];
}

function receiptRaceWait(array $worker): void
{
    for ($attempt = 0; $attempt < 200; $attempt++) {
        clearstatcache(true, $worker['marker']);
        if (filesize($worker['marker']) > 0) return;
        usleep(50000);
    }
    throw new RuntimeException('Synthetic receipt worker did not start');
}

function receiptRaceResult(array $worker): array
{
    $output = stream_get_contents($worker['pipes'][1]);
    $errors = stream_get_contents($worker['pipes'][2]);
    fclose($worker['pipes'][1]);
    fclose($worker['pipes'][2]);
    $exitCode = proc_close($worker['process']);
    $result = json_decode((string) $output, true);
    if ($exitCode !== 0 || !is_array($result)) {
        throw new RuntimeException('Synthetic receipt worker failed: '
            . substr((string) $errors . (string) $output, 0, 500));
    }
    return $result;
}

function receiptRaceRun(PDO $pdo, string $lockTable, int $lockId,
    array $requests, array &$temporary): array
{
    $workers = [];
    $pdo->beginTransaction();
    try {
        $sql = $lockTable === 'billing_invoices'
            ? 'SELECT id FROM billing_invoices WHERE tenant_id = :tenant AND id = :id FOR UPDATE'
            : 'SELECT id FROM accounting_bank_statement_lines WHERE tenant_id = :tenant AND id = :id FOR UPDATE';
        $locked = qaOne($pdo, $sql, ['tenant' => QA_TENANT, 'id' => $lockId]);
        if ((int) ($locked['id'] ?? 0) !== $lockId) {
            throw new RuntimeException('Synthetic receipt race row lock failed');
        }
        foreach ($requests as $request) {
            $worker = receiptRaceStart($request['action'], $request['line_id'],
                $request['body'], $request['cookie'], $temporary);
            $workers[] = $worker;
            receiptRaceWait($worker);
            usleep(500000);
        }
        $waiting = count($workers) === 2
            && proc_get_status($workers[0]['process'])['running']
            && proc_get_status($workers[1]['process'])['running'];
    } finally {
        if ($pdo->inTransaction()) $pdo->commit();
    }
    $results = array_map('receiptRaceResult', $workers);
    qaExpect($waiting, 'two independent live receipt requests wait for the same financial row');
    return $results;
}

$entity = qaOne($pdo, 'SELECT id FROM accounting_entities WHERE tenant_id = :t AND code = :code',
    ['t' => QA_TENANT, 'code' => QA_ENTITY_CODE]);
$entityId = (int) ($entity['id'] ?? 0);
$bank = qaOne($pdo, 'SELECT id FROM accounting_bank_accounts
    WHERE tenant_id = :t AND entity_id = :e AND gl_account_code = :code',
    ['t' => QA_TENANT, 'e' => $entityId, 'code' => QA_BANK_CODE]);
$bankId = (int) ($bank['id'] ?? 0);
if ($entityId <= 0 || $bankId <= 0) {
    throw new RuntimeException('Synthetic lifecycle entity and bank must exist first');
}

$actors = [];
$temporary = [];
try {
    foreach (['maker', 'reviewer', 'receipt-peer'] as $label) {
        $actors[$label] = qaEnsureActor($pdo, $label);
        $cookie = tempnam(sys_get_temp_dir(), 'cf-receipt-login-');
        if (!$cookie) throw new RuntimeException('Could not prepare synthetic actor session');
        $temporary[] = $cookie;
        qaLogin($actors[$label], $cookie);
        $actors[$label]['cookie'] = $cookie;
    }
    $maker = $actors['maker']['cookie'];
    $reviewer = $actors['reviewer']['cookie'];
    $peer = $actors['receipt-peer']['cookie'];
    $run = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));

    $invoiceA = receiptRaceInvoice($entityId, 31.25, $run . '-A',
        $maker, $reviewer, (int) $actors['reviewer']['id']);
    $invoiceB = receiptRaceInvoice($entityId, 18.75, $run . '-B',
        $maker, $reviewer, (int) $actors['reviewer']['id']);
    $line = receiptRaceBankLine($bankId, 'SIM-RECEIPT-RACE-SPLIT-' . $run, 50, $maker);
    $splitBody = ['allocations' => [
        ['invoice_id' => $invoiceA, 'amount' => 31.25],
        ['invoice_id' => $invoiceB, 'amount' => 18.75],
    ]];
    $before = qaBalances($pdo, $entityId);
    $split = receiptRaceRun($pdo, 'accounting_bank_statement_lines', $line, [
        ['action' => 'split_match_invoices', 'line_id' => $line,
            'body' => $splitBody, 'cookie' => $reviewer],
        ['action' => 'split_match_invoices', 'line_id' => $line,
            'body' => $splitBody, 'cookie' => $peer],
    ], $temporary);
    $first = $split[0]['data'] ?? [];
    $second = $split[1]['data'] ?? [];
    qaExpect(!empty($split[0]['ok']) && !empty($split[1]['ok'])
        && (int) ($first['matched_je_id'] ?? 0) > 0
        && (int) ($first['matched_je_id'] ?? 0) === (int) ($second['matched_je_id'] ?? 0)
        && ($first['payment_ids'] ?? []) === ($second['payment_ids'] ?? [])
        && !empty($first['idempotent_replay']) !== !empty($second['idempotent_replay']),
        'simultaneous identical split requests return one posted result and one replay');
    $paymentIds = array_map('intval', $first['payment_ids'] ?? []);
    $lineState = qaOne($pdo, 'SELECT match_status, matched_je_id FROM accounting_bank_statement_lines
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $line]);
    $receiptCount = qaOne($pdo, 'SELECT COUNT(*) AS n FROM billing_bank_receipt_requests
        WHERE tenant_id = :t AND bank_line_id = :id', ['t' => QA_TENANT, 'id' => $line]);
    $journalCount = qaOne($pdo, 'SELECT COUNT(*) AS n FROM accounting_journal_entries
        WHERE tenant_id = :t AND source_module = "billing"
            AND source_ref_type = "bank_statement_line" AND source_ref_id = :id',
        ['t' => QA_TENANT, 'id' => $line]);
    $activePaymentCount = qaOne($pdo, 'SELECT COUNT(*) AS n FROM billing_payments
        WHERE tenant_id = :t AND journal_entry_id = :je AND voided_at IS NULL',
        ['t' => QA_TENANT, 'je' => (int) $first['matched_je_id']]);
    qaExpect(count($paymentIds) === 2
        && $lineState['match_status'] === 'matched'
        && (int) $lineState['matched_je_id'] === (int) $first['matched_je_id']
        && (int) $receiptCount['n'] === 1 && (int) $journalCount['n'] === 1
        && (int) $activePaymentCount['n'] === 2,
        'concurrent split leaves one bank match, one journal, one request record, and two payments');
    foreach ([$invoiceA, $invoiceB] as $invoiceId) {
        $state = qaOne($pdo, 'SELECT status, amount_due FROM billing_invoices
            WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $invoiceId]);
        qaExpect($state['status'] === 'paid' && abs((float) $state['amount_due']) < 0.005,
            'split invoice ' . $invoiceId . ' settled exactly once');
    }
    $after = qaBalances($pdo, $entityId);
    qaExpect(qaDelta($before, $after, QA_BANK_CODE, 50)
        && qaDelta($before, $after, '1100', -50)
        && abs(array_sum($after)) < 0.005,
        'concurrent split moves cash and AR once with a balanced GL');

    $invoiceC = receiptRaceInvoice($entityId, 70, $run . '-C',
        $maker, $reviewer, (int) $actors['reviewer']['id']);
    $lineA = receiptRaceBankLine($bankId, 'SIM-RECEIPT-RACE-ONE-' . $run, 70, $maker);
    $lineB = receiptRaceBankLine($bankId, 'SIM-RECEIPT-RACE-TWO-' . $run, 70, $maker);
    $beforeCompeting = qaBalances($pdo, $entityId);
    $competing = receiptRaceRun($pdo, 'billing_invoices', $invoiceC, [
        ['action' => 'match_invoice', 'line_id' => $lineA,
            'body' => ['invoice_id' => $invoiceC], 'cookie' => $reviewer],
        ['action' => 'match_invoice', 'line_id' => $lineB,
            'body' => ['invoice_id' => $invoiceC], 'cookie' => $peer],
    ], $temporary);
    $winners = array_values(array_filter($competing, static fn(array $r): bool => !empty($r['ok'])));
    $losers = array_values(array_filter($competing, static fn(array $r): bool => empty($r['ok'])));
    qaExpect(count($winners) === 1 && count($losers) === 1
        && str_contains((string) ($losers[0]['error'] ?? ''), 'HTTP 422'),
        'two different deposits cannot both consume one invoice balance');
    $winnerLineId = (int) ($winners[0]['data']['line_id'] ?? 0);
    $loserLineId = $winnerLineId === $lineA ? $lineB : $lineA;
    $winnerLine = qaOne($pdo, 'SELECT match_status, matched_je_id FROM accounting_bank_statement_lines
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $winnerLineId]);
    $loserLine = qaOne($pdo, 'SELECT match_status, matched_je_id FROM accounting_bank_statement_lines
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $loserLineId]);
    $invoiceState = qaOne($pdo, 'SELECT status, amount_due FROM billing_invoices
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $invoiceC]);
    $loserJournalCount = qaOne($pdo, 'SELECT COUNT(*) AS n FROM accounting_journal_entries
        WHERE tenant_id = :t AND source_module = "billing"
            AND source_ref_type = "bank_statement_line" AND source_ref_id = :id',
        ['t' => QA_TENANT, 'id' => $loserLineId]);
    $loserPaymentCount = qaOne($pdo, 'SELECT COUNT(*) AS n FROM billing_payments
        WHERE tenant_id = :t AND source_system = "manual"
            AND (external_id = :line_key OR external_id LIKE :retry_key)',
        ['t' => QA_TENANT, 'line_key' => 'bank-line:' . $loserLineId,
            'retry_key' => 'bank-line:' . $loserLineId . ':r%']);
    $afterCompeting = qaBalances($pdo, $entityId);
    qaExpect($winnerLine['match_status'] === 'matched'
        && (int) $winnerLine['matched_je_id'] > 0
        && $loserLine['match_status'] === 'unmatched' && $loserLine['matched_je_id'] === null
        && (int) $loserJournalCount['n'] === 0 && (int) $loserPaymentCount['n'] === 0
        && $invoiceState['status'] === 'paid' && abs((float) $invoiceState['amount_due']) < 0.005
        && qaDelta($beforeCompeting, $afterCompeting, QA_BANK_CODE, 70)
        && qaDelta($beforeCompeting, $afterCompeting, '1100', -70)
        && abs(array_sum($afterCompeting)) < 0.005,
        'competing receipt posts only the winning bank line and preserves the unmatched deposit');
    echo json_encode(['run' => $run, 'split_line_id' => $line,
        'split_je_id' => (int) $first['matched_je_id'],
        'winner_line_id' => $winnerLineId, 'unmatched_line_id' => $loserLineId],
        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($temporary as $path) {
        if (is_string($path) && file_exists($path)) unlink($path);
    }
    foreach ($actors as $actor) {
        if (!isset($actor['id'])) continue;
        $pdo->prepare('UPDATE users SET is_active = 0, password_hash = NULL
            WHERE tenant_id = :tenant AND id = :id')
            ->execute(['tenant' => QA_TENANT, 'id' => $actor['id']]);
    }
}

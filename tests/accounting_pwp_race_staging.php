<?php
/** Concurrent PWP release/receipt correction acceptance on isolated synthetic staging. */
declare(strict_types=1);

$mode = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || !in_array($mode, ['--execute', '--child'], true)) {
    fwrite(STDERR, "Run only on isolated CoreFlux staging with --execute.\n");
    exit(2);
}

define('QA_LIFECYCLE_LIBRARY_MODE', true);
require_once __DIR__ . '/accounting_staging_lifecycle.php';

if ($mode === '--child') {
    [$script, , $action, $recordId, $cookie, $marker] = array_pad($argv, 6, null);
    if (!in_array($action, ['release', 'correct'], true) || (int) $recordId <= 0
        || !is_file((string) $cookie) || !is_file((string) $marker)) {
        throw new RuntimeException('Invalid synthetic race worker arguments');
    }
    file_put_contents($marker, 'ready');
    try {
        $path = $action === 'release'
            ? '/modules/ap/api/pwp.php?action=release_for_invoice'
            : '/modules/accounting/api/bank_statements.php?action=reverse_receipt&line_id=' . (int) $recordId;
        $body = $action === 'release'
            ? ['ar_invoice_id' => (int) $recordId]
            : ['reason' => 'Synthetic concurrent PWP correction check'];
        $result = ['ok' => true, 'data' => qaRequest($path, 'POST', $body, $cookie)];
    } catch (Throwable $error) {
        $result = ['ok' => false, 'error' => $error->getMessage()];
    }
    echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
    exit(0);
}

function pwpRaceWaitForMarker(string $path): void
{
    for ($attempt = 0; $attempt < 200; $attempt++) {
        clearstatcache(true, $path);
        if (filesize($path) > 0) return;
        usleep(50000);
    }
    throw new RuntimeException('A synthetic race worker did not start');
}

function pwpRaceStart(string $action, int $recordId, string $sourceCookie, array &$temporary): array
{
    $cookie = tempnam(sys_get_temp_dir(), 'cf-race-cookie-');
    $marker = tempnam(sys_get_temp_dir(), 'cf-race-ready-');
    if (!$cookie || !$marker || !copy($sourceCookie, $cookie)) {
        throw new RuntimeException('Could not prepare a synthetic race worker');
    }
    array_push($temporary, $cookie, $marker);
    $process = proc_open([PHP_BINARY, __FILE__, '--child', $action, (string) $recordId,
        $cookie, $marker], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start a synthetic race worker');
    fclose($pipes[0]);
    return ['process' => $process, 'pipes' => $pipes, 'marker' => $marker];
}

function pwpRaceResult(array $worker): array
{
    $output = stream_get_contents($worker['pipes'][1]);
    $errors = stream_get_contents($worker['pipes'][2]);
    fclose($worker['pipes'][1]);
    fclose($worker['pipes'][2]);
    $exitCode = proc_close($worker['process']);
    $result = json_decode((string) $output, true);
    if ($exitCode !== 0 || !is_array($result)) {
        throw new RuntimeException('Synthetic race worker failed: ' . substr((string) $errors . (string) $output, 0, 500));
    }
    return $result;
}

$entity = qaOne($pdo, 'SELECT id FROM accounting_entities
    WHERE tenant_id = :t AND code = :code', ['t' => QA_TENANT, 'code' => QA_ENTITY_CODE]);
$entityId = (int) ($entity['id'] ?? 0);
$bank = qaOne($pdo, 'SELECT id FROM accounting_bank_accounts
    WHERE tenant_id = :t AND entity_id = :e AND gl_account_code = :code',
    ['t' => QA_TENANT, 'e' => $entityId, 'code' => QA_BANK_CODE]);
$bankId = (int) ($bank['id'] ?? 0);
if ($entityId <= 0 || $bankId <= 0) {
    throw new RuntimeException('Synthetic lifecycle entity and bank must be set up first');
}

$maker = null;
$reviewer = null;
$temporary = [];
try {
    $maker = qaEnsureActor($pdo, 'maker');
    $reviewer = qaEnsureActor($pdo, 'reviewer');
    $makerCookie = tempnam(sys_get_temp_dir(), 'cf-race-maker-');
    $reviewerCookie = tempnam(sys_get_temp_dir(), 'cf-race-reviewer-');
    array_push($temporary, $makerCookie, $reviewerCookie);
    qaLogin($maker, $makerCookie);
    qaLogin($reviewer, $reviewerCookie);

    $run = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
    $date = '2026-10-07';
    $winners = [];
    echo "Synthetic PWP race {$run}\n";
    foreach (['correct-first', 'release-first'] as $order) {
        $before = qaBalances($pdo, $entityId);
        $ref = $run . '-' . $order;
        $invoice = qaRequest('/modules/billing/api/invoices.php', 'POST', [
            'entity_id' => $entityId, 'client_name' => 'Synthetic PWP Race Client ' . $ref,
            'issue_date' => $date, 'due_date' => '2026-11-06', 'currency' => 'USD',
            'tax_rate_pct' => 0, 'notes_internal' => 'Synthetic PWP race ' . $ref,
            'lines' => [['description' => 'Invented race service', 'quantity' => 1,
                'unit' => 'each', 'unit_price' => 11.25, 'item_type' => 'fixed_fee',
                'gl_revenue_account_code' => '4000']],
        ], $makerCookie);
        $invoiceId = (int) ($invoice['id'] ?? 0);
        qaExpect($invoiceId > 0, "{$order}: invoice draft created");
        qaRequest('/modules/billing/api/invoices.php?action=request_approval&id=' . $invoiceId,
            'POST', [], $makerCookie);
        qaRequest('/modules/billing/api/approval_assignment.php', 'POST', [
            'invoice_id' => $invoiceId, 'reviewer_user_ids' => [(int) $reviewer['id']],
        ], $makerCookie);
        qaRequest('/modules/billing/api/invoices.php?action=approve&id=' . $invoiceId,
            'POST', [], $reviewerCookie);
        $posted = qaRequest('/modules/billing/api/invoices.php?action=post&id=' . $invoiceId,
            'POST', [], $reviewerCookie);
        qaExpect((int) ($posted['journal_entry_id'] ?? 0) > 0, "{$order}: invoice posted");

        $fitid = 'SIM-PWP-RACE-' . $ref;
        $csv = qaCsv([
            ['Date', 'Description', 'Amount', 'Transaction ID'],
            [$date, 'Synthetic PWP race receipt ' . $ref, '11.25', $fitid],
        ]);
        $import = qaRequest('/modules/accounting/api/bank_statements.php?action=import_csv&bank_account_id=' .
            $bankId, 'POST', ['csv' => $csv], $makerCookie);
        qaExpect((int) ($import['inserted'] ?? 0) === 1, "{$order}: receipt imported");
        $line = qaOne($pdo, 'SELECT id FROM accounting_bank_statement_lines
            WHERE tenant_id = :t AND bank_account_id = :bank AND fitid = :fitid',
            ['t' => QA_TENANT, 'bank' => $bankId, 'fitid' => $fitid]);
        $lineId = (int) ($line['id'] ?? 0);
        qaExpect($lineId > 0, "{$order}: receipt line found");
        $match = qaRequest('/modules/accounting/api/bank_statements.php?action=match_invoice&line_id=' .
            $lineId, 'POST', ['invoice_id' => $invoiceId], $reviewerCookie);
        $receiptJeId = (int) ($match['matched_je_id'] ?? 0);
        qaExpect($receiptJeId > 0, "{$order}: invoice collected without a linked bill");

        $bill = qaRequest('/modules/ap/api/bills.php', 'POST', [
            'entity_id' => $entityId, 'vendor_name' => 'Synthetic PWP Race Vendor ' . $ref,
            'vendor_type' => 'other', 'bill_number' => 'PWP-RACE-' . $ref,
            'bill_date' => $date, 'received_at' => $date, 'due_date' => '2026-11-06',
            'currency' => 'USD', 'tax_rate_pct' => 0,
            'notes_internal' => 'Synthetic PWP race ' . $ref,
            'lines' => [['description' => 'Invented unposted vendor charge', 'quantity' => 1,
                'unit' => 'each', 'unit_price' => 4.00, 'item_type' => 'expense',
                'gl_expense_account_code' => '5000']],
        ], $makerCookie);
        $billId = (int) ($bill['id'] ?? 0);
        qaExpect($billId > 0, "{$order}: pending vendor bill created");

        // Stage-only fault injection recreates a legacy paid-invoice/held-bill race window.
        $fixture = $pdo->prepare('UPDATE ap_bills SET payment_terms = "PWP",
            linked_ar_invoice_id = :invoice_id, pwp_status = "awaiting_ar", pwp_released_at = NULL
            WHERE id = :bill_id AND tenant_id = :tenant_id AND entity_id = :entity_id
                AND status = "pending_approval" AND linked_ar_invoice_id IS NULL');
        $fixture->execute(['invoice_id' => $invoiceId, 'bill_id' => $billId,
            'tenant_id' => QA_TENANT, 'entity_id' => $entityId]);
        qaExpect($fixture->rowCount() === 1, "{$order}: controlled held-bill race fixture set");

        $pdo->beginTransaction();
        $locked = qaOne($pdo, 'SELECT id FROM billing_invoices
            WHERE tenant_id = :t AND entity_id = :e AND id = :id FOR UPDATE',
            ['t' => QA_TENANT, 'e' => $entityId, 'id' => $invoiceId]);
        qaExpect((int) ($locked['id'] ?? 0) === $invoiceId,
            "{$order}: invoice row locked before both requests");
        $workers = [];
        $bothWaiting = false;
        try {
            $sequence = $order === 'correct-first' ? ['correct', 'release'] : ['release', 'correct'];
            foreach ($sequence as $action) {
                $workers[$action] = pwpRaceStart($action,
                    $action === 'correct' ? $lineId : $invoiceId,
                    $reviewerCookie, $temporary);
                pwpRaceWaitForMarker($workers[$action]['marker']);
                usleep(500000);
            }
            usleep(750000);
            $bothWaiting = proc_get_status($workers['release']['process'])['running']
                && proc_get_status($workers['correct']['process'])['running'];
        } catch (Throwable $error) {
            foreach ($workers as $worker) {
                if (!is_resource($worker['process'])) continue;
                proc_terminate($worker['process']);
                fclose($worker['pipes'][1]);
                fclose($worker['pipes'][2]);
                proc_close($worker['process']);
            }
            throw $error;
        } finally {
            if ($pdo->inTransaction()) $pdo->commit();
        }
        $release = pwpRaceResult($workers['release']);
        $correction = pwpRaceResult($workers['correct']);
        qaExpect($bothWaiting, "{$order}: both live requests wait while the invoice is locked");
        $invoiceState = qaOne($pdo, 'SELECT status, amount_due FROM billing_invoices
            WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $invoiceId]);
        $billState = qaOne($pdo, 'SELECT status, pwp_status, pwp_released_at, approved_at FROM ap_bills
            WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $billId]);
        $lineState = qaOne($pdo, 'SELECT match_status, matched_je_id
            FROM accounting_bank_statement_lines WHERE tenant_id = :t AND id = :id',
            ['t' => QA_TENANT, 'id' => $lineId]);
        $journalState = qaOne($pdo, 'SELECT reversed_by_je_id FROM accounting_journal_entries
            WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $receiptJeId]);
        $after = qaBalances($pdo, $entityId);
        $releaseWon = !empty($release['ok'])
            && (int) ($release['data']['released'][0]['bill_id'] ?? 0) === $billId
            && empty($correction['ok'])
            && str_contains((string) ($correction['error'] ?? ''), 'HTTP 409')
            && str_contains((string) ($correction['error'] ?? ''), 'Pay-when-paid bills were released')
            && $invoiceState['status'] === 'paid'
            && abs((float) $invoiceState['amount_due']) < 0.005
            && $billState['status'] === 'pending_approval'
            && $billState['approved_at'] === null
            && $billState['pwp_status'] === 'triggered'
            && $billState['pwp_released_at'] !== null
            && $lineState['match_status'] === 'matched'
            && (int) $lineState['matched_je_id'] === $receiptJeId
            && $journalState['reversed_by_je_id'] === null
            && qaDelta($before, $after, QA_BANK_CODE, 11.25)
            && qaDelta($before, $after, '1100', 0);
        $correctionWon = !empty($correction['ok'])
            && !empty($release['ok']) && empty($release['data']['released'])
            && $invoiceState['status'] === 'approved'
            && abs((float) $invoiceState['amount_due'] - 11.25) < 0.005
            && $billState['status'] === 'pending_approval'
            && $billState['approved_at'] === null
            && $billState['pwp_status'] === 'awaiting_ar'
            && $billState['pwp_released_at'] === null
            && $lineState['match_status'] === 'unmatched'
            && $lineState['matched_je_id'] === null
            && (int) $journalState['reversed_by_je_id'] > 0
            && qaDelta($before, $after, QA_BANK_CODE, 0)
            && qaDelta($before, $after, '1100', 11.25);
        qaExpect(($releaseWon || $correctionWon)
            && qaDelta($before, $after, '4000', -11.25)
            && abs(array_sum($after)) < 0.005,
            "{$order}: one consistent release or correction outcome on the shared ledger");
        $winners[$order] = $releaseWon ? 'release' : 'correction';
        echo json_encode(['order' => $order, 'winner' => $releaseWon ? 'release' : 'correction',
            'invoice_id' => $invoiceId, 'bill_id' => $billId, 'line_id' => $lineId,
            'receipt_je_id' => $receiptJeId], JSON_THROW_ON_ERROR), "\n";
    }
    qaExpect(($winners['correct-first'] ?? null) === 'correction'
        && ($winners['release-first'] ?? null) === 'release',
        'both competing request orderings were observed');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($temporary as $path) if (is_string($path) && file_exists($path)) unlink($path);
    foreach ([$maker, $reviewer] as $actor) {
        if ($actor && isset($actor['id'])) {
            $pdo->prepare('UPDATE users SET is_active = 0, password_hash = NULL
                WHERE id = :id AND tenant_id = :t')
                ->execute(['id' => $actor['id'], 't' => QA_TENANT]);
        }
    }
}

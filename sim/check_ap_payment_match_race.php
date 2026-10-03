<?php
/** Two-session staging check: a bank line cannot match a reversing AP journal. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging') {
    fwrite(STDERR, "This check is restricted to the staging CLI.\n");
    exit(2);
}
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../modules/ap/lib/payment_correction.php';
require_once __DIR__ . '/../modules/accounting/lib/bank_rec.php';
require_once __DIR__ . '/audit_ap_payment_lineage.php';

$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) continue;
    [$key, $value] = explode('=', substr($arg, 2), 2);
    $args[$key] = $value;
}
$tenantId = (int) ($args['tenant'] ?? 0);
if ($tenantId <= 0) {
    fwrite(STDERR, "Use --tenant=ID with a disposable canonical AP simulation tenant.\n");
    exit(2);
}
$GLOBALS['__cf_request_tenant_id'] = $tenantId;
$pdo = getDB();
$tenantStmt = $pdo->prepare('SELECT name FROM tenants WHERE id = :id');
$tenantStmt->execute(['id' => $tenantId]);
if ($tenantStmt->fetchColumn() !== "CoreFlux CI Simulation {$tenantId}") {
    fwrite(STDERR, "This check can only run against a CoreFlux CI Simulation tenant.\n");
    exit(2);
}

if (($args['mode'] ?? '') === 'child') {
    $lineId = (int) ($args['line-id'] ?? 0);
    $journalId = (int) ($args['journal-id'] ?? 0);
    if ($lineId <= 0 || $journalId <= 0) exit(2);
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 10');
    fwrite(STDOUT, "attempting\n");
    fflush(STDOUT);
    $started = microtime(true);
    try {
        bankRecMatchLine($tenantId, $lineId, $journalId, null);
        echo json_encode(['matched' => true, 'wait_ms' => round((microtime(true) - $started) * 1000)]) . "\n";
        exit(1);
    } catch (Throwable $e) {
        echo json_encode([
            'matched' => false,
            'wait_ms' => round((microtime(true) - $started) * 1000),
            'reason' => $e->getMessage(),
        ]) . "\n";
        exit(str_contains($e->getMessage(), 'Only a posted journal entry can be matched') ? 0 : 1);
    }
}

if (($args['commit-correction'] ?? '') !== 'yes') {
    fwrite(STDERR, "Use --tenant=ID --commit-correction=yes. This changes only a synthetic payment.\n");
    exit(2);
}

$paymentStmt = $pdo->prepare(
    'SELECT * FROM ap_payments WHERE tenant_id = :tenant_id AND reference = "SIM-CAN-PAY-0001"'
);
$paymentStmt->execute(['tenant_id' => $tenantId]);
$payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
if (!$payment || $payment['status'] !== 'cleared') {
    fwrite(STDERR, "The canonical synthetic payment is missing or already corrected.\n");
    exit(2);
}
$review = apInspectClearedManualPayment($pdo, $tenantId, $payment);
if ($review['bank_line_id']) {
    fwrite(STDERR, "The synthetic payment already has a bank match.\n");
    exit(2);
}
$paymentId = (int) $payment['id'];
$journalId = (int) $payment['journal_entry_id'];
$bankAccountId = (int) $payment['bank_account_id'];
$journalStmt = $pdo->prepare(
    'SELECT posting_date FROM accounting_journal_entries WHERE tenant_id = :tenant_id AND id = :id'
);
$journalStmt->execute(['tenant_id' => $tenantId, 'id' => $journalId]);
$postingDate = (string) $journalStmt->fetchColumn();
$fitid = 'SIM-AP-MATCH-RACE-' . bin2hex(random_bytes(5));
$lineId = 0;
$proc = null;
$pipes = [];
$checks = [];

try {
    $pdo->prepare(
        'INSERT INTO accounting_bank_statement_lines
            (tenant_id, bank_account_id, posted_date, description, amount, bank_reference, fitid, match_status)
         VALUES (:tenant_id, :bank_account_id, :posted_date, "Synthetic AP reversal race",
                 :amount, :reference, :fitid, "unmatched")'
    )->execute([
        'tenant_id' => $tenantId,
        'bank_account_id' => $bankAccountId,
        'posted_date' => $postingDate,
        'amount' => -(float) $payment['amount'],
        'reference' => $fitid,
        'fitid' => $fitid,
    ]);
    $lineId = (int) $pdo->lastInsertId();

    $pdo->beginTransaction();
    $correction = apCorrectClearedManualPayment(
        $tenantId, $paymentId, 'Disposable two-session AP match race', null
    );
    $checks['correction_held_in_outer_transaction'] = $pdo->inTransaction()
        && (int) $correction['original_je_id'] === $journalId;

    $cmd = [PHP_BINARY, __FILE__, '--mode=child', '--tenant=' . $tenantId,
        '--line-id=' . $lineId, '--journal-id=' . $journalId];
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__);
    if (!is_resource($proc)) throw new RuntimeException('Second PHP session could not start');
    fclose($pipes[0]);
    stream_set_timeout($pipes[1], 15);
    $ready = trim((string) fgets($pipes[1]));
    if ($ready !== 'attempting') throw new RuntimeException('Second session did not start matching');
    usleep(1_000_000);
    $pdo->commit();

    $child = json_decode(trim((string) fgets($pipes[1])), true);
    $childError = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $childExit = proc_close($proc);
    $proc = null;
    $checks['matcher_waited_for_journal_lock'] = is_array($child)
        && (int) ($child['wait_ms'] ?? 0) >= 700;
    $checks['match_refused_after_reversal_commit'] = $childExit === 0
        && ($child['matched'] ?? null) === false
        && str_contains((string) ($child['reason'] ?? ''), 'Only a posted journal entry can be matched');
    if ($childError !== '') throw new RuntimeException('Second session error: ' . $childError);

    $lineStmt = $pdo->prepare(
        'SELECT match_status, matched_je_id FROM accounting_bank_statement_lines
          WHERE tenant_id = :tenant_id AND id = :id'
    );
    $lineStmt->execute(['tenant_id' => $tenantId, 'id' => $lineId]);
    $line = $lineStmt->fetch(PDO::FETCH_ASSOC);
    $checks['bank_line_remained_unmatched'] = $line
        && $line['match_status'] === 'unmatched' && !$line['matched_je_id'];
    $paymentStmt->execute(['tenant_id' => $tenantId]);
    $after = $paymentStmt->fetch(PDO::FETCH_ASSOC);
    $checks['payment_correction_committed'] = $after && $after['status'] === 'void';
    $checks['synthetic_tenant_lineage_consistent'] = simAuditApPaymentLineage($pdo, $tenantId)['issues'] === [];
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $checks['error'] = $e->getMessage();
} finally {
    if (is_resource($proc)) {
        proc_terminate($proc);
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        proc_close($proc);
    }
    if ($lineId > 0) {
        $delete = $pdo->prepare(
            'DELETE FROM accounting_bank_statement_lines
              WHERE tenant_id = :tenant_id AND id = :id AND fitid = :fitid
                AND match_status = "unmatched" AND matched_je_id IS NULL'
        );
        $delete->execute(['tenant_id' => $tenantId, 'id' => $lineId, 'fitid' => $fitid]);
        $checks['temporary_bank_line_removed'] = $delete->rowCount() === 1;
    }
}

echo json_encode(['tenant_id' => $tenantId, 'checks' => $checks], JSON_PRETTY_PRINT) . "\n";
exit($checks && !in_array(false, $checks, true) && !isset($checks['error']) ? 0 : 1);

<?php
/** Two-session, staging-only check that a void-winning AP race leaves no journal. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging') {
    fwrite(STDERR, "This check is restricted to the staging CLI.\n");
    exit(2);
}
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../modules/ap/lib/ap.php';
require_once __DIR__ . '/../modules/accounting/lib/accounting.php';

$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) continue;
    [$key, $value] = explode('=', substr($arg, 2), 2);
    $args[$key] = $value;
}
$tenantId = (int) ($args['tenant'] ?? 0);
if ($tenantId !== 999) {
    fwrite(STDERR, "Use the isolated simulation tenant 999.\n");
    exit(2);
}

$pdo = getDB();
if (($args['mode'] ?? '') === 'child') {
    $billId = (int) ($args['bill-id'] ?? 0);
    $bill = $pdo->prepare('SELECT * FROM ap_bills WHERE tenant_id = :t AND id = :id');
    $bill->execute(['t' => $tenantId, 'id' => $billId]);
    $row = $bill->fetch(PDO::FETCH_ASSOC);
    if (!$row || !str_starts_with((string) $row['internal_ref'], 'SIM-RACE-')
        || $row['status'] !== 'approved' || $row['source'] !== 'manual') {
        fwrite(STDERR, "The synthetic approved bill is unavailable.\n");
        exit(2);
    }
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 5');
    $pdo->beginTransaction();
    try {
        $posted = accountingPostJe($tenantId, [
            'entity_id' => (int) $row['entity_id'],
            'posting_date' => date('Y-m-d'),
            'currency' => 'USD',
            'source_module' => 'ap',
            'source_ref_type' => 'ap_bill',
            'source_ref_id' => $billId,
            'idempotency_key' => 'sim:ap_race:' . $row['internal_ref'],
            'memo' => 'Disposable two-session AP race check',
            'lines' => [
                ['account_code' => '6990', 'debit' => 7.25, 'credit' => 0],
                ['account_code' => '2000', 'debit' => 0, 'credit' => 7.25],
            ],
        ], null, true);
        $pdo->prepare(
            'INSERT INTO accounting_subledger_links
                (tenant_id, source_module, source_record_id, journal_entry_id, link_kind)
             VALUES (:t, "ap", :sr, :je, "primary")'
        )->execute([
            't' => $tenantId, 'sr' => 'ap_bill:' . $billId, 'je' => (int) $posted['je_id'],
        ]);
        fwrite(STDOUT, "journal_prepared\n");
        fflush(STDOUT);
        apAttachPostedBillJournal($pdo, $tenantId, $billId, (int) $posted['je_id']);
        $pdo->commit();
        fwrite(STDOUT, "unexpected_attachment\n");
        exit(1);
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (!str_contains($e->getMessage(), 'Bill changed during posting')) {
            fwrite(STDERR, $e->getMessage() . "\n");
            exit(1);
        }
        fwrite(STDOUT, "attachment_refused\n");
        exit(0);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}

if (($args['cleanup'] ?? '') !== 'yes') {
    fwrite(STDERR, "Use --tenant=999 --cleanup=yes.\n");
    exit(2);
}

$billId = 0;
$internalRef = 'SIM-RACE-' . bin2hex(random_bytes(5));
$checks = [];
$proc = null;
$pipes = [];
try {
    $entity = $pdo->prepare('SELECT id FROM accounting_entities WHERE tenant_id = :t AND active = 1 ORDER BY id LIMIT 1');
    $entity->execute(['t' => $tenantId]);
    $entityId = (int) $entity->fetchColumn();
    if ($entityId <= 0) throw new RuntimeException('No active simulation entity');

    $create = $pdo->prepare(
        'INSERT INTO ap_bills
            (tenant_id, entity_id, bill_number, internal_ref, vendor_name, vendor_type,
             received_at, bill_date, due_date, currency, subtotal, tax_total,
             total, amount_paid, amount_due, status, source)
         VALUES
            (:t, :e, :ref, :ref2, "SIM AP Race", "other", :d, :d2, :d3,
             "USD", 7.25, 0, 7.25, 0, 7.25, "approved", "manual")'
    );
    $today = date('Y-m-d');
    $create->execute([
        't' => $tenantId, 'e' => $entityId, 'ref' => $internalRef, 'ref2' => $internalRef,
        'd' => $today, 'd2' => $today, 'd3' => $today,
    ]);
    $billId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO ap_bill_lines
            (bill_id, line_no, source_type, description, quantity, unit,
             unit_price, subtotal, tax_rate_pct, tax_amount, total, gl_expense_account_code)
         VALUES (:id, 1, "manual", "Disposable AP race check", 1, "item",
                 7.25, 7.25, 0, 0, 7.25, "6990")'
    )->execute(['id' => $billId]);

    $pdo->beginTransaction();
    $lock = $pdo->prepare('SELECT id FROM ap_bills WHERE tenant_id = :t AND id = :id FOR UPDATE');
    $lock->execute(['t' => $tenantId, 'id' => $billId]);
    if (!(int) $lock->fetchColumn()) throw new RuntimeException('Synthetic bill lock failed');

    $cmd = [PHP_BINARY, __FILE__, '--mode=child', '--tenant=999', '--bill-id=' . $billId];
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__);
    if (!is_resource($proc)) throw new RuntimeException('Second PHP session could not start');
    fclose($pipes[0]);
    stream_set_timeout($pipes[1], 15);
    $prepared = trim((string) fgets($pipes[1]));
    if ($prepared !== 'journal_prepared') {
        throw new RuntimeException('Second session did not prepare a journal: ' . $prepared);
    }
    $checks['second_session_prepared_journal'] = true;
    $bill = $pdo->prepare('SELECT * FROM ap_bills WHERE tenant_id = :t AND id = :id');
    $bill->execute(['t' => $tenantId, 'id' => $billId]);
    $locked = $bill->fetch(PDO::FETCH_ASSOC);
    $checks['void_sees_no_committed_activity'] = !apBillHasLedgerOrPaymentActivity($pdo, $tenantId, $locked);
    if (!$checks['void_sees_no_committed_activity']) throw new RuntimeException('Unexpected committed AP activity');
    $void = $pdo->prepare(
        'UPDATE ap_bills SET status = "void", amount_due = 0, voided_at = NOW(),
                void_reason = "Disposable two-session race check"
          WHERE tenant_id = :t AND id = :id AND status = "approved"'
    );
    $void->execute(['t' => $tenantId, 'id' => $billId]);
    if ($void->rowCount() !== 1) throw new RuntimeException('Synthetic void failed');
    $pdo->commit();

    $childResult = trim((string) fgets($pipes[1]));
    $childError = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $childExit = proc_close($proc);
    $proc = null;
    $checks['posting_attachment_refused'] = $childResult === 'attachment_refused' && $childExit === 0;
    if (!$checks['posting_attachment_refused']) {
        throw new RuntimeException('Second session did not refuse attachment: ' . $childResult . ' ' . $childError);
    }

    $bill->execute(['t' => $tenantId, 'id' => $billId]);
    $after = $bill->fetch(PDO::FETCH_ASSOC);
    $checks['bill_void_without_journal'] = $after['status'] === 'void'
        && (float) $after['amount_due'] === 0.0 && empty($after['journal_entry_id']);
    $je = $pdo->prepare(
        'SELECT 1 FROM accounting_journal_entries
          WHERE tenant_id = :t AND source_module = "ap"
            AND source_ref_type = "ap_bill" AND source_ref_id = :id LIMIT 1'
    );
    $je->execute(['t' => $tenantId, 'id' => $billId]);
    $checks['losing_journal_rolled_back'] = !$je->fetchColumn();
    $link = $pdo->prepare(
        'SELECT 1 FROM accounting_subledger_links
          WHERE tenant_id = :t AND source_module = "ap" AND source_record_id = :sr LIMIT 1'
    );
    $link->execute(['t' => $tenantId, 'sr' => 'ap_bill:' . $billId]);
    $checks['losing_source_link_rolled_back'] = !$link->fetchColumn();
    if (in_array(false, $checks, true)) throw new RuntimeException('Race left an inconsistent AP record');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $checks['error'] = $e->getMessage();
} finally {
    if (is_resource($proc)) {
        proc_terminate($proc);
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        proc_close($proc);
    }
    if ($billId > 0) {
        $verify = $pdo->prepare(
            'SELECT b.status, b.journal_entry_id,
                    (SELECT COUNT(*) FROM accounting_journal_entries je
                      WHERE je.tenant_id = b.tenant_id AND je.source_module = "ap"
                        AND je.source_ref_type = "ap_bill" AND je.source_ref_id = b.id) AS journals,
                    (SELECT COUNT(*) FROM accounting_subledger_links sl
                      WHERE sl.tenant_id = b.tenant_id AND sl.source_module = "ap"
                        AND sl.source_record_id = :sr) AS links
               FROM ap_bills b WHERE b.tenant_id = :t AND b.id = :id'
        );
        $verify->execute(['sr' => 'ap_bill:' . $billId, 't' => $tenantId, 'id' => $billId]);
        $row = $verify->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['status'] === 'void' && !$row['journal_entry_id']
            && (int) $row['journals'] === 0 && (int) $row['links'] === 0) {
            $pdo->prepare('DELETE FROM ap_bill_lines WHERE bill_id = :id')->execute(['id' => $billId]);
            $pdo->prepare('DELETE FROM ap_bills WHERE tenant_id = :t AND id = :id')
                ->execute(['t' => $tenantId, 'id' => $billId]);
            $checks['synthetic_bill_cleaned_up'] = true;
        } else {
            $checks['synthetic_bill_cleaned_up'] = false;
        }
    }
}

echo json_encode(['checks' => $checks], JSON_PRETTY_PRINT) . "\n";
exit(in_array(false, $checks, true) || isset($checks['error']) ? 1 : 0);

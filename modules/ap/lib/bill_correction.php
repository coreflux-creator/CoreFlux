<?php
/** Source-owned correction of an unpaid, manually entered AP bill. */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/tx_helpers.php';
require_once __DIR__ . '/../../accounting/lib/accounting.php';

/** Reused by the detail screen and the locked correction transaction. */
function apAssertPostedManualBillCorrectable(\PDO $pdo, int $tenantId, array $bill, bool $lockJournal = false): array
{
    $billId = (int) $bill['id'];
    if ($bill['status'] !== 'approved' || $bill['source'] !== 'manual'
        || empty($bill['journal_entry_id']) || !empty($bill['intercompany_group_id'])) {
        throw new \RuntimeException('Only an unpaid, posted manual bill without an intercompany split can be corrected here');
    }
    if (abs((float) $bill['amount_paid']) > 0.005
        || abs((float) $bill['amount_due'] - (float) $bill['total']) > 0.005
        || !empty($bill['placement_id']) || !empty($bill['linked_ar_invoice_id'])) {
        throw new \RuntimeException('This bill has payments or downstream placement/customer obligations; review those first');
    }

    $alloc = $pdo->prepare('SELECT 1 FROM ap_payment_allocations WHERE bill_id = :bill_id LIMIT 1');
    $alloc->execute(['bill_id' => $billId]);
    if ($alloc->fetchColumn()) throw new \RuntimeException('A payment is reserved or allocated to this bill');

    $line = $pdo->prepare('SELECT 1 FROM ap_bill_lines WHERE bill_id = :bill_id AND source_type <> "manual" LIMIT 1');
    $line->execute(['bill_id' => $billId]);
    if ($line->fetchColumn()) throw new \RuntimeException('Source-linked bill lines need their own correction workflow');

    $obligation = $pdo->prepare(
        'SELECT 1 FROM placement_economic_obligations WHERE tenant_id = :tenant_id AND ap_bill_id = :bill_id LIMIT 1'
    );
    $obligation->execute(['tenant_id' => $tenantId, 'bill_id' => $billId]);
    if ($obligation->fetchColumn()) throw new \RuntimeException('A placement obligation is linked to this bill');

    $originalId = (int) $bill['journal_entry_id'];
    $journalStmt = $pdo->prepare(
        'SELECT * FROM accounting_journal_entries WHERE tenant_id = :tenant_id AND id = :id'
        . ($lockJournal ? ' FOR UPDATE' : '')
    );
    $journalStmt->execute(['tenant_id' => $tenantId, 'id' => $originalId]);
    $journal = $journalStmt->fetch(\PDO::FETCH_ASSOC);
    if (!$journal || $journal['status'] !== 'posted' || $journal['source_module'] !== 'ap'
        || (int) $journal['entity_id'] !== (int) ($bill['entity_id'] ?: $journal['entity_id'])
        || (string) $journal['currency'] !== (string) $bill['currency']) {
        throw new \RuntimeException('The linked AP journal is missing or does not match this bill');
    }
    if ($journal['source_ref_type'] !== null
        && !($journal['source_ref_type'] === 'ap_bill' && (int) $journal['source_ref_id'] === $billId)) {
        throw new \RuntimeException('The linked journal belongs to a different AP source');
    }

    $sourceRecordId = 'ap_bill:' . $billId;
    $links = $pdo->prepare(
        'SELECT journal_entry_id FROM accounting_subledger_links
          WHERE tenant_id = :tenant_id AND source_module = "ap"
            AND source_record_id = :source_record_id AND link_kind = "primary"'
    );
    $links->execute(['tenant_id' => $tenantId, 'source_record_id' => $sourceRecordId]);
    $linkedIds = array_map('intval', $links->fetchAll(\PDO::FETCH_COLUMN));
    if (array_diff($linkedIds, [$originalId])
        || (!$linkedIds && !($journal['source_ref_type'] === 'ap_bill'
            && (int) $journal['source_ref_id'] === $billId))) {
        throw new \RuntimeException('The original journal linkage needs review before this bill can be corrected');
    }
    $otherLink = $pdo->prepare(
        'SELECT 1 FROM accounting_subledger_links
          WHERE tenant_id = :tenant_id AND journal_entry_id = :journal_id AND link_kind = "primary"
            AND (source_module <> "ap" OR source_record_id <> :source_record_id) LIMIT 1'
    );
    $otherLink->execute([
        'tenant_id' => $tenantId, 'journal_id' => $originalId, 'source_record_id' => $sourceRecordId,
    ]);
    if ($otherLink->fetchColumn()) throw new \RuntimeException('The journal is linked to another source');

    $otherBill = $pdo->prepare(
        'SELECT 1 FROM ap_bills
          WHERE tenant_id = :tenant_id AND journal_entry_id = :journal_id AND id <> :bill_id LIMIT 1'
    );
    $otherBill->execute(['tenant_id' => $tenantId, 'journal_id' => $originalId, 'bill_id' => $billId]);
    if ($otherBill->fetchColumn()) throw new \RuntimeException('Another bill shares this journal');

    $extra = $pdo->prepare(
        'SELECT 1 FROM accounting_journal_entries
          WHERE tenant_id = :tenant_id AND source_module = "ap"
            AND source_ref_type = "ap_bill" AND source_ref_id = :bill_id
            AND id <> :journal_id LIMIT 1'
    );
    $extra->execute(['tenant_id' => $tenantId, 'bill_id' => $billId, 'journal_id' => $originalId]);
    if ($extra->fetchColumn()) throw new \RuntimeException('Another AP journal is linked to this bill');

    $apLine = $pdo->prepare(
        'SELECT COALESCE(SUM(jl.credit - jl.debit), 0)
           FROM accounting_journal_entry_lines jl
           JOIN accounting_accounts a ON a.id = jl.account_id AND a.tenant_id = jl.tenant_id
          WHERE jl.tenant_id = :tenant_id AND jl.je_id = :journal_id AND a.code = "2000"'
    );
    $apLine->execute(['tenant_id' => $tenantId, 'journal_id' => $originalId]);
    if (abs((float) $apLine->fetchColumn() - (float) $bill['total']) > 0.005) {
        throw new \RuntimeException('The AP control amount does not equal this bill; review the journal first');
    }

    $bankMatch = $pdo->prepare(
        'SELECT 1 FROM accounting_bank_statement_lines
          WHERE tenant_id = :tenant_id AND matched_je_id = :journal_id LIMIT 1'
    );
    $bankMatch->execute(['tenant_id' => $tenantId, 'journal_id' => $originalId]);
    if ($bankMatch->fetchColumn()) throw new \RuntimeException('The bill journal is matched to a bank line');

    return ['original_je_id' => $originalId, 'source_record_id' => $sourceRecordId];
}

function apCorrectPostedManualBill(int $tenantId, int $billId, string $reason, ?int $actorUserId): array
{
    $reason = trim($reason);
    if ($tenantId <= 0 || $billId <= 0 || $reason === '') {
        throw new \InvalidArgumentException('Bill and correction reason are required');
    }

    $pdo = getDB();
    $ownsTransaction = cf_tx_begin($pdo);
    try {
        $stmt = $pdo->prepare('SELECT * FROM ap_bills WHERE tenant_id = :tenant_id AND id = :id FOR UPDATE');
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $billId]);
        $bill = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$bill) throw new \RuntimeException('Bill not found');
        $eligibility = apAssertPostedManualBillCorrectable($pdo, $tenantId, $bill, true);
        $originalId = $eligibility['original_je_id'];
        $sourceRecordId = $eligibility['source_record_id'];

        $reversal = accountingReverseJe($tenantId, $originalId, $reason, $actorUserId);
        if (!empty($reversal['idempotent_replay'])) throw new \RuntimeException('This bill journal was already reversed');
        $reversalId = (int) $reversal['je_id'];

        $void = $pdo->prepare(
            'UPDATE ap_bills SET status = "void", amount_due = 0, voided_at = NOW(), voided_by_user_id = :actor,
                    void_reason = :reason
              WHERE tenant_id = :tenant_id AND id = :id AND status = "approved" AND journal_entry_id = :journal_id'
        );
        $void->execute([
            'actor' => $actorUserId, 'reason' => $reason, 'tenant_id' => $tenantId,
            'id' => $billId, 'journal_id' => $originalId,
        ]);
        if ($void->rowCount() !== 1) throw new \RuntimeException('Bill changed while correcting it');

        $pdo->prepare(
            'INSERT INTO accounting_subledger_links
                (tenant_id, source_module, source_record_id, journal_entry_id, link_kind)
             VALUES (:tenant_id, "ap", :source_record_id, :journal_entry_id, "reversal")'
        )->execute([
            'tenant_id' => $tenantId, 'source_record_id' => $sourceRecordId,
            'journal_entry_id' => $reversalId,
        ]);
        cf_tx_commit($pdo, $ownsTransaction);
        return [
            'bill_id' => $billId,
            'original_je_id' => $originalId,
            'reversal_je_id' => $reversalId,
        ];
    } catch (\Throwable $e) {
        cf_tx_rollback($pdo, $ownsTransaction);
        throw $e;
    }
}

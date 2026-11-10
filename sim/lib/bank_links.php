<?php
declare(strict_types=1);

function simLinkPostedBankLine(int $tenantId, int $lineId, int $jeId): void {
    if ($tenantId <= 0 || $lineId <= 0 || $jeId <= 0) {
        throw new InvalidArgumentException('A tenant, bank line, and posted journal entry are required');
    }
    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $line = $pdo->prepare(
            'SELECT l.id, l.amount, l.match_status, l.matched_je_id, b.gl_account_code,
                    b.entity_id, b.status AS account_status
               FROM accounting_bank_statement_lines l
               JOIN accounting_bank_accounts b ON b.id = l.bank_account_id AND b.tenant_id = l.tenant_id
              WHERE l.tenant_id = :tenant_id AND l.id = :line_id FOR UPDATE'
        );
        $line->execute(['tenant_id' => $tenantId, 'line_id' => $lineId]);
        $source = $line->fetch(PDO::FETCH_ASSOC);
        if (!$source || $source['account_status'] !== 'active') {
            throw new RuntimeException('Simulation bank line or active bank account is missing');
        }

        $journal = $pdo->prepare(
            'SELECT entity_id, status FROM accounting_journal_entries
              WHERE tenant_id = :tenant_id AND id = :je_id FOR UPDATE'
        );
        $journal->execute(['tenant_id' => $tenantId, 'je_id' => $jeId]);
        $posted = $journal->fetch(PDO::FETCH_ASSOC);
        if (!$posted || $posted['status'] !== 'posted'
            || (int) $posted['entity_id'] !== (int) $source['entity_id']) {
            throw new RuntimeException('Bank line and posted journal belong to different entities');
        }

        $bankSide = $pdo->prepare(
            'SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
               FROM accounting_journal_entry_lines jl
               JOIN accounting_accounts a ON a.id = jl.account_id
              WHERE jl.je_id = :je_id AND a.tenant_id = :tenant_id AND a.code = :code'
        );
        $bankSide->execute([
            'je_id' => $jeId, 'tenant_id' => $tenantId, 'code' => (string) $source['gl_account_code'],
        ]);
        if (round((float) $bankSide->fetchColumn(), 2) !== round((float) $source['amount'], 2)) {
            throw new RuntimeException('Bank line amount does not match the posted cash-side journal line');
        }

        if ($source['match_status'] === 'matched') {
            if ((int) ($source['matched_je_id'] ?? 0) !== $jeId) {
                throw new RuntimeException('Bank line is matched to another journal entry');
            }
        } elseif ($source['match_status'] === 'unmatched' && (int) ($source['matched_je_id'] ?? 0) === 0) {
            $pdo->prepare(
                'UPDATE accounting_bank_statement_lines
                    SET match_status = "matched", matched_je_id = :je_id, matched_at = NOW()
                  WHERE tenant_id = :tenant_id AND id = :line_id'
            )->execute(['je_id' => $jeId, 'tenant_id' => $tenantId, 'line_id' => $lineId]);
        } else {
            throw new RuntimeException('Bank line has an incompatible match state');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function simInvariantBankLineMatched(PDO $pdo, int $tenantId, array $state): array {
    $lineIds = array_values($state['bank_lines'] ?? []);
    $missing = [];
    if (!$lineIds) $missing[] = ['issue' => 'scenario did not create a bank line'];
    $stmt = $pdo->prepare(
        'SELECT l.match_status, l.matched_je_id, j.status AS journal_status, j.tenant_id AS journal_tenant
           FROM accounting_bank_statement_lines l
           LEFT JOIN accounting_journal_entries j ON j.id = l.matched_je_id
          WHERE l.tenant_id = :tenant_id AND l.id = :line_id'
    );
    foreach ($lineIds as $lineId) {
        $stmt->execute(['tenant_id' => $tenantId, 'line_id' => $lineId]);
        $line = $stmt->fetch(PDO::FETCH_ASSOC);
        $expectedJe = (int) ($state['posted_bank_lines'][$lineId] ?? 0);
        if (!$line || $line['match_status'] !== 'matched'
            || $expectedJe <= 0 || (int) ($line['matched_je_id'] ?? 0) !== $expectedJe
            || $line['journal_status'] !== 'posted' || (int) $line['journal_tenant'] !== $tenantId) {
            $missing[] = ['line_id' => $lineId, 'issue' => 'bank line is not matched to its posted journal'];
        }
    }
    return [
        'name' => 'bank_line_matched',
        'ok' => $missing === [],
        'severity' => 'error',
        'details' => ['mismatch_count' => count($missing), 'sample' => array_slice($missing, 0, 5)],
    ];
}

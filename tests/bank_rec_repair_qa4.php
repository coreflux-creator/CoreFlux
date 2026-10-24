<?php
/** Rollback-only posted-lineage repair proof on the disposable QA4 database. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--qa4-rollback'
    || getenv('COREFLUX_ENV') !== 'coreaccounting') {
    fwrite(STDERR, "Run only on isolated QA4 with --qa4-rollback.\n");
    exit(2);
}

$root = realpath((string) getenv('COREFLUX_QA4_ROOT'));
if ($root !== '/home/1516771.cloudwaysapps.com/fyqqcqhwwr/public_html') {
    throw new RuntimeException('Refusing to run outside the disposable CoreAccounting QA4 webroot.');
}
require_once $root . '/core/tenant_scope.php';
require_once $root . '/core/business_integrity.php';
require_once $root . '/modules/accounting/lib/accounting.php';
require_once $root . '/modules/accounting/lib/bank_rec.php';

$pdo = getDB();
if (!$pdo || (string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'fyqqcqhwwr') {
    throw new RuntimeException('Refusing to run outside the disposable CoreAccounting QA4 database.');
}
$entities = $pdo->query(
    'SELECT tenant_id, id FROM accounting_entities WHERE active = 1 ORDER BY tenant_id, id'
)->fetchAll(PDO::FETCH_ASSOC);
$byTenant = [];
foreach ($entities as $entity) $byTenant[(int) $entity['tenant_id']][] = (int) $entity['id'];
$tenantId = 0;
foreach ($byTenant as $candidateTenant => $ids) {
    if (count($ids) >= 2) { $tenantId = $candidateTenant; break; }
}
if ($tenantId <= 0) throw new RuntimeException('QA4 needs two synthetic legal entities for this proof.');
[$bankEntityId, $otherEntityId] = $byTenant[$tenantId];
setRequestTenantId($tenantId);

$assertions = 0;
$check = static function (bool $ok, string $message) use (&$assertions): void {
    if (!$ok) throw new RuntimeException($message);
    $assertions++;
};
$lineCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM accounting_bank_statement_lines')->fetchColumn();
$journalCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM accounting_journal_entries')->fetchColumn();
$auditChecks = static function (int $tenantId): array {
    $checks = [];
    foreach (businessIntegrityAudit($tenantId)['checks'] as $check) {
        $checks[$check['key']] = $check;
    }
    return $checks;
};
$baselineAudit = $auditChecks($tenantId);

$pdo->beginTransaction();
try {
    $cashCode = '18' . random_int(100000, 999999);
    $offsetCode = '19' . random_int(100000, 999999);
    $newAccount = $pdo->prepare(
        'INSERT INTO accounting_accounts
            (tenant_id, code, name, account_type, normal_side, currency, is_postable, active)
         VALUES (:t, :code, :name, "asset", "debit", "USD", 1, 1)'
    );
    foreach ([$cashCode => 'Rollback-only repair cash', $offsetCode => 'Rollback-only offset'] as $code => $name) {
        $newAccount->execute(['t' => $tenantId, 'code' => $code, 'name' => $name]);
    }
    $pdo->prepare(
        'INSERT INTO accounting_bank_accounts (tenant_id, entity_id, name, gl_account_code, currency)
         VALUES (:t, :entity_id, "Rollback-only repair bank", :code, "USD")'
    )->execute(['t' => $tenantId, 'entity_id' => $bankEntityId, 'code' => $cashCode]);
    $bankId = (int) $pdo->lastInsertId();

    $makeLine = static function () use ($pdo, $tenantId, $bankId): int {
        $pdo->prepare(
            'INSERT INTO accounting_bank_statement_lines
                (tenant_id, bank_account_id, posted_date, description, amount, match_status, fitid)
             VALUES (:t, :bank, "2026-10-01", "Rollback-only repair proof", 10.00,
                     "unmatched", :fitid)'
        )->execute(['t' => $tenantId, 'bank' => $bankId, 'fitid' => 'QA-REPAIR-' . bin2hex(random_bytes(8))]);
        return (int) $pdo->lastInsertId();
    };
    $post = static function (int $lineId, int $entityId, float $cashAmount, bool $direct = true)
        use ($tenantId, $cashCode, $offsetCode): int {
        $payload = [
            'entity_id' => $entityId,
            'posting_date' => '2026-10-01',
            'currency' => 'USD',
            'memo' => 'Rollback-only bank repair proof',
            'idempotency_key' => 'qa-bank-repair-' . bin2hex(random_bytes(8)),
            'lines' => [
                ['account_code' => $cashCode, 'debit' => $cashAmount, 'credit' => 0],
                ['account_code' => $offsetCode, 'debit' => 0, 'credit' => $cashAmount],
            ],
        ];
        $payload['source_module'] = 'treasury_feed';
        $payload['source_ref_type'] = $direct ? 'bank_statement_line' : 'bank_statement_line_reversal';
        $payload['source_ref_id'] = $lineId;
        return (int) accountingPostJe($tenantId, $payload, null, true)['je_id'];
    };
    $lineState = static function (int $lineId) use ($pdo, $tenantId): array {
        $stmt = $pdo->prepare(
            'SELECT match_status, matched_je_id FROM accounting_bank_statement_lines
              WHERE tenant_id = :t AND id = :id'
        );
        $stmt->execute(['t' => $tenantId, 'id' => $lineId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    };

    $goodLine = $makeLine();
    $goodJe = $post($goodLine, $bankEntityId, 10.00);
    $repaired = bankRecRepairPostedMatches($tenantId, $bankId, $goodLine);
    $check($repaired['repaired'] === 1 && !$repaired['conflicts'], 'valid posted Treasury lineage repairs once');
    $check($lineState($goodLine)['match_status'] === 'matched'
        && (int) $lineState($goodLine)['matched_je_id'] === $goodJe, 'valid repair links the correct journal');

    $wrongEntityLine = $makeLine();
    $wrongEntityJe = $post($wrongEntityLine, $otherEntityId, 10.00);
    $wrongEntity = bankRecRepairPostedMatches($tenantId, $bankId, $wrongEntityLine);
    $check($wrongEntity['repaired'] === 0 && isset($wrongEntity['conflicts'][$wrongEntityLine])
        && $wrongEntity['conflicts'][$wrongEntityLine]['journal_ids'] === [$wrongEntityJe],
        'wrong legal entity stays in review');
    $check($lineState($wrongEntityLine)['match_status'] === 'unmatched',
        'wrong-entity cash is not silently reconciled');
    try {
        bankRecGuardPostedLineage($tenantId, $wrongEntityLine);
        throw new RuntimeException('Wrong-entity line was not blocked');
    } catch (RuntimeException $e) {
        $check(str_contains($e->getMessage(), 'same legal entity'), 'write guard blocks wrong-entity lineage');
    }

    $wrongAmountLine = $makeLine();
    $wrongAmountJe = $post($wrongAmountLine, $bankEntityId, 9.00);
    $wrongAmount = bankRecRepairPostedMatches($tenantId, $bankId, $wrongAmountLine);
    $check($wrongAmount['repaired'] === 0 && isset($wrongAmount['conflicts'][$wrongAmountLine])
        && $lineState($wrongAmountLine)['match_status'] === 'unmatched',
        'wrong cash movement stays in review');

    $ambiguousLine = $makeLine();
    $post($ambiguousLine, $bankEntityId, 10.00);
    $post($ambiguousLine, $bankEntityId, 10.00);
    $ambiguous = bankRecRepairPostedMatches($tenantId, $bankId, $ambiguousLine);
    $check($ambiguous['repaired'] === 0 && count($ambiguous['conflicts'][$ambiguousLine]['journal_ids'] ?? []) === 2
        && $lineState($ambiguousLine)['match_status'] === 'unmatched',
        'multiple posted source journals require review');

    $reversalLine = $makeLine();
    $reversalJe = $post($reversalLine, $bankEntityId, 10.00, false);
    $pdo->prepare(
        'INSERT INTO accounting_subledger_links
            (tenant_id, source_module, source_record_id, journal_entry_id, link_kind)
         VALUES (:t, "treasury_feed", :source_id, :je, "reversal")'
    )->execute(['t' => $tenantId, 'source_id' => 'bank_line:' . $reversalLine, 'je' => $reversalJe]);
    $reversal = bankRecRepairPostedMatches($tenantId, $bankId, $reversalLine);
    $check($reversal['repaired'] === 0 && !$reversal['conflicts']
        && $lineState($reversalLine)['match_status'] === 'unmatched',
        'reversal-only Treasury link is not treated as an active posting');

    $pdo->prepare(
        'INSERT INTO accounting_bank_rules
            (tenant_id, bank_account_id, name, pattern_kind, pattern, direction,
             target_account_code, is_approved, status)
         VALUES (:t, :bank, "Rollback-only repair rule", "contains", "Rollback-only repair proof",
                 "credit", :code, 1, "active")'
    )->execute(['t' => $tenantId, 'bank' => $bankId, 'code' => $offsetCode]);
    $ruleId = (int) $pdo->lastInsertId();
    $ruleResult = bankRecApplyRules($tenantId, $bankId, null);
    $check($ruleResult['auto_applied'] === 1 && $ruleResult['lines_evaluated'] === 1
        && $ruleResult['lineage_conflict_count'] === 3,
        'rules skip three conflicted posted lines and review only the unbooked line');
    $ruleLine = $pdo->prepare(
        'SELECT applied_rule_id FROM accounting_bank_statement_lines WHERE tenant_id = :t AND id = :id'
    );
    foreach ([$wrongEntityLine, $wrongAmountLine, $ambiguousLine] as $conflictedLine) {
        $ruleLine->execute(['t' => $tenantId, 'id' => $conflictedLine]);
        $check($ruleLine->fetchColumn() === null, 'conflicted line receives no rule suggestion');
    }
    $ruleLine->execute(['t' => $tenantId, 'id' => $reversalLine]);
    $check((int) $ruleLine->fetchColumn() === $ruleId,
        'rule still applies to the unbooked bank line');

    $unresolvedAudit = $auditChecks($tenantId);
    $check(($unresolvedAudit['bank_match_integrity']['issue_count'] ?? -1)
        === ($baselineAudit['bank_match_integrity']['issue_count'] ?? 0),
        'valid repaired match keeps the cash-movement audit clean');
    $check(($unresolvedAudit['bank_unmatched_explicit_lineage']['issue_count'] ?? -1)
        === ($baselineAudit['bank_unmatched_explicit_lineage']['issue_count'] ?? 0) + 3,
        'unmatched direct posted lineage surfaces three review exceptions');

    $forceMatch = $pdo->prepare(
        'UPDATE accounting_bank_statement_lines
            SET match_status = "matched", matched_je_id = :je
          WHERE tenant_id = :t AND id = :id'
    );
    foreach ([
        [$wrongEntityLine, $wrongEntityJe],
        [$wrongAmountLine, $wrongAmountJe],
        [$ambiguousLine, $goodJe],
    ] as [$lineId, $jeId]) {
        $forceMatch->execute(['t' => $tenantId, 'id' => $lineId, 'je' => $jeId]);
    }
    $wrongCurrencyLine = $makeLine();
    $wrongCurrencyJe = $post($wrongCurrencyLine, $bankEntityId, 10.00);
    $pdo->prepare('UPDATE accounting_journal_entries SET currency = "EUR" WHERE tenant_id = :t AND id = :id')
        ->execute(['t' => $tenantId, 'id' => $wrongCurrencyJe]);
    $forceMatch->execute(['t' => $tenantId, 'id' => $wrongCurrencyLine, 'je' => $wrongCurrencyJe]);
    $invalidAudit = $auditChecks($tenantId);
    $check(($invalidAudit['bank_match_integrity']['issue_count'] ?? -1)
        === ($baselineAudit['bank_match_integrity']['issue_count'] ?? 0) + 3,
        'matched-line audit finds wrong entity, cash amount and currency');
    $check(($invalidAudit['bank_duplicate_journal_matches']['issue_count'] ?? -1)
        === ($baselineAudit['bank_duplicate_journal_matches']['issue_count'] ?? 0) + 1,
        'audit finds two bank lines claiming one posted journal');
    $check(($invalidAudit['bank_unmatched_explicit_lineage']['issue_count'] ?? -1)
        === ($baselineAudit['bank_unmatched_explicit_lineage']['issue_count'] ?? 0),
        'resolved synthetic lines no longer appear as unmatched exceptions');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    setRequestTenantId(null);
}

$check((int) $pdo->query('SELECT COUNT(*) FROM accounting_bank_statement_lines')->fetchColumn()
    === $lineCountBefore, 'rollback leaves no bank lines');
$check((int) $pdo->query('SELECT COUNT(*) FROM accounting_journal_entries')->fetchColumn()
    === $journalCountBefore, 'rollback leaves no journals');
echo "Passed: {$assertions}; Failed: 0\n";

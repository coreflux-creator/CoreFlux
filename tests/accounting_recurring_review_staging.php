<?php
/** Hosted recurring-journal acceptance using invented records only. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--execute') {
    fwrite(STDERR, "Run only on isolated CoreFlux staging with --execute.\n");
    exit(2);
}

define('QA_LIFECYCLE_LIBRARY_MODE', true);
require_once __DIR__ . '/accounting_staging_lifecycle.php';
require_once __DIR__ . '/../modules/accounting/lib/recurring_je.php';

$actor = null;
$cookie = null;
$templateId = 0;
try {
    $entity = qaOne($pdo,
        'SELECT id FROM accounting_entities WHERE tenant_id = :t AND code = :code',
        ['t' => QA_TENANT, 'code' => QA_ENTITY_CODE]
    );
    if (!$entity) throw new RuntimeException('Synthetic staging entity is missing');
    $entityId = (int) $entity['id'];

    $suffix = strtoupper(bin2hex(random_bytes(4)));
    $expenseCode = 'QA-EX-' . $suffix;
    $revenueCode = 'QA-RV-' . $suffix;
    $insertAccount = $pdo->prepare('INSERT INTO accounting_accounts
        (tenant_id, code, name, account_type, normal_side, is_postable, active)
        VALUES (:t, :code, :name, :type, :side, 1, 1)');
    $insertAccount->execute(['t' => QA_TENANT, 'code' => $expenseCode,
        'name' => 'Synthetic recurring review expense', 'type' => 'expense', 'side' => 'debit']);
    $insertAccount->execute(['t' => QA_TENANT, 'code' => $revenueCode,
        'name' => 'Synthetic recurring review revenue', 'type' => 'revenue', 'side' => 'credit']);

    $actor = qaEnsureActor($pdo, 'recurring-review');
    $cookie = tempnam(sys_get_temp_dir(), 'cf-recurring-');
    qaLogin($actor, $cookie);

    foreach ([$expenseCode, $revenueCode] as $code) {
        $accountList = qaRequest('/modules/accounting/api/accounts.php?q=' . urlencode($code),
            'GET', null, $cookie);
        $account = array_values(array_filter((array) ($accountList['rows'] ?? []),
            static fn(array $row): bool => ($row['code'] ?? '') === $code))[0] ?? null;
        qaExpect($account !== null && !empty($account['general_journal_eligible']),
            "ordinary {$code} appears in the journal account picker");
    }

    $created = qaRequest('/modules/accounting/api/recurring_journal_entries.php', 'POST', [
        'entity_id' => $entityId,
        'name' => 'Synthetic recurring review ' . $suffix,
        'memo' => 'Invented staging-only review entry',
        'cadence' => 'monthly',
        'next_run_date' => date('Y-m-d'),
        'auto_post' => 0,
        'lines' => [
            ['account_code' => $expenseCode, 'debit' => 12.34, 'credit' => 0],
            ['account_code' => $revenueCode, 'debit' => 0, 'credit' => 12.34],
        ],
    ], $cookie);
    $templateId = (int) ($created['id'] ?? 0);
    qaExpect($templateId > 0, 'recurring review template created through HTTP');

    $run = qaRequest('/modules/accounting/api/recurring_journal_entries.php?action=run_now&id='
        . $templateId, 'POST', [], $cookie);
    $jeId = (int) ($run['je_id'] ?? 0);
    qaExpect($jeId > 0 && empty($run['auto_posted']), 'run stages a draft instead of posting');

    $templates = qaRequest('/modules/accounting/api/recurring_journal_entries.php',
        'GET', null, $cookie);
    $listed = array_values(array_filter((array) ($templates['rows'] ?? []),
        static fn(array $row): bool => (int) $row['id'] === $templateId))[0] ?? null;
    qaExpect($listed && (int) $listed['last_run_je_id'] === $jeId
        && $listed['last_run_je_status'] === 'draft',
        'recurring list points to the draft for review');

    $detail = qaRequest('/modules/accounting/api/journal_entries.php?id=' . $jeId,
        'GET', null, $cookie);
    qaExpect(($detail['entry']['source_module'] ?? '') === 'recurring_je'
        && (int) ($detail['entry']['source_ref_id'] ?? 0) === $templateId
        && ($detail['entry']['status'] ?? '') === 'draft',
        'journal detail retains the recurring template link and draft status');

    $posted = qaRequest('/modules/accounting/api/recurring_journal_entries.php?action=post_draft&id='
        . $jeId, 'POST', [], $cookie);
    qaExpect(($posted['status'] ?? '') === 'posted'
        && abs((float) ($posted['total_debit'] ?? 0) - 12.34) < 0.005,
        'review posts the exact draft amount through HTTP');
    $replay = qaRequest('/modules/accounting/api/recurring_journal_entries.php?action=post_draft&id='
        . $jeId, 'POST', [], $cookie);
    qaExpect(!empty($replay['idempotent_replay']) && (int) $replay['je_id'] === $jeId,
        'repeat review does not create a second journal');

    $ledger = qaOne($pdo, 'SELECT status, total_debit, total_credit
        FROM accounting_journal_entries WHERE tenant_id = :t AND entity_id = :e AND id = :id',
        ['t' => QA_TENANT, 'e' => $entityId, 'id' => $jeId]);
    qaExpect($ledger && $ledger['status'] === 'posted'
        && abs((float) $ledger['total_debit'] - 12.34) < 0.005
        && abs((float) $ledger['total_credit'] - 12.34) < 0.005,
        'posted journal is balanced in the canonical CoreFlux ledger');

    $templates = qaRequest('/modules/accounting/api/recurring_journal_entries.php',
        'GET', null, $cookie);
    $listed = array_values(array_filter((array) ($templates['rows'] ?? []),
        static fn(array $row): bool => (int) $row['id'] === $templateId))[0] ?? null;
    qaExpect(($listed['last_run_je_status'] ?? '') === 'posted',
        'recurring list updates from review draft to posted');

    $reversal = qaRequest('/modules/accounting/api/recurring_journal_entries.php?action=reverse_run&id='
        . $jeId, 'POST', ['reason' => 'Synthetic staging correction'], $cookie);
    $reversalId = (int) ($reversal['je_id'] ?? 0);
    qaExpect($reversalId > 0 && $reversalId !== $jeId
        && ($reversal['status'] ?? '') === 'posted',
        'recurring run reverses through its source workflow');
    $original = qaOne($pdo, 'SELECT status, reversed_by_je_id
        FROM accounting_journal_entries WHERE tenant_id = :t AND id = :id',
        ['t' => QA_TENANT, 'id' => $jeId]);
    qaExpect($original && $original['status'] === 'reversed'
        && (int) $original['reversed_by_je_id'] === $reversalId,
        'original run links to its posted reversal');
    $netStatement = $pdo->prepare('SELECT a.code, ROUND(SUM(l.debit - l.credit), 2) AS amount
        FROM accounting_journal_entry_lines l
        JOIN accounting_journal_entries je ON je.id = l.je_id AND je.tenant_id = l.tenant_id
        JOIN accounting_accounts a ON a.id = l.account_id AND a.tenant_id = l.tenant_id
        WHERE je.tenant_id = :t AND je.entity_id = :e AND je.id IN (:original, :reversal)
          AND je.status IN ("posted", "reversed") GROUP BY a.code');
    $netStatement->execute(['t' => QA_TENANT, 'e' => $entityId,
        'original' => $jeId, 'reversal' => $reversalId]);
    $nets = array_column($netStatement->fetchAll(PDO::FETCH_ASSOC), 'amount', 'code');
    qaExpect(count($nets) === 2
        && array_key_exists($expenseCode, $nets) && abs((float) $nets[$expenseCode]) < 0.005
        && array_key_exists($revenueCode, $nets) && abs((float) $nets[$revenueCode]) < 0.005,
        'both affected accounts net to zero in the canonical ledger');
    $repeatReversal = qaRequest('/modules/accounting/api/recurring_journal_entries.php?action=reverse_run&id='
        . $jeId, 'POST', ['reason' => 'Synthetic staging correction'], $cookie);
    qaExpect(!empty($repeatReversal['idempotent_replay'])
        && (int) $repeatReversal['je_id'] === $reversalId,
        'repeat reversal returns the existing correction');

    $beforeReplacement = qaOne($pdo, 'SELECT next_run_date, last_run_je_id
        FROM accounting_recurring_journal_entries WHERE tenant_id = :t AND id = :id',
        ['t' => QA_TENANT, 'id' => $templateId]);
    $replacementPreview = qaRequest('/modules/accounting/api/recurring_journal_entries.php?action=replacement&id='
        . $jeId, 'GET', null, $cookie);
    qaExpect((int) ($replacementPreview['original']['id'] ?? 0) === $jeId
        && ($replacementPreview['replacement'] ?? null) === null
        && count($replacementPreview['lines'] ?? []) === 2,
        'reversed run opens as an editable replacement with original lines');
    $replacementPayload = [
        'entity_id' => $entityId,
        'posting_date' => date('Y-m-d'),
        'memo' => 'Edited synthetic recurring replacement',
        'reason' => 'Correct the amount in the reviewed run',
        'lines' => [
            ['account_code' => $expenseCode, 'debit' => 14.56, 'credit' => 0],
            ['account_code' => $revenueCode, 'debit' => 0, 'credit' => 14.56],
        ],
    ];
    $prepared = qaRequest('/modules/accounting/api/recurring_journal_entries.php?action=prepare_replacement&id='
        . $jeId, 'POST', $replacementPayload, $cookie);
    $replacementId = (int) ($prepared['je_id'] ?? 0);
    qaExpect($replacementId > 0 && ($prepared['status'] ?? '') === 'draft'
        && empty($prepared['updated']), 'replacement is staged off-ledger for review');
    $foreignTenantError = null;
    try { recurringJeLinkedJournal(1002, $replacementId); }
    catch (RuntimeException $e) { $foreignTenantError = $e->getMessage(); }
    qaExpect($foreignTenantError === 'Journal entry not found',
        'replacement chain cannot be resolved from another tenant');
    $replacementPayload['lines'][0]['debit'] = 15.67;
    $replacementPayload['lines'][1]['credit'] = 15.67;
    $revised = qaRequest('/modules/accounting/api/recurring_journal_entries.php?action=prepare_replacement&id='
        . $jeId, 'POST', $replacementPayload, $cookie);
    qaExpect((int) ($revised['je_id'] ?? 0) === $replacementId
        && !empty($revised['updated']) && abs((float) $revised['total_debit'] - 15.67) < 0.005,
        'editing the replacement updates the same draft instead of duplicating it');
    $replacementDetail = qaRequest('/modules/accounting/api/journal_entries.php?id=' . $replacementId,
        'GET', null, $cookie);
    qaExpect(($replacementDetail['entry']['source_module'] ?? '') === 'recurring_je'
        && ($replacementDetail['entry']['source_ref_type'] ?? '') === 'replaces_je'
        && (int) ($replacementDetail['entry']['source_ref_id'] ?? 0) === $jeId,
        'replacement retains its original-run link and recurring source ownership');
    $postedReplacement = qaRequest('/modules/accounting/api/recurring_journal_entries.php?action=post_draft&id='
        . $replacementId, 'POST', [], $cookie);
    qaExpect(($postedReplacement['status'] ?? '') === 'posted'
        && abs((float) ($postedReplacement['total_debit'] ?? 0) - 15.67) < 0.005,
        'review posts the edited replacement to the canonical ledger');
    $repeatPost = qaRequest('/modules/accounting/api/recurring_journal_entries.php?action=post_draft&id='
        . $replacementId, 'POST', [], $cookie);
    qaExpect(!empty($repeatPost['idempotent_replay'])
        && (int) $repeatPost['je_id'] === $replacementId,
        'repeat replacement review does not post twice');
    $replacementNet = $pdo->prepare('SELECT a.code, ROUND(SUM(l.debit - l.credit), 2) AS amount
        FROM accounting_journal_entry_lines l
        JOIN accounting_journal_entries je ON je.id = l.je_id AND je.tenant_id = l.tenant_id
        JOIN accounting_accounts a ON a.id = l.account_id AND a.tenant_id = l.tenant_id
        WHERE je.tenant_id = :t AND je.entity_id = :e
          AND je.id IN (:original, :reversal, :replacement)
          AND je.status IN ("posted", "reversed") GROUP BY a.code');
    $replacementNet->execute(['t' => QA_TENANT, 'e' => $entityId,
        'original' => $jeId, 'reversal' => $reversalId, 'replacement' => $replacementId]);
    $replacementBalances = array_column($replacementNet->fetchAll(PDO::FETCH_ASSOC), 'amount', 'code');
    qaExpect(abs((float) ($replacementBalances[$expenseCode] ?? 0) - 15.67) < 0.005
        && abs((float) ($replacementBalances[$revenueCode] ?? 0) + 15.67) < 0.005,
        'original, reversal, and replacement net to only the corrected amount');
    $afterReplacement = qaOne($pdo, 'SELECT next_run_date, last_run_je_id
        FROM accounting_recurring_journal_entries WHERE tenant_id = :t AND id = :id',
        ['t' => QA_TENANT, 'id' => $templateId]);
    qaExpect($afterReplacement === $beforeReplacement,
        'replacement never rewinds or advances the recurring schedule');
    $duplicateError = null;
    try {
        qaRequest('/modules/accounting/api/recurring_journal_entries.php?action=prepare_replacement&id='
            . $jeId, 'POST', $replacementPayload, $cookie);
    } catch (RuntimeException $e) { $duplicateError = $e->getMessage(); }
    qaExpect($duplicateError !== null && str_contains($duplicateError, 'HTTP 409'),
        'posted replacement cannot be silently overwritten or duplicated');
    $manualError = null;
    try {
        qaRequest('/modules/accounting/api/journal_entries.php?action=replace&id=' . $jeId,
            'POST', $replacementPayload, $cookie);
    } catch (RuntimeException $e) { $manualError = $e->getMessage(); }
    qaExpect($manualError !== null && str_contains($manualError, 'HTTP 409'),
        'generic manual correction still refuses source-owned recurring journals');

    $secondReversal = qaRequest('/modules/accounting/api/recurring_journal_entries.php?action=reverse_run&id='
        . $replacementId, 'POST', ['reason' => 'Synthetic second correction'], $cookie);
    $secondReversalId = (int) ($secondReversal['je_id'] ?? 0);
    qaExpect($secondReversalId > 0 && $secondReversalId !== $replacementId,
        'a posted replacement can itself be reversed through the recurring source');
    $replacementPayload['lines'][0]['debit'] = 16.78;
    $replacementPayload['lines'][1]['credit'] = 16.78;
    $secondPrepared = qaRequest('/modules/accounting/api/recurring_journal_entries.php?action=prepare_replacement&id='
        . $replacementId, 'POST', $replacementPayload, $cookie);
    $secondReplacementId = (int) ($secondPrepared['je_id'] ?? 0);
    qaExpect($secondReplacementId > 0 && $secondReplacementId !== $replacementId
        && ($secondPrepared['status'] ?? '') === 'draft',
        'a second correction is staged as a separately linked draft');
    $secondPosted = qaRequest('/modules/accounting/api/recurring_journal_entries.php?action=post_draft&id='
        . $secondReplacementId, 'POST', [], $cookie);
    qaExpect(($secondPosted['status'] ?? '') === 'posted'
        && abs((float) ($secondPosted['total_debit'] ?? 0) - 16.78) < 0.005,
        'review posts the second correction without changing the template');
    $latestSchedule = qaOne($pdo, 'SELECT next_run_date, last_run_je_id
        FROM accounting_recurring_journal_entries WHERE tenant_id = :t AND id = :id',
        ['t' => QA_TENANT, 'id' => $templateId]);
    qaExpect($latestSchedule === $beforeReplacement,
        'successive corrections still leave the template schedule untouched');

    $ended = qaRequest('/modules/accounting/api/recurring_journal_entries.php?action=end&id='
        . $templateId, 'POST', [], $cookie);
    qaExpect(($ended['status'] ?? '') === 'ended',
        'synthetic schedule ended; linked journals remain for audit');
    $endReplay = qaRequest('/modules/accounting/api/recurring_journal_entries.php?action=end&id='
        . $templateId, 'POST', [], $cookie);
    qaExpect(!empty($endReplay['idempotent_replay']), 'ending an ended schedule is idempotent');
    $resumeError = null;
    try {
        qaRequest('/modules/accounting/api/recurring_journal_entries.php?action=resume&id='
            . $templateId, 'POST', [], $cookie);
    } catch (RuntimeException $e) {
        $resumeError = $e->getMessage();
    }
    $endedRow = qaOne($pdo, 'SELECT status FROM accounting_recurring_journal_entries
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $templateId]);
    qaExpect($resumeError !== null && str_contains($resumeError, 'HTTP 409')
        && ($endedRow['status'] ?? '') === 'ended',
        'ended schedule cannot be reactivated through the API');
    echo "Staging recurring template {$templateId}, journal {$jeId}, reversal {$reversalId}, replacement {$replacementId}, second replacement {$secondReplacementId}, entity {$entityId}.\n";
} finally {
    if ($templateId > 0) {
        $pdo->prepare('UPDATE accounting_recurring_journal_entries SET status = "ended"
            WHERE tenant_id = :t AND id = :id')
            ->execute(['t' => QA_TENANT, 'id' => $templateId]);
    }
    if ($actor) {
        $pdo->prepare('UPDATE users SET is_active = 0 WHERE tenant_id = :t AND id = :id')
            ->execute(['t' => QA_TENANT, 'id' => (int) $actor['id']]);
    }
    if ($cookie && is_file($cookie)) unlink($cookie);
}

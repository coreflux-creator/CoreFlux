<?php
/** Hosted recurring-journal acceptance using invented records only. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--execute') {
    fwrite(STDERR, "Run only on isolated CoreFlux staging with --execute.\n");
    exit(2);
}

define('QA_LIFECYCLE_LIBRARY_MODE', true);
require_once __DIR__ . '/accounting_staging_lifecycle.php';

if (!defined('COREFLUX_STAGING') || !COREFLUX_STAGING) {
    throw new RuntimeException('Refusing a non-staging environment.');
}

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

    qaRequest('/modules/accounting/api/recurring_journal_entries.php?action=end&id='
        . $templateId, 'POST', [], $cookie);
    qaExpect(true, 'synthetic schedule ended; posted journal remains for audit');
    echo "Staging recurring template {$templateId}, journal {$jeId}, entity {$entityId}.\n";
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

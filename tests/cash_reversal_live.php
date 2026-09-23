<?php
/** MySQL-backed bank match/reversal/correction regression in the CI sim tenant. */
declare(strict_types=1);

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/tenant_scope.php';
require_once __DIR__ . '/../modules/accounting/lib/accounting.php';
require_once __DIR__ . '/../modules/accounting/lib/bank_rec.php';
require_once __DIR__ . '/../core/business_integrity.php';

$tenantId = (int) (getenv('SIM_TENANT_ID') ?: 0);
if ($tenantId <= 0) throw new RuntimeException('SIM_TENANT_ID is required');
$pdo = getDB();
$sim = $pdo->prepare('SELECT is_simulation FROM tenants WHERE id = :id');
$sim->execute(['id' => $tenantId]);
if ((int) $sim->fetchColumn() !== 1) throw new RuntimeException('Refusing to write to a non-simulation tenant');
setRequestTenantId($tenantId);

$pdo->prepare(
    "INSERT INTO accounting_bank_accounts
        (id, tenant_id, entity_id, name, gl_account_code, currency)
     VALUES (9501, :tenant_id, 1, 'CI clearing bank', '1010', 'USD')"
)->execute(['tenant_id' => $tenantId]);
$pdo->prepare(
    "INSERT INTO accounting_bank_statement_lines
        (id, tenant_id, bank_account_id, posted_date, description, amount)
     VALUES (9501, :tenant_id, 9501, '2026-01-20', 'Customer receipt', 100)"
)->execute(['tenant_id' => $tenantId]);

$postReceipt = static fn(string $memo): array => accountingPostJe($tenantId, [
    'entity_id' => 1,
    'posting_date' => '2026-01-20',
    'currency' => 'USD',
    'source_module' => 'manual',
    'memo' => $memo,
    'lines' => [
        ['account_code' => '1010', 'debit' => 100, 'credit' => 0],
        ['account_code' => '4000', 'debit' => 0, 'credit' => 100],
    ],
]);
$original = $postReceipt('Original receipt');
bankRecMatchLine($tenantId, 9501, (int) $original['je_id'], null);

$matched = $pdo->prepare(
    'SELECT match_status, matched_je_id FROM accounting_bank_statement_lines WHERE tenant_id = :t AND id = 9501'
);
$matched->execute(['t' => $tenantId]);
$bankLine = $matched->fetch(PDO::FETCH_ASSOC);
if ($bankLine['match_status'] !== 'matched' || (int) $bankLine['matched_je_id'] !== (int) $original['je_id']) {
    throw new RuntimeException('Bank line did not match the posted receipt');
}

$reversal = accountingReverseJe($tenantId, (int) $original['je_id'], 'CI correction');
$matched->execute(['t' => $tenantId]);
$bankLine = $matched->fetch(PDO::FETCH_ASSOC);
if ($bankLine['match_status'] !== 'unmatched' || $bankLine['matched_je_id'] !== null) {
    throw new RuntimeException('Reversing the journal left its bank line matched');
}
$replay = accountingReverseJe($tenantId, (int) $original['je_id'], 'CI correction');
if ((int) $replay['je_id'] !== (int) $reversal['je_id'] || !$replay['idempotent_replay']) {
    throw new RuntimeException('Journal reversal was not idempotent');
}

try {
    bankRecMatchLine($tenantId, 9501, (int) $original['je_id'], null);
    throw new RuntimeException('A bank line matched a reversed journal');
} catch (RuntimeException $e) {
    if ($e->getMessage() !== 'Only a posted journal entry can be matched') throw $e;
}

$replacement = $postReceipt('Corrected receipt');
bankRecMatchLine($tenantId, 9501, (int) $replacement['je_id'], null);
$matched->execute(['t' => $tenantId]);
$bankLine = $matched->fetch(PDO::FETCH_ASSOC);
if ($bankLine['match_status'] !== 'matched' || (int) $bankLine['matched_je_id'] !== (int) $replacement['je_id']) {
    throw new RuntimeException('Bank line did not match the corrected journal');
}

$audit = businessIntegrityAudit($tenantId);
$bankCheck = null;
foreach ($audit['checks'] as $check) {
    if (($check['key'] ?? '') === 'bank_match_integrity') $bankCheck = $check;
}
if (!$bankCheck || $bankCheck['status'] !== 'ok') {
    throw new RuntimeException('Bank-match integrity failed after reversal and correction');
}
echo "Bank receipt matched, reversed, and corrected without stale reconciliation links.\n";

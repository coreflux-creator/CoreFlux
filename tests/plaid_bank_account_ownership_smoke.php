<?php
/**
 * Guard account-level ledger and legal-entity ownership for Plaid mirroring.
 * Run: php tests/plaid_bank_account_ownership_smoke.php
 */
declare(strict_types=1);

$pass = 0;
$fail = 0;
function check(string $label, bool $condition): void {
    global $pass, $fail;
    if ($condition) { echo "PASS {$label}\n"; $pass++; }
    else { echo "FAIL {$label}\n"; $fail++; }
}
function source(string $file): string {
    $content = file_get_contents(__DIR__ . '/../' . $file);
    if ($content === false) throw new RuntimeException("Missing {$file}");
    return $content;
}

$link = source('api/plaid_bank_link.php');
$diagnostics = source('api/plaid_diagnostics.php');
$ui = source('modules/treasury/ui/TreasuryOverview.jsx');

check('Each linked deposit and liability gets a distinct GL code',
    substr_count($link, 'plaidAllocateBankGlCode(') === 2
    && !str_contains($link, 'plaidEnsureSharedGlAccount('));
check('Unsupported shared-ledger toggle is gone',
    !str_contains($link, 'create_gl_per_account')
    && !str_contains($ui, 'create_gl_per_account'));
check('New deposit ledgers are cash equivalents',
    str_contains($link, "'cash_and_equivalents', 1, NULL, 1, NOW())")
    && substr_count($diagnostics, "'cash_and_equivalents', 1, NULL, 1, NOW())") === 2);
check('Liability mirrors carry an entity in both paths',
    str_contains($link, '(tenant_id, account_id, entity_id, subtype')
    && str_contains($diagnostics, '(tenant_id, account_id, entity_id, subtype'));
check('Link requires an explicit owner in multi-entity tenants',
    str_contains($link, 'count($availableEntities) > 1 && !$requestedEntityId')
    && str_contains($link, 'account_entity_ids')
    && str_contains($link, '$accountEntityId = $accountEntityIds[$accId] ?? $entityId'));
check('Exact re-link refuses a change of legal entity',
    str_contains($link, '($existing[\'entity_id\'] ?? 0) !== $accountEntityId')
    && str_contains($link, '($existingRow[\'entity_id\'] ?? 0) !== $accountEntityId'));
check('Re-link and adoption writes are entity constrained',
    substr_count($link, 'WHERE tenant_id = :t AND id = :id AND entity_id = :eid') >= 3);
check('An empty explicit account selection mirrors no accounts',
    str_contains($link, "array_key_exists('selected_account_ids', \$body)"));
check('Recovery requires explicit account selection and legal entity',
    str_contains($diagnostics, "Select the connected accounts to add to Treasury")
    && str_contains($diagnostics, 'count($availableEntities) > 1 && !$requestedEntityId')
    && str_contains($diagnostics, "'eid' => \$accountEntityId"));
check('Diagnostics exposes entity ownership for existing mirrors',
    str_contains($diagnostics, 'SELECT id, entity_id, name, gl_account_code')
    && str_contains($diagnostics, 'SELECT id, account_id, entity_id, subtype'));
check('UI offers entity assignment and deliberate bulk recovery',
    str_contains($ui, 'data-testid="plaid-bank-entity"')
    && str_contains($ui, 'account_entity_ids: entityIds')
    && str_contains($ui, 'data-testid="plaid-orphan-select-all"')
    && str_contains($ui, 'selected_account_ids: ids'));
check('Recovery counters and picker fit narrow screens',
    str_contains($ui, 'repeat(auto-fit, minmax(105px, 1fr))')
    && str_contains($ui, "boxSizing: 'border-box', maxHeight: '88vh'"));

echo "{$pass} passed; {$fail} failed\n";
exit($fail ? 1 : 0);

<?php
/** Regression coverage for tenant-authoritative bank/Treasury scope. */
declare(strict_types=1);

$root = dirname(__DIR__);
$fail = 0;
$check = static function (string $label, bool $ok) use (&$fail): void {
    echo ($ok ? "PASS" : "FAIL") . "  {$label}\n";
    if (!$ok) $fail++;
};
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$header = $read('dashboard/src/layout/Header.jsx');
$check('global briefcase selector removed',
    !str_contains($header, 'header-entity-switcher') && !str_contains($header, 'Briefcase'));
$check('tenant selector remains', str_contains($header, 'data-testid="tenant-switcher"'));

$active = $read('core/active_entity.php');
$check('active entity list is current-tenant only', str_contains($active, 'AND ae.tenant_id = :t'));
$check('active entity set validates tenant ownership', str_contains($active, 'WHERE id = :id AND tenant_id = :t'));
$check('posting resolver defaults inside tenant', str_contains($active, 'activeEntityResolveForTenant'));
$check('cross-tenant active-session helper removed', !str_contains($active, 'activeEntityResolveAllowedTenantIds'));

$bankLink = $read('api/plaid_bank_link.php');
$check('Plaid exchange resolves tenant entity', str_contains($bankLink, 'activeEntityResolveForTenant'));
$check('new Plaid bank rows persist entity_id',
    str_contains($bankLink, '(tenant_id, entity_id, name, gl_account_code'));
$check('relinked Plaid bank rows repair null entity',
    str_contains($bankLink, 'entity_id = COALESCE(entity_id, :eid)'));

$bankApi = $read('modules/accounting/api/bank_accounts.php');
$check('manual bank creation resolves tenant entity', str_contains($bankApi, 'activeEntityResolveForTenant'));
$check('bank entity can be repaired through PUT',
    str_contains($bankApi, "['entity_id','name','gl_account_code"));

$migration = $read('modules/accounting/migrations/024_bank_account_entity_backfill.sql');
$check('legacy bank rows backfill only for unambiguous tenants',
    str_contains($migration, 'HAVING COUNT(*) = 1') && str_contains($migration, 'WHERE ba.entity_id IS NULL'));

foreach ([
    'core/active_entity.php',
    'api/active_entity.php',
    'api/plaid_bank_link.php',
    'api/plaid_exchange.php',
    'api/plaid_diagnostics.php',
    'modules/accounting/api/bank_accounts.php',
    'modules/treasury/api/deposit_accounts.php',
] as $path) {
    $out = [];
    $rc = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/' . $path) . ' 2>&1', $out, $rc);
    $check("php -l {$path}", $rc === 0);
}

exit($fail === 0 ? 0 : 1);

<?php
/** Static and pure-value checks for the entity-scoped CoreOne v1 journal boundary. */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/core/accounting/coreone_v1.php';
$service = (string) file_get_contents($root . '/core/accounting/coreone_v1.php');
$endpoint = (string) file_get_contents($root . '/api/coreone/v1/journals.php');
$management = (string) file_get_contents($root . '/api/coreone_credentials.php');
$migration = (string) file_get_contents($root . '/core/migrations/153_coreone_accounting_credentials.sql');
$scopeMigration = (string) file_get_contents($root . '/core/migrations/154_coreone_document_requests.sql');
$registry = (string) file_get_contents($root . '/core/seeds/event_registry_seed.php');
$rules = (string) file_get_contents($root . '/core/posting_engine/seed_defaults.php');
$checks = [];
$check = static function (string $name, bool $ok) use (&$checks): void {
    $checks[$name] = $ok;
    echo ($ok ? 'OK  ' : 'FAIL  ') . $name . PHP_EOL;
};

$check('token stored as hash with expiry and revocation',
    str_contains($migration, 'token_hash CHAR(64) NOT NULL')
    && str_contains($migration, 'expires_at DATETIME NOT NULL')
    && str_contains($migration, 'revoked_at DATETIME NULL')
    && !str_contains($migration, 'token_plain'));
$check('credential is bound to one tenant and legal entity',
    str_contains($service, 'accountingValidateActiveEntityId($tenantId, $entityId)')
    && str_contains($service, 'e.tenant_id = c.tenant_id AND e.id = c.entity_id AND e.active = 1'));
$check('service route uses bearer auth and credential tenant, not browser login',
    str_contains($endpoint, 'coreoneV1Authenticate(')
    && str_contains($endpoint, "coreoneV1HasScope(\$credential, 'journals:write')")
    && str_contains($endpoint, "setRequestTenantId((int) \$credential['tenant_id'])")
    && !str_contains($endpoint, 'api_require_auth('));
$check('existing credentials retain only their original scopes',
    str_contains($scopeMigration, '["journals:write","reports:read"]')
    && COREONE_V1_DEFAULT_SCOPES === ['journals:write', 'reports:read']
    && !in_array('invoices:draft', COREONE_V1_DEFAULT_SCOPES, true));
$check('invoice draft scope needs an administrator with Billing draft permission',
    str_contains($management, "'billing.invoice.draft'")
    && str_contains($management, "in_array('invoices:draft', \$scopes, true)")
    && str_contains($management, "in_array('journals:write', \$scopes, true)")
    && str_contains($management, "in_array('reports:read', \$scopes, true)"));
$check('credential management uses existing human auth and accounting RBAC',
    str_contains($management, 'api_require_auth()')
    && str_contains($management, "'accounting.manage_integrations'")
    && str_contains($management, "'accounting.je.post'"));
$check('only one registered general-journal event type is emitted',
    str_contains($registry, "'coreone.journal.posted'")
    && str_contains($rules, "'event_type'  => 'coreone.journal.posted'")
    && str_contains($service, "'source_module' => 'coreone'")
    && str_contains($service, "'event_type' => 'coreone.journal.posted'"));
$check('managed control accounts are withheld from general journals',
    in_array('1100', COREONE_V1_PROTECTED_ACCOUNTS, true)
    && in_array('2000', COREONE_V1_PROTECTED_ACCOUNTS, true)
    && in_array('2300', COREONE_V1_PROTECTED_ACCOUNTS, true));
$check('bank-linked cash accounts are withheld even when their code is custom',
    str_contains($service, 'LEFT JOIN accounting_bank_accounts ba')
    && str_contains($service, 'ba.gl_account_code = a.code')
    && str_contains($service, "\$account['bank_account_id'] !== null"));
$check('reversal verifies source ownership, primary link and bank match',
    str_contains($service, "\$journal['source_module'] !== 'coreone'")
    && str_contains($service, 'accounting_event_id = :event_id')
    && str_contains($service, 'matched_je_id = :je_id')
    && str_contains($service, 'accountingReverseJe('));
$check('lookup exposes correction and reversal protects liability matches',
    str_contains($service, 'je.reversed_by_je_id AS reversal_journal_entry_id')
    && str_contains($service, 'FROM treasury_liability_statement_lines WHERE tenant_id = :t'));
$check('decimal parser handles cents exactly', coreoneV1Cents('12.34', 'amount') === 1234
    && coreoneV1Cents(12, 'amount') === 1200
    && coreoneV1Cents('0.01', 'amount') === 1);
$rejects = static function (mixed $value): bool {
    try { coreoneV1Cents($value, 'amount'); return false; }
    catch (InvalidArgumentException $e) { return true; }
};
$check('decimal parser rejects negative, fractional-cent and exponent amounts',
    $rejects('-1') && $rejects('1.001') && $rejects('1e3'));

$failed = count(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo ($failed ? "Failed: {$failed}" : 'Passed: ' . count($checks)) . PHP_EOL;
exit($failed ? 1 : 0);

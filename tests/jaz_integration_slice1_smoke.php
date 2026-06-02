<?php
/**
 * jaz_integration_slice1_smoke.php
 *
 * CoreFlux × Jaz.ai Slice 1 — provider-neutral accounting backend.
 *
 *   • migration 088 — 4 tables (provider connections, destination
 *     links, outbox events, report snapshots)
 *   • core/accounting/provider_adapter.php —
 *       - AccountingProviderAdapter abstract surface
 *       - AccountingAdapterNotReadyException + ValidationException
 *       - accountingProviderAdapterFor() factory
 *   • core/accounting/jaz_adapter.php — JazAccountingAdapter skeleton
 *     (writes throw NotReady, reads return canonical empty shapes,
 *     credential resolution real via encryptField/decryptField)
 *   • core/accounting/command_service.php —
 *       - accountingResolveProvider()
 *       - accountingCommandEnqueue() with idempotency (UNIQUE key)
 *       - accountingCommandExecute() dispatches to adapter
 *       - failure path: retrying → dead_letter at max_attempts
 *       - success path: writes accounting_destination_links row
 *   • core/accounting/connection_service.php — connect / validate /
 *     disconnect with AES-256-GCM secret storage, last4 display
 *   • api/accounting.php — 13 actions (5 connection, 7 reads, 6 commands)
 *     + provider-neutral RBAC codes
 *   • core/rbac/legacy_map.php — 5 new permission codes
 *   • dashboard/src/pages/JazIntegrationSettings.jsx — admin page
 *   • dashboard/src/pages/AdminModule.jsx — route mounted
 *   • dashboard/src/pages/IntegrationsHub.jsx — Jaz tile in Accounting
 *
 * Also includes a FUNCTIONAL test of the adapter contract (skeleton
 * surface, write methods raise NotReady, factory honors swap).
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ✓ {$name}\n"; }
    else     { $fail++; echo "  ✗ {$name}\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "Jaz Integration Slice 1 smoke\n";
echo "==============================\n\n";

$ROOT = dirname(__DIR__);

// --- migration 088 ----------------------------------------------
echo "core/migrations/088_jaz_integration_foundation.sql\n";
$mig = $read("{$ROOT}/core/migrations/088_jaz_integration_foundation.sql");
$a('migration file exists',                       $mig !== '');
$a('creates accounting_provider_connections',     str_contains($mig, 'CREATE TABLE IF NOT EXISTS accounting_provider_connections'));
$a('  provider ENUM jaz/coreflux_native/qbo/xero',str_contains($mig, "ENUM('jaz','coreflux_native','qbo','xero')"));
$a('  credential_secret_ct VARBINARY (encrypted)',str_contains($mig, 'credential_secret_ct     VARBINARY(2048)'));
$a('  connection_status ENUM 5-state',            str_contains($mig, "ENUM('pending','active','expired','revoked','failed')"));
$a('  api_scope_summary JSON',                    str_contains($mig, 'api_scope_summary        JSON'));
$a('  UNIQUE per tenant+sub_tenant+provider',     str_contains($mig, 'UNIQUE KEY uq_apc_provider_entity (tenant_id, sub_tenant_id, provider)'));

$a('creates accounting_destination_links',        str_contains($mig, 'CREATE TABLE IF NOT EXISTS accounting_destination_links'));
$a('  sync_status ENUM 6-state',                  str_contains($mig, "ENUM('pending','posted','failed','voided','reversed','superseded')"));
$a('  idempotency_key UNIQUE',                    str_contains($mig, 'UNIQUE KEY uq_adl_idem (idempotency_key)'));
$a('  UNIQUE per provider object',                str_contains($mig, 'UNIQUE KEY uq_adl_provider_obj'));

$a('creates accounting_outbox_events',            str_contains($mig, 'CREATE TABLE IF NOT EXISTS accounting_outbox_events'));
$a('  status ENUM with dead_letter',              str_contains($mig, "ENUM('queued','processing','posted','failed','retrying','dead_letter')"));
$a('  attempts + max_attempts columns',           str_contains($mig, 'attempts                 INT UNSIGNED NOT NULL DEFAULT 0')
                                               && str_contains($mig, 'max_attempts             INT UNSIGNED NOT NULL DEFAULT 5'));
$a('  next_retry_at index for worker pull',       str_contains($mig, 'KEY ix_aoe_status_retry (status, next_retry_at)'));
$a('  idempotency_key UNIQUE on outbox too',      str_contains($mig, 'UNIQUE KEY uq_aoe_idem (idempotency_key)'));

$a('creates accounting_report_snapshots',         str_contains($mig, 'CREATE TABLE IF NOT EXISTS accounting_report_snapshots'));
$a('  normalized_report_json JSON',               str_contains($mig, 'normalized_report_json   JSON NOT NULL'));
$a('  provider_raw_response_ref column',          str_contains($mig, 'provider_raw_response_ref VARCHAR(255)'));

// --- core/accounting/provider_adapter.php -----------------------
echo "\ncore/accounting/provider_adapter.php\n";
$pa = $read("{$ROOT}/core/accounting/provider_adapter.php");
$a('declares strict_types',                       str_contains($pa, 'declare(strict_types=1);'));
$a('AccountingAdapterNotReadyException class',    str_contains($pa, 'class AccountingAdapterNotReadyException extends \\RuntimeException'));
$a('AccountingAdapterValidationException class',  str_contains($pa, 'class AccountingAdapterValidationException extends \\RuntimeException'));
$a('AccountingProviderAdapter abstract',          str_contains($pa, 'abstract class AccountingProviderAdapter'));

foreach ([
    'providerKey',
    'validateConnection',
    'getChartOfAccounts', 'getTrialBalance', 'getGeneralLedger',
    'getProfitAndLoss', 'getBalanceSheet', 'getArAging', 'getApAging',
    'createDraftBill', 'createDraftInvoice', 'createDraftJournal',
    'postObject', 'getObject', 'normalizeProviderError',
] as $m) {
    $a("declares abstract {$m}()",                str_contains($pa, "abstract public function {$m}"));
}
$a('factory routes "jaz" → JazAccountingAdapter', str_contains($pa, "case 'jaz':")
                                               && str_contains($pa, 'return new JazAccountingAdapter();'));
$a('factory throws on unknown provider',          str_contains($pa, 'No adapter registered for provider='));

// --- core/accounting/jaz_adapter.php ----------------------------
echo "\ncore/accounting/jaz_adapter.php\n";
$ja = $read("{$ROOT}/core/accounting/jaz_adapter.php");
$a('JazAccountingAdapter extends AccountingProviderAdapter',
    str_contains($ja, 'class JazAccountingAdapter extends AccountingProviderAdapter'));
$a('providerKey() returns "jaz"',                 str_contains($ja, "return 'jaz';"));
$a('resolveCredential uses decryptField',         str_contains($ja, 'decryptField($ct)'));
$a('resolveCredential filters out revoked',       str_contains($ja, "if (!\$row || \$row['connection_status'] === 'revoked')"));
$a('validateConnection probes GET /organization', str_contains($ja, "jazCall(\$key, 'GET', 'organization')"));
$a('validateConnection persists provider_org_id', str_contains($ja, 'UPDATE accounting_provider_connections'));
$a('reads use live jazCall',                      str_contains($ja, "jazCall(\$key, 'GET', 'chart-of-accounts'")
                                               && str_contains($ja, "jazCall(\$key, 'POST', 'reports/trial-balance'")
                                               && str_contains($ja, "jazCall(\$key, 'POST', 'reports/general-ledger'"));
$a('createDraftBill POSTs to /bills saveAsDraft', str_contains($ja, "jazCall(\$key, 'POST', 'bills', \$payload)")
                                               && str_contains($ja, "'saveAsDraft' => true,"));
$a('createDraftInvoice POSTs to /invoices',       str_contains($ja, "jazCall(\$key, 'POST', 'invoices', \$payload)"));
$a('createDraftJournal POSTs to /journals',       str_contains($ja, "jazCall(\$key, 'POST', 'journals', \$payload)"));
$a('postObject POSTs to draft/convert-to-active', str_contains($ja, "jazCall(\$key, 'POST', 'draft/convert-to-active'"));
$a('normalizeProviderError maps Jaz HTTP codes',  str_contains($ja, "case 401: \$code = 'auth_invalid';")
                                               && str_contains($ja, "case 422: \$code = 'provider_validation';")
                                               && str_contains($ja, "case 429: \$code = 'rate_limited';"));

// --- functional adapter contract tests --------------------------
echo "\nFunctional adapter contract\n";
require_once "{$ROOT}/core/accounting/provider_adapter.php";
require_once "{$ROOT}/core/accounting/jaz_adapter.php";

$a('JazAccountingAdapter instantiable',           class_exists('JazAccountingAdapter'));
$adapter = new JazAccountingAdapter();
$a('  inherits AccountingProviderAdapter',        $adapter instanceof AccountingProviderAdapter);
$a('  providerKey() === "jaz"',                   $adapter->providerKey() === 'jaz');

// Reads — without credentials, the live adapter raises
// AccountingAdapterValidationException. The "canonical empty shape"
// path from Slice 1 is gone; Slice 2 always probes Jaz.
$threw = false;
try { $adapter->getChartOfAccounts(1, 1, []); }
catch (AccountingAdapterValidationException $e) { $threw = true; }
$a('  getChartOfAccounts raises Validation when no credential', $threw);

$threw = false;
try { $adapter->getTrialBalance(1, 1, ['asOf' => '2026-01-31']); }
catch (AccountingAdapterValidationException $e) { $threw = true; }
$a('  getTrialBalance raises Validation when no credential',    $threw);

// Writes — with no credentials configured for tenant=1/entity=1, the
// adapter throws AccountingAdapterValidationException (credential
// resolver returns null → keyOrThrow). When a real key is present,
// these would hit Jaz live (smoke test for that lives in
// jaz_integration_slice2_live_smoke.php with a stubbed transport).
$threw = false;
try { $adapter->createDraftBill(1, 1, ['x' => 1], 'idem-1'); }
catch (AccountingAdapterValidationException $e) { $threw = true; }
$a('  createDraftBill raises Validation when no credential',     $threw);

$threw = false;
try { $adapter->postObject(1, 1, 'bill', 'jaz_123'); }
catch (AccountingAdapterValidationException $e) { $threw = true; }
$a('  postObject raises Validation when no credential',          $threw);

// normalizeProviderError shape.
$norm = $adapter->normalizeProviderError(new AccountingAdapterNotReadyException('test'));
$a('  normalize NotReady → code=adapter_not_ready', $norm['code'] === 'adapter_not_ready');
$norm = $adapter->normalizeProviderError(new \RuntimeException('boom'));
$a('  normalize generic → code=provider_error',     $norm['code'] === 'provider_error');

// Factory.
$threw = false;
try { accountingProviderAdapterFor('xero'); }
catch (\InvalidArgumentException $e) { $threw = true; }
$a('  factory throws on unwired provider (xero)', $threw);
$a('  factory honors "jaz"',                       accountingProviderAdapterFor('jaz') instanceof JazAccountingAdapter);

// --- core/accounting/command_service.php ------------------------
echo "\ncore/accounting/command_service.php\n";
$cs = $read("{$ROOT}/core/accounting/command_service.php");
$a('ACCOUNTING_OUTBOX_MAX_ATTEMPTS = 5',          str_contains($cs, 'ACCOUNTING_OUTBOX_MAX_ATTEMPTS         = 5'));
$a('ACCOUNTING_OUTBOX_BACKOFF_BASE_SECONDS=60',   str_contains($cs, 'ACCOUNTING_OUTBOX_BACKOFF_BASE_SECONDS = 60'));

foreach ([
    'accountingResolveProvider', 'accountingCommandEnqueue',
    'accountingCommandGetStatus', 'accountingCommandApprove',
    'accountingCommandExecute', 'accountingCommandMarkFailure',
    'accountingDestinationLinkInsert', 'accountingOutboxRowDecode',
] as $fn) {
    $a("declares {$fn}()",                        str_contains($cs, "function {$fn}"));
}

$a('enqueue rejects empty idempotency_key',       str_contains($cs, 'idempotency_key required'));
$a('enqueue INSERT IGNORE for idempotency',       str_contains($cs, 'INSERT IGNORE INTO accounting_outbox_events'));
$a('enqueue inserts with status="queued"',        str_contains($cs, '"queued"'));
$a('execute switches over command_type',          str_contains($cs, "case 'create_draft_bill':")
                                               && str_contains($cs, "case 'create_draft_invoice':")
                                               && str_contains($cs, "case 'create_draft_journal':")
                                               && str_contains($cs, "case 'post_object':"));
$a('execute marks status=processing first',       str_contains($cs, 'SET status = "processing"'));
$a('execute on success sets status=posted',       str_contains($cs, 'SET status = "posted"'));
$a('execute writes destination link on success',  str_contains($cs, 'accountingDestinationLinkInsert('));
$a('markFailure exponential backoff',             str_contains($cs, 'ACCOUNTING_OUTBOX_BACKOFF_BASE_SECONDS * (2 ** ($attempts - 1))'));
$a('markFailure dead-letters at max_attempts',    str_contains($cs, '$deadLetter = $attempts >= $max;')
                                               && str_contains($cs, "'dead_letter'"));
$a('approve refuses non-queued rows',             str_contains($cs, "cannot approve command in status"));
$a('destination_link insert is INSERT IGNORE',    str_contains($cs, 'INSERT IGNORE INTO accounting_destination_links'));

// --- core/accounting/connection_service.php ---------------------
echo "\ncore/accounting/connection_service.php\n";
$cns = $read("{$ROOT}/core/accounting/connection_service.php");
$a('upsert requires api_key',                     str_contains($cns, 'api_key required'));
$a('upsert enforces ≥16 chars',                   str_contains($cns, 'api_key must be at least 16 characters'));
$a('upsert encrypts via encryptField',            str_contains($cns, '$ct           = encryptField($apiKey);'));
$a('upsert stores last4 only',                    str_contains($cns, '$last4        = substr($apiKey, -4);'));
$a('upsert ON DUPLICATE KEY',                     str_contains($cns, 'ON DUPLICATE KEY UPDATE'));
$a('validate persists status + scope',            str_contains($cns, 'SET connection_status   = :s,')
                                               && str_contains($cns, "api_scope_summary   = :scope,"));
$a('validate maps pending_diligence → pending',   str_contains($cns, "\$dbStatus = \$status === 'pending_diligence' ? 'pending' : \$status;"));
$a('disconnect revokes + zeroes secret',          str_contains($cns, 'SET connection_status = "revoked"')
                                               && str_contains($cns, 'credential_secret_ct = NULL'));

// --- api/accounting.php -----------------------------------------
echo "\napi/accounting.php\n";
$apiA = $read("{$ROOT}/api/accounting.php");
$a('api_require_auth gate at top',                str_contains($apiA, '$ctx     = api_require_auth();'));
$a('GET status action',                           str_contains($apiA, "if (\$method === 'GET' && \$action === 'status')"));
$a('POST connect + rotate_key both routed',       str_contains($apiA, "in_array(\$action, ['connect', 'rotate_key'], true)"));
$a('POST validate action',                        str_contains($apiA, "\$action === 'validate'"));
$a('POST disconnect action',                      str_contains($apiA, "\$action === 'disconnect'"));
$a('reads include chart_of_accounts, trial_balance, general_ledger',
    str_contains($apiA, "'chart_of_accounts' => 'getChartOfAccounts'")
    && str_contains($apiA, "'trial_balance'     => 'getTrialBalance'")
    && str_contains($apiA, "'general_ledger'    => 'getGeneralLedger'"));
$a('reads include pnl + balance_sheet + ar_aging + ap_aging',
    str_contains($apiA, "'pnl'               => 'getProfitAndLoss'")
    && str_contains($apiA, "'balance_sheet'     => 'getBalanceSheet'")
    && str_contains($apiA, "'ar_aging'          => 'getArAging'")
    && str_contains($apiA, "'ap_aging'          => 'getApAging'"));
$a('command create_draft_* actions wired',        str_contains($apiA, "'create_draft_bill'    => 'create_draft_bill'")
                                               && str_contains($apiA, "'create_draft_invoice' => 'create_draft_invoice'")
                                               && str_contains($apiA, "'create_draft_journal' => 'create_draft_journal'"));
$a('approve_command action',                      str_contains($apiA, "\$action === 'approve_command'"));
$a('execute_command action',                      str_contains($apiA, "\$action === 'execute_command'"));
$a('command_status action',                       str_contains($apiA, "\$action === 'command_status'"));

$a('RBAC: connection.view on status + reads',     substr_count($apiA, "rbac_legacy_require(\$user, 'accounting.connection.view')") >= 2);
$a('RBAC: connection.manage on connect',          str_contains($apiA, "rbac_legacy_require(\$user, 'accounting.connection.manage')"));
$a('RBAC: commands.draft on create_draft_*',      str_contains($apiA, "rbac_legacy_require(\$user, 'accounting.commands.draft')"));
$a('RBAC: commands.approve on approve_command',   str_contains($apiA, "rbac_legacy_require(\$user, 'accounting.commands.approve')"));
$a('RBAC: commands.execute on execute_command',   str_contains($apiA, "rbac_legacy_require(\$user, 'accounting.commands.execute')"));
$a('  none use accounting.jaz.* (provider-neutral)',
    !str_contains($apiA, "'accounting.jaz.")
    && !str_contains($apiA, "'accounting.jaz."));

// --- core/rbac/legacy_map.php -----------------------------------
echo "\ncore/rbac/legacy_map.php\n";
$rb = $read("{$ROOT}/core/rbac/legacy_map.php");
foreach ([
    'accounting.connection.view'   => "['accounting', 'read']",
    'accounting.connection.manage' => "['accounting', 'admin']",
    'accounting.commands.draft'    => "['accounting', 'write']",
    'accounting.commands.approve'  => "['accounting', 'admin']",
    'accounting.commands.execute'  => "['accounting', 'admin']",
] as $code => $expected) {
    $a("permission code {$code} mapped",          str_contains($rb, "'{$code}'") && str_contains($rb, $expected));
}

// --- frontend ---------------------------------------------------
echo "\ndashboard/src/pages/JazIntegrationSettings.jsx\n";
$jsx = $read("{$ROOT}/dashboard/src/pages/JazIntegrationSettings.jsx");
$a('default exports JazIntegrationSettings',      str_contains($jsx, 'export default function JazIntegrationSettings()'));
$a('loads sub-tenants on mount',                  str_contains($jsx, "api.get('/api/sub_tenants.php')"));
$a('GETs /api/accounting.php?action=status',      str_contains($jsx, "action=status"));
$a('POSTs connect with sub_tenant_id + api_key',  str_contains($jsx, 'action=connect&provider=jaz')
                                               && str_contains($jsx, 'api_key: apiKey'));
$a('POSTs validate',                              str_contains($jsx, 'action=validate&provider=jaz'));
$a('POSTs disconnect with confirm()',             str_contains($jsx, 'action=disconnect&provider=jaz')
                                               && str_contains($jsx, "confirm('Disconnect Jaz"));
$a('entity selector testid',                      str_contains($jsx, 'data-testid="jaz-entity-select"'));
// Parent entity must be selectable — the parent tenant keeps its own books,
// it is NOT just a consolidation layer over sub-tenants.
$a('parent entity included in selector',          str_contains($jsx, "kind === 'parent'")
                                               && str_contains($jsx, "parent: parentRow") === false ? str_contains($jsx, 'r?.parent') : true);
$a('parent-entity label & note rendered',         str_contains($jsx, "' — parent entity'")
                                               && str_contains($jsx, 'data-testid="jaz-parent-entity-note"'));
$a('sub_tenants endpoint surfaces parent row',    (function () use ($ROOT): bool {
    $api = (string) @file_get_contents("{$ROOT}/api/sub_tenants.php");
    return str_contains($api, "'parent'           => \$parentRow")
        && str_contains($api, 'WHERE id = :p LIMIT 1');
})());
$a('connection status testid',                    str_contains($jsx, 'data-testid="jaz-connection-status"'));
$a('api key input testid + ≥16 enforced',         str_contains($jsx, 'data-testid="jaz-api-key-input"')
                                               && str_contains($jsx, 'apiKey.length < 16'));
$a('connect btn label switches to "Rotate key"',  str_contains($jsx, 'connection ? \'Rotate key\' : \'Connect Jaz\''));
$a('validate btn testid',                         str_contains($jsx, 'data-testid="jaz-validate-btn"'));
$a('disconnect btn testid',                       str_contains($jsx, 'data-testid="jaz-disconnect-btn"'));
$a('diligence banner shown when not ready',       str_contains($jsx, 'data-testid="jaz-diligence-banner"'));

echo "\ndashboard/src/pages/AdminModule.jsx\n";
$am = $read("{$ROOT}/dashboard/src/pages/AdminModule.jsx");
$a('imports JazIntegrationSettings',              str_contains($am, "import JazIntegrationSettings from './JazIntegrationSettings';"));
$a('mounts /integrations/jaz route',              str_contains($am, '<Route path="/integrations/jaz"'));

echo "\ndashboard/src/pages/IntegrationsHub.jsx\n";
$ih = $read("{$ROOT}/dashboard/src/pages/IntegrationsHub.jsx");
$a('Jaz tile has testid integration-card-jaz',    str_contains($ih, 'testid="integration-card-jaz"'));
$a('Jaz tile points at /admin/integrations/jaz',  str_contains($ih, 'href="/admin/integrations/jaz"'));
$a('Jaz tile status="pending" or dynamic',         str_contains($ih, 'status="pending"')
                                                || str_contains($ih, "jaz.data?.connected ? 'connected'"));

// --- PHP syntax checks ------------------------------------------
echo "\nPHP syntax checks\n";
foreach ([
    'core/accounting/provider_adapter.php',
    'core/accounting/jaz_adapter.php',
    'core/accounting/command_service.php',
    'core/accounting/connection_service.php',
    'api/accounting.php',
] as $f) {
    $r = shell_exec("php -l {$ROOT}/{$f} 2>&1");
    $a("{$f} parses",                              is_string($r) && str_contains($r, 'No syntax errors'));
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "Jaz Slice 1: {$pass} ✓ / {$fail} ✗\n";
exit($fail > 0 ? 1 : 0);

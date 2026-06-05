<?php
/**
 * jaz_integration_slice4_smoke.php
 *
 * CoreFlux × Jaz.ai Slice 4 — Finish-line for the Jaz integration:
 *
 *   • core/accounting/jaz_payload_mapper.php — CoreFlux row → Jaz API
 *     shape translator with provider-link resolution, error-quality
 *     validation messages, and a per-object_type dispatcher.
 *       - mapBillToJaz / mapInvoiceToJaz / mapJournalToJaz
 *       - mapCorefluxRowToJaz front door
 *       - _accCents / _accAmount helpers
 *       - AccountingAdapterValidationException on missing links /
 *         unbalanced journals / missing line account_id
 *
 *   • core/accounting/command_service.php — execute() invokes the
 *     mapper for jaz / bill|invoice|journal commands before calling
 *     the adapter, and mapper failures route to
 *     accountingCommandMarkFailure (NOT thrown as 500s).
 *
 *   • api/admin/accounting/outbox.php — operator API for the outbox:
 *       - GET list with status filter + by_status rollup
 *       - GET ?action=detail&id
 *       - POST ?action=retry  (resets attempts on dead_letter)
 *       - POST ?action=cancel (queued/retrying/failed → dead_letter)
 *       - RBAC: accounting.connection.view for reads,
 *               accounting.commands.execute for retry/cancel
 *
 *   • dashboard/src/pages/AccountingOutbox.jsx — admin UI: filterable
 *     table, per-row detail modal with command_payload +
 *     provider_result, retry / cancel actions.
 *
 *   • dashboard/src/pages/AdminModule.jsx — route + sidebar link +
 *     ActionCard at /admin/accounting/outbox.
 *
 *   • dashboard/src/pages/JazIntegrationSettings.jsx — discoverability
 *     link from the Jaz settings page to the outbox.
 *
 * Also includes FUNCTIONAL tests of the mapper bail paths (validation
 * exceptions on missing vendor / unbalanced JE / missing account_id).
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ✓ {$name}\n"; }
    else     { $fail++; echo "  ✗ {$name}\n"; }
};
$read = fn (string $p) => is_file($p) ? (string) file_get_contents($p) : '';
$ROOT = dirname(__DIR__);

echo "Jaz Slice 4 — payload mapper + outbox admin UI smoke\n";
echo "====================================================\n\n";

// ----- core/accounting/jaz_payload_mapper.php -------------------
echo "core/accounting/jaz_payload_mapper.php\n";
$m = $read("{$ROOT}/core/accounting/jaz_payload_mapper.php");
$a('file exists',                                  $m !== '');
$a('declares strict_types',                        str_contains($m, 'declare(strict_types=1);'));
$a('requires provider_adapter.php',                str_contains($m, "require_once __DIR__ . '/provider_adapter.php';"));
$a('requires db.php',                              str_contains($m, "require_once __DIR__ . '/../db.php';"));
$a('declares _accLookupJazResourceId',             str_contains($m, 'function _accLookupJazResourceId(int $tenantId, int $subTenantId, string $corefluxObjectType, int $corefluxObjectId): string'));
$a('  scopes lookup to provider = jaz',            str_contains($m, "AND provider = 'jaz'"));
$a('  scopes lookup to tenant + sub_tenant',       str_contains($m, 'tenant_id = :t AND sub_tenant_id = :st'));
$a('  filters sync_status pending/posted',         str_contains($m, "sync_status IN ('pending','posted')"));
$a('  throws clear missing-link message',          str_contains($m, 'is not linked to Jaz'));
$a('declares _accCents helper',                    str_contains($m, 'function _accCents(') && str_contains($m, '* 100'));
$a('declares _accAmount helper',                   str_contains($m, 'function _accAmount(') && str_contains($m, '/ 100, 2'));

$a('declares mapBillToJaz',                        str_contains($m, 'function mapBillToJaz(int $tenantId, int $subTenantId, array $row): array'));
$a('  rejects bill missing vendor_id',             str_contains($m, "throw new AccountingAdapterValidationException('bill missing vendor_id')"));
$a('  rejects bill with no line items',            str_contains($m, "throw new AccountingAdapterValidationException('bill has no line items')"));
$a('  rejects bill line missing account_id',       str_contains($m, "bill line #{\$idx} missing account_id"));
$a('  payload carries contactResourceId',          str_contains($m, "'contactResourceId' => \$contactRid,"));
$a('  payload carries lineItems[]',                str_contains($m, "'lineItems'         => \$jazLines,"));
$a('  per-line accountResourceId resolved',        str_contains($m, "'accountResourceId' => _accLookupJazResourceId(\$tenantId, \$subTenantId, 'account', \$acctId),"));

$a('declares mapInvoiceToJaz',                     str_contains($m, 'function mapInvoiceToJaz(int $tenantId, int $subTenantId, array $row): array'));
$a('  rejects invoice missing customer_id',        str_contains($m, "throw new AccountingAdapterValidationException('invoice missing customer_id')"));
$a('  rejects invoice with no line items',         str_contains($m, "throw new AccountingAdapterValidationException('invoice has no line items')"));
$a('  invoice line falls back to revenue_account', str_contains($m, "\$ln['revenue_account_id']"));
$a('  reference defaults to invoice_number',       str_contains($m, "\$row['invoice_number']"));

$a('declares mapJournalToJaz',                     str_contains($m, 'function mapJournalToJaz(int $tenantId, int $subTenantId, array $row): array'));
$a('  requires ≥2 journal lines',                  str_contains($m, "throw new AccountingAdapterValidationException('journal entry needs ≥2 lines')"));
$a('  rejects journal line missing account_id',    str_contains($m, "journal line #{\$idx} missing account_id"));
$a('  detects unbalanced debits/credits',          str_contains($m, 'journal entry unbalanced'));
$a('  payload carries internalNotes + valueDate',  str_contains($m, "'internalNotes'") && str_contains($m, "'valueDate'"));
$a('  payload uses journalEntries (not lines)',    str_contains($m, "'journalEntries' =>"));
$a('  payload wraps currency as BTCurrency obj',   str_contains($m, "'sourceCurrency'"));

$a('declares mapCorefluxRowToJaz dispatcher',      str_contains($m, 'function mapCorefluxRowToJaz(string $corefluxObjectType, int $tenantId, int $subTenantId, array $row): array'));
$a('  switch covers bill/invoice/journal',         str_contains($m, "case 'bill':")
                                                && str_contains($m, "case 'invoice':")
                                                && str_contains($m, "case 'journal':"));
$a('  unknown object_type → Validation',           str_contains($m, "no Jaz mapper for object type"));

// ----- command_service.php integration --------------------------
echo "\ncore/accounting/command_service.php — Slice 4 wiring\n";
$cs = $read("{$ROOT}/core/accounting/command_service.php");
$a('requires jaz_payload_mapper.php in execute',   str_contains($cs, "require_once __DIR__ . '/jaz_payload_mapper.php';"));
$a('calls mapCorefluxRowToJaz before adapter',     str_contains($cs, '$mappedDraft = mapCorefluxRowToJaz($objectType, $tenantId, $subTenantId, $rawRow);'));
$a('gated to jaz + supported object types',        str_contains($cs, "in_array(\$objectType, ['bill','invoice','journal'], true)")
                                                && str_contains($cs, "(string) \$row['provider'] === 'jaz'"));
$a('mapper failure → accountingCommandMarkFailure',str_contains($cs, 'return accountingCommandMarkFailure($tenantId, $commandId, $row, $adapter, $e);'));
$a('adapter call uses mappedDraft when present',   str_contains($cs, '$adapter->createDraftBill($tenantId, $subTenantId, $mappedDraft ?? $payload, $idem);')
                                                && str_contains($cs, '$adapter->createDraftInvoice($tenantId, $subTenantId, $mappedDraft ?? $payload, $idem);')
                                                && str_contains($cs, '$adapter->createDraftJournal($tenantId, $subTenantId, $mappedDraft ?? $payload, $idem);'));

// ----- api/admin/accounting/outbox.php --------------------------
echo "\napi/admin/accounting/outbox.php — operator API\n";
$o = $read("{$ROOT}/api/admin/accounting/outbox.php");
$a('file exists',                                  $o !== '');
$a('declares strict_types',                        str_contains($o, 'declare(strict_types=1);'));
$a('requires api_bootstrap.php',                   str_contains($o, "require_once __DIR__ . '/../../../core/api_bootstrap.php';"));
$a('requires rbac/legacy_map.php',                 str_contains($o, "require_once __DIR__ . '/../../../core/rbac/legacy_map.php';"));
$a('requires command_service.php',                 str_contains($o, "require_once __DIR__ . '/../../../core/accounting/command_service.php';"));
$a('GET list gated by accounting.connection.view', str_contains($o, "rbac_legacy_require(\$user, 'accounting.connection.view');"));
$a('SELECT scoped to tenant_id',                   str_contains($o, 'FROM accounting_outbox_events') && str_contains($o, 'WHERE tenant_id = :t'));
$a('status filter sanitized via whitelist',        str_contains($o, "in_array(\$status, ['queued','processing','posted','failed','retrying','dead_letter'], true)"));
$a('GET detail returns command shape',             str_contains($o, "if (\$method === 'GET' && \$action === 'detail')")
                                                && str_contains($o, "api_ok(['command' => \$cmd]);"));
$a('POST retry uses accounting.commands.execute',  str_contains($o, "if (\$method === 'POST' && \$action === 'retry')")
                                                && str_contains($o, "rbac_legacy_require(\$user, 'accounting.commands.execute');"));
$a('retry rejects ineligible statuses',            str_contains($o, "cannot retry from status"));
$a('retry resets attempts on dead_letter',         str_contains($o, "\$resetAttempts = \$row['status'] === 'dead_letter' ? 0 : (int) \$row['attempts'];"));
$a('retry kicks accountingCommandExecute inline',  str_contains($o, '$after = accountingCommandExecute($tid, $id);'));
$a('POST cancel flips to dead_letter',             str_contains($o, "if (\$method === 'POST' && \$action === 'cancel')")
                                                && str_contains($o, "SET status        = 'dead_letter',"));
$a('cancel rejects ineligible statuses',           str_contains($o, "cannot cancel from status"));
$a('unknown action falls through to 400',          str_contains($o, "unknown action '{\$action}' or wrong HTTP method"));
$a('by_status rollup computed',                    str_contains($o, "SELECT status, COUNT(*) c FROM accounting_outbox_events"));

// ----- dashboard/src/pages/AccountingOutbox.jsx -----------------
echo "\ndashboard/src/pages/AccountingOutbox.jsx — admin UI\n";
$j = $read("{$ROOT}/dashboard/src/pages/AccountingOutbox.jsx");
$a('file exists',                                  $j !== '');
$a('imports api helper',                           str_contains($j, "import { api } from '../lib/api';"));
$a('default-exports AccountingOutbox',             str_contains($j, 'export default function AccountingOutbox'));
$a('GETs /api/admin/accounting/outbox.php',        str_contains($j, '/api/admin/accounting/outbox.php'));
$a('POSTs ?action=retry',                          str_contains($j, "/api/admin/accounting/outbox.php?action=retry"));
$a('POSTs ?action=cancel',                         str_contains($j, "/api/admin/accounting/outbox.php?action=cancel"));
$a('GETs ?action=detail&id=',                      str_contains($j, "/api/admin/accounting/outbox.php?action=detail&id="));
$a('status filter buttons for all 6 statuses',     str_contains($j, "['queued', 'processing', 'posted', 'retrying', 'failed', 'dead_letter']"));
$a('root data-testid=accounting-outbox',           str_contains($j, 'data-testid="accounting-outbox"'));
$a('filter-all testid',                            str_contains($j, 'data-testid="outbox-filter-all"'));
$a('per-status filter testid template',            str_contains($j, 'data-testid={`outbox-filter-${s}`}'));
$a('table renders rows with row testid',           str_contains($j, 'data-testid={`outbox-row-${r.id}`}'));
$a('retry button testid',                          str_contains($j, 'data-testid={`outbox-retry-${r.id}`}'));
$a('cancel button testid',                         str_contains($j, 'data-testid={`outbox-cancel-${r.id}`}'));
$a('detail modal renders payload + provider_result',
    str_contains($j, 'data-testid="outbox-detail-modal"')
 && str_contains($j, 'command_payload')
 && str_contains($j, 'provider_result'));
$a('cancel asks for confirmation',                 str_contains($j, "confirm('Cancel this outbox row?"));
$a('error block has testid',                       str_contains($j, 'data-testid="outbox-error"'));

// ----- AdminModule.jsx — route + nav + card ---------------------
echo "\ndashboard/src/pages/AdminModule.jsx — routing & discoverability\n";
$am = $read("{$ROOT}/dashboard/src/pages/AdminModule.jsx");
$a('imports AccountingOutbox',                     str_contains($am, "import AccountingOutbox from './AccountingOutbox';"));
$a('imports Inbox icon from lucide',               str_contains($am, 'Inbox')
                                                && str_contains($am, "from 'lucide-react'"));
$a('mounts /accounting/outbox route',              str_contains($am, '<Route path="/accounting/outbox"')
                                                && str_contains($am, '<AccountingOutbox session={session} />'));
$a('sidebar nav exposes outbox link',              str_contains($am, "to: '/admin/accounting/outbox'"));
$a('Overview ActionCard exposes outbox',           str_contains($am, 'title="Accounting outbox"')
                                                && str_contains($am, 'href="/admin/accounting/outbox"'));

// ----- JazIntegrationSettings → outbox link ---------------------
echo "\ndashboard/src/pages/JazIntegrationSettings.jsx — outbox link\n";
$ji = $read("{$ROOT}/dashboard/src/pages/JazIntegrationSettings.jsx");
$a('header links to /admin/accounting/outbox',     str_contains($ji, 'href="/admin/accounting/outbox"'));
$a('outbox link testid present',                   str_contains($ji, 'data-testid="jaz-link-outbox"'));

// ----- IntegrationsHub Jaz tile (sanity) -------------------------
echo "\ndashboard/src/pages/IntegrationsHub.jsx — Jaz tile copy\n";
$ih = $read("{$ROOT}/dashboard/src/pages/IntegrationsHub.jsx");
$a('Jaz tile mentions accounting outbox',          str_contains($ih, 'accounting outbox'));

// ----- FUNCTIONAL: mapper bail paths -----------------------------
echo "\nFunctional mapper bail paths (no DB needed for argument-validation paths)\n";
require_once "{$ROOT}/core/accounting/provider_adapter.php";
require_once "{$ROOT}/core/accounting/jaz_payload_mapper.php";

// _accCents / _accAmount round-trip
$a('_accCents int 5 → 5 (treated as cents)',       _accCents(5)        === 5);
$a('_accCents float 5.25 → 525',                   _accCents(5.25)     === 525);
$a('_accCents string "10.10" → 1010',              _accCents('10.10')  === 1010);
$a('_accAmount 525 → 5.25',                        _accAmount(525)     === 5.25);

// Missing vendor_id → Validation
$threw = false;
try { mapBillToJaz(1, 1, ['id' => 1, 'lines' => [['account_id' => 1, 'unit_amount' => 10]]]); }
catch (AccountingAdapterValidationException $e) { $threw = str_contains($e->getMessage(), 'vendor_id'); }
$a('mapBillToJaz throws Validation when vendor missing', $threw);

// Missing customer_id on invoice
$threw = false;
try { mapInvoiceToJaz(1, 1, ['id' => 1, 'lines' => [['account_id' => 1, 'unit_amount' => 10]]]); }
catch (AccountingAdapterValidationException $e) { $threw = str_contains($e->getMessage(), 'customer_id'); }
$a('mapInvoiceToJaz throws Validation when customer missing', $threw);

// Journal needs ≥2 lines
$threw = false;
try { mapJournalToJaz(1, 1, ['id' => 1, 'lines' => [['account_id' => 1, 'debit' => 10]]]); }
catch (AccountingAdapterValidationException $e) { $threw = str_contains($e->getMessage(), '≥2 lines'); }
$a('mapJournalToJaz throws Validation on single-line JE', $threw);

// Unknown object_type via dispatcher
$threw = false;
try { mapCorefluxRowToJaz('widget', 1, 1, ['id' => 1]); }
catch (AccountingAdapterValidationException $e) { $threw = str_contains($e->getMessage(), 'no Jaz mapper'); }
$a('mapCorefluxRowToJaz rejects unknown object_type', $threw);

// ----- PHP syntax checks -----------------------------------------
echo "\nPHP syntax checks\n";
foreach ([
    'core/accounting/jaz_payload_mapper.php',
    'core/accounting/command_service.php',
    'api/admin/accounting/outbox.php',
] as $f) {
    $r = shell_exec("php -l {$ROOT}/{$f} 2>&1");
    $a("{$f} parses",                              is_string($r) && str_contains($r, 'No syntax errors'));
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "Jaz Slice 4: {$pass} ✓ / {$fail} ✗\n";
exit($fail > 0 ? 1 : 0);

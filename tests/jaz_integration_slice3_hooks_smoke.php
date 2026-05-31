<?php
/**
 * jaz_integration_slice3_hooks_smoke.php
 *
 * Slice 3 — AP/AR/JE write-path hooks + accounting outbox worker.
 *
 *   • core/accounting/command_service.php — accountingTryEnqueueDraft()
 *     best-effort helper (resolves sub_tenant, requires active
 *     connection, stable per-version idempotency, never throws)
 *   • cron/accounting_outbox_worker.php — picks queued/retrying rows
 *     past next_retry_at, dispatches via execute, per-row error
 *     isolation, --tenant/--max-rows/--dry-run flags
 *   • Wired call sites:
 *       - modules/ap/api/bills.php           — on approve
 *       - modules/billing/api/invoices.php   — on approve
 *       - modules/accounting/lib/accounting.php — on JE post (only when $post===true)
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ✓ {$name}\n"; }
    else     { $fail++; echo "  ✗ {$name}\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);
$ROOT = dirname(__DIR__);

echo "Jaz Slice 3 — module hooks + outbox worker smoke\n";
echo "================================================\n\n";

// --- helper: accountingTryEnqueueDraft surface -----------------
echo "core/accounting/command_service.php — accountingTryEnqueueDraft()\n";
$cs = $read("{$ROOT}/core/accounting/command_service.php");
$a('declares accountingTryEnqueueDraft',          str_contains($cs, 'function accountingTryEnqueueDraft('));
$a('  command map bill/invoice/journal',          str_contains($cs, "'bill'    => 'create_draft_bill',")
                                               && str_contains($cs, "'invoice' => 'create_draft_invoice',")
                                               && str_contains($cs, "'journal' => 'create_draft_journal',"));
$a('  bails on unknown object type',              str_contains($cs, 'if (!isset($commandMap[$objectType])) return null;'));
$a('  bails on zero row id',                      str_contains($cs, 'if ($rowId <= 0) return null;'));
$a('  resolves sub_tenant from row.entity_id',    str_contains($cs, "(int) (\$row['sub_tenant_id'] ?? \$row['entity_id'] ?? 0);"));
$a('  validates active connection for row entity',str_contains($cs, "AND connection_status = 'active'"));
$a('  ambiguous tenant (≥2 active) → skip',       str_contains($cs, 'if (count($rows) !== 1) return null;'));
$a('  stable idempotency key with version',       str_contains($cs, "sprintf('%s:%d:v=%s'"));
$a('  payload carries coreflux_object_type/id',   str_contains($cs, "'coreflux_object_type' => \$objectType,")
                                               && str_contains($cs, "'coreflux_object_id'   => \$rowId,"));
$a('  payload preserves raw row under \"row\" key',
    str_contains($cs, "'row'                  => \$row,"));
$a('  swallows enqueue exception (never blocks)', str_contains($cs, "error_log('[accountingTryEnqueueDraft]"));

// --- AP bills wiring -------------------------------------------
echo "\nmodules/ap/api/bills.php — on approve\n";
$ap = $read("{$ROOT}/modules/ap/api/bills.php");
$a('approve action present',                      str_contains($ap, "if (\$method === 'POST' && \$action === 'approve')"));
$a('audit fires first (existing behavior unchanged)',
    str_contains($ap, "apAudit('ap.bill.approved'"));
$a('accountingTryEnqueueDraft called with bill',  str_contains($ap, "accountingTryEnqueueDraft(\$tid, 'bill', \$row, \$user['id'] ?? null);"));
$a('stamps approved status on local row first',   str_contains($ap, "\$row['status']      = 'approved';"));
$a('  + approved_at timestamp',                   str_contains($ap, "\$row['approved_at'] = date('Y-m-d H:i:s');"));
$a('comment marks Slice 3 hook',                  str_contains($ap, 'Jaz hook (Slice 3)'));

// --- AR invoices wiring ----------------------------------------
echo "\nmodules/billing/api/invoices.php — on approve\n";
$ar = $read("{$ROOT}/modules/billing/api/invoices.php");
$a('billing approve action still present',        str_contains($ar, "billingAudit('billing.invoice.approved'"));
$a('requires command_service.php',                str_contains($ar, "require_once __DIR__ . '/../../../core/accounting/command_service.php';"));
$a('accountingTryEnqueueDraft called with invoice',
    str_contains($ar, "accountingTryEnqueueDraft(\$tid, 'invoice', \$row, \$user['id'] ?? null);"));
$a('comment marks Slice 3 hook',                  str_contains($ar, 'Jaz hook (Slice 3)'));

// --- JE post wiring --------------------------------------------
echo "\nmodules/accounting/lib/accounting.php — on JE post\n";
$je = $read("{$ROOT}/modules/accounting/lib/accounting.php");
$a('hook lives inside post-only block',           str_contains($je, "accountingTryEnqueueDraft(\$tenantId, 'journal',"));
$a('passes entity_id as sub-tenant signal',       str_contains($je, "'entity_id'    => \$entityId,"));
$a('passes je_number + posting_date + currency',  str_contains($je, "'je_number'    => \$jeNumber,")
                                               && str_contains($je, "'posting_date' => \$postingDate,")
                                               && str_contains($je, "'currency'     => \$currency,"));
$a('total_debit + total_credit carried',          str_contains($je, "'total_debit'  => \$totalDebit,")
                                               && str_contains($je, "'total_credit' => \$totalCredit,"));
$a('lines from resolved set carried',             str_contains($je, "'lines'        => \$resolved,"));
$a('wrapped in try/catch (never blocks post)',    str_contains($je, '/* never block the post */'));

// --- outbox worker ---------------------------------------------
echo "\ncron/accounting_outbox_worker.php\n";
$w = $read("{$ROOT}/cron/accounting_outbox_worker.php");
$a('declares strict_types',                       str_contains($w, 'declare(strict_types=1);'));
$a('requires command_service.php',                str_contains($w, "require_once __DIR__ . '/../core/accounting/command_service.php';"));
$a('requires provider_adapter.php',               str_contains($w, "require_once __DIR__ . '/../core/accounting/provider_adapter.php';"));
$a('reads --tenant / --max-rows / --dry-run',     str_contains($w, "getopt('', ['tenant::', 'max-rows::', 'dry-run']);"));
$a('selects queued + retrying',                   str_contains($w, "WHERE status IN ('queued','retrying')"));
$a('respects next_retry_at <= NOW()',             str_contains($w, "next_retry_at IS NULL OR next_retry_at <= NOW()"));
$a('caps --max-rows default 100',                 str_contains($w, 'isset($opts[\'max-rows\']) ? max(1, (int) $opts[\'max-rows\']) : 100'));
$a('schema-not-ready exits clean (0)',            str_contains($w, 'schema not ready')
                                               && str_contains($w, 'exit(0);'));
$a('dispatches via accountingCommandExecute',     str_contains($w, '$after = accountingCommandExecute($tid, $cid);'));
$a('dry-run prints DRY-RUN without executing',    str_contains($w, "  DRY-RUN  "));
$a('per-row try/catch isolates errors',           str_contains($w, "catch (\\Throwable \$e) {")
                                               && str_contains($w, "EXCEPT   command="));
$a('exception path nudges row back to retrying',  str_contains($w, "SET status = 'retrying',")
                                               && str_contains($w, "DATE_ADD(NOW(), INTERVAL 60 SECOND)"));
$a('summary stdout line at end',                  str_contains($w, '[accounting_outbox_worker] %d processed'));

// --- functional helper test (no DB needed for the bail paths) --
echo "\nFunctional helper bail paths\n";
require_once "{$ROOT}/core/accounting/provider_adapter.php";
require_once "{$ROOT}/core/accounting/command_service.php";

$a('unknown object_type returns null',            accountingTryEnqueueDraft(1, 'gizmo', ['id' => 1]) === null);
$a('missing row id returns null',                 accountingTryEnqueueDraft(1, 'bill', []) === null);
$a('zero row id returns null',                    accountingTryEnqueueDraft(1, 'bill', ['id' => 0]) === null);

// --- PHP syntax checks -----------------------------------------
echo "\nPHP syntax checks\n";
foreach ([
    'core/accounting/command_service.php',
    'cron/accounting_outbox_worker.php',
    'modules/ap/api/bills.php',
    'modules/billing/api/invoices.php',
    'modules/accounting/lib/accounting.php',
] as $f) {
    $r = shell_exec("php -l {$ROOT}/{$f} 2>&1");
    $a("{$f} parses",                              is_string($r) && str_contains($r, 'No syntax errors'));
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "Jaz Slice 3: {$pass} ✓ / {$fail} ✗\n";
exit($fail > 0 ? 1 : 0);

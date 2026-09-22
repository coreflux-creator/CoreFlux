<?php
/**
 * Sprint 7e — Subledger replay + Billing migration smoke.
 *
 * Asserts:
 *   - api/ap_bill_replay.php (POST-only, RBAC, days clamp 1..1825,
 *     since regex, source_module='ap_replay', audit-only linking to the
 *     existing JE, status filter clamping, idempotency check).
 *   - api/billing_invoice_replay.php (same shape).
 *   - Module-namespaced kebab aliases delegate cleanly.
 *   - modules/billing/api/invoices.php?action=post emits
 *     billing.invoice.sent and falls back to direct accountingPostJe
 *     with subledger_links + event-status flip.
 *   - RuleSandbox.jsx exposes the new subledger replay strip with the
 *     full set of testids.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$lint = function (string $p): bool {
    $o = []; $rc = 0; @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
    return $rc === 0;
};
$ROOT = realpath(__DIR__ . '/..');

echo "AP bill replay — api/ap_bill_replay.php\n";
$apr = (string) file_get_contents("{$ROOT}/api/ap_bill_replay.php");
$assert('endpoint exists',                       strlen($apr) > 0);
$assert('parses',                                $lint("{$ROOT}/api/ap_bill_replay.php"));
$assert('POST-only',                             strpos($apr, "if (api_method() !== 'POST')") !== false);
$assert('RBAC accounting.manage_posting_rules',  strpos($apr, "rbac_legacy_require(\$user, 'accounting.manage_posting_rules')") !== false);
$assert('days clamp 1..1825',                    strpos($apr, "max(1, min(1825,") !== false);
$assert('since regex YYYY-MM-DD',                strpos($apr, "preg_match('/^\\d{4}-\\d{2}-\\d{2}$/'") !== false);
$assert('status filter whitelist',               strpos($apr, "['approved','partially_paid','paid']") !== false);
$assert('source_module=ap_replay',               strpos($apr, "'source_module'    => 'ap_replay'") !== false);
$assert('event_type=ap.bill.approved',           strpos($apr, "'event_type'       => 'ap.bill.approved'") !== false);
$assert('source_record_id=ap_bill:<id>',         strpos($apr, "'ap_bill:' . \$billId") !== false);
$assert('only-unlinked checks live event row',
    strpos($apr, "source_module = 'ap'") !== false
    && strpos($apr, '$liveEvCheck->execute') !== false);
$assert('idempotent skip if replay event exists',
    strpos($apr, "source_module = 'ap_replay'") !== false
    && strpos($apr, "skipped_already_event") !== false);
$assert('snapshots original posted journal lines',
    strpos($apr, 'FROM accounting_journal_entries je') !== false
    && strpos($apr, 'JOIN accounting_journal_entry_lines jl') !== false
    && strpos($apr, 'je.status = "posted"') !== false);
$assert('preserves original line dimensions',
    strpos($apr, "json_decode((string) \$line['dim_json']") !== false
    && strpos($apr, "'dims' => \$dimensions") !== false);
$assert('replay payload flag',                   strpos($apr, "'replay'       => true") !== false);
$assert('audit-link-only replay mode',
    strpos($apr, "'replay_mode'  => 'audit_link_only'") !== false);
$assert('original_journal_entry_id captured',
    strpos($apr, "'original_journal_entry_id' => (int) \$b['journal_entry_id']") !== false);
$assert('replay never invokes posting engine',
    strpos($apr, 'accountingProcessEvent') === false
    && strpos($apr, 'posting_engine/process.php') === false);
$assert('writes event and linked existing JE atomically',
    strpos($apr, 'INSERT IGNORE INTO accounting_events') !== false
    && strpos($apr, 'INSERT IGNORE INTO accounting_subledger_links') !== false
    && strpos($apr, 'accounting_event_id') !== false
    && strpos($apr, 'cf_tx_begin($pdo)') !== false
    && strpos($apr, 'cf_tx_commit($pdo, $ownsTxn)') !== false);
$assert('returns full counts envelope',
    strpos($apr, "'replayed'") !== false
    && strpos($apr, "'skipped_already_event'") !== false
    && strpos($apr, "'skipped_no_je'") !== false
    && strpos($apr, "'failed'") !== false);
$assert('errors truncated at 50',                strpos($apr, "count(\$out['errors']) > 50") !== false);

echo "\nBilling invoice replay — api/billing_invoice_replay.php\n";
$bir = (string) file_get_contents("{$ROOT}/api/billing_invoice_replay.php");
$assert('endpoint exists',                       strlen($bir) > 0);
$assert('parses',                                $lint("{$ROOT}/api/billing_invoice_replay.php"));
$assert('POST-only',                             strpos($bir, "if (api_method() !== 'POST')") !== false);
$assert('RBAC accounting.manage_posting_rules',  strpos($bir, "rbac_legacy_require(\$user, 'accounting.manage_posting_rules')") !== false);
$assert('source_module=billing_replay',          strpos($bir, "'source_module'    => 'billing_replay'") !== false);
$assert('event_type=billing.invoice.sent',       strpos($bir, "'event_type'       => 'billing.invoice.sent'") !== false);
$assert('status filter whitelist',
    strpos($bir, "['approved','sent','partially_paid','paid']") !== false);
$assert('snapshots original posted journal lines',
    strpos($bir, 'FROM accounting_journal_entries je') !== false
    && strpos($bir, 'JOIN accounting_journal_entry_lines jl') !== false
    && strpos($bir, 'je.status = "posted"') !== false);
$assert('preserves original line dimensions',
    strpos($bir, "json_decode((string) \$line['dim_json']") !== false
    && strpos($bir, "'dims' => \$dimensions") !== false);
$assert('audit-link-only replay mode',
    strpos($bir, "'replay_mode'       => 'audit_link_only'") !== false);
$assert('replay never invokes posting engine',
    strpos($bir, 'accountingProcessEvent') === false
    && strpos($bir, 'posting_engine/process.php') === false);
$assert('writes event and linked existing JE atomically',
    strpos($bir, 'INSERT IGNORE INTO accounting_events') !== false
    && strpos($bir, 'INSERT IGNORE INTO accounting_subledger_links') !== false
    && strpos($bir, 'accounting_event_id') !== false
    && strpos($bir, 'cf_tx_begin($pdo)') !== false
    && strpos($bir, 'cf_tx_commit($pdo, $ownsTxn)') !== false);

echo "\nModule-namespaced kebab aliases\n";
$apAlias = "{$ROOT}/modules/ap/api/bill_replay.php";
$bgAlias = "{$ROOT}/modules/billing/api/invoice_replay.php";
$assert('AP alias exists',                       is_file($apAlias));
$assert('AP alias parses',                       $lint($apAlias));
$assert('AP alias delegates',
    strpos((string) file_get_contents($apAlias), "require __DIR__ . '/../../../api/ap_bill_replay.php'") !== false);
$assert('Billing alias exists',                  is_file($bgAlias));
$assert('Billing alias parses',                  $lint($bgAlias));
$assert('Billing alias delegates',
    strpos((string) file_get_contents($bgAlias), "require __DIR__ . '/../../../api/billing_invoice_replay.php'") !== false);

echo "\nBilling invoice → event-layer migration\n";
$inv = (string) file_get_contents("{$ROOT}/modules/billing/api/invoices.php");
$assert('parses',                                $lint("{$ROOT}/modules/billing/api/invoices.php"));
$assert('require posting_engine/process.php',
    strpos($inv, "require_once __DIR__ . '/../../../core/posting_engine/process.php'") !== false);
$assert('emits billing.invoice.sent',            strpos($inv, "'event_type'       => 'billing.invoice.sent'") !== false);
$assert('source_module = billing',
    strpos($inv, "'source_module'    => 'billing'") !== false
    && strpos($inv, "'source_record_id' => 'billing_invoice:' . \$id") !== false);
$assert('payload carries lines[]',               strpos($inv, "'lines'          => \$payloadLines") !== false);
$assert('preferred path: stamp journal_entry_id from event',
    strpos($inv, "'j' => \$eventResult['journal_entry_id']") !== false);
$assert('preferred path: audit via=event_layer',
    strpos($inv, "'via' => 'event_layer'") !== false);
$assert('fallback: legacy accountingPostJe still wired',
    strpos($inv, "\$res = accountingPostJe(\$tid, [") !== false);
$assert('fallback: writes subledger_links',
    strpos($inv, 'INSERT IGNORE INTO accounting_subledger_links') !== false
    && strpos($inv, '"billing"') !== false);
$assert('fallback: flips event row to posted',
    strpos($inv, 'UPDATE accounting_events') !== false
    && strpos($inv, 'fallback: legacy direct post (no rule matched)') !== false);
$assert('fallback: audit via=legacy_direct',
    strpos($inv, "'via' => 'legacy_direct'") !== false);

echo "\nRuleSandbox UI — subledger replay strip\n";
$ui = (string) file_get_contents("{$ROOT}/dashboard/src/pages/RuleSandbox.jsx");
$assert('subledger replay state hooks',
    strpos($ui, 'setSubledgerKind') !== false
    && strpos($ui, 'setSubledgerDays') !== false
    && strpos($ui, 'setSubledgerDryRun') !== false
    && strpos($ui, 'setSubledgerOnlyUnlinked') !== false);
$assert('routes to ap_bill_replay.php for ap_bill kind',
    strpos($ui, '/api/ap_bill_replay.php') !== false);
$assert('routes to billing_invoice_replay.php for billing_invoice kind',
    strpos($ui, '/api/billing_invoice_replay.php') !== false);
foreach ([
    'subledger-replay-strip', 'subledger-replay-kind',
    'subledger-replay-days', 'subledger-replay-only-unlinked',
    'subledger-replay-dry-run', 'subledger-replay-run',
    'subledger-replay-result',  'subledger-replay-error',
] as $id) {
    $assert("testid: rule-sandbox-{$id}",
        strpos($ui, "data-testid=\"rule-sandbox-{$id}\"") !== false);
}

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);

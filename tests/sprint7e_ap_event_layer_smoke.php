<?php
/**
 * Sprint 7e (vertical slice — AP) smoke.
 *
 * Asserts:
 *   - Migration 019 adds line_source ENUM('template','payload') (idempotent guard).
 *   - postingEngineRender supports line_source='payload' (passthrough).
 *   - Default seed pack now contains AP/AR rules + uses passthrough where needed.
 *   - postingRulesSeedDefaults handles passthrough templates (skips line inserts).
 *   - modules/ap/api/bills.php?action=post emits ap.bill.approved through
 *     accountingProcessEvent and falls back to direct accountingPostJe + audit
 *     when no rule is seeded.
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

echo "Migration — 019_journal_template_line_source.sql\n";
$migPath = "{$ROOT}/modules/accounting/migrations/019_journal_template_line_source.sql";
$mig = (string) file_get_contents($migPath);
$assert('migration file exists',                is_file($migPath));
$assert('idempotent: information_schema guard', strpos($mig, 'information_schema.columns') !== false);
$assert('column line_source added',             strpos($mig, "ADD COLUMN line_source ENUM('template','payload')") !== false);
$assert('default = template (BC-safe)',         strpos($mig, "DEFAULT 'template'") !== false);

echo "\nposting_engine/process.php — passthrough render\n";
$proc = (string) file_get_contents("{$ROOT}/core/posting_engine/process.php");
$assert('parses',                            $lint("{$ROOT}/core/posting_engine/process.php"));
$assert('reads line_source from template',   strpos($proc, "(string) (\$tpl['line_source'] ?? 'template')") !== false);
$assert('passthrough branch reads payload.lines',
    strpos($proc, "\$payloadLines = \$context['payload']['lines'] ?? null") !== false);
$assert('passthrough requires >= 2 lines',
    strpos($proc, "count(\$payloadLines) < 2") !== false);
$assert('passthrough resolves account_id OR account_code',
    strpos($proc, "\$pl['account_id']")   !== false
    && strpos($proc, "\$pl['account_code']") !== false);
$assert('passthrough rejects negative amounts',
    strpos($proc, "negative amount") !== false);
$assert('passthrough rejects mixed Dr+Cr',
    strpos($proc, "cannot have both debit and credit") !== false);
$assert('passthrough verifies balance',
    strpos($proc, "round(\$td, 2) !== round(\$tc, 2)") !== false);
$assert('passthrough returns standard JE shape',
    strpos($proc, "'lines'        => \$lines,") !== false);

echo "\nseed_defaults.php — AP / AR pack entries\n";
$sd = (string) file_get_contents("{$ROOT}/core/posting_engine/seed_defaults.php");
$assert('parses',                                   $lint("{$ROOT}/core/posting_engine/seed_defaults.php"));
$assert('event ap.bill.approved present',           strpos($sd, "'ap.bill.approved'") !== false);
$assert('event billing.invoice.sent present',       strpos($sd, "'billing.invoice.sent'") !== false);
$assert('event ap.payment.cleared present',         strpos($sd, "'ap.payment.cleared'") !== false);
$assert('event billing.payment.received present',   strpos($sd, "'billing.payment.received'") !== false);
$assert('AP bill template is passthrough',
    strpos($sd, "'name'           => 'AP bill approved — passthrough'") !== false
    && strpos($sd, "'line_source'    => 'payload'") !== false);
$assert('insTpl carries line_source param',
    strpos($sd, 'INSERT INTO accounting_journal_templates (tenant_id, name, memo_template, currency_source, line_source)') !== false);
$assert('seed loop sets line_source per entry',
    strpos($sd, "\$lineSource = (string) (\$entry['template']['line_source'] ?? 'template')") !== false);
$assert("seed loop skips line inserts on passthrough",
    strpos($sd, "if (\$lineSource === 'template') {") !== false);

echo "\nAP bills.php — event-layer migration (preferred + fallback)\n";
$bills = (string) file_get_contents("{$ROOT}/modules/ap/api/bills.php");
$assert('parses',                                $lint("{$ROOT}/modules/ap/api/bills.php"));
$assert('require posting_engine/process.php',
    strpos($bills, "require_once __DIR__ . '/../../../core/posting_engine/process.php'") !== false);
$assert('emits ap.bill.approved event',          strpos($bills, "'event_type'       => 'ap.bill.approved'") !== false);
$assert('source_module = ap',                    strpos($bills, "'source_module'    => 'ap'") !== false);
$assert('source_record_id namespaced ap_bill:',  strpos($bills, "'source_record_id' => 'ap_bill:' . \$id") !== false);
$assert('payload carries lines[] for passthrough',
    strpos($bills, "'lines'        => \$payloadLines,") !== false);
$assert('payload carries currency + amount',
    strpos($bills, "'amount'       => (float) \$row['total']") !== false
    && strpos($bills, "'currency'     => (string) \$row['currency']") !== false);
$assert('preferred path: stamp journal_entry_id from event result',
    strpos($bills, "'j' => \$eventResult['journal_entry_id']") !== false);
$assert('preferred path: audit via=event_layer',
    strpos($bills, "'via' => 'event_layer'") !== false);
$assert('fallback: legacy accountingPostJe still wired',
    strpos($bills, '$res = accountingPostJe($tid,') !== false);
$assert('fallback: writes subledger_links',
    strpos($bills, 'INSERT IGNORE INTO accounting_subledger_links') !== false);
$assert('fallback: flips ignored event → posted',
    strpos($bills, 'UPDATE accounting_events') !== false
    && strpos($bills, 'fallback: legacy direct post (no rule matched)') !== false);
$assert('fallback: audit via=legacy_direct',
    strpos($bills, "'via' => 'legacy_direct'") !== false);
$assert('idempotent replay path retained',
    strpos($bills, "'idempotent_replay' => true") !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);

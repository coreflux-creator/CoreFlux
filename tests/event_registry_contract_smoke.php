<?php
/**
 * Phase 1a — Event Registry contract smoke (Live Books Rails, 2026-02-14).
 *
 * Pins:
 *   • Migration 036_event_registry.sql creates the right shape.
 *   • Seed defines 52 canonical events + 3 deprecated aliases.
 *   • Helper library exposes the public surface we depend on.
 *   • posting_engine/process.php validates every emit against the registry.
 *   • Every event_type referenced by an existing emit site in the codebase
 *     IS in the seed (either as a canonical row OR a deprecated alias).
 *   • No deprecated alias is missing its `deprecated_alias_for` pointer.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "Migration\n";
$mig = $read(__DIR__ . '/../core/migrations/036_event_registry.sql');
$a('creates event_registry',                  str_contains($mig, 'CREATE TABLE IF NOT EXISTS event_registry'));
$a('PK on (event_type, schema_version)',      str_contains($mig, 'PRIMARY KEY (event_type, schema_version)'));
$a('schema_version default 1',                str_contains($mig, 'schema_version        INT UNSIGNED NOT NULL DEFAULT 1'));
$a('deprecated_alias_for column',             str_contains($mig, 'deprecated_alias_for  VARCHAR(120)'));
$a('JSON columns for payload keys/consumers', str_contains($mig, 'required_payload_keys JSON') && str_contains($mig, 'expected_consumers    JSON'));

echo "\nSeed file\n";
require_once __DIR__ . '/../core/seeds/event_registry_seed.php';
$rows    = eventRegistrySeedRows();
$aliases = eventRegistryAliasRows();
$a('seed defines exactly 52 canonical events', count($rows) === 52);
$a('seed defines exactly 3 deprecated aliases', count($aliases) === 3);
$a('every seed row is a 9-tuple', (function () use ($rows) {
    foreach ($rows as $r) if (count($r) !== 9) return false; return true;
})());

// Inspect for catalog coverage.
$canonicalNames = array_column($rows, 0);
foreach ([
    'ar.invoice.issued','ar.payment.received','ar.cash.applied','ar.writeoff.recorded',
    'ap.bill.approved','ap.payment.executed','ap.payment.cleared','ap.po.issued',
    'treasury.transfer.completed','treasury.bank_transaction.matched','treasury.bank_fee.detected','treasury.fx.revaluation.recorded',
    'payroll.run.approved','payroll.cash.disbursed','payroll.tax_liability.paid',
    'staffing.worker_hours.approved','staffing.worker.classification_changed',
    'fixed_asset.depreciation.recorded','tax.sales_tax.collected',
    'period.close.locked','accounting.je.reversed','accounting.ai.interpretation_overridden',
    'capital.contribution.received',
] as $required) {
    $a("seed includes {$required}",          in_array($required, $canonicalNames, true));
}

$a('alias billing.invoice.sent → ar.invoice.issued',
    in_array(['billing.invoice.sent','ar.invoice.issued'], $aliases, true));
$a('alias billing.payment.received → ar.payment.received',
    in_array(['billing.payment.received','ar.payment.received'], $aliases, true));
$a('alias treasury.payment.executed → ap.payment.executed',
    in_array(['treasury.payment.executed','ap.payment.executed'], $aliases, true));

// Sanity: each alias points at a real canonical name.
foreach ($aliases as [$legacy, $canonical]) {
    $a("alias target exists: {$legacy} → {$canonical}",  in_array($canonical, $canonicalNames, true));
}

// Sanity: each row's optional_payload_keys / expected_consumers / parent_event_types are arrays (not nulls).
$malformed = 0;
foreach ($rows as $r) {
    if (!is_array($r[3]) || !is_array($r[4]) || !is_array($r[6]) || !is_array($r[7])) $malformed++;
}
$a('every seed row has array-typed JSON columns', $malformed === 0);

echo "\nHelper library\n";
$lib = $read(__DIR__ . '/../core/event_registry.php');
foreach (['_eventRegistryLoadAll','eventRegistryGet','eventRegistryAll','eventRegistryValidate'] as $fn) {
    $a("library defines {$fn}",                str_contains($lib, "function {$fn}("));
}
$a('library graceful when table missing',     str_contains($lib, 'Migration not run on this tenant — fall back to warn-only'));
$a('library auto-seeds when table is empty',  str_contains($lib, 'eventRegistrySeedRun($pdo)'));
$a('library resolves alias → canonical',      str_contains($lib, "_alias_for"));
$a('library returns registry_present flag',   str_contains($lib, "'registry_present'"));

echo "\nPosting engine wire-in\n";
$proc = $read(__DIR__ . '/../core/posting_engine/process.php');
$a('process requires event_registry.php',     str_contains($proc, "require_once __DIR__ . '/../event_registry.php'"));
$a('process calls eventRegistryValidate',     str_contains($proc, 'eventRegistryValidate('));
$a('process rejects invalid events',          str_contains($proc, 'Event rejected by registry'));
$a('process warns (not throws) on warnings',  str_contains($proc, '$validation[\'warnings\']') && str_contains($proc, 'error_log'));

echo "\nCoverage of existing emit sites\n";
// Walk the codebase for every accountingProcessEvent($..., ['event_type' => '...'])
// and assert each event_type IS in seed OR alias list.
$emitCalls = [];
foreach ([
    'modules/ap/api/bills.php',
    'modules/billing/api/invoices.php',
    'modules/staffing/lib/timesheets.php',
    'api/treasury_transfers.php',
    'api/treasury_payments.php',
] as $rel) {
    $src = $read(__DIR__ . '/../' . $rel);
    preg_match_all("/'event_type'\s*=>\s*'([a-z._:]+)'/", $src, $m);
    foreach ($m[1] as $type) $emitCalls[$type] = $rel;
}
$allValidNames = array_merge($canonicalNames, array_column($aliases, 0));
foreach ($emitCalls as $type => $where) {
    $a("existing emit '{$type}' (in {$where}) is registry-valid",
        in_array($type, $allValidNames, true));
}

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

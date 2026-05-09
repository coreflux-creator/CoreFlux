<?php
/**
 * Scenario presets smoke — TreasuryScenario.jsx one-click templated event lists.
 *
 * Asserts:
 *   - SCENARIO_PRESETS catalog declared with at least 5 templates.
 *   - Each preset has key/label/description/build() factory.
 *   - Date math helpers (addDays, monthAhead) defined and used so dates
 *     are always relative to "now" (preset never goes stale).
 *   - applyPreset adds the templated events to the existing stack
 *     (additive — does not blow away prior events).
 *   - clearAll button surfaced when events present.
 *   - Per-preset render testid + preset-bar root testid.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$ROOT = realpath(__DIR__ . '/..');

echo "Catalog — SCENARIO_PRESETS declaration\n";
$pg = (string) file_get_contents("{$ROOT}/dashboard/src/pages/TreasuryScenario.jsx");
$assert('SCENARIO_PRESETS array declared',        strpos($pg, 'const SCENARIO_PRESETS = [') !== false);
$assert('imports Wand2 icon',                     strpos($pg, "Wand2 } from 'lucide-react'") !== false || strpos($pg, "Wand2,") !== false || strpos($pg, ', Wand2 ') !== false);

foreach (['hire_contractors','lose_big_customer','delay_ap_30','tax_payment','term_loan'] as $key) {
    $assert("preset '{$key}' present",
        preg_match("/key:\\s*'{$key}'/", $pg) === 1);
}

echo "\nDate helpers — relative to now\n";
$assert('addDays helper defined',                 strpos($pg, 'const addDays = (n) => {') !== false);
$assert('monthAhead helper defined',              strpos($pg, 'const monthAhead = (m, day = 1) => {') !== false);
$assert('addDays returns YYYY-MM-DD',             strpos($pg, "d.toISOString().slice(0, 10)") !== false);
$assert('term_loan inflow uses today() (immediate funding)',
    preg_match("/key:\\s*'term_loan'.*?date: today\\(\\)/s", $pg) === 1);
$assert('hire_contractors uses monthAhead(1..3)',
    preg_match("/key:\\s*'hire_contractors'.*?monthAhead\\(1\\).*?monthAhead\\(2\\).*?monthAhead\\(3\\)/s", $pg) === 1);
$assert('tax_payment uses addDays(60)',
    preg_match("/key:\\s*'tax_payment'.*?addDays\\(60\\)/s", $pg) === 1);

echo "\nBehaviour — applyPreset + clearAll\n";
$assert('applyPreset declared',                   strpos($pg, 'const applyPreset = (preset) => {') !== false);
$assert('applyPreset is additive (spreads existing events)',
    strpos($pg, 'const next = [...events, ...built];') !== false);
$assert('applyPreset re-runs projection',         strpos($pg, 'run(next, days);') !== false);
$assert('clearAll declared',                      strpos($pg, 'const clearAll = () => {') !== false);
$assert('clearAll resets events to []',           strpos($pg, 'setEvents([]);') !== false);

echo "\nUI wiring — preset bar testids\n";
$assert('preset bar root testid',                 strpos($pg, 'data-testid="scenario-presets-bar"') !== false);
$assert('per-preset button testid template',      strpos($pg, 'data-testid={`scenario-preset-${p.key}`}') !== false);
$assert('clear-all button testid',                strpos($pg, 'data-testid="scenario-clear-all"') !== false);
$assert('clear-all only shown when events.length > 0',
    strpos($pg, 'events.length > 0 && (') !== false);
$assert('iterates SCENARIO_PRESETS to render buttons',
    strpos($pg, 'SCENARIO_PRESETS.map((p)') !== false);
$assert('preset button renders label + description',
    strpos($pg, '{p.label}') !== false && strpos($pg, '{p.description}') !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);

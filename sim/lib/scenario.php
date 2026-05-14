<?php
/**
 * Scenario loader.
 *
 * Scenarios are JSON files under /app/sim/scenarios/*.json. Each declares:
 *   {
 *     "name":        "ap_bill_happy_path",
 *     "description": "AP bill → approve → post → pay → match",
 *     "default_seed": 42,
 *     "expected": { "events_emitted": 4, "je_posted": 4, "assertions_failed": 0 },
 *     "steps": [
 *       { "action": "create_vendor",  "name": "Acme",         "vendor_type": "1099" },
 *       { "action": "create_bill",    "vendor": "Acme",       "amount": 1500.00, "lines": [...] },
 *       { "action": "approve_bill",   "bill_ref": "$last_bill_id" },
 *       ...
 *     ],
 *     "invariants": ["debits_equal_credits", "replay_reproducible", "no_orphan_events", "no_direct_gl"]
 *   }
 */
declare(strict_types=1);

const SIM_SCENARIO_DIR = __DIR__ . '/../scenarios';

function simLoadScenario(string $name): array {
    $path = SIM_SCENARIO_DIR . '/' . preg_replace('/[^a-z0-9_]/', '', strtolower($name)) . '.json';
    if (!is_file($path)) {
        throw new \RuntimeException('Scenario not found: ' . $name);
    }
    $raw = file_get_contents($path);
    $sc  = json_decode($raw, true);
    if (!is_array($sc)) {
        throw new \RuntimeException('Scenario is not valid JSON: ' . $name);
    }
    foreach (['name', 'steps'] as $k) {
        if (!isset($sc[$k])) throw new \RuntimeException("Scenario missing required key '$k'");
    }
    $sc['invariants']   = $sc['invariants']   ?? ['debits_equal_credits', 'replay_reproducible', 'no_orphan_events'];
    $sc['default_seed'] = (int) ($sc['default_seed'] ?? 1);
    $sc['expected']     = $sc['expected']     ?? [];
    return $sc;
}

function simListScenarios(): array {
    $out = [];
    if (!is_dir(SIM_SCENARIO_DIR)) return $out;
    foreach (glob(SIM_SCENARIO_DIR . '/*.json') as $p) {
        $sc = json_decode((string) file_get_contents($p), true);
        if (is_array($sc) && !empty($sc['name'])) {
            $out[] = [
                'name'         => $sc['name'],
                'description'  => $sc['description']  ?? '',
                'default_seed' => (int) ($sc['default_seed'] ?? 1),
                'invariants'   => $sc['invariants']   ?? [],
                'step_count'   => is_array($sc['steps'] ?? null) ? count($sc['steps']) : 0,
                'path'         => basename($p),
            ];
        }
    }
    return $out;
}

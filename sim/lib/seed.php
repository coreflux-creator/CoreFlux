<?php
/**
 * Simulation seed + deterministic time + RNG.
 *
 * Per harness spec §18: every simulation run carries a seed; every
 * randomized value (entity counts, amounts, dates, IDs) and every
 * "now()" must derive from that seed so replays produce byte-identical
 * output.
 *
 * Usage:
 *     simSeed(42);
 *     $amount  = simRandFloat(100, 5000);
 *     $name    = simRandPick(['Acme', 'Globex', 'Initech']);
 *     $today   = simNow();           // deterministic UTC datetime
 *     simAdvance('+1 day');          // step the sim clock forward
 */
declare(strict_types=1);

$GLOBALS['__sim_seed']  = null;
$GLOBALS['__sim_state'] = null;
$GLOBALS['__sim_clock'] = null;

/** Initialize a sim run. Call once at the top of every scenario. */
function simSeed(int $seed, string $startDate = '2026-01-01 00:00:00'): void {
    $GLOBALS['__sim_seed']  = $seed;
    $GLOBALS['__sim_state'] = $seed;
    $GLOBALS['__sim_clock'] = strtotime($startDate . ' UTC');
    if (!$GLOBALS['__sim_clock']) {
        $GLOBALS['__sim_clock'] = strtotime('2026-01-01 00:00:00 UTC');
    }
    mt_srand($seed);
}

/** Linear congruential RNG — same seed → same sequence on every platform. */
function simRandInt(int $min = 0, int $max = PHP_INT_MAX): int {
    if ($GLOBALS['__sim_state'] === null) simSeed(0);
    // Numerical Recipes LCG constants — 32-bit-safe.
    $s = ($GLOBALS['__sim_state'] * 1103515245 + 12345) & 0x7FFFFFFF;
    $GLOBALS['__sim_state'] = $s;
    $range = $max - $min + 1;
    return $min + (int) ($s % $range);
}

function simRandFloat(float $min, float $max, int $decimals = 2): float {
    $r = simRandInt(0, 1_000_000) / 1_000_000.0;
    return round($min + $r * ($max - $min), $decimals);
}

function simRandPick(array $opts) {
    if (empty($opts)) return null;
    return $opts[simRandInt(0, count($opts) - 1)];
}

function simRandId(string $prefix = 'SIM'): string {
    return sprintf('%s-%06d', $prefix, simRandInt(100000, 999999));
}

/** Deterministic "now" — moves forward only via simAdvance(). */
function simNow(string $fmt = 'Y-m-d H:i:s'): string {
    if ($GLOBALS['__sim_clock'] === null) simSeed(0);
    return gmdate($fmt, $GLOBALS['__sim_clock']);
}

function simAdvance(string $modifier): void {
    if ($GLOBALS['__sim_clock'] === null) simSeed(0);
    $ts = strtotime($modifier, $GLOBALS['__sim_clock']);
    if ($ts !== false) $GLOBALS['__sim_clock'] = $ts;
}

/** SHA-256 of a canonical JSON encoding — used for replay hash diffing. */
function simHash($v): string {
    return hash('sha256', json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

<?php
/** Pure JobDiva date normalization shared by sync and standalone contract readers. */
declare(strict_types=1);

function jobdivaNormaliseDate(mixed $raw): ?string
{
    if ($raw === null) return null;
    if (is_scalar($raw)) $raw = trim((string) $raw);
    else return null;
    if ($raw === '' || $raw === '0' || $raw === 'null') return null;

    // Preserve the account-local business date rather than shifting end-of-day timestamps.
    if (preg_match('/^\d{4}-\d{2}-\d{2}(?:[T\s]|$)/', $raw)) {
        return substr($raw, 0, 10);
    }
    if (ctype_digit($raw)) {
        $n = (int) $raw;
        if ($n >= 1_000_000_000_000) {
            $n = (int) ($n / 1000);
        }
        if ($n >= 100_000_000) {
            return gmdate('Y-m-d', $n);
        }
        return null;
    }
    $ts = strtotime($raw);
    if ($ts !== false) return gmdate('Y-m-d', $ts);
    return null;
}

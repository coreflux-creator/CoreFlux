<?php
/**
 * Reports Module — Period resolver.
 *
 * Translates UI period codes into [from, to] date ranges plus a weekly
 * bucket list the dashboards use for trend lines.
 *
 * Accepted codes (per Reports spec §Time period options):
 *   1w, 2w, 4w (default), 8w, 12w, mtd, last_month,
 *   qtd, last_quarter, ytd, last_12m, last_year
 *
 * Custom range: pass from=YYYY-MM-DD + to=YYYY-MM-DD to reportsResolvePeriod()
 * which bypasses the code lookup.
 */
declare(strict_types=1);

/**
 * @return array{code:string, label:string, from:string, to:string, weeks:list<array{start:string,end:string,label:string}>}
 */
function reportsResolvePeriod(?string $code, ?string $customFrom = null, ?string $customTo = null): array {
    $today = new DateTimeImmutable('today');
    $code  = $code ?: '4w';

    if ($customFrom && $customTo) {
        $from = new DateTimeImmutable($customFrom);
        $to   = new DateTimeImmutable($customTo);
        if ($from > $to) [$from, $to] = [$to, $from];
        return _reportsBuildRange('custom', 'Custom range', $from, $to);
    }

    switch ($code) {
        case '1w':  return _reportsBuildRange('1w',  'Last 1 week',   $today->modify('-1 week'),   $today);
        case '2w':  return _reportsBuildRange('2w',  'Last 2 weeks',  $today->modify('-2 weeks'),  $today);
        case '4w':  return _reportsBuildRange('4w',  'Last 4 weeks',  $today->modify('-4 weeks'),  $today);
        case '8w':  return _reportsBuildRange('8w',  'Last 8 weeks',  $today->modify('-8 weeks'),  $today);
        case '12w': return _reportsBuildRange('12w', 'Last 12 weeks', $today->modify('-12 weeks'), $today);
        case 'mtd':
            $from = $today->modify('first day of this month');
            return _reportsBuildRange('mtd', 'Month to date', $from, $today);
        case 'last_month':
            $firstThis = $today->modify('first day of this month');
            $from = $firstThis->modify('-1 month');
            $to   = $firstThis->modify('-1 day');
            return _reportsBuildRange('last_month', 'Last month', $from, $to);
        case 'qtd':
            $m = (int) $today->format('n');
            $qStartMonth = $m - (($m - 1) % 3);
            $from = $today->setDate((int) $today->format('Y'), $qStartMonth, 1);
            return _reportsBuildRange('qtd', 'Quarter to date', $from, $today);
        case 'last_quarter':
            $m = (int) $today->format('n');
            $qStartMonth = $m - (($m - 1) % 3);
            $qStart = $today->setDate((int) $today->format('Y'), $qStartMonth, 1);
            $from = $qStart->modify('-3 months');
            $to   = $qStart->modify('-1 day');
            return _reportsBuildRange('last_quarter', 'Last quarter', $from, $to);
        case 'ytd':
            $from = $today->setDate((int) $today->format('Y'), 1, 1);
            return _reportsBuildRange('ytd', 'Year to date', $from, $today);
        case 'last_12m':
            return _reportsBuildRange('last_12m', 'Last 12 months', $today->modify('-12 months'), $today);
        case 'last_year':
            $thisYearStart = $today->setDate((int) $today->format('Y'), 1, 1);
            $from = $thisYearStart->modify('-1 year');
            $to   = $thisYearStart->modify('-1 day');
            return _reportsBuildRange('last_year', 'Last year', $from, $to);
    }
    // Fallback to 4w if unknown code.
    return _reportsBuildRange('4w', 'Last 4 weeks', $today->modify('-4 weeks'), $today);
}

/** Build the response envelope + weekly bucket list. Monday-based weeks. */
function _reportsBuildRange(string $code, string $label, DateTimeImmutable $from, DateTimeImmutable $to): array {
    $weeks = [];
    // Normalize to Monday for bucket starts.
    $cursor = $from->modify('-' . (int) $from->format('N') + 1 . ' days'); // back up to Monday
    if ((int) $cursor->format('N') !== 1) {
        $cursor = $from->modify('monday this week');
    }
    $endCap = $to;
    while ($cursor <= $endCap) {
        $weekEnd = $cursor->modify('+6 days');
        $weeks[] = [
            'start' => $cursor->format('Y-m-d'),
            'end'   => $weekEnd->format('Y-m-d'),
            'label' => $cursor->format('M j'),
        ];
        $cursor = $cursor->modify('+1 week');
    }

    return [
        'code'  => $code,
        'label' => $label,
        'from'  => $from->format('Y-m-d'),
        'to'    => $to->format('Y-m-d'),
        'weeks' => $weeks,
    ];
}

/**
 * Supported period codes, in the order they should appear in the UI dropdown.
 * Used by the smoke test and the PeriodSelector React component.
 * @return list<array{code:string,label:string}>
 */
function reportsPeriodOptions(): array {
    return [
        ['code' => '1w',           'label' => '1 week'],
        ['code' => '2w',           'label' => '2 weeks'],
        ['code' => '4w',           'label' => '4 weeks'],
        ['code' => '8w',           'label' => '8 weeks'],
        ['code' => '12w',          'label' => '12 weeks'],
        ['code' => 'mtd',          'label' => 'Month to date'],
        ['code' => 'last_month',   'label' => 'Last month'],
        ['code' => 'qtd',          'label' => 'Quarter to date'],
        ['code' => 'last_quarter', 'label' => 'Last quarter'],
        ['code' => 'ytd',          'label' => 'Year to date'],
        ['code' => 'last_12m',     'label' => 'Last 12 months'],
        ['code' => 'last_year',    'label' => 'Last year'],
    ];
}

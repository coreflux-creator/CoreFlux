<?php
/**
 * Pure identity helpers for reconciling spreadsheet placements with JobDiva.
 *
 * These functions deliberately make no database calls. Keeping the fuzzy
 * rules isolated makes them easy to test and prevents broad, opaque matching
 * from leaking into the placement repair workflow.
 */
declare(strict_types=1);

function jobdivaPlacementReconcileNormaliseClientName(?string $name): string
{
    $name = trim((string) $name);
    if ($name === '') return '';
    $name = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    $name = str_replace('&', ' and ', $name);
    $name = preg_replace('/\([^)]*\)/u', ' ', $name) ?? $name;
    $name = preg_replace('/[^a-z0-9]+/u', ' ', $name) ?? $name;
    $tokens = array_values(array_filter(
        preg_split('/\s+/', trim($name)) ?: [],
        static fn(string $token): bool => $token !== '' && !in_array($token, [
            'the', 'inc', 'incorporated', 'llc', 'ltd', 'limited', 'corp',
            'corporation', 'company', 'co', 'plc',
        ], true)
    ));
    return implode(' ', $tokens);
}

/**
 * Return the conservative client relationship used by the placement matcher.
 */
function jobdivaPlacementReconcileClientMatch(array $left, array $right): ?string
{
    foreach (['end_client_company_id', 'client_id'] as $column) {
        $a = (int) ($left[$column] ?? 0);
        $b = (int) ($right[$column] ?? 0);
        if ($a > 0 && $a === $b) return $column;
    }

    $a = jobdivaPlacementReconcileNormaliseClientName((string) ($left['end_client_name'] ?? ''));
    $b = jobdivaPlacementReconcileNormaliseClientName((string) ($right['end_client_name'] ?? ''));
    if ($a === '' || $b === '') return null;
    if ($a === $b) return 'exact_name';

    $short = strlen($a) <= strlen($b) ? $a : $b;
    $long = $short === $a ? $b : $a;
    $shortTokens = preg_split('/\s+/', $short) ?: [];
    if (strlen($short) >= 7
        && count($shortTokens) <= 3
        && str_starts_with($long, $short . ' ')) {
        return 'name_prefix_alias';
    }
    return null;
}

/**
 * Accept only exact, adjacent-day, or unambiguous month/day-swapped dates.
 */
function jobdivaPlacementReconcileDateMatch(?string $left, ?string $right): ?string
{
    $left = trim((string) $left);
    $right = trim((string) $right);
    if ($left === '' || $right === '') return null;
    try {
        $a = new \DateTimeImmutable($left);
        $b = new \DateTimeImmutable($right);
    } catch (\Throwable $_) {
        return null;
    }
    if ($a->format('Y-m-d') !== $left || $b->format('Y-m-d') !== $right) return null;
    if ($left === $right) return 'exact_date';

    $days = abs((int) $a->diff($b)->format('%r%a'));
    if ($days === 1) return 'adjacent_day';
    if ($a->format('Y') === $b->format('Y')
        && (int) $a->format('m') === (int) $b->format('d')
        && (int) $a->format('d') === (int) $b->format('m')) {
        return 'month_day_swap';
    }
    return null;
}

function jobdivaPlacementReconcileIsSpreadsheetImport(array $row): bool
{
    return str_contains(
        strtolower((string) ($row['notes'] ?? '')),
        'imported from placements.xlsx'
    );
}

/**
 * Score a single JobDiva/spreadsheet pair, or return null when it is unsafe.
 */
function jobdivaPlacementReconcilePair(array $jobdiva, array $spreadsheet): ?array
{
    $personA = (int) ($jobdiva['person_id'] ?? 0);
    $personB = (int) ($spreadsheet['person_id'] ?? 0);
    if ($personA <= 0 || $personA !== $personB) return null;
    if (empty($jobdiva['is_jobdiva_mapping']) || !jobdivaPlacementReconcileIsSpreadsheetImport($spreadsheet)) {
        return null;
    }

    $engagementA = strtolower(trim((string) ($jobdiva['engagement_type'] ?? '')));
    $engagementB = strtolower(trim((string) ($spreadsheet['engagement_type'] ?? '')));
    if ($engagementA !== '' && $engagementB !== '' && $engagementA !== $engagementB) return null;

    $dateMatch = jobdivaPlacementReconcileDateMatch(
        (string) ($jobdiva['start_date'] ?? ''),
        (string) ($spreadsheet['start_date'] ?? '')
    );
    $clientMatch = jobdivaPlacementReconcileClientMatch($jobdiva, $spreadsheet);
    if ($dateMatch === null || $clientMatch === null) return null;

    $dateScores = ['exact_date' => 40, 'adjacent_day' => 32, 'month_day_swap' => 28];
    $clientScores = ['end_client_company_id' => 40, 'client_id' => 38, 'exact_name' => 34, 'name_prefix_alias' => 26];
    $score = ($dateScores[$dateMatch] ?? 0) + ($clientScores[$clientMatch] ?? 0) + 20;
    if ((string) ($jobdiva['status'] ?? '') === (string) ($spreadsheet['status'] ?? '')) $score += 5;

    return [
        'score' => $score,
        'date_match' => $dateMatch,
        'client_match' => $clientMatch,
    ];
}

/**
 * Return only reciprocal, uniquely best matches. Ambiguous repeated
 * engagements are deliberately omitted for manual review.
 */
function jobdivaPlacementReconcileUniquePairs(array $jobdivaRows, array $spreadsheetRows): array
{
    $candidates = [];
    $bySource = [];
    $byLegacy = [];
    foreach ($jobdivaRows as $source) {
        $sourceId = (int) ($source['id'] ?? 0);
        if ($sourceId <= 0) continue;
        foreach ($spreadsheetRows as $legacy) {
            $legacyId = (int) ($legacy['id'] ?? 0);
            if ($legacyId <= 0) continue;
            $match = jobdivaPlacementReconcilePair($source, $legacy);
            if ($match === null) continue;
            $index = count($candidates);
            $candidates[] = [
                'score' => (int) ($match['score'] ?? 0),
                'source' => $source,
                'legacy' => $legacy,
                'match' => $match,
            ];
            $bySource[$sourceId][] = $index;
            $byLegacy[$legacyId][] = $index;
        }
    }

    $uniqueBest = static function (array $indexes) use ($candidates): ?int {
        usort($indexes, static fn(int $a, int $b): int =>
            ((int) ($candidates[$b]['score'] ?? 0) <=> (int) ($candidates[$a]['score'] ?? 0))
            ?: ($a <=> $b)
        );
        if (!$indexes) return null;
        $best = $indexes[0];
        if (isset($indexes[1])
            && (int) ($candidates[$indexes[1]]['score'] ?? 0) === (int) ($candidates[$best]['score'] ?? 0)) {
            return null;
        }
        return $best;
    };

    $bestBySource = [];
    foreach ($bySource as $id => $indexes) $bestBySource[(int) $id] = $uniqueBest($indexes);
    $bestByLegacy = [];
    foreach ($byLegacy as $id => $indexes) $bestByLegacy[(int) $id] = $uniqueBest($indexes);

    $pairs = [];
    foreach ($candidates as $index => $candidate) {
        $sourceId = (int) ($candidate['source']['id'] ?? 0);
        $legacyId = (int) ($candidate['legacy']['id'] ?? 0);
        if (($bestBySource[$sourceId] ?? null) !== $index || ($bestByLegacy[$legacyId] ?? null) !== $index) {
            continue;
        }
        $pairs[] = $candidate;
    }
    usort($pairs, static fn(array $a, array $b): int =>
        ((int) ($b['score'] ?? 0) <=> (int) ($a['score'] ?? 0))
        ?: ((int) ($a['source']['id'] ?? 0) <=> (int) ($b['source']['id'] ?? 0))
        ?: ((int) ($a['legacy']['id'] ?? 0) <=> (int) ($b['legacy']['id'] ?? 0))
    );
    return $pairs;
}

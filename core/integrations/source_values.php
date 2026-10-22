<?php
/** Shared, fail-closed source-value normalization. No inferred country or money basis. */
declare(strict_types=1);

function integrationSourceCodes(): array
{
    static $codes;
    return $codes ??= json_decode(file_get_contents(__DIR__ . '/data/source_codes.json'), true, 512, JSON_THROW_ON_ERROR);
}

function integrationSourceCountry(mixed $raw): ?string
{
    if (!is_scalar($raw)) return null;
    static $aliases;
    if ($aliases === null) {
        $aliases = [];
        foreach (integrationSourceCodes()['countries'] as $code => $names) {
            foreach ($names as $name) {
                $key = strtolower(trim($name));
                // An ambiguous name is never an identity rule.
                $aliases[$key] = array_key_exists($key, $aliases) && $aliases[$key] !== $code ? null : $code;
            }
        }
        $aliases['united states of america'] = 'US';
    }
    return $aliases[strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $raw)))] ?? null;
}

/** Parse a complete descriptor, not a substring (e.g. "USD/Hour", "$60 / H"). */
function integrationSourceRateDescriptor(mixed $raw): array
{
    $empty = ['unit' => null, 'currency' => null];
    if (!is_scalar($raw)) return $empty;
    $value = trim((string) $raw);
    if ($value === '') return $empty;
    $currencies = integrationSourceCodes()['currencies'];
    if (in_array(strtoupper($value), $currencies, true)) return ['unit' => null, 'currency' => strtoupper($value)];
    $units = [
        'h'=>'hour', 'hr'=>'hour', 'hrs'=>'hour', 'hour'=>'hour', 'hours'=>'hour', 'hourly'=>'hour',
        'd'=>'day', 'day'=>'day', 'days'=>'day', 'daily'=>'day',
        'w'=>'week', 'wk'=>'week', 'week'=>'week', 'weeks'=>'week', 'weekly'=>'week',
        'm'=>'month', 'mo'=>'month', 'month'=>'month', 'months'=>'month', 'monthly'=>'month',
        'p'=>'project', 'project'=>'project', 'fixed'=>'project', 'flat'=>'project', 'fixed fee'=>'project',
    ];
    if (isset($units[strtolower($value)])) return ['unit' => $units[strtolower($value)], 'currency' => null];
    if (!preg_match('~^(.*?)\s*(?:/|\bper\s+)\s*([a-z]+(?:\s+fee)?)$~iD', $value, $matches)) return $empty;
    $unit = $units[strtolower($matches[2])] ?? null;
    if ($unit === null) return $empty;
    $prefix = trim($matches[1]);
    if ($prefix === '') return ['unit' => $unit, 'currency' => null];
    // Currency may precede or follow the numeric amount. Symbols alone do not identify currency.
    if (!preg_match('/^(?:([a-z]{3})\s*)?(?:[$€£¥]?\s*[-+]?\d[\d,]*(?:\.\d+)?\s*)?(?:([a-z]{3}))?$/iuD', $prefix, $money)) return $empty;
    $codes = array_values(array_unique(array_filter([strtoupper($money[1] ?? ''), strtoupper($money[2] ?? '')])));
    if (count($codes) > 1 || ($codes !== [] && !in_array($codes[0], $currencies, true))) return $empty;
    return ['unit' => $unit, 'currency' => $codes[0] ?? null];
}

function integrationSourceRateUnit(mixed $raw): ?string
{
    return integrationSourceRateDescriptor($raw)['unit'];
}

function integrationSourceCurrency(mixed $raw): ?string
{
    return integrationSourceRateDescriptor($raw)['currency'];
}

/** Resolve all observed money metadata together; explicit disagreements cannot be hidden. */
function integrationSourceRateBasis(array $source): array
{
    $errors = [];
    $resolved = [];
    $currencies = [];
    foreach (['bill', 'pay'] as $side) {
        $field = $side . '_rate_unit';
        $unitRaw = $source[$field] ?? null;
        $descriptor = integrationSourceRateDescriptor($unitRaw);
        $amountRaw = $source[$side . '_rate_raw'] ?? null;
        $amountDescriptor = integrationSourceRateDescriptor($amountRaw);
        $explicit = is_scalar($unitRaw) && trim((string) $unitRaw) !== '';
        $unit = $explicit ? $descriptor['unit'] : $amountDescriptor['unit'];
        if ($unit === null) $errors[] = "$field: an explicit recognized rate unit is required.";
        if ($explicit && $amountDescriptor['unit'] !== null && $unit !== $amountDescriptor['unit']) {
            $errors[] = "$field: rate amount and unit disagree.";
        }
        $resolved[$field] = $unit;
        foreach ([$descriptor['currency'], $amountDescriptor['currency']] as $code) {
            if ($code !== null) $currencies[] = $code;
        }
    }
    foreach (['currency', 'bill_currency', 'pay_currency'] as $field) {
        $raw = $source[$field] ?? null;
        if ($raw === null || $raw === '') continue;
        $code = integrationSourceCurrency($raw);
        if ($code === null) $errors[] = "$field: an explicit recognized currency code is required.";
        else $currencies[] = $code;
    }
    $currencies = array_values(array_unique($currencies));
    if ($currencies === []) $errors[] = 'currency: missing source currency; a dollar symbol does not establish USD.';
    if (count($currencies) > 1) $errors[] = 'currency: conflicting bill/pay currencies require review.';
    $resolved['currency'] = count($currencies) === 1 ? $currencies[0] : null;
    $resolved['errors'] = $errors;
    return $resolved;
}

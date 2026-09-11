<?php
/**
 * Tenant staffing-economics policy.
 *
 * W-2 employer costs and C2C overhead default at the tenant level. Nullable
 * placement-rate fields are overrides: NULL inherits the tenant value, while
 * zero is an explicit waiver for that placement.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function staffingEconomicsEmptyDefaults(): array
{
    return [
        'payroll_load_pct' => 0.0,
        'workers_comp_pct' => 0.0,
        'benefits_load_pct' => 0.0,
        'c2c_overhead_pct' => 0.0,
    ];
}

function staffingEconomicsEmptyW2Defaults(): array
{
    return staffingEconomicsEmptyDefaults();
}

function staffingEconomicsTenantDefaults(int $tenantId, ?\PDO $pdo = null): array
{
    $defaults = staffingEconomicsEmptyDefaults();
    if ($tenantId <= 0) return $defaults;

    try {
        $pdo = $pdo ?? getDB();
        $stmt = $pdo->prepare(
            'SELECT w2_payroll_load_pct, w2_workers_comp_pct, w2_benefits_load_pct,
                    c2c_overhead_pct
               FROM tenant_staffing_economics_defaults
              WHERE tenant_id = :tenant_id
              LIMIT 1'
        );
        $stmt->execute(['tenant_id' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [
            'payroll_load_pct' => max(0.0, (float) ($row['w2_payroll_load_pct'] ?? 0)),
            'workers_comp_pct' => max(0.0, (float) ($row['w2_workers_comp_pct'] ?? 0)),
            'benefits_load_pct' => max(0.0, (float) ($row['w2_benefits_load_pct'] ?? 0)),
            'c2c_overhead_pct' => max(0.0, (float) ($row['c2c_overhead_pct'] ?? 0)),
        ];
    } catch (\Throwable $e) {
        // Deploys remain readable while the additive migration is pending.
        $message = $e->getMessage();
        if (stripos($message, 'c2c_overhead_pct') !== false && isset($pdo)) {
            $stmt = $pdo->prepare(
                'SELECT w2_payroll_load_pct, w2_workers_comp_pct, w2_benefits_load_pct
                   FROM tenant_staffing_economics_defaults
                  WHERE tenant_id = :tenant_id
                  LIMIT 1'
            );
            $stmt->execute(['tenant_id' => $tenantId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            return [
                'payroll_load_pct' => max(0.0, (float) ($row['w2_payroll_load_pct'] ?? 0)),
                'workers_comp_pct' => max(0.0, (float) ($row['w2_workers_comp_pct'] ?? 0)),
                'benefits_load_pct' => max(0.0, (float) ($row['w2_benefits_load_pct'] ?? 0)),
                'c2c_overhead_pct' => 0.0,
            ];
        }
        if (stripos($message, 'tenant_staffing_economics_defaults') === false
            && stripos($message, 'c2c_overhead_pct') === false) {
            throw $e;
        }
        return $defaults;
    }
}

function staffingEconomicsTenantW2Defaults(int $tenantId, ?\PDO $pdo = null): array
{
    return staffingEconomicsTenantDefaults($tenantId, $pdo);
}

/**
 * Resolve effective W-2 rates without losing override intent.
 *
 * @return array{rates:array<string,float>,sources:array<string,string>}
 */
function staffingEconomicsResolveW2Costs(array $rate, ?array $tenantDefaults): array
{
    $fields = [
        'adder_pct' => 'payroll_load_pct',
        'workers_comp_pct' => 'workers_comp_pct',
        'benefits_load_pct' => 'benefits_load_pct',
    ];
    $rates = [];
    $sources = [];
    foreach ($fields as $rateField => $defaultField) {
        $hasOverride = array_key_exists($rateField, $rate)
            && $rate[$rateField] !== null
            && $rate[$rateField] !== '';
        if ($hasOverride) {
            $rates[$rateField] = max(0.0, (float) $rate[$rateField]);
            $sources[$rateField] = 'placement_override';
            continue;
        }
        if ($tenantDefaults !== null) {
            $rates[$rateField] = max(0.0, (float) ($tenantDefaults[$defaultField] ?? 0));
            $sources[$rateField] = 'tenant_default';
            continue;
        }
        $rates[$rateField] = 0.0;
        $sources[$rateField] = 'none';
    }
    return ['rates' => $rates, 'sources' => $sources];
}

/**
 * Resolve the C2C overhead independently from both vendor labor pay and W-2
 * employer costs.
 *
 * @return array{rate:float,source:string}
 */
function staffingEconomicsResolveC2COverhead(
    array $rate,
    ?array $tenantDefaults,
    ?bool $sourceEnabled = null
): array
{
    $hasOverride = array_key_exists('c2c_overhead_pct', $rate)
        && $rate['c2c_overhead_pct'] !== null
        && $rate['c2c_overhead_pct'] !== '';
    if ($hasOverride) {
        return [
            'rate' => max(0.0, (float) $rate['c2c_overhead_pct']),
            'source' => 'placement_override',
        ];
    }
    // An explicit source-system "No" is a business rule, not missing data.
    // It must waive the tenant default unless an operator enters a placement
    // override above.
    if ($sourceEnabled === false) {
        return ['rate' => 0.0, 'source' => 'source_waiver'];
    }
    if ($tenantDefaults !== null) {
        return [
            'rate' => max(0.0, (float) ($tenantDefaults['c2c_overhead_pct'] ?? 0)),
            'source' => 'tenant_default',
        ];
    }
    return ['rate' => 0.0, 'source' => 'none'];
}

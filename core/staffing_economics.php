<?php
/**
 * Tenant staffing-economics policy.
 *
 * W-2 employer costs default at the tenant level. Nullable placement-rate
 * fields are overrides: NULL inherits the tenant value, while zero is an
 * explicit waiver for that placement.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function staffingEconomicsEmptyW2Defaults(): array
{
    return [
        'payroll_load_pct' => 0.0,
        'workers_comp_pct' => 0.0,
        'benefits_load_pct' => 0.0,
    ];
}

function staffingEconomicsTenantW2Defaults(int $tenantId, ?\PDO $pdo = null): array
{
    $defaults = staffingEconomicsEmptyW2Defaults();
    if ($tenantId <= 0) return $defaults;

    try {
        $pdo = $pdo ?? getDB();
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
        ];
    } catch (\Throwable $e) {
        // Deploys remain readable while the additive migration is pending.
        if (stripos($e->getMessage(), 'tenant_staffing_economics_defaults') === false) throw $e;
        return $defaults;
    }
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


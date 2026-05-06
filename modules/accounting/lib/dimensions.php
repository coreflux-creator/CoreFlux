<?php
/**
 * Accounting — Tenant-configurable dimensions engine (Sprint 2 / B2).
 *
 * Tenants register their own dimension keys (e.g. department, location,
 * project, placement, shift, service_period). Per-account rules declare
 * which dimensions are `required` / `optional` / `blocked` when posting
 * to that account. Validation is invoked from `accountingPostJe()` so
 * subledger postings (AP, Billing, Payroll) cannot bypass the rules.
 *
 * VERTICAL-AGNOSTIC: nothing in this file references staffing, hospitality,
 * or any other vertical. The dimension keys are entirely tenant-driven.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';

/**
 * Cached fetch of all active dimensions for a tenant.
 * @return array<string, array{id:int, key:string, label:string, data_type:string, required_default:bool, sort_order:int}>
 */
function accountingDimensionRegistry(int $tenantId): array {
    static $cache = [];
    if (isset($cache[$tenantId])) return $cache[$tenantId];

    $pdo = getDB();
    if (!$pdo) return [];
    $stmt = $pdo->prepare(
        "SELECT id, dim_key, label, data_type, reference_table, required_default, sort_order
           FROM accounting_dimensions
          WHERE tenant_id = :t AND active = 1
          ORDER BY sort_order, dim_key"
    );
    $stmt->execute(['t' => $tenantId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[$r['dim_key']] = [
            'id'              => (int) $r['id'],
            'key'             => (string) $r['dim_key'],
            'label'           => (string) $r['label'],
            'data_type'       => (string) $r['data_type'],
            'reference_table' => $r['reference_table'],
            'required_default'=> (bool) $r['required_default'],
            'sort_order'      => (int) $r['sort_order'],
        ];
    }
    $cache[$tenantId] = $out;
    return $out;
}

/**
 * Per-account rules. Returns map of dim_key => requirement.
 * @return array<string, string>  e.g. ['department' => 'required', 'project' => 'optional']
 */
function accountingAccountDimRules(int $tenantId, int $accountId): array {
    $pdo = getDB();
    if (!$pdo) return [];
    $stmt = $pdo->prepare(
        "SELECT d.dim_key, r.requirement
           FROM accounting_account_dim_rules r
           JOIN accounting_dimensions d ON d.id = r.dimension_id AND d.active = 1
          WHERE r.tenant_id = :t AND r.account_id = :a"
    );
    $stmt->execute(['t' => $tenantId, 'a' => $accountId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(string) $r['dim_key']] = (string) $r['requirement'];
    }
    return $out;
}

/**
 * Validate a journal-entry line's dims against per-account rules + dimension defaults.
 *
 * @param array $dims  e.g. ['department' => 'ENG', 'project' => 'PRJ-01']
 * @return array{ok:bool, errors:list<string>}
 */
function accountingValidateLineDims(int $tenantId, int $accountId, array $dims): array {
    $errors = [];
    $registry = accountingDimensionRegistry($tenantId);
    if (!$registry) {
        // No dimensions defined for this tenant — accept anything.
        return ['ok' => true, 'errors' => []];
    }

    $accountRules = accountingAccountDimRules($tenantId, $accountId);

    foreach ($registry as $key => $def) {
        $rule = $accountRules[$key] ?? ($def['required_default'] ? 'required' : 'optional');
        $value = $dims[$key] ?? null;
        $hasValue = $value !== null && $value !== '';

        if ($rule === 'blocked' && $hasValue) {
            $errors[] = "Dimension '{$def['label']}' is blocked on this account but a value was supplied";
            continue;
        }
        if ($rule === 'required' && !$hasValue) {
            $errors[] = "Dimension '{$def['label']}' is required on this account";
            continue;
        }
        if ($hasValue && $def['data_type'] === 'enum') {
            // Enforce value-whitelist when one exists.
            $pdo = getDB();
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM accounting_dimension_values
                  WHERE tenant_id = :t AND dimension_id = :d AND value_code = :v AND active = 1"
            );
            $stmt->execute(['t' => $tenantId, 'd' => $def['id'], 'v' => (string) $value]);
            if ((int) $stmt->fetchColumn() === 0) {
                // Only enforce if any whitelist values exist for this dimension.
                $stmt2 = $pdo->prepare(
                    "SELECT COUNT(*) FROM accounting_dimension_values
                      WHERE tenant_id = :t AND dimension_id = :d AND active = 1"
                );
                $stmt2->execute(['t' => $tenantId, 'd' => $def['id']]);
                if ((int) $stmt2->fetchColumn() > 0) {
                    $errors[] = "Dimension '{$def['label']}' value '{$value}' is not in allowed list";
                }
            }
        }
    }

    return ['ok' => empty($errors), 'errors' => $errors];
}

/**
 * Validate every line in a journal entry against dim rules.
 * Throws RuntimeException with combined message on first failure.
 *
 * @param array $lines  resolved lines (already have account_id + dims)
 */
function accountingValidateJeDims(int $tenantId, array $lines): void {
    $allErrors = [];
    foreach ($lines as $i => $line) {
        $accountId = (int) ($line['account_id'] ?? 0);
        $dims      = (array) ($line['dims'] ?? []);
        if (!$accountId) continue;
        $r = accountingValidateLineDims($tenantId, $accountId, $dims);
        if (!$r['ok']) {
            foreach ($r['errors'] as $e) $allErrors[] = "Line " . ($i + 1) . ": " . $e;
        }
    }
    if ($allErrors) {
        throw new \RuntimeException("Dimension validation failed: " . implode('; ', $allErrors));
    }
}

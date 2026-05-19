<?php
/**
 * RBAC B4 — legacy permission-string → (module_key, action) mapping.
 *
 * Single source of truth for the sweep that retires RBAC::hasPermission().
 * Every legacy permission string used across /api and /modules resolves
 * here to a tuple the new RBACResolver understands.
 *
 * The mapping table mirrors the doc at:
 *   /app/memory/RBAC_B4_PERMISSION_MAPPING.md
 *
 * IMPORTANT: any new legacy permission added to a module's RBAC checks
 * MUST be added here too, or the bridge falls through to a default of
 * `(<first_segment>, write)` which is intentionally conservative for
 * unknowns. The `rbac_b4_bridge_smoke.php` test locks the table.
 *
 * Special tuples:
 *   - ('_platform', 'admin') means "platform-level; do not translate".
 *     The bridge call defers to legacy RBAC::hasPermission() so we don't
 *     accidentally widen access on `tenant.manage` style checks until
 *     a platform_admin capability is modelled.
 */
declare(strict_types=1);

final class RbacLegacyMap
{
    /**
     * @return array<string, array{0:string, 1:string}>
     */
    public static function table(): array
    {
        return [
            // ── accounting ────────────────────────────────────────────────
            'accounting.audit.view'              => ['accounting', 'read'],
            'accounting.bank.manage'             => ['accounting', 'admin'],
            'accounting.bank.reconcile'          => ['accounting', 'admin'],
            'accounting.bank.view'               => ['accounting', 'read'],
            'accounting.close_task.assign'       => ['accounting', 'write'],
            'accounting.close_task.complete'     => ['accounting', 'write'],
            'accounting.close_workflow.manage'   => ['accounting', 'admin'],
            'accounting.coa.edit'                => ['accounting', 'write'],
            'accounting.coa.manage'              => ['accounting', 'admin'],
            'accounting.coa.view'                => ['accounting', 'read'],
            'accounting.create_entry'            => ['accounting', 'write'],
            'accounting.dimensions.manage'       => ['accounting', 'admin'],
            'accounting.dimensions.view'         => ['accounting', 'read'],
            'accounting.entities.manage'         => ['accounting', 'admin'],
            'accounting.entities.view'           => ['accounting', 'read'],
            'accounting.intercompany.manage'     => ['accounting', 'admin'],
            'accounting.je.create'               => ['accounting', 'write'],
            'accounting.je.post'                 => ['accounting', 'admin'],
            'accounting.je.reverse'              => ['accounting', 'admin'],
            'accounting.je.view'                 => ['accounting', 'read'],
            'accounting.manage_posting_rules'    => ['accounting', 'admin'],
            'accounting.period.view'             => ['accounting', 'read'],
            'accounting.reports.export'          => ['accounting', 'write'],
            'accounting.reports.view'            => ['accounting', 'read'],

            // ── ai ────────────────────────────────────────────────────────
            'ai.config.manage'                   => ['ai', 'admin'],

            // ── ap ────────────────────────────────────────────────────────
            'ap.1099.generate'                   => ['ap', 'admin'],
            'ap.1099.view'                       => ['ap', 'read'],
            'ap.bill.approve'                    => ['ap', 'admin'],
            'ap.bill.create'                     => ['ap', 'write'],
            'ap.bill.post'                       => ['ap', 'admin'],
            'ap.bill.view'                       => ['ap', 'read'],
            'ap.bill.void'                       => ['ap', 'admin'],
            'ap.bills.approve_admin'             => ['ap', 'admin'],
            'ap.expense.approve'                 => ['ap', 'write'],
            'ap.expense.submit'                  => ['ap', 'write'],
            'ap.export.run'                      => ['ap', 'write'],
            'ap.payment.allocate'                => ['ap', 'write'],
            'ap.payment.create'                  => ['ap', 'admin'],
            'ap.payment.send'                    => ['ap', 'admin'],
            'ap.recurring.manage'                => ['ap', 'write'],
            'ap.reports.view'                    => ['ap', 'read'],
            'ap.vendor.view_pii'                 => ['ap', 'admin'],
            'ap.view'                            => ['ap', 'read'],

            // ── billing ───────────────────────────────────────────────────
            'billing.invoice.approve'            => ['billing', 'admin'],
            'billing.invoice.create'             => ['billing', 'write'],
            'billing.invoice.draft'              => ['billing', 'write'],
            'billing.invoice.send'               => ['billing', 'admin'],
            'billing.invoice.void'               => ['billing', 'admin'],
            'billing.payments.record'            => ['billing', 'write'],
            'billing.view'                       => ['billing', 'read'],

            // ── integrations ──────────────────────────────────────────────
            'integrations.jobdiva.manage'        => ['integrations', 'admin'],
            'integrations.jobdiva.view'          => ['integrations', 'read'],
            'integrations.qbo.manage'            => ['integrations', 'admin'],
            'integrations.qbo.view'              => ['integrations', 'read'],

            // ── payroll ───────────────────────────────────────────────────
            'payroll.runs.approve'               => ['payroll', 'admin'],

            // ── people ────────────────────────────────────────────────────
            'people.banking.manage'              => ['people', 'admin'],
            'people.banking.view'                => ['people', 'admin'],
            'people.custom_fields.manage'        => ['people', 'write'],
            'people.docs.manage'                 => ['people', 'write'],
            'people.docs.view'                   => ['people', 'read'],
            'people.manage'                      => ['people', 'write'],
            'people.merge'                       => ['people', 'admin'],
            'people.pii.audit.view'              => ['people', 'admin'],
            'people.pii.manage'                  => ['people', 'admin'],
            'people.pii.view'                    => ['people', 'admin'],
            'people.pipeline.substages.manage'   => ['people', 'write'],
            'people.tax.manage'                  => ['people', 'admin'],
            'people.tax.view'                    => ['people', 'read'],
            'people.terminate'                   => ['people', 'admin'],
            'people.view'                        => ['people', 'read'],

            // ── placements ────────────────────────────────────────────────
            'placements.commissions.manage'      => ['placements', 'admin'],
            'placements.commissions.view'        => ['placements', 'read'],
            'placements.corp.manage'             => ['placements', 'write'],
            'placements.corp.view'               => ['placements', 'read'],
            'placements.docs.manage'             => ['placements', 'write'],
            'placements.docs.view'               => ['placements', 'read'],
            'placements.financials.approve'      => ['placements', 'admin'],
            'placements.financials.manage'       => ['placements', 'admin'],
            'placements.financials.view'         => ['placements', 'read'],
            'placements.manage'                  => ['placements', 'write'],
            'placements.portal_credentials.view' => ['placements', 'admin'],
            'placements.referrals.manage'        => ['placements', 'write'],
            'placements.terminate'               => ['placements', 'admin'],
            'placements.view'                    => ['placements', 'read'],

            // ── reports ───────────────────────────────────────────────────
            'reports.view'                       => ['reports', 'read'],

            // ── staffing ──────────────────────────────────────────────────
            'staffing.view'                      => ['staffing', 'read'],

            // ── tenant (PARKED — platform gate, keep legacy) ──────────────
            'tenant.manage'                      => ['_platform', 'admin'],

            // ── time ──────────────────────────────────────────────────────
            'time.approve'                       => ['time', 'admin'],
            'time.bulk_upload'                   => ['time', 'write'],
            'time.categories.manage'             => ['time', 'write'],
            'time.entry.create'                  => ['time', 'write'],
            'time.entry.manage'                  => ['time', 'write'],
            'time.entry.self'                    => ['time', 'read'],
            'time.feed.consume'                  => ['time', 'read'],
            'time.period.close'                  => ['time', 'admin'],
            'time.reject'                        => ['time', 'admin'],
            'time.review'                        => ['time', 'write'],
            'time.tokenized_email.issue'         => ['time', 'admin'],
            'time.tokenized_email.revoke'        => ['time', 'admin'],
            'time.view'                          => ['time', 'read'],

            // ── treasury ──────────────────────────────────────────────────
            'treasury.approve_payment'           => ['treasury', 'admin'],
            'treasury.approve_transfer'          => ['treasury', 'admin'],
            'treasury.create_payment'            => ['treasury', 'admin'],
            'treasury.create_transfer'           => ['treasury', 'write'],
            'treasury.execute_payment'           => ['treasury', 'admin'],
            'treasury.payment.manage'            => ['treasury', 'admin'],
            'treasury.payment.view'              => ['treasury', 'read'],
            'treasury.view_bank_balances'        => ['treasury', 'read'],
        ];
    }

    /**
     * Resolve a legacy permission string to (module_key, action).
     *
     * Unknown strings fall back to (<first_segment>, write) — intentionally
     * conservative so a missed-in-the-doc permission still gates properly
     * (read is too loose, admin would lock out legitimate users).
     */
    public static function resolve(string $perm): array
    {
        $t = self::table();
        if (isset($t[$perm])) return $t[$perm];
        $segs = explode('.', $perm);
        return [$segs[0] ?? '_unknown', 'write'];
    }

    /** True when the permission is in the PARK list (platform-only). */
    public static function isParked(string $perm): bool
    {
        [$m] = self::resolve($perm);
        return $m === '_platform';
    }
}

/**
 * Bridge helper — drop-in replacement for `RBAC::hasPermission($user, $perm)`.
 *
 * Translates the legacy permission string to a (module, action) tuple and
 * asks RBACResolver via api_can().
 *
 * Cut-over strategy (B4-staged):
 *   - DEFAULT: dual-check mode. The bridge returns TRUE only when BOTH
 *     the legacy RBAC config AND the new RBACResolver grant the request.
 *     This is intentionally strict — the sweep can route every callsite
 *     through the bridge without widening anybody's access, because the
 *     more-restrictive layer always wins.
 *   - OVERRIDE: set CF_RBAC_BRIDGE_MODE=new (env var or config constant)
 *     to flip to new-only checks. Reserved for after the membership
 *     backfill has been tightened to mirror rbac_config grants.
 *
 * For PARKED strings (`tenant.manage`) we always defer to legacy regardless
 * of mode — the platform_admin capability is not yet modelled in the new
 * grid, so the legacy gate is the only correct answer.
 */
function rbac_legacy_can(array $user, string $perm): bool {
    // PARKED → legacy is the only correct answer.
    if (RbacLegacyMap::isParked($perm)) {
        if (class_exists('RBAC') && method_exists('RBAC', 'hasPermission')) {
            return RBAC::hasPermission($user, $perm);
        }
        return false;
    }

    $mode = getenv('CF_RBAC_BRIDGE_MODE');
    if ($mode === false && defined('CF_RBAC_BRIDGE_MODE')) $mode = constant('CF_RBAC_BRIDGE_MODE');
    $mode = is_string($mode) ? strtolower($mode) : 'dual';

    [$module, $action] = RbacLegacyMap::resolve($perm);
    $newOk = function_exists('api_can') ? api_can($module, $action) : false;

    if ($mode === 'new') {
        return $newOk;
    }
    if ($mode === 'legacy') {
        return class_exists('RBAC') && method_exists('RBAC', 'hasPermission')
            ? RBAC::hasPermission($user, $perm) : false;
    }
    // Default 'dual' — AND both layers. Preserves legacy denials while we
    // route every callsite through the bridge, no widening possible.
    $legacyOk = class_exists('RBAC') && method_exists('RBAC', 'hasPermission')
        ? RBAC::hasPermission($user, $perm) : false;
    return $legacyOk && $newOk;
}

/**
 * Bridge enforcer — drop-in replacement for `RBAC::requirePermission()`.
 * Emits 403 via api_error() on denial (same shape the legacy helper used).
 */
function rbac_legacy_require(array $user, string $perm): void {
    if (rbac_legacy_can($user, $perm)) return;
    if (function_exists('api_error')) {
        [$module, $action] = RbacLegacyMap::resolve($perm);
        api_error("Forbidden: missing permission '{$perm}'", 403, [
            'required'        => $perm,
            'required_module' => $module,
            'required_action' => $action,
        ]);
    }
    http_response_code(403);
    echo "Forbidden: missing permission '{$perm}'";
    exit;
}

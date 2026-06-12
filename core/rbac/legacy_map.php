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
            'accounting.coa.manage'               => ['accounting', 'admin'],
            // Slice 4 — write-tool gates.
            'accounting.write'                    => ['accounting', 'write'],
            'accounting.approve'                  => ['accounting', 'admin'],
            // Slice 5 — module-specific reviewer role.
            'accounting.review'                    => ['accounting', 'read'],
            'accounting.coa.view'                => ['accounting', 'read'],
            'accounting.create_entry'            => ['accounting', 'write'],
            'accounting.dimensions.manage'       => ['accounting', 'admin'],
            'accounting.dimensions.view'         => ['accounting', 'read'],
            'accounting.entities.manage'         => ['accounting', 'admin'],
            'accounting.entities.view'           => ['accounting', 'read'],
            'accounting.intercompany.manage'     => ['accounting', 'admin'],
            'accounting.je.create'               => ['accounting', 'write'],
            'accounting.je.edit_draft'           => ['accounting', 'write'],
            'accounting.je.submit'               => ['accounting', 'write'],
            'accounting.je.approve'              => ['accounting', 'admin'],
            'accounting.je.post'                 => ['accounting', 'admin'],
            'accounting.je.reverse'              => ['accounting', 'admin'],
            'accounting.je.void'                 => ['accounting', 'admin'],
            'accounting.je.view'                 => ['accounting', 'read'],
            'accounting.manage_integrations'     => ['accounting', 'admin'],
            'accounting.manage_posting_rules'    => ['accounting', 'admin'],
            'accounting.view'                    => ['accounting', 'read'],
            'accounting.period.view'             => ['accounting', 'read'],
            'accounting.reports.export'          => ['accounting', 'write'],
            'accounting.reports.view'            => ['accounting', 'read'],

            // ── accounting connection (spec §15 — provider-neutral backend) ─
            // Jaz / QBO / Xero / CoreFlux-Native all gated by the same 5
            // codes so swapping providers later doesn't reshuffle RBAC.
            'accounting.connection.view'         => ['accounting', 'read'],
            'accounting.connection.manage'       => ['accounting', 'admin'],
            'accounting.commands.draft'          => ['accounting', 'write'],
            'accounting.commands.approve'        => ['accounting', 'admin'],
            'accounting.commands.execute'        => ['accounting', 'admin'],

            // ── ai ────────────────────────────────────────────────────────
            'ai.config.manage'                   => ['ai', 'admin'],
            'ai.use'                             => ['ai', 'read'],
            'ai.audit.view'                      => ['ai', 'read'],
            'ai.gateway.invoke'                  => ['ai', 'write'],
            'ai.workflow.approve'                => ['ai', 'admin'],
            'platform.ai.admin'                  => ['ai', 'admin'],

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
            'billing.invoice.post'               => ['billing', 'admin'],
            'billing.invoice.send'               => ['billing', 'admin'],
            'billing.invoice.void'               => ['billing', 'admin'],
            'billing.payments.record'            => ['billing', 'write'],
            'billing.view'                       => ['billing', 'read'],

            // ── coreflux platform (PARKED) ────────────────────────────────
            // coreflux.* keys are platform-internal sandbox / lab toggles
            // (LayerFi sandbox env switch, etc). They are PARKED on the
            // new-side resolver because there is no 'coreflux' module in
            // the membership grid — the legacy RBAC config (rbac_config.php)
            // grants these via the '*' catchall on master_admin /
            // tenant_admin and that remains the only correct gate until a
            // dedicated platform_admin capability is modelled.
            'coreflux.internal_sandbox'          => ['_platform', 'admin'],

            // ── integrations ──────────────────────────────────────────────
            'integrations.jobdiva.manage'        => ['integrations', 'admin'],
            'integrations.jobdiva.view'          => ['integrations', 'read'],
            'integrations.qbo.manage'            => ['integrations', 'admin'],
            'integrations.qbo.view'              => ['integrations', 'read'],
            'integrations.zoho_books.manage'     => ['integrations', 'admin'],
            'integrations.zoho_books.view'       => ['integrations', 'read'],
            'integrations.airtable.manage'       => ['integrations', 'admin'],
            'integrations.airtable.view'         => ['integrations', 'read'],
            // Tenant Integration Field Map registry (Slice 3 scaffolding) —
            // admin-only because misconfiguration drives data into wrong
            // columns. master_admin + tenant_admin via the 'integrations'
            // module getting 'admin' level.
            'integrations.field_map.manage'      => ['integrations', 'admin'],
            'integrations.field_map.view'        => ['integrations', 'admin'],

            // Broad tenant-admin scope on integrations (used by mirror sync,
            // probe diagnostic, raw payload viewer, reindex, etc). Same
            // resolved (module, action) as the granular field_map perms so
            // a role granting one inherits the other. Without this entry
            // the resolver fell back to ('tenant_admin', 'write') which
            // most role bundles do NOT grant — producing the operator-
            // reported "Forbidden: missing permission 'tenant_admin.integrations'"
            // even though the user is a tenant integrations admin.
            'tenant_admin.integrations'          => ['integrations', 'admin'],

            // ── payroll ───────────────────────────────────────────────────
            'payroll.anomalies.acknowledge'     => ['payroll', 'write'],
            'payroll.anomalies.view'            => ['payroll', 'read'],
            'payroll.cycles.manage'             => ['payroll', 'admin'],
            'payroll.deductions.manage'         => ['payroll', 'admin'],
            'payroll.profiles.banking.manage'   => ['payroll', 'admin'],
            'payroll.profiles.banking.view'     => ['payroll', 'admin'],
            'payroll.profiles.manage'           => ['payroll', 'admin'],
            'payroll.profiles.view'             => ['payroll', 'read'],
            'payroll.reports.view'              => ['payroll', 'read'],
            'payroll.run.approve'               => ['payroll', 'admin'],
            'payroll.run.build'                 => ['payroll', 'write'],
            'payroll.run.compute'               => ['payroll', 'write'],
            'payroll.run.create'                => ['payroll', 'write'],
            'payroll.run.disburse'              => ['payroll', 'admin'],
            'payroll.run.post'                  => ['payroll', 'admin'],
            'payroll.run.reverse'               => ['payroll', 'admin'],
            'payroll.runs.approve'               => ['payroll', 'admin'],
            'payroll.schedules.manage'          => ['payroll', 'admin'],
            'payroll.tax.manage'                => ['payroll', 'admin'],
            'payroll.tax.view'                  => ['payroll', 'read'],
            'payroll.view'                      => ['payroll', 'read'],
            'payroll.w2.generate'               => ['payroll', 'admin'],
            'payroll.w2.view'                   => ['payroll', 'read'],

            // ── people ────────────────────────────────────────────────────
            'people.banking.manage'              => ['people', 'admin'],
            'people.banking.view'                => ['people', 'admin'],
            'people.access_reviews.manage'       => ['people', 'admin'],
            'people.access_reviews.view'         => ['people', 'admin'],
            'people.comp.manage'                 => ['people', 'admin'],
            'people.comp.view'                   => ['people', 'read'],
            'people.custom_fields.manage'        => ['people', 'write'],
            'people.docs.manage'                 => ['people', 'write'],
            'people.docs.view'                   => ['people', 'read'],
            'people.graph.delegate'              => ['people', 'admin'],
            'people.graph.manage'                => ['people', 'admin'],
            'people.graph.view'                  => ['people', 'read'],
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
            'admin.export_templates.manage'      => ['reports', 'admin'],
            'reports.view'                       => ['reports', 'read'],
            'reports.export'                     => ['reports', 'write'],
            'reports.custom.build'               => ['reports', 'write'],
            'reports.custom.share'               => ['reports', 'admin'],

            // ── staffing ──────────────────────────────────────────────────
            'staffing.billing.manage'            => ['billing', 'write'],
            'staffing.billing.view'              => ['billing', 'read'],
            'staffing.payroll.manage'            => ['payroll', 'write'],
            'staffing.payroll.view'              => ['payroll', 'read'],
            'staffing.reports.view'              => ['reports', 'read'],
            'staffing.settings.manage'           => ['staffing', 'admin'],
            'staffing.time.approve'              => ['time', 'admin'],
            'staffing.time.create'               => ['time', 'write'],
            'staffing.time.reject'               => ['time', 'admin'],
            'staffing.time.submit'               => ['time', 'write'],
            'staffing.time.view'                 => ['time', 'read'],
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
            'treasury.manage_forecast'           => ['treasury', 'write'],
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

    // Audit disagreements so an admin can see where legacy and new drift.
    // Best-effort: never throws, never blocks the request, returns the
    // dual-check verdict regardless of whether the log write succeeds.
    if ($legacyOk !== $newOk) {
        rbac_bridge_record_disagreement($user, $perm, $module, $action, $legacyOk, $newOk);
    }

    return $legacyOk && $newOk;
}

/**
 * Append a row to `rbac_bridge_audit` whenever the legacy and new layers
 * disagree. Best-effort — wrapped in try/catch and silently no-ops when:
 *   - migration 056 hasn't been applied
 *   - the DB is unreachable
 *   - `getDB()` isn't available (CLI smoke tests)
 *
 * Bounded by the caller: only invoked on actual disagreements, so
 * steady-state traffic on an aligned tenant produces zero log writes.
 */
function rbac_bridge_record_disagreement(
    array $user, string $perm, string $module, string $action,
    bool $legacyOk, bool $newOk
): void {
    if (!function_exists('getDB')) return;
    try {
        $pdo = getDB();
        if (!$pdo) return;
        $tenantId = function_exists('currentTenantId') ? (currentTenantId() ?: null) : null;
        $st = $pdo->prepare(
            'INSERT INTO rbac_bridge_audit
                (tenant_id, user_id, perm, module_key, action, legacy_ok, new_ok)
             VALUES (:t, :u, :p, :m, :a, :lo, :no)'
        );
        $st->execute([
            't'  => $tenantId,
            'u'  => isset($user['id']) ? (int) $user['id'] : null,
            'p'  => $perm,
            'm'  => $module,
            'a'  => $action,
            'lo' => $legacyOk ? 1 : 0,
            'no' => $newOk    ? 1 : 0,
        ]);
    } catch (\Throwable $_) {
        // intentional: never bubble an audit failure into the caller.
    }
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

/**
 * Bridge enforcer accepting ANY of multiple permissions — grants when at
 * least one is held. Used by endpoints whose access is granted to two
 * historically-distinct permission strings that resolve to different
 * (module, action) tuples but represent the same admin scope.
 *
 * Example: field_map.php is gated by both `integrations.field_map.manage`
 * (granular module-admin perm, resolves to `integrations:admin`) AND
 * `tenant_admin.integrations` (broader tenant-admin scope, resolves to
 * `tenant_admin:write`). A user holding either should be able to manage
 * the field map — a strict AND-of-both check produces false-negatives
 * for tenants whose role bundles map only one of the two.
 */
function rbac_legacy_can_any(array $user, array $perms): bool {
    foreach ($perms as $p) {
        if (rbac_legacy_can($user, (string) $p)) return true;
    }
    return false;
}

function rbac_legacy_require_any(array $user, array $perms): void {
    if (rbac_legacy_can_any($user, $perms)) return;
    $primary = (string) ($perms[0] ?? 'unknown');
    if (function_exists('api_error')) {
        [$module, $action] = RbacLegacyMap::resolve($primary);
        api_error(
            "Forbidden: missing permission '" . implode("' or '", $perms) . "'",
            403,
            [
                'required_any'    => $perms,
                'required_module' => $module,
                'required_action' => $action,
            ]
        );
    }
    http_response_code(403);
    echo "Forbidden: missing permission '" . implode("' or '", $perms) . "'";
    exit;
}

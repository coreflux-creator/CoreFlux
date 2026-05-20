<?php
/**
 * CoreFlux RBAC — role → permissions mapping.
 *
 * Source of truth for which roles can do what. Version-controlled,
 * reviewable, no DB lookup at runtime.
 *
 * Patterns:
 *   - Exact:      'people.view'
 *   - Wildcard:   'people.*'        (matches everything beginning people.)
 *   - Catchall:   '*'               (everything)
 *
 * Permission keys come from each module's manifest.php (see ModuleRegistry::
 * getAllPermissions()). When you add a new module, declare its permissions
 * in its manifest, then map them here.
 *
 * Hard rule: default-deny. If a role doesn't list a permission (directly
 * or via wildcard), the user cannot do that thing.
 */

return [

    // ---------------------------------------------------------------------
    // master_admin — Emergent / CoreFlux team. Full access across every
    // tenant. The catchall '*' grants anything any module declares now or
    // in the future.
    // ---------------------------------------------------------------------
    'master_admin' => ['*'],

    // ---------------------------------------------------------------------
    // tenant_admin — owner of a tenant. Full access *within their tenant*
    // for every module the tenant has enabled.
    // ---------------------------------------------------------------------
    'tenant_admin' => ['*'],

    // ---------------------------------------------------------------------
    // admin — second-tier admin (e.g., HR director, accounting controller).
    // Manage everything in regular operational modules. Excluded from
    // tenant-level platform controls (`tenant.*`) — that's tenant_admin /
    // master_admin only.
    //
    // The wildcard list below intentionally mirrors every module_key the
    // B4 bridge mapping uses (see /app/memory/RBAC_B4_PERMISSION_MAPPING.md)
    // so the legacy half of the dual-check bridge can't silently deny an
    // admin user on a module their new-side membership grants.
    // ---------------------------------------------------------------------
    'admin' => [
        'people.*',
        'placements.*',
        'time.*',
        'billing.*',
        'ap.*',
        'accounting.*',
        'payroll.*',
        'treasury.*',
        'reports.*',         // new mapping naming
        'reporting.*',       // legacy naming kept for back-compat
        'finance.*',
        'staffing.*',
        'integrations.*',
        // ai: explicit list — DO NOT widen to a catch-all wildcard. The
        // ai.enable_auto_execute (autonomy on/off) permission is
        // intentionally tenant_admin / master_admin only.
        'ai.view_recommendations',
        'ai.approve_actions',
        'ai.configure_agents',
        'ai.config.manage',
        'ai.low_confidence',
    ],

    // ---------------------------------------------------------------------
    // manager — team lead. Read everything in their domain, manage some.
    // Cannot view PII, cannot modify pay rates, cannot post journal entries.
    // ---------------------------------------------------------------------
    'manager' => [
        'people.view',
        'people.timeoff.manage',
        'payroll.view',
        'payroll.runs.view',
        'reporting.view',
        'treasury.view',
        'accounting.coa.view',
        'ai.view_recommendations',
    ],

    // ---------------------------------------------------------------------
    // employee — base user. Sees own data only (enforced in module code,
    // not by RBAC alone — RBAC says "may use the People module"; the
    // module's queries restrict by employee_id).
    // ---------------------------------------------------------------------
    'employee' => [
        'people.view',
    ],

];

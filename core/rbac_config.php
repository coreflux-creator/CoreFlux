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
    // platform-level controls (tenant settings, billing — when those exist).
    // ---------------------------------------------------------------------
    'admin' => [
        'people.*',
        'accounting.*',
        'treasury.*',
        'finance.*',
        'payroll.*',
        'reporting.*',
        'ai.view_recommendations',
        'ai.approve_actions',
        'ai.configure_agents',
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

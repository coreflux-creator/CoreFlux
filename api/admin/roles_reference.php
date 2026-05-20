<?php
/**
 * /api/admin/roles_reference.php — read-only metadata about every
 * persona_type so the `/admin/roles` UI can explain exactly what
 * picking a given role grants without admins having to grep the codebase.
 *
 *   GET /api/admin/roles_reference.php
 *
 *   Response:
 *     {
 *       allowed_persona_types: [...],        # canonical list from memberships.php
 *       allowed_access_levels: ['none','read','write','admin'],
 *       roles: [
 *         { key, label, summary, scope, default_access_level,
 *           grants_wildcard_modules: [...], grants_specific_perms: [...],
 *           legacy_role_mapping, notes[] }
 *       ],
 *       legend: { ... }                       # plain-English glossary
 *     }
 *
 * Auth: master_admin / tenant_admin / global. Read-only.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';

$ctx = api_require_auth();
if (api_method() !== 'GET') api_error('Method not allowed', 405);

$role          = (string) ($ctx['role'] ?? 'employee');
$isGlobalAdmin = (bool) ($ctx['is_global_admin'] ?? false);
if (!$isGlobalAdmin && !in_array($role, ['master_admin', 'tenant_admin'], true)) {
    api_error('Forbidden — admin only', 403);
}

// ----------------------------------------------------------------- canonical lists
$allowedPersonaTypes = [
    'master_admin','tenant_admin','admin','manager','employee',
    'contractor','client','vendor','platform_staff','custom',
];
$allowedAccessLevels = ['none','read','write','admin'];

// ----------------------------------------------------------------- pull legacy grants
// /core/rbac_config.php returns the legacy role → permission map. Used
// today by `RBAC::hasPermission` (the legacy half of the dual-check
// bridge). We surface its wildcard grants here so admins see which
// modules each persona unlocks by default.
$legacyMap = [];
try {
    $legacyMap = (array) (require __DIR__ . '/../../core/rbac_config.php');
} catch (\Throwable $_) { /* defensive — file should always exist */ }

$splitGrants = static function (array $patterns): array {
    $wildcards = [];
    $specific  = [];
    foreach ($patterns as $p) {
        $p = (string) $p;
        if ($p === '*')                       $wildcards[] = '*';
        elseif (str_ends_with($p, '.*'))      $wildcards[] = rtrim($p, '*.');
        else                                  $specific[]  = $p;
    }
    return [array_values(array_unique($wildcards)), array_values(array_unique($specific))];
};

// ----------------------------------------------------------------- catalogue
// Each entry maps the canonical persona_type to a human-readable
// explanation. `legacy_role_mapping` shows which legacy role the
// dual-check bridge falls back to before a membership exists.
$catalogue = [
    'master_admin' => [
        'label'                => 'Master Admin',
        'summary'              => 'CoreFlux / Emergent platform staff. Cross-tenant access — sees every tenant and every module.',
        'scope'                => 'platform',
        'default_access_level' => 'admin',
        'legacy_role_mapping'  => 'master_admin',
        'notes'                => [
            '`users.is_global_admin = 1` short-circuits permission checks before RBACResolver even runs.',
            'Only used by the platform team. Do not assign to customer staff.',
            'Persona toggle (header dropdown) honours this — switching tenants is unrestricted.',
        ],
    ],
    'tenant_admin' => [
        'label'                => 'Tenant Admin',
        'summary'              => 'Owner of a single tenant. Full access to every enabled module within that tenant.',
        'scope'                => 'tenant',
        'default_access_level' => 'admin',
        'legacy_role_mapping'  => 'tenant_admin',
        'notes'                => [
            'Can invite / remove users on their own tenant.',
            'Can adjust `tenant.*` settings (logo, branding, integrations, SSO).',
            'Cannot see other tenants — that requires `master_admin`.',
        ],
    ],
    'admin' => [
        'label'                => 'Admin (operational)',
        'summary'              => 'Second-tier admin — HR director, accounting controller, ops manager. Manages day-to-day modules but excluded from tenant-level platform controls.',
        'scope'                => 'tenant',
        'default_access_level' => 'admin',
        'legacy_role_mapping'  => 'admin',
        'notes'                => [
            'Wildcards cover every operational module (people, billing, ap, accounting, payroll, treasury, reports, staffing).',
            'AI permissions are intentionally explicit — auto-execute / autonomy toggles stay tenant_admin only.',
            'Cannot manage tenant-level SSO, branding, billing plans, or sub-tenant provisioning.',
        ],
    ],
    'manager' => [
        'label'                => 'Manager',
        'summary'              => 'Team lead. Read-mostly across their domain with a few approval permissions.',
        'scope'                => 'tenant',
        'default_access_level' => 'read',
        'legacy_role_mapping'  => 'manager',
        'notes'                => [
            'Can approve PTO + timesheets, view payroll runs but not modify pay rates.',
            'Cannot view PII fields (SSN / DOB), cannot post journal entries.',
            'Use sub-tenant scope on the membership to restrict to a single team / location.',
        ],
    ],
    'employee' => [
        'label'                => 'Employee',
        'summary'              => 'Base user. Sees only their own records inside whichever modules are enabled.',
        'scope'                => 'tenant',
        'default_access_level' => 'read',
        'legacy_role_mapping'  => 'employee',
        'notes'                => [
            'RBAC grants module-level access; queries inside each module restrict by `employee_id` so they only see their own data.',
            'Default for net-new users created via the Users admin or magic-link invites.',
        ],
    ],
    'contractor' => [
        'label'                => 'Contractor',
        'summary'              => '1099 / external worker. Same data-restriction pattern as employee but typically gated to time-entry + invoicing surfaces.',
        'scope'                => 'tenant',
        'default_access_level' => 'read',
        'legacy_role_mapping'  => 'employee',
        'notes'                => [
            'Falls through to the legacy `employee` role on the dual-check bridge until you grant specific module access.',
            'Pair with `linked_entity_type = "contractor"` on the membership so the People module surfaces their record.',
        ],
    ],
    'client' => [
        'label'                => 'Client',
        'summary'              => 'External party — usually the company being billed. Sees only billing surfaces tied to their own customer record.',
        'scope'                => 'tenant',
        'default_access_level' => 'read',
        'legacy_role_mapping'  => 'employee',
        'notes'                => [
            'Grant `billing.view` + scope to their `customer_id`. Do not grant `billing.*`.',
            'Use the `linked_entity_type = "customer"` membership field to wire the row-level scope.',
        ],
    ],
    'vendor' => [
        'label'                => 'Vendor',
        'summary'              => 'External party — supplier / contractor company. Typically views AP bills + payments for their own vendor record.',
        'scope'                => 'tenant',
        'default_access_level' => 'read',
        'legacy_role_mapping'  => 'employee',
        'notes'                => [
            'Pair with `linked_entity_type = "vendor"` so AP queries scope to their own bills only.',
            'Common for the upcoming `/vendor/portal` route.',
        ],
    ],
    'platform_staff' => [
        'label'                => 'Platform Staff',
        'summary'              => 'Internal CoreFlux operator embedded in a customer tenant — support, onboarding, implementation.',
        'scope'                => 'tenant',
        'default_access_level' => 'write',
        'legacy_role_mapping'  => 'admin',
        'notes'                => [
            'Behaves like `admin` but is intended for non-customer staff, so you can revoke it cleanly when an engagement ends.',
            'All actions are tagged in `membership_audit` for compliance reporting.',
        ],
    ],
    'custom' => [
        'label'                => 'Custom',
        'summary'              => 'Catch-all persona for bespoke role definitions — e.g., "External auditor" or "Read-only board observer".',
        'scope'                => 'tenant',
        'default_access_level' => 'none',
        'legacy_role_mapping'  => 'employee',
        'notes'                => [
            'Starts with zero implicit permissions. Grant module access manually via the Memberships & access screen.',
            'Use when none of the canonical personas describe the role. Pair with a descriptive `persona_label` so admins can tell custom personas apart.',
        ],
    ],
];

// ----------------------------------------------------------------- build response
$roles = [];
foreach ($allowedPersonaTypes as $key) {
    $entry        = $catalogue[$key] ?? [
        'label'                => ucwords(str_replace('_', ' ', $key)),
        'summary'              => '(no description)',
        'scope'                => 'tenant',
        'default_access_level' => 'read',
        'legacy_role_mapping'  => $key,
        'notes'                => [],
    ];
    $legacyKey    = (string) $entry['legacy_role_mapping'];
    $patterns     = (array) ($legacyMap[$legacyKey] ?? []);
    [$wild, $spec] = $splitGrants($patterns);

    $roles[] = [
        'key'                      => $key,
        'label'                    => $entry['label'],
        'summary'                  => $entry['summary'],
        'scope'                    => $entry['scope'],
        'default_access_level'     => $entry['default_access_level'],
        'grants_wildcard_modules'  => $wild,
        'grants_specific_perms'    => $spec,
        'legacy_role_mapping'      => $legacyKey,
        'notes'                    => array_values((array) $entry['notes']),
    ];
}

$legend = [
    'scope.platform'    => 'Cross-tenant. Affects every tenant on the install.',
    'scope.tenant'      => 'Scoped to a single tenant. Per-tenant memberships are required to act elsewhere.',
    'access.read'       => 'May view module data. No writes.',
    'access.write'      => 'May create / update records but not change settings or approve.',
    'access.admin'      => 'May change module settings, approve, and delete.',
    'access.none'       => 'No access. Default for `custom` personas until you grant something.',
    'wildcard.notation' => 'Wildcards like "people.*" mean every permission key beginning with that prefix is granted.',
    'dual_check_bridge' => 'Until the legacy RBAC sweep retires, every check passes IF the legacy role allows it AND the new RBACResolver allows it. Disagreements are logged to the bridge health monitor.',
];

api_ok([
    'allowed_persona_types' => $allowedPersonaTypes,
    'allowed_access_levels' => $allowedAccessLevels,
    'roles'                 => $roles,
    'legend'                => $legend,
]);

<?php
/**
 * Sprint 5 — Saved Views for the executive dashboard
 *
 * Asserts the new exec_dashboard_views API + UI wiring:
 *   - Migration creates exec_dashboard_views with the right shape
 *   - API supports GET (list/by-slug), POST (create), PATCH, DELETE
 *   - Visibility model: own + tenant-shared
 *   - is_default flips off siblings on the same user
 *   - UI renders a picker, save modal, manage modal, all with testids
 *   - URL ?view=<slug> deep-link works
 */

declare(strict_types=1);

$pass = 0; $fail = 0;
function _a(string $label, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok  $label\n"; }
    else       { $fail++; echo "  FAIL  $label\n"; }
}

echo "Sprint 5 — saved views\n";

$mig = (string) file_get_contents(__DIR__ . '/../core/migrations/014_exec_dashboard_views.sql');
$api = (string) file_get_contents(__DIR__ . '/../api/exec_dashboard_views.php');
$ui  = (string) file_get_contents(__DIR__ . '/../dashboard/src/pages/ExecutiveDashboard.jsx');

echo "\nMigration 014\n";
_a('CREATE TABLE exec_dashboard_views',          str_contains($mig, 'CREATE TABLE IF NOT EXISTS exec_dashboard_views'));
_a('column tenant_id',                           str_contains($mig, 'tenant_id'));
_a('column user_id',                             str_contains($mig, 'user_id'));
_a('column name',                                str_contains($mig, 'name            VARCHAR(120)'));
_a('column slug',                                str_contains($mig, 'slug            VARCHAR(100)'));
_a('column filters_json',                        str_contains($mig, 'filters_json'));
_a('column is_default',                          str_contains($mig, 'is_default'));
_a('column is_shared',                           str_contains($mig, 'is_shared'));
_a('UNIQUE per user_id+slug',                    str_contains($mig, 'uq_edv_user_slug'));
_a('index for shared lookup',                    str_contains($mig, 'idx_edv_tenant_shared'));

echo "\nAPI /api/exec_dashboard_views.php\n";
_a('requires auth',                              str_contains($api, 'api_require_auth()'));
_a('manager+ gate',                              str_contains($api, "['master_admin', 'tenant_admin', 'admin', 'manager']"));
_a('list returns own + shared views',            str_contains($api, "(v.user_id = :u OR v.is_shared = 1)"));
_a('GET by slug supported',                      str_contains($api, "v.slug = :s"));
_a('POST sanitises filter keys',                 str_contains($api, '_EXEC_VIEW_FILTER_KEYS'));
_a('POST clamps weeks 1..104',                   str_contains($api, "max(1, min(104,"));
_a('POST builds unique slug per user',           str_contains($api, 'WHERE user_id = :u AND slug = :s'));
_a('PATCH covers name/filters/shared/default',   str_contains($api, "'name'") && str_contains($api, "'filters'") && str_contains($api, "'is_shared'") && str_contains($api, "'is_default'"));
_a('setting is_default clears siblings',         str_contains($api, 'SET is_default = 0') && str_contains($api, 'AND id != :id'));
_a('DELETE checks tenant + ownership/shared',    str_contains($api, '_execViewCanModify'));
_a('shared views editable by tenant_admin',      str_contains($api, "['master_admin', 'tenant_admin', 'admin']"));
_a('serialise returns is_owner flag',            str_contains($api, "'is_owner'") && str_contains($api, "_is_owner"));

echo "\nUI ExecutiveDashboard.jsx\n";
_a('imports api (for POST/PATCH/DELETE)',        str_contains($ui, "import { api, useApi }"));
_a('hits /api/exec_dashboard_views.php',         str_contains($ui, '/api/exec_dashboard_views.php'));
_a('reads ?view= from URL on mount',             str_contains($ui, "URLSearchParams(location.search).get('view')"));
_a('auto-loads the user default view',           str_contains($ui, 'v.is_default && v.is_owner'));
_a('navigate updates URL with slug',             str_contains($ui, 'navigate(`/exec?view='));

echo "\nUI: header controls\n";
_a('Save view button',                           str_contains($ui, 'data-testid="exec-save-view"'));
_a('View picker dropdown',                       str_contains($ui, 'data-testid="exec-view-picker"'));
_a('Manage views button',                        str_contains($ui, 'data-testid="exec-views-manage"'));

echo "\nUI: SaveViewModal\n";
_a('save modal exists',                          str_contains($ui, 'function SaveViewModal'));
_a('save form has name input',                   str_contains($ui, 'data-testid="exec-save-name"'));
_a('shows captured filters preview',             str_contains($ui, 'data-testid="exec-save-filters-preview"'));
_a('default checkbox',                           str_contains($ui, 'data-testid="exec-save-default"'));
_a('shared checkbox (admins only)',              str_contains($ui, 'data-testid="exec-save-shared"'));
_a('shared toggle gated to master/tenant_admin', str_contains($ui, "global_role === 'master_admin'") && str_contains($ui, "global_role === 'tenant_admin'"));
_a('save submit button',                         str_contains($ui, 'data-testid="exec-save-submit"'));

echo "\nUI: ManageViewsModal\n";
_a('manage modal exists',                        str_contains($ui, 'function ManageViewsModal'));
_a('rows iterated by slug',                      str_contains($ui, 'data-testid={`exec-manage-row-${v.slug}`}'));
_a('default toggle per row',                     str_contains($ui, 'data-testid={`exec-manage-default-${v.slug}`}'));
_a('shared toggle per row',                      str_contains($ui, 'data-testid={`exec-manage-shared-${v.slug}`}'));
_a('delete per row',                             str_contains($ui, 'data-testid={`exec-manage-delete-${v.slug}`}'));
_a('delete confirms then DELETEs',               str_contains($ui, "api.delete(`/api/exec_dashboard_views.php?id="));
_a('load-from-row reuses onPickView',            str_contains($ui, 'onPicked'));

echo "\n--- $pass assertions, $fail failed ---\n";
exit($fail === 0 ? 0 : 1);

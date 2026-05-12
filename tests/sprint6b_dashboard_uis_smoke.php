<?php
/**
 * Sprint 6b — Web Dashboard UIs (Workflow Inbox + Audit Log + Dimensions
 * Admin + Period Close Workflow + Multi-entity Header Switcher) — static smoke.
 *
 *   php -d zend.assertions=1 /app/tests/sprint6b_dashboard_uis_smoke.php
 *
 * Validates each new React component on disk, its wiring into the SPA
 * router (App.jsx, AdminModule, AccountingModule), the Header.jsx
 * dropdown, and the backing API endpoints those components consume.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $name, bool $cond, ?string $hint = null) use (&$pass, &$fail): void {
    if ($cond) { echo "  ✓ {$name}\n"; $pass++; }
    else       { echo "  ✗ {$name}" . ($hint ? "  ({$hint})" : '') . "\n"; $fail++; }
};

$ROOT = realpath(__DIR__ . '/..');

echo "Files exist\n";
foreach ([
    'dashboard/src/pages/WorkflowInbox.jsx',
    'dashboard/src/pages/AuditLogViewer.jsx',
    'modules/accounting/ui/DimensionsAdmin.jsx',
    'modules/accounting/ui/PeriodCloseWorkflow.jsx',
    'dashboard/src/layout/Header.jsx',
] as $rel) {
    $assert("file: {$rel}", is_file("{$ROOT}/{$rel}"));
}

echo "\nWorkflowInbox component\n";
$wi = (string) file_get_contents("{$ROOT}/dashboard/src/pages/WorkflowInbox.jsx");
$assert('hits /api/workflow.php?path=inbox',         stripos($wi, "/api/workflow.php?path=inbox") !== false);
$assert('posts to ?action=act&id=',                  stripos($wi, '/api/workflow.php?action=act&id=') !== false);
$assert('approve action call',                       stripos($wi, "act(i.id, 'approve')") !== false);
$assert('reject action call',                        stripos($wi, "act(i.id, 'reject')") !== false);
$assert('comment action call',                       stripos($wi, "'comment'") !== false);
$assert('via=app sent in body',                      stripos($wi, "via: 'app'") !== false);
foreach (['workflow-inbox','workflow-inbox-empty','workflow-inbox-loading','workflow-inbox-error'] as $tid) {
    $assert("testid: {$tid}", stripos($wi, "data-testid=\"{$tid}\"") !== false);
}
foreach (['workflow-inbox-row-','workflow-inbox-approve-','workflow-inbox-reject-','workflow-inbox-comment-','workflow-inbox-sla-','workflow-inbox-comment-input-','workflow-inbox-comment-submit-','workflow-inbox-open-'] as $tidPrefix) {
    $assert("dynamic testid: {$tidPrefix}", stripos($wi, "data-testid={`{$tidPrefix}") !== false);
}

echo "\nAuditLogViewer component\n";
$av = (string) file_get_contents("{$ROOT}/dashboard/src/pages/AuditLogViewer.jsx");
$assert('hits /api/audit_log.php',                   stripos($av, '/api/audit_log.php') !== false);
$assert('CSV export href ?format=csv',               stripos($av, 'format=csv') !== false);
$assert('builds URLSearchParams',                    stripos($av, 'URLSearchParams') !== false);
foreach (['audit-log-viewer','audit-filter-event','audit-filter-user','audit-filter-from','audit-filter-to','audit-filter-limit','audit-search','audit-export-csv','audit-count','audit-empty','audit-error'] as $tid) {
    $assert("testid: {$tid}", stripos($av, "data-testid=\"{$tid}\"") !== false);
}
foreach (['audit-row-','audit-toggle-','audit-meta-'] as $tidPrefix) {
    $assert("dynamic testid: {$tidPrefix}", stripos($av, "data-testid={`{$tidPrefix}") !== false);
}

echo "\nDimensionsAdmin component\n";
$da = (string) file_get_contents("{$ROOT}/modules/accounting/ui/DimensionsAdmin.jsx");
$assert('GET /modules/accounting/api/dimensions.php',         stripos($da, "useApi('/modules/accounting/api/dimensions.php')") !== false);
$assert('POST upsert dimension',                              preg_match("#api\\.post\\(\\s*['\"]/modules/accounting/api/dimensions\\.php['\"]\\s*,#", $da) === 1);
$assert('DELETE dimension by id',                             stripos($da, "api.delete(`/modules/accounting/api/dimensions.php?id=") !== false);
$assert('add_value action',                                   stripos($da, '?action=add_value') !== false);
$assert('values action',                                      stripos($da, '?action=values&id=') !== false);
foreach (['accounting-dimensions','dimensions-new','dimensions-empty','dimensions-error','dimensions-action-error','dimensions-create-modal','dimensions-create-key','dimensions-create-label','dimensions-create-type','dimensions-create-required','dimensions-create-submit','dimensions-values-panel','dimensions-values-code','dimensions-values-label','dimensions-values-add'] as $tid) {
    $assert("testid: {$tid}", stripos($da, "data-testid=\"{$tid}\"") !== false);
}
foreach (['dimensions-row-','dimensions-deactivate-','dimensions-values-','dimensions-value-row-'] as $tidPrefix) {
    $assert("dynamic testid: {$tidPrefix}", stripos($da, "data-testid={`{$tidPrefix}") !== false);
}

echo "\nPeriodCloseWorkflow component\n";
$pc = (string) file_get_contents("{$ROOT}/modules/accounting/ui/PeriodCloseWorkflow.jsx");
$assert('GET periods list',                          stripos($pc, "/modules/accounting/api/periods.php") !== false);
$assert('GET close_tasks by period',                 stripos($pc, "/modules/accounting/api/close_tasks.php?period_id=") !== false);
$assert('POST seed checklist',                       stripos($pc, "/modules/accounting/api/close_tasks.php?action=seed") !== false);
$assert('POST complete task',                        stripos($pc, "/modules/accounting/api/close_tasks.php?action=complete&id=") !== false);
$assert('PATCH task status',                         preg_match("#api\\.patch\\(\\s*['\"]/modules/accounting/api/close_tasks\\.php['\"]#", $pc) === 1);
$assert('POST record close packet',                  stripos($pc, "/modules/accounting/api/close_packet.php?period_id=") !== false && stripos($pc, "&action=record") !== false);
$assert('opens close packet HTML in new tab',        stripos($pc, "format=html") !== false && stripos($pc, "window.open(") !== false);
foreach (['accounting-period-close-workflow','close-period-select','close-seed','close-build-packet','close-error','close-empty','close-stats','close-task-list'] as $tid) {
    $assert("testid: {$tid}", stripos($pc, "data-testid=\"{$tid}\"") !== false);
}
foreach (['close-task-','close-task-pill-','close-task-start-','close-task-complete-','close-task-block-'] as $tidPrefix) {
    $assert("dynamic testid: {$tidPrefix}", stripos($pc, "data-testid={`{$tidPrefix}") !== false);
}

echo "\nAccountingModule wiring\n";
$am = (string) file_get_contents("{$ROOT}/modules/accounting/ui/AccountingModule.jsx");
$assert('imports DimensionsAdmin',                   stripos($am, "import DimensionsAdmin from './DimensionsAdmin'") !== false);
$assert('imports PeriodCloseWorkflow',               stripos($am, "import PeriodCloseWorkflow from './PeriodCloseWorkflow'") !== false);
$assert('tab: dimensions',                           stripos($am, 'to="dimensions"') !== false);
$assert('tab: close',                                stripos($am, 'to="close"') !== false);
$assert('route: dimensions',                         stripos($am, 'path="dimensions"') !== false && stripos($am, '<DimensionsAdmin') !== false);
$assert('route: close',                              stripos($am, 'path="close"')      !== false && stripos($am, '<PeriodCloseWorkflow') !== false);

echo "\nApp.jsx wiring\n";
$app = (string) file_get_contents("{$ROOT}/dashboard/src/App.jsx");
$assert('imports WorkflowInbox',                     stripos($app, "import WorkflowInbox from './pages/WorkflowInbox'") !== false);
$assert('route /inbox',                              stripos($app, 'path="/inbox"') !== false && stripos($app, '<WorkflowInbox') !== false);

echo "\nAdminModule wiring\n";
$adm = (string) file_get_contents("{$ROOT}/dashboard/src/pages/AdminModule.jsx");
$assert('imports AuditLogViewer',                    stripos($adm, "import AuditLogViewer from './AuditLogViewer'") !== false);
$assert('imports ScrollText icon',                   stripos($adm, 'ScrollText') !== false);
$assert('sidebar link: audit-log',                   stripos($adm, "to: '/admin/audit-log'") !== false);
$assert('route: audit-log',                          stripos($adm, 'path="/audit-log"') !== false && stripos($adm, '<AuditLogViewer') !== false);
$assert('quick-action: audit log card',              stripos($adm, 'href="/admin/audit-log"') !== false);

echo "\nHeader.jsx — multi-entity switcher + inbox link\n";
$h = (string) file_get_contents("{$ROOT}/dashboard/src/layout/Header.jsx");
$assert('imports api',                               stripos($h, "from '../lib/api'") !== false);
$assert('imports Inbox icon',                        stripos($h, 'Inbox') !== false);
$assert('imports Briefcase icon',                    stripos($h, 'Briefcase') !== false);
$assert('GET /api/active_entity.php',                stripos($h, '/api/active_entity.php') !== false);
$assert('POST entity_id',                            preg_match("#api\\.post\\(\\s*['\"]/api/active_entity\\.php['\"]#", $h) === 1);
$assert('emits cf:active-entity-changed event',      stripos($h, 'cf:active-entity-changed') !== false);
$assert('header-inbox-link testid',                  stripos($h, 'data-testid="header-inbox-link"') !== false);
$assert('header-entity-button testid',               stripos($h, 'data-testid="header-entity-button"') !== false);
$assert('header-entity-switcher testid',             stripos($h, 'data-testid="header-entity-switcher"') !== false);
$assert('dynamic header-entity-option- testid',      stripos($h, 'data-testid={`header-entity-option-') !== false);
$assert('renders only when entities.length > 0',     stripos($h, 'entities.length > 0') !== false);
$assert('Inbox link routes to /inbox',               preg_match('#to="/inbox"#', $h) === 1);

echo "\nBacking API endpoints exist\n";
foreach ([
    'api/workflow.php',
    'api/audit_log.php',
    'api/active_entity.php',
    'modules/accounting/api/dimensions.php',
    'modules/accounting/api/close_tasks.php',
    'modules/accounting/api/close_packet.php',
    'modules/accounting/api/periods.php',
] as $rel) {
    $assert("api: {$rel}", is_file("{$ROOT}/{$rel}"));
}

echo "\nVite bundle synced\n";
$bundleHash = 'index-D38hBIYY.js';
$assert('compiled JS in spa-assets',                 is_file("{$ROOT}/spa-assets/{$bundleHash}"));
$indexHtml = (string) file_get_contents("{$ROOT}/dashboard/dist/index.html");
$assert('dashboard/dist/index.html references new JS', stripos($indexHtml, $bundleHash) !== false);
$deploy = (string) file_get_contents("{$ROOT}/.deploy-version");
$assert('.deploy-version expected_bundle updated',   stripos($deploy, $bundleHash) !== false);
$assert('.deploy-version lists new feature flags',
    stripos($deploy, 'web.workflow_inbox_ui') !== false
    && stripos($deploy, 'web.audit_log_viewer_ui') !== false
    && stripos($deploy, 'accounting.dimensions_admin_ui') !== false
    && stripos($deploy, 'accounting.period_close_workflow_ui') !== false
    && stripos($deploy, 'web.multi_entity_switcher_header') !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);

<?php
/**
 * Source-level contract for the unified CoreFlux workspace redesign.
 */
declare(strict_types=1);

$pass = 0;
$fail = 0;
$assert = static function (string $message, bool $ok) use (&$pass, &$fail): void {
    if ($ok) {
        echo "  OK {$message}\n";
        $pass++;
        return;
    }

    echo "  FAIL {$message}\n";
    $fail++;
};

$root = dirname(__DIR__);
$app = (string) file_get_contents($root . '/dashboard/src/App.jsx');
$layout = (string) file_get_contents($root . '/dashboard/src/layout/AppLayout.jsx');
$header = (string) file_get_contents($root . '/dashboard/src/layout/Header.jsx');
$sidebar = (string) file_get_contents($root . '/dashboard/src/layout/Sidebar.jsx');
$dashboard = (string) file_get_contents($root . '/dashboard/src/pages/DashboardOverview.jsx');
$tabs = (string) file_get_contents($root . '/dashboard/src/components/ModuleTabs.jsx');
$styles = (string) file_get_contents($root . '/dashboard/src/styles.css');
$ap = (string) file_get_contents($root . '/modules/ap/ui/APModule.jsx');
$billing = (string) file_get_contents($root . '/modules/billing/ui/BillingModule.jsx');
$treasury = (string) file_get_contents($root . '/modules/treasury/ui/TreasuryModule.jsx');
$placements = (string) file_get_contents($root . '/modules/placements/ui/List.jsx');

echo "\n1. Persistent workspace shell\n";
$assert('the application layout keeps the workspace rail visible',
    str_contains($layout, '<Sidebar')
    && !str_contains($layout, 'hideGlobalNavigation'));
$assert('the rail provides primary workspace and operational destinations',
    str_contains($sidebar, 'WORKSPACE_ITEMS')
    && str_contains($sidebar, '<SidebarGroup label="Operations">')
    && str_contains($sidebar, 'Ask CoreFlux')
    && str_contains($sidebar, 'Workspace settings'));
$assert('global search supports keyboard focus and routed commands',
    str_contains($header, 'Ctrl K')
    && str_contains($header, 'header-search-results')
    && str_contains($header, 'navigate(item.to)'));
$assert('the local visual test mode is restricted to localhost',
    str_contains($app, "['127.0.0.1', 'localhost'].includes(window.location.hostname)")
    && str_contains($app, ".get('demo') === '1'"));

echo "\n2. Decision-ready home\n";
$assert('home combines live KPIs, trend data, and attention queues',
    str_contains($dashboard, 'workspace-kpi-strip')
    && str_contains($dashboard, '<LineChart')
    && str_contains($dashboard, 'Needs attention'));
$assert('module cards remain compact routes into real work',
    str_contains($dashboard, '<span className="workspace-eyebrow">Workspaces</span>')
    && str_contains($dashboard, 'ModuleCards'));

echo "\n3. Module information architecture\n";
$assert('module tabs split primary destinations from overflow navigation',
    str_contains($tabs, 'const primary = items.slice')
    && str_contains($tabs, 'const overflow = items.slice')
    && str_contains($tabs, 'More'));
$assert('high-volume finance modules share the tab architecture',
    str_contains($ap, '<ModuleTabs')
    && str_contains($billing, '<ModuleTabs')
    && str_contains($treasury, '<ModuleTabs'));
$assert('placements opens with an operational KPI summary and action overflow',
    str_contains($placements, 'page-kpi-strip')
    && str_contains($placements, 'action-overflow'));

echo "\n4. Responsive workspace\n";
$assert('workspace chrome and operational controls adapt on smaller screens',
    str_contains($styles, '--cf-sidebar-width')
    && str_contains($styles, '.module-list-toolbar__actions')
    && str_contains($styles, '.approved-hours-ready__stats')
    && str_contains($styles, '@media (max-width: 620px)'));

echo "\nWorkspace shell: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);

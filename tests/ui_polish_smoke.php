<?php
/**
 * Source-level contract for the shared operational UI polish pass.
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
$sidebar = (string) file_get_contents($root . '/dashboard/src/layout/Sidebar.jsx');
$styles = (string) file_get_contents($root . '/dashboard/src/styles.css');
$bulk = (string) file_get_contents($root . '/dashboard/src/components/BulkEditBar.jsx');
$templates = (string) file_get_contents($root . '/dashboard/src/components/ExportTemplatePicker.jsx');
$accounting = (string) file_get_contents($root . '/modules/accounting/ui/AccountingModule.jsx');
$placements = (string) file_get_contents($root . '/modules/placements/ui/List.jsx');
$clients = (string) file_get_contents($root . '/modules/staffing/ui/Clients.jsx');

echo "\n1. Shared visual system\n";
$assert('sidebar icons use a consistent visual container',
    str_contains($sidebar, 'className="sidebar-icon-wrap"')
    && str_contains($styles, '.sidebar-icon-wrap'));
$assert('placement sidebar routes have deliberate icons',
    str_contains($sidebar, "'list':")
    && str_contains($sidebar, "'expiring':")
    && str_contains($sidebar, "'new':")
    && str_contains($sidebar, "'commissions':")
    && str_contains($sidebar, "'referrals':"));
$assert('accounting route aliases do not fall through to the generic icon',
    str_contains($sidebar, "'coa':")
    && str_contains($sidebar, "'journal':")
    && str_contains($sidebar, "'reconcile':")
    && str_contains($sidebar, "'transactions-to-review':"));
$assert('buttons, inputs, tables, and selected rows share polished states',
    str_contains($styles, '.btn--icon')
    && str_contains($styles, 'var(--cf-control-border)')
    && str_contains($styles, '.data-table tbody tr:hover')
    && str_contains($styles, 'input[type="checkbox"]:checked'));
$assert('responsive rules cover directory, bulk edit, and accounting layouts',
    str_contains($styles, '@media (max-width: 1280px)')
    && str_contains($styles, '@media (max-width: 820px)')
    && str_contains($styles, '@media (max-width: 620px)')
    && str_contains($styles, '.accounting-nav__menu'));

echo "\n2. Operational directories\n";
$assert('placements uses the shared page header and filter bar',
    str_contains($placements, 'directory-page__header')
    && str_contains($placements, 'directory-filter-bar')
    && str_contains($placements, 'data-table-wrap'));
$assert('placement actions use icons and one clear primary command',
    str_contains($placements, '<DatabaseZap')
    && str_contains($placements, '<Upload')
    && str_contains($placements, '<Download')
    && str_contains($placements, 'className="btn btn--primary" data-testid="placements-new-btn"'));
$assert('placement telemetry is not visible presentation copy',
    str_contains($placements, 'className="sr-only" data-testid="placements-rest-perf"'));
$assert('clients uses the shared page header, filters, and table wrapper',
    str_contains($clients, 'directory-page__header')
    && str_contains($clients, 'directory-filter-bar')
    && str_contains($clients, 'data-table-wrap'));
$assert('clients visually distinguishes source, terms, MSA, and status',
    str_contains($clients, 'className="source-chip"')
    && str_contains($clients, 'className="terms-chip"')
    && str_contains($clients, 'badge--${r.msa_status')
    && str_contains($clients, 'badge--${r.status}'));
$assert('export templates are secondary to create actions',
    str_contains($templates, 'className="btn"')
    && !str_contains($templates, 'className="btn btn--primary"'));

echo "\n3. Bulk editing and accounting navigation\n";
$assert('bulk selection is a focused workflow bar',
    str_contains($bulk, 'className="bulk-edit-bar"')
    && str_contains($bulk, 'bulk-edit-bar__selection')
    && str_contains($bulk, 'bulk-edit-bar__controls'));
$assert('accounting keeps the daily workflows in primary navigation',
    str_contains($accounting, "label: 'Bookkeeping'")
    && str_contains($accounting, "label: 'Transactions'")
    && str_contains($accounting, "label: 'Chart of accounts'")
    && str_contains($accounting, "label: 'Journal entries'")
    && str_contains($accounting, "label: 'Bank reconciliation'")
    && str_contains($accounting, "label: 'Reports'"));
$assert('less frequent accounting tools are grouped in the More menu',
    str_contains($accounting, "label: 'Financial statements'")
    && str_contains($accounting, "label: 'Review and automation'")
    && str_contains($accounting, "label: 'Data and configuration'")
    && str_contains($accounting, "label: 'Multi-entity'")
    && str_contains($accounting, 'data-testid="accounting-more-menu"'));
$assert('old unstructured accounting tab strip is gone',
    !str_contains($accounting, "<nav style={{ display: 'flex'")
    && !str_contains($accounting, 'function Tab('));

echo "\nUI polish smoke: {$pass} passed / {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

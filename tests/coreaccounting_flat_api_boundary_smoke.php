<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/accounting/standalone_boundary.php';
require_once __DIR__ . '/../core/accounting/standalone_api_boundary.php';

$root = dirname(__DIR__);
$allowed = [
    'api/index.php',
    'api/users.php',
    'api/active_entity.php',
    'api/admin/memberships.php',
    'api/ap/approve_by_email.php',
    'api/billing/money_movement_view.php',
    'api/coreone/v1/bills.php',
    'api/coreone/v1/invoices.php',
    'api/coreone/v1/journals.php',
    'api/coreone/v1/reports.php',
    'api/plaid_bank_link.php',
    'core/api/payment_rails.php',
    'modules/accounting/api/reports.php',
    'modules/billing/api/invoices.php',
    'dashboard.php',
    'login.php',
    'session.php',
    'spa.php',
];
$denied = [
    'api/ai/agents.php',
    'api/connecteam.php',
    'api/connecteam/status.php',
    'api/gusto_webhook.php',
    'api/internal/jobdiva_proxy.php',
    'api/internal/mappings_lookup.php',
    'api/jobdiva.php',
    'api/mercury_payments.php',
    'api/plaid_transfer_link.php',
    'api/quanta.php',
    'api/reports_staffing.php',
    'api/admin/integrations/field_map.php',
    'modules/payroll/api/runs.php',
    'modules/staffing/api/timesheets.php',
    'install.php',
    'diagnostics.php',
    'bootstrap_debug.php',
    'core/config.php',
];

$failures = [];
foreach ($allowed as $relative) {
    $path = $root . '/' . $relative;
    if (!is_file($path) || !coreAccountingAllowsPublicApiScript($path, $root, 'coreaccounting')) {
        $failures[] = "finance route refused: $relative";
    }
}
foreach ($denied as $relative) {
    $path = $root . '/' . $relative;
    if (!is_file($path) || coreAccountingAllowsPublicApiScript($path, $root, 'coreaccounting')) {
        $failures[] = "non-finance route allowed: $relative";
    }
    if (is_file($path) && !coreAccountingAllowsPublicApiScript($path, $root, 'production')) {
        $failures[] = "ERP route changed: $relative";
    }
}
if (coreAccountingAllowsPublicApiScript($root . '/api/no_such_route.php', $root, 'coreaccounting')) {
    $failures[] = 'unknown route allowed';
}

$config = (string) file_get_contents($root . '/core/config.php');
if (strpos($config, 'coreAccountingEnforcePublicApiScript(') === false
    || strpos($config, 'coreAccountingEnforcePublicApiScript(') > strpos($config, "require_once \$dbLocalConfig")) {
    $failures[] = 'standalone route guard runs after private DB config';
}
foreach (['jobdiva_proxy.php', 'mappings_lookup.php'] as $entrypoint) {
    $source = (string) file_get_contents($root . '/api/internal/' . $entrypoint);
    if (strpos($source, 'coreAccountingEnforcePublicApiScript(') === false
        || strpos($source, 'coreAccountingEnforcePublicApiScript(') > strpos($source, "getenv('INTERNAL_HMAC_SECRET')")) {
        $failures[] = "internal route can exit before boundary: $entrypoint";
    }
}
$aiSuggestions = (string) file_get_contents($root . '/core/api/ai_suggestions.php');
if (strpos($aiSuggestions, "coreAccountingAllowsModule(\$module, 'coreaccounting')") === false) {
    $failures[] = 'shared AI suggestions do not check standalone module scope';
}
$legacyDashboard = (string) file_get_contents($root . '/dashboard.php');
if (strpos($legacyDashboard, "getenv('COREFLUX_ENV') === 'coreaccounting'") === false
    || strpos($legacyDashboard, "header('Location: /spa.php')") > strpos($legacyDashboard, '$keepLegacy =')) {
    $failures[] = 'standalone dashboard still exposes legacy escape hatches';
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo 'Passed: ' . (count($allowed) + count($denied) + 6) . " standalone flat API boundary checks\n";

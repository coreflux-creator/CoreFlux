<?php
/**
 * Smoke: setup checklist + wizard "switch into now" CTA.
 *
 * Static contract checks for:
 *   - /api/sub_tenant_setup_checklist.php endpoint shape
 *   - SetupChecklistWidget.jsx rendering + dismiss
 *   - DashboardOverview wires the widget
 *   - SubTenantWizard "switch into" button calls switch API
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};

echo "/api/sub_tenant_setup_checklist.php\n";
$api = (string) file_get_contents(__DIR__ . '/../api/sub_tenant_setup_checklist.php');
$a('endpoint exists',                       strlen($api) > 500);
$a('PHP parses cleanly',                    (int) shell_exec('php -l ' . escapeshellarg(__DIR__ . '/../api/sub_tenant_setup_checklist.php') . ' >/dev/null 2>&1; echo $?') === 0);
$a('lazy ALTER adds dismissed_at column',   str_contains($api, 'ADD COLUMN setup_checklist_dismissed_at'));
$a('idempotent ALTER (caught)',             str_contains($api, "ALTER TABLE tenants ADD COLUMN setup_checklist_dismissed_at") &&
                                            str_contains($api, 'catch (\\Throwable'));
$a('GET returns checklist items',           str_contains($api, "'items'          => \$items"));
$a('returns visible flag',                  str_contains($api, "'visible'"));
$a('hides after 30 days',                   str_contains($api, '$ageDays <= 30'));
$a('hides on dismiss',                      str_contains($api, '!$dismissed'));
$a('hides on 100%',                         str_contains($api, '$pct < 100'));
$a('completion_pct calculated',             str_contains($api, '$pct'));

// Each checklist item must have id/label/done/action_label/action_href
$a('item: branding.logo',                   str_contains($api, "'id'            => 'branding.logo'"));
$a('item: branding.color',                  str_contains($api, "'id'            => 'branding.color'"));
$a('item: users.invited',                   str_contains($api, "'id'           => 'users.invited'"));
$a('item: accounting.coa',                  str_contains($api, "'id'           => 'accounting.coa'"));
$a('item: accounting.bank',                 str_contains($api, "'id'           => 'accounting.bank'"));
$a('item: approvals.policy',                str_contains($api, "'id'           => 'approvals.policy'"));
$a('item: entities.created',                str_contains($api, "'id'           => 'entities.created'"));
$a('item: people.added',                    str_contains($api, "'id'           => 'people.added'"));

$a('POST ?action=dismiss',                  str_contains($api, "\$action !== 'dismiss'"));
$a('dismiss is admin-only',                 str_contains($api, "['master_admin','tenant_admin','admin']"));

echo "\nSetupChecklistWidget.jsx\n";
$jsx = (string) file_get_contents(__DIR__ . '/../dashboard/src/pages/SetupChecklistWidget.jsx');
$a('widget file exists',                    strlen($jsx) > 500);
$a('hits checklist endpoint',               str_contains($jsx, '/api/sub_tenant_setup_checklist.php'));
$a('hides when !data.visible',              str_contains($jsx, '!data.visible'));
$a('progress bar present',                  str_contains($jsx, 'data-testid="setup-checklist-progress-bar"'));
$a('progress text test-id',                 str_contains($jsx, 'data-testid="setup-checklist-progress-text"'));
$a('dismiss button test-id',                str_contains($jsx, 'data-testid="setup-checklist-dismiss"'));
$a('per-item test-id template',             str_contains($jsx, 'setup-checklist-item-${item.id}'));
$a('per-item action button template',       str_contains($jsx, 'setup-checklist-action-${item.id}'));
$a('strikethrough for done items',          str_contains($jsx, "textDecoration: item.done ? 'line-through'"));
$a('renders Day X of 30 label',             str_contains($jsx, 'Day ${data.age_days} of 30'));

echo "\nDashboardOverview wires the widget\n";
$dash = (string) file_get_contents(__DIR__ . '/../dashboard/src/pages/DashboardOverview.jsx');
$a('imports SetupChecklistWidget',          str_contains($dash, "import SetupChecklistWidget from './SetupChecklistWidget'"));
$a('renders <SetupChecklistWidget />',      str_contains($dash, '<SetupChecklistWidget />'));

echo "\nSubTenantWizard 'switch into' CTA\n";
$wiz = (string) file_get_contents(__DIR__ . '/../dashboard/src/pages/SubTenantWizard.jsx');
$a('switch button test-id',                 str_contains($wiz, 'data-testid="wizard-switch-into"'));
$a('calls /api/sub_tenants.php?action=switch', str_contains($wiz, "/api/sub_tenants.php?action=switch"));
$a('passes new tenant_id',                  str_contains($wiz, '{ tenant_id: done.id }'));
$a('full reload to /',                      str_contains($wiz, "window.location.href = '/'"));
$a('shows tip about checklist',             stripos($wiz, 'Setup Checklist') !== false);

echo "\n--- " . ($pass + $fail) . " assertions, $fail failed ---\n";
exit($fail ? 1 : 0);

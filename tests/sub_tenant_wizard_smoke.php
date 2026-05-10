<?php
/**
 * Smoke: Sub-Tenant Onboarding Wizard.
 *
 * Static contract checks for:
 *   - SubTenantWizard.jsx 4-step component shape + test ids
 *   - AdminModule routes + sidebar wiring
 *   - SubTenantsAdmin "New" button now links to the wizard
 *   - core/sub_tenants.php subTenantProvision() accepts invites[]
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};

echo "SubTenantWizard.jsx component\n";
$jsx = (string) file_get_contents(__DIR__ . '/../dashboard/src/pages/SubTenantWizard.jsx');
$a('component file exists',                strlen($jsx) > 500);
$a('default export',                       str_contains($jsx, 'export default function SubTenantWizard'));
$a('uses useNavigate',                     str_contains($jsx, 'useNavigate'));
$a('posts to /api/sub_tenants.php',        str_contains($jsx, "api.post('/api/sub_tenants.php'"));

// 4-step stepper
$a('renders stepper',                      str_contains($jsx, 'data-testid="wizard-stepper"'));
$a('step ids interpolated 1..4',           str_contains($jsx, '`wizard-step-${idx}`'));
$a('step labels include Identity',         str_contains($jsx, "'Identity'") );
$a('step labels include Modules',          str_contains($jsx, "'Modules'"));
$a('step labels include Defaults',         str_contains($jsx, "'Defaults'"));
$a('step labels include Invites',          str_contains($jsx, "'Invites'"));

// Step 1 fields
$a('name input',                           str_contains($jsx, 'data-testid="wizard-name"'));
$a('slug input',                           str_contains($jsx, 'data-testid="wizard-slug"'));
$a('color picker',                         str_contains($jsx, 'data-testid="wizard-color"'));
$a('logo input',                           str_contains($jsx, 'data-testid="wizard-logo"'));

// Step 2 modules
$a('modules table',                        str_contains($jsx, 'data-testid="wizard-modules-table"'));
$a('per-module enable toggle',             str_contains($jsx, 'wizard-module-toggle-${m.key}'));
$a('per-module scope select',              str_contains($jsx, 'wizard-module-scope-${m.key}'));
$a('shared option',                        str_contains($jsx, '<option value="shared"'));
$a('isolated option',                      str_contains($jsx, '<option value="isolated"'));
$a('11 modules listed',                    substr_count($jsx, "{ key: '") >= 11);

// Step 4 invites
$a('invite row email field',               str_contains($jsx, 'wizard-invite-email-${i}'));
$a('invite row role field',                str_contains($jsx, 'wizard-invite-role-${i}'));
$a('add invite button',                    str_contains($jsx, 'data-testid="wizard-invite-add"'));
$a('email regex validates payload',        str_contains($jsx, '/^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$/'));

// Footer / nav controls
$a('prev button',                          str_contains($jsx, 'data-testid="wizard-prev"'));
$a('next button',                          str_contains($jsx, 'data-testid="wizard-next"'));
$a('finish button',                        str_contains($jsx, 'data-testid="wizard-finish"'));
$a('cancel returns to admin',              str_contains($jsx, "navigate('/admin/sub-tenants')"));
$a('done state',                           str_contains($jsx, 'data-testid="sub-tenant-wizard-done"'));

// Auto-slug
$a('auto-slug from name',                  str_contains($jsx, 'SLUGIFY'));

echo "\nAdminModule.jsx wiring\n";
$adm = (string) file_get_contents(__DIR__ . '/../dashboard/src/pages/AdminModule.jsx');
$a('imports SubTenantWizard',              str_contains($adm, "import SubTenantWizard from './SubTenantWizard'"));
$a('routes /sub-tenants/new',              str_contains($adm, '<Route path="/sub-tenants/new"'));

echo "\nSubTenantsAdmin.jsx button → wizard link\n";
$adminPage = (string) file_get_contents(__DIR__ . '/../dashboard/src/pages/SubTenantsAdmin.jsx');
$a('"New" button is now a Link',           str_contains($adminPage, 'to="/admin/sub-tenants/new"'));

echo "\ncore/sub_tenants.php — invites support\n";
$lib = (string) file_get_contents(__DIR__ . '/../core/sub_tenants.php');
$a('subTenantProvision reads opts.invites',     str_contains($lib, "\$opts['invites']"));
$a('validates email format',                    str_contains($lib, 'FILTER_VALIDATE_EMAIL'));
$a('idempotent ON DUPLICATE KEY user_tenants',  str_contains($lib, 'ON DUPLICATE KEY UPDATE'));
$a('audit captures invited list',               str_contains($lib, "'invited'") && str_contains($lib, "'invited' => \$invited"));
$a('PHP parses cleanly',                        (int) shell_exec('php -l ' . escapeshellarg(__DIR__ . '/../core/sub_tenants.php') . ' >/dev/null 2>&1; echo $?') === 0);

echo "\n--- " . ($pass + $fail) . " assertions, $fail failed ---\n";
exit($fail ? 1 : 0);

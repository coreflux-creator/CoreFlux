<?php
/**
 * Access Reviews enterprise-control smoke.
 *
 * Static + pure-helper contract. No DB required.
 */

declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
require_once $root . '/core/access_reviews.php';
require_once $root . '/core/ModuleRegistry.php';

$pass = 0;
$fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { echo "  OK  {$msg}\n"; $pass++; }
    else       { echo "  BAD {$msg}\n"; $fail++; }
};
$read = fn(string $path): string => (string) file_get_contents($path);

$svc = $read($root . '/core/access_reviews.php');
$api = $read($root . '/api/access_reviews.php');
$mig = $read($root . '/core/migrations/117_access_reviews.sql');
$router = $read($root . '/core/api_router.php');
$peopleManifestSrc = $read($root . '/modules/people/manifest.php');
$legacyMap = $read($root . '/core/rbac/legacy_map.php');
$docs = $read($root . '/docs/ACCESS_REVIEWS.md') . "\n" . $read($root . '/docs/PRODUCT_ARCHITECTURE_ALIGNMENT.md');

echo "Files parse\n";
foreach ([
    'core/access_reviews.php',
    'api/access_reviews.php',
    'core/api_router.php',
    'modules/people/manifest.php',
    'core/rbac/legacy_map.php',
] as $file) {
    $out = [];
    $rc = 0;
    exec('php -l ' . escapeshellarg($root . '/' . $file) . ' 2>&1', $out, $rc);
    $a("php -l {$file}", $rc === 0);
}

echo "\nMigration contract\n";
foreach (['access_review_campaigns', 'access_review_items', 'access_review_audit'] as $table) {
    $a("creates {$table}", str_contains($mig, "CREATE TABLE IF NOT EXISTS {$table}"));
}
foreach (['rbac_role_permission', 'membership_module_access', 'people_graph_permission_grant'] as $source) {
    $a("item source includes {$source}", str_contains($mig, "'{$source}'"));
}
$a('items store certification decision', str_contains($mig, "ENUM('pending','certified','revoked','exception','needs_change')"));
$a('items store remediation status', str_contains($mig, 'remediation_status'));
$a('campaign key is tenant unique', str_contains($mig, 'uq_access_review_campaign_key'));

echo "\nService contract\n";
foreach ([
    'accessReviewCreateCampaign',
    'accessReviewOpenCampaign',
    'accessReviewSnapshotCampaign',
    'accessReviewRecordDecision',
    'accessReviewCompleteCampaign',
    'accessReviewListItems',
] as $fn) {
    $a("function exists {$fn}", function_exists($fn));
}
$a('snapshots tenant memberships', str_contains($svc, 'FROM tenant_memberships tm'));
$a('snapshots module access grants', str_contains($svc, 'membership_module_access'));
$a('snapshots People Graph grants', str_contains($svc, 'people_graph_permission_grants'));
$a('expands role permissions with RBAC', str_contains($svc, 'RBAC::hasPermission($user, $permission)'));
$a('revokes module access by setting none', str_contains($svc, 'UPDATE membership_module_access SET access_level = "none"'));
$a('revokes People Graph grant through service', str_contains($svc, 'peopleGraphRevokePermissionGrant'));
$a('writes membership audit on module revoke', str_contains($svc, 'membership_audit'));
$a('campaign cannot complete with pending items', str_contains($svc, 'Cannot complete access review while items are pending'));

echo "\nPure risk helpers\n";
$a('PII permission is critical', accessReviewPermissionRisk('people.pii.view') === 'critical');
$a('banking permission is critical', accessReviewPermissionRisk('payroll.profiles.banking.view') === 'critical');
$a('approval permission is high', accessReviewPermissionRisk('billing.invoice.approve') === 'high');
$a('PII permission is sensitive', accessReviewIsSensitivePermission('people.pii.view'));
$a('low-risk permission excluded by default', !accessReviewIsSensitivePermission('people.view'));
$a('include_low_risk scope includes low-risk permission', accessReviewIsSensitivePermission('people.view', ['include_low_risk' => true]));

echo "\nAPI and routing\n";
$a('API gates GET with view permission', str_contains($api, "rbac_legacy_require(\$user, 'people.access_reviews.view')"));
$a('API gates POST with manage permission', str_contains($api, "rbac_legacy_require(\$user, 'people.access_reviews.manage')"));
foreach (['create', 'open', 'snapshot', 'decision', 'complete'] as $action) {
    $a("API supports {$action}", str_contains($api, "\$action === '{$action}'"));
}
$a('router aliases people/access-reviews', str_contains($router, "'people/access-reviews' => \$root . '/api/access_reviews.php'"));

echo "\nManifest and RBAC\n";
$manifest = require $root . '/modules/people/manifest.php';
$perms = array_keys($manifest['permissions'] ?? []);
$routes = array_map(fn($a) => $a['route'] ?? '', $manifest['actions'] ?? []);
$events = $manifest['audit_events'] ?? [];
$a('manifest declares access review route', in_array('access_reviews', $routes, true));
$a('manifest declares view permission', in_array('people.access_reviews.view', $perms, true));
$a('manifest declares manage permission', in_array('people.access_reviews.manage', $perms, true));
$a('manifest declares campaign audit', in_array('people.access_review.campaign.created', $events, true));
$a('legacy map declares view permission', str_contains($legacyMap, "'people.access_reviews.view'"));
$a('legacy map declares manage permission', str_contains($legacyMap, "'people.access_reviews.manage'"));

echo "\nDocs\n";
$a('docs mention canonical route', str_contains($docs, '/api/v1/people/access-reviews'));
$a('docs mention People Graph grants', str_contains($docs, 'People Graph permission grants'));
$a('docs mention certification decisions', str_contains($docs, 'certify, revoke, exception'));

echo "\nAccess Reviews smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

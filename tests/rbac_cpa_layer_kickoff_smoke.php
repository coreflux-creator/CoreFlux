<?php
/**
 * RBAC CPA-layer kickoff smoke — covers:
 *   - core/rbac/cpa_firms.php           (CpaFirmService)
 *   - api/admin/cpa_firms.php           (admin CRUD + portfolio route)
 *   - api/auth/consume_magic_link.php   (auto-apply external_auditor.default)
 *   - dashboard/src/pages/PermissionProfileBuilder.jsx
 *   - dashboard/src/pages/CpaPortfolio.jsx
 *   - dashboard/src/pages/AdminModule.jsx  (route + sidebar + overview wiring)
 *
 *   php -d zend.assertions=1 /app/tests/rbac_cpa_layer_kickoff_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ────────────────────────────────────────── 1) CpaFirmService
echo "core/rbac/cpa_firms.php — service surface\n";
$svcPath = $ROOT . '/core/rbac/cpa_firms.php';
$svc     = (string) file_get_contents($svcPath);
$a('service file exists',                                 $svc !== '');
$a('declares class CpaFirmService',                       $c($svc, 'class CpaFirmService'));
$a('RELATIONSHIP_TYPES constant',                         $c($svc, "RELATIONSHIP_TYPES = ['books_full', 'books_review_only', 'tax_only', 'advisory_only', 'custom']"));
$a('STATUSES constant',                                   $c($svc, "STATUSES           = ['active', 'pending', 'paused', 'ended']"));
$a('listClientsForFirm()',                                $c($svc, 'public static function listClientsForFirm'));
$a('getForFirm()',                                        $c($svc, 'public static function getForFirm'));
$a('upsert()',                                            $c($svc, 'public static function upsert'));
$a('endLink()',                                           $c($svc, 'public static function endLink'));
$a('deleteLink()',                                        $c($svc, 'public static function deleteLink'));
$a('portfolioForUser()',                                  $c($svc, 'public static function portfolioForUser'));
$a('list joins tenants for client_name',                  $c($svc, 'LEFT JOIN tenants c ON c.id = l.client_tenant_id'));
$a('upsert blocks self-link',                             $c($svc, 'A firm cannot link to itself'));
$a('upsert validates relationship_type',                  $c($svc, 'Invalid relationship_type'));
$a('upsert ON DUPLICATE KEY UPDATE',                      $c($svc, 'ON DUPLICATE KEY UPDATE'));
$a('endLink sets status="ended" + engagement_end_date',   $c($svc, "SET status = \"ended\", engagement_end_date"));
$a('portfolio filters by user firm memberships',          $c($svc, 'SELECT tenant_id FROM tenant_memberships'));
$a('portfolio considers cpa* personas',                   $c($svc, "'cpa','cpa_partner','cpa_staff'"));
$a('portfolio considers bookkeeper/client_advisor',       $c($svc, "'bookkeeper','client_advisor'"));
$a('portfolio surfaces has_client_membership flag',       $c($svc, 'has_client_membership'));
$a('endLink scoped to firm_tenant_id',                    $c($svc, 'WHERE id = :id AND firm_tenant_id = :t'));
$a('deleteLink scoped to firm_tenant_id',                 $c($svc, 'DELETE FROM cpa_firm_client_links WHERE id = :id AND firm_tenant_id = :t'));
$a('writes to membership_audit on changes',               $c($svc, 'INSERT INTO membership_audit'));

$rc = 0; $o = [];
exec('php -l ' . escapeshellarg($svcPath) . ' 2>&1', $o, $rc);
$a('php -l cpa_firms.php service',                        $rc === 0);

// ────────────────────────────────────────── 2) admin endpoint
echo "\napi/admin/cpa_firms.php — admin endpoint\n";
$endPath = $ROOT . '/api/admin/cpa_firms.php';
$end     = (string) file_get_contents($endPath);
$a('endpoint file exists',                                $end !== '');
$a('requires api_bootstrap',                              $c($end, "require_once __DIR__ . '/../../core/api_bootstrap.php'"));
$a('requires CpaFirmService',                             $c($end, "require_once __DIR__ . '/../../core/rbac/cpa_firms.php'"));
$a('portfolio action is auth-only (any user)',            $c($end, "if (\$method === 'GET' && \$action === 'portfolio')"));
$a('portfolio uses api_require_auth(false)',              $c($end, 'api_require_auth(false)'));
$a('portfolio groups by firm',                            $c($end, "'firms' => array_values"));
$a('admin gate after portfolio branch',                   $c($end, "in_array(\$role, ['master_admin', 'tenant_admin']"));
$a('handles missing migration with 503',                  $c($end, 'Migration 100_rbac_cpa_personas_and_profiles.sql has not been applied'));
$a('GET list',                                            $c($end, 'CpaFirmService::listClientsForFirm'));
$a('GET single by id',                                    $c($end, 'CpaFirmService::getForFirm'));
$a('POST action=save',                                    $c($end, "\$action === 'save'") && $c($end, 'CpaFirmService::upsert'));
$a('POST action=end',                                     $c($end, "\$action === 'end'") && $c($end, 'CpaFirmService::endLink'));
$a('DELETE -> deleteLink',                                $c($end, 'CpaFirmService::deleteLink'));
$a('returns 422 on bad payload',                          $c($end, 'InvalidArgumentException'));
$a('405 on unknown method',                               $c($end, "api_error('Method not allowed', 405)"));

$rc = 0; $o = [];
exec('php -l ' . escapeshellarg($endPath) . ' 2>&1', $o, $rc);
$a('php -l cpa_firms.php endpoint',                       $rc === 0);

// ────────────────────────────────────────── 3) external_auditor auto-apply
echo "\nexternal_auditor auto-apply in consume_magic_link.php\n";
$cmPath = $ROOT . '/api/auth/consume_magic_link.php';
$cm     = (string) file_get_contents($cmPath);
$a('reads persona_type when stamping accepted_at',        $c($cm, 'SELECT id, persona_type FROM tenant_memberships'));
$a("branches on persona_type === 'external_auditor'",    $c($cm, "if (\$persona === 'external_auditor')"));
$a('requires permission_profiles service',                $c($cm, "require_once __DIR__ . '/../../core/rbac/permission_profiles.php'"));
$a('looks up external_auditor.default profile',           $c($cm, "PermissionProfileService::getByKey('external_auditor.default'"));
$a('applies profile via PermissionProfileService::apply', $c($cm, 'PermissionProfileService::apply'));
$a('apply is wrapped in try/catch (non-fatal)',           $c($cm, '/* best effort — sign-in still succeeds */'));

$rc = 0; $o = [];
exec('php -l ' . escapeshellarg($cmPath) . ' 2>&1', $o, $rc);
$a('php -l consume_magic_link.php',                       $rc === 0);

// ────────────────────────────────────────── 4) PermissionProfileBuilder.jsx
echo "\ndashboard/src/pages/PermissionProfileBuilder.jsx\n";
$ppbPath = $ROOT . '/dashboard/src/pages/PermissionProfileBuilder.jsx';
$ppb     = (string) file_get_contents($ppbPath);
$a('builder file exists',                                 $ppb !== '');
$a('root data-testid',                                    $c($ppb, 'data-testid="permission-profile-builder"'));
$a('new profile button',                                  $c($ppb, 'data-testid="profile-builder-new"'));
$a('refresh button',                                      $c($ppb, 'data-testid="profile-builder-refresh"'));
$a('list testid',                                         $c($ppb, 'data-testid="profile-builder-list"'));
$a('empty state testid',                                  $c($ppb, 'data-testid="profile-builder-empty"'));
$a('loading state testid',                                $c($ppb, 'data-testid="profile-builder-loading"'));
$a('per-row testid',                                      $c($ppb, 'data-testid={`profile-row-${p.profile_key}`}'));
$a('per-row edit button testid',                          $c($ppb, 'data-testid={`profile-edit-${p.profile_key}`}'));
$a('editor save testid',                                  $c($ppb, 'data-testid="profile-editor-save"'));
$a('editor delete testid',                                $c($ppb, 'data-testid="profile-editor-delete"'));
$a('editor cancel testid',                                $c($ppb, 'data-testid="profile-editor-cancel"'));
$a('grants matrix testid',                                $c($ppb, 'data-testid="profile-grants-matrix"'));
$a('per-module grant testid',                             $c($ppb, 'data-testid={`profile-grant-${m}`}'));
$a('POST save action',                                    $c($ppb, "'/api/admin/permission_profiles.php?action=save'"));
$a('DELETE by id',                                        $c($ppb, '/api/admin/permission_profiles.php?id=${id}'));
$a('GET list',                                            $c($ppb, "'/api/admin/permission_profiles.php'"));
$a('system badge testid',                                 $c($ppb, 'data-testid="profile-badge-system"'));
$a('tenant badge testid',                                 $c($ppb, 'data-testid="profile-badge-tenant"'));
$a('persona dropdown includes cpa types',                 $c($ppb, "'cpa', 'cpa_partner', 'cpa_staff'"));
$a('persona dropdown includes bookkeeper/external_auditor', $c($ppb, "'bookkeeper', 'client_advisor', 'external_auditor'"));

// ────────────────────────────────────────── 5) CpaPortfolio.jsx
echo "\ndashboard/src/pages/CpaPortfolio.jsx\n";
$cppPath = $ROOT . '/dashboard/src/pages/CpaPortfolio.jsx';
$cpp     = (string) file_get_contents($cppPath);
$a('portfolio file exists',                               $cpp !== '');
$a('root data-testid',                                    $c($cpp, 'data-testid="cpa-portfolio"'));
$a('GETs portfolio action',                               $c($cpp, "'/api/admin/cpa_firms.php?action=portfolio'"));
$a('switch tenant action call',                           $c($cpp, "'/api/sub_tenants.php?action=switch'"));
$a('refresh button testid',                               $c($cpp, 'data-testid="cpa-portfolio-refresh"'));
$a('empty state testid',                                  $c($cpp, 'data-testid="cpa-portfolio-empty"'));
$a('summary testid',                                      $c($cpp, 'data-testid="cpa-portfolio-summary"'));
$a('firms-count testid',                                  $c($cpp, 'data-testid="cpa-portfolio-firms-count"'));
$a('clients-count testid',                                $c($cpp, 'data-testid="cpa-portfolio-clients-count"'));
$a('per-firm testid',                                     $c($cpp, 'data-testid={`cpa-portfolio-firm-${firm.firm_tenant_id}`}'));
$a('per-row testid',                                      $c($cpp, 'data-testid={`cpa-portfolio-row-${c.link_id}`}'));
$a('jump-in button testid',                               $c($cpp, 'data-testid={`cpa-portfolio-jump-${c.client_tenant_id}`}'));
$a('disables jump when has_client_membership=false',      $c($cpp, '!c.has_client_membership'));
$a('reloads SPA after switch',                            $c($cpp, "window.location.href = '/'"));

// ────────────────────────────────────────── 6) AdminModule wiring
echo "\nAdminModule wiring\n";
$amPath = $ROOT . '/dashboard/src/pages/AdminModule.jsx';
$am     = (string) file_get_contents($amPath);
$a('imports PermissionProfileBuilder',                    $c($am, "import PermissionProfileBuilder from './PermissionProfileBuilder'"));
$a('imports CpaPortfolio',                                $c($am, "import CpaPortfolio from './CpaPortfolio'"));
$a('mounts /permission-profiles',                         $c($am, 'path="/permission-profiles"'));
$a('mounts /cpa-portfolio',                               $c($am, 'path="/cpa-portfolio"'));
$a('sidebar link to /admin/permission-profiles',          $c($am, "to: '/admin/permission-profiles'"));
$a('sidebar link to /admin/cpa-portfolio',                $c($am, "to: '/admin/cpa-portfolio'"));
$a('overview card for permission profiles',               $c($am, 'href="/admin/permission-profiles"'));
$a('overview card for CPA portfolio',                     $c($am, 'href="/admin/cpa-portfolio"'));

// ────────────────────────────────────────── summary
echo "\n=========================================\n";
echo "RBAC CPA-layer kickoff smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);

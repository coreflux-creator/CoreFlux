<?php
/**
 * RBAC CPA-layer phase 2 smoke — covers the three "next items":
 *   - Bulk-seat onboarding (CpaFirmService::upsert with seed_memberships)
 *   - CPA-scoped audit endpoint (api/admin/cpa_audit.php)
 *   - Firm-dashboard KPI rollup endpoint (api/admin/cpa_firm_dashboard.php)
 *   - Three new React pages (CpaFirmClientsAdmin, CpaFirmDashboard, CpaAuditPage)
 *   - AdminModule wiring for all of them
 *
 *   php -d zend.assertions=1 /app/tests/rbac_cpa_layer_phase2_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ────────────────────────────────────────── 1) bulk-seat on the service
echo "core/rbac/cpa_firms.php — bulk-seat upsert\n";
$svc = (string) file_get_contents($ROOT . '/core/rbac/cpa_firms.php');
$a('upsert signature returns int|array',                  $c($svc, 'public static function upsert(array $input, int $firmTenantId, ?int $actorUserId = null): int|array'));
$a('seed_memberships parsed from input',                  $c($svc, "is_array(\$input['seed_memberships'] ?? null)"));
$a('seed phase requires PermissionProfileService',        $c($svc, "require_once __DIR__ . '/permission_profiles.php'"));
$a('seed phase calls seatMembershipOnClient',             $c($svc, 'self::seatMembershipOnClient'));
$a('seed phase applies profile when profile_key set',     $c($svc, "PermissionProfileService::getByKey(\$profileKey"));
$a('seed phase tolerates per-row errors',                 $c($svc, "['user_id' => \$userId, 'error' =>"));
$a('seed audit emits cpa_link_seed',                      $c($svc, "'cpa_link_seed'"));
$a('seatMembershipOnClient persona whitelist',            $c($svc, "'cpa','cpa_partner','cpa_staff'") && $c($svc, "'bookkeeper','client_advisor','external_auditor'"));
$a('seatMembershipOnClient validates user exists',        $c($svc, "user_id not found"));
$a('seatMembershipOnClient upserts active row',           $c($svc, 'invited_at, accepted_at)') && $c($svc, '"active"'));
$a('linkedClientTenantIdsForUser helper',                 $c($svc, 'public static function linkedClientTenantIdsForUser'));
$a('firmTenantIdsForUser helper',                         $c($svc, 'public static function firmTenantIdsForUser'));

$rc = 0; $o = [];
exec('php -l ' . escapeshellarg($ROOT . '/core/rbac/cpa_firms.php') . ' 2>&1', $o, $rc);
$a('php -l cpa_firms.php',                                $rc === 0);

// ────────────────────────────────────────── 2) endpoint passes bulk-seat
echo "\napi/admin/cpa_firms.php — bulk-seat passthrough\n";
$end = (string) file_get_contents($ROOT . '/api/admin/cpa_firms.php');
$a('save action handles int|array return',                $c($end, 'is_array($result)'));
$a('seeded array surfaced on success',                    $c($end, "'seeded' => \$result['seeded']"));
$a('back-compat plain id path',                           $c($end, "'id' => (int) \$result, 'saved' => true"));

// ────────────────────────────────────────── 3) cpa_audit endpoint
echo "\napi/admin/cpa_audit.php\n";
$audit = (string) file_get_contents($ROOT . '/api/admin/cpa_audit.php');
$a('audit endpoint exists',                               $audit !== '');
$a('requires CpaFirmService',                             $c($audit, "require_once __DIR__ . '/../../core/rbac/cpa_firms.php'"));
$a('auth required (no admin gate)',                       $c($audit, 'api_require_auth(false)') && !$c($audit, "in_array(\$role, ['master_admin'"));
$a('uses linkedClientTenantIdsForUser',                   $c($audit, 'CpaFirmService::linkedClientTenantIdsForUser'));
$a('returns empty when no portfolio',                     $c($audit, "'rows' => [], 'tenant_ids' => []"));
$a('reads from cross_tenant_accounting_audit',            $c($audit, 'cross_tenant_accounting_audit'));
$a('reads from membership_audit',                         $c($audit, 'FROM membership_audit'));
$a('tags rows with source = accounting / membership',     $c($audit, "'accounting' AS source") && $c($audit, "'membership' AS source"));
$a('handles missing tables gracefully',                   substr_count($audit, '/* table absent → empty branch */') >= 2);
$a('migration-absent path returns empty + 200',           $c($audit, "api_ok(['rows' => [], 'tenant_ids' => [], 'count' => 0, 'limit' => 0])"));
$a('validates since YYYY-MM-DD',                          $c($audit, "/^\\d{4}-\\d{2}-\\d{2}\$/"));
$a('limit clamped to 1..500',                             $c($audit, "max(1, min(500, (int) api_query('limit', 200)))"));
$a('merges + sorts by occurred_at DESC',                  $c($audit, 'usort($rows'));

$rc = 0; $o = [];
exec('php -l ' . escapeshellarg($ROOT . '/api/admin/cpa_audit.php') . ' 2>&1', $o, $rc);
$a('php -l cpa_audit.php',                                $rc === 0);

// ────────────────────────────────────────── 4) firm dashboard endpoint
echo "\napi/admin/cpa_firm_dashboard.php\n";
$dash = (string) file_get_contents($ROOT . '/api/admin/cpa_firm_dashboard.php');
$a('dashboard endpoint exists',                           $dash !== '');
$a('requires CpaFirmService',                             $c($dash, "require_once __DIR__ . '/../../core/rbac/cpa_firms.php'"));
$a('auth required (no admin gate)',                       $c($dash, 'api_require_auth(false)') && !$c($dash, "in_array(\$role, ['master_admin'"));
$a('uses portfolioForUser',                               $c($dash, 'CpaFirmService::portfolioForUser'));
$a('firm_tenant_id filter supported',                     $c($dash, 'api_query(\'firm_tenant_id\')'));
$a('KPI 1: accounting_exceptions',                        $c($dash, 'FROM accounting_exceptions'));
$a('KPI 1 filters open/assigned',                         $c($dash, "status IN ('open','assigned')"));
$a('KPI 2: accounting_outbox_events',                     $c($dash, 'FROM accounting_outbox_events'));
$a('KPI 2 filters queued/retrying/dead_letter',           $c($dash, "status IN ('queued','retrying','dead_letter')"));
$a('KPI 3: accounting_periods',                           $c($dash, 'FROM accounting_periods'));
$a('KPI 3 filters past end_date open/soft_closed',        $c($dash, 'end_date < CURDATE()') && $c($dash, "status IN ('open','soft_closed')"));
$a('KPI tables guarded against absence',                  substr_count($dash, '/* migration ') >= 2 || substr_count($dash, '/* periods module absent */') >= 1);
$a('needs_attention summed per client',                   $c($dash, "\$kpi['needs_attention'] = \$kpi['open_exceptions'] + \$kpi['draft_outbox'] + \$kpi['late_close_periods']"));
$a('per-firm + portfolio-wide totals',                    $c($dash, '_emptyTotals()'));

$rc = 0; $o = [];
exec('php -l ' . escapeshellarg($ROOT . '/api/admin/cpa_firm_dashboard.php') . ' 2>&1', $o, $rc);
$a('php -l cpa_firm_dashboard.php',                       $rc === 0);

// ────────────────────────────────────────── 5) CpaFirmClientsAdmin.jsx
echo "\ndashboard/src/pages/CpaFirmClientsAdmin.jsx\n";
$cfca = (string) file_get_contents($ROOT . '/dashboard/src/pages/CpaFirmClientsAdmin.jsx');
$a('admin file exists',                                   $cfca !== '');
$a('root data-testid',                                    $c($cfca, 'data-testid="cpa-clients-admin"'));
$a('new-link button',                                     $c($cfca, 'data-testid="cpa-clients-new"'));
$a('form testid',                                         $c($cfca, 'data-testid="cpa-clients-form"'));
$a('GET list endpoint',                                   $c($cfca, "'/api/admin/cpa_firms.php'"));
$a('POST save endpoint',                                  $c($cfca, "'/api/admin/cpa_firms.php?action=save'"));
$a('POST end endpoint',                                   $c($cfca, "'/api/admin/cpa_firms.php?action=end'"));
$a('list testid',                                         $c($cfca, 'data-testid="cpa-clients-list"'));
$a('per-row testid template',                             $c($cfca, 'data-testid={`cpa-clients-row-${l.id}`}'));
$a('per-row End button',                                  $c($cfca, 'data-testid={`cpa-clients-end-${l.id}`}'));
$a('bulk-seat add button',                                $c($cfca, 'data-testid="cpa-clients-seed-add"'));
$a('bulk-seat row testid template',                       $c($cfca, 'data-testid={`cpa-clients-seed-row-${index}`}'));
$a('bulk-seat user picker',                               $c($cfca, 'data-testid={`cpa-clients-seed-user-${index}`}'));
$a('bulk-seat profile picker',                            $c($cfca, 'data-testid={`cpa-clients-seed-profile-${index}`}'));
$a('bulk-seat persona picker',                            $c($cfca, 'data-testid={`cpa-clients-seed-type-${index}`}'));
$a('bulk-seat remove button',                             $c($cfca, 'data-testid={`cpa-clients-seed-remove-${index}`}'));
$a('seed outcome card',                                   $c($cfca, 'data-testid="cpa-clients-seed-outcome"'));
$a('firm-personas array exposed',                         $c($cfca, "'cpa', 'cpa_partner', 'cpa_staff'") && $c($cfca, "'bookkeeper', 'client_advisor', 'external_auditor'"));

// ────────────────────────────────────────── 6) CpaFirmDashboard.jsx
echo "\ndashboard/src/pages/CpaFirmDashboard.jsx\n";
$cfd = (string) file_get_contents($ROOT . '/dashboard/src/pages/CpaFirmDashboard.jsx');
$a('dashboard file exists',                               $cfd !== '');
$a('root data-testid',                                    $c($cfd, 'data-testid="cpa-dashboard"'));
$a('GETs dashboard endpoint',                             $c($cfd, "'/api/admin/cpa_firm_dashboard.php'"));
$a('firm_tenant_id filter URL form',                      $c($cfd, '?firm_tenant_id=${firmFilter}'));
$a('switch tenant via /api/sub_tenants.php',              $c($cfd, "'/api/sub_tenants.php?action=switch'"));
$a('totals block testid',                                 $c($cfd, 'data-testid="cpa-dashboard-totals"'));
$a('totals firms tile',                                   $c($cfd, 'testid="cpa-dashboard-total-firms"'));
$a('totals exceptions tile',                              $c($cfd, 'testid="cpa-dashboard-total-exceptions"'));
$a('totals outbox tile',                                  $c($cfd, 'testid="cpa-dashboard-total-outbox"'));
$a('totals late-close tile',                              $c($cfd, 'testid="cpa-dashboard-total-late-close"'));
$a('per-firm card testid template',                       $c($cfd, 'data-testid={`cpa-dashboard-firm-${firm.firm_tenant_id}`}'));
$a('per-client row testid template',                      $c($cfd, 'data-testid={`cpa-dashboard-row-${c.link_id}`}'));
$a('per-client jump button',                              $c($cfd, 'data-testid={`cpa-dashboard-jump-${c.client_tenant_id}`}'));
$a('per-client exceptions cell',                          $c($cfd, 'data-testid={`cpa-dashboard-cell-exceptions-${c.client_tenant_id}`}'));
$a('NeedsAttentionPill clean state',                      $c($cfd, 'data-testid="cpa-dashboard-pill-clean"'));
$a('NeedsAttentionPill attention state',                  $c($cfd, 'data-testid="cpa-dashboard-pill-attention"'));
$a('sort by needs_attention DESC',                        $c($cfd, 'b.kpis.needs_attention - a.kpis.needs_attention'));
$a('empty state testid',                                  $c($cfd, 'data-testid="cpa-dashboard-empty"'));

// ────────────────────────────────────────── 7) CpaAuditPage.jsx
echo "\ndashboard/src/pages/CpaAuditPage.jsx\n";
$cap = (string) file_get_contents($ROOT . '/dashboard/src/pages/CpaAuditPage.jsx');
$a('audit page file exists',                              $cap !== '');
$a('root data-testid',                                    $c($cap, 'data-testid="cpa-audit-page"'));
$a('GETs cpa_audit.php',                                  $c($cap, '/api/admin/cpa_audit.php?'));
$a('since input',                                         $c($cap, 'data-testid="cpa-audit-since"'));
$a('action input',                                        $c($cap, 'data-testid="cpa-audit-action"'));
$a('limit select',                                        $c($cap, 'data-testid="cpa-audit-limit"'));
$a('apply button',                                        $c($cap, 'data-testid="cpa-audit-apply"'));
$a('empty state testid',                                  $c($cap, 'data-testid="cpa-audit-empty"'));
$a('table testid',                                        $c($cap, 'data-testid="cpa-audit-table"'));
$a('per-row testid template',                             $c($cap, 'data-testid={`cpa-audit-row-${r.source}-${r.id}`}'));
$a('source badge accounting',                             $c($cap, 'data-testid={`cpa-audit-source-${src}`}'));
$a('refresh button testid',                               $c($cap, 'data-testid="cpa-audit-refresh"'));
$a('YYYY-MM-DD client-side validation',                   $c($cap, '/^\\d{4}-\\d{2}-\\d{2}$/'));

// ────────────────────────────────────────── 8) AdminModule wiring
echo "\nAdminModule wiring — three new routes\n";
$am = (string) file_get_contents($ROOT . '/dashboard/src/pages/AdminModule.jsx');
$a('imports CpaFirmClientsAdmin',                         $c($am, "import CpaFirmClientsAdmin from './CpaFirmClientsAdmin'"));
$a('imports CpaFirmDashboard',                            $c($am, "import CpaFirmDashboard from './CpaFirmDashboard'"));
$a('imports CpaAuditPage',                                $c($am, "import CpaAuditPage from './CpaAuditPage'"));
$a('mounts /cpa-clients',                                 $c($am, 'path="/cpa-clients"'));
$a('mounts /cpa-dashboard',                               $c($am, 'path="/cpa-dashboard"'));
$a('mounts /cpa-audit',                                   $c($am, 'path="/cpa-audit"'));
$a('sidebar link /admin/cpa-clients',                     $c($am, "to: '/admin/cpa-clients'"));
$a('sidebar link /admin/cpa-dashboard',                   $c($am, "to: '/admin/cpa-dashboard'"));
$a('sidebar link /admin/cpa-audit',                       $c($am, "to: '/admin/cpa-audit'"));
$a('overview card /admin/cpa-clients',                    $c($am, 'href="/admin/cpa-clients"'));
$a('overview card /admin/cpa-dashboard',                  $c($am, 'href="/admin/cpa-dashboard"'));
$a('overview card /admin/cpa-audit',                      $c($am, 'href="/admin/cpa-audit"'));

// ────────────────────────────────────────── summary
echo "\n=========================================\n";
echo "RBAC CPA layer phase 2 smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);

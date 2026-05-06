<?php
/**
 * Sprint 6i smoke — Audit-log Anomaly Spotter (Phase 1).
 *
 * Asserts the AI anomaly endpoint shape and the AuditLogViewer UI wiring
 * (advisory banner, signals chips, hours selector, graceful degrade).
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$lint = function (string $p): bool {
    $o = []; $rc = 0; @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
    return $rc === 0;
};
$ROOT = realpath(__DIR__ . '/..');

echo "Audit Anomaly API — /api/audit_anomaly.php\n";
$api = (string) file_get_contents("{$ROOT}/api/audit_anomaly.php");
$assert('audit_anomaly.php exists',                     strlen($api) > 0);
$assert('audit_anomaly.php parses',                     $lint("{$ROOT}/api/audit_anomaly.php"));
$assert('admin-gated (master_admin/tenant_admin/admin)',
    stripos($api, "master_admin") !== false
    && stripos($api, "tenant_admin") !== false
    && stripos($api, "Forbidden") !== false);
$assert('only POST + action=spot allowed',              stripos($api, "Unknown method/action") !== false
                                                      && stripos($api, "'spot'") !== false);
$assert('hours param clamped to 1..168',                stripos($api, '$hours = 1') !== false
                                                      && stripos($api, '$hours = 168') !== false);
$assert('reads from audit_log table',                   stripos($api, 'FROM audit_log') !== false);
$assert('computes per-event counts',                    stripos($api, 'GROUP BY event') !== false);
$assert('off-hours uses HOUR(created_at)',              stripos($api, 'HOUR(created_at)') !== false);
$assert('mass-export looks at export/csv/download',     stripos($api, "'%export%'") !== false
                                                      && stripos($api, "'%csv%'") !== false
                                                      && stripos($api, "'%download%'") !== false);
$assert('top users limited to 3',                       stripos($api, 'ORDER BY cnt DESC') !== false
                                                      && stripos($api, 'LIMIT 3') !== false);
$assert('spike threshold uses median',                  stripos($api, '$median') !== false);
$assert('AI uses narrative feature_class',              stripos($api, "'narrative'") !== false);
$assert('AI feature_key namespaced to audit',           stripos($api, "'audit.anomaly.spotter'") !== false);
$assert('AI summary degrades gracefully on Throwable',  stripos($api, '$summary = \'\'') !== false
                                                      && stripos($api, 'catch (\\Throwable') !== false);
$assert('response includes signals envelope',           stripos($api, "'signals'") !== false
                                                      && stripos($api, "'spike_events'") !== false
                                                      && stripos($api, "'mass_export_users'") !== false
                                                      && stripos($api, "'off_hours_count'") !== false
                                                      && stripos($api, "'top_users'") !== false);

echo "\nAuditLogViewer.jsx — anomaly UI\n";
$ui = (string) file_get_contents("{$ROOT}/dashboard/src/pages/AuditLogViewer.jsx");
$assert('imports Sparkles icon',                        stripos($ui, 'Sparkles') !== false);
$assert('anomaly state hooks wired',                    strpos($ui, 'setAnomaly') !== false
                                                      && strpos($ui, 'anomalyLoading') !== false
                                                      && strpos($ui, 'anomalyHours') !== false);
$assert('runAnomalyCheck posts to audit_anomaly endpoint',
    strpos($ui, "/api/audit_anomaly.php?action=spot") !== false
    && strpos($ui, 'api.post') !== false);
$assert('hours selector testid present',                strpos($ui, 'data-testid="audit-anomaly-hours"') !== false);
$assert('run button testid present',                    strpos($ui, 'data-testid="audit-anomaly-run"') !== false);
$assert('advisory label present',                       stripos($ui, 'advisory only') !== false);
$assert('summary testid present',                       strpos($ui, 'data-testid="audit-anomaly-summary"') !== false);
$assert('empty-summary fallback testid present',        strpos($ui, 'data-testid="audit-anomaly-empty"') !== false);
$assert('signals chips rendered',                       strpos($ui, 'data-testid="audit-anomaly-signal-total"') !== false
                                                      && strpos($ui, 'data-testid="audit-anomaly-signal-offhours"') !== false
                                                      && strpos($ui, 'data-testid="audit-anomaly-signal-spikes"') !== false
                                                      && strpos($ui, 'data-testid="audit-anomaly-signal-mass-exports"') !== false);
$assert('spike list / mass-export list / top users rendered',
    strpos($ui, 'data-testid="audit-anomaly-spike-list"') !== false
    && strpos($ui, 'data-testid="audit-anomaly-mass-list"') !== false
    && strpos($ui, 'data-testid="audit-anomaly-top-users"') !== false);
$assert('error banner testid present',                  strpos($ui, 'data-testid="audit-anomaly-error"') !== false);
$assert('hours options cover 1h..7d',                   strpos($ui, 'value={1}') !== false
                                                      && strpos($ui, 'value={24}') !== false
                                                      && strpos($ui, 'value={168}') !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);

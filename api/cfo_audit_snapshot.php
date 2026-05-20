<?php
/**
 * /api/cfo_audit_snapshot.php — one-page "Audit Snapshot" payload.
 *
 *   GET ?from=YYYY-MM-DD&to=YYYY-MM-DD     # both optional, default last 90d
 *
 * Returns the minimum a printable audit one-pager needs that the existing
 * dashboards don't already surface:
 *
 *   {
 *     tenant: { id, name, logo_url, slug, subdomain },
 *     period: { from, to, label },
 *     prepared: { at, by },
 *     auditor_scope: { is_auditor, modules, expires_at },
 *     totals: {
 *       revenue_total,        # sum invoices in window
 *       collected_total,      # sum payments in window
 *       ap_total,             # sum bills in window
 *       ar_open,              # open invoices balance now
 *       ap_open,              # open bills balance now
 *       net_margin_pct        # (revenue - ap) / revenue * 100
 *     }
 *   }
 *
 * Auth: api_require_cfo() (master_admin, tenant_admin, admin, auditor,
 * or explicit `cfo` module grant).
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';

$ctx       = api_require_cfo();
$user      = $ctx['user'];
$tenantId  = (int) ($ctx['tenant_id'] ?? 0);

if (!$tenantId) api_error('No tenant context', 400);

$pdo = getDB();
if (!$pdo) api_error('No database connection', 500);

// Default window — last 90 days through today.
$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$from  = (string) api_query('from', (new DateTimeImmutable('today -90 days'))->format('Y-m-d'));
$to    = (string) api_query('to',   $today);

// Validate ISO dates (defensive; bad input → 422, not a silent fall-through).
foreach (['from' => $from, 'to' => $to] as $label => $v) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        api_error("Invalid date for '{$label}'", 422);
    }
}

// Tenant header — logo, name, slug.
$tStmt = $pdo->prepare(
    'SELECT id, name, slug, subdomain, logo_url
       FROM tenants
      WHERE id = :t LIMIT 1'
);
$tStmt->execute(['t' => $tenantId]);
$tenant = $tStmt->fetch(PDO::FETCH_ASSOC);
if (!$tenant) api_error('Tenant not found', 404);

// ----------------------------------------------------------------- TOTALS ---
// Each query is wrapped in a try/catch so a missing module table doesn't
// 500 the whole snapshot — the section just renders as "N/A" on the page.
$totals = [
    'revenue_total'   => null,
    'collected_total' => null,
    'ap_total'        => null,
    'ar_open'         => null,
    'ap_open'         => null,
    'net_margin_pct'  => null,
];

try {
    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(total_amount), 0) AS s
           FROM billing_invoices
          WHERE tenant_id = :t AND issue_date BETWEEN :f AND :tt'
    );
    $st->execute(['t' => $tenantId, 'f' => $from, 'tt' => $to]);
    $totals['revenue_total'] = (float) $st->fetchColumn();
} catch (\Throwable $_) { /* leave null */ }

try {
    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(amount), 0) AS s
           FROM billing_invoice_payments
          WHERE tenant_id = :t AND received_at BETWEEN :f AND :tt'
    );
    $st->execute(['t' => $tenantId, 'f' => $from, 'tt' => $to]);
    $totals['collected_total'] = (float) $st->fetchColumn();
} catch (\Throwable $_) { /* leave null */ }

try {
    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(amount), 0) AS s
           FROM ap_bills
          WHERE tenant_id = :t AND bill_date BETWEEN :f AND :tt'
    );
    $st->execute(['t' => $tenantId, 'f' => $from, 'tt' => $to]);
    $totals['ap_total'] = (float) $st->fetchColumn();
} catch (\Throwable $_) { /* leave null */ }

try {
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(total_amount - COALESCE(paid_amount,0)), 0)
           FROM billing_invoices
          WHERE tenant_id = :t AND status NOT IN ('paid','void','cancelled')"
    );
    $st->execute(['t' => $tenantId]);
    $totals['ar_open'] = (float) $st->fetchColumn();
} catch (\Throwable $_) { /* leave null */ }

try {
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(amount - COALESCE(amount_paid,0)), 0)
           FROM ap_bills
          WHERE tenant_id = :t AND status NOT IN ('paid','void')"
    );
    $st->execute(['t' => $tenantId]);
    $totals['ap_open'] = (float) $st->fetchColumn();
} catch (\Throwable $_) { /* leave null */ }

if ($totals['revenue_total'] !== null && $totals['ap_total'] !== null && $totals['revenue_total'] > 0) {
    $totals['net_margin_pct'] = round(
        (($totals['revenue_total'] - $totals['ap_total']) / $totals['revenue_total']) * 100, 1
    );
}

// ------------------------------------------------------ AUDITOR SCOPE ---
$auditorMode  = !empty($_SESSION['auditor_mode']);
$auditorScope = [
    'is_auditor' => $auditorMode,
    'modules'    => $auditorMode ? ($_SESSION['auditor_modules'] ?? null) : null,
    'expires_at' => $auditorMode ? ($_SESSION['auditor_expires_at'] ?? null) : null,
];

// Format date label — "May 1 – Aug 1, 2026" or year-over-year style if it spans.
$fLabel = (new DateTimeImmutable($from))->format('M j, Y');
$tLabel = (new DateTimeImmutable($to))->format('M j, Y');
$periodLabel = $fLabel . ' – ' . $tLabel;

api_ok([
    'tenant'        => [
        'id'        => (int) $tenant['id'],
        'name'      => $tenant['name'],
        'slug'      => $tenant['slug'] ?? null,
        'subdomain' => $tenant['subdomain'] ?? null,
        'logo_url'  => $tenant['logo_url'] ?? null,
    ],
    'period'        => ['from' => $from, 'to' => $to, 'label' => $periodLabel],
    'prepared'      => [
        'at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        'by' => $auditorMode
            ? 'External Auditor (token session)'
            : ($user['email'] ?? $user['name'] ?? 'CoreFlux user'),
    ],
    'auditor_scope' => $auditorScope,
    'totals'        => $totals,
]);

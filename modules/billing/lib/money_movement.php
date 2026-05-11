<?php
/**
 * Weekly Money Movement digest — CFO inbox edition.
 *
 * Pulls a 7-day snapshot of cash in / cash out / past-due / runway from
 * data the platform already collects. Pure functions for testability —
 * every query is in its own helper so the smoke test can swap any of
 * them out without touching the renderer.
 *
 * Sections (each tolerates missing tables on minimal installs):
 *   1. Cash IN  last week  — billing_payments.amount where received_at ≥ start
 *   2. Cash OUT last week  — ap_payments.amount where pay_date ≥ start AND status NOT IN (draft, void, failed)
 *   3. AR statements sent  — count from audit_log (event = billing.statement.sent)
 *   4. Dunning notices     — count from billing_dunning_log where status=sent
 *   5. Top-5 past-due clients — billingComputeAging() reduced to total past-due
 *   6. Runway-to-zero      — read-through to liquidity_forecast.php API (if present)
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/billing.php';

/**
 * Build the full snapshot for $tenantId over the 7-day window
 * ending on $asOf (inclusive). $asOf defaults to today.
 */
function moneyMovementSnapshot(int $tenantId, ?string $asOf = null): array
{
    $asOf  = $asOf ?: date('Y-m-d');
    $start = date('Y-m-d', strtotime($asOf . ' -6 days')); // 7 inclusive days
    return [
        'tenant_id'        => $tenantId,
        'as_of'            => $asOf,
        'window_start'     => $start,
        'window_end'       => $asOf,
        'cash_in'          => moneyMovementCashIn($tenantId, $start, $asOf),
        'cash_out'         => moneyMovementCashOut($tenantId, $start, $asOf),
        'statements_sent'  => moneyMovementStatementsSent($tenantId, $start, $asOf),
        'dunning_sent'     => moneyMovementDunningSent($tenantId, $start, $asOf),
        'top_past_due'     => moneyMovementTopPastDue($tenantId, $asOf, 5),
        'runway'           => moneyMovementRunway($tenantId, $asOf),
    ];
}

/** Inbound payments: sum + count + by-method breakdown. */
function moneyMovementCashIn(int $tenantId, string $start, string $end): array
{
    $out = ['total' => 0.0, 'count' => 0, 'by_method' => []];
    try {
        $st = getDB()->prepare(
            'SELECT method, SUM(amount) AS total, COUNT(*) AS n
               FROM billing_payments
              WHERE tenant_id = :t AND received_at BETWEEN :s AND :e
              GROUP BY method'
        );
        $st->execute(['t' => $tenantId, 's' => $start, 'e' => $end]);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
            $out['total']    += (float) $r['total'];
            $out['count']    += (int)   $r['n'];
            $out['by_method'][(string) $r['method']] = ['amount' => (float) $r['total'], 'count' => (int) $r['n']];
        }
    } catch (\Throwable $_) { /* table missing on minimal install */ }
    return $out;
}

/** Outbound AP payments that actually moved money (excludes draft / void / failed). */
function moneyMovementCashOut(int $tenantId, string $start, string $end): array
{
    $out = ['total' => 0.0, 'count' => 0, 'by_method' => []];
    try {
        $st = getDB()->prepare(
            "SELECT method, SUM(amount) AS total, COUNT(*) AS n
               FROM ap_payments
              WHERE tenant_id = :t AND pay_date BETWEEN :s AND :e
                AND status NOT IN ('draft','void','failed')
              GROUP BY method"
        );
        $st->execute(['t' => $tenantId, 's' => $start, 'e' => $end]);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
            $out['total']    += (float) $r['total'];
            $out['count']    += (int)   $r['n'];
            $out['by_method'][(string) $r['method']] = ['amount' => (float) $r['total'], 'count' => (int) $r['n']];
        }
    } catch (\Throwable $_) { /* table missing on minimal install */ }
    return $out;
}

function moneyMovementStatementsSent(int $tenantId, string $start, string $end): int
{
    try {
        $st = getDB()->prepare(
            "SELECT COUNT(*) FROM audit_log
              WHERE tenant_id = :t AND event = 'billing.statement.sent'
                AND created_at BETWEEN :s AND DATE_ADD(:e, INTERVAL 1 DAY)"
        );
        $st->execute(['t' => $tenantId, 's' => $start . ' 00:00:00', 'e' => $end]);
        return (int) $st->fetchColumn();
    } catch (\Throwable $_) { return 0; }
}

function moneyMovementDunningSent(int $tenantId, string $start, string $end): int
{
    try {
        $st = getDB()->prepare(
            "SELECT COUNT(*) FROM billing_dunning_log
              WHERE tenant_id = :t AND status = 'sent'
                AND created_at BETWEEN :s AND DATE_ADD(:e, INTERVAL 1 DAY)"
        );
        $st->execute(['t' => $tenantId, 's' => $start . ' 00:00:00', 'e' => $end]);
        return (int) $st->fetchColumn();
    } catch (\Throwable $_) { return 0; }
}

/**
 * Top $limit clients ranked by past-due balance (bucket_1_30 + 31_60 + 61_90 + 91_plus).
 * Reuses billingComputeAging() so the digest matches the AR Aging page exactly.
 */
function moneyMovementTopPastDue(int $tenantId, string $asOf, int $limit = 5): array
{
    try {
        $rows = billingComputeAging($tenantId, $asOf);
    } catch (\Throwable $_) { return []; }
    $ranked = [];
    foreach ($rows as $r) {
        $past = (float) ($r['bucket_1_30'] ?? 0)
              + (float) ($r['bucket_31_60'] ?? 0)
              + (float) ($r['bucket_61_90'] ?? 0)
              + (float) ($r['bucket_91_plus'] ?? 0);
        if ($past <= 0.005) continue;
        $ranked[] = [
            'client_name'    => (string) $r['client_name'],
            'past_due_total' => $past,
            'bucket_91_plus' => (float) ($r['bucket_91_plus'] ?? 0),
            'total_due'      => (float) ($r['total_due'] ?? $past),
        ];
    }
    usort($ranked, fn ($a, $b) => $b['past_due_total'] <=> $a['past_due_total']);
    return array_slice($ranked, 0, $limit);
}

/**
 * Runway-to-zero from the liquidity forecast — defaults to "no runway data"
 * when the treasury module is not installed.
 *
 * Returns ['days' => int|null, 'projected_zero_date' => string|null, 'note' => string].
 */
function moneyMovementRunway(int $tenantId, string $asOf): array
{
    $libPath = __DIR__ . '/../../../core/treasury/liquidity_projection.php';
    if (!is_file($libPath)) return ['days' => null, 'projected_zero_date' => null, 'note' => 'treasury module not installed'];
    try {
        require_once $libPath;
        if (!function_exists('treasuryLiquidityForecast')) return ['days' => null, 'projected_zero_date' => null, 'note' => 'forecast helper missing'];
        $f = treasuryLiquidityForecast($tenantId, $asOf, 90);
        $days = $f['runway_to_zero_days'] ?? null;
        return [
            'days'                => is_int($days) ? $days : null,
            'projected_zero_date' => $f['projected_zero_date'] ?? null,
            'note'                => $days === null ? 'no runway risk in 90d window' : 'projected to go negative',
        ];
    } catch (\Throwable $_) {
        return ['days' => null, 'projected_zero_date' => null, 'note' => 'forecast unavailable'];
    }
}

/**
 * Render the digest email. Returns ['subject','html','text'].
 *
 * Tenant name + recipient name are caller-supplied (we don't reach back
 * to the DB inside the renderer — keeps it pure and unit-testable).
 */
function moneyMovementRenderEmail(array $snapshot, string $tenantName, string $recipientName = ''): array
{
    $h     = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $money = fn ($n) => '$' . number_format((float) $n, 0);
    $start = (string) $snapshot['window_start'];
    $end   = (string) $snapshot['window_end'];
    $in    = $snapshot['cash_in']['total']  ?? 0.0;
    $out   = $snapshot['cash_out']['total'] ?? 0.0;
    $net   = $in - $out;
    $arrow = $net >= 0 ? '▲' : '▼';
    $netCol = $net >= 0 ? '#16a34a' : '#dc2626';

    $subject = "Money movement — net " . ($net >= 0 ? '+' : '−') . $money(abs($net))
             . " for the week of " . date('M j', strtotime($start));

    /* Top past-due rows */
    $pdRows = '';
    foreach ($snapshot['top_past_due'] ?? [] as $r) {
        $pdRows .= '<tr>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb">' . $h($r['client_name']) . '</td>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:right;font-variant-numeric:tabular-nums">' . $h($money($r['past_due_total'])) . '</td>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:right;color:' . ($r['bucket_91_plus'] > 0 ? '#b91c1c' : '#475569') . '">' . $h($money($r['bucket_91_plus'])) . '</td>'
                . '</tr>';
    }
    if ($pdRows === '') {
        $pdRows = '<tr><td colspan="3" style="padding:10px 8px;color:#16a34a;text-align:center">No past-due AR. Nice.</td></tr>';
    }

    /* Runway block */
    $runway = $snapshot['runway'] ?? [];
    $runwayBlock = isset($runway['days']) && is_int($runway['days'])
        ? '<p style="margin:0;color:#b91c1c;font-weight:600;font-size:14px">Projected to go negative in ' . (int) $runway['days'] . ' days (' . $h($runway['projected_zero_date'] ?? '') . ').</p>'
        : '<p style="margin:0;color:#16a34a;font-size:14px">' . $h($runway['note'] ?? 'no runway risk in 90d window') . '</p>';

    $greeting = $recipientName !== '' ? "Hi {$recipientName}," : 'Money movement digest';

    $html  = '<div style="font-family:system-ui;max-width:680px;margin:0 auto;padding:24px;color:#0f172a">'
           . '<h2 style="margin:0 0 4px">' . $h($greeting) . '</h2>'
           . '<p style="margin:0 0 20px;color:#64748b;font-size:13px">' . $h($tenantName) . ' &middot; ' . $h($start) . ' → ' . $h($end) . '</p>'

           // Big number
           . '<div style="background:#f8fafc;border-radius:10px;padding:18px;margin-bottom:18px;text-align:center">'
           . '<div style="font-size:13px;color:#64748b">Net movement this week</div>'
           . '<div style="font-size:28px;font-weight:700;color:' . $netCol . '">' . $arrow . ' ' . $h($money(abs($net))) . '</div>'
           . '<div style="font-size:13px;color:#64748b;margin-top:6px">'
           . 'In ' . $h($money($in)) . ' &middot; Out ' . $h($money($out))
           . '</div></div>'

           // Activity grid
           . '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:18px">'
           . '<tr>'
           . '<td style="background:#f1f5f9;padding:10px;border-radius:6px 0 0 6px;text-align:center"><div style="font-size:11px;color:#64748b">Collections</div><div style="font-size:18px;font-weight:600">' . (int) ($snapshot['cash_in']['count'] ?? 0)  . '</div></td>'
           . '<td style="width:8px"></td>'
           . '<td style="background:#f1f5f9;padding:10px;text-align:center"><div style="font-size:11px;color:#64748b">AP runs</div><div style="font-size:18px;font-weight:600">' . (int) ($snapshot['cash_out']['count'] ?? 0) . '</div></td>'
           . '<td style="width:8px"></td>'
           . '<td style="background:#f1f5f9;padding:10px;text-align:center"><div style="font-size:11px;color:#64748b">Statements sent</div><div style="font-size:18px;font-weight:600">' . (int) ($snapshot['statements_sent'] ?? 0) . '</div></td>'
           . '<td style="width:8px"></td>'
           . '<td style="background:#f1f5f9;padding:10px;border-radius:0 6px 6px 0;text-align:center"><div style="font-size:11px;color:#64748b">Dunning sent</div><div style="font-size:18px;font-weight:600">' . (int) ($snapshot['dunning_sent'] ?? 0) . '</div></td>'
           . '</tr></table>'

           // Top past-due
           . '<h3 style="margin:24px 0 8px;font-size:15px">Top past-due clients</h3>'
           . '<table style="width:100%;border-collapse:collapse;font-size:13px;background:#fff;border:1px solid #e5e7eb;border-radius:6px;overflow:hidden">'
           . '<thead><tr style="background:#f1f5f9">'
           . '<th style="text-align:left;padding:6px 8px">Client</th>'
           . '<th style="text-align:right;padding:6px 8px">Past due</th>'
           . '<th style="text-align:right;padding:6px 8px">91+ d</th>'
           . '</tr></thead><tbody>' . $pdRows . '</tbody></table>'

           // Runway
           . '<h3 style="margin:24px 0 8px;font-size:15px">Runway</h3>'
           . $runwayBlock

           . '<p style="margin-top:32px;color:#94a3b8;font-size:12px">— ' . $h($tenantName) . ' Finance Ops</p>'
           . '</div>';

    /* Plain-text fallback */
    $text  = "{$greeting}\n\n"
           . "{$tenantName} — money movement digest\n"
           . "{$start} → {$end}\n\n"
           . sprintf("Net:        %s%s\n", $net >= 0 ? '+' : '-', $money(abs($net)))
           . sprintf("Cash in:    %s (%d collections)\n",  $money($in),  (int) ($snapshot['cash_in']['count']  ?? 0))
           . sprintf("Cash out:   %s (%d AP runs)\n",      $money($out), (int) ($snapshot['cash_out']['count'] ?? 0))
           . sprintf("Statements: %d sent\n",              (int) ($snapshot['statements_sent'] ?? 0))
           . sprintf("Dunning:    %d sent\n\n",            (int) ($snapshot['dunning_sent'] ?? 0))
           . "Top past-due clients:\n";
    foreach ($snapshot['top_past_due'] ?? [] as $r) {
        $text .= sprintf("  %-30s %s (91+: %s)\n", $r['client_name'], $money($r['past_due_total']), $money($r['bucket_91_plus']));
    }
    if (empty($snapshot['top_past_due'])) $text .= "  (none — nice)\n";
    $text .= "\nRunway: " . (isset($runway['days']) && is_int($runway['days'])
        ? "negative in {$runway['days']} days ({$runway['projected_zero_date']})\n"
        : ($runway['note'] ?? 'no runway risk') . "\n");
    $text .= "\n— {$tenantName} Finance Ops\n";

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}

/**
 * Resolve CFO inbox recipients for $tenantId.
 *
 * Heuristic: anyone with role/global_role containing 'cfo', 'controller',
 * 'admin' or 'master_admin' on this tenant. Falls back to the simpler
 * users.role-only schema if user_tenants isn't present.
 */
function moneyMovementResolveRecipients(\PDO $pdo, int $tenantId): array
{
    $cfoRoles = ['cfo', 'controller', 'admin', 'master_admin', 'tenant_admin'];
    try {
        $st = $pdo->prepare(
            "SELECT DISTINCT u.id, u.name, u.email
               FROM users u
               LEFT JOIN user_tenants ut ON ut.user_id = u.id AND ut.tenant_id = :t
              WHERE u.email IS NOT NULL AND u.email <> ''
                AND (
                  ut.role IN ('" . implode("','", $cfoRoles) . "')
                  OR u.role  IN ('" . implode("','", $cfoRoles) . "')
                )"
        );
        $st->execute(['t' => $tenantId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $_) {
        try {
            $st = $pdo->prepare(
                "SELECT id, name, email FROM users
                  WHERE tenant_id = :t AND email IS NOT NULL AND email <> ''
                    AND role IN ('" . implode("','", $cfoRoles) . "')"
            );
            $st->execute(['t' => $tenantId]);
            return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $__) { return []; }
    }
}

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
require_once __DIR__ . '/../../../core/tenant_branding.php';
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
function moneyMovementRenderEmail(array $snapshot, string $tenantName, string $recipientName = '', ?array $branding = null, ?array $wow = null): array
{
    $h     = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $money = fn ($n) => '$' . number_format((float) $n, 0);
    if ($branding === null) {
        $tid = (int) ($snapshot['tenant_id'] ?? 0);
        $branding = $tid > 0 ? cf_tenant_branding($tid) : ['logo_url' => null, 'accent_color' => '#0f172a', 'signature_html' => '', 'show_powered_by' => true];
    }
    $accent = (string) ($branding['accent_color'] ?? '#0f172a');
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

    /* WoW delta strip (optional) */
    $wowBlock = '';
    if (is_array($wow) && !empty($wow['available'])) {
        $netDelta = (float) ($wow['net']['delta'] ?? 0);
        $netPct   = $wow['net']['pct'] ?? null;
        $deltaCol = $netDelta >= 0 ? '#16a34a' : '#dc2626';
        $sign     = $netDelta >= 0 ? '+' : '−';
        $pctStr   = $netPct !== null ? sprintf(' (%s%.0f%%)', $netDelta >= 0 ? '+' : '', $netPct) : '';
        $wowBlock = '<p style="margin:6px 0 0;font-size:13px;color:' . $deltaCol . '">'
                  . 'WoW: ' . $sign . $h($money(abs($netDelta))) . $pctStr
                  . '<span style="color:#94a3b8"> vs ' . $h($wow['prior_as_of'] ?? '—') . '</span></p>';
    }

    $html  = '<div style="font-family:system-ui;max-width:680px;margin:0 auto;padding:24px;color:#0f172a">'
           . cf_branding_header_html($branding, $greeting)
           . '<p style="margin:0 0 20px;color:#64748b;font-size:13px">' . $h($tenantName) . ' &middot; ' . $h($start) . ' → ' . $h($end) . '</p>'

           // Big number
           . '<div style="background:#f8fafc;border-radius:10px;padding:18px;margin-bottom:18px;text-align:center;border-top:3px solid ' . $h($accent) . '">'
           . '<div style="font-size:13px;color:#64748b">Net movement this week</div>'
           . '<div style="font-size:28px;font-weight:700;color:' . $netCol . '">' . $arrow . ' ' . $h($money(abs($net))) . '</div>'
           . '<div style="font-size:13px;color:#64748b;margin-top:6px">'
           . 'In ' . $h($money($in)) . ' &middot; Out ' . $h($money($out))
           . '</div>'
           . $wowBlock
           . '</div>'

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
           . '<h3 style="margin:24px 0 8px;font-size:15px;color:' . $h($accent) . '">Top past-due clients</h3>'
           . '<table style="width:100%;border-collapse:collapse;font-size:13px;background:#fff;border:1px solid #e5e7eb;border-radius:6px;overflow:hidden">'
           . '<thead><tr style="background:#f1f5f9">'
           . '<th style="text-align:left;padding:6px 8px">Client</th>'
           . '<th style="text-align:right;padding:6px 8px">Past due</th>'
           . '<th style="text-align:right;padding:6px 8px">91+ d</th>'
           . '</tr></thead><tbody>' . $pdRows . '</tbody></table>'

           // Runway
           . '<h3 style="margin:24px 0 8px;font-size:15px;color:' . $h($accent) . '">Runway</h3>'
           . $runwayBlock

           . cf_branding_footer_html($branding, $tenantName)
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

/* ─────────────────────  Snapshot history (A1)  ───────────────────── */

/**
 * Persist a snapshot for week-over-week comparison + share-link replay.
 * Idempotent on (tenant_id, as_of).
 */
function moneyMovementWriteSnapshot(array $snapshot): void
{
    try {
        getDB()->prepare(
            'INSERT INTO tenant_money_movement_snapshots
                (tenant_id, as_of, window_start, window_end, cash_in, cash_out, net_movement, snapshot_json)
             VALUES (:t, :a, :s, :e, :ci, :co, :nm, :j)
             ON DUPLICATE KEY UPDATE
                window_start  = VALUES(window_start),
                window_end    = VALUES(window_end),
                cash_in       = VALUES(cash_in),
                cash_out      = VALUES(cash_out),
                net_movement  = VALUES(net_movement),
                snapshot_json = VALUES(snapshot_json)'
        )->execute([
            't'  => (int) $snapshot['tenant_id'],
            'a'  => $snapshot['as_of'],
            's'  => $snapshot['window_start'],
            'e'  => $snapshot['window_end'],
            'ci' => (float) ($snapshot['cash_in']['total']  ?? 0),
            'co' => (float) ($snapshot['cash_out']['total'] ?? 0),
            'nm' => (float) (($snapshot['cash_in']['total'] ?? 0) - ($snapshot['cash_out']['total'] ?? 0)),
            'j'  => json_encode($snapshot),
        ]);
    } catch (\Throwable $_) { /* migration may not be applied yet */ }
}

/** Return the snapshot for the immediately preceding 7-day window, or null. */
function moneyMovementGetPriorSnapshot(int $tenantId, string $asOf): ?array
{
    try {
        $st = getDB()->prepare(
            'SELECT snapshot_json FROM tenant_money_movement_snapshots
              WHERE tenant_id = :t AND as_of < :a
           ORDER BY as_of DESC LIMIT 1'
        );
        $st->execute(['t' => $tenantId, 'a' => $asOf]);
        $row = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$row) return null;
        $decoded = json_decode((string) $row['snapshot_json'], true);
        return is_array($decoded) ? $decoded : null;
    } catch (\Throwable $_) { return null; }
}

/** List the most recent $limit snapshots for $tenantId, newest first. */
function moneyMovementListSnapshots(int $tenantId, int $limit = 12): array
{
    try {
        $st = getDB()->prepare(
            'SELECT as_of, window_start, window_end, cash_in, cash_out, net_movement
               FROM tenant_money_movement_snapshots
              WHERE tenant_id = :t
           ORDER BY as_of DESC LIMIT ' . max(1, min(52, $limit))
        );
        $st->execute(['t' => $tenantId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $_) { return []; }
}

/** Read one snapshot by exact as_of (used by share link + archive detail). */
function moneyMovementReadSnapshot(int $tenantId, string $asOf): ?array
{
    try {
        $st = getDB()->prepare(
            'SELECT snapshot_json FROM tenant_money_movement_snapshots
              WHERE tenant_id = :t AND as_of = :a LIMIT 1'
        );
        $st->execute(['t' => $tenantId, 'a' => $asOf]);
        $row = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$row) return null;
        $decoded = json_decode((string) $row['snapshot_json'], true);
        return is_array($decoded) ? $decoded : null;
    } catch (\Throwable $_) { return null; }
}

/** Compute net + cash_in + cash_out delta vs prior snapshot. */
function moneyMovementWowDelta(array $current, ?array $prior): array
{
    if (!$prior) return ['available' => false];
    $curIn  = (float) ($current['cash_in']['total']  ?? 0);
    $prIn   = (float) ($prior['cash_in']['total']    ?? 0);
    $curOut = (float) ($current['cash_out']['total'] ?? 0);
    $prOut  = (float) ($prior['cash_out']['total']   ?? 0);
    $curNet = $curIn - $curOut;
    $prNet  = $prIn  - $prOut;
    $pct = function (float $cur, float $pr): ?float {
        if (abs($pr) < 0.005) return null;
        return ($cur - $pr) / abs($pr) * 100.0;
    };
    return [
        'available'   => true,
        'prior_as_of' => $prior['as_of'] ?? null,
        'cash_in'     => ['delta' => $curIn  - $prIn,  'pct' => $pct($curIn,  $prIn)],
        'cash_out'    => ['delta' => $curOut - $prOut, 'pct' => $pct($curOut, $prOut)],
        'net'         => ['delta' => $curNet - $prNet, 'pct' => $pct($curNet, $prNet)],
    ];
}

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

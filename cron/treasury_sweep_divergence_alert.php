<?php
/**
 * Treasury Sweep Divergence Alert (cron driver).
 *
 * Cron: 0 9 * * * php /home/master/applications/<app>/public_html/cron/treasury_sweep_divergence_alert.php
 *  (09:00 daily — 30 minutes after the sweep worker fires)
 *
 * Builds a daily summary of the previous calendar day's sweep
 * evaluations per tenant and emails the CFO/finance admins:
 *   • Per-rule outcome (swept / under-floor / failed)
 *   • DRY-RUN: planned amounts vs nothing happening — surfaces drift
 *     between the engine's intent and operator expectation BEFORE
 *     money moves.
 *   • LIVE: amounts actually swept, with PI links for trace.
 *   • Go-live recommendation: clean streak detection — N consecutive
 *     days of zero failures in dry-run mode is the strongest possible
 *     evidence to flip TREASURY_SWEEP_LIVE=1.
 *
 * If `mailerSend()` is unavailable (smoke/test env), the worker still
 * computes the summary and logs it — operators wire the actual mail
 * driver in production via core/mailer.php / Resend.
 *
 * RBAC implicit: cron has full DB access; recipient selection limits
 * delivery to the tenant's master_admin / finance_admin / cfo users.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
if (is_file(__DIR__ . '/../core/mailer.php')) {
    require_once __DIR__ . '/../core/mailer.php';
}

$now = new \DateTimeImmutable('now');
$yesterday = $now->modify('-1 day');
$dayStart = $yesterday->format('Y-m-d 00:00:00');
$dayEnd   = $yesterday->format('Y-m-d 23:59:59');

fwrite(STDOUT, "[treasury_sweep_divergence] checking " . $yesterday->format('Y-m-d') . "\n");

$pdo = getDB();

// Pull every tenant that had at least one sweep evaluation yesterday.
// We DON'T pre-filter by outcome — operators want to know if the
// worker was silent too (zero rows = scheduling problem worth flagging).
try {
    $tenantStmt = $pdo->prepare(
        'SELECT DISTINCT tenant_id FROM treasury_sweep_runs
          WHERE ran_at BETWEEN :a AND :b'
    );
    $tenantStmt->execute(['a' => $dayStart, 'b' => $dayEnd]);
    $tenantIds = array_map('intval', array_column($tenantStmt->fetchAll(\PDO::FETCH_ASSOC), 'tenant_id'));
} catch (\Throwable $e) {
    fwrite(STDERR, "[treasury_sweep_divergence] migration 074 not applied? — {$e->getMessage()}\n");
    exit(0); // soft-exit so cron keeps trying once the migration lands
}

if (!$tenantIds) {
    fwrite(STDOUT, "[treasury_sweep_divergence] no tenants had sweep activity yesterday — nothing to send\n");
    exit(0);
}

$summarySent = 0; $summarySkipped = 0;

foreach ($tenantIds as $tenantId) {
    // Pull yesterday's evaluations for this tenant, joined to rule names.
    $runs = $pdo->prepare(
        'SELECT r.id, r.rule_id, sr.name AS rule_name,
                r.ran_at, r.source_balance_cents, r.sweep_amount_cents,
                r.outcome, r.dry_run, r.payment_instruction_id, r.error_message
           FROM treasury_sweep_runs r
      LEFT JOIN tenant_sweep_rules sr ON sr.id = r.rule_id AND sr.tenant_id = r.tenant_id
          WHERE r.tenant_id = :t AND r.ran_at BETWEEN :a AND :b
          ORDER BY r.ran_at ASC'
    );
    $runs->execute(['t' => $tenantId, 'a' => $dayStart, 'b' => $dayEnd]);
    $rows = $runs->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    if (!$rows) { $summarySkipped++; continue; }

    // Roll up per-outcome.
    $counts = ['swept' => 0, 'skipped_under_floor' => 0, 'skipped_not_due' => 0,
               'skipped_disabled' => 0, 'failed_no_connection' => 0,
               'failed_balance_fetch' => 0, 'failed_execute' => 0];
    $sweptDryRun = 0; $sweptLive = 0; $failures = [];
    foreach ($rows as $r) {
        $o = (string) $r['outcome'];
        if (!isset($counts[$o])) $counts[$o] = 0;
        $counts[$o]++;
        if ($o === 'swept') {
            if ((int) $r['dry_run'] === 1) $sweptDryRun += (int) $r['sweep_amount_cents'];
            else                            $sweptLive   += (int) $r['sweep_amount_cents'];
        }
        if (str_starts_with($o, 'failed_')) $failures[] = $r;
    }

    $failCount = count($failures);
    $isDryRun  = array_sum(array_column($rows, 'dry_run')) === count($rows);

    // Compute go-live readiness streak: count consecutive prior days
    // (up to 14) with zero failures, all-dry-run. >= 7 clean days is
    // the recommendation threshold.
    $streak = 0;
    if ($isDryRun && $failCount === 0) {
        for ($i = 1; $i <= 14; $i++) {
            $d = $now->modify("-{$i} day");
            $st = $pdo->prepare(
                "SELECT
                    SUM(CASE WHEN outcome LIKE 'failed_%' THEN 1 ELSE 0 END) AS fails,
                    SUM(CASE WHEN dry_run=0 THEN 1 ELSE 0 END) AS live_rows,
                    COUNT(*) AS total
                   FROM treasury_sweep_runs
                  WHERE tenant_id = :t
                    AND ran_at BETWEEN :a AND :b"
            );
            $st->execute([
                't' => $tenantId,
                'a' => $d->format('Y-m-d 00:00:00'),
                'b' => $d->format('Y-m-d 23:59:59'),
            ]);
            $h = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$h || (int) $h['total'] === 0) break;          // no activity → streak ends
            if ((int) $h['fails']     > 0)      break;          // failure → streak ends
            if ((int) $h['live_rows'] > 0)      break;          // streak is dry-run-clean specifically
            $streak++;
        }
    }
    // Today's day counts too.
    $streakIncludingToday = ($isDryRun && $failCount === 0) ? $streak + 1 : 0;

    // Resolve recipients: master_admin + finance_admin members of this
    // tenant. user_tenants is the legacy table; tenant_memberships
    // would be the modern source once backfill is complete.
    $recipStmt = $pdo->prepare(
        "SELECT DISTINCT u.email
           FROM users u
           JOIN user_tenants ut ON ut.user_id = u.id
          WHERE ut.tenant_id = :t
            AND ut.status    = 'active'
            AND ut.role IN ('master_admin','tenant_admin','finance_admin','cfo')
            AND u.email IS NOT NULL AND u.email <> ''"
    );
    $recipStmt->execute(['t' => $tenantId]);
    $recipients = array_filter(array_column($recipStmt->fetchAll(\PDO::FETCH_ASSOC), 'email'));
    if (!$recipients) {
        fwrite(STDOUT, "[treasury_sweep_divergence] tenant={$tenantId} has no finance admin — skipped\n");
        $summarySkipped++;
        continue;
    }

    // Build the summary email.
    $subject = sprintf(
        '[Treasury Sweep] %s — %d evaluations · %s',
        $yesterday->format('M j, Y'),
        count($rows),
        $failCount > 0 ? "{$failCount} failures" : ($isDryRun ? 'all dry-run' : 'live')
    );

    $rowHtml = '';
    foreach ($rows as $r) {
        $amt = number_format(((int) $r['sweep_amount_cents']) / 100, 2);
        $bal = $r['source_balance_cents'] !== null
            ? '$' . number_format(((int) $r['source_balance_cents']) / 100, 2)
            : '—';
        $ranAt = $r['ran_at'];
        $outcomeBadge = $r['outcome'];
        $mode = ((int) $r['dry_run'] === 1) ? 'DRY-RUN' : 'LIVE';
        $note = $r['error_message']
            ? htmlspecialchars((string) $r['error_message'])
            : ($r['payment_instruction_id'] ? 'PI #' . (int) $r['payment_instruction_id'] : '—');
        $rowHtml .= "<tr><td>{$ranAt}</td><td>" . htmlspecialchars((string) ($r['rule_name'] ?? '#' . $r['rule_id'])) . "</td>"
                  . "<td style=\"text-align:right\">{$bal}</td><td style=\"text-align:right\">\${$amt}</td>"
                  . "<td>{$outcomeBadge}</td><td>{$mode}</td><td>{$note}</td></tr>";
    }

    $recBanner = '';
    if ($streakIncludingToday >= 7) {
        $recBanner = '<div style="padding:12px;background:#dcfce7;border:1px solid #bbf7d0;color:#15803d;border-radius:6px;margin-bottom:12px">'
                   . "<strong>Go-live ready:</strong> {$streakIncludingToday} consecutive days of clean dry-run evaluations. "
                   . 'Recommend flipping <code>TREASURY_SWEEP_LIVE=1</code> when the destination counterparty mappings are pushed.</div>';
    } elseif ($failCount > 0) {
        $recBanner = '<div style="padding:12px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:6px;margin-bottom:12px">'
                   . "<strong>Action required:</strong> {$failCount} failed evaluation(s). "
                   . 'Investigate before any live execution.</div>';
    } elseif ($streakIncludingToday > 0) {
        $recBanner = '<div style="padding:12px;background:#fef3c7;border:1px solid #fde68a;color:#92400e;border-radius:6px;margin-bottom:12px">'
                   . "<strong>Building go-live evidence:</strong> {$streakIncludingToday} consecutive clean dry-run day(s). "
                   . 'Need 7+ for the go-live recommendation.</div>';
    }

    $bodyHtml = '<div style="font-family:system-ui,sans-serif;font-size:14px;line-height:1.5">'
              . "<h2 style=\"margin-top:0\">Treasury Sweep — {$yesterday->format('M j, Y')}</h2>"
              . $recBanner
              . '<table style="border-collapse:collapse;width:100%;font-size:12px">'
              . '<thead><tr style="background:#f1f5f9;text-align:left">'
              . '<th style="padding:6px">Ran at</th><th style="padding:6px">Rule</th>'
              . '<th style="padding:6px;text-align:right">Source balance</th>'
              . '<th style="padding:6px;text-align:right">Amount</th>'
              . '<th style="padding:6px">Outcome</th><th style="padding:6px">Mode</th>'
              . '<th style="padding:6px">Notes</th></tr></thead>'
              . "<tbody>{$rowHtml}</tbody></table>"
              . '<p style="color:#64748b;font-size:12px;margin-top:12px">Planned (dry-run) total: $'
              . number_format($sweptDryRun / 100, 2) . ' · Swept (live) total: $'
              . number_format($sweptLive / 100, 2) . '</p>'
              . '</div>';

    if (function_exists('mailerSend')) {
        try {
            mailerSend([
                'to'        => array_values($recipients),
                'subject'   => $subject,
                'body_html' => $bodyHtml,
                'tenant_id' => $tenantId,
                'module'    => 'treasury',
                'purpose'   => 'treasury_sweep_divergence',
            ]);
            $summarySent++;
            fwrite(STDOUT, "[treasury_sweep_divergence] tenant={$tenantId} → " . count($recipients) . " recipients · {$subject}\n");
        } catch (\Throwable $e) {
            fwrite(STDERR, "[treasury_sweep_divergence] tenant={$tenantId} mailerSend failed: {$e->getMessage()}\n");
            $summarySkipped++;
        }
    } else {
        // No mailer in this env — log the summary so cron tails still expose the math.
        fwrite(STDOUT, "[treasury_sweep_divergence] tenant={$tenantId} (MOCKED — no mailerSend): {$subject}\n");
        $summarySent++;
    }
}

fwrite(STDOUT, "[treasury_sweep_divergence] complete: sent={$summarySent} skipped={$summarySkipped}\n");
exit(0);

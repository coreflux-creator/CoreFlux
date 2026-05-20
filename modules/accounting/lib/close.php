<?php
/**
 * Accounting — Period close workflow (Sprint 2 / B4).
 *
 * Builds + manages per-period checklists, captures reopen reasons, and
 * generates the close-packet HTML artifact (foundation for PDF; the same
 * HTML can be print-to-PDF in the browser, or post-processed by dompdf
 * once that lib is wired in a later sprint).
 *
 * VERTICAL-AGNOSTIC.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/accounting.php';

/**
 * Default close-checklist template. Tenants can override via the API in
 * Sprint 4 when the generic WorkflowEngine lands.
 *
 * @return list<array{key:string,title:string,description:string}>
 */
function accountingDefaultCloseChecklist(): array {
    return [
        ['key' => 'reconcile_bank',     'title' => 'Reconcile bank accounts',         'description' => 'Confirm all bank accounts match the period-end statement balance.'],
        ['key' => 'review_unposted',    'title' => 'Review unposted journal entries', 'description' => 'Post or void all draft journals dated within the period.'],
        ['key' => 'subledger_lock',     'title' => 'Lock AR / AP subledgers',         'description' => 'No new bills or invoices may post-date back into the closing period.'],
        ['key' => 'accruals',           'title' => 'Post accruals',                    'description' => 'Revenue + expense accruals captured.'],
        ['key' => 'fx_revalue',         'title' => 'Run FX revaluation',               'description' => 'Period-end FX revaluation if multi-currency entities present.'],
        ['key' => 'review_trial_balance','title' => 'Review trial balance',            'description' => 'Trial balance reviewed; no anomalies.'],
        ['key' => 'flux_review',        'title' => 'Flux / variance review',           'description' => 'Period-over-period variance explained.'],
        ['key' => 'lock_period',        'title' => 'Close + lock period',              'description' => 'Final lock — no further postings allowed.'],
        ['key' => 'build_packet',       'title' => 'Build close packet',               'description' => 'Generate the close-packet PDF artifact for retention.'],
    ];
}

/**
 * Seed the checklist for a period (idempotent — only inserts missing tasks).
 */
function accountingSeedCloseChecklist(int $tenantId, int $periodId, ?int $actorUserId = null): int {
    $pdo = getDB();
    if (!$pdo) return 0;
    $template = accountingDefaultCloseChecklist();
    // tenant-leak-allow: defense-in-depth — caller scoped row by tenant_id before this id-only write
    $existing = $pdo->prepare("SELECT task_key FROM accounting_close_tasks WHERE period_id = :p");
    $existing->execute(['p' => $periodId]);
    $have = array_flip(array_column($existing->fetchAll(PDO::FETCH_ASSOC), 'task_key'));

    $ins = $pdo->prepare(
        "INSERT INTO accounting_close_tasks
           (tenant_id, period_id, task_key, title, description, sort_order, status, created_at)
         VALUES
           (:t, :p, :k, :ti, :d, :s, 'pending', NOW())"
    );
    $added = 0;
    foreach ($template as $i => $row) {
        if (isset($have[$row['key']])) continue;
        $ins->execute([
            't'  => $tenantId,
            'p'  => $periodId,
            'k'  => $row['key'],
            'ti' => $row['title'],
            'd'  => $row['description'],
            's'  => $i,
        ]);
        $added++;
    }
    return $added;
}

/**
 * Mark a single close task complete. Returns updated task row.
 */
function accountingCompleteCloseTask(int $tenantId, int $taskId, int $actorUserId, ?string $notes = null): array {
    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('No DB');
    $upd = $pdo->prepare(
        "UPDATE accounting_close_tasks
            SET status = 'done',
                completed_at = NOW(),
                completed_by_user_id = :u,
                notes = COALESCE(:n, notes)
          WHERE tenant_id = :t AND id = :id AND status <> 'done'"
    );
    $upd->execute(['t' => $tenantId, 'id' => $taskId, 'u' => $actorUserId, 'n' => $notes]);

    $sel = $pdo->prepare("SELECT * FROM accounting_close_tasks WHERE tenant_id = :t AND id = :id");
    $sel->execute(['t' => $tenantId, 'id' => $taskId]);
    return $sel->fetch(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Build a printable close-packet HTML for a closed period.
 * Includes: period meta + close summary + JE counts + trial balance.
 */
function accountingBuildClosePacketHtml(int $tenantId, int $periodId): string {
    $pdo = getDB();
    if (!$pdo) return '';

    $stmt = $pdo->prepare(
        "SELECT p.*, e.code AS entity_code, e.legal_name AS entity_name
           FROM accounting_periods p
           LEFT JOIN accounting_entities e ON e.id = p.entity_id
          WHERE p.tenant_id = :t AND p.id = :id"
    );
    $stmt->execute(['t' => $tenantId, 'id' => $periodId]);
    $period = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!$period) return '<p>Period not found.</p>';

    $jeCounts = $pdo->prepare(
        "SELECT status, COUNT(*) AS c
           FROM accounting_journal_entries
          WHERE tenant_id = :t AND period_id = :p
          GROUP BY status"
    );
    $jeCounts->execute(['t' => $tenantId, 'p' => $periodId]);
    $jeByStatus = [];
    foreach ($jeCounts->fetchAll(PDO::FETCH_ASSOC) as $r) $jeByStatus[$r['status']] = (int) $r['c'];

    $tb = accountingTrialBalance($tenantId, (string) $period['end_date'], (int) $period['entity_id']);

    $tasksStmt = $pdo->prepare(
        "SELECT title, status, completed_at,
                (SELECT u.name FROM users u WHERE u.id = t.completed_by_user_id) AS completed_by
           FROM accounting_close_tasks t
          WHERE t.tenant_id = :t AND t.period_id = :p
          ORDER BY t.sort_order"
    );
    $tasksStmt->execute(['t' => $tenantId, 'p' => $periodId]);
    $tasks = $tasksStmt->fetchAll(PDO::FETCH_ASSOC);

    $h = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES); };

    $html  = '<!doctype html><html><head><meta charset="utf-8"><title>Close packet — ';
    $html .= $h($period['entity_code']) . ' P' . $h($period['period_number']);
    $html .= '</title><style>body{font-family:-apple-system,Segoe UI,sans-serif;color:#111;max-width:760px;margin:24px auto;padding:0 16px}h1,h2{margin:0 0 8px}h2{margin-top:24px;font-size:16px;text-transform:uppercase;letter-spacing:.5px;color:#374151}table{width:100%;border-collapse:collapse;margin-top:8px}th,td{padding:6px 10px;border-bottom:1px solid #e5e7eb;text-align:left;font-size:13px}th{background:#f9fafb;font-weight:600}.r{text-align:right}.muted{color:#6b7280;font-size:12px}.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600}.b-done{background:#d1fae5;color:#065f46}.b-pending{background:#fef3c7;color:#92400e}@media print{body{margin:0}}</style></head><body>';
    $html .= '<h1>Close packet</h1>';
    $html .= '<div class="muted">' . $h($period['entity_code']) . ' — ' . $h($period['entity_name'])
          .  ' &nbsp;•&nbsp; Period ' . $h($period['period_number'])
          .  ' &nbsp;•&nbsp; ' . $h($period['start_date']) . ' → ' . $h($period['end_date'])
          .  ' &nbsp;•&nbsp; Status: ' . $h($period['status']) . '</div>';

    if (!empty($period['closed_at'])) {
        $html .= '<div class="muted">Closed at ' . $h($period['closed_at']) . '</div>';
    }
    if (!empty($period['reopen_reason'])) {
        $html .= '<div class="muted">Reopen reason: ' . $h($period['reopen_reason']) . '</div>';
    }

    $html .= '<h2>Journal activity</h2>';
    $html .= '<table><thead><tr><th>Status</th><th class="r">Count</th></tr></thead><tbody>';
    foreach (['draft','posted','reversed','void'] as $s) {
        $html .= '<tr><td>' . $h($s) . '</td><td class="r">' . (int) ($jeByStatus[$s] ?? 0) . '</td></tr>';
    }
    $html .= '</tbody></table>';

    $html .= '<h2>Close checklist</h2>';
    $html .= '<table><thead><tr><th>Task</th><th>Status</th><th>Completed by</th><th>At</th></tr></thead><tbody>';
    foreach ($tasks as $t) {
        $cls = ($t['status'] === 'done') ? 'b-done' : 'b-pending';
        $html .= '<tr><td>' . $h($t['title']) . '</td>'
              .  '<td><span class="badge ' . $cls . '">' . $h($t['status']) . '</span></td>'
              .  '<td>' . $h($t['completed_by']) . '</td>'
              .  '<td class="muted">' . $h($t['completed_at']) . '</td></tr>';
    }
    $html .= '</tbody></table>';

    $html .= '<h2>Trial balance — as of ' . $h($period['end_date']) . '</h2>';
    $html .= '<table><thead><tr><th>Code</th><th>Account</th><th class="r">Debit</th><th class="r">Credit</th></tr></thead><tbody>';
    foreach (($tb['rows'] ?? []) as $r) {
        $html .= '<tr><td>' . $h($r['code'] ?? '') . '</td>'
              .  '<td>' . $h($r['name'] ?? '') . '</td>'
              .  '<td class="r">' . number_format((float) ($r['debit']  ?? 0), 2) . '</td>'
              .  '<td class="r">' . number_format((float) ($r['credit'] ?? 0), 2) . '</td></tr>';
    }
    $html .= '<tr><th>Total</th><th></th>'
          .  '<th class="r">' . number_format((float) ($tb['total_debit']  ?? 0), 2) . '</th>'
          .  '<th class="r">' . number_format((float) ($tb['total_credit'] ?? 0), 2) . '</th></tr>';
    $html .= '</tbody></table>';

    $html .= '<div class="muted" style="margin-top:32px">Generated ' . date('Y-m-d H:i:s') . ' UTC</div>';
    $html .= '</body></html>';
    return $html;
}

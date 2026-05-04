<?php
/**
 * Accounting — Recurring journal entries engine.
 *
 * - recurringJeListDue($tenantId)        Templates with next_run_date <= today.
 * - recurringJeRunOnce($tenantId, $id)   Posts one run for one template, advances
 *                                         next_run_date by cadence, idempotent
 *                                         on (template_id, run_date).
 * - recurringJeRunDueForTenant($tid)     Loops every active due template.
 * - recurringJeAdvanceDate($iso, $cad)   Pure date-math helper (unit tested).
 *
 * Idempotency key shape: 'recurring:{template_id}:{run_date}' → so a cron
 * that fires twice in the same day cannot double-post. The idem key lives
 * inside the standard accounting_posting_idempotency table.
 */

declare(strict_types=1);

require_once __DIR__ . '/accounting.php';

function recurringJeListDue(int $tenantId): array
{
    return scopedQuery(
        'SELECT * FROM accounting_recurring_journal_entries
         WHERE tenant_id = :tenant_id AND status = "active"
           AND next_run_date <= CURDATE()
         ORDER BY next_run_date, id'
    );
}

/**
 * Run a single template. Posts a JE (or stages a draft when auto_post=0)
 * with idempotency key recurring:<id>:<run_date>, then bumps next_run_date.
 *
 * Returns the je_id + advanced-to date so the cron can summarize.
 */
function recurringJeRunOnce(int $tenantId, int $templateId, ?int $actorUserId = null, ?string $forceRunDate = null): array
{
    $tpl = scopedFind('SELECT * FROM accounting_recurring_journal_entries WHERE tenant_id = :tenant_id AND id = :id', ['id' => $templateId]);
    if (!$tpl)                       throw new \RuntimeException('Template not found');
    if ($tpl['status'] !== 'active') throw new \RuntimeException('Template is not active');

    $runDate = $forceRunDate ?: (string) $tpl['next_run_date'];
    if ($tpl['end_date'] && $runDate > $tpl['end_date']) {
        // Past the end — auto-end the template instead of running.
        scopedUpdate('accounting_recurring_journal_entries', $templateId, ['status' => 'ended']);
        accountingAudit('accounting.recurring_je.auto_ended', ['template_id' => $templateId], $templateId);
        return ['template_id' => $templateId, 'skipped' => true, 'reason' => 'past_end_date'];
    }

    $lines = scopedQuery(
        'SELECT * FROM accounting_recurring_je_lines
         WHERE tenant_id = :tenant_id AND recurring_je_id = :rid
         ORDER BY line_no, id',
        ['rid' => $templateId]
    );
    if (count($lines) < 2) throw new \RuntimeException('Template has fewer than 2 lines');

    $jeLines = [];
    foreach ($lines as $l) {
        $jeLines[] = [
            'account_code' => (string) $l['account_code'],
            'debit'        => (float)  $l['debit'],
            'credit'       => (float)  $l['credit'],
            'description'  => $l['description'] ?? null,
        ];
    }
    $autoPost = (int) $tpl['auto_post'] === 1;

    $res = accountingPostJe($tenantId, [
        'posting_date'      => $runDate,
        'memo'              => ($tpl['memo'] ?? '') . ' (recurring: ' . $tpl['name'] . ')',
        'source_module'     => 'recurring_je',
        'source_ref_type'   => 'recurring_je',
        'source_ref_id'     => $templateId,
        'idempotency_key'   => 'recurring:' . $templateId . ':' . $runDate,
        'lines'             => $jeLines,
        'entity_id'         => $tpl['entity_id'],
    ], $actorUserId, $autoPost);

    $next = recurringJeAdvanceDate($runDate, (string) $tpl['cadence']);
    scopedUpdate('accounting_recurring_journal_entries', $templateId, [
        'next_run_date'  => $next,
        'last_run_at'    => date('Y-m-d H:i:s'),
        'last_run_je_id' => $res['je_id'],
    ]);
    accountingAudit('accounting.recurring_je.run', [
        'template_id'  => $templateId,
        'run_date'     => $runDate,
        'je_id'        => $res['je_id'],
        'auto_posted'  => $autoPost,
        'idempotent'   => !empty($res['idempotent_replay']),
        'next_run'     => $next,
    ], $templateId);

    return [
        'template_id' => $templateId,
        'run_date'    => $runDate,
        'je_id'       => $res['je_id'],
        'auto_posted' => $autoPost,
        'idempotent'  => !empty($res['idempotent_replay']),
        'next_run'    => $next,
    ];
}

/**
 * Run every due template for one tenant. Catches per-template failures
 * so one bad template doesn't block the rest.
 */
function recurringJeRunDueForTenant(int $tenantId, ?int $actorUserId = null): array
{
    $due = recurringJeListDue($tenantId);
    $results = ['ran' => 0, 'skipped' => 0, 'errors' => 0, 'detail' => []];
    foreach ($due as $tpl) {
        try {
            $r = recurringJeRunOnce($tenantId, (int) $tpl['id'], $actorUserId);
            $results['detail'][] = $r;
            empty($r['skipped']) ? $results['ran']++ : $results['skipped']++;
        } catch (\Throwable $e) {
            $results['errors']++;
            $results['detail'][] = ['template_id' => (int) $tpl['id'], 'error' => $e->getMessage()];
            error_log('[recurring_je] template ' . $tpl['id'] . ' failed: ' . $e->getMessage());
        }
    }
    return $results;
}

/**
 * Pure date helper. ISO yyyy-mm-dd in/out. Cadence ∈ {weekly, biweekly,
 * monthly, quarterly, yearly}.
 */
function recurringJeAdvanceDate(string $iso, string $cadence): string
{
    $ts = strtotime($iso);
    if ($ts === false) throw new \InvalidArgumentException('Bad date: ' . $iso);
    $delta = match ($cadence) {
        'weekly'    => '+1 week',
        'biweekly'  => '+2 weeks',
        'monthly'   => '+1 month',
        'quarterly' => '+3 months',
        'yearly'    => '+1 year',
        default     => throw new \InvalidArgumentException('Unknown cadence: ' . $cadence),
    };
    return date('Y-m-d', strtotime($delta, $ts));
}

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
require_once __DIR__ . '/dimensions.php';

/** @return array<string,mixed> */
function recurringJeDecodeDimensions($raw): array
{
    if (is_array($raw)) return $raw;
    if ($raw === null || $raw === '') return [];
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        throw new \InvalidArgumentException('Recurring journal line dimensions must be a JSON object');
    }
    return $decoded;
}

/**
 * Prepare recurring lines for storage or posting. Assignment-owned context is
 * refreshed from the placement master as of the run date; custom dimensions
 * remain intact. Vendor is inherited only when the selected account requires
 * it, preventing AP context from leaking onto revenue lines.
 *
 * @param list<array<string,mixed>> $lines
 * @return list<array<string,mixed>>
 */
function recurringJePrepareLines(
    int $tenantId,
    int $entityId,
    string $runDate,
    array $lines
): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $runDate)) {
        throw new \InvalidArgumentException('Recurring journal run date must be YYYY-MM-DD');
    }
    $pdo = getDB();
    $accountLookup = $pdo->prepare(
        'SELECT id FROM accounting_accounts
          WHERE tenant_id = :tenant_id AND code = :code AND active = 1
          LIMIT 1'
    );
    $assignmentKeys = [
        'client','placement','worker','job','recruiter','account_manager',
        'branch','service_line','work_state','wc_class','department','cost_center',
        'vendor',
    ];

    foreach ($lines as $index => &$line) {
        $dims = recurringJeDecodeDimensions($line['dims'] ?? $line['dim_json'] ?? null);
        unset($dims['legal_entity']);
        $placementRaw = trim((string) ($dims['placement'] ?? ''));
        $placementId = (int) preg_replace('/^PL-/i', '', $placementRaw);
        if ($placementId <= 0) {
            $line['dims'] = $dims;
            continue;
        }

        require_once __DIR__ . '/../../staffing/lib/dimensions.php';
        $context = staffingAssignmentDimensionContext(
            $tenantId,
            $placementId,
            $entityId,
            $runDate
        );
        $resolvedEntityId = (int) ($context['event_entity_id'] ?? 0);
        if ($resolvedEntityId <= 0 || $resolvedEntityId !== $entityId) {
            throw new \RuntimeException(
                'Recurring line ' . ($index + 1) . " assignment PL-{$placementId} belongs to a different legal entity"
            );
        }

        $accountLookup->execute([
            'tenant_id' => $tenantId,
            'code' => (string) ($line['account_code'] ?? ''),
        ]);
        $accountId = (int) ($accountLookup->fetchColumn() ?: 0);
        $requiredDimensions = accountingRequiredDimensionKeysForAccountCodes(
            $tenantId,
            [(string) ($line['account_code'] ?? '')]
        );
        $requiresVendor = in_array('vendor', $requiredDimensions, true);
        $missing = staffingDimensionMissingForRequirements($context, $requiredDimensions, $dims);
        if ($missing) {
            throw new \RuntimeException(
                'Recurring line ' . ($index + 1) . " assignment PL-{$placementId} is missing "
                . implode(', ', staffingDimensionMissingLabels($missing))
            );
        }

        foreach ($assignmentKeys as $key) unset($dims[$key]);
        $inherited = (array) ($context['dimensions'] ?? []);
        unset($inherited['legal_entity']);
        $dims = array_replace($dims, $inherited, ['placement' => $placementId]);
        if ($requiresVendor) {
            $vendor = $context['vendor_dimension'] ?? null;
            if ($vendor === null || $vendor === '') {
                throw new \RuntimeException(
                    'Recurring line ' . ($index + 1) . " assignment PL-{$placementId} has no payable vendor"
                );
            }
            $dims['vendor'] = $vendor;
        }
        $line['dims'] = $dims;
    }
    unset($line);
    return $lines;
}

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

    $entityId = accountingValidateActiveEntityId($tenantId, $tpl['entity_id'] ?? null)
        ?? (int) accountingDefaultEntity($tenantId)['id'];
    $lines = recurringJePrepareLines($tenantId, $entityId, $runDate, $lines);
    $jeLines = [];
    foreach ($lines as $l) {
        $dims = (array) ($l['dims'] ?? []);
        $jeLines[] = [
            'account_code' => (string) $l['account_code'],
            'debit'        => (float)  $l['debit'],
            'credit'       => (float)  $l['credit'],
            'description'  => $l['description'] ?? null,
            'counterparty_entity_id' => !empty($dims['counterparty_entity'])
                ? (int) $dims['counterparty_entity']
                : null,
            'dims'         => $dims,
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
        'entity_id'         => $entityId,
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

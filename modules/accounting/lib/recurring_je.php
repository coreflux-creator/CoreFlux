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
    $protectedCodes = array_fill_keys(accountingSourceOwnedControlCodes($tenantId, $pdo), true);
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
        if (isset($protectedCodes[(string) ($line['account_code'] ?? '')])) {
            throw new \InvalidArgumentException(
                'Recurring line ' . ($index + 1) . ': this account is managed by its source workflow'
            );
        }
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

/** Resolve a recurring run by explicit tenant and its historical template link. */
function recurringJeLinkedJournal(int $tenantId, int $jeId, bool $lock = false, int $depth = 0): array
{
    if ($depth > 20) throw new \RuntimeException('Recurring correction chain is too deep');
    $pdo = getDB();
    $entryStatement = $pdo->prepare(
        'SELECT id, entity_id, source_module, source_ref_type, source_ref_id, status,
                posting_date, currency, memo, je_number, reversed_by_je_id
           FROM accounting_journal_entries WHERE tenant_id = :tenant_id AND id = :id'
        . ($lock ? ' FOR UPDATE' : '')
    );
    $entryStatement->execute(['tenant_id' => $tenantId, 'id' => $jeId]);
    $entry = $entryStatement->fetch(\PDO::FETCH_ASSOC) ?: null;
    if (!$entry) throw new \RuntimeException('Journal entry not found');
    if (($entry['source_module'] ?? '') !== 'recurring_je'
        || !in_array((string) ($entry['source_ref_type'] ?? ''), ['recurring_je', 'replaces_je'], true)
        || (int) ($entry['source_ref_id'] ?? 0) <= 0) {
        throw new \RuntimeException('Journal entry is not a recurring template run');
    }
    if ($entry['source_ref_type'] === 'replaces_je') {
        if ((int) $entry['source_ref_id'] === $jeId) {
            throw new \RuntimeException('Recurring correction cannot replace itself');
        }
        $original = recurringJeLinkedJournal(
            $tenantId, (int) $entry['source_ref_id'], $lock, $depth + 1
        );
        if ((int) $entry['entity_id'] !== (int) $original['entry']['entity_id']) {
            throw new \RuntimeException('Recurring correction and original entity do not match');
        }
        return ['entry' => $entry, 'template' => $original['template']];
    }
    $templateStatement = $pdo->prepare(
        'SELECT id, entity_id FROM accounting_recurring_journal_entries
          WHERE tenant_id = :tenant_id AND id = :id'
    );
    $templateStatement->execute(['tenant_id' => $tenantId, 'id' => (int) $entry['source_ref_id']]);
    $template = $templateStatement->fetch(\PDO::FETCH_ASSOC) ?: null;
    if (!$template) {
        throw new \RuntimeException('Recurring template not found');
    }
    return ['entry' => $entry, 'template' => $template];
}

/** Original run plus its one editable or posted replacement, if any. */
function recurringJeReplacementDetail(int $tenantId, int $jeId): array
{
    ['entry' => $entry, 'template' => $template] = recurringJeLinkedJournal($tenantId, $jeId);
    if ($entry['status'] !== 'reversed') {
        throw new \RuntimeException('Reverse this recurring journal before preparing a replacement');
    }
    $pdo = getDB();
    $replacement = $pdo->prepare(
        'SELECT id, je_number, status, posting_date, memo, total_debit
           FROM accounting_journal_entries
          WHERE tenant_id = :tenant_id AND source_module = "recurring_je"
            AND source_ref_type = "replaces_je" AND source_ref_id = :id
            AND status <> "void" ORDER BY id DESC LIMIT 1'
    );
    $replacement->execute(['tenant_id' => $tenantId, 'id' => $jeId]);
    $existing = $replacement->fetch(\PDO::FETCH_ASSOC) ?: null;
    $lineJournalId = $existing && $existing['status'] === 'draft' ? (int) $existing['id'] : $jeId;
    $lines = $pdo->prepare(
        'SELECT l.line_no, a.code AS account_code, l.debit, l.credit,
                l.description, l.dim_json
           FROM accounting_journal_entry_lines l
           JOIN accounting_accounts a ON a.id = l.account_id AND a.tenant_id = l.tenant_id
          WHERE l.tenant_id = :tenant_id AND l.je_id = :je_id ORDER BY l.line_no'
    );
    $lines->execute(['tenant_id' => $tenantId, 'je_id' => $lineJournalId]);
    $rows = $lines->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$line) $line['dims'] = recurringJeDecodeDimensions($line['dim_json'] ?? null);
    unset($line);
    return ['original' => $entry, 'template' => $template,
        'replacement' => $existing, 'lines' => $rows];
}

function recurringJeReplacementValidationMessage(array $validation): string
{
    $messages = array_values((array) ($validation['errors'] ?? []));
    foreach ((array) ($validation['line_validations'] ?? []) as $line) {
        foreach ((array) ($line['errors'] ?? []) as $message) {
            $messages[] = 'Line ' . (int) ($line['line_no'] ?? 0) . ': ' . $message;
        }
    }
    return implode('; ', array_values(array_unique(array_filter($messages)))) ?: 'Journal validation failed';
}

/** Stage or revise one source-linked draft; neither action changes the schedule. */
function recurringJePrepareReplacement(
    int $tenantId, int $jeId, array $payload, ?int $actorUserId = null
): array {
    $reason = trim((string) ($payload['reason'] ?? ''));
    if ($reason === '') throw new \InvalidArgumentException('Correction reason required');
    $postingDate = (string) ($payload['posting_date'] ?? '');
    $lines = $payload['lines'] ?? null;
    if (!is_array($lines) || count($lines) < 2) {
        throw new \InvalidArgumentException('Need at least two replacement lines');
    }
    $pdo = getDB();
    $ownsTransaction = cf_tx_begin($pdo);
    try {
        ['entry' => $original, 'template' => $template] = recurringJeLinkedJournal($tenantId, $jeId, true);
        if ($original['status'] !== 'reversed' || (int) $original['reversed_by_je_id'] <= 0) {
            throw new \RuntimeException('Reverse this recurring journal before preparing a replacement');
        }
        $entityId = (int) $original['entity_id'];
        $templateEntityId = accountingValidateActiveEntityId($tenantId, $template['entity_id'] ?? null)
            ?? (int) accountingDefaultEntity($tenantId)['id'];
        if ($templateEntityId !== $entityId) {
            throw new \RuntimeException('Recurring template and original journal entity do not match');
        }
        if (isset($payload['entity_id']) && (int) $payload['entity_id'] !== $entityId) {
            throw new \RuntimeException('A replacement must stay in the original legal entity');
        }
        $lines = recurringJePrepareLines($tenantId, $entityId, $postingDate, $lines);
        $validation = accountingValidateJe($tenantId, [
            'entity_id' => $entityId, 'posting_date' => $postingDate,
            'currency' => (string) $original['currency'], 'lines' => $lines,
        ]);
        if (!$validation['ok']) {
            throw new \InvalidArgumentException(recurringJeReplacementValidationMessage($validation));
        }
        $find = $pdo->prepare(
            'SELECT id, status FROM accounting_journal_entries
              WHERE tenant_id = :tenant_id AND source_module = "recurring_je"
                AND source_ref_type = "replaces_je" AND source_ref_id = :id
                AND status <> "void" ORDER BY id DESC LIMIT 1 FOR UPDATE'
        );
        $find->execute(['tenant_id' => $tenantId, 'id' => $jeId]);
        $existing = $find->fetch(\PDO::FETCH_ASSOC) ?: null;
        if ($existing && $existing['status'] !== 'draft') {
            throw new \RuntimeException('This replacement is already posted; reverse it to correct it again');
        }
        $candidate = [
            'entity_id' => $entityId,
            'posting_date' => $postingDate,
            'currency' => (string) $original['currency'],
            'memo' => (string) ($payload['memo'] ?? ''),
            'lines' => $lines,
        ];
        if ($existing) {
            $result = accountingUpdateDraftJe($tenantId, (int) $existing['id'], $candidate, $actorUserId);
            $result['updated'] = true;
        } else {
            $result = accountingPostJe($tenantId, $candidate + [
                'source_module' => 'recurring_je',
                'source_ref_type' => 'replaces_je',
                'source_ref_id' => $jeId,
                'idempotency_key' => "recurring:replacement:{$tenantId}:{$jeId}",
            ], $actorUserId, false);
            $result['updated'] = false;
        }
        cf_tx_commit($pdo, $ownsTransaction);
    } catch (\Throwable $error) {
        cf_tx_rollback($pdo, $ownsTransaction);
        throw $error;
    }
    accountingAudit('accounting.recurring_je.replacement_prepared', [
        'template_id' => (int) $template['id'],
        'original_je_id' => $jeId,
        'replacement_je_id' => (int) $result['je_id'],
        'updated' => $result['updated'],
        'reason' => $reason,
    ], (int) $template['id']);
    return $result + ['original_je_id' => $jeId];
}

/** Post only a draft produced by a recurring template in this tenant and entity. */
function recurringJePostDraft(int $tenantId, int $jeId, ?int $actorUserId = null): array
{
    ['entry' => $entry, 'template' => $template] = recurringJeLinkedJournal($tenantId, $jeId);
    $templateEntityId = accountingValidateActiveEntityId($tenantId, $template['entity_id'] ?? null)
        ?? (int) accountingDefaultEntity($tenantId)['id'];
    if ($templateEntityId !== (int) $entry['entity_id']) {
        throw new \RuntimeException('Recurring template and journal entity do not match');
    }
    if (!in_array((string) $entry['status'], ['draft', 'posted'], true)) {
        throw new \RuntimeException('Only draft recurring journals can be posted');
    }
    if ($entry['source_ref_type'] === 'replaces_je') {
        $original = recurringJeLinkedJournal($tenantId, (int) $entry['source_ref_id']);
        if ($original['entry']['status'] !== 'reversed') {
            throw new \RuntimeException('The original recurring journal must remain reversed');
        }
    }
    $result = accountingPostDraftJe($tenantId, $jeId, $actorUserId);
    if (empty($result['idempotent_replay'])) {
        accountingAudit('accounting.recurring_je.draft_posted', [
            'template_id' => (int) $template['id'],
            'je_id' => $jeId,
            'total' => (float) $result['total_debit'],
        ], (int) $template['id']);
    }
    return $result;
}

/** Reverse one posted run without changing the template's future schedule. */
function recurringJeReverseRun(int $tenantId, int $jeId, string $reason, ?int $actorUserId = null): array
{
    $reason = trim($reason);
    if ($reason === '') throw new \InvalidArgumentException('Reason for reversal required');
    $pdo = getDB();
    $ownsTransaction = cf_tx_begin($pdo);
    try {
        ['entry' => $entry, 'template' => $template] = recurringJeLinkedJournal($tenantId, $jeId, true);
        if (!in_array((string) $entry['status'], ['posted', 'reversed'], true)) {
            throw new \RuntimeException('Only posted recurring journals can be reversed');
        }
        if ($entry['status'] === 'posted') {
            $matched = $pdo->prepare('SELECT id FROM accounting_bank_statement_lines
                WHERE tenant_id = :tenant_id AND matched_je_id = :je_id
                  AND match_status = "matched" LIMIT 1');
            $matched->execute(['tenant_id' => $tenantId, 'je_id' => $jeId]);
            if ($matched->fetchColumn()) {
                throw new \RuntimeException('Unmatch the linked bank transaction before reversing this run');
            }
        }
        $result = accountingReverseJe($tenantId, $jeId, $reason, $actorUserId);
        cf_tx_commit($pdo, $ownsTransaction);
    } catch (\Throwable $error) {
        cf_tx_rollback($pdo, $ownsTransaction);
        throw $error;
    }
    if (empty($result['idempotent_replay'])) {
        accountingAudit('accounting.recurring_je.run_reversed', [
            'template_id' => (int) $template['id'],
            'original_je_id' => $jeId,
            'reversal_je_id' => (int) $result['je_id'],
            'reason' => $reason,
        ], (int) $template['id']);
    }
    return $result;
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

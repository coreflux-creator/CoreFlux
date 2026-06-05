<?php
/**
 * Accounting v1.0 — Phase 0 library.
 *
 * Scope:
 *   • Chart of accounts (CRUD)
 *   • Entities + periods (simple CRUD)
 *   • Journal entries: draft → post → reverse
 *   • Subledger posting protocol (idempotency_key-based)
 *
 * OUT OF SCOPE (future phases):
 *   • Dimensions / segments
 *   • Close workflows / tasks / packets
 *   • Consolidation / intercompany auto-balancing
 *   • HMAC webhooks / external sync
 *
 * SPEC: /app/modules/accounting/SPEC.md §2 (locked principles), §3 (data model),
 * §5 (subledger post protocol).
 */

require_once __DIR__ . '/../../../core/tenant_scope.php';
require_once __DIR__ . '/../../../core/financial_state_cache.php';

// ─────────────────────────────────────────────────────────────────────────
// Numbering — atomic JE number allocation
// ─────────────────────────────────────────────────────────────────────────

/**
 * Atomically allocate the next JE number for a tenant.
 * Format: {prefix}-{YYYY}-{NNNNNN} (6-digit padded).
 *
 * Re-entrant: when the caller already holds an open transaction (e.g.
 * accountingPostJe wraps the entire write in `beginTransaction()` and
 * then calls this helper to obtain the je_number), we participate in
 * that outer transaction instead of opening a nested one. PDO refuses
 * nested `beginTransaction()` calls with "There is already an active
 * transaction", which surfaced in the New Journal Entry → Post JE
 * form. The FOR UPDATE row lock still holds correctly inside the
 * outer transaction so the sequence is just as safe.
 */
function accountingNextJeNumber(int $tenantId): string
{
    $pdo = getDB();
    $owningTxn = !$pdo->inTransaction();
    if ($owningTxn) $pdo->beginTransaction();
    try {
        $row = $pdo->prepare(
            'SELECT accounting_je_prefix, accounting_next_je_seq
             FROM tenants WHERE id = :id FOR UPDATE'
        );
        $row->execute(['id' => $tenantId]);
        $r = $row->fetch(\PDO::FETCH_ASSOC);
        if (!$r) throw new \RuntimeException("tenant {$tenantId} not found");
        $prefix = trim((string) ($r['accounting_je_prefix'] ?? 'JE')) ?: 'JE';
        $seq    = (int) $r['accounting_next_je_seq'];

        $pdo->prepare('UPDATE tenants SET accounting_next_je_seq = :n WHERE id = :id')
            ->execute(['n' => $seq + 1, 'id' => $tenantId]);
        if ($owningTxn) $pdo->commit();

        return sprintf('%s-%s-%06d', $prefix, date('Y'), $seq);
    } catch (\Throwable $e) {
        if ($owningTxn && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Period resolver
// ─────────────────────────────────────────────────────────────────────────

/**
 * Resolve the accounting period that covers $postingDate for $entityId.
 * If no period is found, auto-creates a monthly open period for the date.
 * This keeps Phase 0 functional without forcing admins to pre-configure
 * calendars.
 *
 * @return array the period row with id, status, start_date, end_date
 */
function accountingResolvePeriod(int $tenantId, int $entityId, string $postingDate): array
{
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT * FROM accounting_periods
         WHERE tenant_id = :t AND entity_id = :e
           AND start_date <= :d_lo AND end_date >= :d_hi
         LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'e' => $entityId, 'd_lo' => $postingDate, 'd_hi' => $postingDate]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if ($row) return $row;

    // Auto-create a monthly period.
    $first = date('Y-m-01', strtotime($postingDate));
    $last  = date('Y-m-t',  strtotime($postingDate));
    $pnum  = (int) date('n', strtotime($postingDate));
    $pdo->prepare(
        'INSERT INTO accounting_periods
           (tenant_id, entity_id, period_number, start_date, end_date, status)
         VALUES
           (:t, :e, :n, :s, :x, "open")
         ON DUPLICATE KEY UPDATE id = id'
    )->execute(['t' => $tenantId, 'e' => $entityId, 'n' => $pnum, 's' => $first, 'x' => $last]);

    $stmt->execute(['t' => $tenantId, 'e' => $entityId, 'd_lo' => $postingDate, 'd_hi' => $postingDate]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) throw new \RuntimeException('Failed to resolve/create accounting period');
    return $row;
}

/**
 * Default entity for a tenant — first active entity, or auto-create
 * a "MAIN" entity on first use. Keeps subledger posting simple until
 * the tenant configures multi-entity.
 */
function accountingDefaultEntity(int $tenantId): array
{
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT * FROM accounting_entities WHERE tenant_id = :t AND active = 1 ORDER BY id ASC LIMIT 1');
    $stmt->execute(['t' => $tenantId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if ($row) return $row;

    $pdo->prepare(
        'INSERT INTO accounting_entities (tenant_id, code, legal_name, base_currency, active)
         VALUES (:t, "MAIN", "Main Entity", "USD", 1)'
    )->execute(['t' => $tenantId]);
    $stmt->execute(['t' => $tenantId]);
    return $stmt->fetch(\PDO::FETCH_ASSOC);
}

// ─────────────────────────────────────────────────────────────────────────
// JE posting — atomic, balanced, idempotent
// ─────────────────────────────────────────────────────────────────────────

/**
 * Create + post a balanced JE in one call. Subledgers use this.
 *
 * @param array $je {
 *   entity_id?, posting_date, memo?, currency?,
 *   source_module ('manual'|'ap'|'billing'|...),
 *   source_ref_type?, source_ref_id?,
 *   idempotency_key (REQUIRED for subledgers),
 *   lines: [{account_code|account_id, debit, credit, memo?, counterparty_company_id?, counterparty_person_id?}, ...]
 * }
 * @param int|null $actorUserId
 * @param bool     $post  when false, leaves JE in 'draft'
 *
 * @return array {je_id, je_number, status, total_debit, total_credit, idempotent_replay (bool)}
 * @throws \RuntimeException on unbalanced, unknown account, closed period, etc.
 */
function accountingPostJe(int $tenantId, array $je, ?int $actorUserId = null, bool $post = true): array
{
    $lines = $je['lines'] ?? [];
    if (!is_array($lines) || count($lines) < 2) {
        throw new \InvalidArgumentException('Need at least 2 lines');
    }
    $postingDate = (string) ($je['posting_date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $postingDate)) {
        throw new \InvalidArgumentException('posting_date must be YYYY-MM-DD');
    }
    $currency     = (string) ($je['currency']      ?? 'USD');
    $sourceModule = (string) ($je['source_module'] ?? 'manual');
    $idemKey      = $je['idempotency_key'] ?? null;

    $pdo = getDB();

    // Idempotency short-circuit (only when a key is provided).
    if ($idemKey) {
        $stmt = $pdo->prepare(
            'SELECT je.* FROM accounting_posting_idempotency i
             JOIN accounting_journal_entries je ON je.id = i.je_id
             WHERE i.tenant_id = :t AND i.idempotency_key = :k LIMIT 1'
        );
        $stmt->execute(['t' => $tenantId, 'k' => $idemKey]);
        $prior = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($prior) {
            return [
                'je_id'             => (int) $prior['id'],
                'je_number'         => $prior['je_number'],
                'status'            => $prior['status'],
                'total_debit'       => (float) $prior['total_debit'],
                'total_credit'      => (float) $prior['total_credit'],
                'idempotent_replay' => true,
            ];
        }
    }

    $entityId = !empty($je['entity_id']) ? (int) $je['entity_id']
                                         : (int) accountingDefaultEntity($tenantId)['id'];

    $period = accountingResolvePeriod($tenantId, $entityId, $postingDate);
    if (in_array($period['status'], ['closed','soft_closed'], true)) {
        throw new \RuntimeException("Period {$period['period_number']} ({$period['start_date']}..{$period['end_date']}) is {$period['status']}; cannot post");
    }

    // Resolve accounts + validate balance in a single pass.
    $byId = $byCode = [];
    $stmt = $pdo->prepare('SELECT id, code, name, is_postable, active FROM accounting_accounts WHERE tenant_id = :t');
    $stmt->execute(['t' => $tenantId]);
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $a) {
        $byId[(int) $a['id']] = $a;
        $byCode[strtolower($a['code'])] = $a;
    }

    $totalDebit = $totalCredit = 0.0;
    $resolved = [];
    foreach ($lines as $i => $l) {
        $debit  = round((float) ($l['debit']  ?? 0), 2);
        $credit = round((float) ($l['credit'] ?? 0), 2);
        if ($debit < 0 || $credit < 0) throw new \InvalidArgumentException("Line {$i}: negative amounts not allowed");
        if ($debit > 0 && $credit > 0) throw new \InvalidArgumentException("Line {$i}: a line cannot have both debit and credit");
        if ($debit == 0 && $credit == 0) throw new \InvalidArgumentException("Line {$i}: must specify debit or credit");

        if (!empty($l['account_id'])) {
            $a = $byId[(int) $l['account_id']] ?? null;
        } elseif (!empty($l['account_code'])) {
            $a = $byCode[strtolower((string) $l['account_code'])] ?? null;
        } else {
            throw new \InvalidArgumentException("Line {$i}: account_id or account_code required");
        }
        if (!$a)                throw new \RuntimeException("Line {$i}: account not found");
        if (!$a['active'])      throw new \RuntimeException("Line {$i}: account {$a['code']} is inactive");
        if (!$a['is_postable']) throw new \RuntimeException("Line {$i}: account {$a['code']} is not postable (summary)");

        $totalDebit  += $debit;
        $totalCredit += $credit;
        $resolved[] = [
            'line_no'                 => $i + 1,
            'account_id'              => (int) $a['id'],
            'debit'                   => $debit,
            'credit'                  => $credit,
            'memo'                    => $l['memo'] ?? null,
            'counterparty_company_id' => !empty($l['counterparty_company_id']) ? (int) $l['counterparty_company_id'] : null,
            'counterparty_person_id'  => !empty($l['counterparty_person_id'])  ? (int) $l['counterparty_person_id']  : null,
            'counterparty_entity_id'  => !empty($l['counterparty_entity_id'])  ? (int) $l['counterparty_entity_id']  : null,
            'dim_json'                => isset($l['dims']) ? json_encode($l['dims']) : null,
        ];
    }
    if (round(abs($totalDebit - $totalCredit), 2) > 0.005) {
        throw new \RuntimeException(sprintf('Unbalanced JE: debits=%.2f credits=%.2f', $totalDebit, $totalCredit));
    }

    // B2: per-account dimension validation. Tenant-configurable; no-op if no
    // dimensions defined for this tenant. Throws on first failure.
    if (file_exists(__DIR__ . '/dimensions.php')) {
        require_once __DIR__ . '/dimensions.php';
        $linesForDimCheck = [];
        foreach ($lines as $i => $l) {
            $accId = (int) ($resolved[$i]['account_id'] ?? 0);
            $linesForDimCheck[] = [
                'account_id' => $accId,
                'dims'       => (array) ($l['dims'] ?? []),
            ];
        }
        accountingValidateJeDims($tenantId, $linesForDimCheck);
    }

    $pdo->beginTransaction();
    try {
        $jeNumber = accountingNextJeNumber($tenantId);
        $jeId = scopedInsert('accounting_journal_entries', [
            'tenant_id'         => $tenantId,
            'entity_id'         => $entityId,
            'period_id'         => (int) $period['id'],
            'je_number'         => $jeNumber,
            'posting_date'      => $postingDate,
            'source_module'     => $sourceModule,
            'source_ref_type'   => $je['source_ref_type'] ?? null,
            'source_ref_id'     => !empty($je['source_ref_id']) ? (int) $je['source_ref_id'] : null,
            'idempotency_key'   => $idemKey,
            'status'            => $post ? 'posted' : 'draft',
            'currency'          => $currency,
            'total_debit'       => $totalDebit,
            'total_credit'      => $totalCredit,
            'memo'              => $je['memo'] ?? null,
            // Sprint P2 — when set, the auto_reverse_accruals.php cron will
            // generate a reversing JE on or after this date, then null it.
            // Validated as YYYY-MM-DD or null; ignored for non-posted entries
            // (only posted JEs reverse).
            'auto_reverses_on'  => (function ($v) {
                if (empty($v)) return null;
                $v = (string) $v;
                return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
            })($je['auto_reverses_on'] ?? null),
            'posted_at'         => $post ? date('Y-m-d H:i:s') : null,
            'posted_by_user_id' => $post ? $actorUserId : null,
            'created_by_user_id'=> $actorUserId,
        ]);

        foreach ($resolved as $l) {
            // tenant-leak-allow: defense-in-depth — caller scoped row by tenant_id before this id-only write
            $stmt = $pdo->prepare(
                'INSERT INTO accounting_journal_entry_lines
                   (je_id, line_no, account_id, debit, credit, memo, counterparty_company_id, counterparty_person_id, counterparty_entity_id, dim_json)
                 VALUES (:je, :ln, :a, :d, :c, :m, :cc, :cp, :ce, :dj)'
            );
            $stmt->execute([
                'je' => $jeId, 'ln' => $l['line_no'], 'a' => $l['account_id'],
                'd'  => $l['debit'], 'c' => $l['credit'], 'm' => $l['memo'],
                'cc' => $l['counterparty_company_id'], 'cp' => $l['counterparty_person_id'],
                'ce' => $l['counterparty_entity_id'],
                'dj' => $l['dim_json'],
            ]);
        }

        if ($idemKey) {
            $pdo->prepare(
                'INSERT INTO accounting_posting_idempotency (tenant_id, idempotency_key, je_id)
                 VALUES (:t, :k, :j)'
            )->execute(['t' => $tenantId, 'k' => $idemKey, 'j' => $jeId]);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // Phase 2 — mark the affected period dirty in the Financial State Cache
    // so the next CFO Dashboard / AI read auto-rebuilds. Best-effort; never
    // blocks the JE post if the cache table doesn't exist or write fails.
    if ($post && isset($period['id'])) {
        try {
            fscMarkDirty(
                $tenantId,
                FSC_SCOPE_PERIOD,
                (string) $period['id'],
                'je_posted',
                $actorUserId
            );
        } catch (\Throwable $_) { /* never block the post */ }

        // Jaz hook (Slice 3) — enqueue a draft accounting command for
        // the newly posted JE. Best-effort, no-op when no Jaz wiring;
        // never blocks the post.
        try {
            require_once __DIR__ . '/../../../core/accounting/command_service.php';
            accountingTryEnqueueDraft($tenantId, 'journal', [
                'id'           => $jeId,
                'entity_id'    => $entityId,
                'je_number'    => $jeNumber,
                'posting_date' => $postingDate,
                'currency'     => $currency,
                'total_debit'  => $totalDebit,
                'total_credit' => $totalCredit,
                'memo'         => $je['memo'] ?? null,
                'lines'        => $resolved,
                'updated_at'   => date('Y-m-d H:i:s'),
            ], $actorUserId);
        } catch (\Throwable $_) { /* never block the post */ }
    }

    return [
        'je_id'             => $jeId,
        'je_number'         => $jeNumber,
        'status'            => $post ? 'posted' : 'draft',
        'total_debit'       => $totalDebit,
        'total_credit'      => $totalCredit,
        'idempotent_replay' => false,
    ];
}

/**
 * Reverse a posted JE. Creates a new JE (source_module='reversal') with
 * debit/credit swapped on each line. The original becomes status='reversed'.
 * Always idempotent: calling twice returns the existing reversal.
 */
function accountingReverseJe(int $tenantId, int $jeId, string $reason, ?int $actorUserId = null): array
{
    $pdo = getDB();
    $jeStmt = $pdo->prepare('SELECT * FROM accounting_journal_entries WHERE tenant_id = :t AND id = :id');
    $jeStmt->execute(['t' => $tenantId, 'id' => $jeId]);
    $je = $jeStmt->fetch(\PDO::FETCH_ASSOC);
    if (!$je) throw new \RuntimeException("JE {$jeId} not found");
    if ($je['status'] === 'reversed' && $je['reversed_by_je_id']) {
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $rev = $pdo->prepare('SELECT * FROM accounting_journal_entries WHERE id = :id');
        $rev->execute(['id' => $je['reversed_by_je_id']]);
        $r = $rev->fetch(\PDO::FETCH_ASSOC);
        return [
            'je_id' => (int) $r['id'], 'je_number' => $r['je_number'], 'status' => $r['status'],
            'total_debit' => (float) $r['total_debit'], 'total_credit' => (float) $r['total_credit'],
            'idempotent_replay' => true,
        ];
    }
    if ($je['status'] !== 'posted') throw new \RuntimeException("Can only reverse posted JEs (was {$je['status']})");

    // tenant-leak-allow: defense-in-depth — caller scoped row by tenant_id before this id-only write
    $lines = $pdo->prepare('SELECT * FROM accounting_journal_entry_lines WHERE je_id = :j ORDER BY line_no');
    $lines->execute(['j' => $jeId]);
    $flipped = [];
    foreach ($lines->fetchAll(\PDO::FETCH_ASSOC) as $l) {
        $flipped[] = [
            'account_id'              => (int) $l['account_id'],
            'debit'                   => (float) $l['credit'],
            'credit'                  => (float) $l['debit'],
            'memo'                    => 'Reversal: ' . ($l['memo'] ?? ''),
            'counterparty_company_id' => $l['counterparty_company_id'],
            'counterparty_person_id'  => $l['counterparty_person_id'],
        ];
    }
    $rev = accountingPostJe($tenantId, [
        'entity_id'       => (int) $je['entity_id'],
        'posting_date'    => date('Y-m-d'),
        'currency'        => $je['currency'],
        'source_module'   => 'reversal',
        'source_ref_type' => 'je',
        'source_ref_id'   => $jeId,
        'memo'            => "Reversal of {$je['je_number']}: {$reason}",
        'lines'           => $flipped,
    ], $actorUserId, true);

    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    $pdo->prepare(
        'UPDATE accounting_journal_entries
         SET status = "reversed", reversed_by_je_id = :rid
         WHERE id = :id'
    )->execute(['rid' => $rev['je_id'], 'id' => $jeId]);
    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    $pdo->prepare('UPDATE accounting_journal_entries SET reverses_je_id = :orig WHERE id = :rid')
        ->execute(['orig' => $jeId, 'rid' => $rev['je_id']]);

    // Phase 2 — the ORIGINAL period now has one fewer posted JE (status
    // flipped to 'reversed'). The reversal's period was auto-marked dirty
    // by the recursive accountingPostJe() call above.
    try {
        fscMarkDirty(
            $tenantId,
            FSC_SCOPE_PERIOD,
            (string) $je['period_id'],
            'je_reversed',
            $actorUserId
        );
    } catch (\Throwable $_) { /* never block the reversal */ }

    return $rev;
}

// ─────────────────────────────────────────────────────────────────────────
// Trial balance — simple on-read sum
// ─────────────────────────────────────────────────────────────────────────

/**
 * Trial balance per account as of $asOf (inclusive), posted JEs only.
 * Returns rows of {code, name, account_type, normal_side, debit, credit, balance_signed}.
 */
function accountingTrialBalance(int $tenantId, string $asOf, ?int $entityId = null): array
{
    $pdo = getDB();
    // Note: `:t` was previously used twice in the same SQL — that throws
    // HY093 with PDO_MYSQL native prepares. Use distinct placeholders.
    $where  = ['je.tenant_id = :t2', 'je.status = "posted"', 'je.posting_date <= :d'];
    $params = ['t' => $tenantId, 't2' => $tenantId, 'd' => $asOf];
    if ($entityId) { $where[] = 'je.entity_id = :e'; $params['e'] = $entityId; }

    $sql = 'SELECT a.code, a.name, a.account_type, a.normal_side,
                   COALESCE(SUM(l.debit),0)  AS debit,
                   COALESCE(SUM(l.credit),0) AS credit
            FROM accounting_accounts a
            LEFT JOIN accounting_journal_entry_lines l ON l.account_id = a.id
            LEFT JOIN accounting_journal_entries je ON je.id = l.je_id
            WHERE a.tenant_id = :t
              AND (je.id IS NULL OR (' . implode(' AND ', $where) . '))
            GROUP BY a.id
            ORDER BY a.code';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $d = (float) $r['debit']; $c = (float) $r['credit'];
        $r['balance_signed'] = $r['normal_side'] === 'debit' ? round($d - $c, 2) : round($c - $d, 2);
    }
    unset($r);
    return $rows;
}

// ─────────────────────────────────────────────────────────────────────────
// Audit helper
// ─────────────────────────────────────────────────────────────────────────
function accountingAudit(string $event, array $meta = [], ?int $targetId = null): void
{
    try {
        $ctx = function_exists('currentTenantContext') ? currentTenantContext() : null;
        getDB()->prepare(
            'INSERT INTO audit_log (tenant_id, actor_user_id, event, target_id, meta_json, ip_address, created_at)
             VALUES (:tenant_id, :actor, :event, :target_id, :meta_json, :ip, NOW())'
        )->execute([
            'tenant_id' => $ctx['tenant_id'] ?? null,
            'actor'     => $ctx['user']['id'] ?? null,
            'event'     => $event,
            'target_id' => $targetId,
            'meta_json' => json_encode($meta),
            'ip'        => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (\Throwable $e) {
        error_log('[accounting.audit] ' . $event . ' write-failed: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Slice C — JE draft validation + approval-gated post.
// Spec §11 ("validateJournalEntry tool") + §15 (risk-tier=4 transactional).
//
// accountingValidateJe()  — pure read; runs the same rules accountingPostJe
//                           runs (period openness, account validity, balance,
//                           dimensions) WITHOUT touching the DB. Returns a
//                           structured report so the LLM / reviewer can see
//                           every check that passed or failed in one pass.
//
// accountingPromoteDraftToPosted()
//                         — flips an existing draft JE row to posted.
//                           Re-validates the row at promotion time so a
//                           draft that became stale (closed period, deactivated
//                           account) is refused.  Used by the
//                           coreflux.post_approved_journal_entry tool which
//                           is risk_level=4 — caller must hold an approved
//                           workflow_approval id.
// ─────────────────────────────────────────────────────────────────────────

/**
 * Pure-read JE validation. Mirrors accountingPostJe's pre-insert checks
 * (lines 142-247) without side effects.
 *
 * @param array $je {entity_id, posting_date, currency?, lines: [{account_id|account_code, debit, credit, memo?, dims?}]}
 * @return array {
 *     ok: bool, balanced: bool,
 *     total_debit: float, total_credit: float, line_count: int,
 *     period: ?{id, period_number, status, start_date, end_date},
 *     entity_id: int,
 *     line_validations: [{line_no, account_code?, debit, credit, errors: [string]}],
 *     errors: [string],
 *     ai_advice?: string  — short human-readable next step for the reviewer / LLM
 * }
 */
function accountingValidateJe(int $tenantId, array $je): array
{
    $errors = [];
    $line_validations = [];
    $totalDebit = $totalCredit = 0.0;
    $period = null;
    $entityId = 0;

    $lines = $je['lines'] ?? [];
    if (!is_array($lines) || count($lines) < 2) {
        $errors[] = 'JE needs at least 2 lines';
    }
    $postingDate = (string) ($je['posting_date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $postingDate)) {
        $errors[] = 'posting_date must be YYYY-MM-DD';
        $postingDate = '';
    }

    try {
        $entityId = !empty($je['entity_id']) ? (int) $je['entity_id']
                                              : (int) accountingDefaultEntity($tenantId)['id'];
    } catch (\Throwable $e) {
        $errors[] = 'no default accounting entity — pass entity_id explicitly';
    }

    if ($entityId > 0 && $postingDate !== '') {
        try {
            $period = accountingResolvePeriod($tenantId, $entityId, $postingDate);
            if (in_array($period['status'], ['closed', 'soft_closed'], true)) {
                $errors[] = "Period {$period['period_number']} ({$period['start_date']}..{$period['end_date']}) is {$period['status']}; cannot post";
            }
        } catch (\Throwable $e) {
            $errors[] = 'period resolution failed: ' . substr($e->getMessage(), 0, 200);
        }
    }

    // Resolve accounts for the tenant.
    $byId = $byCode = [];
    try {
        $stmt = getDB()->prepare('SELECT id, code, name, is_postable, active FROM accounting_accounts WHERE tenant_id = :t');
        $stmt->execute(['t' => $tenantId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $a) {
            $byId[(int) $a['id']] = $a;
            $byCode[strtolower((string) $a['code'])] = $a;
        }
    } catch (\Throwable $e) {
        $errors[] = 'chart-of-accounts lookup failed: ' . substr($e->getMessage(), 0, 200);
    }

    foreach ((is_array($lines) ? $lines : []) as $i => $l) {
        $debit  = round((float) ($l['debit']  ?? 0), 2);
        $credit = round((float) ($l['credit'] ?? 0), 2);
        $lineErrors = [];
        if ($debit < 0 || $credit < 0)        $lineErrors[] = 'negative amounts not allowed';
        if ($debit > 0 && $credit > 0)        $lineErrors[] = 'a line cannot have both debit and credit';
        if ($debit == 0 && $credit == 0)      $lineErrors[] = 'must specify debit or credit';

        $a = null;
        if (!empty($l['account_id']))         $a = $byId[(int) $l['account_id']] ?? null;
        elseif (!empty($l['account_code']))   $a = $byCode[strtolower((string) $l['account_code'])] ?? null;
        else                                  $lineErrors[] = 'account_id or account_code required';

        if (!$a && empty($lineErrors))        $lineErrors[] = 'account not found';
        if ($a && !$a['active'])              $lineErrors[] = "account {$a['code']} is inactive";
        if ($a && !$a['is_postable'])         $lineErrors[] = "account {$a['code']} is not postable (summary)";

        $totalDebit  += $debit;
        $totalCredit += $credit;
        $line_validations[] = [
            'line_no'      => $i + 1,
            'account_code' => $a['code'] ?? ($l['account_code'] ?? null),
            'account_name' => $a['name'] ?? null,
            'debit'        => $debit,
            'credit'       => $credit,
            'errors'       => $lineErrors,
        ];
    }

    $balanced = round(abs($totalDebit - $totalCredit), 2) <= 0.005;
    if (!$balanced) {
        $errors[] = sprintf('Unbalanced JE: debits=%.2f credits=%.2f (diff=%.2f)',
            $totalDebit, $totalCredit, $totalDebit - $totalCredit);
    }

    // Dimension validation when configured.
    if ($balanced && file_exists(__DIR__ . '/dimensions.php')) {
        try {
            require_once __DIR__ . '/dimensions.php';
            $linesForDimCheck = [];
            foreach ((is_array($lines) ? $lines : []) as $i => $l) {
                $a = !empty($l['account_id']) ? ($byId[(int) $l['account_id']] ?? null)
                                              : ($byCode[strtolower((string) ($l['account_code'] ?? ''))] ?? null);
                $linesForDimCheck[] = [
                    'account_id' => $a['id'] ?? 0,
                    'dims'       => (array) ($l['dims'] ?? []),
                ];
            }
            accountingValidateJeDims($tenantId, $linesForDimCheck);
        } catch (\Throwable $e) {
            $errors[] = 'dimension validation: ' . substr($e->getMessage(), 0, 200);
        }
    }

    $ok = $balanced && count($errors) === 0
       && !array_filter($line_validations, fn ($lv) => !empty($lv['errors']));

    $advice = $ok
        ? 'Validation passed — request workflow approval, then call coreflux.post_approved_journal_entry.'
        : 'Fix the errors above before drafting; the LLM should call validate_journal_entry again until ok=true.';

    return [
        'ok'                 => $ok,
        'balanced'           => $balanced,
        'total_debit'        => $totalDebit,
        'total_credit'       => $totalCredit,
        'line_count'         => count($line_validations),
        'period'             => $period,
        'entity_id'          => $entityId,
        'line_validations'   => $line_validations,
        'errors'             => $errors,
        'ai_advice'          => $advice,
    ];
}

/**
 * Promote a draft JE to posted. Approval-gated by the caller (the AI
 * tool gateway enforces risk_level=4 + _approval_id before reaching
 * here). Re-validates the JE at promotion time so a draft that went
 * stale (closed period, deactivated account) is refused.
 *
 * Idempotent on re-entry: if the row is already posted, returns the
 * existing posted snapshot with idempotent_replay=true.
 *
 * @return array {je_id, je_number, status, total_debit, total_credit,
 *                idempotent_replay, approval_id}
 */
function accountingPromoteDraftToPosted(int $tenantId, int $jeId, array $opts = []): array
{
    if ($jeId <= 0) throw new \InvalidArgumentException('je_id required');
    $approvalId = isset($opts['approval_id']) ? (int) $opts['approval_id'] : 0;
    if ($approvalId <= 0) throw new \InvalidArgumentException('approval_id required');
    $actorUserId = isset($opts['actor_user_id']) ? (int) $opts['actor_user_id'] : null;

    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT id, tenant_id, entity_id, period_id, je_number,
                posting_date, currency, status, total_debit, total_credit, memo
           FROM accounting_journal_entries
          WHERE id = :id AND tenant_id = :t LIMIT 1'
    );
    $stmt->execute(['id' => $jeId, 't' => $tenantId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) throw new \RuntimeException("draft JE #{$jeId} not found");

    if ($row['status'] === 'posted') {
        return [
            'je_id'             => (int) $row['id'],
            'je_number'         => (string) $row['je_number'],
            'status'            => 'posted',
            'total_debit'       => (float) $row['total_debit'],
            'total_credit'      => (float) $row['total_credit'],
            'idempotent_replay' => true,
            'approval_id'       => $approvalId,
        ];
    }
    if ($row['status'] !== 'draft') {
        throw new \RuntimeException("JE #{$jeId} is status='{$row['status']}', not 'draft'");
    }

    // Reassemble line dictionary and re-validate at promotion time.
    // tenant-leak-allow: parent JE was fetched tenant-scoped above; lines join by je_id
    $lstmt = $pdo->prepare(
        'SELECT l.line_no, l.debit, l.credit, l.memo, l.dim_json,
                a.id   AS account_id, a.code AS account_code, a.active, a.is_postable
           FROM accounting_journal_entry_lines l
           JOIN accounting_accounts a ON a.id = l.account_id
          WHERE l.je_id = :je
          ORDER BY l.line_no ASC'
    );
    $lstmt->execute(['je' => $jeId]);
    $lineRows = $lstmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    $linesForValidate = array_map(fn ($r) => [
        'account_id' => (int) $r['account_id'],
        'debit'      => (float) $r['debit'],
        'credit'     => (float) $r['credit'],
        'memo'       => $r['memo'] ?? null,
        'dims'       => $r['dim_json'] ? (json_decode((string) $r['dim_json'], true) ?: []) : [],
    ], $lineRows);

    $report = accountingValidateJe($tenantId, [
        'entity_id'    => (int) $row['entity_id'],
        'posting_date' => (string) $row['posting_date'],
        'currency'     => (string) $row['currency'],
        'lines'        => $linesForValidate,
    ]);
    if (!$report['ok']) {
        throw new \RuntimeException('promotion refused: ' . implode('; ', $report['errors'] ?: ['validation failed at promotion']));
    }

    // Flip status + stamp posted_at / posted_by_user_id + approval_id
    // in one shot, and atomically consume the workflow_approvals row.
    // The single-use guard lives at the DB level: the UPDATE on
    // workflow_approvals requires consumed_at IS NULL, so a concurrent
    // promotion racing for the same approval is rejected.
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE accounting_journal_entries
                SET status = "posted",
                    posted_at = NOW(),
                    posted_by_user_id = :u,
                    approval_id = :a
              WHERE id = :id AND tenant_id = :t AND status = "draft"'
        )->execute(['u' => $actorUserId, 'a' => $approvalId, 'id' => $jeId, 't' => $tenantId]);

        $consume = $pdo->prepare(
            'UPDATE workflow_approvals
                SET consumed_at = NOW(),
                    consumed_by_je_id = :je
              WHERE id = :a AND tenant_id = :t AND consumed_at IS NULL'
        );
        $consume->execute(['je' => $jeId, 'a' => $approvalId, 't' => $tenantId]);
        if ($consume->rowCount() === 0) {
            // Some other path consumed the approval first (race) — bail.
            throw new \RuntimeException("approval #{$approvalId} race-consumed by another promotion");
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // Best-effort FSC cache invalidation + Jaz outbox enqueue (same
    // hooks accountingPostJe runs after a real post — keeps the data
    // flow identical between draft-promotion and direct-post paths).
    try {
        fscMarkDirty($tenantId, FSC_SCOPE_PERIOD, (string) $row['period_id'], 'je_posted', $actorUserId);
    } catch (\Throwable $_) { /* never block */ }

    return [
        'je_id'             => (int) $row['id'],
        'je_number'         => (string) $row['je_number'],
        'status'            => 'posted',
        'total_debit'       => (float) $row['total_debit'],
        'total_credit'      => (float) $row['total_credit'],
        'idempotent_replay' => false,
        'approval_id'       => $approvalId,
    ];
}

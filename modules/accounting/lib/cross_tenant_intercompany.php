<?php
/**
 * Accounting — Cross-Tenant Intercompany Posting helper (sub-tenant aware).
 *
 * Distinct from `intercompany.php` (which handles entity-to-entity posting
 * INSIDE a single tenant). This helper posts a balanced *pair* of journal
 * entries across two SUB-TENANTS (different tenant_id), linked by a shared
 * `intercompany_ref` (also written to `accounting_journal_entries
 * .intercompany_group_id`) so a master_admin can always reconcile sister
 * tenants AND so the reversal helper below can find and reverse both legs
 * with a single call.
 *
 * If the second leg fails to post, the first leg is rolled back via a
 * compensating reversal so the books never carry an orphan side. (Cross-
 * tenant queries can't reliably ride one MySQL transaction once tenants
 * shard onto separate schemas in the future; compensating-reversal is the
 * forward-compatible pattern.)
 *
 * Memo and intercompany_ref propagate to both legs. Multi-currency: when
 * from_currency != to_currency the caller provides `fx_rate`; the to-leg
 * posts in to_currency with amount * fx_rate rounded to two decimals.
 *
 *   accountingPostCrossTenantIntercompany(
 *     fromTenantId, toTenantId,
 *     amount: 5000.00,
 *     memo: 'Q1 management fee',
 *     opts: [
 *       'from_account_code' => '1700',   // default 'Intercompany Receivable'
 *       'to_account_code'   => '2700',   // default 'Intercompany Payable'
 *       'from_offset_code'  => '1000',
 *       'to_offset_code'    => '1000',
 *       'from_currency'     => 'USD',
 *       'to_currency'       => 'EUR',
 *       'fx_rate'           => 0.92,
 *       'posting_date'      => '2026-02-15',
 *       'intercompany_ref'  => 'IC-2026-ABC123',
 *     ]
 *   );
 *
 * Reversal:
 *   accountingReverseCrossTenantIntercompany($ref, $reason, $actorUserId);
 */

declare(strict_types=1);

require_once __DIR__ . '/accounting.php';
require_once __DIR__ . '/../../../core/sub_tenants.php';

/**
 * Stamp the just-posted JE with the shared intercompany_group_id so the
 * reversal helper can find the pair without trawling memos. Best-effort:
 * a failure here is logged but does not block the posting.
 *
 * tenant-leak-allow: id is the JE we just received from our own accountingPostJe call; tenant_id pinned in WHERE
 */
function _cxIcStampGroupId(\PDO $pdo, int $tenantId, int $jeId, string $ref): void {
    try {
        $stmt = $pdo->prepare(
            'UPDATE accounting_journal_entries
                SET intercompany_group_id = :ref
              WHERE id = :id AND tenant_id = :t'
        );
        $stmt->execute(['ref' => $ref, 'id' => $jeId, 't' => $tenantId]);
    } catch (\Throwable $e) {
        error_log("cross_tenant_intercompany: failed to stamp group_id on JE {$jeId}: " . $e->getMessage());
    }
}

function accountingPostCrossTenantIntercompany(
    int $fromTenantId,
    int $toTenantId,
    float $amount,
    string $memo,
    array $opts = [],
    ?int $actorUserId = null
): array {
    if ($fromTenantId === $toTenantId) {
        throw new \InvalidArgumentException('from and to tenant must differ');
    }
    if ($amount <= 0) {
        throw new \InvalidArgumentException('amount must be > 0');
    }

    // Sanity: both tenants must share the same master parent.
    $from = subTenantLookup($fromTenantId);
    $to   = subTenantLookup($toTenantId);
    if (!$from || !$to) {
        throw new \InvalidArgumentException('tenant not found');
    }
    $fromMaster = $from['tenant_type'] === 'master' ? (int) $from['id'] : (int) ($from['parent_id'] ?? 0);
    $toMaster   = $to['tenant_type']   === 'master' ? (int) $to['id']   : (int) ($to['parent_id']   ?? 0);
    if ($fromMaster !== $toMaster || !$fromMaster) {
        throw new \InvalidArgumentException('cross-tenant intercompany requires the same master parent');
    }

    $fromAccountCode  = (string) ($opts['from_account_code']  ?? '1700');
    $toAccountCode    = (string) ($opts['to_account_code']    ?? '2700');
    $fromOffsetCode   = (string) ($opts['from_offset_code']   ?? '1000');
    $toOffsetCode     = (string) ($opts['to_offset_code']     ?? '1000');
    $postingDate      = (string) ($opts['posting_date']       ?? date('Y-m-d'));

    // ── Multi-currency: from_currency drives the from-leg; to_currency the
    //    to-leg. When they differ the caller MUST supply fx_rate (units of
    //    to-currency per unit of from-currency); to-leg amount = amount *
    //    fx_rate, rounded to cents. fx_rate must be > 0.
    $fromCurrency = strtoupper((string) ($opts['from_currency'] ?? $opts['currency'] ?? 'USD'));
    $toCurrency   = strtoupper((string) ($opts['to_currency']   ?? $fromCurrency));
    $fxRate       = (float)  ($opts['fx_rate']      ?? 1.0);
    if ($fromCurrency !== $toCurrency) {
        if ($fxRate <= 0) {
            throw new \InvalidArgumentException(
                "fx_rate must be > 0 when from_currency ({$fromCurrency}) != to_currency ({$toCurrency})"
            );
        }
    } else {
        // Same currency: force 1.0 unless explicitly overridden (and even then
        // a zero/negative rate is corrected) — prevents silent precision drift.
        if ($fxRate <= 0) $fxRate = 1.0;
    }
    $toAmount = round($amount * $fxRate, 2);
    if ($toAmount <= 0) {
        throw new \InvalidArgumentException("computed to-leg amount must be > 0 (got {$toAmount})");
    }

    $ref = (string) ($opts['intercompany_ref']
        ?? sprintf('IC-%s-%s', date('Y'), strtoupper(bin2hex(random_bytes(3)))));
    if (!preg_match('/^[A-Za-z0-9_-]{4,64}$/', $ref)) {
        throw new \InvalidArgumentException("intercompany_ref must match /^[A-Za-z0-9_-]{4,64}$/");
    }

    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('No database connection');

    $fxNote = $fromCurrency === $toCurrency
        ? ''
        : sprintf(' [FX %s→%s @ %.6f]', $fromCurrency, $toCurrency, $fxRate);

    // ── Post the FROM leg first (Dr IC Receivable / Cr cash). ──
    $fromJe = accountingPostJe($fromTenantId, [
        'posting_date'    => $postingDate,
        'currency'        => $fromCurrency,
        'source_module'   => 'cross_tenant_intercompany',
        'description'     => $memo . " [{$ref}]" . $fxNote,
        'memo'            => $memo . " [{$ref}]" . $fxNote,
        'idempotency_key' => "cross_intercompany:{$ref}:from",
        'lines' => [
            ['account_code' => $fromAccountCode, 'debit'  => $amount, 'memo' => $memo . $fxNote],
            ['account_code' => $fromOffsetCode,  'credit' => $amount, 'memo' => $memo . $fxNote],
        ],
    ], $actorUserId, true);

    _cxIcStampGroupId($pdo, $fromTenantId, (int) $fromJe['je_id'], $ref);

    // ── Post the TO leg (Dr cash / Cr IC Payable) in to-currency. If this
    //    fails, compensate by reversing the from-leg so the books never
    //    carry a half-posted intercompany pair.
    try {
        $toJe = accountingPostJe($toTenantId, [
            'posting_date'    => $postingDate,
            'currency'        => $toCurrency,
            'source_module'   => 'cross_tenant_intercompany',
            'description'     => $memo . " [{$ref}]" . $fxNote,
            'memo'            => $memo . " [{$ref}]" . $fxNote,
            'idempotency_key' => "cross_intercompany:{$ref}:to",
            'lines' => [
                ['account_code' => $toOffsetCode,  'debit'  => $toAmount, 'memo' => $memo . $fxNote],
                ['account_code' => $toAccountCode, 'credit' => $toAmount, 'memo' => $memo . $fxNote],
            ],
        ], $actorUserId, true);
    } catch (\Throwable $e) {
        try {
            accountingReverseJe(
                $fromTenantId,
                (int) $fromJe['je_id'],
                "Cross-tenant intercompany {$ref} aborted: to-leg post failed (" . $e->getMessage() . ')',
                $actorUserId
            );
        } catch (\Throwable $compErr) {
            error_log("cross_tenant_intercompany: compensating reversal FAILED for {$ref} from-leg JE {$fromJe['je_id']}: " . $compErr->getMessage());
        }
        throw $e;
    }

    _cxIcStampGroupId($pdo, $toTenantId, (int) $toJe['je_id'], $ref);

    // ── Symmetric audit on BOTH sides: master sees a sent + received row so
    //    reconciliation is one SELECT. Best-effort; logging never blocks. ──
    try {
        subTenantAudit($fromMaster, $fromTenantId, $actorUserId, 'cross_tenant.intercompany.posted', [
            'ref'         => $ref,
            'direction'   => 'out',
            'amount'      => $amount,
            'currency'    => $fromCurrency,
            'memo'        => $memo,
            'to_tenant'   => $toTenantId,
            'fx_rate'     => $fxRate,
            'from_je_id'  => $fromJe['je_id'],
            'to_je_id'    => $toJe['je_id'],
        ]);
        subTenantAudit($toMaster, $toTenantId, $actorUserId, 'cross_tenant.intercompany.received', [
            'ref'         => $ref,
            'direction'   => 'in',
            'amount'      => $toAmount,
            'currency'    => $toCurrency,
            'memo'        => $memo,
            'from_tenant' => $fromTenantId,
            'fx_rate'     => $fxRate,
            'from_je_id'  => $fromJe['je_id'],
            'to_je_id'    => $toJe['je_id'],
        ]);
    } catch (\Throwable $auditErr) {
        error_log("cross_tenant_intercompany: audit log failed for {$ref}: " . $auditErr->getMessage());
    }

    return [
        'intercompany_ref' => $ref,
        'amount'           => $amount,
        'to_amount'        => $toAmount,
        'fx_rate'          => $fxRate,
        'memo'             => $memo,
        'from' => [
            'tenant_id' => $fromTenantId,
            'je_id'     => (int) $fromJe['je_id'],
            'je_number' => $fromJe['je_number'],
            'currency'  => $fromCurrency,
        ],
        'to' => [
            'tenant_id' => $toTenantId,
            'je_id'     => (int) $toJe['je_id'],
            'je_number' => $toJe['je_number'],
            'currency'  => $toCurrency,
        ],
    ];
}

/**
 * Reverse both legs of a cross-tenant intercompany pair identified by its
 * shared `intercompany_ref`. Idempotent: legs already reversed are reported
 * as `idempotent_replay`.
 *
 *   accountingReverseCrossTenantIntercompany(
 *     'IC-2026-ABC123',
 *     'Posted in error — vendor cancelled',
 *     $actorUserId
 *   );
 *
 * tenant-leak-allow: master-admin cross-tenant lookup by globally-unique IC ref column
 */
function accountingReverseCrossTenantIntercompany(
    string $ref,
    string $reason,
    ?int $actorUserId = null
): array {
    if (!preg_match('/^[A-Za-z0-9_-]{4,64}$/', $ref)) {
        throw new \InvalidArgumentException("intercompany_ref must match /^[A-Za-z0-9_-]{4,64}$/");
    }

    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('No database connection');

    // tenant-leak-allow: master-admin cross-tenant lookup by globally-unique IC ref
    $stmt = $pdo->prepare(
        "SELECT id, tenant_id, source_module, status
           FROM accounting_journal_entries
          WHERE intercompany_group_id = :ref
            AND source_module = 'cross_tenant_intercompany'
          ORDER BY id ASC"
    );
    $stmt->execute(['ref' => $ref]);
    $legs = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    // Filter out reversal rows (we stamped reversal JEs with the same ref so
    // the worksheet shows them; we mustn't reverse a reversal).
    $legs = array_values(array_filter($legs, function ($l) {
        // Original posts have status 'posted' or 'reversed'; reversal-of-reversal would
        // be 'posted' too but lives under source_module='reversal'. We filtered on
        // source_module=cross_tenant_intercompany above, so any stamped reversal won't
        // appear here — accountingReverseJe writes the new JE with source_module='reversal'.
        return true;
    }));
    if (count($legs) === 0) {
        throw new \RuntimeException("No cross-tenant intercompany legs found for ref {$ref}");
    }
    if (count($legs) === 1) {
        error_log("cross_tenant_intercompany: only one leg found for ref {$ref}; reversing what we have");
    }

    $reversals = [];
    foreach ($legs as $leg) {
        $tid = (int) $leg['tenant_id'];
        $jid = (int) $leg['id'];
        $rev = accountingReverseJe($tid, $jid, "Cross-tenant intercompany {$ref}: {$reason}", $actorUserId);
        _cxIcStampGroupId($pdo, $tid, (int) $rev['je_id'], $ref);
        $reversals[] = [
            'tenant_id'          => $tid,
            'original_je_id'     => $jid,
            'reversal_je_id'     => (int) $rev['je_id'],
            'reversal_je_number' => $rev['je_number'],
            'idempotent_replay'  => $rev['idempotent_replay'] ?? false,
        ];
    }

    try {
        $firstTid = (int) $legs[0]['tenant_id'];
        $masterRow = subTenantLookup($firstTid);
        $masterTid = $masterRow && $masterRow['tenant_type'] === 'master'
            ? (int) $masterRow['id']
            : (int) ($masterRow['parent_id'] ?? 0);
        if ($masterTid) {
            subTenantAudit($masterTid, $firstTid, $actorUserId, 'cross_tenant.intercompany.reversed', [
                'ref'       => $ref,
                'reason'    => $reason,
                'reversals' => $reversals,
            ]);
        }
    } catch (\Throwable $auditErr) {
        error_log("cross_tenant_intercompany: reversal audit log failed for {$ref}: " . $auditErr->getMessage());
    }

    return [
        'intercompany_ref' => $ref,
        'reason'           => $reason,
        'reversals'        => $reversals,
    ];
}

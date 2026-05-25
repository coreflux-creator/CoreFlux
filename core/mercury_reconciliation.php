<?php
/**
 * core/mercury_reconciliation.php — Slice 4 reconciliation engine.
 *
 * Walks every payment_instructions row in `Settled` state, joins it to
 * mercury_transactions by payout_mercury_txn_id, verifies the amount +
 * currency match, then advances the row to `Reconciled` and writes an
 * audit row to reconciliation_matches. Also maintains the optional
 * funding_transfers ledger view from the payment_instructions funding
 * leg whenever a row reaches the funding-cleared milestone.
 *
 * NEVER hits Mercury — operates purely on already-synced local data
 * (mercury_transactions is populated by core/mercury_service.php). That
 * keeps reconciliation fast and idempotent.
 *
 * Public surface:
 *   mercuryReconcileTenant(int $tenantId): array         — engine entry
 *   mercuryReconciliationStats(int $tenantId): array      — UI summary
 *   mercuryReconciliationMatches(int $tenantId, ?int $instructionId = null): array
 *   mercuryUpsertFundingTransfer(int $tenantId, array $row): void
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mercury_payments.php';

/**
 * Reconcile every Settled payment_instructions row for a tenant.
 * Idempotent — re-running over the same row updates the existing
 * reconciliation_matches row (via UNIQUE).
 *
 * Returns:
 *   [
 *     'scanned'    => int,
 *     'matched'    => int,
 *     'discrepancies' => int,
 *     'missing'    => int,
 *   ]
 */
function mercuryReconcileTenant(int $tenantId): array
{
    $out = ['scanned' => 0, 'matched' => 0, 'discrepancies' => 0, 'missing' => 0];
    try {
        $pdo = getDB();
    } catch (\Throwable $e) {
        return $out;
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT id, idempotency_key, currency, amount_cents,
                    payout_mercury_txn_id, operating_mercury_account_id,
                    funding_recipient_id, funding_mercury_txn_id,
                    funding_mercury_status, funding_initiated_at, funding_settled_at
               FROM payment_instructions
              WHERE tenant_id = :t AND state = "Settled" AND reconciled_at IS NULL
              ORDER BY payout_settled_at ASC, id ASC
              LIMIT 500'
        );
        $stmt->execute(['t' => $tenantId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return $out;
    }

    foreach ($rows as $row) {
        $out['scanned']++;
        $verdict = mercuryReconcileOne($tenantId, $row);
        $out[$verdict] = ($out[$verdict] ?? 0) + 1;
        // Side-effect: keep funding_transfers ledger in sync.
        mercuryUpsertFundingTransfer($tenantId, $row);
    }
    // Slice 4 funding-leg reconciliation: walk every row whose funding leg
    // has a Mercury transaction id (regardless of high-level state) and
    // record the match outcome with leg='funding'. Doesn't drive state
    // transitions (the payout leg is what advances Settled→Reconciled);
    // this exists for the audit trail + treasury-ops visibility.
    $fundingCounts = mercuryReconcileFundingLeg($tenantId);
    $out['funding_matched']       = $fundingCounts['matched'];
    $out['funding_discrepancies'] = $fundingCounts['discrepancies'];
    $out['funding_missing']       = $fundingCounts['missing'];
    return $out;
}

/**
 * Slice 4 extension — reconcile every payment_instructions row whose
 * funding leg has been originated (`funding_mercury_txn_id IS NOT NULL`).
 * Pure audit, no state transitions. Idempotent via the same UNIQUE on
 * reconciliation_matches (now keyed by leg='funding').
 */
function mercuryReconcileFundingLeg(int $tenantId): array
{
    $out = ['matched' => 0, 'discrepancies' => 0, 'missing' => 0];
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            'SELECT id, currency, amount_cents, funding_mercury_txn_id
               FROM payment_instructions
              WHERE tenant_id = :t AND funding_mercury_txn_id IS NOT NULL
                AND funding_mercury_txn_id <> ""
              ORDER BY funding_initiated_at ASC, id ASC
              LIMIT 500'
        );
        $stmt->execute(['t' => $tenantId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return $out;
    }

    foreach ($rows as $row) {
        $piId  = (int) $row['id'];
        $txnId = (string) $row['funding_mercury_txn_id'];

        $mt = $pdo->prepare(
            'SELECT id, amount_cents, currency
               FROM mercury_transactions
              WHERE tenant_id = :t AND mercury_txn_id = :tx LIMIT 1'
        );
        $mt->execute(['t' => $tenantId, 'tx' => $txnId]);
        $found = $mt->fetch(\PDO::FETCH_ASSOC);

        if (!$found) {
            mercuryRecordMatch($tenantId, $piId, null, $txnId, 'funding', 'missing_mercury_txn',
                (int) $row['amount_cents'], null,
                'funding mercury_transactions row not yet synced');
            $out['missing']++;
            continue;
        }
        $expected = (int) $row['amount_cents'];
        $observed = abs((int) $found['amount_cents']);
        $expectedCur = (string) ($row['currency'] ?? 'USD');
        $observedCur = (string) ($found['currency'] ?? 'USD');

        if ($observed !== $expected) {
            mercuryRecordMatch($tenantId, $piId, (int) $found['id'], $txnId, 'funding', 'discrepancy',
                $expected, $observed,
                "funding amount mismatch (expected {$expected} cents, observed {$observed} cents)");
            $out['discrepancies']++;
            continue;
        }
        if (strcasecmp($expectedCur, $observedCur) !== 0) {
            mercuryRecordMatch($tenantId, $piId, (int) $found['id'], $txnId, 'funding', 'discrepancy',
                $expected, $observed,
                "funding currency mismatch (expected {$expectedCur}, observed {$observedCur})");
            $out['discrepancies']++;
            continue;
        }
        mercuryRecordMatch($tenantId, $piId, (int) $found['id'], $txnId, 'funding', 'matched',
            $expected, $observed, null);
        $out['matched']++;
    }
    return $out;
}

/**
 * Reconcile a single payment_instructions row. Returns the verdict bucket:
 *   'matched' | 'discrepancies' | 'missing'
 */
function mercuryReconcileOne(int $tenantId, array $row): string
{
    $pdo  = getDB();
    $piId = (int) $row['id'];
    $txnId = (string) ($row['payout_mercury_txn_id'] ?? '');

    if ($txnId === '') {
        mercuryRecordMatch($tenantId, $piId, null, '', 'payout', 'missing_mercury_txn',
            (int) $row['amount_cents'], null, 'no payout_mercury_txn_id on instruction');
        return 'missing';
    }

    $mt = $pdo->prepare(
        'SELECT id, amount_cents, currency, status
           FROM mercury_transactions
          WHERE tenant_id = :t AND mercury_txn_id = :tx LIMIT 1'
    );
    $mt->execute(['t' => $tenantId, 'tx' => $txnId]);
    $found = $mt->fetch(\PDO::FETCH_ASSOC);

    if (!$found) {
        mercuryRecordMatch($tenantId, $piId, null, $txnId, 'payout', 'missing_mercury_txn',
            (int) $row['amount_cents'], null,
            'mercury_transactions row not yet synced (cron sync lag)');
        return 'missing';
    }

    $expected = (int) $row['amount_cents'];
    $observed = abs((int) $found['amount_cents']);   // mercury_transactions amounts are signed (negative outflow)
    $expectedCur = (string) ($row['currency'] ?? 'USD');
    $observedCur = (string) ($found['currency'] ?? 'USD');

    if ($observed !== $expected) {
        mercuryRecordMatch($tenantId, $piId, (int) $found['id'], $txnId, 'payout', 'discrepancy',
            $expected, $observed,
            "amount mismatch (expected {$expected} cents, observed {$observed} cents)");
        return 'discrepancies';
    }
    if (strcasecmp($expectedCur, $observedCur) !== 0) {
        mercuryRecordMatch($tenantId, $piId, (int) $found['id'], $txnId, 'payout', 'discrepancy',
            $expected, $observed,
            "currency mismatch (expected {$expectedCur}, observed {$observedCur})");
        return 'discrepancies';
    }

    // Perfect match — record + advance to Reconciled.
    mercuryRecordMatch($tenantId, $piId, (int) $found['id'], $txnId, 'payout', 'matched',
        $expected, $observed, null);

    try {
        mpTransition($tenantId, $piId, 'Reconciled', 'matched against mercury_transactions', null, [
            'reconciled_at' => date('Y-m-d H:i:s'),
        ], ['mercury_txn_id' => $txnId, 'mercury_txn_pk' => (int) $found['id']]);
    } catch (\Throwable $e) {
        // If the transition fails (e.g., illegal state) leave the match row in place
        // for human inspection rather than crashing the reconciliation worker.
    }
    return 'matched';
}

/**
 * Insert a reconciliation_matches row. Idempotent on the composite UNIQUE
 * so re-runs collapse cleanly.
 */
function mercuryRecordMatch(
    int $tenantId, int $instructionId, ?int $mercuryTxnPk, string $mercuryTxnId,
    string $leg, string $outcome, ?int $expected, ?int $observed, ?string $reason
): void {
    try {
        $pdo = getDB();
        $pdo->prepare(
            'INSERT INTO reconciliation_matches
                (tenant_id, instruction_id, mercury_txn_pk, mercury_txn_id, leg, outcome,
                 expected_amount_cents, observed_amount_cents, discrepancy_reason)
             VALUES (:t, :pi, :pk, :tx, :lg, :oc, :ex, :ob, :rn)
             ON DUPLICATE KEY UPDATE
                mercury_txn_pk        = VALUES(mercury_txn_pk),
                mercury_txn_id        = VALUES(mercury_txn_id),
                expected_amount_cents = VALUES(expected_amount_cents),
                observed_amount_cents = VALUES(observed_amount_cents),
                discrepancy_reason    = VALUES(discrepancy_reason),
                matched_at            = NOW()'
        )->execute([
            't'  => $tenantId,
            'pi' => $instructionId,
            'pk' => $mercuryTxnPk,
            'tx' => $mercuryTxnId,
            'lg' => $leg,
            'oc' => $outcome,
            'ex' => $expected,
            'ob' => $observed,
            'rn' => $reason ? substr($reason, 0, 240) : null,
        ]);
    } catch (\Throwable $e) {
        error_log('[mercury.reconciliation] record_match failed: ' . $e->getMessage());
    }
}

/**
 * Upsert the funding_transfers ledger view from a payment_instructions row.
 * Idempotent on instruction_id.
 */
function mercuryUpsertFundingTransfer(int $tenantId, array $row): void
{
    if (empty($row['funding_mercury_txn_id'])) return;
    try {
        $pdo = getDB();
        $pdo->prepare(
            'INSERT INTO funding_transfers
                (tenant_id, instruction_id, funding_recipient_id, mercury_account_id,
                 mercury_txn_id, amount_cents, status, initiated_at, settled_at)
             VALUES (:t, :pi, :fr, :ma, :tx, :a, :st, :ia, :sa)
             ON DUPLICATE KEY UPDATE
                mercury_txn_id  = VALUES(mercury_txn_id),
                status          = VALUES(status),
                settled_at      = VALUES(settled_at),
                updated_at      = NOW()'
        )->execute([
            't'  => $tenantId,
            'pi' => (int) $row['id'],
            'fr' => $row['funding_recipient_id'] ?? null,
            'ma' => $row['operating_mercury_account_id'] ?? null,
            'tx' => $row['funding_mercury_txn_id'],
            'a'  => (int) $row['amount_cents'],
            'st' => $row['funding_mercury_status'] ?? null,
            'ia' => $row['funding_initiated_at'] ?? null,
            'sa' => $row['funding_settled_at'] ?? null,
        ]);
    } catch (\Throwable $e) {
        // best-effort
    }
}

/**
 * Summary stats for the UI dashboard tile.
 * Returns counters per state + reconciliation-lag indicator (Settled
 * rows that haven't been reconciled within the last 24h).
 */
function mercuryReconciliationStats(int $tenantId): array
{
    $out = [
        'settled_unreconciled' => 0,
        'reconciled_total'     => 0,
        'discrepancies_open'   => 0,
        'missing_mercury_txn'  => 0,
        'oldest_unreconciled'  => null,
    ];
    try {
        $pdo = getDB();
    } catch (\Throwable $e) { return $out; }
    try {
        $r = $pdo->prepare(
            'SELECT
                SUM(state = "Settled"    AND reconciled_at IS NULL) AS settled_unreconciled,
                SUM(state = "Reconciled")                           AS reconciled_total,
                MIN(CASE WHEN state="Settled" AND reconciled_at IS NULL THEN payout_settled_at END) AS oldest
               FROM payment_instructions WHERE tenant_id = :t'
        );
        $r->execute(['t' => $tenantId]);
        $row = $r->fetch(\PDO::FETCH_ASSOC) ?: [];
        $out['settled_unreconciled'] = (int) ($row['settled_unreconciled'] ?? 0);
        $out['reconciled_total']     = (int) ($row['reconciled_total']     ?? 0);
        $out['oldest_unreconciled']  = $row['oldest'] ?? null;

        $disc = $pdo->prepare(
            'SELECT
               SUM(outcome = "discrepancy")          AS discrepancies,
               SUM(outcome = "missing_mercury_txn")  AS missing
               FROM reconciliation_matches
              WHERE tenant_id = :t
                AND matched_at = (SELECT MAX(matched_at) FROM reconciliation_matches r2
                                    WHERE r2.tenant_id = :t2 AND r2.instruction_id = reconciliation_matches.instruction_id)'
        );
        $disc->execute(['t' => $tenantId, 't2' => $tenantId]);
        $d = $disc->fetch(\PDO::FETCH_ASSOC) ?: [];
        $out['discrepancies_open'] = (int) ($d['discrepancies'] ?? 0);
        $out['missing_mercury_txn'] = (int) ($d['missing'] ?? 0);
    } catch (\Throwable $e) {}
    return $out;
}

function mercuryReconciliationMatches(int $tenantId, ?int $instructionId = null, ?string $outcome = null): array
{
    try {
        $pdo = getDB();
        $sql = 'SELECT rm.*, pi.amount_cents AS pi_amount, pi.currency AS pi_currency,
                       pi.state AS pi_state, r.name AS recipient_name
                  FROM reconciliation_matches rm
                  LEFT JOIN payment_instructions pi ON pi.id = rm.instruction_id AND pi.tenant_id = rm.tenant_id
                  LEFT JOIN mercury_recipients r    ON r.id = pi.recipient_id     AND r.tenant_id  = rm.tenant_id
                 WHERE rm.tenant_id = :t';
        $params = ['t' => $tenantId];
        if ($instructionId !== null) { $sql .= ' AND rm.instruction_id = :i'; $params['i'] = $instructionId; }
        if ($outcome !== null && $outcome !== '') {
            if (!in_array($outcome, ['matched','discrepancy','missing_mercury_txn'], true)) return [];
            $sql .= ' AND rm.outcome = :o'; $params['o'] = $outcome;
        }
        $sql .= ' ORDER BY rm.matched_at DESC LIMIT 200';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Reconciliation workbench feed — payment_instructions that are
 * Settled (Mercury confirmed the payout cleared) but haven't been
 * tied to an incoming bank-feed transaction yet.
 *
 * The 3-pane UI's LEFT column lives off this list. The MIDDLE column
 * is populated by clicking "Run auto-match" (which kicks the existing
 * mercuryReconcileTenant() engine). The RIGHT column shows the
 * already-reconciled rows via mercuryReconciliationMatches().
 *
 * Returns up to 100 rows ordered by oldest-unreconciled-first so
 * stale items surface immediately.
 */
function mercuryReconciliationUnmatched(int $tenantId, int $limit = 100): array
{
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            'SELECT pi.id, pi.amount_cents, pi.currency, pi.state, pi.memo,
                    pi.payout_mercury_txn_id, pi.payout_initiated_at,
                    pi.payout_settled_at, pi.payout_last_polled_at,
                    pi.funding_mercury_txn_id, pi.funding_settled_at,
                    r.name AS recipient_name, r.email AS recipient_email
               FROM payment_instructions pi
               LEFT JOIN mercury_recipients r ON r.id = pi.recipient_id AND r.tenant_id = pi.tenant_id
              WHERE pi.tenant_id = :t
                AND pi.state IN ("Settled")
                AND pi.reconciled_at IS NULL
              ORDER BY pi.payout_settled_at ASC, pi.id ASC
              LIMIT ' . (int) max(1, min(500, $limit))
        );
        $stmt->execute(['t' => $tenantId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['id']           = (int) $r['id'];
            $r['amount_cents'] = (int) $r['amount_cents'];
        }
        return $rows;
    } catch (\Throwable $e) {
        return [];
    }
}

<?php
/**
 * core/qbo/sync_in_arap.php
 *
 * QBO two-way sync — Phase 1 (AR: Invoice + Payment + Deposit) +
 * Phase 2 (AP: Bill + BillPayment) inbound pull.
 *
 * Architecture (pull → shadow → drift):
 *
 *   1. Page through QBO Query API by entity type.
 *   2. Upsert each row verbatim into the matching qbo_inbound_* shadow
 *      table (full payload in raw_payload, key fields denormalised).
 *   3. Resolve the CoreFlux link via `external_entity_mappings`
 *      (source = 'quickbooks_online'). When a link exists, populate
 *      `coreflux_invoice_id` / `coreflux_bill_id` for fast joins.
 *   4. Compute drift against the live CoreFlux row (only for Invoice
 *      and Bill — the entities where we own the source side).
 *   5. Write drift rows to `qbo_sync_drift` with the canonical
 *      drift_kind taxonomy ('balance_changed', 'paid_out_of_band',
 *      'amount_changed', 'voided_in_qbo', 'qbo_only_orphan').
 *
 * Drift is intentionally surfaced — NOT auto-applied. The operator
 * triages each row in /api/admin/qbo/sync_drift.php and decides
 * whether to (a) accept QBO as truth, (b) re-push CoreFlux to QBO,
 * (c) dismiss as expected.
 *
 * Public surface:
 *   qboPullInvoices(int $tid, array $opts=[]): array      // Phase 1
 *   qboPullPayments(int $tid, array $opts=[]): array      // Phase 1
 *   qboPullDeposits(int $tid, array $opts=[]): array      // Phase 1
 *   qboPullBills(int $tid, array $opts=[]): array         // Phase 2
 *   qboPullBillPayments(int $tid, array $opts=[]): array  // Phase 2
 *
 * Opts: limit (default 1000), max_pages (default 10), modified_since (ISO datetime; default null = full pull)
 */
declare(strict_types=1);

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/../integrations/entity_mappings.php';
require_once __DIR__ . '/sync_je.php'; // for QBO_SOURCE constant

const QBO_PULL_PAGE = 100;

// ─────────────────────────────────────────────────────────────────────
// Phase 1 — AR
// ─────────────────────────────────────────────────────────────────────

function qboPullInvoices(int $tenantId, array $opts = []): array
{
    return _qboPullEntity($tenantId, $opts, [
        'qbo_resource' => 'Invoice',
        'shadow_table' => 'qbo_inbound_invoices',
        'id_column'    => 'qbo_invoice_id',
        'mapping_type' => 'invoice',
        'upsert'       => '_qboShadowInvoice',
    ]);
}

function qboPullPayments(int $tenantId, array $opts = []): array
{
    return _qboPullEntity($tenantId, $opts, [
        'qbo_resource' => 'Payment',
        'shadow_table' => 'qbo_inbound_payments',
        'id_column'    => 'qbo_payment_id',
        'mapping_type' => null, // no direct CoreFlux row mapping
        'upsert'       => '_qboShadowPayment',
    ]);
}

function qboPullDeposits(int $tenantId, array $opts = []): array
{
    return _qboPullEntity($tenantId, $opts, [
        'qbo_resource' => 'Deposit',
        'shadow_table' => 'qbo_inbound_deposits',
        'id_column'    => 'qbo_deposit_id',
        'mapping_type' => null,
        'upsert'       => '_qboShadowDeposit',
    ]);
}

// ─────────────────────────────────────────────────────────────────────
// Phase 2 — AP
// ─────────────────────────────────────────────────────────────────────

function qboPullBills(int $tenantId, array $opts = []): array
{
    return _qboPullEntity($tenantId, $opts, [
        'qbo_resource' => 'Bill',
        'shadow_table' => 'qbo_inbound_bills',
        'id_column'    => 'qbo_bill_id',
        'mapping_type' => 'bill',
        'upsert'       => '_qboShadowBill',
    ]);
}

function qboPullBillPayments(int $tenantId, array $opts = []): array
{
    return _qboPullEntity($tenantId, $opts, [
        'qbo_resource' => 'BillPayment',
        'shadow_table' => 'qbo_inbound_billpayments',
        'id_column'    => 'qbo_billpayment_id',
        'mapping_type' => null,
        'upsert'       => '_qboShadowBillPayment',
    ]);
}

// ─────────────────────────────────────────────────────────────────────
// Shared pull engine
// ─────────────────────────────────────────────────────────────────────

function _qboPullEntity(int $tenantId, array $opts, array $cfg): array
{
    $start    = microtime(true);
    $limit    = max(1, min(5000, (int) ($opts['limit'] ?? 1000)));
    $maxPages = max(1, min(50,   (int) ($opts['max_pages'] ?? 10)));
    $since    = (string) ($opts['modified_since'] ?? '');

    $conn = qboConnection($tenantId);
    if (!$conn || $conn['status'] !== 'active') {
        throw new \RuntimeException('QuickBooks is not connected for this tenant');
    }
    $realm     = (string) $conn['realm_id'];
    $resource  = $cfg['qbo_resource'];
    $upsertFn  = $cfg['upsert'];

    $created = 0; $updated = 0; $unchanged = 0; $failed = 0; $driftCount = 0;
    $startPos = 1; $pulled = 0; $pages = 0;

    while ($pulled < $limit && $pages < $maxPages) {
        $pages++;
        $pageSize = min(QBO_PULL_PAGE, $limit - $pulled);
        // QBO Query API uses backtick-bounded LastUpdatedTime filter.
        $where = '';
        if ($since !== '') {
            $where = " WHERE MetaData.LastUpdatedTime >= '" . addslashes($since) . "' ";
        }
        $query = "SELECT * FROM {$resource}{$where} STARTPOSITION {$startPos} MAXRESULTS {$pageSize}";
        try {
            $resp = qboCall($tenantId, 'GET', '/v3/company/' . $realm . '/query', null, [
                'query'        => $query,
                'minorversion' => 65,
            ]);
        } catch (\Throwable $e) {
            qboAudit($tenantId, 'pull_' . strtolower($resource) . '_error', [
                'ok' => false, 'direction' => 'pull', 'entity_type' => strtolower($resource),
                'detail' => ['error' => substr($e->getMessage(), 0, 500), 'page' => $pages],
            ]);
            throw $e;
        }
        $rows = $resp['QueryResponse'][$resource] ?? [];
        if (!is_array($rows) || count($rows) === 0) break;

        foreach ($rows as $row) {
            try {
                $r = $upsertFn($tenantId, $row);
                $a = $r['action'] ?? 'unchanged';
                if      ($a === 'created') $created++;
                elseif  ($a === 'updated') $updated++;
                else                       $unchanged++;
                $driftCount += (int) ($r['drift_rows_written'] ?? 0);
            } catch (\Throwable $e) {
                $failed++;
            }
        }
        $pulled += count($rows);
        if (count($rows) < $pageSize) break;
        $startPos += count($rows);
    }

    qboAudit($tenantId, 'pull_' . strtolower($resource), [
        'entity_type' => strtolower($resource), 'direction' => 'pull',
        'ok' => $failed === 0,
        'items_processed' => $created + $updated,
        'items_skipped'   => $unchanged,
        'items_failed'    => $failed,
        'detail' => [
            'created' => $created, 'updated' => $updated, 'unchanged' => $unchanged,
            'failed'  => $failed,  'drift_rows_written' => $driftCount,
            'pulled'  => $pulled,  'pages' => $pages,
            'latency_ms' => (int) round((microtime(true) - $start) * 1000),
            'since'   => $since,
        ],
    ]);

    return [
        'ok' => $failed === 0, 'pulled' => $pulled,
        'created' => $created, 'updated' => $updated, 'unchanged' => $unchanged,
        'failed'  => $failed,  'drift_rows_written' => $driftCount,
        'pages'   => $pages,
    ];
}

// ─────────────────────────────────────────────────────────────────────
// Upserts — one per shadow table
// ─────────────────────────────────────────────────────────────────────

function _qboShadowInvoice(int $tenantId, array $q): array
{
    $qboId   = (string) ($q['Id'] ?? '');
    $bal     = (int) round(((float) ($q['Balance']     ?? 0)) * 100);
    $total   = (int) round(((float) ($q['TotalAmt']    ?? 0)) * 100);
    $linkRow = _qboFindMapping($tenantId, 'invoice', $qboId);
    $cfId    = $linkRow['internal_entity_id'] ?? null;

    $action = _qboShadowUpsert('qbo_inbound_invoices', 'qbo_invoice_id', $tenantId, $qboId, [
        'doc_number'         => (string) ($q['DocNumber'] ?? ''),
        'customer_qbo_id'    => (string) ($q['CustomerRef']['value'] ?? ''),
        'customer_name'      => (string) ($q['CustomerRef']['name']  ?? ''),
        'issue_date'         => $q['TxnDate']  ?? null,
        'due_date'           => $q['DueDate']  ?? null,
        'total_amount_cents' => $total,
        'balance_cents'      => $bal,
        'currency'           => (string) ($q['CurrencyRef']['value'] ?? 'USD'),
        'qbo_status'         => $bal === 0 ? 'Paid' : ($bal < $total ? 'PartiallyPaid' : 'Open'),
        'qbo_last_updated'   => _qboLastUpdated($q),
        'coreflux_invoice_id'=> $cfId,
        'raw_payload'        => json_encode($q),
    ]);

    // Drift detection — only when a CoreFlux link exists.
    $drift = 0;
    if ($cfId) {
        $drift = _qboDetectInvoiceDrift($tenantId, (int) $cfId, $qboId, $bal, $total, $q);
    }
    return ['action' => $action, 'drift_rows_written' => $drift];
}

function _qboShadowPayment(int $tenantId, array $q): array
{
    $qboId   = (string) ($q['Id'] ?? '');
    $total   = (int) round(((float) ($q['TotalAmt'] ?? 0)) * 100);
    $unapp   = (int) round(((float) ($q['UnappliedAmt'] ?? 0)) * 100);
    $linked  = [];
    foreach ((array) ($q['Line'] ?? []) as $line) {
        foreach ((array) ($line['LinkedTxn'] ?? []) as $lt) {
            if (($lt['TxnType'] ?? '') === 'Invoice' && !empty($lt['TxnId'])) {
                $linked[] = (string) $lt['TxnId'];
            }
        }
    }
    $action = _qboShadowUpsert('qbo_inbound_payments', 'qbo_payment_id', $tenantId, $qboId, [
        'customer_qbo_id'    => (string) ($q['CustomerRef']['value'] ?? ''),
        'customer_name'      => (string) ($q['CustomerRef']['name']  ?? ''),
        'payment_date'       => $q['TxnDate'] ?? null,
        'total_amount_cents' => $total,
        'unapplied_cents'    => $unapp,
        'payment_method'     => (string) ($q['PaymentMethodRef']['name'] ?? ''),
        'deposit_qbo_id'     => (string) ($q['DepositToAccountRef']['value'] ?? ''),
        'linked_invoice_ids' => json_encode($linked),
        'qbo_last_updated'   => _qboLastUpdated($q),
        'raw_payload'        => json_encode($q),
    ]);

    // Phase 1 critical: detect "invoice paid out of band in QBO" drift.
    $drift = 0;
    foreach ($linked as $invQboId) {
        $map = _qboFindMapping($tenantId, 'invoice', $invQboId);
        if ($map && !empty($map['internal_entity_id'])) {
            $drift += _qboDetectPaidOutOfBand($tenantId, (int) $map['internal_entity_id'], $invQboId, $qboId, $total);
        }
    }
    return ['action' => $action, 'drift_rows_written' => $drift];
}

function _qboShadowDeposit(int $tenantId, array $q): array
{
    $qboId   = (string) ($q['Id'] ?? '');
    $total   = (int) round(((float) ($q['DepositTotal'] ?? $q['TotalAmt'] ?? 0)) * 100);

    // Processor fees show up as a negative-amount Line[] with AccountRef
    // pointing at a Fee/Expense account (Stripe, QBO Payments, etc.
    // batch the day's settlements and net their cut out before the
    // deposit lands). Extract the sum. True wire-in / bank-maintenance
    // fees do NOT appear here — they're separate Expense entries.
    $feeCents = 0; $feeAcct = '';
    $linkedPayments = [];
    foreach ((array) ($q['Line'] ?? []) as $line) {
        $amt = (float) ($line['Amount'] ?? 0);
        if ($amt < 0) {
            $feeCents += (int) round(abs($amt) * 100);
            $feeAcct = (string) ($line['DepositLineDetail']['AccountRef']['value'] ?? $feeAcct);
        }
        foreach ((array) ($line['LinkedTxn'] ?? []) as $lt) {
            if (($lt['TxnType'] ?? '') === 'Payment' && !empty($lt['TxnId'])) {
                $linkedPayments[] = (string) $lt['TxnId'];
            }
        }
    }
    $action = _qboShadowUpsert('qbo_inbound_deposits', 'qbo_deposit_id', $tenantId, $qboId, [
        'deposit_date'        => $q['TxnDate'] ?? null,
        'total_amount_cents'  => $total,
        'fee_cents'           => $feeCents,
        'fee_account_qbo_id'  => $feeAcct,
        'bank_account_qbo_id' => (string) ($q['DepositToAccountRef']['value'] ?? ''),
        'linked_payment_ids'  => json_encode($linkedPayments),
        'qbo_last_updated'    => _qboLastUpdated($q),
        'raw_payload'         => json_encode($q),
    ]);
    return ['action' => $action, 'drift_rows_written' => 0];
}

function _qboShadowBill(int $tenantId, array $q): array
{
    $qboId   = (string) ($q['Id'] ?? '');
    $bal     = (int) round(((float) ($q['Balance']  ?? 0)) * 100);
    $total   = (int) round(((float) ($q['TotalAmt'] ?? 0)) * 100);
    $linkRow = _qboFindMapping($tenantId, 'bill', $qboId);
    $cfId    = $linkRow['internal_entity_id'] ?? null;

    $action = _qboShadowUpsert('qbo_inbound_bills', 'qbo_bill_id', $tenantId, $qboId, [
        'doc_number'         => (string) ($q['DocNumber'] ?? ''),
        'vendor_qbo_id'      => (string) ($q['VendorRef']['value'] ?? ''),
        'vendor_name'        => (string) ($q['VendorRef']['name']  ?? ''),
        'issue_date'         => $q['TxnDate'] ?? null,
        'due_date'           => $q['DueDate'] ?? null,
        'total_amount_cents' => $total,
        'balance_cents'      => $bal,
        'currency'           => (string) ($q['CurrencyRef']['value'] ?? 'USD'),
        'qbo_last_updated'   => _qboLastUpdated($q),
        'coreflux_bill_id'   => $cfId,
        'raw_payload'        => json_encode($q),
    ]);

    $drift = 0;
    if ($cfId) {
        $drift = _qboDetectBillDrift($tenantId, (int) $cfId, $qboId, $bal, $total, $q);
    }
    return ['action' => $action, 'drift_rows_written' => $drift];
}

function _qboShadowBillPayment(int $tenantId, array $q): array
{
    $qboId  = (string) ($q['Id'] ?? '');
    $total  = (int) round(((float) ($q['TotalAmt'] ?? 0)) * 100);
    $linked = [];
    foreach ((array) ($q['Line'] ?? []) as $line) {
        foreach ((array) ($line['LinkedTxn'] ?? []) as $lt) {
            if (($lt['TxnType'] ?? '') === 'Bill' && !empty($lt['TxnId'])) {
                $linked[] = (string) $lt['TxnId'];
            }
        }
    }
    $bank = (string) ($q['CheckPayment']['BankAccountRef']['value']
        ?? $q['CreditCardPayment']['CCAccountRef']['value']
        ?? '');
    $action = _qboShadowUpsert('qbo_inbound_billpayments', 'qbo_billpayment_id', $tenantId, $qboId, [
        'vendor_qbo_id'       => (string) ($q['VendorRef']['value'] ?? ''),
        'payment_date'        => $q['TxnDate'] ?? null,
        'total_amount_cents'  => $total,
        'pay_type'            => (string) ($q['PayType'] ?? ''),
        'bank_account_qbo_id' => $bank,
        'linked_bill_ids'     => json_encode($linked),
        'qbo_last_updated'    => _qboLastUpdated($q),
        'raw_payload'         => json_encode($q),
    ]);

    // Detect bill paid-out-of-band: if any linked QBO Bill has a
    // CoreFlux mapping AND the CoreFlux bill is still status='approved'
    // / 'pending', that's drift.
    $drift = 0;
    foreach ($linked as $billQboId) {
        $map = _qboFindMapping($tenantId, 'bill', $billQboId);
        if ($map && !empty($map['internal_entity_id'])) {
            $drift += _qboDetectBillPaidOutOfBand($tenantId, (int) $map['internal_entity_id'], $billQboId, $qboId, $total);
        }
    }
    return ['action' => $action, 'drift_rows_written' => $drift];
}

// ─────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────

function _qboLastUpdated(array $q): ?string
{
    $t = $q['MetaData']['LastUpdatedTime'] ?? null;
    if (!$t) return null;
    $ts = strtotime((string) $t);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

function _qboFindMapping(int $tenantId, string $entityType, string $qboId): ?array
{
    try {
        $stmt = getDB()->prepare(
            'SELECT internal_entity_id
               FROM external_entity_mappings
              WHERE tenant_id = :t AND source_system = :src
                AND internal_entity_type = :et AND external_id = :ext
              LIMIT 1'
        );
        $stmt->execute(['t' => $tenantId, 'src' => QBO_SOURCE, 'et' => $entityType, 'ext' => $qboId]);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (\Throwable $_) { return null; }
}

function _qboShadowUpsert(string $table, string $idCol, int $tenantId, string $qboId, array $fields): string
{
    $pdo = getDB();
    try {
        $existsStmt = $pdo->prepare("SELECT id FROM {$table} WHERE tenant_id = :t AND {$idCol} = :q LIMIT 1");
        $existsStmt->execute(['t' => $tenantId, 'q' => $qboId]);
        $exists = $existsStmt->fetch(\PDO::FETCH_ASSOC);
    } catch (\Throwable $_) { return 'unchanged'; }
    $now = date('Y-m-d H:i:s');
    $action = $exists ? 'updated' : 'created';

    $cols = array_keys($fields);
    if ($exists) {
        // tenant-leak-allow: existence proven above via tenant_id-scoped SELECT
        $set = implode(', ', array_map(fn($c) => "{$c} = :{$c}", $cols));
        $set .= ', last_seen_at = :ls';
        $params = $fields + ['ls' => $now, 'id' => (int) $exists['id']];
        $pdo->prepare("UPDATE {$table} SET {$set} WHERE id = :id")->execute($params);
    } else {
        $colNames = array_merge([$idCol, 'tenant_id'], $cols, ['first_seen_at', 'last_seen_at']);
        $placeholders = array_map(fn($c) => ':' . $c, $colNames);
        $sql = "INSERT INTO {$table} (" . implode(', ', $colNames) . ') VALUES ('
             . implode(', ', $placeholders) . ')';
        $params = $fields + [
            $idCol => $qboId, 'tenant_id' => $tenantId,
            'first_seen_at' => $now, 'last_seen_at' => $now,
        ];
        $pdo->prepare($sql)->execute($params);
    }
    return $action;
}

function _qboWriteDrift(int $tenantId, string $entityType, ?int $cfId, ?string $qboId, string $kind, string $severity, string $summary, array $cfSnap = [], array $qboSnap = []): int
{
    try {
        $pdo = getDB();
        $now = date('Y-m-d H:i:s');
        $cfsJson = json_encode($cfSnap);
        $qbsJson = json_encode($qboSnap);
        $sumTrim = substr($summary, 0, 500);

        // Manual upsert by the (tenant, entity_type, qbo_id, drift_kind)
        // tuple so the behaviour is identical on MySQL + SQLite (the
        // smoke runs against SQLite, prod runs MySQL).
        $sel = $pdo->prepare(
            'SELECT id FROM qbo_sync_drift
              WHERE tenant_id = :t AND entity_type = :et
                AND ((qbo_id = :q) OR (qbo_id IS NULL AND :q2 IS NULL))
                AND drift_kind = :k LIMIT 1'
        );
        $sel->execute(['t'=>$tenantId,'et'=>$entityType,'q'=>$qboId,'q2'=>$qboId,'k'=>$kind]);
        $row = $sel->fetch(\PDO::FETCH_ASSOC);

        if ($row) {
            // tenant-leak-allow: row id resolved via tenant-scoped SELECT above
            $pdo->prepare(
                'UPDATE qbo_sync_drift
                    SET severity = :sev, coreflux_snapshot = :cfs,
                        qbo_snapshot = :qbs, summary = :sm, last_seen_at = :ln
                  WHERE id = :id'
            )->execute([
                'sev'=>$severity,'cfs'=>$cfsJson,'qbs'=>$qbsJson,
                'sm'=>$sumTrim,'ln'=>$now,'id'=>(int)$row['id'],
            ]);
        } else {
            $pdo->prepare(
                'INSERT INTO qbo_sync_drift
                    (tenant_id, entity_type, coreflux_id, qbo_id, drift_kind, severity,
                     coreflux_snapshot, qbo_snapshot, summary, status,
                     detected_at, last_seen_at)
                 VALUES (:t,:et,:cf,:q,:k,:sev,:cfs,:qbs,:sm,"open",:dn,:ln)'
            )->execute([
                't'=>$tenantId,'et'=>$entityType,'cf'=>$cfId,'q'=>$qboId,
                'k'=>$kind,'sev'=>$severity,'cfs'=>$cfsJson,'qbs'=>$qbsJson,
                'sm'=>$sumTrim,'dn'=>$now,'ln'=>$now,
            ]);
        }
        return 1;
    } catch (\Throwable $_) { return 0; }
}

function _qboDetectInvoiceDrift(int $tenantId, int $cfId, string $qboId, int $balCents, int $totalCents, array $qbo): int
{
    try {
        $stmt = getDB()->prepare(
            'SELECT id, invoice_number, status, currency
               FROM billing_invoices WHERE tenant_id = :t AND id = :id LIMIT 1'
        );
        $stmt->execute(['t'=>$tenantId,'id'=>$cfId]);
        $cf = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$cf) return 0;
    } catch (\Throwable $_) { return 0; }

    $written = 0;
    // Voided / deleted in QBO: PrivateNote often says VOID; status 'Voided'.
    if (strcasecmp((string) ($qbo['PrivateNote'] ?? ''), 'Voided') === 0
        || strcasecmp((string) ($qbo['status'] ?? ''), 'Voided') === 0) {
        $written += _qboWriteDrift($tenantId, 'invoice', $cfId, $qboId, 'voided_in_qbo', 'critical',
            "Invoice {$cf['invoice_number']} voided in QBO but still active in CoreFlux",
            ['status'=>$cf['status']], ['private_note'=>$qbo['PrivateNote'] ?? null]);
    }
    // Balance zero in QBO but CoreFlux still 'sent' / 'approved'/'partially_paid'
    if ($balCents === 0 && in_array($cf['status'], ['sent','approved','partially_paid'], true)) {
        $written += _qboWriteDrift($tenantId, 'invoice', $cfId, $qboId, 'paid_out_of_band', 'warn',
            "Invoice {$cf['invoice_number']} fully paid in QBO (balance=0) — CoreFlux status is '{$cf['status']}'",
            ['status'=>$cf['status']], ['balance_cents'=>$balCents,'total_cents'=>$totalCents]);
    }
    // Balance changed (partial payment / amount edit upstream)
    elseif ($balCents > 0 && $balCents < $totalCents) {
        $written += _qboWriteDrift($tenantId, 'invoice', $cfId, $qboId, 'balance_changed', 'info',
            "Invoice {$cf['invoice_number']} partially paid in QBO (balance {$balCents}c / total {$totalCents}c)",
            ['status'=>$cf['status']], ['balance_cents'=>$balCents,'total_cents'=>$totalCents]);
    }
    return $written;
}

function _qboDetectPaidOutOfBand(int $tenantId, int $cfInvId, string $qboInvId, string $qboPaymentId, int $paymentCents): int
{
    try {
        $stmt = getDB()->prepare('SELECT invoice_number, status FROM billing_invoices WHERE tenant_id = :t AND id = :id LIMIT 1');
        $stmt->execute(['t'=>$tenantId,'id'=>$cfInvId]);
        $cf = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$cf) return 0;
        if (in_array($cf['status'], ['paid','void','cancelled'], true)) return 0;
    } catch (\Throwable $_) { return 0; }

    return _qboWriteDrift($tenantId, 'invoice', $cfInvId, $qboInvId, 'paid_out_of_band', 'warn',
        "QBO Payment {$qboPaymentId} (\${$paymentCents}c) applied to invoice {$cf['invoice_number']} — CoreFlux status still '{$cf['status']}'",
        ['status'=>$cf['status']],
        ['qbo_payment_id'=>$qboPaymentId,'amount_cents'=>$paymentCents]);
}

function _qboDetectBillDrift(int $tenantId, int $cfId, string $qboId, int $balCents, int $totalCents, array $qbo): int
{
    try {
        $stmt = getDB()->prepare(
            'SELECT id, bill_number, status FROM ap_bills WHERE tenant_id = :t AND id = :id LIMIT 1'
        );
        $stmt->execute(['t'=>$tenantId,'id'=>$cfId]);
        $cf = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$cf) return 0;
    } catch (\Throwable $_) { return 0; }

    $written = 0;
    if ($balCents === 0 && in_array($cf['status'], ['approved','posted','partially_paid','pending'], true)) {
        $written += _qboWriteDrift($tenantId, 'bill', $cfId, $qboId, 'paid_out_of_band', 'warn',
            "Bill {$cf['bill_number']} fully paid in QBO (balance=0) — CoreFlux status is '{$cf['status']}'",
            ['status'=>$cf['status']], ['balance_cents'=>$balCents,'total_cents'=>$totalCents]);
    }
    if (abs($balCents) > 0 && $balCents < $totalCents) {
        $written += _qboWriteDrift($tenantId, 'bill', $cfId, $qboId, 'balance_changed', 'info',
            "Bill {$cf['bill_number']} partially paid in QBO (balance {$balCents}c / total {$totalCents}c)",
            ['status'=>$cf['status']], ['balance_cents'=>$balCents,'total_cents'=>$totalCents]);
    }
    return $written;
}

function _qboDetectBillPaidOutOfBand(int $tenantId, int $cfBillId, string $qboBillId, string $qboBillPaymentId, int $amtCents): int
{
    try {
        $stmt = getDB()->prepare('SELECT bill_number, status FROM ap_bills WHERE tenant_id = :t AND id = :id LIMIT 1');
        $stmt->execute(['t'=>$tenantId,'id'=>$cfBillId]);
        $cf = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$cf) return 0;
        if (in_array($cf['status'], ['paid','void','cancelled'], true)) return 0;
    } catch (\Throwable $_) { return 0; }

    return _qboWriteDrift($tenantId, 'bill', $cfBillId, $qboBillId, 'paid_out_of_band', 'warn',
        "QBO BillPayment {$qboBillPaymentId} (\${$amtCents}c) paid bill {$cf['bill_number']} — CoreFlux status still '{$cf['status']}'",
        ['status'=>$cf['status']],
        ['qbo_billpayment_id'=>$qboBillPaymentId,'amount_cents'=>$amtCents]);
}

<?php
/**
 * QBO Payments daily polling cron — Step 6 Phase 4.
 *
 * QBO Payments charge lifecycle:
 *   ISSUED  → CAPTURED (immediate, on capture=true)
 *           → SETTLED (T+1 for cards, T+3 for ACH)
 *           → REFUNDED / VOIDED
 *
 * Intuit doesn't fire a dedicated webhook for charge settlement, so
 * every pending charge (ISSUED / PENDING / CAPTURED-not-yet-SETTLED)
 * needs to be polled. We page through `qbo_payment_charges` and call
 * `qboGetCharge` for each, then re-upsert via `qboRecordChargeShadow`
 * to advance `status`, `settled_at`, `error_*` fields.
 *
 * Schedule: hourly is more than enough; settlement deltas update with
 * 24h granularity from Intuit's side.
 *
 *   0 * * * * php /home/master/applications/<app>/public_html/cron/qbo_payments_poll.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/qbo/client.php';
require_once __DIR__ . '/../core/qbo/payments_client.php';

$pdo = getDB();

try {
    $tenants = $pdo->query(
        "SELECT DISTINCT c.tenant_id
           FROM qbo_payment_charges c
           JOIN qbo_connections cn ON cn.tenant_id = c.tenant_id AND cn.status = 'active'
          WHERE c.status IN ('ISSUED','PENDING','CAPTURED','AUTHORIZED')
            AND (c.settled_at IS NULL)
            AND c.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
    )->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    fwrite(STDERR, "qbo_payments_poll: bootstrap failed — {$e->getMessage()}\n");
    exit(0);
}
if (!$tenants) {
    fwrite(STDOUT, "qbo_payments_poll: no pending charges.\n");
    exit(0);
}

$totals = ['tenants' => 0, 'polled' => 0, 'advanced' => 0, 'errors' => 0];

foreach ($tenants as $row) {
    $tid = (int) $row['tenant_id'];
    $totals['tenants']++;

    try {
        $stmt = $pdo->prepare(
            "SELECT id, qbo_charge_id, status, charge_type, coreflux_invoice_id, context_token
               FROM qbo_payment_charges
              WHERE tenant_id = :t
                AND status IN ('ISSUED','PENDING','CAPTURED','AUTHORIZED')
                AND (settled_at IS NULL)
              ORDER BY created_at ASC LIMIT 200"
        );
        $stmt->execute(['t' => $tid]);
        $charges = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        fwrite(STDERR, "tenant {$tid}: list query failed — {$e->getMessage()}\n");
        continue;
    }

    foreach ($charges as $c) {
        $totals['polled']++;
        $beforeStatus = (string) $c['status'];
        try {
            $live = qboGetCharge($tid, (string) $c['qbo_charge_id']);
            qboRecordChargeShadow($tid, $live, [
                'charge_type'         => $c['charge_type'] ?? 'card',
                'coreflux_invoice_id' => $c['coreflux_invoice_id'] ?: null,
                'context_token'       => $c['context_token'] ?? null,
            ]);
            if (strtoupper((string) ($live['status'] ?? '')) !== $beforeStatus) {
                $totals['advanced']++;
            }
        } catch (\Throwable $e) {
            $totals['errors']++;
            // Stamp the shadow row with the latest error so the operator
            // sees something actionable in the IntegrationTriage page.
            try {
                $pdo->prepare(
                    'UPDATE qbo_payment_charges
                        SET error_message = :em, updated_at = CURRENT_TIMESTAMP
                      WHERE id = :id AND tenant_id = :t'
                )->execute([
                    'em' => substr('poll_error: ' . $e->getMessage(), 0, 500),
                    'id' => (int) $c['id'], 't' => $tid,
                ]);
            } catch (\Throwable $_) {}
        }
    }
}

fwrite(STDOUT, sprintf(
    "qbo_payments_poll done: tenants=%d polled=%d advanced=%d errors=%d\n",
    $totals['tenants'], $totals['polled'], $totals['advanced'], $totals['errors']
));
exit(0);

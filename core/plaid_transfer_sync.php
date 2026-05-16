<?php
/**
 * core/plaid_transfer_sync.php — pull Plaid Transfer event deltas via
 * /transfer/event/sync, persist to plaid_transfer_events, and update the
 * payment row (ap_payments.rail_status, optional posted_at audit).
 *
 *   plaidTransferSync(int $tenantId): array
 *     → { fetched: N, updated_payments: M, new_cursor: ID, errors: [] }
 *
 * Triggered by:
 *   - /api/plaid_transfer_webhook.php on TRANSFER_EVENTS_UPDATE
 *   - manual: /api/admin/plaid_transfer_sync.php?tenant_id=N (operator)
 *   - cron (future): every 5min as a safety net
 *
 * Idempotent on the (tenant_id, plaid_event_id) UNIQUE key — re-running
 * on the same cursor is a no-op (no duplicate event rows, no double
 * ap_payments updates because the rail_status set matches the latest
 * event for the transfer).
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/plaid_service.php';

/** Map Plaid event_type → internal rail_status. */
function plaidTransferMapEventStatus(string $eventType): string
{
    switch (strtolower($eventType)) {
        case 'pending':       return 'pending';
        case 'authorized':    return 'authorized';
        case 'posted':        return 'posted';
        case 'settled':       return 'settled';
        case 'cancelled':     return 'cancelled';
        case 'failed':        return 'failed';
        case 'returned':      return 'returned';
        case 'reversed':      return 'reversed';
        case 'swept':         return 'settled';      // ledger sweep ~= settled for AP
        case 'sweep_posted':  return 'settled';
        default:              return $eventType;     // unknown — preserve raw
    }
}

function plaidTransferSync(int $tenantId): array
{
    $pdo  = getDB();
    $stmt = $pdo->prepare('SELECT last_event_id FROM plaid_transfer_cursor WHERE tenant_id = :t');
    $stmt->execute(['t' => $tenantId]);
    $afterId = $stmt->fetchColumn();
    $afterId = $afterId !== false && $afterId !== null ? (int) $afterId : 0;

    $fetched = 0;
    $updated = 0;
    $errors  = [];
    $newCursor = $afterId;

    // /transfer/event/sync returns up to 25 events per page. Loop until exhausted.
    do {
        try {
            $resp = plaidPost('/transfer/event/sync', [
                'after_id' => $newCursor,
                'count'    => 25,
            ]);
        } catch (\Throwable $e) {
            $errors[] = 'sync_fetch_failed: ' . $e->getMessage();
            break;
        }
        $events = is_array($resp['transfer_events'] ?? null) ? $resp['transfer_events'] : [];
        if (!$events) break;

        foreach ($events as $ev) {
            $eventId    = (int)    ($ev['event_id']     ?? 0);
            $transferId = (string) ($ev['transfer_id']  ?? '');
            $type       = (string) ($ev['event_type']   ?? '');
            $failure    = (string) ($ev['failure_reason']['ach_return_code'] ?? $ev['failure_reason'] ?? '');
            if (!$eventId || !$transferId || !$type) continue;

            // Persist event row (idempotent via UNIQUE).
            try {
                $pdo->prepare(
                    'INSERT IGNORE INTO plaid_transfer_events
                        (tenant_id, plaid_event_id, transfer_id, event_type, failure_reason, sweep_id, payload_json)
                     VALUES (:t, :eid, :tid, :ty, :fr, :sw, :pl)'
                )->execute([
                    't'   => $tenantId,
                    'eid' => $eventId,
                    'tid' => $transferId,
                    'ty'  => $type,
                    'fr'  => $failure ?: null,
                    'sw'  => (string) ($ev['sweep_id'] ?? '') ?: null,
                    'pl'  => json_encode($ev),
                ]);
            } catch (\Throwable $e) {
                $errors[] = 'event_persist_failed: ' . $e->getMessage();
            }

            // Update the matching ap_payments row (rail_external_ref ==
            // transfer_id). Best-effort; missing rows are fine (could be a
            // transfer originated outside CoreFlux). Single tenant per call.
            try {
                $u = $pdo->prepare(
                    'UPDATE ap_payments
                        SET rail_status = :s, updated_at = NOW()
                      WHERE tenant_id = :t
                        AND rail_external_ref = :ref
                        AND disbursement_rail = "plaid_transfer"'
                );
                $u->execute([
                    's'   => plaidTransferMapEventStatus($type),
                    't'   => $tenantId,
                    'ref' => $transferId,
                ]);
                if ($u->rowCount() > 0) $updated++;
            } catch (\Throwable $e) {
                $errors[] = 'payment_update_failed: ' . $e->getMessage();
            }

            $fetched++;
            if ($eventId > $newCursor) $newCursor = $eventId;
        }
        // If Plaid said there are more, keep paging.
        $hasMore = (bool) ($resp['has_more'] ?? false);
    } while ($hasMore);

    // Persist cursor (upsert).
    try {
        $pdo->prepare(
            'INSERT INTO plaid_transfer_cursor (tenant_id, last_event_id, last_synced_at, last_error)
             VALUES (:t, :eid, NOW(), :err)
             ON DUPLICATE KEY UPDATE
               last_event_id = VALUES(last_event_id),
               last_synced_at = NOW(),
               last_error = VALUES(last_error)'
        )->execute([
            't'   => $tenantId,
            'eid' => $newCursor,
            'err' => $errors ? substr(implode(' | ', $errors), 0, 240) : null,
        ]);
    } catch (\Throwable $e) {
        $errors[] = 'cursor_persist_failed: ' . $e->getMessage();
    }

    return [
        'fetched'          => $fetched,
        'updated_payments' => $updated,
        'new_cursor'       => $newCursor,
        'errors'           => $errors,
    ];
}

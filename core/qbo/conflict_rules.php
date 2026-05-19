<?php
/**
 * QBO Slice 5 — Conflict rules for `two_way` entities.
 *
 * When an entity's sync_config direction is `two_way`, every pull
 * (Customer / Vendor / Account so far — Bill/Invoice/Payment will follow)
 * compares the QBO `MetaData.LastUpdatedTime` against the CoreFlux
 * `updated_at` of the matched internal row AND the timestamp on the
 * existing `external_entity_mappings.payload_snapshot`.
 *
 * Cases:
 *   - both unchanged since last sync                  → no conflict.
 *   - only QBO changed                                → pull update, no conflict.
 *   - only CoreFlux changed                           → push update later, no conflict.
 *   - both changed since last sync                    → CONFLICT. Apply rule:
 *       'last_write_wins'  → newer side wins. Loser snapshot logged.
 *       (Future rules: 'coreflux_wins', 'quickbooks_wins', 'manual')
 *
 * `qboDetectConflict()` writes to qbo_conflict_log when a divergence is
 * detected. It returns the winner so the caller can decide whether to
 * proceed with the pull-side update or skip.
 *
 * Public surface:
 *   qboDetectConflict(int $tid, string $entityType, ?int $internalId,
 *                     string $externalId, array $qboPayload,
 *                     ?string $corefluxUpdatedAt): array
 *       { winner: 'coreflux'|'quickbooks'|'tie'|'no_conflict',
 *         rule: 'last_write_wins',
 *         logged: bool }
 */
declare(strict_types=1);

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/sync_je.php';   // QBO_SOURCE
require_once __DIR__ . '/../integrations/entity_mappings.php';

function qboDetectConflict(
    int $tenantId,
    string $entityType,
    ?int $internalId,
    string $externalId,
    array $qboPayload,
    ?string $corefluxUpdatedAt
): array {
    // Only `two_way` entities trip the conflict path.
    $cfg = qboSyncConfigRead($tenantId);
    $cfgKey = _qboCfgKeyFor($entityType);
    $dir    = $cfg[$cfgKey] ?? 'off';
    if ($dir !== 'two_way') {
        return ['winner' => 'no_conflict', 'rule' => 'last_write_wins', 'logged' => false];
    }

    // Last successful sync snapshot lives in external_entity_mappings.
    $mapping = mappingFindInternal($tenantId, QBO_SOURCE, $entityType, $externalId);
    $lastSeenQboAt = null;
    if ($mapping) {
        $snap = $mapping['payload_snapshot'] ? json_decode((string) $mapping['payload_snapshot'], true) : null;
        if (is_array($snap)) {
            $lastSeenQboAt = (string) ($snap['MetaData']['LastUpdatedTime'] ?? '');
        }
    }
    $qboNow = (string) ($qboPayload['MetaData']['LastUpdatedTime'] ?? '');
    $qboChanged = $qboNow !== '' && $lastSeenQboAt !== $qboNow;

    // CoreFlux "changed since last sync" = updated_at > mapping last_synced_at.
    $corefluxChanged = false;
    if ($corefluxUpdatedAt && $mapping && !empty($mapping['last_synced_at'])) {
        $corefluxChanged = strtotime((string) $corefluxUpdatedAt) > strtotime((string) $mapping['last_synced_at']);
    } elseif ($corefluxUpdatedAt && !$mapping) {
        $corefluxChanged = true;
    }

    if (!$qboChanged && !$corefluxChanged) {
        return ['winner' => 'no_conflict', 'rule' => 'last_write_wins', 'logged' => false];
    }
    if ($qboChanged && !$corefluxChanged) {
        return ['winner' => 'quickbooks', 'rule' => 'last_write_wins', 'logged' => false];
    }
    if ($corefluxChanged && !$qboChanged) {
        return ['winner' => 'coreflux',   'rule' => 'last_write_wins', 'logged' => false];
    }

    // Both sides changed — real conflict. Decide via last-write-wins.
    $qboTs = $qboNow ? strtotime($qboNow) : 0;
    $cfTs  = $corefluxUpdatedAt ? strtotime($corefluxUpdatedAt) : 0;
    $winner = 'tie';
    if ($qboTs > $cfTs) $winner = 'quickbooks';
    elseif ($cfTs > $qboTs) $winner = 'coreflux';

    // Persist loser snapshot for replay/audit.
    try {
        getDB()->prepare(
            'INSERT INTO qbo_conflict_log
                (tenant_id, entity_type, internal_id, external_id,
                 rule_applied, winner,
                 coreflux_updated_at, qbo_updated_at,
                 coreflux_snapshot, qbo_snapshot, notes)
             VALUES (:t, :et, :iid, :eid, :ra, :w, :ca, :qa, :cs, :qs, :n)'
        )->execute([
            't'   => $tenantId,
            'et'  => $entityType,
            'iid' => $internalId,
            'eid' => $externalId,
            'ra'  => 'last_write_wins',
            'w'   => $winner,
            'ca'  => $corefluxUpdatedAt,
            'qa'  => $qboNow !== '' ? $qboNow : null,
            'cs'  => $mapping && $mapping['payload_snapshot'] ? (string) $mapping['payload_snapshot'] : null,
            'qs'  => json_encode($qboPayload),
            'n'   => 'Both sides changed since last sync',
        ]);
    } catch (\Throwable $e) {
        // Conflict log is best-effort — never fail a sync run on this.
    }
    qboAudit($tenantId, 'conflict_detected', [
        'entity_type' => $entityType, 'direction' => 'two_way',
        'detail' => [
            'external_id' => $externalId, 'internal_id' => $internalId,
            'winner' => $winner, 'rule' => 'last_write_wins',
        ],
    ]);
    return ['winner' => $winner, 'rule' => 'last_write_wins', 'logged' => true];
}

function _qboCfgKeyFor(string $entityType): string
{
    $map = [
        'customer' => 'customers',
        'vendor'   => 'vendors',
        'account'  => 'chart_of_accounts',
        'invoice'  => 'invoices',
        'bill'     => 'bills',
        'payment'  => 'payments',
    ];
    return $map[$entityType] ?? $entityType;
}

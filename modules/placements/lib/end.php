<?php
/** Validated, replay-safe placement ending. The caller authorizes placements.terminate. */
declare(strict_types=1);

function placementEndRequest(array $body, array $current, string $today): array
{
    $status = array_key_exists('status', $body) ? $body['status'] : 'ended';
    if (!is_string($status) || !in_array($status, ['ended', 'cancelled'], true)) {
        throw new InvalidArgumentException('Choose ended or cancelled.', 422);
    }
    // A retry tomorrow must not move an already recorded actual end date.
    $defaultDate = ($current['status'] ?? null) === $status && !empty($current['actual_end_date'])
        ? $current['actual_end_date'] : $today;
    $date = array_key_exists('actual_end_date', $body) ? $body['actual_end_date'] : $defaultDate;
    $parsed = is_string($date) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date)
        ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
    if (!$parsed || $parsed->format('Y-m-d') !== $date || (int) substr($date, 0, 4) < 1000) {
        throw new InvalidArgumentException('Enter a valid actual end date in YYYY-MM-DD format.', 422);
    }
    if ($status === 'ended' && !empty($current['start_date']) && $date < $current['start_date']) {
        throw new InvalidArgumentException('The actual end date cannot precede the placement start date.', 422);
    }
    return ['status' => $status, 'actual_end_date' => $date];
}

function placementEnd(int $id, array $body): array
{
    require_once __DIR__ . '/placements.php';
    require_once __DIR__ . '/economics.php';
    $pdo = getDB();
    $tenant = (int) effectiveTenantIdForRequest();
    if (!$pdo || $tenant <= 0) throw new RuntimeException('Placement workspace is unavailable.', 403);
    $owns = !$pdo->inTransaction();
    $savepoint = 'placement_end_' . bin2hex(random_bytes(8));
    if ($owns) $pdo->beginTransaction();
    else $pdo->exec('SAVEPOINT ' . $savepoint);
    try {
        $lock = $pdo->prepare('SELECT id FROM placements WHERE tenant_id=? AND id=? AND deleted_at IS NULL FOR UPDATE');
        $lock->execute([$tenant, $id]);
        if (!$lock->fetchColumn()) throw new RuntimeException('Placement not found.', 404);
        $before = placementAuditRow($id);
        if (!$before) throw new RuntimeException('Placement not found.', 404);
        $changes = placementEndRequest($body, $before, date('Y-m-d'));
        // A deliberate Core closure owns both lifecycle values until an
        // operator explicitly releases them through the override review.
        if (str_starts_with((string) ($before['external_id'] ?? ''), 'jd:')) {
            $raw = $before['coreflux_overridden_fields'] ?? null;
            $overrides = $raw === null || $raw === '' ? [] : json_decode($raw, true);
            if (!is_array($overrides) || !array_is_list($overrides)
                || array_filter($overrides, static fn($field) => !is_string($field) || $field === '')) {
                throw new RuntimeException('Review this placement\'s field ownership before ending it.', 422);
            }
            $merged = array_values(array_unique([...$overrides, 'status', 'actual_end_date']));
            if ($merged !== $overrides) $changes['coreflux_overridden_fields'] = json_encode($merged, JSON_THROW_ON_ERROR);
        }
        $changed = $before['status'] !== $changes['status'] || $before['actual_end_date'] !== $changes['actual_end_date'];
        $changed = $changed || array_key_exists('coreflux_overridden_fields', $changes);
        if ($changed) {
            scopedUpdate('placements', $id, $changes);
            $graph = placementEconomicsReconcile($tenant, $id);
            if (!$graph['available'] || $graph['errors']) {
                throw new RuntimeException('The placement commercial graph could not be updated. Review its company links and try again.', 422);
            }
            placementsAudit('placement.ended', [
                'id' => $id, 'status' => $changes['status'], 'reason' => $body['reason'] ?? null,
                'before' => $before, 'after' => placementAuditRow($id),
            ], $id);
        }
        if ($owns) $pdo->commit();
        else $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        return ['changed' => $changed];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            if ($owns) $pdo->rollBack();
            else {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
        }
        throw $e;
    }
}

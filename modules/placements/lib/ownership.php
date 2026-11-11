<?php
/** Explicit placement field ownership; callers authorize the requested operation. */
declare(strict_types=1);
require_once __DIR__ . '/placements.php';

function placementOwnershipFields(?string $raw): array
{
    $fields = $raw === null || $raw === '' ? [] : json_decode($raw);
    if (!is_array($fields) || !array_is_list($fields)
        || array_filter($fields, static fn($field) => !is_string($field) || $field === '')) {
        throw new RuntimeException('Review this placement\'s field ownership before changing it.', 422);
    }
    return $fields;
}

/** The row lock and all callback writes share one transaction or savepoint. */
function placementWithLockedRecord(int $tenantId, int $id, callable $write): mixed
{
    $pdo = getDB();
    if (!$pdo || $tenantId <= 0) throw new RuntimeException('Placement workspace is unavailable.', 403);
    $owns = !$pdo->inTransaction();
    $savepoint = 'placement_owned_' . bin2hex(random_bytes(8));
    if ($owns) $pdo->beginTransaction();
    else $pdo->exec('SAVEPOINT ' . $savepoint);
    try {
        $lock = $pdo->prepare('SELECT * FROM placements WHERE tenant_id=? AND id=? AND deleted_at IS NULL FOR UPDATE');
        $lock->execute([$tenantId, $id]);
        $row = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Placement not found.', 404);
        $result = $write($row, $pdo);
        if ($owns) $pdo->commit();
        else $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        return $result;
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

function placementClearOverrides(int $id, array $fields, ?array $review = null): array
{
    if (!$fields || !array_is_list($fields)
        || array_filter($fields, static fn($field) => !is_string($field) || !preg_match('/^[a-z][a-z0-9_]*$/D', $field))) {
        throw new InvalidArgumentException('Choose the fields to return to JobDiva.', 422);
    }
    $fields = array_values(array_unique($fields));
    return placementWithLockedRecord((int) effectiveTenantIdForRequest(), $id, static function (array $row, PDO $pdo) use ($id, $fields, $review): array {
        $current = placementOwnershipFields($row['coreflux_overridden_fields'] ?? null);
        $remaining = array_values(array_diff($current, $fields));
        if ($remaining === $current) return ['changed' => false, 'released_fields' => []];
        if ($review !== null) {
            $seen = $review['owned_fields'] ?? null;
            $values = $review['values'] ?? null;
            if (!is_array($seen) || !array_is_list($seen) || !is_array($values)
                || array_filter($seen, static fn($field) => !is_string($field))) {
                throw new InvalidArgumentException('Refresh the placement before returning fields to JobDiva.', 422);
            }
            $expected = $current; sort($expected); sort($seen);
            if ($seen !== $expected) throw new RuntimeException('Field ownership changed. Refresh the placement and review it again.', 409);
            foreach ($fields as $field) {
                if (!array_key_exists($field, $values) || ($row[$field] ?? null) !== $values[$field]) {
                    throw new RuntimeException('A reviewed field changed. Refresh the placement and review it again.', 409);
                }
            }
        }
        $pair = ['status', 'actual_end_date'];
        if (in_array($row['status'], ['ended', 'cancelled'], true)
            && count(array_intersect($pair, $current)) === 2
            && count(array_intersect($pair, $fields)) === 1) {
            throw new InvalidArgumentException('Return the status and actual end date to JobDiva together for this closed placement.', 422);
        }
        $released = array_values(array_diff($current, $remaining));
        scopedUpdate('placements', $id, ['coreflux_overridden_fields' => $remaining === [] ? null : json_encode($remaining, JSON_THROW_ON_ERROR)]);
        placementRequiredAudit('placement.override_cleared', ['id' => $id, 'fields' => $released,
            'before' => ['coreflux_overridden_fields' => $current],
            'after' => ['coreflux_overridden_fields' => $remaining],
        ], $id);
        return ['changed' => true, 'released_fields' => $released];
    });
}

/** A successful native write must have its audit receipt in the same transaction. */
function placementRequiredAudit(string $event, array $meta, int $id): void
{
    $pdo = getDB();
    if (!$pdo || !$pdo->inTransaction()) throw new RuntimeException('Placement audit requires the edit transaction.', 503);
    $changeId = bin2hex(random_bytes(16));
    $meta['ownership_change_id'] = $changeId;
    placementsAudit($event, $meta, $id);
    $audit = $pdo->prepare("SELECT id FROM audit_log WHERE tenant_id=? AND event=? AND target_id=? AND JSON_UNQUOTE(JSON_EXTRACT(meta_json,'$.ownership_change_id'))=? LIMIT 1");
    $audit->execute([currentTenantId(), $event, $id, $changeId]);
    if (!$audit->fetchColumn()) throw new RuntimeException('The placement change could not be recorded. Please try again.', 503);
}

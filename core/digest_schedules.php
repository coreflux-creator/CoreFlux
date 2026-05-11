<?php
/**
 * Tenant digest schedules — read/write helpers.
 *
 * Used by:
 *   • scripts/money_movement_weekly.php  (skips tenant when dow≠today OR enabled=0)
 *   • scripts/dunning_daily.php          (hour gate)
 *   • API /api/tenant_digest_schedules.php (admin CRUD)
 *
 * Schedule for an unconfigured tenant defaults to:
 *   money_movement   → dow=1 (Mon) hour=13 (≈9am ET) enabled
 *   dunning          → dow=0      hour=14            enabled (dow=0 = daily)
 *   ap_weekly_queue  → defers to ap_settings (existing behaviour)
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const DIGEST_DEFAULTS = [
    'money_movement'  => ['dow' => 1, 'hour' => 13, 'enabled' => 1, 'cadence' => 'weekly'],
    'dunning'         => ['dow' => 0, 'hour' => 14, 'enabled' => 1, 'cadence' => 'daily'],
    'ap_weekly_queue' => ['dow' => 7, 'hour' => 22, 'enabled' => 1, 'cadence' => 'weekly'],
];

function cf_digest_schedule_get(int $tenantId, string $key): array
{
    $defaults = DIGEST_DEFAULTS[$key] ?? ['dow' => 1, 'hour' => 13, 'enabled' => 1, 'cadence' => 'weekly'];
    try {
        $st = getDB()->prepare(
            'SELECT dow, hour, enabled, recipients_json
               FROM tenant_digest_schedules
              WHERE tenant_id = :t AND digest_key = :k LIMIT 1'
        );
        $st->execute(['t' => $tenantId, 'k' => $key]);
        $row = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$row) return $defaults + ['source' => 'default'];
        return [
            'dow'        => (int) $row['dow'],
            'hour'       => (int) $row['hour'],
            'enabled'    => (int) $row['enabled'],
            'cadence'    => $defaults['cadence'] ?? 'weekly',
            'recipients' => !empty($row['recipients_json']) ? json_decode((string) $row['recipients_json'], true) : null,
            'source'     => 'tenant_override',
        ];
    } catch (\Throwable $_) { return $defaults + ['source' => 'default']; }
}

/**
 * Does the schedule say "fire right now"? Helper for cron scripts.
 * $now = unix timestamp (UTC). Weekly digests fire when ISO dow + hour match.
 * Daily digests fire when hour matches.
 */
function cf_digest_schedule_should_fire(array $schedule, int $now): bool
{
    if (empty($schedule['enabled'])) return false;
    $h = (int) date('G', $now);
    if (($schedule['hour'] ?? -1) !== $h) return false;
    $cadence = $schedule['cadence'] ?? 'weekly';
    if ($cadence === 'daily') return true;
    $d = (int) date('N', $now); // 1..7 (Mon..Sun)
    return ((int) ($schedule['dow'] ?? 0)) === $d;
}

function cf_digest_schedule_set(int $tenantId, string $key, int $dow, int $hour, bool $enabled, ?array $recipients, ?int $byUserId): void
{
    getDB()->prepare(
        'INSERT INTO tenant_digest_schedules (tenant_id, digest_key, dow, hour, enabled, recipients_json, updated_by_user_id)
         VALUES (:t, :k, :d, :h, :e, :r, :u)
         ON DUPLICATE KEY UPDATE
            dow                = VALUES(dow),
            hour               = VALUES(hour),
            enabled            = VALUES(enabled),
            recipients_json    = VALUES(recipients_json),
            updated_by_user_id = VALUES(updated_by_user_id)'
    )->execute([
        't' => $tenantId, 'k' => $key,
        'd' => max(0, min(7,  $dow)),
        'h' => max(0, min(23, $hour)),
        'e' => $enabled ? 1 : 0,
        'r' => $recipients !== null ? json_encode($recipients) : null,
        'u' => $byUserId ?: null,
    ]);
}

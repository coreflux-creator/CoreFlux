<?php
/** Shared profile reference and validation helpers. */
declare(strict_types=1);

/** @return array{schedules: array<int,array>, cycles: array<int,array>, cycles_by_schedule: array<int,list<array>>} */
function payrollProfileReferenceData(int $tenantId): array
{
    $pdo = getDB();
    $schedules = [];
    $cycles = [];
    $cyclesBySchedule = [];

    $stmt = $pdo->prepare('SELECT * FROM payroll_pay_schedules WHERE tenant_id = :tenant_id ORDER BY active DESC, name');
    $stmt->execute(['tenant_id' => $tenantId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $schedules[(int) $row['id']] = $row;

    $stmt = $pdo->prepare(
        'SELECT c.*, s.name AS schedule_name, s.frequency
           FROM payroll_pay_cycles c
           JOIN payroll_pay_schedules s ON s.id = c.schedule_id AND s.tenant_id = c.tenant_id
          WHERE c.tenant_id = :tenant_id
          ORDER BY c.active DESC, c.name'
    );
    $stmt->execute(['tenant_id' => $tenantId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int) $row['id'];
        $scheduleId = (int) $row['schedule_id'];
        $cycles[$id] = $row;
        $cyclesBySchedule[$scheduleId][] = $row;
    }
    return ['schedules' => $schedules, 'cycles' => $cycles, 'cycles_by_schedule' => $cyclesBySchedule];
}

function payrollPresentProfile(?array $profile, array $refs): ?array
{
    if (!$profile) return null;
    $scheduleId = (int) ($profile['schedule_id'] ?? 0);
    $cycleId = (int) ($profile['cycle_id'] ?? 0);
    $profile['schedule_name'] = $refs['schedules'][$scheduleId]['name'] ?? null;
    $profile['cycle_name'] = $refs['cycles'][$cycleId]['name'] ?? null;
    return $profile;
}

/** @return list<string> */
function payrollProfileAssignmentGaps(?array $profile, array $refs): array
{
    if (!$profile) return ['payroll_profile'];
    $gaps = [];
    $scheduleId = (int) ($profile['schedule_id'] ?? 0);
    $cycleId = (int) ($profile['cycle_id'] ?? 0);
    if (!$scheduleId || !isset($refs['schedules'][$scheduleId])) $gaps[] = 'pay_schedule';
    $scheduleCycles = $refs['cycles_by_schedule'][$scheduleId] ?? [];
    if ($scheduleCycles && (!$cycleId || !isset($refs['cycles'][$cycleId]))) $gaps[] = 'pay_cycle';
    return $gaps;
}

function payrollNormalizeProfileInput(array $body, ?array $existing, int $tenantId): array
{
    $refs = payrollProfileReferenceData($tenantId);
    $enabled = array_key_exists('enabled', $body)
        ? (int) payrollProfileBoolean($body['enabled'], 'enabled')
        : (int) ($existing['enabled'] ?? 1);

    $scheduleId = array_key_exists('schedule_id', $body)
        ? ((int) ($body['schedule_id'] ?? 0) ?: null)
        : (($existing['schedule_id'] ?? null) ? (int) $existing['schedule_id'] : null);
    $cycleId = array_key_exists('cycle_id', $body)
        ? ((int) ($body['cycle_id'] ?? 0) ?: null)
        : (($existing['cycle_id'] ?? null) ? (int) $existing['cycle_id'] : null);

    if ($cycleId !== null) {
        $cycle = $refs['cycles'][$cycleId] ?? null;
        if (!$cycle) api_error('Selected pay cycle was not found', 422);
        if ($enabled && !(int) $cycle['active']) api_error('Selected pay cycle is inactive', 422);
        $cycleScheduleId = (int) $cycle['schedule_id'];
        if ($scheduleId !== null && $scheduleId !== $cycleScheduleId) {
            api_error('Selected pay cycle does not belong to the selected pay schedule', 422);
        }
        $scheduleId = $cycleScheduleId;
    }

    if ($scheduleId !== null) {
        $schedule = $refs['schedules'][$scheduleId] ?? null;
        if (!$schedule) api_error('Selected pay schedule was not found', 422);
        if ($enabled && !(int) $schedule['active']) api_error('Selected pay schedule is inactive', 422);
        if ($cycleId === null) {
            $activeCycles = array_values(array_filter(
                $refs['cycles_by_schedule'][$scheduleId] ?? [],
                static fn(array $cycle): bool => (int) $cycle['active'] === 1
            ));
            if (count($activeCycles) === 1) {
                $cycleId = (int) $activeCycles[0]['id'];
            } elseif ($enabled && count($activeCycles) > 1) {
                api_error('Choose a pay cycle; this schedule has multiple active cohorts', 422);
            }
        }
    } elseif ($enabled) {
        api_error('A pay schedule is required for an enabled payroll profile', 422);
    }

    $workState = strtoupper(trim((string) ($body['work_state'] ?? ($existing['work_state'] ?? 'CA'))));
    if (!preg_match('/^[A-Z]{2}$/', $workState)) api_error('Work state must be a 2-letter code', 422);

    $paymentMethod = (string) ($body['payment_method'] ?? ($existing['payment_method'] ?? 'direct_deposit'));
    if (!in_array($paymentMethod, ['direct_deposit', 'check'], true)) api_error('Invalid payment method', 422);

    $hours = array_key_exists('default_hours_per_period', $body)
        ? ($body['default_hours_per_period'] === '' || $body['default_hours_per_period'] === null
            ? null : (float) $body['default_hours_per_period'])
        : ($existing['default_hours_per_period'] ?? null);
    if ($hours !== null && ($hours < 0 || $hours > 744)) api_error('Default hours must be between 0 and 744', 422);

    $bps = payrollProfileInteger($body, $existing, 'retirement_pretax_bps', 0);
    if ($bps < 0 || $bps > 10000) api_error('Retirement contribution must be between 0% and 100%', 422);

    $data = [
        'schedule_id' => $scheduleId,
        'cycle_id' => $cycleId,
        'work_state' => $workState,
        'payment_method' => $paymentMethod,
        'default_hours_per_period' => $hours,
        'retirement_pretax_bps' => $bps,
        'health_premium_cents' => payrollProfileInteger($body, $existing, 'health_premium_cents', 0),
        'hsa_pretax_cents' => payrollProfileInteger($body, $existing, 'hsa_pretax_cents', 0),
        'extra_post_tax_cents' => payrollProfileInteger($body, $existing, 'extra_post_tax_cents', 0),
        'enabled' => $enabled,
    ];
    foreach (['health_premium_cents', 'hsa_pretax_cents', 'extra_post_tax_cents'] as $field) {
        if ($data[$field] < 0) api_error(str_replace('_', ' ', $field) . ' cannot be negative', 422);
    }
    if (array_key_exists('notes', $body)) $data['notes'] = trim((string) $body['notes']) ?: null;
    elseif ($existing && array_key_exists('notes', $existing)) $data['notes'] = $existing['notes'];
    return $data;
}

function payrollProfileInteger(array $body, ?array $existing, string $field, int $default): int
{
    if (!array_key_exists($field, $body)) return (int) ($existing[$field] ?? $default);
    if ($body[$field] === '' || $body[$field] === null) return $default;
    if (!is_numeric($body[$field])) api_error(str_replace('_', ' ', $field) . ' must be numeric', 422);
    return (int) round((float) $body[$field]);
}

function payrollProfileBoolean(mixed $value, string $field): bool
{
    if (is_bool($value)) return $value;
    if (is_int($value) || is_float($value)) return (float) $value !== 0.0;
    $normalized = strtolower(trim((string) $value));
    if (in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true)) return true;
    if (in_array($normalized, ['0', 'false', 'no', 'n', 'off', ''], true)) return false;
    api_error("{$field} must be true or false", 422);
}

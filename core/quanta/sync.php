<?php
/** Preview and import Quanta's daily time into CoreFlux's canonical weekly timesheets. */
declare(strict_types=1);

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/../sub_tenants.php';
require_once __DIR__ . '/../../modules/time/lib/time.php';
require_once __DIR__ . '/../../modules/staffing/lib/timesheets.php';

function quantaValue(array $row, array $keys): mixed
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && $row[$key] !== '') return $row[$key];
    }
    return null;
}

function quantaDecimalHours(mixed $value, string $field): float
{
    if (!is_numeric($value)) throw new InvalidArgumentException("Quanta {$field} is not numeric");
    $hours = (float) $value;
    if (!is_finite($hours) || $hours < 0 || $hours > 24) {
        throw new InvalidArgumentException("Quanta {$field} must be between 0 and 24 hours");
    }
    return round($hours, 2);
}

function quantaDimensions(mixed $values): array
{
    if ($values === null) return [];
    if (!is_array($values) || ($values !== [] && array_is_list($values))) {
        throw new InvalidArgumentException('Quanta dimensions must be named values');
    }
    $normalized = [];
    foreach ($values as $key => $value) {
        if (!is_string($key) || $key === '' || !is_scalar($value)) {
            throw new InvalidArgumentException('Quanta dimensions include an unsupported value');
        }
        $normalized[$key] = (string) $value;
    }
    ksort($normalized, SORT_STRING);
    if (strlen(json_encode($normalized, JSON_THROW_ON_ERROR)) > 2000) {
        throw new InvalidArgumentException('Quanta dimensions are too large to route safely');
    }
    return $normalized;
}

function quantaDimensionKey(array $values): string
{
    return hash('sha256', json_encode(quantaDimensions($values), JSON_THROW_ON_ERROR));
}

/** No guessed person, placement, timezone, or overtime classification. */
function quantaNormalizeEntry(array $raw, array $worksites, string $requestedStatus): array
{
    $id = trim((string) quantaValue($raw, ['id', 'entry_id']));
    $worker = trim((string) quantaValue($raw, ['worker_id', 'user_id']));
    $site = trim((string) quantaValue($raw, ['worksite_id']));
    if ($id === '' || strlen($id) > 128) throw new InvalidArgumentException('Quanta entry has no usable ID');
    if ($worker === '' || strlen($worker) > 128) throw new InvalidArgumentException("Quanta entry {$id} has no worker ID");
    if (strlen($site) > 128) throw new InvalidArgumentException("Quanta entry {$id} has an invalid worksite ID");
    $dimensions = quantaDimensions($raw['dimension_values'] ?? null);

    $date = (string) quantaValue($raw, ['work_date', 'date']);
    if ($date === '') {
        $clockIn = quantaValue($raw, ['clock_in_at', 'in_time', 'clock_in']);
        if (!is_string($clockIn) || $clockIn === '') throw new InvalidArgumentException("Quanta entry {$id} has no work date or clock-in");
        $tz = trim((string) quantaValue($worksites[$site] ?? [], ['timezone_override', 'timezone']));
        if ($tz === '' && preg_match('/[+-][0-9]{2}:[0-9]{2}$/', $clockIn)) {
            $tz = (new DateTimeImmutable($clockIn))->format('P');
        }
        if ($tz === '') throw new InvalidArgumentException("Quanta entry {$id} needs its worksite timezone to derive a work date");
        try { $date = (new DateTimeImmutable($clockIn))->setTimezone(new DateTimeZone($tz))->format('Y-m-d'); }
        catch (Throwable $e) { throw new InvalidArgumentException("Quanta entry {$id} has an invalid clock-in or worksite timezone"); }
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new InvalidArgumentException("Quanta entry {$id} has an invalid work date");

    $status = strtolower(trim((string) quantaValue($raw, ['timesheet_status'])));
    if ($status === '' && is_array($raw['timesheet'] ?? null)) {
        $status = strtolower(trim((string) ($raw['timesheet']['status'] ?? '')));
    }
    if ($status === '') $status = $requestedStatus === 'approved' ? 'approved' : 'not_reported';
    if (!in_array($status, ['approved', 'submitted', 'not_reported'], true)) {
        throw new InvalidArgumentException("Quanta entry {$id} has unsupported timesheet status {$status}");
    }
    if ($requestedStatus === 'approved' && $status !== 'approved') {
        throw new InvalidArgumentException("Quanta entry {$id} is not approved");
    }

    $breakdown = [
        'regular' => quantaValue($raw, ['reg_hours', 'regular_hours']),
        'overtime' => quantaValue($raw, ['ot_hours', 'overtime_hours']),
        'doubletime' => quantaValue($raw, ['dt_hours', 'doubletime_hours']),
        'pto' => quantaValue($raw, ['pto_hours']),
    ];
    $components = [];
    $hasBreakdown = false;
    foreach ($breakdown as $type => $value) {
        if ($value === null) continue;
        $hasBreakdown = true;
        $hours = quantaDecimalHours($value, $type . ' hours');
        if ($hours > 0) $components[$type] = $hours;
    }
    if (isset($components['pto'])) {
        $leaveType = strtolower(trim((string) quantaValue($raw, ['pto_type', 'leave_type', 'time_off_type'])));
        if (!in_array($leaveType, ['vacation', 'holiday', 'sick', 'bereavement'], true)) {
            throw new InvalidArgumentException("Quanta entry {$id} has PTO without a supported leave type");
        }
        $components['pto_' . $leaveType] = $components['pto'];
        unset($components['pto']);
    }
    if (!$hasBreakdown) {
        $hours = quantaValue($raw, ['duration_hours', 'hours']);
        if ($hours === null) {
            $minutes = quantaValue($raw, ['duration_minutes']);
            if ($minutes !== null && is_numeric($minutes)) $hours = (float) $minutes / 60;
        }
        if ($hours === null) {
            $seconds = quantaValue($raw, ['duration_seconds']);
            if ($seconds !== null && is_numeric($seconds)) $hours = (float) $seconds / 3600;
        }
        if ($hours === null) throw new InvalidArgumentException("Quanta entry {$id} has no duration");
        $type = strtolower(trim((string) quantaValue($raw, ['hour_type', 'time_type', 'category'])));
        if ($type === '') {
            throw new InvalidArgumentException("Quanta entry {$id} has no regular/overtime breakdown or time type");
        }
        if ($type === 'pto') {
            $type = strtolower(trim((string) quantaValue($raw, ['pto_type', 'leave_type', 'time_off_type'])));
        }
        $type = match ($type) {
            'regular', 'regular_billable' => 'regular',
            'overtime', 'ot', 'ot_billable' => 'overtime',
            'doubletime', 'dt' => 'doubletime',
            'vacation' => 'pto_vacation',
            'holiday' => 'pto_holiday',
            'sick' => 'pto_sick',
            'bereavement' => 'pto_bereavement',
            default => throw new InvalidArgumentException("Quanta entry {$id} has unsupported time type {$type}"),
        };
        $components[$type] = quantaDecimalHours($hours, 'duration');
    }
    $total = array_sum($components);
    if ($total <= 0 || $total > 24) throw new InvalidArgumentException("Quanta entry {$id} must contain 0-24 hours");
    $duration = quantaValue($raw, ['duration_hours', 'hours']);
    if ($hasBreakdown && $duration !== null && abs((float) $duration - $total) > 0.02) {
        throw new InvalidArgumentException("Quanta entry {$id} duration does not match its hour breakdown");
    }
    return [
        'id' => $id, 'worker_id' => $worker, 'worksite_id' => $site,
        'dimension_values' => $dimensions, 'dimension_key' => quantaDimensionKey($dimensions),
        'work_date' => $date, 'source_status' => $status,
        'components' => $components,
        'hours' => round($total, 2),
        'description' => substr(trim((string) ($raw['notes'] ?? $raw['description'] ?? '')), 0, 500),
    ];
}

function quantaComponentCategory(string $component): array
{
    return match ($component) {
        'regular' => ['regular', 'regular_billable', 1],
        'overtime' => ['overtime', 'OT_billable', 1],
        'doubletime' => ['doubletime', 'OT_billable', 1],
        'pto_vacation' => ['pto', 'vacation', 0],
        'pto_holiday' => ['holiday', 'holiday', 0],
        'pto_sick' => ['sick', 'sick', 0],
        'pto_bereavement' => ['bereavement', 'bereavement', 0],
        default => throw new InvalidArgumentException('Unsupported Quanta time component'),
    };
}

function quantaRoutes(int $tenantId): array
{
    $placementTenant = effectiveTenantIdForModule('placements', $tenantId) ?? $tenantId;
    $peopleTenant = effectiveTenantIdForModule('people', $tenantId) ?? $tenantId;
    $stmt = getDB()->prepare(
        'SELECT r.*, p.person_id, p.status AS placement_status, p.start_date, p.end_date, p.actual_end_date,
                p.title, p.end_client_name, p.deleted_at, pe.email_primary AS person_email,
                CONCAT_WS(" ", pe.first_name, pe.last_name) AS person_name
           FROM quanta_time_routes r
      LEFT JOIN placements p ON p.id = r.placement_id AND p.tenant_id = :pt
      LEFT JOIN people pe ON pe.id = p.person_id AND pe.tenant_id = :pet AND pe.deleted_at IS NULL
          WHERE r.tenant_id = :t ORDER BY r.worker_id, r.worksite_id, r.effective_from'
    );
    $stmt->execute(['pt' => $placementTenant, 'pet' => $peopleTenant, 't' => $tenantId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function quantaRouteFor(array $routes, array $entry): array
{
    $matching = array_values(array_filter($routes, static fn (array $route): bool =>
        (string) $route['worker_id'] === $entry['worker_id']
        && (string) $route['worksite_id'] === $entry['worksite_id']
        && (string) $route['dimension_key'] === $entry['dimension_key']
        && (string) $route['effective_from'] <= $entry['work_date']
        && (empty($route['effective_to']) || (string) $route['effective_to'] >= $entry['work_date'])
    ));
    if (!$matching) throw new RuntimeException('Map this Quanta worker, worksite, and dimensions to a CoreFlux placement');
    if (count($matching) !== 1) throw new RuntimeException('Overlapping Quanta placement routes need correction');
    $route = $matching[0];
    if (empty($route['person_id']) || empty($route['person_name'])) throw new RuntimeException('The mapped placement has no available person');
    if (!empty($route['deleted_at']) || $route['placement_status'] === 'cancelled') throw new RuntimeException('The mapped placement is deleted or cancelled');
    if (!empty($route['start_date']) && $entry['work_date'] < $route['start_date']) throw new RuntimeException('Work date is before placement start');
    $end = $route['actual_end_date'] ?: $route['end_date'];
    if ($end && $entry['work_date'] > $end) throw new RuntimeException('Work date is after placement end');
    return $route;
}

function quantaImportLookup(int $tenantId, array $entryIds): array
{
    if (!$entryIds) return [];
    $found = [];
    foreach (array_chunk(array_values(array_unique($entryIds)), 200) as $chunk) {
        $holders = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = getDB()->prepare(
            "SELECT qi.*, te.status AS entry_status, te.placement_id, te.person_id, te.work_date,
                    te.timesheet_id AS current_timesheet_id, te.bill_extracted_at, te.ap_extracted_at,
                    te.payroll_extracted_at
               FROM quanta_time_imports qi
          LEFT JOIN time_entries te ON te.id = qi.time_entry_id AND te.tenant_id = qi.tenant_id
              WHERE qi.tenant_id = ? AND qi.quanta_entry_id IN ({$holders})"
        );
        $stmt->execute(array_merge([$tenantId], $chunk));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $found[(string) $row['quanta_entry_id']][(string) $row['component']] = $row;
        }
    }
    return $found;
}

function quantaComponentHash(array $entry, array $route, string $component, float $hours): string
{
    return hash('sha256', json_encode([
        (int) $route['placement_id'], (int) $route['person_id'], $entry['work_date'],
        $component, $hours, $entry['description'], $entry['dimension_key'],
    ], JSON_THROW_ON_ERROR));
}

function quantaEntryDecision(array $entry, array $route, array $imports): array
{
    $prior = $imports[$entry['id']] ?? [];
    foreach ($prior as $component => $row) {
        if (!isset($entry['components'][$component])) return ['conflict', 'A previously imported hour type is now absent; correct it in CoreFlux'];
    }
    $changed = false;
    $new = false;
    foreach ($entry['components'] as $component => $hours) {
        $priorRow = $prior[$component] ?? null;
        if (!$priorRow) { $new = true; continue; }
        if (empty($priorRow['entry_status'])) return ['conflict', 'The original CoreFlux time row was deleted'];
        if ($priorRow['source_hash'] === quantaComponentHash($entry, $route, $component, $hours)) continue;
        if (!in_array($priorRow['entry_status'], ['draft', 'pending_review', 'rejected'], true)
            || $priorRow['bill_extracted_at'] || $priorRow['ap_extracted_at'] || $priorRow['payroll_extracted_at']) {
            return ['conflict', 'Changed Quanta time has already been approved or used downstream'];
        }
        if ((int) $priorRow['placement_id'] !== (int) $route['placement_id']
            || (int) $priorRow['person_id'] !== (int) $route['person_id']
            || $priorRow['work_date'] !== $entry['work_date']) {
            return ['conflict', 'Changed Quanta time would move to a different person, placement, or date'];
        }
        $changed = true;
    }
    return $changed ? ['update', 'Source hours changed; update unapproved CoreFlux time']
        : ($new ? ['ready', 'Ready for CoreFlux review'] : ['imported', 'Already imported']);
}

function quantaPreviewRows(int $tenantId, array $rawEntries, array $worksites, string $requestedStatus): array
{
    $routes = quantaRoutes($tenantId);
    $ids = [];
    foreach ($rawEntries as $raw) {
        $id = trim((string) quantaValue($raw, ['id', 'entry_id']));
        if ($id !== '') $ids[] = $id;
    }
    $imports = quantaImportLookup($tenantId, $ids);
    $rows = [];
    $counts = ['ready' => 0, 'update' => 0, 'imported' => 0, 'conflict' => 0];
    foreach ($rawEntries as $raw) {
        $id = trim((string) quantaValue($raw, ['id', 'entry_id']));
        $row = ['id' => $id, 'status' => 'conflict'];
        try {
            $entry = quantaNormalizeEntry($raw, $worksites, $requestedStatus);
            $row += $entry;
            $route = quantaRouteFor($routes, $entry);
            $row['placement_id'] = (int) $route['placement_id'];
            $row['person_name'] = $route['person_name'];
            [$row['status'], $row['message']] = quantaEntryDecision($entry, $route, $imports);
        } catch (Throwable $e) {
            $row['message'] = $e->getMessage();
        }
        $counts[$row['status']]++;
        $rows[] = $row;
    }
    return ['rows' => $rows, 'counts' => $counts, 'total' => count($rows)];
}

function quantaWorksitesById(array $sites): array
{
    $indexed = [];
    foreach ($sites as $site) {
        $id = trim((string) ($site['id'] ?? ''));
        if ($id !== '') $indexed[$id] = $site;
    }
    return $indexed;
}

/** Import only selected source IDs, refetched by the caller after preview. Atomic on conflict. */
function quantaImportSelected(int $tenantId, int $actorId, array $rawEntries, array $worksites, string $requestedStatus, array $selectedIds): array
{
    $selectedIds = array_values(array_unique(array_map('strval', $selectedIds)));
    if (!$selectedIds || count($selectedIds) > 200) throw new InvalidArgumentException('Select between 1 and 200 Quanta entries');
    $source = [];
    foreach ($rawEntries as $raw) {
        $id = trim((string) quantaValue($raw, ['id', 'entry_id']));
        if ($id !== '') $source[$id] = $raw;
    }
    foreach ($selectedIds as $id) {
        if (!isset($source[$id])) throw new RuntimeException("Quanta entry {$id} is no longer in this approved source window");
    }
    $pdo = getDB();
    $ownsTxn = cf_tx_begin($pdo);
    $touched = [];
    $inserted = 0;
    $updated = 0;
    $skipped = 0;
    try {
        $routes = quantaRoutes($tenantId);
        $imports = quantaImportLookup($tenantId, $selectedIds);
        foreach ($selectedIds as $id) {
            $entry = quantaNormalizeEntry($source[$id], $worksites, $requestedStatus);
            $route = quantaRouteFor($routes, $entry);
            [$decision, $message] = quantaEntryDecision($entry, $route, $imports);
            if ($decision === 'conflict') throw new RuntimeException("Quanta entry {$id}: {$message}");
            if ($decision === 'imported') { $skipped++; continue; }
            [$weekStart, $weekEnd] = timeWeekBounds($entry['work_date']);
            $timesheet = staffingTimesheetUpsert((int) $route['person_id'], $weekStart, $weekEnd, [
                'source' => 'quanta', 'source_system' => 'quanta', 'created_by_user_id' => $actorId,
            ]);
            if (in_array($timesheet['status'], ['approved', 'payroll_ready', 'billing_ready', 'locked'], true)) {
                throw new RuntimeException("Week of {$weekStart} is already {$timesheet['status']}; reopen it before import");
            }
            $periodId = timeOpenPeriodIdForDate($entry['work_date']);
            foreach ($entry['components'] as $component => $hours) {
                $prior = $imports[$id][$component] ?? null;
                $hash = quantaComponentHash($entry, $route, $component, $hours);
                if ($prior && $prior['source_hash'] === $hash) continue;
                [$hourType, $category, $billable] = quantaComponentCategory($component);
                $existingId = $prior ? (int) $prior['time_entry_id'] : 0;
                $usage = scopedFind(
                    'SELECT COALESCE(SUM(hours),0) AS hours FROM time_entries
                      WHERE tenant_id = :tenant_id AND person_id = :p AND work_date = :d
                        AND status != "superseded" AND id != :exclude_id',
                    ['p' => (int) $route['person_id'], 'd' => $entry['work_date'], 'exclude_id' => $existingId]
                );
                if ((float) ($usage['hours'] ?? 0) + $hours > 24.0) {
                    throw new RuntimeException("Quanta entry {$id} would exceed 24 hours on {$entry['work_date']}");
                }
                $weekUsage = scopedFind(
                    'SELECT COALESCE(SUM(hours),0) AS hours FROM time_entries
                      WHERE tenant_id = :tenant_id AND person_id = :p AND work_date BETWEEN :s AND :e
                        AND status != "superseded" AND id != :exclude_id',
                    ['p' => (int) $route['person_id'], 's' => $weekStart, 'e' => $weekEnd, 'exclude_id' => $existingId]
                );
                if ((float) ($weekUsage['hours'] ?? 0) + $hours > 168.0) {
                    throw new RuntimeException("Quanta entry {$id} would exceed 168 hours in the week");
                }
                $payload = [
                    'placement_id' => (int) $route['placement_id'], 'person_id' => (int) $route['person_id'],
                    'period_id' => $periodId, 'timesheet_id' => (int) $timesheet['id'],
                    'work_date' => $entry['work_date'], 'hour_type' => $hourType,
                    'category' => $category, 'billable' => $billable, 'payable' => 1,
                    'hours' => $hours, 'description' => $entry['description'] ?: null,
                    'source' => 'quanta', 'source_system' => 'quanta', 'status' => 'pending_review',
                ];
                if ($prior) {
                    scopedUpdate('time_entries', $existingId, $payload);
                    scopedUpdate('quanta_time_imports', (int) $prior['id'], [
                        'source_hash' => $hash, 'source_timesheet_status' => $entry['source_status'],
                        'timesheet_id' => (int) $timesheet['id'], 'imported_by_user_id' => $actorId,
                    ]);
                    $updated++;
                } else {
                    $payload['external_id'] = hash('sha256', $id . '|' . $component);
                    $payload['created_by_user_id'] = $actorId;
                    $entryId = scopedInsert('time_entries', $payload);
                    scopedInsert('quanta_time_imports', [
                        'quanta_entry_id' => $id, 'component' => $component,
                        'time_entry_id' => $entryId, 'timesheet_id' => (int) $timesheet['id'],
                        'source_hash' => $hash, 'source_timesheet_status' => $entry['source_status'],
                        'imported_by_user_id' => $actorId,
                    ]);
                    $inserted++;
                }
            }
            $touched[(int) $timesheet['id']] = true;
        }
        $artifacts = [];
        foreach (array_keys($touched) as $sheetId) {
            timeReconcileTimesheetHeader($sheetId);
            $header = staffingTimesheetRecordArtifactEvent($sheetId, 'timesheet.entries_imported', $actorId, [
                'source' => 'quanta',
            ]);
            $artifacts[] = [
                'id' => (int) $header['id'], 'display_id' => $header['display_id'],
                'artifact_id' => $header['artifact_id'], 'status' => $header['status'],
            ];
        }
        $stmt = $pdo->prepare('UPDATE quanta_connections SET last_import_at = NOW(), last_import_error = NULL WHERE tenant_id = :t');
        $stmt->execute(['t' => $tenantId]);
        cf_tx_commit($pdo, $ownsTxn);
        return ['inserted' => $inserted, 'updated' => $updated, 'already_imported' => $skipped, 'timesheets' => $artifacts];
    } catch (Throwable $e) {
        cf_tx_rollback($pdo, $ownsTxn);
        throw $e;
    }
}

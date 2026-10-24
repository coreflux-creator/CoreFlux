<?php
/** Atomic legal-entity and first fiscal-calendar creation. */
declare(strict_types=1);

require_once __DIR__ . '/entity_profile.php';

function accountingFirstFiscalYear($value): int
{
    if (!is_string($value) && !is_int($value)) {
        throw new InvalidArgumentException('First fiscal year must be a four-digit year');
    }
    $year = (string) $value;
    if (!preg_match('/^(19|20|21)[0-9]{2}$/D', $year)) {
        throw new InvalidArgumentException('First fiscal year must be a four-digit year from 1900 to 2199');
    }
    return (int) $year;
}

/** @return array{entity_id:int,calendar_id:int,periods_created:int} */
function accountingCreateEntityWithCalendar(PDO $pdo, int $tenantId, array $input, int $year): array
{
    if ($tenantId <= 0 || $year < 1900 || $year > 2199) {
        throw new InvalidArgumentException('Valid tenant and first fiscal year are required');
    }
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $profile = accountingReviewNewEntityProfile($pdo, $tenantId, $input);
        $entity = $pdo->prepare(
            'INSERT INTO accounting_entities
                (tenant_id, code, legal_name, country, base_currency, entity_type,
                 accounting_basis, fiscal_year_start_month, parent_entity_id, active)
             VALUES (:tenant_id, :code, :legal_name, :country, :base_currency, :entity_type,
                     :accounting_basis, :fiscal_year_start_month, :parent_entity_id, 1)'
        );
        $entity->execute(['tenant_id' => $tenantId] + $profile);
        $entityId = (int) $pdo->lastInsertId();

        $calendar = $pdo->prepare(
            'INSERT INTO accounting_fiscal_calendars
                (tenant_id, entity_id, name, calendar_type, start_date, end_date,
                 period_count, is_default, active)
             VALUES (:tenant_id, :entity_id, :name, "calendar_year", :start_date,
                     :end_date, 12, 1, 1)'
        );
        $calendar->execute([
            'tenant_id' => $tenantId,
            'entity_id' => $entityId,
            'name' => $year . ' calendar year',
            'start_date' => $year . '-01-01',
            'end_date' => $year . '-12-31',
        ]);
        $calendarId = (int) $pdo->lastInsertId();

        $period = $pdo->prepare(
            'INSERT INTO accounting_periods
                (tenant_id, entity_id, calendar_id, period_number, start_date, end_date, status)
             VALUES (:tenant_id, :entity_id, :calendar_id, :period_number,
                     :start_date, :end_date, "open")'
        );
        for ($month = 1; $month <= 12; $month++) {
            $start = sprintf('%04d-%02d-01', $year, $month);
            $period->execute([
                'tenant_id' => $tenantId,
                'entity_id' => $entityId,
                'calendar_id' => $calendarId,
                'period_number' => $month,
                'start_date' => $start,
                'end_date' => DateTimeImmutable::createFromFormat('!Y-m-d', $start)
                    ->modify('last day of this month')->format('Y-m-d'),
            ]);
        }
        if ($ownsTransaction) $pdo->commit();
        return ['entity_id' => $entityId, 'calendar_id' => $calendarId, 'periods_created' => 12];
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

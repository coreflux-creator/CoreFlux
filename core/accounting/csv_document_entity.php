<?php
/** Legal-entity selection shared by invoice and bill CSV imports. */
declare(strict_types=1);

/** @return array<string,array{id:int,code:string,base_currency:string}> */
function accountingCsvDocumentEntities(PDO $pdo, int $tenantId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, code, base_currency FROM accounting_entities
          WHERE tenant_id = :t AND active = 1 ORDER BY id'
    );
    $stmt->execute(['t' => $tenantId]);
    $entities = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $entities[strtoupper((string) $row['code'])] = [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'base_currency' => (string) $row['base_currency'],
        ];
    }
    return $entities;
}

/** @param array<string,array{id:int,code:string,base_currency:string}> $entities */
function accountingCsvDocumentEntity(array $entities, string $code, string $currency): array
{
    if (!$entities) throw new InvalidArgumentException('Create an active legal entity before importing documents.');
    $key = strtoupper(trim($code));
    if ($key === '') {
        if (count($entities) !== 1) {
            throw new InvalidArgumentException('Entity code is required when this workspace has multiple legal entities.');
        }
        $entity = reset($entities);
    } else {
        $entity = $entities[$key] ?? null;
        if (!$entity) throw new InvalidArgumentException('Entity code does not name an active legal entity in this workspace.');
    }
    $requestedCurrency = strtoupper(trim($currency));
    if ($requestedCurrency !== '' && $requestedCurrency !== $entity['base_currency']) {
        throw new InvalidArgumentException('Document currency must match the legal entity base currency.');
    }
    return $entity;
}

/**
 * Validate whole multi-line documents so a rejected row cannot silently
 * become a shorter invoice or bill when skip_invalid is requested.
 *
 * @return array{result:array,groups:array<string,array<int,array>>,entities:array<string,array>}
 */
function accountingCsvReviewDocumentGroups(
    array $result,
    array $entities,
    string $groupField,
    string $documentLabel,
    array $requiredHeaderFields
): array {
    $groups = [];
    $resolved = [];
    foreach ($result['rows'] as $rowNumber => $row) {
        $number = trim((string) ($row[$groupField] ?? ''));
        if ($number !== '') $groups[$number][$rowNumber] = $row;
    }

    foreach ($groups as $number => $rows) {
        $firstRowNumber = array_key_first($rows);
        $first = $rows[$firstRowNumber];
        foreach ($requiredHeaderFields as $field) {
            if (trim((string) ($first[$field] ?? '')) === '') {
                $result['errors'][$firstRowNumber][] = "{$field}: required on first row of {$documentLabel} #{$number}";
            }
        }
        try {
            $entity = accountingCsvDocumentEntity(
                $entities,
                (string) ($first['entity_code'] ?? ''),
                (string) ($first['currency'] ?? '')
            );
            $resolved[$number] = $entity;
        } catch (InvalidArgumentException $error) {
            $result['errors'][$firstRowNumber][] = $error->getMessage();
            continue;
        }
        foreach ($rows as $rowNumber => $row) {
            if ($rowNumber === $firstRowNumber) continue;
            $code = trim((string) ($row['entity_code'] ?? ''));
            if ($code !== '' && strcasecmp($code, $entity['code']) !== 0) {
                $result['errors'][$rowNumber][] = "Entity code conflicts with first row of {$documentLabel} #{$number}";
            }
            $currency = trim((string) ($row['currency'] ?? ''));
            if ($currency !== '' && strcasecmp($currency, $entity['base_currency']) !== 0) {
                $result['errors'][$rowNumber][] = "Currency conflicts with legal entity for {$documentLabel} #{$number}";
            }
        }
    }

    $result['error_count'] = count($result['errors']);
    $result['groups'] = count($groups);
    return ['result' => $result, 'groups' => $groups, 'entities' => $resolved];
}

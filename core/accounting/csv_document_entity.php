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

/** @return array{quantity:float,unitPrice:float,subtotal:float,tax:float,total:float} */
function accountingCsvDocumentLineAmounts(array $row): array
{
    $quantity = ($row['line_quantity'] ?? '') === '' ? 1.0 : (float) $row['line_quantity'];
    $unitPrice = ($row['line_unit_price'] ?? '') === '' ? 0.0 : (float) $row['line_unit_price'];
    $subtotal = ($row['line_subtotal'] ?? '') === ''
        ? round($quantity * $unitPrice, 2)
        : round((float) $row['line_subtotal'], 2);
    $tax = ($row['line_tax_amount'] ?? '') === '' ? 0.0 : round((float) $row['line_tax_amount'], 2);
    $total = ($row['line_total'] ?? '') === ''
        ? round($subtotal + $tax, 2)
        : round((float) $row['line_total'], 2);
    foreach ([$quantity, $unitPrice, $subtotal, $tax, $total] as $value) {
        if (!is_finite($value) || abs($value) >= 1_000_000_000_000) {
            throw new InvalidArgumentException('Line amount must be a finite value below one trillion.');
        }
    }
    if ((int) round($total * 100) !== (int) round(($subtotal + $tax) * 100)) {
        throw new InvalidArgumentException('Line total must equal subtotal plus tax.');
    }
    return compact('quantity', 'unitPrice', 'subtotal', 'tax', 'total');
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
    array $requiredHeaderFields,
    ?callable $validateLine = null
): array {
    $groups = [];
    $resolved = [];
    $unassignedRows = [];
    foreach ($result['rows'] as $rowNumber => $row) {
        $number = trim((string) ($row[$groupField] ?? ''));
        if ($number === '') {
            $unassignedRows[] = $rowNumber;
            $result['errors'][$rowNumber] ??= ["{$groupField}: required"];
            continue;
        }
        $groups[$number][$rowNumber] = $row;
    }

    if ($unassignedRows) {
        $result['blocking_error'] = "Every line needs a {$documentLabel} number. A line without one cannot be assigned to a document, so fix the file before importing any documents.";
    } elseif (!$groups) {
        $result['blocking_error'] = "No {$documentLabel} lines were found in this file.";
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
            if (trim((string) ($row['entity_code'] ?? '')) === '') {
                $result['rows'][$rowNumber]['entity_code'] = $entity['code'];
            }
            if ($rowNumber !== $firstRowNumber) {
                $code = trim((string) ($row['entity_code'] ?? ''));
                if ($code !== '' && strcasecmp($code, $entity['code']) !== 0) {
                    $result['errors'][$rowNumber][] = "Entity code conflicts with first row of {$documentLabel} #{$number}";
                }
                $currency = trim((string) ($row['currency'] ?? ''));
                if ($currency !== '' && strcasecmp($currency, $entity['base_currency']) !== 0) {
                    $result['errors'][$rowNumber][] = "Currency conflicts with legal entity for {$documentLabel} #{$number}";
                }
            }
            if (isset($result['errors'][$rowNumber])) continue;
            try {
                $amounts = accountingCsvDocumentLineAmounts($row);
                if ($validateLine !== null) $validateLine($row, $amounts);
                if (($row['line_total'] ?? '') === '') {
                    $result['rows'][$rowNumber]['line_total'] = number_format($amounts['total'], 2, '.', '');
                }
            } catch (InvalidArgumentException $error) {
                $result['errors'][$rowNumber][] = $error->getMessage();
            }
        }
    }

    $result['error_count'] = count($result['errors']);
    $result['groups'] = count($groups);
    return ['result' => $result, 'groups' => $groups, 'entities' => $resolved];
}

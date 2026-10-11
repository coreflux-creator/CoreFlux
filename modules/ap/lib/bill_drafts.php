<?php
/** Shared manual bill intake for the ERP and CoreOne. No GL posting occurs here. */
declare(strict_types=1);

require_once __DIR__ . '/ap.php';
require_once __DIR__ . '/../../../core/active_entity.php';
require_once __DIR__ . '/../../people/lib/companies.php';
require_once __DIR__ . '/../../accounting/lib/dimensions.php';

/** Validate amounts before either ERP or CoreOne creates an approvable bill. */
function apValidateManualBillLines(array $lines, float $taxPct): array
{
    foreach ($lines as $index => $line) {
        $number = $index + 1;
        if (!is_array($line)) {
            throw new InvalidArgumentException("Bill line {$number} must be an object");
        }
        if (isset($line['item_type']) && !is_string($line['item_type'])) {
            throw new InvalidArgumentException("Bill line {$number}: item_type must be text");
        }
        if (apNormalizeItemType($line['item_type'] ?? null, 'manual') === 'discount') {
            throw new InvalidArgumentException(
                "Bill line {$number}: discounts are not supported on payable lines; enter the net price instead"
            );
        }
        foreach (['quantity', 'unit_price'] as $field) {
            $value = $line[$field] ?? null;
            if (!apValidPositiveBillDecimal4($value)) {
                throw new InvalidArgumentException(
                    "Bill line {$number}: {$field} must be positive with at most four decimals"
                );
            }
        }
    }
    $computed = apComputeTotals($lines, $taxPct);
    foreach ($computed['lines'] as $index => $line) {
        if (!is_finite((float) $line['total']) || (float) $line['total'] <= 0
            || (float) $line['total'] > 9999999999.99) {
            throw new InvalidArgumentException(
                'Bill line ' . ($index + 1) . ': total must be between 0.01 and 9,999,999,999.99 in the bill currency'
            );
        }
    }
    if (!is_finite((float) $computed['total']) || (float) $computed['total'] > 9999999999.99) {
        throw new InvalidArgumentException('Bill total exceeds the supported amount');
    }
    return $computed;
}

/** A direct bill supplies vendor and legal entity, but no assignment context. */
function apValidateDirectBillExpenseAccounts(int $tenantId, array $lines): void
{
    $account = getDB()->prepare(
        'SELECT id, name FROM accounting_accounts
          WHERE tenant_id = :tenant_id AND code = :code
            AND active = 1 AND is_postable = 1
            AND account_type IN ("expense", "other_expense") LIMIT 1'
    );
    $registry = accountingDimensionRegistry($tenantId);
    $checked = [];
    foreach ($lines as $index => $line) {
        $rawCode = $line['gl_expense_account_code'] ?? null;
        if ($rawCode !== null && !is_scalar($rawCode)) {
            throw new InvalidArgumentException('Bill line ' . ($index + 1) . ': choose a valid expense account');
        }
        $code = trim((string) $rawCode) ?: '6990';
        if (!array_key_exists($code, $checked)) {
            $account->execute(['tenant_id' => $tenantId, 'code' => $code]);
            $found = $account->fetch(PDO::FETCH_ASSOC);
            if (!$found) {
                $checked[$code] = "Expense account {$code} is inactive, unavailable or not postable";
            } else {
                $required = accountingRequiredDimensionKeysForAccountCodes($tenantId, [$code]);
                $missing = array_values(array_diff($required, ['vendor', 'legal_entity']));
                $rules = accountingAccountDimRules($tenantId, (int) $found['id']);
                $blocked = array_keys(array_filter(
                    $rules,
                    static fn(string $requirement, string $key): bool => $requirement === 'blocked'
                        && in_array($key, ['vendor', 'legal_entity'], true),
                    ARRAY_FILTER_USE_BOTH
                ));
                $labels = array_map(
                    static fn(string $key): string => (string) ($registry[$key]['label'] ?? $key),
                    $missing
                );
                $checked[$code] = $labels
                    ? "{$code} {$found['name']} requires " . implode(', ', $labels)
                        . '; choose an account without those dimensions for a direct bill'
                    : ($blocked ? "{$code} {$found['name']} blocks " . implode(', ', $blocked)
                        . '; choose an account that accepts vendor and legal entity coding' : null);
            }
        }
        if ($checked[$code] !== null) {
            throw new InvalidArgumentException('Bill line ' . ($index + 1) . ': ' . $checked[$code]);
        }
    }
}

function apCreateManualBill(int $tenantId, array $body, ?int $actorUserId = null): array
{
    $vendorName = trim((string) ($body['vendor_name'] ?? ''));
    if ($vendorName === '' || strlen($vendorName) > 255) {
        throw new InvalidArgumentException('Enter a vendor name of at most 255 characters');
    }
    if (empty($body['lines']) || !is_array($body['lines']) || !array_is_list($body['lines'])
        || count($body['lines']) > 500) {
        throw new InvalidArgumentException('lines must contain 1 to 500 bill lines');
    }
    foreach ($body['lines'] as $line) {
        if (!is_array($line)) throw new InvalidArgumentException('Each bill line must be an object');
    }
    $vendorType = (string) ($body['vendor_type'] ?? 'other');
    if (!in_array($vendorType, ['1099_individual', 'c2c_corp', 'w9_business', 'utility', 'other'], true)) {
        throw new InvalidArgumentException('Choose a valid vendor type');
    }
    $vendorId = $body['vendor_id'] ?? null;
    if ($vendorId !== null && filter_var($vendorId, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]) === false) {
        throw new InvalidArgumentException('Choose a valid vendor');
    }
    $currency = (string) ($body['currency'] ?? 'USD');
    if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
        throw new InvalidArgumentException('Currency must be a three-letter code');
    }
    $taxPct = $body['tax_rate_pct'] ?? 0;
    if (!is_numeric($taxPct) || !is_finite((float) $taxPct)
        || (float) $taxPct < 0 || (float) $taxPct > 100) {
        throw new InvalidArgumentException('Tax rate must be between 0 and 100');
    }
    $validDate = static function (string $value): bool {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    };
    $billDate = trim((string) ($body['bill_date'] ?? date('Y-m-d')));
    $receivedAt = trim((string) ($body['received_at'] ?? date('Y-m-d')));
    if (!$validDate($billDate) || !$validDate($receivedAt)) {
        throw new InvalidArgumentException('Bill and received dates must use YYYY-MM-DD');
    }
    $poNumber = $body['po_number'] ?? null;
    if ($poNumber !== null && (!is_string($poNumber) || strlen($poNumber) > 80)) {
        throw new InvalidArgumentException('PO number must be at most 80 characters');
    }
    $providedBillNumber = $body['bill_number'] ?? null;
    if ($providedBillNumber !== null && (!is_string($providedBillNumber)
        || strlen(trim($providedBillNumber)) > 80)) {
        throw new InvalidArgumentException('Bill number must be at most 80 characters');
    }

    $pdo = getDB();
    $owns = !$pdo->inTransaction();
    $savepoint = $owns ? null : 'ap_bill_' . bin2hex(random_bytes(4));
    if ($owns) $pdo->beginTransaction();
    else $pdo->exec('SAVEPOINT ' . $savepoint);
    try {
    $vendorLookup = $pdo->prepare($vendorId !== null
        ? 'SELECT vendor_name, company_id, vendor_type, requires_1099 FROM ap_vendors_index
            WHERE tenant_id = :t AND id = :id FOR UPDATE'
        : 'SELECT vendor_name, company_id, vendor_type, requires_1099 FROM ap_vendors_index
            WHERE tenant_id = :t AND vendor_name = :name FOR UPDATE');
    $vendorLookup->execute($vendorId !== null
        ? ['t' => $tenantId, 'id' => (int) $vendorId]
        : ['t' => $tenantId, 'name' => $vendorName]);
    $savedVendor = $vendorLookup->fetch(PDO::FETCH_ASSOC);
    if ($vendorId !== null && !$savedVendor) {
        throw new InvalidArgumentException('Vendor is unavailable in this workspace');
    }
    if ($savedVendor) {
        if ($vendorId !== null && ($vendorName !== $savedVendor['vendor_name']
            || $vendorType !== $savedVendor['vendor_type'])) {
            throw new InvalidArgumentException('Vendor details changed. Select the vendor again');
        }
        $vendorName = (string) $savedVendor['vendor_name'];
        $vendorType = (string) $savedVendor['vendor_type'];
        $vendorRequires1099 = (int) $savedVendor['requires_1099'] === 1;
        $vendorCompanyId = $savedVendor['company_id'] !== null ? (int) $savedVendor['company_id'] : null;
    } else {
        $vendorRequires1099 = $vendorType === '1099_individual';
        $vendorCompanyId = !empty($body['vendor_company_id']) ? (int) $body['vendor_company_id'] : null;
    }
    $taxStmt = $pdo->prepare('SELECT ap_default_terms FROM tenants WHERE id = :id');
    $taxStmt->execute(['id' => $tenantId]);
    $cfg = $taxStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $netDays = preg_match('/^NET(\d+)$/i', (string) ($cfg['ap_default_terms'] ?? 'NET30'), $m)
        ? (int) $m[1] : 30;
    if ($vendorCompanyId !== null) {
        $vendor = $pdo->prepare('SELECT name FROM companies WHERE tenant_id = :t AND id = :id AND deleted_at IS NULL');
        $vendor->execute(['t' => $tenantId, 'id' => $vendorCompanyId]);
        if ($vendor->fetchColumn() === false) throw new InvalidArgumentException('Vendor company is unavailable in this workspace');
    }
    try {
        if ($vendorCompanyId) {
            $terms = $pdo->prepare('SELECT payment_terms_days FROM companies WHERE tenant_id = :t AND id = :id AND payment_terms_days IS NOT NULL LIMIT 1');
            $terms->execute(['t' => $tenantId, 'id' => $vendorCompanyId]);
        } else {
            $terms = $pdo->prepare('SELECT payment_terms_days FROM companies WHERE tenant_id = :t AND name = :n AND payment_terms_days IS NOT NULL LIMIT 1');
            $terms->execute(['t' => $tenantId, 'n' => $vendorName]);
        }
        $perVendor = $terms->fetchColumn();
        if ($perVendor !== false && $perVendor !== null && (int) $perVendor > 0) {
            $netDays = (int) $perVendor;
        }
    } catch (Throwable $_) { /* Older company schemas may not have payment terms. */ }
    $dueDate = trim((string) ($body['due_date'] ?? ''));
    if ($dueDate === '') $dueDate = (new DateTimeImmutable($billDate))->modify("+{$netDays} days")->format('Y-m-d');
    if (!$validDate($dueDate) || $dueDate < $billDate) {
        throw new InvalidArgumentException('Due date must be on or after the bill date (YYYY-MM-DD)');
    }
    $computed = apValidateManualBillLines($body['lines'], (float) $taxPct);
    try {
        $issuingEntity = activeEntityResolveForTenant(
            $tenantId, !empty($body['entity_id']) ? (int) $body['entity_id'] : null
        );
    } catch (Throwable $e) {
        throw new InvalidArgumentException($e->getMessage(), 0, $e);
    }
    if (!$issuingEntity) throw new InvalidArgumentException('An active issuing entity is required');
    if (empty($body['placement_id'])) {
        apValidateDirectBillExpenseAccounts($tenantId, $body['lines']);
    }

        $internalRef = apNextInternalRef($tenantId);
        if (!$vendorCompanyId && in_array($vendorType, ['c2c_corp', 'w9_business', 'utility', 'other'], true)) {
            $vendorCompanyId = companiesUpsertByName($tenantId, $vendorName,
                ['created_by_user_id' => $actorUserId], ['vendor']);
            companiesBumpUsage($vendorCompanyId);
        }
        $billNumber = trim((string) $providedBillNumber) ?: $internalRef;
        $duplicate = $pdo->prepare(
            'SELECT id FROM ap_bills
              WHERE tenant_id = :t AND entity_id = :e AND vendor_name = :vendor
                AND bill_number = :number AND status <> "void" LIMIT 1 FOR UPDATE'
        );
        $duplicate->execute(['t' => $tenantId, 'e' => (int) $issuingEntity['id'],
            'vendor' => $vendorName, 'number' => $billNumber]);
        if ($duplicate->fetchColumn()) {
            throw new DomainException('This vendor bill number already exists for the selected entity');
        }
        $billId = scopedInsert('ap_bills', [
            'tenant_id' => $tenantId,
            'bill_number' => $billNumber,
            'internal_ref' => $internalRef,
            'vendor_name' => $vendorName,
            'vendor_company_id' => $vendorCompanyId,
            'vendor_type' => $vendorType,
            'received_at' => $receivedAt,
            'bill_date' => $billDate,
            'due_date' => $dueDate,
            'currency' => $currency,
            'po_number' => $poNumber,
            'placement_id' => !empty($body['placement_id']) ? (int) $body['placement_id'] : null,
            'entity_id' => (int) $issuingEntity['id'],
            'notes_internal' => $body['notes_internal'] ?? null,
            'subtotal' => $computed['subtotal'],
            'tax_total' => $computed['tax_total'],
            'total' => $computed['total'],
            'amount_due' => $computed['total'],
            'status' => 'pending_approval',
            'source' => 'manual',
            'created_by_user_id' => $actorUserId,
        ]);
        $pdo->prepare(
            'INSERT INTO ap_vendors_index
                (tenant_id, vendor_name, company_id, vendor_type, requires_1099, last_bill_at, placement_id_last)
             VALUES (:tenant_id, :vendor_name, :company_id, :vendor_type, :requires_1099, NOW(), :placement_id)
             ON DUPLICATE KEY UPDATE
                company_id = COALESCE(VALUES(company_id), company_id),
                last_bill_at = NOW(),
                placement_id_last = COALESCE(VALUES(placement_id_last), placement_id_last)'
        )->execute([
            'tenant_id' => $tenantId, 'vendor_name' => $vendorName,
            'company_id' => $vendorCompanyId, 'vendor_type' => $vendorType,
            'requires_1099' => $vendorRequires1099 ? 1 : 0,
            'placement_id' => !empty($body['placement_id']) ? (int) $body['placement_id'] : null,
        ]);
        $lineStmt = $pdo->prepare(
            'INSERT INTO ap_bill_lines
                (bill_id, line_no, source_type, item_type, description, quantity, unit, unit_price,
                 subtotal, tax_rate_pct, tax_amount, total, gl_expense_account_code, is_1099_eligible)
             VALUES
                (:bill_id, :line_no, "manual", :item_type, :description, :quantity, :unit, :unit_price,
                 :subtotal, :tax_rate_pct, :tax_amount, :total, :gl, :is_1099)'
        );
        $lineNo = 1;
        foreach ($computed['lines'] as $line) {
            $lineStmt->execute([
                'bill_id' => $billId, 'line_no' => $lineNo++,
                'item_type' => apNormalizeItemType($line['item_type'] ?? null, 'manual'),
                'description' => $line['description'] ?? '',
                'quantity' => $line['quantity'] ?? 0,
                'unit' => $line['unit'] ?? 'each',
                'unit_price' => $line['unit_price'] ?? 0,
                'subtotal' => $line['subtotal'],
                'tax_rate_pct' => $line['tax_rate_pct'],
                'tax_amount' => $line['tax_amount'],
                'total' => $line['total'],
                'gl' => $line['gl_expense_account_code'] ?? null,
                'is_1099' => $vendorRequires1099
                    && (!array_key_exists('is_1099_eligible', $line) || !empty($line['is_1099_eligible'])) ? 1 : 0,
            ]);
        }
        if ($owns) $pdo->commit();
        else $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        return ['id' => $billId, 'internal_ref' => $internalRef,
            'entity_id' => (int) $issuingEntity['id'], 'total' => $computed['total'],
            'status' => 'pending_approval'];
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
        elseif (!$owns && $pdo->inTransaction()) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        }
        throw $e;
    }
}

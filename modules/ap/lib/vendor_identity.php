<?php
declare(strict_types=1);

/**
 * Update a vendor by stable ID, keeping legacy name-keyed AP records attached.
 * The caller may already own a CSV import transaction; the savepoint keeps a
 * single skipped row from leaving only part of the rename behind.
 *
 * @return array<string, int> Counts of changed dependent records.
 */
function apUpdateVendorIdentity(
    PDO $pdo,
    int $tenantId,
    int $vendorId,
    string $newName,
    array $updates,
    ?int $actorUserId = null
): array {
    $newName = trim($newName);
    if ($tenantId <= 0 || $vendorId <= 0 || $newName === '') {
        throw new RuntimeException('Choose a valid vendor and name.');
    }

    $allowed = [
        'external_id', 'source_system', 'vendor_type', 'vendor_category',
        'payment_method', 'remit_to_email', 'remit_to_phone', 'default_terms',
        'tax_id_last4', 'requires_1099',
    ];
    foreach (array_keys($updates) as $column) {
        if (!in_array($column, $allowed, true)) {
            throw new InvalidArgumentException('Unsupported vendor update field.');
        }
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    } else {
        $pdo->exec('SAVEPOINT ap_vendor_identity');
    }

    try {
        $find = $pdo->prepare(
            'SELECT vendor_name FROM ap_vendors_index WHERE tenant_id = :t AND id = :id FOR UPDATE'
        );
        $find->execute(['t' => $tenantId, 'id' => $vendorId]);
        $oldName = $find->fetchColumn();
        if ($oldName === false) throw new RuntimeException('Vendor ID does not belong to this company.');
        $oldName = (string) $oldName;
        $renamed = $oldName !== $newName;

        $counts = [];
        if ($renamed) {
            $collision = $pdo->prepare(
                'SELECT id FROM ap_vendors_index
                  WHERE tenant_id = :t AND vendor_name = :name AND id <> :id LIMIT 1'
            );
            $collision->execute(['t' => $tenantId, 'name' => $newName, 'id' => $vendorId]);
            if ($collision->fetchColumn() !== false) {
                throw new RuntimeException('Vendor name belongs to a different vendor.');
            }

            $taxRows = $pdo->prepare(
                'SELECT id, vendor_name, form_storage_object_id, submitted_to_irs_at
                   FROM ap_1099_ledger
                  WHERE tenant_id = :t AND vendor_name IN (:old, :new) FOR UPDATE'
            );
            $taxRows->execute(['t' => $tenantId, 'old' => $oldName, 'new' => $newName]);
            foreach ($taxRows as $taxRow) {
                if ($taxRow['vendor_name'] !== $oldName) {
                    throw new RuntimeException('A 1099 record already uses the new vendor name. Resolve it before renaming.');
                }
                if ($taxRow['form_storage_object_id'] !== null || $taxRow['submitted_to_irs_at'] !== null) {
                    throw new RuntimeException('A 1099 form has already been generated or filed for this vendor. Correct it before renaming.');
                }
            }

            foreach (['ap_1099_ledger', 'ap_recurring_bills', 'ap_purchase_orders'] as $table) {
                $conflict = $pdo->prepare(
                    "SELECT id FROM {$table} WHERE tenant_id = :t AND vendor_name = :old
                       AND vendor_id IS NOT NULL AND vendor_id <> :id LIMIT 1"
                );
                $conflict->execute(['t' => $tenantId, 'old' => $oldName, 'id' => $vendorId]);
                if ($conflict->fetchColumn() !== false) {
                    throw new RuntimeException('A related AP record has a conflicting vendor ID. Resolve it before renaming.');
                }
            }
        }

        $assignments = ['vendor_name = :name'];
        $params = ['t' => $tenantId, 'id' => $vendorId, 'name' => $newName];
        foreach ($updates as $column => $value) {
            $assignments[] = "{$column} = :{$column}";
            $params[$column] = $value;
        }
        $pdo->prepare('UPDATE ap_vendors_index SET ' . implode(', ', $assignments)
            . ' WHERE tenant_id = :t AND id = :id')->execute($params);

        if ($renamed) {
            foreach (['ap_bills', 'ap_payments'] as $table) {
                $update = $pdo->prepare(
                    "UPDATE {$table} SET vendor_name = :name
                      WHERE tenant_id = :t AND vendor_name = :old"
                );
                $update->execute(['name' => $newName, 't' => $tenantId, 'old' => $oldName]);
                $counts[$table] = $update->rowCount();
            }
            foreach (['ap_1099_ledger', 'ap_recurring_bills', 'ap_purchase_orders'] as $table) {
                $update = $pdo->prepare(
                    "UPDATE {$table} SET vendor_name = :name, vendor_id = :new_id
                      WHERE tenant_id = :t AND vendor_name = :old
                        AND (vendor_id IS NULL OR vendor_id = :matched_id)"
                );
                $update->execute([
                    'name' => $newName, 'new_id' => $vendorId,
                    't' => $tenantId, 'old' => $oldName, 'matched_id' => $vendorId,
                ]);
                $counts[$table] = $update->rowCount();
            }

            $audit = $pdo->prepare(
                'INSERT INTO audit_log
                    (tenant_id, actor_user_id, event, target_id, meta_json, ip_address, created_at)
                 VALUES (:t, :actor, :event, :id, :meta, :ip, NOW())'
            );
            $audit->execute([
                't' => $tenantId,
                'actor' => $actorUserId,
                'event' => 'ap.vendor.renamed',
                'id' => $vendorId,
                'meta' => json_encode([
                    'old_name' => $oldName,
                    'new_name' => $newName,
                    'affected_records' => $counts,
                ], JSON_THROW_ON_ERROR),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        }

        if ($ownsTransaction) $pdo->commit();
        else $pdo->exec('RELEASE SAVEPOINT ap_vendor_identity');
        return $counts;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            if ($ownsTransaction) $pdo->rollBack();
            else {
                $pdo->exec('ROLLBACK TO SAVEPOINT ap_vendor_identity');
                $pdo->exec('RELEASE SAVEPOINT ap_vendor_identity');
            }
        }
        throw $e;
    }
}

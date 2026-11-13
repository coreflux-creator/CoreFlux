<?php
/** Vendor and Companies directories may be shared independently. */
declare(strict_types=1);
require_once __DIR__ . '/../../people/lib/companies.php';

class ApVendorCompanyConflict extends RuntimeException {}

function apVendorCompanyIdInput(mixed $value): ?int
{
    if ($value === null || $value === '') return null;
    if ((!is_int($value) && !is_string($value))
        || !preg_match('/^[1-9][0-9]*$/D', (string) $value)
        || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
        throw new InvalidArgumentException('Choose a valid company from this workspace.');
    }
    return (int) $value;
}

/** Resolve links under the same transaction as the vendor write and its required audit. */
function apVendorResolveCompany(PDO $pdo, int $workspaceId, ?array $existing, string $name,
    string $vendorType, mixed $input, ?int $actorId): ?int
{
    if (!$pdo->inTransaction()) throw new LogicException('Vendor company resolution requires a transaction.');
    $owner = companiesDirectoryTenantId($workspaceId);
    $selected = apVendorCompanyIdInput($input);
    $find = $pdo->prepare('SELECT id FROM companies WHERE tenant_id=:t AND id=:id AND deleted_at IS NULL FOR UPDATE');
    if ($selected !== null) {
        $find->execute(['t' => $owner, 'id' => $selected]);
        if (!$find->fetchColumn()) throw new InvalidArgumentException('Choose a company from this workspace\'s Companies directory.');
    }
    $previous = !empty($existing['company_id']) ? (int) $existing['company_id'] : null;
    if ($previous !== null) {
        $find->execute(['t' => $owner, 'id' => $previous]);
        if (!$find->fetchColumn()) throw new ApVendorCompanyConflict('This vendor\'s existing company link needs review in its owning directory.');
        if ($selected !== null && $selected !== $previous) {
            throw new ApVendorCompanyConflict('This vendor already links to another company. Review the existing vendor before changing its identity.');
        }
        return $previous;
    }
    if ($selected === null && in_array($vendorType, ['c2c_corp','w9_business','utility','other'], true)) {
        $selected = companiesUpsertByName($owner, $name, ['created_by_user_id' => $actorId], ['vendor']);
    }
    if ($selected !== null) companiesBumpUsage($selected);
    return $selected;
}

/** Hide inaccessible/retired linked identities without rewriting the stored association. */
function apVendorDirectoryRows(PDO $pdo, int $vendorOwner, int $companyOwner, array $rows): array
{
    foreach ($rows as &$row) {
        $linked = !empty($row['company_id']);
        $available = $linked && !empty($row['directory_company_id']);
        $row['company_link_status'] = $available ? 'available' : ($linked ? 'needs_review' : 'unlinked');
        if (!$available) {
            $row['company_id'] = null;
            $row['company_name'] = null;
            $row['company_legal_name'] = null;
        }
        unset($row['directory_company_id']);
    }
    unset($row);
    $rows = coreRecordNumbersAttach($pdo, $vendorOwner, 'vendor', $rows);
    return coreRecordNumbersAttachRelated($pdo, $companyOwner, 'company', $rows, 'company_id');
}

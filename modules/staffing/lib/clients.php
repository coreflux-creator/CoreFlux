<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../core/tenant_scope.php';
require_once __DIR__ . '/../../people/lib/companies.php';

/**
 * Ensure the staffing client consumer row exists for a canonical company.
 *
 * People/Companies owns organization identity. Staffing consumes that graph
 * through staffing_clients so staffing-specific fields and reports can remain
 * module-owned without creating a second client universe.
 *
 * @return array{client_id:int, company_id:int|null, name:string}
 */
function staffingClientEnsureForCompany(int $tenantId, ?int $companyId, string $name, array $extra = []): array
{
    $name = trim($name);
    if ($name === '') {
        throw new \InvalidArgumentException('client name required');
    }

    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('No database connection');

    if ($companyId && !empty($extra['sync_company_patch'])) {
        staffingClientApplyCompanyPatch($tenantId, (int) $companyId, array_merge($extra, ['name' => $name]));
    }

    $company = null;
    if ($companyId && $companyId > 0) {
        $stmt = $pdo->prepare('SELECT id, name, legal_name, industry, primary_contact_name, primary_contact_email, primary_contact_phone, city, state, country
                                 FROM companies
                                WHERE tenant_id = :t AND id = :id AND deleted_at IS NULL
                                LIMIT 1');
        $stmt->execute(['t' => $tenantId, 'id' => $companyId]);
        $company = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    if (!$company) {
        $companyId = companiesUpsertByName($tenantId, $name, [
            'created_by_user_id' => $extra['created_by_user_id'] ?? null,
        ], ['client']);
        $company = companiesGet((int) $companyId);
    } else {
        companiesAddRole((int) $company['id'], 'client');
    }

    $companyId = $company ? (int) $company['id'] : null;
    $name = trim((string) ($company['name'] ?? $name));

    $existing = null;
    if ($companyId) {
        $stmt = $pdo->prepare('SELECT * FROM staffing_clients WHERE tenant_id = :t AND company_id = :cid LIMIT 1');
        $stmt->execute(['t' => $tenantId, 'cid' => $companyId]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
    if (!$existing) {
        $stmt = $pdo->prepare('SELECT * FROM staffing_clients WHERE tenant_id = :t AND name = :n LIMIT 1');
        $stmt->execute(['t' => $tenantId, 'n' => $name]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    $payload = [
        'company_id'             => $companyId,
        'name'                   => $name,
        'legal_name'             => $company['legal_name'] ?? ($extra['legal_name'] ?? null),
        'industry'               => $company['industry'] ?? ($extra['industry'] ?? null),
        'primary_contact_name'   => $company['primary_contact_name'] ?? ($extra['primary_contact_name'] ?? null),
        'primary_contact_email'  => $company['primary_contact_email'] ?? ($extra['primary_contact_email'] ?? null),
        'primary_contact_phone'  => $company['primary_contact_phone'] ?? ($extra['primary_contact_phone'] ?? null),
        'billing_city'           => $company['city'] ?? ($extra['billing_city'] ?? null),
        'billing_state'          => $company['state'] ?? ($extra['billing_state'] ?? null),
        'billing_country'        => $company['country'] ?? ($extra['billing_country'] ?? 'US'),
        'payment_terms_days'     => isset($extra['payment_terms_days']) ? (int) $extra['payment_terms_days'] : 30,
        'status'                 => $extra['status'] ?? 'active',
    ];

    if ($existing) {
        $patch = [];
        foreach ($payload as $key => $value) {
            if ($value === null || $value === '') continue;
            if (!array_key_exists($key, $existing) || (string) ($existing[$key] ?? '') !== (string) $value) {
                $patch[$key] = $value;
            }
        }
        if ($patch) {
            $sets = [];
            $params = ['tenant_id' => $tenantId, 'id' => (int) $existing['id']];
            foreach ($patch as $key => $value) {
                $sets[] = "`{$key}` = :{$key}";
                $params[$key] = $value;
            }
            $params['updated_at'] = date('Y-m-d H:i:s');
            $sets[] = 'updated_at = :updated_at';
            $pdo->prepare(
                'UPDATE staffing_clients SET ' . implode(', ', $sets) . ' WHERE tenant_id = :tenant_id AND id = :id'
            )->execute($params);
        }
        return ['client_id' => (int) $existing['id'], 'company_id' => $companyId, 'name' => $name];
    }

    $payload = array_filter($payload, static fn($v) => $v !== null);
    $payload['tenant_id'] = $tenantId;
    $payload['created_at'] = date('Y-m-d H:i:s');
    $cols = array_keys($payload);
    $placeholders = array_map(static fn($c) => ":{$c}", $cols);
    $pdo->prepare(
        'INSERT INTO staffing_clients (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', $placeholders) . ')'
    )->execute($payload);
    $clientId = (int) $pdo->lastInsertId();
    return ['client_id' => $clientId, 'company_id' => $companyId, 'name' => $name];
}

function staffingClientApplyCompanyPatch(int $tenantId, int $companyId, array $patch): void
{
    if ($companyId <= 0) return;
    $map = [
        'name' => 'name',
        'legal_name' => 'legal_name',
        'industry' => 'industry',
        'primary_contact_name' => 'primary_contact_name',
        'primary_contact_email' => 'primary_contact_email',
        'primary_contact_phone' => 'primary_contact_phone',
        'billing_city' => 'city',
        'billing_state' => 'state',
        'billing_country' => 'country',
    ];
    $sets = [];
    $params = ['tenant_id' => $tenantId, 'id' => $companyId];
    foreach ($map as $source => $column) {
        if (!array_key_exists($source, $patch)) continue;
        $value = $patch[$source];
        if ($value === null || $value === '') continue;
        $sets[] = "`{$column}` = :{$column}";
        $params[$column] = $value;
    }
    if (!$sets) return;
    getDB()->prepare('UPDATE companies SET ' . implode(', ', $sets) . ' WHERE tenant_id = :tenant_id AND id = :id')
        ->execute($params);
}

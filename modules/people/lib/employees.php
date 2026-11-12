<?php
/**
 * People Module — Cross-Module Employee Library
 *
 * This is the STABLE INTERFACE other CoreFlux modules (e.g. Payroll) use to
 * read employee data. Keep the signatures stable; internal SQL can evolve.
 *
 * Rules for consumers:
 *   - Never SELECT from people_* tables directly from another module.
 *   - Always go through these helpers (tenant scoping is already enforced).
 *   - Never mutate people_* data from another module (People owns writes).
 */

require_once __DIR__ . '/../../../core/tenant_scope.php';

/**
 * Light directory listing. Returns array of rows with basic identity +
 * employment fields. Never includes PII (no SSN, no bank).
 */
function peopleListActiveEmployees(?string $search = null, ?string $department = null): array {
    // Directory reads never create or infer employment identities.

    $where = ['tenant_id = :tenant_id', "status = 'active'"];
    $params = [];
    if ($search) {
        // Distinct placeholders required by PDO_MYSQL native prepares.
        $where[]      = '(legal_last_name LIKE :q OR legal_first_name LIKE :q2 OR preferred_name LIKE :q3 OR work_email LIKE :q4 OR employee_number = :eq)';
        $params['q']  = '%' . $search . '%';
        $params['q2'] = $params['q'];
        $params['q3'] = $params['q'];
        $params['q4'] = $params['q'];
        $params['eq'] = $search;
    }
    if ($department) {
        $where[] = 'department = :dept';
        $params['dept'] = $department;
    }
    $sql = 'SELECT id, employee_number, legal_first_name, preferred_name, legal_last_name,
                   status, employment_type, flsa_class, job_title, department, location,
                   manager_id, work_email, hire_date
            FROM people_employees
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY legal_last_name, legal_first_name';
    return scopedQuery($sql, $params);
}

/**
 * Full employee record (no PII decryption — returns last4 + cipher blobs).
 * Callers that actually need SSN plaintext must go through peopleRevealSSN()
 * (permissioned + audited).
 */
function peopleGetEmployee(int $employeeId): ?array {
    return scopedFind(
        'SELECT * FROM people_employees WHERE tenant_id = :tenant_id AND id = :id',
        ['id' => $employeeId]
    );
}

/**
 * Return the currently-active compensation row for an employee (or null).
 */
function peopleActiveCompensation(int $employeeId, ?string $asOfDate = null): ?array {
    $asOfDate ??= date('Y-m-d');
    return scopedFind(
        'SELECT * FROM people_compensation
         WHERE tenant_id = :tenant_id AND employee_id = :emp
           AND effective_from <= :as_of_start
           AND (effective_to IS NULL OR effective_to > :as_of_end)
         ORDER BY effective_from DESC
         LIMIT 1',
        ['emp' => $employeeId, 'as_of_start' => $asOfDate, 'as_of_end' => $asOfDate]
    );
}

/**
 * Return the active federal W-4 row for an employee.
 */
function peopleActiveFederalTax(int $employeeId, ?string $asOfDate = null): ?array {
    $asOfDate ??= date('Y-m-d');
    return scopedFind(
        'SELECT * FROM people_tax_federal
         WHERE tenant_id = :tenant_id AND employee_id = :emp
           AND effective_date <= :as_of
         ORDER BY effective_date DESC
         LIMIT 1',
        ['emp' => $employeeId, 'as_of' => $asOfDate]
    );
}

/**
 * Return all active state tax rows (an employee can have multi-state withholding).
 */
function peopleActiveStateTaxes(int $employeeId): array {
    // Distinct placeholders required by PDO_MYSQL native prepares.
    // The subquery + outer SELECT need their own copies of tenant/emp.
    return scopedQuery(
        'SELECT t1.* FROM people_tax_state t1
         JOIN (
             SELECT state_code, MAX(effective_date) AS max_eff
             FROM people_tax_state
             WHERE tenant_id = :tenant_id AND employee_id = :emp
             GROUP BY state_code
         ) t2 ON t2.state_code = t1.state_code AND t2.max_eff = t1.effective_date
         WHERE t1.tenant_id = :tenant_id2 AND t1.employee_id = :emp2',
        ['emp' => $employeeId, 'emp2' => $employeeId, 'tenant_id2' => effectiveTenantIdForRequest()]
    );
}

/**
 * Return active bank accounts ordered by priority. Ciphertext columns are
 * included; callers that need plaintext must use the permissioned reveal flow.
 */
function peopleActiveBankAccounts(int $employeeId): array {
    return scopedQuery(
        'SELECT * FROM people_bank_accounts
         WHERE tenant_id = :tenant_id AND employee_id = :emp AND status = "active"
         ORDER BY priority ASC',
        ['emp' => $employeeId]
    );
}

/**
 * Deterministic gap analysis — does this employee have everything Payroll needs?
 * Returns an array of missing-field codes. Empty array means ready.
 */
function peoplePayrollReadiness(int $employeeId): array {
    $emp = peopleGetEmployee($employeeId);
    if (!$emp) return ['employee_not_found'];

    $gaps = [];
    if (!$emp['hire_date'])                             $gaps[] = 'hire_date';
    if (!$emp['ssn_cipher'])                            $gaps[] = 'ssn';
    if (!peopleActiveCompensation($employeeId))         $gaps[] = 'compensation';
    if (!peopleActiveFederalTax($employeeId))           $gaps[] = 'tax_federal';
    if (!peopleActiveBankAccounts($employeeId))         $gaps[] = 'bank_account';

    // I-9 verified?
    $i9 = scopedFind(
        'SELECT status FROM people_i9 WHERE tenant_id = :tenant_id AND employee_id = :emp',
        ['emp' => $employeeId]
    );
    if (!$i9 || $i9['status'] !== 'verified')           $gaps[] = 'i9_verified';

    return $gaps;
}


/** Legacy callers now receive an explicit review inventory; reads never adopt employees. */
function peopleEnsureEmployeesFromW2(): array {
    require_once __DIR__.'/employment_links.php';
    $tenant=(int)currentTenantId();$pdo=getDB();
    if(!$tenant||!$pdo)return ['scanned'=>0,'materialised'=>0,'review_required'=>[],'errors'=>[]];
    $owner=peopleEmploymentOwner($tenant);
    if(!peopleEmploymentSchema($pdo))return ['scanned'=>0,'materialised'=>0,'review_required'=>[],'errors'=>['Employment linking is not installed.']];
    $s=$pdo->prepare("SELECT p.id FROM people p LEFT JOIN people_employment_links l ON l.tenant_id=p.tenant_id AND l.person_id=p.id
        WHERE p.tenant_id=? AND LOWER(p.classification)='w2' AND p.status='active' AND p.deleted_at IS NULL AND l.id IS NULL ORDER BY p.id LIMIT 100");
    $s->execute([$owner]);$ids=array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));
    return ['scanned'=>count($ids),'materialised'=>0,'review_required'=>$ids,'errors'=>[]];
}

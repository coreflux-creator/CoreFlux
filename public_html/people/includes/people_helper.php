
<?php

// Get list of employees the user can view based on tenant access
function getAccessibleEmployees(PDO $pdo, int $userId, int $tenantId): array {
    $stmt = $pdo->prepare("
        SELECT t.id
        FROM user_tenants ut
        JOIN tenants t ON t.id = ut.tenant_id
        WHERE ut.user_id = ?
    ");
    $stmt->execute([$userId]);
    $tenantIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');

    if (empty($tenantIds)) return [];

    $placeholders = str_repeat('?,', count($tenantIds) - 1) . '?';
    $query = "
        SELECT u.id AS user_id, u.name, u.email, u.role, u.created_at AS start_date, u.is_active, t.name AS tenant_name
        FROM users u
        JOIN user_tenants ut ON ut.user_id = u.id
        JOIN tenants t ON t.id = ut.tenant_id
        WHERE ut.tenant_id IN ($placeholders)
        ORDER BY t.name, u.name
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute($tenantIds);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch one employee with profile, timesheet, and approver info
function getEmployeeProfile(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.email, u.role, u.is_active, u.created_at AS start_date, t.name AS tenant_name
        FROM users u
        JOIN user_tenants ut ON ut.user_id = u.id
        JOIN tenants t ON t.id = ut.tenant_id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$employee) return null;

    $stmt = $pdo->prepare("
        SELECT a.name, a.email
        FROM approver_assignments aa
        JOIN users a ON a.id = aa.approver_id
        WHERE aa.employee_id = ?
    ");
    $stmt->execute([$userId]);
    $employee['approvers'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT week_start, week_end, hours_worked, status
        FROM timesheets
        WHERE employee_id = ?
        ORDER BY week_start DESC
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $employee['timesheets'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $employee;
}

// Save changes to an employee profile
function updateEmployee(PDO $pdo, int $id, string $name, string $email, string $role, int $isActive): void {
    $stmt = $pdo->prepare("
        UPDATE users
        SET name = ?, email = ?, role = ?, is_active = ?
        WHERE id = ?
    ");
    $stmt->execute([$name, $email, $role, $isActive, $id]);
}

// Create a new employee and link to tenant
function createNewEmployee(PDO $pdo, string $name, string $email, string $role, int $isActive, int $tenantId): void {
    $defaultPassword = password_hash("changeme123", PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("
        INSERT INTO users (name, email, password, role, is_active)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$name, $email, $defaultPassword, $role, $isActive]);
    $userId = $pdo->lastInsertId();

    $stmt = $pdo->prepare("INSERT INTO user_tenants (user_id, tenant_id, role) VALUES (?, ?, ?)");
    $stmt->execute([$userId, $tenantId, $role]);
}

// Get list of available approvers in current tenant
function getAllApprovers(PDO $pdo, int $tenantId): array {
    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.email
        FROM users u
        JOIN user_tenants ut ON ut.user_id = u.id
        WHERE ut.tenant_id = ? AND u.role = 'approver'
    ");
    $stmt->execute([$tenantId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get approver IDs currently linked to employee
function getApproverIdsForEmployee(PDO $pdo, int $employeeId): array {
    $stmt = $pdo->prepare("SELECT approver_id FROM approver_assignments WHERE employee_id = ?");
    $stmt->execute([$employeeId]);
    return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'approver_id');
}

// Save selected approvers for employee
function assignApproversToEmployee(PDO $pdo, int $employeeId, array $approverIds): void {
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM approver_assignments WHERE employee_id = ?")->execute([$employeeId]);

    $stmt = $pdo->prepare("INSERT INTO approver_assignments (employee_id, approver_id) VALUES (?, ?)");
    foreach ($approverIds as $id) {
        $stmt->execute([$employeeId, $id]);
    }
    $pdo->commit();
}

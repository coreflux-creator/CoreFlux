<?php
/**
 * People Module - Employee Directory
 */

// Demo data
$employees = [
    ['id' => 1, 'name' => 'John Smith', 'email' => 'john.smith@acme.com', 'department' => 'Engineering', 'title' => 'Senior Developer', 'phone' => '(555) 123-4567'],
    ['id' => 2, 'name' => 'Sarah Johnson', 'email' => 'sarah.j@acme.com', 'department' => 'Engineering', 'title' => 'Product Manager', 'phone' => '(555) 234-5678'],
    ['id' => 3, 'name' => 'Mike Davis', 'email' => 'mike.d@acme.com', 'department' => 'Sales', 'title' => 'Account Executive', 'phone' => '(555) 345-6789'],
    ['id' => 4, 'name' => 'Emily Chen', 'email' => 'emily.c@acme.com', 'department' => 'Finance', 'title' => 'Financial Analyst', 'phone' => '(555) 456-7890'],
    ['id' => 5, 'name' => 'Alex Wilson', 'email' => 'alex.w@acme.com', 'department' => 'HR', 'title' => 'HR Coordinator', 'phone' => '(555) 567-8901'],
    ['id' => 6, 'name' => 'Lisa Brown', 'email' => 'lisa.b@acme.com', 'department' => 'Marketing', 'title' => 'Marketing Manager', 'phone' => '(555) 678-9012'],
];

$departments = ['All Departments', 'Engineering', 'Sales', 'Finance', 'HR', 'Marketing'];
$isAdmin = in_array($user['role'] ?? '', ['admin', 'tenant_admin']);
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: flex-start;">
    <div>
        <h1 class="page-title">Employee Directory</h1>
        <p class="page-subtitle"><?= count($employees) ?> employees</p>
    </div>
    <?php if ($isAdmin): ?>
    <div>
        <button class="btn btn-secondary" style="margin-right: 8px;">Import</button>
        <button class="btn btn-primary">+ Add Employee</button>
    </div>
    <?php endif; ?>
</div>

<!-- Search & Filters -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-body" style="display: flex; gap: 16px; align-items: center;">
        <div class="form-group" style="margin-bottom: 0; flex: 1;">
            <input type="text" class="form-input" id="search-input" placeholder="Search by name, email, or title...">
        </div>
        <div class="form-group" style="margin-bottom: 0;">
            <select class="form-select" id="dept-filter">
                <?php foreach ($departments as $dept): ?>
                <option value="<?= $dept === 'All Departments' ? '' : $dept ?>"><?= $dept ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</div>

<!-- Employee Grid -->
<div class="action-cards" id="employee-grid">
    <?php foreach ($employees as $emp): ?>
    <div class="card employee-card" data-name="<?= strtolower($emp['name']) ?>" data-dept="<?= $emp['department'] ?>">
        <div class="card-body">
            <div style="display: flex; align-items: center; gap: 16px; margin-bottom: 16px;">
                <div style="width: 48px; height: 48px; border-radius: 50%; background: var(--color-primary); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 18px;">
                    <?= strtoupper(substr($emp['name'], 0, 1)) ?>
                </div>
                <div>
                    <h3 style="font-size: 16px; font-weight: 600; margin: 0;"><?= htmlspecialchars($emp['name']) ?></h3>
                    <p style="font-size: 13px; color: var(--color-text-secondary); margin: 2px 0 0 0;"><?= htmlspecialchars($emp['title']) ?></p>
                </div>
            </div>
            <div style="font-size: 13px; color: var(--color-text-secondary);">
                <div style="margin-bottom: 6px;">
                    <strong>Department:</strong> <?= htmlspecialchars($emp['department']) ?>
                </div>
                <div style="margin-bottom: 6px;">
                    <strong>Email:</strong> <a href="mailto:<?= htmlspecialchars($emp['email']) ?>" style="color: var(--color-accent);"><?= htmlspecialchars($emp['email']) ?></a>
                </div>
                <div>
                    <strong>Phone:</strong> <?= htmlspecialchars($emp['phone']) ?>
                </div>
            </div>
            <?php if ($isAdmin): ?>
            <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--color-border-light);">
                <a href="?page=view_employee&id=<?= $emp['id'] ?>" class="btn btn-secondary" style="font-size: 12px; padding: 4px 12px;">View Profile</a>
                <a href="?page=edit_employee&id=<?= $emp['id'] ?>" class="btn btn-secondary" style="font-size: 12px; padding: 4px 12px;">Edit</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('search-input');
    const deptFilter = document.getElementById('dept-filter');
    const cards = document.querySelectorAll('.employee-card');
    
    function filterCards() {
        const search = searchInput.value.toLowerCase();
        const dept = deptFilter.value;
        
        cards.forEach(card => {
            const name = card.dataset.name;
            const cardDept = card.dataset.dept;
            const text = card.textContent.toLowerCase();
            
            const matchesSearch = text.includes(search);
            const matchesDept = !dept || cardDept === dept;
            
            card.style.display = (matchesSearch && matchesDept) ? 'block' : 'none';
        });
    }
    
    searchInput.addEventListener('input', filterCards);
    deptFilter.addEventListener('change', filterCards);
});
</script>

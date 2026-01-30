<?php
/**
 * People Module - Reports
 */
?>

<div class="page-header">
    <h1 class="page-title">People Reports</h1>
    <p class="page-subtitle">Generate and export workforce analytics</p>
</div>

<!-- Report Categories -->
<div class="grid grid-cols-2" style="margin-bottom: 32px;">
    
    <!-- Time & Attendance -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Time & Attendance</h3>
        </div>
        <div class="card-body">
            <div style="display: flex; flex-direction: column; gap: 12px;">
                <a href="#" class="report-link">
                    <strong>Timesheet Summary</strong>
                    <span>Hours by employee and project</span>
                </a>
                <a href="#" class="report-link">
                    <strong>Approval Status</strong>
                    <span>Pending, approved, rejected timesheets</span>
                </a>
                <a href="#" class="report-link">
                    <strong>Utilization Report</strong>
                    <span>Billable vs non-billable hours</span>
                </a>
            </div>
        </div>
    </div>
    
    <!-- Workforce -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Workforce</h3>
        </div>
        <div class="card-body">
            <div style="display: flex; flex-direction: column; gap: 12px;">
                <a href="#" class="report-link">
                    <strong>Headcount Report</strong>
                    <span>Employees by department and location</span>
                </a>
                <a href="#" class="report-link">
                    <strong>Directory Export</strong>
                    <span>Full employee list with contact info</span>
                </a>
                <a href="#" class="report-link">
                    <strong>Org Chart</strong>
                    <span>Organizational structure view</span>
                </a>
            </div>
        </div>
    </div>
    
</div>

<!-- Quick Report Builder -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Custom Report Builder</h3>
    </div>
    <div class="card-body">
        <form id="report-form">
            <div class="grid grid-cols-3" style="gap: 16px; margin-bottom: 24px;">
                <div class="form-group">
                    <label class="form-label">Report Type</label>
                    <select class="form-select">
                        <option value="">Select type...</option>
                        <option value="timesheet">Timesheet Data</option>
                        <option value="employee">Employee Data</option>
                        <option value="approvals">Approval History</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Date Range</label>
                    <select class="form-select">
                        <option value="week">This Week</option>
                        <option value="month">This Month</option>
                        <option value="quarter">This Quarter</option>
                        <option value="year">This Year</option>
                        <option value="custom">Custom Range</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Department</label>
                    <select class="form-select">
                        <option value="">All Departments</option>
                        <option value="engineering">Engineering</option>
                        <option value="sales">Sales</option>
                        <option value="finance">Finance</option>
                        <option value="hr">HR</option>
                        <option value="marketing">Marketing</option>
                    </select>
                </div>
            </div>
            <div style="display: flex; gap: 12px;">
                <button type="button" class="btn btn-primary">Generate Report</button>
                <button type="button" class="btn btn-secondary">Export CSV</button>
                <button type="button" class="btn btn-secondary">Export PDF</button>
            </div>
        </form>
    </div>
</div>

<style>
.report-link {
    display: flex;
    flex-direction: column;
    padding: 12px;
    border-radius: 6px;
    transition: background 0.15s ease;
}
.report-link:hover {
    background: var(--color-bg);
}
.report-link strong {
    font-size: 14px;
    color: var(--color-text);
    margin-bottom: 2px;
}
.report-link span {
    font-size: 12px;
    color: var(--color-text-secondary);
}
</style>

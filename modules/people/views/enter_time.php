<?php
/**
 * People Module - Enter Time
 */
$today = date('Y-m-d');
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd = date('Y-m-d', strtotime('sunday this week'));
?>

<div class="page-header">
    <h1 class="page-title">Enter Time</h1>
    <p class="page-subtitle">Submit your time entries for the week of <?= date('M j', strtotime($weekStart)) ?> - <?= date('M j, Y', strtotime($weekEnd)) ?></p>
</div>

<div class="card">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <h3 class="card-title">Time Entry</h3>
        <div>
            <button class="btn btn-secondary" style="margin-right: 8px;">Previous Week</button>
            <button class="btn btn-secondary">Next Week</button>
        </div>
    </div>
    <div class="card-body">
        <form id="time-entry-form">
            <table>
                <thead>
                    <tr>
                        <th style="width: 200px;">Project / Task</th>
                        <th>Mon</th>
                        <th>Tue</th>
                        <th>Wed</th>
                        <th>Thu</th>
                        <th>Fri</th>
                        <th>Sat</th>
                        <th>Sun</th>
                        <th style="width: 80px;">Total</th>
                        <th style="width: 50px;"></th>
                    </tr>
                </thead>
                <tbody id="time-rows">
                    <tr class="time-row">
                        <td>
                            <select class="form-select" style="width: 100%;">
                                <option value="">Select project...</option>
                                <option value="proj-1">Client Project A</option>
                                <option value="proj-2">Internal Development</option>
                                <option value="proj-3">Administrative</option>
                                <option value="proj-4">Training</option>
                            </select>
                        </td>
                        <td><input type="number" class="form-input time-input" min="0" max="24" step="0.5" placeholder="0"></td>
                        <td><input type="number" class="form-input time-input" min="0" max="24" step="0.5" placeholder="0"></td>
                        <td><input type="number" class="form-input time-input" min="0" max="24" step="0.5" placeholder="0"></td>
                        <td><input type="number" class="form-input time-input" min="0" max="24" step="0.5" placeholder="0"></td>
                        <td><input type="number" class="form-input time-input" min="0" max="24" step="0.5" placeholder="0"></td>
                        <td><input type="number" class="form-input time-input" min="0" max="24" step="0.5" placeholder="0"></td>
                        <td><input type="number" class="form-input time-input" min="0" max="24" step="0.5" placeholder="0"></td>
                        <td class="row-total" style="font-weight: 600; text-align: center;">0</td>
                        <td><button type="button" class="btn btn-secondary remove-row" style="padding: 4px 8px;">×</button></td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td>
                            <button type="button" id="add-row" class="btn btn-secondary">+ Add Row</button>
                        </td>
                        <td id="day-total-0" style="font-weight: 600; text-align: center;">0</td>
                        <td id="day-total-1" style="font-weight: 600; text-align: center;">0</td>
                        <td id="day-total-2" style="font-weight: 600; text-align: center;">0</td>
                        <td id="day-total-3" style="font-weight: 600; text-align: center;">0</td>
                        <td id="day-total-4" style="font-weight: 600; text-align: center;">0</td>
                        <td id="day-total-5" style="font-weight: 600; text-align: center;">0</td>
                        <td id="day-total-6" style="font-weight: 600; text-align: center;">0</td>
                        <td id="grand-total" style="font-weight: 600; text-align: center; color: var(--color-primary);">0</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
            
            <div style="margin-top: 24px; display: flex; justify-content: flex-end; gap: 12px;">
                <button type="button" class="btn btn-secondary">Save Draft</button>
                <button type="submit" class="btn btn-primary">Submit for Approval</button>
            </div>
        </form>
    </div>
</div>

<style>
.time-input {
    width: 60px;
    text-align: center;
    padding: 8px 4px;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const tbody = document.getElementById('time-rows');
    const addRowBtn = document.getElementById('add-row');
    
    // Add row
    addRowBtn.addEventListener('click', () => {
        const row = tbody.querySelector('.time-row').cloneNode(true);
        row.querySelectorAll('input').forEach(input => input.value = '');
        row.querySelectorAll('select').forEach(select => select.selectedIndex = 0);
        row.querySelector('.row-total').textContent = '0';
        tbody.appendChild(row);
        calculateTotals();
    });
    
    // Remove row
    tbody.addEventListener('click', (e) => {
        if (e.target.classList.contains('remove-row')) {
            if (tbody.querySelectorAll('.time-row').length > 1) {
                e.target.closest('.time-row').remove();
                calculateTotals();
            }
        }
    });
    
    // Calculate totals on input
    tbody.addEventListener('input', calculateTotals);
    
    function calculateTotals() {
        const rows = tbody.querySelectorAll('.time-row');
        const dayTotals = [0, 0, 0, 0, 0, 0, 0];
        let grandTotal = 0;
        
        rows.forEach(row => {
            const inputs = row.querySelectorAll('.time-input');
            let rowTotal = 0;
            
            inputs.forEach((input, i) => {
                const val = parseFloat(input.value) || 0;
                rowTotal += val;
                dayTotals[i] += val;
            });
            
            row.querySelector('.row-total').textContent = rowTotal;
            grandTotal += rowTotal;
        });
        
        dayTotals.forEach((total, i) => {
            document.getElementById('day-total-' + i).textContent = total;
        });
        
        document.getElementById('grand-total').textContent = grandTotal;
    }
    
    // Form submit
    document.getElementById('time-entry-form').addEventListener('submit', (e) => {
        e.preventDefault();
        alert('Timesheet submitted for approval!');
    });
});
</script>

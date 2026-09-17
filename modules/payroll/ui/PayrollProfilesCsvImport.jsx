import React from 'react';
import CsvImportPage from '../../../dashboard/src/components/CsvImportPage';

export default function PayrollProfilesCsvImport() {
  return (
    <CsvImportPage
      endpoint="/modules/payroll/api/profiles_csv_import.php"
      entityLabel="Payroll employee profiles"
      backTo="../profiles"
      backLabel="Employee setup"
      testidPrefix="payroll-profiles-csv-import"
      presetEntity="payroll_profiles"
      defaultUpdateExisting
      updateExistingLabel="Update employees who already have payroll profiles"
      description="Export employee setup, fill schedules, cycles, state, payment method, hours, and deductions in a spreadsheet, then validate every row before applying changes."
      previewColumns={[
        { key: 'employee_number', label: 'Employee' },
        { key: 'work_email', label: 'Work email' },
        { key: 'cycle_name', label: 'Pay cycle' },
        { key: 'work_state', label: 'State' },
        { key: 'payment_method', label: 'Payment method' },
        { key: 'enabled', label: 'Enabled' },
      ]}
    />
  );
}

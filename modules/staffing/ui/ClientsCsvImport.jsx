import React from 'react';
import CsvImportPage from '../../../dashboard/src/components/CsvImportPage';

/**
 * Staffing module — clients CSV import screen.
 *
 * Mounted at /modules/staffing/clients/csv_import. Powered by
 * Core\CsvImportService via /api/staffing/csv_import.php.
 */
export default function ClientsCsvImport() {
  return (
    <CsvImportPage
      endpoint="/modules/staffing/api/csv_import.php"
      entityLabel="Clients"
      backTo="../clients"
      backLabel="← Clients"
      testidPrefix="staffing-clients-csv-import"
      presetEntity="staffing_clients"
      previewColumns={[
        { key: 'name',                  label: 'Client name' },
        { key: 'legal_name',            label: 'Legal name' },
        { key: 'industry',              label: 'Industry' },
        { key: 'primary_contact_email', label: 'Primary email' },
        { key: 'status',                label: 'Status' },
      ]}
    />
  );
}

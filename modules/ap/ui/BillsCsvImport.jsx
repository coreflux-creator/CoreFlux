import React from 'react';
import CsvImportPage from '../../../dashboard/src/components/CsvImportPage';

/**
 * AP module — bills multi-line CSV import.
 *
 * Mounted at /modules/ap/bills/csv_import. Header + line items live in
 * the SAME CSV, grouped by `bill_number`. Powered by Core\CsvImportService
 * via /api/ap/bills_csv_import.php.
 */
export default function BillsCsvImport() {
  return (
    <CsvImportPage
      endpoint="/modules/ap/api/bills_csv_import.php"
      entityLabel="AP Bills"
      backTo="../bills"
      backLabel="← Bills"
      testidPrefix="ap-bills-csv-import"
      presetEntity="ap_bills"
      previewColumns={[
        { key: 'bill_number',      label: 'Bill #' },
        { key: 'vendor_name',      label: 'Vendor' },
        { key: 'bill_date',        label: 'Bill date' },
        { key: 'due_date',         label: 'Due' },
        { key: 'line_description', label: 'Line' },
        { key: 'line_total',       label: 'Line total' },
      ]}
    />
  );
}

import React from 'react';
import CsvImportPage from '../../../dashboard/src/components/CsvImportPage';

/**
 * AP module — bills multi-line CSV import.
 *
 * Mounted at /modules/ap/bills/csv_import. Header + line items live in
 * the SAME CSV, grouped by `bill_number`. Powered by Core\CsvImportService
 * via /api/v1/ap/bills-csv-import.
 */
export default function BillsCsvImport() {
  return (
    <CsvImportPage
      endpoint="/api/v1/ap/bills-csv-import"
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

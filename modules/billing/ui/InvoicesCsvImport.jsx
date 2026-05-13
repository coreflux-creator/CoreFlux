import React from 'react';
import CsvImportPage from '../../../dashboard/src/components/CsvImportPage';

/**
 * Billing module — invoices multi-line CSV import.
 *
 * Mounted at /modules/billing/invoices/csv_import. Header + line items
 * live in the same CSV, grouped by `invoice_number`. Powered by
 * Core\CsvImportService via /api/billing/csv_import.php.
 */
export default function InvoicesCsvImport() {
  return (
    <CsvImportPage
      endpoint="/modules/billing/api/csv_import.php"
      entityLabel="AR Invoices"
      backTo="../invoices"
      backLabel="← Invoices"
      testidPrefix="billing-invoices-csv-import"
      previewColumns={[
        { key: 'invoice_number',   label: 'Invoice #' },
        { key: 'client_name',      label: 'Client' },
        { key: 'issue_date',       label: 'Issue' },
        { key: 'due_date',         label: 'Due' },
        { key: 'line_description', label: 'Line' },
        { key: 'line_total',       label: 'Line total' },
      ]}
    />
  );
}

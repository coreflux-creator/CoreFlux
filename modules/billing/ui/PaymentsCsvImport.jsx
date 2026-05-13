import React from 'react';
import CsvImportPage from '../../../dashboard/src/components/CsvImportPage';

/**
 * Billing module — payments (AR receipts) CSV import.
 *
 * Bulk-loads historical AR payments. Invoice allocations stay out of
 * scope (done via the Payment Detail UI). Powered by
 * Core\CsvImportService via /api/billing/payments_csv_import.php.
 */
export default function PaymentsCsvImport() {
  return (
    <CsvImportPage
      endpoint="/modules/billing/api/payments_csv_import.php"
      entityLabel="Billing Payments"
      backTo="../payments"
      backLabel="← Payments"
      testidPrefix="billing-payments-csv-import"
      presetEntity="billing_payments"
      previewColumns={[
        { key: 'client_name', label: 'Client' },
        { key: 'received_at', label: 'Received' },
        { key: 'method',      label: 'Method' },
        { key: 'reference',   label: 'Reference' },
        { key: 'amount',      label: 'Amount' },
      ]}
    />
  );
}

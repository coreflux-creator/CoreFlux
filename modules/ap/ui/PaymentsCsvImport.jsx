import React from 'react';
import CsvImportPage from '../../../dashboard/src/components/CsvImportPage';

/**
 * AP module — payments CSV import.
 *
 * Bulk-loads historical AP payments. Bill allocations stay out of scope
 * (done via the Payment Detail UI). Powered by Core\CsvImportService
 * via /api/ap/payments_csv_import.php.
 */
export default function PaymentsCsvImport() {
  return (
    <CsvImportPage
      endpoint="/modules/ap/api/payments_csv_import.php"
      entityLabel="AP Payments"
      backTo="../payments"
      backLabel="← Payments"
      testidPrefix="ap-payments-csv-import"
      presetEntity="ap_payments"
      previewColumns={[
        { key: 'vendor_name', label: 'Vendor' },
        { key: 'pay_date',    label: 'Pay date' },
        { key: 'method',      label: 'Method' },
        { key: 'reference',   label: 'Reference' },
        { key: 'amount',      label: 'Amount' },
        { key: 'status',      label: 'Status' },
      ]}
    />
  );
}

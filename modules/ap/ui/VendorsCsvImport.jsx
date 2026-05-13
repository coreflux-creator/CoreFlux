import React from 'react';
import CsvImportPage from '../../../dashboard/src/components/CsvImportPage';

/**
 * AP module — vendors CSV import screen.
 *
 * Mounted at /modules/ap/vendors/csv_import (or wherever the AP router puts
 * us). Powered by Core\CsvImportService via /api/ap/csv_import.php.
 */
export default function VendorsCsvImport() {
  return (
    <CsvImportPage
      endpoint="/modules/ap/api/csv_import.php"
      entityLabel="Vendors"
      backTo="../vendors"
      backLabel="← Vendors"
      testidPrefix="ap-vendors-csv-import"
      previewColumns={[
        { key: 'vendor_name',     label: 'Name' },
        { key: 'vendor_type',     label: 'Type' },
        { key: 'vendor_category', label: 'Category' },
        { key: 'default_terms',   label: 'Terms' },
        { key: 'requires_1099',   label: '1099?' },
      ]}
    />
  );
}

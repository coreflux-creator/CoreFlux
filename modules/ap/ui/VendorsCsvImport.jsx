import React from 'react';
import CsvImportPage from '../../../dashboard/src/components/CsvImportPage';

/**
 * AP module — vendors CSV import screen.
 *
 * Mounted at /modules/ap/vendors/csv_import (or wherever the AP router puts
 * us). Powered by Core\CsvImportService via /api/v1/ap/csv-import.
 */
export default function VendorsCsvImport() {
  return (
    <CsvImportPage
      endpoint="/api/v1/ap/csv-import"
      entityLabel="Vendors"
      backTo="../vendors"
      backLabel="← Vendors"
      testidPrefix="ap-vendors-csv-import"
      presetEntity="ap_vendors"
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

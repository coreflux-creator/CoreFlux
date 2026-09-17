import React from 'react';
import CsvImportPage from '../../../dashboard/src/components/CsvImportPage';

export default function ItemsCsvImport() {
  return (
    <CsvImportPage
      endpoint="/modules/billing/api/items_csv_import.php"
      entityLabel="Products & services"
      backTo="../items"
      backLabel="Products & services"
      testidPrefix="billing-items-csv-import"
      presetEntity="billing_items"
      defaultUpdateExisting
      updateExistingLabel="Update matching item IDs, external IDs, or codes"
      description="Export your catalog, complete or revise it in a spreadsheet, then upload it here. Existing items are matched safely before any changes are applied."
      previewColumns={[
        { key: 'item_id', label: 'Item ID' },
        { key: 'code', label: 'Code' },
        { key: 'name', label: 'Name' },
        { key: 'item_type', label: 'Type' },
        { key: 'default_unit_price', label: 'Default price' },
        { key: 'active', label: 'Active' },
      ]}
    />
  );
}

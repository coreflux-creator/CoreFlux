import React from 'react';
import CsvImportPage from '../../../dashboard/src/components/CsvImportPage';

/**
 * People module — CSV import.
 *
 * Powered by the shared `CsvImportPage` component (interactive column
 * mapping, AI-assisted auto-map, saved presets, dry-run preview).
 */
export default function CsvImport() {
  return (
    <CsvImportPage
      endpoint="/modules/people/api/csv_import.php"
      entityLabel="People"
      backTo=".."
      backLabel="← Directory"
      testidPrefix="people-csv-import"
      presetEntity="people"
      previewColumns={[
        { key: 'first_name',     label: 'First name' },
        { key: 'last_name',      label: 'Last name' },
        { key: 'email_primary',  label: 'Primary email' },
        { key: 'classification', label: 'Classification' },
        { key: 'status',         label: 'Status' },
      ]}
    />
  );
}

import React from 'react';
import CsvImportPage from '../../../dashboard/src/components/CsvImportPage';

/**
 * Placements module — CSV import.
 *
 * Powered by the shared `CsvImportPage` component (interactive column
 * mapping, AI-assisted auto-map, saved presets, dry-run preview).
 */
export default function CsvImport() {
  return (
    <CsvImportPage
      endpoint="/modules/placements/api/csv_import.php"
      entityLabel="Placements"
      backTo=".."
      backLabel="← Placements"
      testidPrefix="placements-csv-import"
      presetEntity="placements"
      previewColumns={[
        { key: 'person_email',    label: 'Person email' },
        { key: 'title',           label: 'Title' },
        { key: 'engagement_type', label: 'Engagement type' },
        { key: 'start_date',      label: 'Start date' },
        { key: 'end_client_name', label: 'End client name' },
      ]}
    />
  );
}

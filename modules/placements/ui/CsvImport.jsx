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
      description="Export placements, fill or correct fields in a spreadsheet, then upload the file to update matching Placement IDs or create new rows."
      defaultUpdateExisting
      updateExistingLabel="Update matching Placement IDs and create rows that do not exist"
      previewColumns={[
        { key: 'placement_id',    label: 'Placement ID' },
        { key: 'person_email',    label: 'Person email' },
        { key: 'title',           label: 'Title' },
        { key: 'engagement_type', label: 'Engagement type' },
        { key: 'start_date',      label: 'Start date' },
        { key: 'end_client_name', label: 'End client name' },
      ]}
      successCtas={(result) => {
        const n = result?.imported_count ?? 0;
        if (n <= 0) return [];
        return [
          { label: `View ${n} processed placement${n === 1 ? '' : 's'}`,
            to: '../list?status=',
            testid: 'placements-csv-import-view-placements',
            primary: true },
          { label: 'Review draft rates',
            to: '../draft-rates',
            testid: 'placements-csv-import-view-draft-rates',
            primary: false },
        ];
      }}
    />
  );
}

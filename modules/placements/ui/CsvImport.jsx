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
      endpoint="/api/v1/placements/csv-import"
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
      successCtas={(result) => {
        // CSV imports always land placements as `status='draft'` and
        // their first rate row as unapproved. The default Placements
        // list filters to status=active, which hides everything the
        // operator just imported. Surface explicit CTAs so the
        // post-import path is "review drafts → activate → approve
        // rates" instead of "where did my rows go?".
        const n = result?.imported_count ?? 0;
        if (n <= 0) return [];
        return [
          { label: `View ${n} draft placement${n === 1 ? '' : 's'}`,
            to: '../list?status=draft',
            testid: 'placements-csv-import-view-drafts',
            primary: true },
          { label: `Approve ${n} draft rate${n === 1 ? '' : 's'}`,
            to: '../draft-rates',
            testid: 'placements-csv-import-view-draft-rates',
            primary: false },
        ];
      }}
    />
  );
}

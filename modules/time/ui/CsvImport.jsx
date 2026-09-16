import React from 'react';
import CsvImportPage from '../../../dashboard/src/components/CsvImportPage';

/**
 * Time CSV Import — now uses the shared CsvImportPage (2026-02-XX).
 *
 * Previously this was a 100-line one-off that predated the shared
 * component. Refactoring it onto the shared component gives the Time
 * module:
 *   • Interactive column mapping (auto-detect + manual overrides)
 *   • AI-assisted mapping suggestions (uses Time's ai_suggest_map
 *     endpoint if /modules/time/api/ai_suggest_map.php exists; safe
 *     fallback to auto_map if not).
 *   • Saved mapping presets (header signature → one-click re-import)
 *   • CSV Import History recording (audit trail on every commit)
 *   • Cross-links: + Bulk Import, View History
 *   • The Time-specific "Pre-approved" toggle survives as an
 *     extraToggle so the same `?already_approved=1` query string still
 *     reaches /modules/time/api/csv_import.php.
 */
export default function CsvImport() {
  return (
    <CsvImportPage
      endpoint="/modules/time/api/csv_import.php"
      entityLabel="Time Entries"
      backTo="../entries"
      backLabel="← My Time"
      testidPrefix="time-csv-import"
      presetEntity="time"
      description="Import time by Placement ID or external placement ID. Matching unapproved entries can be corrected in the same file."
      updateExistingLabel="Update matching unapproved entries instead of creating duplicates"
      previewColumns={[
        { key: 'placement_id',          label: 'Placement ID' },
        { key: 'placement_external_id', label: 'External placement ID' },
        { key: 'work_date',              label: 'Work Date' },
        { key: 'category',               label: 'Category' },
        { key: 'hours',                  label: 'Hours' },
        { key: 'description',            label: 'Description' },
      ]}
      extraToggles={[
        {
          key:         'already_approved',
          label:       'Mark as pre-approved (skip review queue)',
          commitParam: 'already_approved=1',
          default:     false,
        },
      ]}
      successCtas={(result) => {
        const n = result?.imported_count ?? 0;
        if (n <= 0) return [];
        return [
          { label: 'Review imported time', to: '../review', testid: 'time-csv-import-review', primary: true },
          { label: 'Open settlement', to: '../settlement', testid: 'time-csv-import-settlement', primary: false },
        ];
      }}
    />
  );
}

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
      backTo="/modules/staffing/timesheets"
      backLabel="Back to timesheets"
      testidPrefix="time-csv-import"
      presetEntity="time"
      description="Import time by Placement ID and date. CoreFlux fills in the worker, client, weekly timesheet, and time period automatically."
      templateNote={(
        <>
          Only <strong>Placement ID</strong>, <strong>Work date</strong>, and <strong>Hours</strong> are required.
          Work date may also be the source timesheet&apos;s week-ending date when the row contains a weekly total.
          Time type defaults to regular, and a stable source-row ID makes repeat imports safe.
        </>
      )}
      extraDownloads={[
        {
          label: 'Download placement ID reference',
          href: '/modules/time/api/csv_import.php?action=placement_reference',
          testid: 'time-csv-import-placement-reference',
        },
      ]}
      defaultUpdateExisting
      updateExistingLabel="Update matching unapproved entries instead of creating duplicates"
      previewColumns={[
        { key: 'placement_id',    label: 'Placement ID' },
        { key: 'person_name',     label: 'Person' },
        { key: 'end_client_name', label: 'Client' },
        { key: 'work_date',       label: 'Work date' },
        { key: 'hour_type',       label: 'Time type' },
        { key: 'hours',           label: 'Hours' },
        { key: 'description',     label: 'Description' },
      ]}
      extraToggles={[
        {
          key:         'already_approved',
          label:       'Already approved externally',
          commitParam: 'already_approved=1',
          default:     false,
        },
      ]}
      successCtas={(result) => {
        const n = result?.imported_count ?? 0;
        if (n <= 0) return [];
        return [
          { label: 'Open weekly timesheets', to: '/modules/staffing/timesheets', testid: 'time-csv-import-timesheets', primary: true },
          { label: 'Review imported time', to: '../review', testid: 'time-csv-import-review', primary: false },
          { label: 'Open settlement', to: '../settlement', testid: 'time-csv-import-settlement', primary: false },
        ];
      }}
    />
  );
}

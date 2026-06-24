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
 *     endpoint through the v1 module API when available; safe
 *     fallback to auto_map if not).
 *   • Saved mapping presets (header signature → one-click re-import)
 *   • CSV Import History recording (audit trail on every commit)
 *   • Cross-links: + Bulk Import, View History
 *   • The Time-specific "Pre-approved" toggle survives as an
 *     extraToggle so the same `?already_approved=1` query string still
 *     reaches /api/v1/time/csv-import.
 */
export default function CsvImport() {
  return (
    <CsvImportPage
      endpoint="/api/v1/time/csv-import"
      entityLabel="Time Entries"
      backTo="../entries"
      backLabel="← My Time"
      testidPrefix="time-csv-import"
      presetEntity="time"
      previewColumns={[
        { key: 'placement_external_id', label: 'Placement' },
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
    />
  );
}

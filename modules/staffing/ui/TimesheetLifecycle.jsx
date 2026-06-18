import React from 'react';
import { Link, useParams } from 'react-router-dom';
import { useApi } from '../../../dashboard/src/lib/api';
import TimesheetLifecycleTimeline from '../../../dashboard/src/components/TimesheetLifecycleTimeline';

/**
 * Full-page Timesheet Lifecycle view — 2026-02.
 *
 * URL: /modules/staffing/timesheets/:id/lifecycle
 *      /modules/staffing/timesheets/:id/lifecycle?entry_id=N (narrowed)
 *
 * Pulls the full downstream cascade from
 *   GET /modules/staffing/api/lifecycle.php?action=timesheet&id=:id
 * (or action=entry when entry_id is supplied) and renders the
 * vertical timeline.
 */
export default function TimesheetLifecycle() {
  const { id } = useParams();
  const entryId = new URLSearchParams(window.location.search).get('entry_id');
  const apiPath = entryId
    ? `/modules/staffing/api/lifecycle.php?action=entry&id=${entryId}`
    : `/modules/staffing/api/lifecycle.php?action=timesheet&id=${id}`;
  const { data, loading, error } = useApi(apiPath, [apiPath]);

  return (
    <section data-testid="timesheet-lifecycle-page" style={{ padding: 16 }}>
      <Link
        to={`/modules/staffing/timesheets/${id}`}
        data-testid="timesheet-lifecycle-back"
        style={{ fontSize: 13, color: '#6b7280' }}
      >
        ← Back to timesheet
      </Link>
      <h2 style={{ marginTop: 8, marginBottom: 4 }}>
        Downstream cascade {entryId ? `· entry #${entryId}` : `· timesheet #${id}`}
      </h2>
      <p style={{ fontSize: 13, color: '#6b7280', marginTop: 0 }}>
        Every artifact that flowed from this {entryId ? 'time entry' : 'timesheet'} — accruals, AR invoices, vendor bills,
        pay-when-paid releases, and rail dispatches. Read-only.
      </p>

      {loading && <p data-testid="timesheet-lifecycle-loading">Loading cascade…</p>}
      {error && <p className="error" data-testid="timesheet-lifecycle-error">Error: {error.message}</p>}
      {data && <TimesheetLifecycleTimeline cascade={data} />}
    </section>
  );
}

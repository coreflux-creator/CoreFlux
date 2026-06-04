import React, { useEffect, useState, useMemo } from 'react';
import { useParams, Link, useNavigate } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';

/**
 * Timesheet Detail — Batch 2 (2026-02) + inline-edit (2026-02 follow-up).
 *
 * Drill-in view for a single timesheet, with row-level editing wired
 * directly to the existing `entry_save` / `entry_delete` API endpoints
 * (which auto-reopen submitted/approved sheets via
 * `staffingTimesheetReopen()` — see modules/staffing/lib/timesheets.php).
 *
 * Why this exists: operators reported "we're back to issues with
 * timesheets. I see where they're available individually, but they
 * don't do what they need to, can't edit them." The previous version
 * sent them to TimesheetWeek which hard-coded anchor=today + person=YOU,
 * silently dumping them on their own current week. This page now hosts
 * the entire edit experience.
 *
 * Action surface by status:
 *   - draft      → inline edit on every row, "Add entry" form, Submit.
 *   - submitted  → Approve + Reject + Re-open for edit + still-inline-
 *                  editable rows (auto-reopen happens server-side on save).
 *   - approved   → Re-open for edit (gated by timesheets.write); rows
 *                  read-only until reopened.
 *   - rejected   → inline editable, Re-submit at the bottom.
 *   - locked / payroll_ready / billing_ready → read-only; show a notice
 *                  that downstream lines must be reversed first.
 *
 * URL: /modules/staffing/timesheets/:id
 *      /modules/staffing/timesheets/:id?placement_id=N  (placement view)
 */
const HOUR_TYPES = [
  { v: 'regular',     l: 'Regular',      billable: true  },
  { v: 'overtime',    l: 'Overtime',     billable: true  },
  { v: 'doubletime',  l: 'Double',       billable: true  },
  { v: 'holiday',     l: 'Holiday',      billable: false },
  { v: 'pto',         l: 'PTO',          billable: false },
  { v: 'sick',        l: 'Sick',         billable: false },
  { v: 'bereavement', l: 'Bereavement',  billable: false },
  { v: 'unpaid',      l: 'Unpaid',       billable: false },
  { v: 'nonbillable', l: 'Non-billable', billable: false },
];

const READ_ONLY_STATUSES = ['locked', 'payroll_ready', 'billing_ready'];

export default function TimesheetDetail({ session }) {
  const { id } = useParams();
  const nav = useNavigate();
  const placementId = new URLSearchParams(window.location.search).get('placement_id');
  const apiPath = placementId
    ? `/modules/staffing/api/timesheets.php?action=detail_for_placement&id=${id}&placement_id=${placementId}`
    : `/modules/staffing/api/timesheets.php?action=detail&id=${id}`;
  const { data, loading, error, reload } = useApi(apiPath, [apiPath]);
  const ts      = data?.timesheet;
  const entries = data?.entries ?? [];

  const [busy, setBusy]           = useState(false);
  const [rejecting, setRejecting] = useState(false);
  const [reason, setReason]       = useState('');
  const [actionError, setActionError] = useState(null);
  const [saveError, setSaveError] = useState(null);
  const [savingRowId, setSavingRowId] = useState(null);
  // Per-row local edit buffer keyed by entry id; populated lazily when a
  // field changes so untouched rows render the server values verbatim.
  const [edits, setEdits] = useState({});

  // Fetch this worker's active placements so the "Add entry" form can
  // offer a dropdown instead of asking for a raw id.
  const placementsApi = useApi(
    ts?.person_id
      ? `/modules/placements/api/placements.php?person_id=${ts.person_id}&status=active&per_page=200`
      : null,
    [ts?.person_id]
  );
  const placements = placementsApi.data?.rows ?? [];

  // Reset edit buffer whenever the timesheet reloads (post-save).
  useEffect(() => { setEdits({}); }, [data?.timesheet?.id, entries.length]);

  if (loading) return <p data-testid="timesheet-detail-loading">Loading timesheet…</p>;
  if (error)   return <p className="error" data-testid="timesheet-detail-error">Error: {error.message}</p>;
  if (!ts)     return <p data-testid="timesheet-detail-empty">Timesheet not found.</p>;

  const isReadOnly = READ_ONLY_STATUSES.includes(ts.status);
  const isApproved = ts.status === 'approved';
  // Submitted + rejected + draft are all inline-editable. Approved
  // requires explicit reopen first (preserves SoD intent: an approver
  // can't silently mutate a row a worker already signed off on).
  const canEditRows = !isReadOnly && !isApproved;

  const act = async (action, extra = {}) => {
    setBusy(true); setActionError(null);
    try {
      await api.post(`/modules/staffing/api/timesheets.php?action=${action}`, {
        person_id: ts.person_id,
        period_start: ts.period_start,
        period_end: ts.period_end,
        ...extra,
      });
      reload();
      setRejecting(false); setReason('');
    } catch (e) { setActionError(e.message); }
    finally { setBusy(false); }
  };

  const reopenForEdit = async () => {
    setBusy(true); setActionError(null);
    try {
      await api.post('/modules/staffing/api/timesheets.php?action=reopen',
        { id: ts.id, reason: 'inline edit from detail page' });
      reload();
    } catch (e) { setActionError(e.message); }
    finally { setBusy(false); }
  };

  // ── inline-edit helpers ──
  const editedRow = (e) => ({ ...e, ...(edits[e.id] || {}) });
  const setField = (entryId, field, value) => {
    setEdits(prev => ({ ...prev, [entryId]: { ...(prev[entryId] || {}), [field]: value } }));
  };
  const isDirty = (entryId) => edits[entryId] && Object.keys(edits[entryId]).length > 0;

  const saveRow = async (entry) => {
    setSavingRowId(entry.id); setSaveError(null);
    const merged = editedRow(entry);
    try {
      await api.post('/modules/staffing/api/timesheets.php?action=entry_save', {
        id: entry.id,
        placement_id: merged.placement_id,
        work_date: merged.work_date,
        hour_type: merged.hour_type,
        hours: parseFloat(merged.hours) || 0,
        description: merged.description ?? '',
      });
      setEdits(prev => { const n = { ...prev }; delete n[entry.id]; return n; });
      reload();
    } catch (e) { setSaveError(`Row #${entry.id}: ${e.message}`); }
    finally { setSavingRowId(null); }
  };

  const deleteRow = async (entry) => {
    if (!window.confirm(`Delete entry for ${entry.work_date} (${Number(entry.hours).toFixed(2)}h)? This cannot be undone.`)) return;
    setSavingRowId(entry.id); setSaveError(null);
    try {
      await api.post('/modules/staffing/api/timesheets.php?action=entry_delete', { id: entry.id });
      reload();
    } catch (e) { setSaveError(`Row #${entry.id}: ${e.message}`); }
    finally { setSavingRowId(null); }
  };

  // Per-placement summary (only when timesheet spans multiple placements).
  // Computed over the MERGED rows so unsaved edits flow into the totals
  // live — operators see the impact of each cell change before committing.
  const { byPlacement, liveTotal, liveByCategory, liveByHourType } = useMemo(() => {
    const m = {};
    let total = 0;
    const byCat = { billable: 0, nonbillable: 0 };
    const byHt = {};
    entries.forEach(e => {
      const row = { ...e, ...(edits[e.id] || {}) };
      const h = Number(row.hours || 0);
      const k = row.placement_id;
      if (!m[k]) m[k] = { title: e.placement_title || `Placement #${k}`, client: e.client_name, hours: 0 };
      m[k].hours += h;
      total += h;
      // Billable flag follows hour_type per STAFFING_HOUR_TYPE_TO_CATEGORY
      // rules — keep the UI in sync with what the backend will compute.
      const ht = HOUR_TYPES.find(t => t.v === row.hour_type) || HOUR_TYPES[0];
      byCat[ht.billable ? 'billable' : 'nonbillable'] += h;
      byHt[row.hour_type] = (byHt[row.hour_type] || 0) + h;
    });
    return { byPlacement: m, liveTotal: total, liveByCategory: byCat, liveByHourType: byHt };
  }, [entries, edits]);

  const serverTotal = Number(ts.total_hours || 0);
  const delta = liveTotal - serverTotal;
  const hasUnsavedEdits = Object.keys(edits).length > 0;

  const isSubmitted = ts.status === 'submitted';
  const isRejected  = ts.status === 'rejected';
  const isDraft     = ts.status === 'draft';

  return (
    <section data-testid="timesheet-detail-page">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <button type="button" className="btn btn--ghost" onClick={() => nav('..')}
                  data-testid="timesheet-detail-back">← Timesheets</button>
          <h2 style={{ margin: '8px 0 4px' }} data-testid="timesheet-detail-title">
            Timesheet #{ts.id}
            {placementId && <span style={{ fontSize: 14, color: '#666', marginLeft: 8 }}>
              · placement {placementId} only ({Number(data.placement_hours || 0).toFixed(2)}h)
            </span>}
          </h2>
          <p style={{ color: '#666', fontSize: 13, margin: 0 }} data-testid="timesheet-detail-meta">
            <strong>{ts.first_name || ''} {ts.last_name || ''}</strong>
            {ts.email_primary && <> · {ts.email_primary}</>}
            {' · '}{ts.period_start} → {ts.period_end}
            {' · '}<StatusBadge status={ts.status} />
            {' · '}<span data-testid="timesheet-detail-header-total">{liveTotal.toFixed(2)}h total</span>
            {hasUnsavedEdits && Math.abs(delta) >= 0.005 && (
              <span data-testid="timesheet-detail-header-total-pending"
                    style={{ color: '#d97706', fontSize: 11, marginLeft: 6 }}>
                ({delta >= 0 ? '+' : ''}{delta.toFixed(2)}h pending save)
              </span>
            )}
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          {/* Always offer the weekly-grid edit option, but ANCHORED on
              this timesheet's period + worker (fixes the bug where the
              link used to dump operators on their own current week). */}
          {!isReadOnly && (
            <Link to={`../week?period_start=${ts.period_start}&person_id=${ts.person_id}`}
                  className="btn btn--ghost"
                  data-testid="timesheet-detail-open-week">
              Open weekly grid
            </Link>
          )}
          {isApproved && (
            <button type="button" className="btn btn--ghost" disabled={busy}
                    onClick={reopenForEdit}
                    data-testid="timesheet-detail-reopen">
              {busy ? '…' : 'Re-open for edit'}
            </button>
          )}
          {isSubmitted && !rejecting && (
            <>
              <button type="button" className="btn btn--primary" disabled={busy}
                      onClick={() => act('approve')}
                      data-testid="timesheet-detail-approve">
                {busy ? '…' : 'Approve'}
              </button>
              <button type="button" className="btn btn--ghost" disabled={busy}
                      onClick={() => setRejecting(true)}
                      data-testid="timesheet-detail-reject-open">
                Reject
              </button>
            </>
          )}
          {(isDraft || isRejected) && (
            <button type="button" className="btn btn--primary" disabled={busy}
                    onClick={() => act('submit')}
                    data-testid="timesheet-detail-submit">
              {busy ? '…' : (isRejected ? 'Re-submit' : 'Submit for approval')}
            </button>
          )}
        </div>
      </header>

      {rejecting && (
        <div style={{ background: '#fff7ed', border: '1px solid #fdba74', padding: 12, borderRadius: 6, marginBottom: 16 }}
             data-testid="timesheet-detail-reject-form">
          <label style={{ fontSize: 12, display: 'block', marginBottom: 6 }}>
            Rejection reason (required)
            <input className="input" style={{ width: '100%', marginTop: 4 }}
                   value={reason} onChange={e => setReason(e.target.value)}
                   data-testid="timesheet-detail-reject-reason" />
          </label>
          <div style={{ display: 'flex', gap: 8 }}>
            <button type="button" className="btn btn--danger" disabled={busy || !reason.trim()}
                    onClick={() => act('reject', { reason })}
                    data-testid="timesheet-detail-reject-confirm">
              Confirm reject
            </button>
            <button type="button" className="btn btn--ghost" disabled={busy}
                    onClick={() => { setRejecting(false); setReason(''); }}>
              Cancel
            </button>
          </div>
        </div>
      )}

      {isReadOnly && (
        <p data-testid="timesheet-detail-readonly-notice"
           style={{ background: '#f3f4f6', padding: 10, borderRadius: 4, fontSize: 13, color: '#475569' }}>
          This timesheet is <strong>{ts.status}</strong>. Rows are read-only — the downstream payroll/billing lines must be reversed before edits can land.
        </p>
      )}
      {isApproved && (
        <p data-testid="timesheet-detail-approved-notice"
           style={{ background: '#ecfdf5', padding: 10, borderRadius: 4, fontSize: 13, color: '#065f46' }}>
          Approved on {ts.approved_at || '—'}. Click <strong>Re-open for edit</strong> if a correction is needed.
        </p>
      )}
      {actionError && <p className="error" data-testid="timesheet-detail-action-error">{actionError}</p>}
      {saveError && <p className="error" data-testid="timesheet-detail-save-error">{saveError}</p>}
      {ts.rejection_reason && (
        <p style={{ background: '#fee2e2', padding: 8, borderRadius: 4, fontSize: 13 }}
           data-testid="timesheet-detail-rejection-reason">
          <strong>Rejection reason:</strong> {ts.rejection_reason}
        </p>
      )}

      {/* Live running total — reflects unsaved edits in real time. */}
      <div data-testid="timesheet-detail-live-totals"
           style={{
             marginTop: 16,
             padding: 12,
             background: hasUnsavedEdits ? '#fffbeb' : '#f8fafc',
             border: `1px solid ${hasUnsavedEdits ? '#fbbf24' : '#e2e8f0'}`,
             borderRadius: 6,
             display: 'grid',
             gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))',
             gap: 12,
           }}>
        <LiveCell label="Total hours"
                  value={`${liveTotal.toFixed(2)}h`}
                  testId="timesheet-detail-live-total"
                  emphasis />
        <LiveCell label="Billable"
                  value={`${liveByCategory.billable.toFixed(2)}h`}
                  testId="timesheet-detail-live-billable" />
        <LiveCell label="Non-billable"
                  value={`${liveByCategory.nonbillable.toFixed(2)}h`}
                  testId="timesheet-detail-live-nonbillable" />
        <LiveCell label="Entries"
                  value={entries.length}
                  testId="timesheet-detail-live-entry-count" />
        {hasUnsavedEdits && (
          <LiveCell label="Unsaved delta"
                    value={`${delta >= 0 ? '+' : ''}${delta.toFixed(2)}h`}
                    testId="timesheet-detail-live-delta"
                    color={Math.abs(delta) < 0.005 ? '#475569' : (delta > 0 ? '#d97706' : '#dc2626')} />
        )}
      </div>
      {Object.keys(liveByHourType).length > 0 && (
        <div data-testid="timesheet-detail-live-by-hour-type"
             style={{ marginTop: 6, fontSize: 11, color: '#64748b', display: 'flex', flexWrap: 'wrap', gap: 12 }}>
          {Object.entries(liveByHourType)
            .filter(([, h]) => h > 0)
            .sort((a, b) => b[1] - a[1])
            .map(([ht, h]) => {
              const label = (HOUR_TYPES.find(t => t.v === ht) || {}).l || ht;
              return (
                <span key={ht} data-testid={`timesheet-detail-live-ht-${ht}`}>
                  <strong>{label}:</strong> {h.toFixed(2)}h
                </span>
              );
            })}
        </div>
      )}

      {/* Per-placement summary */}
      {!placementId && Object.keys(byPlacement).length > 1 && (
        <>
          <h3 style={{ marginTop: 16, fontSize: 14, color: '#475569' }}>By placement</h3>
          <table className="data-table" data-testid="timesheet-detail-by-placement">
            <thead><tr><th>Placement</th><th>Client</th><th>Hours</th></tr></thead>
            <tbody>
              {Object.entries(byPlacement).map(([k, v]) => (
                <tr key={k} data-testid={`timesheet-detail-by-placement-row-${k}`}>
                  <td>
                    <Link to={`/modules/staffing/placements/${k}`}>{v.title}</Link>
                  </td>
                  <td style={{ fontSize: 12, color: '#666' }}>{v.client}</td>
                  <td style={{ fontWeight: 600 }}>{v.hours.toFixed(2)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </>
      )}

      <h3 style={{ marginTop: 16, fontSize: 14, color: '#475569' }}>
        Entries {canEditRows && <span style={{ fontSize: 11, color: '#999', fontWeight: 400 }}>· click a cell to edit</span>}
      </h3>
      <table className="data-table" data-testid="timesheet-detail-entries">
        <thead>
          <tr>
            <th>Date</th>
            <th>Placement</th>
            <th>Hour type</th>
            <th style={{ width: 80 }}>Hours</th>
            <th>Billable</th>
            <th>Description</th>
            {canEditRows && <th style={{ width: 130 }}></th>}
          </tr>
        </thead>
        <tbody>
          {entries.length === 0 && (
            <tr><td colSpan={canEditRows ? 7 : 6} style={{ color: '#999' }}
                    data-testid="timesheet-detail-entries-empty">No entries.</td></tr>
          )}
          {entries.map(e => {
            const row = editedRow(e);
            const dirty = isDirty(e.id);
            const saving = savingRowId === e.id;
            return (
              <tr key={e.id} data-testid={`timesheet-detail-entry-${e.id}`}
                  style={{ background: dirty ? '#fffbeb' : undefined }}>
                <td style={{ fontSize: 12 }}>
                  {canEditRows ? (
                    <input type="date" className="input" value={row.work_date}
                           onChange={ev => setField(e.id, 'work_date', ev.target.value)}
                           disabled={saving}
                           data-testid={`timesheet-detail-entry-${e.id}-date`}
                           style={{ width: 130, fontSize: 12 }} />
                  ) : e.work_date}
                </td>
                <td style={{ fontSize: 12 }}>
                  {canEditRows && placements.length > 0 ? (
                    <select className="input" value={row.placement_id}
                            onChange={ev => setField(e.id, 'placement_id', parseInt(ev.target.value, 10))}
                            disabled={saving}
                            data-testid={`timesheet-detail-entry-${e.id}-placement`}
                            style={{ fontSize: 12 }}>
                      {placements.map(p => (
                        <option key={p.id} value={p.id}>
                          {p.title || `Placement #${p.id}`}
                          {p.end_client_name ? ` · ${p.end_client_name}` : ''}
                        </option>
                      ))}
                      {/* Defensive: include the current placement if it's
                          not in the active list (e.g. ended placement on
                          a historical timesheet). */}
                      {!placements.some(p => p.id === e.placement_id) && (
                        <option value={e.placement_id}>
                          {e.placement_title || `Placement #${e.placement_id}`} (inactive)
                        </option>
                      )}
                    </select>
                  ) : (
                    <>
                      <Link to={`/modules/staffing/placements/${e.placement_id}`}>{e.placement_title || `Placement #${e.placement_id}`}</Link>
                      {e.client_name && <span style={{ display: 'block', color: '#666', fontSize: 11 }}>{e.client_name}</span>}
                    </>
                  )}
                </td>
                <td style={{ fontSize: 12 }}>
                  {canEditRows ? (
                    <select className="input" value={row.hour_type}
                            onChange={ev => setField(e.id, 'hour_type', ev.target.value)}
                            disabled={saving}
                            data-testid={`timesheet-detail-entry-${e.id}-hour-type`}
                            style={{ fontSize: 12 }}>
                      {HOUR_TYPES.map(t => <option key={t.v} value={t.v}>{t.l}</option>)}
                    </select>
                  ) : e.hour_type}
                </td>
                <td>
                  {canEditRows ? (
                    <input type="number" step="0.25" min="0" max="24" className="input"
                           value={row.hours}
                           onChange={ev => setField(e.id, 'hours', ev.target.value)}
                           disabled={saving}
                           data-testid={`timesheet-detail-entry-${e.id}-hours`}
                           style={{ width: 70, textAlign: 'right' }} />
                  ) : <span style={{ fontWeight: 600 }}>{Number(e.hours || 0).toFixed(2)}</span>}
                </td>
                <td style={{ fontSize: 12 }}>{Number(e.billable) === 1 ? 'Yes' : 'No'}</td>
                <td style={{ fontSize: 12, color: '#666' }}>
                  {canEditRows ? (
                    <input type="text" className="input" value={row.description || ''}
                           onChange={ev => setField(e.id, 'description', ev.target.value)}
                           disabled={saving}
                           data-testid={`timesheet-detail-entry-${e.id}-description`}
                           style={{ width: '100%', fontSize: 12 }} />
                  ) : e.description}
                </td>
                {canEditRows && (
                  <td style={{ fontSize: 12 }}>
                    <button type="button" className="btn btn--primary"
                            onClick={() => saveRow(e)} disabled={!dirty || saving}
                            data-testid={`timesheet-detail-entry-${e.id}-save`}
                            style={{ marginRight: 4, padding: '2px 8px', fontSize: 11 }}>
                      {saving ? '…' : 'Save'}
                    </button>
                    <button type="button" className="btn btn--ghost"
                            onClick={() => deleteRow(e)} disabled={saving}
                            data-testid={`timesheet-detail-entry-${e.id}-delete`}
                            style={{ padding: '2px 8px', fontSize: 11, color: '#dc2626' }}>
                      ×
                    </button>
                  </td>
                )}
              </tr>
            );
          })}
        </tbody>
      </table>

      {canEditRows && (
        <AddEntryRow
          timesheet={ts}
          placements={placements}
          defaultPlacementId={placementId ? parseInt(placementId, 10) : null}
          onSaved={() => reload()}
        />
      )}
    </section>
  );
}

function AddEntryRow({ timesheet, placements, defaultPlacementId, onSaved }) {
  const [open, setOpen]   = useState(false);
  const [busy, setBusy]   = useState(false);
  const [error, setError] = useState(null);
  const [form, setForm]   = useState({
    placement_id: defaultPlacementId || placements[0]?.id || '',
    work_date:    timesheet.period_start,
    hour_type:    'regular',
    hours:        '0',
    description:  '',
  });

  // Re-seed placement when the list loads asynchronously.
  useEffect(() => {
    if (!form.placement_id && placements[0]?.id) {
      setForm(f => ({ ...f, placement_id: placements[0].id }));
    }
  }, [placements.length]);

  const submit = async () => {
    setBusy(true); setError(null);
    try {
      await api.post('/modules/staffing/api/timesheets.php?action=entry_save', {
        timesheet_id: timesheet.id,
        placement_id: parseInt(form.placement_id, 10),
        work_date:    form.work_date,
        hour_type:    form.hour_type,
        hours:        parseFloat(form.hours) || 0,
        description:  form.description || '',
      });
      setOpen(false);
      setForm(f => ({ ...f, hours: '0', description: '' }));
      onSaved();
    } catch (e) { setError(e.message); }
    finally { setBusy(false); }
  };

  if (!open) {
    return (
      <button type="button" className="btn btn--ghost"
              onClick={() => setOpen(true)}
              data-testid="timesheet-detail-add-entry-open"
              style={{ marginTop: 12 }}>
        + Add entry
      </button>
    );
  }

  return (
    <div data-testid="timesheet-detail-add-entry-form"
         style={{ marginTop: 12, padding: 12, background: '#f8fafc', borderRadius: 6, border: '1px solid #e2e8f0' }}>
      <h4 style={{ margin: '0 0 8px', fontSize: 13 }}>Add a new entry</h4>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))', gap: 8 }}>
        <label style={{ fontSize: 11 }}>Placement
          <select className="input" value={form.placement_id}
                  onChange={e => setForm(f => ({ ...f, placement_id: e.target.value }))}
                  data-testid="timesheet-detail-add-entry-placement"
                  style={{ width: '100%', fontSize: 12 }}>
            {placements.length === 0 && <option value="">(no active placements)</option>}
            {placements.map(p => (
              <option key={p.id} value={p.id}>
                {p.title || `Placement #${p.id}`}{p.end_client_name ? ` · ${p.end_client_name}` : ''}
              </option>
            ))}
          </select>
        </label>
        <label style={{ fontSize: 11 }}>Date
          <input type="date" className="input" value={form.work_date}
                 min={timesheet.period_start} max={timesheet.period_end}
                 onChange={e => setForm(f => ({ ...f, work_date: e.target.value }))}
                 data-testid="timesheet-detail-add-entry-date"
                 style={{ width: '100%', fontSize: 12 }} />
        </label>
        <label style={{ fontSize: 11 }}>Hour type
          <select className="input" value={form.hour_type}
                  onChange={e => setForm(f => ({ ...f, hour_type: e.target.value }))}
                  data-testid="timesheet-detail-add-entry-hour-type"
                  style={{ width: '100%', fontSize: 12 }}>
            {HOUR_TYPES.map(t => <option key={t.v} value={t.v}>{t.l}</option>)}
          </select>
        </label>
        <label style={{ fontSize: 11 }}>Hours
          <input type="number" step="0.25" min="0" max="24" className="input"
                 value={form.hours}
                 onChange={e => setForm(f => ({ ...f, hours: e.target.value }))}
                 data-testid="timesheet-detail-add-entry-hours"
                 style={{ width: '100%', fontSize: 12 }} />
        </label>
        <label style={{ fontSize: 11, gridColumn: '1 / -1' }}>Description (optional)
          <input type="text" className="input" value={form.description}
                 onChange={e => setForm(f => ({ ...f, description: e.target.value }))}
                 data-testid="timesheet-detail-add-entry-description"
                 style={{ width: '100%', fontSize: 12 }} />
        </label>
      </div>
      {error && <p className="error" data-testid="timesheet-detail-add-entry-error">{error}</p>}
      <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
        <button type="button" className="btn btn--primary" disabled={busy || !form.placement_id || !form.work_date}
                onClick={submit}
                data-testid="timesheet-detail-add-entry-save">
          {busy ? 'Saving…' : 'Save entry'}
        </button>
        <button type="button" className="btn btn--ghost" disabled={busy}
                onClick={() => setOpen(false)}
                data-testid="timesheet-detail-add-entry-cancel">
          Cancel
        </button>
      </div>
    </div>
  );
}

function LiveCell({ label, value, testId, emphasis, color }) {
  return (
    <div data-testid={testId}>
      <div style={{ fontSize: 10, textTransform: 'uppercase', color: '#94a3b8', letterSpacing: '0.05em', fontWeight: 600 }}>
        {label}
      </div>
      <div style={{
        fontSize: emphasis ? 22 : 16,
        fontWeight: emphasis ? 700 : 600,
        color: color || (emphasis ? '#0f172a' : '#334155'),
        marginTop: 2,
      }}>
        {value}
      </div>
    </div>
  );
}

function StatusBadge({ status }) {
  const colors = {
    draft:         { bg: '#e0e7ff', fg: '#3730a3' },
    submitted:     { bg: '#fef3c7', fg: '#92400e' },
    approved:      { bg: '#dcfce7', fg: '#166534' },
    rejected:      { bg: '#fee2e2', fg: '#991b1b' },
    locked:        { bg: '#e5e7eb', fg: '#374151' },
    payroll_ready: { bg: '#cffafe', fg: '#155e75' },
    billing_ready: { bg: '#fae8ff', fg: '#86198f' },
  };
  const c = colors[status] || { bg: '#e5e7eb', fg: '#374151' };
  return (
    <span style={{
      display: 'inline-block', padding: '2px 8px', borderRadius: 999,
      background: c.bg, color: c.fg, fontSize: 11, fontWeight: 600,
    }} data-testid={`timesheet-status-${status}`}>{status}</span>
  );
}

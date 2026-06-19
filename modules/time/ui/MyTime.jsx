import React, { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';

/**
 * My Time — weekly grid (paper-timesheet mental model) per SPEC decision 15.
 * Shows days of week × placements; totals row at bottom.
 */
const CATS = [
  'regular_billable','regular_nonbillable','OT_billable','OT_nonbillable',
  'holiday','vacation','sick','bereavement','unpaid_leave',
];

function startOfWeek(d) {
  const x = new Date(d); const dow = x.getDay() || 7; x.setDate(x.getDate() - (dow - 1)); return x;
}
function toISO(d) { return d.toISOString().slice(0, 10); }
function addDays(d, n) { const x = new Date(d); x.setDate(x.getDate() + n); return x; }

export default function MyTime() {
  const [monday, setMonday] = useState(startOfWeek(new Date()));
  const weekDays = Array.from({ length: 7 }, (_, i) => addDays(monday, i));
  const from = toISO(weekDays[0]);
  const to   = toISO(weekDays[6]);

  const entriesPath = `/api/v1/time/entries?work_date_from=${from}&work_date_to=${to}&per_page=500`;
  const { data, loading, error, reload } = useApi(entriesPath);
  const entries = data?.rows ?? [];

  // Group by placement_id + work_date
  const byPlacement = {};
  for (const e of entries) {
    const key = e.placement_id;
    if (!byPlacement[key]) byPlacement[key] = { title: e.placement_title, client: e.end_client_name, days: {} };
    const d = e.work_date;
    byPlacement[key].days[d] = byPlacement[key].days[d] || [];
    byPlacement[key].days[d].push(e);
  }

  const [quickAdd, setQuickAdd] = useState({ open: false, placementId: null, workDate: null });
  const [qaForm, setQaForm] = useState({ category: 'regular_billable', hours: '', description: '' });
  const [savingQa, setSavingQa] = useState(false);
  const [qaError, setQaError] = useState(null);

  const placementsList = useApi('/modules/placements/api/placements.php?status=active&per_page=200');

  const addEntry = async (e) => {
    e.preventDefault();
    if (!quickAdd.placementId) return;
    setSavingQa(true); setQaError(null);
    try {
      await api.post('/api/v1/time/entries', {
        placement_id: quickAdd.placementId,
        work_date:    quickAdd.workDate,
        category:     qaForm.category,
        hours:        parseFloat(qaForm.hours),
        description:  qaForm.description || null,
      });
      setQuickAdd({ open: false, placementId: null, workDate: null });
      setQaForm({ category: 'regular_billable', hours: '', description: '' });
      reload();
    } catch (e) { setQaError(e); }
    finally     { setSavingQa(false); }
  };

  const dayTotal = (placementId, date) => (byPlacement[placementId]?.days[date] ?? []).reduce((s, e) => s + parseFloat(e.hours), 0);

  // Operator complaint: "can't submit a single timesheet, only all at
  // once." The API already supports per-entry submit
  // (`/api/v1/time/entries?action=submit&id=N`) — we just
  // never surfaced it here. Now we list every draft this week with a
  // per-row Submit button, plus a single "Submit all N drafts" CTA
  // that loops through them (parallel, all-or-nothing-friendly).
  const drafts = entries.filter(e => e.status === 'draft');
  const [submitBusy, setSubmitBusy] = useState(null); // entry_id while one is in flight, 'all' for the bulk loop
  const submitOne = async (entryId) => {
    setSubmitBusy(entryId);
    try {
      await api.post(`/api/v1/time/entries?action=submit&id=${entryId}`, {});
      reload();
    } catch (e) { alert(`Submit failed: ${e.message}`); }
    finally     { setSubmitBusy(null); }
  };
  const submitAll = async () => {
    if (drafts.length === 0) return;
    if (!confirm(`Submit all ${drafts.length} draft entr${drafts.length === 1 ? 'y' : 'ies'} for review?`)) return;
    setSubmitBusy('all');
    try {
      // Parallel — backend is idempotent on already-submitted rows
      // (returns 409) so a partial failure just leaves the rest queued.
      const results = await Promise.allSettled(
        drafts.map(e => api.post(`/api/v1/time/entries?action=submit&id=${e.id}`, {}))
      );
      const failed = results.filter(r => r.status === 'rejected').length;
      if (failed > 0) alert(`${results.length - failed} submitted, ${failed} failed. Reload to see which.`);
      reload();
    } finally { setSubmitBusy(null); }
  };

  return (
    <section className="people-directory" data-testid="time-my-time">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-4)' }}>
        <div>
          <h2>My Time</h2>
          <p style={{ color: 'var(--cf-text-secondary)' }} data-testid="time-week-label">
            Week of {from} → {to}
          </p>
        </div>
        <div style={{ display: 'flex', gap: 'var(--cf-space-2)', flexWrap: 'wrap' }}>
          <Link to="/modules/time/upload"     className="btn" data-testid="time-my-time-upload-link">↑ Upload timesheet</Link>
          <Link to="/modules/time/bulk"       className="btn" data-testid="time-my-time-csv-import-link">Import CSV</Link>
          <button className="btn" onClick={() => setMonday(addDays(monday, -7))} data-testid="time-week-prev">← Prev</button>
          <button className="btn" onClick={() => setMonday(startOfWeek(new Date()))} data-testid="time-week-this">This week</button>
          <button className="btn" onClick={() => setMonday(addDays(monday,  7))} data-testid="time-week-next">Next →</button>
        </div>
      </header>

      {loading && <p>Loading…</p>}
      {error && <p className="error" data-testid="time-error">Error: {error.message}</p>}

      {/* Drafts panel — surfaces per-entry Submit so users don't have
          to push all their hours in one swing. */}
      {drafts.length > 0 && (
        <section
          data-testid="time-drafts-panel"
          style={{
            margin: '0 0 var(--cf-space-3)',
            padding: 'var(--cf-space-3)',
            background: '#fffbeb',
            border: '1px solid #fcd34d',
            borderRadius: 6,
          }}
        >
          <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-2)' }}>
            <strong data-testid="time-drafts-count">{drafts.length} draft entr{drafts.length === 1 ? 'y' : 'ies'} this week</strong>
            <button
              className="btn btn--primary"
              disabled={submitBusy !== null}
              onClick={submitAll}
              data-testid="time-drafts-submit-all"
              title="Submit every draft in this week for review. Per-row Submit also available below."
            >
              {submitBusy === 'all' ? 'Submitting…' : `Submit all ${drafts.length}`}
            </button>
          </header>
          <table className="data-table" data-testid="time-drafts-table" style={{ marginTop: 4 }}>
            <thead>
              <tr><th>Date</th><th>Placement</th><th>Person</th><th>Category</th><th style={{ textAlign: 'right' }}>Hours</th><th></th></tr>
            </thead>
            <tbody>
              {drafts.map(e => (
                <tr key={e.id} data-testid={`time-draft-row-${e.id}`}>
                  <td>{e.work_date}</td>
                  <td>{e.placement_title || `Placement #${e.placement_id}`}{e.end_client_name ? ` · ${e.end_client_name}` : ''}</td>
                  <td>{e.first_name || e.last_name ? `${e.first_name || ''} ${e.last_name || ''}`.trim() : (e.person_id ? `Person #${e.person_id}` : '—')}</td>
                  <td>{e.category}</td>
                  <td style={{ textAlign: 'right' }}>{parseFloat(e.hours).toFixed(2)}</td>
                  <td style={{ textAlign: 'right' }}>
                    <button
                      className="btn"
                      disabled={submitBusy !== null}
                      onClick={() => submitOne(e.id)}
                      data-testid={`time-draft-submit-${e.id}`}
                      title="Submit just this entry for review."
                    >
                      {submitBusy === e.id ? '…' : 'Submit'}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      )}

      <table className="data-table" data-testid="time-grid">
        <thead>
          <tr>
            <th>Placement</th>
            {weekDays.map((d, i) => (
              <th key={i} data-testid={`time-grid-day-${toISO(d)}`}>{d.toLocaleDateString(undefined, { weekday: 'short' })}<br /><span style={{ fontWeight: 'normal', fontSize: '0.85em', color: 'var(--cf-text-muted)' }}>{toISO(d).slice(5)}</span></th>
            ))}
            <th>Total</th>
          </tr>
        </thead>
        <tbody>
          {Object.keys(byPlacement).length === 0 && (
            <tr><td colSpan={9} className="empty" data-testid="time-grid-empty">
              No entries this week. Add placements below or use Review Queue.
            </td></tr>
          )}
          {Object.entries(byPlacement).map(([pid, info]) => {
            const total = weekDays.reduce((s, d) => s + dayTotal(pid, toISO(d)), 0);
            return (
              <tr key={pid} data-testid={`time-grid-row-${pid}`}>
                <td>
                  <strong>{info.title}</strong>
                  {info.client && <div style={{ fontSize: '0.85em', color: 'var(--cf-text-secondary)' }}>{info.client}</div>}
                </td>
                {weekDays.map((d, i) => {
                  const iso = toISO(d);
                  const h = dayTotal(pid, iso);
                  return (
                    <td key={i} data-testid={`time-grid-cell-${pid}-${iso}`}
                        onClick={() => setQuickAdd({ open: true, placementId: parseInt(pid, 10), workDate: iso })}
                        style={{ cursor: 'pointer', textAlign: 'center' }}>
                      {h > 0 ? <strong>{h.toFixed(2)}</strong> : <span style={{ color: 'var(--cf-text-muted)' }}>—</span>}
                    </td>
                  );
                })}
                <td style={{ textAlign: 'right', fontWeight: 600 }} data-testid={`time-grid-row-total-${pid}`}>{total.toFixed(2)}</td>
              </tr>
            );
          })}
        </tbody>
      </table>

      <div style={{ marginTop: 'var(--cf-space-4)' }}>
        <h3>Add entry</h3>
        <form onSubmit={addEntry} style={{ display: 'flex', gap: 'var(--cf-space-2)', flexWrap: 'wrap' }} data-testid="time-add-form">
          <select className="input" value={quickAdd.placementId || ''}
                  onChange={e => setQuickAdd({ ...quickAdd, open: true, placementId: parseInt(e.target.value, 10) })}
                  data-testid="time-add-placement" required>
            <option value="">— placement —</option>
            {(placementsList.data?.rows ?? []).map(p => (
              <option key={p.id} value={p.id}>{p.title} ({p.end_client_name || '—'})</option>
            ))}
          </select>
          <input className="input" type="date" value={quickAdd.workDate || toISO(new Date())}
                 onChange={e => setQuickAdd({ ...quickAdd, open: true, workDate: e.target.value })}
                 data-testid="time-add-date" required />
          <select className="input" value={qaForm.category} onChange={e => setQaForm({ ...qaForm, category: e.target.value })} data-testid="time-add-category">
            {CATS.map(c => <option key={c} value={c}>{c}</option>)}
          </select>
          <input className="input" type="number" step="0.25" placeholder="Hours" value={qaForm.hours}
                 onChange={e => setQaForm({ ...qaForm, hours: e.target.value })} data-testid="time-add-hours" required style={{ width: '100px' }} />
          <input className="input" placeholder="Description (optional)" value={qaForm.description}
                 onChange={e => setQaForm({ ...qaForm, description: e.target.value })} data-testid="time-add-description" style={{ flex: 1 }} />
          <button className="btn btn--primary" disabled={savingQa || !quickAdd.placementId || !qaForm.hours} data-testid="time-add-btn">
            {savingQa ? '…' : 'Save draft'}
          </button>
        </form>
        {qaError && <p className="error" data-testid="time-add-error">Error: {qaError.message}</p>}
      </div>
    </section>
  );
}

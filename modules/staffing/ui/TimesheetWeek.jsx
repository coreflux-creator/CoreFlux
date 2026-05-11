import React, { useEffect, useMemo, useRef, useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';

/**
 * Weekly Timesheet — inline-editable grid.
 *
 * One submission = one week. Rows = placements, columns = 7 days.
 * Each cell is editable; type a number, tab to next day. The whole week
 * autosaves as a single draft and ships with one "Submit Week" click.
 *
 * Per-cell hour-type split: click "+" inside a cell to break that day
 * into multiple hour_type rows (e.g. 8h regular + 2h overtime).
 *
 * Spec: §10.6 (Timesheet header) + §10.7 (Timesheet rows) + §11.1 workflow.
 */
const HOUR_TYPES = [
  { v: 'regular',     l: 'Regular',     billable: true,  className: 'ht-reg' },
  { v: 'overtime',    l: 'Overtime',    billable: true,  className: 'ht-ot'  },
  { v: 'doubletime',  l: 'Double',      billable: true,  className: 'ht-dt'  },
  { v: 'holiday',     l: 'Holiday',     billable: false, className: 'ht-hol' },
  { v: 'pto',         l: 'PTO',         billable: false, className: 'ht-pto' },
  { v: 'sick',        l: 'Sick',        billable: false, className: 'ht-sick'},
  { v: 'unpaid',      l: 'Unpaid',      billable: false, className: 'ht-unp' },
  { v: 'nonbillable', l: 'Non-billable',billable: false, className: 'ht-nb'  },
];

const HOUR_TYPE_LABEL = Object.fromEntries(HOUR_TYPES.map(t => [t.v, t.l]));

function toISO(d) { return d.toISOString().slice(0, 10); }
function addDays(d, n) { const x = new Date(d); x.setDate(x.getDate() + n); return x; }
function weekStartOf(date, startsOn /* 0=Sun, 1=Mon */) {
  const d = new Date(date);
  const dow = d.getDay(); // 0..6, Sun=0
  let diff = (dow - startsOn + 7) % 7;
  d.setDate(d.getDate() - diff);
  d.setHours(0, 0, 0, 0);
  return d;
}

export default function TimesheetWeek({ session }) {
  // ── session resolves person_id for "My Time" mode ──
  const personId = session?.user?.person_id || session?.user?.id || 1;

  // Pull settings first so we can compute the right week-start.
  const settingsApi = useApi('/modules/staffing/api/timesheets.php?action=settings');
  const weekStartsOn = settingsApi.data?.settings?.week_starts_on ?? 1;
  const contracted   = settingsApi.data?.settings?.contracted_hours_per_week ?? 40;

  const [anchor, setAnchor] = useState(new Date());
  const weekStart = useMemo(() => weekStartOf(anchor, weekStartsOn), [anchor, weekStartsOn]);
  const periodStart = toISO(weekStart);
  const periodEnd   = toISO(addDays(weekStart, 6));
  const days        = useMemo(() => Array.from({ length: 7 }, (_, i) => addDays(weekStart, i)), [weekStart]);

  // ── fetch the week ──
  const weekPath = `/modules/staffing/api/timesheets.php?action=week&person_id=${personId}&period_start=${periodStart}&period_end=${periodEnd}`;
  const { data, loading, error, reload } = useApi(weekPath, [weekPath]);

  const placementsApi = useApi('/modules/placements/api/placements.php?status=active&per_page=200');
  const activePlacements = placementsApi.data?.rows ?? [];

  const header  = data?.timesheet;
  const entries = data?.entries ?? [];

  // ── local editable grid state ──
  //   grid[placementId][workDate] = [ { id, hour_type, hours, description } ]
  const [grid, setGrid]       = useState({});
  const [dirty, setDirty]     = useState(false);
  const [saveState, setSave]  = useState({ saving: false, lastSavedAt: null, error: null });

  useEffect(() => {
    if (!entries) return;
    const next = {};
    for (const e of entries) {
      next[e.placement_id] = next[e.placement_id] || {};
      next[e.placement_id][e.work_date] = next[e.placement_id][e.work_date] || [];
      next[e.placement_id][e.work_date].push({
        id: e.id, hour_type: e.hour_type || 'regular', hours: parseFloat(e.hours) || 0,
        description: e.description || '', client_name: e.client_name,
      });
    }
    setGrid(next);
    setDirty(false);
  }, [data?.timesheet?.id, periodStart]);

  const rowKeys = useMemo(() => {
    // Union of placements with existing entries + all active placements.
    const ids = new Set(Object.keys(grid));
    for (const p of activePlacements) ids.add(String(p.id));
    return Array.from(ids);
  }, [grid, activePlacements]);

  const placementInfo = (pid) => {
    const fromActive = activePlacements.find(p => String(p.id) === String(pid));
    if (fromActive) return { title: fromActive.title, client: fromActive.end_client_name || fromActive.client_name || '' };
    const first = entries.find(e => String(e.placement_id) === String(pid));
    return { title: first?.placement_title || `Placement #${pid}`, client: first?.client_name || '' };
  };

  // ── editing handlers ──
  const setCellHours = (pid, date, idx, hours) => {
    setGrid(prev => {
      const next = { ...prev };
      next[pid] = { ...(next[pid] || {}) };
      const cell = [...((next[pid][date] || [{ hour_type: 'regular', hours: 0 }]))];
      cell[idx] = { ...cell[idx], hours };
      next[pid][date] = cell;
      return next;
    });
    setDirty(true);
  };

  const setCellHourType = (pid, date, idx, hour_type) => {
    setGrid(prev => {
      const next = { ...prev };
      next[pid] = { ...(next[pid] || {}) };
      const cell = [...((next[pid][date] || [{ hour_type: 'regular', hours: 0 }]))];
      cell[idx] = { ...cell[idx], hour_type };
      next[pid][date] = cell;
      return next;
    });
    setDirty(true);
  };

  const splitCell = (pid, date) => {
    setGrid(prev => {
      const next = { ...prev };
      next[pid] = { ...(next[pid] || {}) };
      const cell = [...((next[pid][date] || [{ hour_type: 'regular', hours: 0 }]))];
      cell.push({ hour_type: 'overtime', hours: 0 });
      next[pid][date] = cell;
      return next;
    });
    setDirty(true);
  };

  const removeSplit = (pid, date, idx) => {
    setGrid(prev => {
      const next = { ...prev };
      next[pid] = { ...(next[pid] || {}) };
      const cell = [...((next[pid][date] || []))];
      cell.splice(idx, 1);
      if (cell.length === 0) delete next[pid][date]; else next[pid][date] = cell;
      return next;
    });
    setDirty(true);
  };

  // ── save (debounced autosave) ──
  const saveTimer = useRef(null);
  useEffect(() => {
    if (!dirty) return;
    clearTimeout(saveTimer.current);
    saveTimer.current = setTimeout(() => doSave(false), 1500);
    return () => clearTimeout(saveTimer.current);
  }, [grid, dirty]);

  const doSave = async (forceFlush) => {
    if (header && ['approved','locked','payroll_ready'].includes(header.status)) return;
    setSave(s => ({ ...s, saving: true, error: null }));
    const rows = [];
    for (const pid of Object.keys(grid)) {
      for (const date of Object.keys(grid[pid] || {})) {
        const cells = grid[pid][date] || [];
        for (const c of cells) {
          rows.push({
            id: c.id || null,
            placement_id: parseInt(pid, 10),
            work_date: date,
            hour_type: c.hour_type,
            hours: parseFloat(c.hours) || 0,
            description: c.description || null,
          });
        }
      }
    }
    try {
      await api.post('/modules/staffing/api/timesheets.php?action=bulk_save', {
        person_id: personId, period_start: periodStart, period_end: periodEnd, rows,
      });
      setSave({ saving: false, lastSavedAt: new Date(), error: null });
      setDirty(false);
      if (forceFlush) reload();
    } catch (e) {
      setSave(s => ({ ...s, saving: false, error: e.message || String(e) }));
    }
  };

  const submitWeek = async () => {
    if (dirty) await doSave(false);
    try {
      await api.post('/modules/staffing/api/timesheets.php?action=submit', {
        person_id: personId, period_start: periodStart, period_end: periodEnd,
      });
      reload();
    } catch (e) {
      setSave(s => ({ ...s, error: e.message || String(e) }));
    }
  };

  // ── derived ──
  const dayTotal = (pid, iso) => {
    const cells = grid[pid]?.[iso] || [];
    return cells.reduce((s, c) => s + (parseFloat(c.hours) || 0), 0);
  };
  const rowTotal = (pid) => days.reduce((s, d) => s + dayTotal(pid, toISO(d)), 0);
  const weekTotal = rowKeys.reduce((s, pid) => s + rowTotal(pid), 0);

  const isLocked = header && ['submitted','approved','locked','payroll_ready'].includes(header.status);
  const overContracted = weekTotal - contracted;

  return (
    <section className="people-directory" data-testid="staffing-timesheet-week">
      <header style={{ display:'flex', justifyContent:'space-between', alignItems:'flex-start', marginBottom: 'var(--cf-space-4)', flexWrap:'wrap', gap:'var(--cf-space-3)' }}>
        <div>
          <h2 style={{ marginBottom: 4 }}>Weekly Timesheet</h2>
          <div style={{ color: 'var(--cf-text-secondary)' }} data-testid="ts-week-label">
            Week of {periodStart} → {periodEnd}
            {header && <StatusPill status={header.status} />}
          </div>
        </div>
        <div style={{ display: 'flex', gap: 'var(--cf-space-2)', flexWrap:'wrap', alignItems:'center' }}>
          <button className="btn" onClick={() => setAnchor(addDays(weekStart, -7))} data-testid="ts-prev"     disabled={loading}>← Prev</button>
          <button className="btn" onClick={() => setAnchor(new Date())}             data-testid="ts-current"  disabled={loading}>This week</button>
          <button className="btn" onClick={() => setAnchor(addDays(weekStart,  7))} data-testid="ts-next"     disabled={loading}>Next →</button>
          <button className="btn btn--primary" onClick={submitWeek} disabled={isLocked || saveState.saving} data-testid="ts-submit-week">
            {header?.status === 'rejected' ? 'Re-submit Week' : 'Submit Week'}
          </button>
        </div>
      </header>

      <SaveBar saveState={saveState} dirty={dirty} isLocked={isLocked} headerStatus={header?.status} />
      {error && <p className="error" data-testid="ts-error">Error: {error.message}</p>}

      <div style={{ overflowX: 'auto' }}>
      <table className="data-table" data-testid="ts-grid" style={{ minWidth: 900 }}>
        <thead>
          <tr>
            <th style={{ width: 220, textAlign:'left' }}>Placement</th>
            {days.map((d, i) => (
              <th key={i} data-testid={`ts-day-${toISO(d)}`} style={{ textAlign:'center', minWidth: 84 }}>
                {d.toLocaleDateString(undefined, { weekday:'short' })}
                <br />
                <span style={{ fontWeight:'normal', fontSize:'0.8em', color:'var(--cf-text-muted)' }}>{toISO(d).slice(5)}</span>
              </th>
            ))}
            <th style={{ width: 80, textAlign:'right' }}>Total</th>
          </tr>
        </thead>
        <tbody>
          {loading && <tr><td colSpan={9} className="empty">Loading…</td></tr>}
          {!loading && rowKeys.length === 0 && (
            <tr><td colSpan={9} className="empty" data-testid="ts-empty">
              No active placements. Create one in <a href="/modules/staffing/placements">Placements</a> first.
            </td></tr>
          )}
          {rowKeys.map((pid) => {
            const info = placementInfo(pid);
            return (
              <tr key={pid} data-testid={`ts-row-${pid}`}>
                <td>
                  <strong>{info.title}</strong>
                  {info.client && <div style={{ fontSize:'0.8em', color:'var(--cf-text-secondary)' }}>{info.client}</div>}
                </td>
                {days.map((d, i) => {
                  const iso   = toISO(d);
                  const cells = grid[pid]?.[iso] || [{ hour_type: 'regular', hours: 0 }];
                  const tot   = dayTotal(pid, iso);
                  return (
                    <td key={i} data-testid={`ts-cell-${pid}-${iso}`} style={{ verticalAlign:'top', padding: 4 }}>
                      {cells.map((c, idx) => (
                        <CellEditor
                          key={idx}
                          cell={c}
                          isLocked={isLocked}
                          showRemove={cells.length > 1}
                          onHours={(v)    => setCellHours(pid, iso, idx, v)}
                          onType={(v)     => setCellHourType(pid, iso, idx, v)}
                          onRemove={()    => removeSplit(pid, iso, idx)}
                          dataTestId={`ts-cell-input-${pid}-${iso}-${idx}`}
                        />
                      ))}
                      {!isLocked && (
                        <button className="btn-link" style={{ fontSize: '0.75em', color:'var(--cf-text-muted)', padding: 0 }}
                                onClick={() => splitCell(pid, iso)}
                                data-testid={`ts-split-${pid}-${iso}`}>+ split</button>
                      )}
                      {tot > 0 && cells.length > 1 && (
                        <div style={{ fontSize:'0.75em', textAlign:'center', color:'var(--cf-text-muted)' }}>= {tot.toFixed(2)}</div>
                      )}
                    </td>
                  );
                })}
                <td style={{ textAlign:'right', fontWeight: 600 }} data-testid={`ts-row-total-${pid}`}>{rowTotal(pid).toFixed(2)}</td>
              </tr>
            );
          })}
        </tbody>
        <tfoot>
          <tr style={{ fontWeight: 600, background:'var(--cf-surface-subtle, #f9fafb)' }}>
            <td>Week total</td>
            <td colSpan={7} style={{ textAlign:'right' }}>
              {overContracted > 0 && (
                <span style={{ color:'var(--cf-warning, #d97706)', marginRight: 12 }} data-testid="ts-over-contracted">
                  +{overContracted.toFixed(2)}h over contracted {contracted}h
                </span>
              )}
              {overContracted < 0 && (
                <span style={{ color:'var(--cf-text-muted)', marginRight: 12 }} data-testid="ts-under-contracted">
                  {overContracted.toFixed(2)}h vs contracted {contracted}h
                </span>
              )}
            </td>
            <td style={{ textAlign:'right' }} data-testid="ts-week-total">{weekTotal.toFixed(2)}</td>
          </tr>
        </tfoot>
      </table>
      </div>

      {header?.status === 'rejected' && header.rejection_reason && (
        <div className="alert" style={{ marginTop: 'var(--cf-space-3)', padding: 12, borderLeft: '3px solid var(--cf-error, #dc2626)', background: '#fef2f2' }} data-testid="ts-rejection-banner">
          <strong>Rejected:</strong> {header.rejection_reason}
        </div>
      )}
    </section>
  );
}

function CellEditor({ cell, isLocked, showRemove, onHours, onType, onRemove, dataTestId }) {
  const ht = HOUR_TYPES.find(t => t.v === cell.hour_type) || HOUR_TYPES[0];
  return (
    <div style={{ display:'flex', alignItems:'center', gap: 2, marginBottom: 2 }}>
      <input
        type="number" step="0.25" min="0" max="24"
        value={cell.hours || ''}
        disabled={isLocked}
        onChange={(e) => onHours(e.target.value)}
        data-testid={dataTestId}
        style={{ width: '46px', textAlign:'right', fontSize:'0.9em', padding:'2px 4px', border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 3 }}
        placeholder="—"
      />
      <select
        value={cell.hour_type}
        disabled={isLocked}
        onChange={(e) => onType(e.target.value)}
        data-testid={`${dataTestId}-type`}
        style={{ fontSize:'0.7em', padding:'1px 2px', border:'1px solid var(--cf-border, #e5e7eb)', borderRadius: 3, background: ht.billable ? 'transparent' : 'var(--cf-surface-subtle, #f3f4f6)' }}
        title={ht.l}
      >
        {HOUR_TYPES.map(t => <option key={t.v} value={t.v}>{t.l}</option>)}
      </select>
      {showRemove && !isLocked && (
        <button onClick={onRemove} title="Remove split"
                style={{ background:'none', border:'none', cursor:'pointer', color:'var(--cf-text-muted)', padding:'0 2px', fontSize:'0.9em' }}
                data-testid={`${dataTestId}-remove`}>×</button>
      )}
    </div>
  );
}

function SaveBar({ saveState, dirty, isLocked, headerStatus }) {
  if (isLocked) {
    return <div style={{ padding: 8, background:'#f3f4f6', borderRadius:4, marginBottom:'var(--cf-space-3)', fontSize:'0.9em', color:'var(--cf-text-secondary)' }} data-testid="ts-save-bar">
      Timesheet is <strong>{headerStatus}</strong>. Cells are read-only.
    </div>;
  }
  let label = 'All changes saved';
  let color = 'var(--cf-text-muted)';
  if (saveState.saving) { label = 'Saving…'; color = 'var(--cf-text-secondary)'; }
  else if (dirty)       { label = 'Unsaved changes — autosaving…'; color = 'var(--cf-warning, #d97706)'; }
  else if (saveState.lastSavedAt) { label = `Saved ${saveState.lastSavedAt.toLocaleTimeString()}`; }
  return (
    <div style={{ padding: 6, fontSize:'0.85em', color, marginBottom: 'var(--cf-space-2)' }} data-testid="ts-save-bar">
      {label}
      {saveState.error && <span style={{ marginLeft: 12, color:'var(--cf-error, #dc2626)' }} data-testid="ts-save-error">Save error: {saveState.error}</span>}
    </div>
  );
}

function StatusPill({ status }) {
  const colors = {
    draft:       { bg:'#e0e7ff', fg:'#3730a3', label:'Draft' },
    submitted:   { bg:'#fef3c7', fg:'#92400e', label:'Submitted' },
    approved:    { bg:'#d1fae5', fg:'#065f46', label:'Approved' },
    rejected:    { bg:'#fee2e2', fg:'#991b1b', label:'Rejected' },
    locked:      { bg:'#e5e7eb', fg:'#374151', label:'Locked' },
    payroll_ready:{ bg:'#dbeafe', fg:'#1e40af', label:'Payroll Ready' },
    billing_ready:{ bg:'#dbeafe', fg:'#1e40af', label:'Billing Ready' },
  };
  const c = colors[status] || colors.draft;
  return (
    <span data-testid={`ts-status-${status}`} style={{
      display:'inline-block', marginLeft: 8, padding: '2px 8px', borderRadius: 999,
      fontSize:'0.75em', fontWeight:600, background: c.bg, color: c.fg,
    }}>{c.label}</span>
  );
}

import React, { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';
import { useBulkSelection } from '../../../dashboard/src/lib/useBulkSelection';
import PlacementPicker from '../../placements/ui/PlacementPicker';

const TARGETS = [
  {
    id: 'billing', label: 'Client billing', refLabel: 'Invoice ID',
    autoLabel: 'Create draft invoices', busyLabel: 'Creating invoices…',
    listHref: '/modules/billing/invoices', listLabel: 'View all invoices',
    helper: 'Creates reviewable draft invoices. Nothing is sent or posted.',
  },
  {
    id: 'ap', label: 'Vendor bills', refLabel: 'Vendor bill ID',
    autoLabel: 'Create draft vendor bills', busyLabel: 'Creating bills…',
    listHref: '/modules/ap/bills', listLabel: 'View all bills',
    helper: 'Creates reviewable draft vendor bills. Nothing is approved or paid.',
  },
  {
    id: 'payroll', label: 'Payroll', refLabel: 'Payroll run ID',
    autoLabel: 'Add to draft payroll runs', busyLabel: 'Updating payroll runs…',
    listHref: '/modules/payroll/runs', listLabel: 'View all payroll runs',
    helper: 'Adds approved hours to editable draft payroll runs. Nothing is submitted or paid.',
  },
];

const PAYROLL_SKIP_LABELS = {
  no_matching_employee: 'no matching employee record',
  no_payroll_profile_or_schedule: 'missing payroll profile or pay schedule',
  no_payroll_profile_or_cycle: 'missing payroll profile or pay schedule',
  no_editable_pay_period_for_work_date: 'no editable pay period covering the work date',
};

/**
 * Time Settlement — per-day extract engine for Billing / AP / Payroll.
 *
 * Each row in the table is ONE extractable day-block (placement × work_date)
 * containing all approved time entries for that day. Once extracted, the
 * day disappears from this list — its entries are stamped with the
 * target-specific ref and are never extracted again unless un-extracted.
 *
 * No period close required. Cycle defaults from the placement are shown
 * as advisory hints; user can extract any subset of approved days.
 */
export default function TimeSettlement() {
  const [target, setTarget] = useState('billing');
  return (
    <section data-testid="time-settlement">
      <header style={{ display: 'flex', alignItems: 'baseline', gap: 24, marginBottom: 16 }}>
        <h2 style={{ margin: 0, fontSize: 20 }}>Process approved time</h2>
        <p style={{ margin: 0, color: 'var(--cf-text-secondary)', fontSize: 12 }}>
          Move approved time into client invoices, vendor bills, or draft payroll runs.
        </p>
      </header>
      <nav style={{ display: 'flex', gap: 6, marginBottom: 16 }}>
        {TARGETS.map(t => (
          <button
            key={t.id}
            data-testid={`time-settlement-tab-${t.id}`}
            onClick={() => setTarget(t.id)}
            style={{
              padding: '6px 14px', borderRadius: 6, border: '1px solid var(--cf-border, #e5e7eb)',
              background: target === t.id ? 'var(--cf-text, #111827)' : 'transparent',
              color: target === t.id ? '#fff' : 'var(--cf-text-secondary)',
              fontSize: 13, cursor: 'pointer',
            }}
          >{t.label}</button>
        ))}
      </nav>
      <SettlementBoard key={target} target={target} />
    </section>
  );
}

function SettlementBoard({ target }) {
  const [from, setFrom] = useState('');
  const [to, setTo]     = useState('');
  const [placementId, setPlacementId] = useState('');
  const [targetRef, setTargetRef]     = useState('');
  const [busy, setBusy]               = useState(false);
  const [err, setErr]                 = useState(null);
  const [success, setSuccess]         = useState(null);
  const [resultLinks, setResultLinks] = useState([]);
  const [warning, setWarning]         = useState(null);

  // AI suggestions panel state.
  const [aiBusy, setAiBusy]         = useState(false);
  const [aiSugs, setAiSugs]         = useState(null);    // {suggestions, advisories, ai_used}
  const [aiErr, setAiErr]           = useState(null);

  const supportsAutoCreate = target === 'billing' || target === 'ap' || target === 'payroll';

  const qs = useMemo(() => {
    const p = new URLSearchParams({ target });
    if (from) p.set('from', from);
    if (to)   p.set('to',   to);
    if (placementId) p.set('placement_id', placementId);
    return p.toString();
  }, [target, from, to, placementId]);

  const { data, loading, error, reload } = useApi(`/modules/time/api/settlement.php?${qs}`);
  const blocks = useMemo(() => data?.blocks ?? [], [data?.blocks]);

  // Each "id" here is the day-block's flat list of entry ids — collected as
  // a single selection unit when the user ticks the day.
  const dayBlockIds = useMemo(
    () => blocks.filter(b => b.ready !== false).map(b => `${b.placement_id}:${b.work_date}`),
    [blocks]
  );
  const sel = useBulkSelection(dayBlockIds);
  const selectedBlocks = useMemo(() => blocks.filter(b => {
    const id = `${b.placement_id}:${b.work_date}`;
    return b.ready !== false && sel.has(id);
  }), [blocks, sel]);
  const selectedEntryIds = useMemo(() => {
    const ids = [];
    selectedBlocks.forEach(b => b.entries.forEach(e => ids.push(e.id)));
    return ids;
  }, [selectedBlocks]);

  const totalSelectedHours = useMemo(() =>
    selectedBlocks.reduce((sum, b) => sum + (Number(b.total_hours) || 0), 0),
    [selectedBlocks]
  );
  const blockedBlocks = useMemo(() => blocks.filter(b => b.ready === false), [blocks]);

  const targetMeta = TARGETS.find(t => t.id === target);

  const extract = async () => {
    if (!selectedEntryIds.length) return;
    if (!targetRef || Number(targetRef) <= 0) {
      setErr(new Error(`${targetMeta.refLabel} required (positive integer)`));
      return;
    }
    const selectedDays = selectedBlocks.length;
    setBusy(true); setErr(null); setSuccess(null); setResultLinks([]); setWarning(null);
    try {
      const res = await api.post(
        '/modules/time/api/settlement.php?action=extract',
        { entry_ids: selectedEntryIds, target, target_ref: Number(targetRef) }
      );
      setSuccess(`Linked ${res.extracted_count} entries across ${selectedDays} ${selectedDays === 1 ? 'day' : 'days'} to ${targetMeta.refLabel} #${targetRef}.`);
      sel.clear();
      setTargetRef('');
      reload();
    } catch (e) { setErr(e); }
    finally { setBusy(false); }
  };

  const autoExtract = async () => {
    if (!selectedEntryIds.length) return;
    if (!supportsAutoCreate) return;
    setBusy(true); setErr(null); setSuccess(null); setResultLinks([]); setWarning(null);
    try {
      const res = await api.post(
        '/modules/time/api/settlement.php?action=auto_extract',
        { entry_ids: selectedEntryIds, target }
      );
      const created = res.created || {};
      const links = [];
      const summaryParts = Object.entries(created).map(([key, info]) => {
        if (target === 'payroll') {
          const hours = `${Number(info.hours_regular || 0).toFixed(2)} regular + ${Number(info.hours_overtime || 0).toFixed(2)} OT hours`;
          links.push({
            href: `/modules/payroll/runs/${info.run_id}`,
            label: `Open payroll run #${info.run_id}`,
            key: `payroll-${info.run_id}`,
          });
          return `${info.employee_name || `Employee #${info.employee_id}`} → payroll run #${info.run_id} (${info.line_count} entries, ${hours})`;
        }
        if (target === 'billing') {
          links.push({
            href: `/modules/billing/invoices/${info.target_id}`,
            label: `Open invoice ${info.invoice_number || `#${info.target_id}`}`,
            key: `invoice-${info.target_id}`,
          });
        } else {
          (info.target_ids || [info.target_id]).forEach((id) => links.push({
            href: `/modules/ap/bills/${id}`,
            label: `Open bill #${id}`,
            key: `bill-${id}`,
          }));
        }
        return `Placement #${key} → ${info.kind} #${info.target_id} (${info.line_count} lines, ${info.currency} ${Number(info.total).toFixed(2)})`;
      });
      const destination = target === 'billing' ? 'draft invoice' : target === 'ap' ? 'draft bill' : 'employee/run group';
      setSuccess(`Created ${Object.keys(created).length} ${destination}${Object.keys(created).length === 1 ? '' : 's'}; ${res.extracted_count} entries were linked.\n${summaryParts.join('\n')}`);
      setResultLinks(Array.from(new Map(links.map(link => [link.key, link])).values()));
      const skipped = Array.isArray(res.skipped) ? res.skipped : [];
      if (skipped.length) {
        const grouped = skipped.reduce((acc, row) => {
          const reason = PAYROLL_SKIP_LABELS[row.reason] || row.reason || 'unknown setup issue';
          acc[reason] = (acc[reason] || 0) + 1;
          return acc;
        }, {});
        setWarning(`${skipped.length} ${skipped.length === 1 ? 'entry remains' : 'entries remain'} in Time Settlement: ${Object.entries(grouped).map(([reason, count]) => `${count} ${reason}`).join('; ')}.`);
      }
      sel.clear();
      reload();
    } catch (e) { setErr(e); }
    finally { setBusy(false); }
  };

  const fetchAiSuggestions = async () => {
    setAiBusy(true); setAiErr(null); setAiSugs(null);
    try {
      const res = await api.post(
        '/modules/time/api/settlement.php?action=ai_suggest',
        { target, from: from || undefined, to: to || undefined, placement_id: placementId ? Number(placementId) : undefined }
      );
      setAiSugs(res);
    } catch (e) { setAiErr(e); }
    finally { setAiBusy(false); }
  };

  const applySuggestion = (sug) => {
    // Map back from entry_ids → day-block keys (placement:work_date).
    const wantedEntryIds = new Set((sug.entry_ids || []).map(Number));
    const blockKeys = blocks
      .filter(b => b.ready !== false && b.entries.some(e => wantedEntryIds.has(e.id)))
      .map(b => `${b.placement_id}:${b.work_date}`);
    sel.selectMany(blockKeys);
  };

  return (
    <>
      <div style={{ display: 'flex', gap: 12, alignItems: 'flex-end', marginBottom: 12, flexWrap: 'wrap' }}>
        <label style={{ fontSize: 12 }}>
          From <input type="date" value={from} onChange={e => setFrom(e.target.value)} data-testid="time-settlement-from" style={inp} />
        </label>
        <label style={{ fontSize: 12 }}>
          To <input type="date" value={to} onChange={e => setTo(e.target.value)} data-testid="time-settlement-to" style={inp} />
        </label>
        <label style={{ fontSize: 12, minWidth: 300 }}>
          Placement
          <PlacementPicker
            value={placementId}
            onChange={(row) => setPlacementId(row?.id ? String(row.id) : '')}
            placeholder="Search person, role, client, or PL ID"
            testId="time-settlement-placement-filter"
          />
        </label>
        <button className="btn btn--ghost" onClick={() => reload()} data-testid="time-settlement-reload">Refresh</button>
        <button className="btn btn--ghost" onClick={fetchAiSuggestions} disabled={aiBusy}
                data-testid="time-settlement-ai-suggest"
                style={{ marginLeft: 'auto' }}>
          {aiBusy ? 'Finding batches…' : 'Suggest batches'}
        </button>
      </div>

      {aiErr && <p className="error" data-testid="time-settlement-ai-error">AI error: {aiErr.message}</p>}
      {aiSugs && (
        <div data-testid="time-settlement-ai-panel" style={aiPanel}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 8 }}>
            <strong style={{ fontSize: 13 }}>{aiSugs.ai_used ? 'AI suggestions' : 'Standard suggestions'}</strong>
            <span style={{ color: 'var(--cf-text-secondary)', fontSize: 12 }}>{aiSugs.suggestions?.length || 0} batches</span>
            <button className="btn btn--ghost" onClick={() => setAiSugs(null)} style={{ marginLeft: 'auto', fontSize: 11 }}
                    data-testid="time-settlement-ai-close">close</button>
          </div>
          {(aiSugs.advisories || []).map((ad, i) => (
            <p key={i} style={{ margin: '0 0 6px', fontSize: 12, color: 'var(--cf-text-secondary)' }}>· {ad}</p>
          ))}
          <div style={{ display: 'grid', gap: 8 }}>
            {(aiSugs.suggestions || []).map((sug, i) => (
              <div key={i} data-testid={`time-settlement-ai-suggestion-${i}`} style={sugCard}>
                <div style={{ flex: 1 }}>
                  <strong style={{ fontSize: 13 }}>{sug.name}</strong>
                  <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)', marginTop: 2 }}>{sug.reasoning}</div>
                  <div style={{ fontSize: 11, marginTop: 4 }}>
                    <span style={{ color: '#374151' }}>{sug.entry_ids?.length || 0} entries · {Number(sug.total_hours || 0).toFixed(2)}h</span>
                    {(sug.flags || []).map(f => (
                      <span key={f} className="badge" style={{ marginLeft: 6, background: '#fef3c7', color: '#92400e' }}>{f}</span>
                    ))}
                  </div>
                </div>
                <button className="btn btn--primary" onClick={() => applySuggestion(sug)}
                        data-testid={`time-settlement-ai-apply-${i}`}
                        style={{ fontSize: 12 }}>
                  Use this batch
                </button>
              </div>
            ))}
          </div>
        </div>
      )}

      {blockedBlocks.length > 0 && (
        <div className="alert alert--warning" data-testid="time-settlement-blocked-summary" style={{ marginBottom: 12 }}>
          <strong>{blockedBlocks.length} approved {blockedBlocks.length === 1 ? 'day needs' : 'days need'} placement setup.</strong>{' '}
          These rows remain visible below and cannot be sent onward until their placement setup is complete.
        </div>
      )}

      {selectedBlocks.length > 0 && (
        <div data-testid="time-settlement-bulk-bar" style={bulkBar}>
          <div style={{ minWidth: 220 }}>
            <strong>{selectedBlocks.length} {selectedBlocks.length === 1 ? 'day' : 'days'} selected</strong>
            <div style={{ color: 'var(--cf-text-secondary)', fontSize: 12, marginTop: 2 }}>
              {selectedEntryIds.length} entries · {totalSelectedHours.toFixed(2)}h total
            </div>
          </div>
          <div style={{ flex: 1, minWidth: 240, color: 'var(--cf-text-secondary)', fontSize: 12 }}>
            {targetMeta.helper}
          </div>
          {supportsAutoCreate && (
            <button className="btn btn--primary" onClick={autoExtract} disabled={busy}
                    data-testid="time-settlement-auto-extract">
              {busy ? targetMeta.busyLabel : targetMeta.autoLabel}
            </button>
          )}
          <button className="btn btn--ghost" onClick={sel.clear} data-testid="time-settlement-clear">Clear</button>
          <details style={advancedLinking} data-testid="time-settlement-existing-target">
            <summary style={{ cursor: 'pointer', fontSize: 12, color: 'var(--cf-text-secondary)' }}>
              Advanced: link to an existing record
            </summary>
            <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginTop: 8, flexWrap: 'wrap' }}>
              <input type="number" min="1" value={targetRef} onChange={e => setTargetRef(e.target.value)}
                     placeholder={targetMeta.refLabel} aria-label={targetMeta.refLabel}
                     data-testid="time-settlement-target-ref" style={{ ...inp, width: 180 }} />
              <button className="btn" onClick={extract} disabled={busy || !targetRef}
                      data-testid="time-settlement-extract">
                {busy ? 'Linking…' : 'Link selected time'}
              </button>
              <span style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>
                Use this only when the destination record already exists.
              </span>
            </div>
          </details>
        </div>
      )}
      {err && <p className="error" data-testid="time-settlement-error">Error: {err.message}</p>}
      {success && (
        <div data-testid="time-settlement-success" style={successStyle}>
          <div style={{ whiteSpace: 'pre-wrap' }}>{success}</div>
          <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', marginTop: 8 }}>
            {resultLinks.map(link => <Link key={link.key} to={link.href}>{link.label}</Link>)}
            <Link to={targetMeta.listHref}>{targetMeta.listLabel}</Link>
          </div>
        </div>
      )}
      {warning && (
        <div className="alert alert--warning" data-testid="time-settlement-warning" style={{ marginBottom: 12 }}>
          {warning}{' '}
          {target === 'payroll' && <Link to="/modules/payroll/profiles">Review payroll setup</Link>}
        </div>
      )}

      {loading && <p>Loading…</p>}
      {error && <p className="error">Load error: {error.message}</p>}

      <table className="data-table" data-testid="time-settlement-table">
        <thead>
          <tr>
            <th style={{ width: 32 }}>
              <input
                type="checkbox"
                checked={sel.allSelected}
                ref={el => { if (el) el.indeterminate = sel.someSelected; }}
                onChange={sel.toggleAll}
                disabled={!dayBlockIds.length}
                data-testid="time-settlement-select-all"
              />
            </th>
            <th>Day</th>
            <th>Person / placement</th>
            <th>Default schedule</th>
            <th>Scheduled window</th>
            <th>Entries</th>
            <th style={{ textAlign: 'right' }}>Hours</th>
            <th>Readiness</th>
          </tr>
        </thead>
        <tbody>
          {!loading && blocks.length === 0 && (
            <tr><td colSpan={8} className="empty" data-testid="time-settlement-empty">
              No approved time is ready for {targetMeta.label.toLowerCase()}.
            </td></tr>
          )}
          {blocks.map(b => {
            const id = `${b.placement_id}:${b.work_date}`;
            const blocked = b.ready === false;
            return (
              <tr key={id} data-testid={`time-settlement-day-${b.placement_id}-${b.work_date}`}
                  style={blocked ? blockedRow : (sel.has(id) ? { background: 'var(--cf-surface-alt, #f9fafb)' } : null)}>
                <td>
                  <input type="checkbox" checked={!blocked && sel.has(id)} onChange={() => sel.toggle(id)}
                         disabled={blocked}
                         data-testid={`time-settlement-select-${b.placement_id}-${b.work_date}`} />
                </td>
                <td>{b.work_date}</td>
                <td>
                  <strong style={{ display: 'block', fontSize: 13 }}>{b.person_name || b.person_email || `Person #${b.person_id}`}</strong>
                  <Link to={`/modules/placements/${b.placement_id}/economics`} style={{ fontSize: 12 }}>
                    {b.placement_title || `Placement #${b.placement_id}`}
                  </Link>
                  {b.end_client_name && (
                    <span style={{ display: 'block', color: 'var(--cf-text-secondary)', fontSize: 11 }}>{b.end_client_name}</span>
                  )}
                </td>
                <td><span className="badge">{scheduleLabel(b.cycle_default)}</span></td>
                <td style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>
                  {b.cycle_window?.label}
                  <br/>
                  <span style={{ fontSize: 11 }}>{b.cycle_window?.from} – {b.cycle_window?.to}</span>
                </td>
                <td>
                  {b.entries.map(e => (
                    <span key={e.id} className="badge" style={{ marginRight: 4 }}>
                      {e.category} · {e.hours}h
                    </span>
                  ))}
                </td>
                <td style={{ textAlign: 'right', fontWeight: 600 }}>{Number(b.total_hours).toFixed(2)}</td>
                <td style={{ minWidth: 220 }}>
                  {blocked ? (
                    <div data-testid={`time-settlement-blocked-${b.placement_id}-${b.work_date}`}>
                      <span className="badge" style={blockedBadge}>Setup needed</span>
                      <div style={{ marginTop: 5, color: '#92400e', fontSize: 11 }}>{b.blocked_reason}</div>
                      <Link to={`/modules/placements/${b.placement_id}/economics`} style={{ display: 'inline-block', marginTop: 4, fontSize: 11 }}>
                        Fix placement economics
                      </Link>
                    </div>
                  ) : (
                    <span className="badge" style={readyBadge}>Ready</span>
                  )}
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </>
  );
}

const inp = { padding: '4px 8px', borderRadius: 6, border: '1px solid var(--cf-border, #e5e7eb)', fontSize: 13 };
const bulkBar = {
  display: 'flex', alignItems: 'center', gap: 12, padding: '10px 14px',
  background: 'var(--cf-surface-alt, #f3f4f6)', borderRadius: 8,
  marginBottom: 12, flexWrap: 'wrap',
};
const successStyle = {
  background: '#ecfdf5', color: '#065f46', padding: 8, borderRadius: 6, fontSize: 13,
  whiteSpace: 'pre-wrap',
};
const aiPanel = {
  border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8, padding: 12,
  background: 'linear-gradient(180deg, #eff6ff 0%, #fff 100%)',
  marginBottom: 12,
};
const sugCard = {
  display: 'flex', alignItems: 'flex-start', gap: 12,
  padding: 10, background: '#fff', borderRadius: 6,
  border: '1px solid var(--cf-border, #e5e7eb)',
};
const advancedLinking = {
  flexBasis: '100%', paddingTop: 8, borderTop: '1px solid var(--cf-border, #e5e7eb)',
};
const blockedRow = { background: '#fffbeb' };
const blockedBadge = { background: '#fef3c7', color: '#92400e' };
const readyBadge = { background: '#ecfdf5', color: '#047857' };

function scheduleLabel(value) {
  if (!value) return 'Not set';
  return String(value)
    .replaceAll('_', ' ')
    .replace(/\b\w/g, char => char.toUpperCase());
}

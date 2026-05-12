import React, { useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';

/**
 * Staffing Readiness queue (Payroll OR Billing).
 *
 * Mode-driven so we don't duplicate the page. Payroll mode groups by
 * worker + cost. Billing mode groups by client + revenue.
 */
export default function StaffingReadiness({ mode = 'payroll' }) {
  const isPayroll = mode === 'payroll';
  const action    = isPayroll ? 'payroll' : 'billing';
  const markAction = isPayroll ? 'mark_payroll_pushed' : 'mark_billing_invoiced';

  const path = `/modules/staffing/api/readiness.php?action=${action}`;
  const { data, loading, error, reload } = useApi(path, [path]);
  const groups = data?.groups ?? [];

  const [selected, setSelected] = useState(new Set());
  const [busy, setBusy] = useState(false);

  const toggle = (id) => setSelected(prev => {
    const next = new Set(prev);
    if (next.has(id)) next.delete(id); else next.add(id);
    return next;
  });

  const markPushed = async () => {
    if (selected.size === 0) { alert('Select at least one row first'); return; }
    const ids = [];
    for (const g of groups) {
      const key = isPayroll ? g.person_id : g.client_id;
      if (selected.has(String(key))) ids.push(...(g.timesheet_ids || []));
    }
    if (!ids.length) return;
    if (!confirm(`Mark ${ids.length} timesheet${ids.length === 1 ? '' : 's'} as ${isPayroll ? 'payroll-pushed' : 'invoiced'}?`)) return;
    setBusy(true);
    try {
      await api.post(`/modules/staffing/api/readiness.php?action=${markAction}`, { timesheet_ids: ids });
      setSelected(new Set());
      reload();
    } catch (e) { alert(e.message); }
    finally { setBusy(false); }
  };

  const totalHours   = groups.reduce((s, g) => s + (parseFloat(g.hours) || 0), 0);
  const totalRevenue = groups.reduce((s, g) => s + (parseFloat(g.revenue) || 0), 0);

  return (
    <section className="people-directory" data-testid={`staffing-readiness-${mode}`}>
      <header style={{ display:'flex', justifyContent:'space-between', alignItems:'flex-start', marginBottom:'var(--cf-space-3)', flexWrap:'wrap', gap:'var(--cf-space-3)' }}>
        <div>
          <h2>{isPayroll ? 'Payroll Readiness' : 'Billing Readiness'}</h2>
          <p style={{ color:'var(--cf-text-secondary)' }}>
            {isPayroll
              ? 'Approved timesheets ready to be pushed to payroll.'
              : 'Approved hours ready to be turned into client invoices.'}
          </p>
        </div>
        <button className="btn btn--primary" disabled={busy || selected.size === 0}
                onClick={markPushed}
                data-testid={`staffing-readiness-${mode}-mark`}>
          {busy ? 'Working…' : isPayroll ? `Mark ${selected.size || ''} pushed to payroll`.trim() : `Mark ${selected.size || ''} invoiced`.trim()}
        </button>
      </header>

      {loading && <p>Loading…</p>}
      {error && <p className="error">Error: {error.message}</p>}
      {!loading && groups.length === 0 && (
        <p className="empty" data-testid={`staffing-readiness-${mode}-empty`}>
          Nothing waiting — every approved timesheet has been {isPayroll ? 'pushed to payroll' : 'invoiced'}.
        </p>
      )}

      {groups.length > 0 && (
        <>
          <div style={{ display:'flex', gap:'var(--cf-space-4)', marginBottom:'var(--cf-space-3)', padding:12, background:'var(--cf-surface-subtle, #f9fafb)', borderRadius: 6 }} data-testid={`staffing-readiness-${mode}-summary`}>
            <Metric label="Groups"  value={groups.length} />
            <Metric label="Hours"   value={totalHours.toFixed(1)} />
            {!isPayroll && <Metric label="Revenue" value={`$${totalRevenue.toLocaleString(undefined, { maximumFractionDigits: 0 })}`} emphasis="good" />}
          </div>

          <table className="data-table" data-testid={`staffing-readiness-${mode}-table`}>
            <thead>
              <tr>
                <th style={{ width: 32 }}>
                  <input type="checkbox"
                         checked={selected.size === groups.length && groups.length > 0}
                         onChange={(e) => setSelected(e.target.checked ? new Set(groups.map(g => String(isPayroll ? g.person_id : g.client_id))) : new Set())}
                         data-testid={`staffing-readiness-${mode}-select-all`} />
                </th>
                <th>{isPayroll ? 'Worker' : 'Client'}</th>
                <th>Hours</th>
                {!isPayroll && <th>Revenue</th>}
                <th>Timesheets</th>
                <th>Periods</th>
              </tr>
            </thead>
            <tbody>
              {groups.map(g => {
                const key = String(isPayroll ? g.person_id : g.client_id);
                return (
                  <tr key={key} data-testid={`staffing-readiness-${mode}-row-${key}`}>
                    <td>
                      <input type="checkbox" checked={selected.has(key)} onChange={() => toggle(key)}
                             data-testid={`staffing-readiness-${mode}-select-${key}`} />
                    </td>
                    <td>
                      <strong>{isPayroll ? g.name : (g.client_name || '— Unassigned —')}</strong>
                      {isPayroll && g.email && <div style={{ fontSize:'0.75em', color:'var(--cf-text-muted)' }}>{g.email}</div>}
                    </td>
                    <td>{(parseFloat(g.hours) || 0).toFixed(2)}</td>
                    {!isPayroll && <td>${(parseFloat(g.revenue) || 0).toLocaleString(undefined, { maximumFractionDigits: 0 })}</td>}
                    <td>{(g.timesheet_ids || []).length}</td>
                    <td style={{ fontSize:'0.85em' }}>{isPayroll ? (g.periods || []).join(' · ') : (g.placement_ids || []).length + ' placement(s)'}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </>
      )}
    </section>
  );
}

function Metric({ label, value, emphasis }) {
  const color = emphasis === 'good' ? '#059669' : 'inherit';
  return (
    <div>
      <div style={{ fontSize:'0.7em', textTransform:'uppercase', color:'var(--cf-text-muted)', letterSpacing:'0.05em' }}>{label}</div>
      <div style={{ fontSize:'1.4em', fontWeight: 600, color }}>{value}</div>
    </div>
  );
}

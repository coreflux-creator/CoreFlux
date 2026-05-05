import React, { useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';

const fmtMoney = (n) =>
  (Number(n) || 0).toLocaleString(undefined, { style: 'currency', currency: 'USD' });

const FREQS = [
  ['weekly', 'Weekly'],
  ['biweekly', 'Bi-weekly'],
  ['monthly', 'Monthly'],
  ['quarterly', 'Quarterly'],
  ['yearly', 'Yearly'],
];

export default function RecurringBills() {
  const { data, loading, reload } = useApi('/modules/ap/api/recurring.php');
  const rows = data?.rows || [];
  const [editing, setEditing] = useState(null); // 'new' or row obj
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState(null);
  const [genResult, setGenResult] = useState(null);

  const generateDue = async () => {
    setBusy(true); setErr(null); setGenResult(null);
    try {
      const r = await api.post('/modules/ap/api/recurring.php?action=generate_due', {});
      setGenResult(r);
      reload();
    } catch (e) { setErr(e.message); }
    finally { setBusy(false); }
  };

  const setStatus = async (id, action) => {
    try {
      await api.post(`/modules/ap/api/recurring.php?id=${id}&action=${action}`, {});
      reload();
    } catch (e) { setErr(e.message); }
  };

  return (
    <section data-testid="ap-recurring">
      <header style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 16, gap: 8 }}>
        <h2 style={{ margin: 0, fontSize: 18 }}>Recurring bills</h2>
        <div style={{ display: 'flex', gap: 8 }}>
          <button
            type="button"
            className="btn btn--ghost"
            onClick={generateDue}
            disabled={busy}
            data-testid="ap-recurring-generate-due"
          >
            {busy ? 'Generating…' : 'Generate due now'}
          </button>
          <button
            type="button"
            className="btn btn--primary"
            onClick={() => setEditing('new')}
            data-testid="ap-recurring-new"
          >
            + New schedule
          </button>
        </div>
      </header>

      {err && <p className="error" data-testid="ap-recurring-error">{err}</p>}
      {genResult && (
        <p className="muted" data-testid="ap-recurring-gen-result" style={{ fontSize: 12 }}>
          Generated {genResult.generated} new bill(s).
        </p>
      )}

      {loading && <p>Loading…</p>}
      {!loading && rows.length === 0 && (
        <p className="empty" data-testid="ap-recurring-empty">
          No recurring bills yet. Click <em>+ New schedule</em> to set one up.
        </p>
      )}

      {rows.length > 0 && (
        <table className="data-table" data-testid="ap-recurring-table">
          <thead>
            <tr>
              <th>Vendor</th><th>Description</th><th style={{ textAlign: 'right' }}>Amount</th>
              <th>Frequency</th><th>Next bill</th><th>Status</th><th></th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.id} data-testid={`ap-recurring-row-${r.id}`}>
                <td>{r.vendor_name}</td>
                <td>{r.description}</td>
                <td style={{ textAlign: 'right' }}>{fmtMoney(r.amount)}</td>
                <td>{r.frequency}</td>
                <td>{r.next_bill_date}</td>
                <td><span className="badge">{r.status}</span></td>
                <td>
                  <button type="button" className="btn btn--ghost" onClick={() => setEditing(r)} style={{ padding: '2px 8px', fontSize: 12 }} data-testid={`ap-recurring-edit-${r.id}`}>Edit</button>
                  {r.status === 'active' && <button type="button" className="btn btn--ghost" onClick={() => setStatus(r.id, 'pause')} data-testid={`ap-recurring-pause-${r.id}`} style={{ padding: '2px 8px', fontSize: 12 }}>Pause</button>}
                  {r.status === 'paused' && <button type="button" className="btn btn--ghost" onClick={() => setStatus(r.id, 'resume')} data-testid={`ap-recurring-resume-${r.id}`} style={{ padding: '2px 8px', fontSize: 12 }}>Resume</button>}
                  {r.status !== 'ended' && <button type="button" className="btn btn--ghost" onClick={() => { if (window.confirm('End this schedule? It will stop generating.')) setStatus(r.id, 'end'); }} data-testid={`ap-recurring-end-${r.id}`} style={{ padding: '2px 8px', fontSize: 12 }}>End</button>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {editing && (
        <RecurringEditor
          row={editing === 'new' ? null : editing}
          onCancel={() => setEditing(null)}
          onSaved={() => { setEditing(null); reload(); }}
        />
      )}
    </section>
  );
}

function RecurringEditor({ row, onCancel, onSaved }) {
  const [vendorName, setVendorName] = useState(row?.vendor_name || '');
  const [description, setDescription] = useState(row?.description || '');
  const [amount, setAmount] = useState(row?.amount || '');
  const [frequency, setFrequency] = useState(row?.frequency || 'monthly');
  const [dayOfPeriod, setDayOfPeriod] = useState(row?.day_of_period || 1);
  const [nextBillDate, setNextBillDate] = useState(row?.next_bill_date || new Date().toISOString().slice(0, 10));
  const [endDate, setEndDate] = useState(row?.end_date || '');
  const [glAccount, setGlAccount] = useState(row?.gl_expense_account_code || '');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState(null);

  const save = async () => {
    setBusy(true); setErr(null);
    try {
      const payload = {
        vendor_name: vendorName, description,
        amount: Number(amount), frequency,
        day_of_period: Number(dayOfPeriod) || 1,
        next_bill_date: nextBillDate,
        end_date: endDate || null,
        gl_expense_account_code: glAccount || null,
      };
      if (row) await api.patch(`/modules/ap/api/recurring.php?id=${row.id}`, payload);
      else     await api.post('/modules/ap/api/recurring.php', payload);
      onSaved();
    } catch (e) { setErr(e.message); }
    finally { setBusy(false); }
  };

  return (
    <div onClick={onCancel} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.4)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 50 }}>
      <div onClick={(e) => e.stopPropagation()} style={{ background: '#fff', padding: 20, borderRadius: 8, minWidth: 480, maxWidth: 640 }} data-testid="ap-recurring-editor">
        <h3 style={{ margin: '0 0 12px' }}>{row ? 'Edit recurring bill' : 'New recurring bill'}</h3>
        <label style={{ fontSize: 12, display: 'block', marginBottom: 6 }}>Vendor name</label>
        <input className="input" value={vendorName} onChange={(e) => setVendorName(e.target.value)} data-testid="ap-recurring-vendor" style={{ width: '100%', marginBottom: 8 }} />
        <label style={{ fontSize: 12, display: 'block', marginBottom: 6 }}>Description</label>
        <input className="input" value={description} onChange={(e) => setDescription(e.target.value)} data-testid="ap-recurring-desc" style={{ width: '100%', marginBottom: 8 }} />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8, marginBottom: 8 }}>
          <div>
            <label style={{ fontSize: 12, display: 'block', marginBottom: 6 }}>Amount</label>
            <input className="input" type="number" step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} data-testid="ap-recurring-amount" style={{ width: '100%' }} />
          </div>
          <div>
            <label style={{ fontSize: 12, display: 'block', marginBottom: 6 }}>Frequency</label>
            <select className="input" value={frequency} onChange={(e) => setFrequency(e.target.value)} data-testid="ap-recurring-frequency" style={{ width: '100%' }}>
              {FREQS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
          </div>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8, marginBottom: 8 }}>
          <div>
            <label style={{ fontSize: 12, display: 'block', marginBottom: 6 }}>Next bill date</label>
            <input className="input" type="date" value={nextBillDate} onChange={(e) => setNextBillDate(e.target.value)} data-testid="ap-recurring-next-date" style={{ width: '100%' }} />
          </div>
          <div>
            <label style={{ fontSize: 12, display: 'block', marginBottom: 6 }}>Day of period</label>
            <input className="input" type="number" min="1" max="28" value={dayOfPeriod} onChange={(e) => setDayOfPeriod(e.target.value)} data-testid="ap-recurring-day" style={{ width: '100%' }} />
          </div>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8, marginBottom: 12 }}>
          <div>
            <label style={{ fontSize: 12, display: 'block', marginBottom: 6 }}>End date (optional)</label>
            <input className="input" type="date" value={endDate || ''} onChange={(e) => setEndDate(e.target.value)} data-testid="ap-recurring-end-date" style={{ width: '100%' }} />
          </div>
          <div>
            <label style={{ fontSize: 12, display: 'block', marginBottom: 6 }}>GL account (optional)</label>
            <input className="input" value={glAccount} onChange={(e) => setGlAccount(e.target.value)} data-testid="ap-recurring-gl" style={{ width: '100%' }} />
          </div>
        </div>
        {err && <p className="error">{err}</p>}
        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
          <button type="button" className="btn btn--ghost" onClick={onCancel}>Cancel</button>
          <button type="button" className="btn btn--primary" onClick={save} disabled={busy || !vendorName || !amount} data-testid="ap-recurring-save">{busy ? 'Saving…' : 'Save'}</button>
        </div>
      </div>
    </div>
  );
}

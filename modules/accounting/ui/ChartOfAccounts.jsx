import React, { useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';

const TYPES = ['asset','liability','equity','revenue','expense'];
const NORMAL = { asset: 'debit', expense: 'debit', liability: 'credit', equity: 'credit', revenue: 'credit' };

const DEFAULT_COA = [
  { code: '1000', name: 'Cash', account_type: 'asset' },
  { code: '1100', name: 'Accounts Receivable', account_type: 'asset' },
  { code: '2000', name: 'Accounts Payable', account_type: 'liability' },
  { code: '2100', name: 'Sales Tax Payable', account_type: 'liability' },
  { code: '3000', name: 'Retained Earnings', account_type: 'equity' },
  { code: '4000', name: 'Revenue', account_type: 'revenue' },
  { code: '5000', name: 'Cost of Services (Contractor Pay)', account_type: 'expense' },
  { code: '6000', name: 'Operating Expenses', account_type: 'expense' },
];

export default function ChartOfAccounts() {
  const { data, loading, error, reload } = useApi('/modules/accounting/api/accounts.php');
  const rows = data?.rows ?? [];
  const [form, setForm] = useState({ code: '', name: '', account_type: 'expense' });
  const [busy, setBusy] = useState(false);
  const [seedBusy, setSeedBusy] = useState(false);
  const [notice, setNotice] = useState(null);

  const add = async (e) => {
    e.preventDefault();
    setBusy(true); setNotice(null);
    try {
      await api.post('/modules/accounting/api/accounts.php', form);
      setForm({ code: '', name: '', account_type: 'expense' });
      reload();
    } catch (err) { setNotice({ type: 'err', text: err.message }); }
    finally       { setBusy(false); }
  };

  const seed = async () => {
    setSeedBusy(true); setNotice(null);
    const existing = new Set(rows.map((r) => r.code));
    let created = 0, skipped = 0;
    for (const a of DEFAULT_COA) {
      if (existing.has(a.code)) { skipped++; continue; }
      try {
        await api.post('/modules/accounting/api/accounts.php', a);
        created++;
      } catch { /* ignore individual seed errors */ }
    }
    setSeedBusy(false);
    setNotice({ type: 'ok', text: `Seeded ${created} accounts (skipped ${skipped}).` });
    reload();
  };

  return (
    <section data-testid="accounting-accounts">
      <header style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 12 }}>
        <div>
          <h2 style={{ margin: 0 }}>Chart of Accounts</h2>
          <p style={{ margin: '4px 0 0', fontSize: 13, color: '#666' }}>Tenant-shared accounts. Used by AP, Billing, and manual JEs.</p>
        </div>
        <button type="button" className="btn btn--ghost" data-testid="accounting-accounts-seed" onClick={seed} disabled={seedBusy}>
          {seedBusy ? 'Seeding…' : 'Seed standard COA'}
        </button>
      </header>

      {notice && (
        <p data-testid="accounting-accounts-notice" style={{ padding: '6px 10px', borderRadius: 6, background: notice.type === 'ok' ? '#ecfdf5' : '#fef2f2', color: notice.type === 'ok' ? '#065f46' : '#991b1b' }}>
          {notice.text}
        </p>
      )}

      <form onSubmit={add} style={{ display: 'flex', gap: 8, marginBottom: 16, flexWrap: 'wrap' }} data-testid="accounting-accounts-form">
        <input className="input" placeholder="Code" value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} data-testid="accounting-accounts-code" required style={{ maxWidth: 100 }} />
        <input className="input" placeholder="Name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} data-testid="accounting-accounts-name" required style={{ flex: 1, minWidth: 200 }} />
        <select className="input" value={form.account_type} onChange={(e) => setForm({ ...form, account_type: e.target.value })} data-testid="accounting-accounts-type">
          {TYPES.map((t) => <option key={t} value={t}>{t} ({NORMAL[t]})</option>)}
        </select>
        <button className="btn btn--primary" data-testid="accounting-accounts-add" disabled={busy}>{busy ? '…' : 'Add account'}</button>
      </form>

      {loading && <p>Loading…</p>}
      {error   && <p className="error">Error: {error.message}</p>}

      <table className="data-table" style={{ width: '100%' }} data-testid="accounting-accounts-table">
        <thead><tr><th>Code</th><th>Name</th><th>Type</th><th>Normal</th><th>Postable</th><th>Active</th></tr></thead>
        <tbody>
          {rows.length === 0 && <tr><td colSpan={6} className="empty" data-testid="accounting-accounts-empty">No accounts yet. Click <em>Seed standard COA</em> to get started.</td></tr>}
          {rows.map((r) => (
            <tr key={r.id} data-testid={`accounting-accounts-row-${r.code}`}>
              <td><code>{r.code}</code></td>
              <td>{r.name}</td>
              <td>{r.account_type}</td>
              <td>{r.normal_side}</td>
              <td>{r.is_postable ? '✓' : '—'}</td>
              <td>{r.active ? '✓' : '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  );
}

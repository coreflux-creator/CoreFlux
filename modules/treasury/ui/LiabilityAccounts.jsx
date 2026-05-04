import React, { useState } from 'react';
import { Routes, Route, Link, useNavigate, useParams } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';
import AccountTransactions from './AccountTransactions';

const fmtMoney = (n) =>
  (n || 0).toLocaleString(undefined, { style: 'currency', currency: 'USD' });

const SUBTYPE_LABELS = {
  credit_card:     'Credit card',
  loan:            'Loan',
  line_of_credit:  'Line of credit',
  other_liability: 'Other liability',
};

export default function LiabilityAccounts() {
  return (
    <Routes>
      <Route index        element={<LiabilityList />} />
      <Route path=":id"   element={<LiabilityDetail />} />
    </Routes>
  );
}

function LiabilityList() {
  const { data, loading, reload } = useApi('/modules/treasury/api/liability_accounts.php');
  const rows = data?.rows || [];
  const [showNew, setShowNew] = useState(false);
  const navigate = useNavigate();

  return (
    <section className="treasury-liabilities" data-testid="treasury-liabilities">
      <header className="treasury-overview__header">
        <div>
          <h2>Liability accounts</h2>
          <p className="muted">
            Credit cards, loans, and lines of credit. Balances shown as
            outstanding (credit-normal sign flipped). Click a row to see card
            activity.
          </p>
        </div>
        <button
          className="btn btn--primary"
          onClick={() => setShowNew((v) => !v)}
          data-testid="treasury-liability-new-btn"
        >
          {showNew ? 'Cancel' : '+ New liability account'}
        </button>
      </header>

      {showNew && <NewLiabilityForm onDone={() => { setShowNew(false); reload(); }} />}

      {loading && <p>Loading…</p>}
      {!loading && rows.length === 0 && (
        <p className="empty-state" data-testid="treasury-liabilities-empty">
          No liability accounts yet.
        </p>
      )}

      {rows.length > 0 && (
        <table className="data-table" data-testid="treasury-liabilities-table">
          <thead>
            <tr>
              <th>Code</th><th>Name</th><th>Type</th><th>Institution</th>
              <th>Last 4</th>
              <th style={{ textAlign: 'right' }}>Outstanding</th>
              <th style={{ textAlign: 'right' }}>Limit</th>
              <th style={{ textAlign: 'right' }}>Util</th>
              <th>APR</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => {
              const util = r.credit_limit && r.credit_limit > 0
                ? Math.round((r.gl_balance / r.credit_limit) * 100)
                : null;
              return (
                <tr
                  key={r.id}
                  data-testid={`treasury-liability-row-${r.id}`}
                  style={{ cursor: 'pointer' }}
                  onClick={() => navigate(`./${r.id}`)}
                >
                  <td><code>{r.code}</code></td>
                  <td>{r.name}</td>
                  <td>{SUBTYPE_LABELS[r.subtype] || r.subtype || '—'}</td>
                  <td>{r.institution_name || '—'}</td>
                  <td>{r.last4 || '—'}</td>
                  <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
                    {fmtMoney(r.gl_balance)}
                  </td>
                  <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
                    {r.credit_limit ? fmtMoney(r.credit_limit) : '—'}
                  </td>
                  <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
                    {util !== null ? `${util}%` : '—'}
                  </td>
                  <td>{r.apr_pct !== null && r.apr_pct !== undefined ? `${r.apr_pct.toFixed(2)}%` : '—'}</td>
                  <td>
                    <Link
                      to={`./${r.id}`}
                      onClick={(e) => e.stopPropagation()}
                      className="btn btn--ghost"
                      data-testid={`treasury-liability-view-${r.id}`}
                      style={{ padding: '4px 10px', fontSize: 12 }}
                    >
                      View →
                    </Link>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      )}
    </section>
  );
}

function LiabilityDetail() {
  const { id } = useParams();
  const accountId = Number(id);
  const { data: listData } = useApi('/modules/treasury/api/liability_accounts.php');
  const account = (listData?.rows || []).find((r) => r.id === accountId);

  const label = account
    ? `${account.name}${account.last4 ? ` · ····${account.last4}` : ''}`
    : `Liability account #${accountId}`;

  return (
    <section data-testid="treasury-liability-detail">
      <p style={{ marginBottom: 12 }}>
        <Link to=".." className="muted" style={{ fontSize: 13 }}>← Back to liability accounts</Link>
      </p>
      <AccountTransactions
        accountId={accountId}
        type="liability"
        accountLabel={label}
      />
    </section>
  );
}

function NewLiabilityForm({ onDone }) {
  const [f, setF] = useState({
    code: '', name: '', subtype: 'credit_card',
    institution_name: '', last4: '',
    credit_limit: '', apr_pct: '', statement_day: '',
  });
  const [busy, setBusy] = useState(false);
  const [err, setErr]   = useState(null);
  const submit = async () => {
    setBusy(true); setErr(null);
    try {
      const body = { ...f };
      if (body.credit_limit === '')   delete body.credit_limit;   else body.credit_limit = Number(body.credit_limit);
      if (body.apr_pct === '')        delete body.apr_pct;        else body.apr_pct = Number(body.apr_pct);
      if (body.statement_day === '')  delete body.statement_day;  else body.statement_day = Number(body.statement_day);
      await api.post('/modules/treasury/api/liability_accounts.php', body);
      onDone();
    } catch (e) { setErr(e.message); } finally { setBusy(false); }
  };
  return (
    <div
      data-testid="treasury-liability-new-form"
      style={{
        padding: 16, marginBottom: 16, background: 'var(--cf-surface)',
        border: '1px solid var(--cf-border)', borderRadius: 8,
      }}
    >
      <h3 style={{ margin: '0 0 12px', fontSize: 14 }}>New liability account</h3>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 8 }}>
        <input className="input" placeholder="GL code (2100)"
          value={f.code} onChange={(e) => setF({ ...f, code: e.target.value })}
          data-testid="treasury-liability-code" required />
        <input className="input" placeholder="Name (Chase Business Ink)"
          value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })}
          data-testid="treasury-liability-name" required />
        <select className="input" value={f.subtype}
          onChange={(e) => setF({ ...f, subtype: e.target.value })}
          data-testid="treasury-liability-subtype">
          {Object.entries(SUBTYPE_LABELS).map(([v, l]) => (
            <option key={v} value={v}>{l}</option>
          ))}
        </select>
        <input className="input" placeholder="Institution"
          value={f.institution_name} onChange={(e) => setF({ ...f, institution_name: e.target.value })} />
        <input className="input" placeholder="Last 4" maxLength={4}
          value={f.last4} onChange={(e) => setF({ ...f, last4: e.target.value })} />
        <input className="input" type="number" step="0.01" placeholder="Credit limit ($)"
          value={f.credit_limit} onChange={(e) => setF({ ...f, credit_limit: e.target.value })}
          data-testid="treasury-liability-limit" />
        <input className="input" type="number" step="0.01" placeholder="APR %"
          value={f.apr_pct} onChange={(e) => setF({ ...f, apr_pct: e.target.value })}
          data-testid="treasury-liability-apr" />
        <input className="input" type="number" min="1" max="31" placeholder="Statement day (1-31)"
          value={f.statement_day} onChange={(e) => setF({ ...f, statement_day: e.target.value })} />
      </div>
      <div style={{ display: 'flex', gap: 8, marginTop: 12 }}>
        <button
          type="button" className="btn btn--primary"
          onClick={submit}
          disabled={busy || !f.code || !f.name}
          data-testid="treasury-liability-save">
          {busy ? 'Saving…' : 'Save'}
        </button>
      </div>
      {err && <p className="error" data-testid="treasury-liability-error">{err}</p>}
    </div>
  );
}

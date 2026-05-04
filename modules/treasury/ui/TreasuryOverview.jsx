import React from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../../../dashboard/src/lib/api';

const fmtMoney = (n) =>
  (n || 0).toLocaleString(undefined, { style: 'currency', currency: 'USD' });

export default function TreasuryOverview() {
  const dep = useApi('/modules/treasury/api/deposit_accounts.php');
  const lia = useApi('/modules/treasury/api/liability_accounts.php');

  const depositRows   = dep.data?.rows || [];
  const liabilityRows = lia.data?.rows || [];
  const depositTotal  = depositRows.reduce((s, r) => s + (r.gl_balance || 0), 0);
  const liabilityTotal = liabilityRows.reduce((s, r) => s + (r.gl_balance || 0), 0);
  const netCash = depositTotal - liabilityTotal;

  return (
    <section className="treasury-overview" data-testid="treasury-overview">
      <header className="treasury-overview__header">
        <div>
          <h2>Treasury</h2>
          <p className="muted">
            Cash positions, credit exposure, and bank-feed health — one view.
            Dashboards + 13-week forecast coming soon.
          </p>
        </div>
      </header>

      <div className="treasury-overview__stats payroll-stats" data-testid="treasury-overview-stats">
        <div className="stat-card">
          <div className="stat-card__value" data-testid="treasury-overview-deposits-total">
            {fmtMoney(depositTotal)}
          </div>
          <div className="stat-card__label">Deposit accounts ({depositRows.length})</div>
        </div>
        <div className="stat-card stat-card--warn">
          <div className="stat-card__value" data-testid="treasury-overview-liabilities-total">
            {fmtMoney(liabilityTotal)}
          </div>
          <div className="stat-card__label">Liabilities ({liabilityRows.length})</div>
        </div>
        <div className="stat-card" data-testid="treasury-overview-net-cash">
          <div className="stat-card__value" style={{ color: netCash < 0 ? '#dc2626' : undefined }}>
            {fmtMoney(netCash)}
          </div>
          <div className="stat-card__label">Net cash position</div>
        </div>
      </div>

      <section className="treasury-overview__section">
        <header className="treasury-overview__section-head">
          <h3>Deposit accounts</h3>
          <Link to="../deposits" className="btn btn--ghost" data-testid="treasury-overview-deposits-link">
            Manage →
          </Link>
        </header>
        {depositRows.length === 0 ? (
          <p className="empty-state">
            No deposit accounts yet.{' '}
            <Link to="../deposits">Create your first one</Link> or connect a bank via Plaid.
          </p>
        ) : (
          <table className="data-table" data-testid="treasury-overview-deposits-table">
            <thead>
              <tr><th>Name</th><th>Bank</th><th>Last 4</th><th>Feed</th><th style={{ textAlign: 'right' }}>GL Balance</th></tr>
            </thead>
            <tbody>
              {depositRows.slice(0, 5).map((r) => (
                <tr key={r.id}>
                  <td><Link to={`../deposits/${r.id}`}>{r.name}</Link></td>
                  <td>{r.bank_name || '—'}</td>
                  <td>{r.last4 || '—'}</td>
                  <td>
                    {r.plaid_connected ? (
                      <span className="badge badge--active">plaid</span>
                    ) : (
                      <span className="badge">manual</span>
                    )}
                  </td>
                  <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
                    {fmtMoney(r.gl_balance)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </section>

      <section className="treasury-overview__section">
        <header className="treasury-overview__section-head">
          <h3>Liability accounts</h3>
          <Link to="../liabilities" className="btn btn--ghost" data-testid="treasury-overview-liabilities-link">
            Manage →
          </Link>
        </header>
        {liabilityRows.length === 0 ? (
          <p className="empty-state">
            No liability accounts yet.{' '}
            <Link to="../liabilities">Add a credit card, loan, or line of credit</Link>.
          </p>
        ) : (
          <table className="data-table" data-testid="treasury-overview-liabilities-table">
            <thead>
              <tr><th>Name</th><th>Type</th><th>Last 4</th><th style={{ textAlign: 'right' }}>Outstanding</th><th style={{ textAlign: 'right' }}>Limit</th></tr>
            </thead>
            <tbody>
              {liabilityRows.slice(0, 5).map((r) => (
                <tr key={r.id}>
                  <td><Link to={`../liabilities/${r.id}`}>{r.name}</Link></td>
                  <td>{(r.subtype || 'other_liability').replace('_', ' ')}</td>
                  <td>{r.last4 || '—'}</td>
                  <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
                    {fmtMoney(r.gl_balance)}
                  </td>
                  <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
                    {r.credit_limit ? fmtMoney(r.credit_limit) : '—'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </section>
      <section className="treasury-overview__section">
        <BankConnectCard onLinked={() => window.location.reload()} />
      </section>

      <section className="treasury-overview__section">
        <PlaidTransferFundingCard />
      </section>
    </section>
  );
}

function BankConnectCard({ onLinked }) {
  const [busy, setBusy] = React.useState(false);
  const [msg, setMsg]   = React.useState(null);
  const [err, setErr]   = React.useState(null);

  const link = async () => {
    setBusy(true); setMsg(null); setErr(null);
    try {
      const tok = await fetch('/api/plaid_bank_link.php', {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
      }).then((r) => r.json().then((d) => r.ok ? d : Promise.reject(d)));
      if (!tok.link_token) throw new Error('No link_token returned');

      await ensurePlaidLink();
      const handler = window.Plaid.create({
        token: tok.link_token,
        onSuccess: async (publicToken, meta) => {
          try {
            const res = await fetch('/api/plaid_bank_link.php?action=exchange', {
              method: 'POST', credentials: 'include',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                public_token: publicToken,
                accounts:    meta?.accounts || [],
                institution: {
                  name:           meta?.institution?.name           || null,
                  institution_id: meta?.institution?.institution_id || null,
                },
              }),
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || 'Exchange failed');
            const dep = data.bank_accounts_created?.length || 0;
            const lia = data.liability_accounts_created?.length || 0;
            const parts = [];
            if (dep) parts.push(`${dep} deposit${dep === 1 ? '' : 's'}`);
            if (lia) parts.push(`${lia} liabilit${lia === 1 ? 'y' : 'ies'}`);
            const summary = parts.length ? parts.join(' + ') : 'no new accounts (already linked?)';
            setMsg(`Linked ${meta?.institution?.name || 'bank'} — ${summary}.`);
            if (onLinked) setTimeout(onLinked, 1200);
          } catch (e) { setErr(e.message); }
        },
        onExit: (e) => { if (e) setErr(e.error_message || 'Cancelled'); },
      });
      handler.open();
    } catch (e) {
      setErr(e.error || e.message || 'Plaid Link failed');
    } finally { setBusy(false); }
  };

  return (
    <div data-testid="plaid-bank-connect-card">
      <h3>Connect a bank (read-only feed)</h3>
      <p className="muted" style={{ fontSize: 13 }}>
        Link checking, savings, credit cards, and loans so balances and
        transactions auto-sync for reconciliation. Depository accounts land
        on the deposits tab; cards and loans land on the liabilities tab.
        <strong> No money moves</strong> — this is a read-only feed using
        Plaid Auth + Transactions. To enable outbound ACH payments, use{' '}
        <em>Outbound disbursements</em> below.
      </p>
      <button onClick={link} disabled={busy} className="btn btn--primary" data-testid="plaid-bank-connect-btn">
        {busy ? 'Opening Plaid…' : 'Connect bank'}
      </button>
      {msg && <p style={{ color: '#065f46', fontSize: 13, marginTop: 8 }} data-testid="plaid-bank-connect-success">{msg}</p>}
      {err && <p className="error" data-testid="plaid-bank-connect-error">{err}</p>}
    </div>
  );
}

function PlaidTransferFundingCard() {
  const [busy, setBusy] = React.useState(false);
  const [msg, setMsg]   = React.useState(null);
  const [err, setErr]   = React.useState(null);

  const link = async () => {
    setBusy(true); setMsg(null); setErr(null);
    try {
      const tok = await fetch('/api/plaid_transfer_link.php', {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
      }).then((r) => r.json().then((d) => r.ok ? d : Promise.reject(d)));
      if (!tok.link_token) throw new Error('No link_token returned');

      // Plaid Link script must be loaded for `Plaid.create()`. Load on demand.
      await ensurePlaidLink();
      const handler = window.Plaid.create({
        token: tok.link_token,
        onSuccess: async (publicToken, meta) => {
          const accountId = meta?.accounts?.[0]?.id;
          if (!accountId) { setErr('No account selected'); return; }
          try {
            const res = await fetch('/api/plaid_transfer_link.php?action=exchange', {
              method: 'POST', credentials: 'include',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ public_token: publicToken, account_id: accountId }),
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || 'Exchange failed');
            setMsg(`Linked ${meta?.institution?.name || 'bank'} · account ${meta?.accounts?.[0]?.mask || ''}`);
          } catch (e) {
            setErr(e.message);
          }
        },
        onExit: (e) => { if (e) setErr(e.error_message || 'Cancelled'); },
      });
      handler.open();
    } catch (e) {
      setErr(e.error || e.message || 'Plaid Link failed');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div data-testid="plaid-transfer-funding-card">
      <h3>Outbound disbursements (Plaid Transfer)</h3>
      <p className="muted" style={{ fontSize: 13 }}>
        Link a funding bank account to enable programmatic ACH/RTP credits
        out of CoreFlux for AP payments and Payroll runs. One-time setup;
        subsequent originate calls reuse this token.
      </p>
      <button onClick={link} disabled={busy} className="btn btn--primary" data-testid="plaid-transfer-link-btn">
        {busy ? 'Opening Plaid…' : 'Link funding source'}
      </button>
      {msg && <p style={{ color: '#065f46', fontSize: 13, marginTop: 8 }} data-testid="plaid-transfer-link-success">{msg}</p>}
      {err && <p className="error" data-testid="plaid-transfer-link-error">{err}</p>}
    </div>
  );
}

async function ensurePlaidLink() {
  if (window.Plaid) return;
  await new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = 'https://cdn.plaid.com/link/v2/stable/link-initialize.js';
    s.onload = resolve; s.onerror = () => reject(new Error('Failed to load Plaid Link'));
    document.head.appendChild(s);
  });
}

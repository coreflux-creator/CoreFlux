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
    </section>
  );
}

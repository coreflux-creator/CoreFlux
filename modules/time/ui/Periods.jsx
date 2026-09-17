import React, { useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';
import PeriodCloseWizard from './PeriodCloseWizard';

export default function Periods() {
  const { data, loading, error, reload } = useApi('/modules/time/api/periods.php');
  const rows = data?.rows ?? [];
  const [busy, setBusy] = useState(null);
  const [uiError, setUiError] = useState(null);
  const [notice, setNotice] = useState('');
  const [wizardPeriod, setWizardPeriod] = useState(null);

  const reopen = async (id) => {
    if (!confirm('Reopen? Only possible if no downstream bundles are consumed.')) return;
    setBusy(id); setUiError(null); setNotice('');
    try {
      await api.post(`/modules/time/api/periods.php?action=reopen&id=${id}`, {});
      setNotice('Weekly period reopened. Its time entries are editable again.');
      reload();
    }
    catch (e) { setUiError(e); } finally { setBusy(null); }
  };
  const generate = async () => {
    setBusy('gen'); setUiError(null); setNotice('');
    try {
      const res = await api.post('/modules/time/api/periods.php?action=generate&weeks=8', {});
      setNotice(res.count
        ? `Added ${res.count} weekly ${res.count === 1 ? 'period' : 'periods'}.`
        : 'The next eight weeks are already available.');
      reload();
    } catch (e) { setUiError(e); } finally { setBusy(null); }
  };

  return (
    <section className="people-directory" data-testid="time-periods">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-4)' }}>
        <div>
          <h2 style={{ marginBottom: 4 }}>Weekly periods</h2>
          <p className="muted" style={{ margin: 0 }}>Control weekly time-entry windows. Payroll periods are managed separately in Payroll.</p>
        </div>
        <button className="btn btn--primary" onClick={generate} disabled={busy === 'gen'} data-testid="time-periods-generate">
          {busy === 'gen' ? 'Adding…' : 'Add next 8 weeks'}
        </button>
      </header>

      {loading && <p>Loading…</p>}
      {error && <p className="error" data-testid="time-periods-error">Error: {error.message}</p>}
      {uiError && <p className="error" data-testid="time-periods-ui-error">Error: {uiError.message}</p>}
      {notice && <div className="alert alert--ok" data-testid="time-periods-notice">{notice}</div>}

      <table className="data-table" data-testid="time-periods-table">
        <thead><tr><th>Label</th><th>Type</th><th>Start</th><th>End</th><th>Status</th><th>Closed</th><th></th></tr></thead>
        <tbody>
          {rows.length === 0 && <tr><td colSpan={7} className="empty" data-testid="time-periods-empty">No weekly periods yet. Add the next eight weeks to begin entering time.</td></tr>}
          {rows.map(p => (
            <tr key={p.id} data-testid={`time-period-row-${p.id}`}>
              <td>{p.label}</td><td>{p.period_type}</td><td>{p.start_date}</td><td>{p.end_date}</td>
              <td><span className={`badge badge--${p.status}`}>{p.status === 'open' ? 'Open' : 'Closed'}</span></td>
              <td>{p.closed_at ? p.closed_at.replace('T', ' ').slice(0, 16) : '—'}</td>
              <td>
                {p.status === 'open'   && <button className="btn" onClick={() => setWizardPeriod(p)} disabled={busy === p.id} data-testid={`time-period-close-${p.id}`}>Close…</button>}
                {p.status === 'closed' && <button className="btn btn--ghost" onClick={() => reopen(p.id)} disabled={busy === p.id} data-testid={`time-period-reopen-${p.id}`}>Reopen</button>}
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      {wizardPeriod && (
        <PeriodCloseWizard
          period={wizardPeriod}
          onClose={() => setWizardPeriod(null)}
          onClosed={(res) => {
            setWizardPeriod(null);
            setNotice(`Weekly period closed. Prepared ${res.bundles_built} downstream ${res.bundles_built === 1 ? 'bundle' : 'bundles'} for review.`);
            reload();
          }}
        />
      )}
    </section>
  );
}

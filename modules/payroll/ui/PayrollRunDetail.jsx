import React, { useState, useEffect } from 'react';
import { useParams, Link } from 'react-router-dom';
import { api } from '../../../dashboard/src/lib/api';
import AISuggestion from '../../../dashboard/src/components/AISuggestion';

const fmtMoney = (cents) =>
  ((cents || 0) / 100).toLocaleString(undefined, { style: 'currency', currency: 'USD' });

export default function PayrollRunDetail() {
  const { runId } = useParams();
  const [data, setData] = useState(null);
  const [busy, setBusy] = useState(null);
  const [aiEnvelope, setAiEnvelope] = useState(null);
  const [aiError, setAiError] = useState(null);
  const [error, setError] = useState(null);

  const load = async () => {
    try {
      const d = await api.get(`/modules/payroll/api/runs.php?id=${runId}`);
      setData(d);
    } catch (e) { setError(e.message); }
  };

  useEffect(() => { load(); }, [runId]);

  const compute = async () => {
    setBusy('compute'); setError(null);
    try {
      await api.post('/modules/payroll/api/runs.php', { run_id: parseInt(runId, 10), action: 'compute' });
      await load();
    } catch (e) { setError(e.message); } finally { setBusy(null); }
  };

  const approve = async () => {
    setBusy('approve');
    try {
      await api.post('/modules/payroll/api/runs.php', { run_id: parseInt(runId, 10), action: 'approve' });
      await load();
    } finally { setBusy(null); }
  };

  const markPaid = async () => {
    setBusy('paid');
    try {
      await api.post('/modules/payroll/api/runs.php', { run_id: parseInt(runId, 10), action: 'paid' });
      await load();
    } finally { setBusy(null); }
  };

  const askAi = async () => {
    setBusy('ai'); setAiError(null);
    try {
      const res = await api.post('/modules/payroll/api/ai_run_summary.php', { run_id: parseInt(runId, 10) });
      setAiEnvelope(res.ai);
    } catch (e) {
      setAiError(e.message);
    } finally { setBusy(null); }
  };

  if (!data?.run) return <p>Loading…</p>;
  const run = data.run;
  const lines = data.lines || [];

  return (
    <section className="payroll-run-detail" data-testid="payroll-run-detail">
      <header className="payroll-run-detail__header">
        <div>
          <h2>Payroll Run #{run.id}</h2>
          <p className="muted">
            Period {run.period_start} → {run.period_end} · Pay date {run.pay_date} ·{' '}
            <span className={`badge badge--${run.status}`}>{run.status}</span>
          </p>
        </div>
        <div className="payroll-run-detail__actions">
          {run.status === 'draft' && (
            <button
              className="btn btn--primary"
              onClick={compute} disabled={busy === 'compute'}
              data-testid="payroll-run-compute"
            >
              {busy === 'compute' ? 'Computing…' : 'Compute payroll'}
            </button>
          )}
          {run.status === 'computed' && (
            <>
              <button
                className="btn btn--ghost"
                onClick={compute} disabled={busy === 'compute'}
                data-testid="payroll-run-recompute"
              >
                {busy === 'compute' ? 'Recomputing…' : 'Recompute'}
              </button>
              <button
                className="btn btn--primary"
                onClick={approve} disabled={busy === 'approve'}
                data-testid="payroll-run-approve"
              >
                Approve run
              </button>
            </>
          )}
          {run.status === 'approved' && (
            <button
              className="btn btn--primary"
              onClick={markPaid} disabled={busy === 'paid'}
              data-testid="payroll-run-mark-paid"
            >
              Mark paid
            </button>
          )}
        </div>
      </header>

      {error && <p className="error">{error}</p>}

      {run.status !== 'draft' && (
        <div className="payroll-run-detail__exports" style={{ display: 'flex', gap: 8, margin: '8px 0' }}>
          <a
            className="btn btn--ghost"
            href={`/modules/payroll/api/runs.php?action=export_run&id=${run.id}`}
            data-testid="payroll-run-export-csv"
            download
          >
            Download audit CSV
          </a>
          <a
            className="btn btn--ghost"
            href={`/modules/payroll/api/runs.php?action=export_gusto&id=${run.id}`}
            data-testid="payroll-run-export-gusto"
            download
          >
            Download Gusto-import CSV
          </a>
        </div>
      )}

      <div className="payroll-stats" data-testid="payroll-run-totals">
        <div className="stat-card">
          <div className="stat-card__value">{run.employee_count}</div>
          <div className="stat-card__label">Employees</div>
        </div>
        <div className="stat-card">
          <div className="stat-card__value">{fmtMoney(run.gross_total_cents)}</div>
          <div className="stat-card__label">Gross</div>
        </div>
        <div className="stat-card">
          <div className="stat-card__value">{fmtMoney(run.taxes_total_cents)}</div>
          <div className="stat-card__label">Employee taxes</div>
        </div>
        <div className="stat-card">
          <div className="stat-card__value">{fmtMoney(run.deductions_total_cents)}</div>
          <div className="stat-card__label">Deductions</div>
        </div>
        <div className="stat-card stat-card--accent">
          <div className="stat-card__value">{fmtMoney(run.net_total_cents)}</div>
          <div className="stat-card__label">Net pay</div>
        </div>
        <div className="stat-card">
          <div className="stat-card__value">{fmtMoney(run.employer_taxes_cents)}</div>
          <div className="stat-card__label">Employer taxes</div>
        </div>
      </div>

      {run.status !== 'draft' && (
        <section className="payroll-run-detail__ai">
          <header className="payroll-run-detail__section-head">
            <h3>AI narrative summary</h3>
            <button
              className="btn btn--ghost"
              onClick={askAi}
              disabled={busy === 'ai'}
              data-testid="payroll-run-ai-summary-btn"
            >
              {busy === 'ai' ? 'Asking…' : (aiEnvelope ? 'Regenerate' : 'Generate summary')}
            </button>
          </header>
          {aiError && <p className="error">{aiError}</p>}
          {aiEnvelope && (
            <AISuggestion
              envelope={aiEnvelope}
              featureKey="payroll.run_summary"
              subjectType="payroll_run"
              subjectId={parseInt(runId, 10)}
            />
          )}
          {!aiEnvelope && !aiError && (
            <p className="muted">
              Click <em>Generate summary</em> to get an advisory narrative. The figures above are produced
              deterministically by CoreFlux — AI only describes them.
            </p>
          )}
        </section>
      )}

      {lines.length === 0 ? (
        run.status === 'draft' ? (
          <p className="empty-state">Click <em>Compute payroll</em> to generate line items.</p>
        ) : (
          <p className="empty-state">No employees were eligible. Check Employee Setup.</p>
        )
      ) : (
        <table className="data-table" data-testid="payroll-run-lines">
          <thead>
            <tr>
              <th>#</th><th>Employee</th><th>Type</th>
              <th>Gross</th><th>Pre-tax</th><th>Taxes</th>
              <th>Post-tax</th><th>Net</th><th></th>
            </tr>
          </thead>
          <tbody>
            {lines.map((l) => (
              <tr key={l.id}>
                <td>{l.employee_number}</td>
                <td>{l.preferred_name || l.legal_first_name} {l.legal_last_name}</td>
                <td>{l.pay_type}</td>
                <td>{fmtMoney(l.gross_cents)}</td>
                <td>{fmtMoney(l.pretax_cents)}</td>
                <td>{fmtMoney(l.employee_taxes_cents)}</td>
                <td>{fmtMoney(l.posttax_cents)}</td>
                <td><strong>{fmtMoney(l.net_cents)}</strong></td>
                <td>
                  <Link to={`../stub/${l.id}`} className="btn btn--ghost" data-testid={`payroll-line-stub-${l.id}`}>
                    Pay stub
                  </Link>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}

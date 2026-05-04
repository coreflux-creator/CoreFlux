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
  const [anomalies, setAnomalies] = useState([]);
  const [anomaliesLoading, setAnomaliesLoading] = useState(false);
  const [anomalySummary, setAnomalySummary] = useState(null);

  const load = async () => {
    try {
      const d = await api.get(`/modules/payroll/api/runs.php?id=${runId}`);
      setData(d);
    } catch (e) { setError(e.message); }
  };

  const loadAnomalies = async () => {
    setAnomaliesLoading(true);
    try {
      const r = await api.get(`/modules/payroll/api/anomalies.php?run_id=${runId}`);
      setAnomalies(r.findings || []);
    } catch (e) { /* swallow — panel just hides */ }
    finally { setAnomaliesLoading(false); }
  };

  useEffect(() => { load(); loadAnomalies(); }, [runId]);

  const compute = async () => {
    setBusy('compute'); setError(null);
    try {
      await api.post('/modules/payroll/api/runs.php', { run_id: parseInt(runId, 10), action: 'compute' });
      await load();
      await loadAnomalies();
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

  const rerunAnomalies = async (withAi) => {
    setBusy('anomalies'); setError(null);
    try {
      const res = await api.post('/modules/payroll/api/anomalies.php', {
        run_id: parseInt(runId, 10),
        ai: !!withAi,
      });
      setAnomalySummary(res);
      await loadAnomalies();
    } catch (e) { setError(e.message); } finally { setBusy(null); }
  };

  const ackAnomaly = async (id) => {
    try {
      await api.put(`/modules/payroll/api/anomalies.php?id=${id}`, {});
      await loadAnomalies();
    } catch (e) { setError(e.message); }
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
        <GustoSyncPanel run={run} reload={load} runId={parseInt(runId, 10)} />
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

      {run.status !== 'draft' && (
        <section className="payroll-run-detail__anomalies" data-testid="payroll-run-anomalies">
          <header className="payroll-run-detail__section-head">
            <h3>
              AI cross-checks
              {anomalies.length > 0 && (
                <span
                  className={`badge badge--${anomalies.some((a) => a.severity === 'critical') ? 'critical' : 'warning'}`}
                  data-testid="payroll-run-anomalies-count"
                  style={{ marginLeft: 8 }}
                >
                  {anomalies.length}
                </span>
              )}
            </h3>
            <div style={{ display: 'flex', gap: 8 }}>
              <button
                className="btn btn--ghost"
                onClick={() => rerunAnomalies(false)}
                disabled={busy === 'anomalies'}
                data-testid="payroll-run-anomalies-rerun"
              >
                {busy === 'anomalies' ? 'Checking…' : 'Re-run checks'}
              </button>
              <button
                className="btn btn--ghost"
                onClick={() => rerunAnomalies(true)}
                disabled={busy === 'anomalies'}
                data-testid="payroll-run-anomalies-rerun-ai"
              >
                Re-run with AI narrative
              </button>
            </div>
          </header>
          {anomaliesLoading && <p className="muted">Loading…</p>}
          {!anomaliesLoading && anomalies.length === 0 && (
            <p className="muted" data-testid="payroll-run-anomalies-empty">
              No anomalies detected vs prior runs (hours drift, missing time, rate changes).
            </p>
          )}
          {anomalySummary?.ai?.content && (
            <AISuggestion
              envelope={anomalySummary.ai}
              featureKey="payroll.anomalies"
              subjectType="payroll_run"
              subjectId={parseInt(runId, 10)}
            />
          )}
          {anomalies.length > 0 && (
            <ul className="payroll-run-detail__anomaly-list">
              {anomalies.map((a) => (
                <li
                  key={a.id}
                  className={`payroll-anomaly payroll-anomaly--${a.severity}`}
                  data-testid={`payroll-run-anomaly-${a.id}`}
                >
                  <span className={`badge badge--${a.severity}`}>{a.severity}</span>
                  <span className={`badge badge--${a.code}`} style={{ marginLeft: 4 }}>{a.code}</span>
                  <span className="payroll-anomaly__msg" style={{ marginLeft: 8 }}>{a.message}</span>
                  {!a.acknowledged_at && (
                    <button
                      className="btn btn--ghost btn--sm"
                      onClick={() => ackAnomaly(a.id)}
                      data-testid={`payroll-run-anomaly-ack-${a.id}`}
                      style={{ marginLeft: 8 }}
                    >
                      Acknowledge
                    </button>
                  )}
                  {a.acknowledged_at && (
                    <span className="muted" style={{ marginLeft: 8 }}>✓ acknowledged {a.acknowledged_at}</span>
                  )}
                </li>
              ))}
            </ul>
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


/**
 * Gusto sync polish — three states:
 *   1. Not yet synced       → download CSV + "Mark synced to Gusto" form
 *   2. Synced (submitted)   → show Gusto run ID + URL, "Mark paid in Gusto" button, Unlink
 *   3. Paid in Gusto        → show paid badge + Unlink (rare, e.g. wrong ID pasted)
 * The "Mark paid in Gusto" button records that Gusto handled disbursement,
 * which the future post-to-GL code reads to suppress duplicate cash-leg JEs.
 */
function GustoSyncPanel({ run, reload, runId }) {
  const [busy, setBusy] = React.useState(null);
  const [err, setErr]   = React.useState(null);
  const [gid, setGid]   = React.useState('');
  const [url, setUrl]   = React.useState('');

  const post = async (action, body = {}) => {
    setBusy(action); setErr(null);
    try {
      const res = await api.post('/modules/payroll/api/runs.php', { run_id: runId, action, ...body });
      await reload();
      return res;
    } catch (e) {
      setErr(e.message || String(e));
    } finally { setBusy(null); }
  };

  const linked = !!run.gusto_run_id;
  const paid   = run.gusto_status === 'paid';

  return (
    <section
      className="payroll-run-detail__gusto"
      data-testid="payroll-run-gusto-panel"
      style={{
        margin: '12px 0', padding: 12, border: '1px solid var(--cf-border, #e5e7eb)',
        borderRadius: 8, background: '#fafbff',
      }}
    >
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12 }}>
        <h3 style={{ margin: 0, fontSize: 14 }}>Gusto sync</h3>
        {linked && (
          <span
            data-testid={paid ? 'payroll-run-gusto-status-paid' : 'payroll-run-gusto-status-submitted'}
            style={{
              padding: '2px 8px', borderRadius: 999, fontSize: 11, fontWeight: 600, textTransform: 'uppercase',
              background: paid ? '#d1fae5' : '#dbeafe',
              color:      paid ? '#065f46' : '#1e40af',
            }}
          >
            {paid ? 'Paid in Gusto' : 'Submitted to Gusto'}
          </span>
        )}
      </header>

      <div style={{ display: 'flex', gap: 8, marginTop: 12, flexWrap: 'wrap' }}>
        <a
          className="btn btn--ghost"
          href={`/modules/payroll/api/runs.php?action=export_run&id=${run.id}`}
          data-testid="payroll-run-export-csv"
          download
        >Download audit CSV</a>
        <a
          className="btn btn--ghost"
          href={`/modules/payroll/api/runs.php?action=export_gusto&id=${run.id}`}
          data-testid="payroll-run-export-gusto"
          download
        >Download Gusto-import CSV</a>
      </div>

      {!linked && (
        <div style={{ marginTop: 12, display: 'flex', flexDirection: 'column', gap: 8, maxWidth: 560 }}>
          <p style={{ fontSize: 12, color: 'var(--cf-text-secondary)', margin: 0 }}>
            After uploading the CSV in Gusto, paste the Gusto payroll run ID here so CoreFlux
            knows this run is being executed in Gusto. The audit trail stays inside CoreFlux —
            no payroll data is ever sent off-platform automatically.
          </p>
          <label style={{ fontSize: 12 }}>
            <span style={{ color: 'var(--cf-text-secondary)' }}>Gusto run ID</span>
            <input
              className="input"
              value={gid}
              onChange={(e) => setGid(e.target.value)}
              data-testid="payroll-run-gusto-id-input"
              style={{ display: 'block', marginTop: 4 }}
              placeholder="e.g. 7c8a4d12-..."
            />
          </label>
          <label style={{ fontSize: 12 }}>
            <span style={{ color: 'var(--cf-text-secondary)' }}>Gusto payroll URL <em>(optional)</em></span>
            <input
              className="input"
              value={url}
              onChange={(e) => setUrl(e.target.value)}
              data-testid="payroll-run-gusto-url-input"
              style={{ display: 'block', marginTop: 4 }}
              placeholder="https://app.gusto.com/payrolls/..."
            />
          </label>
          <button
            className="btn btn--primary"
            data-testid="payroll-run-gusto-link-btn"
            disabled={!gid || busy === 'mark_gusto_synced'}
            onClick={() => post('mark_gusto_synced', { gusto_run_id: gid.trim(), gusto_payroll_url: url.trim() || null })}
            style={{ alignSelf: 'flex-start' }}
          >
            {busy === 'mark_gusto_synced' ? 'Linking…' : 'Mark synced to Gusto'}
          </button>
        </div>
      )}

      {linked && (
        <div style={{ marginTop: 12, fontSize: 13, display: 'flex', flexDirection: 'column', gap: 4 }}>
          <div>
            <strong>Gusto run ID:</strong>{' '}
            <code data-testid="payroll-run-gusto-id">{run.gusto_run_id}</code>
          </div>
          {run.gusto_payroll_url && (
            <div>
              <a
                href={run.gusto_payroll_url}
                target="_blank"
                rel="noopener noreferrer"
                data-testid="payroll-run-gusto-link"
              >Open in Gusto ↗</a>
            </div>
          )}
          {run.gusto_synced_at && (
            <div style={{ color: 'var(--cf-text-secondary)', fontSize: 11 }}>
              Synced at {run.gusto_synced_at}
              {run.gusto_paid_at ? ` · paid at ${run.gusto_paid_at}` : ''}
            </div>
          )}
          <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
            {!paid && (
              <button
                className="btn btn--primary"
                data-testid="payroll-run-gusto-mark-paid-btn"
                disabled={busy === 'mark_gusto_paid'}
                onClick={() => {
                  if (window.confirm('Confirm Gusto reports this run as paid? CoreFlux will mark this run paid and skip the duplicate cash-leg GL post when the run posts to Accounting.')) {
                    post('mark_gusto_paid');
                  }
                }}
              >
                {busy === 'mark_gusto_paid' ? 'Updating…' : 'Mark paid in Gusto'}
              </button>
            )}
            <button
              className="btn btn--ghost"
              data-testid="payroll-run-gusto-unlink-btn"
              disabled={busy === 'unlink_gusto'}
              onClick={() => {
                if (window.confirm('Unlink this run from Gusto? Use only if you pasted the wrong ID.')) {
                  post('unlink_gusto');
                }
              }}
            >Unlink</button>
          </div>
        </div>
      )}

      {err && <p className="error" data-testid="payroll-run-gusto-error" style={{ marginTop: 8 }}>{err}</p>}
    </section>
  );
}

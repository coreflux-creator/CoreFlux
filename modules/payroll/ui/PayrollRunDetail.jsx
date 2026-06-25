import React, { useState, useEffect } from 'react';
import { useParams, Link } from 'react-router-dom';
import { api } from '../../../dashboard/src/lib/api';
import AISuggestion from '../../../dashboard/src/components/AISuggestion';
import ExportTemplatePicker from '../../../dashboard/src/components/ExportTemplatePicker';

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
      const d = await api.get(`/api/v1/payroll/runs?id=${runId}`);
      setData(d);
    } catch (e) { setError(e.message); }
  };

  const loadAnomalies = async () => {
    setAnomaliesLoading(true);
    try {
      const r = await api.get(`/api/v1/payroll/anomalies?run_id=${runId}`);
      setAnomalies(r.findings || []);
    } catch (e) { /* swallow — panel just hides */ }
    finally { setAnomaliesLoading(false); }
  };

  useEffect(() => { load(); loadAnomalies(); }, [runId]);

  const compute = async () => {
    setBusy('compute'); setError(null);
    try {
      await api.post('/api/v1/payroll/runs', { run_id: parseInt(runId, 10), action: 'compute' });
      await load();
      await loadAnomalies();
    } catch (e) { setError(e.message); } finally { setBusy(null); }
  };

  const approve = async () => {
    setBusy('approve');
    try {
      await api.post('/api/v1/payroll/runs', { run_id: parseInt(runId, 10), action: 'approve' });
      await load();
    } finally { setBusy(null); }
  };

  const markPaid = async () => {
    setBusy('paid');
    try {
      await api.post('/api/v1/payroll/runs', { run_id: parseInt(runId, 10), action: 'paid' });
      await load();
    } finally { setBusy(null); }
  };

  const askAi = async () => {
    setBusy('ai'); setAiError(null);
    try {
      const res = await api.post('/api/v1/payroll/ai-run-summary', { run_id: parseInt(runId, 10) });
      setAiEnvelope(res.ai);
    } catch (e) {
      setAiError(e.message);
    } finally { setBusy(null); }
  };

  const rerunAnomalies = async (withAi) => {
    setBusy('anomalies'); setError(null);
    try {
      const res = await api.post('/api/v1/payroll/anomalies', {
        run_id: parseInt(runId, 10),
        ai: !!withAi,
      });
      setAnomalySummary(res);
      await loadAnomalies();
    } catch (e) { setError(e.message); } finally { setBusy(null); }
  };

  const ackAnomaly = async (id) => {
    try {
      await api.put(`/api/v1/payroll/anomalies?id=${id}`, {});
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

      {run.pay_period_id && (
        <PayrollPreflightCard periodId={run.pay_period_id} />
      )}

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
  const [conn, setConn] = React.useState(null);
  const [unprocessed, setUnprocessed] = React.useState(null);
  const [pickedUuid, setPickedUuid]   = React.useState('');
  const [submitResult, setSubmitResult] = React.useState(null);

  React.useEffect(() => {
    api.get('/api/v1/payroll/gusto-connect')
      .then((d) => setConn(d))
      .catch(() => setConn({ configured: false, connection: null }));
  }, []);

  const post = async (action, body = {}) => {
    setBusy(action); setErr(null);
    try {
      const res = await api.post('/api/v1/payroll/runs', { run_id: runId, action, ...body });
      await reload();
      return res;
    } catch (e) {
      setErr(e.message || String(e));
    } finally { setBusy(null); }
  };

  const listUnprocessed = async () => {
    setBusy('list_unprocessed'); setErr(null);
    try {
      const res = await api.post('/api/v1/payroll/gusto-submit', {
        run_id: runId, action: 'list_unprocessed',
      });
      setUnprocessed(res.payrolls || []);
      if ((res.payrolls || []).length === 1) setPickedUuid(res.payrolls[0].uuid);
    } catch (e) { setErr(e.message); } finally { setBusy(null); }
  };

  const submitToGusto = async () => {
    if (!pickedUuid) { setErr('Pick a Gusto payroll period first'); return; }
    if (!window.confirm('Submit this run to Gusto? Hours, overtime, bonuses, and reimbursements will be pushed to the selected Gusto payroll period and the run will be moved to "submitted" in Gusto.')) return;
    setBusy('submit_to_gusto'); setErr(null);
    try {
      const res = await api.post('/api/v1/payroll/gusto-submit', {
        run_id: runId, gusto_payroll_uuid: pickedUuid,
      });
      setSubmitResult(res);
      await reload();
    } catch (e) { setErr(e.message); } finally { setBusy(null); }
  };

  const [previewResult, setPreviewResult] = useState(null);
  // Legacy route sentinels: action=export_run, action=export_gusto,
  // /modules/payroll/api/gusto_preview.php
  const previewToGusto = async () => {
    if (!pickedUuid) { setErr('Pick a Gusto payroll period first'); return; }
    setBusy('preview_to_gusto'); setErr(null); setPreviewResult(null);
    try {
      const res = await api.post('/api/v1/payroll/gusto-preview', {
        run_id: runId, gusto_payroll_uuid: pickedUuid,
      });
      setPreviewResult(res);
    } catch (e) { setErr(e.message); } finally { setBusy(null); }
  };

  const linked = !!run.gusto_run_id;
  const paid   = run.gusto_status === 'paid';
  const apiConnected = !!(conn && conn.connection && conn.connection.status === 'active');

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
          href={`/api/v1/payroll/runs/${run.id}/export-run`}
          data-testid="payroll-run-export-csv"
          download
        >Download audit CSV</a>
        <a
          className="btn btn--ghost"
          href={`/api/v1/payroll/runs/${run.id}/export-gusto`}
          data-testid="payroll-run-export-gusto"
          download
        >Download Gusto-import CSV</a>
        <ExportTemplatePicker
          dataset="payroll_disbursements"
          buildHref={(tplId) => `/api/v1/payroll/runs/${run.id}/export-template?template_id=${tplId}`}
          label="Export via template"
          testid="payroll-run-export-template"
        />
      </div>

      {apiConnected && !linked && (
        <div
          data-testid="payroll-run-gusto-api-panel"
          style={{ marginTop: 12, padding: 12, border: '1px dashed var(--cf-border, #cbd5e1)', borderRadius: 6 }}
        >
          <p style={{ margin: 0, fontSize: 13 }}>
            <strong>Submit to Gusto via API</strong>{' '}
            <span className="muted">
              ({conn.connection.company_name || conn.connection.company_uuid.slice(0, 8) + '…'} · {conn.connection.env})
            </span>
          </p>
          <p className="muted" style={{ fontSize: 12, margin: '4px 0 8px 0' }}>
            Pushes hours + bonuses to Gusto using the OAuth connection. Run must be approved.
            Employees match by employee_number across both systems.
          </p>
          {!unprocessed && (
            <button
              type="button"
              className="btn btn--primary"
              onClick={listUnprocessed}
              disabled={busy === 'list_unprocessed' || run.status === 'draft'}
              data-testid="payroll-run-gusto-list-unprocessed-btn"
            >
              {busy === 'list_unprocessed' ? 'Loading Gusto periods…' : 'Find matching Gusto payroll period'}
            </button>
          )}
          {unprocessed && unprocessed.length === 0 && (
            <p className="error" data-testid="payroll-run-gusto-no-unprocessed">
              No unprocessed Gusto payroll periods found for this date range. Check that a pay schedule exists in Gusto for this period.
            </p>
          )}
          {unprocessed && unprocessed.length > 0 && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
              <label style={{ fontSize: 12 }}>
                <span style={{ color: 'var(--cf-text-secondary)' }}>Gusto pay period</span>
                <select
                  className="input"
                  value={pickedUuid}
                  onChange={(e) => setPickedUuid(e.target.value)}
                  data-testid="payroll-run-gusto-period-select"
                  style={{ display: 'block', marginTop: 4 }}
                >
                  <option value="">— select —</option>
                  {unprocessed.map((p) => (
                    <option key={p.uuid} value={p.uuid}>
                      {p.period_start} → {p.period_end} (pay {p.pay_date})
                    </option>
                  ))}
                </select>
              </label>
              <div style={{ display: 'flex', gap: 8, alignSelf: 'flex-start' }}>
                <button
                  type="button"
                  className="btn btn--ghost"
                  onClick={previewToGusto}
                  disabled={busy === 'preview_to_gusto' || !pickedUuid}
                  data-testid="payroll-run-gusto-preview-btn"
                  title="Show what would be PUT to Gusto without submitting"
                >
                  {busy === 'preview_to_gusto' ? 'Loading preview…' : 'Preview diff'}
                </button>
                <button
                  type="button"
                  className="btn btn--primary"
                  onClick={submitToGusto}
                  disabled={busy === 'submit_to_gusto' || !pickedUuid}
                  data-testid="payroll-run-gusto-submit-btn"
                >
                  {busy === 'submit_to_gusto' ? 'Submitting to Gusto…' : 'Submit run to Gusto'}
                </button>
              </div>
            </div>
          )}
          {previewResult && (
            <GustoPreviewPanel result={previewResult} onClose={() => setPreviewResult(null)} />
          )}
          {submitResult && (
            <p
              className="success"
              data-testid="payroll-run-gusto-submit-result"
              style={{ marginTop: 8, fontSize: 12 }}
            >
              ✓ Submitted to Gusto · status: {submitResult.submission_status} ·{' '}
              {submitResult.matched_employees} employee(s) matched
              {submitResult.skipped?.length ? ` · ${submitResult.skipped.length} skipped` : ''}
            </p>
          )}
        </div>
      )}

      {!apiConnected && !linked && (
        <p
          className="muted"
          data-testid="payroll-run-gusto-csv-fallback-hint"
          style={{ marginTop: 8, fontSize: 12 }}
        >
          Tip: connect Gusto in <em>Payroll Settings</em> to skip the CSV upload step and submit runs over the API instead.
        </p>
      )}

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


function PayrollPreflightCard({ periodId }) {
  const [data, setData] = useState(null);
  const [busy, setBusy] = useState(false);
  const [err,  setErr]  = useState(null);
  const [open, setOpen] = useState(true);

  useEffect(() => {
    let cancelled = false;
    setBusy(true); setErr(null);
    api.get(`/api/v1/payroll/preflight?period_id=${periodId}`)
      .then((d) => { if (!cancelled) setData(d); })
      .catch((e) => { if (!cancelled) setErr(e.message); })
      .finally(() => { if (!cancelled) setBusy(false); });
    return () => { cancelled = true; };
  }, [periodId]);

  if (busy && !data) {
    return <p className="muted" data-testid="payroll-preflight-loading">Running preflight checks…</p>;
  }
  if (err) {
    return <p className="error" data-testid="payroll-preflight-error">Preflight failed: {err}</p>;
  }
  if (!data) return null;

  const { summary, employees } = data;
  const tone = summary.ready_to_run ? '#065f46' : (summary.blockers > 0 ? '#991b1b' : '#92400e');
  const bg   = summary.ready_to_run ? '#ecfdf5' : (summary.blockers > 0 ? '#fef2f2' : '#fffbeb');
  const headline = summary.ready_to_run
    ? `${summary.total_w2_employees} employees ready to run`
    : (summary.blockers > 0
        ? `${summary.blockers} blocker${summary.blockers === 1 ? '' : 's'} across ${employees.filter((e) => !e.ready).length} employee${employees.filter((e) => !e.ready).length === 1 ? '' : 's'}`
        : `${summary.warnings} warning${summary.warnings === 1 ? '' : 's'} — review before submit`);

  return (
    <section
      data-testid="payroll-preflight-card"
      style={{
        margin: '16px 0', padding: 14, background: bg,
        border: `1px solid ${tone}`, borderRadius: 6,
      }}
    >
      <header
        style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', cursor: 'pointer' }}
        onClick={() => setOpen(!open)}
      >
        <strong style={{ color: tone }}>
          Preflight: {headline}
        </strong>
        <button
          type="button"
          className="btn btn--ghost"
          data-testid="payroll-preflight-toggle"
          style={{ padding: '2px 10px', fontSize: 12 }}
        >
          {open ? 'Hide details' : 'Show details'}
        </button>
      </header>
      {open && (
        <div style={{ marginTop: 12 }}>
          {employees.length === 0 && (
            <p className="muted" data-testid="payroll-preflight-empty">
              No W2 employees enrolled on this schedule. Enable payroll profiles
              under Payroll → Profiles for each W2 employee on this schedule.
            </p>
          )}
          {employees.map((e) => (
            <div
              key={e.employee_id}
              data-testid={`payroll-preflight-employee-${e.employee_id}`}
              style={{
                padding: '8px 0', borderTop: '1px solid rgba(0,0,0,0.06)',
                display: 'flex', justifyContent: 'space-between', gap: 16,
              }}
            >
              <div style={{ flex: 1 }}>
                <div>
                  <strong>{e.name}</strong>
                  {e.employee_number && <span className="muted" style={{ marginLeft: 8, fontSize: 12 }}>#{e.employee_number}</span>}
                  {e.ready
                    ? <span className="badge badge--active" style={{ marginLeft: 10 }}>ready</span>
                    : <span className="badge" style={{ marginLeft: 10, background: '#fee2e2', color: '#991b1b' }}>{e.blocker_count} blocker{e.blocker_count === 1 ? '' : 's'}</span>}
                </div>
                {e.blockers.map((b, i) => (
                  <div key={'b' + i} style={{ fontSize: 12, color: '#991b1b', marginTop: 4 }}>
                    ✗ <strong>{b.label}</strong> — {b.hint}
                  </div>
                ))}
                {e.warnings.map((w, i) => (
                  <div key={'w' + i} style={{ fontSize: 12, color: '#92400e', marginTop: 4 }}>
                    ⚠ <strong>{w.label}</strong> — {w.hint}
                  </div>
                ))}
                {e.info.map((info, i) => (
                  <div key={'i' + i} style={{ fontSize: 12, color: '#475569', marginTop: 4 }}>
                    {info.label}{info.hint ? ` — ${info.hint}` : ''}
                  </div>
                ))}
              </div>
            </div>
          ))}
        </div>
      )}
    </section>
  );
}

function GustoPreviewPanel({ result, onClose }) {
  const { gusto_payroll: gp, summary, employees, unmatched_in_coreflux, unmatched_in_gusto } = result;
  const tone = summary.safe_to_submit ? '#065f46' : '#92400e';
  const bg   = summary.safe_to_submit ? '#ecfdf5' : '#fffbeb';
  return (
    <div
      data-testid="payroll-gusto-preview-panel"
      style={{
        marginTop: 16, padding: 14, background: bg,
        border: `1px solid ${tone}`, borderRadius: 6,
      }}
    >
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
        <strong style={{ color: tone }}>
          Gusto preview: {summary.matched} matched, {summary.total_employees_in_gusto - summary.matched} unmatched in Gusto, {unmatched_in_coreflux.length} unmatched in CoreFlux
        </strong>
        <button
          type="button"
          className="btn btn--ghost"
          onClick={onClose}
          data-testid="payroll-gusto-preview-close"
          style={{ padding: '2px 10px', fontSize: 12 }}
        >
          Close
        </button>
      </header>
      <p className="muted" style={{ fontSize: 12, margin: '0 0 10px' }}>
        Gusto payroll {gp.uuid} · {gp.period_start} → {gp.period_end} · status {gp.status}
      </p>

      {employees.length > 0 && (
        <table className="data-table" data-testid="payroll-gusto-preview-table">
          <thead>
            <tr>
              <th>Employee</th>
              <th style={{ textAlign: 'right' }}>Reg hrs</th>
              <th style={{ textAlign: 'right' }}>OT hrs</th>
              <th>Fixed</th>
              <th>Changes</th>
            </tr>
          </thead>
          <tbody>
            {employees.map((e) => (
              <tr key={e.gusto_employee_number || e.name} data-testid={`payroll-gusto-preview-row-${e.gusto_employee_number || 'unmatched'}`}>
                <td>
                  <strong>{e.name || '—'}</strong>
                  <div className="muted" style={{ fontSize: 11 }}>#{e.gusto_employee_number}</div>
                  {!e.matched && <span className="badge" style={{ background: '#fee2e2', color: '#991b1b' }}>not in CoreFlux</span>}
                </td>
                <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
                  {e.proposed?.regular_hours ?? e.current?.regular_hours ?? 0}
                  {e.diff?.find((d) => d.field === 'regular_hours') && (
                    <span className="muted" style={{ fontSize: 10, marginLeft: 4 }}>
                      (was {e.current?.regular_hours ?? 0})
                    </span>
                  )}
                </td>
                <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
                  {e.proposed?.overtime_hours ?? e.current?.overtime_hours ?? 0}
                  {e.diff?.find((d) => d.field === 'overtime_hours') && (
                    <span className="muted" style={{ fontSize: 10, marginLeft: 4 }}>
                      (was {e.current?.overtime_hours ?? 0})
                    </span>
                  )}
                </td>
                <td style={{ fontSize: 12 }}>
                  {(e.proposed?.fixed || e.current?.fixed || []).map((f, i) => (
                    <div key={i}>{f.name}: ${f.amount.toFixed(2)}</div>
                  ))}
                </td>
                <td style={{ fontSize: 12 }}>
                  {!e.diff || e.diff.length === 0
                    ? <span className="muted">no change</span>
                    : (
                      <ul style={{ margin: 0, paddingLeft: 16 }}>
                        {e.diff.map((d, i) => (
                          <li key={i}>{d.field}: {String(d.from)} → {String(d.to)}</li>
                        ))}
                      </ul>
                    )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {unmatched_in_coreflux.length > 0 && (
        <div style={{ marginTop: 10, padding: 8, background: '#fef2f2', borderRadius: 4 }}>
          <strong style={{ fontSize: 12, color: '#991b1b' }}>CoreFlux lines with no Gusto employee:</strong>
          <ul style={{ margin: 4, paddingLeft: 16, fontSize: 12 }}>
            {unmatched_in_coreflux.map((u, i) => (
              <li key={i}>{u.name} (#{u.employee_number || '—'}) — {u.reason}</li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}


import React, { useState, useEffect, useCallback } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../lib/api';

/**
 * <CloseDashboard /> — operator's home for period-close runs.
 *
 * Slice D / Spec §11 ("Close Agent"): wraps the existing
 * accounting_close_tasks + accounting_close_packets behind a unified
 * `accounting_close_runs` lifecycle (initiated → in_progress →
 *  packet_built → locked, with `reopened` as the back-link).
 *
 * Two-column layout:
 *   left   — run list (newest first, filterable by status)
 *   right  — drill-down: progress bar, checklist tasks, action surface
 *            (build packet · lock · reopen)
 *
 * Mounted at /modules/accounting/close. RBAC handled server-side:
 *   list/detail        → accounting.read
 *   build_packet       → accounting.write
 *   lock / reopen      → accounting.approve
 */
export default function CloseDashboard() {
  const [runs, setRuns]         = useState([]);
  const [loading, setLoading]   = useState(true);
  const [error, setError]       = useState(null);
  const [selectedId, setSel]    = useState(null);
  const [detail, setDetail]     = useState(null);
  const [detailLoading, setDL]  = useState(false);
  const [busy, setBusy]         = useState(false);
  const [statusFilter, setStatusFilter] = useState('');
  const [newRunPeriod, setNewRunPeriod] = useState('');

  const loadList = useCallback(async () => {
    setLoading(true); setError(null);
    try {
      const r = await api.get(`/api/accounting/close_runs.php${statusFilter ? `?status=${encodeURIComponent(statusFilter)}` : ''}`);
      setRuns(r.runs || []);
    } catch (e) { setError(e.message || String(e)); }
    finally { setLoading(false); }
  }, [statusFilter]);

  const loadDetail = useCallback(async (id) => {
    if (!id) { setDetail(null); return; }
    setDL(true);
    try {
      const r = await api.get(`/api/accounting/close_runs.php?action=detail&id=${id}`);
      setDetail(r);
    } catch (e) { setError(e.message || String(e)); }
    finally { setDL(false); }
  }, []);

  useEffect(() => { loadList(); },           [loadList]);
  useEffect(() => { loadDetail(selectedId); }, [selectedId, loadDetail]);

  const startNewRun = async () => {
    const periodId = parseInt(newRunPeriod, 10);
    if (!periodId) { setError('Enter a numeric period_id'); return; }
    setBusy(true); setError(null);
    try {
      const r = await api.post('/api/accounting/close_runs.php?action=start', { period_id: periodId });
      setNewRunPeriod('');
      await loadList();
      if (r.run?.id) setSel(r.run.id);
    } catch (e) { setError(e.message || String(e)); }
    finally { setBusy(false); }
  };

  const runAction = async (action, payload = {}) => {
    if (!detail?.run) return;
    setBusy(true); setError(null);
    try {
      const r = await api.post(`/api/accounting/close_runs.php?action=${action}`, {
        id: detail.run.id,
        ...payload,
      });
      // Reopen swaps to a new run id — pivot the selection.
      if (action === 'reopen' && r.new_run?.id) setSel(r.new_run.id);
      else                                      await loadDetail(detail.run.id);
      await loadList();
    } catch (e) { setError(e.message || String(e)); }
    finally { setBusy(false); }
  };

  return (
    <div data-testid="close-dashboard-page" style={{ padding: '0 8px' }}>
      <header style={{ marginBottom: 16 }}>
        <h2 style={{ fontSize: 'var(--cf-text-xl)', margin: 0 }}
            data-testid="close-dashboard-title">
          Period close dashboard
        </h2>
        <p style={{ color: 'var(--cf-text-secondary)', fontSize: 13, margin: '4px 0 0' }}>
          One run per (period, tenant). Status flows
          {' '}<code>initiated → in_progress → packet_built → locked</code>.
          Reopens supersede the locked row and start a new run.
          {' '}<Link to="/admin/ai/artifacts">→ Close packets in Artifacts</Link>
        </p>
      </header>

      {error && (
        <div className="alert alert--error"
             data-testid="close-dashboard-error"
             style={{ marginBottom: 12 }}>{error}</div>
      )}

      {/* Filter bar + new-run starter */}
      <div style={{ display: 'flex', gap: 8, marginBottom: 12, flexWrap: 'wrap' }}>
        <label style={{ fontSize: 12 }}>Status filter
          <select className="input" value={statusFilter}
                  onChange={e => setStatusFilter(e.target.value)}
                  data-testid="close-dashboard-filter-status"
                  style={{ marginLeft: 6, minWidth: 140 }}>
            <option value="">(all)</option>
            <option value="initiated">Initiated</option>
            <option value="in_progress">In progress</option>
            <option value="packet_built">Packet built</option>
            <option value="locked">Locked</option>
            <option value="reopened">Reopened (history)</option>
          </select>
        </label>
        <span style={{ marginLeft: 'auto', display: 'flex', gap: 6, alignItems: 'flex-end' }}>
          <input className="input" placeholder="period_id" type="number" min="1"
                 value={newRunPeriod}
                 onChange={e => setNewRunPeriod(e.target.value)}
                 data-testid="close-dashboard-new-period-input"
                 style={{ width: 110, fontSize: 12 }} />
          <button type="button" className="btn btn--primary"
                  disabled={busy || !newRunPeriod}
                  onClick={startNewRun}
                  data-testid="close-dashboard-start-run"
                  style={{ fontSize: 12 }}>
            Start close run
          </button>
        </span>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'minmax(360px, 1fr) 2fr', gap: 16 }}>
        <RunList rows={runs} loading={loading}
                 selectedId={selectedId} onSelect={setSel} />
        <RunDetail detail={detail} loading={detailLoading}
                   selectedId={selectedId} busy={busy}
                   onAction={runAction} />
      </div>
    </div>
  );
}

/* ─────────────────────────────────────────────────────── */
function RunList({ rows, loading, selectedId, onSelect }) {
  if (loading) return <p data-testid="close-dashboard-list-loading">Loading…</p>;
  if (rows.length === 0) {
    return (
      <p data-testid="close-dashboard-list-empty"
         style={{ color: 'var(--cf-text-secondary)', fontSize: 13 }}>
        No close runs yet — start one above by entering a period_id.
      </p>
    );
  }
  return (
    <div data-testid="close-dashboard-list"
         style={{ border: '1px solid var(--cf-border)', borderRadius: 6, overflow: 'hidden' }}>
      {rows.map(r => {
        const pct = r.total_tasks > 0 ? Math.round((r.completed_tasks / r.total_tasks) * 100) : 0;
        return (
          <button key={r.id}
                  type="button"
                  onClick={() => onSelect(r.id)}
                  data-testid={`close-dashboard-row-${r.id}`}
                  style={{
                    display: 'block', width: '100%', textAlign: 'left',
                    padding: '10px 12px', cursor: 'pointer',
                    background: r.id === selectedId ? 'var(--cf-bg-selected, #eff6ff)' : 'transparent',
                    borderBottom: '1px solid var(--cf-border-muted, #f1f5f9)',
                    border: 'none', borderTop: '1px solid var(--cf-border-muted, #f1f5f9)',
                  }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
              <span style={{ fontWeight: 600, fontSize: 13 }}>
                Run #{r.id} · period {r.period_id}
              </span>
              <StatusChip status={r.status} />
            </div>
            <div style={{ display: 'flex', gap: 8, fontSize: 11, color: 'var(--cf-text-secondary)', marginTop: 4 }}>
              <span>{r.completed_tasks} / {r.total_tasks} tasks ({pct}%)</span>
              <span style={{ marginLeft: 'auto' }}>{(r.started_at || '').slice(0, 16).replace('T', ' ')}</span>
            </div>
            <ProgressBar pct={pct} />
          </button>
        );
      })}
    </div>
  );
}

function RunDetail({ detail, loading, selectedId, busy, onAction }) {
  if (!selectedId) {
    return (
      <div data-testid="close-dashboard-detail-placeholder"
           style={{ padding: 24, color: 'var(--cf-text-secondary)', fontSize: 13,
                    border: '1px dashed var(--cf-border)', borderRadius: 6 }}>
        Select a close run on the left to drill in.
      </div>
    );
  }
  if (loading) return <p data-testid="close-dashboard-detail-loading">Loading…</p>;
  if (!detail?.run) return <p data-testid="close-dashboard-detail-empty">Run not found.</p>;

  const { run, tasks } = detail;
  const pct = run.total_tasks > 0 ? Math.round((run.completed_tasks / run.total_tasks) * 100) : 0;
  const canBuild  = ['initiated', 'in_progress'].includes(run.status) && run.completed_tasks === run.total_tasks && run.total_tasks > 0;
  const canLock   = run.status === 'packet_built';
  const canReopen = run.status === 'locked';

  return (
    <div data-testid="close-dashboard-detail"
         style={{ border: '1px solid var(--cf-border)', borderRadius: 6, padding: 16 }}>
      <header style={{ marginBottom: 12 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 12 }}>
          <div>
            <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>
              Close run #<code>{run.id}</code> · period {run.period_id}
              {' · '}<StatusChip status={run.status} />
            </div>
            <h3 data-testid="close-dashboard-detail-progress"
                style={{ margin: '4px 0 0', fontSize: 16, fontWeight: 600 }}>
              {run.completed_tasks} / {run.total_tasks} tasks complete ({pct}%)
            </h3>
          </div>
          <div style={{ textAlign: 'right', fontSize: 11, color: 'var(--cf-text-secondary)' }}>
            Started {(run.started_at || '').slice(0, 16).replace('T', ' ')}
            {run.locked_at && <div data-testid="close-dashboard-detail-locked-at">Locked {(run.locked_at || '').slice(0, 16).replace('T', ' ')}</div>}
            {run.reopened_at && <div data-testid="close-dashboard-detail-reopened-at" style={{ color: '#b91c1c' }}>Reopened {(run.reopened_at || '').slice(0, 16).replace('T', ' ')}</div>}
          </div>
        </div>
        <ProgressBar pct={pct} testId="close-dashboard-detail-progress-bar" />
      </header>

      {run.reopen_reason && (
        <div data-testid="close-dashboard-detail-reopen-reason"
             style={{ background: '#fef2f2', border: '1px solid #fecaca',
                      borderRadius: 6, padding: 8, marginBottom: 12, fontSize: 12, color: '#7f1d1d' }}>
          <strong>Reopen reason:</strong> {run.reopen_reason}
        </div>
      )}

      {/* Linked artifact + workflow */}
      {(run.packet_artifact_id || run.workflow_run_id) && (
        <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)', marginBottom: 12 }}>
          {run.packet_artifact_id && (
            <span data-testid="close-dashboard-detail-artifact-link" style={{ marginRight: 12 }}>
              Packet:{' '}
              <Link to={`/admin/ai/artifacts?id=${run.packet_artifact_id}`}>
                <code>{String(run.packet_artifact_id).slice(0, 8)}…</code>
              </Link>
            </span>
          )}
          {run.workflow_run_id && (
            <span data-testid="close-dashboard-detail-workflow-link">
              Workflow:{' '}
              <Link to={`/admin/ai-gateway/workflows?run=${run.workflow_run_id}`}>
                <code>{String(run.workflow_run_id).slice(0, 8)}…</code>
              </Link>
            </span>
          )}
        </div>
      )}

      {/* Checklist table */}
      <table data-testid="close-dashboard-detail-tasks"
             style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12, marginBottom: 14 }}>
        <thead>
          <tr style={{ background: 'var(--cf-bg-muted, #f8fafc)', textAlign: 'left' }}>
            <th style={cellTH}>#</th>
            <th style={cellTH}>Task</th>
            <th style={cellTH}>Status</th>
            <th style={cellTH}>Completed</th>
          </tr>
        </thead>
        <tbody>
          {tasks?.map((t) => (
            <tr key={t.id} data-testid={`close-dashboard-task-${t.id}`}>
              <td style={cellTD}>{t.sort_order + 1}</td>
              <td style={cellTD}>
                <div style={{ fontWeight: 500 }}>{t.title}</div>
                {t.description && (
                  <div style={{ color: 'var(--cf-text-secondary)', fontSize: 11 }}>{t.description}</div>
                )}
              </td>
              <td style={cellTD}><TaskStatusChip status={t.status} /></td>
              <td style={cellTD} data-testid={`close-dashboard-task-${t.id}-completed-at`}>
                {t.completed_at ? (t.completed_at || '').slice(0, 16).replace('T', ' ') : '—'}
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      {/* Action surface */}
      <div style={{ display: 'flex', gap: 8, borderTop: '1px solid var(--cf-border-muted, #f1f5f9)', paddingTop: 12, flexWrap: 'wrap' }}>
        <button type="button"
                className="btn btn--ghost"
                disabled={busy}
                onClick={() => onAction('refresh')}
                data-testid="close-dashboard-detail-refresh">
          Refresh progress
        </button>
        <button type="button"
                className="btn btn--primary"
                disabled={busy || !canBuild}
                onClick={() => onAction('build_packet')}
                data-testid="close-dashboard-detail-build-packet"
                title={canBuild ? '' : 'All tasks must be done before building the packet'}>
          Build close packet
        </button>
        <button type="button"
                className="btn btn--primary"
                disabled={busy || !canLock}
                onClick={() => onAction('lock')}
                data-testid="close-dashboard-detail-lock"
                title={canLock ? '' : 'Build the packet first'}>
          Lock period
        </button>
        <button type="button"
                className="btn btn--ghost"
                disabled={busy || !canReopen}
                onClick={() => {
                  const reason = window.prompt('Reopen reason (required, kept on the audit trail):', '');
                  if (reason === null || reason.trim() === '') return;
                  onAction('reopen', { reason: reason.trim() });
                }}
                data-testid="close-dashboard-detail-reopen"
                title={canReopen ? '' : 'Only locked runs may be reopened'}>
          Reopen
        </button>
      </div>
    </div>
  );
}

function ProgressBar({ pct, testId }) {
  return (
    <div data-testid={testId}
         style={{ marginTop: 6, height: 6, width: '100%',
                  background: 'var(--cf-bg-muted, #e2e8f0)', borderRadius: 999, overflow: 'hidden' }}>
      <div style={{ height: '100%', width: `${pct}%`,
                    background: pct === 100 ? '#16a34a' : '#2563eb',
                    transition: 'width .15s' }} />
    </div>
  );
}

const cellTH = { padding: '6px 8px', borderBottom: '1px solid var(--cf-border-muted, #e2e8f0)', fontWeight: 600 };
const cellTD = { padding: '6px 8px', borderBottom: '1px solid var(--cf-border-muted, #f1f5f9)' };

function StatusChip({ status }) {
  const colors = {
    initiated:    { bg: '#fef3c7', fg: '#92400e' },
    in_progress:  { bg: '#dbeafe', fg: '#1e40af' },
    packet_built: { bg: '#ddd6fe', fg: '#5b21b6' },
    locked:       { bg: '#dcfce7', fg: '#166534' },
    reopened:     { bg: '#fee2e2', fg: '#991b1b' },
  };
  const c = colors[status] || { bg: '#e5e7eb', fg: '#374151' };
  return (
    <span data-testid={`close-dashboard-status-${status}`}
          style={{
            display: 'inline-block', padding: '1px 6px', borderRadius: 999,
            background: c.bg, color: c.fg, fontSize: 10, fontWeight: 600,
          }}>{status.replace(/_/g, ' ')}</span>
  );
}

function TaskStatusChip({ status }) {
  const colors = {
    pending:     { bg: '#e5e7eb', fg: '#374151' },
    in_progress: { bg: '#dbeafe', fg: '#1e40af' },
    done:        { bg: '#dcfce7', fg: '#166534' },
    skipped:     { bg: '#fef3c7', fg: '#92400e' },
    blocked:     { bg: '#fee2e2', fg: '#991b1b' },
  };
  const c = colors[status] || { bg: '#e5e7eb', fg: '#374151' };
  return (
    <span style={{
            display: 'inline-block', padding: '1px 6px', borderRadius: 999,
            background: c.bg, color: c.fg, fontSize: 10, fontWeight: 600,
          }}>{status.replace(/_/g, ' ')}</span>
  );
}

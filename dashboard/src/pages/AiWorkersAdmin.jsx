import React, { useState, useEffect, useCallback } from 'react';
import { api } from '../lib/api';

/**
 * <AiWorkersAdmin /> — Slice 7A worker queue dashboard.
 *
 * Three rails:
 *   1. Workers row    — registered worker processes with heartbeat
 *                       (online / draining / stalled / offline).
 *   2. Depth strip    — queue depth by status (queued / claimed /
 *                       running / succeeded / failed / dead).
 *   3. Recent jobs    — last N jobs with retry / cancel affordances.
 *
 * Mounted at /admin/ai/workers.
 */
export default function AiWorkersAdmin() {
  const [workers, setWorkers] = useState([]);
  const [depth,   setDepth]   = useState({});
  const [jobs,    setJobs]    = useState([]);
  const [error,   setError]   = useState(null);
  const [loading, setLoading] = useState(true);
  const [statusFilter, setStatusFilter] = useState('');
  const [busy, setBusy] = useState(false);

  const loadAll = useCallback(async () => {
    setLoading(true); setError(null);
    try {
      const [w, d, j] = await Promise.all([
        api.get('/api/ai/workers.php?action=workers'),
        api.get('/api/ai/workers.php?action=depth'),
        api.get(`/api/ai/workers.php${statusFilter ? `?status=${encodeURIComponent(statusFilter)}` : ''}`),
      ]);
      setWorkers(w.workers || []);
      setDepth(d.depth || {});
      setJobs(j.jobs || []);
    } catch (e) { setError(e.message || String(e)); }
    finally { setLoading(false); }
  }, [statusFilter]);

  useEffect(() => { loadAll(); }, [loadAll]);

  const act = async (action, id) => {
    setBusy(true); setError(null);
    try {
      const body = { id };
      if (action === 'cancel') {
        const reason = window.prompt('Cancel reason (kept on audit trail):', '');
        if (reason === null) { setBusy(false); return; }
        body.reason = reason;
      }
      await api.post(`/api/ai/workers.php?action=${action}`, body);
      await loadAll();
    } catch (e) { setError(e.message || String(e)); }
    finally { setBusy(false); }
  };

  return (
    <div data-testid="ai-workers-page" style={{ padding: '0 8px' }}>
      <header style={{ marginBottom: 16 }}>
        <h2 style={{ fontSize: 'var(--cf-text-xl)', margin: 0 }}
            data-testid="ai-workers-title">
          AI worker runtime
        </h2>
        <p style={{ color: 'var(--cf-text-secondary)', fontSize: 13, margin: '4px 0 0' }}>
          Durable job queue + registered worker processes. Long-running tools
          (close packets, forecasts, AP extraction) run async through here.
          Run a worker:{' '}
          <code>php cron/ai_worker.php --queue=default --label="prod-1"</code>
        </p>
      </header>

      {error && (
        <div className="alert alert--error"
             data-testid="ai-workers-error"
             style={{ marginBottom: 12 }}>{error}</div>
      )}

      {/* Queue depth strip */}
      <div data-testid="ai-workers-depth"
           style={{ display: 'flex', gap: 12, marginBottom: 16, padding: 12,
                    background: 'var(--cf-bg-muted, #f8fafc)',
                    border: '1px solid var(--cf-border)', borderRadius: 6 }}>
        {[
          ['queued',    '#dbeafe', '#1e40af'],
          ['claimed',   '#ddd6fe', '#5b21b6'],
          ['running',   '#fef3c7', '#92400e'],
          ['succeeded', '#dcfce7', '#166534'],
          ['failed',    '#fee2e2', '#991b1b'],
          ['dead',      '#e5e7eb', '#374151'],
          ['cancelled', '#e5e7eb', '#374151'],
        ].map(([k, bg, fg]) => (
          <DepthStat key={k} label={k} value={depth[k] ?? 0} bg={bg} fg={fg} testId={`ai-workers-depth-${k}`} />
        ))}
      </div>

      {/* Workers panel */}
      <h3 style={{ fontSize: 14, fontWeight: 600, margin: '0 0 6px' }}>Workers</h3>
      {loading
        ? <p data-testid="ai-workers-list-loading">Loading…</p>
        : workers.length === 0
            ? <p data-testid="ai-workers-list-empty" style={{ color: 'var(--cf-text-secondary)', fontSize: 13 }}>
                No workers registered. Run <code>cron/ai_worker.php</code> on a host.
              </p>
            : (
                <table data-testid="ai-workers-list"
                       style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12, marginBottom: 18 }}>
                  <thead>
                    <tr style={{ background: 'var(--cf-bg-muted, #f8fafc)', textAlign: 'left' }}>
                      <th style={cellTH}>Key</th>
                      <th style={cellTH}>Label</th>
                      <th style={cellTH}>Status</th>
                      <th style={cellTH}>Queues</th>
                      <th style={cellTH}>Last heartbeat</th>
                    </tr>
                  </thead>
                  <tbody>
                    {workers.map(w => (
                      <tr key={w.id} data-testid={`ai-worker-row-${w.id}`}>
                        <td style={cellTD}><code>{w.worker_key}</code></td>
                        <td style={cellTD}>{w.label || <em style={{ color: '#94a3b8' }}>(unset)</em>}</td>
                        <td style={cellTD}><WorkerStatusChip status={w.status} /></td>
                        <td style={cellTD}>{(w.capabilities?.queues || []).join(', ') || <em style={{ color: '#94a3b8' }}>(any)</em>}</td>
                        <td style={cellTD}>{(w.last_heartbeat_at || '').slice(0, 19).replace('T', ' ')}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}

      {/* Jobs panel */}
      <h3 style={{ fontSize: 14, fontWeight: 600, margin: '0 0 6px',
                   display: 'flex', justifyContent: 'space-between' }}>
        <span>Recent jobs</span>
        <label style={{ fontSize: 11, fontWeight: 400 }}>
          Status filter{' '}
          <select className="input" value={statusFilter}
                  onChange={e => setStatusFilter(e.target.value)}
                  data-testid="ai-workers-jobs-filter-status"
                  style={{ minWidth: 130, fontSize: 11 }}>
            <option value="">(all)</option>
            <option>queued</option><option>claimed</option><option>running</option>
            <option>succeeded</option><option>failed</option><option>dead</option><option>cancelled</option>
          </select>
        </label>
      </h3>
      {jobs.length === 0
        ? <p data-testid="ai-workers-jobs-empty" style={{ color: 'var(--cf-text-secondary)', fontSize: 13 }}>No jobs match the filter.</p>
        : (
            <table data-testid="ai-workers-jobs"
                   style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
              <thead>
                <tr style={{ background: 'var(--cf-bg-muted, #f8fafc)', textAlign: 'left' }}>
                  <th style={cellTH}>#</th>
                  <th style={cellTH}>Tool</th>
                  <th style={cellTH}>Queue</th>
                  <th style={cellTH}>Status</th>
                  <th style={cellTH}>Attempts</th>
                  <th style={cellTH}>Created</th>
                  <th style={cellTH}>Actions</th>
                </tr>
              </thead>
              <tbody>
                {jobs.map(j => (
                  <tr key={j.id} data-testid={`ai-workers-job-row-${j.id}`}
                      style={{ background: j.status === 'failed' || j.status === 'dead' ? '#fef2f2' : 'transparent' }}>
                    <td style={cellTD}>#{j.id}</td>
                    <td style={cellTD}><code>{j.tool_name}</code></td>
                    <td style={cellTD}>{j.queue}</td>
                    <td style={cellTD}><JobStatusChip status={j.status} /></td>
                    <td style={cellTD}>{j.attempt} / {j.max_attempts}</td>
                    <td style={cellTD}>{(j.created_at || '').slice(0, 16).replace('T', ' ')}</td>
                    <td style={cellTD}>
                      {(j.status === 'dead' || j.status === 'cancelled' || j.status === 'failed') && (
                        <button type="button" className="btn btn--ghost"
                                disabled={busy}
                                onClick={() => act('retry', j.id)}
                                data-testid={`ai-workers-job-${j.id}-retry`}
                                style={{ fontSize: 11, marginRight: 4 }}>Retry</button>
                      )}
                      {(j.status === 'queued' || j.status === 'claimed' || j.status === 'failed') && (
                        <button type="button" className="btn btn--ghost"
                                disabled={busy}
                                onClick={() => act('cancel', j.id)}
                                data-testid={`ai-workers-job-${j.id}-cancel`}
                                style={{ fontSize: 11 }}>Cancel</button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
    </div>
  );
}

function DepthStat({ label, value, bg, fg, testId }) {
  return (
    <div data-testid={testId} style={{ minWidth: 80 }}>
      <div style={{ fontSize: 10, color: 'var(--cf-text-secondary)', textTransform: 'uppercase' }}>{label}</div>
      <div style={{ marginTop: 2, display: 'inline-block', padding: '2px 10px',
                    borderRadius: 999, background: bg, color: fg, fontWeight: 700, fontSize: 14 }}>
        {value}
      </div>
    </div>
  );
}

function WorkerStatusChip({ status }) {
  const c = ({
    online:   { bg: '#dcfce7', fg: '#166534' },
    draining: { bg: '#fef3c7', fg: '#92400e' },
    stalled:  { bg: '#fee2e2', fg: '#991b1b' },
    offline:  { bg: '#e5e7eb', fg: '#374151' },
  })[status] || { bg: '#e5e7eb', fg: '#374151' };
  return <span data-testid={`ai-worker-status-${status}`}
               style={{ background: c.bg, color: c.fg, padding: '1px 6px', borderRadius: 999, fontSize: 10, fontWeight: 600 }}>{status}</span>;
}

function JobStatusChip({ status }) {
  const c = ({
    queued:    { bg: '#dbeafe', fg: '#1e40af' },
    claimed:   { bg: '#ddd6fe', fg: '#5b21b6' },
    running:   { bg: '#fef3c7', fg: '#92400e' },
    succeeded: { bg: '#dcfce7', fg: '#166534' },
    failed:    { bg: '#fee2e2', fg: '#991b1b' },
    dead:      { bg: '#7f1d1d', fg: '#fee2e2' },
    cancelled: { bg: '#e5e7eb', fg: '#374151' },
  })[status] || { bg: '#e5e7eb', fg: '#374151' };
  return <span data-testid={`ai-workers-job-status-${status}`}
               style={{ background: c.bg, color: c.fg, padding: '1px 6px', borderRadius: 999, fontSize: 10, fontWeight: 600 }}>{status}</span>;
}

const cellTH = { padding: '6px 8px', borderBottom: '1px solid var(--cf-border-muted, #e2e8f0)', fontWeight: 600 };
const cellTD = { padding: '6px 8px', borderBottom: '1px solid var(--cf-border-muted, #f1f5f9)' };

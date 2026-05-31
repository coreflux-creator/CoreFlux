import React, { useCallback, useEffect, useState } from 'react';
import { api } from '../lib/api';

/**
 * <WorkflowTimeline /> — admin view of the workflow runtime
 * (Slice 3).
 *
 * Two panels:
 *   • Left — list of workflow runs (filterable by graph + status)
 *   • Right — selected run timeline: every checkpoint in order
 *             (with duration), every approval (pending or decided),
 *             and the assembled output_json when completed.
 *
 * Reviewer affordance: pending approvals expose Approve / Reject
 * buttons. Clicking them POSTs the decision and automatically
 * POSTs resume so the workflow advances without a second click.
 *
 * Spec §15 UI components: Workflow Timeline + Approval Queue both
 * live here as a single page in Slice 3. We split them when more
 * graphs land.
 *
 * Mounted at /admin/ai-gateway/workflows.
 */
export default function WorkflowTimeline() {
  const [runs, setRuns]       = useState([]);
  const [graphs, setGraphs]   = useState([]);
  const [selId, setSel]       = useState(null);
  const [detail, setDetail]   = useState(null);
  const [graphName, setGraph] = useState('');
  const [status, setStatus]   = useState('');
  const [loading, setLoad]    = useState(true);
  const [error, setError]     = useState(null);
  const [busyApproval, setBA] = useState({});

  const reload = useCallback(async () => {
    setLoad(true); setError(null);
    try {
      const qs = new URLSearchParams({ action: 'list', limit: '100' });
      if (graphName) qs.set('graph_name', graphName);
      if (status)    qs.set('status', status);
      const [r, g] = await Promise.all([
        api.get(`/api/ai/workflows.php?${qs.toString()}`),
        api.get('/api/ai/workflows.php'),
      ]);
      setRuns(r.runs || []);
      setGraphs(g.graphs || []);
    } catch (e) { setError(e.message || 'Failed to load'); }
    finally { setLoad(false); }
  }, [graphName, status]);

  const loadDetail = useCallback(async (id) => {
    setSel(id); setDetail(null);
    try {
      const d = await api.get(`/api/ai/workflows.php?id=${encodeURIComponent(id)}`);
      setDetail(d);
    } catch (e) { setError(e.message || 'Detail failed'); }
  }, []);

  const decide = useCallback(async (apprId, decision) => {
    if (decision === 'rejected' && !confirm('Reject this approval? The workflow will halt.')) return;
    setBA(s => ({ ...s, [apprId]: true }));
    try {
      await api.post('/api/ai/workflows.php?action=decide_approval', {
        approval_id: apprId, decision, decision_payload: {},
      });
      // Auto-resume the parent workflow.
      if (detail?.run?.id) {
        await api.post('/api/ai/workflows.php?action=resume', {
          workflow_run_id: detail.run.id,
        });
        await loadDetail(detail.run.id);
        await reload();
      }
    } catch (e) { setError(e.message || 'Decision failed'); }
    finally { setBA(s => ({ ...s, [apprId]: false })); }
  }, [detail, loadDetail, reload]);

  useEffect(() => { reload(); }, [reload]);

  const STATUSES = ['queued','running','awaiting_approval','completed','failed','cancelled'];

  return (
    <section data-testid="workflow-timeline" style={{ padding: 'var(--cf-space-3, 1rem)' }}>
      <header style={{ marginBottom: 16 }}>
        <h2 style={{ margin: 0, fontSize: 20, fontWeight: 600 }}>Workflow runtime — durable graphs</h2>
        <p style={{ margin: '4px 0 0', fontSize: 13, color: '#64748b' }}>
          Every workflow run lands here. Each row shows the per-node timeline,
          paused approvals, and the assembled output. Spec §6 LangGraph MVP.
        </p>
      </header>

      {error && <div className="error" data-testid="workflow-timeline-error">{error}</div>}

      <div style={{ display: 'flex', gap: 16, alignItems: 'flex-start' }}>
        {/* LEFT — list */}
        <div style={{ flex: '1 1 360px', minWidth: 0 }}>
          <div style={{ display: 'flex', gap: 8, marginBottom: 8 }}>
            <select className="input" value={graphName}
                    onChange={(e) => setGraph(e.target.value)}
                    data-testid="workflow-filter-graph"
                    style={{ flex: 1, fontSize: 12 }}>
              <option value="">All graphs</option>
              {graphs.map(g => (
                <option key={g.name} value={g.name}>{g.name} · v{g.version}</option>
              ))}
            </select>
            <select className="input" value={status}
                    onChange={(e) => setStatus(e.target.value)}
                    data-testid="workflow-filter-status"
                    style={{ width: 160, fontSize: 12 }}>
              <option value="">all status</option>
              {STATUSES.map(s => <option key={s} value={s}>{s}</option>)}
            </select>
          </div>
          {loading ? (
            <p data-testid="workflow-loading" style={{ fontSize: 12, color: '#64748b' }}>Loading…</p>
          ) : runs.length === 0 ? (
            <p data-testid="workflow-empty" style={{ fontSize: 12, color: '#64748b' }}>No workflow runs yet.</p>
          ) : (
            <table className="data-table" data-testid="workflow-list-table" style={{ width: '100%', fontSize: 12 }}>
              <thead>
                <tr style={{ color: '#64748b', textAlign: 'left' }}>
                  <th style={{ padding: '4px 6px' }}>Graph</th>
                  <th style={{ padding: '4px 6px' }}>Status</th>
                  <th style={{ padding: '4px 6px' }}>Node</th>
                  <th style={{ padding: '4px 6px' }}>Created</th>
                </tr>
              </thead>
              <tbody>
                {runs.map(r => (
                  <tr key={r.id}
                      data-testid={`workflow-row-${r.id}`}
                      onClick={() => loadDetail(r.id)}
                      style={{ cursor: 'pointer',
                               background: selId === r.id ? '#eff6ff' : 'transparent' }}>
                    <td style={{ padding: '4px 6px' }}><code>{r.graph_name}</code></td>
                    <td style={{ padding: '4px 6px' }}>
                      <span style={statusBadge(r.status)}>{r.status.replace('_', ' ')}</span>
                    </td>
                    <td style={{ padding: '4px 6px', color: '#475569', fontSize: 11 }}>{r.current_node || '—'}</td>
                    <td style={{ padding: '4px 6px', color: '#64748b', fontSize: 11 }}>{r.created_at}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>

        {/* RIGHT — detail */}
        <div style={{ flex: '2 1 0', minWidth: 0 }} data-testid="workflow-detail">
          {!detail ? (
            <p style={{ fontSize: 12, color: '#64748b' }}>Select a workflow on the left.</p>
          ) : (
            <>
              <h3 style={{ margin: '0 0 8px', fontSize: 16 }}>
                <code>{detail.run.graph_name}</code> · v{detail.run.graph_version}
              </h3>
              <p style={{ margin: 0, fontSize: 12, color: '#64748b' }}>
                <span style={statusBadge(detail.run.status)}>{detail.run.status.replace('_', ' ')}</span>
                {' · '} run <code>{detail.run.id}</code>
                {detail.run.current_node && <> · current: <code>{detail.run.current_node}</code></>}
              </p>

              <h4 style={{ margin: '16px 0 4px', fontSize: 13 }}>Timeline</h4>
              <ol data-testid="workflow-checkpoints" style={{ paddingLeft: 16, fontSize: 12 }}>
                {(detail.checkpoints || []).map(c => (
                  <li key={c.id} data-testid={`workflow-checkpoint-${c.id}`} style={{ marginBottom: 4 }}>
                    <code>{c.node_name}</code> · <span style={statusBadge(c.status)}>{c.status}</span>
                    {c.duration_ms !== null && <> · {c.duration_ms}ms</>}
                    {c.error_code && <span style={{ color: '#b91c1c' }}> · [{c.error_code}] {c.error_message}</span>}
                  </li>
                ))}
              </ol>

              {(detail.approvals || []).length > 0 && (
                <>
                  <h4 style={{ margin: '16px 0 4px', fontSize: 13 }}>Approvals</h4>
                  <ul data-testid="workflow-approvals" style={{ paddingLeft: 16, fontSize: 12 }}>
                    {detail.approvals.map(ap => (
                      <li key={ap.id} data-testid={`workflow-approval-${ap.id}`} style={{ marginBottom: 6 }}>
                        <code>{ap.approval_type}</code> · risk L{ap.risk_level} ·{' '}
                        <span style={statusBadge(ap.status)}>{ap.status}</span>
                        {ap.status === 'pending' && (
                          <span style={{ marginLeft: 8 }}>
                            <button className="btn btn--primary"
                                    onClick={() => decide(ap.id, 'approved')}
                                    disabled={!!busyApproval[ap.id]}
                                    data-testid={`workflow-approval-approve-${ap.id}`}
                                    style={{ fontSize: 11, padding: '2px 8px' }}>
                              {busyApproval[ap.id] ? '…' : 'Approve'}
                            </button>
                            <button className="btn btn--ghost"
                                    onClick={() => decide(ap.id, 'rejected')}
                                    disabled={!!busyApproval[ap.id]}
                                    data-testid={`workflow-approval-reject-${ap.id}`}
                                    style={{ fontSize: 11, padding: '2px 8px', marginLeft: 4 }}>
                              Reject
                            </button>
                          </span>
                        )}
                        <details style={{ marginTop: 4 }}>
                          <summary style={{ cursor: 'pointer', fontSize: 11, color: '#64748b' }}>request payload</summary>
                          <pre style={preStyle}>{JSON.stringify(ap.request_payload, null, 2)}</pre>
                        </details>
                      </li>
                    ))}
                  </ul>
                </>
              )}

              {detail.run.output_json && (
                <>
                  <h4 style={{ margin: '16px 0 4px', fontSize: 13 }}>Output</h4>
                  <pre data-testid="workflow-output" style={preStyle}>
                    {JSON.stringify(detail.run.output_json, null, 2)}
                  </pre>
                </>
              )}

              <details style={{ marginTop: 16 }}>
                <summary style={{ cursor: 'pointer', fontSize: 12, color: '#64748b' }}>Final state</summary>
                <pre style={preStyle}>{JSON.stringify(detail.run.state_json, null, 2)}</pre>
              </details>
            </>
          )}
        </div>
      </div>
    </section>
  );
}

function statusBadge(s) {
  const colors = {
    queued:              ['#eff6ff', '#1e40af'],
    running:             ['#fef3c7', '#92400e'],
    awaiting_approval:   ['#fef3c7', '#92400e'],
    completed:           ['#dcfce7', '#166534'],
    failed:              ['#fee2e2', '#991b1b'],
    cancelled:           ['#f1f5f9', '#475569'],
    entered:             ['#eff6ff', '#1e40af'],
    skipped:             ['#f1f5f9', '#475569'],
    paused:              ['#fef3c7', '#92400e'],
    pending:             ['#fef3c7', '#92400e'],
    approved:            ['#dcfce7', '#166534'],
    rejected:            ['#fee2e2', '#7f1d1d'],
    expired:             ['#fee2e2', '#991b1b'],
  }[s] || ['#f1f5f9', '#475569'];
  return {
    padding: '1px 6px', borderRadius: 4, fontSize: 10, fontWeight: 600,
    background: colors[0], color: colors[1], textTransform: 'capitalize',
  };
}
const preStyle = {
  background: '#f8fafc', padding: 8, borderRadius: 4,
  fontSize: 11, fontFamily: 'ui-monospace, monospace',
  maxHeight: 300, overflow: 'auto', whiteSpace: 'pre-wrap',
};

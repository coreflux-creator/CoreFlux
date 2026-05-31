import React, { useCallback, useEffect, useState } from 'react';
import { api } from '../lib/api';

/**
 * <AiGatewayAdmin /> — admin trace explorer for the AI Tool Gateway
 * (Slice 1).
 *
 * Three panels stacked vertically:
 *
 *   1. Recent runs (left of timeline). Filter by agent + status.
 *      Click a row to drill into a single run.
 *   2. Selected run detail (right). Renders the run metadata, the
 *      tool calls in order, and the spec-§15 audit events in order.
 *   3. Tool registry catalog (bottom). What tools exist, what
 *      permissions they require, what args they accept. Lets an
 *      admin verify "does the gateway even know about this tool?"
 *      without grep-ing PHP.
 *
 * All three reads are gated by `ai.audit.view`. There is no LLM call
 * here — Slice 1 ships plumbing only.
 *
 * Mounted at /admin/ai-gateway.
 */
export default function AiGatewayAdmin() {
  const [runs, setRuns]         = useState([]);
  const [selectedId, setSel]    = useState(null);
  const [detail, setDetail]     = useState(null);
  const [events, setEvents]     = useState([]);
  const [tools, setTools]       = useState([]);
  const [agent, setAgent]       = useState('');
  const [status, setStatus]     = useState('');
  const [loading, setLoading]   = useState(true);
  const [error, setError]       = useState(null);

  const loadRuns = useCallback(async () => {
    setLoading(true); setError(null);
    try {
      const qs = new URLSearchParams({ limit: '100' });
      if (agent)  qs.set('agent', agent);
      if (status) qs.set('status', status);
      const r = await api.get(`/api/ai/runs.php?${qs.toString()}`);
      setRuns(r.runs || []);
    } catch (e) { setError(e.message || 'Failed to load runs'); }
    finally { setLoading(false); }
  }, [agent, status]);

  const loadTools = useCallback(async () => {
    try {
      const r = await api.get('/api/ai/tools.php?action=list');
      // The existing tools.php wraps the registry in an envelope.
      const list = r?.result?.tools || r?.tools || [];
      setTools(list);
    } catch (e) { /* non-fatal */ }
  }, []);

  const loadDetail = useCallback(async (id) => {
    setSel(id);
    setDetail(null); setEvents([]);
    try {
      const [d, e] = await Promise.all([
        api.get(`/api/ai/runs.php?id=${encodeURIComponent(id)}`),
        api.get(`/api/ai/audit.php?ai_run_id=${encodeURIComponent(id)}`),
      ]);
      setDetail(d);
      setEvents(e.events || []);
    } catch (err) { setError(err.message || 'Failed to load run detail'); }
  }, []);

  useEffect(() => { loadRuns(); loadTools(); }, [loadRuns, loadTools]);

  const STATUSES = ['queued','running','completed','failed','cancelled','awaiting_approval'];

  return (
    <section data-testid="ai-gateway-admin" style={{ padding: 'var(--cf-space-3, 1rem)' }}>
      <header style={{ marginBottom: 16 }}>
        <h2 style={{ margin: 0, fontSize: 20, fontWeight: 600 }}>AI Tool Gateway — admin trace</h2>
        <p style={{ margin: '4px 0 0', fontSize: 13, color: '#64748b' }}>
          Every AI-originated run is logged here with its tool calls and the
          spec-§15 audit events (<code>ai_run_created</code>, <code>ai_tool_call_requested</code>,{' '}
          <code>ai_tool_call_executed</code>, <code>ai_tool_call_blocked</code>).
        </p>
      </header>

      {error && <div className="error" data-testid="ai-gateway-error">{error}</div>}

      <div style={{ display: 'flex', gap: 16, alignItems: 'flex-start' }}>
        {/* LEFT — runs list */}
        <div style={{ flex: '1 1 320px', minWidth: 0 }}>
          <div style={{ display: 'flex', gap: 8, marginBottom: 8 }}>
            <input className="input" placeholder="agent" value={agent}
                   onChange={(e) => setAgent(e.target.value)}
                   data-testid="ai-gateway-filter-agent"
                   style={{ flex: 1, fontSize: 12 }} />
            <select className="input" value={status}
                    onChange={(e) => setStatus(e.target.value)}
                    data-testid="ai-gateway-filter-status"
                    style={{ width: 120, fontSize: 12 }}>
              <option value="">all status</option>
              {STATUSES.map(s => <option key={s} value={s}>{s}</option>)}
            </select>
          </div>
          {loading ? (
            <p data-testid="ai-gateway-loading" style={{ fontSize: 12, color: '#64748b' }}>Loading…</p>
          ) : runs.length === 0 ? (
            <p data-testid="ai-gateway-empty" style={{ fontSize: 12, color: '#64748b' }}>No runs yet.</p>
          ) : (
            <table className="data-table" data-testid="ai-gateway-runs-table" style={{ width: '100%', fontSize: 12 }}>
              <thead>
                <tr style={{ color: '#64748b', textAlign: 'left' }}>
                  <th style={{ padding: '4px 6px' }}>Agent</th>
                  <th style={{ padding: '4px 6px' }}>Status</th>
                  <th style={{ padding: '4px 6px' }}>Created</th>
                </tr>
              </thead>
              <tbody>
                {runs.map(r => (
                  <tr key={r.id}
                      data-testid={`ai-gateway-run-${r.id}`}
                      onClick={() => loadDetail(r.id)}
                      style={{ cursor: 'pointer',
                               background: selectedId === r.id ? '#eff6ff' : 'transparent' }}>
                    <td style={{ padding: '4px 6px' }}>{r.agent_name}</td>
                    <td style={{ padding: '4px 6px' }}>
                      <span style={statusBadge(r.status)}>{r.status}</span>
                    </td>
                    <td style={{ padding: '4px 6px', color: '#64748b', fontSize: 11 }}>{r.created_at}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>

        {/* RIGHT — run detail */}
        <div style={{ flex: '2 1 0', minWidth: 0 }} data-testid="ai-gateway-detail">
          {!detail ? (
            <p style={{ fontSize: 12, color: '#64748b' }}>Select a run on the left to see its tool calls + audit events.</p>
          ) : (
            <>
              <h3 style={{ margin: '0 0 8px', fontSize: 16 }}>Run <code>{detail.run.id}</code></h3>
              <p style={{ margin: 0, fontSize: 12, color: '#64748b' }}>
                <code>{detail.run.agent_name}</code> · <span style={statusBadge(detail.run.status)}>{detail.run.status}</span>
                {detail.run.prompt_version && <> · prompt: <code>{detail.run.prompt_version}</code></>}
                {detail.run.model_name     && <> · model: <code>{detail.run.model_name}</code></>}
              </p>
              {detail.run.input_summary && (
                <details style={{ marginTop: 8 }}><summary style={{ cursor: 'pointer', fontSize: 12, fontWeight: 600 }}>input_summary</summary>
                  <pre style={preStyle}>{detail.run.input_summary}</pre></details>
              )}
              {detail.run.output_summary && (
                <details open style={{ marginTop: 8 }}><summary style={{ cursor: 'pointer', fontSize: 12, fontWeight: 600 }}>output_summary</summary>
                  <pre style={preStyle}>{detail.run.output_summary}</pre></details>
              )}

              <h4 style={{ margin: '16px 0 4px', fontSize: 13 }}>Tool calls</h4>
              {(detail.tool_calls || []).length === 0 ? (
                <p style={{ fontSize: 12, color: '#64748b' }}>(no tool calls in this run)</p>
              ) : (
                <ol data-testid="ai-gateway-tool-calls" style={{ paddingLeft: 16, fontSize: 12 }}>
                  {detail.tool_calls.map(tc => (
                    <li key={tc.id} data-testid={`ai-gateway-tool-call-${tc.id}`} style={{ marginBottom: 6 }}>
                      <code>{tc.tool_name}</code> · <span style={statusBadge(tc.status)}>{tc.status}</span>
                      {tc.latency_ms !== null && <> · {tc.latency_ms}ms</>}
                      {tc.error_code && <span style={{ color: '#b91c1c' }}> · [{tc.error_code}] {tc.error_message}</span>}
                      <details style={{ marginTop: 4 }}>
                        <summary style={{ cursor: 'pointer', fontSize: 11, color: '#64748b' }}>args + result</summary>
                        <pre style={preStyle}>{JSON.stringify({ args: tc.args_json, result: tc.result_summary }, null, 2)}</pre>
                      </details>
                    </li>
                  ))}
                </ol>
              )}

              <h4 style={{ margin: '16px 0 4px', fontSize: 13 }}>Audit events</h4>
              {events.length === 0 ? (
                <p style={{ fontSize: 12, color: '#64748b' }}>(no audit events)</p>
              ) : (
                <ul data-testid="ai-gateway-audit-events" style={{ paddingLeft: 16, fontSize: 12 }}>
                  {events.map(ev => (
                    <li key={ev.id} data-testid={`ai-gateway-audit-${ev.id}`}>
                      <code>{ev.event}</code> · <span style={{ color: '#64748b' }}>{ev.created_at}</span>
                    </li>
                  ))}
                </ul>
              )}
            </>
          )}
        </div>
      </div>

      {/* BOTTOM — tool registry */}
      <section style={{ marginTop: 32 }}>
        <h3 style={{ fontSize: 14, fontWeight: 600 }}>Tool registry</h3>
        <p style={{ margin: '0 0 8px', fontSize: 12, color: '#64748b' }}>
          The full catalog the gateway knows about. Each tool's RBAC permission is enforced
          inside <code>aiToolInvoke</code>; this view is for discovery only.
        </p>
        <table className="data-table" data-testid="ai-gateway-tool-registry" style={{ width: '100%', fontSize: 12 }}>
          <thead>
            <tr style={{ color: '#64748b', textAlign: 'left' }}>
              <th style={{ padding: '4px 6px' }}>Tool</th>
              <th style={{ padding: '4px 6px' }}>Description</th>
              <th style={{ padding: '4px 6px' }}>Permission</th>
              <th style={{ padding: '4px 6px' }}>Args</th>
            </tr>
          </thead>
          <tbody>
            {tools.map(t => (
              <tr key={t.name} data-testid={`ai-gateway-tool-${t.name.replace(/\./g, '-')}`}>
                <td style={{ padding: '4px 6px' }}><code>{t.name}</code></td>
                <td style={{ padding: '4px 6px' }}>{t.description}</td>
                <td style={{ padding: '4px 6px' }}>{t.permission ? <code>{t.permission}</code> : <span style={{ color: '#64748b' }}>(open)</span>}</td>
                <td style={{ padding: '4px 6px' }}>
                  {Object.keys(t.args || {}).length === 0
                    ? <span style={{ color: '#64748b' }}>—</span>
                    : Object.entries(t.args).map(([k, v]) => (
                        <div key={k} style={{ fontSize: 11 }}>
                          <code>{k}</code>:{v.type}{v.required ? '*' : ''}
                        </div>
                      ))}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>
    </section>
  );
}

function statusBadge(s) {
  const colors = {
    queued:             ['#eff6ff', '#1e40af'],
    running:            ['#fef3c7', '#92400e'],
    completed:          ['#dcfce7', '#166534'],
    failed:             ['#fee2e2', '#991b1b'],
    cancelled:          ['#f1f5f9', '#475569'],
    awaiting_approval:  ['#fef3c7', '#92400e'],
    ok:                 ['#dcfce7', '#166534'],
    denied:             ['#fee2e2', '#7f1d1d'],
    validation_failed:  ['#fee2e2', '#991b1b'],
    provider_error:     ['#fee2e2', '#991b1b'],
    internal_error:     ['#fee2e2', '#991b1b'],
  }[s] || ['#f1f5f9', '#475569'];
  return {
    padding: '1px 6px', borderRadius: 4, fontSize: 10, fontWeight: 600,
    background: colors[0], color: colors[1], textTransform: 'capitalize',
  };
}
const preStyle = {
  background: '#f8fafc', padding: 8, borderRadius: 4,
  fontSize: 11, fontFamily: 'ui-monospace, monospace',
  maxHeight: 200, overflow: 'auto', whiteSpace: 'pre-wrap',
};

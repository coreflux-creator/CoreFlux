import React, { useState, useEffect, useCallback } from 'react';
import { api } from '../lib/api';

/**
 * <AgentRegistryAdmin /> — Slice 7C Agents + Handoffs admin.
 *
 * Two panels:
 *   1. Agents table         — registered agents (tenant + platform-shared).
 *   2. Handoffs panel       — recent handoffs with resolve / refuse actions.
 *
 * Mounted at /admin/ai/agents.
 */
export default function AgentRegistryAdmin() {
  const [agents,    setAgents]   = useState([]);
  const [handoffs,  setHandoffs] = useState([]);
  const [loading,   setLoading]  = useState(true);
  const [error,     setError]    = useState(null);
  const [busy,      setBusy]     = useState(false);
  const [handoffFilter, setHandoffFilter] = useState('pending');

  const loadAll = useCallback(async () => {
    setLoading(true); setError(null);
    try {
      const [a, h] = await Promise.all([
        api.get('/api/ai/agents.php'),
        api.get(`/api/ai/agents.php?action=handoffs${handoffFilter ? `&status=${handoffFilter}` : ''}`),
      ]);
      setAgents(a.agents || []);
      setHandoffs(h.handoffs || []);
    } catch (e) { setError(e.message || String(e)); }
    finally { setLoading(false); }
  }, [handoffFilter]);

  useEffect(() => { loadAll(); }, [loadAll]);

  const resolve = async (id, status) => {
    const note = status === 'refused'
      ? window.prompt('Refusal reason (required for audit):', '')
      : null;
    if (status === 'refused' && (note === null || note.trim() === '')) return;
    setBusy(true); setError(null);
    try {
      await api.post('/api/ai/agents.php?action=resolve', { id, status, note });
      await loadAll();
    } catch (e) { setError(e.message || String(e)); }
    finally { setBusy(false); }
  };

  return (
    <div data-testid="agents-page" style={{ padding: '0 8px' }}>
      <header style={{ marginBottom: 16 }}>
        <h2 style={{ fontSize: 'var(--cf-text-xl)', margin: 0 }}
            data-testid="agents-title">
          Agent registry &amp; handoffs
        </h2>
        <p style={{ color: 'var(--cf-text-secondary)', fontSize: 13, margin: '4px 0 0' }}>
          Named agents bundle a set of tools, a system prompt, and a permission
          surface. Handoffs delegate work between agents (e.g. Close Agent →
          Cash Agent once a close packet is built).
        </p>
      </header>

      {error && (
        <div className="alert alert--error"
             data-testid="agents-error"
             style={{ marginBottom: 12 }}>{error}</div>
      )}

      {/* Agents table */}
      <h3 style={{ fontSize: 14, fontWeight: 600, margin: '0 0 6px' }}>Registered agents</h3>
      {loading
        ? <p data-testid="agents-list-loading">Loading…</p>
        : agents.length === 0
            ? <p data-testid="agents-list-empty" style={{ color: 'var(--cf-text-secondary)', fontSize: 13 }}>
                No agents registered. Seed via{' '}
                <code>coreflux.upsert_agent</code> or POST to the API.
              </p>
            : (
                <table data-testid="agents-list"
                       style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12, marginBottom: 18 }}>
                  <thead>
                    <tr style={{ background: 'var(--cf-bg-muted, #f8fafc)', textAlign: 'left' }}>
                      <th style={cellTH}>Key</th>
                      <th style={cellTH}>Label</th>
                      <th style={cellTH}>Module</th>
                      <th style={cellTH}>Status</th>
                      <th style={cellTH}>Tools</th>
                      <th style={cellTH}>Scope</th>
                    </tr>
                  </thead>
                  <tbody>
                    {agents.map(a => (
                      <tr key={a.id} data-testid={`agents-row-${a.id}`}>
                        <td style={cellTD}><code>{a.agent_key}</code></td>
                        <td style={cellTD}>{a.label}</td>
                        <td style={cellTD}>{a.owner_module || <em style={{ color: '#94a3b8' }}>—</em>}</td>
                        <td style={cellTD}><AgentStatusChip status={a.status} /></td>
                        <td style={cellTD} style={{ ...cellTD, color: '#475569' }}>
                          {(a.default_tools || []).length} tools
                        </td>
                        <td style={cellTD}>
                          {a.tenant_id === null
                            ? <span data-testid={`agents-scope-platform-${a.id}`}
                                    style={{ fontSize: 10, fontWeight: 600, color: '#0c4a6e',
                                             background: '#dbeafe', padding: '1px 6px', borderRadius: 999 }}>platform</span>
                            : <span data-testid={`agents-scope-tenant-${a.id}`}
                                    style={{ fontSize: 10, fontWeight: 600, color: '#374151',
                                             background: '#e5e7eb', padding: '1px 6px', borderRadius: 999 }}>tenant</span>}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}

      {/* Handoffs panel */}
      <h3 style={{ fontSize: 14, fontWeight: 600, margin: '0 0 6px',
                   display: 'flex', justifyContent: 'space-between' }}>
        <span>Handoffs</span>
        <label style={{ fontSize: 11, fontWeight: 400 }}>Status filter{' '}
          <select className="input" value={handoffFilter}
                  onChange={e => setHandoffFilter(e.target.value)}
                  data-testid="agents-handoff-filter"
                  style={{ minWidth: 130, fontSize: 11 }}>
            <option value="">(all)</option>
            <option>pending</option>
            <option>accepted</option>
            <option>refused</option>
            <option>completed</option>
            <option>cancelled</option>
          </select>
        </label>
      </h3>

      {handoffs.length === 0
        ? <p data-testid="agents-handoffs-empty" style={{ color: 'var(--cf-text-secondary)', fontSize: 13 }}>No handoffs match the filter.</p>
        : (
            <table data-testid="agents-handoffs"
                   style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
              <thead>
                <tr style={{ background: 'var(--cf-bg-muted, #f8fafc)', textAlign: 'left' }}>
                  <th style={cellTH}>#</th>
                  <th style={cellTH}>From</th>
                  <th style={cellTH}>To</th>
                  <th style={cellTH}>Status</th>
                  <th style={cellTH}>Reason</th>
                  <th style={cellTH}>Created</th>
                  <th style={cellTH}>Actions</th>
                </tr>
              </thead>
              <tbody>
                {handoffs.map(h => (
                  <tr key={h.id} data-testid={`agents-handoff-row-${h.id}`}>
                    <td style={cellTD}>#{h.id}</td>
                    <td style={cellTD}><code>{h.from_agent_key}</code></td>
                    <td style={cellTD}><code>{h.to_agent_key}</code></td>
                    <td style={cellTD}><HandoffStatusChip status={h.status} /></td>
                    <td style={cellTD}>{h.reason || <em style={{ color: '#94a3b8' }}>—</em>}</td>
                    <td style={cellTD}>{(h.created_at || '').slice(0, 16).replace('T', ' ')}</td>
                    <td style={cellTD}>
                      {h.status === 'pending' && (
                        <>
                          <button type="button" className="btn btn--ghost"
                                  disabled={busy}
                                  onClick={() => resolve(h.id, 'accepted')}
                                  data-testid={`agents-handoff-${h.id}-accept`}
                                  style={{ fontSize: 11, marginRight: 4 }}>Accept</button>
                          <button type="button" className="btn btn--ghost"
                                  disabled={busy}
                                  onClick={() => resolve(h.id, 'refused')}
                                  data-testid={`agents-handoff-${h.id}-refuse`}
                                  style={{ fontSize: 11, marginRight: 4 }}>Refuse</button>
                        </>
                      )}
                      {h.status === 'accepted' && (
                        <button type="button" className="btn btn--ghost"
                                disabled={busy}
                                onClick={() => resolve(h.id, 'completed')}
                                data-testid={`agents-handoff-${h.id}-complete`}
                                style={{ fontSize: 11 }}>Complete</button>
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

function AgentStatusChip({ status }) {
  const c = ({
    active:  { bg: '#dcfce7', fg: '#166534' },
    draft:   { bg: '#fef3c7', fg: '#92400e' },
    retired: { bg: '#e5e7eb', fg: '#374151' },
  })[status] || { bg: '#e5e7eb', fg: '#374151' };
  return <span data-testid={`agents-status-${status}`}
               style={{ background: c.bg, color: c.fg, padding: '1px 6px', borderRadius: 999, fontSize: 10, fontWeight: 600 }}>{status}</span>;
}

function HandoffStatusChip({ status }) {
  const c = ({
    pending:   { bg: '#fef3c7', fg: '#92400e' },
    accepted:  { bg: '#dbeafe', fg: '#1e40af' },
    refused:   { bg: '#fee2e2', fg: '#991b1b' },
    completed: { bg: '#dcfce7', fg: '#166534' },
    cancelled: { bg: '#e5e7eb', fg: '#374151' },
  })[status] || { bg: '#e5e7eb', fg: '#374151' };
  return <span data-testid={`agents-handoff-status-${status}`}
               style={{ background: c.bg, color: c.fg, padding: '1px 6px', borderRadius: 999, fontSize: 10, fontWeight: 600 }}>{status}</span>;
}

const cellTH = { padding: '6px 8px', borderBottom: '1px solid var(--cf-border-muted, #e2e8f0)', fontWeight: 600 };
const cellTD = { padding: '6px 8px', borderBottom: '1px solid var(--cf-border-muted, #f1f5f9)' };

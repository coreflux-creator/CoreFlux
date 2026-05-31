import React, { useCallback, useEffect, useState } from 'react';
import { api } from '../lib/api';

/**
 * <AiReviewerDashboard /> — reviewer cockpit (Slice 5).
 *
 * One page that surfaces everything the AI gateway has parked for
 * human attention. Three count tiles + three drill-in tables:
 *
 *   • Open accounting exceptions (with severity breakdown)
 *   • Pending workflow approvals (with risk_level + graph)
 *   • Recent AI-drafted JEs (status='draft', source_ref_type
 *     starts with 'ai_workflow' or 'workflow_run')
 *
 * Cross-links: clicking a pending approval row jumps to
 * /admin/ai-gateway/workflows?id=<workflow_run_id> so the reviewer
 * can decide it without losing context.
 *
 * Mounted at /admin/ai-gateway/reviewer.
 */
export default function AiReviewerDashboard() {
  const [data, setData]       = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState(null);

  const reload = useCallback(async () => {
    setLoading(true); setError(null);
    try {
      const r = await api.get('/api/ai/dashboard.php');
      setData(r);
    } catch (e) { setError(e.message || 'Failed to load'); }
    finally { setLoading(false); }
  }, []);

  useEffect(() => { reload(); }, [reload]);

  if (loading) return <p data-testid="reviewer-loading" style={{ padding: 16, fontSize: 12, color: '#64748b' }}>Loading reviewer dashboard…</p>;
  if (error)   return <div className="error" data-testid="reviewer-error">{error}</div>;
  if (!data)   return null;

  const oe = data.open_exceptions   || { count: 0, recent: [] };
  const pa = data.pending_approvals || { count: 0, recent: [] };
  const rd = data.recent_drafts     || { count: 0, recent: [] };
  const sev = data.counts_by_severity || { low: 0, medium: 0, high: 0, critical: 0 };

  return (
    <section data-testid="ai-reviewer-dashboard" style={{ padding: 'var(--cf-space-3, 1rem)' }}>
      <header style={{ marginBottom: 16, display: 'flex', alignItems: 'baseline', justifyContent: 'space-between' }}>
        <div>
          <h2 style={{ margin: 0, fontSize: 20, fontWeight: 600 }}>AI Reviewer cockpit</h2>
          <p style={{ margin: '4px 0 0', fontSize: 13, color: '#64748b' }}>
            Everything the AI gateway has parked for human attention — exceptions,
            pending approvals, and recently drafted journal entries.
          </p>
        </div>
        <button className="btn btn--ghost" onClick={reload}
                data-testid="reviewer-refresh"
                style={{ fontSize: 12 }}>↻ Refresh</button>
      </header>

      {/* Count tiles */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 12, marginBottom: 24 }}>
        <CountTile
          label="Open exceptions"
          count={oe.count}
          testId="tile-open-exceptions"
          accent="#fee2e2"
          accentText="#991b1b"
          sub={`crit:${sev.critical} · high:${sev.high} · med:${sev.medium} · low:${sev.low}`}
        />
        <CountTile
          label="Pending approvals"
          count={pa.count}
          testId="tile-pending-approvals"
          accent="#fef3c7"
          accentText="#92400e"
          sub={pa.count > 0 ? 'awaiting reviewer decision' : 'queue empty'}
        />
        <CountTile
          label="Recent AI drafts"
          count={rd.count}
          testId="tile-recent-drafts"
          accent="#dbeafe"
          accentText="#1e40af"
          sub="status=draft, ready for post"
        />
      </div>

      {/* Pending approvals table */}
      <Section title="Pending workflow approvals" testId="section-pending-approvals" emptyMsg="No pending approvals — reviewers are caught up.">
        {pa.recent.length > 0 && (
          <table className="data-table" data-testid="reviewer-approvals-table" style={{ width: '100%', fontSize: 12 }}>
            <thead><tr style={{ color: '#64748b', textAlign: 'left' }}>
              <th style={th}>Type</th><th style={th}>Graph</th><th style={th}>Node</th>
              <th style={th}>Risk</th><th style={th}>Created</th><th style={th}>Action</th>
            </tr></thead>
            <tbody>
              {pa.recent.map(ap => (
                <tr key={ap.id} data-testid={`reviewer-approval-${ap.id}`}>
                  <td style={td}><code>{ap.approval_type}</code></td>
                  <td style={td}>{ap.graph_name || '—'}</td>
                  <td style={td}>{ap.node_name}</td>
                  <td style={td}><span style={riskBadge(ap.risk_level)}>L{ap.risk_level}</span></td>
                  <td style={{ ...td, color: '#64748b', fontSize: 11 }}>{ap.created_at}</td>
                  <td style={td}>
                    <a href={`/admin/ai-gateway/workflows`}
                       data-testid={`reviewer-approval-link-${ap.id}`}
                       style={{ fontSize: 11, color: '#2563eb', textDecoration: 'none' }}>
                      Decide →
                    </a>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Section>

      {/* Open exceptions table */}
      <Section title="Open accounting exceptions" testId="section-open-exceptions" emptyMsg="No open exceptions.">
        {oe.recent.length > 0 && (
          <table className="data-table" data-testid="reviewer-exceptions-table" style={{ width: '100%', fontSize: 12 }}>
            <thead><tr style={{ color: '#64748b', textAlign: 'left' }}>
              <th style={th}>Type</th><th style={th}>Severity</th><th style={th}>Summary</th>
              <th style={th}>Reference</th><th style={th}>Created</th>
            </tr></thead>
            <tbody>
              {oe.recent.map(ex => (
                <tr key={ex.id} data-testid={`reviewer-exception-${ex.id}`}>
                  <td style={td}><code>{ex.exception_type}</code></td>
                  <td style={td}><span style={sevBadge(ex.severity)}>{ex.severity}</span></td>
                  <td style={td}>{ex.summary}</td>
                  <td style={{ ...td, color: '#64748b', fontSize: 11 }}>
                    {ex.related_ref_type ? `${ex.related_ref_type}#${ex.related_ref_id ?? '?'}` : '—'}
                  </td>
                  <td style={{ ...td, color: '#64748b', fontSize: 11 }}>{ex.created_at}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Section>

      {/* Recent AI drafts table */}
      <Section title="Recent AI-drafted JEs" testId="section-recent-drafts" emptyMsg="No AI-drafted journal entries yet.">
        {rd.recent.length > 0 && (
          <table className="data-table" data-testid="reviewer-drafts-table" style={{ width: '100%', fontSize: 12 }}>
            <thead><tr style={{ color: '#64748b', textAlign: 'left' }}>
              <th style={th}>JE #</th><th style={th}>Posting</th>
              <th style={{ ...th, textAlign: 'right' }}>Debit</th>
              <th style={{ ...th, textAlign: 'right' }}>Credit</th>
              <th style={th}>Source</th><th style={th}>Memo</th>
            </tr></thead>
            <tbody>
              {rd.recent.map(je => (
                <tr key={je.id} data-testid={`reviewer-draft-${je.id}`}>
                  <td style={td}><code>{je.je_number}</code></td>
                  <td style={td}>{je.posting_date}</td>
                  <td style={{ ...td, textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>${je.total_debit.toFixed(2)}</td>
                  <td style={{ ...td, textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>${je.total_credit.toFixed(2)}</td>
                  <td style={{ ...td, color: '#64748b', fontSize: 11 }}>{je.source_ref_type || '—'}</td>
                  <td style={{ ...td, color: '#64748b' }}>{je.memo || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Section>
    </section>
  );
}

function CountTile({ label, count, testId, accent, accentText, sub }) {
  return (
    <div data-testid={testId} style={{ background: '#fff', border: `1px solid ${accent}`, borderLeft: `4px solid ${accentText}`, borderRadius: 8, padding: 16 }}>
      <div style={{ fontSize: 11, fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.05em', color: '#64748b' }}>{label}</div>
      <div style={{ fontSize: 32, fontWeight: 700, color: accentText, lineHeight: 1.1, marginTop: 4 }}>{count}</div>
      <div style={{ fontSize: 11, color: '#64748b', marginTop: 4 }}>{sub}</div>
    </div>
  );
}
function Section({ title, testId, emptyMsg, children }) {
  return (
    <section data-testid={testId} style={{ marginBottom: 24 }}>
      <h3 style={{ fontSize: 14, fontWeight: 600, marginBottom: 8 }}>{title}</h3>
      {React.Children.count(children) > 0 && children}
      {!children && <p style={{ fontSize: 12, color: '#64748b' }}>{emptyMsg}</p>}
    </section>
  );
}
const th = { padding: '4px 6px' };
const td = { padding: '4px 6px' };
function riskBadge(level) {
  const [bg, fg] = level >= 4 ? ['#fee2e2', '#991b1b']
                  : level === 3 ? ['#fef3c7', '#92400e']
                  : ['#dbeafe', '#1e40af'];
  return { padding: '1px 6px', borderRadius: 4, fontSize: 10, fontWeight: 600, background: bg, color: fg };
}
function sevBadge(s) {
  const [bg, fg] = {
    critical: ['#7f1d1d', '#fef2f2'],
    high:     ['#fee2e2', '#991b1b'],
    medium:   ['#fef3c7', '#92400e'],
    low:      ['#dbeafe', '#1e40af'],
  }[s] || ['#f1f5f9', '#475569'];
  return { padding: '1px 6px', borderRadius: 4, fontSize: 10, fontWeight: 600, background: bg, color: fg, textTransform: 'capitalize' };
}

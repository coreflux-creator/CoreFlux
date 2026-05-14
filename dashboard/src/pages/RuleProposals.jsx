import React, { useState } from 'react';
import { Sparkles, Check, X, ChevronDown, ChevronRight, RefreshCw, AlertCircle, Loader2 } from 'lucide-react';
import { api, useApi } from '../lib/api';

/**
 * Rule Proposals — Phase 2 AI v1 review queue.
 *
 * Operators trigger an AI proposal, review the diff, accept/reject. Accepted
 * proposals stay queued in the DB (status='accepted'); Phase 2.1 will add
 * appliers that promote them into production rules.
 *
 * v1 ships ONE rule type ('ap_expense_category_map'). Adding another type
 * is a backend-only change (rcRegisterReplay + rpCurrentRule).
 */

const RULE_TYPES = [
  { key: 'ap_expense_category_map',
    label: 'AP expense account mapping',
    hint:  'AI looks at the last 30 AP-line categories and proposes tweaks to category→GL-account routing.' },
];

const STATUS_COLORS = {
  proposed:  { bg: '#e0e7ff', fg: '#3730a3' },
  competed:  { bg: '#fef3c7', fg: '#92400e' },
  accepted:  { bg: '#dcfce7', fg: '#15803d' },
  rejected:  { bg: '#fee2e2', fg: '#b91c1c' },
  applied:   { bg: '#cffafe', fg: '#0e7490' },
  error:     { bg: '#fee2e2', fg: '#b91c1c' },
};

export default function RuleProposals() {
  const { data, reload, loading } = useApi('/api/admin/rule_proposals.php?limit=100');
  const rows = data?.rows || [];
  const [busy, setBusy] = useState(null);
  const [error, setError] = useState(null);

  const propose = async (ruleType) => {
    setBusy('propose'); setError(null);
    try {
      await api.post('/api/admin/rule_proposals.php', { action: 'propose', rule_type: ruleType });
      reload();
    } catch (e) { setError(e?.message || 'propose failed'); }
    finally { setBusy(null); }
  };

  return (
    <section data-testid="rule-proposals-page" style={{ padding: 'var(--cf-space-3, 16px)' }}>
      <header style={{ display:'flex', alignItems:'center', justifyContent:'space-between', flexWrap:'wrap', gap:12, marginBottom:16 }}>
        <div>
          <h1 style={{ margin:0, fontSize:'1.5rem', display:'inline-flex', alignItems:'center', gap:8 }}>
            <Sparkles size={20}/> AI Rule Proposals
          </h1>
          <p style={{ margin:'4px 0 0', color:'#64748b', fontSize:13 }}>
            AI competes proposed rule changes against the current rule by replaying recent events.
            Review the diff; accept measured improvements, reject overreaches.
          </p>
        </div>
        <button onClick={reload} disabled={loading}
                data-testid="rule-proposals-refresh"
                className="btn btn--ghost"
                style={{ display:'inline-flex', alignItems:'center', gap:6, fontSize:12 }}>
          <RefreshCw size={12} className={loading ? 'cf-spin' : ''}/> Refresh
        </button>
      </header>

      <div style={{
        display:'grid', gridTemplateColumns:'repeat(auto-fit, minmax(280px,1fr))',
        gap:12, marginBottom:20,
      }} data-testid="rule-proposals-triggers">
        {RULE_TYPES.map(rt => (
          <div key={rt.key}
               data-testid={`rule-proposals-trigger-${rt.key}`}
               style={{ padding:14, border:'1px solid #e2e8f0', borderRadius:8, background:'#fff' }}>
            <div style={{ fontWeight:600, marginBottom:4 }}>{rt.label}</div>
            <p style={{ fontSize:12, color:'#64748b', margin:'0 0 10px' }}>{rt.hint}</p>
            <button onClick={() => propose(rt.key)} disabled={busy === 'propose'}
                    data-testid={`rule-proposals-propose-${rt.key}`}
                    className="btn btn--primary"
                    style={{ display:'inline-flex', alignItems:'center', gap:6, fontSize:13 }}>
              {busy === 'propose' ? <Loader2 size={12} className="cf-spin"/> : <Sparkles size={12}/>}
              Propose tweak
            </button>
          </div>
        ))}
      </div>

      {error && (
        <p className="error" data-testid="rule-proposals-error"
           style={{ color:'#dc2626', fontSize:13, padding:'8px 12px', background:'#fef2f2', borderRadius:6 }}>
          <AlertCircle size={12} style={{ verticalAlign:'middle', marginRight:4 }}/> {error}
        </p>
      )}

      {rows.length === 0 ? (
        <p data-testid="rule-proposals-empty" style={{ color:'#94a3b8', fontSize:13 }}>
          No proposals yet. Click "Propose tweak" above to ask the AI for a recommendation.
        </p>
      ) : (
        <ul style={{ listStyle:'none', padding:0, margin:0, display:'flex', flexDirection:'column', gap:8 }}
            data-testid="rule-proposals-list">
          {rows.map(row => (
            <ProposalCard key={row.id} row={row} onChange={reload}/>
          ))}
        </ul>
      )}
    </section>
  );
}

function ProposalCard({ row, onChange }) {
  const [open, setOpen]   = useState(false);
  const [busy, setBusy]   = useState(null);
  const [err, setErr]     = useState(null);
  const [notes, setNotes] = useState('');

  const status = row.status || 'proposed';
  const c = STATUS_COLORS[status] || { bg: '#f1f5f9', fg: '#475569' };

  const recompete = async () => {
    setBusy('compete'); setErr(null);
    try { await api.post('/api/admin/rule_proposals.php', { action:'compete', id: row.id }); onChange?.(); }
    catch (e) { setErr(e?.message || 'compete failed'); }
    finally { setBusy(null); }
  };

  const review = async (decision) => {
    setBusy(decision); setErr(null);
    try {
      await api.post('/api/admin/rule_proposals.php',
        { action:'review', id: row.id, decision, notes });
      onChange?.();
    } catch (e) { setErr(e?.message || 'review failed'); }
    finally { setBusy(null); }
  };

  const cmp = row.comparison_json || null;
  const diffPreview = cmp?.diff?.slice(0, 5) || [];

  return (
    <li data-testid={`rule-proposals-card-${row.id}`}
        style={{ border:'1px solid #e2e8f0', borderRadius:8, background:'#fff' }}>
      <button onClick={() => setOpen(o => !o)}
              data-testid={`rule-proposals-toggle-${row.id}`}
              style={{ width:'100%', textAlign:'left', background:'transparent', border:'none',
                       padding:'12px 14px', cursor:'pointer',
                       display:'flex', alignItems:'center', gap:10 }}>
        {open ? <ChevronDown size={14}/> : <ChevronRight size={14}/>}
        <span style={{ fontFamily:'monospace', fontSize:12, color:'#64748b' }}>#{row.id}</span>
        <span style={{ fontWeight:600 }}>{row.rule_type}</span>
        <span style={{
          padding:'2px 8px', borderRadius:999, fontSize:11, fontWeight:600,
          background:c.bg, color:c.fg, textTransform:'capitalize',
        }} data-testid={`rule-proposals-status-${row.id}`}>{status}</span>
        {cmp && (
          <span style={{ marginLeft:'auto', fontSize:12, color:'#475569' }}>
            <strong>{row.events_changed}</strong>/{row.events_compared} events ·
            ${Number(row.dollars_changed || 0).toLocaleString('en-US', { minimumFractionDigits:2, maximumFractionDigits:2 })} ·
            score <strong>{(row.score ?? 0).toFixed(2)}</strong>
          </span>
        )}
      </button>

      {open && (
        <div style={{ padding:'4px 14px 14px', fontSize:13 }}>
          {row.rationale && (
            <blockquote data-testid={`rule-proposals-rationale-${row.id}`}
                        style={{ margin:'4px 0 10px', padding:'8px 12px',
                                 borderLeft:'3px solid #94a3b8', background:'#f8fafc',
                                 color:'#334155', fontStyle:'italic' }}>
              {row.rationale}
            </blockquote>
          )}

          <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:10, marginBottom:10 }}>
            <RuleJsonBox label="Current rule"  json={row.current_rule_json}  testid={`rule-proposals-current-${row.id}`}/>
            <RuleJsonBox label="Proposed rule" json={row.proposed_rule_json} testid={`rule-proposals-proposed-${row.id}`}/>
          </div>

          {cmp && (
            <div data-testid={`rule-proposals-diff-${row.id}`} style={{ marginBottom:10 }}>
              <h4 style={{ fontSize:12, color:'#64748b', textTransform:'uppercase', letterSpacing:'.04em', margin:'0 0 6px' }}>
                Replay diff (showing {diffPreview.length} of {cmp.diff?.length || 0} changes)
              </h4>
              {cmp.diff?.length > 0 ? (
                <table style={{ width:'100%', fontSize:12, borderCollapse:'collapse' }}>
                  <thead>
                    <tr style={{ color:'#94a3b8', textAlign:'left' }}>
                      <th style={{ padding:'4px 6px' }}>Event</th>
                      <th style={{ padding:'4px 6px' }}>Category</th>
                      <th style={{ padding:'4px 6px', textAlign:'right' }}>$</th>
                      <th style={{ padding:'4px 6px' }}>From → To</th>
                    </tr>
                  </thead>
                  <tbody>
                    {diffPreview.map((d, i) => (
                      <tr key={i} style={{ borderTop:'1px solid #f1f5f9' }}
                          data-testid={`rule-proposals-diff-${row.id}-row-${i}`}>
                        <td style={{ padding:'4px 6px', fontFamily:'monospace', color:'#64748b' }}>{d.event_key}</td>
                        <td style={{ padding:'4px 6px' }}>{d.raw?.category || '—'}</td>
                        <td style={{ padding:'4px 6px', textAlign:'right' }}>${Number(d.dollars || 0).toFixed(2)}</td>
                        <td style={{ padding:'4px 6px', fontFamily:'monospace', color:'#0369a1' }}>{d.from} → {d.to}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              ) : (
                <p style={{ color:'#94a3b8', fontSize:12, margin:0 }}>
                  No events would have been routed differently. The proposed rule is effectively identical to the current rule.
                </p>
              )}
            </div>
          )}

          {err && (
            <p className="error" data-testid={`rule-proposals-card-error-${row.id}`}
               style={{ color:'#dc2626', fontSize:12 }}>{err}</p>
          )}

          {(status === 'proposed' || status === 'competed') && (
            <div style={{ display:'flex', alignItems:'center', gap:8, flexWrap:'wrap' }}>
              <input type="text" placeholder="Review notes (optional)"
                     value={notes} onChange={e => setNotes(e.target.value)}
                     data-testid={`rule-proposals-notes-${row.id}`}
                     style={{ flex:1, minWidth:200, padding:'6px 10px',
                              border:'1px solid #cbd5e1', borderRadius:6, fontSize:13 }}/>
              <button onClick={recompete} disabled={busy === 'compete'}
                      data-testid={`rule-proposals-recompete-${row.id}`}
                      className="btn btn--ghost" style={{ display:'inline-flex', alignItems:'center', gap:4, fontSize:12 }}>
                <RefreshCw size={12} className={busy === 'compete' ? 'cf-spin' : ''}/> Re-replay
              </button>
              <button onClick={() => review('reject')} disabled={busy}
                      data-testid={`rule-proposals-reject-${row.id}`}
                      className="btn btn--ghost"
                      style={{ display:'inline-flex', alignItems:'center', gap:4, color:'#b91c1c', fontSize:12 }}>
                <X size={12}/> Reject
              </button>
              <button onClick={() => review('accept')} disabled={busy}
                      data-testid={`rule-proposals-accept-${row.id}`}
                      className="btn btn--primary"
                      style={{ display:'inline-flex', alignItems:'center', gap:4, fontSize:13 }}>
                <Check size={12}/> Accept
              </button>
            </div>
          )}

          {row.reviewed_at && (
            <p style={{ marginTop:8, fontSize:11, color:'#94a3b8' }}
               data-testid={`rule-proposals-reviewed-${row.id}`}>
              Reviewed {new Date(row.reviewed_at.replace(' ', 'T') + 'Z').toLocaleString()}
              {row.review_notes ? ` — "${row.review_notes}"` : ''}
            </p>
          )}
        </div>
      )}
    </li>
  );
}

function RuleJsonBox({ label, json, testid }) {
  return (
    <div data-testid={testid} style={{ border:'1px solid #f1f5f9', borderRadius:6, padding:8 }}>
      <div style={{ fontSize:10, color:'#94a3b8', textTransform:'uppercase', letterSpacing:'.04em', marginBottom:4 }}>{label}</div>
      <pre style={{
        margin:0, fontSize:11, fontFamily:'monospace',
        maxHeight:160, overflow:'auto', background:'#f8fafc',
        padding:6, borderRadius:4, color:'#334155',
      }}>{json ? JSON.stringify(json, null, 2) : '—'}</pre>
    </div>
  );
}

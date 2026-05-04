import React, { useMemo, useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';

/**
 * Intercompany Elimination Worksheet —
 * month-end sanity check: did we book both sides of every IC transaction?
 *
 *  - Pairs table: A→B debits vs B→A credits; imbalances in red
 *  - Groups table: every IC split group with per-leg totals
 *  - Orphans: IC-tagged lines that are NOT part of an IC group (manual
 *    tags to be reviewed)
 *  - AI narrative (on demand) summarising imbalance / orphan patterns
 *  - CSV export per section
 */
export default function EliminationWorksheet() {
  const [from, setFrom] = useState(() => new Date(new Date().getFullYear(), 0, 1).toISOString().slice(0, 10));
  const [to, setTo]     = useState(() => new Date().toISOString().slice(0, 10));
  const [aiBusy, setAiBusy]       = useState(false);
  const [aiNarr, setAiNarr]       = useState(null);
  const [aiError, setAiError]     = useState(null);

  const qs = new URLSearchParams({ action: 'elimination_worksheet', from, to }).toString();
  const { data, loading, error, reload } = useApi(`/modules/accounting/api/intercompany.php?${qs}`);

  const summary = data?.summary || {};
  const imbalancedPairs = useMemo(() => (data?.pairs || []).filter(p => Math.abs(p.imbalance_signed) > 0.005), [data]);
  const balancedPairs   = useMemo(() => (data?.pairs || []).filter(p => Math.abs(p.imbalance_signed) < 0.005), [data]);

  const downloadPairsCsv = () => {
    const rows = ['from_entity_id,to_entity_id,from_debit,from_credit,to_debit,to_credit,imbalance'];
    (data?.pairs || []).forEach(p => rows.push([p.from_entity_id, p.to_entity_id, p.from_total_debit, p.from_total_credit, p.to_total_debit, p.to_total_credit, p.imbalance_signed].join(',')));
    const blob = new Blob([rows.join('\n')], { type: 'text/csv' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `ic-pairs-${to}.csv`;
    document.body.appendChild(a); a.click(); a.remove();
  };

  const generateNarrative = async () => {
    setAiBusy(true); setAiError(null);
    try {
      // Reuse the reconciliation narrative pipeline-style call: send the
      // summary + imbalanced pairs as context. aiAsk is exposed via a
      // generic endpoint? No — we'll pipe via an ad-hoc narrative endpoint.
      const res = await api.post('/modules/accounting/api/intercompany.php?action=narrate_elimination', { from, to });
      setAiNarr(res);
    } catch (e) { setAiError(e.message); }
    finally { setAiBusy(false); }
  };

  return (
    <section data-testid="accounting-ic-elimination">
      <h2 style={{ margin: '0 0 8px' }}>Intercompany elimination worksheet</h2>
      <p style={{ fontSize: 13, color: '#666', maxWidth: 720 }}>
        Pre-close sanity check — for every IC leg in Entity A's books, does Entity B's books have a matching offset? Imbalances flag either a missing post or a dollar drift.
      </p>

      <div style={{ display: 'flex', gap: 8, alignItems: 'end', marginBottom: 16 }}>
        <label style={{ fontSize: 13 }}>From<input type="date" className="input" value={from} onChange={e => setFrom(e.target.value)} data-testid="accounting-ic-elim-from" style={{display:'block'}} /></label>
        <label style={{ fontSize: 13 }}>To<input type="date" className="input" value={to} onChange={e => setTo(e.target.value)} data-testid="accounting-ic-elim-to" style={{display:'block'}} /></label>
        <button className="btn btn--ghost" onClick={() => reload()} data-testid="accounting-ic-elim-refresh">Refresh</button>
        <button className="btn btn--ghost" onClick={downloadPairsCsv} data-testid="accounting-ic-elim-csv">⬇ Pairs CSV</button>
        <button className="btn btn--ghost" onClick={generateNarrative} disabled={aiBusy} data-testid="accounting-ic-elim-narrate">
          {aiBusy ? 'Generating…' : '✨ Summarize'}
        </button>
      </div>

      {loading && <p>Loading…</p>}
      {error && <p className="error">{error.message}</p>}
      {data && (
        <>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 8, marginBottom: 16 }}>
            <Stat label="IC groups"        value={summary.group_count} testId="accounting-ic-elim-stat-groups" />
            <Stat label="Entity pairs"     value={summary.pair_count} testId="accounting-ic-elim-stat-pairs" />
            <Stat label="⚠ Imbalanced"    value={summary.imbalanced_pairs} testId="accounting-ic-elim-stat-imbalanced" warn={summary.imbalanced_pairs > 0} />
            <Stat label="⚠ Orphan lines" value={summary.orphan_line_count} testId="accounting-ic-elim-stat-orphans" warn={summary.orphan_line_count > 0} />
          </div>

          {aiError && <p className="error">{aiError}</p>}
          {aiNarr && (
            <div data-testid="accounting-ic-elim-narrative" style={{ background:'#f9fafb', border:'1px solid #e5e7eb', padding:12, borderRadius:6, whiteSpace:'pre-wrap', marginBottom:16 }}>
              <div style={{ fontSize: 11, color:'#666', marginBottom: 6 }}>✨ AI summary — review before using externally</div>
              {aiNarr.content || aiNarr.ai_response || JSON.stringify(aiNarr)}
            </div>
          )}

          <h3 style={{fontSize: 14, margin: '0 0 8px'}}>Entity pair balance</h3>
          <table className="data-table" data-testid="accounting-ic-elim-pairs-table">
            <thead><tr><th>From → To</th><th style={{textAlign:'right'}}>From Dr</th><th style={{textAlign:'right'}}>From Cr</th><th style={{textAlign:'right'}}>To Dr</th><th style={{textAlign:'right'}}>To Cr</th><th style={{textAlign:'right'}}>Imbalance</th><th>Lines</th></tr></thead>
            <tbody>
              {imbalancedPairs.length === 0 && balancedPairs.length === 0 && (
                <tr><td colSpan={7} style={{color:'#999'}} data-testid="accounting-ic-elim-pairs-empty">No intercompany activity in this period.</td></tr>
              )}
              {imbalancedPairs.map((p, i) => (
                <tr key={`imb-${i}`} data-testid={`accounting-ic-elim-pair-imb-${i}`} style={{ background: '#fef2f2' }}>
                  <td><code>{p.from_entity_id}</code> → <code>{p.to_entity_id}</code></td>
                  <td style={{textAlign:'right'}}>{fmt(p.from_total_debit)}</td>
                  <td style={{textAlign:'right'}}>{fmt(p.from_total_credit)}</td>
                  <td style={{textAlign:'right'}}>{fmt(p.to_total_debit)}</td>
                  <td style={{textAlign:'right'}}>{fmt(p.to_total_credit)}</td>
                  <td style={{textAlign:'right', color:'#991b1b', fontWeight:600}}>{fmt(p.imbalance_signed)}</td>
                  <td style={{fontSize:11}}>{p.line_count_from} / {p.line_count_to}</td>
                </tr>
              ))}
              {balancedPairs.map((p, i) => (
                <tr key={`bal-${i}`} data-testid={`accounting-ic-elim-pair-bal-${i}`}>
                  <td><code>{p.from_entity_id}</code> → <code>{p.to_entity_id}</code></td>
                  <td style={{textAlign:'right'}}>{fmt(p.from_total_debit)}</td>
                  <td style={{textAlign:'right'}}>{fmt(p.from_total_credit)}</td>
                  <td style={{textAlign:'right'}}>{fmt(p.to_total_debit)}</td>
                  <td style={{textAlign:'right'}}>{fmt(p.to_total_credit)}</td>
                  <td style={{textAlign:'right', color:'#065f46'}}>✓</td>
                  <td style={{fontSize:11}}>{p.line_count_from} / {p.line_count_to}</td>
                </tr>
              ))}
            </tbody>
          </table>

          <h3 style={{fontSize: 14, margin: '16px 0 8px'}}>IC groups ({(data.groups || []).length})</h3>
          <table className="data-table" data-testid="accounting-ic-elim-groups-table">
            <thead><tr><th>Group</th><th>Legs</th><th>Entities</th><th style={{textAlign:'right'}}>Sum debit</th><th style={{textAlign:'right'}}>Sum credit</th></tr></thead>
            <tbody>
              {(data.groups || []).length === 0 && <tr><td colSpan={5} style={{color:'#999'}}>No IC groups yet. Post a split transaction to create one.</td></tr>}
              {(data.groups || []).map(g => {
                const td = g.legs.reduce((s, l) => s + Number(l.debit_total || 0), 0);
                const tc = g.legs.reduce((s, l) => s + Number(l.credit_total || 0), 0);
                return (
                  <tr key={g.group_id} data-testid={`accounting-ic-elim-group-${g.group_id.slice(0,8)}`}>
                    <td><code style={{fontSize:11}}>{g.group_id.slice(0, 12)}…</code></td>
                    <td>{g.leg_count}</td>
                    <td>{g.legs.map(l => l.entity_id).join(', ')}</td>
                    <td style={{textAlign:'right'}}>{fmt(td)}</td>
                    <td style={{textAlign:'right'}}>{fmt(tc)}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>

          {(data.orphans || []).length > 0 && (
            <>
              <h3 style={{fontSize: 14, margin: '16px 0 8px', color: '#991b1b'}}>⚠ Orphan IC-tagged lines (not in a group — review)</h3>
              <table className="data-table" data-testid="accounting-ic-elim-orphans-table">
                <thead><tr><th>Date</th><th>JE</th><th>Source entity</th><th>Counterparty</th><th>Account</th><th style={{textAlign:'right'}}>Signed $</th></tr></thead>
                <tbody>
                  {data.orphans.map(o => (
                    <tr key={o.line_id}>
                      <td>{o.posting_date}</td>
                      <td>#{o.je_id}</td>
                      <td><code>{o.entity_id}</code></td>
                      <td><code>{o.counterparty_entity_id}</code></td>
                      <td><code>{o.account_code}</code></td>
                      <td style={{textAlign:'right'}}>{fmt(o.amount_signed)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </>
          )}
        </>
      )}
    </section>
  );
}

function Stat({ label, value, testId, warn }) {
  return (
    <div data-testid={testId} style={{
      padding: 12, borderRadius: 6, background: warn ? '#fef2f2' : '#f3f4f6',
      border: '1px solid ' + (warn ? '#fecaca' : '#e5e7eb'),
    }}>
      <div style={{fontSize: 11, color: '#666'}}>{label}</div>
      <div style={{fontSize: 22, fontWeight: 600, color: warn ? '#991b1b' : '#111'}}>{value ?? '—'}</div>
    </div>
  );
}

function fmt(n) {
  const v = parseFloat(n);
  if (Number.isNaN(v)) return '—';
  return v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

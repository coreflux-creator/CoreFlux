import React, { useMemo, useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';

/**
 * Consolidation page — two halves:
 *
 *  (1) Entity relationships — directional ownership edges (parent → child
 *      with ownership_pct + relationship_type + consolidation_method +
 *      effective_from/to).
 *  (2) Consolidated P&L / BS / TB viewer — pick entities or an ownership
 *      root, select report type and period, render the consolidated view
 *      with intercompany eliminations applied inline.
 */
export default function Consolidation() {
  return (
    <section data-testid="accounting-consolidation">
      <h2 style={{ margin: '0 0 8px' }}>Consolidation</h2>
      <p style={{ fontSize: 13, color: '#666', maxWidth: 720, margin: '0 0 16px' }}>
        Define ownership structure, then view consolidated financials with intercompany eliminations applied automatically. Uses the same IC-tagged lines you post via the split dialog.
      </p>
      <RelationshipsSection />
      <hr style={{ margin: '24px 0', border: 'none', borderTop: '1px solid #e5e7eb' }} />
      <ConsolidatedReport />
    </section>
  );
}

function RelationshipsSection() {
  const { data, loading, error, reload } = useApi('/modules/accounting/api/entity_relationships.php');
  const entities = useApi('/modules/accounting/api/entities.php').data?.rows
                || useApi('/modules/accounting/api/entities.php').data?.entities
                || [];
  const [form, setForm] = useState({
    parent_entity_id: '', child_entity_id: '', ownership_pct: '100',
    relationship_type: 'subsidiary', consolidation_method: 'full',
    effective_from: new Date().toISOString().slice(0, 10), effective_to: '', notes: '',
  });
  const [busy, setBusy] = useState(false);
  const [err, setErr]   = useState(null);

  const save = async (e) => {
    e.preventDefault();
    setBusy(true); setErr(null);
    try {
      await api.post('/modules/accounting/api/entity_relationships.php', {
        ...form,
        parent_entity_id: Number(form.parent_entity_id),
        child_entity_id: Number(form.child_entity_id),
        ownership_pct: parseFloat(form.ownership_pct),
        effective_to: form.effective_to || null,
      });
      setForm({ ...form, child_entity_id: '', ownership_pct: '100', notes: '' });
      reload();
    } catch (e2) { setErr(e2.message); }
    finally { setBusy(false); }
  };
  const remove = async (id) => {
    if (!window.confirm('Deactivate this relationship?')) return;
    try { await api.delete(`/modules/accounting/api/entity_relationships.php?id=${id}`); reload(); }
    catch (e) { alert(e.message); }
  };

  return (
    <div data-testid="accounting-consol-relationships">
      <h3 style={{fontSize:14,margin:'0 0 8px'}}>Ownership structure</h3>
      <form onSubmit={save} style={{ display:'grid', gridTemplateColumns:'repeat(5,1fr) auto', gap:8, alignItems:'end', padding:12, background:'#f9fafb', borderRadius:6, marginBottom:16 }}>
        <label style={{fontSize:12}}>Parent
          <select className="input" value={form.parent_entity_id} onChange={e => setForm({...form, parent_entity_id:e.target.value})} required data-testid="accounting-consol-parent">
            <option value="">— select —</option>
            {entities.map(en => <option key={en.id} value={en.id}>{en.legal_name || en.code}</option>)}
          </select>
        </label>
        <label style={{fontSize:12}}>Child
          <select className="input" value={form.child_entity_id} onChange={e => setForm({...form, child_entity_id:e.target.value})} required data-testid="accounting-consol-child">
            <option value="">— select —</option>
            {entities.map(en => <option key={en.id} value={en.id}>{en.legal_name || en.code}</option>)}
          </select>
        </label>
        <label style={{fontSize:12}}>Ownership %
          <input className="input" type="number" step="0.01" min="0" max="100" value={form.ownership_pct} onChange={e => setForm({...form, ownership_pct:e.target.value})} required data-testid="accounting-consol-pct" />
        </label>
        <label style={{fontSize:12}}>Type
          <select className="input" value={form.relationship_type} onChange={e => setForm({...form, relationship_type:e.target.value})} data-testid="accounting-consol-type">
            <option value="subsidiary">Subsidiary</option><option value="affiliate">Affiliate</option>
            <option value="branch">Branch</option><option value="jv">Joint venture</option>
            <option value="other">Other</option>
          </select>
        </label>
        <label style={{fontSize:12}}>Method
          <select className="input" value={form.consolidation_method} onChange={e => setForm({...form, consolidation_method:e.target.value})} data-testid="accounting-consol-method">
            <option value="full">Full</option><option value="equity">Equity</option>
            <option value="cost">Cost (exclude)</option><option value="none">None (exclude)</option>
          </select>
        </label>
        <button type="submit" className="btn btn--primary" disabled={busy} data-testid="accounting-consol-save">{busy ? '…' : 'Save edge'}</button>
      </form>
      {err && <p className="error">{err}</p>}
      {loading && <p>Loading…</p>}
      {error && <p className="error">{error.message}</p>}
      <table className="data-table" data-testid="accounting-consol-edges-table">
        <thead><tr><th>Parent</th><th>Child</th><th>%</th><th>Type</th><th>Method</th><th>Effective</th><th>Status</th><th></th></tr></thead>
        <tbody>
          {(data?.rows || []).length === 0 && !loading && (
            <tr><td colSpan={8} style={{color:'#999'}}>No ownership edges yet. Add parent → child to enable consolidated reporting.</td></tr>
          )}
          {(data?.rows || []).map(r => (
            <tr key={r.id} data-testid={`accounting-consol-edge-${r.id}`}>
              <td>{r.parent_name || r.parent_entity_id}</td>
              <td>{r.child_name  || r.child_entity_id}</td>
              <td>{Number(r.ownership_pct).toFixed(2)}%</td>
              <td>{r.relationship_type}</td>
              <td>{r.consolidation_method}</td>
              <td style={{fontSize:11}}>{r.effective_from}{r.effective_to ? ` → ${r.effective_to}` : ''}</td>
              <td><span className="badge">{Number(r.active) === 1 ? 'active' : 'inactive'}</span></td>
              <td>{Number(r.active) === 1 && <button className="btn btn--ghost" onClick={() => remove(r.id)} data-testid={`accounting-consol-remove-${r.id}`}>Deactivate</button>}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function ConsolidatedReport() {
  const [reportType, setType] = useState('income_statement');
  const [entityIds, setEntityIds] = useState([]);
  const [from, setFrom] = useState(() => new Date(new Date().getFullYear(), 0, 1).toISOString().slice(0, 10));
  const [to, setTo]     = useState(() => new Date().toISOString().slice(0, 10));
  const entities = useApi('/modules/accounting/api/entities.php').data?.rows
                || useApi('/modules/accounting/api/entities.php').data?.entities
                || [];

  const qs = useMemo(() => {
    if (!entityIds.length) return null;
    const params = new URLSearchParams({
      type: reportType,
      consolidate: '1',
      entity_ids: entityIds.join(','),
    });
    if (reportType === 'balance_sheet' || reportType === 'trial_balance') params.set('as_of', to);
    else { params.set('from', from); params.set('to', to); }
    return params.toString();
  }, [reportType, entityIds, from, to]);

  const { data, loading, error } = useApi(qs ? `/modules/accounting/api/reports.php?${qs}` : null);

  const toggleEntity = (id) => {
    setEntityIds(prev => prev.includes(id) ? prev.filter(x => x !== id) : [...prev, id]);
  };

  return (
    <div data-testid="accounting-consol-report">
      <h3 style={{fontSize:14,margin:'0 0 8px'}}>Consolidated report</h3>

      <div style={{ display:'flex', gap:8, alignItems:'end', marginBottom:12, flexWrap:'wrap' }}>
        <label style={{fontSize:12}}>Report
          <select className="input" value={reportType} onChange={e => setType(e.target.value)} data-testid="accounting-consol-report-type">
            <option value="income_statement">Income Statement</option>
            <option value="balance_sheet">Balance Sheet</option>
            <option value="trial_balance">Trial Balance</option>
          </select>
        </label>
        {reportType === 'income_statement' && (
          <label style={{fontSize:12}}>From<input type="date" className="input" value={from} onChange={e => setFrom(e.target.value)} data-testid="accounting-consol-from" /></label>
        )}
        <label style={{fontSize:12}}>{reportType === 'income_statement' ? 'To' : 'As of'}
          <input type="date" className="input" value={to} onChange={e => setTo(e.target.value)} data-testid="accounting-consol-to" />
        </label>
      </div>

      <div style={{ background:'#f9fafb', padding:12, borderRadius:6, marginBottom:12 }}>
        <strong style={{fontSize:12}}>Entities in scope:</strong>
        <div style={{display:'flex', flexWrap:'wrap', gap:6, marginTop:6}} data-testid="accounting-consol-entity-picker">
          {entities.map(en => (
            <label key={en.id} style={{fontSize:12, padding:'2px 8px', background: entityIds.includes(en.id) ? '#dbeafe' : '#fff', border:'1px solid #ddd', borderRadius:4, cursor:'pointer'}}>
              <input type="checkbox" checked={entityIds.includes(en.id)} onChange={() => toggleEntity(en.id)} style={{marginRight:4}} data-testid={`accounting-consol-entity-${en.id}`} />
              {en.legal_name || en.code}
            </label>
          ))}
        </div>
      </div>

      {!qs && <p style={{color:'#666'}}>Pick at least one entity to run the consolidation.</p>}
      {loading && <p>Running consolidation…</p>}
      {error && <p className="error">{error.message}</p>}
      {data && (
        <div style={{ display:'flex', gap:8, alignItems:'center', marginBottom:8 }}>
          <button
            className="btn btn--primary"
            disabled={lockBusy}
            data-testid="accounting-consol-lock"
            onClick={async () => {
              if (!window.confirm('Lock this consolidation? A snapshot will be persisted; you\'ll need to reverse it with a reason to re-lock for the same period.')) return;
              setLockBusy(true); setLockErr(null);
              try {
                const res = await api.post('/modules/accounting/api/consolidation_runs.php?action=lock', {
                  report_type: reportType,
                  entity_ids: entityIds,
                  period_from: reportType === 'income_statement' ? from : null,
                  period_to:   to,
                  notes: `Locked from Consolidation UI`,
                });
                setLockedId(res?.id || null);
                runsApi.reload();
              } catch (e) { setLockErr(e.message); }
              finally { setLockBusy(false); }
            }}
          >{lockBusy ? 'Locking…' : '🔒 Lock & publish'}</button>
          {lockedId && <span style={{fontSize:12,color:'#065f46'}} data-testid="accounting-consol-lock-success">✓ Locked as run #{lockedId}</span>}
          {lockErr && <span style={{fontSize:12,color:'#991b1b'}}>{lockErr}</span>}
        </div>
      )}
      {data && reportType === 'income_statement' && <IsView data={data} />}
      {data && reportType === 'balance_sheet'    && <BsView data={data} />}
      {data && reportType === 'trial_balance'    && <TbView data={data} />}

      {runsApi.data?.rows?.length > 0 && (
        <div style={{marginTop:24}} data-testid="accounting-consol-runs">
          <h4 style={{fontSize:13, margin:'0 0 6px'}}>Past {reportType.replace('_',' ')} runs</h4>
          <table className="data-table" style={{fontSize:12}}>
            <thead><tr><th>ID</th><th>Period</th><th>Entities</th><th>Status</th><th>Locked</th><th>Reversed</th><th></th></tr></thead>
            <tbody>
              {runsApi.data.rows.map(r => (
                <tr key={r.id} data-testid={`accounting-consol-run-${r.id}`}>
                  <td>#{r.id}</td>
                  <td>{r.period_from ? `${r.period_from} → ` : ''}{r.period_to}</td>
                  <td>{(JSON.parse(r.entity_ids_json || '[]') || []).join(', ')}</td>
                  <td><span className="badge">{r.status}</span></td>
                  <td>{r.locked_at || '—'}</td>
                  <td>{r.reversed_at || '—'}</td>
                  <td>{r.status === 'locked' && (
                    <button
                      className="btn btn--ghost"
                      data-testid={`accounting-consol-run-reverse-${r.id}`}
                      onClick={async () => {
                        const reason = prompt('Reason for reversing this locked run?');
                        if (!reason) return;
                        try {
                          await api.post(`/modules/accounting/api/consolidation_runs.php?action=reverse&id=${r.id}`, { reason });
                          runsApi.reload();
                        } catch (e) { alert(e.message); }
                      }}
                    >Reverse</button>
                  )}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

function IsView({ data }) {
  return (
    <div data-testid="accounting-consol-is">
      <p style={{fontSize:12, color:'#666'}}>Consolidated P&L · {data.period?.from} → {data.period?.to} · entities {(data.period?.entity_ids || []).join(', ')}</p>
      <table className="data-table">
        <thead><tr><th>Account</th><th>Name</th><th style={{textAlign:'right'}}>Amount</th><th style={{textAlign:'right'}}>Elim</th></tr></thead>
        <tbody>
          <tr style={{background:'#f3f4f6'}}><td colSpan={4}><strong>Revenue</strong></td></tr>
          {data.revenue.map(r => <tr key={'r'+r.code}><td><code>{r.code}</code></td><td>{r.name}</td><td style={{textAlign:'right'}}>{fmt(r.amount)}</td><td style={{textAlign:'right',color:'#b91c1c'}}>{r.amount_elim ? `(${fmt(r.amount_elim)})` : ''}</td></tr>)}
          <tr><td colSpan={2} style={{fontWeight:600}}>Total revenue</td><td style={{textAlign:'right',fontWeight:600}}>{fmt(data.total_revenue)}</td><td></td></tr>
          <tr style={{background:'#f3f4f6'}}><td colSpan={4}><strong>Expense</strong></td></tr>
          {data.expense.map(r => <tr key={'e'+r.code}><td><code>{r.code}</code></td><td>{r.name}</td><td style={{textAlign:'right'}}>{fmt(r.amount)}</td><td style={{textAlign:'right',color:'#b91c1c'}}>{r.amount_elim ? `(${fmt(r.amount_elim)})` : ''}</td></tr>)}
          <tr><td colSpan={2} style={{fontWeight:600}}>Total expense</td><td style={{textAlign:'right',fontWeight:600}}>{fmt(data.total_expense)}</td><td></td></tr>
          <tr style={{background: data.net_income >= 0 ? '#ecfdf5' : '#fef2f2'}}><td colSpan={2} style={{fontWeight:700}}>Net income</td><td style={{textAlign:'right',fontWeight:700}} data-testid="accounting-consol-net-income">{fmt(data.net_income)}</td><td></td></tr>
        </tbody>
      </table>
    </div>
  );
}

function BsView({ data }) {
  return (
    <div data-testid="accounting-consol-bs">
      <p style={{fontSize:12, color:'#666'}}>Consolidated BS · as of {data.as_of} · entities {(data.entities || []).join(', ')}</p>
      <table className="data-table">
        <thead><tr><th>Account</th><th>Name</th><th style={{textAlign:'right'}}>Balance</th><th style={{textAlign:'right'}}>Elim Dr/Cr</th></tr></thead>
        <tbody>
          {[['assets','Assets'],['liabilities','Liabilities'],['equity','Equity']].map(([k,label]) => (
            <React.Fragment key={k}>
              <tr style={{background:'#f3f4f6'}}><td colSpan={4}><strong>{label}</strong></td></tr>
              {(data[k] || []).map(r => (
                <tr key={k+r.code}><td><code>{r.code}</code></td><td>{r.name}</td><td style={{textAlign:'right'}}>{fmt(r.balance_signed)}</td><td style={{textAlign:'right',color:'#b91c1c',fontSize:11}}>{(r.debit_elim || r.credit_elim) ? `${fmt(r.debit_elim)} / ${fmt(r.credit_elim)}` : ''}</td></tr>
              ))}
              <tr><td colSpan={2} style={{fontWeight:600}}>Total {label.toLowerCase()}</td><td style={{textAlign:'right',fontWeight:600}}>{fmt(data['total_'+k])}</td><td></td></tr>
            </React.Fragment>
          ))}
          {Number(data.nci_equity || 0) !== 0 && (
            <>
              <tr style={{background:'#fef3c7'}}>
                <td colSpan={2} style={{fontWeight:600}}>  Controlling interest equity</td>
                <td style={{textAlign:'right',fontWeight:600}} data-testid="accounting-consol-controlling-equity">{fmt(data.controlling_equity)}</td>
                <td></td>
              </tr>
              <tr style={{background:'#fef3c7'}}>
                <td colSpan={2} style={{fontWeight:600}}>  Non-controlling interest (NCI)</td>
                <td style={{textAlign:'right',fontWeight:600}} data-testid="accounting-consol-nci-equity">{fmt(data.nci_equity)}</td>
                <td style={{fontSize:11,color:'#666'}}>{(data.nci_detail || []).map(d => `E${d.entity_id} @ ${d.ownership_pct}%`).join(' · ')}</td>
              </tr>
            </>
          )}
        </tbody>
      </table>
    </div>
  );
}

function TbView({ data }) {
  return (
    <div data-testid="accounting-consol-tb">
      <p style={{fontSize:12, color:'#666'}}>Consolidated TB · as of {data.as_of} · entities {(data.entities || []).join(', ')} · {(data.eliminations || []).length} elimination groups</p>
      <table className="data-table">
        <thead><tr><th>Code</th><th>Name</th><th style={{textAlign:'right'}}>Gross Dr</th><th style={{textAlign:'right'}}>Gross Cr</th><th style={{textAlign:'right'}}>Elim Dr</th><th style={{textAlign:'right'}}>Elim Cr</th><th style={{textAlign:'right'}}>Signed</th></tr></thead>
        <tbody>
          {(data.rows || []).map(r => (
            <tr key={r.code}>
              <td><code>{r.code}</code></td><td>{r.name}</td>
              <td style={{textAlign:'right'}}>{fmt(r.debit_gross)}</td>
              <td style={{textAlign:'right'}}>{fmt(r.credit_gross)}</td>
              <td style={{textAlign:'right',color:'#b91c1c'}}>{r.debit_elim  ? fmt(r.debit_elim)  : '—'}</td>
              <td style={{textAlign:'right',color:'#b91c1c'}}>{r.credit_elim ? fmt(r.credit_elim) : '—'}</td>
              <td style={{textAlign:'right',fontWeight:600}}>{fmt(r.balance_signed)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function fmt(n) {
  const v = parseFloat(n);
  if (Number.isNaN(v)) return '—';
  return v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

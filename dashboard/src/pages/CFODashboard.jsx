import React, { useEffect, useMemo, useState, useCallback } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { api, useApi } from '../lib/api';
import { fmtMoney } from '../lib/format';
import Sparkline from '../components/Sparkline';
import CIStatusBadge from '../components/CIStatusBadge';
import FscHealthPanel from '../components/FscHealthPanel';
import QboSyncHealthTile from '../components/QboSyncHealthTile';
import ApprovalMixTile from '../components/ApprovalMixTile';
import RevenueStreamWidget from '../components/RevenueStreamWidget';
import PwpReleasedNudge from '../components/PwpReleasedNudge';
import {
  TrendingUp, TrendingDown, Calendar, Save, Send, Sparkles, StickyNote, X,
  Eye, EyeOff, ArrowUpDown, Plus, Trash2, Loader2, FileText,
} from 'lucide-react';

/**
 * CFODashboard — high-level CFO surface.
 *
 * Pulls the existing /api/exec_dashboard.php payload, plus:
 *   - finance.dso / dpo / unapplied_cash    (new CFO scalars)
 *   - staffing.upcoming_starts / upcoming_terminations
 *   - compare.scalars (prior_period or prior_year deltas)
 *
 * Layers on:
 *   - Per-widget AI annotation (POST /api/cfo_annotate)
 *   - Per-widget user notes    (CRUD /api/cfo_notes)
 *   - Custom formula widgets   (CRUD /api/cfo_formulas + ?action=evaluate)
 *   - Saved views with widget visibility + ordering (PATCH /api/exec_dashboard_views)
 *   - Send-report email        (POST /api/cfo_send_report)
 *
 * All widget keys are stable strings so notes/annotations/order survive renames.
 */

// ---------- widget registry --------------------------------------------------
const WIDGETS = [
  { key: 'finance.revenue',        title: 'Revenue (YTD)',          group: 'finance' },
  { key: 'finance.margin',         title: 'Gross Margin (YTD)',     group: 'finance' },
  { key: 'finance.ar_aging',       title: 'AR Aging',               group: 'finance' },
  { key: 'finance.ap_aging',       title: 'AP Aging',               group: 'finance' },
  { key: 'finance.dso',            title: 'Days Sales Outstanding', group: 'finance' },
  { key: 'finance.dpo',            title: 'Days Payable Outstanding',group:'finance' },
  { key: 'finance.unapplied_cash', title: 'Unapplied Cash',         group: 'finance' },
  { key: 'finance.payroll',        title: 'Payroll (YTD)',          group: 'finance' },
  { key: 'staffing.headcount',     title: 'Headcount',              group: 'staffing'},
  { key: 'staffing.upcoming',      title: 'Upcoming Starts / Terms',group: 'staffing'},
  { key: 'staffing.new_starts',    title: 'New Starts',             group: 'staffing'},
  { key: 'staffing.terminations',  title: 'Terminations',           group: 'staffing'},
  { key: 'staffing.placements',    title: 'Placements',             group: 'staffing'},
];

const DEFAULT_VISIBLE = WIDGETS.map(w => w.key);
const COMPARE_LABELS  = { prior_period: 'vs prior period', prior_year: 'vs same period last yr' };
const WINDOW_PRESETS  = [4, 12, 26, 52, 104];

// ---------- formatters -------------------------------------------------------
const fmt = {
  money:   v => (v == null ? '—' : fmtMoney(v)),
  number:  v => (v == null ? '—' : Number(v).toLocaleString(undefined, { maximumFractionDigits: 1 })),
  percent: v => (v == null ? '—' : (Number(v).toFixed(1) + '%')),
  ratio:   v => (v == null ? '—' : Number(v).toFixed(2)),
  days:    v => (v == null ? '—' : (Number(v).toFixed(1) + ' days')),
};

function deltaPct(curr, prev) {
  if (prev == null || prev === 0) return null;
  if (curr == null) return null;
  return ((curr - prev) / prev) * 100;
}

function DeltaBadge({ value }) {
  if (value == null) return null;
  const pos  = value >= 0;
  const Icon = pos ? TrendingUp : TrendingDown;
  const color = Math.abs(value) < 1 ? '#64748b' : (pos ? '#16a34a' : '#dc2626');
  return (
    <span style={{ display:'inline-flex', alignItems:'center', gap:4, color, fontSize:12, fontWeight:600 }}
          data-testid="cfo-delta-badge">
      <Icon size={12}/> {pos ? '+' : ''}{value.toFixed(1)}%
    </span>
  );
}

// ---------- main component ---------------------------------------------------
export default function CFODashboard({ session }) {
  const location = useLocation();
  const navigate = useNavigate();
  const params   = new URLSearchParams(location.search);

  const [weeks,    setWeeks]    = useState(parseInt(params.get('weeks') || '12', 10));
  const [compare,  setCompare]  = useState(params.get('compare') || 'prior_period');
  const [viewId,   setViewId]   = useState(parseInt(params.get('view') || '0', 10) || 0);
  const [visible,  setVisible]  = useState(new Set(DEFAULT_VISIBLE));
  const [order,    setOrder]    = useState(DEFAULT_VISIBLE);
  const [editMode, setEditMode] = useState(false);
  const [showSend, setShowSend] = useState(false);
  const [showSave, setShowSave] = useState(false);
  const [showFormulas, setShowFormulas] = useState(false);

  const qs = `weeks=${weeks}&compare=${compare}`;
  const exec = useApi(`/api/exec_dashboard.php?${qs}`, [weeks, compare]);
  const data = exec.data || {};

  const views    = useApi('/api/exec_dashboard_views.php');
  const formulas = useApi('/api/cfo_formulas.php');
  const notes    = useApi(`/api/cfo_notes.php?view_id=${viewId}`, [viewId]);

  // Apply view config when the user selects one.
  useEffect(() => {
    if (!viewId) return;
    const v = (views.data?.views || []).find(x => x.id === viewId);
    if (!v) return;
    if (v.filters?.weeks) setWeeks(Number(v.filters.weeks));
    if (v.widget_config) {
      if (Array.isArray(v.widget_config.visible)) setVisible(new Set(v.widget_config.visible));
      if (Array.isArray(v.widget_config.order))   setOrder(v.widget_config.order);
    }
  }, [viewId, views.data]);

  const noteByKey = useMemo(() => {
    const m = {};
    for (const n of (notes.data?.notes || [])) {
      if (!m[n.widget_key] || new Date(n.updated_at) > new Date(m[n.widget_key].updated_at)) m[n.widget_key] = n;
    }
    return m;
  }, [notes.data]);

  const widgetsResolved = order
    .map(key => WIDGETS.find(w => w.key === key))
    .filter(Boolean)
    .concat(WIDGETS.filter(w => !order.includes(w.key)));   // append any new widgets

  const toggleVisible = key => {
    const next = new Set(visible);
    next.has(key) ? next.delete(key) : next.add(key);
    setVisible(next);
  };
  const moveWidget = (key, dir) => {
    const i = order.indexOf(key);
    if (i < 0) return;
    const j = i + dir;
    if (j < 0 || j >= order.length) return;
    const next = [...order];
    [next[i], next[j]] = [next[j], next[i]];
    setOrder(next);
  };

  return (
    <div className="cfo-dashboard" data-testid="cfo-dashboard" style={{ padding:'var(--cf-space-4, 24px)' }}>
      <header style={{
        display:'flex', flexWrap:'wrap', alignItems:'flex-end',
        justifyContent:'space-between', gap:12,
        position:'sticky', top:0, zIndex:5,
        background: 'linear-gradient(180deg, #fff 0%, #fff 88%, rgba(255,255,255,0) 100%)',
        padding: '12px 0 14px',
        borderBottom: '1px solid #e2e8f0',
        marginBottom: 'var(--cf-space-3)',
      }}>
        <div style={{ flex: 1, minWidth: 240 }}>
          <div style={{ display:'flex', alignItems:'center', gap:10, flexWrap:'wrap' }}>
            <h1 data-testid="cfo-title"
                style={{ margin:0, fontSize:22, fontWeight:700,
                         color:'#0f172a', letterSpacing:'-0.01em' }}>
              CFO Dashboard
            </h1>
            <CIStatusBadge />
          </div>
          <p style={{ margin:'4px 0 0', color:'#64748b', fontSize:13 }}>
            Window: <strong style={{ color:'#0f172a' }}>{data.range?.from} → {data.range?.to}</strong>
            {data.compare && <> &nbsp;·&nbsp; {COMPARE_LABELS[data.compare.mode]} ({data.compare.prev_from} → {data.compare.prev_to})</>}
          </p>
        </div>
        <Toolbar
          weeks={weeks} setWeeks={setWeeks}
          compare={compare} setCompare={setCompare}
          editMode={editMode} setEditMode={setEditMode}
          onSend={() => setShowSend(true)}
          onSave={() => setShowSave(true)}
          onFormulas={() => setShowFormulas(true)}
          views={views.data?.views || []}
          viewId={viewId}
          onPickView={id => { setViewId(id); navigate(`/cfo${id ? `?view=${id}` : ''}`); }}
        />
      </header>

      {exec.loading && <p data-testid="cfo-loading">Loading…</p>}
      {exec.error && <p className="error" data-testid="cfo-error">Error: {exec.error.message}</p>}

      <div className="cfo-grid" data-testid="cfo-grid" style={{
        display:'grid',
        gridTemplateColumns:'repeat(auto-fill, minmax(280px, 1fr))',
        gap:16,
      }}>
        {widgetsResolved.filter(w => editMode || visible.has(w.key)).map(w => (
          <Widget
            key={w.key}
            spec={w}
            data={data}
            hidden={!visible.has(w.key)}
            editMode={editMode}
            onToggle={() => toggleVisible(w.key)}
            onMoveUp={() => moveWidget(w.key, -1)}
            onMoveDown={() => moveWidget(w.key, +1)}
            note={noteByKey[w.key]}
            onNoteSaved={() => notes.reload?.()}
          />
        ))}
        <CustomFormulaTiles snapshot={data} formulas={formulas.data?.formulas || []} editMode={editMode} />
      </div>

      <RevenueStreamWidget />

      {/* P2.3 — auto-suggest a payment run when PWP just released bills.
          Renders nothing when there's nothing to nudge about. */}
      <PwpReleasedNudge variant="tile" days={7} />

      <FscHealthPanel />

      <QboSyncHealthTile />

      <ApprovalMixTile />

      {showSend && (
        <SendReportModal
          onClose={() => setShowSend(false)}
          snapshot={data}
          widgets={widgetsResolved.filter(w => visible.has(w.key))}
          noteByKey={noteByKey}
        />
      )}
      {showSave && (
        <SaveViewModal
          onClose={() => setShowSave(false)}
          weeks={weeks}
          compare={compare}
          visible={[...visible]}
          order={order}
          onSaved={() => views.reload?.()}
        />
      )}
      {showFormulas && (
        <FormulaBuilderModal
          onClose={() => setShowFormulas(false)}
          formulas={formulas.data}
          onSaved={() => formulas.reload?.()}
        />
      )}
    </div>
  );
}

// ---------- toolbar ----------------------------------------------------------
function Toolbar({ weeks, setWeeks, compare, setCompare, editMode, setEditMode, onSend, onSave, onFormulas, views, viewId, onPickView }) {
  return (
    <div style={{ display:'flex', flexWrap:'wrap', gap:8, alignItems:'center' }}>
      <select value={viewId} onChange={e => onPickView(parseInt(e.target.value, 10) || 0)}
              data-testid="cfo-view-picker"
              style={{ padding:'6px 8px', borderRadius:6, border:'1px solid #e2e8f0' }}>
        <option value={0}>Default view</option>
        {views.map(v => <option key={v.id} value={v.id}>{v.name}{v.is_default ? ' ★' : ''}</option>)}
      </select>
      <select value={weeks} onChange={e => setWeeks(parseInt(e.target.value, 10))}
              data-testid="cfo-window-picker"
              style={{ padding:'6px 8px', borderRadius:6, border:'1px solid #e2e8f0' }}>
        {WINDOW_PRESETS.map(w => <option key={w} value={w}>{w}w</option>)}
      </select>
      <select value={compare} onChange={e => setCompare(e.target.value)}
              data-testid="cfo-compare-picker"
              style={{ padding:'6px 8px', borderRadius:6, border:'1px solid #e2e8f0' }}>
        <option value="">No compare</option>
        <option value="prior_period">vs prior period</option>
        <option value="prior_year">vs same period last year</option>
      </select>
      <button onClick={() => setEditMode(!editMode)} data-testid="cfo-edit-toggle"
              className="btn" style={{ display:'inline-flex', alignItems:'center', gap:6 }}>
        {editMode ? <Eye size={14}/> : <ArrowUpDown size={14}/>} {editMode ? 'Done editing' : 'Edit layout'}
      </button>
      <button onClick={onFormulas} data-testid="cfo-formulas-btn" className="btn"
              style={{ display:'inline-flex', alignItems:'center', gap:6 }}><Plus size={14}/>Custom KPI</button>
      <button onClick={onSave} data-testid="cfo-save-view" className="btn"
              style={{ display:'inline-flex', alignItems:'center', gap:6 }}><Save size={14}/>Save view</button>
      <Link to="/cfo/audit-snapshot"
            data-testid="cfo-audit-snapshot-btn" className="btn"
            style={{ display:'inline-flex', alignItems:'center', gap:6, textDecoration:'none' }}><FileText size={14}/>Audit snapshot</Link>
      <button onClick={onSend} data-testid="cfo-send-report" className="btn btn--primary"
              style={{ display:'inline-flex', alignItems:'center', gap:6 }}><Send size={14}/>Send report</button>
    </div>
  );
}

// ---------- widget tile ------------------------------------------------------
function Widget({ spec, data, hidden, editMode, onToggle, onMoveUp, onMoveDown, note, onNoteSaved }) {
  const card = renderWidgetCard(spec, data);
  return (
    <section className="cfo-widget"
             data-testid={`cfo-widget-${spec.key}`}
             data-hidden={hidden ? '1' : '0'}
             style={{
                background:'#fff',
                border:'1px solid #e2e8f0',
                borderLeft:'3px solid #334155',
                borderRadius:6,
                padding:'14px 16px',
                opacity: hidden ? 0.45 : 1,
                display:'flex', flexDirection:'column', gap:8,
                transition: 'box-shadow 120ms ease, border-color 120ms ease',
             }}
             onMouseEnter={(e) => { e.currentTarget.style.boxShadow = '0 4px 12px rgba(15,23,42,0.06)'; }}
             onMouseLeave={(e) => { e.currentTarget.style.boxShadow = 'none'; }}>
      <div style={{ display:'flex', alignItems:'center', justifyContent:'space-between', gap:8 }}>
        <h3 style={{ margin:0, fontSize:11, color:'#64748b',
                     textTransform:'uppercase', letterSpacing:0.4, fontWeight:600 }}>
          {spec.title}
        </h3>
        {editMode && (
          <div style={{ display:'flex', gap:4 }}>
            <button onClick={onMoveUp}   data-testid={`cfo-widget-up-${spec.key}`}     className="btn" style={{ padding:'2px 6px' }}>↑</button>
            <button onClick={onMoveDown} data-testid={`cfo-widget-down-${spec.key}`}   className="btn" style={{ padding:'2px 6px' }}>↓</button>
            <button onClick={onToggle}   data-testid={`cfo-widget-toggle-${spec.key}`} className="btn" style={{ padding:'2px 6px' }}>
              {hidden ? <Eye size={12}/> : <EyeOff size={12}/>}
            </button>
          </div>
        )}
      </div>
      {card}
      <WidgetAnnotation widgetKey={spec.key} snapshot={card?.snapshot ?? data} comparison={data?.compare} />
      <WidgetNote widgetKey={spec.key} note={note} onSaved={onNoteSaved} />
    </section>
  );
}

// ---------- per-widget render dispatch --------------------------------------
function renderWidgetCard(spec, data) {
  const f = data?.finance  || {};
  const s = data?.staffing || {};
  const prev = data?.compare?.scalars || {};

  switch (spec.key) {
    case 'finance.revenue': {
      const v = f.revenue?.ytd;
      const d = deltaPct(v, prev.revenue);
      return Scalar({ value: fmt.money(v), secondary: 'Run rate: ' + fmt.money(f.revenue?.run_rate_90d), trend: f.revenue?.trend, delta: d, snapshot: { ytd: v, trend: f.revenue?.trend } });
    }
    case 'finance.margin': {
      const v = f.margin?.ytd, pct = f.margin?.gross_pct;
      return Scalar({ value: fmt.money(v), secondary: fmt.percent(pct) + ' gross', trend: f.margin?.trend, snapshot: { ytd: v, gross_pct: pct } });
    }
    case 'finance.ar_aging': return AgingBars({ data: f.ar_aging });
    case 'finance.ap_aging': return AgingBars({ data: f.ap_aging });
    case 'finance.dso': {
      return Scalar({ value: fmt.days(f.dso), secondary: 'Lower is faster cash collection', snapshot: { dso: f.dso } });
    }
    case 'finance.dpo': {
      return Scalar({ value: fmt.days(f.dpo), secondary: 'Higher = cash conserved vs vendors', snapshot: { dpo: f.dpo } });
    }
    case 'finance.unapplied_cash': {
      return Scalar({ value: fmt.money(f.unapplied_cash), secondary: f.unapplied_cash > 0 ? 'Apply to open invoices' : 'All clean', snapshot: { unapplied_cash: f.unapplied_cash } });
    }
    case 'finance.payroll': {
      const v = f.payroll?.ytd;
      const d = deltaPct(v, prev.payroll);
      return Scalar({ value: fmt.money(v), secondary: 'Last run: ' + fmt.money(f.payroll?.last_run_total), delta: d, snapshot: { ytd: v } });
    }
    case 'staffing.headcount': {
      const h = s.headcount || {};
      return (
        <div data-snapshot={JSON.stringify(h)}>
          <div style={{ fontSize:26, fontWeight:600 }} data-testid="cfo-headcount-active">{h.active ?? 0}</div>
          <div style={{ fontSize:12, color:'#64748b' }}>
            W2 {h.contractors_w2 ?? 0} · 1099 {h.contractors_1099 ?? 0} · C2C {h.contractors_c2c ?? 0} · Perm {h.perm ?? 0}
          </div>
        </div>
      );
    }
    case 'staffing.upcoming': {
      return (
        <div data-snapshot={JSON.stringify({ starts: s.upcoming_starts, terms: s.upcoming_terminations })}>
          <div style={{ display:'flex', gap:16 }}>
            <div>
              <div style={{ fontSize:11, color:'#64748b' }}>Starts (30d)</div>
              <div style={{ fontSize:22, fontWeight:600, color:'#16a34a' }} data-testid="cfo-upcoming-starts">{s.upcoming_starts ?? 0}</div>
            </div>
            <div>
              <div style={{ fontSize:11, color:'#64748b' }}>Terminations (30d)</div>
              <div style={{ fontSize:22, fontWeight:600, color:'#dc2626' }} data-testid="cfo-upcoming-terms">{s.upcoming_terminations ?? 0}</div>
            </div>
          </div>
        </div>
      );
    }
    case 'staffing.new_starts': {
      const v = s.new_starts?.period, d = deltaPct(v, prev.new_starts);
      return Scalar({ value: fmt.number(v), trend: s.new_starts?.trend, delta: d, snapshot: { period: v } });
    }
    case 'staffing.terminations': {
      const v = s.terminations?.period, d = deltaPct(v, prev.terminations);
      return Scalar({ value: fmt.number(v), trend: s.terminations?.trend, delta: d, snapshot: { period: v } });
    }
    case 'staffing.placements': {
      return (
        <div data-snapshot={JSON.stringify({ active: s.active_placements, ending_soon: s.ending_soon, new_period: s.new_placements?.period })}>
          <div style={{ fontSize:26, fontWeight:600 }}>{s.active_placements ?? 0}</div>
          <div style={{ fontSize:12, color:'#64748b' }}>
            Ending soon: <strong>{s.ending_soon ?? 0}</strong> · New: {s.new_placements?.period ?? 0}
          </div>
        </div>
      );
    }
    default: return <em style={{ color:'#94a3b8' }}>No renderer</em>;
  }
}

// ---------- shared scalar tile ------------------------------------------------
function Scalar({ value, secondary, trend, delta, snapshot }) {
  return (
    <div data-snapshot={JSON.stringify(snapshot ?? {})}>
      <div style={{ display:'flex', alignItems:'baseline', gap:8 }}>
        <div data-testid="cfo-scalar-value"
             style={{ fontSize:24, fontWeight:700, color:'#0f172a',
                      letterSpacing:'-0.02em', lineHeight:1.15,
                      fontVariantNumeric:'tabular-nums' }}>
          {value}
        </div>
        {delta != null && <DeltaBadge value={delta} />}
      </div>
      {secondary && (
        <div style={{ fontSize:11, color:'#64748b', marginTop:2 }}>
          {secondary}
        </div>
      )}
      {trend && trend.length > 0 && (
        <div style={{ marginTop:8 }}>
          <Sparkline data={trend} height={32} />
        </div>
      )}
    </div>
  );
}

// ---------- aging bars --------------------------------------------------------
function AgingBars({ data }) {
  const buckets = ['current', 'd30', 'd60', 'd90', 'd90_plus'];
  const colors  = { current:'#16a34a', d30:'#84cc16', d60:'#facc15', d90:'#f97316', d90_plus:'#dc2626' };
  const total   = (data?.total) || 0;
  return (
    <div data-snapshot={JSON.stringify(data || {})}>
      <div style={{ fontSize:22, fontWeight:600 }} data-testid="cfo-aging-total">{fmt.money(total)}</div>
      <div style={{ display:'flex', height:10, marginTop:6, borderRadius:4, overflow:'hidden', background:'#f1f5f9' }}>
        {total > 0 && buckets.map(b => {
          const pct = ((data?.[b] || 0) / total) * 100;
          if (pct <= 0) return null;
          return <div key={b} title={`${b}: ${fmt.money(data?.[b])}`} style={{ width:`${pct}%`, background:colors[b] }} data-testid={`cfo-aging-bar-${b}`}/>;
        })}
      </div>
      <div style={{ display:'flex', justifyContent:'space-between', fontSize:11, color:'#64748b', marginTop:4 }}>
        <span>Curr {fmt.money(data?.current)}</span>
        <span>30d {fmt.money(data?.d30)}</span>
        <span>60d {fmt.money(data?.d60)}</span>
        <span>90d {fmt.money(data?.d90)}</span>
        <span>90+ {fmt.money(data?.d90_plus)}</span>
      </div>
    </div>
  );
}

// ---------- AI annotation -----------------------------------------------------
function WidgetAnnotation({ widgetKey, snapshot, comparison }) {
  const [text, setText]       = useState('');
  const [loading, setLoading] = useState(false);
  const [err, setErr]         = useState(null);

  const generate = async () => {
    setLoading(true); setErr(null);
    try {
      const r = await api.post('/api/cfo_annotate.php', { widget_key: widgetKey, snapshot, comparison });
      setText(r.annotation || (r.disabled ? '(AI disabled for this tenant)' : '(no annotation returned)'));
    } catch (e) {
      setErr(e.message);
    } finally { setLoading(false); }
  };

  return (
    <div>
      {!text && !loading && (
        <button className="btn" onClick={generate}
                data-testid={`cfo-annotate-btn-${widgetKey}`}
                style={{ display:'inline-flex', alignItems:'center', gap:4, fontSize:12, padding:'4px 8px', background:'#f1f5f9' }}>
          <Sparkles size={12}/> AI suggestion
        </button>
      )}
      {loading && <span style={{ fontSize:12, color:'#94a3b8' }}><Loader2 size={12} className="cf-spin"/> generating…</span>}
      {err && <span style={{ fontSize:12, color:'#dc2626' }}>{err}</span>}
      {text && (
        <div style={{ fontSize:12, lineHeight:1.5, color:'#475569', background:'#f8fafc', borderLeft:'3px solid #2563eb', padding:'6px 10px', borderRadius:'0 6px 6px 0' }}
             data-testid={`cfo-annotate-text-${widgetKey}`}>
          <Sparkles size={12} style={{ display:'inline', marginRight:4 }}/> {text}
        </div>
      )}
    </div>
  );
}

// ---------- per-widget note ---------------------------------------------------
function WidgetNote({ widgetKey, note, onSaved }) {
  const [open, setOpen] = useState(false);
  const [val, setVal]   = useState('');
  const [busy, setBusy] = useState(false);

  const save = async () => {
    if (!val.trim()) return;
    setBusy(true);
    try {
      await api.post('/api/cfo_notes.php', { widget_key: widgetKey, body: val.trim() });
      setOpen(false); setVal(''); onSaved?.();
    } catch (e) { alert(e.message); }
    finally { setBusy(false); }
  };
  const remove = async () => {
    if (!note) return;
    if (!window.confirm('Delete this note?')) return;
    await api.delete(`/api/cfo_notes.php?id=${note.id}`);
    onSaved?.();
  };

  return (
    <div>
      {note && !open && (
        <div style={{ fontSize:12, color:'#0f172a', background:'#fefce8', borderLeft:'3px solid #ca8a04', padding:'6px 10px', borderRadius:'0 6px 6px 0', display:'flex', justifyContent:'space-between', alignItems:'start', gap:6 }}
             data-testid={`cfo-note-text-${widgetKey}`}>
          <span><StickyNote size={12} style={{ display:'inline', marginRight:4 }}/> {note.body}</span>
          <button onClick={remove} className="btn" style={{ padding:'0 4px', fontSize:11 }} data-testid={`cfo-note-remove-${widgetKey}`}><Trash2 size={10}/></button>
        </div>
      )}
      {!note && !open && (
        <button className="btn" onClick={() => setOpen(true)}
                data-testid={`cfo-note-add-${widgetKey}`}
                style={{ display:'inline-flex', alignItems:'center', gap:4, fontSize:12, padding:'4px 8px' }}>
          <StickyNote size={12}/> Add note
        </button>
      )}
      {open && (
        <div style={{ display:'flex', flexDirection:'column', gap:4 }}>
          <textarea value={val} onChange={e => setVal(e.target.value)} rows={2}
                    placeholder="Pin a note to this metric…" autoFocus
                    data-testid={`cfo-note-input-${widgetKey}`}
                    style={{ padding:6, border:'1px solid #e2e8f0', borderRadius:4, fontSize:13 }} />
          <div style={{ display:'flex', gap:4 }}>
            <button className="btn btn--primary" onClick={save} disabled={busy || !val.trim()}
                    data-testid={`cfo-note-save-${widgetKey}`}>{busy ? 'Saving…' : 'Save'}</button>
            <button className="btn" onClick={() => { setOpen(false); setVal(''); }}
                    data-testid={`cfo-note-cancel-${widgetKey}`}>Cancel</button>
          </div>
        </div>
      )}
    </div>
  );
}

// ---------- Save view modal ---------------------------------------------------
function SaveViewModal({ onClose, weeks, compare, visible, order, onSaved }) {
  const [name, setName]   = useState('');
  const [isDefault, setIsDefault] = useState(false);
  const [isShared,  setIsShared]  = useState(false);
  const [busy, setBusy]   = useState(false);

  const save = async () => {
    if (!name.trim()) return;
    setBusy(true);
    try {
      await api.post('/api/exec_dashboard_views.php', {
        name: name.trim(),
        filters:      { weeks },
        widget_config:{ visible, order, compare },
        is_default:   isDefault,
        is_shared:    isShared,
      });
      onSaved?.();
      onClose();
    } catch (e) { alert(e.message); }
    finally { setBusy(false); }
  };

  return (
    <ModalShell title="Save current view" onClose={onClose} testid="cfo-save-modal">
      <label style={{ display:'block', fontSize:12, color:'#64748b', marginBottom:4 }}>Name</label>
      <input value={name} onChange={e => setName(e.target.value)} autoFocus
             data-testid="cfo-save-name"
             style={{ width:'100%', padding:8, border:'1px solid #e2e8f0', borderRadius:4 }} />
      <div style={{ marginTop:12, display:'flex', flexDirection:'column', gap:6 }}>
        <label style={{ fontSize:13 }}>
          <input type="checkbox" checked={isDefault} onChange={e => setIsDefault(e.target.checked)} data-testid="cfo-save-default"/>
          {' '}Set as my default
        </label>
        <label style={{ fontSize:13 }}>
          <input type="checkbox" checked={isShared} onChange={e => setIsShared(e.target.checked)} data-testid="cfo-save-shared"/>
          {' '}Share with everyone in this tenant
        </label>
      </div>
      <div style={{ marginTop:16, display:'flex', justifyContent:'flex-end', gap:8 }}>
        <button className="btn" onClick={onClose} data-testid="cfo-save-cancel">Cancel</button>
        <button className="btn btn--primary" onClick={save} disabled={busy || !name.trim()}
                data-testid="cfo-save-submit">{busy ? 'Saving…' : 'Save view'}</button>
      </div>
    </ModalShell>
  );
}

// ---------- Send report modal -------------------------------------------------
function SendReportModal({ onClose, snapshot, widgets, noteByKey }) {
  const [emails, setEmails]   = useState('');
  const [subject, setSubject] = useState('CFO Dashboard snapshot');
  const [intro,   setIntro]   = useState('');
  const [busy, setBusy]       = useState(false);
  const [result, setResult]   = useState(null);

  const send = async () => {
    setBusy(true); setResult(null);
    try {
      const recipients = emails.split(/[,;\n\s]+/).map(s => s.trim()).filter(Boolean);
      const flat = widgets.map(w => {
        const card = renderWidgetCard(w, snapshot);
        const node = card?.props?.['data-snapshot'];
        const snap = node ? JSON.parse(node) : {};
        return {
          key: w.key,
          title: w.title,
          value_display: snap.ytd != null
              ? fmt.money(snap.ytd)
              : (snap.total != null ? fmt.money(snap.total)
              : (snap.active != null ? String(snap.active)
              : (snap.period != null ? String(snap.period)
              : (snap.dso != null ? fmt.days(snap.dso)
              : (snap.dpo != null ? fmt.days(snap.dpo)
              : (snap.unapplied_cash != null ? fmt.money(snap.unapplied_cash) : '—')))))),
          note: noteByKey[w.key]?.body || '',
        };
      });
      const r = await api.post('/api/cfo_send_report.php', {
        recipients, subject, intro, snapshot, widgets: flat,
        comparison: snapshot.compare,
      });
      setResult(r);
    } catch (e) { setResult({ error: e.message }); }
    finally { setBusy(false); }
  };

  return (
    <ModalShell title="Send dashboard report" onClose={onClose} testid="cfo-send-modal">
      <label style={{ display:'block', fontSize:12, color:'#64748b' }}>Recipients (comma or newline separated)</label>
      <textarea rows={2} value={emails} onChange={e => setEmails(e.target.value)} autoFocus
                placeholder="cfo@example.com, ceo@example.com"
                data-testid="cfo-send-recipients"
                style={{ width:'100%', padding:8, border:'1px solid #e2e8f0', borderRadius:4, fontSize:13 }} />
      <label style={{ display:'block', fontSize:12, color:'#64748b', marginTop:10 }}>Subject</label>
      <input value={subject} onChange={e => setSubject(e.target.value)}
             data-testid="cfo-send-subject"
             style={{ width:'100%', padding:8, border:'1px solid #e2e8f0', borderRadius:4 }} />
      <label style={{ display:'block', fontSize:12, color:'#64748b', marginTop:10 }}>Intro (optional)</label>
      <textarea rows={3} value={intro} onChange={e => setIntro(e.target.value)}
                placeholder="A short paragraph for context…"
                data-testid="cfo-send-intro"
                style={{ width:'100%', padding:8, border:'1px solid #e2e8f0', borderRadius:4, fontSize:13 }} />
      {result && (
        <div data-testid="cfo-send-result"
             style={{ marginTop:10, padding:8, borderRadius:4, fontSize:12,
                      background: result.error ? '#fee2e2' : (result.failed?.length ? '#fef3c7' : '#dcfce7'),
                      color: result.error ? '#7f1d1d' : '#0f172a' }}>
          {result.error && <>Error: {result.error}</>}
          {!result.error && (
            <>
              Sent: {result.sent?.length || 0} · Failed: {result.failed?.length || 0}
              {!result.mailer_available && <> · <em>Mailer offline — preview HTML returned in response.</em></>}
            </>
          )}
        </div>
      )}
      <div style={{ marginTop:14, display:'flex', justifyContent:'flex-end', gap:8 }}>
        <button className="btn" onClick={onClose} data-testid="cfo-send-cancel">Close</button>
        <button className="btn btn--primary" onClick={send} disabled={busy || !emails.trim()}
                data-testid="cfo-send-submit">{busy ? 'Sending…' : 'Send'}</button>
      </div>
    </ModalShell>
  );
}

// ---------- formula builder ---------------------------------------------------
function FormulaBuilderModal({ onClose, formulas, onSaved }) {
  const [name, setName] = useState('');
  const [a, setA]       = useState('');
  const [op, setOp]     = useState('+');
  const [b, setB]       = useState('');
  const [fmtSel, setFmt]= useState('number');
  const [busy, setBusy] = useState(false);

  const create = async () => {
    if (!name.trim() || !a || !b) return;
    setBusy(true);
    try {
      await api.post('/api/cfo_formulas.php', {
        name: name.trim(), operand_a: a, operator: op, operand_b: b, format: fmtSel,
      });
      onSaved?.();
      setName(''); setA(''); setB('');
    } catch (e) { alert(e.message); }
    finally { setBusy(false); }
  };

  const remove = async (id) => {
    if (!window.confirm('Delete this formula?')) return;
    await api.delete(`/api/cfo_formulas.php?id=${id}`);
    onSaved?.();
  };

  return (
    <ModalShell title="Custom KPI widgets" onClose={onClose} testid="cfo-formulas-modal">
      <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:8 }}>
        <div>
          <label style={{ fontSize:12, color:'#64748b' }}>Name</label>
          <input value={name} onChange={e => setName(e.target.value)} placeholder="e.g. Revenue / Headcount"
                 data-testid="cfo-formula-name"
                 style={{ width:'100%', padding:6, border:'1px solid #e2e8f0', borderRadius:4 }} />
        </div>
        <div>
          <label style={{ fontSize:12, color:'#64748b' }}>Format</label>
          <select value={fmtSel} onChange={e => setFmt(e.target.value)}
                  data-testid="cfo-formula-format"
                  style={{ width:'100%', padding:6, border:'1px solid #e2e8f0', borderRadius:4 }}>
            <option value="number">Number</option>
            <option value="money">Money</option>
            <option value="percent">Percent</option>
            <option value="ratio">Ratio</option>
          </select>
        </div>
      </div>
      <div style={{ display:'grid', gridTemplateColumns:'1fr 80px 1fr', gap:8, marginTop:8 }}>
        <select value={a} onChange={e => setA(e.target.value)} data-testid="cfo-formula-a"
                style={{ padding:6, border:'1px solid #e2e8f0', borderRadius:4 }}>
          <option value="">Select metric A</option>
          {(formulas?.allowed_keys || []).map(k => <option key={k} value={k}>{k}</option>)}
        </select>
        <select value={op} onChange={e => setOp(e.target.value)} data-testid="cfo-formula-op"
                style={{ padding:6, border:'1px solid #e2e8f0', borderRadius:4 }}>
          {(formulas?.allowed_ops || ['+','-','*','/','pct_of']).map(o => <option key={o} value={o}>{o}</option>)}
        </select>
        <select value={b} onChange={e => setB(e.target.value)} data-testid="cfo-formula-b"
                style={{ padding:6, border:'1px solid #e2e8f0', borderRadius:4 }}>
          <option value="">Select metric B</option>
          {(formulas?.allowed_keys || []).map(k => <option key={k} value={k}>{k}</option>)}
        </select>
      </div>
      <div style={{ marginTop:8, display:'flex', justifyContent:'flex-end' }}>
        <button className="btn btn--primary" onClick={create} disabled={busy || !name.trim() || !a || !b}
                data-testid="cfo-formula-create">{busy ? 'Saving…' : 'Add formula'}</button>
      </div>
      <hr style={{ margin:'14px 0', border:'none', borderTop:'1px solid #e2e8f0' }}/>
      <div data-testid="cfo-formulas-list">
        {(formulas?.formulas || []).length === 0 && <p style={{ fontSize:13, color:'#94a3b8' }}>No custom formulas yet.</p>}
        {(formulas?.formulas || []).map(f => (
          <div key={f.id} style={{ display:'flex', justifyContent:'space-between', padding:'6px 0', borderBottom:'1px dashed #e2e8f0', fontSize:13 }}
               data-testid={`cfo-formula-row-${f.id}`}>
            <span><strong>{f.name}</strong> &nbsp; <code style={{ fontSize:11, color:'#64748b' }}>{f.operand_a} {f.operator} {f.operand_b}</code></span>
            {f.is_owner && <button className="btn" onClick={() => remove(f.id)} style={{ padding:'0 6px' }} data-testid={`cfo-formula-del-${f.id}`}><Trash2 size={12}/></button>}
          </div>
        ))}
      </div>
    </ModalShell>
  );
}

// ---------- custom formula tile renderer --------------------------------------
function CustomFormulaTiles({ snapshot, formulas, editMode }) {
  if (!formulas || formulas.length === 0) return null;
  return (
    <>
      {formulas.map(f => <CustomFormulaTile key={f.id} formula={f} snapshot={snapshot} />)}
    </>
  );
}

function CustomFormulaTile({ formula, snapshot }) {
  const [value, setValue] = useState(null);
  useEffect(() => {
    let active = true;
    (async () => {
      try {
        const r = await api.post('/api/cfo_formulas.php?action=evaluate', {
          operand_a: formula.operand_a, operator: formula.operator,
          operand_b: formula.operand_b, snapshot,
        });
        if (active) setValue(r.result);
      } catch (_) { /* ignore */ }
    })();
    return () => { active = false; };
  }, [formula.id, snapshot]);

  const fmtFn = { money: fmt.money, percent: fmt.percent, ratio: fmt.ratio, number: fmt.number }[formula.format] || fmt.number;

  return (
    <section className="cfo-widget cfo-widget--custom"
             data-testid={`cfo-formula-tile-${formula.id}`}
             style={{ background:'#fff', border:'1px dashed #94a3b8', borderRadius:8, padding:16 }}>
      <h3 style={{ margin:0, fontSize:13, color:'#64748b', textTransform:'uppercase', letterSpacing:'.04em' }}>
        {formula.name}
      </h3>
      <div style={{ fontSize:26, fontWeight:600, color:'#0f172a', marginTop:4 }} data-testid={`cfo-formula-value-${formula.id}`}>
        {value == null ? '…' : fmtFn(value)}
      </div>
      <div style={{ fontSize:11, color:'#94a3b8', marginTop:4 }}>
        <code>{formula.operand_a} {formula.operator} {formula.operand_b}</code>
      </div>
    </section>
  );
}

// ---------- modal shell -------------------------------------------------------
function ModalShell({ title, onClose, children, testid }) {
  return (
    <div onClick={onClose}
         data-testid={testid}
         style={{ position:'fixed', inset:0, background:'rgba(15,23,42,.5)', zIndex:1000, display:'flex', alignItems:'center', justifyContent:'center', padding:20 }}>
      <div onClick={e => e.stopPropagation()}
           style={{ background:'#fff', borderRadius:8, padding:24, maxWidth:560, width:'100%', maxHeight:'90vh', overflow:'auto' }}>
        <div style={{ display:'flex', justifyContent:'space-between', alignItems:'center', marginBottom:12 }}>
          <h2 style={{ margin:0, fontSize:18 }}>{title}</h2>
          <button onClick={onClose} className="btn" style={{ padding:'4px 6px' }} data-testid={`${testid}-close`}><X size={14}/></button>
        </div>
        {children}
      </div>
    </div>
  );
}

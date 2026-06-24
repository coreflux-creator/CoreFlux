import React, { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Printer, ArrowLeft, FileText } from 'lucide-react';
import { api } from '../lib/api';

/**
 * AuditSnapshot — one-page printable snapshot for external auditors.
 *
 * Layout: tenant logo + name at top, period band, four-up KPI grid,
 * footer with prepared-at / prepared-by and an auditor scope chip.
 *
 * Print styling: a global @media print stylesheet hides the rest of the
 * shell (sidebar, header, banners) and the in-page back/print buttons
 * so File → Print → Save as PDF produces a clean one-pager.
 */
export default function AuditSnapshot({ session }) {
  const navigate = useNavigate();
  const [data, setData] = useState(null);
  const [err, setErr]   = useState(null);
  const [loading, setLoading] = useState(true);

  // Date range — default last 90 days, editable.
  const today = new Date().toISOString().slice(0, 10);
  const ninetyAgo = (() => { const d = new Date(); d.setDate(d.getDate() - 90); return d.toISOString().slice(0,10); })();
  const [from, setFrom] = useState(ninetyAgo);
  const [to, setTo]     = useState(today);

  useEffect(() => {
    let cancel = false;
    setLoading(true); setErr(null);
    api.get(`/api/cfo_audit_snapshot.php?from=${from}&to=${to}`)
      .then(d => { if (!cancel) { setData(d); setLoading(false); } })
      .catch(e => { if (!cancel) { setErr(e); setLoading(false); } });
    return () => { cancel = true; };
  }, [from, to]);

  const onPrint = () => window.print();

  if (loading) return <div style={{ padding: 40, textAlign: 'center' }}>Loading snapshot…</div>;
  if (err)     return <div style={{ padding: 40, color: '#b91c1c' }} data-testid="audit-snapshot-error">
                       {err?.message || String(err)}
                     </div>;
  if (!data)   return null;

  const { tenant, period, prepared, auditor_scope, totals } = data;

  // Currency formatter — falls back to '—' for null (= "module not configured").
  const fmt = (v) => v === null || v === undefined
    ? '—'
    : new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD',
                                         minimumFractionDigits: 0,
                                         maximumFractionDigits: 0 }).format(v);
  const pct = (v) => v === null ? '—' : `${v.toFixed(1)}%`;

  return (
    <div data-testid="audit-snapshot" className="audit-snapshot">
      {/* Inline print stylesheet — scoped via .audit-snapshot so it doesn't
          leak into other routes. Hides shell chrome on print and forces an
          A4-ish single page. */}
      <style>{`
        @media print {
          body * { visibility: hidden !important; }
          .audit-snapshot, .audit-snapshot * { visibility: visible !important; }
          .audit-snapshot { position: absolute; left: 0; top: 0; width: 100%; }
          .audit-snapshot .no-print { display: none !important; }
          [data-testid="auditor-banner"] { display: none !important; }
          @page { size: letter portrait; margin: 0.5in; }
        }
        .audit-snapshot { background: #fff; color: #111827; padding: 32px;
                          max-width: 880px; margin: 0 auto;
                          font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        .audit-snapshot h1 { font-size: 28px; margin: 0; letter-spacing: -0.02em; }
        .audit-snapshot .label { font-size: 11px; text-transform: uppercase;
                                 letter-spacing: 0.06em; color: #6b7280;
                                 font-weight: 600; margin-bottom: 4px; }
        .audit-snapshot .kpi  { padding: 20px; border: 1px solid #e5e7eb;
                                border-radius: 10px; background: #fafafa;
                                display: flex; flex-direction: column; gap: 8px; }
        .audit-snapshot .kpi-value { font-size: 24px; font-weight: 700;
                                     font-variant-numeric: tabular-nums;
                                     color: #111827; }
        .audit-snapshot .chip { display: inline-flex; gap: 4px; align-items: center;
                                font-size: 11px; padding: 2px 8px;
                                border-radius: 12px; background: #fef3c7;
                                color: #92400e; font-weight: 600; }
      `}</style>

      {/* Non-printed control bar */}
      <div className="no-print" style={{ display: 'flex', justifyContent: 'space-between',
                                          alignItems: 'center', marginBottom: 24 }}>
        <button className="btn btn--ghost" onClick={() => navigate('/cfo')}
                data-testid="audit-snapshot-back">
          <ArrowLeft size={14} /> Back to CFO Dashboard
        </button>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <label style={{ fontSize: 12, color: '#6b7280' }}>
            From <input type="date" value={from} onChange={e => setFrom(e.target.value)}
                        className="input" style={{ padding: '2px 6px', marginLeft: 4 }}
                        data-testid="audit-snapshot-from" />
          </label>
          <label style={{ fontSize: 12, color: '#6b7280' }}>
            To <input type="date" value={to} onChange={e => setTo(e.target.value)}
                     className="input" style={{ padding: '2px 6px', marginLeft: 4 }}
                     data-testid="audit-snapshot-to" />
          </label>
          <button className="btn btn--primary" onClick={onPrint}
                  data-testid="audit-snapshot-print">
            <Printer size={14} /> Print / Save as PDF
          </button>
        </div>
      </div>

      {/* Branded header */}
      <header style={{ display: 'flex', justifyContent: 'space-between',
                        alignItems: 'flex-start', borderBottom: '2px solid #111827',
                        paddingBottom: 16, marginBottom: 24 }}>
        <div>
          <div className="label">CoreFlux · Audit Snapshot</div>
          <h1 data-testid="audit-snapshot-tenant">{tenant.name}</h1>
          <p style={{ margin: '6px 0 0', color: '#6b7280', fontSize: 13 }}>
            Period: <strong style={{ color: '#111827' }}>{period.label}</strong>
            {auditor_scope?.is_auditor && (
              <span className="chip" style={{ marginLeft: 12 }} data-testid="audit-snapshot-auditor-chip">
                🔒 External Auditor View
              </span>
            )}
          </p>
        </div>
        {tenant.logo_url && (
          <img src={tenant.logo_url} alt={`${tenant.name} logo`}
               style={{ maxHeight: 56, maxWidth: 180, objectFit: 'contain' }} />
        )}
      </header>

      {/* KPI grid — 2x3 on print/desktop, single column on phones */}
      <div style={{ display: 'grid',
                    gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))',
                    gap: 16, marginBottom: 28 }} data-testid="audit-snapshot-kpis">
        <Kpi label="Revenue (period)"      value={fmt(totals.revenue_total)}   tid="kpi-revenue" />
        <Kpi label="Cash collected"        value={fmt(totals.collected_total)} tid="kpi-collected" />
        <Kpi label="Vendor bills (period)" value={fmt(totals.ap_total)}        tid="kpi-ap" />
        <Kpi label="Accounts receivable (open)" value={fmt(totals.ar_open)}    tid="kpi-ar-open" />
        <Kpi label="Accounts payable (open)"    value={fmt(totals.ap_open)}    tid="kpi-ap-open" />
        <Kpi label="Net margin (period)"   value={pct(totals.net_margin_pct)}  tid="kpi-margin" />
      </div>

      {/* Auditor scope explainer (only when in auditor mode) */}
      {auditor_scope?.is_auditor && (
        <section style={{ marginBottom: 24, padding: 14,
                          background: '#fff7ed', border: '1px solid #fdba74',
                          borderRadius: 8, fontSize: 12, color: '#9a3412',
                          lineHeight: 1.6 }}
                 data-testid="audit-snapshot-scope">
          <strong>Auditor access scope:</strong> read-only across{' '}
          <code>{(auditor_scope.modules || []).join(', ') || 'reports, accounting, cfo, ap, billing, treasury'}</code>.
          {auditor_scope.expires_at && (
            <> Access expires <strong>{auditor_scope.expires_at.replace('T',' ').slice(0,16)}</strong>.</>
          )}{' '}
          Every page view is logged in <code>auditor_access_log</code>; every mutation
          is rejected with HTTP 403 at the API.
        </section>
      )}

      {/* Notes block — auditors can write workpaper notes by hand on the
          printed PDF; CoreFlux team gets a placeholder for auditor questions. */}
      <section style={{ marginBottom: 24 }}>
        <div className="label">Notes for the engagement</div>
        <div style={{ minHeight: 90, border: '1px dashed #d1d5db',
                       borderRadius: 8, padding: 12, fontSize: 13,
                       color: '#9ca3af', lineHeight: 1.6 }}
             data-testid="audit-snapshot-notes">
          Use this space to annotate the printed snapshot — line-item callouts,
          follow-up questions, or signoff initials. CoreFlux retains a hashed
          record of which auditor session viewed this page.
        </div>
      </section>

      {/* Footer */}
      <footer style={{ borderTop: '1px solid #e5e7eb', paddingTop: 12,
                        fontSize: 11, color: '#6b7280',
                        display: 'flex', justifyContent: 'space-between' }}
              data-testid="audit-snapshot-footer">
        <span>Prepared {prepared.at?.replace('T',' ').slice(0,16)} by {prepared.by}</span>
        <span>CoreFlux · {tenant.name} · {period.label}</span>
      </footer>
    </div>
  );
}

function Kpi({ label, value, tid }) {
  return (
    <div className="kpi" data-testid={tid}>
      <div className="label">{label}</div>
      <div className="kpi-value">{value}</div>
    </div>
  );
}

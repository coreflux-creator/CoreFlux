import React, { useEffect, useState, useMemo } from 'react';
import { api } from '../../../dashboard/src/lib/api';

/**
 * Period Close wizard — Step 1: bundle preview, Step 2: confirm.
 *
 * Reads from /api/time/periods?action=preview_close&id=N (no writes), shows
 * the user the exact AR / AP / Payroll / RevRec bundles that will be written
 * to time_downstream_feed before they hit Confirm. Blockers (pending_review
 * entries) prevent close.
 */
export default function PeriodCloseWizard({ period, onClose, onClosed }) {
  const [preview, setPreview] = useState(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true); setError(null);
    api.get(`/api/v1/time/periods?action=preview_close&id=${period.id}`)
      .then(r => { if (!cancelled) setPreview(r); })
      .catch(e => { if (!cancelled) setError(e); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [period.id]);

  const grouped = useMemo(() => {
    const out = { ar: [], ap: [], payroll: [], revrec: [] };
    (preview?.bundles ?? []).forEach(b => out[b.bundle_type]?.push(b));
    return out;
  }, [preview]);

  const blocked = (preview?.blockers?.pending_review_count ?? 0) > 0;
  const nothingToBundle = !loading && (preview?.approved_entries_count ?? 0) === 0;

  const confirm = async () => {
    setBusy(true); setError(null);
    try {
      const res = await api.post(`/api/v1/time/periods?action=close&id=${period.id}`, {});
      onClosed?.(res);
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  };

  const fmtCur = (n) => `$${(Number(n) || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  const fmtHrs = (n) => `${(Number(n) || 0).toFixed(2)}h`;

  return (
    <div
      className="period-close-wizard__backdrop"
      data-testid="time-period-close-wizard"
      style={{
        position: 'fixed', inset: 0, background: 'rgba(15,18,28,0.55)',
        backdropFilter: 'blur(4px)', display: 'flex', alignItems: 'center',
        justifyContent: 'center', zIndex: 1000, padding: 'var(--cf-space-4)',
      }}
      onClick={(e) => { if (e.target === e.currentTarget && !busy) onClose?.(); }}
    >
      <div
        className="period-close-wizard"
        style={{
          background: 'var(--cf-surface, #fff)', borderRadius: 'var(--cf-radius-lg, 12px)',
          width: 'min(960px, 100%)', maxHeight: '90vh', display: 'flex',
          flexDirection: 'column', boxShadow: '0 24px 60px rgba(0,0,0,0.25)',
        }}
      >
        <header style={{ padding: 'var(--cf-space-4) var(--cf-space-5)', borderBottom: '1px solid var(--cf-border, #e5e7eb)', display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 'var(--cf-space-3)' }}>
          <div>
            <h3 style={{ margin: 0 }} data-testid="time-period-close-wizard-title">
              Close period <strong>{period.label}</strong>
            </h3>
            <p style={{ margin: '4px 0 0', color: 'var(--cf-text-muted, #6b7280)', fontSize: 13 }}>
              {period.start_date} → {period.end_date} · Review the downstream
              feed bundles below before locking this period.
            </p>
          </div>
          <button className="btn btn--ghost" onClick={() => !busy && onClose?.()} disabled={busy} data-testid="time-period-close-wizard-cancel-x" aria-label="Close">×</button>
        </header>

        <div style={{ overflow: 'auto', padding: 'var(--cf-space-5)', flex: 1 }}>
          {loading && <p data-testid="time-period-close-wizard-loading">Loading preview…</p>}
          {error && <p className="error" data-testid="time-period-close-wizard-error">Error: {error.message}</p>}

          {!loading && preview && (
            <>
              {/* Summary cards */}
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 'var(--cf-space-3)', marginBottom: 'var(--cf-space-4)' }}>
                <SummaryCard label="Approved entries"     value={preview.approved_entries_count} testid="time-pcw-summary-approved" />
                <SummaryCard label="Placements bundled"   value={preview.totals.placements}      testid="time-pcw-summary-placements" />
                <SummaryCard label="Bundles to write"     value={preview.totals.bundles}         testid="time-pcw-summary-bundles" tone={preview.totals.bundles > 0 ? 'positive' : 'neutral'} />
                <SummaryCard label="Billable hours"       value={fmtHrs(preview.totals.hours_billable)} testid="time-pcw-summary-billable" />
                <SummaryCard label="PTO hours"            value={fmtHrs(preview.totals.hours_pto)}      testid="time-pcw-summary-pto" />
                <SummaryCard label="AR amount"            value={fmtCur(preview.totals.amount_bill)}    testid="time-pcw-summary-ar" tone="positive" />
                <SummaryCard label="Payroll/AP amount"    value={fmtCur(preview.totals.amount_pay)}     testid="time-pcw-summary-pay" />
              </div>

              {/* Blockers */}
              {blocked && (
                <div className="error" data-testid="time-pcw-blocker" style={{ background: 'var(--cf-danger-bg, #fef2f2)', border: '1px solid var(--cf-danger-border, #fecaca)', color: 'var(--cf-danger, #b91c1c)', padding: 'var(--cf-space-3)', borderRadius: 8, marginBottom: 'var(--cf-space-4)' }}>
                  <strong>{preview.blockers.pending_review_count}</strong> entries
                  are still <code>pending_review</code>. Approve or reject them in
                  the Review Queue before closing this period.
                </div>
              )}

              {nothingToBundle && !blocked && (
                <div data-testid="time-pcw-empty" style={{ padding: 'var(--cf-space-4)', background: 'var(--cf-surface-alt, #f9fafb)', borderRadius: 8, color: 'var(--cf-text-muted, #6b7280)', textAlign: 'center', marginBottom: 'var(--cf-space-4)' }}>
                  No approved entries in this period — closing it will produce an empty downstream feed. You can still proceed.
                </div>
              )}

              {/* Informational */}
              {(preview.informational.draft_count > 0 || preview.informational.rejected_count > 0) && (
                <p style={{ color: 'var(--cf-text-muted, #6b7280)', fontSize: 13, margin: '0 0 var(--cf-space-4)' }}>
                  {preview.informational.draft_count > 0 && <>FYI: {preview.informational.draft_count} draft entries will be excluded. </>}
                  {preview.informational.rejected_count > 0 && <>{preview.informational.rejected_count} rejected entries will be excluded.</>}
                </p>
              )}

              {/* Bundle breakdown by type */}
              {preview.totals.bundles > 0 && (
                <div data-testid="time-pcw-bundle-tables">
                  {['ar', 'ap', 'payroll', 'revrec'].map(type => (
                    <BundleTable key={type} type={type} rows={grouped[type]} fmtCur={fmtCur} fmtHrs={fmtHrs} />
                  ))}
                </div>
              )}
            </>
          )}
        </div>

        <footer style={{ padding: 'var(--cf-space-4) var(--cf-space-5)', borderTop: '1px solid var(--cf-border, #e5e7eb)', display: 'flex', justifyContent: 'flex-end', gap: 'var(--cf-space-3)', background: 'var(--cf-surface-alt, #f9fafb)', borderRadius: '0 0 var(--cf-radius-lg, 12px) var(--cf-radius-lg, 12px)' }}>
          <button className="btn btn--ghost" onClick={() => onClose?.()} disabled={busy} data-testid="time-period-close-wizard-cancel">Cancel</button>
          <button
            className="btn btn--primary"
            onClick={confirm}
            disabled={loading || busy || blocked}
            data-testid="time-period-close-wizard-confirm"
          >
            {busy ? 'Closing…' : blocked ? 'Resolve blockers to close' : `Confirm close & build ${preview?.totals?.bundles ?? 0} bundle(s)`}
          </button>
        </footer>
      </div>
    </div>
  );
}

function SummaryCard({ label, value, tone, testid }) {
  const toneColor = tone === 'positive' ? 'var(--cf-success, #047857)' : 'var(--cf-text, #111827)';
  return (
    <div data-testid={testid} style={{ padding: 'var(--cf-space-3)', background: 'var(--cf-surface-alt, #f9fafb)', border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8 }}>
      <div style={{ fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.04em', color: 'var(--cf-text-muted, #6b7280)' }}>{label}</div>
      <div style={{ fontSize: 22, fontWeight: 600, color: toneColor, marginTop: 4 }}>{value}</div>
    </div>
  );
}

const BUNDLE_LABELS = {
  ar:      { title: 'AR — Billing',    blurb: 'Consumed by Billing module to produce client invoices.' },
  ap:      { title: 'AP — Vendor pay', blurb: 'Consumed by AP module for C2C / 1099 vendor payouts.' },
  payroll: { title: 'Payroll',         blurb: 'Consumed by Payroll module for W-2 employee wages.' },
  revrec:  { title: 'RevRec',          blurb: 'Consumed by Accounting GL for revenue recognition.' },
};

function BundleTable({ type, rows, fmtCur, fmtHrs }) {
  if (!rows?.length) return null;
  const meta = BUNDLE_LABELS[type];
  return (
    <section data-testid={`time-pcw-bundle-${type}`} style={{ marginBottom: 'var(--cf-space-4)' }}>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', marginBottom: 'var(--cf-space-2)' }}>
        <div>
          <strong style={{ textTransform: 'uppercase', letterSpacing: '0.04em', fontSize: 12 }}>{meta.title}</strong>
          <span style={{ marginLeft: 8, color: 'var(--cf-text-muted, #6b7280)', fontSize: 12 }}>{meta.blurb}</span>
        </div>
        <span className="badge">{rows.length} bundle{rows.length !== 1 ? 's' : ''}</span>
      </header>
      <table className="data-table" data-testid={`time-pcw-bundle-table-${type}`}>
        <thead>
          <tr>
            <th>Placement</th>
            <th>End client</th>
            <th style={{ textAlign: 'right' }}>Entries</th>
            <th style={{ textAlign: 'right' }}>Billable hrs</th>
            <th style={{ textAlign: 'right' }}>PTO hrs</th>
            <th style={{ textAlign: 'right' }}>Bill $</th>
            <th style={{ textAlign: 'right' }}>Pay $</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          {rows.map(b => (
            <tr key={`${type}-${b.placement_id}`} data-testid={`time-pcw-bundle-row-${type}-${b.placement_id}`}>
              <td>{b.placement_title || `#${b.placement_id}`}</td>
              <td>{b.end_client_name || '—'}</td>
              <td style={{ textAlign: 'right' }}>{b.entry_count}</td>
              <td style={{ textAlign: 'right' }}>{fmtHrs(b.total_hours_billable)}</td>
              <td style={{ textAlign: 'right' }}>{fmtHrs(b.total_hours_pto)}</td>
              <td style={{ textAlign: 'right' }}>{fmtCur(b.total_amount_bill)}</td>
              <td style={{ textAlign: 'right' }}>{fmtCur(b.total_amount_pay)}</td>
              <td>
                {b.supersedes_existing_bundle_id && (
                  <span className="badge badge--warning" data-testid={`time-pcw-bundle-supersede-${type}-${b.placement_id}`} title={`Supersedes consumed bundle #${b.supersedes_existing_bundle_id}`}>
                    supersedes #{b.supersedes_existing_bundle_id}
                  </span>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  );
}

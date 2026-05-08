import React, { useState, useMemo } from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../../../dashboard/src/lib/api';

/**
 * Tax-form export — preview + one-click CSV download.
 *
 * Sums every posted JE line through `accounting_tax_mappings` so a
 * tenant can hand totals to their tax preparer (or autofill a Schedule C
 * line by line). Surfaces "unmapped" accounts loudly so nothing slips
 * through the cracks.
 */
export default function TaxExport() {
  const today = new Date().toISOString().slice(0, 10);
  const yearStart = `${new Date().getFullYear()}-01-01`;
  const [form, setForm]   = useState('');
  const [start, setStart] = useState(yearStart);
  const [end, setEnd]     = useState(today);

  const formsApi = useApi('/api/tax_mappings.php');
  const previewUrl = form
    ? `/api/tax_form_export.php?tax_form_code=${encodeURIComponent(form)}&start=${start}&end=${end}`
    : null;
  const { data, error, loading, reload } = useApi(previewUrl);

  const downloadCsv = () => {
    if (!form) return;
    const url = `/api/tax_form_export.php?tax_form_code=${encodeURIComponent(form)}&start=${start}&end=${end}&format=csv`;
    // Use window.location so the browser handles the download via Content-Disposition.
    window.location.href = url;
  };

  const grandTotal = useMemo(() => {
    if (!data?.totals_by_line) return 0;
    return data.totals_by_line.reduce((s, r) => s + (r.total || 0), 0);
  }, [data?.totals_by_line]);

  const forms = formsApi.data?.available_forms || [];

  return (
    <section data-testid="accounting-tax-export-page">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', flexWrap: 'wrap', gap: 12, marginBottom: 12 }}>
        <div>
          <h2 style={{ margin: 0 }}>Tax export</h2>
          <p style={{ color: '#64748b', margin: '4px 0 0', fontSize: 13 }}>
            Sums every posted JE line into form lines via your{' '}
            <Link to="/modules/accounting/tax-mappings" data-testid="accounting-tax-export-mappings-link">tax mappings</Link>.
            Hand the CSV straight to your accountant.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <button data-testid="accounting-tax-export-refresh" onClick={reload} className="btn btn--ghost" style={{ fontSize: 12 }}>Refresh</button>
          <button data-testid="accounting-tax-export-download"
                  className="btn btn--primary"
                  onClick={downloadCsv}
                  disabled={!form || !data?.totals_by_line?.length}
                  style={{ fontSize: 12 }}>
            Download CSV
          </button>
        </div>
      </header>

      <div style={{ display: 'flex', alignItems: 'flex-end', gap: 12, flexWrap: 'wrap', marginBottom: 14 }}>
        <label style={lbl}>Tax form
          <select className="input"
                  data-testid="accounting-tax-export-form"
                  value={form}
                  onChange={e => setForm(e.target.value)}>
            <option value="">— pick a form —</option>
            {forms.map(f => <option key={f.code} value={f.code}>{f.label}</option>)}
          </select>
        </label>
        <label style={lbl}>Start
          <input type="date" className="input" value={start}
                 onChange={e => setStart(e.target.value)}
                 data-testid="accounting-tax-export-start" />
        </label>
        <label style={lbl}>End
          <input type="date" className="input" value={end}
                 onChange={e => setEnd(e.target.value)}
                 data-testid="accounting-tax-export-end" />
        </label>
      </div>

      {!form && (
        <div data-testid="accounting-tax-export-empty-state" style={emptyHero}>
          Pick a tax form to preview the totals.
        </div>
      )}
      {loading && <p data-testid="accounting-tax-export-loading">Loading…</p>}
      {error   && <p data-testid="accounting-tax-export-error" className="error">Error: {error.message}</p>}

      {data?.totals_by_line && (
        <>
          <div data-testid="accounting-tax-export-summary"
               style={{ display: 'flex', gap: 24, padding: 14, background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: 10, marginBottom: 12, flexWrap: 'wrap' }}>
            <Stat label="Form" value={data.tax_form_label || data.tax_form_code} />
            <Stat label="Window" value={`${data.start} → ${data.end}`} />
            <Stat label="Mapped lines" value={String(data.mapped_count)} testId="accounting-tax-export-mapped-count" />
            <Stat label="Unmapped accounts" value={String(data.unmapped_count)} testId="accounting-tax-export-unmapped-count"
                  warn={data.unmapped_count > 0} />
            <Stat label="Grand total" value={fmt(grandTotal)} bold testId="accounting-tax-export-grand-total" />
          </div>

          <table className="data-table" style={{ width: '100%' }} data-testid="accounting-tax-export-table">
            <thead>
              <tr><th>Line</th><th>Label</th><th>Accounts</th><th style={{ textAlign: 'right' }}>Total</th></tr>
            </thead>
            <tbody>
              {data.totals_by_line.length === 0 && (
                <tr><td colSpan={4} className="empty" data-testid="accounting-tax-export-empty">
                  No mappings on this form. <Link to="/modules/accounting/tax-mappings">Map accounts first</Link>.
                </td></tr>
              )}
              {data.totals_by_line.map((b, i) => (
                <tr key={`${b.line}-${i}`} data-testid={`accounting-tax-export-row-${b.line}-${i}`}>
                  <td><code>{b.line}</code></td>
                  <td>{b.label || ''}</td>
                  <td style={{ fontSize: 12, color: '#64748b' }}>
                    {(b.accounts || []).map(a => a.code).join(', ')}
                  </td>
                  <td style={{ textAlign: 'right', fontFamily: 'ui-monospace, monospace' }}>{fmt(b.total)}</td>
                </tr>
              ))}
            </tbody>
          </table>

          {data.unmapped_summary?.account_count > 0 && (
            <div data-testid="accounting-tax-export-unmapped-warning"
                 style={{ marginTop: 16, padding: 14, background: '#fef3c7', border: '1px solid #fcd34d', borderRadius: 10 }}>
              <strong style={{ fontSize: 13, color: '#92400e' }}>
                {data.unmapped_summary.account_count} account{data.unmapped_summary.account_count === 1 ? '' : 's'} with activity not yet mapped — total {fmt(data.unmapped_summary.total)}
              </strong>
              <ul style={{ margin: '8px 0 0', paddingLeft: 18, fontSize: 12, color: '#78350f' }}>
                {data.unmapped_summary.accounts.slice(0, 8).map(a => (
                  <li key={a.code}>
                    <code>{a.code}</code> {a.name} — {fmt(a.balance)}
                  </li>
                ))}
                {data.unmapped_summary.accounts.length > 8 && (
                  <li style={{ fontStyle: 'italic' }}>+ {data.unmapped_summary.accounts.length - 8} more</li>
                )}
              </ul>
              <Link to="/modules/accounting/tax-mappings" className="btn btn--primary" style={{ marginTop: 10, fontSize: 11 }}
                    data-testid="accounting-tax-export-map-cta">
                Map them now →
              </Link>
            </div>
          )}
        </>
      )}
    </section>
  );
}

const lbl = { display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: '#475569' };
const emptyHero = { padding: 36, textAlign: 'center', color: '#64748b', background: '#f8fafc', border: '1px dashed #e2e8f0', borderRadius: 10 };

function Stat({ label, value, bold, warn, testId }) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 2, minWidth: 120 }}>
      <span style={{ fontSize: 11, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: 0.4 }}>{label}</span>
      <span data-testid={testId}
            style={{ fontFamily: 'ui-monospace, monospace', fontSize: 13, fontWeight: bold ? 700 : 400, color: warn ? '#b45309' : '#0f172a' }}>
        {value}
      </span>
    </div>
  );
}
function fmt(n) {
  return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

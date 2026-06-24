import React, { useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';

const ACCOUNTING_IMPORT_API = '/api/v1/accounting/import';

/**
 * Accounting ledger CSV import.
 *  1. Pick type (COA / Journal Entries / Periods)
 *  2. Download template
 *  3. Paste CSV → dry-run → review errors
 *  4. Commit (optionally skip invalid rows)
 *
 * For JE import: one row per LINE; group by `batch_ref` column (so one JE
 * with 3 lines becomes 3 CSV rows with the same batch_ref). Idempotent via
 * SHA-256 of (tenant_id + batch_ref) as the posting idempotency key.
 */
export default function AccountingImport() {
  const [type, setType] = useState('coa');
  const [csv, setCsv]   = useState('');
  const [dry, setDry]   = useState(null);
  const [busy, setBusy] = useState(false);
  const [err, setErr]   = useState(null);
  const [result, setResult] = useState(null);
  const [skipInvalid, setSkip] = useState(false);

  const download = () => {
    const a = document.createElement('a');
    a.href = `${ACCOUNTING_IMPORT_API}?action=template&type=${type}`;
    a.download = `accounting-${type}-template.csv`;
    document.body.appendChild(a); a.click(); a.remove();
  };

  const runDry = async () => {
    setBusy(true); setErr(null); setResult(null);
    try { setDry(await api.post(`${ACCOUNTING_IMPORT_API}?action=dry_run&type=${type}`, { csv })); }
    catch (e) { setErr(e.message); }
    finally { setBusy(false); }
  };

  const commit = async () => {
    setBusy(true); setErr(null); setResult(null);
    try {
      setResult(await api.post(
        `${ACCOUNTING_IMPORT_API}?action=commit&type=${type}${skipInvalid ? '&skip_invalid=1' : ''}`,
        { csv }));
    } catch (e) { setErr(e.message); }
    finally { setBusy(false); }
  };

  return (
    <section data-testid="accounting-import">
      <h2 style={{ margin: '0 0 8px' }}>CSV ledger import</h2>
      <p style={{ fontSize: 13, color: '#666', maxWidth: 700 }}>
        Use for migrations from other systems (QuickBooks, Wave, etc.). Always dry-run first; then commit.
        Duplicate-safe: COA upserts by <code>code</code>, Periods upsert by <code>(entity_id, start_date)</code>,
        JEs use <code>SHA-256(batch_ref)</code> as their posting idempotency key.
      </p>

      <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 12 }}>
        <label>Type
          <select className="input" value={type} onChange={e => { setType(e.target.value); setDry(null); setResult(null); }} data-testid="accounting-import-type" style={{ marginLeft: 6 }}>
            <option value="coa">Chart of Accounts</option>
            <option value="je">Journal Entries</option>
            <option value="periods">Periods</option>
          </select>
        </label>
        <button className="btn btn--ghost" onClick={download} data-testid="accounting-import-download-template">⬇ Download template</button>
      </div>

      <textarea
        className="input"
        data-testid="accounting-import-csv"
        placeholder="Paste CSV content (first row = headers)"
        value={csv}
        onChange={e => setCsv(e.target.value)}
        style={{ width: '100%', minHeight: 180, fontFamily: 'monospace', fontSize: 12 }}
      />
      <div style={{ display: 'flex', gap: 8, margin: '8px 0' }}>
        <button className="btn btn--ghost" onClick={runDry} disabled={!csv || busy} data-testid="accounting-import-dry-run">
          {busy ? 'Running…' : 'Dry run'}
        </button>
        <button className="btn btn--primary" onClick={commit} disabled={!csv || busy} data-testid="accounting-import-commit">
          {busy ? 'Committing…' : 'Commit'}
        </button>
        <label style={{ display: 'flex', alignItems: 'center', gap: 4, fontSize: 13 }}>
          <input type="checkbox" checked={skipInvalid} onChange={e => setSkip(e.target.checked)} data-testid="accounting-import-skip-invalid" />
          Skip invalid rows
        </label>
      </div>
      {err && <p className="error" data-testid="accounting-import-error">{err}</p>}

      {dry && (
        <div data-testid="accounting-import-dry-result" style={{ background: '#f9fafb', border: '1px solid #e5e7eb', padding: 12, borderRadius: 6 }}>
          <strong>Dry run:</strong> {dry.row_count} rows · {dry.error_count || 0} errors
          {dry.errors && Object.keys(dry.errors).length > 0 && (
            <ul style={{ fontSize: 12, marginTop: 6 }}>
              {Object.entries(dry.errors).slice(0, 20).map(([k, v]) => (
                <li key={k}><strong>Row {k}:</strong> {(v || []).join(', ')}</li>
              ))}
            </ul>
          )}
        </div>
      )}
      {result && (
        <div data-testid="accounting-import-commit-result" style={{ background: '#ecfdf5', border: '1px solid #a7f3d0', padding: 12, borderRadius: 6, marginTop: 8 }}>
          <strong>Commit:</strong> imported {result.imported_count} · skipped {result.skipped_count}
          {result.errors && Object.keys(result.errors).length > 0 && (
            <ul style={{ fontSize: 12, marginTop: 6 }}>
              {Object.entries(result.errors).slice(0, 20).map(([k, v]) => (
                <li key={k}><strong>{k}:</strong> {(v || []).join(', ')}</li>
              ))}
            </ul>
          )}
          {result.message && <div style={{ fontSize: 13, marginTop: 6 }}>{result.message}</div>}
        </div>
      )}
    </section>
  );
}

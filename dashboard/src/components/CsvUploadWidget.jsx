/**
 * CsvUploadWidget — tiny reusable file-picker → multipart POST →
 * result panel for any CSV-import endpoint. Used by the Treasury
 * bank-account drawer (statement CSV) and the Payroll pay-period
 * drawer (payroll register CSV).
 *
 *   <CsvUploadWidget
 *     testIdPrefix="treasury-csv"
 *     endpoint="/api/v1/treasury/import-csv"
 *     extraFields={{ bank_account_id: 42 }}
 *     accept=".csv,text/csv"
 *     label="Upload bank statement CSV"
 *     hint="Date / Description / Amount (or Debit + Credit) / optional Reference."
 *     onSuccess={result => reloadParent()}
 *   />
 */
import React, { useState } from 'react';

export default function CsvUploadWidget({
  testIdPrefix = 'cf-csv',
  endpoint,
  extraFields = {},
  accept       = '.csv,text/csv,text/plain',
  label        = 'Upload CSV',
  hint         = null,
  onSuccess    = null,
}) {
  const [file,    setFile]    = useState(null);
  const [busy,    setBusy]    = useState(false);
  const [result,  setResult]  = useState(null);
  const [error,   setError]   = useState(null);

  const submit = async () => {
    if (!file) { setError('Pick a CSV file first.'); return; }
    setBusy(true); setError(null); setResult(null);
    try {
      const form = new FormData();
      Object.entries(extraFields).forEach(([k, v]) => form.append(k, String(v)));
      form.append('file', file);
      const base = (window.__CF_API_BASE__ || '');
      const res = await fetch(`${base}${endpoint}`, {
        method: 'POST', credentials: 'include', body: form,
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok || json.ok === false) {
        throw new Error(json.error || json.message || `Upload failed (${res.status})`);
      }
      setResult(json);
      if (onSuccess) onSuccess(json);
    } catch (e) {
      setError(e.message || 'Upload failed');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div data-testid={`${testIdPrefix}-widget`}
         style={{ marginBottom: 12, padding: 12, background: '#fefce8',
                  border: '1px solid #fde68a', borderRadius: 8, fontSize: 13 }}>
      <strong style={{ display: 'block', marginBottom: 4 }}>{label}</strong>
      {hint && (
        <p data-testid={`${testIdPrefix}-hint`}
           style={{ margin: '0 0 8px', fontSize: 12, color: '#475569' }}>
          {hint}
        </p>
      )}
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
        <input
          data-testid={`${testIdPrefix}-file`}
          type="file"
          accept={accept}
          onChange={e => { setFile(e.target.files?.[0] || null); setError(null); setResult(null); }}
          style={{ fontSize: 12 }}
        />
        <button
          data-testid={`${testIdPrefix}-submit`}
          onClick={submit}
          disabled={busy || !file}
          className="btn btn--primary"
          style={{ fontSize: 12 }}>
          {busy ? 'Uploading…' : 'Upload CSV'}
        </button>
      </div>
      {error && (
        <p data-testid={`${testIdPrefix}-error`}
           style={{ marginTop: 8, color: '#b91c1c', fontSize: 12 }}>{error}</p>
      )}
      {result && (
        <div data-testid={`${testIdPrefix}-result`}
             style={{ marginTop: 8, padding: 8, background: '#ecfdf5',
                      border: '1px solid #86efac', borderRadius: 6, fontSize: 12 }}>
          <strong>Done.</strong>{' '}
          {result.rows_inserted !== undefined && (
            <>Inserted <strong>{result.rows_inserted}</strong></>
          )}
          {result.rows_seen !== undefined && (
            <> of {result.rows_seen} row(s)</>
          )}
          {result.rows_duplicate > 0 && (
            <> · <em>{result.rows_duplicate} duplicate(s)</em></>
          )}
          {result.rows_skipped > 0 && (
            <> · <em>{result.rows_skipped} skipped</em></>
          )}
          {result.run_id && <> · run_id <code>{result.run_id}</code></>}
          {result.date_range && Array.isArray(result.date_range) && result.date_range[0] && (
            <> · range {result.date_range[0]} → {result.date_range[1]}</>
          )}
          {Array.isArray(result.errors) && result.errors.length > 0 && (
            <details style={{ marginTop: 6 }}>
              <summary style={{ cursor: 'pointer', color: '#b91c1c' }}>
                {result.errors.length} row error(s)
              </summary>
              <ul style={{ margin: '4px 0 0 16px', padding: 0 }}>
                {result.errors.map((er, i) =>
                  <li key={i} style={{ fontSize: 11 }}>{er}</li>
                )}
              </ul>
            </details>
          )}
        </div>
      )}
    </div>
  );
}

import React, { useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../../dashboard/src/lib/api';

/**
 * Shared CSV Import flow — built on Core\CsvImportService.
 *
 * Per HARD_RULES (2026-02-XX): every primary-entity module exposes a CSV
 * import via /api/<module>/csv_import.php with the three actions
 *   ?action=template | ?action=dry_run | ?action=commit
 *
 * To avoid duplicating 170 lines of UI per entity, this component is
 * parameterized by:
 *   - endpoint:       e.g. '/modules/ap/api/csv_import.php'  (or '/api/ap/csv_import')
 *   - entityLabel:    'Vendors' (used in headings + filename)
 *   - previewColumns: [{ key, label }]   how to render the dry-run preview table
 *   - backTo:         react-router path for the "back" link
 *   - testidPrefix:   data-testid prefix, e.g. 'ap-vendors-csv-import'
 *
 * Usage:
 *   <CsvImportPage endpoint="/modules/ap/api/csv_import.php"
 *                  entityLabel="Vendors"
 *                  backTo=".."
 *                  testidPrefix="ap-vendors-csv-import"
 *                  previewColumns={[
 *                    { key: 'vendor_name', label: 'Name' },
 *                    { key: 'vendor_type', label: 'Type' },
 *                  ]} />
 */
export default function CsvImportPage({
  endpoint,
  entityLabel,
  previewColumns,
  backTo = '..',
  backLabel = '← Back',
  testidPrefix = 'csv-import',
}) {
  const fileRef = useRef(null);
  const [csvText, setCsvText]         = useState('');
  const [fileName, setFileName]       = useState('');
  const [preview, setPreview]         = useState(null);
  const [running, setRunning]         = useState(false);
  const [error, setError]             = useState(null);
  const [committed, setCommitted]     = useState(null);
  const [skipInvalid, setSkipInvalid] = useState(false);

  const onFile = (e) => {
    const f = e.target.files?.[0];
    if (!f) return;
    setFileName(f.name);
    const reader = new FileReader();
    reader.onload = () => setCsvText(String(reader.result || ''));
    reader.readAsText(f);
  };

  const dryRun = async () => {
    if (!csvText) return;
    setRunning(true); setError(null); setCommitted(null);
    try {
      const res = await api.post(`${endpoint}?action=dry_run`, { csv: csvText });
      setPreview(res);
    } catch (e) { setError(e); }
    finally     { setRunning(false); }
  };

  const commit = async () => {
    if (!csvText) return;
    setRunning(true); setError(null);
    try {
      const path = `${endpoint}?action=commit${skipInvalid ? '&skip_invalid=1' : ''}`;
      const res = await api.post(path, { csv: csvText });
      setCommitted(res);
    } catch (e) { setError(e); }
    finally     { setRunning(false); }
  };

  const reset = () => {
    setCsvText(''); setFileName(''); setPreview(null); setCommitted(null); setError(null);
    if (fileRef.current) fileRef.current.value = '';
  };

  const rowsArr = preview ? Object.entries(preview.rows || {}) : [];
  const errorsByRow = preview?.errors || {};

  return (
    <section data-testid={testidPrefix}>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-4)' }}>
        <div>
          <h2>CSV Import — {entityLabel}</h2>
          <p style={{ color: 'var(--cf-text-secondary)' }}>
            Bulk-create {entityLabel.toLowerCase()}. Template-driven; dry-run before commit.
          </p>
        </div>
        <Link to={backTo} className="btn btn--ghost" data-testid={`${testidPrefix}-back`}>{backLabel}</Link>
      </header>

      <div style={{ background: 'var(--cf-surface)', padding: 'var(--cf-space-6)', borderRadius: 'var(--cf-radius-lg)', border: '1px solid var(--cf-border)' }}>
        <div style={{ display: 'flex', gap: 'var(--cf-space-3)', flexWrap: 'wrap', marginBottom: 'var(--cf-space-4)' }}>
          <a
            className="btn"
            href={`${endpoint}?action=template`}
            data-testid={`${testidPrefix}-download-template`}
          >
            Download template
          </a>
          <a
            className="btn btn--ghost"
            href={`${endpoint}?action=sample`}
            data-testid={`${testidPrefix}-download-sample`}
          >
            Download sample with example rows
          </a>
          <input
            ref={fileRef}
            type="file"
            accept=".csv,text/csv"
            onChange={onFile}
            data-testid={`${testidPrefix}-file-input`}
            style={{ alignSelf: 'center' }}
          />
          {fileName && <span data-testid={`${testidPrefix}-filename`} style={{ alignSelf: 'center', color: 'var(--cf-text-secondary)' }}>{fileName}</span>}
          <button
            className="btn btn--primary"
            onClick={dryRun}
            disabled={!csvText || running}
            data-testid={`${testidPrefix}-dry-run`}
          >
            {running ? 'Validating…' : 'Validate (dry run)'}
          </button>
          {(preview || committed) && (
            <button className="btn" onClick={reset} data-testid={`${testidPrefix}-reset`}>Reset</button>
          )}
        </div>

        {error && <p className="error" data-testid={`${testidPrefix}-error`}>Error: {error.message}</p>}

        {preview && !committed && (
          <>
            <div style={{ display: 'flex', gap: 'var(--cf-space-4)', marginBottom: 'var(--cf-space-3)', alignItems: 'center', flexWrap: 'wrap' }}>
              <strong data-testid={`${testidPrefix}-preview-summary`}>
                {preview.row_count} rows · {preview.error_count} with errors · {preview.row_count - preview.error_count} valid
              </strong>
              {preview.error_count > 0 && (
                <label style={{ display: 'flex', alignItems: 'center', gap: 'var(--cf-space-2)' }}>
                  <input
                    type="checkbox"
                    checked={skipInvalid}
                    onChange={e => setSkipInvalid(e.target.checked)}
                    data-testid={`${testidPrefix}-skip-invalid`}
                  />
                  Skip invalid rows and import the rest
                </label>
              )}
              <button
                className="btn btn--primary"
                onClick={commit}
                disabled={running || (preview.error_count > 0 && !skipInvalid)}
                data-testid={`${testidPrefix}-commit`}
              >
                {running ? 'Importing…' : 'Commit import'}
              </button>
            </div>

            <table className="data-table" data-testid={`${testidPrefix}-preview-table`} style={{ marginTop: 'var(--cf-space-3)' }}>
              <thead>
                <tr>
                  <th>#</th>
                  {previewColumns.map(c => <th key={c.key}>{c.label}</th>)}
                  <th>Errors</th>
                </tr>
              </thead>
              <tbody>
                {rowsArr.length === 0 && (
                  <tr><td colSpan={previewColumns.length + 2} className="empty">No rows parsed.</td></tr>
                )}
                {rowsArr.map(([rn, row]) => {
                  const errs = errorsByRow[rn] || [];
                  return (
                    <tr key={rn} data-testid={`${testidPrefix}-row-${rn}`} style={{ background: errs.length ? 'rgba(239,68,68,0.04)' : 'transparent' }}>
                      <td>{rn}</td>
                      {previewColumns.map(c => <td key={c.key}>{row[c.key] ?? ''}</td>)}
                      <td>
                        {errs.length === 0
                          ? <span style={{ color: 'var(--cf-green)' }}>✓ valid</span>
                          : <ul style={{ margin: 0, paddingLeft: '1rem', color: '#c0392b' }}>
                              {errs.map((e, i) => <li key={i}>{e}</li>)}
                            </ul>}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </>
        )}

        {committed && (
          <div data-testid={`${testidPrefix}-result`} style={{ marginTop: 'var(--cf-space-4)' }}>
            <h3>Import complete</h3>
            <p>
              <strong data-testid={`${testidPrefix}-result-imported`}>{committed.imported_count}</strong> imported,{' '}
              <strong data-testid={`${testidPrefix}-result-skipped`}>{committed.skipped_count}</strong> skipped.
            </p>
            <Link to={backTo} className="btn btn--primary" data-testid={`${testidPrefix}-result-back`}>Done</Link>
          </div>
        )}
      </div>
    </section>
  );
}

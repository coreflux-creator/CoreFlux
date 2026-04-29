import React, { useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../../dashboard/src/lib/api';

/**
 * CSV Import — built on Core\CsvImportService (PHP).
 *
 * Three-step flow per HARD_RULES (2026-02-XX):
 *   1. Download template (?action=template)
 *   2. Upload + dry-run (?action=dry_run) — preview rows + errors
 *   3. Commit (?action=commit) — actually persist; supports skip_invalid
 */
export default function CsvImport() {
  const fileRef = useRef(null);
  const [csvText, setCsvText] = useState('');
  const [fileName, setFileName] = useState('');
  const [preview, setPreview]   = useState(null);
  const [running, setRunning]   = useState(false);
  const [error, setError]       = useState(null);
  const [committed, setCommitted] = useState(null);
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
      const res = await api.post('/modules/people/api/csv_import.php?action=dry_run', { csv: csvText });
      setPreview(res);
    } catch (e) { setError(e); }
    finally     { setRunning(false); }
  };

  const commit = async () => {
    if (!csvText) return;
    setRunning(true); setError(null);
    try {
      const path = `/modules/people/api/csv_import.php?action=commit${skipInvalid ? '&skip_invalid=1' : ''}`;
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
    <section data-testid="people-csv-import">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-4)' }}>
        <div>
          <h2>CSV Import — People</h2>
          <p style={{ color: 'var(--cf-text-secondary)' }}>
            Bulk-create person records. Template-driven; dry-run before commit.
          </p>
        </div>
        <Link to=".." className="btn btn--ghost" data-testid="csv-import-back">← Directory</Link>
      </header>

      <div style={{ background: 'var(--cf-surface)', padding: 'var(--cf-space-6)', borderRadius: 'var(--cf-radius-lg)', border: '1px solid var(--cf-border)' }}>
        <div style={{ display: 'flex', gap: 'var(--cf-space-3)', flexWrap: 'wrap', marginBottom: 'var(--cf-space-4)' }}>
          <a
            className="btn"
            href="/modules/people/api/csv_import.php?action=template"
            data-testid="csv-import-download-template"
          >
            Download template
          </a>
          <input
            ref={fileRef}
            type="file"
            accept=".csv,text/csv"
            onChange={onFile}
            data-testid="csv-import-file-input"
            style={{ alignSelf: 'center' }}
          />
          {fileName && <span data-testid="csv-import-filename" style={{ alignSelf: 'center', color: 'var(--cf-text-secondary)' }}>{fileName}</span>}
          <button
            className="btn btn--primary"
            onClick={dryRun}
            disabled={!csvText || running}
            data-testid="csv-import-dry-run"
          >
            {running ? 'Validating…' : 'Validate (dry run)'}
          </button>
          {(preview || committed) && (
            <button className="btn" onClick={reset} data-testid="csv-import-reset">Reset</button>
          )}
        </div>

        {error && <p className="error" data-testid="csv-import-error">Error: {error.message}</p>}

        {preview && !committed && (
          <>
            <div style={{ display: 'flex', gap: 'var(--cf-space-4)', marginBottom: 'var(--cf-space-3)', alignItems: 'center', flexWrap: 'wrap' }}>
              <strong data-testid="csv-import-preview-summary">
                {preview.row_count} rows · {preview.error_count} with errors · {preview.row_count - preview.error_count} valid
              </strong>
              {preview.error_count > 0 && (
                <label data-testid="csv-import-skip-invalid-label" style={{ display: 'flex', alignItems: 'center', gap: 'var(--cf-space-2)' }}>
                  <input
                    type="checkbox"
                    checked={skipInvalid}
                    onChange={e => setSkipInvalid(e.target.checked)}
                    data-testid="csv-import-skip-invalid"
                  />
                  Skip invalid rows and import the rest
                </label>
              )}
              <button
                className="btn btn--primary"
                onClick={commit}
                disabled={running || (preview.error_count > 0 && !skipInvalid)}
                data-testid="csv-import-commit"
              >
                {running ? 'Importing…' : 'Commit import'}
              </button>
            </div>

            <table className="data-table" data-testid="csv-import-preview-table" style={{ marginTop: 'var(--cf-space-3)' }}>
              <thead><tr><th>#</th><th>First</th><th>Last</th><th>Email</th><th>Classification</th><th>Errors</th></tr></thead>
              <tbody>
                {rowsArr.length === 0 && <tr><td colSpan={6} className="empty" data-testid="csv-import-preview-empty">No rows parsed.</td></tr>}
                {rowsArr.map(([rn, row]) => {
                  const errs = errorsByRow[rn] || [];
                  return (
                    <tr key={rn} data-testid={`csv-import-row-${rn}`} style={{ background: errs.length ? 'rgba(239,68,68,0.04)' : 'transparent' }}>
                      <td>{rn}</td>
                      <td>{row.first_name}</td>
                      <td>{row.last_name}</td>
                      <td>{row.email_primary}</td>
                      <td>{row.classification}</td>
                      <td>
                        {errs.length === 0 ? <span style={{ color: 'var(--cf-green)' }}>✓ valid</span>
                                            : <ul style={{ margin: 0, paddingLeft: '1rem', color: '#c0392b' }}>
                                                {errs.map((e, i) => <li key={i} data-testid={`csv-import-row-${rn}-error-${i}`}>{e}</li>)}
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
          <div data-testid="csv-import-result" style={{ marginTop: 'var(--cf-space-4)' }}>
            <h3>Import complete</h3>
            <p>
              <strong data-testid="csv-import-result-imported">{committed.imported_count}</strong> imported,{' '}
              <strong data-testid="csv-import-result-skipped">{committed.skipped_count}</strong> skipped.
            </p>
            <Link to=".." className="btn btn--primary" data-testid="csv-import-result-back">View directory</Link>
          </div>
        )}
      </div>
    </section>
  );
}

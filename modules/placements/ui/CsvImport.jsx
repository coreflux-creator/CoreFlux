import React, { useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../../dashboard/src/lib/api';

/**
 * Placements CSV Import — same pattern as People (Core\CsvImportService primitive).
 * Imports placements + first rate row + chain[0] (end client).
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
    const f = e.target.files?.[0]; if (!f) return;
    setFileName(f.name);
    const r = new FileReader();
    r.onload = () => setCsvText(String(r.result || ''));
    r.readAsText(f);
  };
  const dryRun = async () => {
    setRunning(true); setError(null); setCommitted(null);
    try { setPreview(await api.post('/modules/placements/api/csv_import.php?action=dry_run', { csv: csvText })); }
    catch (e) { setError(e); } finally { setRunning(false); }
  };
  const commit = async () => {
    setRunning(true); setError(null);
    try {
      const path = `/modules/placements/api/csv_import.php?action=commit${skipInvalid ? '&skip_invalid=1' : ''}`;
      setCommitted(await api.post(path, { csv: csvText }));
    } catch (e) { setError(e); } finally { setRunning(false); }
  };
  const reset = () => { setCsvText(''); setFileName(''); setPreview(null); setCommitted(null); setError(null); if (fileRef.current) fileRef.current.value = ''; };

  const rowsArr = preview ? Object.entries(preview.rows || {}) : [];
  const errorsByRow = preview?.errors || {};

  return (
    <section data-testid="placements-csv-import">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-4)' }}>
        <div>
          <h2>CSV Import — Placements</h2>
          <p style={{ color: 'var(--cf-text-secondary)' }}>Imports placement + first rate row + end-client tier. Multi-tier chain, commissions, referrals, corp added per-placement after.</p>
        </div>
        <Link to=".." className="btn btn--ghost" data-testid="placements-csv-back">← List</Link>
      </header>

      <div style={{ background: 'var(--cf-surface)', padding: 'var(--cf-space-6)', borderRadius: 'var(--cf-radius-lg)', border: '1px solid var(--cf-border)' }}>
        <div style={{ display: 'flex', gap: 'var(--cf-space-3)', flexWrap: 'wrap', marginBottom: 'var(--cf-space-4)' }}>
          <a className="btn" href="/modules/placements/api/csv_import.php?action=template" data-testid="placements-csv-template">Download template</a>
          <input ref={fileRef} type="file" accept=".csv,text/csv" onChange={onFile} data-testid="placements-csv-file" style={{ alignSelf: 'center' }} />
          {fileName && <span data-testid="placements-csv-filename" style={{ alignSelf: 'center', color: 'var(--cf-text-secondary)' }}>{fileName}</span>}
          <button className="btn btn--primary" onClick={dryRun} disabled={!csvText || running} data-testid="placements-csv-dryrun">{running ? 'Validating…' : 'Validate (dry run)'}</button>
          {(preview || committed) && <button className="btn" onClick={reset} data-testid="placements-csv-reset">Reset</button>}
        </div>

        {error && <p className="error" data-testid="placements-csv-error">Error: {error.message}</p>}

        {preview && !committed && (
          <>
            <div style={{ display: 'flex', gap: 'var(--cf-space-4)', marginBottom: 'var(--cf-space-3)', alignItems: 'center', flexWrap: 'wrap' }}>
              <strong data-testid="placements-csv-summary">{preview.row_count} rows · {preview.error_count} errors · {preview.row_count - preview.error_count} valid</strong>
              {preview.error_count > 0 && (
                <label style={{ display: 'flex', alignItems: 'center', gap: 'var(--cf-space-2)' }}>
                  <input type="checkbox" checked={skipInvalid} onChange={e => setSkipInvalid(e.target.checked)} data-testid="placements-csv-skip-invalid" />
                  Skip invalid, import valid
                </label>
              )}
              <button className="btn btn--primary" onClick={commit} disabled={running || (preview.error_count > 0 && !skipInvalid)} data-testid="placements-csv-commit">
                {running ? 'Importing…' : 'Commit import'}
              </button>
            </div>
            <table className="data-table" data-testid="placements-csv-preview-table">
              <thead><tr><th>#</th><th>Person email</th><th>Title</th><th>Type</th><th>Start</th><th>Errors</th></tr></thead>
              <tbody>
                {rowsArr.length === 0 && <tr><td colSpan={6} className="empty">No rows parsed.</td></tr>}
                {rowsArr.map(([rn, row]) => {
                  const errs = errorsByRow[rn] || [];
                  return (
                    <tr key={rn} data-testid={`placements-csv-row-${rn}`} style={{ background: errs.length ? 'rgba(239,68,68,0.04)' : 'transparent' }}>
                      <td>{rn}</td><td>{row.person_email}</td><td>{row.title}</td><td>{row.engagement_type}</td><td>{row.start_date}</td>
                      <td>{errs.length === 0 ? <span style={{ color: 'var(--cf-green)' }}>✓ valid</span> : (
                        <ul style={{ margin: 0, paddingLeft: '1rem', color: '#c0392b' }}>{errs.map((e, i) => <li key={i}>{e}</li>)}</ul>
                      )}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </>
        )}

        {committed && (
          <div data-testid="placements-csv-result" style={{ marginTop: 'var(--cf-space-4)' }}>
            <h3>Import complete</h3>
            <p><strong data-testid="placements-csv-imported">{committed.imported_count}</strong> imported, <strong data-testid="placements-csv-skipped">{committed.skipped_count}</strong> skipped.</p>
            <Link to=".." className="btn btn--primary" data-testid="placements-csv-back-after">View placements</Link>
          </div>
        )}
      </div>
    </section>
  );
}

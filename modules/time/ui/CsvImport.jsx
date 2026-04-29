import React, { useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../../dashboard/src/lib/api';

export default function CsvImport() {
  const fileRef = useRef(null);
  const [csvText, setCsvText] = useState(''); const [fileName, setFileName] = useState('');
  const [preview, setPreview] = useState(null); const [committed, setCommitted] = useState(null);
  const [running, setRunning] = useState(false); const [error, setError] = useState(null);
  const [skipInvalid, setSkipInvalid] = useState(false); const [preApproved, setPreApproved] = useState(false);

  const onFile = (e) => {
    const f = e.target.files?.[0]; if (!f) return;
    setFileName(f.name);
    const r = new FileReader(); r.onload = () => setCsvText(String(r.result || '')); r.readAsText(f);
  };
  const dryRun = async () => {
    setRunning(true); setError(null); setCommitted(null);
    try { setPreview(await api.post('/modules/time/api/csv_import.php?action=dry_run', { csv: csvText })); }
    catch (e) { setError(e); } finally { setRunning(false); }
  };
  const commit = async () => {
    setRunning(true); setError(null);
    try {
      const q = [];
      if (skipInvalid) q.push('skip_invalid=1');
      if (preApproved) q.push('already_approved=1');
      const path = `/modules/time/api/csv_import.php?action=commit${q.length ? '&' + q.join('&') : ''}`;
      setCommitted(await api.post(path, { csv: csvText }));
    } catch (e) { setError(e); } finally { setRunning(false); }
  };
  const reset = () => { setCsvText(''); setFileName(''); setPreview(null); setCommitted(null); setError(null); if (fileRef.current) fileRef.current.value = ''; };

  const rowsArr = preview ? Object.entries(preview.rows || {}) : [];
  const errorsByRow = preview?.errors || {};

  return (
    <section data-testid="time-csv-import">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-4)' }}>
        <div>
          <h2>Bulk Upload — Time Entries</h2>
          <p style={{ color: 'var(--cf-text-secondary)' }}>Columns: placement_external_id, work_date, category, hours, description.</p>
        </div>
        <Link to="../entries" className="btn btn--ghost" data-testid="time-csv-back">← My Time</Link>
      </header>

      <div style={{ background: 'var(--cf-surface)', padding: 'var(--cf-space-6)', borderRadius: 'var(--cf-radius-lg)', border: '1px solid var(--cf-border)' }}>
        <div style={{ display: 'flex', gap: 'var(--cf-space-3)', flexWrap: 'wrap', marginBottom: 'var(--cf-space-4)' }}>
          <a className="btn" href="/modules/time/api/csv_import.php?action=template" data-testid="time-csv-template">Download template</a>
          <input ref={fileRef} type="file" accept=".csv,text/csv" onChange={onFile} data-testid="time-csv-file" />
          {fileName && <span style={{ alignSelf: 'center', color: 'var(--cf-text-secondary)' }} data-testid="time-csv-filename">{fileName}</span>}
          <button className="btn btn--primary" onClick={dryRun} disabled={!csvText || running} data-testid="time-csv-dryrun">
            {running ? 'Validating…' : 'Validate (dry run)'}
          </button>
          {(preview || committed) && <button className="btn" onClick={reset} data-testid="time-csv-reset">Reset</button>}
        </div>

        {error && <p className="error" data-testid="time-csv-error">Error: {error.message}</p>}

        {preview && !committed && (
          <>
            <div style={{ display: 'flex', gap: 'var(--cf-space-4)', marginBottom: 'var(--cf-space-3)', alignItems: 'center', flexWrap: 'wrap' }}>
              <strong data-testid="time-csv-summary">{preview.row_count} rows · {preview.error_count} errors · {preview.row_count - preview.error_count} valid</strong>
              {preview.error_count > 0 && (
                <label style={{ display: 'flex', alignItems: 'center', gap: 'var(--cf-space-2)' }}>
                  <input type="checkbox" checked={skipInvalid} onChange={e => setSkipInvalid(e.target.checked)} data-testid="time-csv-skip-invalid" />
                  Skip invalid, import valid
                </label>
              )}
              <label data-testid="time-csv-pre-approved-label" style={{ display: 'flex', alignItems: 'center', gap: 'var(--cf-space-2)' }}>
                <input type="checkbox" checked={preApproved} onChange={e => setPreApproved(e.target.checked)} data-testid="time-csv-pre-approved" />
                Mark as already approved (requires time.approve; client already signed off out of band)
              </label>
              <button className="btn btn--primary" onClick={commit} disabled={running || (preview.error_count > 0 && !skipInvalid)} data-testid="time-csv-commit">
                {running ? 'Importing…' : 'Commit import'}
              </button>
            </div>
            <table className="data-table" data-testid="time-csv-preview-table">
              <thead><tr><th>#</th><th>Placement ext</th><th>Date</th><th>Category</th><th>Hours</th><th>Errors</th></tr></thead>
              <tbody>
                {rowsArr.length === 0 && <tr><td colSpan={6} className="empty">No rows parsed.</td></tr>}
                {rowsArr.map(([rn, row]) => {
                  const errs = errorsByRow[rn] || [];
                  return (
                    <tr key={rn} data-testid={`time-csv-row-${rn}`} style={{ background: errs.length ? 'rgba(239,68,68,0.04)' : 'transparent' }}>
                      <td>{rn}</td><td>{row.placement_external_id}</td><td>{row.work_date}</td><td>{row.category}</td><td>{row.hours}</td>
                      <td>{errs.length === 0 ? <span style={{ color: 'var(--cf-green)' }}>✓ valid</span>
                                               : <ul style={{ margin: 0, paddingLeft: '1rem', color: '#c0392b' }}>{errs.map((e, i) => <li key={i}>{e}</li>)}</ul>}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </>
        )}

        {committed && (
          <div data-testid="time-csv-result" style={{ marginTop: 'var(--cf-space-4)' }}>
            <h3>Import complete</h3>
            <p><strong data-testid="time-csv-imported">{committed.imported_count}</strong> imported, <strong data-testid="time-csv-skipped">{committed.skipped_count}</strong> skipped.</p>
            <Link to="../review" className="btn btn--primary" data-testid="time-csv-goto-review">Review queue →</Link>
          </div>
        )}
      </div>
    </section>
  );
}

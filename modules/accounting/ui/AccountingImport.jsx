import React, { useMemo, useRef, useState } from 'react';
import {
  AlertCircle,
  CheckCircle2,
  Download,
  FileSpreadsheet,
  Info,
  RotateCcw,
  Upload,
} from 'lucide-react';
import { api } from '../../../dashboard/src/lib/api';

const IMPORT_TYPES = {
  je: {
    label: 'Journal entries',
    description: 'One row per debit or credit line. A Placement ID automatically supplies its assignment dimensions.',
    required: [
      { label: 'Batch ref', aliases: ['batch ref', 'batch_ref'], defaultable: true },
      { label: 'Posting date', aliases: ['posting date', 'posting_date'] },
      { label: 'Account code', aliases: ['account code', 'account_code'] },
      { label: 'Entity id', aliases: ['entity id', 'entity_id'] },
    ],
  },
  coa: {
    label: 'Chart of accounts',
    description: 'Create new accounts or update existing accounts with the same code.',
    required: [
      { label: 'Code', aliases: ['code'] },
      { label: 'Name', aliases: ['name'] },
      { label: 'Account type', aliases: ['account type', 'account_type'] },
      { label: 'Normal side', aliases: ['normal side', 'normal_side'] },
    ],
  },
  periods: {
    label: 'Accounting periods',
    description: 'Create or update periods for an entity and control their close status.',
    required: [
      { label: 'Entity id', aliases: ['entity id', 'entity_id'] },
      { label: 'Period number', aliases: ['period number', 'period_number'] },
      { label: 'Start date', aliases: ['start date', 'start_date'] },
      { label: 'End date', aliases: ['end date', 'end_date'] },
      { label: 'Status', aliases: ['status'] },
    ],
  },
};

const ERROR_LABELS = {
  batch_ref: 'Batch ref',
  posting_date: 'Posting date',
  account_code: 'Account code',
  line_memo: 'Line memo',
  entity_id: 'Entity id',
  account_type: 'Account type',
  normal_side: 'Normal side',
  parent_account_id: 'Parent account id',
  is_postable: 'Is postable',
  cash_flow_tag: 'Cash flow tag',
  period_number: 'Period number',
  start_date: 'Start date',
  end_date: 'End date',
};

function normalizeHeader(value) {
  return String(value || '').replace(/^\uFEFF/, '').trim().toLowerCase();
}

function parseDelimitedRows(text, delimiter, maxRows = 8) {
  const rows = [];
  let row = [];
  let cell = '';
  let quoted = false;

  const pushRow = () => {
    row.push(cell);
    if (row.some(value => String(value).trim() !== '')) rows.push(row);
    row = [];
    cell = '';
  };

  for (let index = 0; index < text.length && rows.length < maxRows; index += 1) {
    const char = text[index];
    const next = text[index + 1];
    if (char === '"') {
      if (quoted && next === '"') {
        cell += '"';
        index += 1;
      } else {
        quoted = !quoted;
      }
    } else if (char === delimiter && !quoted) {
      row.push(cell);
      cell = '';
    } else if ((char === '\n' || char === '\r') && !quoted) {
      if (char === '\r' && next === '\n') index += 1;
      pushRow();
    } else {
      cell += char;
    }
  }

  if (rows.length < maxRows && (cell !== '' || row.length > 0)) pushRow();
  return rows.slice(0, maxRows);
}

function detectDelimiter(text) {
  const firstLine = String(text || '').split(/\r\n|\n|\r/).find(line => line.trim()) || '';
  const candidates = [',', '\t', ';', '|'];
  return candidates.reduce((best, candidate) => {
    const columns = parseDelimitedRows(firstLine, candidate, 1)[0]?.length || 1;
    return columns > best.columns ? { delimiter: candidate, columns } : best;
  }, { delimiter: ',', columns: 1 }).delimiter;
}

function buildSourcePreview(text) {
  if (!String(text || '').trim()) return { headers: [], rows: [], delimiter: ',' };
  const delimiter = detectDelimiter(text);
  const rows = parseDelimitedRows(text, delimiter, 7);
  const headers = (rows[0] || []).map(value => String(value).replace(/^\uFEFF/, '').trim());
  return { headers, rows: rows.slice(1), delimiter };
}

function delimiterName(delimiter) {
  if (delimiter === '\t') return 'Excel/tab-separated';
  if (delimiter === ';') return 'Semicolon-separated';
  if (delimiter === '|') return 'Pipe-separated';
  return 'Comma-separated CSV';
}

function formatImportError(message) {
  const value = String(message || '');
  const match = value.match(/^([a-z0-9_]+):\s*(.*)$/i);
  if (!match) return value;
  const label = ERROR_LABELS[match[1]] || match[1].replaceAll('_', ' ');
  const detail = match[2] === 'required' ? 'is required' : match[2];
  return `${label}: ${detail}`;
}

/** Accounting ledger CSV/TSV import with an aligned source preview. */
export default function AccountingImport() {
  const fileRef = useRef(null);
  const [type, setType] = useState('je');
  const [csv, setCsv] = useState('');
  const [fileName, setFileName] = useState('');
  const [defaultBatchRef, setDefaultBatchRef] = useState('');
  const [dry, setDry] = useState(null);
  const [busyAction, setBusyAction] = useState(null);
  const [err, setErr] = useState(null);
  const [result, setResult] = useState(null);
  const [skipInvalid, setSkip] = useState(false);

  const config = IMPORT_TYPES[type];
  const sourcePreview = useMemo(() => buildSourcePreview(csv), [csv]);
  const normalizedHeaders = useMemo(
    () => new Set(sourcePreview.headers.map(normalizeHeader)),
    [sourcePreview.headers]
  );
  const missingRequired = config.required.filter(field => {
    if (field.defaultable && defaultBatchRef.trim()) return false;
    return !field.aliases.some(alias => normalizedHeaders.has(alias));
  });
  const validRows = dry ? Math.max(0, dry.row_count - (dry.error_count || 0)) : 0;
  const canCommit = Boolean(
    dry &&
    dry.row_count > 0 &&
    (dry.error_count === 0 || (skipInvalid && validRows > 0)) &&
    !busyAction
  );

  const invalidateCheck = () => {
    setDry(null);
    setResult(null);
    setErr(null);
    setSkip(false);
  };

  const handleTypeChange = event => {
    setType(event.target.value);
    setDefaultBatchRef('');
    invalidateCheck();
  };

  const handleCsvChange = value => {
    setCsv(value);
    invalidateCheck();
  };

  const handleFile = event => {
    const file = event.target.files?.[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => {
      setFileName(file.name);
      handleCsvChange(String(reader.result || ''));
    };
    reader.onerror = () => setErr(new Error('The selected file could not be read.'));
    reader.readAsText(file);
  };

  const download = () => {
    const anchor = document.createElement('a');
    anchor.href = `/modules/accounting/api/import.php?action=template&type=${type}`;
    anchor.download = `accounting-${type}-template.csv`;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
  };

  const requestBody = () => ({
    csv,
    ...(type === 'je' && defaultBatchRef.trim()
      ? { default_batch_ref: defaultBatchRef.trim() }
      : {}),
  });

  const runDry = async () => {
    setBusyAction('dry');
    setErr(null);
    setResult(null);
    try {
      setDry(await api.post(
        `/modules/accounting/api/import.php?action=dry_run&type=${type}`,
        requestBody()
      ));
    } catch (error) {
      setErr(error);
      setDry(null);
    } finally {
      setBusyAction(null);
    }
  };

  const commit = async () => {
    if (!canCommit) return;
    setBusyAction('commit');
    setErr(null);
    try {
      setResult(await api.post(
        `/modules/accounting/api/import.php?action=commit&type=${type}${skipInvalid ? '&skip_invalid=1' : ''}`,
        requestBody()
      ));
    } catch (error) {
      setErr(error);
    } finally {
      setBusyAction(null);
    }
  };

  const clear = () => {
    setCsv('');
    setFileName('');
    setDefaultBatchRef('');
    invalidateCheck();
    if (fileRef.current) fileRef.current.value = '';
  };

  return (
    <section data-testid="accounting-import">
      <header style={{ marginBottom: 'var(--cf-space-5)' }}>
        <h2 style={{ margin: '0 0 var(--cf-space-1)' }}>CSV ledger import</h2>
        <p style={{ margin: 0, color: 'var(--cf-text-secondary)', maxWidth: 820 }}>
          Upload a file or paste rows from Excel. CoreFlux checks every row before anything is posted.
        </p>
      </header>

      <div style={{
        background: 'var(--cf-surface)',
        border: '1px solid var(--cf-border)',
        borderTop: '3px solid var(--cf-accent)',
        borderRadius: 'var(--cf-radius-md, 6px)',
        overflow: 'hidden',
      }}>
        <div style={{
          display: 'grid',
          gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 280px), 1fr))',
          gap: 'var(--cf-space-5)',
          alignItems: 'end',
          padding: 'var(--cf-space-5)',
          background: 'color-mix(in srgb, var(--cf-accent-light) 32%, white)',
          borderBottom: '1px solid var(--cf-border)',
        }}>
          <label style={{ display: 'grid', gap: 6, fontWeight: 600 }}>
            Import type
            <select
              className="input"
              value={type}
              onChange={handleTypeChange}
              data-testid="accounting-import-type"
            >
              {Object.entries(IMPORT_TYPES).map(([key, option]) => (
                <option key={key} value={key}>{option.label}</option>
              ))}
            </select>
          </label>
          <div>
            <div style={{ fontWeight: 600, marginBottom: 4 }}>{config.label}</div>
            <div style={{ color: 'var(--cf-text-secondary)', fontSize: 13 }}>{config.description}</div>
          </div>
        </div>

        <div style={{ padding: 'var(--cf-space-5)' }}>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 'var(--cf-space-4)' }}>
            <button
              type="button"
              className="btn"
              onClick={download}
              data-testid="accounting-import-download-template"
            >
              <Download size={15} aria-hidden="true" /> Download template
            </button>
            <button
              type="button"
              className="btn"
              onClick={() => fileRef.current?.click()}
              data-testid="accounting-import-upload-trigger"
            >
              <Upload size={15} aria-hidden="true" /> Choose CSV or TSV
            </button>
            <input
              ref={fileRef}
              type="file"
              accept=".csv,.tsv,text/csv,text/tab-separated-values"
              onChange={handleFile}
              data-testid="accounting-import-file"
              style={{ display: 'none' }}
            />
            {csv && (
              <button type="button" className="btn btn--ghost" onClick={clear} data-testid="accounting-import-clear">
                <RotateCcw size={15} aria-hidden="true" /> Clear
              </button>
            )}
            {fileName && (
              <span style={{ alignSelf: 'center', color: 'var(--cf-text-secondary)', fontSize: 13 }}>
                {fileName}
              </span>
            )}
          </div>

          {type === 'je' && (
            <label style={{ display: 'grid', gap: 6, maxWidth: 460, marginBottom: 'var(--cf-space-4)' }}>
              <span style={{ fontWeight: 600 }}>Default batch reference</span>
              <input
                className="input"
                value={defaultBatchRef}
                onChange={event => { setDefaultBatchRef(event.target.value); invalidateCheck(); }}
                placeholder="Example: MAR-2025-INTEREST"
                maxLength={120}
                data-testid="accounting-import-default-batch-ref"
              />
              <span style={{ color: 'var(--cf-text-secondary)', fontSize: 12 }}>
                Optional when your file already has Batch ref. Otherwise this groups the pasted lines into one journal entry.
              </span>
            </label>
          )}

          <label style={{ display: 'grid', gap: 6 }}>
            <span style={{ display: 'flex', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
              <strong>Source data</strong>
              {csv && (
                <span data-testid="accounting-import-detected-format" style={{ color: 'var(--cf-accent-dark)', fontSize: 12 }}>
                  {delimiterName(sourcePreview.delimiter)} detected
                </span>
              )}
            </span>
            <textarea
              className="input"
              data-testid="accounting-import-csv"
              placeholder="Paste CSV rows or copy cells directly from Excel"
              value={csv}
              onChange={event => handleCsvChange(event.target.value)}
              spellCheck={false}
              style={{
                width: '100%',
                minHeight: 150,
                resize: 'vertical',
                fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
                fontSize: 12,
                lineHeight: 1.55,
                tabSize: 4,
                whiteSpace: 'pre',
                overflow: 'auto',
              }}
            />
          </label>

          {csv && missingRequired.length > 0 && (
            <div
              data-testid="accounting-import-missing-columns"
              style={{
                display: 'flex',
                gap: 8,
                alignItems: 'flex-start',
                marginTop: 10,
                padding: '10px 12px',
                borderLeft: '3px solid #d97706',
                background: '#fffbeb',
                color: '#92400e',
                fontSize: 13,
              }}
            >
              <AlertCircle size={16} aria-hidden="true" style={{ flex: '0 0 auto', marginTop: 1 }} />
              <span>
                Missing required {missingRequired.length === 1 ? 'column' : 'columns'}: <strong>{missingRequired.map(field => field.label).join(', ')}</strong>.
                {type === 'je' && missingRequired.some(field => field.defaultable) && ' Enter a default batch reference above or add the column.'}
              </span>
            </div>
          )}
        </div>

        {sourcePreview.headers.length > 0 && (
          <div style={{ borderTop: '1px solid var(--cf-border)' }} data-testid="accounting-import-source-preview">
            <div style={{
              display: 'flex',
              alignItems: 'center',
              gap: 8,
              padding: '10px var(--cf-space-5)',
              color: 'var(--cf-primary)',
              fontWeight: 600,
              fontSize: 13,
              background: 'var(--cf-accent-light)',
            }}>
              <FileSpreadsheet size={16} aria-hidden="true" /> Aligned preview
              <span style={{ color: 'var(--cf-text-secondary)', fontWeight: 400 }}>
                First {sourcePreview.rows.length} data {sourcePreview.rows.length === 1 ? 'row' : 'rows'}
              </span>
            </div>
            <div style={{ overflowX: 'auto', maxWidth: '100%' }}>
              <table className="data-table" data-testid="accounting-import-preview-table" style={{ minWidth: '100%', width: 'max-content' }}>
                <thead>
                  <tr>
                    <th style={{ width: 44 }}>#</th>
                    {sourcePreview.headers.map((header, index) => (
                      <th key={`${header}-${index}`} style={{ minWidth: 140, whiteSpace: 'nowrap' }}>
                        {header || `Column ${index + 1}`}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {sourcePreview.rows.map((row, rowIndex) => (
                    <tr key={rowIndex}>
                      <td style={{ color: 'var(--cf-text-muted)' }}>{rowIndex + 2}</td>
                      {sourcePreview.headers.map((header, columnIndex) => {
                        const numeric = ['debit', 'credit', 'amount'].includes(normalizeHeader(header));
                        return (
                          <td key={columnIndex} style={{ minWidth: 140, textAlign: numeric ? 'right' : 'left', whiteSpace: 'nowrap' }}>
                            {row[columnIndex] || ''}
                          </td>
                        );
                      })}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}

        <div style={{
          display: 'flex',
          alignItems: 'center',
          gap: 10,
          flexWrap: 'wrap',
          padding: 'var(--cf-space-4) var(--cf-space-5)',
          borderTop: '1px solid var(--cf-border)',
          background: '#f8fafc',
        }}>
          <button
            type="button"
            className="btn btn--primary"
            onClick={runDry}
            disabled={!csv.trim() || Boolean(busyAction)}
            data-testid="accounting-import-dry-run"
          >
            {busyAction === 'dry' ? 'Checking...' : 'Check data'}
          </button>
          <button
            type="button"
            className="btn"
            onClick={commit}
            disabled={!canCommit}
            data-testid="accounting-import-commit"
          >
            {busyAction === 'commit' ? 'Importing...' : 'Commit import'}
          </button>
          {dry?.error_count > 0 && validRows > 0 && (
            <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13 }}>
              <input
                type="checkbox"
                checked={skipInvalid}
                onChange={event => setSkip(event.target.checked)}
                data-testid="accounting-import-skip-invalid"
              />
              Import {validRows} valid {validRows === 1 ? 'row' : 'rows'} and skip the rest
            </label>
          )}
          {!dry && (
            <span style={{ display: 'flex', alignItems: 'center', gap: 6, color: 'var(--cf-text-secondary)', fontSize: 12 }}>
              <Info size={14} aria-hidden="true" /> Check data before committing.
            </span>
          )}
        </div>
      </div>

      {err && (
        <div className="error" data-testid="accounting-import-error" style={{ marginTop: 12 }}>
          {err.message || String(err)}
        </div>
      )}

      {dry && (
        <div
          data-testid="accounting-import-dry-result"
          style={{
            marginTop: 12,
            border: `1px solid ${dry.error_count ? '#f3c98b' : '#a7e2cf'}`,
            borderLeft: `3px solid ${dry.error_count ? '#d97706' : '#0f9f78'}`,
            padding: 14,
            background: dry.error_count ? '#fffbeb' : '#ecfdf5',
            borderRadius: 'var(--cf-radius-sm, 4px)',
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, fontWeight: 700 }}>
            {dry.error_count
              ? <AlertCircle size={17} aria-hidden="true" />
              : <CheckCircle2 size={17} aria-hidden="true" />}
            {dry.error_count
              ? `${dry.error_count} ${dry.error_count === 1 ? 'row needs' : 'rows need'} attention`
              : `${dry.row_count} ${dry.row_count === 1 ? 'row is' : 'rows are'} ready to import`}
          </div>
          {dry.errors && Object.keys(dry.errors).length > 0 && (
            <ul style={{ fontSize: 13, margin: '10px 0 0', paddingLeft: 22 }}>
              {Object.entries(dry.errors).slice(0, 20).map(([rowNumber, messages]) => (
                <li key={rowNumber} style={{ marginBottom: 4 }}>
                  <strong>Row {rowNumber}:</strong> {(messages || []).map(formatImportError).join('; ')}
                </li>
              ))}
            </ul>
          )}
        </div>
      )}

      {result && (
        <div
          data-testid="accounting-import-commit-result"
          style={{ marginTop: 12, background: '#ecfdf5', border: '1px solid #a7e2cf', borderLeft: '3px solid #0f9f78', padding: 14 }}
        >
          <strong>Import complete:</strong> {result.imported_count} imported, {result.skipped_count} skipped.
          {result.errors && Object.keys(result.errors).length > 0 && (
            <ul style={{ fontSize: 13, margin: '8px 0 0', paddingLeft: 22 }}>
              {Object.entries(result.errors).slice(0, 20).map(([key, messages]) => (
                <li key={key}><strong>{key}:</strong> {(messages || []).map(formatImportError).join('; ')}</li>
              ))}
            </ul>
          )}
          {result.message && <div style={{ fontSize: 13, marginTop: 6 }}>{result.message}</div>}
        </div>
      )}
    </section>
  );
}

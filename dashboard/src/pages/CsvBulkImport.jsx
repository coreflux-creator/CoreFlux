import React, { useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { Download } from 'lucide-react';
import { api } from '../lib/api';
import { reserveCsvImportAudit, archiveCsvImportAudit, finalizeCsvImportAudit } from '../lib/csvImportAudit';

/**
 * Tenant Onboarding — Bulk CSV Import Wizard.
 *
 * Drag-and-drop multiple CSVs at once. The wizard auto-detects each
 * file's entity from its header signature, dry-runs them all, and on
 * confirmation commits them in FK-respecting order:
 *
 *   1. people            → no FKs (root)
 *   2. ap_vendors        → no FKs (root)
 *   3. staffing_clients  → no FKs (root)
 *   4. accounting_accounts → parent accounts before dependent items
 *   5. billing_items     → optional revenue account code
 *   6. payroll_profiles  → people, existing pay schedules/cycles
 *   7. placements        → people, end_client_name (string)
 *   8. time              → placements (via CoreFlux Placement ID)
 *   9. ap_bills          → vendor_name (string)
 *  10. billing_invoices  → client_name (string)
 *
 * Each per-entity endpoint already implements idempotent semantics (skip
 * existing rows or upsert), so reruns are safe.
 */

const ENTITY_ORDER = [
  'people', 'ap_vendors', 'staffing_clients',
  'accounting_accounts', 'billing_items', 'payroll_profiles',
  'placements', 'time', 'ap_bills', 'billing_invoices',
  'ap_payments', 'billing_payments',
];
const FINANCE_ENTITY_ORDER = [
  'ap_vendors', 'accounting_accounts', 'billing_items',
  'ap_bills', 'billing_invoices', 'ap_payments', 'billing_payments',
];

const ENTITY_CONFIG = {
  people: {
    label: 'People',
    endpoint: '/modules/people/api/csv_import.php',
    exportHref: '/modules/people/api/csv_export.php',
    signature: ['First name','Last name','Primary email','Classification'],
    supportsUpdate: true,
  },
  ap_vendors: {
    label: 'Vendors',
    endpoint: '/modules/ap/api/csv_import.php',
    exportHref: '/modules/ap/api/csv_export.php',
    signature: ['Vendor ID','Vendor name','Vendor type'],
    supportsUpdate: true,
  },
  staffing_clients: {
    label: 'Clients',
    endpoint: '/modules/staffing/api/csv_import.php',
    exportHref: '/modules/staffing/api/csv_export.php',
    signature: ['Client name','Primary contact email'],
    supportsUpdate: true,
  },
  accounting_accounts: {
    label: 'Chart of accounts',
    endpoint: '/modules/accounting/api/accounts_csv_import.php',
    exportHref: '/modules/accounting/api/export.php?type=coa',
    signature: ['Account ID','Code','Name','Account type'],
    supportsUpdate: true,
  },
  billing_items: {
    label: 'Products & services',
    endpoint: '/modules/billing/api/items_csv_import.php',
    exportHref: '/modules/billing/api/items_csv_export.php',
    signature: ['Item ID','Code','Name','Item type'],
    supportsUpdate: true,
  },
  payroll_profiles: {
    label: 'Payroll employee profiles',
    endpoint: '/modules/payroll/api/profiles_csv_import.php',
    exportHref: '/modules/payroll/api/profiles_csv_export.php',
    signature: ['Employee ID','Employee number','Work email','Pay schedule name'],
    supportsUpdate: true,
  },
  placements: {
    label: 'Placements',
    endpoint: '/modules/placements/api/csv_import.php',
    exportHref: '/modules/placements/api/csv_export.php',
    signature: ['Person email','Title','Engagement type','Start date'],
    supportsUpdate: true,
  },
  time: {
    label: 'Time entries',
    endpoint: '/modules/time/api/csv_import.php',
    exportHref: '/modules/time/api/csv_export.php',
    signature: ['Placement ID','Placement external ID','Work date','Hours'],
    supportsUpdate: true,
  },
  ap_bills: {
    label: 'AP Bills',
    endpoint: '/modules/ap/api/bills_csv_import.php',
    exportHref: '/modules/ap/api/bills_csv_export.php',
    signature: ['Bill #','Vendor name','Bill date','Line description'],
    supportsUpdate: true,
  },
  billing_invoices: {
    label: 'AR Invoices',
    endpoint: '/modules/billing/api/csv_import.php',
    exportHref: '/modules/billing/api/csv_export.php',
    signature: ['Invoice #','Client name','Issue date','Line description'],
    supportsUpdate: true,
  },
  ap_payments: {
    label: 'Vendor payments',
    endpoint: '/modules/ap/api/payments_csv_import.php',
    exportHref: '/modules/ap/api/payments_csv_export.php',
    signature: ['Vendor name','Pay date','Method','Amount'],
    supportsUpdate: true,
  },
  billing_payments: {
    label: 'Customer payments',
    endpoint: '/modules/billing/api/payments_csv_import.php',
    exportHref: '/modules/billing/api/payments_csv_export.php',
    signature: ['Client name','Received at','Method','Amount'],
    supportsUpdate: true,
  },
};

function parseCsvRow(rowText) {
  const values = [];
  let value = '';
  let quoted = false;

  for (let i = 0; i < rowText.length; i += 1) {
    const char = rowText[i];
    if (char === '"') {
      if (quoted && rowText[i + 1] === '"') {
        value += '"';
        i += 1;
      } else {
        quoted = !quoted;
      }
    } else if (char === ',' && !quoted) {
      values.push(value.trim());
      value = '';
    } else {
      value += char;
    }
  }
  values.push(value.trim());
  return values;
}

function firstCsvRecord(csv) {
  let quoted = false;
  for (let i = 0; i < csv.length; i += 1) {
    if (csv[i] === '"') {
      if (quoted && csv[i + 1] === '"') i += 1;
      else quoted = !quoted;
    } else if ((csv[i] === '\n' || csv[i] === '\r') && !quoted) {
      return csv.slice(0, i);
    }
  }
  return csv;
}

function detectEntity(headers, candidates = ENTITY_ORDER) {
  const normalized = new Set(headers.map(value => String(value || '').trim().toLowerCase()));
  let best = null;
  let bestScore = 0;
  for (const key of candidates) {
    const cfg = ENTITY_CONFIG[key];
    const score = cfg.signature.filter(s => normalized.has(s.toLowerCase())).length;
    if (score > bestScore) { best = key; bestScore = score; }
  }
  // Require at least 2 signature columns to be confident
  return bestScore >= 2 ? best : null;
}

function readFile(f) {
  return new Promise((resolve, reject) => {
    const r = new FileReader();
    r.onload  = () => resolve(String(r.result || ''));
    r.onerror = () => reject(r.error);
    r.readAsText(f);
  });
}

export default function CsvBulkImport({ financeOnly = false }) {
  const visibleEntityOrder = financeOnly ? FINANCE_ENTITY_ORDER : ENTITY_ORDER;
  const fileRef = useRef(null);
  // files: { fileName, sourceFile, entity, csv, preview, committed, error, audit, columnMap, presetName }
  const [files, setFiles] = useState([]);
  const [skipInvalid, setSkipInvalid] = useState(false);
  const [updateExisting, setUpdateExisting] = useState(false);
  const [exportEntity, setExportEntity] = useState(financeOnly ? 'accounting_accounts' : 'placements');
  const [busy, setBusy] = useState(false);

  // Compute the same header signature the backend uses (sha256 of
  // comma-joined, lowercased, sorted headers). Lets us auto-apply saved
  // mapping presets without hitting the AI on rerun.
  const signatureFor = async (headers) => {
    const norm = headers.map(h => String(h || '').trim().toLowerCase()).sort();
    const buf  = new TextEncoder().encode(norm.join(','));
    const hash = await crypto.subtle.digest('SHA-256', buf);
    return Array.from(new Uint8Array(hash)).map(b => b.toString(16).padStart(2, '0')).join('');
  };

  const tryApplyPreset = async (entity, headers) => {
    try {
      const sig = await signatureFor(headers);
      const res = await api.get(`/api/admin/csv_mapping_presets.php?entity=${entity}&signature=${sig}`);
      const match = (res?.rows || [])[0];
      if (match) {
        await api.post(`/api/admin/csv_mapping_presets.php?action=use&id=${match.id}`).catch(() => null);
        return { columnMap: match.column_map || {}, presetName: match.name };
      }
    } catch { /* presets are a nicety; never block the flow */ }
    return { columnMap: null, presetName: null };
  };

  const onPick = async (e) => {
    const picked = Array.from(e.target.files || []);
    if (!picked.length) return;
    const next = [];
    for (const f of picked) {
      try {
        const csv = await readFile(f);
        const headers = parseCsvRow(firstCsvRecord(csv).replace(/^\uFEFF/, ''));
        const entity = detectEntity(headers, visibleEntityOrder);
        let columnMap = null, presetName = null;
        if (entity) {
          const applied = await tryApplyPreset(entity, headers);
          columnMap = applied.columnMap;
          presetName = applied.presetName;
        }
        next.push({ fileName: f.name, sourceFile: f, entity, csv, preview: null, committed: null, error: null, audit: null, columnMap, presetName });
      } catch (err) {
        next.push({ fileName: f.name, entity: null, csv: '', preview: null, committed: null, error: err, audit: null, columnMap: null, presetName: null });
      }
    }
    setFiles(prev => [...prev, ...next]);
    if (fileRef.current) fileRef.current.value = '';
  };

  const setEntity = (idx, entity) => {
    setFiles(prev => prev.map((f, i) => i === idx ? { ...f, entity, preview: null, committed: null, audit: null, columnMap: null, presetName: null } : f));
  };

  const removeFile = (idx) => {
    setFiles(prev => prev.filter((_, i) => i !== idx));
  };

  const dryRunAll = async () => {
    setBusy(true);
    try {
      const next = [...files];
      for (let i = 0; i < next.length; i++) {
        const f = next[i];
        if (!f.entity || !f.csv) continue;
        const cfg = ENTITY_CONFIG[f.entity];
        try {
          const body = { csv: f.csv };
          if (f.columnMap) body.column_map = f.columnMap;
          const shouldUpdate = updateExisting && cfg.supportsUpdate;
          const res = await api.post(`${cfg.endpoint}?action=dry_run${shouldUpdate ? '&update_existing=1' : ''}`, body);
          next[i] = { ...f, preview: res, error: null };
        } catch (err) {
          next[i] = { ...f, preview: null, error: err };
        }
      }
      setFiles(next);
    } finally { setBusy(false); }
  };

  const commitAll = async () => {
    setBusy(true);
    try {
      // Commit in FK-respecting order.
      const ordered = visibleEntityOrder
        .flatMap(entity => files
          .map((f, idx) => ({ f, idx }))
          .filter(({ f }) => f.entity === entity && f.csv && !f.committed && (!f.audit?.id || f.audit.state === 'source_missing'))
        );
      const next = [...files];
      for (const { f, idx } of ordered) {
        const cfg = ENTITY_CONFIG[f.entity];
        const shouldUpdate = updateExisting && cfg.supportsUpdate;
        const auditInput = {
          csv: f.csv, sourceFile: f.sourceFile, fileName: f.fileName, entity: f.entity,
          columnMap: JSON.stringify(f.columnMap), skipInvalid, updateExisting: shouldUpdate,
        };
        if (f.audit?.state === 'source_missing' && Object.keys(auditInput).some(key => auditInput[key] !== f.audit.input?.[key])) {
          next[idx] = { ...f, error: new Error('The file or import options changed. Select the file again to start a new import run.') };
          setFiles([...next]);
          continue;
        }
        const path = `${cfg.endpoint}?action=commit${skipInvalid ? '&skip_invalid=1' : ''}${shouldUpdate ? '&update_existing=1' : ''}`;
        const startedAt = Date.now();
        let runId = f.audit?.state === 'source_missing' ? f.audit.id : null;
        let sourceArchived = false;
        try {
          if (!runId) {
            runId = await reserveCsvImportAudit({
              entity: f.entity, fileName: f.fileName, csvText: f.csv, sourceFile: f.sourceFile, preview: f.preview,
              skipInvalid, updateExisting: shouldUpdate, columnMap: f.columnMap,
            });
          }
          next[idx] = { ...f, audit: { id: runId, state: 'unconfirmed', input: auditInput } };
          setFiles([...next]);
          if (financeOnly) {
            await archiveCsvImportAudit({
              runId, csvText: f.csv, sourceFile: f.sourceFile, fileName: f.fileName,
              entity: f.entity, columnMap: f.columnMap,
            });
          }
          sourceArchived = true;
          const body = { csv: f.csv };
          if (f.columnMap) body.column_map = f.columnMap;
          if (financeOnly) body.import_run_id = runId;
          const res = await api.post(path, body);
          next[idx] = { ...next[idx], committed: res, error: null };
          try {
            const receipt = await finalizeCsvImportAudit({
              runId, result: res, startedAt,
              postCommitSource: financeOnly ? null : {
                csvText: f.csv, sourceFile: f.sourceFile, fileName: f.fileName,
                entity: f.entity, columnMap: f.columnMap,
              },
            });
            next[idx] = { ...next[idx], audit: { id: receipt.id, state: receipt.sourceAttached ? 'logged' : 'source_missing_after_commit', input: auditInput } };
          } catch (auditError) {
            next[idx] = { ...next[idx], audit: { id: runId, state: 'unconfirmed', input: auditInput, message: auditError.message } };
          }
        } catch (err) {
          next[idx] = {
            ...next[idx], committed: null,
            audit: runId ? { id: runId, state: sourceArchived ? 'unconfirmed' : 'source_missing', input: auditInput, message: err.message } : next[idx].audit,
            error: runId && !sourceArchived ? null : runId
              ? new Error(`Import outcome unconfirmed. Check Import History and source records before retrying. ${err.message}`)
              : err,
          };
        }
        setFiles([...next]); // surface progress as each file completes
      }
    } finally { setBusy(false); }
  };

  const retryAudit = async (idx) => {
    const f = files[idx];
    if (!f?.committed || !f.audit?.id || busy) return;
    setBusy(true);
    try {
      const receipt = await finalizeCsvImportAudit({
        runId: f.audit.id, result: f.committed, startedAt: Date.now(),
        postCommitSource: financeOnly ? null : {
          csvText: f.csv, sourceFile: f.sourceFile, fileName: f.fileName,
          entity: f.entity, columnMap: f.columnMap,
        },
      });
      setFiles(prev => prev.map((row, i) => i === idx
        ? { ...row, audit: { id: receipt.id, state: receipt.sourceAttached ? 'logged' : 'source_missing_after_commit', input: f.audit.input } }
        : row));
    } catch (err) {
      setFiles(prev => prev.map((row, i) => i === idx
        ? { ...row, audit: { id: f.audit.id, state: 'unconfirmed', input: f.audit.input, message: err.message } }
        : row));
    } finally { setBusy(false); }
  };

  const reset = () => {
    setFiles([]);
    setSkipInvalid(false);
    setUpdateExisting(false);
    if (fileRef.current) fileRef.current.value = '';
  };

  const hasAny       = files.length > 0;
  const allDetected  = files.every(f => f.entity && visibleEntityOrder.includes(f.entity));
  const validationFinished = hasAny && files.every(f => f.preview || f.error);
  const validationSucceeded = validationFinished && files.every(f => f.preview && !f.error);
  const totalRows    = files.reduce((sum, f) => sum + (f.preview?.row_count || 0), 0);
  const totalErrors  = files.reduce((sum, f) => sum + (f.preview?.error_count || 0), 0);
  const canCommit = validationSucceeded && (skipInvalid || totalErrors === 0);
  const hasUpdatableEntity = files.some(f => f.entity && ENTITY_CONFIG[f.entity]?.supportsUpdate);

  return (
    <section className="people-directory" data-testid="csv-bulk-import">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 'var(--cf-space-4)', flexWrap: 'wrap', gap: 'var(--cf-space-3)' }}>
        <div>
          <h2>Bulk CSV Import</h2>
          <p style={{ color: 'var(--cf-text-secondary)' }}>
            Drag in multiple CSVs at once. We&apos;ll auto-detect each file&apos;s entity,
            preview it, then import it in dependency-safe order. Exported master-data files can
            also be completed offline and re-uploaded with update mode enabled.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 'var(--cf-space-2)', flexWrap: 'wrap' }}>
          <Link to={financeOnly ? '/modules/accounting/import-history' : '/data/import-history'} className="btn btn--ghost" data-testid="csv-bulk-history-link">Import history</Link>
          <Link to={financeOnly ? '/modules/accounting/overview' : '/'} className="btn btn--ghost" data-testid="csv-bulk-back">← Overview</Link>
        </div>
      </header>

      <div style={{ background: 'var(--cf-surface)', padding: 'var(--cf-space-6)', borderRadius: 'var(--cf-radius-lg)', border: '1px solid var(--cf-border)' }}>
        <div className="csv-roundtrip-toolbar" data-testid="csv-bulk-current-data-export">
          <strong>Export current data</strong>
          <select
            value={exportEntity}
            onChange={e => setExportEntity(e.target.value)}
            aria-label="Data to export"
            data-testid="csv-bulk-export-entity"
          >
            {visibleEntityOrder.map(k => <option key={k} value={k}>{ENTITY_CONFIG[k].label}</option>)}
          </select>
          <a
            className="btn btn--primary"
            href={ENTITY_CONFIG[exportEntity].exportHref}
            data-testid="csv-bulk-export-current"
          >
            <Download size={15} aria-hidden="true" />
            Export CSV
          </a>
        </div>

        {/* Sample CSV pack — onboarding-friendly. Each link downloads a
            template + 5 realistic example rows so a new tenant can load a
            full working dataset before importing their real books. */}
        <details style={{ marginBottom: 'var(--cf-space-4)' }} data-testid="csv-bulk-sample-pack">
          <summary style={{ cursor: 'pointer', color: 'var(--cf-text-secondary)', fontSize: 13 }}>
            {financeOnly ? 'Sample accounting CSVs' : 'New to CoreFlux? Download our sample CSV pack'}
          </summary>
          <div style={{ marginTop: 8, display: 'flex', flexWrap: 'wrap', gap: 8 }}>
            {visibleEntityOrder.map(k => (
              <a
                key={k}
                className="btn btn--ghost"
                href={`${ENTITY_CONFIG[k].endpoint}?action=sample`}
                data-testid={`csv-bulk-sample-${k}`}
                style={{ fontSize: 12 }}
              >
                {ENTITY_CONFIG[k].label} sample
              </a>
            ))}
          </div>
          <p style={{ marginTop: 8, fontSize: 12, color: 'var(--cf-text-secondary)' }}>
            {financeOnly
              ? 'Check each file before import. Financial documents retain their approval and posting controls.'
              : 'All samples are FK-coherent — emails, vendor names and placement IDs line up across files so you can load them in order.'}
          </p>
        </details>

        <div style={{ display: 'flex', gap: 'var(--cf-space-3)', flexWrap: 'wrap', marginBottom: 'var(--cf-space-4)', alignItems: 'center' }}>
          <input
            ref={fileRef}
            type="file"
            accept=".csv,text/csv"
            multiple
            onChange={onPick}
            data-testid="csv-bulk-file-input"
          />
          {hasAny && (
            <>
              <button className="btn btn--primary" onClick={dryRunAll} disabled={busy || !allDetected} data-testid="csv-bulk-validate">
                {busy ? 'Checking…' : `Check all (${files.length})`}
              </button>
              <button className="btn" onClick={reset} disabled={busy} data-testid="csv-bulk-reset">Reset</button>
              <label style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                <input
                  type="checkbox"
                  checked={skipInvalid}
                  onChange={e => setSkipInvalid(e.target.checked)}
                  data-testid="csv-bulk-skip-invalid"
                />
                Import valid rows even when others have errors
              </label>
              {hasUpdatableEntity && (
                <label style={{ display: 'flex', alignItems: 'center', gap: 6 }} title="Match records by their stable ID or documented unique key. Posted, paid, approved, or otherwise locked records remain read-only.">
                  <input
                    type="checkbox"
                    checked={updateExisting}
                    onChange={e => {
                      setUpdateExisting(e.target.checked);
                      setFiles(prev => prev.map(f => ({ ...f, preview: null })));
                    }}
                    data-testid="csv-bulk-update-existing"
                  />
                  Update matching editable records
                </label>
              )}
              {validationFinished && (
                <button className="btn btn--primary" onClick={commitAll} disabled={busy || !canCommit} data-testid="csv-bulk-commit">
                  Import all ({totalRows - totalErrors} ready / {totalRows} total)
                </button>
              )}
            </>
          )}
        </div>

        {!hasAny && (
          <p className="empty" data-testid="csv-bulk-empty">
            {financeOnly
              ? 'No files selected yet. Choose one or more accounting CSV files.'
              : 'No files selected yet. Pick multiple CSV files above — one per entity (people.csv, vendors.csv, clients.csv, etc.).'}
          </p>
        )}

        {validationFinished && !canCommit && (
          <div className="alert alert--warning" data-testid="csv-bulk-commit-blocked" style={{ marginBottom: 'var(--cf-space-4)' }}>
            {files.some(f => f.error)
              ? 'One or more files could not be validated. Fix or remove those files before importing.'
              : 'Fix the rows with errors, or explicitly choose to import valid rows only.'}
          </div>
        )}

        {hasAny && (
          <table className="data-table" data-testid="csv-bulk-table">
            <thead>
              <tr>
                <th>File</th>
                <th>Entity</th>
                <th>Rows</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {files.map((f, idx) => (
                <tr key={idx} data-testid={`csv-bulk-row-${idx}`}>
                  <td data-testid={`csv-bulk-row-${idx}-filename`}>
                    {f.fileName}
                    {f.presetName && (
                      <span data-testid={`csv-bulk-row-${idx}-preset`} style={{ marginLeft: 6, fontSize: 11, padding: '1px 6px', borderRadius: 3, background: 'rgba(34,197,94,0.15)', color: '#047857' }}>
                        preset: {f.presetName}
                      </span>
                    )}
                  </td>
                  <td>
                    <select
                      value={f.entity || ''}
                      onChange={e => setEntity(idx, e.target.value || null)}
                      data-testid={`csv-bulk-row-${idx}-entity`}
                      style={{ padding: '4px 8px', borderRadius: 4, border: '1px solid var(--cf-border, #e5e7eb)' }}
                    >
                      <option value="">— pick entity —</option>
                      {visibleEntityOrder.map(k => <option key={k} value={k}>{ENTITY_CONFIG[k].label}</option>)}
                    </select>
                  </td>
                  <td>
                    {f.committed
                      ? <span style={{ color: 'var(--cf-green, #047857)' }}>{f.committed.imported_count} imported</span>
                      : f.preview
                          ? `${f.preview.row_count} (${f.preview.error_count} errors)`
                          : '—'}
                  </td>
                  <td>
                    {f.error && <span style={{ color: '#c0392b' }}>Error: {f.error.message}</span>}
                    {!f.error && f.committed && <span style={{ color: 'var(--cf-green, #047857)' }}>Imported</span>}
                    {f.audit?.state === 'logged' && <span data-testid={`csv-bulk-row-${idx}-audit-logged`}> · History #{f.audit.id}</span>}
                    {f.audit?.state === 'source_missing' && <span className="error" data-testid={`csv-bulk-row-${idx}-source-missing`}> · No records imported: source CSV not archived. Retry Import all. {f.audit.message}</span>}
                    {f.audit?.state === 'source_missing_after_commit' && <span className="error" data-testid={`csv-bulk-row-${idx}-source-missing`}> · Source CSV not archived; keep the original file.</span>}
                    {f.audit?.state === 'source_missing_after_commit' && f.committed && <button className="btn" disabled={busy} onClick={() => retryAudit(idx)} data-testid={`csv-bulk-row-${idx}-source-retry`}>Retry source archive</button>}
                    {f.audit?.state === 'unconfirmed' && <span className="error" data-testid={`csv-bulk-row-${idx}-audit-unconfirmed`}> · Run #{f.audit.id} needs review. Do not import again. {f.audit.message}</span>}
                    {f.audit?.state === 'unconfirmed' && f.committed && <button className="btn" disabled={busy} onClick={() => retryAudit(idx)} data-testid={`csv-bulk-row-${idx}-audit-retry`}>Retry history only</button>}
                    {!f.error && !f.audit?.id && !f.committed && f.preview && (
                      f.preview.error_count > 0
                        ? <span style={{ color: '#a16207' }}>⚠ {f.preview.error_count} rows have errors</span>
                        : <span style={{ color: 'var(--cf-green, #047857)' }}>Ready to import</span>
                    )}
                    {!f.error && !f.committed && !f.preview && <span style={{ color: 'var(--cf-text-secondary)' }}>Pending</span>}
                  </td>
                  <td>
                    <button className="btn btn--ghost" onClick={() => removeFile(idx)} disabled={busy} data-testid={`csv-bulk-row-${idx}-remove`}>Remove</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}

        {hasAny && validationFinished && files.some(f => f.committed) && (
          <div data-testid="csv-bulk-summary" style={{ marginTop: 'var(--cf-space-4)' }}>
            <h3>Import summary</h3>
            <ul>
              {files.filter(f => f.committed).map((f, i) => (
                <li key={i}>
                  <strong>{ENTITY_CONFIG[f.entity].label}</strong> ({f.fileName}):{' '}
                  {f.committed.imported_count} imported, {f.committed.skipped_count} skipped
                </li>
              ))}
            </ul>
            <p style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>
              History status is shown beside each file. Resolve any unconfirmed run before importing that file again.
            </p>
            <Link to={financeOnly ? '/modules/accounting/import-history' : '/data/import-history'} className="btn btn--primary" data-testid="csv-bulk-summary-view-history">View import history</Link>
          </div>
        )}
      </div>
    </section>
  );
}

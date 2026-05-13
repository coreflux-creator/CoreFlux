import React, { useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../lib/api';

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
 *   4. placements        → people, end_client_name (string)
 *   5. time              → placements (via external_id)
 *   6. ap_bills          → vendor_name (string)
 *   7. billing_invoices  → client_name (string)
 *
 * Each per-entity endpoint already implements idempotent semantics (skip
 * existing rows or upsert), so reruns are safe.
 */

const ENTITY_ORDER = [
  'people', 'ap_vendors', 'staffing_clients',
  'placements', 'time', 'ap_bills', 'billing_invoices',
  'ap_payments', 'billing_payments',
];

const ENTITY_CONFIG = {
  people: {
    label: 'People',
    endpoint: '/modules/people/api/csv_import.php',
    signature: ['First name','Last name','Primary email','Classification'],
  },
  ap_vendors: {
    label: 'Vendors',
    endpoint: '/modules/ap/api/csv_import.php',
    signature: ['Vendor name','Vendor type'],
  },
  staffing_clients: {
    label: 'Clients',
    endpoint: '/modules/staffing/api/csv_import.php',
    signature: ['Client name','Primary contact email'],
  },
  placements: {
    label: 'Placements',
    endpoint: '/modules/placements/api/csv_import.php',
    signature: ['Person email','Title','Engagement type','Start date'],
  },
  time: {
    label: 'Time entries',
    endpoint: '/modules/time/api/csv_import.php',
    signature: ['Placement external ID','Work date','Category','Hours'],
  },
  ap_bills: {
    label: 'AP Bills',
    endpoint: '/modules/ap/api/bills_csv_import.php',
    signature: ['Bill #','Vendor name','Bill date','Line description'],
  },
  billing_invoices: {
    label: 'AR Invoices',
    endpoint: '/modules/billing/api/csv_import.php',
    signature: ['Invoice #','Client name','Issue date','Line description'],
  },
  ap_payments: {
    label: 'AP Payments',
    endpoint: '/modules/ap/api/payments_csv_import.php',
    signature: ['Vendor name','Pay date','Method','Amount'],
  },
  billing_payments: {
    label: 'Billing Payments',
    endpoint: '/modules/billing/api/payments_csv_import.php',
    signature: ['Client name','Received at','Method','Amount'],
  },
};function detectEntity(headerLine) {
  const hdr = (headerLine || '').toLowerCase();
  let best = null;
  let bestScore = 0;
  for (const [key, cfg] of Object.entries(ENTITY_CONFIG)) {
    const score = cfg.signature.filter(s => hdr.includes(s.toLowerCase())).length;
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

export default function CsvBulkImport() {
  const fileRef = useRef(null);
  // files: { fileName, entity, csv, preview, committed, error, columnMap, presetName }
  const [files, setFiles] = useState([]);
  const [skipInvalid, setSkipInvalid] = useState(true);
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
      const res = await api.get(`/api/admin/csv_mapping_presets?entity=${entity}&signature=${sig}`);
      const match = (res?.rows || [])[0];
      if (match) {
        await api.post(`/api/admin/csv_mapping_presets?action=use&id=${match.id}`).catch(() => null);
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
        const firstLine = csv.split(/\r?\n/, 1)[0] || '';
        const entity = detectEntity(firstLine);
        let columnMap = null, presetName = null;
        if (entity) {
          // Parse the header row out of the first line; cheap enough since
          // CSV first-row escaping is rarely complex.
          const headers = firstLine.split(',').map(h => h.replace(/^"|"$/g, '').trim());
          const applied = await tryApplyPreset(entity, headers);
          columnMap = applied.columnMap;
          presetName = applied.presetName;
        }
        next.push({ fileName: f.name, entity, csv, preview: null, committed: null, error: null, columnMap, presetName });
      } catch (err) {
        next.push({ fileName: f.name, entity: null, csv: '', preview: null, committed: null, error: err, columnMap: null, presetName: null });
      }
    }
    setFiles(prev => [...prev, ...next]);
    if (fileRef.current) fileRef.current.value = '';
  };

  const setEntity = (idx, entity) => {
    setFiles(prev => prev.map((f, i) => i === idx ? { ...f, entity, preview: null, committed: null, columnMap: null, presetName: null } : f));
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
          const res = await api.post(`${cfg.endpoint}?action=dry_run`, body);
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
      const ordered = ENTITY_ORDER
        .flatMap(entity => files
          .map((f, idx) => ({ f, idx }))
          .filter(({ f }) => f.entity === entity && f.csv && !f.committed)
        );
      const next = [...files];
      for (const { f, idx } of ordered) {
        const cfg = ENTITY_CONFIG[f.entity];
        const path = `${cfg.endpoint}?action=commit${skipInvalid ? '&skip_invalid=1' : ''}`;
        try {
          const body = { csv: f.csv };
          if (f.columnMap) body.column_map = f.columnMap;
          const res = await api.post(path, body);
          next[idx] = { ...f, committed: res, error: null };
        } catch (err) {
          next[idx] = { ...f, committed: null, error: err };
        }
        setFiles([...next]); // surface progress as each file completes
      }
    } finally { setBusy(false); }
  };

  const reset = () => { setFiles([]); if (fileRef.current) fileRef.current.value = ''; };

  const hasAny       = files.length > 0;
  const allDetected  = files.every(f => f.entity);
  const allPreviewed = hasAny && files.every(f => f.preview || f.error);
  const totalRows    = files.reduce((sum, f) => sum + (f.preview?.row_count || 0), 0);
  const totalErrors  = files.reduce((sum, f) => sum + (f.preview?.error_count || 0), 0);

  return (
    <section className="people-directory" data-testid="csv-bulk-import">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 'var(--cf-space-4)', flexWrap: 'wrap', gap: 'var(--cf-space-3)' }}>
        <div>
          <h2>Bulk CSV Import</h2>
          <p style={{ color: 'var(--cf-text-secondary)' }}>
            Drag in multiple CSVs at once. We&apos;ll auto-detect each file&apos;s entity,
            preview it, then import them in the correct order
            (People → Vendors → Clients → Placements → Time → Bills → Invoices).
          </p>
        </div>
        <Link to="/" className="btn btn--ghost" data-testid="csv-bulk-back">← Dashboard</Link>
      </header>

      <div style={{ background: 'var(--cf-surface)', padding: 'var(--cf-space-6)', borderRadius: 'var(--cf-radius-lg)', border: '1px solid var(--cf-border)' }}>
        {/* Sample CSV pack — onboarding-friendly. Each link downloads a
            template + 5 realistic example rows so a new tenant can load a
            full working dataset before importing their real books. */}
        <details style={{ marginBottom: 'var(--cf-space-4)' }} data-testid="csv-bulk-sample-pack">
          <summary style={{ cursor: 'pointer', color: 'var(--cf-text-secondary)', fontSize: 13 }}>
            New to CoreFlux? Download our sample CSV pack →
          </summary>
          <div style={{ marginTop: 8, display: 'flex', flexWrap: 'wrap', gap: 8 }}>
            {ENTITY_ORDER.map(k => (
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
            All samples are FK-coherent — emails, vendor names and placement IDs
            line up across files so you can load them in order and see the
            full platform populated in 30 seconds.
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
                {busy ? 'Working…' : `Validate all (${files.length})`}
              </button>
              <button className="btn" onClick={reset} disabled={busy} data-testid="csv-bulk-reset">Reset</button>
              <label style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                <input
                  type="checkbox"
                  checked={skipInvalid}
                  onChange={e => setSkipInvalid(e.target.checked)}
                  data-testid="csv-bulk-skip-invalid"
                />
                Skip invalid rows on commit
              </label>
              {allPreviewed && (
                <button className="btn btn--primary" onClick={commitAll} disabled={busy} data-testid="csv-bulk-commit">
                  Commit all ({totalRows - totalErrors} valid / {totalRows} total)
                </button>
              )}
            </>
          )}
        </div>

        {!hasAny && (
          <p className="empty" data-testid="csv-bulk-empty">
            No files selected yet. Pick multiple CSV files above — one per entity
            (people.csv, vendors.csv, clients.csv, etc.).
          </p>
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
                      {ENTITY_ORDER.map(k => <option key={k} value={k}>{ENTITY_CONFIG[k].label}</option>)}
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
                    {!f.error && f.committed && <span style={{ color: 'var(--cf-green, #047857)' }}>✓ Committed</span>}
                    {!f.error && !f.committed && f.preview && (
                      f.preview.error_count > 0
                        ? <span style={{ color: '#a16207' }}>⚠ {f.preview.error_count} rows have errors</span>
                        : <span style={{ color: 'var(--cf-green, #047857)' }}>✓ Validated</span>
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

        {hasAny && allPreviewed && files.some(f => f.committed) && (
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
          </div>
        )}
      </div>
    </section>
  );
}

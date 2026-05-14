import React, { useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../../dashboard/src/lib/api';
import { attachCsvToImportRun } from '../../../dashboard/src/lib/csvAuditAttach';

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
  /**
   * presetEntity: short identifier (e.g. 'people', 'ap_vendors') used to
   * look up saved mapping presets via /api/admin/csv_mapping_presets.
   * When set, the UI exposes "Apply preset" + "Save mapping" controls.
   */
  presetEntity = null,

  /**
   * extraToggles: per-entity boolean toggles that get rendered alongside
   * skip_invalid / update_existing and appended to the commit URL. Used
   * for module-specific options (e.g. Time's "already_approved"). Shape:
   *   [{ key: 'already_approved',
   *      label: 'Pre-approved (skip review queue)',
   *      commitParam: 'already_approved=1',
   *      default: false }, ...]
   * The toggle's current value is also forwarded to the history log
   * under `extra_flags` so audits show which options were checked.
   */
  extraToggles = [],
}) {
  const fileRef = useRef(null);
  const [csvText, setCsvText]         = useState('');
  const [fileName, setFileName]       = useState('');
  // Interactive column mapping state
  const [inspecting, setInspecting]   = useState(false);
  const [inspect, setInspectResult]   = useState(null);  // { headers, auto_map, fields }
  const [columnMap, setColumnMap]     = useState(null);  // { headerName: field_key | null }
  // Preview / commit state
  const [preview, setPreview]         = useState(null);
  const [running, setRunning]         = useState(false);
  const [error, setError]             = useState(null);
  const [committed, setCommitted]     = useState(null);
  const [skipInvalid, setSkipInvalid] = useState(false);
  const [updateExisting, setUpdateExisting] = useState(false);
  // Per-entity extra toggles (e.g. Time's "already_approved"). Stored as
  // a single object keyed by toggle.key so adding a new toggle is just
  // a prop change, no React state surgery.
  const [extraToggleValues, setExtraToggleValues] = useState(
    () => Object.fromEntries((extraToggles || []).map(t => [t.key, !!t.default]))
  );
  const setExtraToggle = (key, val) => setExtraToggleValues(prev => ({ ...prev, [key]: !!val }));

  const onFile = async (e) => {
    const f = e.target.files?.[0];
    if (!f) return;
    setFileName(f.name);
    const reader = new FileReader();
    reader.onload = async () => {
      const text = String(reader.result || '');
      setCsvText(text);
      setPreview(null); setCommitted(null); setError(null);
      // Auto-inspect on file pick so the mapping table appears immediately.
      setInspecting(true);
      try {
        const res = await api.post(`${endpoint}?action=inspect`, { csv: text });
        setInspectResult(res);
        // Seed columnMap from auto_map
        const seed = {};
        (res.headers || []).forEach((h, i) => { seed[h] = (res.auto_map || [])[i] ?? null; });
        setColumnMap(seed);
        // Surface saved presets that match this header set.
        loadPresetsForHeaders(res.headers || []);
      } catch (err) { setError(err); }
      finally       { setInspecting(false); }
    };
    reader.readAsText(f);
  };

  const setMapForHeader = (header, fieldKey) => {
    setColumnMap(prev => ({ ...prev, [header]: fieldKey || null }));
    // Mapping changed — invalidate any prior preview.
    setPreview(null);
  };

  // AI-assisted mapping: post the CSV + already-mapped pairs to the
  // module's ai_suggest_map endpoint; merge the suggestions into our
  // local columnMap. The user still sees the result and can override.
  const [aiRunning, setAiRunning]   = useState(false);
  const [aiReasoning, setAiReason]  = useState(null);
  const [aiError, setAiError]       = useState(null);

  // Saved mapping presets (per tenant + entity). After ?action=inspect we
  // hash the header set and look for a matching preset; if found, we
  // surface a one-click "Apply" button. After a successful commit we
  // expose "Save this mapping as…" so the next rerun is zero-AI.
  const [presets, setPresets]       = useState([]);
  const [presetMatch, setPresetMatch] = useState(null);   // exact-header-match preset
  const [presetName, setPresetName] = useState('');
  const [savingPreset, setSavingPreset] = useState(false);
  const [presetSaved, setPresetSaved]   = useState(null);

  // Quick header-signature hash that matches the backend's csvPresetSignature():
  // sha256(lowercased, sorted, comma-joined headers).
  const computeHeaderSignature = async (headers) => {
    const norm = headers.map(h => String(h || '').trim().toLowerCase()).sort();
    const buf  = new TextEncoder().encode(norm.join(','));
    const hash = await crypto.subtle.digest('SHA-256', buf);
    return Array.from(new Uint8Array(hash)).map(b => b.toString(16).padStart(2, '0')).join('');
  };

  const loadPresetsForHeaders = async (headers) => {
    if (!presetEntity) return;
    try {
      const sig = await computeHeaderSignature(headers);
      const all = await api.get(`/api/admin/csv_mapping_presets?entity=${presetEntity}`);
      const rows = all?.rows || [];
      setPresets(rows);
      const exact = rows.find(p => p.header_signature === sig);
      if (exact) setPresetMatch(exact);
    } catch {
      // Presets are a nicety — never break the import flow if the lookup
      // fails (e.g. migration not yet run on this tenant).
    }
  };

  const applyPreset = async (p) => {
    setColumnMap(prev => ({ ...(prev || {}), ...(p.column_map || {}) }));
    setPreview(null);
    try { await api.post(`/api/admin/csv_mapping_presets?action=use&id=${p.id}`); } catch { /* non-fatal */ }
  };

  const savePreset = async () => {
    if (!presetEntity || !inspect || !columnMap || !presetName.trim()) return;
    setSavingPreset(true);
    try {
      const res = await api.post('/api/admin/csv_mapping_presets', {
        entity: presetEntity,
        name: presetName.trim(),
        column_map: columnMap,
        source_headers: inspect.headers,
      });
      setPresetSaved(res);
      setPresetName('');
    } catch (err) { setError(err); }
    finally       { setSavingPreset(false); }
  };

  const aiSuggest = async () => {
    if (!csvText || !inspect) return;
    setAiRunning(true); setAiError(null); setAiReason(null);
    try {
      // Only forward already-mapped (non-null) pairs as locks.
      const already = Object.fromEntries(
        Object.entries(columnMap || {}).filter(([, v]) => v)
      );
      const res = await api.post(`${endpoint}?action=ai_suggest_map`, {
        csv: csvText,
        already_mapped: already,
      });
      const sugg = res.suggestions || {};
      setColumnMap(prev => ({ ...(prev || {}), ...sugg }));
      setAiReason(res.reasoning || 'AI suggestion applied. Review before validating.');
      setPreview(null);
    } catch (err) { setAiError(err); }
    finally       { setAiRunning(false); }
  };

  const dryRun = async () => {
    if (!csvText) return;
    setRunning(true); setError(null); setCommitted(null);
    try {
      const body = { csv: csvText };
      if (columnMap) body.column_map = columnMap;
      const res = await api.post(`${endpoint}?action=dry_run`, body);
      setPreview(res);
    } catch (e) { setError(e); }
    finally     { setRunning(false); }
  };

  const commit = async () => {
    if (!csvText) return;
    setRunning(true); setError(null);
    const startedAt = Date.now();
    try {
      const params = [];
      if (skipInvalid)    params.push('skip_invalid=1');
      if (updateExisting) params.push('update_existing=1');
      // Per-entity extras (e.g. ?already_approved=1 for Time).
      (extraToggles || []).forEach(t => {
        if (extraToggleValues[t.key] && t.commitParam) params.push(t.commitParam);
      });
      const path = `${endpoint}?action=commit${params.length ? '&' + params.join('&') : ''}`;
      const body = { csv: csvText };
      if (columnMap) body.column_map = columnMap;
      const res = await api.post(path, body);
      setCommitted(res);

      // Audit-write to CSV Import History. Never throw — the import has
      // already succeeded; a failed history write is a nicety to lose.
      if (presetEntity) {
        try {
          const hist = await api.post('/api/admin/csv_import_history.php', {
            entity:          presetEntity,
            file_name:       fileName || null,
            bytes_processed: csvText.length,
            rows_total:      (res?.imported_count || 0) + (res?.skipped_count || 0),
            rows_imported:   res?.imported_count || 0,
            rows_skipped:    res?.skipped_count  || 0,
            errors:          res?.errors        || {},
            skip_invalid:    skipInvalid,
            update_existing: updateExisting,
            ai_used:         !!aiReasoning,
            preset_id:       presetMatch?.id || null,
            column_map:      columnMap || null,
            duration_ms:     Date.now() - startedAt,
            // Per-entity extras (e.g. {already_approved: true}) — purely
            // for audit visibility; the engine already received them via
            // the URL.
            ...(Object.keys(extraToggleValues).length
                ? { extra_flags: extraToggleValues }
                : {}),
          });
          // Attach the original CSV bytes to the history row so auditors
          // can download the exact input that produced this batch.
          if (hist?.id) {
            await attachCsvToImportRun({
              importRunId: hist.id,
              csvText,
              fileName,
              entity: presetEntity,
              columnMap: columnMap || null,
            });
          }
        } catch { /* non-fatal */ }
      }
    } catch (e) { setError(e); }
    finally     { setRunning(false); }
  };

  const reset = () => {
    setCsvText(''); setFileName(''); setPreview(null); setCommitted(null); setError(null);
    setInspectResult(null); setColumnMap(null);
    if (fileRef.current) fileRef.current.value = '';
  };

  const rowsArr = preview ? Object.entries(preview.rows || {}) : [];
  const errorsByRow = preview?.errors || {};

  return (
    <section data-testid={testidPrefix}>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-4)', gap: 'var(--cf-space-2)', flexWrap: 'wrap' }}>
        <div>
          <h2>CSV Import — {entityLabel}</h2>
          <p style={{ color: 'var(--cf-text-secondary)' }}>
            Bulk-create {entityLabel.toLowerCase()}. Template-driven; dry-run before commit.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 'var(--cf-space-2)', flexWrap: 'wrap' }}>
          <Link to="/data/bulk-import"   className="btn btn--ghost" data-testid={`${testidPrefix}-bulk-link`}>+ Bulk Import (multi-file)</Link>
          <Link to="/data/import-history" className="btn btn--ghost" data-testid={`${testidPrefix}-history-link`}>Import History</Link>
          <Link to={backTo}              className="btn btn--ghost" data-testid={`${testidPrefix}-back`}>{backLabel}</Link>
        </div>
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

        {inspecting && (
          <p data-testid={`${testidPrefix}-inspecting`} style={{ color: 'var(--cf-text-secondary)' }}>
            Inspecting columns…
          </p>
        )}

        {/* Interactive column mapping table — appears after a file is
            picked and ?action=inspect returns. User can pick the target
            field for each source column, or leave it "— skip this column —".
            Required schema fields that have no mapped header are flagged. */}
        {inspect && !committed && (
          <div data-testid={`${testidPrefix}-mapping`} style={{ marginBottom: 'var(--cf-space-4)' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 12, flexWrap: 'wrap', marginBottom: 8 }}>
              <div>
                <h3 style={{ marginTop: 0, marginBottom: 4 }}>Map your columns</h3>
                <p style={{ color: 'var(--cf-text-secondary)', fontSize: 13, margin: 0 }}>
                  We auto-matched what we could. Adjust any mismatched columns or
                  set unmatched ones to <em>— skip this column —</em>.
                </p>
              </div>
              <button
                className="btn"
                onClick={aiSuggest}
                disabled={aiRunning}
                data-testid={`${testidPrefix}-ai-suggest`}
                style={{ background: 'linear-gradient(135deg, #7c3aed, #2563eb)', color: 'white', border: 0 }}
                title="Use AI to suggest mappings for any columns we couldn't match"
              >
                {aiRunning ? 'AI mapping…' : '✨ Auto-map with AI'}
              </button>
            </div>
            {aiError && <p className="error" data-testid={`${testidPrefix}-ai-error`}>AI: {aiError.message}</p>}
            {aiReasoning && (
              <p data-testid={`${testidPrefix}-ai-reasoning`} style={{ background: 'rgba(124,58,237,0.08)', padding: 8, borderRadius: 6, fontSize: 13, color: '#4c1d95' }}>
                <strong>AI:</strong> {aiReasoning}
              </p>
            )}
            {/* Saved-preset surfaces — exact-match-on-headers and ALL
                presets for this entity (so user can apply any). */}
            {presetEntity && presets.length > 0 && (
              <div data-testid={`${testidPrefix}-presets`} style={{ background: 'rgba(34,197,94,0.06)', padding: 8, borderRadius: 6, fontSize: 13, marginBottom: 8 }}>
                {presetMatch ? (
                  <p style={{ margin: 0 }} data-testid={`${testidPrefix}-preset-match`}>
                    <strong>Saved mapping match:</strong> &ldquo;{presetMatch.name}&rdquo;.{' '}
                    <button className="btn btn--ghost" onClick={() => applyPreset(presetMatch)} data-testid={`${testidPrefix}-preset-apply-match`} style={{ marginLeft: 6 }}>
                      Apply
                    </button>
                  </p>
                ) : (
                  <p style={{ margin: 0 }}>
                    <strong>Saved mappings for {entityLabel}:</strong>{' '}
                    {presets.slice(0, 5).map((p, i) => (
                      <button
                        key={p.id}
                        className="btn btn--ghost"
                        onClick={() => applyPreset(p)}
                        data-testid={`${testidPrefix}-preset-apply-${i}`}
                        style={{ marginLeft: i === 0 ? 6 : 4 }}
                      >
                        {p.name}
                      </button>
                    ))}
                  </p>
                )}
              </div>
            )}
            <table className="data-table" data-testid={`${testidPrefix}-mapping-table`}>
              <thead>
                <tr>
                  <th style={{ width: '40%' }}>Source column (your CSV)</th>
                  <th>Target field</th>
                </tr>
              </thead>
              <tbody>
                {(inspect.headers || []).map((h, i) => {
                  const mapped = columnMap?.[h] ?? null;
                  return (
                    <tr key={i} data-testid={`${testidPrefix}-mapping-row-${i}`}>
                      <td>
                        <code>{h}</code>
                        {mapped === null && <span style={{ marginLeft: 8, color: 'var(--cf-text-muted, #9ca3af)', fontSize: 12 }}>(will be ignored)</span>}
                      </td>
                      <td>
                        <select
                          value={mapped || ''}
                          onChange={(e) => setMapForHeader(h, e.target.value || null)}
                          data-testid={`${testidPrefix}-mapping-select-${i}`}
                          style={{ padding: '4px 8px', borderRadius: 4, border: '1px solid var(--cf-border, #e5e7eb)', minWidth: 240 }}
                        >
                          <option value="">— skip this column —</option>
                          {(inspect.fields || []).map(f => (
                            <option key={f.key} value={f.key}>
                              {f.label}{f.required ? ' *' : ''}
                            </option>
                          ))}
                        </select>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
            {(() => {
              const mappedKeys = new Set(Object.values(columnMap || {}).filter(Boolean));
              const missingRequired = (inspect.fields || []).filter(f => f.required && !mappedKeys.has(f.key));
              if (missingRequired.length === 0) return null;
              return (
                <p data-testid={`${testidPrefix}-missing-required`} style={{ color: '#c0392b', fontSize: 13, marginTop: 8 }}>
                  Required fields not yet mapped: <strong>{missingRequired.map(f => f.label).join(', ')}</strong>.
                  Dry-run will surface row-level errors for these.
                </p>
              );
            })()}
            {/* Save mapping panel — turns the next rerun of the same CSV
                format into a zero-AI, one-click apply. */}
            {presetEntity && (
              <div data-testid={`${testidPrefix}-preset-save`} style={{ marginTop: 12, display: 'flex', gap: 6, alignItems: 'center', flexWrap: 'wrap' }}>
                <input
                  className="input"
                  placeholder="Save this mapping as… (e.g. QuickBooks vendors)"
                  value={presetName}
                  onChange={e => setPresetName(e.target.value)}
                  data-testid={`${testidPrefix}-preset-save-name`}
                  style={{ flex: '1 1 240px', maxWidth: 360 }}
                />
                <button
                  className="btn"
                  onClick={savePreset}
                  disabled={!presetName.trim() || savingPreset}
                  data-testid={`${testidPrefix}-preset-save-btn`}
                >
                  {savingPreset ? 'Saving…' : 'Save mapping'}
                </button>
                {presetSaved && (
                  <span data-testid={`${testidPrefix}-preset-saved`} style={{ color: 'var(--cf-green, #047857)', fontSize: 13 }}>
                    ✓ Saved · re-applies automatically next time
                  </span>
                )}
              </div>
            )}
          </div>
        )}

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
              <label style={{ display: 'flex', alignItems: 'center', gap: 'var(--cf-space-2)' }}>
                <input
                  type="checkbox"
                  checked={updateExisting}
                  onChange={e => setUpdateExisting(e.target.checked)}
                  data-testid={`${testidPrefix}-update-existing`}
                />
                Update existing rows on match (else: skip duplicates)
              </label>
              {(extraToggles || []).map(t => (
                <label key={t.key} style={{ display: 'flex', alignItems: 'center', gap: 'var(--cf-space-2)' }}>
                  <input
                    type="checkbox"
                    checked={!!extraToggleValues[t.key]}
                    onChange={e => setExtraToggle(t.key, e.target.checked)}
                    data-testid={`${testidPrefix}-toggle-${t.key}`}
                  />
                  {t.label}
                </label>
              ))}
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
            <p style={{ fontSize: 12, color: 'var(--cf-text-secondary)', margin: '4px 0 12px' }}>
              This import has been logged to the audit trail (who, when, file, rows, errors).
            </p>
            <div style={{ display: 'flex', gap: 'var(--cf-space-2)', flexWrap: 'wrap' }}>
              <Link to={backTo}              className="btn btn--primary" data-testid={`${testidPrefix}-result-back`}>Done</Link>
              <Link to="/data/import-history" className="btn btn--ghost"   data-testid={`${testidPrefix}-result-view-history`}>View Import History</Link>
            </div>
          </div>
        )}
      </div>
    </section>
  );
}

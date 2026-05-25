import React, { useEffect, useState } from 'react';
import { api } from '../lib/api';

/**
 * Tenant Integration Field Map admin — Slice 3 scaffolding.
 *
 * Configures per-tenant overrides for which external (source-side)
 * fields populate which CoreFlux internal columns, per (integration,
 * entity_type) pair. Replaces the hard-coded `jobdivaPluckField()`
 * candidate lists in the syncer once Slice 4 wires this registry in.
 *
 * Current state: lets a tenant_admin list / add / delete mappings.
 * The syncer does NOT yet read from this table — that's the next slice.
 * A banner makes this explicit so operators don't expect immediate
 * behaviour change.
 *
 * RBAC: integrations.field_map.manage (enforced server-side; this UI
 * is hidden from non-admins via AdminModule's nav gating elsewhere).
 */
const ENTITY_TYPES = ['placement', 'person', 'company', 'contact',
                      'gl_account', 'journal_entry', 'bill', 'invoice', 'payment'];
const INTEGRATIONS = ['jobdiva', 'quickbooks', 'zoho_books', 'xero', 'airtable', 'bullhorn'];

export default function IntegrationFieldMapAdmin() {
  const [integration, setIntegration] = useState('jobdiva');
  const [entityType, setEntityType]   = useState('placement');
  const [data, setData]               = useState(null);
  const [loading, setLoading]         = useState(false);
  const [error, setError]             = useState(null);
  const [form, setForm] = useState({ external_field: '', internal_field: '', transform: 'none', notes: '' });
  const [saving, setSaving] = useState(false);

  const reload = async () => {
    setLoading(true); setError(null);
    try {
      const params = new URLSearchParams({ integration, entity_type: entityType });
      const r = await api.get(`/api/admin/integrations/field_map.php?${params}`);
      setData(r);
    } catch (e) {
      setError(e.message || 'Failed to load');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { reload(); /* eslint-disable-next-line */ }, [integration, entityType]);

  const handleSave = async (e) => {
    e.preventDefault();
    if (!form.external_field || !form.internal_field) return;
    setSaving(true); setError(null);
    try {
      await api.post('/api/admin/integrations/field_map.php', { integration, entity_type: entityType, ...form });
      setForm({ external_field: '', internal_field: '', transform: 'none', notes: '' });
      await reload();
    } catch (e) {
      setError(e.message || 'Save failed');
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async (id) => {
    if (!window.confirm('Remove this mapping? The syncer will revert to its built-in defaults.')) return;
    try {
      await api.delete(`/api/admin/integrations/field_map.php?id=${id}`);
      await reload();
    } catch (e) {
      setError(e.message || 'Delete failed');
    }
  };

  // -- Bulk import / export ------------------------------------------------
  // Slice 6/7 — operators paste a JSON snapshot (or upload one) to
  // copy a vetted mapping between environments. Replace mode wipes the
  // current integration's rows first, merge mode upserts in place.
  const [bulkOpen, setBulkOpen]         = useState(false);
  const [bulkText, setBulkText]         = useState('');
  const [bulkMode, setBulkMode]         = useState('merge');
  const [bulkBusy, setBulkBusy]         = useState(false);
  const [bulkResult, setBulkResult]     = useState(null);

  const handleExport = async () => {
    setError(null);
    try {
      const params = new URLSearchParams({ integration });
      const r = await api.get(`/api/admin/integrations/field_map_bulk.php?${params}`);
      const json = JSON.stringify(r, null, 2);
      setBulkText(json);
      setBulkOpen(true);
      setBulkResult(null);
      // Best-effort clipboard copy. Some browsers gate this behind
      // a user gesture which we just had (the click), so it usually
      // succeeds. Failure is silently ignored — the JSON is also
      // visible in the textarea for the operator to copy manually.
      try { await navigator.clipboard.writeText(json); } catch { /* ignore */ }
    } catch (e) {
      setError(e.message || 'Export failed');
    }
  };

  const handleDownload = () => {
    if (!bulkText) return;
    const blob = new Blob([bulkText], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `field_map_${integration}_${new Date().toISOString().slice(0, 10)}.json`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  };

  const handleImport = async () => {
    setError(null); setBulkResult(null);
    let body;
    try { body = JSON.parse(bulkText || '{}'); }
    catch (e) { setError('Paste must be valid JSON: ' + (e.message || e)); return; }
    if (!body.mappings || !Array.isArray(body.mappings)) {
      setError('JSON must have a top-level "mappings" array.');
      return;
    }
    if (bulkMode === 'replace' &&
        !window.confirm(`Replace ALL existing mappings for the integrations present in this JSON (currently includes: ${[...new Set(body.mappings.map(r => r.integration).filter(Boolean))].join(', ') || 'none'})? This deletes existing rows before inserting.`)) {
      return;
    }
    setBulkBusy(true);
    try {
      const r = await api.post('/api/admin/integrations/field_map_bulk.php', {
        ...body,
        mode: bulkMode,
      });
      setBulkResult(r);
      await reload();
    } catch (e) {
      setError(e.message || 'Import failed');
    } finally {
      setBulkBusy(false);
    }
  };

  // -- Test mapping with sample payload ----------------------------------
  // Operator pastes a raw JobDiva record (e.g. from the "View raw payload"
  // affordance in LinkedExternalSystemsPanel), sees what each configured
  // rule would resolve to. Pure dry run — no DB writes.
  const [testOpen, setTestOpen]       = useState(false);
  const [testInput, setTestInput]     = useState('');
  const [testBusy, setTestBusy]       = useState(false);
  const [testResult, setTestResult]   = useState(null);

  const handleTestRun = async () => {
    setError(null); setTestResult(null);
    let payload;
    try {
      payload = JSON.parse(testInput || '{}');
    } catch (e) {
      setError('Sample payload must be valid JSON: ' + (e.message || e));
      return;
    }
    setTestBusy(true);
    try {
      const r = await api.post('/api/admin/integrations/field_map_test.php', {
        integration,
        entity_type: entityType,
        payload,
      });
      setTestResult(r);
    } catch (e) {
      setError(e.message || 'Test failed');
    } finally {
      setTestBusy(false);
    }
  };

  const allowedInternal = data?.allowed_internal_fields?.[entityType] || [];
  const transforms = data?.transforms || ['none'];
  const rows = data?.rows || [];

  return (
    <section data-testid="integration-field-map-admin" style={{ padding: 'var(--cf-space-3, 1rem)' }}>
      <header style={{ marginBottom: '1rem' }}>
        <h2 style={{ margin: 0 }}>Integration field map</h2>
        <p style={{ color: '#64748b', fontSize: 13, marginTop: 4 }}>
          Override the syncer's field choices for this tenant. Picks the external (source-side) field
          name and writes it into the chosen CoreFlux column.
        </p>
        <div
          data-testid="field-map-status-banner"
          style={{
            marginTop: '0.75rem',
            padding: '0.6rem 0.9rem',
            background: '#ecfdf5', border: '1px solid #a7f3d0', borderRadius: 8,
            color: '#065f46', fontSize: 13,
          }}
        >
          <strong>Live.</strong>{' '}
          The next sync will use these mappings. Configured rows override the syncer's built-in field choices;
          unconfigured fields fall back to the default candidate keys. Tip: open any placement and use
          <em> "View raw payload" </em> in the Linked external systems panel to find the exact JobDiva field name to map.
        </div>
      </header>

      <div data-testid="field-map-scope" style={{ display: 'flex', gap: '0.75rem', marginBottom: '1rem', alignItems: 'flex-end', flexWrap: 'wrap' }}>
        <label style={{ display: 'flex', flexDirection: 'column', fontSize: 12, color: '#475569' }}>
          Integration
          <select
            value={integration}
            onChange={(e) => setIntegration(e.target.value)}
            data-testid="field-map-integration-select"
            className="input"
          >
            {INTEGRATIONS.map(i => <option key={i} value={i}>{i}</option>)}
          </select>
        </label>
        <label style={{ display: 'flex', flexDirection: 'column', fontSize: 12, color: '#475569' }}>
          Entity type
          <select
            value={entityType}
            onChange={(e) => setEntityType(e.target.value)}
            data-testid="field-map-entity-select"
            className="input"
          >
            {ENTITY_TYPES.map(e2 => <option key={e2} value={e2}>{e2}</option>)}
          </select>
        </label>
        <div style={{ marginLeft: 'auto', display: 'flex', gap: '0.5rem' }}>
          <button
            type="button"
            className="btn btn--ghost"
            onClick={handleExport}
            data-testid="field-map-export-btn"
            title={`Export every mapping for ${integration} to JSON (copy to clipboard).`}
          >Export JSON</button>
          <button
            type="button"
            className="btn btn--ghost"
            onClick={() => { setBulkOpen(o => !o); setBulkResult(null); }}
            data-testid="field-map-import-toggle"
          >{bulkOpen ? 'Hide import' : 'Import JSON'}</button>
          <button
            type="button"
            className="btn btn--ghost"
            onClick={() => { setTestOpen(o => !o); setTestResult(null); }}
            data-testid="field-map-test-toggle"
          >{testOpen ? 'Hide test' : 'Test with payload'}</button>
        </div>
      </div>

      {bulkOpen && (
        <section
          data-testid="field-map-bulk-panel"
          style={{
            border: '1px solid #e2e8f0', borderRadius: 8,
            padding: '0.9rem', marginBottom: '1rem', background: '#f8fafc',
          }}
        >
          <header style={{ display: 'flex', alignItems: 'center', gap: '0.75rem', marginBottom: '0.5rem' }}>
            <strong style={{ fontSize: 13 }}>Bulk import / export</strong>
            <span style={{ fontSize: 12, color: '#64748b' }}>
              Paste a JSON snapshot from a sibling tenant or environment.
              Mappings whose <code>integration</code> doesn&apos;t match the current
              scope still import — the JSON drives the scope.
            </span>
          </header>
          <textarea
            value={bulkText}
            onChange={(e) => setBulkText(e.target.value)}
            placeholder={'{ "mappings": [ { "integration": "jobdiva", "entity_type": "placement", "external_field": "job.title", "internal_field": "title", "transform": "none", "enabled": true } ] }'}
            data-testid="field-map-bulk-textarea"
            rows={8}
            style={{
              width: '100%', fontFamily: 'ui-monospace, monospace',
              fontSize: 12, padding: 8, border: '1px solid #cbd5e1', borderRadius: 6,
            }}
          />
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', marginTop: '0.5rem', flexWrap: 'wrap' }}>
            <label style={{ display: 'flex', alignItems: 'center', gap: '0.35rem', fontSize: 12, color: '#475569' }}>
              Mode
              <select
                value={bulkMode}
                onChange={(e) => setBulkMode(e.target.value)}
                data-testid="field-map-bulk-mode"
                className="input"
              >
                <option value="merge">merge — upsert each row, leave unrelated rows alone</option>
                <option value="replace">replace — wipe each integration in the JSON, then insert</option>
              </select>
            </label>
            <button
              type="button"
              className="btn btn--primary"
              onClick={handleImport}
              disabled={bulkBusy || !bulkText.trim()}
              data-testid="field-map-bulk-import-btn"
            >{bulkBusy ? 'Importing…' : 'Apply import'}</button>
            <button
              type="button"
              className="btn btn--ghost"
              onClick={handleDownload}
              disabled={!bulkText}
              data-testid="field-map-bulk-download-btn"
            >Download as file</button>
          </div>
          {bulkResult && (
            <div
              data-testid="field-map-bulk-result"
              style={{
                marginTop: '0.75rem', fontSize: 12, color: '#334155',
                background: '#ecfdf5', border: '1px solid #a7f3d0',
                padding: '0.5rem 0.75rem', borderRadius: 6,
              }}
            >
              Imported <strong>{bulkResult.imported}</strong>, skipped{' '}
              <strong>{bulkResult.skipped}</strong> ({bulkResult.mode} mode).
              {bulkResult.replaced_integrations?.length > 0 && (
                <> Replaced integrations: {bulkResult.replaced_integrations.join(', ')}.</>
              )}
              {bulkResult.errors?.length > 0 && (
                <details style={{ marginTop: 6 }} data-testid="field-map-bulk-errors">
                  <summary>Errors ({bulkResult.errors.length})</summary>
                  <ul style={{ margin: '4px 0 0 18px', padding: 0 }}>
                    {bulkResult.errors.map((er, i) => (
                      <li key={i}>row {er.row_index}: {er.error}</li>
                    ))}
                  </ul>
                </details>
              )}
            </div>
          )}
        </section>
      )}

      {testOpen && (
        <section
          data-testid="field-map-test-panel"
          style={{
            border: '1px solid #e2e8f0', borderRadius: 8,
            padding: '0.9rem', marginBottom: '1rem', background: '#f8fafc',
          }}
        >
          <header style={{ marginBottom: '0.5rem' }}>
            <strong style={{ fontSize: 13 }}>Test mapping with sample payload</strong>
            <p style={{ margin: '4px 0 0', fontSize: 12, color: '#64748b' }}>
              Paste a raw JobDiva (or source-side) record. We&apos;ll show you what each
              configured rule would extract — without writing anything. Tip: grab one
              from the &quot;View raw payload&quot; affordance in any Linked external systems
              panel.
            </p>
          </header>
          <textarea
            value={testInput}
            onChange={(e) => setTestInput(e.target.value)}
            placeholder={'{ "id": 12345, "job id": 99887, "job": { "title": "Service Desk Analyst" }, "customer name": "Public Storage" }'}
            data-testid="field-map-test-textarea"
            rows={6}
            style={{
              width: '100%', fontFamily: 'ui-monospace, monospace',
              fontSize: 12, padding: 8, border: '1px solid #cbd5e1', borderRadius: 6,
            }}
          />
          <div style={{ display: 'flex', gap: '0.5rem', marginTop: '0.5rem' }}>
            <button
              type="button"
              className="btn btn--primary"
              onClick={handleTestRun}
              disabled={testBusy || !testInput.trim()}
              data-testid="field-map-test-run-btn"
            >{testBusy ? 'Running…' : 'Run dry-run'}</button>
          </div>
          {testResult && (
            <div data-testid="field-map-test-result" style={{ marginTop: '0.75rem' }}>
              {testResult.resolved.length === 0 ? (
                <p style={{ fontSize: 12, color: '#64748b' }}>
                  No rules configured for ({testResult.integration}, {testResult.entity_type}) yet.
                  Add one above and re-run.
                </p>
              ) : (
                <table style={{ width: '100%', fontSize: 12 }} data-testid="field-map-test-table">
                  <thead>
                    <tr style={{ color: '#64748b', textAlign: 'left' }}>
                      <th style={{ padding: '4px 6px' }}>Internal field</th>
                      <th style={{ padding: '4px 6px' }}>From</th>
                      <th style={{ padding: '4px 6px' }}>Transform</th>
                      <th style={{ padding: '4px 6px' }}>Resolved value</th>
                    </tr>
                  </thead>
                  <tbody>
                    {testResult.resolved.map(r => (
                      <tr key={r.internal_field}
                          data-testid={`field-map-test-row-${r.internal_field}`}
                          style={{ background: r.matched ? '#ecfdf5' : '#fef2f2' }}>
                        <td style={{ padding: '4px 6px', fontFamily: 'ui-monospace, monospace' }}>{r.internal_field}</td>
                        <td style={{ padding: '4px 6px', fontFamily: 'ui-monospace, monospace' }}>{r.external_field}</td>
                        <td style={{ padding: '4px 6px' }}>{r.transform}</td>
                        <td style={{ padding: '4px 6px', fontFamily: 'ui-monospace, monospace' }}>
                          {r.matched
                            ? (r.value === null ? <em>null</em> : String(r.value))
                            : <em style={{ color: '#dc2626' }}>no match</em>}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
              {testResult.unmapped_internal_fields?.length > 0 && (
                <details style={{ marginTop: 8, fontSize: 12, color: '#64748b' }}
                         data-testid="field-map-test-unmapped">
                  <summary>
                    {testResult.unmapped_internal_fields.length} allow-listed columns
                    have no rule (syncer will use built-in defaults)
                  </summary>
                  <code style={{ display: 'block', marginTop: 4 }}>
                    {testResult.unmapped_internal_fields.join(', ')}
                  </code>
                </details>
              )}
            </div>
          )}
        </section>
      )}

      {error && <p className="error" data-testid="field-map-error">{error}</p>}
      {loading && <p data-testid="field-map-loading">Loading…</p>}

      {!loading && (
        <>
          <table className="data-table" data-testid="field-map-table" style={{ width: '100%', marginBottom: '1.5rem' }}>
            <thead>
              <tr style={{ fontSize: 11, color: '#64748b', textAlign: 'left' }}>
                <th style={{ padding: '6px 8px' }}>External field</th>
                <th style={{ padding: '6px 8px' }}>→ Internal field</th>
                <th style={{ padding: '6px 8px' }}>Transform</th>
                <th style={{ padding: '6px 8px' }}>Enabled</th>
                <th style={{ padding: '6px 8px' }}>Notes</th>
                <th style={{ padding: '6px 8px' }}></th>
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 && (
                <tr><td colSpan={6} data-testid="field-map-empty" style={{ padding: 12, color: '#64748b' }}>
                  No overrides configured — syncer uses built-in defaults.
                </td></tr>
              )}
              {rows.map(r => (
                <tr key={r.id} data-testid={`field-map-row-${r.id}`}>
                  <td style={{ padding: '8px', fontFamily: 'ui-monospace, monospace' }}>{r.external_field}</td>
                  <td style={{ padding: '8px', fontFamily: 'ui-monospace, monospace' }}>{r.internal_field}</td>
                  <td style={{ padding: '8px', fontSize: 12 }}>{r.transform}</td>
                  <td style={{ padding: '8px' }}>{r.enabled ? '✓' : '✗'}</td>
                  <td style={{ padding: '8px', fontSize: 12, color: '#64748b' }}>{r.notes || '—'}</td>
                  <td style={{ padding: '8px' }}>
                    <button
                      className="btn btn--ghost"
                      onClick={() => handleDelete(r.id)}
                      data-testid={`field-map-delete-${r.id}`}
                      style={{ fontSize: 12 }}
                    >Remove</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          <form
            onSubmit={handleSave}
            data-testid="field-map-add-form"
            style={{ display: 'flex', gap: '0.5rem', alignItems: 'flex-end', flexWrap: 'wrap' }}
          >
            <label style={{ display: 'flex', flexDirection: 'column', fontSize: 12, color: '#475569' }}>
              External field
              <input
                type="text"
                value={form.external_field}
                onChange={(e) => setForm(f => ({ ...f, external_field: e.target.value }))}
                placeholder="e.g. jobTitle"
                data-testid="field-map-external-input"
                className="input"
              />
            </label>
            <label style={{ display: 'flex', flexDirection: 'column', fontSize: 12, color: '#475569' }}>
              Internal field
              <select
                value={form.internal_field}
                onChange={(e) => setForm(f => ({ ...f, internal_field: e.target.value }))}
                data-testid="field-map-internal-select"
                className="input"
              >
                <option value="">— select —</option>
                {allowedInternal.map(f => <option key={f} value={f}>{f}</option>)}
              </select>
            </label>
            <label style={{ display: 'flex', flexDirection: 'column', fontSize: 12, color: '#475569' }}>
              Transform
              <select
                value={form.transform}
                onChange={(e) => setForm(f => ({ ...f, transform: e.target.value }))}
                data-testid="field-map-transform-select"
                className="input"
              >
                {transforms.map(t => <option key={t} value={t}>{t}</option>)}
              </select>
            </label>
            <label style={{ display: 'flex', flexDirection: 'column', fontSize: 12, color: '#475569', flex: 1, minWidth: 180 }}>
              Notes (optional)
              <input
                type="text"
                value={form.notes}
                onChange={(e) => setForm(f => ({ ...f, notes: e.target.value }))}
                data-testid="field-map-notes-input"
                className="input"
              />
            </label>
            <button
              type="submit"
              className="btn btn--primary"
              disabled={saving || !form.external_field || !form.internal_field}
              data-testid="field-map-add-submit"
            >
              {saving ? 'Saving…' : '+ Add mapping'}
            </button>
          </form>
        </>
      )}
    </section>
  );
}

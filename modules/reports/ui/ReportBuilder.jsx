import React, { useMemo, useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';
import { ReportFrame } from './ReportToolkit';

export default function ReportBuilder() {
  const datasetsApi = useApi('/api/v1/reports/report-builder/datasets');
  const presetsApi = useApi('/api/v1/reports/report-builder/presets');
  const reportsApi = useApi('/api/v1/reports/report-builder/reports');
  const datasets = datasetsApi.data?.datasets || {};
  const presets = presetsApi.data?.presets || {};
  const datasetKeys = Object.keys(datasets);
  const presetList = useMemo(
    () => Object.values(presets).sort((a, b) => String(a.label || '').localeCompare(String(b.label || ''))),
    [presets]
  );
  const [datasetKey, setDatasetKey] = useState('');
  const [presetKey, setPresetKey] = useState('');
  const activeKey = datasetKey || datasetKeys[0] || '';
  const dataset = activeKey ? datasets[activeKey] : null;
  const fields = useMemo(() => Object.values(dataset?.fields || {}), [dataset]);
  const [selected, setSelected] = useState([]);
  const [filters, setFilters] = useState([]);
  const [sorts, setSorts] = useState([]);
  const [name, setName] = useState('');
  const [visibility, setVisibility] = useState('private');
  const [saving, setSaving] = useState(false);
  const [previewing, setPreviewing] = useState(false);
  const [exporting, setExporting] = useState(false);
  const [deletingId, setDeletingId] = useState(null);
  const [error, setError] = useState(null);
  const [savedAt, setSavedAt] = useState(null);
  const [preview, setPreview] = useState(null);

  const definitionFieldKeys = (definition = {}) => {
    const keys = [];
    ['columns', 'dimensions', 'measures'].forEach((section) => {
      (definition[section] || []).forEach((entry) => {
        const key = typeof entry === 'string' ? entry : entry?.field || entry?.key;
        if (key && !keys.includes(key)) keys.push(key);
      });
    });
    return keys;
  };

  const resetWorkingDefinition = () => {
    setSelected([]);
    setFilters([]);
    setSorts([]);
    setPresetKey('');
    setPreview(null);
  };

  const toggleField = (key) => {
    setPreview(null);
    setSelected((prev) => prev.includes(key) ? prev.filter((x) => x !== key) : [...prev, key]);
  };

  const currentDefinition = (limit = 1000) => ({
    dataset: dataset?.key,
    columns: selected,
    filters,
    sorts,
    limit,
  });

  const applyPreset = (preset) => {
    const definition = preset?.definition || {};
    const nextDataset = definition.dataset || preset?.dataset || '';
    if (nextDataset) setDatasetKey(nextDataset);
    setPresetKey(preset?.key || '');
    setSelected(definitionFieldKeys(definition));
    setFilters(Array.isArray(definition.filters) ? definition.filters : []);
    setSorts(Array.isArray(definition.sorts) ? definition.sorts : []);
    setName(preset?.label || '');
    setPreview(null);
    setSavedAt(null);
    setError(null);
  };

  const loadReport = (report) => {
    const definition = report?.definition || {};
    setDatasetKey(report?.dataset || definition.dataset || '');
    setPresetKey('');
    setSelected(definitionFieldKeys(definition));
    setFilters(Array.isArray(definition.filters) ? definition.filters : []);
    setSorts(Array.isArray(definition.sorts) ? definition.sorts : []);
    setName(report?.name || '');
    setVisibility(report?.visibility || 'private');
    setPreview(null);
    setSavedAt(null);
    setError(null);
  };

  const save = async () => {
    if (!dataset || !name.trim() || selected.length === 0) return;
    setSaving(true);
    setError(null);
    try {
      await api.post('/api/v1/reports/report-builder', {
        name: name.trim(),
        visibility,
        definition: {
          dataset: dataset.key,
          columns: selected,
          filters,
          sorts,
          limit: 1000,
        },
      });
      setName('');
      resetWorkingDefinition();
      setSavedAt(Date.now());
      reportsApi.reload();
    } catch (e) {
      setError(e);
    } finally {
      setSaving(false);
    }
  };

  const previewReport = async () => {
    if (!dataset || selected.length === 0) return;
    setPreviewing(true);
    setError(null);
    try {
      const res = await api.post('/api/v1/reports/report-builder/run', {
        definition: currentDefinition(100),
      });
      setPreview(res.result || null);
    } catch (e) {
      setError(e);
    } finally {
      setPreviewing(false);
    }
  };

  const exportCsv = async () => {
    if (!dataset || selected.length === 0) return;
    setExporting(true);
    setError(null);
    try {
      const res = await fetch('/api/v1/reports/report-builder/export', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'text/csv' },
        body: JSON.stringify({ definition: currentDefinition(10000) }),
      });
      if (!res.ok) {
        let message = 'Export failed';
        try {
          const data = await res.json();
          message = data.error || message;
        } catch {
          message = res.statusText || message;
        }
        throw new Error(message);
      }
      const blob = await res.blob();
      const href = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = href;
      a.download = `${dataset.key}_custom_report.csv`;
      a.rel = 'noopener';
      a.click();
      URL.revokeObjectURL(href);
    } catch (e) {
      setError(e);
    } finally {
      setExporting(false);
    }
  };

  const removeReport = async (id) => {
    if (!id || !confirm('Delete saved report?')) return;
    setDeletingId(id);
    setError(null);
    try {
      await api.delete(`/api/v1/reports/report-builder/${id}`);
      reportsApi.reload();
    } catch (e) {
      setError(e);
    } finally {
      setDeletingId(null);
    }
  };

  if (datasetsApi.loading) return <p data-testid="report-builder-loading">Loading...</p>;
  if (datasetsApi.error) return <p className="error" data-testid="report-builder-error">Error: {datasetsApi.error.message}</p>;

  return (
    <ReportFrame
      title="Custom Report Builder"
      subtitle="Governed datasets"
      testid="report-builder"
      actions={(
        <div style={{ display: 'inline-flex', gap: 8 }}>
          <button
            className="btn btn--ghost"
            data-testid="report-builder-preview"
            onClick={previewReport}
            disabled={previewing || selected.length === 0}
          >
            {previewing ? 'Previewing...' : 'Preview'}
          </button>
          <button
            className="btn btn--ghost"
            data-testid="report-builder-export"
            onClick={exportCsv}
            disabled={exporting || selected.length === 0}
          >
            {exporting ? 'Exporting...' : 'Export CSV'}
          </button>
          <button
            className="btn btn--primary"
            data-testid="report-builder-save"
            onClick={save}
            disabled={saving || !name.trim() || selected.length === 0}
          >
            {saving ? 'Saving...' : 'Save'}
          </button>
        </div>
      )}
    >
      {datasetKeys.length === 0 && (
        <p className="empty" data-testid="report-builder-empty">No datasets available.</p>
      )}

      {datasetKeys.length > 0 && (
        <div style={{ display: 'grid', gridTemplateColumns: 'minmax(220px, 280px) minmax(0, 1fr)', gap: 16 }}>
          <aside data-testid="report-builder-datasets" style={{ borderRight: '1px solid #e2e8f0', paddingRight: 16 }}>
            <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#475569', marginBottom: 6 }}>
              Dataset
            </label>
            <select
              className="input"
              value={activeKey}
              onChange={(e) => { setDatasetKey(e.target.value); resetWorkingDefinition(); }}
              data-testid="report-builder-dataset-select"
              style={{ width: '100%' }}
            >
              {datasetKeys.map((key) => (
                <option key={key} value={key}>{datasets[key].label || key}</option>
              ))}
            </select>

            {presetList.length > 0 && (
              <div style={{ marginTop: 16 }} data-testid="report-builder-presets">
                <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#475569', marginBottom: 6 }}>
                  Preset
                </label>
                <div style={{ display: 'grid', gridTemplateColumns: '1fr auto', gap: 8 }}>
                  <select
                    className="input"
                    value={presetKey}
                    onChange={(e) => setPresetKey(e.target.value)}
                    data-testid="report-builder-preset-select"
                    style={{ width: '100%' }}
                  >
                    <option value="">Select preset</option>
                    {presetList.map((preset) => (
                      <option key={preset.key} value={preset.key}>
                        {preset.label || preset.key}
                      </option>
                    ))}
                  </select>
                  <button
                    className="btn btn--ghost"
                    onClick={() => applyPreset(presets[presetKey])}
                    disabled={!presetKey || !presets[presetKey]}
                    data-testid="report-builder-preset-apply"
                  >
                    Apply
                  </button>
                </div>
              </div>
            )}

            <div style={{ marginTop: 16 }}>
              <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#475569', marginBottom: 6 }}>
                Name
              </label>
              <input
                className="input"
                value={name}
                onChange={(e) => setName(e.target.value)}
                data-testid="report-builder-name"
                style={{ width: '100%' }}
              />
            </div>

            <div style={{ marginTop: 16 }}>
              <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#475569', marginBottom: 6 }}>
                Visibility
              </label>
              <select
                className="input"
                value={visibility}
                onChange={(e) => setVisibility(e.target.value)}
                data-testid="report-builder-visibility"
                style={{ width: '100%' }}
              >
                <option value="private">Private</option>
                <option value="shared">Shared</option>
              </select>
            </div>

            <div style={{ marginTop: 18, fontSize: 12, color: '#64748b' }}>
              <div data-testid="report-builder-selected-count">{selected.length} selected</div>
              {(filters.length > 0 || sorts.length > 0) && (
                <div data-testid="report-builder-definition-conditions" style={{ marginTop: 8, display: 'grid', gap: 4 }}>
                  {filters.map((filter, idx) => (
                    <div key={`filter-${idx}`}>
                      Filter: {filter.field || filter.key} {filter.operator || 'equals'} {String(filter.value ?? '')}
                    </div>
                  ))}
                  {sorts.map((sort, idx) => (
                    <div key={`sort-${idx}`}>
                      Sort: {sort.field || sort.key} {sort.direction || 'asc'}
                    </div>
                  ))}
                </div>
              )}
              {savedAt && <div data-testid="report-builder-saved" style={{ color: '#047857', marginTop: 6 }}>Saved.</div>}
              {error && <div className="error" data-testid="report-builder-save-error" style={{ marginTop: 6 }}>Error: {error.message}</div>}
            </div>
          </aside>

          <section>
            <header style={{ display: 'flex', justifyContent: 'space-between', gap: 12, alignItems: 'center', marginBottom: 10 }}>
              <div>
                <h3 style={{ margin: 0, fontSize: 16 }}>{dataset?.label}</h3>
                <div style={{ color: '#64748b', fontSize: 12 }} data-testid="report-builder-dataset-permission">
                  {dataset?.permission || 'No permission'}
                </div>
              </div>
              <div style={{ color: '#64748b', fontSize: 12 }} data-testid="report-builder-field-count">
                {fields.length} fields
              </div>
            </header>

            <table className="data-table" data-testid="report-builder-fields" style={{ width: '100%', fontSize: 13 }}>
              <thead>
                <tr>
                  <th style={{ width: 48 }}></th>
                  <th>Field</th>
                  <th>Type</th>
                  <th>Role</th>
                  <th>Sensitive</th>
                </tr>
              </thead>
              <tbody>
                {fields.map((field) => (
                  <tr key={field.key} data-testid={`report-builder-field-${field.key}`}>
                    <td>
                      <input
                        type="checkbox"
                        checked={selected.includes(field.key)}
                        onChange={() => toggleField(field.key)}
                        data-testid={`report-builder-field-toggle-${field.key}`}
                        aria-label={`Select ${field.label}`}
                      />
                    </td>
                    <td>
                      <div style={{ fontWeight: 600 }}>{field.label}</div>
                      <code style={{ color: '#64748b' }}>{field.key}</code>
                    </td>
                    <td>{field.type}</td>
                    <td>{field.role}</td>
                    <td>{field.sensitive ? 'Yes' : 'No'}</td>
                  </tr>
                ))}
              </tbody>
            </table>

            <section data-testid="report-builder-saved-list" style={{ marginTop: 22 }}>
              <h3 style={{ margin: '0 0 8px', fontSize: 16 }}>Saved reports</h3>
              {(reportsApi.data?.reports || []).length === 0 && (
                <p className="empty" data-testid="report-builder-saved-empty">No saved reports.</p>
              )}
              {(reportsApi.data?.reports || []).map((report) => (
                <div
                  key={report.id}
                  data-testid={`report-builder-saved-${report.id}`}
                  style={{ borderTop: '1px solid #e2e8f0', padding: '8px 0', display: 'flex', justifyContent: 'space-between', gap: 12 }}
                >
                  <span>{report.name}</span>
                  <span style={{ color: '#64748b', fontSize: 12 }}>{report.dataset} - {report.visibility}</span>
                  <span style={{ display: 'inline-flex', gap: 8 }}>
                    <button
                      className="btn btn--ghost"
                      data-testid={`report-builder-load-${report.id}`}
                      onClick={() => loadReport(report)}
                    >
                      Load
                    </button>
                    <button
                      className="btn btn--ghost"
                      data-testid={`report-builder-delete-${report.id}`}
                      onClick={() => removeReport(report.id)}
                      disabled={deletingId === report.id}
                    >
                      {deletingId === report.id ? 'Deleting...' : 'Delete'}
                    </button>
                  </span>
                </div>
              ))}
            </section>

            {preview && (
              <section data-testid="report-builder-preview-results" style={{ marginTop: 22 }}>
                <h3 style={{ margin: '0 0 8px', fontSize: 16 }}>Preview</h3>
                <div style={{ color: '#64748b', fontSize: 12, marginBottom: 8 }} data-testid="report-builder-preview-count">
                  {preview.row_count || 0} rows
                </div>
                <table className="data-table" style={{ width: '100%', fontSize: 13 }}>
                  <thead>
                    <tr>
                      {(preview.columns || []).map((column) => (
                        <th key={column.field}>{column.label || column.field}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {(preview.rows || []).slice(0, 25).map((row, idx) => (
                      <tr key={idx}>
                        {(preview.columns || []).map((column) => (
                          <td key={column.field}>{row[column.field]}</td>
                        ))}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </section>
            )}
          </section>
        </div>
      )}
    </ReportFrame>
  );
}

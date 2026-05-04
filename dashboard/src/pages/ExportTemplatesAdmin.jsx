import React, { useEffect, useState, useMemo } from 'react';
import { FileText, Plus, Edit2, Trash2, Copy, Upload, X, Save, ChevronDown } from 'lucide-react';
import { api, useApi } from '../lib/api';
import { Card } from '../components/UIComponents';

/**
 * Admin → Export Templates.
 *
 * Tenant_admin manages tenant-scoped templates; master_admin can additionally
 * author platform-scoped templates that show up on every tenant. Tenants can
 * clone any visible template to fork it.
 */
export default function ExportTemplatesAdmin({ session }) {
  const { data, loading, error, reload } = useApi('/api/export_templates.php');
  const { data: dsData } = useApi('/api/export_templates.php?action=datasets');
  const datasets = dsData?.datasets || {};
  const isMaster = session?.user?.global_role === 'master_admin';

  const [filter, setFilter] = useState('');
  const [editing, setEditing] = useState(null);     // template row OR { _new: true, dataset }
  const [cloning, setCloning] = useState(false);

  const templates = data?.templates || [];
  const filtered = useMemo(() => {
    if (!filter) return templates;
    return templates.filter((t) => t.dataset === filter);
  }, [templates, filter]);

  const onClone = async (id) => {
    setCloning(true);
    try { await api.post(`/api/export_templates.php?action=clone&id=${id}`); reload(); }
    catch (e) { alert(e.message); }
    finally { setCloning(false); }
  };

  const onDelete = async (t) => {
    if (!confirm(`Delete "${t.name}"?${t.is_system ? ' (system templates are archived, not removed)' : ''}`)) return;
    try { await api.delete(`/api/export_templates.php?id=${t.id}`); reload(); }
    catch (e) { alert(e.message); }
  };

  return (
    <div data-testid="export-templates-admin">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-6)' }}>
        <div>
          <h1 style={{ fontSize: 'var(--cf-text-2xl)', fontWeight: 700, marginBottom: 4 }}>
            <FileText size={22} style={{ display: 'inline', marginRight: 8 }} />
            Export templates
          </h1>
          <p style={{ color: 'var(--cf-text-secondary)', fontSize: 14 }}>
            Define the exact CSV format your bank, payroll provider, or accounting
            tool wants. Upload a sample CSV → map each column → save. Templates
            appear in every "Export" dropdown across CoreFlux.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <select className="input" value={filter} onChange={(e) => setFilter(e.target.value)} data-testid="xtpl-filter-dataset" style={{ width: 220 }}>
            <option value="">All datasets</option>
            {Object.values(datasets).map((d) => (
              <option key={d.key} value={d.key}>{d.label}</option>
            ))}
          </select>
          <button
            onClick={() => setEditing({ _new: true, dataset: filter || Object.keys(datasets)[0] || '', scope: 'tenant' })}
            className="btn btn--primary"
            data-testid="xtpl-new-btn"
            disabled={Object.keys(datasets).length === 0}
            style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}
          >
            <Plus size={16} /> New template
          </button>
        </div>
      </div>

      {error && <div className="alert alert--err">{error.message}</div>}

      <Card>
        {loading ? (
          <div style={{ padding: 24, color: 'var(--cf-text-secondary)' }}>Loading…</div>
        ) : filtered.length === 0 ? (
          <div style={{ padding: 32, textAlign: 'center', color: 'var(--cf-text-secondary)' }} data-testid="xtpl-empty">
            No templates yet. Click <strong>New template</strong> to create one.
          </div>
        ) : (
          <table className="data-table" style={{ width: '100%' }}>
            <thead>
              <tr>
                <th style={{ textAlign: 'left' }}>Name</th>
                <th style={{ textAlign: 'left' }}>Dataset</th>
                <th style={{ textAlign: 'left' }}>Scope</th>
                <th style={{ textAlign: 'right' }}>Columns</th>
                <th style={{ textAlign: 'right' }}>Actions</th>
              </tr>
            </thead>
            <tbody>
              {filtered.map((t) => (
                <tr key={t.id} data-testid={`xtpl-row-${t.id}`}>
                  <td style={{ fontWeight: 500 }}>
                    {t.name}
                    {t.is_system === 1 || t.is_system === '1'
                      ? <span className="badge" style={{ marginLeft: 8, fontSize: 10 }}>SYSTEM</span> : null}
                  </td>
                  <td>{datasets[t.dataset]?.label || t.dataset}</td>
                  <td>
                    <span className={t.scope === 'platform' ? 'badge badge--info' : 'badge badge--ok'}>
                      {t.scope}
                    </span>
                  </td>
                  <td style={{ textAlign: 'right' }}>{(t.column_mappings || []).length}</td>
                  <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
                    <button onClick={() => onClone(t.id)} className="btn btn--ghost" disabled={cloning}
                            data-testid={`xtpl-clone-${t.id}`}
                            style={{ padding: '4px 8px', fontSize: 12 }}>
                      <Copy size={12} /> Clone
                    </button>
                    {(t.scope === 'tenant' || isMaster) && (
                      <>
                        <button onClick={() => setEditing(t)} className="btn btn--ghost"
                                data-testid={`xtpl-edit-${t.id}`}
                                style={{ padding: '4px 8px', fontSize: 12, marginLeft: 4 }}>
                          <Edit2 size={12} /> Edit
                        </button>
                        <button onClick={() => onDelete(t)} className="btn btn--ghost"
                                data-testid={`xtpl-delete-${t.id}`}
                                style={{ padding: '4px 8px', fontSize: 12, marginLeft: 4, color: '#dc2626' }}>
                          <Trash2 size={12} />
                        </button>
                      </>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Card>

      {editing && (
        <TemplateEditor
          template={editing}
          datasets={datasets}
          isMaster={isMaster}
          onClose={() => setEditing(null)}
          onSaved={() => { setEditing(null); reload(); }}
        />
      )}
    </div>
  );
}

// ───── Editor (modal) ─────

function TemplateEditor({ template, datasets, isMaster, onClose, onSaved }) {
  const isNew = !!template._new;
  const [name, setName] = useState(template.name || '');
  const [dataset, setDataset] = useState(template.dataset || '');
  const [scope, setScope] = useState(template.scope || 'tenant');
  const [delimiter, setDelimiter] = useState(template.delimiter || ',');
  const [hasHeader, setHasHeader] = useState((template.has_header_row ?? 1) == 1);
  const [mappings, setMappings] = useState(
    (template.column_mappings || []).length
      ? template.column_mappings
      : [{ position: 1, output_header: '', kind: 'field', source_field: '' }]
  );
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState(null);

  const dsFields = useMemo(() => {
    const ds = datasets[dataset];
    return ds ? ds.fields : {};
  }, [datasets, dataset]);

  const fieldOpts = Object.entries(dsFields);

  const addRow = () => setMappings((m) => [...m, {
    position: m.length + 1, output_header: '', kind: 'field', source_field: '',
  }]);
  const removeRow = (i) => setMappings((m) => m.filter((_, idx) => idx !== i));
  const updateRow = (i, patch) => setMappings((m) => m.map((row, idx) => idx === i ? { ...row, ...patch } : row));

  const onUploadSample = async (file) => {
    setErr(null);
    if (!file) return;
    if (file.size > 262144) { setErr('Sample must be < 256 KB'); return; }
    const fd = new FormData();
    fd.append('file', file);
    fd.append('delimiter', delimiter);
    try {
      const res = await fetch('/api/export_templates.php?action=parse_headers', {
        method: 'POST', credentials: 'include', body: fd,
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Parse failed');
      // Pre-fill mapping rows; user picks source_field for each.
      setMappings(data.headers.map((h, i) => ({
        position: i + 1, output_header: h, kind: 'field', source_field: '',
      })));
    } catch (e) {
      setErr(e.message);
    }
  };

  const save = async () => {
    setBusy(true); setErr(null);
    try {
      const body = {
        name, dataset, scope,
        delimiter, has_header_row: hasHeader ? 1 : 0,
        column_mappings: mappings,
      };
      if (isNew) {
        await api.post('/api/export_templates.php', body);
      } else {
        await api.patch(`/api/export_templates.php?id=${template.id}`, body);
      }
      onSaved();
    } catch (e) {
      setErr(e.message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="modal-backdrop" data-testid="xtpl-editor">
      <div className="modal" style={{ maxWidth: 880 }}>
        <div className="modal-header">
          <h3>{isNew ? 'New export template' : `Edit: ${template.name}`}</h3>
          <button onClick={onClose} className="btn btn--ghost"><X size={18} /></button>
        </div>
        <div className="modal-body" style={{ display: 'grid', gap: 16 }}>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
            <div>
              <label className="form-label">Name</label>
              <input className="input" value={name} onChange={(e) => setName(e.target.value)} data-testid="xtpl-name" />
            </div>
            <div>
              <label className="form-label">Dataset</label>
              <select className="input" value={dataset} onChange={(e) => setDataset(e.target.value)}
                      disabled={!isNew} data-testid="xtpl-dataset">
                <option value="">— Pick a dataset —</option>
                {Object.values(datasets).map((d) => (
                  <option key={d.key} value={d.key}>{d.label}</option>
                ))}
              </select>
            </div>
          </div>

          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 16 }}>
            <div>
              <label className="form-label">Delimiter</label>
              <select className="input" value={delimiter} onChange={(e) => setDelimiter(e.target.value)} data-testid="xtpl-delimiter">
                <option value=",">, (comma)</option>
                <option value=";">; (semicolon)</option>
                <option value="	">tab</option>
                <option value="|">| (pipe)</option>
              </select>
            </div>
            <div style={{ display: 'flex', alignItems: 'flex-end', gap: 6 }}>
              <input id="xtpl-hh" type="checkbox" checked={hasHeader} onChange={(e) => setHasHeader(e.target.checked)} data-testid="xtpl-has-header" />
              <label htmlFor="xtpl-hh" style={{ fontSize: 13 }}>Include header row</label>
            </div>
            {isMaster && (
              <div>
                <label className="form-label">Scope</label>
                <select className="input" value={scope} onChange={(e) => setScope(e.target.value)} data-testid="xtpl-scope">
                  <option value="tenant">tenant (private)</option>
                  <option value="platform">platform (visible to ALL tenants)</option>
                </select>
              </div>
            )}
          </div>

          <div style={{ background: 'var(--cf-bg-elev)', padding: 12, borderRadius: 6, border: '1px dashed var(--cf-border)' }}>
            <label style={{ fontSize: 13, fontWeight: 500, display: 'block', marginBottom: 6 }}>
              <Upload size={14} style={{ display: 'inline', marginRight: 4 }} />
              Optional — upload a sample CSV to pre-fill column mappings:
            </label>
            <input type="file" accept=".csv,text/csv"
                   onChange={(e) => onUploadSample(e.target.files?.[0])}
                   data-testid="xtpl-upload-sample" />
            <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)', marginTop: 4 }}>
              We&rsquo;ll read the first row as your header. Max 256 KB.
            </div>
          </div>

          <div>
            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 8 }}>
              <strong>Column mappings</strong>
              <button className="btn btn--ghost" onClick={addRow} data-testid="xtpl-add-row">
                <Plus size={12} /> Add column
              </button>
            </div>
            <table className="data-table" style={{ width: '100%' }}>
              <thead>
                <tr>
                  <th style={{ width: 40 }}>#</th>
                  <th style={{ textAlign: 'left' }}>Output header</th>
                  <th style={{ textAlign: 'left', width: 110 }}>Kind</th>
                  <th style={{ textAlign: 'left' }}>Source / Fixed value</th>
                  <th style={{ width: 32 }}></th>
                </tr>
              </thead>
              <tbody>
                {mappings.map((m, i) => (
                  <tr key={i} data-testid={`xtpl-row-${i}`}>
                    <td>{i + 1}</td>
                    <td>
                      <input className="input" value={m.output_header}
                             onChange={(e) => updateRow(i, { output_header: e.target.value })}
                             data-testid={`xtpl-row-${i}-header`} />
                    </td>
                    <td>
                      <select className="input" value={m.kind || 'field'}
                              onChange={(e) => updateRow(i, { kind: e.target.value })}
                              data-testid={`xtpl-row-${i}-kind`}>
                        <option value="field">field</option>
                        <option value="fixed">fixed</option>
                      </select>
                    </td>
                    <td>
                      {(m.kind || 'field') === 'fixed' ? (
                        <input className="input" placeholder="e.g. ACH"
                               value={m.fixed_value || ''}
                               onChange={(e) => updateRow(i, { fixed_value: e.target.value })}
                               data-testid={`xtpl-row-${i}-fixed-value`} />
                      ) : (
                        <select className="input" value={m.source_field || ''}
                                onChange={(e) => updateRow(i, { source_field: e.target.value })}
                                data-testid={`xtpl-row-${i}-source-field`}>
                          <option value="">— Pick source field —</option>
                          {fieldOpts.map(([k, meta]) => (
                            <option key={k} value={k}>{meta.label} ({k})</option>
                          ))}
                        </select>
                      )}
                    </td>
                    <td>
                      <button className="btn btn--ghost" onClick={() => removeRow(i)}
                              disabled={mappings.length === 1}
                              data-testid={`xtpl-row-${i}-remove`}
                              style={{ padding: 4, color: '#dc2626' }}>
                        <Trash2 size={14} />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {err && <div className="alert alert--err" data-testid="xtpl-error">{err}</div>}

          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
            <button className="btn btn--ghost" onClick={onClose}>Cancel</button>
            <button
              className="btn btn--primary"
              onClick={save}
              disabled={busy || !name || !dataset || mappings.length === 0}
              data-testid="xtpl-save"
            >
              <Save size={14} /> {busy ? 'Saving…' : 'Save template'}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

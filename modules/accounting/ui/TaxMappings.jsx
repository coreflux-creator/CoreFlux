import React, { useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';
import { Sparkles } from 'lucide-react';

const TAX_MAPPINGS_API = '/api/v1/accounting/tax-mappings';

/**
 * Tax mappings — map each postable expense / revenue account onto a
 * line of a standard tax form (Schedule C, 1120-S, etc.). Powers
 * tax-time exports.
 *
 * Workflow:
 *   1. Pick a tax form from the dropdown
 *   2. Click "AI auto-map" to seed every unmapped account at once,
 *      OR per-account: set a line + (optional) label, click Save
 *   3. Click Edit on an existing row to update or remove
 */
export default function TaxMappings() {
  const [form, setForm] = useState('');
  const url = `${TAX_MAPPINGS_API}${form ? `?tax_form_code=${encodeURIComponent(form)}` : ''}`;
  const { data, error, loading, reload } = useApi(url);

  const [draft, setDraft] = useState({});       // accountId → { line, label, notes }
  const [busy, setBusy]   = useState({});
  const [errMsg, setErr]  = useState(null);

  // AI auto-map state.
  const [aiBusy, setAiBusy]               = useState(false);
  const [aiSuggestions, setAiSuggestions] = useState([]);   // [{account_id, line, label, confidence, reasoning, ...}]
  const [aiThreshold, setAiThreshold]     = useState(0.85);
  const [aiSkipped, setAiSkipped]         = useState([]);
  const [aiModel, setAiModel]             = useState(null);
  const [bulkBusy, setBulkBusy]           = useState(false);

  const setField = (id, k, v) => setDraft(d => ({ ...d, [id]: { ...(d[id] || {}), [k]: v } }));

  const saveMapping = async (accountId) => {
    const d = draft[accountId] || {};
    if (!d.line || !d.line.trim()) { setErr('Line is required.'); return; }
    setBusy(b => ({ ...b, [accountId]: true })); setErr(null);
    try {
      await api.post(TAX_MAPPINGS_API, {
        account_id:     accountId,
        tax_form_code:  form,
        tax_form_line:  d.line.trim(),
        tax_form_label: (d.label || '').trim() || null,
        notes:          (d.notes || '').trim() || null,
      });
      setDraft(s => ({ ...s, [accountId]: undefined }));
      reload();
    } catch (e) { setErr(e.message); }
    finally    { setBusy(b => ({ ...b, [accountId]: false })); }
  };

  const removeMapping = async (id) => {
    setBusy(b => ({ ...b, [`del_${id}`]: true })); setErr(null);
    try {
      await api.delete(`${TAX_MAPPINGS_API}/${id}`);
      reload();
    } catch (e) { setErr(e.message); }
    finally    { setBusy(b => ({ ...b, [`del_${id}`]: false })); }
  };

  const runAiAutomap = async () => {
    if (!form) return;
    setAiBusy(true); setErr(null); setAiSuggestions([]); setAiSkipped([]);
    try {
      const r = await api.post('/api/tax_mapping_ai_suggest.php', { tax_form_code: form });
      setAiSuggestions(r.suggestions || []);
      setAiSkipped(r.skipped || []);
      setAiModel(r.model || null);
      // Pre-populate draft with AI suggestions so the user sees them in
      // the unmapped table even if they don't bulk-accept.
      const d = {};
      (r.suggestions || []).forEach(s => {
        d[s.account_id] = { line: s.line, label: s.label, notes: '' };
      });
      setDraft(prev => ({ ...prev, ...d }));
    } catch (e) {
      setErr(e.message);
    } finally {
      setAiBusy(false);
    }
  };

  const acceptAllAi = async () => {
    const eligible = aiSuggestions.filter(s => (s.confidence ?? 0) >= aiThreshold);
    if (!eligible.length) return;
    setBulkBusy(true); setErr(null);
    try {
      // Sequential to keep audit log readable.
      for (const s of eligible) {
        await api.post(TAX_MAPPINGS_API, {
          account_id:     s.account_id,
          tax_form_code:  form,
          tax_form_line:  s.line,
          tax_form_label: s.label || null,
          notes:          `AI auto-mapped (${Math.round((s.confidence ?? 0) * 100)}% confidence)`,
        });
      }
      const acceptedIds = new Set(eligible.map(s => s.account_id));
      setAiSuggestions(prev => prev.filter(s => !acceptedIds.has(s.account_id)));
      reload();
    } catch (e) {
      setErr(e.message);
    } finally {
      setBulkBusy(false);
    }
  };

  const aiByAccount = aiSuggestions.reduce((m, s) => { m[s.account_id] = s; return m; }, {});
  const eligibleCount = aiSuggestions.filter(s => (s.confidence ?? 0) >= aiThreshold).length;

  const forms     = data?.available_forms || [];
  const mappings  = data?.mappings || [];
  const unmapped  = data?.unmapped_accounts || [];

  return (
    <section data-testid="accounting-tax-mappings-page">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', flexWrap: 'wrap', gap: 12, marginBottom: 12 }}>
        <div>
          <h2 style={{ margin: 0 }}>Tax mappings</h2>
          <p style={{ color: '#64748b', margin: '4px 0 0', fontSize: 13 }}>
            Map each expense / revenue account to a line of a standard tax form. Used at tax time to export totals to Schedule C / 1120 / 1065.
          </p>
        </div>
        <button data-testid="accounting-tax-mappings-refresh" onClick={reload} className="btn btn--ghost" style={{ fontSize: 12 }}>Refresh</button>
      </header>

      <div style={{ display: 'flex', alignItems: 'flex-end', gap: 12, flexWrap: 'wrap', marginBottom: 14 }}>
        <label style={lbl}>
          Tax form
          <select className="input"
                  data-testid="accounting-tax-mappings-form"
                  value={form}
                  onChange={e => setForm(e.target.value)}>
            <option value="">— pick a form —</option>
            {forms.map(f => <option key={f.code} value={f.code}>{f.label}</option>)}
          </select>
        </label>
        {data?.tax_form_code && (
          <span data-testid="accounting-tax-mappings-counts" style={{ fontSize: 12, color: '#64748b' }}>
            {data.mapped_count} mapped · {data.unmapped_count} unmapped
          </span>
        )}
      </div>

      {loading && <p data-testid="accounting-tax-mappings-loading">Loading…</p>}
      {error   && <p data-testid="accounting-tax-mappings-error" className="error">Error: {error.message}</p>}
      {errMsg  && <p data-testid="accounting-tax-mappings-action-error" className="error">{errMsg}</p>}

      {!form && (
        <div data-testid="accounting-tax-mappings-empty-state" style={emptyHero}>
          Pick a tax form to start mapping accounts.
        </div>
      )}

      {form && (
        <>
          {/* AI auto-map strip */}
          <div data-testid="accounting-tax-mappings-ai-strip"
               style={{ padding: 14, background: '#faf5ff', border: '1px solid #ddd6fe', borderRadius: 10, display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap', marginBottom: 12 }}>
            <Sparkles size={18} color="#7c3aed" />
            <div style={{ flex: 1, minWidth: 280 }}>
              <strong style={{ fontSize: 13, color: '#5b21b6', display: 'block' }}>AI auto-map</strong>
              <span style={{ fontSize: 12, color: '#6d28d9' }}>
                {aiSuggestions.length === 0
                  ? 'Let AI suggest a tax-form line for every unmapped account in one pass.'
                  : `${aiSuggestions.length} suggestion${aiSuggestions.length === 1 ? '' : 's'} ready · ${eligibleCount} above threshold`}
              </span>
            </div>
            {aiSuggestions.length > 0 && (
              <label style={{ fontSize: 12, color: '#475569', display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                Threshold ≥
                <select className="input" data-testid="accounting-tax-mappings-ai-threshold"
                        value={aiThreshold} onChange={e => setAiThreshold(Number(e.target.value))}
                        style={{ padding: '2px 6px', fontSize: 12 }}>
                  <option value={0.7}>70%</option>
                  <option value={0.8}>80%</option>
                  <option value={0.85}>85%</option>
                  <option value={0.9}>90%</option>
                  <option value={0.95}>95%</option>
                </select>
              </label>
            )}
            <button className="btn btn--ghost"
                    data-testid="accounting-tax-mappings-ai-run"
                    onClick={runAiAutomap}
                    disabled={aiBusy}
                    style={{ fontSize: 12 }}>
              {aiBusy ? 'Thinking…' : (aiSuggestions.length === 0 ? 'Suggest mappings' : 'Re-run AI')}
            </button>
            {aiSuggestions.length > 0 && (
              <button className="btn btn--primary"
                      data-testid="accounting-tax-mappings-ai-accept-all"
                      onClick={acceptAllAi}
                      disabled={bulkBusy || eligibleCount === 0}
                      style={{ fontSize: 12 }}>
                {bulkBusy ? 'Saving…' : `Accept ${eligibleCount} ≥ ${Math.round(aiThreshold * 100)}%`}
              </button>
            )}
            {aiModel && (
              <span data-testid="accounting-tax-mappings-ai-model" style={{ fontSize: 10, color: '#94a3b8', flexBasis: '100%' }}>
                via {aiModel} · {aiSkipped.length > 0 && `${aiSkipped.length} skipped by AI`}
              </span>
            )}
          </div>

          <h3 style={{ margin: '20px 0 8px', fontSize: 14, color: '#0f172a' }}>Mapped accounts ({mappings.length})</h3>
          <table className="data-table" style={{ width: '100%' }} data-testid="accounting-tax-mappings-table-mapped">
            <thead>
              <tr><th>Code</th><th>Name</th><th>Type</th><th>Form line</th><th>Label</th><th>Notes</th><th></th></tr>
            </thead>
            <tbody>
              {mappings.length === 0 && (
                <tr><td colSpan={7} className="empty" data-testid="accounting-tax-mappings-mapped-empty">
                  No mappings yet for this form.
                </td></tr>
              )}
              {mappings.map(m => (
                <tr key={m.id} data-testid={`accounting-tax-mappings-mapped-row-${m.account_id}`}>
                  <td><code>{m.code}</code></td>
                  <td>{m.name}</td>
                  <td style={{ fontSize: 11, color: '#64748b' }}>{m.account_type}</td>
                  <td><code>{m.tax_form_line}</code></td>
                  <td style={{ fontSize: 12 }}>{m.tax_form_label || ''}</td>
                  <td style={{ fontSize: 12, color: '#64748b' }}>{m.notes || ''}</td>
                  <td style={{ textAlign: 'right' }}>
                    <button data-testid={`accounting-tax-mappings-delete-${m.id}`}
                            className="btn btn--ghost"
                            onClick={() => removeMapping(m.id)}
                            disabled={busy[`del_${m.id}`]}
                            style={{ fontSize: 11, color: '#dc2626' }}>
                      Remove
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          <h3 style={{ margin: '24px 0 8px', fontSize: 14, color: '#0f172a' }}>Unmapped accounts ({unmapped.length})</h3>
          <table className="data-table" style={{ width: '100%' }} data-testid="accounting-tax-mappings-table-unmapped">
            <thead>
              <tr><th>Code</th><th>Name</th><th>Type</th><th>Line *</th><th>Label</th><th>Notes</th><th></th></tr>
            </thead>
            <tbody>
              {unmapped.length === 0 && (
                <tr><td colSpan={7} className="empty" data-testid="accounting-tax-mappings-unmapped-empty">
                  All revenue + expense accounts are mapped. Nice.
                </td></tr>
              )}
              {unmapped.map(a => {
                const d = draft[a.id] || {};
                const ai = aiByAccount[a.id];
                return (
                  <tr key={a.id} data-testid={`accounting-tax-mappings-unmapped-row-${a.id}`}>
                    <td><code>{a.code}</code></td>
                    <td>
                      {a.name}
                      {ai && (
                        <span data-testid={`accounting-tax-mappings-ai-pill-${a.id}`}
                              title={ai.reasoning || ''}
                              style={{ marginLeft: 6, fontSize: 10, fontWeight: 600,
                                       padding: '1px 6px', borderRadius: 8,
                                       background: '#ede9fe',
                                       color: ai.confidence >= 0.9 ? '#059669'
                                            : ai.confidence >= 0.75 ? '#d97706' : '#dc2626' }}>
                          AI · {Math.round((ai.confidence ?? 0) * 100)}%
                        </span>
                      )}
                    </td>
                    <td style={{ fontSize: 11, color: '#64748b' }}>{a.account_type}</td>
                    <td>
                      <input className="input"
                             data-testid={`accounting-tax-mappings-line-${a.id}`}
                             value={d.line || ''}
                             onChange={e => setField(a.id, 'line', e.target.value)}
                             placeholder="e.g. 22"
                             style={{ width: 70 }} />
                    </td>
                    <td>
                      <input className="input"
                             data-testid={`accounting-tax-mappings-label-${a.id}`}
                             value={d.label || ''}
                             onChange={e => setField(a.id, 'label', e.target.value)}
                             placeholder="e.g. Supplies"
                             style={{ width: 150 }} />
                    </td>
                    <td>
                      <input className="input"
                             data-testid={`accounting-tax-mappings-notes-${a.id}`}
                             value={d.notes || ''}
                             onChange={e => setField(a.id, 'notes', e.target.value)}
                             style={{ width: 200, fontSize: 12 }} />
                    </td>
                    <td style={{ textAlign: 'right' }}>
                      <button data-testid={`accounting-tax-mappings-save-${a.id}`}
                              className="btn btn--primary"
                              disabled={busy[a.id] || !(d.line || '').trim()}
                              onClick={() => saveMapping(a.id)}
                              style={{ fontSize: 11 }}>
                        {busy[a.id] ? 'Saving…' : 'Save'}
                      </button>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </>
      )}
    </section>
  );
}

const lbl = { display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: '#475569' };
const emptyHero = { padding: 36, textAlign: 'center', color: '#64748b', background: '#f8fafc', border: '1px dashed #e2e8f0', borderRadius: 10 };

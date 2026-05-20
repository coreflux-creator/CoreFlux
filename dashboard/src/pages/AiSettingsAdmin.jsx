import React, { useEffect, useMemo, useState } from 'react';
import { Sparkles, Save, AlertCircle, CheckCircle2, FileText } from 'lucide-react';
import { api } from '../lib/api';
import { Section, StatCard } from '../components/UIComponents';

/**
 * AiSettingsAdmin — per-tenant AI controls.
 *
 * Toggles the master `tenants.ai_enabled` flag (the gate every feature
 * checks), the `ai_full_content_logging` opt-in, and per-feature-class
 * rows in `ai_tenant_features`.
 *
 * Defaults to the active tenant; a master_admin can pass any tenant id
 * via the dropdown.
 */
export default function AiSettingsAdmin({ session }) {
  const activeTenantId = session?.active_tenant_id || session?.tenant_id || null;
  const isGlobalAdmin = Boolean(session?.user?.is_global_admin);
  const role = session?.user?.role || session?.role || '';

  const [selectedTid, setSelectedTid] = useState(activeTenantId);
  const [data, setData] = useState(null);
  const [draft, setDraft] = useState(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);
  const [savedAt, setSavedAt] = useState(null);

  const tenants = useMemo(() => session?.tenants || [], [session]);

  const load = async (tid) => {
    setLoading(true); setError(null);
    try {
      const r = await api.get(`/api/admin/ai_settings.php?tenant_id=${tid}`);
      setData(r);
      setDraft({
        ai_enabled: r.ai_enabled,
        ai_full_content_logging: r.ai_full_content_logging,
        features: Object.fromEntries((r.features || []).map((f) => [f.feature_class, f.enabled])),
      });
    } catch (e) {
      setError(e.message || String(e));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (!selectedTid) return;
    load(selectedTid);
  }, [selectedTid]);

  const isDirty = useMemo(() => {
    if (!data || !draft) return false;
    if (draft.ai_enabled !== data.ai_enabled) return true;
    if (draft.ai_full_content_logging !== data.ai_full_content_logging) return true;
    const currentFeatures = Object.fromEntries((data.features || []).map((f) => [f.feature_class, f.enabled]));
    for (const k of Object.keys(draft.features || {})) {
      if (currentFeatures[k] !== draft.features[k]) return true;
    }
    return false;
  }, [data, draft]);

  const save = async () => {
    if (!draft) return;
    setSaving(true); setError(null); setSavedAt(null);
    try {
      const payload = {
        tenant_id: selectedTid,
        ai_enabled: draft.ai_enabled,
        ai_full_content_logging: draft.ai_full_content_logging,
        features: draft.features,
      };
      const r = await api.post('/api/admin/ai_settings.php', payload);
      setData(r);
      setSavedAt(new Date());
    } catch (e) {
      setError(e.message || String(e));
    } finally {
      setSaving(false);
    }
  };

  const setFeature = (cls, enabled) =>
    setDraft((d) => ({ ...d, features: { ...d.features, [cls]: enabled } }));

  if (loading && !data) {
    return <div data-testid="ai-settings-loading" style={{ padding: 'var(--cf-space-6)' }}>Loading AI settings…</div>;
  }
  if (error && !data) {
    return (
      <div data-testid="ai-settings-error" className="error" style={{ padding: 'var(--cf-space-6)' }}>
        <AlertCircle size={18} style={{ marginRight: 8, verticalAlign: 'middle' }} /> {error}
      </div>
    );
  }
  if (!data) return null;

  const featureCopy = {
    classification: { label: 'Classification', help: 'Auto-categorise bank transactions, vendor bills, expense lines.' },
    extraction:     { label: 'Extraction',     help: 'Pull structured fields out of receipts, invoices, PDFs.' },
    summary:        { label: 'Summary',        help: 'Period-end narratives, weekly digests, anomaly callouts.' },
    narrative:      { label: 'Narrative',      help: 'Long-form CFO notes on P&L variance and cash position.' },
    draft:          { label: 'Draft',          help: 'AI-drafted messages, journal-entry suggestions (human-approved before posting).' },
    deep_reasoning: { label: 'Deep reasoning', help: 'Multi-step model calls for forecasting and scenario analysis.' },
  };

  return (
    <div data-testid="ai-settings-admin" style={{ padding: 'var(--cf-space-6)', maxWidth: 880 }}>
      <div style={{ marginBottom: 'var(--cf-space-6)' }}>
        <h1 style={{ fontSize: 'var(--cf-text-2xl)', fontWeight: 700, marginBottom: 'var(--cf-space-2)', display: 'flex', alignItems: 'center', gap: 10 }}>
          <Sparkles size={26} /> AI settings
        </h1>
        <p style={{ color: 'var(--cf-text-secondary)' }}>
          Master toggle and per-feature controls for AI-powered modules. New tenants start with AI <strong>off</strong> (opt-in).
        </p>
      </div>

      {isGlobalAdmin && tenants.length > 1 && (
        <Section title="Tenant">
          <div style={{ marginBottom: 'var(--cf-space-3)' }}>
            <select
              data-testid="ai-settings-tenant-select"
              value={selectedTid || ''}
              onChange={(e) => setSelectedTid(Number(e.target.value))}
              style={{ padding: '8px 12px', borderRadius: 6, border: '1px solid var(--cf-border)', minWidth: 320 }}
            >
              {tenants.map((t) => (
                <option key={t.id || t} value={t.id || t}>{t.name || t} (#{t.id || t})</option>
              ))}
            </select>
          </div>
        </Section>
      )}

      <Section title={data.tenant_name}>
        <div className="stat-card" style={{ padding: 'var(--cf-space-5)', display: 'block' }}>
          <label data-testid="ai-settings-master-toggle-label" style={{ display: 'flex', alignItems: 'flex-start', gap: 12, cursor: 'pointer' }}>
            <input
              type="checkbox"
              data-testid="ai-settings-master-toggle"
              checked={!!draft.ai_enabled}
              onChange={(e) => setDraft((d) => ({ ...d, ai_enabled: e.target.checked }))}
              style={{ marginTop: 4, width: 18, height: 18 }}
            />
            <div>
              <div style={{ fontWeight: 600 }}>AI enabled for this tenant</div>
              <div style={{ fontSize: 13, color: 'var(--cf-text-secondary)', marginTop: 2 }}>
                Master switch. While off, every AI feature returns a "disabled" response and never calls the model provider.
              </div>
            </div>
          </label>
        </div>

        <div className="stat-card" style={{ padding: 'var(--cf-space-5)', marginTop: 'var(--cf-space-3)', display: 'block' }}>
          <label data-testid="ai-settings-full-content-logging-label" style={{ display: 'flex', alignItems: 'flex-start', gap: 12, cursor: 'pointer' }}>
            <input
              type="checkbox"
              data-testid="ai-settings-full-content-logging"
              checked={!!draft.ai_full_content_logging}
              onChange={(e) => setDraft((d) => ({ ...d, ai_full_content_logging: e.target.checked }))}
              disabled={!draft.ai_enabled}
              style={{ marginTop: 4, width: 18, height: 18 }}
            />
            <div>
              <div style={{ fontWeight: 600, display: 'flex', alignItems: 'center', gap: 8 }}>
                <FileText size={16} /> Full prompt + response logging
              </div>
              <div style={{ fontSize: 13, color: 'var(--cf-text-secondary)', marginTop: 2 }}>
                Default off. When on, every AI call stores the full prompt and response in <code>ai_interactions</code> for audit + debugging. Compliance-heavy — flip on only when you need to investigate a specific incident.
              </div>
            </div>
          </label>
        </div>
      </Section>

      <Section title="Per-feature controls">
        <div style={{ display: 'grid', gap: 'var(--cf-space-3)' }}>
          {(data.known_feature_classes || []).map((cls) => {
            const copy = featureCopy[cls] || { label: cls, help: '' };
            const checked = !!draft.features?.[cls];
            return (
              <label
                key={cls}
                className="stat-card"
                data-testid={`ai-settings-feature-${cls}`}
                style={{ padding: 'var(--cf-space-4)', display: 'flex', alignItems: 'flex-start', gap: 12, cursor: 'pointer' }}
              >
                <input
                  type="checkbox"
                  data-testid={`ai-settings-feature-${cls}-toggle`}
                  checked={checked}
                  onChange={(e) => setFeature(cls, e.target.checked)}
                  disabled={!draft.ai_enabled}
                  style={{ marginTop: 4, width: 18, height: 18 }}
                />
                <div>
                  <div style={{ fontWeight: 600 }}>{copy.label}</div>
                  <div style={{ fontSize: 13, color: 'var(--cf-text-secondary)', marginTop: 2 }}>{copy.help}</div>
                </div>
              </label>
            );
          })}
        </div>
      </Section>

      <div style={{ marginTop: 'var(--cf-space-6)', display: 'flex', alignItems: 'center', gap: 12 }}>
        <button
          data-testid="ai-settings-save-btn"
          className="btn btn--primary"
          onClick={save}
          disabled={!isDirty || saving}
          style={{ display: 'flex', alignItems: 'center', gap: 8 }}
        >
          <Save size={16} /> {saving ? 'Saving…' : 'Save settings'}
        </button>
        {savedAt && (
          <span data-testid="ai-settings-saved-indicator" style={{ color: 'var(--cf-success, #047857)', display: 'flex', alignItems: 'center', gap: 6 }}>
            <CheckCircle2 size={16} /> Saved at {savedAt.toLocaleTimeString()}
          </span>
        )}
        {error && (
          <span data-testid="ai-settings-save-error" style={{ color: 'var(--cf-danger, #b91c1c)', display: 'flex', alignItems: 'center', gap: 6 }}>
            <AlertCircle size={16} /> {error}
          </span>
        )}
      </div>
    </div>
  );
}

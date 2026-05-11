import React, { useEffect, useState } from 'react';
import { Section } from '../components/UIComponents';
import { api } from '../lib/api';

/**
 * Tenant SSO configuration — Slice 1.
 *
 * Lets a tenant admin register their IdP (Okta or Microsoft Entra/Azure AD)
 * credentials so the platform team can flip on real OIDC redirect/callback
 * in Slice 2. NO OIDC dance is wired up by this UI — it's storage only.
 *
 * Secret confirmation pattern:
 *   - On load the API returns `client_secret_last4` ("ab12") but NEVER the
 *     decrypted secret itself. UI shows "Secret on file: ••••ab12".
 *   - Leaving the secret input empty on save preserves the stored value.
 *   - "Clear secret" button blanks the column AND disables SSO for safety.
 */
export default function SsoConfigAdmin() {
  const [config, setConfig] = useState(null);
  const [canWrite, setCanWrite] = useState(false);
  const [callbackHint, setCallbackHint] = useState('');
  const [form, setForm] = useState({
    provider_type: 'okta',
    issuer_url: '',
    client_id: '',
    client_secret: '',
    allowed_email_domains: '',
    is_enabled: false,
    sso_slug: '',
    notes: '',
  });
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState(null);
  const [error, setError] = useState(null);

  const load = async () => {
    setLoading(true); setError(null);
    try {
      const data = await api.get('/api/sso_config.php');
      setCanWrite(!!data.can_write);
      setCallbackHint(data.callback_url_hint || '');
      if (data.config) {
        setConfig(data.config);
        setForm({
          provider_type:         data.config.provider_type || 'okta',
          issuer_url:            data.config.issuer_url    || '',
          client_id:             data.config.client_id     || '',
          client_secret:         '',
          allowed_email_domains: (data.config.allowed_email_domains || []).join(', '),
          is_enabled:            !!data.config.is_enabled,
          sso_slug:              data.config.sso_slug      || '',
          notes:                 data.config.notes         || '',
        });
      }
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { load(); }, []);

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true); setMsg(null); setError(null);
    try {
      const payload = {
        ...form,
        allowed_email_domains: form.allowed_email_domains
          .split(',').map((s) => s.trim()).filter(Boolean),
      };
      // Don't send client_secret if user left it blank (preserve stored secret)
      if (!payload.client_secret) delete payload.client_secret;
      await api.post('/api/sso_config.php', payload);
      setMsg('Saved');
      load();
    } catch (e) { setError(e.message); }
    finally { setBusy(false); }
  };

  const clearSecret = async () => {
    if (!confirm('Clear the stored client secret and disable SSO for this tenant?')) return;
    setBusy(true); setMsg(null); setError(null);
    try {
      await api.post('/api/sso_config.php?action=clear_secret', {});
      setMsg('Secret cleared. SSO disabled.');
      load();
    } catch (e) { setError(e.message); }
    finally { setBusy(false); }
  };

  if (loading) return <p>Loading…</p>;

  return (
    <section data-testid="admin-sso-config">
      <header style={{ marginBottom: 24 }}>
        <h1 style={{ margin: 0 }}>SSO configuration</h1>
        <p style={{ color: 'var(--cf-text-secondary)', fontSize: 13, margin: '6px 0 0' }}>
          Register your identity provider (Okta or Microsoft Entra) so your team can sign in with their work account.
          Stored creds are encrypted at rest. The real OIDC redirect/callback ships in <strong>Slice 2</strong>;
          you can stage the configuration here today so it's ready when the flow goes live.
        </p>
      </header>

      <form onSubmit={submit} style={{ display: 'flex', flexDirection: 'column', gap: 20, maxWidth: 720 }}>

        <fieldset style={{ border: '1px solid var(--cf-border, #e5e7eb)', padding: 16, borderRadius: 8 }}>
          <legend style={{ padding: '0 8px', fontSize: 13, fontWeight: 600 }}>Identity provider</legend>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 12 }}>
            <label style={{ fontSize: 12 }}>
              <span>Provider type</span>
              <select
                className="input" value={form.provider_type}
                disabled={!canWrite}
                onChange={(e) => setForm({ ...form, provider_type: e.target.value })}
                data-testid="admin-sso-provider-type"
                style={{ display: 'block', width: '100%', marginTop: 4 }}
              >
                <option value="okta">Okta</option>
                <option value="entra">Microsoft Entra (Azure AD)</option>
                <option value="generic_oidc">Generic OIDC</option>
              </select>
            </label>
            <label style={{ fontSize: 12, gridColumn: '1 / -1' }}>
              <span>Issuer / discovery base URL</span>
              <input
                className="input" type="url" value={form.issuer_url}
                disabled={!canWrite}
                placeholder={form.provider_type === 'okta'
                  ? 'https://acme.okta.com'
                  : form.provider_type === 'entra'
                    ? 'https://login.microsoftonline.com/{tenant-id}/v2.0'
                    : 'https://issuer.example.com'}
                onChange={(e) => setForm({ ...form, issuer_url: e.target.value })}
                data-testid="admin-sso-issuer-url"
                style={{ display: 'block', width: '100%', marginTop: 4 }}
              />
            </label>
            <label style={{ fontSize: 12 }}>
              <span>Client ID</span>
              <input
                className="input" value={form.client_id}
                disabled={!canWrite}
                onChange={(e) => setForm({ ...form, client_id: e.target.value })}
                data-testid="admin-sso-client-id"
                style={{ display: 'block', width: '100%', marginTop: 4 }}
              />
            </label>
            <label style={{ fontSize: 12 }}>
              <span>Client secret {config?.client_secret_last4 && <em style={{ color: 'var(--cf-text-secondary)' }}>(on file: ••••{config.client_secret_last4})</em>}</span>
              <input
                className="input" type="password"
                placeholder={config?.client_secret_last4 ? 'Leave blank to keep current' : 'Paste secret here'}
                value={form.client_secret}
                disabled={!canWrite}
                onChange={(e) => setForm({ ...form, client_secret: e.target.value })}
                data-testid="admin-sso-client-secret"
                style={{ display: 'block', width: '100%', marginTop: 4 }}
                autoComplete="new-password"
              />
            </label>
          </div>
        </fieldset>

        <fieldset style={{ border: '1px solid var(--cf-border, #e5e7eb)', padding: 16, borderRadius: 8 }}>
          <legend style={{ padding: '0 8px', fontSize: 13, fontWeight: 600 }}>Tenant routing</legend>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 12 }}>
            <label style={{ fontSize: 12 }}>
              <span>SSO slug (URL path)</span>
              <input
                className="input" value={form.sso_slug}
                disabled={!canWrite}
                placeholder="acme-corp"
                onChange={(e) => setForm({ ...form, sso_slug: e.target.value.toLowerCase() })}
                data-testid="admin-sso-slug"
                style={{ display: 'block', width: '100%', marginTop: 4 }}
              />
              <p style={{ fontSize: 11, color: 'var(--cf-text-secondary)', margin: '4px 0 0' }}>
                Final callback URL will be <code>{`/api/sso/${form.sso_slug || '{slug}'}/callback`}</code> — register this in your IdP.
              </p>
            </label>
            <label style={{ fontSize: 12, gridColumn: '1 / -1' }}>
              <span>Allowed email domains (optional, comma-separated)</span>
              <input
                className="input" value={form.allowed_email_domains}
                disabled={!canWrite}
                placeholder="acme.com, acme.co.uk"
                onChange={(e) => setForm({ ...form, allowed_email_domains: e.target.value })}
                data-testid="admin-sso-domains"
                style={{ display: 'block', width: '100%', marginTop: 4 }}
              />
              <p style={{ fontSize: 11, color: 'var(--cf-text-secondary)', margin: '4px 0 0' }}>
                Only emails ending in these domains will be auto-provisioned. Leave empty to allow everyone the IdP returns.
              </p>
            </label>
            <label style={{ fontSize: 12, gridColumn: '1 / -1' }}>
              <span>Notes</span>
              <textarea
                className="input" rows={2} value={form.notes}
                disabled={!canWrite}
                onChange={(e) => setForm({ ...form, notes: e.target.value })}
                data-testid="admin-sso-notes"
                style={{ display: 'block', width: '100%', marginTop: 4 }}
              />
            </label>
            <label style={{ fontSize: 13, gridColumn: '1 / -1', display: 'flex', alignItems: 'center', gap: 8 }}>
              <input
                type="checkbox" checked={form.is_enabled}
                disabled={!canWrite}
                onChange={(e) => setForm({ ...form, is_enabled: e.target.checked })}
                data-testid="admin-sso-enabled"
              />
              <span>Enable SSO for this tenant (Slice 2 will respect this flag at runtime)</span>
            </label>
          </div>
        </fieldset>

        {msg && <p className="success" data-testid="admin-sso-saved" style={{ color: '#065f46' }}>{msg}</p>}
        {error && <p className="error" data-testid="admin-sso-error">{error}</p>}

        <div style={{ display: 'flex', gap: 8 }}>
          <button
            type="submit" className="btn btn--primary"
            disabled={!canWrite || busy} data-testid="admin-sso-save"
          >
            {busy ? 'Saving…' : 'Save configuration'}
          </button>
          {config?.client_secret_last4 && (
            <button
              type="button" className="btn btn--ghost"
              disabled={!canWrite || busy} onClick={clearSecret}
              data-testid="admin-sso-clear-secret"
              style={{ color: '#dc2626' }}
            >
              Clear secret & disable SSO
            </button>
          )}
        </div>

        {!canWrite && (
          <p style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }} data-testid="admin-sso-readonly">
            Read-only view. Master_admin or tenant_admin role is required to change SSO configuration.
          </p>
        )}

        {callbackHint && (
          <p style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>
            <strong>Heads-up:</strong> {callbackHint}
          </p>
        )}
      </form>
    </section>
  );
}

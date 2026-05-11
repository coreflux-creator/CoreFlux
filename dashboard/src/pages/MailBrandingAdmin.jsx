import React, { useEffect, useState } from 'react';
import { api } from '../lib/api';

/**
 * Tenant mail branding admin — logo + accent + signature.
 *
 * Live preview swatch reflects the chosen accent so the admin sees the
 * actual rendered colour before saving. No real-time email preview yet
 * (deferred to a future enhancement; the digest preview pages will show
 * the rendered output as-published).
 */
export default function MailBrandingAdmin() {
  const [form, setForm] = useState({
    logo_url: '', accent_color: '#0f172a', signature_html: '', show_powered_by: true,
  });
  const [canWrite, setCanWrite] = useState(false);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    api.get('/api/tenant_mail_branding.php')
      .then((d) => {
        setCanWrite(!!d.can_write);
        if (d.branding) setForm((f) => ({ ...f, ...d.branding, logo_url: d.branding.logo_url || '', signature_html: d.branding.signature_html || '' }));
      })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, []);

  const submit = async (e) => {
    e.preventDefault(); setBusy(true); setMsg(null); setError(null);
    try {
      await api.post('/api/tenant_mail_branding.php', form);
      setMsg('Saved');
    } catch (err) { setError(err.message); }
    finally { setBusy(false); }
  };

  if (loading) return <p>Loading…</p>;

  return (
    <section data-testid="admin-mail-branding" style={{ maxWidth: 720 }}>
      <header style={{ marginBottom: 20 }}>
        <h1 style={{ margin: 0 }}>Email branding</h1>
        <p style={{ color: 'var(--cf-text-secondary)', fontSize: 13, margin: '6px 0 0' }}>
          Applied to every digest email (AR statement, Money Movement, AP weekly queue, dunning).
          Changes take effect on the next outbound send.
        </p>
      </header>

      <form onSubmit={submit} style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>

        <label style={{ fontSize: 12 }}>
          <span>Logo URL (https only, ≤ 32px tall renders crispest)</span>
          <input
            className="input" type="url" value={form.logo_url}
            disabled={!canWrite}
            placeholder="https://yourdomain.com/logo.png"
            onChange={(e) => setForm({ ...form, logo_url: e.target.value })}
            data-testid="admin-mail-branding-logo"
            style={{ display: 'block', width: '100%', marginTop: 4 }}
          />
        </label>

        <label style={{ fontSize: 12, display: 'flex', alignItems: 'center', gap: 12 }}>
          <span>Accent color</span>
          <input
            className="input" type="color" value={form.accent_color}
            disabled={!canWrite}
            onChange={(e) => setForm({ ...form, accent_color: e.target.value })}
            data-testid="admin-mail-branding-accent"
            style={{ width: 48, padding: 2, marginTop: 0 }}
          />
          <input
            className="input" type="text" value={form.accent_color}
            disabled={!canWrite}
            pattern="^#[0-9a-fA-F]{6}$"
            onChange={(e) => setForm({ ...form, accent_color: e.target.value })}
            data-testid="admin-mail-branding-accent-hex"
            style={{ width: 100 }}
          />
          <span data-testid="admin-mail-branding-accent-swatch"
                style={{ display: 'inline-block', width: 32, height: 32, borderRadius: 6, background: form.accent_color, border: '1px solid #e5e7eb' }} />
        </label>

        <label style={{ fontSize: 12 }}>
          <span>Email signature HTML (optional, ≤ 800 chars)</span>
          <textarea
            className="input" rows={3} value={form.signature_html}
            disabled={!canWrite}
            maxLength={800}
            placeholder='<strong>Acme AR Team</strong><br/>ar@acme.com · +1 (555) 123-4567'
            onChange={(e) => setForm({ ...form, signature_html: e.target.value })}
            data-testid="admin-mail-branding-signature"
            style={{ display: 'block', width: '100%', marginTop: 4, fontFamily: 'monospace', fontSize: 12 }}
          />
        </label>

        <label style={{ fontSize: 13, display: 'flex', alignItems: 'center', gap: 8 }}>
          <input
            type="checkbox" checked={!!form.show_powered_by}
            disabled={!canWrite}
            onChange={(e) => setForm({ ...form, show_powered_by: e.target.checked })}
            data-testid="admin-mail-branding-powered-by"
          />
          <span>Show "powered by CoreFlux" footer line</span>
        </label>

        {msg   && <p className="success" data-testid="admin-mail-branding-saved" style={{ color: '#065f46' }}>{msg}</p>}
        {error && <p className="error">{error}</p>}

        <button
          type="submit" className="btn btn--primary"
          disabled={!canWrite || busy} data-testid="admin-mail-branding-save"
          style={{ alignSelf: 'flex-start' }}
        >
          {busy ? 'Saving…' : 'Save branding'}
        </button>
        {!canWrite && (
          <p style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>
            Admin role required to change branding.
          </p>
        )}
      </form>
    </section>
  );
}

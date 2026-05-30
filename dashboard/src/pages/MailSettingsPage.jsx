import React, { useEffect, useState } from 'react';
import { api } from '../lib/api';
import { Section, Card } from '../components/UIComponents';
import { Mail, CheckCircle2, AlertTriangle } from 'lucide-react';
import MailConnectionsCard from './MailConnectionsCard';
import MailTestSendCard from './MailTestSendCard';

/**
 * Tenant self-service mail settings page — Model B scope:
 *   - Reply-To (any email, no DNS required)
 *   - From display-name override (e.g. "Acme Staffing Timesheets")
 *
 * Model C (custom verified From domain) will bolt on here later.
 */
const MailSettingsPage = () => {
  const [form, setForm] = useState({ reply_to: '', from_name_override: '' });
  const [platform, setPlatform] = useState(null);
  const [preview, setPreview] = useState(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);
  const [saved, setSaved] = useState(false);
  const [flash, setFlash] = useState(null);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const m365 = params.get('m365');
    if (m365 === 'connected') {
      setFlash({ kind: 'connected', email: params.get('email') });
      window.history.replaceState({}, '', window.location.pathname);
    } else if (m365 === 'error') {
      setFlash({ kind: 'error', msg: params.get('msg') });
      window.history.replaceState({}, '', window.location.pathname);
    }
  }, []);

  const load = async () => {
    setLoading(true); setError(null);
    try {
      const data = await api.get('/api/mail_settings.php');
      setForm({
        reply_to: data.reply_to ?? '',
        from_name_override: data.from_name_override ?? '',
      });
      setPlatform({
        from_email: data.platform_from_email,
        from_name:  data.platform_from_name,
      });
      setPreview(data.preview);
    } catch (e) {
      setError(e);
    } finally {
      setLoading(false);
    }
  };
  useEffect(() => { load(); }, []);

  const save = async () => {
    setSaving(true); setError(null); setSaved(false);
    try {
      await api.put('/api/mail_settings.php', {
        reply_to: form.reply_to.trim() || null,
        from_name_override: form.from_name_override.trim() || null,
      });
      setSaved(true);
      await load();
    } catch (e) {
      setError(e);
    } finally {
      setSaving(false);
    }
  };

  return (
    <div data-testid="mail-settings-page">
      <div style={{ marginBottom: 'var(--cf-space-6)' }}>
        <h1 style={{ fontSize: 'var(--cf-text-2xl)', fontWeight: 700, marginBottom: 'var(--cf-space-2)' }}>Email delivery</h1>
        <p style={{ color: 'var(--cf-text-secondary)', maxWidth: 720 }}>
          Configure how your tenant's transactional emails appear to recipients.
          Replies can be routed to any inbox you own — no DNS setup required.
        </p>
      </div>

      {loading && <p>Loading…</p>}
      {error && <p className="error" data-testid="mail-settings-error">Error: {error.message}</p>}

      {!loading && (
        <div style={{ display: 'grid', gap: 'var(--cf-space-5)', maxWidth: 720 }}>
          {/* Preview card */}
          {preview && (
            <Card>
              <h3 style={{ margin: '0 0 var(--cf-space-3)' }} data-testid="mail-settings-preview-title">Preview — how recipients will see your email</h3>
              <div style={{ background: 'var(--cf-surface-alt, #f9fafb)', padding: 'var(--cf-space-3)', borderRadius: 8, fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace', fontSize: 13 }}>
                <div data-testid="mail-settings-preview-from"><span style={{ color: 'var(--cf-text-muted, #6b7280)' }}>From:     </span>{preview.display || '—'}</div>
                <div data-testid="mail-settings-preview-reply">
                  <span style={{ color: 'var(--cf-text-muted, #6b7280)' }}>Reply-To: </span>
                  {preview.reply_to
                    ? <span>{preview.reply_to}</span>
                    : <span style={{ color: 'var(--cf-danger, #b91c1c)' }}>— not set (replies will bounce to the platform inbox)</span>}
                </div>
              </div>
            </Card>
          )}

          {/* Reply-To card */}
          <Card>
            <div style={{ display: 'flex', gap: 'var(--cf-space-4)', alignItems: 'flex-start' }}>
              <div style={{ width: 40, height: 40, borderRadius: 8, background: 'var(--cf-blue-bg, #eff6ff)', color: 'var(--cf-blue, #1d4ed8)', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
                <Mail size={20} />
              </div>
              <div style={{ flex: 1 }}>
                <h3 style={{ margin: '0 0 4px' }}>Reply-To address</h3>
                <p style={{ margin: '0 0 var(--cf-space-3)', color: 'var(--cf-text-secondary)', fontSize: 14 }}>
                  When a client approver clicks "Reply" on an email from CoreFlux,
                  their message will go to this inbox. Any valid email — no DNS
                  setup required. Example: <code>approvals@your-agency.com</code>.
                </p>
                <input
                  type="email"
                  className="input"
                  placeholder="approvals@your-agency.com"
                  value={form.reply_to}
                  onChange={(e) => setForm(f => ({ ...f, reply_to: e.target.value }))}
                  data-testid="mail-settings-reply-to"
                  style={{ width: '100%', maxWidth: 360 }}
                />
              </div>
            </div>
          </Card>

          {/* From display name card */}
          <Card>
            <div style={{ display: 'flex', gap: 'var(--cf-space-4)', alignItems: 'flex-start' }}>
              <div style={{ width: 40, height: 40, borderRadius: 8, background: 'var(--cf-purple-bg, #faf5ff)', color: 'var(--cf-purple, #6d28d9)', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
                <Mail size={20} />
              </div>
              <div style={{ flex: 1 }}>
                <h3 style={{ margin: '0 0 4px' }}>Sender display name</h3>
                <p style={{ margin: '0 0 var(--cf-space-3)', color: 'var(--cf-text-secondary)', fontSize: 14 }}>
                  The name shown next to the email address in the recipient's inbox.
                  The address itself stays as <code>{platform?.from_email || '(not configured)'}</code> until you verify
                  your own domain in Resend. Example: <em>"Acme Staffing Timesheets"</em>.
                </p>
                <input
                  type="text"
                  className="input"
                  placeholder="Your Company Name"
                  maxLength={120}
                  value={form.from_name_override}
                  onChange={(e) => setForm(f => ({ ...f, from_name_override: e.target.value }))}
                  data-testid="mail-settings-from-name"
                  style={{ width: '100%', maxWidth: 360 }}
                />
              </div>
            </div>
          </Card>

          {/* Future Model C teaser */}
          <Card>
            <div style={{ display: 'flex', gap: 'var(--cf-space-4)', alignItems: 'flex-start', opacity: 0.7 }}>
              <div style={{ width: 40, height: 40, borderRadius: 8, background: 'var(--cf-amber-bg, #fffbeb)', color: 'var(--cf-amber, #b45309)', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
                <AlertTriangle size={20} />
              </div>
              <div>
                <h3 style={{ margin: '0 0 4px' }}>Custom sending domain <span className="badge badge--info" style={{ marginLeft: 8, fontSize: 11 }}>coming soon</span></h3>
                <p style={{ margin: 0, color: 'var(--cf-text-secondary)', fontSize: 14 }}>
                  Want emails to actually come FROM your own domain (e.g.
                  <code> timesheets@your-agency.com</code>)? That requires a one-time
                  DNS setup. We'll guide you through it when this feature ships.
                </p>
              </div>
            </div>
          </Card>

          {/* Inbound mailbox connections (Phase B Slice 2a) */}
          <MailConnectionsCard flash={flash} />

          {/* Slice 3.2 — Live confidence check for outbound mail. */}
          <MailTestSendCard />

          {saved && (
            <div data-testid="mail-settings-saved" style={{ padding: 'var(--cf-space-3)', background: '#ecfdf5', border: '1px solid #a7f3d0', color: '#047857', borderRadius: 8, display: 'flex', alignItems: 'center', gap: 8 }}>
              <CheckCircle2 size={18} /> Saved. New emails will use these settings immediately.
            </div>
          )}

          <div style={{ display: 'flex', gap: 'var(--cf-space-3)' }}>
            <button className="btn btn--primary" onClick={save} disabled={saving} data-testid="mail-settings-save">
              {saving ? 'Saving…' : 'Save changes'}
            </button>
            <button className="btn btn--ghost" onClick={load} disabled={saving} data-testid="mail-settings-reload">Reset</button>
          </div>
        </div>
      )}
    </div>
  );
};

export default MailSettingsPage;

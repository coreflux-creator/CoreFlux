import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../lib/api';
import { Section, Card } from '../components/UIComponents';
import { Bell, ChevronLeft, Check, X, Save, AlertTriangle, Info } from 'lucide-react';

/**
 * NotificationSendersPage — per-purpose mail sender overrides.
 *
 * Mounted at /settings/notifications. Lets tenant admins customise the
 * display name + Reply-To address for each outgoing-email purpose
 * (Timesheets, AP, Vendor Portal, CFO, Payments) and toggle a
 * purpose-level mute. From address is always the platform
 * RESEND_FROM_EMAIL (Model B).
 */
const NotificationSendersPage = () => {
  const [purposes, setPurposes] = useState([]);
  const [platform, setPlatform] = useState(null);
  const [drafts, setDrafts]   = useState({});      // { [purposeKey]: { from_name, reply_to, enabled } }
  const [saving, setSaving]   = useState({});      // { [purposeKey]: true }
  const [flash, setFlash]     = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState(null);

  const load = async () => {
    setLoading(true); setError(null);
    try {
      const data = await api.get('/api/admin/mail_senders.php');
      setPurposes(data.purposes || []);
      setPlatform(data.platform || null);
      // Seed drafts from current resolved view (so empty form fields show derived default as placeholder).
      const seeded = {};
      (data.purposes || []).forEach((p) => {
        seeded[p.key] = {
          from_name: p.override?.from_name ?? '',
          reply_to:  p.override?.reply_to  ?? '',
          enabled:   p.override?.enabled === false ? false : true,
        };
      });
      setDrafts(seeded);
    } catch (e) {
      setError(e);
    } finally {
      setLoading(false);
    }
  };
  useEffect(() => { load(); }, []);

  const updateDraft = (key, field, value) => {
    setDrafts((d) => ({ ...d, [key]: { ...d[key], [field]: value } }));
  };

  const save = async (purpose) => {
    setSaving((s) => ({ ...s, [purpose.key]: true })); setFlash(null);
    try {
      const d = drafts[purpose.key] || {};
      await api.post('/api/admin/mail_senders.php', {
        purpose:   purpose.key,
        from_name: d.from_name === '' ? null : d.from_name,
        reply_to:  d.reply_to  === '' ? null : d.reply_to,
        enabled:   d.enabled !== false,
      });
      setFlash({ kind: 'success', msg: `${purpose.label} sender saved.` });
      await load();
    } catch (e) {
      setFlash({ kind: 'error', msg: e.message || String(e) });
    } finally {
      setSaving((s) => ({ ...s, [purpose.key]: false }));
    }
  };

  const reset = async (purpose) => {
    if (!window.confirm(`Reset ${purpose.label} to defaults? The tenant-wide and platform fallbacks will apply.`)) return;
    setSaving((s) => ({ ...s, [purpose.key]: true })); setFlash(null);
    try {
      await api.delete(`/api/admin/mail_senders.php?purpose=${encodeURIComponent(purpose.key)}`);
      setFlash({ kind: 'success', msg: `${purpose.label} reset to defaults.` });
      await load();
    } catch (e) {
      setFlash({ kind: 'error', msg: e.message || String(e) });
    } finally {
      setSaving((s) => ({ ...s, [purpose.key]: false }));
    }
  };

  if (loading) return <div data-testid="notif-senders-loading">Loading notification settings…</div>;
  if (error)   return <div data-testid="notif-senders-error" style={{ color: 'var(--cf-red, #b91c1c)' }}>Failed to load: {error.message || String(error)}</div>;

  const platformConfigured = !!platform?.from_email;

  return (
    <div data-testid="notification-senders-page" style={{ maxWidth: 920 }}>
      <div style={{ marginBottom: 16 }}>
        <Link to="/settings" style={{ color: 'var(--cf-text-secondary)', fontSize: 13, textDecoration: 'none' }} data-testid="notif-senders-back">
          <ChevronLeft size={14} style={{ verticalAlign: 'middle' }} /> Back to Settings
        </Link>
      </div>
      <header style={{ marginBottom: 24 }}>
        <h1 style={{ fontSize: 'var(--cf-text-2xl)', fontWeight: 700, marginBottom: 'var(--cf-space-2)' }}>
          <Bell size={20} style={{ verticalAlign: 'middle', marginRight: 8 }} />
          Notification senders
        </h1>
        <p style={{ color: 'var(--cf-text-secondary)', fontSize: 13 }}>
          Customise the display name and reply-to address per email purpose. Toggle off to mute an entire category.
          The sending domain stays as <code data-testid="notif-platform-from">{platform?.from_email || 'not configured'}</code>
          {!platformConfigured && (
            <span style={{ color: 'var(--cf-orange, #c2410c)', marginLeft: 6 }} data-testid="notif-platform-warn">
              <AlertTriangle size={12} style={{ verticalAlign: 'middle', marginRight: 4 }} />
              RESEND_FROM_EMAIL not set — sends will fail until the platform key is configured.
            </span>
          )}
          .
        </p>
      </header>

      {flash && (
        <div
          data-testid={`notif-senders-flash-${flash.kind}`}
          style={{
            padding: '10px 14px', borderRadius: 6, marginBottom: 16,
            background: flash.kind === 'success' ? 'var(--cf-green-bg, #ecfdf5)' : 'var(--cf-red-bg, #fef2f2)',
            color:      flash.kind === 'success' ? 'var(--cf-green, #047857)'    : 'var(--cf-red, #b91c1c)',
            fontSize: 13,
          }}
        >
          {flash.msg}
        </div>
      )}

      <div style={{ display: 'grid', gap: 16 }}>
        {purposes.map((p) => {
          const draft     = drafts[p.key] || { from_name: '', reply_to: '', enabled: true };
          const overridden= !!p.override;
          const muted     = draft.enabled === false;
          const display   = p.resolved?.display || '—';

          return (
            <Card key={p.key} data-testid={`notif-purpose-${p.key}`}>
              <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 16, marginBottom: 14 }}>
                <div>
                  <div style={{ fontSize: 15, fontWeight: 600 }}>
                    {p.label}
                    {overridden && (
                      <span data-testid={`notif-${p.key}-override-badge`} style={{ marginLeft: 8, fontSize: 11, padding: '2px 6px', borderRadius: 4, background: 'var(--cf-blue-bg, #dbeafe)', color: 'var(--cf-blue, #1d4ed8)' }}>
                        custom
                      </span>
                    )}
                    {muted && (
                      <span data-testid={`notif-${p.key}-muted-badge`} style={{ marginLeft: 8, fontSize: 11, padding: '2px 6px', borderRadius: 4, background: 'var(--cf-red-bg, #fee2e2)', color: 'var(--cf-red, #b91c1c)' }}>
                        muted
                      </span>
                    )}
                  </div>
                  <div style={{ fontSize: 12, color: 'var(--cf-text-secondary)', marginTop: 4 }}>{p.description}</div>
                </div>
                <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12, cursor: 'pointer' }}>
                  <input
                    type="checkbox"
                    checked={draft.enabled !== false}
                    onChange={(e) => updateDraft(p.key, 'enabled', e.target.checked)}
                    data-testid={`notif-${p.key}-enabled`}
                  />
                  <span>{draft.enabled !== false ? 'Sending enabled' : 'Sending muted'}</span>
                </label>
              </div>

              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 12, marginBottom: 12 }}>
                <div>
                  <label style={{ fontSize: 12, color: 'var(--cf-text-secondary)', fontWeight: 500 }}>
                    Display name
                  </label>
                  <input
                    type="text"
                    data-testid={`notif-${p.key}-from-name`}
                    value={draft.from_name}
                    onChange={(e) => updateDraft(p.key, 'from_name', e.target.value)}
                    placeholder={`e.g. ${p.resolved?.from_name || 'Acme Staffing ' + p.label}`}
                    maxLength={120}
                    style={{ width: '100%', marginTop: 4, padding: '8px 10px', borderRadius: 6, border: '1px solid var(--cf-border)', fontSize: 13 }}
                  />
                  <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)', marginTop: 4 }}>
                    Leave empty to use the derived default (<em>{p.resolved?.from_name || 'Tenant ' + p.label}</em>).
                  </div>
                </div>
                <div>
                  <label style={{ fontSize: 12, color: 'var(--cf-text-secondary)', fontWeight: 500 }}>
                    Reply-To address
                  </label>
                  <input
                    type="email"
                    data-testid={`notif-${p.key}-reply-to`}
                    value={draft.reply_to}
                    onChange={(e) => updateDraft(p.key, 'reply_to', e.target.value)}
                    placeholder="replies@yourdomain.com (optional)"
                    maxLength={255}
                    style={{ width: '100%', marginTop: 4, padding: '8px 10px', borderRadius: 6, border: '1px solid var(--cf-border)', fontSize: 13 }}
                  />
                  <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)', marginTop: 4 }}>
                    Where recipient replies should land. Leave empty for no Reply-To header.
                  </div>
                </div>
              </div>

              <div data-testid={`notif-${p.key}-preview`} style={{ background: 'var(--cf-border-muted, #f8fafc)', padding: '10px 12px', borderRadius: 6, fontSize: 12, marginBottom: 12 }}>
                <Info size={11} style={{ verticalAlign: 'middle', marginRight: 6, color: 'var(--cf-blue, #2563eb)' }} />
                <strong>Current effective sender:</strong>{' '}
                <code style={{ fontFamily: 'var(--cf-mono, ui-monospace)' }}>{display}</code>
                {p.resolved?.reply_to && (
                  <> · Reply-To: <code style={{ fontFamily: 'var(--cf-mono, ui-monospace)' }}>{p.resolved.reply_to}</code></>
                )}
                {' · '}
                <span style={{ color: 'var(--cf-text-secondary)' }}>source: {p.resolved?.source || 'platform'}</span>
              </div>

              <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
                {overridden && (
                  <button
                    type="button"
                    className="btn"
                    onClick={() => reset(p)}
                    disabled={!!saving[p.key]}
                    data-testid={`notif-${p.key}-reset`}
                  >
                    <X size={12} style={{ marginRight: 4 }} />
                    Reset to default
                  </button>
                )}
                <button
                  type="button"
                  className="btn btn--primary"
                  onClick={() => save(p)}
                  disabled={!!saving[p.key]}
                  data-testid={`notif-${p.key}-save`}
                >
                  <Save size={12} style={{ marginRight: 4 }} />
                  {saving[p.key] ? 'Saving…' : 'Save'}
                </button>
              </div>
            </Card>
          );
        })}
      </div>
    </div>
  );
};

export default NotificationSendersPage;

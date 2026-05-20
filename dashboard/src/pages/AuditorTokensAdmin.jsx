import React, { useState, useEffect, useCallback } from 'react';
import { Plus, Copy, Trash2, X, Check, ShieldOff, ExternalLink, Activity, ChevronDown, ChevronUp } from 'lucide-react';
import { api, useApi } from '../lib/api';
import { Card } from '../components/UIComponents';

/**
 * AuditorTokensAdmin — issue, list, revoke external auditor links.
 *
 * Master_admin sees a tenant filter at the top. Tenant_admins only see
 * their own tenant's tokens (backend enforces).
 *
 * Critical UX: the plain token is shown EXACTLY ONCE, right after
 * creation, in a modal with a "Copy link" button. After dismissing the
 * modal it's never recoverable — by design (the DB only stores sha256).
 */
const DEFAULT_MODULES = ['reports', 'accounting', 'cfo', 'ap', 'billing', 'treasury'];

export default function AuditorTokensAdmin({ session }) {
  const isMaster = session?.user?.global_role === 'master_admin' || session?.user?.is_global_admin;
  const activeTid = session?.tenant_id ?? null;
  const [tenantFilter, setTenantFilter] = useState(activeTid || '');
  const url = `/api/admin/auditor_tokens.php${tenantFilter ? `?tenant_id=${tenantFilter}` : ''}`;
  const { data, loading, error, reload } = useApi(url);

  const [creating, setCreating]   = useState(false);
  const [revealing, setRevealing] = useState(null); // { token, url, expires_at, label }
  const [expandedLogId, setExpandedLogId] = useState(null); // token id whose log is open

  const tokens = data?.tokens || [];
  const activeCount  = data?.active_count  ?? 0;
  const expiredCount = data?.expired_count ?? 0;

  const onRevoke = async (tk) => {
    if (!confirm(`Revoke "${tk.label}"? This invalidates the link immediately.`)) return;
    try { await api.patch(`/api/admin/auditor_tokens.php?id=${tk.id}&action=revoke`, {}); reload(); }
    catch (e) { alert(e.message || 'Revoke failed'); }
  };
  const onDelete = async (tk) => {
    if (!confirm(`Delete "${tk.label}"? The audit log is retained.`)) return;
    try { await api.delete(`/api/admin/auditor_tokens.php?id=${tk.id}`); reload(); }
    catch (e) { alert(e.message || 'Delete failed'); }
  };

  return (
    <div data-testid="auditor-tokens-admin">
      <div style={{ display: 'flex', justifyContent: 'space-between',
                    alignItems: 'center', marginBottom: 'var(--cf-space-6)' }}>
        <div>
          <h1 style={{ fontSize: 'var(--cf-text-2xl)', fontWeight: 700, marginBottom: 4 }}>
            External Auditor Links
          </h1>
          <p style={{ color: 'var(--cf-text-secondary)', fontSize: 14 }}>
            Issue revocable, time-limited, read-only access links for external
            auditors. The token is shown once at creation — store it somewhere
            safe (e.g. your password manager) before sharing.
          </p>
        </div>
        <button className="btn btn--primary"
                onClick={() => setCreating(true)}
                data-testid="auditor-new-btn">
          <Plus size={16} /> New auditor link
        </button>
      </div>

      <div style={{ display: 'flex', gap: 16, marginBottom: 12, fontSize: 13,
                    color: 'var(--cf-text-secondary)' }}>
        <span>Active: <strong style={{ color: '#15803d' }}>{activeCount}</strong></span>
        <span>Expired / revoked: <strong>{expiredCount}</strong></span>
      </div>

      {loading && <Card><p>Loading…</p></Card>}
      {error && <Card><p style={{ color: '#b91c1c' }}>{error.message || String(error)}</p></Card>}

      {!loading && !error && (
        <Card>
          <table className="data-table" data-testid="auditor-tokens-table">
            <thead>
              <tr>
                <th>Label</th>
                <th>Tenant</th>
                <th>Email</th>
                <th>Expires</th>
                <th>Last used</th>
                <th>Status</th>
                <th style={{ textAlign: 'right' }}>Actions</th>
              </tr>
            </thead>
            <tbody>
              {tokens.map(t => (
                <React.Fragment key={t.id}>
                <tr data-testid={`auditor-row-${t.id}`}>
                  <td style={{ fontWeight: 500 }}>{t.label}</td>
                  <td>{t.tenant_name}</td>
                  <td style={{ color: 'var(--cf-text-secondary)' }}>{t.email || '—'}</td>
                  <td style={{ fontSize: 12 }}>{t.expires_at?.replace('T',' ').slice(0,16) || ''}</td>
                  <td style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>
                    {t.last_used_at?.replace('T',' ').slice(0,16) || 'never'}
                  </td>
                  <td>
                    {t.revoked_at
                      ? <span className="badge badge--muted">Revoked</span>
                      : t.is_active
                        ? <span className="badge badge--success">Active</span>
                        : <span className="badge badge--warning">Expired</span>}
                  </td>
                  <td style={{ textAlign: 'right' }}>
                    <button className="btn btn--ghost"
                            onClick={() => setExpandedLogId(expandedLogId === t.id ? null : t.id)}
                            data-testid={`auditor-activity-${t.id}`}
                            title={expandedLogId === t.id ? 'Hide activity' : 'Show activity'}>
                      <Activity size={14} />
                      {expandedLogId === t.id ? <ChevronUp size={12} /> : <ChevronDown size={12} />}
                    </button>
                    {!t.revoked_at && t.is_active && (
                      <button className="btn btn--ghost"
                              onClick={() => onRevoke(t)}
                              data-testid={`auditor-revoke-${t.id}`}
                              title="Revoke link" style={{ color: '#b45309' }}>
                        <ShieldOff size={14} />
                      </button>
                    )}
                    <button className="btn btn--ghost"
                            onClick={() => onDelete(t)}
                            data-testid={`auditor-delete-${t.id}`}
                            title="Delete row" style={{ color: '#b91c1c' }}>
                      <Trash2 size={14} />
                    </button>
                  </td>
                </tr>
                {expandedLogId === t.id && (
                  <tr data-testid={`auditor-log-row-${t.id}`}>
                    <td colSpan={7} style={{ background: '#f9fafb', padding: 16 }}>
                      <SessionLogPanel tokenId={t.id} />
                    </td>
                  </tr>
                )}
                </React.Fragment>
              ))}
              {tokens.length === 0 && (
                <tr><td colSpan={7} style={{ textAlign: 'center', padding: 24,
                                             color: 'var(--cf-text-secondary)' }}>
                  No auditor links yet. Click "New auditor link" to issue one.
                </td></tr>
              )}
            </tbody>
          </table>
        </Card>
      )}

      {creating && (
        <NewAuditorTokenModal
          isMaster={isMaster}
          activeTid={activeTid}
          tenants={session?.tenants || []}
          onClose={() => setCreating(false)}
          onCreated={(payload) => { setCreating(false); setRevealing(payload); reload(); }}
        />
      )}
      {revealing && (
        <RevealTokenModal payload={revealing} onClose={() => setRevealing(null)} />
      )}
    </div>
  );
}

function NewAuditorTokenModal({ isMaster, activeTid, tenants, onClose, onCreated }) {
  const [form, setForm] = useState({
    tenant_id: activeTid || (tenants?.[0]?.id ?? 0),
    label: '',
    email: '',
    days: 7,
    scope_modules: DEFAULT_MODULES,
  });
  const [busy, setBusy] = useState(false);
  const [err, setErr]   = useState(null);
  const setF = (k, v) => setForm(f => ({ ...f, [k]: v }));

  const onSave = async (e) => {
    e.preventDefault();
    if (!form.label) { setErr('Label is required'); return; }
    setBusy(true); setErr(null);
    try {
      const r = await api.post('/api/admin/auditor_tokens.php', form);
      onCreated({ ...r, label: form.label });
    } catch (e) { setErr(e?.message || 'Create failed'); }
    finally { setBusy(false); }
  };

  return (
    <div className="modal-backdrop" onClick={onClose} data-testid="auditor-new-modal">
      <div className="modal" onClick={(e) => e.stopPropagation()} style={{ maxWidth: 520 }}>
        <div className="modal-header">
          <h3>New auditor link</h3>
          <button className="btn btn--ghost" onClick={onClose}><X size={16} /></button>
        </div>
        <form onSubmit={onSave}>
          <div className="modal-body" style={{ display: 'grid', gap: 12 }}>
            {isMaster && (
              <label>
                <div className="label">Tenant</div>
                <select className="input" value={form.tenant_id}
                        onChange={(e) => setF('tenant_id', Number(e.target.value))}
                        data-testid="auditor-new-tenant">
                  {tenants.map(t => (
                    <option key={t.id} value={t.id}>{t.name}</option>
                  ))}
                </select>
              </label>
            )}
            <label>
              <div className="label">Label *</div>
              <input className="input" value={form.label}
                     onChange={(e) => setF('label', e.target.value)}
                     placeholder="e.g. Deloitte 2025 audit"
                     data-testid="auditor-new-label" />
            </label>
            <label>
              <div className="label">Auditor email (optional, for your records)</div>
              <input className="input" type="email" value={form.email}
                     onChange={(e) => setF('email', e.target.value)}
                     placeholder="auditor@example.com" />
            </label>
            <label>
              <div className="label">Valid for (1–90 days)</div>
              <input className="input" type="number" min="1" max="90"
                     value={form.days}
                     onChange={(e) => setF('days', Math.max(1, Math.min(90, Number(e.target.value) || 7)))}
                     data-testid="auditor-new-days" />
            </label>
            <div>
              <div className="label">Modules visible</div>
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
                {DEFAULT_MODULES.map(m => {
                  const on = form.scope_modules.includes(m);
                  return (
                    <button key={m} type="button"
                            onClick={() => setF('scope_modules',
                              on ? form.scope_modules.filter(x => x !== m)
                                 : [...form.scope_modules, m])}
                            className={`btn ${on ? 'btn--primary' : 'btn--ghost'}`}
                            style={{ padding: '4px 10px', fontSize: 12 }}>
                      {m}
                    </button>
                  );
                })}
              </div>
            </div>
            {err && <div style={{ color: '#b91c1c', fontSize: 13 }}>{err}</div>}
          </div>
          <div className="modal-footer">
            <button type="button" className="btn btn--ghost" onClick={onClose}>Cancel</button>
            <button type="submit" className="btn btn--primary" disabled={busy}
                    data-testid="auditor-new-save">
              {busy ? 'Issuing…' : 'Issue link'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

function SessionLogPanel({ tokenId }) {
  const { data, loading, error } = useApi(`/api/admin/auditor_tokens.php?action=log&id=${tokenId}`);
  if (loading) return <div style={{ fontSize: 13, color: '#6b7280' }}>Loading activity…</div>;
  if (error)   return <div style={{ fontSize: 13, color: '#b91c1c' }}
                           data-testid={`auditor-log-error-${tokenId}`}>
                       {error?.message || String(error)}
                     </div>;
  if (!data)   return null;
  const { stats = {}, top_paths = [], events = [] } = data;

  return (
    <div data-testid={`auditor-log-panel-${tokenId}`}>
      {/* Stats strip */}
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 24, marginBottom: 16,
                    fontSize: 13, color: '#374151' }}
           data-testid={`auditor-log-stats-${tokenId}`}>
        <Stat label="Total events"     value={stats.hits ?? 0} />
        <Stat label="Page views"       value={stats.views ?? 0} />
        <Stat label="Redeems"          value={stats.redeems ?? 0} />
        <Stat label="Distinct pages"   value={stats.unique_paths ?? 0} />
        <Stat label="Distinct IPs"     value={stats.unique_ips ?? 0} />
        <Stat label="First seen"
              value={stats.first_seen?.replace('T',' ').slice(0,16) || '—'} mono />
        <Stat label="Last seen"
              value={stats.last_seen?.replace('T',' ').slice(0,16) || '—'} mono />
      </div>

      {/* Two-column layout: top pages + event list */}
      <div style={{ display: 'grid', gridTemplateColumns: 'minmax(0,1fr) minmax(0,1.4fr)',
                    gap: 24, alignItems: 'flex-start' }}>
        <div>
          <div style={{ fontSize: 11, fontWeight: 600, color: '#6b7280',
                        textTransform: 'uppercase', letterSpacing: 0.4,
                        marginBottom: 6 }}>
            Most-visited pages
          </div>
          {top_paths.length === 0 && (
            <div style={{ fontSize: 13, color: '#6b7280', padding: 8 }}>
              No page views yet — auditor hasn't opened any pages.
            </div>
          )}
          {top_paths.length > 0 && (
            <table className="data-table" data-testid={`auditor-log-top-${tokenId}`}>
              <thead>
                <tr><th>Path</th><th style={{ textAlign: 'right' }}>Hits</th><th>Last</th></tr>
              </thead>
              <tbody>
                {top_paths.map((p, i) => (
                  <tr key={i}>
                    <td style={{ fontFamily: 'monospace', fontSize: 12 }}>{p.path}</td>
                    <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{p.hits}</td>
                    <td style={{ fontSize: 11, color: '#6b7280' }}>
                      {p.last_seen?.replace('T',' ').slice(11,16) || ''}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>

        <div>
          <div style={{ fontSize: 11, fontWeight: 600, color: '#6b7280',
                        textTransform: 'uppercase', letterSpacing: 0.4,
                        marginBottom: 6 }}>
            Event log (most recent 200)
          </div>
          <div style={{ maxHeight: 320, overflowY: 'auto', border: '1px solid #e5e7eb',
                        borderRadius: 6, background: '#fff' }}
               data-testid={`auditor-log-events-${tokenId}`}>
            {events.length === 0 && (
              <div style={{ fontSize: 13, color: '#6b7280', padding: 12 }}>
                No events recorded yet.
              </div>
            )}
            {events.map(ev => (
              <div key={ev.id}
                   style={{ display: 'flex', gap: 8, alignItems: 'baseline',
                            padding: '6px 10px',
                            borderBottom: '1px solid #f3f4f6',
                            fontSize: 12 }}>
                <span style={{ color: '#6b7280', fontVariantNumeric: 'tabular-nums',
                               flexShrink: 0, width: 130 }}>
                  {ev.occurred_at?.replace('T',' ').slice(0,16)}
                </span>
                <span style={{ flexShrink: 0, padding: '1px 6px',
                               borderRadius: 4, fontSize: 10, fontWeight: 600,
                               textTransform: 'uppercase',
                               background: ev.action === 'redeem' ? '#dcfce7' :
                                           ev.action === 'view'   ? '#dbeafe' :
                                                                    '#fee2e2',
                               color:      ev.action === 'redeem' ? '#15803d' :
                                           ev.action === 'view'   ? '#1d4ed8' :
                                                                    '#b91c1c' }}>
                  {ev.action}
                </span>
                <span style={{ fontFamily: 'monospace',
                               flex: 1, minWidth: 0,
                               overflow: 'hidden', textOverflow: 'ellipsis',
                               whiteSpace: 'nowrap',
                               color: '#374151' }}
                      title={ev.path || ''}>
                  {ev.path || '—'}
                </span>
                <span style={{ color: '#9ca3af', fontSize: 11, flexShrink: 0 }}
                      title={ev.user_agent || ''}>
                  {ev.ip || ''}
                </span>
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}

function Stat({ label, value, mono }) {
  return (
    <div>
      <div style={{ fontSize: 11, fontWeight: 600, color: '#6b7280',
                    textTransform: 'uppercase', letterSpacing: 0.4 }}>
        {label}
      </div>
      <div style={{ fontSize: 18, fontWeight: 700,
                    fontFamily: mono ? 'monospace' : undefined,
                    fontVariantNumeric: 'tabular-nums' }}>
        {value}
      </div>
    </div>
  );
}

function RevealTokenModal({ payload, onClose }) {
  const [copied, setCopied] = useState(false);
  const fullUrl = `${window.location.origin}${payload.url}`;
  const onCopy = async () => {
    try {
      await navigator.clipboard.writeText(fullUrl);
      setCopied(true); setTimeout(() => setCopied(false), 1500);
    } catch (_) { /* clipboard blocked */ }
  };
  return (
    <div className="modal-backdrop" data-testid="auditor-reveal-modal">
      <div className="modal" onClick={(e) => e.stopPropagation()} style={{ maxWidth: 540 }}>
        <div className="modal-header">
          <h3>✅ Auditor link ready</h3>
          <button className="btn btn--ghost" onClick={onClose}><X size={16} /></button>
        </div>
        <div className="modal-body">
          <p style={{ fontSize: 13, color: 'var(--cf-text-secondary)', marginBottom: 12 }}>
            <strong>Copy this link now</strong> — it won't be shown again.
            Anyone with the link can view <strong>{payload.label}</strong>
            until <strong>{payload.expires_at?.replace('T',' ').slice(0,16)}</strong>.
            You can revoke it at any time.
          </p>
          <div style={{ display: 'flex', gap: 8, alignItems: 'stretch',
                        background: 'var(--cf-bg-muted, #f5f5f5)',
                        padding: '10px 12px', borderRadius: 8 }}>
            <input className="input" readOnly value={fullUrl}
                   style={{ flex: 1, fontFamily: 'monospace', fontSize: 12 }}
                   data-testid="auditor-reveal-url"
                   onClick={(e) => e.target.select()} />
            <button className="btn btn--primary"
                    onClick={onCopy}
                    data-testid="auditor-reveal-copy">
              {copied ? <Check size={14} /> : <Copy size={14} />}
              {copied ? ' Copied' : ' Copy'}
            </button>
            <a className="btn btn--ghost" href={payload.url} target="_blank" rel="noreferrer"
               title="Preview as auditor (read-only)">
              <ExternalLink size={14} />
            </a>
          </div>
        </div>
        <div className="modal-footer">
          <button className="btn btn--primary" onClick={onClose}
                  data-testid="auditor-reveal-done">
            I've copied it — done
          </button>
        </div>
      </div>
    </div>
  );
}

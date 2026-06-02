import React, { useEffect, useMemo, useState } from 'react';
import { api } from '../lib/api';
import { Card } from '../components/UIComponents';
import { Building2, Plus, X, Save, Trash2, RefreshCw, Users } from 'lucide-react';

/**
 * CpaFirmClientsAdmin — firm-side admin surface for the
 * cpa_firm_client_links table.
 *
 * Operator picks the active tenant FIRST (the firm tenant), then opens
 * this page from /admin/cpa-clients. The endpoint reads the active
 * tenant out of the session as the firm side, so this page implicitly
 * scopes itself to "manage MY firm's client list".
 *
 * Beyond CRUD, the create modal includes a bulk-seat affordance that
 * accepts a roster of {user_id, persona_label, persona_type, profile_key}
 * — every seed row triggers a tenant_memberships upsert + profile_apply
 * on the client tenant in one POST.
 */

const RELATIONSHIP_TYPES = [
  { value: 'books_full',        label: 'Books — full books access' },
  { value: 'books_review_only', label: 'Books — review only' },
  { value: 'tax_only',          label: 'Tax only' },
  { value: 'advisory_only',     label: 'Advisory only' },
  { value: 'custom',            label: 'Custom' },
];
const STATUSES = ['active', 'pending', 'paused', 'ended'];
const FIRM_PERSONAS = [
  'cpa', 'cpa_partner', 'cpa_staff',
  'bookkeeper', 'client_advisor', 'external_auditor',
];

function StatusBadge({ status }) {
  const tones = {
    active:  { bg: '#2f7a3b22', fg: '#2f7a3b' },
    paused:  { bg: '#a06a0022', fg: '#a06a00' },
    pending: { bg: '#0c4a6e22', fg: '#0c4a6e' },
    ended:   { bg: '#7a2a2a22', fg: '#7a2a2a' },
  };
  const t = tones[status] || tones.active;
  return (
    <span data-testid={`cpa-clients-status-${status}`}
          style={{ background: t.bg, color: t.fg, fontSize: 11, padding: '2px 8px',
                   borderRadius: 10, fontWeight: 600, textTransform: 'uppercase' }}>{status}</span>
  );
}

/**
 * Inline subform for one bulk-seat roster row. The parent owns the array
 * state and re-renders this component as roster entries shift.
 */
function SeedMemberRow({ row, onChange, onRemove, profiles, index, users }) {
  const filteredProfiles = useMemo(
    () => (profiles || []).filter(p => !p.applies_to_persona || p.applies_to_persona === row.persona_type),
    [profiles, row.persona_type]
  );
  return (
    <div
      data-testid={`cpa-clients-seed-row-${index}`}
      style={{ display: 'grid', gridTemplateColumns: '1.5fr 1fr 1fr 1.5fr 32px', gap: 8, marginBottom: 6 }}
    >
      <select
        value={row.user_id || ''}
        onChange={(e) => onChange({ ...row, user_id: e.target.value })}
        data-testid={`cpa-clients-seed-user-${index}`}
      >
        <option value="">— pick a user —</option>
        {(users || []).map(u => (
          <option key={u.id} value={u.id}>{u.name || u.email} ({u.email})</option>
        ))}
      </select>
      <input
        value={row.persona_label || ''}
        onChange={(e) => onChange({ ...row, persona_label: e.target.value })}
        placeholder="Persona label"
        data-testid={`cpa-clients-seed-label-${index}`}
      />
      <select
        value={row.persona_type || 'cpa_staff'}
        onChange={(e) => onChange({ ...row, persona_type: e.target.value })}
        data-testid={`cpa-clients-seed-type-${index}`}
      >
        {FIRM_PERSONAS.map(p => <option key={p} value={p}>{p}</option>)}
      </select>
      <select
        value={row.profile_key || ''}
        onChange={(e) => onChange({ ...row, profile_key: e.target.value })}
        data-testid={`cpa-clients-seed-profile-${index}`}
      >
        <option value="">— no profile —</option>
        {filteredProfiles.map(p => (
          <option key={p.id} value={p.profile_key}>{p.label}</option>
        ))}
      </select>
      <button
        onClick={onRemove}
        className="btn btn--ghost btn--sm"
        style={{ color: '#b94a4a' }}
        aria-label="Remove row"
        data-testid={`cpa-clients-seed-remove-${index}`}
      >
        <X size={14} />
      </button>
    </div>
  );
}

function LinkForm({ initial, onSave, onCancel, profiles, users }) {
  const [form, setForm] = useState(initial || {
    client_tenant_id: '',
    relationship_type: 'books_full',
    status: 'active',
    primary_cpa_user_id: '',
    engagement_start_date: '',
    notes: '',
    seed_memberships: [],
  });
  const isNew = !initial?.id;
  const [saving, setSaving] = useState(false);
  const [result, setResult] = useState(null);
  const [error, setError] = useState(null);

  const updateSeed = (i, next) => {
    setForm(f => {
      const arr = (f.seed_memberships || []).slice();
      arr[i] = next;
      return { ...f, seed_memberships: arr };
    });
  };
  const removeSeed = (i) => {
    setForm(f => ({
      ...f, seed_memberships: (f.seed_memberships || []).filter((_, idx) => idx !== i),
    }));
  };
  const addSeed = () => {
    setForm(f => ({
      ...f, seed_memberships: [
        ...(f.seed_memberships || []),
        { user_id: '', persona_label: 'CPA', persona_type: 'cpa_staff', profile_key: '' },
      ],
    }));
  };

  const submit = async () => {
    if (!form.client_tenant_id) { setError('Client tenant id is required'); return; }
    setError(null); setResult(null); setSaving(true);
    try {
      const payload = {
        ...form,
        client_tenant_id: Number(form.client_tenant_id),
        primary_cpa_user_id: form.primary_cpa_user_id ? Number(form.primary_cpa_user_id) : null,
        seed_memberships: (form.seed_memberships || [])
          .filter(r => r.user_id)
          .map(r => ({
            user_id: Number(r.user_id),
            persona_label: r.persona_label || 'CPA',
            persona_type:  r.persona_type  || 'cpa_staff',
            profile_key:   r.profile_key   || null,
          })),
      };
      const r = await api.post('/api/admin/cpa_firms.php?action=save', payload);
      setResult(r);
      // Slight delay so the operator sees the bulk-seat outcome before we close.
      setTimeout(() => onSave(), 1200);
    } catch (e) {
      setError(e.message || 'Save failed');
    } finally { setSaving(false); }
  };

  return (
    <Card data-testid="cpa-clients-form">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
        <strong>{isNew ? 'Link a new client' : `Edit client #${initial.id}`}</strong>
        <button onClick={onCancel} className="btn btn--ghost btn--sm" aria-label="Close" data-testid="cpa-clients-form-close">
          <X size={14} />
        </button>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 12 }}>
        <label style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
          <span style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>Client tenant id</span>
          <input
            type="number"
            value={form.client_tenant_id}
            onChange={(e) => setForm({ ...form, client_tenant_id: e.target.value })}
            disabled={!isNew}
            placeholder="42"
            data-testid="cpa-clients-tenant-id"
          />
        </label>
        <label style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
          <span style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>Relationship type</span>
          <select
            value={form.relationship_type}
            onChange={(e) => setForm({ ...form, relationship_type: e.target.value })}
            data-testid="cpa-clients-relationship"
          >
            {RELATIONSHIP_TYPES.map(rt => <option key={rt.value} value={rt.value}>{rt.label}</option>)}
          </select>
        </label>
        <label style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
          <span style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>Status</span>
          <select
            value={form.status}
            onChange={(e) => setForm({ ...form, status: e.target.value })}
            data-testid="cpa-clients-status-select"
          >
            {STATUSES.map(s => <option key={s} value={s}>{s}</option>)}
          </select>
        </label>
        <label style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
          <span style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>Primary CPA</span>
          <select
            value={form.primary_cpa_user_id || ''}
            onChange={(e) => setForm({ ...form, primary_cpa_user_id: e.target.value })}
            data-testid="cpa-clients-primary-cpa"
          >
            <option value="">— optional —</option>
            {(users || []).map(u => (
              <option key={u.id} value={u.id}>{u.name || u.email}</option>
            ))}
          </select>
        </label>
        <label style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
          <span style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>Engagement start (optional)</span>
          <input
            type="date"
            value={form.engagement_start_date || ''}
            onChange={(e) => setForm({ ...form, engagement_start_date: e.target.value })}
            data-testid="cpa-clients-engagement-start"
          />
        </label>
        <label style={{ display: 'flex', flexDirection: 'column', gap: 4, gridColumn: '1 / -1' }}>
          <span style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>Notes (optional)</span>
          <textarea
            rows={2}
            value={form.notes || ''}
            onChange={(e) => setForm({ ...form, notes: e.target.value })}
            placeholder="Engagement notes, scope, etc."
            data-testid="cpa-clients-notes"
          />
        </label>
      </div>

      {isNew && (
        <div style={{ marginTop: 16, padding: 12, background: 'var(--cf-surface-alt, #fafafa)', borderRadius: 6 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
            <strong style={{ fontSize: 13 }}>
              <Users size={13} style={{ verticalAlign: 'middle', marginRight: 6 }} />
              Bulk-seat the client team (optional)
            </strong>
            <button onClick={addSeed} className="btn btn--ghost btn--sm" data-testid="cpa-clients-seed-add">
              <Plus size={12} style={{ marginRight: 4 }} />Add roster row
            </button>
          </div>
          <p style={{ margin: '0 0 8px', fontSize: 12, color: 'var(--cf-text-secondary)' }}>
            Each row creates an active <code>tenant_memberships</code> row on the CLIENT tenant
            and optionally applies a permission profile in one shot.
          </p>
          {(form.seed_memberships || []).length === 0 && (
            <p data-testid="cpa-clients-seed-empty" style={{ margin: 0, fontSize: 12, color: 'var(--cf-text-secondary)' }}>
              No roster rows yet — click <em>Add roster row</em> to seat a CPA on this client.
            </p>
          )}
          {(form.seed_memberships || []).map((row, idx) => (
            <SeedMemberRow
              key={idx}
              row={row}
              index={idx}
              onChange={(next) => updateSeed(idx, next)}
              onRemove={() => removeSeed(idx)}
              profiles={profiles}
              users={users}
            />
          ))}
        </div>
      )}

      {error && (
        <div data-testid="cpa-clients-form-error" style={{ marginTop: 10, color: '#b94a4a', fontSize: 13 }}>{error}</div>
      )}

      {result?.seeded && (
        <div data-testid="cpa-clients-seed-outcome" style={{
          marginTop: 10, padding: 8, background: '#f0fdf4',
          borderRadius: 6, fontSize: 12, border: '1px solid #bbf7d0',
        }}>
          <strong>Seeded {result.seeded.filter(r => !r.error).length} of {result.seeded.length}.</strong>
          {result.seeded.filter(r => r.error).length > 0 && (
            <div style={{ marginTop: 4, color: '#b94a4a' }}>
              Failures: {result.seeded.filter(r => r.error).map(r => `user ${r.user_id} (${r.error})`).join(', ')}
            </div>
          )}
        </div>
      )}

      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
        <button onClick={onCancel} className="btn btn--ghost" disabled={saving} data-testid="cpa-clients-form-cancel">Cancel</button>
        <button onClick={submit} className="btn btn--primary" disabled={saving} data-testid="cpa-clients-form-save">
          <Save size={14} style={{ marginRight: 6 }} />{saving ? 'Saving…' : 'Save'}
        </button>
      </div>
    </Card>
  );
}

export default function CpaFirmClientsAdmin() {
  const [links, setLinks]       = useState(null);
  const [profiles, setProfiles] = useState([]);
  const [users, setUsers]       = useState([]);
  const [editing, setEditing]   = useState(null);
  const [error, setError]       = useState(null);

  const reload = async () => {
    try {
      setError(null);
      const [a, b, c] = await Promise.all([
        api.get('/api/admin/cpa_firms.php'),
        api.get('/api/admin/permission_profiles.php').catch(() => ({ profiles: [] })),
        api.get('/api/admin/manageable_tenants.php').catch(() => ({ tenants: [] })),
      ]);
      setLinks(Array.isArray(a?.links) ? a.links : []);
      setProfiles(Array.isArray(b?.profiles) ? b.profiles : []);
      // The users list comes from /api/admin/memberships.php joined data — but
      // simplest path is to read from a fresh /api/users endpoint if present,
      // else fall back to whatever the memberships page already returned.
      try {
        const u = await api.get('/api/admin/memberships.php?include_inactive=1');
        const seen = new Set();
        const out = [];
        for (const m of (u?.memberships || [])) {
          if (seen.has(m.user_id)) continue;
          seen.add(m.user_id);
          out.push({ id: m.user_id, name: m.user_name, email: m.email });
        }
        setUsers(out);
      } catch { /* fallback: keep users empty */ }
    } catch (e) {
      setLinks([]);
      setError(e.message || 'Failed to load clients');
    }
  };

  useEffect(() => { reload(); }, []);

  const endLink = async (id) => {
    if (!confirm('End this engagement? The row stays in the audit history.')) return;
    try {
      await api.post('/api/admin/cpa_firms.php?action=end', { id });
      reload();
    } catch (e) { alert(e.message || 'End failed'); }
  };

  return (
    <div data-testid="cpa-clients-admin">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <div>
          <h2 style={{ margin: 0, display: 'flex', alignItems: 'center', gap: 8 }}>
            <Building2 size={20} /> Firm clients
          </h2>
          <p style={{ margin: '4px 0 0', color: 'var(--cf-text-secondary)', fontSize: 13 }}>
            Manage the client tenants this firm is engaged with. Switch to the firm tenant before opening this page.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <button onClick={reload} className="btn btn--ghost" data-testid="cpa-clients-refresh">
            <RefreshCw size={14} style={{ marginRight: 6 }} />Refresh
          </button>
          <button
            onClick={() => setEditing({})}
            className="btn btn--primary"
            data-testid="cpa-clients-new"
          >
            <Plus size={14} style={{ marginRight: 6 }} />Link client
          </button>
        </div>
      </div>

      {error && (
        <Card data-testid="cpa-clients-error" style={{ background: '#fff1f0', border: '1px solid #ffccc7' }}>{error}</Card>
      )}

      {editing !== null && (
        <LinkForm
          initial={editing}
          profiles={profiles}
          users={users}
          onSave={() => { setEditing(null); reload(); }}
          onCancel={() => setEditing(null)}
        />
      )}

      {editing === null && (
        <Card data-testid="cpa-clients-list">
          {!links && <div data-testid="cpa-clients-loading">Loading…</div>}
          {links && links.length === 0 && (
            <div data-testid="cpa-clients-empty" style={{ padding: 20, color: 'var(--cf-text-secondary)' }}>
              No clients linked yet. Click <strong>Link client</strong> to engage your first one.
            </div>
          )}
          {links && links.length > 0 && (
            <table style={{ width: '100%', borderCollapse: 'collapse' }} data-testid="cpa-clients-table">
              <thead>
                <tr style={{ textAlign: 'left', fontSize: 12, color: 'var(--cf-text-secondary)' }}>
                  <th style={{ padding: '6px 4px' }}>Client</th>
                  <th style={{ padding: '6px 4px' }}>Status</th>
                  <th style={{ padding: '6px 4px' }}>Relationship</th>
                  <th style={{ padding: '6px 4px' }}>Primary CPA</th>
                  <th style={{ padding: '6px 4px' }}></th>
                </tr>
              </thead>
              <tbody>
                {links.map((l) => (
                  <tr key={l.id} style={{ borderTop: '1px solid var(--cf-border)' }} data-testid={`cpa-clients-row-${l.id}`}>
                    <td style={{ padding: '8px 4px', fontWeight: 500 }}>
                      {l.client_name || `Tenant #${l.client_tenant_id}`}
                      {!l.client_is_active && <span style={{ marginLeft: 6, fontSize: 11, color: '#b94a4a' }}>(inactive)</span>}
                    </td>
                    <td style={{ padding: '8px 4px' }}><StatusBadge status={l.status} /></td>
                    <td style={{ padding: '8px 4px', fontSize: 12 }}>{l.relationship_type}</td>
                    <td style={{ padding: '8px 4px', fontSize: 12 }}>{l.primary_cpa_name || l.primary_cpa_email || '—'}</td>
                    <td style={{ padding: '8px 4px', textAlign: 'right' }}>
                      {l.status !== 'ended' && (
                        <button
                          onClick={() => endLink(l.id)}
                          className="btn btn--ghost btn--sm"
                          style={{ color: '#b94a4a' }}
                          data-testid={`cpa-clients-end-${l.id}`}
                        >
                          <Trash2 size={12} style={{ marginRight: 4 }} />End
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Card>
      )}
    </div>
  );
}

import React, { useEffect, useState } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import { api, useApi } from '../../../dashboard/src/lib/api';
import { useAccountingEntityScope } from '../../../dashboard/src/lib/useAccountingEntityScope';
import AccountingEntitySelector from '../../../dashboard/src/components/AccountingEntitySelector';

/**
 * Client AR contacts — the roster the dunning engine falls back to when an
 * invoice's bill_to.email is empty, and the escalation contact CC'd once
 * dunning crosses the tenant policy's threshold.
 */
export default function ClientContacts() {
  const [q, setQ] = useState('');
  const scope = useAccountingEntityScope();
  const params = new URLSearchParams();
  if (scope.entityId) params.set('entity_id', String(scope.entityId));
  if (q) params.set('q', q);
  const url = scope.ready ? `/api/v1/billing/client-contacts?${params}` : null;
  const { data, loading, error, reload } = useApi(url, { enabled: scope.ready });
  const [editing, setEditing] = useState(null);

  const current = Number(data?.entity_id || 0) === Number(scope.entityId || 0);
  const rows = current ? (data?.rows || []) : [];
  const unassigned = current ? (data?.unassigned || []) : [];
  const entityName = id => scope.entities.find(entity => Number(entity.id) === Number(id))?.legal_name || `Entity ${id}`;

  useEffect(() => { setEditing(null); }, [scope.scopeKey]);

  const del = async (id, name) => {
    if (!confirm(`Remove contacts for "${name}"?`)) return;
    try {
      await api.post(`/api/v1/billing/client-contacts?action=delete&id=${id}`, {});
      reload();
    } catch (e) { alert(`Delete failed: ${e.message}`); }
  };

  return (
    <section data-testid="billing-client-contacts">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16, gap: 8 }}>
        <div>
          <h3 style={{ margin: 0 }}>Client AR contacts</h3>
          <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--cf-text-secondary)' }}>
            Primary contact is the fallback when an invoice has no bill-to email. Contacts and outgoing replies belong to one legal entity.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <input className="input" placeholder="Search client…" value={q} onChange={(e) => setQ(e.target.value)} data-testid="billing-client-contacts-search" style={{ maxWidth: 240 }} />
          <button className="btn btn--primary" onClick={() => setEditing('new')} disabled={!scope.entityId} title={!scope.entityId ? 'Select one legal entity first' : 'Add client contact'} data-testid="billing-client-contacts-new"><Plus size={14} aria-hidden="true" /> New contact</button>
        </div>
      </header>

      <div style={{ display: 'flex', alignItems: 'end', gap: 12, marginBottom: 16 }}>
        <AccountingEntitySelector scope={scope} />
        {scope.error && <p className="error">{scope.error}</p>}
      </div>
      {scope.entityId && <EntitySenderSettings key={scope.entityId} entityId={scope.entityId} />}

      {loading && <p>Loading…</p>}
      {error && <p className="error">Error: {error.message}</p>}

      <table className="data-table" data-testid="billing-client-contacts-table">
        <thead><tr><th>Client</th><th>Legal entity</th><th>AR primary</th><th>Escalation contact</th><th>Notes</th><th>Updated</th><th></th></tr></thead>
        <tbody>
          {rows.length === 0 && !loading && (
            <tr><td colSpan={7} style={{ textAlign: 'center', padding: 24, color: 'var(--cf-text-secondary)' }} data-testid="billing-client-contacts-empty">No contacts for this selection.</td></tr>
          )}
          {rows.map(r => (
            <tr key={r.id} data-testid={`billing-client-contact-row-${r.id}`}>
              <td><strong>{r.client_name}</strong></td>
              <td>{entityName(r.entity_id)}</td>
              <td style={{ fontFamily: 'monospace', fontSize: 12 }}>{r.ar_primary_email || '—'}</td>
              <td style={{ fontFamily: 'monospace', fontSize: 12 }}>{r.ar_escalation_email || '—'}</td>
              <td style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>{r.notes || ''}</td>
              <td style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>{r.updated_at}</td>
              <td>
                <button className="btn btn--ghost" style={{ fontSize: 11 }} onClick={() => setEditing(r)} data-testid={`billing-client-contact-edit-${r.id}`}>Edit</button>
                <button className="btn btn--ghost" style={{ fontSize: 11, color: '#dc2626' }} onClick={() => del(r.id, r.client_name)} title="Delete contact" aria-label={`Delete contact for ${r.client_name}`} data-testid={`billing-client-contact-delete-${r.id}`}><Trash2 size={14} aria-hidden="true" /></button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      {unassigned.length > 0 && (
        <section style={{ marginTop: 24 }} data-testid="billing-client-contacts-unassigned">
          <h3 style={{ margin: '0 0 4px' }}>Needs legal entity</h3>
          <p style={{ margin: '0 0 12px', fontSize: 13, color: 'var(--cf-text-secondary)' }}>
            These older contacts are not used for invoice, statement, or reminder delivery until assigned.
          </p>
          <table className="data-table">
            <thead><tr><th>Client</th><th>AR primary</th><th>Escalation contact</th><th></th></tr></thead>
            <tbody>{unassigned.map(r => <tr key={r.id}>
              <td>{r.client_name}</td><td>{r.ar_primary_email || '—'}</td><td>{r.ar_escalation_email || '—'}</td>
              <td><button className="btn btn--ghost" onClick={() => setEditing({ ...r, legacy: true })} disabled={!scope.entityId} title={!scope.entityId ? 'Select the destination legal entity first' : 'Review and assign this contact'}>Assign</button></td>
            </tr>)}</tbody>
          </table>
        </section>
      )}

      {editing && (
        <ContactModal contact={editing === 'new' ? null : editing} entityId={scope.entityId} entityName={entityName(editing === 'new' || editing.legacy ? scope.entityId : editing.entity_id)} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); reload(); }} />
      )}
    </section>
  );
}

function EntitySenderSettings({ entityId }) {
  const { data, loading, error, reload } = useApi(`/api/v1/billing/client-contacts?action=sender&entity_id=${entityId}`);
  const [form, setForm] = useState({ from_name: '', reply_to: '' });
  const [busy, setBusy] = useState(false);
  const [saveError, setSaveError] = useState(null);
  const [saved, setSaved] = useState(false);
  useEffect(() => {
    if (!data) return;
    setForm({ from_name: data.settings?.from_name || '', reply_to: data.settings?.reply_to || '' });
  }, [data]);
  const save = async () => {
    setBusy(true); setSaveError(null); setSaved(false);
    try {
      await api.post(`/api/v1/billing/client-contacts?action=sender&entity_id=${entityId}`, form);
      await reload(); setSaved(true);
    } catch (e) { setSaveError(e.message); }
    finally { setBusy(false); }
  };
  return <section style={{ borderTop: '1px solid var(--cf-border, #d8e1eb)', borderBottom: '1px solid var(--cf-border, #d8e1eb)', padding: '14px 0', marginBottom: 18 }} data-testid="billing-entity-sender">
    <h3 style={{ margin: '0 0 8px', fontSize: 15 }}>Billing email sender</h3>
    {loading && <p>Loading…</p>}{error && <p className="error">{error.message}</p>}
    {data && <p style={{ fontSize: 12, color: data.sender?.ready ? 'var(--cf-text-secondary)' : '#a16207', margin: '0 0 10px' }}>
      {data.sender?.ready ? `From: ${data.sender.from_name} <${data.sender.from || 'platform address'}> · replies: ${data.sender.reply_to || 'platform address'}` : data.sender?.reason}
    </p>}
    <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'end' }}>
      <label style={{ fontSize: 12 }}>Display name (optional)<input className="input" value={form.from_name} onChange={e => { setForm({ ...form, from_name: e.target.value }); setSaved(false); }} placeholder={data?.sender?.entity_name || ''} style={{ display: 'block', marginTop: 4 }} /></label>
      <label style={{ fontSize: 12 }}>Reply-to email<input className="input" type="email" value={form.reply_to} onChange={e => { setForm({ ...form, reply_to: e.target.value }); setSaved(false); }} placeholder="billing@example.com" style={{ display: 'block', marginTop: 4 }} /></label>
      <button className="btn btn--ghost" onClick={save} disabled={busy || loading}>Save sender</button>
      {saved && <span role="status" style={{ color: '#138477', fontSize: 12 }}>Saved</span>}
    </div>
    {saveError && <p className="error" role="alert">{saveError}</p>}
  </section>;
}

function ContactModal({ contact, entityId, entityName, onClose, onSaved }) {
  const isNew = !contact;
  const [form, setForm] = useState({
    client_name:         contact?.client_name         || '',
    ar_primary_email:    contact?.ar_primary_email    || '',
    ar_escalation_email: contact?.ar_escalation_email || '',
    notes:               contact?.notes               || '',
  });
  const [busy, setBusy] = useState(false);
  const [err, setErr]   = useState(null);

  const save = async () => {
    setBusy(true); setErr(null);
    try {
      const path = contact?.legacy ? `/api/v1/billing/client-contacts?action=assign&id=${contact.id}` : '/api/v1/billing/client-contacts';
      await api.post(path, { ...form, entity_id: contact?.legacy ? entityId : (contact?.entity_id || entityId) });
      onSaved();
    } catch (e) { setErr(e); }
    finally { setBusy(false); }
  };

  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(15,18,28,0.5)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }} data-testid="billing-client-contact-modal" onClick={(e) => e.target === e.currentTarget && !busy && onClose()}>
      <div style={{ background: 'var(--cf-surface, #fff)', borderRadius: 12, width: 'min(520px, 100%)', padding: 24 }}>
        <h3 style={{ margin: '0 0 4px' }}>{isNew ? 'New client contact' : contact.legacy ? `Assign: ${contact.client_name}` : `Edit: ${contact.client_name}`}</h3>
        <p style={{ fontSize: 12, margin: '0 0 16px', color: 'var(--cf-text-secondary)' }}>{contact?.legacy ? `Assign to ${entityName}` : `Legal entity: ${entityName}`}</p>
        <label style={{ display: 'block', fontSize: 12, marginBottom: 8 }}>Client name *
          <input className="input" value={form.client_name} onChange={(e) => setForm({ ...form, client_name: e.target.value })} disabled={!isNew} data-testid="billing-client-contact-form-name" />
        </label>
        <label style={{ display: 'block', fontSize: 12, marginBottom: 8 }}>AR primary email
          <input className="input" type="email" value={form.ar_primary_email} onChange={(e) => setForm({ ...form, ar_primary_email: e.target.value })} placeholder="ar@client.com" data-testid="billing-client-contact-form-primary" />
        </label>
        <label style={{ display: 'block', fontSize: 12, marginBottom: 8 }}>Escalation email (CFO / controller / legal)
          <input className="input" type="email" value={form.ar_escalation_email} onChange={(e) => setForm({ ...form, ar_escalation_email: e.target.value })} placeholder="cfo@client.com" data-testid="billing-client-contact-form-escalation" />
        </label>
        <label style={{ display: 'block', fontSize: 12, marginBottom: 8 }}>Notes
          <textarea className="input" rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
        </label>
        {err && <p className="error" style={{ marginTop: 12 }}>Error: {err.message}</p>}
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 12 }}>
          <button className="btn btn--ghost" onClick={onClose} disabled={busy}>Cancel</button>
          <button className="btn btn--primary" onClick={save} disabled={busy || !form.client_name} data-testid="billing-client-contact-form-save">{busy ? 'Saving…' : 'Save'}</button>
        </div>
      </div>
    </div>
  );
}

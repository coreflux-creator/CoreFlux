import React, { useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';

/**
 * Client AR contacts — the roster the dunning engine falls back to when an
 * invoice's bill_to.email is empty, and the escalation contact CC'd once
 * dunning crosses the tenant policy's threshold.
 */
export default function ClientContacts() {
  const [q, setQ] = useState('');
  const url = q ? `/api/v1/billing/client-contacts?q=${encodeURIComponent(q)}` : '/api/v1/billing/client-contacts';
  const { data, loading, error, reload } = useApi(url);
  const [editing, setEditing] = useState(null);

  const rows = data?.rows || [];

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
            Primary contact is the fallback when an invoice has no bill-to email. Escalation contact is CC'd by the dunning engine once attempts cross your policy's threshold.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <input className="input" placeholder="Search client…" value={q} onChange={(e) => setQ(e.target.value)} data-testid="billing-client-contacts-search" style={{ maxWidth: 240 }} />
          <button className="btn btn--primary" onClick={() => setEditing('new')} data-testid="billing-client-contacts-new">+ New</button>
        </div>
      </header>

      {loading && <p>Loading…</p>}
      {error && <p className="error">Error: {error.message}</p>}

      <table className="data-table" data-testid="billing-client-contacts-table">
        <thead><tr><th>Client</th><th>AR primary</th><th>Escalation contact</th><th>Notes</th><th>Updated</th><th></th></tr></thead>
        <tbody>
          {rows.length === 0 && !loading && (
            <tr><td colSpan={6} style={{ textAlign: 'center', padding: 24, color: 'var(--cf-text-secondary)' }} data-testid="billing-client-contacts-empty">No client contacts yet. Add one so dunning has somewhere to escalate.</td></tr>
          )}
          {rows.map(r => (
            <tr key={r.id} data-testid={`billing-client-contact-row-${r.id}`}>
              <td><strong>{r.client_name}</strong></td>
              <td style={{ fontFamily: 'monospace', fontSize: 12 }}>{r.ar_primary_email || '—'}</td>
              <td style={{ fontFamily: 'monospace', fontSize: 12 }}>{r.ar_escalation_email || '—'}</td>
              <td style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>{r.notes || ''}</td>
              <td style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>{r.updated_at}</td>
              <td>
                <button className="btn btn--ghost" style={{ fontSize: 11 }} onClick={() => setEditing(r)} data-testid={`billing-client-contact-edit-${r.id}`}>Edit</button>
                <button className="btn btn--ghost" style={{ fontSize: 11, color: '#dc2626' }} onClick={() => del(r.id, r.client_name)} data-testid={`billing-client-contact-delete-${r.id}`}>Delete</button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      {editing && (
        <ContactModal contact={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); reload(); }} />
      )}
    </section>
  );
}

function ContactModal({ contact, onClose, onSaved }) {
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
      await api.post('/api/v1/billing/client-contacts', form);
      onSaved();
    } catch (e) { setErr(e); }
    finally { setBusy(false); }
  };

  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(15,18,28,0.5)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }} data-testid="billing-client-contact-modal" onClick={(e) => e.target === e.currentTarget && !busy && onClose()}>
      <div style={{ background: 'var(--cf-surface, #fff)', borderRadius: 12, width: 'min(520px, 100%)', padding: 24 }}>
        <h3 style={{ margin: '0 0 16px' }}>{isNew ? 'New client contact' : `Edit: ${contact.client_name}`}</h3>
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

import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Check, RefreshCw } from 'lucide-react';
import { api, useApi } from '../../../dashboard/src/lib/api';

const SETTINGS_URL = '/modules/billing/api/approval_settings.php';

export default function BillingApprovalSettings() {
  const { data, loading, error, reload } = useApi(SETTINGS_URL);
  const [selected, setSelected] = useState([]);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState(null);

  useEffect(() => {
    if (!data) return;
    const eligible = new Set((data.eligible_reviewers || []).map((person) => person.id));
    setSelected((data.reviewer_user_ids || []).filter((id) => eligible.has(id)));
  }, [data]);

  const reviewers = data?.eligible_reviewers || [];
  const savedIds = data?.reviewer_user_ids || [];
  const dirty = Boolean(data) && JSON.stringify([...selected].sort((a, b) => a - b))
    !== JSON.stringify([...savedIds].sort((a, b) => a - b));

  const toggle = (id) => {
    setMessage(null);
    setSelected((current) => current.includes(id)
      ? current.filter((value) => value !== id)
      : [...current, id]);
  };

  const save = async (event) => {
    event.preventDefault();
    if (selected.length === 0 || saving) return;
    setSaving(true);
    setMessage(null);
    try {
      await api.put(SETTINGS_URL, { reviewer_user_ids: selected });
      await reload();
      setMessage({ kind: 'ok', text: 'Invoice reviewers saved.' });
    } catch (saveError) {
      setMessage({ kind: 'error', text: saveError.message || 'Could not save reviewers.' });
    } finally {
      setSaving(false);
    }
  };

  return (
    <section className="directory-page" data-testid="billing-approval-settings">
      <div className="directory-page__header">
        <div>
          <h2 className="directory-page__title">Invoice approvals</h2>
          <p className="directory-page__description">Choose who can review draft invoices before they are sent or posted.</p>
        </div>
        <div className="directory-page__actions">
          <button type="button" className="btn btn--ghost" onClick={reload} disabled={loading || saving} title="Refresh reviewers">
            <RefreshCw size={16} aria-hidden="true" /> Refresh
          </button>
        </div>
      </div>

      {loading && <p>Loading reviewers...</p>}
      {error && <p className="error" role="alert">{error.message}</p>}
      {message && <p className={message.kind === 'error' ? 'error' : 'success'} role="status">{message.text}</p>}

      {!loading && data && (
        <form onSubmit={save}>
          <div style={{ borderTop: '1px solid var(--cf-border)', borderBottom: '1px solid var(--cf-border)', padding: 'var(--cf-space-4) 0' }}>
            <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'baseline', gap: 12, marginBottom: 12 }}>
              <h3 style={{ margin: 0, fontSize: 16 }}>Reviewers</h3>
              <span style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>
                {data.configured ? 'Approval required for draft invoices' : 'No default reviewer policy configured'}
              </span>
            </div>
            {reviewers.length === 0 ? (
              <p>No eligible reviewers yet. <Link to="/admin/users">Add an administrator</Link> with Billing approval access first.</p>
            ) : (
              <div style={{ display: 'grid', gap: 2 }}>
                {reviewers.map((person) => (
                  <label key={person.id} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '10px 4px', cursor: 'pointer', borderBottom: '1px solid var(--cf-border)' }}>
                    <input type="checkbox" checked={selected.includes(person.id)} onChange={() => toggle(person.id)}
                      data-testid={`billing-reviewer-${person.id}`} />
                    <span style={{ display: 'grid', gap: 2, minWidth: 0 }}>
                      <strong>{person.name}</strong>
                      <span style={{ color: 'var(--cf-text-secondary)', fontSize: 13, overflowWrap: 'anywhere' }}>{person.email}</span>
                    </span>
                  </label>
                ))}
              </div>
            )}
          </div>

          {selected.length === 1 && (
            <p style={{ color: 'var(--cf-text-secondary)', fontSize: 13 }}>
              Add a second reviewer so invoices prepared by the first reviewer can still be approved.
            </p>
          )}
          {data.unavailable_reviewer_user_ids?.length > 0 && (
            <p className="error" role="alert">
              {data.unavailable_reviewer_user_ids.length} configured reviewer(s) no longer have access. Save a new selection to replace them.
            </p>
          )}
          {data.other_active_policies > 0 && (
            <p style={{ color: 'var(--cf-text-secondary)', fontSize: 13 }}>
              Other invoice approval policies are active. Their reviewers may also be able to approve an invoice.
            </p>
          )}
          <p style={{ color: 'var(--cf-text-secondary)', fontSize: 13 }}>
            Any selected reviewer may approve. The person who prepared or requested an invoice cannot approve their own work.
            Changes to this list also affect approvals already waiting in the inbox.
          </p>
          <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
            <button className="btn btn--primary" type="submit" disabled={!dirty || saving || selected.length === 0}
              data-testid="billing-reviewers-save">
              <Check size={16} aria-hidden="true" /> {saving ? 'Saving...' : 'Save reviewers'}
            </button>
            <Link to="/modules/billing/invoices" className="btn btn--ghost">Back to invoices</Link>
          </div>
        </form>
      )}
    </section>
  );
}

import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Check, RefreshCw } from 'lucide-react';
import { api, useApi } from '../../../dashboard/src/lib/api';

const SETTINGS_URL = '/modules/payroll/api/approval_settings.php';

export default function PayrollApprovalSettings() {
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

  const save = async (event) => {
    event.preventDefault();
    if (saving || selected.length === 0) return;
    setSaving(true);
    setMessage(null);
    try {
      await api.put(SETTINGS_URL, { reviewer_user_ids: selected });
      await reload();
      setMessage({ kind: 'ok', text: 'Payroll reviewers saved.' });
    } catch (saveError) {
      setMessage({ kind: 'error', text: saveError.message || 'Could not save reviewers.' });
    } finally {
      setSaving(false);
    }
  };

  return (
    <form onSubmit={save} className="payroll-settings__form" data-testid="payroll-approval-settings">
      <fieldset>
        <legend>Run approvals</legend>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
          <span className="muted">{data?.configured ? 'Reviewers configured' : 'Reviewers not configured'}</span>
          <button type="button" className="btn btn--ghost" onClick={reload} disabled={loading || saving} title="Refresh reviewers">
            <RefreshCw size={16} aria-hidden="true" /> Refresh
          </button>
        </div>
        {loading && <p>Loading reviewers...</p>}
        {error && <p className="error" role="alert">{error.message}</p>}
        {message && <p className={message.kind === 'error' ? 'error' : 'success'} role="status">{message.text}</p>}
        {!loading && data && (
          <>
            {reviewers.length === 0 ? (
              <p>No eligible reviewers yet. <Link to="/admin/users">Add an administrator</Link> with payroll approval access.</p>
            ) : (
              <div style={{ display: 'grid', gap: 2 }}>
                {reviewers.map((person) => (
                  <label key={person.id} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '10px 4px', borderBottom: '1px solid var(--cf-border)', cursor: 'pointer' }}>
                    <input type="checkbox" checked={selected.includes(person.id)}
                      onChange={() => {
                        setMessage(null);
                        setSelected((current) => current.includes(person.id)
                          ? current.filter((id) => id !== person.id) : [...current, person.id]);
                      }}
                      data-testid={`payroll-reviewer-${person.id}`} />
                    <span style={{ display: 'grid', gap: 2, minWidth: 0 }}>
                      <strong>{person.name}</strong>
                      <span className="muted" style={{ overflowWrap: 'anywhere' }}>{person.email}</span>
                    </span>
                  </label>
                ))}
              </div>
            )}
            {selected.length === 1 && (
              <p className="muted">Choose another reviewer so a run prepared by the first can still be approved.</p>
            )}
            {data.unavailable_reviewer_user_ids?.length > 0 && (
              <p className="error" role="alert">A configured reviewer no longer has access. Save a new selection.</p>
            )}
            {data.other_active_policies > 0 && (
              <p className="muted">Other payroll approval policies are active and may route a run to additional reviewers.</p>
            )}
            <p className="muted">A run's builder cannot approve it. Changing this list also changes who can act on a pending run.</p>
            <button className="btn btn--primary" type="submit" disabled={!dirty || saving || selected.length === 0}
              data-testid="payroll-reviewers-save">
              <Check size={16} aria-hidden="true" /> {saving ? 'Saving...' : 'Save reviewers'}
            </button>
          </>
        )}
      </fieldset>
    </form>
  );
}

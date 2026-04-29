import React, { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';

const ETYPES = ['w2', '1099', 'c2c', 'temp_to_perm', 'direct_hire'];

export default function PlacementCreate() {
  const nav = useNavigate();
  const [form, setForm] = useState({
    person_id: '', title: '', engagement_type: 'w2',
    start_date: new Date().toISOString().slice(0, 10),
    end_date: '', due_date: '',
    end_client_name: '', worksite_state: '', worksite_country: '',
    remote_policy: '', external_id: '', notes: '',
  });
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState(null);
  const [personSearch, setPersonSearch] = useState('');

  const search = useApi(personSearch.length >= 2
    ? `/modules/people/api/people.php?q=${encodeURIComponent(personSearch)}&per_page=10`
    : null);

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });

  const submit = async (e) => {
    e.preventDefault();
    setSubmitting(true); setError(null);
    try {
      const cleaned = { ...form };
      ['end_date', 'due_date'].forEach(k => { if (!cleaned[k]) delete cleaned[k]; });
      cleaned.person_id = parseInt(cleaned.person_id, 10);
      const result = await api.post('/modules/placements/api/placements.php', cleaned);
      nav(`../${result.placement.id}`);
    } catch (e) { setError(e); setSubmitting(false); }
  };

  return (
    <section className="person-create" data-testid="placement-create">
      <header style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 'var(--cf-space-4)' }}>
        <h2>New Placement</h2>
        <Link to=".." className="btn btn--ghost" data-testid="placement-create-back">← Back to list</Link>
      </header>

      <form onSubmit={submit} className="person-create__form" data-testid="placement-create-form" style={{ maxWidth: '760px' }}>
        <fieldset disabled={submitting}>
          <Field label="Person *">
            <input className="input" placeholder="Type to search People…" value={personSearch}
                   onChange={e => setPersonSearch(e.target.value)} data-testid="placement-create-person-search" />
            {search.data?.rows?.length > 0 && (
              <ul style={{ listStyle: 'none', padding: 0, margin: 'var(--cf-space-2) 0', maxHeight: '180px', overflow: 'auto', border: '1px solid var(--cf-border)', borderRadius: 'var(--cf-radius-md)' }} data-testid="placement-create-person-results">
                {search.data.rows.map(p => (
                  <li key={p.id}>
                    <button type="button" onClick={() => { setForm({ ...form, person_id: p.id }); setPersonSearch(`${p.first_name} ${p.last_name} (${p.email_primary})`); }}
                            data-testid={`placement-create-pick-person-${p.id}`}
                            style={{ width: '100%', textAlign: 'left', padding: 'var(--cf-space-2)', background: 'transparent', border: 'none', cursor: 'pointer' }}>
                      {p.first_name} {p.last_name} <span style={{ color: 'var(--cf-text-secondary)' }}>· {p.email_primary} · {p.classification}</span>
                    </button>
                  </li>
                ))}
              </ul>
            )}
            <input type="hidden" value={form.person_id} data-testid="placement-create-person-id" readOnly />
          </Field>

          <Row>
            <Field label="Title *">
              <input className="input" required value={form.title} onChange={set('title')} data-testid="placement-create-title" />
            </Field>
            <Field label="Engagement type *">
              <select className="input" required value={form.engagement_type} onChange={set('engagement_type')} data-testid="placement-create-etype">
                {ETYPES.map(t => <option key={t} value={t}>{t}</option>)}
              </select>
            </Field>
          </Row>

          <Row>
            <Field label="Start date *">
              <input className="input" type="date" required value={form.start_date} onChange={set('start_date')} data-testid="placement-create-start" />
            </Field>
            <Field label="End date">
              <input className="input" type="date" value={form.end_date} onChange={set('end_date')} data-testid="placement-create-end" />
            </Field>
            <Field label="Due date">
              <input className="input" type="date" value={form.due_date} onChange={set('due_date')} data-testid="placement-create-due" />
            </Field>
          </Row>

          <Row>
            <Field label="End client name">
              <input className="input" value={form.end_client_name} onChange={set('end_client_name')} data-testid="placement-create-client" />
            </Field>
            <Field label="External ID">
              <input className="input" value={form.external_id} onChange={set('external_id')} data-testid="placement-create-external" />
            </Field>
          </Row>

          <Row>
            <Field label="Worksite state">
              <input className="input" value={form.worksite_state} onChange={set('worksite_state')} data-testid="placement-create-state" />
            </Field>
            <Field label="Worksite country (2)">
              <input className="input" maxLength={2} value={form.worksite_country} onChange={set('worksite_country')} data-testid="placement-create-country" />
            </Field>
            <Field label="Remote policy">
              <select className="input" value={form.remote_policy} onChange={set('remote_policy')} data-testid="placement-create-remote">
                <option value="">—</option>
                <option value="onsite">onsite</option>
                <option value="hybrid">hybrid</option>
                <option value="remote">remote</option>
              </select>
            </Field>
          </Row>

          <Field label="Notes">
            <textarea className="input" rows={3} value={form.notes} onChange={set('notes')} data-testid="placement-create-notes" />
          </Field>

          {error && <p className="error" data-testid="placement-create-error">Error: {error.message} {error.data?.fields ? `(missing: ${error.data.fields.join(', ')})` : ''}</p>}

          <div style={{ marginTop: 'var(--cf-space-3)', display: 'flex', gap: 'var(--cf-space-2)' }}>
            <button type="submit" className="btn btn--primary" data-testid="placement-create-submit" disabled={submitting || !form.person_id}>
              {submitting ? 'Saving…' : 'Create placement'}
            </button>
            <Link to=".." className="btn btn--ghost" data-testid="placement-create-cancel">Cancel</Link>
          </div>
        </fieldset>
      </form>
    </section>
  );
}

const Row = ({ children }) => <div style={{ display: 'flex', gap: 'var(--cf-space-3)', marginBottom: 'var(--cf-space-3)', flexWrap: 'wrap' }}>{children}</div>;
const Field = ({ label, children }) => (
  <label style={{ display: 'flex', flexDirection: 'column', flex: 1, minWidth: '180px' }}>
    <span style={{ fontSize: '0.85em', color: 'var(--cf-text-secondary)', marginBottom: 'var(--cf-space-1)' }}>{label}</span>
    {children}
  </label>
);

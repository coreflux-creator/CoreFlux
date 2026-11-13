import React, { useEffect, useRef, useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';
import CompanyTypeahead from '../../people/ui/CompanyTypeahead';

export default function PartyCompanyReview({ kind, id, onSaved }) {
  const [open, setOpen] = useState(false);
  const [company, setCompany] = useState(null);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const generation = useRef(0);
  useEffect(() => {
    generation.current += 1;
    setOpen(false); setCompany(null); setSaving(false); setError('');
    return () => { generation.current += 1; };
  }, [kind, id]);
  const save = async () => {
    if (!company?.id || saving) return;
    const current = generation.current;
    setSaving(true); setError('');
    try {
      const field = kind === 'chain' ? 'company_id' : 'referrer_company_id';
      const endpoint = kind === 'chain' ? 'chain' : 'referrals';
      await api.patch(`/modules/placements/api/${endpoint}.php?id=${id}`, { [field]: company.id });
      if (current !== generation.current) return;
      setOpen(false); setCompany(null); onSaved();
    } catch (e) { if (current === generation.current) setError(e.message); }
    finally { if (current === generation.current) setSaving(false); }
  };
  if (!open) return <button type="button" className="btn btn--ghost" onClick={() => setOpen(true)}>Choose company</button>;
  return <div data-testid={`party-company-review-${kind}-${id}`}>
    <CompanyTypeahead value={company} onChange={setCompany} onQueryChange={() => setCompany(null)} placeholder="Search existing companies" testId="party-company-choice" />
    <p className="muted">Saving makes this rule set managed in Core. Future source refreshes will preserve your selection.</p>
    <button type="button" className="btn btn--primary" disabled={!company?.id || saving} onClick={save}>{saving ? 'Saving…' : 'Save company'}</button>
    <button type="button" className="btn btn--ghost" disabled={saving} onClick={() => { setOpen(false); setCompany(null); setError(''); }}>Cancel</button>
    {error && <p className="error" role="alert">{error}</p>}
  </div>;
}

import React, { useEffect, useRef, useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';

const labels = {
  client_payment_terms_override: 'Client payment terms', vendor_payment_terms_override: 'Contractor payment terms',
  vendor_pwp_enabled: 'Paid when paid', client_bill_cycle: 'Billing frequency', client_bill_cycle_anchor: 'Billing anchor date',
  vendor_pay_cycle: 'Payment frequency', vendor_pay_cycle_anchor: 'Payment anchor date',
  payment_terms: 'Payment terms', payment_terms_override: 'Payment terms', pwp_enabled: 'Paid when paid',
  name: 'Schedule', cadence: 'Frequency', anchor_date: 'Anchor date',
  company_id: 'Core company ID', display_name: 'Payee', corp_legal_name: 'Legal name', active: 'Active payee',
  engagement_type: 'Employment model',
};
const valueText = (key, value) => value === null || value === undefined || value === '' ? '—'
  : key.endsWith('pwp_enabled') || key === 'active' ? (Number(value) ? 'Yes' : 'No')
    : key === 'engagement_type' ? String(value).toUpperCase() : String(value);
function CurrentValues({ values }) {
  return <dl style={{ margin: '6px 0', display: 'grid', gridTemplateColumns: 'minmax(150px, 1fr) 2fr', gap: '4px 12px' }}>
    {Object.entries(values || {}).filter(([key]) => labels[key]).map(([key, value]) => <React.Fragment key={key}>
      <dt>{labels[key]}</dt><dd style={{ margin: 0 }}>{valueText(key, value)}</dd>
    </React.Fragment>)}
  </dl>;
}

export default function CommercialOwnershipReview({ placementId, disabled = false, onReleased }) {
  const [review, setReview] = useState(null);
  const [selected, setSelected] = useState([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const generation = useRef(0);
  const endpoint = `/modules/placements/api/economics.php?placement_id=${placementId}`;
  const canRelease = review?.permissions?.release === true;
  useEffect(() => {
    setReview(null); setSelected([]); setError(''); setNotice(''); setBusy(false);
    return () => { generation.current += 1; };
  }, [placementId]);
  const load = async () => {
    const request = ++generation.current;
    setBusy(true); setReview(null); setSelected([]); setError(''); setNotice('');
    try {
      const next = await api.get(`${endpoint}&action=ownership_review`);
      if (request === generation.current) setReview(next);
    }
    catch (e) { if (request === generation.current) setError(`Ownership review unavailable: ${e.message}`); }
    finally { if (request === generation.current) setBusy(false); }
  };
  const release = async () => {
    if (!review || !canRelease || !selected.length || busy || disabled) return;
    const chosen = review.groups.filter(group => selected.includes(group.key));
    if (!window.confirm(`Return these linked fields to JobDiva?\n\n${chosen.map(group => group.label).join('\n')}\n\nCurrent values stay unchanged now. A later JobDiva refresh may replace them. Placement and participant ownership will be released together.`)) return;
    const token = review.review_token;
    const groups = [...selected];
    const request = ++generation.current;
    setBusy(true); setError(''); setNotice(''); setReview(null); setSelected([]);
    try {
      await api.post(`${endpoint}&action=release_ownership`, { groups, review_token: token, confirm: 'RELEASE_COMMERCIAL_OWNERSHIP' });
      if (request !== generation.current) return;
      setNotice('Ownership released. Current values are unchanged; a later JobDiva refresh may replace them.');
      try { await onReleased?.(); } catch { if (request === generation.current) setNotice('Ownership released. Refresh the Contract tab to view the updated ownership. Current values were not replaced.'); }
    } catch (e) { if (request === generation.current) setError(`${e.message} Load a fresh ownership review before trying again.`); }
    finally { if (request === generation.current) setBusy(false); }
  };
  return <section style={{ margin: '12px 0' }} data-testid="commercial-ownership-review">
    <button type="button" className="btn btn--sm" disabled={disabled || busy} onClick={load}>
      {busy ? 'Working…' : review ? 'Refresh ownership review' : 'Review JobDiva ownership'}
    </button>
    {error && <p className="error" role="alert">{error}</p>}
    {notice && <p role="status">{notice}</p>}
    {review && <div style={{ marginTop: 12, padding: 16, border: '1px solid #cbd5e1', borderRadius: 8 }}>
      <h4 style={{ marginTop: 0 }}>{canRelease ? 'Return linked contract fields to JobDiva' : 'Contract field ownership'}</h4>
      <p>{canRelease ? 'Review the current values below. Releasing ownership keeps these values in place until a later source refresh. Related placement and participant fields are released together.' : 'You can review these values. Releasing ownership requires placement edit access.'}</p>
      {!review.groups?.length && <p>No linked commercial fields are currently managed by Core.</p>}
      {(review.groups || []).map(group => <div key={group.key} style={{ padding: '12px 0', borderTop: '1px solid #e2e8f0' }}>
        <label style={{ display: 'flex', gap: 8, fontWeight: 600 }}>
          {canRelease && <input type="checkbox" disabled={!group.selectable || busy || disabled} checked={selected.includes(group.key)}
            onChange={() => setSelected(current => current.includes(group.key) ? current.filter(key => key !== group.key) : [...current, group.key])} />
          }
          {group.label}
        </label>
        {group.description && <p>{group.description}</p>}
        {!group.selectable && <p role="note">{group.blocked_reason}</p>}
        <div style={{ marginLeft: 22 }}>
          <strong>Placement</strong><CurrentValues values={group.placement_values} />
          {group.participants.map(party => <div key={party.id}><strong>{party.name}</strong>
            <CurrentValues values={party.values} /><CurrentValues values={party.cycle} />
          </div>)}
          {group.corporation_values && <><strong>Corporation record</strong><CurrentValues values={group.corporation_values} /></>}
        </div>
      </div>)}
      {canRelease && <button type="button" className="btn btn--primary" onClick={release} disabled={disabled || busy || !selected.length}>Return selected fields to JobDiva</button>}
    </div>}
  </section>;
}

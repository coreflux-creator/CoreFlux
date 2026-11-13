import React, { useState } from 'react';

const label = field => ({ roles: 'Company roles', account_manager_user_id: 'Account manager', default_terms: 'Payment terms', duns: 'DUNS' }[field] || field.replaceAll('_', ' ').replace(/^./, c => c.toUpperCase()));
const show = value => value === null || value === undefined || value === '' ? 'Blank' : Array.isArray(value) ? value.join(', ') || 'None' : String(value);
const impactLabel = table => ({ ap_vendors_index: 'Vendor profiles', staffing_clients: 'Client profiles', staffing_jobs: 'Jobs', placements: 'Placements', placement_client_chain: 'Assignment companies', placement_corp_details: 'Contractor company details', placement_economic_parties: 'Placement financial parties', placement_referrals: 'Referrals', company_contacts: 'Contacts', company_addresses: 'Addresses', ap_bills: 'Bills', ap_payments: 'Payments', ap_1099_ledger: '1099 history', billing_invoices: 'Invoices', accounting_journal_entry_lines: 'Journal lines', treasury_liability_statement_lines: 'Liability statement lines', people_graph_actor_links: 'Approval identities' }[table] || label(table));

function initialChoices(review) {
  const onePrimary = rows => { const primary = rows.filter(r => Number(r.company_id) === review.survivor.id && Number(r.is_primary) === 1); return primary.length === 1 ? Number(primary[0].id) : 0; };
  const kinds = [...new Set(review.addresses.map(a => a.kind))];
  return { fields: Object.fromEntries(review.fields.map(f => [f, 'survivor'])), custom_fields: Object.fromEntries(Object.keys(review.custom_fields).map(f => [f, 'survivor'])),
    primary_contact_id: onePrimary(review.contacts), primary_addresses: Object.fromEntries(kinds.map(kind => [kind, onePrimary(review.addresses.filter(a => a.kind === kind))])) };
}

export default function CompanyMergeReview({ review, busy, error, onApply, onRefresh, onCancel }) {
  const [choices, setChoices] = useState(() => initialChoices(review));
  const [confirmed, setConfirmed] = useState(false);
  const change = (group, field, value) => { setChoices(old => ({ ...old, [group]: { ...old[group], [field]: value } })); setConfirmed(false); };
  const current = review.survivor, retired = review.retired;
  const select = (group, field) => <select aria-label={`Choose ${label(field)}`} disabled={busy} value={choices[group][field]} onChange={e => change(group, field, e.target.value)}>
    <option value="survivor">Keep {current.company_number}</option><option value="retired">Use {retired.company_number}</option>
    {field === 'roles' && group === 'fields' && <option value="union">Combine roles</option>}
  </select>;
  return <section aria-label="Review company merge" data-testid="company-merge-review" style={{ border: '1px solid var(--cf-border, #ddd)', borderRadius: 8, padding: 16, marginBottom: 20 }}>
    <h3>Review {retired.company_number} → {current.company_number}</h3>
    <p>Choose the values to keep on {current.company_number}. Both company numbers and all source IDs are preserved. The selected fields will be managed in Core.</p>
    {error && <p role="alert" className="error">{error}</p>}
    <div style={{ overflowX: 'auto' }}><table className="data-table"><thead><tr><th>Field</th><th>{current.company_number}</th><th>{retired.company_number}</th><th>Keep</th></tr></thead>
      <tbody>{review.fields.map(field => <tr key={field}><td>{label(field)}</td><td style={{ whiteSpace: 'pre-wrap' }}>{show(current.values[field])}</td><td style={{ whiteSpace: 'pre-wrap' }}>{show(retired.values[field])}</td><td>{select('fields', field)}</td></tr>)}</tbody>
    </table></div>
    {Object.keys(review.custom_fields).length > 0 && <><h4>Custom fields</h4><table className="data-table"><thead><tr><th>Field</th><th>{current.company_number}</th><th>{retired.company_number}</th><th>Keep</th></tr></thead>
      <tbody>{Object.entries(review.custom_fields).map(([key, field]) => <tr key={key}><td>{field.label}</td><td>{show(field.survivor.value)}</td><td>{show(field.retired.value)}</td><td>{select('custom_fields', key)}</td></tr>)}</tbody></table></>}
    {review.contacts.length > 0 && <label style={{ display: 'block', marginTop: 16 }}>Primary contact <select aria-label="Primary contact" disabled={busy} value={choices.primary_contact_id} onChange={e => { setChoices(old => ({ ...old, primary_contact_id: Number(e.target.value) })); setConfirmed(false); }}>
      <option value={0}>No primary contact</option>{review.contacts.map(c => <option key={c.id} value={c.id}>{c.name}{c.email ? ` · ${c.email}` : ''}</option>)}</select></label>}
    {Object.keys(choices.primary_addresses).map(kind => <label key={kind} style={{ display: 'block', marginTop: 8 }}>Primary {label(kind).toLowerCase()} address <select aria-label={`Primary ${kind} address`} disabled={busy} value={choices.primary_addresses[kind]} onChange={e => change('primary_addresses', kind, Number(e.target.value))}>
      <option value={0}>No primary address</option>{review.addresses.filter(a => a.kind === kind).map(a => <option key={a.id} value={a.id}>{a.label || a.line1} · {a.city}</option>)}</select></label>)}
    <h4>Affected records</h4><ul>{review.impact.filter(i => i.count > 0).map(i => <li key={i.table}>{impactLabel(i.table)}: {i.count} — {i.action === 'redirect' ? 'link to the surviving company' : 'retain original references'}</li>)}</ul>
    <p>Financial history, approval identities, protected tax information, and document records retain their original references. Restricted custom values stay on their original records. Contacts, addresses, client and vendor profiles keep their own IDs.</p>
    {review.sources.length > 0 && <><h4>Preserved source identities</h4><ul>{review.sources.map((s, i) => <li key={`${s.tenant_id}-${s.source_system}-${s.external_id}-${i}`}>{s.source_system}: {s.external_id}</li>)}</ul></>}
    <label style={{ display: 'block', marginTop: 16 }}><input type="checkbox" disabled={busy} checked={confirmed} onChange={e => setConfirmed(e.target.checked)} /> I reviewed these choices and confirm these records represent the same company.</label>
    <div style={{ display: 'flex', gap: 8, marginTop: 12 }}>
      <button type="button" className="btn btn--primary" disabled={busy || !confirmed} onClick={() => { if (confirmed && !busy) onApply(choices); }}>Apply reviewed merge</button>
      <button type="button" className="btn btn--ghost" disabled={busy} onClick={onRefresh}>Refresh review</button>
      <button type="button" className="btn btn--ghost" disabled={busy} onClick={onCancel}>Cancel</button>
    </div>
  </section>;
}

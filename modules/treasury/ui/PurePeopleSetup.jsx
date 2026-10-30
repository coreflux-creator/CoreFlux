import React, { useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';

export function PurePeopleSetupView({ result }) {
  const checks = result.indicators;
  const tax = { employee_confirmed: 'Employee confirmation recorded', provider_recorded: 'Recorded by payroll provider', not_recorded: 'Not recorded' };
  return <div role="status">
    <p><strong>{result.person.number}</strong> · PurePayroll employee {result.provider_id}</p>
    <dl>
      <dt>Employee status in PurePayroll</dt><dd>{checks.employee_status}</dd>
      <dt>Invitation</dt><dd>{checks.invitation_recorded ? 'Recorded' : 'Not recorded'}</dd>
      <dt>Contact, address and birth date fields</dt><dd>{checks.profile_fields_present ? 'Present' : 'Incomplete'}</dd>
      <dt>Tax election record</dt><dd>{tax[checks.tax_election_record]}</dd>
      <dt>Bank reference</dt><dd>{checks.bank_reference_present ? 'Recorded' : 'Not recorded'}</dd>
    </dl>
    <p>Observed {result.observed_at}. These are setup indicators. They do not confirm document review, completed onboarding, bank verification or payroll approval.</p>
  </div>;
}

export default function PurePeopleSetup() {
  const [number, setNumber] = useState('');
  const [result, setResult] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  return <section style={{ border: '1px solid var(--cf-border)', borderRadius: 8, padding: 16, marginTop: 16 }}>
    <h4>Employee setup in PurePayroll</h4>
    <p>Check the current setup for a person whose Core and PurePayroll records are already linked. Finish or review their setup in PurePayroll.</p>
    <form onSubmit={async e => {
      e.preventDefault(); setBusy(true); setError(''); setResult(null);
      try { setResult(await api.post('/api/purepay_core.php?action=people_setup', { person_number: number.trim() })); }
      catch (e) { setError(e.message || 'Could not read employee setup.'); }
      finally { setBusy(false); }
    }}>
      <label>Core person number<input className="input" placeholder="P-123" value={number} disabled={busy}
        onChange={e => { setNumber(e.target.value); setResult(null); setError(''); }} /></label>
      <button className="btn" disabled={busy || !number.trim()}>{busy ? 'Checking…' : 'Check employee setup'}</button>
    </form>
    {error && <p className="error" role="alert">{error}</p>}
    {result && <PurePeopleSetupView result={result} />}
  </section>;
}

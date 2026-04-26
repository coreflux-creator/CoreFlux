import React, { useState, useEffect } from 'react';
import { api } from '../../../dashboard/src/lib/api';

export default function PayrollSettings() {
  const [form, setForm] = useState({
    legal_name: '',
    dba_name: '',
    ein: '',
    primary_state: 'CA',
    state_tax_id: '',
    address_street1: '',
    address_city: '',
    address_region: 'CA',
    address_postal: '',
    suta_rate_bps: 340,
    futa_credit_rate_bps: 540,
    ai_run_summary_enabled: 1,
  });
  const [loaded, setLoaded] = useState(false);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    api.get('/modules/payroll/api/settings.php').then((d) => {
      if (d.settings) setForm({ ...form, ...d.settings });
      setLoaded(true);
    }).catch((e) => { setError(e.message); setLoaded(true); });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const submit = async (e) => {
    e.preventDefault(); setBusy(true); setError(null); setMsg(null);
    try {
      await api.put('/modules/payroll/api/settings.php', form);
      setMsg('Saved');
    } catch (err) { setError(err.message); }
    finally { setBusy(false); }
  };

  if (!loaded) return <p>Loading…</p>;

  return (
    <section className="payroll-settings" data-testid="payroll-settings">
      <header>
        <h2>Payroll Settings</h2>
        <p>Company-level payroll configuration. Required before running payroll for the first time.</p>
      </header>

      <form onSubmit={submit} className="payroll-settings__form">
        <fieldset>
          <legend>Company</legend>
          <label>
            <span>Legal business name</span>
            <input
              required type="text"
              value={form.legal_name || ''}
              onChange={(e) => setForm({ ...form, legal_name: e.target.value })}
              data-testid="payroll-settings-legal-name"
            />
          </label>
          <label>
            <span>DBA / trade name</span>
            <input
              type="text"
              value={form.dba_name || ''}
              onChange={(e) => setForm({ ...form, dba_name: e.target.value })}
            />
          </label>
          <label>
            <span>Federal EIN</span>
            <input
              type="text"
              value={form.ein || ''}
              onChange={(e) => setForm({ ...form, ein: e.target.value })}
              data-testid="payroll-settings-ein"
            />
          </label>
          <label>
            <span>Primary state</span>
            <input
              type="text" maxLength="2"
              value={form.primary_state || 'CA'}
              onChange={(e) => setForm({ ...form, primary_state: e.target.value.toUpperCase() })}
            />
          </label>
          <label>
            <span>State tax ID</span>
            <input
              type="text"
              value={form.state_tax_id || ''}
              onChange={(e) => setForm({ ...form, state_tax_id: e.target.value })}
            />
          </label>
        </fieldset>

        <fieldset>
          <legend>Employer tax rates</legend>
          <label>
            <span>SUTA rate (basis points)</span>
            <input
              type="number" min="0" max="20000"
              value={form.suta_rate_bps}
              onChange={(e) => setForm({ ...form, suta_rate_bps: parseInt(e.target.value, 10) || 0 })}
              data-testid="payroll-settings-suta"
            />
            <small className="muted">e.g. 340 = 3.40% — set per state agency rate notice.</small>
          </label>
          <label>
            <span>FUTA credit rate (basis points)</span>
            <input
              type="number" min="0" max="600"
              value={form.futa_credit_rate_bps}
              onChange={(e) => setForm({ ...form, futa_credit_rate_bps: parseInt(e.target.value, 10) || 0 })}
            />
            <small className="muted">540 = full 5.40% SUTA credit (effective FUTA = 0.6%).</small>
          </label>
        </fieldset>

        <fieldset>
          <legend>AI</legend>
          <label className="checkbox">
            <input
              type="checkbox"
              checked={!!parseInt(form.ai_run_summary_enabled, 10)}
              onChange={(e) => setForm({ ...form, ai_run_summary_enabled: e.target.checked ? 1 : 0 })}
              data-testid="payroll-settings-ai-toggle"
            />
            <span>Enable AI advisory narrative for runs</span>
          </label>
          <p className="muted">
            AI never produces numbers. It narrates totals already computed by CoreFlux. Reviews are required.
          </p>
        </fieldset>

        {msg && <p className="success" data-testid="payroll-settings-saved">{msg}</p>}
        {error && <p className="error">{error}</p>}
        <button type="submit" className="btn btn--primary" disabled={busy} data-testid="payroll-settings-save">
          {busy ? 'Saving…' : 'Save settings'}
        </button>
      </form>
    </section>
  );
}

import React, { useState, useEffect } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';
import RailPicker from '../../../dashboard/src/components/RailPicker';
import GustoConnectCard from './GustoConnectCard';

export default function PayrollSettings() {
  const { data: railsData, loading: railsLoading } = useApi('/core/api/payment_rails.php');
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
    disbursement_rail: 'nacha',
    nacha_company_id: '',
    nacha_origin_routing: '',
  });
  const [loaded, setLoaded] = useState(false);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    api.get('/api/v1/payroll/settings').then((d) => {
      if (d.settings) setForm({ ...form, ...d.settings, disbursement_rail: d.settings.disbursement_rail || 'nacha' });
      setLoaded(true);
    }).catch((e) => { setError(e.message); setLoaded(true); });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const submit = async (e) => {
    e.preventDefault(); setBusy(true); setError(null); setMsg(null);
    try {
      await api.put('/api/v1/payroll/settings', form);
      setMsg('Saved');
    } catch (err) { setError(err.message); }
    finally { setBusy(false); }
  };

  if (!loaded || railsLoading) return <p>Loading…</p>;

  return (
    <section className="payroll-settings" data-testid="payroll-settings">
      <header>
        <h2>Payroll Settings</h2>
        <p>Company-level payroll configuration. Required before running payroll for the first time.</p>
      </header>

      <form onSubmit={submit} className="payroll-settings__form">
        <GustoConnectCard />

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
          <legend>Disbursement rail</legend>
          <p className="muted" style={{ marginTop: 0 }}>
            How CoreFlux funds direct-deposit. Per-row override available on each payroll run.
          </p>
          <RailPicker
            rails={railsData?.rails || []}
            value={form.disbursement_rail}
            onChange={(railId) => setForm({ ...form, disbursement_rail: railId })}
            testIdPrefix="payroll-rail"
          />
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 12, marginTop: 12 }}>
            <label>
              <span>NACHA Company ID</span>
              <input
                maxLength={10}
                value={form.nacha_company_id || ''}
                onChange={(e) => setForm({ ...form, nacha_company_id: e.target.value })}
                data-testid="payroll-settings-nacha-company-id"
              />
            </label>
            <label>
              <span>NACHA Origin Routing (ODFI ABA)</span>
              <input
                maxLength={9}
                value={form.nacha_origin_routing || ''}
                onChange={(e) => setForm({ ...form, nacha_origin_routing: e.target.value.replace(/\D/g, '').slice(0, 9) })}
                data-testid="payroll-settings-nacha-origin-routing"
              />
            </label>
          </div>
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

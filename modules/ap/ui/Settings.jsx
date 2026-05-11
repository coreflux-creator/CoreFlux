import React, { useEffect, useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';
import RailPicker from '../../../dashboard/src/components/RailPicker';

/**
 * AP Settings — disbursement rail + NACHA originator config.
 *
 * Tenant picks a default rail (NACHA or Plaid Transfer) for AP outbound
 * payments. Rail-cards show real cost, settlement window, and feature
 * support so the tenant can pick on numbers, not gut feel.
 */
export default function APSettings() {
  const { data: railsData, loading: railsLoading } = useApi('/core/api/payment_rails.php');
  const [form, setForm]   = useState({
    disbursement_rail: 'nacha',
    nacha_company_id: '',
    nacha_company_name: '',
    nacha_origin_routing: '',
  });
  const [wq, setWq]         = useState({ dow: 7, hour: 22, can_write: false });
  const [wqMsg, setWqMsg]   = useState(null);
  const [wqBusy, setWqBusy] = useState(false);
  const [loaded, setLoaded] = useState(false);
  const [busy, setBusy]     = useState(false);
  const [msg, setMsg]       = useState(null);
  const [error, setError]   = useState(null);

  useEffect(() => {
    api.get('/modules/ap/api/settings.php').then((d) => {
      if (d.settings) setForm((prev) => ({ ...prev, ...d.settings, disbursement_rail: d.settings.disbursement_rail || 'nacha' }));
      setLoaded(true);
    }).catch((e) => { setError(e.message); setLoaded(true); });
    api.get('/modules/ap/api/weekly_queue_settings.php').then((d) => {
      setWq({ dow: Number(d.dow ?? 7), hour: Number(d.hour ?? 22), can_write: !!d.can_write });
    }).catch(() => { /* surface in error block below if it matters */ });
  }, []);

  const submit = async (e) => {
    e.preventDefault(); setBusy(true); setError(null); setMsg(null);
    try {
      await api.put('/modules/ap/api/settings.php', form);
      setMsg('Saved');
    } catch (err) { setError(err.message); }
    finally { setBusy(false); }
  };

  const saveWq = async () => {
    setWqBusy(true); setWqMsg(null);
    try {
      await api.post('/modules/ap/api/weekly_queue_settings.php', {
        weekly_queue_email_dow:  wq.dow,
        weekly_queue_email_hour: wq.hour,
      });
      setWqMsg('Saved');
    } catch (err) { setWqMsg(`Error: ${err.message}`); }
    finally { setWqBusy(false); }
  };

  if (!loaded || railsLoading) return <p>Loading…</p>;

  return (
    <section data-testid="ap-settings">
      <header style={{ marginBottom: 16 }}>
        <h2 style={{ margin: 0 }}>AP Settings</h2>
        <p style={{ color: 'var(--cf-text-secondary)', fontSize: 13, margin: '4px 0 0' }}>
          Disbursement rail + NACHA originator config for outbound vendor payments.
        </p>
      </header>

      <form onSubmit={submit} style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
        <fieldset style={{ border: 'none', padding: 0, margin: 0 }}>
          <legend style={{ fontSize: 14, fontWeight: 600, marginBottom: 8 }}>Default disbursement rail</legend>
          <RailPicker
            rails={railsData?.rails || []}
            value={form.disbursement_rail}
            onChange={(railId) => setForm({ ...form, disbursement_rail: railId })}
            testIdPrefix="ap-rail"
          />
        </fieldset>

        <fieldset style={{ border: '1px solid var(--cf-border, #e5e7eb)', padding: 16, borderRadius: 8 }}>
          <legend style={{ padding: '0 8px', fontSize: 13, fontWeight: 600 }}>NACHA originator</legend>
          <p style={{ fontSize: 12, color: 'var(--cf-text-secondary)', margin: '0 0 12px' }}>
            Required when disbursement rail is <code>nacha</code>. Get these from your bank's ACH origination agreement.
          </p>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 12 }}>
            <label style={{ fontSize: 12 }}>
              <span>Company ID (10 chars)</span>
              <input
                className="input" maxLength={10}
                value={form.nacha_company_id || ''}
                onChange={(e) => setForm({ ...form, nacha_company_id: e.target.value })}
                data-testid="ap-settings-nacha-company-id"
                style={{ display: 'block', width: '100%', marginTop: 4 }}
              />
            </label>
            <label style={{ fontSize: 12 }}>
              <span>Company name</span>
              <input
                className="input" maxLength={40}
                value={form.nacha_company_name || ''}
                onChange={(e) => setForm({ ...form, nacha_company_name: e.target.value })}
                data-testid="ap-settings-nacha-company-name"
                style={{ display: 'block', width: '100%', marginTop: 4 }}
              />
            </label>
            <label style={{ fontSize: 12 }}>
              <span>Origin routing (ODFI ABA)</span>
              <input
                className="input" maxLength={9}
                value={form.nacha_origin_routing || ''}
                onChange={(e) => setForm({ ...form, nacha_origin_routing: e.target.value.replace(/\D/g, '').slice(0, 9) })}
                data-testid="ap-settings-nacha-origin-routing"
                style={{ display: 'block', width: '100%', marginTop: 4 }}
              />
            </label>
          </div>
        </fieldset>

        {msg   && <p className="success" data-testid="ap-settings-saved" style={{ color: '#065f46' }}>{msg}</p>}
        {error && <p className="error">{error}</p>}
        <button
          type="submit" className="btn btn--primary"
          disabled={busy} data-testid="ap-settings-save"
          style={{ alignSelf: 'flex-start' }}
        >
          {busy ? 'Saving…' : 'Save settings'}
        </button>
      </form>

      <fieldset data-testid="ap-settings-weekly-queue" style={{ border: '1px solid var(--cf-border, #e5e7eb)', padding: 16, borderRadius: 8, marginTop: 24 }}>
        <legend style={{ padding: '0 8px', fontSize: 13, fontWeight: 600 }}>Weekly AP digest schedule</legend>
        <p style={{ fontSize: 12, color: 'var(--cf-text-secondary)', margin: '0 0 12px' }}>
          When the Weekly Queue email digest is sent. Pick <em>Disabled</em> to silence it for this tenant. Times are UTC; the cron must run hourly.
        </p>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 12, alignItems: 'end' }}>
          <label style={{ fontSize: 12 }}>
            <span>Day of week</span>
            <select
              className="input"
              value={wq.dow}
              disabled={!wq.can_write}
              onChange={(e) => setWq({ ...wq, dow: Number(e.target.value) })}
              data-testid="ap-settings-weekly-queue-dow"
              style={{ display: 'block', width: '100%', marginTop: 4 }}
            >
              <option value={0}>Disabled</option>
              <option value={1}>Monday</option>
              <option value={2}>Tuesday</option>
              <option value={3}>Wednesday</option>
              <option value={4}>Thursday</option>
              <option value={5}>Friday</option>
              <option value={6}>Saturday</option>
              <option value={7}>Sunday (default)</option>
            </select>
          </label>
          <label style={{ fontSize: 12 }}>
            <span>Hour (UTC, 0–23)</span>
            <input
              className="input" type="number" min={0} max={23}
              value={wq.hour}
              disabled={!wq.can_write || wq.dow === 0}
              onChange={(e) => setWq({ ...wq, hour: Math.max(0, Math.min(23, Number(e.target.value || 0))) })}
              data-testid="ap-settings-weekly-queue-hour"
              style={{ display: 'block', width: '100%', marginTop: 4 }}
            />
          </label>
          <button
            type="button" className="btn btn--primary"
            disabled={!wq.can_write || wqBusy}
            onClick={saveWq}
            data-testid="ap-settings-weekly-queue-save"
          >
            {wqBusy ? 'Saving…' : 'Save schedule'}
          </button>
        </div>
        {wqMsg && <p className={wqMsg.startsWith('Error') ? 'error' : 'success'} data-testid="ap-settings-weekly-queue-msg" style={{ marginTop: 12 }}>{wqMsg}</p>}
        {!wq.can_write && <p style={{ marginTop: 12, fontSize: 12, color: 'var(--cf-text-secondary)' }}>Admin / manager role required to change this.</p>}
      </fieldset>
    </section>
  );
}

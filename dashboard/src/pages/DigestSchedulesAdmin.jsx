import React, { useEffect, useState } from 'react';
import { api } from '../lib/api';

const DIGEST_LABELS = {
  money_movement:  { title: 'Money Movement (weekly CFO digest)', cadence: 'weekly' },
  dunning:         { title: 'AR Dunning (daily)',                  cadence: 'daily'  },
  ap_weekly_queue: { title: 'AP Weekly Queue',                     cadence: 'weekly' },
};

const DOW_OPTIONS = [
  { v: 0, label: 'Disabled' },
  { v: 1, label: 'Monday' },
  { v: 2, label: 'Tuesday' },
  { v: 3, label: 'Wednesday' },
  { v: 4, label: 'Thursday' },
  { v: 5, label: 'Friday' },
  { v: 6, label: 'Saturday' },
  { v: 7, label: 'Sunday' },
];

export default function DigestSchedulesAdmin() {
  const [schedules, setSchedules] = useState({});
  const [canWrite, setCanWrite] = useState(false);
  const [loading, setLoading] = useState(true);
  const [savingKey, setSavingKey] = useState(null);
  const [msg, setMsg] = useState(null);

  const load = async () => {
    setLoading(true);
    try {
      const d = await api.get('/api/tenant_digest_schedules.php');
      setSchedules(d.schedules || {});
      setCanWrite(!!d.can_write);
    } catch (e) {
      setMsg({ kind: 'err', text: e.message });
    } finally { setLoading(false); }
  };
  useEffect(() => { load(); }, []);

  const updateLocal = (key, patch) => setSchedules((s) => ({ ...s, [key]: { ...s[key], ...patch } }));

  const save = async (key) => {
    setSavingKey(key); setMsg(null);
    const s = schedules[key];
    try {
      await api.post('/api/tenant_digest_schedules.php', {
        digest_key: key,
        dow:     s.cadence === 'daily' ? 0 : Number(s.dow ?? 1),
        hour:    Number(s.hour ?? 13),
        enabled: !!s.enabled,
      });
      setMsg({ kind: 'ok', text: `${DIGEST_LABELS[key]?.title || key} schedule saved.` });
      load();
    } catch (e) { setMsg({ kind: 'err', text: e.message }); }
    finally { setSavingKey(null); }
  };

  if (loading) return <p>Loading…</p>;

  return (
    <section data-testid="admin-digest-schedules" style={{ maxWidth: 760 }}>
      <header style={{ marginBottom: 20 }}>
        <h1 style={{ margin: 0 }}>Digest schedules</h1>
        <p style={{ color: 'var(--cf-text-secondary)', fontSize: 13, margin: '6px 0 0' }}>
          When each digest gets sent. Times are UTC; the underlying cron must run at least hourly.
          Daily digests ignore the day-of-week.
        </p>
      </header>

      {msg && (
        <p className={msg.kind === 'ok' ? 'success' : 'error'} data-testid={`admin-digest-${msg.kind}`}
           style={{ background: msg.kind === 'ok' ? '#f0fdf4' : '#fef2f2', padding: 10, borderRadius: 6, fontSize: 13 }}>
          {msg.text}
        </p>
      )}

      {Object.entries(DIGEST_LABELS).map(([key, info]) => {
        const s = schedules[key] || {};
        const isDaily = info.cadence === 'daily';
        return (
          <div key={key} data-testid={`admin-digest-row-${key}`}
               style={{ border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8, padding: 16, marginBottom: 12 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
              <div>
                <h3 style={{ margin: 0, fontSize: 15 }}>{info.title}</h3>
                <p style={{ margin: '4px 0 0', fontSize: 12, color: 'var(--cf-text-secondary)' }}>
                  {s.source === 'tenant_override' ? 'Tenant override active' : 'Using platform default'} · cadence: {info.cadence}
                </p>
              </div>
              <label style={{ fontSize: 13, display: 'flex', alignItems: 'center', gap: 6 }}>
                <input type="checkbox" checked={!!s.enabled} disabled={!canWrite}
                       onChange={(e) => updateLocal(key, { enabled: e.target.checked, ...info })}
                       data-testid={`admin-digest-enabled-${key}`} />
                <span>Enabled</span>
              </label>
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 12, marginTop: 12 }}>
              {!isDaily && (
                <label style={{ fontSize: 12 }}>
                  <span>Day of week</span>
                  <select className="input" value={s.dow ?? 1}
                          disabled={!canWrite || !s.enabled}
                          onChange={(e) => updateLocal(key, { dow: Number(e.target.value), ...info })}
                          data-testid={`admin-digest-dow-${key}`}
                          style={{ display: 'block', width: '100%', marginTop: 4 }}>
                    {DOW_OPTIONS.filter(o => o.v !== 0).map((o) => <option key={o.v} value={o.v}>{o.label}</option>)}
                  </select>
                </label>
              )}
              <label style={{ fontSize: 12 }}>
                <span>Hour (UTC, 0–23)</span>
                <input className="input" type="number" min={0} max={23} value={s.hour ?? 13}
                       disabled={!canWrite || !s.enabled}
                       onChange={(e) => updateLocal(key, { hour: Math.max(0, Math.min(23, Number(e.target.value || 0))), ...info })}
                       data-testid={`admin-digest-hour-${key}`}
                       style={{ display: 'block', width: '100%', marginTop: 4 }} />
              </label>
              <div style={{ alignSelf: 'end' }}>
                <button type="button" className="btn btn--primary"
                        onClick={() => save(key)}
                        disabled={!canWrite || savingKey === key}
                        data-testid={`admin-digest-save-${key}`}>
                  {savingKey === key ? 'Saving…' : 'Save'}
                </button>
              </div>
            </div>
          </div>
        );
      })}
    </section>
  );
}

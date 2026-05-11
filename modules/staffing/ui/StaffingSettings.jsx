import React, { useEffect, useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';

/**
 * Staffing Settings — tenant-level configuration.
 * - week_starts_on:           0=Sun, 1=Mon
 * - contracted_hours_per_week
 * - overtime_threshold
 */
export default function StaffingSettings() {
  const { data, loading, error, reload } = useApi('/modules/staffing/api/timesheets.php?action=settings');
  const [form, setForm] = useState({ week_starts_on: 1, contracted_hours_per_week: 40, overtime_threshold: 40 });
  const [saving, setSaving]   = useState(false);
  const [savedAt, setSavedAt] = useState(null);
  const [saveErr, setSaveErr] = useState(null);

  useEffect(() => {
    if (data?.settings) setForm({
      week_starts_on:            data.settings.week_starts_on,
      contracted_hours_per_week: data.settings.contracted_hours_per_week,
      overtime_threshold:        data.settings.overtime_threshold,
    });
  }, [data?.settings]);

  const save = async (e) => {
    e.preventDefault();
    setSaving(true); setSaveErr(null);
    try {
      await api.post('/modules/staffing/api/timesheets.php?action=settings', form);
      setSavedAt(new Date()); reload();
    } catch (e) { setSaveErr(e.message); }
    finally { setSaving(false); }
  };

  return (
    <section className="people-directory" data-testid="staffing-settings">
      <header style={{ marginBottom:'var(--cf-space-4)' }}>
        <h2>Staffing Settings</h2>
        <p style={{ color:'var(--cf-text-secondary)' }}>Tenant-wide defaults for weekly timesheets and overtime.</p>
      </header>

      {loading && <p>Loading…</p>}
      {error && <p className="error" data-testid="staffing-settings-error">Error: {error.message}</p>}

      {!loading && (
        <form onSubmit={save} style={{ maxWidth: 480, display:'grid', gap:'var(--cf-space-3)' }} data-testid="staffing-settings-form">
          <label>
            <div style={{ fontWeight:600, marginBottom:4 }}>Week starts on</div>
            <select
              value={form.week_starts_on}
              onChange={e => setForm({ ...form, week_starts_on: parseInt(e.target.value, 10) })}
              data-testid="staffing-settings-week-start"
              style={{ width:'100%', padding:8, border:'1px solid var(--cf-border, #e5e7eb)', borderRadius:4 }}
            >
              <option value={1}>Monday</option>
              <option value={0}>Sunday</option>
            </select>
          </label>
          <label>
            <div style={{ fontWeight:600, marginBottom:4 }}>Contracted hours per week</div>
            <input type="number" step="0.25" min="0" max="168"
                   value={form.contracted_hours_per_week}
                   onChange={e => setForm({ ...form, contracted_hours_per_week: parseFloat(e.target.value) })}
                   data-testid="staffing-settings-contracted"
                   style={{ width:'100%', padding:8, border:'1px solid var(--cf-border, #e5e7eb)', borderRadius:4 }} />
          </label>
          <label>
            <div style={{ fontWeight:600, marginBottom:4 }}>Overtime threshold (hours/week)</div>
            <input type="number" step="0.25" min="0" max="168"
                   value={form.overtime_threshold}
                   onChange={e => setForm({ ...form, overtime_threshold: parseFloat(e.target.value) })}
                   data-testid="staffing-settings-ot-threshold"
                   style={{ width:'100%', padding:8, border:'1px solid var(--cf-border, #e5e7eb)', borderRadius:4 }} />
          </label>
          <div style={{ display:'flex', gap:'var(--cf-space-2)', alignItems:'center' }}>
            <button className="btn btn--primary" disabled={saving} data-testid="staffing-settings-save">
              {saving ? 'Saving…' : 'Save'}
            </button>
            {savedAt && <span style={{ fontSize:'0.85em', color:'var(--cf-text-secondary)' }} data-testid="staffing-settings-saved">Saved {savedAt.toLocaleTimeString()}</span>}
            {saveErr && <span style={{ fontSize:'0.85em', color:'var(--cf-error, #dc2626)' }} data-testid="staffing-settings-save-error">{saveErr}</span>}
          </div>
        </form>
      )}
    </section>
  );
}

import React, { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowLeft, Calculator, CheckCircle2, Save } from 'lucide-react';
import { api } from '../lib/api';
import { Card } from '../components/UIComponents';

const EMPTY = { payroll_load_pct: '', workers_comp_pct: '', benefits_load_pct: '' };

const toPercent = (value) => {
  const number = Number(value || 0) * 100;
  return number === 0 ? '0' : String(Number(number.toFixed(4)));
};

export default function StaffingEconomicsSettings() {
  const [form, setForm] = useState(EMPTY);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [saved, setSaved] = useState(false);

  const load = async () => {
    setLoading(true); setError('');
    try {
      const response = await api.get('/api/staffing_economics_settings.php');
      const defaults = response?.defaults || {};
      setForm({
        payroll_load_pct: toPercent(defaults.payroll_load_pct),
        workers_comp_pct: toPercent(defaults.workers_comp_pct),
        benefits_load_pct: toPercent(defaults.benefits_load_pct),
      });
    } catch (e) {
      setError(e.message || 'Could not load staffing economics defaults.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { load(); }, []);

  const total = useMemo(() => ['payroll_load_pct', 'workers_comp_pct', 'benefits_load_pct']
    .reduce((sum, key) => sum + Number(form[key] || 0), 0), [form]);

  const save = async (event) => {
    event.preventDefault(); setSaving(true); setError(''); setSaved(false);
    try {
      const response = await api.put('/api/staffing_economics_settings.php', {
        payroll_load_pct: Number(form.payroll_load_pct || 0) / 100,
        workers_comp_pct: Number(form.workers_comp_pct || 0) / 100,
        benefits_load_pct: Number(form.benefits_load_pct || 0) / 100,
      });
      const defaults = response?.defaults || {};
      setForm({
        payroll_load_pct: toPercent(defaults.payroll_load_pct),
        workers_comp_pct: toPercent(defaults.workers_comp_pct),
        benefits_load_pct: toPercent(defaults.benefits_load_pct),
      });
      setSaved(true);
    } catch (e) {
      setError(e.message || 'Could not save staffing economics defaults.');
    } finally {
      setSaving(false);
    }
  };

  return <div style={{ maxWidth: 880 }} data-testid="staffing-economics-settings">
    <Link to="/settings" style={{ display: 'inline-flex', alignItems: 'center', gap: 6, color: 'var(--cf-text-secondary)', textDecoration: 'none', marginBottom: 16 }}>
      <ArrowLeft size={15} /> Settings
    </Link>
    <header style={{ marginBottom: 20 }}>
      <h1 style={{ display: 'flex', alignItems: 'center', gap: 10, margin: 0 }}><Calculator size={24} /> Staffing economics</h1>
      <p style={{ color: 'var(--cf-text-secondary)', margin: '6px 0 0' }}>Default W-2 employer costs for this tenant.</p>
    </header>

    <Card>
      {loading ? <p>Loading...</p> : <form onSubmit={save} data-testid="staffing-economics-form">
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(190px, 1fr))', gap: 14 }}>
          <label><span>Payroll and employer load %</span><input className="input" type="number" min="0" max="500" step="0.01" value={form.payroll_load_pct} onChange={e => setForm({ ...form, payroll_load_pct: e.target.value })} data-testid="staffing-default-payroll-load" required /></label>
          <label><span>Workers compensation %</span><input className="input" type="number" min="0" max="500" step="0.01" value={form.workers_comp_pct} onChange={e => setForm({ ...form, workers_comp_pct: e.target.value })} data-testid="staffing-default-workers-comp" required /></label>
          <label><span>Benefits load %</span><input className="input" type="number" min="0" max="500" step="0.01" value={form.benefits_load_pct} onChange={e => setForm({ ...form, benefits_load_pct: e.target.value })} data-testid="staffing-default-benefits-load" required /></label>
        </div>
        <div style={{ marginTop: 18, padding: 12, border: '1px solid var(--cf-border)', borderRadius: 6 }}>
          <span style={{ color: 'var(--cf-text-secondary)', fontSize: 12 }}>Combined default employer cost</span>
          <strong style={{ display: 'block', fontSize: 20 }}>{total.toFixed(2)}% of W-2 labor pay</strong>
        </div>
        <p style={{ color: 'var(--cf-text-secondary)', fontSize: 13 }}>
          Blank employer-cost fields on a W-2 rate inherit these defaults. A placement value, including zero, overrides the corresponding default. Approved rates remain locked until a rate correction is approved.
        </p>
        {error && <div className="alert alert--err" data-testid="staffing-economics-error">{error}</div>}
        <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
          <button className="btn btn--primary" type="submit" disabled={saving} data-testid="staffing-economics-save"><Save size={16} /> {saving ? 'Saving...' : 'Save defaults'}</button>
          {saved && <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, color: 'var(--cf-success)' }} data-testid="staffing-economics-saved"><CheckCircle2 size={16} /> Saved</span>}
        </div>
      </form>}
    </Card>
  </div>;
}


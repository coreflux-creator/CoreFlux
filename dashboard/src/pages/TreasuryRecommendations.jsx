import React, { useMemo, useState } from 'react';
import { api, useApi } from '../lib/api';
import { CheckCircle, RefreshCw, ShieldCheck, XCircle } from 'lucide-react';

const fmtMoney = (value, currency = 'USD') => {
  const n = Number(value || 0);
  return `${currency === 'USD' ? '$' : `${currency} `}${n.toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
};

const fieldStyle = {
  display: 'grid',
  gap: 4,
  fontSize: 12,
  color: '#475569',
};

export default function TreasuryRecommendations() {
  const [policy, setPolicy] = useState({
    currency: 'USD',
    forecast_days: 30,
    minimum_cash_reserve: 0,
    payroll_reserve: 0,
    tax_reserve: 0,
    ap_reserve: 0,
    operating_reserve: 0,
    materiality_threshold: 10000,
  });
  const [appliedPolicy, setAppliedPolicy] = useState(policy);
  const [busyId, setBusyId] = useState(null);
  const [flash, setFlash] = useState(null);
  const [decisions, setDecisions] = useState({});

  const url = useMemo(() => {
    const qs = new URLSearchParams();
    Object.entries(appliedPolicy).forEach(([key, value]) => {
      if (value !== '' && value !== null && value !== undefined) qs.set(key, String(value));
    });
    return `/api/treasury_recommendations.php?${qs.toString()}`;
  }, [appliedPolicy]);

  const recommendations = useApi(url);
  const data = recommendations.data || {};
  const envelope = data.cash_envelope || {};
  const reservePolicy = data.reserve_policy || {};
  const rows = data.recommendations || [];
  const currency = data.currency || appliedPolicy.currency || 'USD';

  const updatePolicy = (key, value) => {
    setPolicy((prev) => ({ ...prev, [key]: value }));
  };

  const decide = async (row, action) => {
    const note = window.prompt(action === 'accept' ? 'Acceptance note' : 'Dismissal note') || '';
    setBusyId(row.id);
    setFlash(null);
    try {
      await api.post(`/api/treasury_recommendations.php?action=${action}`, {
        recommendation_id: row.id,
        payment_id: row.payment?.id,
        decision_note: note,
        evidence: {
          recommendation_action: row.recommendation_action,
          reserve_policy: row.evidence?.reserve_policy,
          cash_envelope: row.cash_impact,
          approval_gate: row.approval_gate,
        },
      });
      setDecisions((prev) => ({ ...prev, [row.id]: action }));
      setFlash({ kind: 'success', message: `Recommendation ${action === 'accept' ? 'accepted' : 'dismissed'} and audit logged.` });
    } catch (err) {
      setFlash({ kind: 'error', message: err.message || String(err) });
    } finally {
      setBusyId(null);
    }
  };

  return (
    <section data-testid="treasury-recommendations-page" style={{ padding: 24, display: 'grid', gap: 16 }}>
      <header style={{ display: 'flex', justifyContent: 'space-between', gap: 16, alignItems: 'flex-start', flexWrap: 'wrap' }}>
        <div>
          <h2 style={{ margin: 0, fontSize: 20, fontWeight: 700 }}>Treasury Recommendations</h2>
          <p style={{ margin: '4px 0 0', color: '#64748b', fontSize: 13 }}>
            Reserve-aware payment timing with workflow gates and decision audit.
          </p>
        </div>
        <button
          type="button"
          className="btn btn--primary"
          onClick={() => setAppliedPolicy(policy)}
          data-testid="treasury-recommendations-refresh"
          style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}
        >
          <RefreshCw size={15} /> Refresh
        </button>
      </header>

      <div data-testid="treasury-reserve-policy-inputs"
           style={{ border: '1px solid #e2e8f0', borderRadius: 8, padding: 14, background: '#fff', display: 'grid', gap: 12 }}>
        <strong style={{ fontSize: 14 }}>Reserve policy inputs</strong>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(150px,1fr))', gap: 10 }}>
          <PolicyInput label="Minimum cash" value={policy.minimum_cash_reserve} onChange={(v) => updatePolicy('minimum_cash_reserve', v)} />
          <PolicyInput label="Payroll reserve" value={policy.payroll_reserve} onChange={(v) => updatePolicy('payroll_reserve', v)} />
          <PolicyInput label="Tax reserve" value={policy.tax_reserve} onChange={(v) => updatePolicy('tax_reserve', v)} />
          <PolicyInput label="AP reserve" value={policy.ap_reserve} onChange={(v) => updatePolicy('ap_reserve', v)} />
          <PolicyInput label="Operating reserve" value={policy.operating_reserve} onChange={(v) => updatePolicy('operating_reserve', v)} />
          <PolicyInput label="Materiality" value={policy.materiality_threshold} onChange={(v) => updatePolicy('materiality_threshold', v)} />
          <label style={fieldStyle}>
            Window
            <select className="input" value={policy.forecast_days} onChange={(e) => updatePolicy('forecast_days', e.target.value)}>
              <option value={30}>30 days</option>
              <option value={60}>60 days</option>
              <option value={90}>90 days</option>
              <option value={180}>180 days</option>
            </select>
          </label>
          <label style={fieldStyle}>
            Currency
            <input className="input" value={policy.currency} maxLength={3} onChange={(e) => updatePolicy('currency', e.target.value.toUpperCase())} />
          </label>
        </div>
      </div>

      {flash && (
        <div data-testid={`treasury-recommendations-flash-${flash.kind}`}
             style={{
               padding: '10px 12px',
               borderRadius: 6,
               background: flash.kind === 'success' ? '#ecfdf5' : '#fef2f2',
               color: flash.kind === 'success' ? '#047857' : '#b91c1c',
               fontSize: 13,
             }}>
          {flash.message}
        </div>
      )}

      {recommendations.loading && <p data-testid="treasury-recommendations-loading">Loading recommendations...</p>}
      {recommendations.error && <p className="error" data-testid="treasury-recommendations-error">{recommendations.error.message}</p>}

      {!recommendations.loading && !recommendations.error && (
        <>
          <div data-testid="treasury-recommendations-envelope"
               style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(175px,1fr))', gap: 12 }}>
            <Metric label="Required reserves" value={fmtMoney(reservePolicy.required_reserves, currency)} />
            <Metric label="Available now" value={fmtMoney(envelope.available_now_after_reserves, currency)} />
            <Metric label="Lowest available" value={fmtMoney(envelope.lowest_available_after_reserves, currency)} />
            <Metric label="Risk level" value={envelope.risk_level || 'stable'} tone={envelope.risk_level === 'critical' ? '#b91c1c' : envelope.risk_level === 'watch' ? '#b45309' : '#047857'} />
          </div>

          <div data-testid="treasury-recommendations-auditability"
               style={{ border: '1px solid #dbeafe', borderRadius: 8, padding: 12, background: '#eff6ff', display: 'flex', gap: 10, alignItems: 'flex-start' }}>
            <ShieldCheck size={18} color="#1d4ed8" />
            <div style={{ fontSize: 13, color: '#1e3a8a' }}>
              Decisions write recommendation audit events. Payment execution still requires Treasury payment workflow approval and execute permission.
            </div>
          </div>

          <div data-testid="treasury-recommendations-list" style={{ display: 'grid', gap: 10 }}>
            {rows.length === 0 && (
              <div data-testid="treasury-recommendations-empty" style={{ color: '#64748b', fontSize: 13 }}>
                No open payments in this recommendation window.
              </div>
            )}
            {rows.map((row) => (
              <RecommendationRow
                key={row.id}
                row={row}
                currency={currency}
                decision={decisions[row.id]}
                busy={busyId === row.id}
                onAccept={() => decide(row, 'accept')}
                onDismiss={() => decide(row, 'dismiss')}
              />
            ))}
          </div>
        </>
      )}
    </section>
  );
}

function PolicyInput({ label, value, onChange }) {
  return (
    <label style={fieldStyle}>
      {label}
      <input
        className="input"
        type="number"
        min="0"
        step="100"
        value={value}
        onChange={(e) => onChange(e.target.value)}
      />
    </label>
  );
}

function Metric({ label, value, tone = '#0f172a' }) {
  return (
    <div style={{ border: '1px solid #e2e8f0', borderRadius: 8, padding: 12, background: '#fff' }}>
      <div style={{ fontSize: 11, textTransform: 'uppercase', color: '#64748b' }}>{label}</div>
      <div style={{ marginTop: 4, fontSize: 20, fontWeight: 700, color: tone }}>{value}</div>
    </div>
  );
}

function RecommendationRow({ row, currency, decision, busy, onAccept, onDismiss }) {
  const payment = row.payment || {};
  const impact = row.cash_impact || {};
  const gate = row.approval_gate || {};
  return (
    <article data-testid={`treasury-recommendation-${payment.id || row.id}`}
             style={{ border: '1px solid #e2e8f0', borderRadius: 8, padding: 14, background: '#fff', display: 'grid', gap: 10 }}>
      <div style={{ display: 'grid', gridTemplateColumns: 'minmax(0,1fr) auto', gap: 12, alignItems: 'start' }}>
        <div>
          <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
            <strong>{payment.payee_name || 'Payment'}</strong>
            <span style={{ fontSize: 11, background: '#f1f5f9', color: '#334155', padding: '2px 6px', borderRadius: 4 }}>
              {row.recommendation_action}
            </span>
            {gate.approval_required && (
              <span data-testid={`treasury-recommendation-gate-${payment.id}`}
                    style={{ fontSize: 11, background: '#fef3c7', color: '#92400e', padding: '2px 6px', borderRadius: 4 }}>
                approval gated
              </span>
            )}
          </div>
          <div style={{ marginTop: 3, color: '#64748b', fontSize: 12 }}>
            {payment.payment_number || `#${payment.id}`} / {payment.status} / {payment.payment_date} / {fmtMoney(payment.amount, payment.currency || currency)}
          </div>
        </div>
        <div style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
          {decision && <span data-testid={`treasury-recommendation-decision-${payment.id}`} style={{ color: '#047857', fontSize: 12 }}>{decision}</span>}
          <button type="button" className="btn btn--ghost" disabled={busy} onClick={onAccept} data-testid={`treasury-recommendation-accept-${payment.id}`} title="Accept recommendation">
            <CheckCircle size={15} /> Accept
          </button>
          <button type="button" className="btn btn--ghost" disabled={busy} onClick={onDismiss} data-testid={`treasury-recommendation-dismiss-${payment.id}`} title="Dismiss recommendation">
            <XCircle size={15} /> Dismiss
          </button>
        </div>
      </div>
      <p style={{ margin: 0, fontSize: 13, color: '#334155' }}>{row.rationale}</p>
      <div data-testid={`treasury-recommendation-evidence-${payment.id}`}
           style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(170px,1fr))', gap: 8, fontSize: 12 }}>
        <Evidence label="Available after payment" value={fmtMoney(impact.available_after_payment, currency)} />
        <Evidence label="Lowest after payment" value={fmtMoney(impact.lowest_available_after_payment, currency)} />
        <Evidence label="Next workflow step" value={gate.next_workflow_step || 'review'} />
        <Evidence label="Approval permission" value={gate.approval_permission || 'treasury.approve_payment'} />
      </div>
    </article>
  );
}

function Evidence({ label, value }) {
  return (
    <div style={{ background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: 6, padding: 8 }}>
      <div style={{ color: '#64748b', fontSize: 11 }}>{label}</div>
      <div style={{ color: '#0f172a', fontWeight: 600, marginTop: 2 }}>{value}</div>
    </div>
  );
}

import React, { useEffect, useMemo, useState } from 'react';
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
  const [savingPolicy, setSavingPolicy] = useState(false);
  const [flash, setFlash] = useState(null);
  const [decisions, setDecisions] = useState({});
  const [decisionEvidence, setDecisionEvidence] = useState(null);
  const [decisionEvidenceLoading, setDecisionEvidenceLoading] = useState(false);
  const [loadedPolicyVersion, setLoadedPolicyVersion] = useState(null);
  const policyDefaults = useApi('/api/v1/treasury/policy');
  const decisionHistory = useApi('/api/v1/treasury/recommendations/decisions?limit=25');
  const exceptionList = useApi('/api/v1/treasury/recommendations/exceptions?status=all&limit=50');

  useEffect(() => {
    const saved = policyDefaults.data?.policy;
    if (!saved || loadedPolicyVersion === saved.policy_version) return;
    const nextPolicy = {
      currency: saved.currency || 'USD',
      forecast_days: saved.forecast_days || 30,
      minimum_cash_reserve: saved.minimum_cash_reserve || 0,
      payroll_reserve: saved.payroll_reserve || 0,
      tax_reserve: saved.tax_reserve || 0,
      ap_reserve: saved.ap_reserve || 0,
      operating_reserve: saved.operating_reserve || 0,
      materiality_threshold: saved.materiality_threshold || 10000,
      review_cadence_days: saved.review_cadence_days || 30,
      effective_date: saved.effective_date || new Date().toISOString().slice(0, 10),
    };
    setPolicy(nextPolicy);
    setAppliedPolicy(nextPolicy);
    setLoadedPolicyVersion(saved.policy_version);
  }, [policyDefaults.data, loadedPolicyVersion]);

  const url = useMemo(() => {
    const qs = new URLSearchParams();
    Object.entries(appliedPolicy).forEach(([key, value]) => {
      if (value !== '' && value !== null && value !== undefined) qs.set(key, String(value));
    });
    return `/api/v1/treasury/recommendations?${qs.toString()}`;
  }, [appliedPolicy]);

  const recommendations = useApi(url);
  const data = recommendations.data || {};
  const envelope = data.cash_envelope || {};
  const reservePolicy = data.reserve_policy || {};
  const rows = data.recommendations || [];
  const summary = data.summary || {};
  const reviewQueue = data.review_queue || [];
  const reviewControl = data.review_control || {};
  const policyReview = reviewControl.policy_review || {};
  const sourceDetail = data.evidence?.source_detail || {};
  const currency = data.currency || appliedPolicy.currency || 'USD';

  const updatePolicy = (key, value) => {
    setPolicy((prev) => ({ ...prev, [key]: value }));
  };

  const savePolicy = async () => {
    setSavingPolicy(true);
    setFlash(null);
    try {
      const res = await api.post('/api/v1/treasury/policy', policy);
      const saved = res.policy || {};
      const nextPolicy = {
        ...policy,
        currency: saved.currency || policy.currency,
        forecast_days: saved.forecast_days || policy.forecast_days,
        minimum_cash_reserve: saved.minimum_cash_reserve ?? policy.minimum_cash_reserve,
        payroll_reserve: saved.payroll_reserve ?? policy.payroll_reserve,
        tax_reserve: saved.tax_reserve ?? policy.tax_reserve,
        ap_reserve: saved.ap_reserve ?? policy.ap_reserve,
        operating_reserve: saved.operating_reserve ?? policy.operating_reserve,
        materiality_threshold: saved.materiality_threshold ?? policy.materiality_threshold,
        review_cadence_days: saved.review_cadence_days ?? policy.review_cadence_days,
        effective_date: saved.effective_date || policy.effective_date,
      };
      setPolicy(nextPolicy);
      setAppliedPolicy(nextPolicy);
      setLoadedPolicyVersion(saved.policy_version);
      policyDefaults.reload();
      setFlash({ kind: 'success', message: `Treasury policy saved as version ${saved.policy_version || '1'}.` });
    } catch (err) {
      setFlash({ kind: 'error', message: err.message || String(err) });
    } finally {
      setSavingPolicy(false);
    }
  };

  const decide = async (row, action) => {
    const note = window.prompt(action === 'accept' ? 'Acceptance note' : 'Dismissal note') || '';
    setBusyId(row.id);
    setFlash(null);
    try {
      await api.post(`/api/v1/treasury/recommendations/${action}`, {
        recommendation_id: row.id,
        payment_id: row.payment?.id,
        decision_note: note,
        evidence: {
          recommendation_action: row.recommendation_action,
          reserve_policy: row.evidence?.reserve_policy,
          cash_envelope: row.cash_impact,
          approval_gate: row.approval_gate,
          projection: row.evidence?.projection,
          variance_context: row.evidence?.variance_context,
          source_detail_summary: row.evidence?.source_detail_summary,
          source_classification_totals: row.evidence?.source_classification_totals,
          freshness_control: row.freshness_control,
        },
      });
      setDecisions((prev) => ({ ...prev, [row.id]: { decision: action, pending: true } }));
      recommendations.reload();
      decisionHistory.reload();
      exceptionList.reload();
      setFlash({ kind: 'success', message: `Recommendation ${action === 'accept' ? 'accepted' : 'dismissed'} and audit logged to the decision ledger.` });
    } catch (err) {
      setFlash({ kind: 'error', message: err.message || String(err) });
    } finally {
      setBusyId(null);
    }
  };

  const handoff = async (row, action) => {
    const paymentId = row.payment?.id;
    if (!paymentId) return;
    const beforeStatus = row.payment?.status || null;
    let body = {};
    if (action === 'reject') {
      const reason = window.prompt('Reason for rejection');
      if (!reason) return;
      body = { reason };
    }
    setBusyId(`${row.id}:${action}`);
    setFlash(null);
    try {
      const res = await api.post(`/api/v1/treasury/payments/${paymentId}/${action}`, body);
      await logHandoff(row, action, 'success', beforeStatus, res.status || (res.approved ? 'approved' : beforeStatus), res, null);
      recommendations.reload();
      decisionHistory.reload();
      exceptionList.reload();
      setFlash({ kind: 'success', message: `Payment workflow ${action} completed. Status: ${res.status || (res.approved ? 'approved' : 'updated')}.` });
    } catch (err) {
      await logHandoff(row, action, 'failure', beforeStatus, beforeStatus, {}, err.message || String(err));
      setFlash({ kind: 'error', message: err.message || String(err) });
    } finally {
      setBusyId(null);
    }
  };

  const logHandoff = async (row, action, result, beforeStatus, afterStatus, workflowResponse, errorText) => {
    try {
      await api.post('/api/v1/treasury/recommendations/handoff-log', {
        recommendation_id: row.id,
        payment_id: row.payment?.id,
        handoff_action: action,
        result,
        payment_status_before: beforeStatus,
        payment_status_after: afterStatus,
        workflow_response: workflowResponse || {},
        error_text: errorText || null,
      });
    } catch (logErr) {
      console.warn('Recommendation handoff log failed', logErr);
    }
  };

  const loadDecisionEvidence = async (row) => {
    if (!row?.id) return;
    setDecisionEvidenceLoading(true);
    setFlash(null);
    try {
      const res = await api.get(`/api/v1/treasury/recommendations/decision-detail/${row.id}`);
      setDecisionEvidence(res.decision || null);
    } catch (err) {
      setFlash({ kind: 'error', message: err.message || String(err) });
    } finally {
      setDecisionEvidenceLoading(false);
    }
  };

  const exceptionAction = async (action, row, existingException = null) => {
    setBusyId(`${row.id}:${action}`);
    setFlash(null);
    try {
      let body = {};
      if (action === 'open_exception') {
        body = {
          recommendation_id: row.id,
          payment_id: row.payment?.id,
          recommendation_action: row.recommendation_action,
          severity: recommendationSeverity(row),
          reason: row.rationale || 'Treasury recommendation requires review',
          policy_version: row.approval_gate?.policy_version,
        };
      } else if (action === 'assign_exception') {
        const owner = window.prompt('Owner user id');
        if (!owner) return;
        body = { exception_id: existingException?.id, owner_user_id: Number(owner) };
      } else {
        const note = window.prompt(action === 'resolve_exception' ? 'Resolution note' : 'Dismissal note');
        if (!note) return;
        body = {
          exception_id: existingException?.id,
          resolution_note: note,
          status: action === 'dismiss_exception' ? 'dismissed' : 'resolved',
        };
        action = 'resolve_exception';
      }
      const res = await api.post(`/api/v1/treasury/recommendations/${action}`, body);
      recommendations.reload();
      exceptionList.reload();
      setFlash({ kind: 'success', message: `Exception ${res.exception?.status || 'updated'} and audit logged.` });
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
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', justifyContent: 'flex-end' }}>
          <button
            type="button"
            className="btn btn--ghost"
            onClick={savePolicy}
            disabled={savingPolicy}
            data-testid="treasury-policy-save"
          >
            {savingPolicy ? 'Saving...' : 'Save policy'}
          </button>
          <button
            type="button"
            className="btn btn--primary"
            onClick={() => setAppliedPolicy(policy)}
            data-testid="treasury-recommendations-refresh"
            style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}
          >
            <RefreshCw size={15} /> Refresh
          </button>
        </div>
      </header>

      <div data-testid="treasury-reserve-policy-inputs"
           style={{ border: '1px solid #e2e8f0', borderRadius: 8, padding: 14, background: '#fff', display: 'grid', gap: 12 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, alignItems: 'baseline', flexWrap: 'wrap' }}>
          <strong style={{ fontSize: 14 }}>Reserve policy inputs</strong>
          <span data-testid="treasury-policy-version" style={{ color: '#64748b', fontSize: 12 }}>
            Policy v{data.reserve_policy?.policy_version ?? policyDefaults.data?.policy?.policy_version ?? 0} / effective {data.reserve_policy?.effective_date ?? policy.effective_date ?? 'today'}
          </span>
        </div>
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
          <PolicyInput label="Review cadence days" value={policy.review_cadence_days || 30} onChange={(v) => updatePolicy('review_cadence_days', v)} />
          <label style={fieldStyle}>
            Effective date
            <input className="input" type="date" value={policy.effective_date || ''} onChange={(e) => updatePolicy('effective_date', e.target.value)} />
          </label>
        </div>
        {policyDefaults.error && (
          <div data-testid="treasury-policy-load-error" className="error" style={{ fontSize: 12 }}>
            {policyDefaults.error.message}
          </div>
        )}
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
            <Metric label="Policy review" value={policyReview.status || 'current'} tone={policyReview.status === 'overdue' ? '#b91c1c' : policyReview.status === 'due_soon' ? '#b45309' : '#047857'} />
          </div>

          <div data-testid="treasury-recommendations-summary"
               style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(145px,1fr))', gap: 10 }}>
            <Metric label="Pay now" value={summary.actions?.pay_now || 0} />
            <Metric label="Submit" value={summary.actions?.submit_for_approval || 0} />
            <Metric label="Hold" value={summary.actions?.hold_for_review || 0} tone="#b45309" />
            <Metric label="Split/escalate" value={(summary.actions?.split || 0) + (summary.actions?.defer_or_escalate || 0)} tone="#b91c1c" />
            <Metric label="Review queue" value={summary.review_queue_count || 0} tone={(summary.review_queue_count || 0) > 0 ? '#b91c1c' : '#047857'} />
            <Metric label="Decided" value={(summary.decisions?.accepted || 0) + (summary.decisions?.dismissed || 0)} />
            <Metric label="Stale reviews" value={reviewControl.counts?.stale_review_items || 0} tone={(reviewControl.counts?.stale_review_items || 0) > 0 ? '#b91c1c' : '#047857'} />
            <Metric label="Unowned exceptions" value={reviewControl.counts?.unowned_exceptions || 0} tone={(reviewControl.counts?.unowned_exceptions || 0) > 0 ? '#b45309' : '#047857'} />
          </div>

          <ReviewControlPanel data={reviewControl} />
          <ProjectionSourcePanel data={sourceDetail} currency={currency} />

          <div data-testid="treasury-recommendations-auditability"
               style={{ border: '1px solid #dbeafe', borderRadius: 8, padding: 12, background: '#eff6ff', display: 'flex', gap: 10, alignItems: 'flex-start' }}>
            <ShieldCheck size={18} color="#1d4ed8" />
            <div style={{ fontSize: 13, color: '#1e3a8a' }}>
              Decisions, exceptions, and review freshness are advisory controls. Payment execution still requires Treasury payment workflow approval and execute permission.
            </div>
          </div>

          <ReviewQueue
            rows={reviewQueue}
            currency={currency}
            busyId={busyId}
            onExceptionAction={exceptionAction}
          />

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
                decision={decisions[row.id] || row.latest_decision}
                busy={busyId === row.id || String(busyId || '').startsWith(`${row.id}:`)}
                onAccept={() => decide(row, 'accept')}
                onDismiss={() => decide(row, 'dismiss')}
                onHandoff={(action) => handoff(row, action)}
              />
            ))}
          </div>

          <DecisionHistory
            data={decisionHistory}
            onLoadEvidence={loadDecisionEvidence}
            evidenceDetail={decisionEvidence}
            evidenceLoading={decisionEvidenceLoading}
          />
          <ExceptionPanel data={exceptionList} />
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

function ReviewControlPanel({ data }) {
  const policy = data.policy_review || {};
  const counts = data.counts || {};
  return (
    <section data-testid="treasury-recommendations-review-control"
             style={{ border: '1px solid #e0e7ff', borderRadius: 8, padding: 14, background: '#eef2ff', display: 'grid', gap: 10 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, alignItems: 'baseline', flexWrap: 'wrap' }}>
        <strong style={{ fontSize: 14 }}>Review control</strong>
        <span data-testid="treasury-policy-review-due" style={{ color: '#4338ca', fontSize: 12 }}>
          Policy {policy.status || 'current'} / due {policy.next_review_due_date || 'N/A'}
        </span>
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(145px,1fr))', gap: 8 }}>
        <Evidence label="Attention items" value={counts.attention_items || 0} />
        <Evidence label="Stale review items" value={counts.stale_review_items || 0} />
        <Evidence label="Open exceptions" value={counts.open_exceptions || 0} />
        <Evidence label="Unowned exceptions" value={counts.unowned_exceptions || 0} />
      </div>
      <div style={{ color: '#4338ca', fontSize: 12 }}>
        Cadence source: {data.cadence_source || 'tenant_treasury_policy.review_cadence_days'} / ownership source: {data.ownership_source || 'treasury_recommendation_exceptions.owner_user_id'}
      </div>
    </section>
  );
}

function ProjectionSourcePanel({ data, currency }) {
  const totals = data.classification_totals || {};
  const summary = data.summary || {};
  const net = (row) => Number(row?.inflows || 0) - Number(row?.outflows || 0);
  return (
    <section data-testid="treasury-recommendations-source-detail"
             style={{ border: '1px solid #dcfce7', borderRadius: 8, padding: 14, background: '#f0fdf4', display: 'grid', gap: 10 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, alignItems: 'baseline', flexWrap: 'wrap' }}>
        <strong style={{ fontSize: 14 }}>Projection source detail</strong>
        <span style={{ color: '#166534', fontSize: 12 }}>
          {(summary.inflows || []).length} inflow sources / {(summary.outflows || []).length} outflow sources
        </span>
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(145px,1fr))', gap: 8 }}>
        {['actual', 'scheduled', 'expected', 'forecasted'].map((key) => (
          <Evidence
            key={key}
            label={key}
            value={fmtMoney(net(totals[key]), currency)}
          />
        ))}
      </div>
    </section>
  );
}

function RecommendationRow({ row, currency, decision, busy, onAccept, onDismiss, onHandoff }) {
  const payment = row.payment || {};
  const impact = row.cash_impact || {};
  const gate = row.approval_gate || {};
  const decisionLabel = typeof decision === 'string' ? decision : decision?.decision;
  const accepted = decisionLabel === 'accept';
  const decidedAt = typeof decision === 'object' ? decision?.decided_at : null;
  const evidenceHash = typeof decision === 'object' ? decision?.evidence_hash : null;
  const handoffActions = accepted ? recommendationHandoffActions(row) : [];
  const latestHandoff = row.latest_handoff;
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
          {decisionLabel && (
            <span data-testid={`treasury-recommendation-decision-${payment.id}`} style={{ color: '#047857', fontSize: 12 }}>
              {decisionLabel}{decidedAt ? ` / ${decidedAt}` : ''}
            </span>
          )}
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
        <Evidence label="Workflow handoff" value={`${gate.workflow_resource || 'treasury.payment'} / ${gate.next_workflow_step || 'review'}`} />
        <Evidence label="Approval permission" value={gate.approval_permission || 'treasury.approve_payment'} />
        <Evidence label="Decision evidence hash" value={evidenceHash || 'None'} />
        <Evidence
          label="Latest handoff"
          value={latestHandoff ? `${latestHandoff.handoff_action} / ${latestHandoff.result} / ${latestHandoff.payment_status_before || 'N/A'} -> ${latestHandoff.payment_status_after || 'N/A'}` : 'None'}
        />
      </div>
      <div data-testid={`treasury-recommendation-handoff-${payment.id}`}
           style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap', borderTop: '1px solid #e2e8f0', paddingTop: 10 }}>
        <span style={{ color: '#64748b', fontSize: 12 }}>
          {accepted ? 'Accepted recommendation handoff' : 'Accept recommendation to enable workflow handoff'}
        </span>
        {handoffActions.map((action) => (
          <button
            key={action.action}
            type="button"
            className="btn btn--ghost"
            disabled={busy}
            onClick={() => onHandoff(action.action)}
            data-testid={`treasury-recommendation-handoff-${action.action}-${payment.id}`}
            title={action.title}
          >
            {action.label}
          </button>
        ))}
      </div>
    </article>
  );
}

function recommendationHandoffActions(row) {
  const status = row.payment?.status;
  const action = row.recommendation_action;
  if (status === 'draft' && action === 'submit_for_approval') {
    return [{ action: 'submit', label: 'Submit', title: 'Submit through Treasury payment workflow' }];
  }
  if (status === 'pending_approval') {
    return [
      { action: 'approve', label: 'Approve', title: 'Approve through Treasury payment workflow' },
      { action: 'reject', label: 'Reject', title: 'Reject through Treasury payment workflow' },
    ];
  }
  if (['approved', 'scheduled'].includes(status) && action === 'pay_now') {
    return [{ action: 'execute', label: 'Execute', title: 'Execute through Treasury payment workflow' }];
  }
  return [];
}

function recommendationSeverity(row) {
  if (Number(row.cash_impact?.lowest_available_after_payment || 0) < 0) return 'critical';
  if (['split', 'defer_or_escalate'].includes(row.recommendation_action)) return 'high';
  return row.approval_gate?.material_recommendation ? 'medium' : 'medium';
}

function ReviewQueue({ rows, currency, busyId, onExceptionAction }) {
  return (
    <section data-testid="treasury-recommendations-review-queue"
             style={{ border: '1px solid #fee2e2', borderRadius: 8, padding: 14, background: '#fff7ed', display: 'grid', gap: 10 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, alignItems: 'baseline' }}>
        <strong style={{ fontSize: 14 }}>Review queue</strong>
        <span style={{ color: '#9a3412', fontSize: 12 }}>{rows.length} item{rows.length === 1 ? '' : 's'}</span>
      </div>
      {rows.length === 0 && (
        <div data-testid="treasury-recommendations-review-empty" style={{ color: '#64748b', fontSize: 13 }}>
          No reserve-breach, split, or escalation recommendations in this window.
        </div>
      )}
      {rows.slice(0, 6).map((row) => (
        <ReviewQueueRow
          key={row.id}
          row={row}
          currency={currency}
          busy={String(busyId || '').startsWith(`${row.id}:`)}
          onExceptionAction={onExceptionAction}
        />
      ))}
    </section>
  );
}

function ReviewQueueRow({ row, currency, busy, onExceptionAction }) {
  const exception = row.latest_exception;
  const freshness = row.freshness_control || {};
  const openStatus = exception?.status;
  const terminal = ['resolved', 'dismissed'].includes(openStatus);
  return (
        <div key={row.id}
             data-testid={`treasury-review-queue-row-${row.payment?.id || row.id}`}
             style={{ display: 'grid', gridTemplateColumns: 'minmax(0,1fr) auto', gap: 10, borderTop: '1px solid #fed7aa', paddingTop: 8, fontSize: 13 }}>
          <div>
            <strong>{row.payment?.payee_name || 'Payment'}</strong>
            <div style={{ color: '#64748b', fontSize: 12 }}>
              {row.recommendation_action} / {row.handoff?.next_workflow_step || 'review'} / {fmtMoney(row.payment?.amount, row.payment?.currency || currency)}
            </div>
            <div style={{ color: '#7c2d12', fontSize: 12 }}>{row.rationale}</div>
            <div data-testid={`treasury-review-freshness-${row.payment?.id || row.id}`} style={{ marginTop: 4, color: freshness.review_status === 'stale' ? '#b91c1c' : freshness.review_status === 'attention' ? '#b45309' : '#475569', fontSize: 12 }}>
              Freshness {freshness.review_status || 'current'} / age {freshness.review_age_days ?? 0}d / due in {freshness.payment_due_in_days ?? 0}d
            </div>
            {exception && (
              <div data-testid={`treasury-exception-status-${row.payment?.id || row.id}`} style={{ marginTop: 4, color: '#0f172a', fontSize: 12 }}>
                Exception {exception.status} / severity {exception.severity} / owner {exception.owner_user_id || 'unassigned'}
              </div>
            )}
          </div>
          <div style={{ color: '#64748b', fontSize: 12, textAlign: 'right', display: 'grid', gap: 6, justifyItems: 'end' }}>
            <span>Policy v{row.approval_gate?.policy_version || 0}</span>
            {!exception && (
              <button type="button" className="btn btn--ghost" disabled={busy} onClick={() => onExceptionAction('open_exception', row)} data-testid={`treasury-exception-open-${row.payment?.id || row.id}`}>
                Open
              </button>
            )}
            {exception && !terminal && (
              <>
                <button type="button" className="btn btn--ghost" disabled={busy} onClick={() => onExceptionAction('assign_exception', row, exception)} data-testid={`treasury-exception-assign-${row.payment?.id || row.id}`}>
                  Assign
                </button>
                <button type="button" className="btn btn--ghost" disabled={busy} onClick={() => onExceptionAction('resolve_exception', row, exception)} data-testid={`treasury-exception-resolve-${row.payment?.id || row.id}`}>
                  Resolve
                </button>
                <button type="button" className="btn btn--ghost" disabled={busy} onClick={() => onExceptionAction('dismiss_exception', row, exception)} data-testid={`treasury-exception-dismiss-${row.payment?.id || row.id}`}>
                  Dismiss
                </button>
              </>
            )}
          </div>
        </div>
  );
}

function ExceptionPanel({ data }) {
  const rows = data.data?.rows || [];
  return (
    <section data-testid="treasury-recommendations-exception-panel"
             style={{ border: '1px solid #e2e8f0', borderRadius: 8, padding: 14, background: '#fff', display: 'grid', gap: 10 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, alignItems: 'baseline' }}>
        <strong style={{ fontSize: 14 }}>Exception ownership</strong>
        <span style={{ color: '#64748b', fontSize: 12 }}>{rows.length} recent</span>
      </div>
      {data.loading && <div style={{ color: '#64748b', fontSize: 13 }}>Loading exceptions...</div>}
      {data.error && <div className="error" style={{ fontSize: 13 }}>{data.error.message}</div>}
      {!data.loading && !data.error && rows.length === 0 && (
        <div data-testid="treasury-recommendations-exception-empty" style={{ color: '#64748b', fontSize: 13 }}>
          No recommendation exceptions have been opened.
        </div>
      )}
      {rows.slice(0, 10).map((row) => (
        <div key={row.id}
             data-testid={`treasury-exception-row-${row.id}`}
             style={{ display: 'grid', gridTemplateColumns: 'auto minmax(0,1fr) auto', gap: 10, borderTop: '1px solid #e2e8f0', paddingTop: 8, fontSize: 12 }}>
          <strong style={{ color: row.status === 'resolved' ? '#047857' : row.severity === 'critical' ? '#b91c1c' : '#b45309' }}>{row.status}</strong>
          <div style={{ minWidth: 0 }}>
            <div style={{ color: '#0f172a' }}>{row.recommendation_action} / payment {row.payment_id || 'N/A'} / owner {row.owner_user_id || 'unassigned'}</div>
            <div style={{ color: '#64748b', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
              {row.resolution_note || row.reason || 'No note'}
            </div>
          </div>
          <span style={{ color: '#64748b', whiteSpace: 'nowrap' }}>Policy v{row.policy_version || 0}</span>
        </div>
      ))}
    </section>
  );
}

function DecisionHistory({ data, onLoadEvidence, evidenceDetail, evidenceLoading }) {
  const rows = data.data?.rows || [];
  return (
    <section data-testid="treasury-recommendations-decision-history"
             style={{ border: '1px solid #e2e8f0', borderRadius: 8, padding: 14, background: '#fff', display: 'grid', gap: 10 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, alignItems: 'baseline' }}>
        <strong style={{ fontSize: 14 }}>Decision history</strong>
        <span style={{ color: '#64748b', fontSize: 12 }}>{rows.length} recent</span>
      </div>
      {data.loading && <div style={{ color: '#64748b', fontSize: 13 }}>Loading decision history...</div>}
      {data.error && <div className="error" style={{ fontSize: 13 }}>{data.error.message}</div>}
      {!data.loading && !data.error && rows.length === 0 && (
        <div data-testid="treasury-recommendations-decision-history-empty" style={{ color: '#64748b', fontSize: 13 }}>
          No recommendation decisions have been recorded yet.
        </div>
      )}
      {rows.slice(0, 10).map((row) => (
        <div key={row.id}
             data-testid={`treasury-decision-history-row-${row.id}`}
             style={{ display: 'grid', gridTemplateColumns: 'auto minmax(0,1fr) auto', gap: 10, borderTop: '1px solid #e2e8f0', paddingTop: 8, fontSize: 12 }}>
          <strong style={{ color: row.decision === 'accept' ? '#047857' : '#b45309' }}>{row.decision}</strong>
          <div style={{ minWidth: 0 }}>
            <div style={{ color: '#0f172a' }}>{row.recommendation_action || 'recommendation'} / payment {row.payment_id || 'N/A'}</div>
            <div style={{ color: '#64748b', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
              {row.decision_note || 'No note'} / hash {row.evidence_hash}
            </div>
          </div>
          <div style={{ display: 'grid', gap: 6, justifyItems: 'end' }}>
            <span style={{ color: '#64748b', whiteSpace: 'nowrap' }}>{row.decided_at}</span>
            <button type="button" className="btn btn--ghost" onClick={() => onLoadEvidence(row)} data-testid={`treasury-decision-evidence-${row.id}`}>
              Evidence
            </button>
          </div>
        </div>
      ))}
      {evidenceLoading && <div style={{ color: '#64748b', fontSize: 13 }}>Loading decision evidence...</div>}
      {evidenceDetail && (
        <div data-testid="treasury-decision-evidence-detail"
             style={{ borderTop: '1px solid #e2e8f0', paddingTop: 10, display: 'grid', gap: 6 }}>
          <strong style={{ fontSize: 13 }}>Evidence packet #{evidenceDetail.id}</strong>
          <div style={{ color: '#64748b', fontSize: 12 }}>Hash {evidenceDetail.evidence_hash}</div>
          <pre style={{ margin: 0, maxHeight: 220, overflow: 'auto', background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: 6, padding: 8, fontSize: 11 }}>
            {JSON.stringify(evidenceDetail.evidence || {}, null, 2)}
          </pre>
        </div>
      )}
    </section>
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

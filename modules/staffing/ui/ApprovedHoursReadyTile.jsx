import React from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../../../dashboard/src/lib/api';

/**
 * Cross-module "approved hours ready" tile.
 *
 * Surfaces a one-line headline on the Payroll / AP / Billing dashboards
 * so operators see, at a glance, how many approved time entries are
 * sitting unprocessed for their workflow.  Clicking the CTA takes them
 * straight into the picker modal (or pre-filtered list) that turns the
 * hours into invoices / bills / payroll runs.
 *
 * Props:
 *   variant   'billing' | 'ap' | 'payroll'
 *   onPick    optional click handler (e.g. open the InvoiceFromTimeEntriesModal)
 *   to        optional react-router target (renders a Link instead)
 */
const VARIANTS = {
  billing: {
    title:  'Approved hours ready to invoice',
    gradient:  'linear-gradient(135deg, #2563eb, #7c3aed)',
    cta:    'Bill these hours →',
    fields: (d) => [
      { label: 'Hours',      value: Number(d.hours ?? 0).toFixed(2) },
      { label: 'Placements', value: d.placements ?? 0 },
      { label: 'Clients',    value: d.clients ?? 0 },
      { label: 'Est. value', value: `$${Number(d.estimated_amount ?? 0).toLocaleString('en-US', { maximumFractionDigits: 0 })}` },
    ],
    sub: (d) => d.earliest_date && d.latest_date
      ? `Period: ${d.earliest_date} → ${d.latest_date}`
      : 'No approved billable entries waiting.',
  },
  ap: {
    title:  'Approved hours ready to pay',
    gradient:  'linear-gradient(135deg, #059669, #2563eb)',
    cta:    'Bill these to AP →',
    fields: (d) => [
      { label: 'Hours',      value: Number(d.hours ?? 0).toFixed(2) },
      { label: 'Vendors',    value: d.vendors ?? 0 },
      { label: 'Placements', value: d.placements ?? 0 },
      { label: 'Est. cost',  value: `$${Number(d.estimated_amount ?? 0).toLocaleString('en-US', { maximumFractionDigits: 0 })}` },
    ],
    sub: (d) => d.earliest_date && d.latest_date
      ? `Period: ${d.earliest_date} → ${d.latest_date}`
      : 'No approved payable entries waiting.',
  },
  payroll: {
    title:  'Approved W-2 hours ready for payroll',
    gradient:  'linear-gradient(135deg, #db2777, #f59e0b)',
    cta:    'Start a payroll run →',
    fields: (d) => [
      { label: 'Hours',     value: Number(d.hours ?? 0).toFixed(2) },
      { label: 'Employees', value: d.employees ?? 0 },
      { label: 'Entries',   value: d.entry_count ?? 0 },
    ],
    sub: (d) => d.earliest_date && d.latest_date
      ? `Period: ${d.earliest_date} → ${d.latest_date}`
      : 'No approved W-2 entries waiting.',
  },
};

export default function ApprovedHoursReadyTile({ variant = 'billing', onPick, to }) {
  const { data, loading } = useApi('/modules/staffing/api/timesheets.php?action=approved_hours_ready');
  const v = VARIANTS[variant] || VARIANTS.billing;
  const bucket = data?.[variant] || {};
  const hours = Number(bucket.hours || 0);
  const isEmpty = hours <= 0;

  const ctaProps = to
    ? { as: Link, to }
    : { onClick: onPick, type: 'button' };

  return (
    <div data-testid={`approved-hours-ready-${variant}`}
         style={{
           background: '#0f172a', color: '#fff', borderRadius: 12, padding: 18,
           position: 'relative', overflow: 'hidden', minHeight: 130, marginBottom: 16,
         }}>
      <div style={{
        position: 'absolute', inset: 0,
        background: v.gradient, opacity: isEmpty ? 0.20 : 0.55,
      }} />
      <div style={{ position: 'relative' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
          <div>
            <div style={{ fontSize: 11, letterSpacing: '0.08em', textTransform: 'uppercase', color: 'rgba(255,255,255,0.75)' }}>
              Approved hours
            </div>
            <h3 style={{ margin: '4px 0 0', fontSize: 18, fontWeight: 600 }}
                data-testid={`approved-hours-ready-${variant}-title`}>{v.title}</h3>
          </div>
          {!isEmpty && (
            <CtaButton {...ctaProps} data-testid={`approved-hours-ready-${variant}-cta`}>
              {v.cta}
            </CtaButton>
          )}
        </div>

        {loading && <p style={{ marginTop: 12, color: 'rgba(255,255,255,0.7)' }} data-testid={`approved-hours-ready-${variant}-loading`}>Counting hours…</p>}

        {!loading && (
          <>
            <div style={{ display: 'flex', gap: 26, marginTop: 14 }}
                 data-testid={`approved-hours-ready-${variant}-stats`}>
              {v.fields(bucket).map(f => (
                <div key={f.label}>
                  <div style={{ fontSize: 11, color: 'rgba(255,255,255,0.75)', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                    {f.label}
                  </div>
                  <div style={{ fontSize: 24, fontWeight: 700, marginTop: 2 }}>
                    {f.value}
                  </div>
                </div>
              ))}
            </div>
            <p style={{ marginTop: 12, fontSize: 12, color: 'rgba(255,255,255,0.85)' }}
               data-testid={`approved-hours-ready-${variant}-sub`}>
              {v.sub(bucket)}
            </p>
          </>
        )}
      </div>
    </div>
  );
}

function CtaButton({ as: As = 'button', children, ...rest }) {
  const Comp = As;
  return (
    <Comp
      {...rest}
      style={{
        background: '#fff', color: '#0f172a',
        padding: '8px 14px', borderRadius: 999, fontSize: 13, fontWeight: 600,
        border: 0, cursor: 'pointer', textDecoration: 'none',
        whiteSpace: 'nowrap',
      }}
    >
      {children}
    </Comp>
  );
}

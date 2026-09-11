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
         className={`approved-hours-ready approved-hours-ready--${variant}${isEmpty ? ' is-empty' : ''}`}>
      <div className="approved-hours-ready__content">
        <div className="approved-hours-ready__header">
          <div>
            <div className="workspace-eyebrow">Approved hours</div>
            <h3
                data-testid={`approved-hours-ready-${variant}-title`}>{v.title}</h3>
          </div>
          {!isEmpty && (
            <CtaButton {...ctaProps} data-testid={`approved-hours-ready-${variant}-cta`}>
              {v.cta}
            </CtaButton>
          )}
        </div>

        {loading && <p className="approved-hours-ready__loading" data-testid={`approved-hours-ready-${variant}-loading`}>Counting hours...</p>}

        {!loading && (
          <>
            <div className="approved-hours-ready__stats"
                 data-testid={`approved-hours-ready-${variant}-stats`}>
              {v.fields(bucket).map(f => (
                <div key={f.label}>
                  <span>{f.label}</span>
                  <strong>{f.value}</strong>
                </div>
              ))}
            </div>
            <p className="approved-hours-ready__sub"
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
      className="btn btn--primary"
    >
      {children}
    </Comp>
  );
}

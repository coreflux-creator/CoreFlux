import React, { useState, useEffect } from 'react';
import { useParams, Link } from 'react-router-dom';
import { api } from '../../../dashboard/src/lib/api';

const fmtMoney = (cents) =>
  ((cents || 0) / 100).toLocaleString(undefined, { style: 'currency', currency: 'USD' });

const TAX_LABEL = {
  fit: 'Federal Income Tax',
  ss_employee: 'Social Security',
  medicare_employee: 'Medicare',
  medicare_addl_employee: 'Medicare (additional)',
  sit_ca: 'CA State Income Tax',
  sdi_ca: 'CA SDI',
  ss_employer: 'Employer SS',
  medicare_employer: 'Employer Medicare',
  futa: 'FUTA',
  suta: 'SUTA',
  local: 'Local',
};
const EARN_LABEL = {
  regular: 'Regular', overtime: 'Overtime', double_time: 'Double-time',
  holiday: 'Holiday', pto: 'PTO', sick: 'Sick', bonus: 'Bonus',
  commission: 'Commission', reimbursement: 'Reimbursement',
};
const DED_LABEL = {
  retirement_401k: '401(k)', health_premium: 'Health premium',
  hsa: 'HSA', dental: 'Dental', vision: 'Vision',
  garnishment: 'Garnishment', loan: 'Loan', union_dues: 'Union dues',
  other_pretax: 'Other (pre-tax)', other_posttax: 'Other (post-tax)',
};

export default function PayStub() {
  const { lineId } = useParams();
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    api.get(`/api/v1/payroll/pay-stub?line_item_id=${lineId}`)
      .then(setData).catch((e) => setError(e.message));
  }, [lineId]);

  if (error) return <p className="error">{error}</p>;
  if (!data) return <p>Loading…</p>;

  const { line, earnings, taxes, deductions, company } = data;
  const employeeTaxes = taxes.filter((t) => !parseInt(t.is_employer, 10));
  const employerTaxes = taxes.filter((t) => parseInt(t.is_employer, 10));

  return (
    <section className="pay-stub" data-testid="pay-stub">
      <header className="pay-stub__header">
        <div>
          <h2>Pay Stub</h2>
          <p className="muted">
            {company.legal_name || 'CoreFlux'} · Pay date {line.pay_date} ·{' '}
            Period {line.period_start} → {line.period_end}
          </p>
        </div>
        <Link to={`../runs/${line.run_id}`} className="btn btn--ghost" data-testid="pay-stub-back">
          ← Back to run
        </Link>
      </header>

      <div className="pay-stub__person" data-testid="pay-stub-person">
        <div>
          <strong>{line.preferred_name || line.legal_first_name} {line.legal_last_name}</strong>
          <p className="muted">Employee #{line.employee_number}</p>
        </div>
        <div className="pay-stub__net">
          <span className="muted">Net pay</span>
          <strong data-testid="pay-stub-net">{fmtMoney(line.net_cents)}</strong>
        </div>
      </div>

      <div className="pay-stub__grid">
        <section>
          <h3>Earnings</h3>
          <table className="data-table">
            <thead><tr><th>Type</th><th>Hours</th><th>Rate</th><th>Amount</th></tr></thead>
            <tbody>
              {earnings.map((e) => (
                <tr key={e.id}>
                  <td>{EARN_LABEL[e.code] || e.code}</td>
                  <td>{e.hours ?? '—'}</td>
                  <td>{e.rate_cents ? fmtMoney(e.rate_cents) : '—'}</td>
                  <td>{fmtMoney(e.amount_cents)}</td>
                </tr>
              ))}
              <tr className="row--total">
                <td colSpan={3}><strong>Gross</strong></td>
                <td><strong>{fmtMoney(line.gross_cents)}</strong></td>
              </tr>
            </tbody>
          </table>
        </section>

        <section>
          <h3>Pre-tax deductions</h3>
          <table className="data-table">
            <thead><tr><th>Type</th><th>Amount</th></tr></thead>
            <tbody>
              {deductions.filter((d) => parseInt(d.is_pretax, 10)).map((d) => (
                <tr key={d.id}><td>{DED_LABEL[d.code] || d.code}</td><td>{fmtMoney(d.amount_cents)}</td></tr>
              ))}
              <tr className="row--total">
                <td><strong>Pre-tax total</strong></td>
                <td><strong>{fmtMoney(line.pretax_cents)}</strong></td>
              </tr>
            </tbody>
          </table>
        </section>

        <section>
          <h3>Employee taxes</h3>
          <table className="data-table">
            <thead><tr><th>Type</th><th>Taxable wage</th><th>Amount</th></tr></thead>
            <tbody>
              {employeeTaxes.map((t) => (
                <tr key={t.id}>
                  <td>{TAX_LABEL[t.code] || t.code}</td>
                  <td>{fmtMoney(t.taxable_wage_cents)}</td>
                  <td>{fmtMoney(t.amount_cents)}</td>
                </tr>
              ))}
              <tr className="row--total">
                <td colSpan={2}><strong>Employee tax total</strong></td>
                <td><strong>{fmtMoney(line.employee_taxes_cents)}</strong></td>
              </tr>
            </tbody>
          </table>
        </section>

        <section>
          <h3>Post-tax deductions</h3>
          {deductions.filter((d) => !parseInt(d.is_pretax, 10)).length === 0 ? (
            <p className="muted">None</p>
          ) : (
            <table className="data-table">
              <thead><tr><th>Type</th><th>Amount</th></tr></thead>
              <tbody>
                {deductions.filter((d) => !parseInt(d.is_pretax, 10)).map((d) => (
                  <tr key={d.id}><td>{DED_LABEL[d.code] || d.code}</td><td>{fmtMoney(d.amount_cents)}</td></tr>
                ))}
              </tbody>
            </table>
          )}
        </section>

        <section className="pay-stub__employer">
          <h3>Employer taxes <span className="muted">(not deducted from employee)</span></h3>
          <table className="data-table">
            <thead><tr><th>Type</th><th>Amount</th></tr></thead>
            <tbody>
              {employerTaxes.map((t) => (
                <tr key={t.id}>
                  <td>{TAX_LABEL[t.code] || t.code}</td>
                  <td>{fmtMoney(t.amount_cents)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      </div>
    </section>
  );
}

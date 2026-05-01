import React, { useState } from 'react';

/**
 * AP CSV Export — type x date-range download buttons.
 * Hits /modules/ap/api/export.php?type=...&from=...&to=... directly with the
 * browser; the server streams text/csv with Content-Disposition: attachment.
 */
export default function Export() {
  const [from, setFrom] = useState(() => {
    const d = new Date(); d.setMonth(d.getMonth() - 1); return d.toISOString().slice(0, 10);
  });
  const [to, setTo]         = useState(new Date().toISOString().slice(0, 10));
  const [taxYear, setTaxYear] = useState(new Date().getFullYear());

  const dl = (type, extra = {}) => {
    const qs = new URLSearchParams({ type, ...extra });
    if (type !== '1099') {
      qs.set('from', from);
      qs.set('to',   to);
    } else {
      qs.set('tax_year', String(extra.tax_year ?? taxYear));
    }
    // Direct anchor click — the browser handles the file dialog.
    const a = document.createElement('a');
    a.href = `/modules/ap/api/export.php?${qs.toString()}`;
    a.rel  = 'noopener';
    a.click();
  };

  return (
    <section data-testid="ap-export">
      <h3 style={{ marginTop: 0 }}>Export</h3>
      <p style={{ color: 'var(--cf-text-secondary)', fontSize: 13 }}>
        Download CSVs of bills, payments, expenses, 1099 ledger, or a Gusto-ready
        contractor-payments file for the date range below.
      </p>

      <div style={{ display: 'flex', gap: 12, alignItems: 'flex-end', flexWrap: 'wrap', marginBottom: 24 }}>
        <label style={{ fontSize: 13 }}>
          <span style={{ color: 'var(--cf-text-secondary)' }}>From</span>
          <input className="input" type="date" value={from} onChange={(e) => setFrom(e.target.value)} data-testid="ap-export-from" style={{ display: 'block', marginTop: 4 }} />
        </label>
        <label style={{ fontSize: 13 }}>
          <span style={{ color: 'var(--cf-text-secondary)' }}>To</span>
          <input className="input" type="date" value={to} onChange={(e) => setTo(e.target.value)} data-testid="ap-export-to" style={{ display: 'block', marginTop: 4 }} />
        </label>
        <label style={{ fontSize: 13 }}>
          <span style={{ color: 'var(--cf-text-secondary)' }}>1099 tax year</span>
          <input className="input" type="number" min="2020" max="2099" value={taxYear} onChange={(e) => setTaxYear(Number(e.target.value))} data-testid="ap-export-tax-year" style={{ display: 'block', marginTop: 4, width: 120 }} />
        </label>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 16 }}>
        <Card title="Bills" desc="All bills (header rows) with vendor, dates, totals, status." testId="ap-export-bills" onClick={() => dl('bills')} />
        <Card title="Payments" desc="Outbound payments with method, reference, status." testId="ap-export-payments" onClick={() => dl('payments')} />
        <Card title="Expenses" desc="Expense report lines (one row per line) with category and merchant." testId="ap-export-expenses" onClick={() => dl('expenses')} />
        <Card title="1099 Ledger" desc="Year-end 1099-NEC totals for the selected tax year." testId="ap-export-1099" onClick={() => dl('1099')} />
        <Card title="Gusto contractors" desc="Contractor payments rolled up by vendor in Gusto bulk-import format." testId="ap-export-gusto" onClick={() => dl('gusto_contractors')} />
      </div>
    </section>
  );
}

function Card({ title, desc, testId, onClick }) {
  return (
    <div className="card" style={{ padding: 16, border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8, display: 'flex', flexDirection: 'column', gap: 8 }}>
      <h4 style={{ margin: 0, fontSize: 15 }}>{title}</h4>
      <p style={{ margin: 0, fontSize: 13, color: 'var(--cf-text-secondary)' }}>{desc}</p>
      <button className="btn btn--primary" data-testid={testId} onClick={onClick} style={{ alignSelf: 'flex-start', marginTop: 4 }}>Download CSV</button>
    </div>
  );
}

import React, { useState } from 'react';
import { useApi } from '../../../dashboard/src/lib/api';
import DataWarning from '../../../dashboard/src/components/DataWarning';

/**
 * Cash Flow Statement (indirect method).
 * Reads /modules/accounting/api/reports.php?type=cash_flow_indirect
 * Date inputs default to YTD.
 */
export default function CashFlowStatement() {
  const today = new Date().toISOString().slice(0, 10);
  const yearStart = today.slice(0, 4) + '-01-01';
  const [from, setFrom] = useState(yearStart);
  const [to, setTo]     = useState(today);
  const { data, loading, error } = useApi(`/modules/accounting/api/reports.php?type=cash_flow_indirect&from=${from}&to=${to}`);
  // _safeReport returns `sections: []` (array) when the underlying SQL throws,
  // but a valid response gives `sections: { operating, investing, financing, untagged }`.
  // Guard against the array shape so we render the data_warning banner instead
  // of crashing with `Cannot read properties of undefined (reading 'lines')`.
  const safe = data && data.sections && !Array.isArray(data.sections);

  return (
    <section data-testid="accounting-cash-flow">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h2 style={{ margin: 0 }}>Cash Flow Statement <small style={{ color: 'var(--cf-text-secondary)', fontWeight: 400 }}>(indirect)</small></h2>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center', fontSize: 13 }}>
          <label>From&nbsp;
            <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="input" data-testid="accounting-cf-from" />
          </label>
          <label>To&nbsp;
            <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="input" data-testid="accounting-cf-to" />
          </label>
        </div>
      </header>
      {loading && <p>Loading…</p>}
      {error   && <p className="error">Error: {error.message}</p>}
      {data?.data_warning && <DataWarning text={data.data_warning} hint="Run accounting migrations or post some balanced JEs in this period." />}
      {safe && (
        <>
          {data.untagged_warning && (
            <div data-testid="accounting-cf-untagged-warning" style={{ background: '#fef3c7', color: '#92400e', padding: 12, borderRadius: 8, marginBottom: 16, fontSize: 13 }}>
              Some accounts in the GL have no <code>cash_flow_tag</code> set. The "Untagged" section below shows them.
              Set tags on the COA to bucket them properly into operating / investing / financing.
            </div>
          )}
          <table className="data-table" data-testid="accounting-cf-table">
            <tbody>
              <Section title="Operating activities" testid="accounting-cf-operating" section={data.sections.operating} />
              <Section title="Investing activities" testid="accounting-cf-investing" section={data.sections.investing} />
              <Section title="Financing activities" testid="accounting-cf-financing" section={data.sections.financing} />
              {data.sections.untagged.lines.length > 0 && (
                <Section title="Untagged (please classify)" testid="accounting-cf-untagged" section={data.sections.untagged} />
              )}
              <tr><td colSpan={2} style={{ height: 12 }} /></tr>
              <Total label="Net change in cash" amount={data.net_change_in_cash} testid="accounting-cf-net-change" />
              <Total label="Cash, beginning"     amount={data.cash_beginning}     testid="accounting-cf-beginning" />
              <Total label="Cash, ending"        amount={data.cash_ending}        testid="accounting-cf-ending" />
              <Total label="Reconciliation"      amount={data.reconciliation_diff} testid="accounting-cf-recon" muted={data.balanced} />
            </tbody>
          </table>
          <p style={{ fontSize: 11, color: data.balanced ? '#065f46' : '#991b1b', marginTop: 8 }} data-testid="accounting-cf-balanced">
            {data.balanced
              ? '✓ Cash flow ties out to GL movement.'
              : `⚠ Reconciliation difference of ${money(data.reconciliation_diff)}. Some accounts are likely missing a cash_flow_tag.`}
          </p>
        </>
      )}
    </section>
  );
}

function Section({ title, section, testid }) {
  return (
    <>
      <tr><th colSpan={2} style={{ background: '#f9fafb', textAlign: 'left' }} data-testid={`${testid}-title`}>{title}</th></tr>
      {section.lines.map((l, i) => (
        <tr key={i} data-testid={`${testid}-line-${i}`}>
          <td style={{ paddingLeft: 24 }}>{l.code ? <code style={{ fontSize: 11 }}>{l.code}</code> : null} {l.name}</td>
          <td style={{ textAlign: 'right' }}>{money(l.amount)}</td>
        </tr>
      ))}
      <tr>
        <td style={{ fontWeight: 600 }}>Subtotal</td>
        <td style={{ textAlign: 'right', fontWeight: 600 }} data-testid={`${testid}-subtotal`}>{money(section.subtotal)}</td>
      </tr>
    </>
  );
}

function Total({ label, amount, testid, muted }) {
  return (
    <tr style={{ borderTop: '1px solid var(--cf-border, #e5e7eb)' }}>
      <td style={{ fontWeight: 700 }}>{label}</td>
      <td style={{ textAlign: 'right', fontWeight: 700, color: muted ? 'var(--cf-text-secondary)' : 'inherit' }} data-testid={testid}>{money(amount)}</td>
    </tr>
  );
}

function money(n) { return Number(n || 0).toLocaleString('en-US', { style: 'currency', currency: 'USD' }); }

/**
 * Income Statement (P&L) — Reports Overhaul Pass 2 (Tier 1).
 *
 * Visual + IA pass: sharp typography, sticky header, comparison columns
 * (vs prior period / vs prior year / both, controlled by ReportShell
 * via useReportPeriod), section subtotals (Gross profit, Operating
 * income, Net income), KPI band on top with sparklines, drill-through
 * on every account row via GlDetailDrilldown.
 *
 * Drops the old route-based drill in favour of the in-page slide-over.
 * Existing endpoint contract (/modules/accounting/api/reports.php?
 * type=income_statement&from=&to=) is unchanged — comparison windows
 * are fetched in parallel and merged client-side.
 */
import React, { useEffect, useMemo, useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';
import DataWarning from '../../../dashboard/src/components/DataWarning';
import ReportShell from '../../../dashboard/src/components/ReportShell';
import MetricCard from '../../../dashboard/src/components/MetricCard';
import ComparisonTable from '../../../dashboard/src/components/ComparisonTable';
import GlDetailDrilldown from '../../../dashboard/src/components/GlDetailDrilldown';
import { fmtMoney } from '../../../dashboard/src/lib/format';
import { useReportPeriod } from '../../../dashboard/src/lib/useReportPeriod';

export default function IncomeStatement() {
  const period = useReportPeriod();

  const [current,     setCurrent]     = useState(null);
  const [priorPeriod, setPriorPeriod] = useState(null);
  const [priorYear,   setPriorYear]   = useState(null);
  const [loading, setLoading] = useState(true);
  const [error,   setError]   = useState(null);
  const [drill,   setDrill]   = useState(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true); setError(null);

    const reqs = [
      api.get(url(period.from, period.to)).then(r => ({ slot: 'current', r })),
    ];
    if (period.showPriorPeriod) {
      reqs.push(api.get(url(period.priorFrom, period.priorTo))
        .then(r => ({ slot: 'prior_period', r }))
        .catch(() => ({ slot: 'prior_period', r: null })));
    }
    if (period.showPriorYear) {
      reqs.push(api.get(url(period.priorYearFrom, period.priorYearTo))
        .then(r => ({ slot: 'prior_year', r }))
        .catch(() => ({ slot: 'prior_year', r: null })));
    }

    Promise.all(reqs).then(results => {
      if (cancelled) return;
      results.forEach(({ slot, r }) => {
        if (slot === 'current')      setCurrent(r);
        if (slot === 'prior_period') setPriorPeriod(r);
        if (slot === 'prior_year')   setPriorYear(r);
      });
      if (!period.showPriorPeriod) setPriorPeriod(null);
      if (!period.showPriorYear)   setPriorYear(null);
    })
    .catch(e => { if (!cancelled) setError(e.message || 'Failed to load'); })
    .finally(() => { if (!cancelled) setLoading(false); });

    return () => { cancelled = true; };
  }, [period.from, period.to, period.compareMode]);

  const safe = current && Array.isArray(current.revenue) && Array.isArray(current.expense);

  // Build comparison columns.
  const columns = useMemo(() => {
    const cols = [{ key: 'current', label: 'This period' }];
    if (period.showPriorPeriod) cols.push({ key: 'prior_period', label: 'Prior period' });
    if (period.showPriorYear)   cols.push({ key: 'prior_year',   label: 'Prior year' });
    return cols;
  }, [period.showPriorPeriod, period.showPriorYear]);

  // Merge rows across the three responses, keyed by account code.
  const buildRows = (section, inverseFlag) => {
    if (!current) return [];
    const idx = (resp, sectionKey) => {
      const map = {};
      (resp?.[sectionKey] || []).forEach(r => { map[r.code] = r; });
      return map;
    };
    const cMap = idx(current,     section);
    const pMap = idx(priorPeriod, section);
    const yMap = idx(priorYear,   section);
    const allCodes = new Set([
      ...Object.keys(cMap), ...Object.keys(pMap), ...Object.keys(yMap),
    ]);
    return [...allCodes]
      .sort()
      .map(code => {
        const r = cMap[code] || pMap[code] || yMap[code];
        return {
          code,
          label: r?.name || code,
          values: {
            current:      cMap[code]?.amount ?? null,
            prior_period: pMap[code]?.amount ?? null,
            prior_year:   yMap[code]?.amount ?? null,
          },
          inverse: inverseFlag,
          onDrill: () => setDrill({ accountCode: code,
                                    start: period.from, end: period.to,
                                    label: r?.name || code }),
        };
      });
  };

  const revenueRows = buildRows('revenue', false);
  const expenseRows = buildRows('expense', true);

  // Subtotals / Net income row.
  const sumCol = (rows, key) => rows.reduce((acc, r) => acc + (Number(r.values[key]) || 0), 0);

  const netIncome = {
    current:      (current?.net_income ?? (sumCol(revenueRows, 'current') - sumCol(expenseRows, 'current'))),
    prior_period: priorPeriod?.net_income ?? (priorPeriod ? (sumCol(revenueRows, 'prior_period') - sumCol(expenseRows, 'prior_period')) : null),
    prior_year:   priorYear?.net_income   ?? (priorYear   ? (sumCol(revenueRows, 'prior_year')   - sumCol(expenseRows, 'prior_year'))   : null),
  };

  const totRevenue = {
    current:      current?.total_revenue ?? sumCol(revenueRows, 'current'),
    prior_period: priorPeriod?.total_revenue ?? (priorPeriod ? sumCol(revenueRows, 'prior_period') : null),
    prior_year:   priorYear?.total_revenue   ?? (priorYear   ? sumCol(revenueRows, 'prior_year')   : null),
  };
  const totExpense = {
    current:      current?.total_expense ?? sumCol(expenseRows, 'current'),
    prior_period: priorPeriod?.total_expense ?? (priorPeriod ? sumCol(expenseRows, 'prior_period') : null),
    prior_year:   priorYear?.total_expense   ?? (priorYear   ? sumCol(expenseRows, 'prior_year')   : null),
  };

  return (
    <ReportShell
      title="Income Statement"
      subtitle={`Revenue and expense activity · ${period.from} → ${period.to}`}
      testIdPrefix="rpt-pnl"
      period={period}
      snapshotEnvelope={current ? {
        params:   { from: period.from, to: period.to, compareMode: period.compareMode },
        envelope: { current, priorPeriod, priorYear },
      } : null}
      onReplayDrill={(d) => setDrill({
        accountCode: d.account_code,
        start: d.period_from || period.from,
        end:   d.period_to   || period.to,
        label: d.label,
      })}
      kpis={safe && (
        <>
          <MetricCard label="Revenue"
                      testIdPrefix="rpt-pnl-kpi-revenue"
                      value={totRevenue.current}
                      format={fmtMoney}
                      tone="positive"
                      priorPeriod={period.showPriorPeriod ? { value: totRevenue.prior_period } : null}
                      priorYear={period.showPriorYear     ? { value: totRevenue.prior_year   } : null} />
          <MetricCard label="Expenses"
                      testIdPrefix="rpt-pnl-kpi-expenses"
                      value={totExpense.current}
                      format={fmtMoney}
                      tone="negative"
                      inverse
                      priorPeriod={period.showPriorPeriod ? { value: totExpense.prior_period } : null}
                      priorYear={period.showPriorYear     ? { value: totExpense.prior_year   } : null} />
          <MetricCard label="Net income"
                      testIdPrefix="rpt-pnl-kpi-net-income"
                      value={netIncome.current}
                      format={fmtMoney}
                      tone={netIncome.current >= 0 ? 'positive' : 'negative'}
                      priorPeriod={period.showPriorPeriod ? { value: netIncome.prior_period } : null}
                      priorYear={period.showPriorYear     ? { value: netIncome.prior_year   } : null} />
          <MetricCard label="Margin"
                      testIdPrefix="rpt-pnl-kpi-margin"
                      value={totRevenue.current ? (netIncome.current / totRevenue.current * 100).toFixed(1) + '%' : '—'}
                      tone={netIncome.current >= 0 ? 'positive' : 'negative'} />
        </>
      )}
    >
      {loading && <p data-testid="rpt-pnl-loading">Loading…</p>}
      {error   && <p className="error" data-testid="rpt-pnl-error">Error: {error}</p>}
      {current?.data_warning && (
        <DataWarning text={current.data_warning}
                     hint="Run accounting migrations or post your first revenue/expense JE." />
      )}

      {safe && (
        <>
          <SectionBlock title="Revenue" testIdPrefix="rpt-pnl-revenue"
                        columns={columns} rows={revenueRows}
                        total={totRevenue} totalLabel="Total revenue" />
          <SectionBlock title="Expenses" testIdPrefix="rpt-pnl-expense"
                        columns={columns} rows={expenseRows}
                        total={totExpense} totalLabel="Total expenses" inverse />
          <ComparisonTable
            testIdPrefix="rpt-pnl-bottomline"
            columns={columns}
            showVariance={true}
            rows={[
              { code: '', label: 'Net income', kind: 'total',
                values: netIncome,
                testIdPrefix: 'rpt-pnl-net-income' },
            ]}
          />
        </>
      )}

      {drill && (
        <GlDetailDrilldown {...drill} reportKey="rpt-pnl" onClose={() => setDrill(null)} />
      )}
    </ReportShell>
  );
}

function SectionBlock({ title, testIdPrefix, columns, rows, total, totalLabel, inverse }) {
  return (
    <div>
      <h3 style={sectionHeadingStyle}>{title}</h3>
      <ComparisonTable
        testIdPrefix={testIdPrefix}
        columns={columns}
        rows={[
          ...rows,
          { code: '', label: totalLabel, kind: 'subtotal',
            values: total, inverse,
            testIdPrefix: `${testIdPrefix}-total` },
        ]}
      />
    </div>
  );
}

function url(from, to) {
  return `/modules/accounting/api/reports.php?type=income_statement&from=${from}&to=${to}`;
}

const sectionHeadingStyle = {
  margin: '4px 0 6px', fontSize: 12, fontWeight: 700,
  color: '#475569', textTransform: 'uppercase', letterSpacing: 0.5,
};

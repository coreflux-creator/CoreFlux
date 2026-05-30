/**
 * Trial Balance — Reports Overhaul Pass 2 (Tier 1).
 *
 * Posted JEs aggregated by account, with debit/credit columns + signed
 * balance. Drill-through to GL detail via slide-over. Tie-out indicator
 * shows whether the suite balances. Comparison columns compare the
 * balance column at three as-of dates.
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

export default function TrialBalance() {
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

    const fetchAt = (asOf) => api.get(url(asOf));
    const reqs = [fetchAt(period.to).then(r => ({ slot: 'current', r }))];
    if (period.showPriorPeriod) {
      reqs.push(fetchAt(period.priorTo).then(r => ({ slot: 'prior_period', r })).catch(() => ({ slot: 'prior_period', r: null })));
    }
    if (period.showPriorYear) {
      reqs.push(fetchAt(period.priorYearTo).then(r => ({ slot: 'prior_year', r })).catch(() => ({ slot: 'prior_year', r: null })));
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
  }, [period.to, period.compareMode]);

  const currentRows = current?.rows ?? [];

  const sums = useMemo(() => currentRows.reduce((s, r) => {
    s.debit  += Number(r.debit)  || 0;
    s.credit += Number(r.credit) || 0;
    return s;
  }, { debit: 0, credit: 0 }), [currentRows]);

  const balanced = Math.abs(sums.debit - sums.credit) < 0.005;

  // Build comparison table rows.
  const columns = useMemo(() => {
    const cols = [
      { key: 'debit',  label: 'Debit'  },
      { key: 'credit', label: 'Credit' },
      { key: 'current', label: `Balance ${period.to}` },
    ];
    if (period.showPriorPeriod) cols.push({ key: 'prior_period', label: `Balance ${period.priorTo}` });
    if (period.showPriorYear)   cols.push({ key: 'prior_year',   label: `Balance ${period.priorYearTo}` });
    return cols;
  }, [period.to, period.priorTo, period.priorYearTo, period.showPriorPeriod, period.showPriorYear]);

  const rows = useMemo(() => {
    const idx = (resp) => {
      const m = {};
      (resp?.rows || []).forEach(r => { m[r.code] = r; });
      return m;
    };
    const c = idx(current), p = idx(priorPeriod), y = idx(priorYear);
    const allCodes = new Set([...Object.keys(c), ...Object.keys(p), ...Object.keys(y)]);
    return [...allCodes].sort().map(code => {
      const r = c[code] || p[code] || y[code];
      return {
        code,
        label: r?.name + (r?.account_type ? ` · ${r.account_type}` : ''),
        values: {
          debit:        c[code]?.debit  ?? null,
          credit:       c[code]?.credit ?? null,
          current:      c[code]?.balance_signed ?? null,
          prior_period: p[code]?.balance_signed ?? null,
          prior_year:   y[code]?.balance_signed ?? null,
        },
        onDrill: () => setDrill({
          accountCode: code, start: period.from, end: period.to, label: r?.name,
        }),
      };
    });
  }, [current, priorPeriod, priorYear, period.from, period.to]);

  const totalRow = {
    code: '', label: 'TOTAL', kind: 'total',
    values: { debit: sums.debit, credit: sums.credit, current: sums.debit - sums.credit },
    testIdPrefix: 'rpt-tb-total',
  };

  return (
    <ReportShell
      title="Trial Balance"
      subtitle={`Posted journal entries aggregated by account · As of ${period.to}`}
      testIdPrefix="rpt-tb"
      period={period}
      singleDate
      snapshotEnvelope={current ? {
        params:   { as_of: period.to, compareMode: period.compareMode },
        envelope: { current, priorPeriod, priorYear },
      } : null}
      onReplayDrill={(d) => setDrill({
        accountCode: d.account_code,
        start: d.period_from || period.from,
        end:   d.period_to   || period.to,
        label: d.label,
      })}
      kpis={(
        <>
          <MetricCard label="Total debits"
                      testIdPrefix="rpt-tb-kpi-debit"
                      value={sums.debit}
                      format={fmtMoney}
                      tone="neutral" />
          <MetricCard label="Total credits"
                      testIdPrefix="rpt-tb-kpi-credit"
                      value={sums.credit}
                      format={fmtMoney}
                      tone="neutral" />
          <MetricCard label="Tie-out"
                      testIdPrefix="rpt-tb-kpi-balanced"
                      value={balanced ? 'Balanced' : fmtMoney(sums.debit - sums.credit) + ' diff'}
                      tone={balanced ? 'positive' : 'negative'} />
          <MetricCard label="Active accounts"
                      testIdPrefix="rpt-tb-kpi-accounts"
                      value={currentRows.length}
                      format={(n) => Number(n).toLocaleString()} />
        </>
      )}
    >
      {loading && <p data-testid="rpt-tb-loading">Loading…</p>}
      {error   && <p className="error" data-testid="rpt-tb-error">Error: {error}</p>}
      {current?.data_warning && (
        <DataWarning text={current.data_warning}
                     hint="Run accounting migrations or post your first balanced JE." />
      )}

      <ComparisonTable
        testIdPrefix="rpt-tb-table"
        columns={columns}
        showVariance={false}
        rows={rows.length === 0
          ? []
          : [...rows, totalRow]}
        emptyText="No posted journal entries yet."
      />

      {drill && (
        <GlDetailDrilldown {...drill} reportKey="rpt-tb" onClose={() => setDrill(null)} />
      )}
    </ReportShell>
  );
}

function url(asOf) {
  return `/modules/accounting/api/journal_entries.php?action=trial_balance&as_of=${asOf}`;
}

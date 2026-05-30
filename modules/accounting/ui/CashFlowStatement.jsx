/**
 * Cash Flow Statement (indirect) — Reports Overhaul Pass 2 (Tier 1).
 *
 * Operating / Investing / Financing with subtotals, net-change KPI,
 * tie-out KPI, untagged-accounts warning, and drill-through on every
 * leaf line via GlDetailDrilldown. Comparison columns supported.
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

export default function CashFlowStatement() {
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

    const reqs = [api.get(url(period.from, period.to)).then(r => ({ slot: 'current', r }))];
    if (period.showPriorPeriod) {
      reqs.push(api.get(url(period.priorFrom, period.priorTo))
        .then(r => ({ slot: 'prior_period', r })).catch(() => ({ slot: 'prior_period', r: null })));
    }
    if (period.showPriorYear) {
      reqs.push(api.get(url(period.priorYearFrom, period.priorYearTo))
        .then(r => ({ slot: 'prior_year', r })).catch(() => ({ slot: 'prior_year', r: null })));
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

  const safe = current && current.sections && !Array.isArray(current.sections);

  const columns = useMemo(() => {
    const cols = [{ key: 'current', label: 'This period' }];
    if (period.showPriorPeriod) cols.push({ key: 'prior_period', label: 'Prior period' });
    if (period.showPriorYear)   cols.push({ key: 'prior_year',   label: 'Prior year' });
    return cols;
  }, [period.showPriorPeriod, period.showPriorYear]);

  const sectionRows = (name, label) => {
    if (!current) return [];
    const c = current.sections?.[name];
    const p = priorPeriod?.sections?.[name];
    const y = priorYear?.sections?.[name];
    const idx = (s) => {
      const m = {};
      (s?.lines || []).forEach(ln => {
        const k = ln.code || ln.name;
        m[k] = ln;
      });
      return m;
    };
    const cMap = idx(c), pMap = idx(p), yMap = idx(y);
    const allKeys = new Set([...Object.keys(cMap), ...Object.keys(pMap), ...Object.keys(yMap)]);
    const lineRows = [...allKeys].sort().map(k => {
      const ln = cMap[k] || pMap[k] || yMap[k];
      return {
        code: ln?.code || '',
        label: ln?.name || k,
        values: {
          current:      cMap[k]?.amount ?? null,
          prior_period: pMap[k]?.amount ?? null,
          prior_year:   yMap[k]?.amount ?? null,
        },
        onDrill: ln?.code ? (() => setDrill({
          accountCode: ln.code, start: period.from, end: period.to, label: ln.name,
        })) : null,
      };
    });
    const subtotalRow = {
      code: '', label, kind: 'subtotal',
      values: {
        current:      c?.subtotal ?? null,
        prior_period: p?.subtotal ?? null,
        prior_year:   y?.subtotal ?? null,
      },
      testIdPrefix: `rpt-cf-${name}-subtotal`,
    };
    return { lineRows, subtotalRow, hasLines: lineRows.length > 0 };
  };

  const operating = sectionRows('operating', 'Net cash from operating');
  const investing = sectionRows('investing', 'Net cash from investing');
  const financing = sectionRows('financing', 'Net cash from financing');
  const untagged  = sectionRows('untagged',  'Untagged subtotal');

  const totals = {
    net_change: {
      current:      current?.net_change_in_cash ?? null,
      prior_period: priorPeriod?.net_change_in_cash ?? null,
      prior_year:   priorYear?.net_change_in_cash   ?? null,
    },
    beginning: {
      current:      current?.cash_beginning ?? null,
      prior_period: priorPeriod?.cash_beginning ?? null,
      prior_year:   priorYear?.cash_beginning   ?? null,
    },
    ending: {
      current:      current?.cash_ending ?? null,
      prior_period: priorPeriod?.cash_ending ?? null,
      prior_year:   priorYear?.cash_ending   ?? null,
    },
  };

  return (
    <ReportShell
      title="Cash Flow Statement"
      subtitle={`Indirect method · ${period.from} → ${period.to}`}
      testIdPrefix="rpt-cf"
      period={period}
      kpis={safe && (
        <>
          <MetricCard label="Net change in cash"
                      testIdPrefix="rpt-cf-kpi-net-change"
                      value={current.net_change_in_cash}
                      format={fmtMoney}
                      tone={current.net_change_in_cash >= 0 ? 'positive' : 'negative'}
                      priorPeriod={period.showPriorPeriod ? { value: priorPeriod?.net_change_in_cash } : null}
                      priorYear={period.showPriorYear     ? { value: priorYear?.net_change_in_cash   } : null} />
          <MetricCard label="Cash, ending"
                      testIdPrefix="rpt-cf-kpi-ending"
                      value={current.cash_ending}
                      format={fmtMoney}
                      tone="positive"
                      priorPeriod={period.showPriorPeriod ? { value: priorPeriod?.cash_ending } : null}
                      priorYear={period.showPriorYear     ? { value: priorYear?.cash_ending   } : null} />
          <MetricCard label="Cash, beginning"
                      testIdPrefix="rpt-cf-kpi-beginning"
                      value={current.cash_beginning}
                      format={fmtMoney} />
          <MetricCard label="Tie-out to GL"
                      testIdPrefix="rpt-cf-kpi-balanced"
                      value={current.balanced ? 'Balanced' : fmtMoney(current.reconciliation_diff) + ' diff'}
                      tone={current.balanced ? 'positive' : 'negative'} />
        </>
      )}
    >
      {loading && <p data-testid="rpt-cf-loading">Loading…</p>}
      {error   && <p className="error" data-testid="rpt-cf-error">Error: {error}</p>}
      {current?.data_warning && (
        <DataWarning text={current.data_warning}
                     hint="Run accounting migrations or post some balanced JEs in this period." />
      )}

      {safe && (
        <>
          {current.untagged_warning && (
            <div data-testid="rpt-cf-untagged-warning"
                 style={{ background: '#fef3c7', color: '#92400e', padding: '10px 14px',
                          borderRadius: 6, fontSize: 13, borderLeft: '3px solid #f59e0b' }}>
              Some accounts in the GL have no <code>cash_flow_tag</code>. The Untagged section
              below lists them — set tags on the COA to bucket them into operating / investing / financing.
            </div>
          )}

          <Section title="Operating activities" testIdPrefix="rpt-cf-operating"
                   data={operating} columns={columns} />
          <Section title="Investing activities" testIdPrefix="rpt-cf-investing"
                   data={investing} columns={columns} />
          <Section title="Financing activities" testIdPrefix="rpt-cf-financing"
                   data={financing} columns={columns} />
          {untagged.hasLines && (
            <Section title="Untagged (please classify)" testIdPrefix="rpt-cf-untagged"
                     data={untagged} columns={columns} />
          )}

          <ComparisonTable
            testIdPrefix="rpt-cf-totals"
            columns={columns}
            showVariance={false}
            rows={[
              { code: '', label: 'Net change in cash', kind: 'subtotal',
                values: totals.net_change, testIdPrefix: 'rpt-cf-net-change' },
              { code: '', label: 'Cash, beginning', kind: 'row',
                values: totals.beginning, testIdPrefix: 'rpt-cf-beginning' },
              { code: '', label: 'Cash, ending', kind: 'total',
                values: totals.ending, testIdPrefix: 'rpt-cf-ending' },
            ]}
          />

          <p style={{ fontSize: 12, color: current.balanced ? '#065f46' : '#991b1b', margin: 0 }}
             data-testid="rpt-cf-balanced">
            {current.balanced
              ? '✓ Cash flow ties out to GL movement.'
              : `⚠ Reconciliation difference of ${fmtMoney(current.reconciliation_diff)}. Some accounts are likely missing a cash_flow_tag.`}
          </p>
        </>
      )}

      {drill && (
        <GlDetailDrilldown {...drill} onClose={() => setDrill(null)} />
      )}
    </ReportShell>
  );
}

function Section({ title, testIdPrefix, data, columns }) {
  return (
    <div>
      <h3 style={sectionHeadingStyle}>{title}</h3>
      <ComparisonTable
        testIdPrefix={testIdPrefix}
        columns={columns}
        rows={[...data.lineRows, data.subtotalRow]}
      />
    </div>
  );
}

function url(from, to) {
  return `/modules/accounting/api/reports.php?type=cash_flow_indirect&from=${from}&to=${to}`;
}

const sectionHeadingStyle = {
  margin: '4px 0 6px', fontSize: 12, fontWeight: 700,
  color: '#475569', textTransform: 'uppercase', letterSpacing: 0.5,
};

/**
 * Balance Sheet — Reports Overhaul Pass 2 (Tier 1).
 *
 * Assets / Liabilities / Equity as of a date with optional comparison
 * columns (prior period as_of = current_as_of − window_days; prior year
 * as_of = current_as_of − 1y). Drill-through wired into every account
 * row via GlDetailDrilldown.
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

export default function BalanceSheet() {
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
  }, [period.to, period.compareMode, period.from]);

  const safe = current && Array.isArray(current.assets)
    && Array.isArray(current.liabilities) && Array.isArray(current.equity);

  const columns = useMemo(() => {
    const cols = [{ key: 'current', label: period.to }];
    if (period.showPriorPeriod) cols.push({ key: 'prior_period', label: period.priorTo });
    if (period.showPriorYear)   cols.push({ key: 'prior_year',   label: period.priorYearTo });
    return cols;
  }, [period.to, period.priorTo, period.priorYearTo, period.showPriorPeriod, period.showPriorYear]);

  const buildRows = (section) => {
    if (!current) return [];
    const idx = (resp) => {
      const map = {};
      (resp?.[section] || []).forEach(r => {
        const key = r.code + (r.synthetic ? '-syn' : '');
        map[key] = r;
      });
      return map;
    };
    const c = idx(current), p = idx(priorPeriod), y = idx(priorYear);
    const allKeys = new Set([...Object.keys(c), ...Object.keys(p), ...Object.keys(y)]);
    return [...allKeys].sort().map(key => {
      const r = c[key] || p[key] || y[key];
      const synthetic = !!r?.synthetic;
      return {
        code: r?.code || key,
        label: (r?.name || key) + (synthetic ? ' *' : ''),
        values: {
          current:      c[key]?.amount ?? null,
          prior_period: p[key]?.amount ?? null,
          prior_year:   y[key]?.amount ?? null,
        },
        onDrill: synthetic ? null : (() => setDrill({
          accountCode: r?.code, start: period.from, end: period.to, label: r?.name,
        })),
      };
    });
  };

  const assetRows  = buildRows('assets');
  const liabRows   = buildRows('liabilities');
  const equityRows = buildRows('equity');

  const totRow = (key, label, kind) => ({
    code: '', label, kind,
    values: {
      current:      current?.[key] ?? null,
      prior_period: priorPeriod?.[key] ?? null,
      prior_year:   priorYear?.[key]   ?? null,
    },
    testIdPrefix: `rpt-bs-${label.toLowerCase().replace(/\s+/g, '-')}`,
  });

  return (
    <ReportShell
      title="Balance Sheet"
      subtitle={`Assets, liabilities, and equity as of ${period.to}`}
      testIdPrefix="rpt-bs"
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
      kpis={safe && (
        <>
          <MetricCard label="Total assets"
                      testIdPrefix="rpt-bs-kpi-assets"
                      value={current.total_assets}
                      format={fmtMoney}
                      tone="positive"
                      priorPeriod={period.showPriorPeriod ? { value: priorPeriod?.total_assets } : null}
                      priorYear={period.showPriorYear     ? { value: priorYear?.total_assets   } : null} />
          <MetricCard label="Total liabilities"
                      testIdPrefix="rpt-bs-kpi-liabilities"
                      value={current.total_liabilities}
                      format={fmtMoney}
                      inverse
                      priorPeriod={period.showPriorPeriod ? { value: priorPeriod?.total_liabilities } : null}
                      priorYear={period.showPriorYear     ? { value: priorYear?.total_liabilities   } : null} />
          <MetricCard label="Total equity"
                      testIdPrefix="rpt-bs-kpi-equity"
                      value={current.total_equity}
                      format={fmtMoney}
                      tone="positive"
                      priorPeriod={period.showPriorPeriod ? { value: priorPeriod?.total_equity } : null}
                      priorYear={period.showPriorYear     ? { value: priorYear?.total_equity   } : null} />
          <MetricCard label="Balanced"
                      testIdPrefix="rpt-bs-kpi-balanced"
                      value={current.balanced ? 'Yes' : fmtMoney(current.total_assets - current.liabilities_plus_equity)}
                      tone={current.balanced ? 'positive' : 'negative'} />
        </>
      )}
    >
      {loading && <p data-testid="rpt-bs-loading">Loading…</p>}
      {error   && <p className="error" data-testid="rpt-bs-error">Error: {error}</p>}
      {current?.data_warning && (
        <DataWarning text={current.data_warning}
                     hint="Run accounting migrations or post your first balanced JE to populate this view." />
      )}

      {safe && (
        <>
          <Section title="Assets" rows={assetRows} columns={columns}
                   total={totRow('total_assets', 'Total assets', 'subtotal')}
                   testIdPrefix="rpt-bs-assets" />
          <Section title="Liabilities" rows={liabRows} columns={columns}
                   total={totRow('total_liabilities', 'Total liabilities', 'subtotal')}
                   testIdPrefix="rpt-bs-liabilities" />
          <Section title="Equity" rows={equityRows} columns={columns}
                   total={totRow('total_equity', 'Total equity', 'subtotal')}
                   testIdPrefix="rpt-bs-equity" />

          <ComparisonTable
            testIdPrefix="rpt-bs-tieout"
            columns={columns}
            showVariance={false}
            rows={[
              totRow('liabilities_plus_equity', 'Liabilities + Equity', 'total'),
            ]}
          />
          <p data-testid="rpt-bs-balanced-line"
             style={{ fontSize: 12, color: current.balanced ? '#065f46' : '#991b1b', margin: 0 }}>
            {current.balanced
              ? '✓ Balanced — Assets = Liabilities + Equity.'
              : `⚠ Difference of ${fmtMoney(current.total_assets - current.liabilities_plus_equity)}.`}
          </p>
        </>
      )}

      {drill && (
        <GlDetailDrilldown {...drill} reportKey="rpt-bs" onClose={() => setDrill(null)} />
      )}
    </ReportShell>
  );
}

function Section({ title, rows, total, testIdPrefix, columns }) {
  return (
    <div>
      <h3 style={sectionHeadingStyle}>{title}</h3>
      <ComparisonTable
        testIdPrefix={testIdPrefix}
        columns={columns}
        rows={[
          ...rows,
          { ...total, testIdPrefix: `${testIdPrefix}-total` },
        ]}
      />
    </div>
  );
}

function url(asOf) {
  return `/modules/accounting/api/reports.php?type=balance_sheet&as_of=${asOf}`;
}

const sectionHeadingStyle = {
  margin: '4px 0 6px', fontSize: 12, fontWeight: 700,
  color: '#475569', textTransform: 'uppercase', letterSpacing: 0.5,
};

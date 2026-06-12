import React from 'react';
import { Routes, Route, Navigate, Link, useLocation } from 'react-router-dom';

// Workbench aliases for Reports-module analytics. Report definitions and
// execution remain owned by modules/reports/ui/*.
import StaffingOverviewReport from '../../reports/ui/StaffingOverview';
import ExecutiveSnapshot      from '../../reports/ui/ExecutiveSnapshot';
import ClientProfitability    from '../../reports/ui/ClientProfitability';
import RateSpreadMonitor      from '../../reports/ui/RateSpreadMonitor';
import OvertimeWatch          from '../../reports/ui/OvertimeWatch';
import WorkerMix              from './WorkerMix';

const SUB_TABS = [
  { slug: 'overview', label: 'Staffing Overview' },
  { slug: 'executive_snapshot', label: 'Executive Snapshot' },
  { slug: 'client_profitability', label: 'Client Profitability' },
  { slug: 'rate_spread', label: 'Rate & Spread' },
  { slug: 'overtime_watch', label: 'Overtime Watch' },
  { slug: 'worker_mix', label: 'Worker Mix' },
];

/**
 * Staffing profitability workbench.
 *
 * These routes compose Reports surfaces in the Staffing workflow without
 * moving report ownership into Staffing.
 */
export default function StaffingProfitability({ session }) {
  const loc = useLocation();
  const activeSlug = (loc.pathname.match(/profitability\/([^\/]+)/) || [])[1] || 'overview';

  return (
    <div data-testid="staffing-profitability">
      <div style={{ display:'flex', gap:'var(--cf-space-2)', flexWrap:'wrap', marginBottom:'var(--cf-space-3)', borderBottom: '1px solid var(--cf-border, #e5e7eb)' }}>
        {SUB_TABS.map(t => {
          const active = activeSlug === t.slug;
          return (
            <Link
              key={t.slug}
              to={`/modules/staffing/profitability/${t.slug}`}
              data-testid={`staffing-prof-tab-${t.slug}`}
              style={{
                padding: '8px 14px',
                borderBottom: active ? '2px solid var(--cf-primary, #2563eb)' : '2px solid transparent',
                fontWeight: active ? 600 : 400,
                color: active ? 'var(--cf-primary, #2563eb)' : 'inherit',
                textDecoration: 'none',
              }}
            >{t.label}</Link>
          );
        })}
      </div>

      <Routes>
        <Route index element={<Navigate to="overview" replace />} />
        <Route path="overview" element={<StaffingOverviewReport session={session} />} />
        <Route path="executive_snapshot" element={<ExecutiveSnapshot session={session} />} />
        <Route path="client_profitability" element={<ClientProfitability session={session} />} />
        <Route path="rate_spread" element={<RateSpreadMonitor session={session} />} />
        <Route path="overtime_watch" element={<OvertimeWatch session={session} />} />
        <Route path="worker_mix" element={<WorkerMix session={session} />} />
      </Routes>
    </div>
  );
}

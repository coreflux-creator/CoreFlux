import React from 'react';
import { Link, useLocation } from 'react-router-dom';
import { LayoutDashboard, FileText, Users, TrendingUp, AlertTriangle, Wrench, Folder } from 'lucide-react';

/**
 * Reports module sidebar.
 * Per Reports.docx §Sidebar — "should show only the active Reports module menu",
 * grouped by Overview / industry-specific / Custom / Other.
 */
export default function ReportsSidebar() {
  const { pathname } = useLocation();

  const sections = [
    {
      title: 'Overview',
      items: [
        { to: '/modules/reports/overview', label: 'Staffing Overview', Icon: LayoutDashboard, testid: 'reports-link-overview' },
      ],
    },
    {
      title: 'Staffing',
      items: [
        { to: '/modules/reports/executive_snapshot',   label: 'Executive Snapshot',   Icon: FileText,    testid: 'reports-link-executive' },
        { to: '/modules/reports/client_profitability', label: 'Client Profitability', Icon: Users,       testid: 'reports-link-client-profitability' },
        { to: '/modules/reports/rate_spread',          label: 'Rate & Spread',        Icon: TrendingUp,  testid: 'reports-link-rate-spread' },
        { to: '/modules/reports/overtime_watch',       label: 'Overtime Watch',       Icon: AlertTriangle, testid: 'reports-link-overtime' },
      ],
    },
    {
      title: 'Build',
      items: [
        { to: '/modules/reports/custom', label: 'Custom Reports', Icon: Wrench,  testid: 'reports-link-custom' },
        { to: '/modules/reports/other',  label: 'Other Reports',  Icon: Folder,  testid: 'reports-link-other' },
      ],
    },
  ];

  return (
    <aside
      data-testid="reports-sidebar"
      style={{
        width: 220,
        borderRight: '1px solid var(--cf-border, #e5e7eb)',
        padding: 'var(--cf-space-4)',
        background: 'var(--cf-surface, #fff)',
        flexShrink: 0,
      }}
    >
      <h3 style={{ margin: 0, marginBottom: 'var(--cf-space-3)', fontSize: 14, color: 'var(--cf-text-muted, #6b7280)' }}>Reports</h3>
      {sections.map((s) => (
        <div key={s.title} style={{ marginBottom: 'var(--cf-space-4)' }}>
          <div style={{ fontSize: 11, textTransform: 'uppercase', color: 'var(--cf-text-muted, #6b7280)', letterSpacing: 0.6, marginBottom: 'var(--cf-space-2)' }}>{s.title}</div>
          <nav style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
            {s.items.map(({ to, label, Icon, testid }) => {
              const active = pathname === to;
              return (
                <Link
                  key={to}
                  to={to}
                  data-testid={testid}
                  style={{
                    display: 'flex', alignItems: 'center', gap: 8,
                    padding: '8px 10px',
                    borderRadius: 6,
                    color: active ? 'var(--cf-primary, #2563eb)' : 'var(--cf-text, #111827)',
                    background: active ? 'var(--cf-primary-soft, #eff6ff)' : 'transparent',
                    textDecoration: 'none',
                    fontSize: 14,
                    fontWeight: active ? 600 : 400,
                  }}
                >
                  <Icon size={16} />
                  <span>{label}</span>
                </Link>
              );
            })}
          </nav>
        </div>
      ))}
    </aside>
  );
}

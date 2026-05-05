import React from 'react';
import { Routes, Route, Link, useLocation, Navigate } from 'react-router-dom';
import { BarChart3, DollarSign, Users, Briefcase, Clock, Package } from 'lucide-react';
import ExecutiveDashboard from './ExecutiveDashboard';

/**
 * ReportsModule — top-level Reports app.
 *
 * Layout: left sidebar (navigation across report pages) + content area.
 *
 * Pages:
 *   /modules/reports/exec       — full executive snapshot (KPIs + line charts)
 *   /modules/reports/finance    — finance-only deep dive (TODO: drill page)
 *   /modules/reports/staffing   — staffing-only deep dive (TODO: drill page)
 *
 * The Reports module is the only place that hosts the full executive
 * dashboard. The home page (/) keeps a tiny KPI snapshot strip + a button
 * that brings users here.
 */

const ReportsSidebar = () => {
  const { pathname } = useLocation();
  const links = [
    { to: '/modules/reports/exec',     label: 'Executive snapshot', icon: BarChart3 },
    { to: '/modules/reports/finance',  label: 'Corporate finance',  icon: DollarSign },
    { to: '/modules/reports/staffing', label: 'Staffing operations',icon: Users },
  ];
  return (
    <aside className="sidebar">
      <div className="sidebar-header">
        <h2 className="sidebar-title">Reports</h2>
      </div>
      <nav className="sidebar-nav">
        {links.map(link => {
          const Icon = link.icon;
          const active = pathname === link.to;
          return (
            <div key={link.to} className="sidebar-item">
              <Link to={link.to} className={`sidebar-link ${active ? 'active' : ''}`}
                    data-testid={`reports-link-${link.to.split('/').pop()}`}>
                <Icon size={18} className="sidebar-icon" />
                <span>{link.label}</span>
              </Link>
            </div>
          );
        })}
      </nav>
    </aside>
  );
};

/**
 * Stub pages for finance / staffing deep-dives. They reuse the
 * ExecutiveDashboard but pre-filter to only their band; until those drill
 * views are designed, they redirect to the consolidated /exec.
 */
const FinanceReports  = ({ session }) => <ExecutiveDashboard session={session} bandFilter="finance"  />;
const StaffingReports = ({ session }) => <ExecutiveDashboard session={session} bandFilter="staffing" />;

const ReportsModule = ({ session }) => {
  return (
    <div style={{ display: 'flex', marginLeft: '-220px', minHeight: 'calc(100vh - 56px)' }}>
      <ReportsSidebar />
      <div style={{ flex: 1, marginLeft: '220px', padding: 'var(--cf-space-6)' }}>
        <Routes>
          <Route path="/"          element={<Navigate to="exec" replace />} />
          <Route path="/exec"      element={<ExecutiveDashboard session={session} />} />
          <Route path="/finance"   element={<FinanceReports     session={session} />} />
          <Route path="/staffing"  element={<StaffingReports    session={session} />} />
        </Routes>
      </div>
    </div>
  );
};

export default ReportsModule;

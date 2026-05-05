import React from 'react';
import { Routes, Route, Link, useLocation, Navigate } from 'react-router-dom';
import { BarChart3, DollarSign, Users } from 'lucide-react';
import ExecutiveDashboard from './ExecutiveDashboard';
import FinanceReports from './FinanceReports';
import StaffingReports from './StaffingReports';

/**
 * ReportsModule — top-level Reports app.
 *
 * Pages:
 *   /modules/reports/exec       — full executive snapshot (KPIs + line charts)
 *   /modules/reports/finance    — finance drill: P&L, cash flow, AR/AP detail
 *   /modules/reports/staffing   — staffing drill: placement margins + recruiter board
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

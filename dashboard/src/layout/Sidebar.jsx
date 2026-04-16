import React from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import { 
  LayoutGrid, 
  BookOpen, 
  FileText, 
  TrendingUp, 
  CreditCard, 
  Receipt, 
  PieChart, 
  Settings,
  Users,
  Clock,
  UserCircle,
  BarChart3,
  Briefcase
} from 'lucide-react';

// Icon mapping for sidebar items
const iconMap = {
  'overview': LayoutGrid,
  'chart_of_accounts': BookOpen,
  'chart-of-accounts': BookOpen,
  'journal_entries': FileText,
  'journal-entries': FileText,
  'general_ledger': TrendingUp,
  'general-ledger': TrendingUp,
  'accounts_payable': CreditCard,
  'accounts-payable': CreditCard,
  'accounts_receivable': Receipt,
  'accounts-receivable': Receipt,
  'reports': PieChart,
  'settings': Settings,
  'enter_time': Clock,
  'enter-time': Clock,
  'timesheets': FileText,
  'employee_directory': Users,
  'employee-directory': Users,
  'hiring_pipeline': Briefcase,
  'hiring-pipeline': Briefcase,
  'budgets': BarChart3,
  'forecasts': TrendingUp,
};

const Sidebar = ({ activeModule }) => {
  const location = useLocation();
  
  if (!activeModule) return null;

  const navItems = activeModule.actions || activeModule.navItems || [];
  const moduleId = activeModule.id || activeModule.name?.toLowerCase().replace(/\s+/g, '_');

  return (
    <aside className="sidebar">
      <div className="sidebar-header">
        <h2 className="sidebar-title">{activeModule.name}</h2>
      </div>
      
      <nav className="sidebar-nav">
        {navItems.map((item) => {
          const routeKey = item.route?.replace('.php', '') || item.name?.toLowerCase().replace(/\s+/g, '_');
          const route = routeKey.replace(/_/g, '-');
          const path = `/modules/${moduleId}/${route}`;
          const isActive = location.pathname.includes(route) || location.pathname.includes(routeKey);
          
          // Get icon component
          const IconComponent = iconMap[routeKey] || iconMap[route] || LayoutGrid;
          
          return (
            <div key={item.name || route} className="sidebar-item">
              <NavLink
                to={path}
                className={`sidebar-link ${isActive ? 'active' : ''}`}
              >
                <IconComponent size={18} className="sidebar-icon" />
                <span>{item.name}</span>
              </NavLink>
            </div>
          );
        })}
      </nav>
      
      <div className="sidebar-footer">
        <small>CoreFlux v1.0</small>
      </div>
    </aside>
  );
};

export default Sidebar;

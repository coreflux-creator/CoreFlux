import React from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import {
  BarChart3, BookOpen, Briefcase, Building2, CalendarCheck, CheckSquare,
  Clock3, CreditCard, Gauge, Inbox, LayoutGrid, Link2, ListChecks,
  Receipt, Settings, Sparkles, Users, Wallet,
} from 'lucide-react';

const corefluxMark = '/assets/brand/coreflux-mark.png';

// Retained as the route-level visual vocabulary used by contextual
// navigation. Every common workflow has a deliberate icon.
const iconMap = {
  'overview': Gauge,
  'list': ListChecks,
  'expiring': CalendarCheck,
  'new': Briefcase,
  'commissions': Receipt,
  'referrals': Users,
  'coa': BookOpen,
  'journal': Receipt,
  'reconcile': CheckSquare,
  'transactions-to-review': ListChecks,
};

const WORKSPACE_ITEMS = [
  { key: 'overview', label: 'Overview', to: '/', Icon: LayoutGrid, paths: ['/', '/dashboard'] },
  { key: 'people', label: 'People', to: '/modules/people/directory', Icon: Users, moduleIds: ['people'] },
  { key: 'placements', label: 'Placements', to: '/modules/placements/list', Icon: Briefcase, moduleIds: ['placements', 'staffing'], paths: ['/modules/placements', '/modules/staffing/placements'] },
  { key: 'time', label: 'Time', to: '/modules/staffing/timesheets', Icon: Clock3, moduleIds: ['time', 'staffing'], paths: ['/modules/time', '/modules/staffing/timesheets'] },
  { key: 'billing', label: 'Billing', to: '/modules/billing/invoices', Icon: Receipt, moduleIds: ['billing'] },
  { key: 'ap', label: 'Accounts payable', to: '/modules/ap/bills', Icon: CreditCard, moduleIds: ['ap'] },
  { key: 'accounting', label: 'Accounting', to: '/modules/accounting/bookkeeping', Icon: BookOpen, moduleIds: ['accounting'] },
  { key: 'payroll', label: 'Payroll', to: '/modules/payroll/runs', Icon: CalendarCheck, moduleIds: ['payroll'] },
  { key: 'treasury', label: 'Treasury', to: '/modules/treasury/overview', Icon: Wallet, moduleIds: ['treasury'] },
  { key: 'reports', label: 'Reports', to: '/modules/reports/overview', Icon: BarChart3, moduleIds: ['reports'] },
];

const Sidebar = ({ session, onModuleChange }) => {
  const location = useLocation();
  const modules = session?.modules || [];
  const availableIds = new Set(modules.map(module => module.id));
  const user = session?.user || {};
  const isAdmin = ['master_admin', 'tenant_admin', 'admin'].includes(user.role)
    || ['master_admin', 'tenant_admin'].includes(user.global_role)
    || Boolean(user.is_global_admin);

  const visibleWorkspace = WORKSPACE_ITEMS.filter(item => {
    if (!item.moduleIds) return true;
    return item.moduleIds.some(id => availableIds.has(id));
  });

  const isItemActive = item => {
    const pathname = location.pathname;
    if (item.key === 'overview') return item.paths.includes(pathname);
    const candidates = item.paths || [`/modules/${item.key}`];
    return candidates.some(path => pathname.startsWith(path));
  };

  const activateModule = item => {
    const module = modules.find(candidate => item.moduleIds?.includes(candidate.id));
    if (module) onModuleChange?.(module);
  };

  return (
    <aside className="sidebar" aria-label="CoreFlux workspace">
      <NavLink to="/" className="sidebar-brand" aria-label="CoreFlux overview">
        <img src={corefluxMark} alt="" aria-hidden="true" />
        <span>Core<span>Flux</span></span>
      </NavLink>

      <nav className="sidebar-nav">
        <SidebarGroup label="Your workspace">
          {visibleWorkspace.map(item => (
            <SidebarLink
              key={item.key}
              item={item}
              active={isItemActive(item)}
              onClick={() => activateModule(item)}
            />
          ))}
        </SidebarGroup>

        <SidebarGroup label="Operations">
          <SidebarLink item={{ label: 'Approvals', to: '/inbox', Icon: Inbox }} active={location.pathname === '/inbox'} />
          {availableIds.has('accounting') && (
            <SidebarLink item={{ label: 'Month-end close', to: '/modules/accounting/close', Icon: CheckSquare }} active={location.pathname.startsWith('/modules/accounting/close')} />
          )}
          {isAdmin && (
            <SidebarLink item={{ label: 'Connections', to: '/admin/integrations', Icon: Link2 }} active={location.pathname.startsWith('/admin/integrations')} />
          )}
        </SidebarGroup>
      </nav>

      <div className="sidebar-footer">
        <NavLink to="/ai-agents" className="sidebar-link sidebar-link--assistant">
          <span className="sidebar-icon-wrap" aria-hidden="true"><Sparkles size={16} className="sidebar-icon" /></span>
          <span>Ask CoreFlux</span>
        </NavLink>
        <NavLink to="/settings" className="sidebar-link">
          <span className="sidebar-icon-wrap" aria-hidden="true"><Settings size={16} className="sidebar-icon" /></span>
          <span>Workspace settings</span>
        </NavLink>
        <div className="sidebar-version"><Building2 size={13} aria-hidden="true" /> CoreFlux workspace</div>
      </div>
    </aside>
  );
};

function SidebarGroup({ label, children }) {
  return (
    <section className="sidebar-group">
      <h2 className="sidebar-title">{label}</h2>
      <div>{children}</div>
    </section>
  );
}

function SidebarLink({ item, active, onClick }) {
  const { Icon = LayoutGrid } = item;
  return (
    <div className="sidebar-item">
      <NavLink
        to={item.to}
        end={item.to === '/'}
        className={`sidebar-link ${active ? 'active' : ''}`}
        onClick={onClick}
        title={item.label}
      >
        <span className="sidebar-icon-wrap" aria-hidden="true">
          <Icon size={17} className="sidebar-icon" />
        </span>
        <span>{item.label}</span>
      </NavLink>
    </div>
  );
}

export { iconMap };
export default Sidebar;

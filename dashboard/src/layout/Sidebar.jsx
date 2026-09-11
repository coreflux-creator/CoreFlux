import React from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import {
  LayoutGrid, BookOpen, FileText, TrendingUp, CreditCard, Receipt,
  PieChart, Settings, Users, Clock, UserCircle, BarChart3, Briefcase,
  Calendar, CalendarClock, Banknote, Building2, Shield, ScrollText,
  Wrench, Folder, AlertTriangle, FileSearch, Coins, HandCoins,
  Wallet, Network, Boxes, Layers, ListChecks, Gauge, Target, Hourglass,
  ClipboardCheck, FileCheck2, Mail, Repeat, BarChart, Activity, Tags,
  CheckSquare, Inbox, BadgeDollarSign, FilePlus2, FolderTree, Sparkles
} from 'lucide-react';

/**
 * Per-route icon map. Each known route gets a distinct lucide-react icon
 * so the sidebar reads at a glance instead of being a wall of identical
 * tiles. Add to this when you add a route — the fallback is `LayoutGrid`
 * but every routed page should land a deliberate icon here.
 *
 * Keys are normalised forms: kebab-case, snake_case, or the bare route
 * filename without `.php`. The lookup tries kebab → underscore → fallback.
 */
const iconMap = {
  // Generic
  'overview': Gauge,
  'dashboard': LayoutGrid,
  'list':               ListChecks,
  'new':                FilePlus2,
  'expiring':           Hourglass,
  'commissions':        BadgeDollarSign,
  'referrals':          HandCoins,
  'csv_import':         FilePlus2,       'csv-import':         FilePlus2,
  'export':             FileText,

  // Accounting
  'chart_of_accounts': BookOpen,        'chart-of-accounts': BookOpen,
  'coa':               BookOpen,
  'journal':           FileText,
  'journal_entries':   FileText,        'journal-entries':   FileText,
  'general_ledger':    Layers,          'general-ledger':    Layers,
  'trial_balance':     BarChart3,       'trial-balance':     BarChart3,
  'standard_reports':  PieChart,        'standard-reports':  PieChart,
  'periods':           Calendar,
  'dimensions':        Tags,
  'close':             ClipboardCheck,
  'approval_queue':    ClipboardCheck,  'approval-queue':    ClipboardCheck,
  'bank_rec':          Banknote,        'bank-rec':          Banknote,
  'reconcile':         Banknote,
  'reconciliations':   Banknote,
  'recurring':         Repeat,
  'recurring_journal_entries': Repeat,  'recurring-journal-entries': Repeat,
  'entities':          Building2,
  'consolidation':     Network,
  'intercompany':      Network,
  'fx':                Coins,
  'allocations':       Boxes,
  'bookkeeping':       BookOpen,
  'transactions_to_review': ListChecks, 'transactions-to-review': ListChecks,
  'gl_detail':         Layers,          'gl-detail':         Layers,
  'dim_pnl':           BarChart3,       'dim-pnl':           BarChart3,
  'tax_mappings':      Tags,            'tax-mappings':      Tags,
  'tax_export':        FileText,        'tax-export':        FileText,
  'posting_rules':     Wrench,          'posting-rules':     Wrench,
  'rule_sandbox':      Sparkles,        'rule-sandbox':      Sparkles,
  'events':            Activity,
  'audit':             ScrollText,

  // Treasury
  'deposits':          Wallet,          'deposit-accounts':  Wallet,
  'liabilities':       HandCoins,       'liability-accounts': HandCoins,
  'transactions':      Activity,        'account-transactions': Activity,
  'rules':             Wrench,          'saved-rules':       Wrench,

  // Accounts Payable
  'accounts_payable':  CreditCard,      'accounts-payable':  CreditCard,
  'bills':             Receipt,
  'vendors':           Building2,
  'payments':          BadgeDollarSign,
  'aging':             Hourglass,       'ap-aging':          Hourglass,
  'credits':           Receipt,
  'dunning':           AlertTriangle,
  'tax':               Settings,
  'approvals':         CheckSquare,
  'expenses':          Receipt,
  '1099':              ScrollText,

  // Billing / AR
  'accounts_receivable': Receipt,       'accounts-receivable': Receipt,
  'invoices':          FileText,
  'customers':         Users,
  'clients':           Building2,
  'jobs':              Briefcase,
  'collections':       AlertTriangle,
  'remittances':       FileCheck2,

  // Reports
  'reports':           PieChart,
  'staffing_overview':    Gauge,        'staffing-overview':    Gauge,
  'executive_snapshot':   FileSearch,   'executive-snapshot':   FileSearch,
  'client_profitability': Users,        'client-profitability': Users,
  'rate_spread':       TrendingUp,      'rate-spread':       TrendingUp,
  'overtime_watch':    AlertTriangle,   'overtime-watch':    AlertTriangle,
  'custom':            Wrench,          'custom-reports':    Wrench,
  'other':             Folder,          'other-reports':     Folder,

  // Time
  'enter_time':        Clock,           'enter-time':        Clock,
  'timesheets':        FileText,
  'entries':           Clock,
  'review':            ClipboardCheck,
  'missing':           AlertTriangle,
  'settlement':        Coins,
  'categories':        Tags,
  'bulk':              FilePlus2,
  'pay_periods':       CalendarClock,   'pay-periods':       CalendarClock,
  'pay_schedules':     Calendar,        'pay-schedules':     Calendar,
  'approvals_queue':   CheckSquare,
  'time_off':          Calendar,        'time-off':          Calendar,

  // People / Hiring
  'employee_directory':Users,           'employee-directory':Users,
  'directory':         Users,
  'profiles':          UserCircle,
  'org_chart':         Network,         'org-chart':         Network,
  'hiring_pipeline':   Briefcase,       'hiring-pipeline':   Briefcase,
  'placements':        Briefcase,
  'pipeline':          Briefcase,
  'onboarding':        FilePlus2,
  'workers':           Users,
  'documents':         Folder,
  'custom_fields':     Wrench,           'custom-fields':     Wrench,

  // Payroll
  'runs':              Banknote,
  'cycles':            Repeat,
  'anomalies':         AlertTriangle,
  'payroll_readiness': ClipboardCheck,   'payroll-readiness': ClipboardCheck,
  'billing_readiness': Receipt,          'billing-readiness': Receipt,
  'profitability':     TrendingUp,
  'paystubs':          Receipt,
  'taxes':             FileCheck2,
  'forecasts':         TrendingUp,
  'budgets':           BarChart,

  // Admin
  'users':             Users,
  'tenants':           Building2,
  'audit_log':         ScrollText,      'audit-log':         ScrollText,
  'permissions':       Shield,
  'settings':          Settings,
  'ai_accuracy':       Sparkles,        'ai-accuracy':       Sparkles,
  'export_templates':  FolderTree,      'export-templates':  FolderTree,
  'mail':              Mail,
  'inbox':             Inbox,
  'tasks':             ListChecks,
  'goals':             Target,
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
                data-route={route}
              >
                <span className="sidebar-icon-wrap" aria-hidden="true">
                  <IconComponent size={17} className="sidebar-icon" />
                </span>
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

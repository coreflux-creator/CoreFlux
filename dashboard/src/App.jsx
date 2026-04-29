import React, { useState, useEffect } from 'react';
import { BrowserRouter as Router, Routes, Route, Navigate, useLocation } from 'react-router-dom';
import AppLayout from './layout/AppLayout';
import DashboardOverview from './pages/DashboardOverview';
import ProfilePage from './pages/ProfilePage';
import SettingsPage from './pages/SettingsPage';
import AdminModule from './pages/AdminModule';
import PeopleModule from '../../modules/people/ui/PeopleModule';
import PlacementsModule from '../../modules/placements/ui/PlacementsModule';
import TimeModule from '../../modules/time/ui/TimeModule';
import PayrollModule from '../../modules/payroll/ui/PayrollModule';
import AccountingModule from './modules/AccountingModule';
import FinanceModule from './modules/FinanceModule';
import GenericModule from './modules/GenericModule';

// Loading screen
const LoadingScreen = () => (
  <div className="loading-screen">
    <div className="loading-spinner"></div>
    <p className="loading-text">Loading CoreFlux...</p>
  </div>
);

// Demo session data
const DEMO_SESSION = {
  user: {
    id: 1,
    first_name: 'Kunal',
    last_name: '',
    email: 'kunal@coreflux.app',
    role: 'admin',
    global_role: 'tenant_admin'
  },
  tenant: 'CoreFlux',
  tenants: [
    { id: 1, name: 'CoreFlux', role: 'admin' },
    { id: 2, name: 'Acme Corp', role: 'employee' }
  ],
  modules: [
    {
      id: 'people',
      name: 'People',
      icon: '/assets/icons/icon-people.png',
      description: 'Talent system of record — directory, classification, work auth, skills, documents, hiring pipeline.',
      actions: [
        { name: 'Directory',       route: 'directory' },
        { name: 'Hiring Pipeline', route: 'pipeline' },
        { name: 'Document Vault',  route: 'documents' },
        { name: 'Custom Fields',   route: 'custom_fields' },
      ]
    },
    {
      id: 'placements',
      name: 'Placements',
      icon: '/assets/icons/icon-placements.png',
      description: 'Active engagements — bill/pay rates, vendor chain, commissions, referrals, C2C corp details.',
      actions: [
        { name: 'Active Placements', route: 'list' },
        { name: 'Expiring Soon',     route: 'expiring' },
        { name: 'New Placement',     route: 'new' },
        { name: 'Commissions',       route: 'commissions' },
        { name: 'Referrals',         route: 'referrals' },
        { name: 'Reports',           route: 'reports' },
      ]
    },
    {
      id: 'time',
      name: 'Time',
      icon: '/assets/icons/icon-time.png',
      description: 'Time entries, AI inbox parsing, tokenized client approvals, downstream feeds.',
      actions: [
        { name: 'My Time',            route: 'entries' },
        { name: 'Review Queue',       route: 'review' },
        { name: 'Inbox (AI)',         route: 'inbox' },
        { name: 'Bulk Upload',        route: 'bulk' },
        { name: 'Missing Timesheets', route: 'missing' },
        { name: 'Pay Periods',        route: 'periods' },
        { name: 'Reports',            route: 'reports' },
      ]
    },
    {
      id: 'billing',
      name: 'Billing',
      icon: '/assets/icons/icon-billing.png',
      description: 'Customer invoices, recurring services, payments, AR aging, dunning, credits/debits, tax.',
      actions: [
        { name: 'AR Dashboard',     route: 'dashboard' },
        { name: 'Invoices',         route: 'invoices' },
        { name: 'Recurring',        route: 'recurring' },
        { name: 'Payments',         route: 'payments' },
        { name: 'Credits & Debits', route: 'credits' },
        { name: 'Aging',            route: 'aging' },
        { name: 'Dunning Queue',    route: 'dunning' },
        { name: 'Tax Settings',     route: 'tax' },
        { name: 'Reports',          route: 'reports' },
      ]
    },
    {
      id: 'ap',
      name: 'Accounts Payable',
      icon: '/assets/icons/icon-ap.png',
      description: 'Vendor invoices, payments, 1099 / C2C contractor pay, expense reports, AP aging.',
      actions: [
        { name: 'AP Dashboard',    route: 'dashboard' },
        { name: 'Vendor Inbox',    route: 'inbox' },
        { name: 'Bills',           route: 'bills' },
        { name: 'Payments',        route: 'payments' },
        { name: 'Expense Reports', route: 'expenses' },
        { name: 'AP Aging',        route: 'aging' },
        { name: '1099 Ledger',     route: '1099' },
        { name: 'Reports',         route: 'reports' },
      ]
    },
    {
      id: 'accounting',
      name: 'Accounting',
      icon: '/assets/icons/icon-accounting.png',
      description: 'Enterprise GL — multi-entity, multi-currency, dimensions, allocations, intercompany, consolidation.',
      actions: [
        { name: 'Accounting Dashboard', route: 'dashboard' },
        { name: 'Entities & Groups',    route: 'entities' },
        { name: 'Chart of Accounts',    route: 'coa' },
        { name: 'Journal Entries',      route: 'journal' },
        { name: 'Approval Queue',       route: 'approval-queue' },
        { name: 'Periods',              route: 'periods' },
        { name: 'Period Close',         route: 'close' },
        { name: 'Bank Reconciliation',  route: 'reconcile' },
        { name: 'Allocations',          route: 'allocations' },
        { name: 'Intercompany',         route: 'intercompany' },
        { name: 'Consolidation',        route: 'consolidation' },
        { name: 'Financial Reports',    route: 'reports' },
        { name: 'Audit Log',            route: 'audit' },
      ]
    }
    // Payroll module is approved per HARD_RULES (Phase B / "soon" priority)
    // but the React UI was built before spec sign-off and will be rebuilt.
    // Sidebar entry omitted until the spec'd implementation ships.
  ],
  active_module: null
};

// Session hook
const useSession = () => {
  const [session, setSession] = useState(null);
  const [loading, setLoading] = useState(true);
  const [usingDemo, setUsingDemo] = useState(false);

  useEffect(() => {
    const fetchSession = async () => {
      try {
        const res = await fetch('/session.php', { 
          credentials: 'include',
          headers: { 'Accept': 'application/json' }
        });
        
        if (!res.ok) throw new Error('Not authenticated');
        
        const data = await res.json();
        if (!data.user || data.error) throw new Error(data.error || 'Invalid session');
        
        console.log('Connected to PHP backend:', data.user.email);
        setSession(data);
        setUsingDemo(false);
      } catch (err) {
        console.log('Using demo mode - connect to PHP backend for real data');
        const demoSession = { ...DEMO_SESSION };
        demoSession.active_module = demoSession.modules[0];
        setSession(demoSession);
        setUsingDemo(true);
      } finally {
        setLoading(false);
      }
    };
    fetchSession();
  }, []);

  return { session, loading, usingDemo };
};

// Inner App with router hooks
const AppContent = ({ session, usingDemo }) => {
  const [activeModule, setActiveModule] = useState(null);
  const location = useLocation();

  // Sync active module with URL
  useEffect(() => {
    if (!session?.modules) return;
    
    const pathMatch = location.pathname.match(/\/modules\/([^\/]+)/);
    if (pathMatch) {
      const moduleId = pathMatch[1];
      const mod = session.modules.find(m => m.id === moduleId);
      if (mod && mod.id !== activeModule?.id) {
        setActiveModule(mod);
        return;
      }
    }
    
    // On dashboard/admin/profile/settings, clear module context or keep first
    if (location.pathname === '/' || 
        location.pathname === '/dashboard' || 
        location.pathname.startsWith('/admin') ||
        location.pathname === '/profile' ||
        location.pathname === '/settings') {
      if (!activeModule && session.modules.length > 0) {
        setActiveModule(session.modules[0]);
      }
    }
  }, [session, location.pathname]);

  const handleModuleChange = (mod) => {
    setActiveModule(mod);
  };

  const handleTenantChange = (tenantId) => {
    if (usingDemo) {
      alert('Tenant switching requires PHP backend.');
      return;
    }
    window.location.href = `/switch_tenant.php?tenant_id=${tenantId}`;
  };

  const sessionWithActiveModule = {
    ...session,
    active_module: activeModule
  };

  // Determine if we should show sidebar
  const isMainDashboard = location.pathname === '/' || location.pathname === '/dashboard';
  const isAdminPage = location.pathname.startsWith('/admin');
  const isProfileOrSettings = location.pathname === '/profile' || location.pathname === '/settings';
  const showSidebar = !isMainDashboard && !isAdminPage && !isProfileOrSettings;

  return (
    <>
      {usingDemo && (
        <div className="demo-badge">
          Demo Mode - Connect to PHP backend for full functionality
        </div>
      )}
      
      <AppLayout 
        session={sessionWithActiveModule}
        onModuleChange={handleModuleChange}
        onTenantChange={handleTenantChange}
        showSidebar={showSidebar}
      >
        <Routes>
          {/* Main Dashboard */}
          <Route path="/" element={<DashboardOverview session={session} onModuleChange={handleModuleChange} />} />
          <Route path="/dashboard" element={<DashboardOverview session={session} onModuleChange={handleModuleChange} />} />
          
          {/* Profile & Settings */}
          <Route path="/profile" element={<ProfilePage session={session} />} />
          <Route path="/settings" element={<SettingsPage session={session} />} />
          
          {/* Admin Module */}
          <Route path="/admin/*" element={<AdminModule session={session} />} />
          
          {/* Module Routes */}
          <Route path="/modules/people/*"     element={<PeopleModule     session={session} />} />
          <Route path="/modules/placements/*" element={<PlacementsModule session={session} />} />
          <Route path="/modules/time/*"       element={<TimeModule       session={session} />} />
          {/* Payroll route unwired pending spec'd implementation (HARD_RULES R1: files kept, route disabled). */}
          {/* <Route path="/modules/payroll/*" element={<PayrollModule session={session} />} /> */}
          {/* All other modules (billing, ap, accounting) fall through to GenericModule
              "Coming soon" panel until Phase 4 module implementation ships. */}
          <Route path="/modules/:moduleId/*" element={<GenericModule session={session} activeModule={activeModule} />} />
        </Routes>
      </AppLayout>
    </>
  );
};

// Main App
const App = () => {
  const { session, loading, usingDemo } = useSession();

  if (loading) return <LoadingScreen />;
  if (!session) return <div className="loading-screen"><p>Unable to load session.</p></div>;

  return (
    <Router>
      <AppContent session={session} usingDemo={usingDemo} />
    </Router>
  );
};

export default App;

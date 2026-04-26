import React, { useState, useEffect } from 'react';
import { BrowserRouter as Router, Routes, Route, Navigate, useLocation } from 'react-router-dom';
import AppLayout from './layout/AppLayout';
import DashboardOverview from './pages/DashboardOverview';
import ProfilePage from './pages/ProfilePage';
import SettingsPage from './pages/SettingsPage';
import AdminModule from './pages/AdminModule';
import PeopleModule from '../../modules/people/ui/PeopleModule';
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
      id: 'accounting', 
      name: 'Accounting', 
      icon: '/assets/icons/icon-accounting.png',
      description: 'General ledger and financial reporting',
      actions: [
        { name: 'Overview', route: 'overview' },
        { name: 'Chart of Accounts', route: 'chart_of_accounts' },
        { name: 'Journal Entries', route: 'journal_entries' },
        { name: 'General Ledger', route: 'general_ledger' },
        { name: 'Accounts Payable', route: 'accounts_payable' },
        { name: 'Accounts Receivable', route: 'accounts_receivable' },
        { name: 'Reports', route: 'reports' },
        { name: 'Settings', route: 'settings' }
      ]
    },
    { 
      id: 'people', 
      name: 'People', 
      icon: '/assets/icons/icon-people.png',
      description: 'HR and workforce management',
      actions: [
        { name: 'Overview', route: 'overview' },
        { name: 'Enter Time', route: 'enter_time' },
        { name: 'Timesheets', route: 'timesheets' },
        { name: 'Employee Directory', route: 'employee_directory' },
        { name: 'Reports', route: 'reports' },
        { name: 'Hiring Pipeline', route: 'hiring_pipeline' }
      ]
    },
    { 
      id: 'finance', 
      name: 'Finance', 
      icon: '/assets/icons/icon-finance.png',
      description: 'Budgeting and forecasting',
      actions: [
        { name: 'Overview', route: 'overview' },
        { name: 'Budgets', route: 'budgets' },
        { name: 'Forecasts', route: 'forecasts' },
        { name: 'Reports', route: 'reports' }
      ]
    },
    {
      id: 'payroll',
      name: 'Payroll',
      icon: '/assets/icons/icon-payroll.png',
      description: 'Pay schedules, runs, and gross-to-net calculation',
      actions: [
        { name: 'Overview',       route: 'overview' },
        { name: 'Pay Schedules',  route: 'pay_schedules' },
        { name: 'Pay Periods',    route: 'pay_periods' },
        { name: 'Employee Setup', route: 'profiles' },
        { name: 'Runs',           route: 'runs' },
        { name: 'Settings',       route: 'settings' }
      ]
    }
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
          <Route path="/modules/people/*" element={<PeopleModule session={session} />} />
          <Route path="/modules/payroll/*" element={<PayrollModule session={session} />} />
          <Route path="/modules/accounting/*" element={<AccountingModule session={session} />} />
          <Route path="/modules/finance/*" element={<FinanceModule session={session} />} />
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

import React, { useState, useEffect } from 'react';
import { BrowserRouter as Router, Routes, Route, Navigate, useLocation } from 'react-router-dom';
import AppLayout from './layout/AppLayout';
import PeopleModule from './modules/PeopleModule';
import AccountingModule from './modules/AccountingModule';
import FinanceModule from './modules/FinanceModule';
import GenericModule from './modules/GenericModule';

// Loading screen component
const LoadingScreen = () => (
  <div className="loading-screen">
    <div className="loading-spinner"></div>
    <p className="loading-text">Loading CoreFlux...</p>
  </div>
);

// Demo session data - used when PHP backend is not available
const DEMO_SESSION = {
  user: {
    id: 1,
    first_name: 'Demo',
    last_name: 'User',
    email: 'demo@coreflux.app',
    role: 'admin',
    global_role: 'tenant_admin'
  },
  tenant: 'CoreFlux Demo',
  tenants: [
    { id: 1, name: 'CoreFlux Demo', role: 'admin' },
    { id: 2, name: 'Acme Corp', role: 'employee' }
  ],
  modules: [
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
      id: 'accounting', 
      name: 'Accounting', 
      icon: '/assets/icons/icon-accounting.png',
      description: 'General ledger and financial reporting',
      actions: [
        { name: 'Overview', route: 'overview' },
        { name: 'Chart of Accounts', route: 'chart_of_accounts' },
        { name: 'Journal Entries', route: 'journal_entries' },
        { name: 'Accounts Payable', route: 'accounts_payable' },
        { name: 'Accounts Receivable', route: 'accounts_receivable' },
        { name: 'Bank Reconciliation', route: 'bank_reconciliation' },
        { name: 'Reports', route: 'reports' }
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
    }
  ],
  active_module: null
};

// Hook to fetch session from PHP backend
const useSession = () => {
  const [session, setSession] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [usingDemo, setUsingDemo] = useState(false);

  const fetchSession = async () => {
    try {
      const res = await fetch('/session.php', { 
        credentials: 'include',
        headers: { 'Accept': 'application/json' }
      });
      
      if (!res.ok) {
        throw new Error('Not authenticated');
      }
      
      const data = await res.json();
      
      if (!data.user) {
        throw new Error('Invalid session data');
      }
      
      setSession(data);
      setError(null);
      setUsingDemo(false);
    } catch (err) {
      console.warn('Could not fetch session from PHP, using demo mode:', err.message);
      const demoSession = { ...DEMO_SESSION };
      demoSession.active_module = demoSession.modules[0];
      setSession(demoSession);
      setUsingDemo(true);
      setError(null);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchSession();
  }, []);

  return { session, loading, error, usingDemo, refetch: fetchSession };
};

// Inner App component that has access to router hooks
const AppContent = ({ session, usingDemo }) => {
  const [activeModule, setActiveModule] = useState(null);
  const location = useLocation();

  // Sync active module with URL path
  useEffect(() => {
    if (!session?.modules) return;
    
    const pathMatch = location.pathname.match(/\/modules\/([^\/]+)/);
    if (pathMatch) {
      const moduleIdFromUrl = pathMatch[1];
      const moduleFromUrl = session.modules.find(m => 
        m.id === moduleIdFromUrl || 
        m.name.toLowerCase().replace(/\s+/g, '_') === moduleIdFromUrl
      );
      if (moduleFromUrl && moduleFromUrl.id !== activeModule?.id) {
        setActiveModule(moduleFromUrl);
        return;
      }
    }
    
    // Fall back to first module if no active module
    if (!activeModule && session?.modules?.length > 0) {
      setActiveModule(session.modules[0]);
    }
  }, [session, location.pathname]);

  // Handle module change from dropdown
  const handleModuleChange = async (mod) => {
    setActiveModule(mod);
    
    if (!usingDemo) {
      try {
        await fetch('/update_active_module.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ module: mod.id || mod.name }),
          credentials: 'include'
        });
      } catch (e) {
        console.warn('Failed to update active module on server');
      }
    }
  };

  // Handle tenant change
  const handleTenantChange = (tenantId) => {
    if (usingDemo) {
      alert('Tenant switching requires PHP backend. This is demo mode.');
      return;
    }
    window.location.href = `/dashboard.php?switch_tenant=${tenantId}`;
  };

  const sessionWithActiveModule = {
    ...session,
    active_module: activeModule
  };

  return (
    <>
      {usingDemo && (
        <div style={{
          position: 'fixed',
          bottom: '16px',
          right: '16px',
          background: '#f59e0b',
          color: '#000',
          padding: '8px 16px',
          borderRadius: '6px',
          fontSize: '12px',
          fontWeight: '500',
          zIndex: 9999,
          boxShadow: '0 4px 12px rgba(0,0,0,0.15)'
        }}>
          Demo Mode - Connect to PHP backend for full functionality
        </div>
      )}
      
      <AppLayout 
        session={sessionWithActiveModule}
        onModuleChange={handleModuleChange}
        onTenantChange={handleTenantChange}
      >
        <Routes>
          <Route path="/modules/people/*" element={<PeopleModule session={session} />} />
          <Route path="/modules/accounting/*" element={<AccountingModule session={session} />} />
          <Route path="/modules/finance/*" element={<FinanceModule session={session} />} />
          <Route path="/modules/:moduleId/*" element={<GenericModule session={session} activeModule={activeModule} />} />
          <Route path="*" element={
            <Navigate to={`/modules/${activeModule?.id || 'people'}/overview`} replace />
          } />
        </Routes>
      </AppLayout>
    </>
  );
};

// Main App Component
const App = () => {
  const { session, loading, error, usingDemo } = useSession();

  if (loading) {
    return <LoadingScreen />;
  }

  if (!session) {
    return (
      <div className="loading-screen">
        <p className="loading-text">Unable to load session. Please refresh.</p>
      </div>
    );
  }

  return (
    <Router>
      <AppContent session={session} usingDemo={usingDemo} />
    </Router>
  );
};

export default App;

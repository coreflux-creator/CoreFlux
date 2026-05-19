import React, { useState, useEffect } from 'react';
import { BrowserRouter as Router, Routes, Route, Navigate, useLocation } from 'react-router-dom';
import AppLayout from './layout/AppLayout';
import DashboardOverview from './pages/DashboardOverview';
import ExecutiveDashboard from './pages/ExecutiveDashboard';
import CFODashboard from './pages/CFODashboard';
import ReportsModule from '../../modules/reports/ui/ReportsModule';
import ProfilePage from './pages/ProfilePage';
import SettingsPage from './pages/SettingsPage';
import MailSettingsPage from './pages/MailSettingsPage';
import AdminModule from './pages/AdminModule';
import TenantPicker from './pages/TenantPicker';
import PeopleModule from '../../modules/people/ui/PeopleModule';
import PlacementsModule from '../../modules/placements/ui/PlacementsModule';
import TimeModule from '../../modules/time/ui/TimeModule';
import StaffingModule from '../../modules/staffing/ui/StaffingModule';
import BillingModule from '../../modules/billing/ui/BillingModule';
import APModule from '../../modules/ap/ui/APModule';
import PayrollModule from '../../modules/payroll/ui/PayrollModule';
import AccountingModule from './modules/AccountingModule';
import AccountingV1Module from '../../modules/accounting/ui/AccountingModule';
import TreasuryModule from '../../modules/treasury/ui/TreasuryModule';
import VendorPortal from './pages/VendorPortal';
import ScenarioShare from './pages/ScenarioShare';
import FinanceModule from './modules/FinanceModule';
import GenericModule from './modules/GenericModule';
import WorkflowInbox from './pages/WorkflowInbox';
import AIAgents from './pages/AIAgents';
import CsvBulkImport from './pages/CsvBulkImport';
import CsvImportHistory from './pages/CsvImportHistory';
import SimulationDashboard from './pages/SimulationDashboard';
import RuleProposals from './pages/RuleProposals';
import Login from './pages/Login';
import MagicLinkConsume from './pages/MagicLinkConsume';
import ErrorBoundary from './components/ErrorBoundary';

// Loading screen
const LoadingScreen = () => (
  <div className="loading-screen">
    <div className="loading-spinner"></div>
    <p className="loading-text">Loading CoreFlux...</p>
  </div>
);

// Demo session data
// PRESERVED for local dev only (set window.__CF_FORCE_DEMO__ = true before
// loading the SPA bundle to opt in). Production never falls back to demo —
// an unauthenticated /session.php response now redirects to /login.php
// instead of dropping a "Demo Mode" badge over the real app.
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
      id: 'staffing',
      name: 'Staffing',
      icon: '/assets/icons/icon-time.png',
      description: 'Client-facing labor: placements, weekly timesheets, approvals, payroll/billing readiness, margin.',
      actions: [
        { name: 'Overview',           route: 'overview' },
        { name: 'Placements',         route: 'placements' },
        { name: 'Timesheets',         route: 'timesheets' },
        { name: 'Approvals',          route: 'approvals' },
        { name: 'Clients',            route: 'clients' },
        { name: 'Jobs',               route: 'jobs' },
        { name: 'Payroll Readiness',  route: 'payroll-readiness' },
        { name: 'Billing Readiness',  route: 'billing-readiness' },
        { name: 'Profitability',      route: 'profitability' },
        { name: 'Settings',           route: 'settings' },
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
        { name: 'Bookkeeping Overview', route: 'bookkeeping' },
        { name: 'Transactions to Review', route: 'transactions-to-review' },
        { name: 'GL Detail',            route: 'gl-detail' },
        { name: 'Dimensional P&L',      route: 'dim-pnl' },
        { name: 'Tax Mappings',         route: 'tax-mappings' },
        { name: 'Tax Export',           route: 'tax-export' },
        { name: 'Posting Rules',        route: 'posting-rules' },
        { name: 'Rule Sandbox',         route: 'rule-sandbox' },
        { name: 'Accounting Events',    route: 'events' },
        { name: 'Audit Log',            route: 'audit' },
      ]
    },
    {
      id: 'payroll',
      name: 'Payroll',
      icon: '/assets/icons/icon-payroll.png',
      description: 'W-2 employee compensation runs — pay schedules, periods, profiles, runs, paystubs, NACHA disbursement.',
      actions: [
        { name: 'Overview',       route: 'overview' },
        { name: 'Pay Schedules',  route: 'pay_schedules' },
        { name: 'Pay Periods',    route: 'pay_periods' },
        { name: 'Employee Setup', route: 'profiles' },
        { name: 'Runs',           route: 'runs' },
        { name: 'Settings',       route: 'settings' },
      ]
    },
    {
      id: 'treasury',
      name: 'Treasury',
      description: 'Deposit + liability account ledgers, bank feeds, cash position.',
      actions: [
        { name: 'Overview',           route: 'overview' },
        { name: 'Deposit Accounts',   route: 'deposits' },
        { name: 'Liability Accounts', route: 'liabilities' },
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
    // Allow public routes (login, magic-link consume) to render without
    // requiring an authenticated session — otherwise the SPA's session
    // check would redirect users away from the very page that authenticates
    // them.
    const path = typeof window !== 'undefined' ? window.location.pathname : '';
    const isPublicRoute = path === '/login' || path.startsWith('/auth/m/');
    if (isPublicRoute) {
      setSession({ __public: true });
      setLoading(false);
      return;
    }

    const fetchSession = async () => {
      try {
        const res = await fetch('/session.php', {
          credentials: 'include',
          headers: { 'Accept': 'application/json' }
        });

        if (res.status === 401) {
          // Not authenticated → punt to the SPA login route.
          const next = encodeURIComponent(window.location.pathname + window.location.hash);
          window.location.replace(`/login?next=${next}`);
          return;
        }
        if (!res.ok) throw new Error(`session.php ${res.status}`);

        const data = await res.json();
        if (!data.user || data.error) throw new Error(data.error || 'Invalid session');

        // Merge in SPA-side modules that aren't yet enabled in tenant_modules
        // for this tenant. Lets newly-launched modules (Staffing during its
        // rollout, etc.) appear in the module switcher and sidebar before
        // every tenant's DB row has caught up. Once tenant_modules has the
        // row, the DB-sourced entry wins (dedup by id).
        const dbModules = Array.isArray(data.modules) ? data.modules : [];
        const dbIds = new Set(dbModules.map(m => m.id));
        const spaOnly = DEMO_SESSION.modules.filter(m => !dbIds.has(m.id) && ['staffing'].includes(m.id));
        data.modules = [...dbModules, ...spaOnly];

        console.log('Connected to PHP backend:', data.user.email);
        setSession(data);
        setUsingDemo(false);
      } catch (err) {
        // Hard-failure path. Only opt into demo when the dev explicitly
        // sets window.__CF_FORCE_DEMO__ = true (offline dev). Otherwise
        // show a clean error rather than silently masking real bugs.
        if (typeof window !== 'undefined' && window.__CF_FORCE_DEMO__ === true) {
          const demoSession = { ...DEMO_SESSION };
          demoSession.active_module = demoSession.modules[0];
          setSession(demoSession);
          setUsingDemo(true);
        } else {
          console.error('Session load failed:', err);
          setSession({ __error: String(err.message || err) });
          setUsingDemo(false);
        }
      } finally {
        setLoading(false);
      }
    };
    fetchSession();
  }, []);

  // RBAC B5 — react to persona switches from the header dropdown.
  // Header.jsx fires `cf:active-persona-changed` after a successful POST
  // to /api/active_persona.php.  The session-baked role, modules sidebar,
  // and every gated button were derived from $ctx['role'] at login time,
  // so re-fetch /session.php to pick up the new persona's effective role,
  // then trigger a soft reload so module-level guards see it too.
  useEffect(() => {
    const onPersonaChanged = () => {
      // Soft refresh: reload the page so the SPA reboots against the new
      // $_SESSION['active_persona_id']. Lower blast-radius than wiring
      // every gated component to a context subscription, and the API
      // round-trip cost is one /session.php call.
      window.location.reload();
    };
    window.addEventListener('cf:active-persona-changed', onPersonaChanged);
    return () => window.removeEventListener('cf:active-persona-changed', onPersonaChanged);
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
      // Fallback: tenant doesn't have this module in their subscription yet
      // (newly-launched modules like Staffing haven't been auto-enabled in
      // tenant_modules). Synthesize a minimal module object from the
      // DEMO_SESSION manifest so the sidebar still renders the right nav
      // instead of showing the wrong module's actions.
      if (!mod && moduleId !== activeModule?.id) {
        const fallback = DEMO_SESSION.modules.find(m => m.id === moduleId);
        if (fallback) { setActiveModule(fallback); return; }
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
    window.location.href = `/switch_tenant.php?tenant_id=${tenantId}&next=/spa.php`;
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
        <ErrorBoundary>
          <Routes>
          {/* Home: module-cards overview for everyone, with a small KPI
              snapshot strip at the top. The full executive snapshot lives
              under /modules/reports — Reports is its own module. */}
          <Route path="/"          element={<DashboardOverview session={session} onModuleChange={handleModuleChange} />} />
          <Route path="/dashboard" element={<DashboardOverview session={session} onModuleChange={handleModuleChange} />} />
          {/* Backwards-compat: the old /exec route now bounces into the
              Reports module so anyone still hitting it lands correctly. */}
          <Route path="/exec"      element={<Navigate to="/modules/reports/exec" replace />} />
          {/* CFO Dashboard — high-level KPI surface with custom views, AI
              annotations, per-section notes, and on-demand report email. */}
          <Route path="/cfo"       element={<CFODashboard session={session} />} />
          
          {/* Tenant picker */}
          <Route path="/select-tenant" element={<TenantPicker session={session} />} />

          {/* Profile & Settings */}
          <Route path="/profile" element={<ProfilePage session={session} />} />
          <Route path="/settings" element={<SettingsPage session={session} />} />
          <Route path="/settings/mail" element={<MailSettingsPage session={session} />} />
          {/* Cross-module approval inbox (Sprint 6b) */}
          <Route path="/inbox" element={<WorkflowInbox session={session} />} />

          {/* AI Agents — Phase A.0 promoted from accounting sub-route to a
              top-level platform feature. The legacy /modules/accounting/ai-agents
              path still resolves via the AccountingModule redirect alias for
              bookmarks. */}
          <Route path="/ai-agents" element={<AIAgents session={session} />} />

          {/* Bulk CSV importer — drop multiple CSVs at once for tenant
              data onboarding (people, vendors, clients, placements, time,
              bills, invoices). Auto-detects entity from header signature,
              dry-runs all files, commits them in FK-respecting order. */}
          <Route path="/data/bulk-import" element={<CsvBulkImport />} />
          {/* Auditor + CFO view of every CSV import committed. */}
          <Route path="/data/import-history" element={<CsvImportHistory />} />

          {/* Simulation Harness — deterministic financial wind tunnel.
              Runs are CLI-driven; this page is the read-only auditor
              view (runs, scenarios, discipline log, replay drill-in). */}
          <Route path="/sim" element={<SimulationDashboard />} />
          <Route path="/ai/rule-proposals" element={<RuleProposals />} />
          
          {/* Admin Module */}
          <Route path="/admin/*" element={<AdminModule session={session} />} />
          
          {/* Module Routes */}
          <Route path="/modules/people/*"     element={<PeopleModule     session={session} />} />
          <Route path="/modules/staffing/*"   element={<StaffingModule   session={session} />} />
          {/* Back-compat shims: keep old direct URLs working while sidebar nav
              promotes the new /modules/staffing/* umbrella. Remove after Phase 2. */}
          <Route path="/modules/placements/*" element={<PlacementsModule session={session} />} />
          <Route path="/modules/time/*"       element={<TimeModule       session={session} />} />
          <Route path="/modules/billing/*"    element={<BillingModule    session={session} />} />
          <Route path="/modules/ap/*"         element={<APModule         session={session} />} />
          <Route path="/modules/accounting/*" element={<AccountingV1Module session={session} />} />
          <Route path="/modules/payroll/*"    element={<PayrollModule    session={session} />} />
          <Route path="/modules/treasury/*"   element={<TreasuryModule   session={session} />} />
          <Route path="/modules/reports/*"    element={<ReportsModule    session={session} />} />
          {/* Vendor self-service portal — uses its own cf_vp_sid cookie auth,
              independent of platform user session. */}
          <Route path="/vendor/portal"        element={<VendorPortal />} />
          {/* Public share-link viewer — token-only auth, no platform-user
              session needed. Mounts independently of any module. */}
          <Route path="/share/scenario"       element={<ScenarioShare />} />
          {/* All other modules fall through to GenericModule
              "Coming soon" panel until Phase 4 module implementation ships. */}
          <Route path="/modules/:moduleId/*" element={<GenericModule session={session} activeModule={activeModule} />} />
          </Routes>
        </ErrorBoundary>
      </AppLayout>
    </>
  );
};

// Main App
const App = () => {
  const { session, loading, usingDemo } = useSession();

  if (loading) return <LoadingScreen />;

  // Public (unauthenticated) routes — login + magic-link consume.
  if (session?.__public) {
    return (
      <Router>
        <Routes>
          <Route path="/login"        element={<Login />} />
          <Route path="/auth/m/:token" element={<MagicLinkConsume />} />
          <Route path="*"              element={<Login />} />
        </Routes>
      </Router>
    );
  }

  if (session?.__error) {
    return (
      <div className="loading-screen" data-testid="session-error-screen">
        <div style={{ maxWidth: 480, textAlign: 'center', padding: 32 }}>
          <h2 style={{ marginBottom: 12 }}>We couldn't load your session</h2>
          <p style={{ color: 'var(--cf-text-secondary)', marginBottom: 20 }}>
            {session.__error}
          </p>
          <a href="/login" className="btn btn--primary" data-testid="session-error-login-link">
            Sign in again
          </a>
        </div>
      </div>
    );
  }
  if (!session) return <div className="loading-screen"><p>Unable to load session.</p></div>;

  return (
    <Router>
      <AppContent session={session} usingDemo={usingDemo} />
    </Router>
  );
};

export default App;

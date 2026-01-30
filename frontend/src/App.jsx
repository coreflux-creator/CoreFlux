import { Routes, Route, Navigate } from 'react-router-dom'
import { AuthProvider, useAuth } from '@/hooks/useAuth'
import { ModulesProvider } from '@/hooks/useModules'

// Layout
import DashboardLayout from '@/components/layout/DashboardLayout'

// Pages
import LoginPage from '@/pages/LoginPage'
import DashboardPage from '@/pages/DashboardPage'

// Admin Pages
import AdminDashboard from '@/pages/admin/AdminDashboard'
import TenantsPage from '@/pages/admin/TenantsPage'
import UsersPage from '@/pages/admin/UsersPage'
import ModulesPage from '@/pages/admin/ModulesPage'

// Module Pages
import AccountingOverview from '@/pages/modules/accounting/AccountingOverview'
import PeopleOverview from '@/pages/modules/people/PeopleOverview'

// Protected Route wrapper
function ProtectedRoute({ children, requireAdmin = false }) {
  const { isAuthenticated, loading, isMasterAdmin } = useAuth()

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-cf-navy"></div>
      </div>
    )
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />
  }

  if (requireAdmin && !isMasterAdmin) {
    return <Navigate to="/dashboard" replace />
  }

  return children
}

// Placeholder pages
function PlaceholderPage({ title }) {
  return (
    <div className="bg-white rounded-xl border p-8 text-center">
      <h1 className="text-xl font-semibold text-gray-900 mb-2">{title}</h1>
      <p className="text-gray-500">This page is under development.</p>
    </div>
  )
}

function AppRoutes() {
  const { isAuthenticated } = useAuth()

  return (
    <Routes>
      {/* Public routes */}
      <Route 
        path="/login" 
        element={isAuthenticated ? <Navigate to="/dashboard" replace /> : <LoginPage />} 
      />

      {/* Protected routes */}
      <Route
        path="/"
        element={
          <ProtectedRoute>
            <ModulesProvider>
              <DashboardLayout />
            </ModulesProvider>
          </ProtectedRoute>
        }
      >
        {/* Dashboard */}
        <Route index element={<Navigate to="/dashboard" replace />} />
        <Route path="dashboard" element={<DashboardPage />} />
        <Route path="profile" element={<PlaceholderPage title="Profile" />} />
        <Route path="settings" element={<PlaceholderPage title="Settings" />} />

        {/* Accounting Module */}
        <Route path="modules/accounting" element={<AccountingOverview />} />
        <Route path="modules/accounting/accounts" element={<PlaceholderPage title="Chart of Accounts" />} />
        <Route path="modules/accounting/journal" element={<PlaceholderPage title="Journal Entries" />} />
        <Route path="modules/accounting/ledger" element={<PlaceholderPage title="General Ledger" />} />
        <Route path="modules/accounting/ap" element={<PlaceholderPage title="Accounts Payable" />} />
        <Route path="modules/accounting/ar" element={<PlaceholderPage title="Accounts Receivable" />} />
        <Route path="modules/accounting/reports" element={<PlaceholderPage title="Accounting Reports" />} />
        <Route path="modules/accounting/settings" element={<PlaceholderPage title="Accounting Settings" />} />

        {/* People Module */}
        <Route path="modules/people" element={<PeopleOverview />} />
        <Route path="modules/people/directory" element={<PlaceholderPage title="Employee Directory" />} />
        <Route path="modules/people/timesheets" element={<PlaceholderPage title="Timesheets" />} />
        <Route path="modules/people/approvals" element={<PlaceholderPage title="Approvals" />} />
        <Route path="modules/people/hiring" element={<PlaceholderPage title="Hiring Pipeline" />} />
        <Route path="modules/people/reports" element={<PlaceholderPage title="People Reports" />} />
        <Route path="modules/people/settings" element={<PlaceholderPage title="People Settings" />} />
      </Route>

      {/* Admin routes */}
      <Route
        path="/admin"
        element={
          <ProtectedRoute requireAdmin>
            <ModulesProvider>
              <DashboardLayout />
            </ModulesProvider>
          </ProtectedRoute>
        }
      >
        <Route index element={<AdminDashboard />} />
        <Route path="tenants" element={<TenantsPage />} />
        <Route path="users" element={<UsersPage />} />
        <Route path="modules" element={<ModulesPage />} />
        <Route path="permissions" element={<PlaceholderPage title="Permissions" />} />
        <Route path="settings" element={<PlaceholderPage title="Admin Settings" />} />
      </Route>

      {/* Catch all */}
      <Route path="*" element={<Navigate to="/dashboard" replace />} />
    </Routes>
  )
}

export default function App() {
  return (
    <AuthProvider>
      <AppRoutes />
    </AuthProvider>
  )
}

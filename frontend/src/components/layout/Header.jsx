import { useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '@/hooks/useAuth'
import { useModules } from '@/hooks/useModules'
import { 
  ChevronDown, 
  LogOut, 
  Settings, 
  User,
  Shield,
  Building2,
  LayoutDashboard
} from 'lucide-react'

export default function Header() {
  const { user, tenant, tenants, logout, switchTenant, isMasterAdmin } = useAuth()
  const { modules, activeModule, switchModule } = useModules()
  const location = useLocation()
  const navigate = useNavigate()
  const [showUserMenu, setShowUserMenu] = useState(false)
  const [showTenantMenu, setShowTenantMenu] = useState(false)
  const [showModuleMenu, setShowModuleMenu] = useState(false)

  const isAdminRoute = location.pathname.startsWith('/admin')
  const isModuleRoute = location.pathname.startsWith('/modules')

  const handleModuleSelect = (module) => {
    switchModule(module)
    setShowModuleMenu(false)
    // Navigate to the module page
    const moduleKey = module.key || module.name?.toLowerCase()
    navigate(`/modules/${moduleKey}`)
  }

  return (
    <header 
      className={`h-14 flex items-center justify-between px-4 ${isAdminRoute ? 'bg-red-900' : 'bg-cf-navy'}`}
      data-testid="header"
    >
      {/* Left: Logo */}
      <div className="flex items-center gap-4">
        <Link to="/dashboard" className="flex items-center gap-2" data-testid="header-logo">
          <img 
            src="./logo-header.png" 
            alt="CoreFlux"
            className="h-8"
          />
        </Link>
        
        {isAdminRoute && (
          <span className="bg-red-700 text-white text-xs px-2 py-1 rounded font-medium" data-testid="admin-badge">
            Master Admin
          </span>
        )}
      </div>

      {/* Center: Navigation */}
      {!isAdminRoute && (
        <div className="flex items-center gap-2">
          {/* Dashboard Link */}
          <Link
            to="/dashboard"
            className={`flex items-center gap-2 px-3 py-2 rounded-lg transition-colors ${
              !isModuleRoute 
                ? 'bg-white/20 text-white' 
                : 'text-white/70 hover:text-white hover:bg-white/10'
            }`}
            data-testid="dashboard-link"
          >
            <LayoutDashboard className="w-4 h-4" />
            <span className="text-sm font-medium">Dashboard</span>
          </Link>

          {/* Module Switcher */}
          {modules.length > 0 && (
            <div className="relative">
              <button
                onClick={() => setShowModuleMenu(!showModuleMenu)}
                className={`flex items-center gap-2 px-3 py-2 rounded-lg transition-colors ${
                  isModuleRoute 
                    ? 'bg-white/20 text-white' 
                    : 'text-white/70 hover:text-white hover:bg-white/10'
                }`}
                data-testid="module-switcher"
              >
                <span className="text-sm font-medium">{activeModule?.name || 'Modules'}</span>
                <ChevronDown className="w-4 h-4" />
              </button>
              
              {showModuleMenu && (
                <div className="absolute top-full mt-1 left-0 bg-white rounded-lg shadow-lg py-1 min-w-[200px] z-50 border border-gray-100">
                  {modules.map((module) => {
                    const moduleKey = module.key || module.name?.toLowerCase()
                    const isActive = location.pathname.startsWith(`/modules/${moduleKey}`)
                    return (
                      <button
                        key={module.id}
                        onClick={() => handleModuleSelect(module)}
                        className={`w-full text-left px-4 py-2 hover:bg-cf-soft transition-colors ${
                          isActive ? 'bg-cf-soft text-cf-flux font-medium' : 'text-cf-dark'
                        }`}
                        data-testid={`module-option-${module.id}`}
                      >
                        {module.name}
                      </button>
                    )
                  })}
                </div>
              )}
            </div>
          )}
        </div>
      )}

      {/* Right: Tenant + User */}
      <div className="flex items-center gap-3">
        {/* Admin Panel Link */}
        {isMasterAdmin && (
          <Link
            to={isAdminRoute ? '/dashboard' : '/admin'}
            className="flex items-center gap-2 text-white/80 hover:text-white px-3 py-1.5 rounded-lg hover:bg-white/10 transition-colors text-sm"
            data-testid="admin-link"
          >
            <Shield className="w-4 h-4" />
            {isAdminRoute ? 'Exit Admin' : 'Admin Panel'}
          </Link>
        )}

        {/* Tenant Switcher */}
        {tenants.length > 1 && !isAdminRoute && (
          <div className="relative">
            <button
              onClick={() => setShowTenantMenu(!showTenantMenu)}
              className="flex items-center gap-2 text-white/80 hover:text-white px-3 py-1.5 rounded-lg hover:bg-white/10 transition-colors text-sm"
              data-testid="tenant-switcher"
            >
              <Building2 className="w-4 h-4" />
              <span>{tenant?.name}</span>
              <ChevronDown className="w-4 h-4" />
            </button>
            
            {showTenantMenu && (
              <div className="absolute top-full mt-1 right-0 bg-white rounded-lg shadow-lg py-1 min-w-[200px] z-50 border border-gray-100">
                {tenants.map((t) => (
                  <button
                    key={t.id}
                    onClick={() => {
                      switchTenant(t)
                      setShowTenantMenu(false)
                    }}
                    className={`w-full text-left px-4 py-2 hover:bg-cf-soft transition-colors ${
                      tenant?.id === t.id ? 'bg-cf-soft text-cf-flux' : 'text-cf-dark'
                    }`}
                    data-testid={`tenant-option-${t.id}`}
                  >
                    <div className="font-medium">{t.name}</div>
                    <div className="text-xs text-cf-dark/60">{t.role}</div>
                  </button>
                ))}
              </div>
            )}
          </div>
        )}

        {/* User Menu */}
        <div className="relative">
          <button
            onClick={() => setShowUserMenu(!showUserMenu)}
            className="flex items-center gap-2 text-white hover:bg-white/10 px-3 py-2 rounded-lg transition-colors"
            data-testid="user-menu-trigger"
          >
            <div className="w-8 h-8 rounded-full bg-cf-flux/30 flex items-center justify-center text-sm font-medium">
              {user?.first_name?.[0] || user?.name?.[0] || 'U'}
            </div>
            <span className="text-sm">{user?.first_name || user?.name}</span>
            <ChevronDown className="w-4 h-4" />
          </button>
          
          {showUserMenu && (
            <div className="absolute top-full mt-1 right-0 bg-white rounded-lg shadow-lg py-1 min-w-[220px] z-50 border border-gray-100">
              <div className="px-4 py-3 border-b border-gray-100">
                <div className="font-semibold text-cf-navy">{user?.first_name} {user?.last_name}</div>
                <div className="text-sm text-cf-dark/60">{user?.email}</div>
                {isMasterAdmin && (
                  <div className="text-xs text-red-600 mt-1 font-medium">Master Admin</div>
                )}
              </div>
              
              <Link
                to="/profile"
                className="flex items-center gap-3 px-4 py-2.5 text-cf-dark hover:bg-cf-soft transition-colors"
                onClick={() => setShowUserMenu(false)}
                data-testid="profile-link"
              >
                <User className="w-4 h-4 text-cf-dark/60" />
                Profile
              </Link>
              
              <Link
                to="/settings"
                className="flex items-center gap-3 px-4 py-2.5 text-cf-dark hover:bg-cf-soft transition-colors"
                onClick={() => setShowUserMenu(false)}
                data-testid="settings-link"
              >
                <Settings className="w-4 h-4 text-cf-dark/60" />
                Settings
              </Link>
              
              <hr className="my-1 border-gray-100" />
              
              <button
                onClick={() => {
                  logout()
                  setShowUserMenu(false)
                }}
                className="flex items-center gap-3 px-4 py-2.5 text-red-600 hover:bg-red-50 w-full transition-colors"
                data-testid="logout-button"
              >
                <LogOut className="w-4 h-4" />
                Logout
              </button>
            </div>
          )}
        </div>
      </div>

      {/* Click outside to close menus */}
      {(showUserMenu || showTenantMenu || showModuleMenu) && (
        <div 
          className="fixed inset-0 z-40" 
          onClick={() => {
            setShowUserMenu(false)
            setShowTenantMenu(false)
            setShowModuleMenu(false)
          }}
        />
      )}
    </header>
  )
}

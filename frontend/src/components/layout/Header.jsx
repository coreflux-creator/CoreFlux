import { useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { useAuth } from '@/hooks/useAuth'
import { useModules } from '@/hooks/useModules'
import { 
  ChevronDown, 
  LogOut, 
  Settings, 
  User,
  Shield,
  Building2
} from 'lucide-react'

export default function Header() {
  const { user, tenant, tenants, logout, switchTenant, isMasterAdmin } = useAuth()
  const { modules, activeModule, switchModule } = useModules()
  const location = useLocation()
  const [showUserMenu, setShowUserMenu] = useState(false)
  const [showTenantMenu, setShowTenantMenu] = useState(false)
  const [showModuleMenu, setShowModuleMenu] = useState(false)

  const isAdminRoute = location.pathname.startsWith('/admin')

  return (
    <header className={`h-14 flex items-center justify-between px-4 ${isAdminRoute ? 'bg-red-900' : 'bg-cf-navy'}`}>
      {/* Left: Logo */}
      <div className="flex items-center gap-4">
        <Link to="/dashboard" className="flex items-center gap-2">
          <img src="/logo-white.png" alt="CoreFlux" className="h-8" onError={(e) => e.target.style.display = 'none'} />
          <span className="text-white font-semibold text-lg">CoreFlux</span>
        </Link>
        
        {isAdminRoute && (
          <span className="bg-red-700 text-white text-xs px-2 py-1 rounded font-medium">
            Master Admin
          </span>
        )}
      </div>

      {/* Center: Module Switcher (not shown in admin mode) */}
      {!isAdminRoute && modules.length > 0 && (
        <div className="relative">
          <button
            onClick={() => setShowModuleMenu(!showModuleMenu)}
            className="flex items-center gap-2 text-white hover:bg-white/10 px-3 py-2 rounded-lg transition-colors"
          >
            <span>{activeModule?.name || 'Select Module'}</span>
            <ChevronDown className="w-4 h-4" />
          </button>
          
          {showModuleMenu && (
            <div className="absolute top-full mt-1 left-0 bg-white rounded-lg shadow-lg py-1 min-w-[200px] z-50">
              {modules.map((module) => (
                <button
                  key={module.id}
                  onClick={() => {
                    switchModule(module)
                    setShowModuleMenu(false)
                  }}
                  className={`w-full text-left px-4 py-2 hover:bg-gray-100 ${
                    activeModule?.id === module.id ? 'bg-blue-50 text-cf-accent' : 'text-gray-700'
                  }`}
                >
                  {module.name}
                </button>
              ))}
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
            >
              <Building2 className="w-4 h-4" />
              <span>{tenant?.name}</span>
              <ChevronDown className="w-4 h-4" />
            </button>
            
            {showTenantMenu && (
              <div className="absolute top-full mt-1 right-0 bg-white rounded-lg shadow-lg py-1 min-w-[200px] z-50">
                {tenants.map((t) => (
                  <button
                    key={t.id}
                    onClick={() => {
                      switchTenant(t)
                      setShowTenantMenu(false)
                    }}
                    className={`w-full text-left px-4 py-2 hover:bg-gray-100 ${
                      tenant?.id === t.id ? 'bg-blue-50 text-cf-accent' : 'text-gray-700'
                    }`}
                  >
                    <div className="font-medium">{t.name}</div>
                    <div className="text-xs text-gray-500">{t.role}</div>
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
          >
            <div className="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center text-sm font-medium">
              {user?.first_name?.[0] || user?.name?.[0] || 'U'}
            </div>
            <span className="text-sm">{user?.first_name || user?.name}</span>
            <ChevronDown className="w-4 h-4" />
          </button>
          
          {showUserMenu && (
            <div className="absolute top-full mt-1 right-0 bg-white rounded-lg shadow-lg py-1 min-w-[200px] z-50">
              <div className="px-4 py-2 border-b">
                <div className="font-medium text-gray-900">{user?.first_name} {user?.last_name}</div>
                <div className="text-sm text-gray-500">{user?.email}</div>
                {isMasterAdmin && (
                  <div className="text-xs text-red-600 mt-1">Master Admin</div>
                )}
              </div>
              
              <Link
                to="/profile"
                className="flex items-center gap-2 px-4 py-2 text-gray-700 hover:bg-gray-100"
                onClick={() => setShowUserMenu(false)}
              >
                <User className="w-4 h-4" />
                Profile
              </Link>
              
              <Link
                to="/settings"
                className="flex items-center gap-2 px-4 py-2 text-gray-700 hover:bg-gray-100"
                onClick={() => setShowUserMenu(false)}
              >
                <Settings className="w-4 h-4" />
                Settings
              </Link>
              
              <hr className="my-1" />
              
              <button
                onClick={() => {
                  logout()
                  setShowUserMenu(false)
                }}
                className="flex items-center gap-2 px-4 py-2 text-red-600 hover:bg-red-50 w-full"
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

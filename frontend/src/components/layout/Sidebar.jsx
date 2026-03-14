import { NavLink, useLocation } from 'react-router-dom'
import { useModules } from '@/hooks/useModules'
import { useAuth } from '@/hooks/useAuth'
import { 
  LayoutDashboard, 
  Users, 
  Building2, 
  Boxes, 
  Shield,
  FileText,
  Settings,
  Clock,
  DollarSign,
  BookOpen,
  TrendingUp,
  UserCheck,
  Briefcase,
  Receipt,
  CreditCard,
  PieChart
} from 'lucide-react'

// Module-specific nav items
const moduleNavItems = {
  accounting: [
    { name: 'Overview', path: '/modules/accounting', icon: LayoutDashboard },
    { name: 'Chart of Accounts', path: '/modules/accounting/accounts', icon: BookOpen },
    { name: 'Journal Entries', path: '/modules/accounting/journal', icon: FileText },
    { name: 'General Ledger', path: '/modules/accounting/ledger', icon: TrendingUp },
    { name: 'Accounts Payable', path: '/modules/accounting/ap', icon: CreditCard },
    { name: 'Accounts Receivable', path: '/modules/accounting/ar', icon: Receipt },
    { name: 'Reports', path: '/modules/accounting/reports', icon: PieChart },
    { name: 'Settings', path: '/modules/accounting/settings', icon: Settings },
  ],
  people: [
    { name: 'Overview', path: '/modules/people', icon: LayoutDashboard },
    { name: 'Employee Directory', path: '/modules/people/directory', icon: Users },
    { name: 'Timesheets', path: '/modules/people/timesheets', icon: Clock },
    { name: 'Approvals', path: '/modules/people/approvals', icon: UserCheck },
    { name: 'Hiring Pipeline', path: '/modules/people/hiring', icon: Briefcase },
    { name: 'Reports', path: '/modules/people/reports', icon: PieChart },
    { name: 'Settings', path: '/modules/people/settings', icon: Settings },
  ],
}

// Admin nav items
const adminNavItems = [
  { name: 'Dashboard', path: '/admin', icon: LayoutDashboard },
  { name: 'Tenants', path: '/admin/tenants', icon: Building2 },
  { name: 'Users', path: '/admin/users', icon: Users },
  { name: 'Modules', path: '/admin/modules', icon: Boxes },
  { name: 'Permissions', path: '/admin/permissions', icon: Shield },
  { name: 'Settings', path: '/admin/settings', icon: Settings },
]

export default function Sidebar() {
  const location = useLocation()
  const { activeModule } = useModules()
  const { isMasterAdmin } = useAuth()
  
  const isAdminRoute = location.pathname.startsWith('/admin')
  
  // Determine which nav items to show
  let navItems = []
  let title = ''
  
  if (isAdminRoute) {
    navItems = adminNavItems
    title = 'Administration'
  } else if (activeModule) {
    const moduleKey = activeModule.key || activeModule.name?.toLowerCase()
    navItems = moduleNavItems[moduleKey] || [
      { name: 'Overview', path: `/modules/${moduleKey}`, icon: LayoutDashboard },
    ]
    title = activeModule.name
  }

  return (
    <aside className="w-56 bg-white border-r border-gray-200 flex flex-col" data-testid="sidebar">
      {/* Sidebar Header */}
      <div className="h-12 flex items-center px-4 border-b border-gray-100">
        <h2 className={`font-semibold ${isAdminRoute ? 'text-red-900' : 'text-cf-navy'}`}>
          {title}
        </h2>
      </div>
      
      {/* Nav Items */}
      <nav className="flex-1 p-2 overflow-y-auto">
        {navItems.map((item) => (
          <NavLink
            key={item.path}
            to={item.path}
            end={item.path === '/admin' || item.path === `/modules/${activeModule?.key || activeModule?.name?.toLowerCase()}`}
            className={({ isActive }) => `
              flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-colors mb-0.5
              ${isActive 
                ? isAdminRoute 
                  ? 'bg-red-50 text-red-900 font-medium' 
                  : 'bg-cf-soft text-cf-flux font-medium border-l-2 border-cf-flux ml-[-2px] pl-[14px]'
                : 'text-cf-dark/80 hover:bg-cf-soft hover:text-cf-dark'
              }
            `}
            data-testid={`nav-${item.name.toLowerCase().replace(/\s+/g, '-')}`}
          >
            <item.icon className="w-4 h-4" />
            {item.name}
          </NavLink>
        ))}
      </nav>
      
      {/* Sidebar Footer */}
      <div className="p-4 border-t border-gray-100">
        <div className="flex items-center gap-2">
          <span className="text-xs text-cf-dark/40">CoreFlux</span>
          <span className="text-xs text-cf-flux/60">v1.0</span>
        </div>
      </div>
    </aside>
  )
}

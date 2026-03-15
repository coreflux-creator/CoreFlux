import { useState, useEffect } from 'react'
import { useAuth } from '@/hooks/useAuth'
import { useModules } from '@/hooks/useModules'
import { Link } from 'react-router-dom'
import api from '@/lib/api'
import { ArrowRight, Building2, Users, DollarSign, Clock, TrendingUp, CheckCircle, RefreshCw } from 'lucide-react'

export default function DashboardPage() {
  const { user, tenant, isMasterAdmin } = useAuth()
  const { modules, loading: modulesLoading } = useModules()
  const [stats, setStats] = useState({
    active_users: '—',
    this_month: '—',
    revenue: '—',
    completed: '—',
  })
  const [statsLoading, setStatsLoading] = useState(true)

  useEffect(() => {
    if (tenant?.id) {
      fetchStats()
    }
  }, [tenant?.id])

  const fetchStats = async () => {
    setStatsLoading(true)
    try {
      const response = await api.get('/api/dashboard/stats')
      setStats({
        active_users: response.data.active_users || 0,
        this_month: response.data.this_month || 0,
        revenue: response.data.revenue ? `$${response.data.revenue.toLocaleString()}` : '$0',
        completed: response.data.completed || 0,
      })
    } catch (error) {
      console.error('Failed to fetch stats:', error)
    } finally {
      setStatsLoading(false)
    }
  }

  const moduleIcons = {
    accounting: DollarSign,
    people: Users,
    finance: DollarSign,
  }

  const moduleColors = {
    accounting: 'bg-emerald-50 text-emerald-600',
    people: 'bg-violet-50 text-violet-600',
    finance: 'bg-blue-50 text-cf-flux',
  }

  const statCards = [
    { label: 'Active Users', value: stats.active_users, icon: Users, color: 'text-violet-500' },
    { label: 'This Month', value: stats.this_month, icon: Clock, color: 'text-amber-500' },
    { label: 'Revenue', value: stats.revenue, icon: TrendingUp, color: 'text-emerald-500' },
    { label: 'Completed', value: stats.completed, icon: CheckCircle, color: 'text-cf-flux' },
  ]

  return (
    <div className="space-y-6" data-testid="dashboard-page">
      {/* Welcome Banner */}
      <div className="bg-gradient-to-br from-cf-navy via-cf-navy to-cf-navy-dark rounded-xl p-8 text-white relative overflow-hidden">
        {/* Background pattern */}
        <div className="absolute inset-0 opacity-5">
          <svg viewBox="0 0 400 400" className="w-full h-full">
            <circle cx="350" cy="50" r="100" fill="white" />
            <circle cx="50" cy="350" r="80" fill="white" />
          </svg>
        </div>
        
        <div className="relative z-10">
          <h1 className="text-3xl font-bold mb-2">
            Welcome back, {user?.first_name || user?.name}!
          </h1>
          <p className="text-white/70 text-lg">
            {tenant?.name} • {new Date().toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' })}
          </p>
          <p className="text-white/50 mt-4 max-w-xl">
            Power Your Core. Evolve with Flux.
          </p>
        </div>
      </div>

      {/* Module Cards */}
      <div>
        <h2 className="text-lg font-semibold text-cf-navy mb-4">Your Modules</h2>
        {modulesLoading ? (
          <div className="flex items-center gap-2 text-cf-dark/60">
            <RefreshCw className="w-4 h-4 animate-spin" />
            Loading modules...
          </div>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {modules.map((module) => {
              const moduleKey = module.key || module.name?.toLowerCase()
              const Icon = moduleIcons[moduleKey] || Building2
              const colorClass = moduleColors[moduleKey] || 'bg-cf-soft text-cf-flux'
              
              return (
                <Link
                  key={module.id}
                  to={`/modules/${moduleKey}`}
                  className="bg-white rounded-xl border border-gray-100 p-6 hover:shadow-lg hover:border-cf-flux/30 transition-all group"
                  data-testid={`module-card-${moduleKey}`}
                >
                  <div className="flex items-start justify-between">
                    <div className={`w-12 h-12 rounded-xl ${colorClass} flex items-center justify-center`}>
                      <Icon className="w-6 h-6" />
                    </div>
                    <ArrowRight className="w-5 h-5 text-gray-300 group-hover:text-cf-flux group-hover:translate-x-1 transition-all" />
                  </div>
                  <h3 className="text-lg font-semibold text-cf-navy mt-4">{module.name}</h3>
                  <p className="text-sm text-cf-dark/60 mt-1">
                    {module.description || `Access ${module.name} module`}
                  </p>
                </Link>
              )
            })}

            {modules.length === 0 && (
              <div className="col-span-full bg-cf-soft rounded-xl p-8 text-center border border-dashed border-gray-200">
                <Building2 className="w-12 h-12 text-cf-dark/30 mx-auto mb-4" />
                <h3 className="text-lg font-medium text-cf-navy">No modules available</h3>
                <p className="text-sm text-cf-dark/60 mt-1">
                  Contact your administrator to enable modules for your account.
                </p>
              </div>
            )}
          </div>
        )}
      </div>

      {/* Quick Stats */}
      <div>
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-semibold text-cf-navy">Quick Overview</h2>
          <button 
            onClick={fetchStats}
            disabled={statsLoading}
            className="text-sm text-cf-flux hover:text-cf-flux-hover flex items-center gap-1"
          >
            <RefreshCw className={`w-3 h-3 ${statsLoading ? 'animate-spin' : ''}`} />
            Refresh
          </button>
        </div>
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          {statCards.map((stat, i) => (
            <div key={i} className="bg-white rounded-xl border border-gray-100 p-5 hover:shadow-sm transition-shadow">
              <div className="flex items-center gap-4">
                <div className={`w-11 h-11 rounded-xl bg-cf-soft flex items-center justify-center ${stat.color}`}>
                  <stat.icon className="w-5 h-5" />
                </div>
                <div>
                  <div className="text-2xl font-bold text-cf-navy">
                    {statsLoading ? '—' : stat.value}
                  </div>
                  <div className="text-sm text-cf-dark/60">{stat.label}</div>
                </div>
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* Quick Actions */}
      {isMasterAdmin && (
        <div className="bg-white rounded-xl border border-gray-100 p-6">
          <h2 className="text-lg font-semibold text-cf-navy mb-4">Admin Quick Actions</h2>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <Link 
              to="/admin/tenants" 
              className="block p-4 rounded-lg border hover:border-cf-flux hover:bg-cf-soft transition-colors"
            >
              <Building2 className="w-6 h-6 text-cf-flux mb-2" />
              <h3 className="font-medium text-cf-navy">Manage Tenants</h3>
              <p className="text-sm text-cf-dark/60">Create and configure tenants</p>
            </Link>
            <Link 
              to="/admin/users" 
              className="block p-4 rounded-lg border hover:border-cf-flux hover:bg-cf-soft transition-colors"
            >
              <Users className="w-6 h-6 text-cf-flux mb-2" />
              <h3 className="font-medium text-cf-navy">Manage Users</h3>
              <p className="text-sm text-cf-dark/60">Add users and assign roles</p>
            </Link>
            <Link 
              to="/admin/modules" 
              className="block p-4 rounded-lg border hover:border-cf-flux hover:bg-cf-soft transition-colors"
            >
              <DollarSign className="w-6 h-6 text-cf-flux mb-2" />
              <h3 className="font-medium text-cf-navy">Module Access</h3>
              <p className="text-sm text-cf-dark/60">Enable modules per tenant</p>
            </Link>
          </div>
        </div>
      )}

      {/* Help Section */}
      <div className="bg-white rounded-xl border border-gray-100 p-6">
        <div className="flex items-center justify-between">
          <div>
            <h3 className="text-lg font-semibold text-cf-navy">Need help getting started?</h3>
            <p className="text-sm text-cf-dark/60 mt-1">
              Check out our documentation or contact support for assistance.
            </p>
          </div>
          <a 
            href="https://corefluxapp.com/docs" 
            target="_blank"
            rel="noopener noreferrer"
            className="px-4 py-2 bg-cf-soft text-cf-flux font-medium rounded-lg hover:bg-cf-flux hover:text-white transition-colors"
          >
            View Docs
          </a>
        </div>
      </div>
    </div>
  )
}

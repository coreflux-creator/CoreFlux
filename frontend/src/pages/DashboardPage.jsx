import { useAuth } from '@/hooks/useAuth'
import { useModules } from '@/hooks/useModules'
import { Link } from 'react-router-dom'
import { ArrowRight, Building2, Users, DollarSign, Clock, TrendingUp, CheckCircle } from 'lucide-react'

export default function DashboardPage() {
  const { user, tenant, isMasterAdmin } = useAuth()
  const { modules } = useModules()

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
      </div>

      {/* Quick Stats */}
      <div>
        <h2 className="text-lg font-semibold text-cf-navy mb-4">Quick Overview</h2>
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          {[
            { label: 'Active Users', value: '—', icon: Users, color: 'text-violet-500' },
            { label: 'This Month', value: '—', icon: Clock, color: 'text-amber-500' },
            { label: 'Revenue', value: '—', icon: TrendingUp, color: 'text-emerald-500' },
            { label: 'Completed', value: '—', icon: CheckCircle, color: 'text-cf-flux' },
          ].map((stat, i) => (
            <div key={i} className="bg-white rounded-xl border border-gray-100 p-5 hover:shadow-sm transition-shadow">
              <div className="flex items-center gap-4">
                <div className={`w-11 h-11 rounded-xl bg-cf-soft flex items-center justify-center ${stat.color}`}>
                  <stat.icon className="w-5 h-5" />
                </div>
                <div>
                  <div className="text-2xl font-bold text-cf-navy">{stat.value}</div>
                  <div className="text-sm text-cf-dark/60">{stat.label}</div>
                </div>
              </div>
            </div>
          ))}
        </div>
      </div>

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

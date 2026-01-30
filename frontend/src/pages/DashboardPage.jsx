import { useAuth } from '@/hooks/useAuth'
import { useModules } from '@/hooks/useModules'
import { Link } from 'react-router-dom'
import { ArrowRight, Building2, Users, DollarSign, Clock } from 'lucide-react'

export default function DashboardPage() {
  const { user, tenant, isMasterAdmin } = useAuth()
  const { modules } = useModules()

  const moduleIcons = {
    accounting: DollarSign,
    people: Users,
    finance: DollarSign,
  }

  return (
    <div className="space-y-6">
      {/* Welcome Banner */}
      <div className="bg-gradient-to-r from-cf-navy to-cf-navy-dark rounded-xl p-6 text-white">
        <h1 className="text-2xl font-bold mb-2">
          Welcome back, {user?.first_name || user?.name}!
        </h1>
        <p className="text-white/70">
          {tenant?.name} • {new Date().toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' })}
        </p>
      </div>

      {/* Module Cards */}
      <div>
        <h2 className="text-lg font-semibold text-gray-900 mb-4">Your Modules</h2>
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {modules.map((module) => {
            const moduleKey = module.key || module.name?.toLowerCase()
            const Icon = moduleIcons[moduleKey] || Building2
            
            return (
              <Link
                key={module.id}
                to={`/modules/${moduleKey}`}
                className="bg-white rounded-xl border p-6 hover:shadow-md hover:border-cf-accent transition-all group"
              >
                <div className="flex items-start justify-between">
                  <div className="w-12 h-12 rounded-lg bg-blue-50 text-cf-accent flex items-center justify-center">
                    <Icon className="w-6 h-6" />
                  </div>
                  <ArrowRight className="w-5 h-5 text-gray-400 group-hover:text-cf-accent transition-colors" />
                </div>
                <h3 className="text-lg font-semibold text-gray-900 mt-4">{module.name}</h3>
                <p className="text-sm text-gray-500 mt-1">
                  {module.description || `Access ${module.name} module`}
                </p>
              </Link>
            )
          })}

          {modules.length === 0 && (
            <div className="col-span-full bg-gray-50 rounded-xl p-8 text-center">
              <Building2 className="w-12 h-12 text-gray-400 mx-auto mb-4" />
              <h3 className="text-lg font-medium text-gray-900">No modules available</h3>
              <p className="text-sm text-gray-500 mt-1">
                Contact your administrator to enable modules for your account.
              </p>
            </div>
          )}
        </div>
      </div>

      {/* Quick Stats (placeholder) */}
      <div>
        <h2 className="text-lg font-semibold text-gray-900 mb-4">Quick Overview</h2>
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          {[
            { label: 'Active Users', value: '—', icon: Users },
            { label: 'This Month', value: '—', icon: Clock },
            { label: 'Revenue', value: '—', icon: DollarSign },
            { label: 'Tasks', value: '—', icon: Building2 },
          ].map((stat, i) => (
            <div key={i} className="bg-white rounded-xl border p-4">
              <div className="flex items-center gap-3">
                <div className="w-10 h-10 rounded-lg bg-gray-50 flex items-center justify-center">
                  <stat.icon className="w-5 h-5 text-gray-400" />
                </div>
                <div>
                  <div className="text-2xl font-bold text-gray-900">{stat.value}</div>
                  <div className="text-sm text-gray-500">{stat.label}</div>
                </div>
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}

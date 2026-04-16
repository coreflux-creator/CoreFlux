import { useState, useEffect } from 'react'
import api from '@/lib/api'
import { Building2, Users, Boxes, TrendingUp } from 'lucide-react'

export default function AdminDashboard() {
  const [stats, setStats] = useState({
    tenants: 0,
    users: 0,
    modules: 0,
  })
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    fetchStats()
  }, [])

  const fetchStats = async () => {
    try {
      const [tenantsRes, usersRes, modulesRes] = await Promise.all([
        api.get('/api/admin/tenants'),
        api.get('/api/admin/users'),
        api.get('/api/admin/modules'),
      ])
      setStats({
        tenants: tenantsRes.data?.length || 0,
        users: usersRes.data?.length || 0,
        modules: modulesRes.data?.length || 0,
      })
    } catch (error) {
      console.error('Failed to fetch stats:', error)
    } finally {
      setLoading(false)
    }
  }

  const statCards = [
    { label: 'Total Tenants', value: stats.tenants, icon: Building2, color: 'bg-blue-50 text-blue-600' },
    { label: 'Total Users', value: stats.users, icon: Users, color: 'bg-green-50 text-green-600' },
    { label: 'Modules', value: stats.modules, icon: Boxes, color: 'bg-purple-50 text-purple-600' },
  ]

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Admin Dashboard</h1>
        <p className="text-gray-500">Platform-wide administration and management</p>
      </div>

      {/* Stats Grid */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        {statCards.map((stat, i) => (
          <div key={i} className="bg-white rounded-xl border p-6">
            <div className="flex items-center gap-4">
              <div className={`w-12 h-12 rounded-lg ${stat.color} flex items-center justify-center`}>
                <stat.icon className="w-6 h-6" />
              </div>
              <div>
                <div className="text-3xl font-bold text-gray-900">
                  {loading ? '—' : stat.value}
                </div>
                <div className="text-sm text-gray-500">{stat.label}</div>
              </div>
            </div>
          </div>
        ))}
      </div>

      {/* Quick Actions */}
      <div className="bg-white rounded-xl border p-6">
        <h2 className="text-lg font-semibold text-gray-900 mb-4">Quick Actions</h2>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <a href="/admin/tenants" className="block p-4 rounded-lg border hover:border-cf-accent hover:bg-blue-50 transition-colors">
            <Building2 className="w-6 h-6 text-cf-accent mb-2" />
            <h3 className="font-medium text-gray-900">Manage Tenants</h3>
            <p className="text-sm text-gray-500">Create, edit, and configure tenants</p>
          </a>
          <a href="/admin/users" className="block p-4 rounded-lg border hover:border-cf-accent hover:bg-blue-50 transition-colors">
            <Users className="w-6 h-6 text-cf-accent mb-2" />
            <h3 className="font-medium text-gray-900">Manage Users</h3>
            <p className="text-sm text-gray-500">Add users and assign roles</p>
          </a>
          <a href="/admin/modules" className="block p-4 rounded-lg border hover:border-cf-accent hover:bg-blue-50 transition-colors">
            <Boxes className="w-6 h-6 text-cf-accent mb-2" />
            <h3 className="font-medium text-gray-900">Module Access</h3>
            <p className="text-sm text-gray-500">Enable modules per tenant</p>
          </a>
        </div>
      </div>
    </div>
  )
}

import { useState, useEffect } from 'react'
import api from '@/lib/api'
import { Boxes, Building2, Check, X, Loader2, Search } from 'lucide-react'

export default function ModulesPage() {
  const [modules, setModules] = useState([])
  const [tenants, setTenants] = useState([])
  const [tenantModules, setTenantModules] = useState({}) // { tenantId: { moduleId: true/false } }
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(null)
  const [selectedTenant, setSelectedTenant] = useState(null)

  useEffect(() => {
    fetchData()
  }, [])

  const fetchData = async () => {
    try {
      const [modulesRes, tenantsRes] = await Promise.all([
        api.get('/api/admin/modules'),
        api.get('/api/admin/tenants'),
      ])
      setModules(modulesRes.data || [])
      setTenants(tenantsRes.data || [])
      
      // Fetch module access for each tenant
      const accessMap = {}
      for (const tenant of tenantsRes.data || []) {
        try {
          const accessRes = await api.get(`/api/admin/tenants/${tenant.id}/modules`)
          accessMap[tenant.id] = {}
          for (const mod of accessRes.data || []) {
            accessMap[tenant.id][mod.module_id || mod.id] = mod.is_enabled
          }
        } catch (e) {
          accessMap[tenant.id] = {}
        }
      }
      setTenantModules(accessMap)
    } catch (error) {
      console.error('Failed to fetch data:', error)
    } finally {
      setLoading(false)
    }
  }

  const toggleModule = async (tenantId, moduleId, enabled) => {
    setSaving(`${tenantId}-${moduleId}`)
    try {
      await api.post(`/api/admin/tenants/${tenantId}/modules/${moduleId}`, { is_enabled: enabled })
      setTenantModules(prev => ({
        ...prev,
        [tenantId]: {
          ...prev[tenantId],
          [moduleId]: enabled
        }
      }))
    } catch (error) {
      alert(error.response?.data?.message || 'Failed to update module access')
    } finally {
      setSaving(null)
    }
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center py-12">
        <Loader2 className="w-8 h-8 animate-spin text-cf-accent" />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Module Access</h1>
        <p className="text-gray-500">Enable or disable modules for each tenant</p>
      </div>

      {/* Tenant selector */}
      <div className="bg-white rounded-xl border p-4">
        <label className="block text-sm font-medium text-gray-700 mb-2">Select Tenant</label>
        <select
          value={selectedTenant?.id || ''}
          onChange={(e) => setSelectedTenant(tenants.find(t => t.id === parseInt(e.target.value)))}
          className="w-full max-w-md px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-cf-accent focus:border-cf-accent"
        >
          <option value="">Select a tenant...</option>
          {tenants.map((tenant) => (
            <option key={tenant.id} value={tenant.id}>{tenant.name}</option>
          ))}
        </select>
      </div>

      {/* Module toggles */}
      {selectedTenant && (
        <div className="bg-white rounded-xl border overflow-hidden">
          <div className="px-6 py-4 border-b bg-gray-50">
            <h3 className="font-semibold text-gray-900">
              Modules for {selectedTenant.name}
            </h3>
          </div>
          <div className="divide-y">
            {modules.map((module) => {
              const isEnabled = tenantModules[selectedTenant.id]?.[module.id] || false
              const isSaving = saving === `${selectedTenant.id}-${module.id}`
              
              return (
                <div key={module.id} className="px-6 py-4 flex items-center justify-between">
                  <div className="flex items-center gap-4">
                    <div className="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center">
                      <Boxes className="w-5 h-5 text-cf-accent" />
                    </div>
                    <div>
                      <div className="font-medium text-gray-900">{module.name}</div>
                      <div className="text-sm text-gray-500">{module.description || `${module.name} module`}</div>
                    </div>
                  </div>
                  <button
                    onClick={() => toggleModule(selectedTenant.id, module.id, !isEnabled)}
                    disabled={isSaving}
                    className={`relative inline-flex h-6 w-11 items-center rounded-full transition-colors ${
                      isEnabled ? 'bg-cf-accent' : 'bg-gray-200'
                    }`}
                  >
                    {isSaving ? (
                      <span className="absolute inset-0 flex items-center justify-center">
                        <Loader2 className="w-4 h-4 animate-spin text-white" />
                      </span>
                    ) : (
                      <span
                        className={`inline-block h-4 w-4 transform rounded-full bg-white transition-transform ${
                          isEnabled ? 'translate-x-6' : 'translate-x-1'
                        }`}
                      />
                    )}
                  </button>
                </div>
              )
            })}
            
            {modules.length === 0 && (
              <div className="px-6 py-8 text-center text-gray-500">
                No modules available
              </div>
            )}
          </div>
        </div>
      )}

      {/* All tenants overview */}
      <div className="bg-white rounded-xl border overflow-hidden">
        <div className="px-6 py-4 border-b bg-gray-50">
          <h3 className="font-semibold text-gray-900">All Tenants Overview</h3>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead className="bg-gray-50 border-b">
              <tr>
                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Tenant
                </th>
                {modules.map((module) => (
                  <th key={module.id} className="text-center px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {module.name}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y">
              {tenants.map((tenant) => (
                <tr key={tenant.id} className="hover:bg-gray-50">
                  <td className="px-6 py-4">
                    <div className="flex items-center gap-2">
                      <Building2 className="w-4 h-4 text-gray-400" />
                      <span className="font-medium text-gray-900">{tenant.name}</span>
                    </div>
                  </td>
                  {modules.map((module) => {
                    const isEnabled = tenantModules[tenant.id]?.[module.id] || false
                    return (
                      <td key={module.id} className="px-4 py-4 text-center">
                        {isEnabled ? (
                          <Check className="w-5 h-5 text-green-500 mx-auto" />
                        ) : (
                          <X className="w-5 h-5 text-gray-300 mx-auto" />
                        )}
                      </td>
                    )
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}

import { createContext, useContext, useState, useEffect } from 'react'
import api from '@/lib/api'
import { useAuth } from './useAuth'

const ModulesContext = createContext(null)

export function ModulesProvider({ children }) {
  const { tenant, isAuthenticated } = useAuth()
  const [modules, setModules] = useState([])
  const [activeModule, setActiveModule] = useState(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(null)

  useEffect(() => {
    if (isAuthenticated && tenant) {
      fetchModules()
    } else {
      // Reset modules when not authenticated or no tenant
      setModules([])
      setActiveModule(null)
    }
  }, [isAuthenticated, tenant?.id])

  const fetchModules = async () => {
    if (!tenant) return
    
    setLoading(true)
    setError(null)
    
    try {
      const response = await api.get(`/api/tenants/${tenant.id}/modules`)
      const moduleData = response.data || []
      const enabledModules = Array.isArray(moduleData) 
        ? moduleData.filter(m => m.is_enabled)
        : []
      
      setModules(enabledModules)
      
      // Restore previously selected module or default to first
      const savedModuleKey = localStorage.getItem('activeModuleKey')
      const previousModule = savedModuleKey 
        ? enabledModules.find(m => (m.key || m.name?.toLowerCase()) === savedModuleKey)
        : null
      
      if (previousModule) {
        setActiveModule(previousModule)
      } else if (enabledModules.length > 0 && !activeModule) {
        setActiveModule(enabledModules[0])
      }
    } catch (error) {
      console.error('Failed to fetch modules:', error)
      setError(error.message || 'Failed to load modules')
      // Set default modules for development/demo
      const defaultModules = [
        { id: 1, name: 'Accounting', key: 'accounting', is_enabled: true },
        { id: 2, name: 'People', key: 'people', is_enabled: true },
      ]
      setModules(defaultModules)
      if (!activeModule) {
        setActiveModule(defaultModules[0])
      }
    } finally {
      setLoading(false)
    }
  }

  const switchModule = (module) => {
    setActiveModule(module)
    const moduleKey = module.key || module.name?.toLowerCase()
    localStorage.setItem('activeModuleKey', moduleKey)
  }

  return (
    <ModulesContext.Provider value={{
      modules,
      activeModule,
      switchModule,
      loading,
      error,
      refetch: fetchModules,
    }}>
      {children}
    </ModulesContext.Provider>
  )
}

export function useModules() {
  const context = useContext(ModulesContext)
  if (!context) {
    throw new Error('useModules must be used within a ModulesProvider')
  }
  return context
}

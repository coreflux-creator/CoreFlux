import { createContext, useContext, useState, useEffect } from 'react'
import api from '@/lib/api'
import { useAuth } from './useAuth'

const ModulesContext = createContext(null)

export function ModulesProvider({ children }) {
  const { tenant, isAuthenticated } = useAuth()
  const [modules, setModules] = useState([])
  const [activeModule, setActiveModule] = useState(null)
  const [loading, setLoading] = useState(false)

  useEffect(() => {
    if (isAuthenticated && tenant) {
      fetchModules()
    }
  }, [isAuthenticated, tenant?.id])

  const fetchModules = async () => {
    if (!tenant) return
    
    setLoading(true)
    try {
      const response = await api.get(`/api/tenants/${tenant.id}/modules`)
      const enabledModules = response.data.filter(m => m.is_enabled)
      setModules(enabledModules)
      
      // Set first module as active if none selected
      if (enabledModules.length > 0 && !activeModule) {
        setActiveModule(enabledModules[0])
      }
    } catch (error) {
      console.error('Failed to fetch modules:', error)
    } finally {
      setLoading(false)
    }
  }

  const switchModule = (module) => {
    setActiveModule(module)
  }

  return (
    <ModulesContext.Provider value={{
      modules,
      activeModule,
      switchModule,
      loading,
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

import { createContext, useContext, useState, useEffect } from 'react'
import api from '@/lib/api'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [tenant, setTenant] = useState(null)
  const [tenants, setTenants] = useState([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    checkAuth()
  }, [])

  const checkAuth = async () => {
    try {
      const token = localStorage.getItem('token')
      if (!token) {
        setLoading(false)
        return
      }

      const response = await api.get('/api/auth/me')
      const userData = response.data.user
      
      // Normalize user data - handle both name formats
      const normalizedUser = {
        ...userData,
        first_name: userData.first_name || userData.name?.split(' ')[0] || userData.name,
        last_name: userData.last_name || userData.name?.split(' ').slice(1).join(' ') || '',
      }
      
      setUser(normalizedUser)
      setTenants(response.data.tenants || [])
      
      // Set active tenant from localStorage or first available
      const savedTenantId = localStorage.getItem('tenantId')
      const activeTenant = response.data.tenants?.find(t => t.id === parseInt(savedTenantId)) 
        || response.data.tenants?.[0]
      setTenant(activeTenant)
    } catch (error) {
      console.error('Auth check failed:', error)
      localStorage.removeItem('token')
      localStorage.removeItem('tenantId')
    } finally {
      setLoading(false)
    }
  }

  const login = async (email, password) => {
    const response = await api.post('/api/auth/login', { email, password })
    const { token, user: userData, tenants: userTenants } = response.data
    
    // Normalize user data
    const normalizedUser = {
      ...userData,
      first_name: userData.first_name || userData.name?.split(' ')[0] || userData.name,
      last_name: userData.last_name || userData.name?.split(' ').slice(1).join(' ') || '',
    }
    
    localStorage.setItem('token', token)
    setUser(normalizedUser)
    setTenants(userTenants || [])
    
    // Set first tenant as active
    if (userTenants?.length > 0) {
      setTenant(userTenants[0])
      localStorage.setItem('tenantId', userTenants[0].id)
    }
    
    return response.data
  }

  const logout = async () => {
    try {
      await api.post('/api/auth/logout')
    } catch (error) {
      // Ignore logout errors
    }
    localStorage.removeItem('token')
    localStorage.removeItem('tenantId')
    setUser(null)
    setTenant(null)
    setTenants([])
  }

  const switchTenant = (newTenant) => {
    setTenant(newTenant)
    localStorage.setItem('tenantId', newTenant.id)
  }

  const isMasterAdmin = user?.role === 'master_admin'

  return (
    <AuthContext.Provider value={{
      user,
      tenant,
      tenants,
      loading,
      login,
      logout,
      switchTenant,
      isMasterAdmin,
      isAuthenticated: !!user,
    }}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth() {
  const context = useContext(AuthContext)
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider')
  }
  return context
}

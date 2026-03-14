import axios from 'axios'

// API base URL - points to Laravel backend
// In production: https://corefluxapp.com (routes to /api/* via .htaccess)
const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
  withCredentials: false, // Set to true if using cookie-based auth
})

// Add auth token to requests
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  
  // Add tenant header if available
  const tenantId = localStorage.getItem('tenantId')
  if (tenantId) {
    config.headers['X-Tenant-Id'] = tenantId
  }
  
  return config
})

// Handle 401 responses
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('token')
      localStorage.removeItem('tenantId')
      window.location.href = '/login'
    }
    return Promise.reject(error)
  }
)

export default api

import { useState } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { useAuth } from '@/hooks/useAuth'
import { Eye, EyeOff, Loader2 } from 'lucide-react'

export default function LoginPage() {
  const navigate = useNavigate()
  const { login } = useAuth()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)

  const handleSubmit = async (e) => {
    e.preventDefault()
    setError('')
    setLoading(true)

    try {
      await login(email, password)
      navigate('/dashboard')
    } catch (err) {
      setError(err.response?.data?.message || 'Invalid email or password')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="min-h-screen flex" data-testid="login-page">
      {/* Left: Hero Section with Image */}
      <div className="hidden lg:flex lg:w-1/2 bg-cf-navy relative overflow-hidden">
        {/* Hero Image */}
        <div className="absolute inset-0 flex items-center justify-center p-12">
          <img 
            src="./assets/icons/hero-login.png" 
            alt="CoreFlux Platform"
            className="max-w-full max-h-full object-contain"
          />
        </div>
        
        {/* Overlay gradient */}
        <div className="absolute inset-0 bg-gradient-to-t from-cf-navy via-transparent to-transparent" />
        
        {/* Content at bottom */}
        <div className="absolute bottom-0 left-0 right-0 p-12 z-10">
          <h1 className="text-3xl font-bold text-white mb-3">
            Welcome to CoreFlux
          </h1>
          <p className="text-lg text-white/80 mb-2">
            Power Your Core. Evolve with Flux.
          </p>
          <p className="text-white/60 max-w-md">
            A unified platform for workforce, financial, and operational excellence.
          </p>
        </div>
      </div>

      {/* Right: Login Form */}
      <div className="flex-1 flex items-center justify-center p-8 bg-cf-soft">
        <div className="w-full max-w-md">
          {/* Logo */}
          <div className="text-center mb-8">
            <img 
              src="./logo-web.png" 
              alt="CoreFlux"
              className="h-16 mx-auto mb-4"
            />
            <p className="text-cf-dark/60 text-sm">Power Your Core. Evolve with Flux.</p>
          </div>

          <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-8">
            <h2 className="text-2xl font-semibold text-cf-navy mb-2">Sign in</h2>
            <p className="text-cf-dark/70 mb-6">Enter your credentials to access your account</p>

            {error && (
              <div className="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm" data-testid="login-error">
                {error}
              </div>
            )}

            <form onSubmit={handleSubmit} className="space-y-5" data-testid="login-form">
              <div>
                <label htmlFor="email" className="block text-sm font-medium text-cf-dark mb-1.5">
                  Email address
                </label>
                <input
                  id="email"
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  className="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-cf-flux/25 focus:border-cf-flux transition-colors text-cf-dark"
                  placeholder="you@company.com"
                  required
                  autoFocus
                  data-testid="email-input"
                />
              </div>

              <div>
                <label htmlFor="password" className="block text-sm font-medium text-cf-dark mb-1.5">
                  Password
                </label>
                <div className="relative">
                  <input
                    id="password"
                    type={showPassword ? 'text' : 'password'}
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    className="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-cf-flux/25 focus:border-cf-flux transition-colors pr-10 text-cf-dark"
                    placeholder="Enter your password"
                    required
                    data-testid="password-input"
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword(!showPassword)}
                    className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-cf-dark transition-colors"
                    data-testid="toggle-password"
                  >
                    {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                  </button>
                </div>
              </div>

              <div className="flex items-center justify-between">
                <label className="flex items-center gap-2 cursor-pointer">
                  <input 
                    type="checkbox" 
                    className="rounded border-gray-300 text-cf-flux focus:ring-cf-flux/25" 
                    data-testid="remember-me"
                  />
                  <span className="text-sm text-cf-dark/70">Remember me</span>
                </label>
                <Link 
                  to="/forgot-password" 
                  className="text-sm text-cf-flux hover:text-cf-flux-hover hover:underline transition-colors"
                >
                  Forgot password?
                </Link>
              </div>

              <button
                type="submit"
                disabled={loading}
                className="w-full bg-cf-navy hover:bg-cf-navy-dark text-white py-2.5 rounded-lg font-semibold transition-colors flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
                data-testid="login-submit"
              >
                {loading ? (
                  <>
                    <Loader2 className="w-4 h-4 animate-spin" />
                    Signing in...
                  </>
                ) : (
                  'Sign in'
                )}
              </button>
            </form>
          </div>

          <p className="text-center text-sm text-cf-dark/60 mt-6">
            Don't have an account?{' '}
            <Link to="/signup" className="text-cf-flux hover:text-cf-flux-hover hover:underline font-medium transition-colors">
              Contact your administrator
            </Link>
          </p>
          
          {/* Footer */}
          <div className="text-center mt-8 text-xs text-cf-dark/40">
            <span>© {new Date().getFullYear()} CoreFlux</span>
            <span className="mx-2">|</span>
            <a href="https://corefluxapp.com" className="hover:text-cf-flux transition-colors">
              www.corefluxapp.com
            </a>
          </div>
        </div>
      </div>
    </div>
  )
}

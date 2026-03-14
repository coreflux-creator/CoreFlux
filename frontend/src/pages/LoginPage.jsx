import { useState } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { useAuth } from '@/hooks/useAuth'
import { Eye, EyeOff, Loader2 } from 'lucide-react'
import Logo, { LogoIcon, LogoText } from '@/components/ui/Logo'

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
      {/* Left: Hero Section */}
      <div className="hidden lg:flex lg:w-1/2 bg-cf-navy relative overflow-hidden">
        {/* Background gradient */}
        <div className="absolute inset-0 bg-gradient-to-br from-cf-navy via-cf-navy-dark to-cf-navy" />
        
        {/* Animated swirl pattern background */}
        <div className="absolute inset-0 opacity-5">
          <svg viewBox="0 0 1000 1000" className="w-full h-full" preserveAspectRatio="xMidYMid slice">
            {/* Outer ring */}
            <circle cx="500" cy="500" r="400" fill="none" stroke="white" strokeWidth="1" />
            <circle cx="500" cy="500" r="350" fill="none" stroke="white" strokeWidth="1" />
            <circle cx="500" cy="500" r="300" fill="none" stroke="white" strokeWidth="1" />
            <circle cx="500" cy="500" r="250" fill="none" stroke="white" strokeWidth="1" />
            <circle cx="500" cy="500" r="200" fill="none" stroke="white" strokeWidth="1" />
            {/* Swirl paths */}
            <path 
              d="M500,100 Q800,200 700,500 T500,900 Q200,800 300,500 T500,100" 
              fill="none" 
              stroke="white" 
              strokeWidth="2"
            />
            <path 
              d="M500,150 Q750,250 650,500 T500,850 Q250,750 350,500 T500,150" 
              fill="none" 
              stroke="white" 
              strokeWidth="1.5"
            />
          </svg>
        </div>
        
        {/* Content */}
        <div className="relative z-10 flex flex-col justify-center px-16 max-w-xl">
          {/* Logo */}
          <div className="flex items-center gap-3 mb-10">
            <LogoIcon className="h-14 w-14" variant="white" />
            <LogoText variant="white" size="xl" />
          </div>
          
          <h1 className="text-4xl font-bold text-white mb-4 leading-tight">
            Welcome to CoreFlux
          </h1>
          <p className="text-xl text-white/80 mb-6">
            Power Your Core. Evolve with Flux.
          </p>
          <p className="text-base text-white/60 max-w-md leading-relaxed">
            Enterprise-grade platform for accounting, people management, and more. 
            All your business modules in one centralized, dynamic platform.
          </p>
          
          {/* Feature highlights */}
          <div className="mt-10 space-y-4">
            <div className="flex items-center gap-3 text-white/80">
              <div className="w-2 h-2 rounded-full bg-cf-flux" />
              <span>Centralized financial insights</span>
            </div>
            <div className="flex items-center gap-3 text-white/80">
              <div className="w-2 h-2 rounded-full bg-cf-flux" />
              <span>Complete employee management</span>
            </div>
            <div className="flex items-center gap-3 text-white/80">
              <div className="w-2 h-2 rounded-full bg-cf-flux" />
              <span>Modular, scalable architecture</span>
            </div>
          </div>
        </div>
      </div>

      {/* Right: Login Form */}
      <div className="flex-1 flex items-center justify-center p-8 bg-cf-soft">
        <div className="w-full max-w-md">
          {/* Mobile logo */}
          <div className="lg:hidden text-center mb-8">
            <div className="flex items-center justify-center gap-2">
              <LogoIcon className="h-10 w-10" />
              <LogoText size="lg" />
            </div>
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
            <span>CoreFlux</span>
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

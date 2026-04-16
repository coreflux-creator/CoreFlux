import { useState } from 'react'

/**
 * CoreFlux Logo Component
 * Uses the actual brand logo
 */
export default function Logo({ 
  variant = 'default', // 'default' | 'white' | 'header'
  className = '',
  size = 'md' // 'sm' | 'md' | 'lg' | 'xl'
}) {
  const [imgError, setImgError] = useState(false)
  
  const sizes = {
    sm: 'h-6',
    md: 'h-8',
    lg: 'h-10',
    xl: 'h-14',
  }

  const sizeClass = sizes[size] || sizes.md

  // For header (white background needed), use the standard logo
  // The logo has the swirl + CoreFlux text built in
  const logoSrc = './logo-header.png'

  if (imgError) {
    // Fallback to text if image fails
    return (
      <div className={`flex items-center gap-2 ${className}`}>
        <LogoIcon className={`${sizeClass} w-auto`} variant={variant} />
        <LogoText variant={variant} size={size} />
      </div>
    )
  }

  return (
    <img 
      src={logoSrc}
      alt="CoreFlux"
      className={`${sizeClass} w-auto ${className}`}
      onError={() => setImgError(true)}
    />
  )
}

// Swirl emblem SVG fallback
export function LogoIcon({ className = '', variant = 'default' }) {
  const isWhite = variant === 'white'
  
  return (
    <svg 
      viewBox="0 0 48 48" 
      className={className}
      fill="none"
    >
      <defs>
        <linearGradient id={`swirlGradient-${variant}`} x1="0%" y1="0%" x2="100%" y2="100%">
          <stop offset="0%" stopColor={isWhite ? '#FFFFFF' : '#0A2540'} />
          <stop offset="100%" stopColor={isWhite ? '#93C5FD' : '#007FFF'} />
        </linearGradient>
      </defs>
      <path
        d="M24 4C35.046 4 44 12.954 44 24C44 35.046 35.046 44 24 44C12.954 44 4 35.046 4 24"
        stroke={`url(#swirlGradient-${variant})`}
        strokeWidth="3.5"
        strokeLinecap="round"
      />
      <path
        d="M24 12C30.627 12 36 17.373 36 24C36 30.627 30.627 36 24 36C17.373 36 12 30.627 12 24"
        stroke={`url(#swirlGradient-${variant})`}
        strokeWidth="3"
        strokeLinecap="round"
      />
      <path
        d="M24 20C26.209 20 28 21.791 28 24C28 26.209 26.209 28 24 28C21.791 28 20 26.209 20 24"
        stroke={`url(#swirlGradient-${variant})`}
        strokeWidth="2.5"
        strokeLinecap="round"
      />
    </svg>
  )
}

export function LogoText({ variant = 'default', size = 'md' }) {
  const sizes = {
    sm: 'text-lg',
    md: 'text-xl',
    lg: 'text-2xl',
    xl: 'text-3xl',
  }
  
  const sizeClass = sizes[size] || sizes.md

  return (
    <span className={`font-bold ${sizeClass} leading-none tracking-tight`}>
      <span className={variant === 'white' ? 'text-white' : 'text-cf-navy'}>Core</span>
      <span className={variant === 'white' ? 'text-blue-300' : 'text-cf-flux'}>Flux</span>
    </span>
  )
}

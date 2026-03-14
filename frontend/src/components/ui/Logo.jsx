import { useState } from 'react'

/**
 * CoreFlux Logo Component
 * Displays the brand logo with proper fallback handling
 */
export default function Logo({ 
  variant = 'default', // 'default' | 'white' | 'icon'
  className = '',
  showTagline = false,
  size = 'md' // 'sm' | 'md' | 'lg' | 'xl'
}) {
  const sizes = {
    sm: { height: 'h-6', text: 'text-lg', tagline: 'text-xs' },
    md: { height: 'h-8', text: 'text-xl', tagline: 'text-sm' },
    lg: { height: 'h-10', text: 'text-2xl', tagline: 'text-base' },
    xl: { height: 'h-14', text: 'text-3xl', tagline: 'text-lg' },
  }

  const sizeConfig = sizes[size] || sizes.md

  // If icon variant, just show the swirl
  if (variant === 'icon') {
    return <LogoIcon className={`${sizeConfig.height} w-auto ${className}`} variant={variant} />
  }

  return (
    <div className={`flex items-center gap-2 ${className}`}>
      <LogoIcon className={`${sizeConfig.height} w-auto`} variant={variant} />
      <div className="flex flex-col">
        <span className={`font-bold ${sizeConfig.text} leading-none tracking-tight`}>
          <span className={variant === 'white' ? 'text-white' : 'text-cf-navy'}>Core</span>
          <span className={variant === 'white' ? 'text-blue-300' : 'text-cf-flux'}>Flux</span>
        </span>
        {showTagline && (
          <span className={`${sizeConfig.tagline} ${variant === 'white' ? 'text-white/70' : 'text-cf-dark/70'} mt-0.5`}>
            Power Your Core. Evolve with Flux.
          </span>
        )}
      </div>
    </div>
  )
}

// Swirl emblem SVG - derived from brand spiral
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
      {/* Outer arc */}
      <path
        d="M24 4C35.046 4 44 12.954 44 24C44 35.046 35.046 44 24 44C12.954 44 4 35.046 4 24"
        stroke={`url(#swirlGradient-${variant})`}
        strokeWidth="3.5"
        strokeLinecap="round"
      />
      {/* Middle arc */}
      <path
        d="M24 12C30.627 12 36 17.373 36 24C36 30.627 30.627 36 24 36C17.373 36 12 30.627 12 24"
        stroke={`url(#swirlGradient-${variant})`}
        strokeWidth="3"
        strokeLinecap="round"
      />
      {/* Inner arc */}
      <path
        d="M24 20C26.209 20 28 21.791 28 24C28 26.209 26.209 28 24 28C21.791 28 20 26.209 20 24"
        stroke={`url(#swirlGradient-${variant})`}
        strokeWidth="2.5"
        strokeLinecap="round"
      />
    </svg>
  )
}

export function LogoText({ variant = 'default', size = 'md', showTagline = false }) {
  const sizes = {
    sm: { text: 'text-lg', tagline: 'text-xs' },
    md: { text: 'text-xl', tagline: 'text-sm' },
    lg: { text: 'text-2xl', tagline: 'text-base' },
    xl: { text: 'text-3xl', tagline: 'text-lg' },
  }
  
  const sizeConfig = sizes[size] || sizes.md

  return (
    <div className="flex flex-col">
      <span className={`font-bold ${sizeConfig.text} leading-none tracking-tight`}>
        <span className={variant === 'white' ? 'text-white' : 'text-cf-navy'}>Core</span>
        <span className={variant === 'white' ? 'text-blue-300' : 'text-cf-flux'}>Flux</span>
      </span>
      {showTagline && (
        <span className={`${sizeConfig.tagline} ${variant === 'white' ? 'text-white/70' : 'text-cf-dark/70'} mt-0.5`}>
          Power Your Core. Evolve with Flux.
        </span>
      )}
    </div>
  )
}

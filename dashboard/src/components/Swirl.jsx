import React from 'react';

/**
 * Swirl — CoreFlux brand swirl as an inline SVG.
 *
 * Designed to feel like the marketing-site `swirl-logo.png` but vector and
 * colour-controllable. Use this anywhere we'd otherwise drop the lucide
 * <Sparkles /> for "AI assistance". Two interlocking arcs form a swirl
 * with a small core dot — same visual language as the brand mark.
 *
 * Props mimic lucide-react icons so callers can swap one for the other:
 *   <Swirl size={14} color="currentColor" />
 */
export default function Swirl({ size = 16, color = 'currentColor', strokeWidth = 1.6, className, style, title }) {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke={color}
      strokeWidth={strokeWidth}
      strokeLinecap="round"
      strokeLinejoin="round"
      className={className}
      style={{ display: 'inline-block', verticalAlign: '-2px', ...style }}
      role={title ? 'img' : 'presentation'}
      aria-label={title}
      data-testid="cf-swirl-icon"
    >
      {/* Outer swirling arc — one and a quarter turns from 12 o'clock around */}
      <path d="M12 3
               C 17 3, 21 7, 21 12
               C 21 17, 17 21, 12 21
               C 8 21, 5 18, 5 14
               C 5 11, 7 9, 10 9
               C 12.5 9, 14 10.5, 14 13" />
      {/* Inner counter-arc forming the swirl */}
      <path d="M14 13
               C 14 14.5, 13 15.5, 11.5 15.5" />
      {/* Core dot */}
      <circle cx="12" cy="12" r="1.2" fill={color} stroke="none" />
    </svg>
  );
}

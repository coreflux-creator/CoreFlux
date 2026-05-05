import React, { useMemo, useState } from 'react';

/**
 * Sparkline — pure-SVG sparkline with hover tooltip. Zero dependencies.
 *
 * Props:
 *   data:    [{week: 'YYYY-MM-DD', amount: number}]
 *   format:  fn(number) → string for tooltip
 *   height:  px (default 48)
 *   color:   stroke colour (default cf accent)
 */
export default function Sparkline({ data = [], format = (n) => n.toFixed(0), height = 48, color = 'var(--cf-accent, #2563eb)' }) {
  const [hover, setHover] = useState(null);

  const { points, max, min } = useMemo(() => {
    if (!data.length) return { points: [], max: 0, min: 0 };
    const values = data.map(d => Number(d.amount) || 0);
    const max = Math.max(...values, 0.0001);
    const min = Math.min(...values, 0);
    return {
      points: data.map((d, i) => ({
        x: data.length === 1 ? 0 : (i / (data.length - 1)) * 100,
        y: 100 - ((Number(d.amount) - min) / (max - min || 1)) * 100,
        raw: d,
      })),
      max, min,
    };
  }, [data]);

  if (!data.length) {
    return <div style={{ height, display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#94a3b8', fontSize: 12 }}>No data</div>;
  }

  const path = points.map((p, i) => `${i === 0 ? 'M' : 'L'} ${p.x.toFixed(2)} ${p.y.toFixed(2)}`).join(' ');
  const area = `${path} L 100 100 L 0 100 Z`;

  return (
    <div style={{ position: 'relative', height }} data-testid="sparkline">
      <svg viewBox="0 0 100 100" preserveAspectRatio="none" style={{ width: '100%', height: '100%', overflow: 'visible' }}
           onMouseLeave={() => setHover(null)}>
        <defs>
          <linearGradient id="spark-fade" x1="0" x2="0" y1="0" y2="1">
            <stop offset="0%"   stopColor={color} stopOpacity="0.18" />
            <stop offset="100%" stopColor={color} stopOpacity="0.00" />
          </linearGradient>
        </defs>
        <path d={area} fill="url(#spark-fade)" />
        <path d={path} fill="none" stroke={color} strokeWidth="1.5" vectorEffect="non-scaling-stroke" />
        {points.map((p, i) => (
          <circle key={i} cx={p.x} cy={p.y} r="1.6" fill={color}
                  vectorEffect="non-scaling-stroke"
                  onMouseEnter={() => setHover({ ...p, idx: i })}
                  style={{ opacity: hover?.idx === i ? 1 : 0, cursor: 'pointer' }} />
        ))}
        <rect x="0" y="0" width="100" height="100" fill="transparent"
              onMouseMove={(e) => {
                const rect = e.currentTarget.getBoundingClientRect();
                const ratio = (e.clientX - rect.left) / rect.width;
                const idx = Math.max(0, Math.min(points.length - 1, Math.round(ratio * (points.length - 1))));
                setHover({ ...points[idx], idx });
              }} />
      </svg>
      {hover && (
        <div style={{
          position: 'absolute',
          left: `${Math.min(Math.max(hover.x, 5), 95)}%`,
          top: -4,
          transform: 'translate(-50%, -100%)',
          background: '#0f172a',
          color: '#fff',
          padding: '4px 8px',
          borderRadius: 4,
          fontSize: 11,
          whiteSpace: 'nowrap',
          pointerEvents: 'none',
          boxShadow: '0 2px 6px rgba(0,0,0,0.3)',
        }} data-testid="sparkline-tooltip">
          <div style={{ fontWeight: 600 }}>{format(Number(hover.raw.amount) || 0)}</div>
          <div style={{ opacity: 0.7, fontSize: 10 }}>{hover.raw.week}</div>
        </div>
      )}
    </div>
  );
}

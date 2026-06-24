import React, { useMemo, useState } from 'react';

/**
 * LineChart — pure-SVG line chart with axis labels, gridlines, hover
 * crosshair, and optional second series (e.g. prior-year comparison).
 *
 * Props:
 *   series:  [{ name: 'Revenue', color: '#2563eb', dashed: false,
 *               data: [{ week: 'YYYY-MM-DD', amount: number }] }]
 *   format:  fn(number) → string for tooltip + axis (default integer)
 *   height:  px (default 280)
 *   yLabel:  optional axis label
 */
export default function LineChart({ series = [], format = (n) => Math.round(n).toLocaleString(), height = 280, yLabel = '' }) {
  const [hoverIdx, setHoverIdx] = useState(null);

  const visible = series.filter(s => Array.isArray(s.data) && s.data.length);
  const buckets = visible[0]?.data?.length || 0;

  const { yMax, yMin, xLabels } = useMemo(() => {
    if (!visible.length || !buckets) return { yMax: 1, yMin: 0, xLabels: [] };
    const all = visible.flatMap(s => s.data.map(d => Number(d.amount) || 0));
    const yMax = Math.max(...all, 0.0001);
    const yMin = Math.min(...all, 0);
    const xLabels = visible[0].data.map(d => d.week);
    return { yMax, yMin, xLabels };
  }, [visible, buckets]);

  if (!visible.length || !buckets) {
    return <div style={{ height, display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#94a3b8', fontSize: 13 }}>No data in range</div>;
  }

  // Layout (in viewBox units — viewBox is 0 0 600 200; SVG scales).
  const W = 600, H = 200;
  const padL = 56, padR = 16, padT = 12, padB = 30;
  const innerW = W - padL - padR;
  const innerH = H - padT - padB;

  const xFor = (i) => padL + (buckets === 1 ? innerW / 2 : (i / (buckets - 1)) * innerW);
  const yFor = (v) => padT + (yMax === yMin ? innerH / 2 : (1 - (v - yMin) / (yMax - yMin)) * innerH);

  const yTicks = 4;
  const tickValues = [];
  for (let i = 0; i <= yTicks; i++) {
    tickValues.push(yMin + (yMax - yMin) * (i / yTicks));
  }

  // Sample ~6 x-axis labels evenly to avoid crowding.
  const xTicks = Math.min(6, buckets);
  const xTickIdxs = Array.from({ length: xTicks }, (_, i) => Math.round((i / (xTicks - 1 || 1)) * (buckets - 1)));

  const buildPath = (data) => data
    .map((d, i) => `${i === 0 ? 'M' : 'L'} ${xFor(i).toFixed(2)} ${yFor(Number(d.amount) || 0).toFixed(2)}`)
    .join(' ');

  const onMouseMove = (e) => {
    const rect = e.currentTarget.getBoundingClientRect();
    const ratio = (e.clientX - rect.left) / rect.width;
    const i = Math.max(0, Math.min(buckets - 1, Math.round(ratio * (buckets - 1))));
    setHoverIdx(i);
  };

  return (
    <div style={{ position: 'relative', height }} data-testid="line-chart">
      <svg viewBox={`0 0 ${W} ${H}`} preserveAspectRatio="none" style={{ width: '100%', height: '100%' }}
           onMouseLeave={() => setHoverIdx(null)} onMouseMove={onMouseMove}>
        {/* Gridlines + Y-axis labels */}
        {tickValues.map((v, i) => (
          <g key={i}>
            <line x1={padL} x2={W - padR} y1={yFor(v)} y2={yFor(v)} stroke="#e2e8f0" strokeWidth="1" />
            <text x={padL - 8} y={yFor(v) + 3} fontSize="10" textAnchor="end" fill="#64748b">{format(v)}</text>
          </g>
        ))}
        {yLabel && (
          <text x={6} y={padT + innerH / 2} fontSize="10" fill="#64748b"
                transform={`rotate(-90 6 ${padT + innerH / 2})`} textAnchor="middle">
            {yLabel}
          </text>
        )}

        {/* X-axis labels */}
        {xTickIdxs.map((idx, k) => (
          <text key={k} x={xFor(idx)} y={H - padB + 14} fontSize="10" textAnchor="middle" fill="#64748b">
            {xLabels[idx]?.slice(5)}
          </text>
        ))}

        {/* Series */}
        {visible.map((s, si) => (
          <g key={si}>
            <path d={buildPath(s.data)} fill="none"
                  stroke={s.color || '#2563eb'} strokeWidth="2"
                  strokeDasharray={s.dashed ? '4 4' : undefined}
                  vectorEffect="non-scaling-stroke" />
          </g>
        ))}

        {/* Crosshair + hover dots */}
        {hoverIdx !== null && (
          <g>
            <line x1={xFor(hoverIdx)} x2={xFor(hoverIdx)} y1={padT} y2={H - padB}
                  stroke="#94a3b8" strokeDasharray="2 4" strokeWidth="1" />
            {visible.map((s, si) => {
              const point = s.data[hoverIdx];
              if (!point) return null;
              return (
                <circle key={si} cx={xFor(hoverIdx)} cy={yFor(Number(point.amount) || 0)}
                        r="3.5" fill={s.color || '#2563eb'} stroke="#fff" strokeWidth="1.5" />
              );
            })}
          </g>
        )}
      </svg>

      {/* Legend */}
      {series.length > 1 && (
        <div style={{ display: 'flex', gap: 12, marginTop: 6, fontSize: 11 }}>
          {visible.map((s, i) => (
            <span key={i} style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}>
              <span style={{
                display: 'inline-block', width: 14, height: 2,
                backgroundColor: s.dashed ? 'transparent' : (s.color || '#2563eb'),
                borderTop: s.dashed ? `2px dashed ${s.color || '#2563eb'}` : 'none',
                backgroundImage: s.dashed
                  ? `repeating-linear-gradient(90deg, ${s.color || '#2563eb'} 0 4px, transparent 4px 8px)`
                  : undefined,
              }} />
              {s.name}
            </span>
          ))}
        </div>
      )}

      {/* Hover tooltip */}
      {hoverIdx !== null && (
        <div style={{
          position: 'absolute',
          left: `${(xFor(hoverIdx) / W) * 100}%`,
          top: 0,
          transform: 'translate(-50%, -110%)',
          background: '#0f172a', color: '#fff',
          padding: '6px 10px', borderRadius: 6, fontSize: 11,
          whiteSpace: 'nowrap', pointerEvents: 'none',
          boxShadow: '0 4px 12px rgba(0,0,0,0.3)',
        }} data-testid="line-chart-tooltip">
          <div style={{ fontWeight: 600, marginBottom: 2, fontSize: 10, opacity: 0.7 }}>
            {xLabels[hoverIdx]}
          </div>
          {visible.map((s, si) => {
            const point = s.data[hoverIdx];
            if (!point) return null;
            return (
              <div key={si} style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                <span style={{ display: 'inline-block', width: 8, height: 8, borderRadius: 2,
                              background: s.color || '#2563eb' }} />
                <span style={{ fontWeight: 500 }}>{s.name}:</span>
                <span>{format(Number(point.amount) || 0)}</span>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}

import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { CheckCircle2, Circle, X, Sparkles } from 'lucide-react';
import { api } from '../lib/api';

/**
 * Setup Checklist widget — shown on the dashboard during the first 30 days
 * of a tenant's life. Self-heals: each item is computed from real data
 * (does the tenant have a CoA? a bank? a teammate?) so checking it off is
 * just a side-effect of doing the work.
 *
 * Hides itself when:
 *   - tenant > 30 days old
 *   - all items complete
 *   - admin has dismissed it
 */
export default function SetupChecklistWidget() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [dismissing, setDismissing] = useState(false);

  useEffect(() => {
    (async () => {
      try {
        const r = await api.get('/api/sub_tenant_setup_checklist.php');
        setData(r);
      } catch (e) {
        // silently fail — widget is non-critical
        // eslint-disable-next-line no-console
        console.warn('[SetupChecklist] load failed', e);
      } finally {
        setLoading(false);
      }
    })();
  }, []);

  const dismiss = async () => {
    setDismissing(true);
    try {
      await api.post('/api/sub_tenant_setup_checklist.php?action=dismiss');
      setData(d => ({ ...d, dismissed: true, visible: false }));
    } catch (e) {
      // eslint-disable-next-line no-console
      console.warn('[SetupChecklist] dismiss failed', e);
    } finally {
      setDismissing(false);
    }
  };

  if (loading || !data || !data.visible) return null;

  const pct = data.completion_pct;

  return (
    <section data-testid="setup-checklist-widget" style={{
      background: 'linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%)',
      border: '1px solid #bae6fd',
      borderRadius: 14,
      padding: 20,
      marginBottom: 20,
      position: 'relative',
    }}>
      <button
        onClick={dismiss}
        disabled={dismissing}
        data-testid="setup-checklist-dismiss"
        title="Dismiss this checklist"
        style={{
          position: 'absolute', top: 12, right: 12, background: 'transparent',
          border: 'none', cursor: 'pointer', color: '#475569', padding: 6, borderRadius: 6,
        }}
      ><X size={16} /></button>

      <header style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 6 }}>
        <Sparkles size={20} style={{ color: '#0284c7' }} />
        <h3 style={{ margin: 0, fontSize: 16, color: '#075985' }}>
          Welcome — let's finish setting up {data.tenant_name || 'your tenant'}
        </h3>
      </header>

      <p style={{ color: '#0c4a6e', fontSize: 13, margin: '4px 0 12px' }} data-testid="setup-checklist-progress-text">
        {data.done_count} of {data.total_count} steps complete · {pct}%
      </p>

      <div style={{
        height: 6, background: '#bae6fd', borderRadius: 3, overflow: 'hidden',
        marginBottom: 16,
      }} data-testid="setup-checklist-progress-bar-container">
        <div data-testid="setup-checklist-progress-bar" style={{
          width: `${pct}%`, height: '100%', background: '#0284c7',
          transition: 'width 240ms ease-out',
        }} />
      </div>

      <ul style={{ listStyle: 'none', padding: 0, margin: 0, display: 'grid', gap: 8 }}>
        {data.items.map(item => (
          <li
            key={item.id}
            data-testid={`setup-checklist-item-${item.id}`}
            style={{
              display: 'flex', alignItems: 'center', gap: 12,
              padding: '10px 12px',
              background: item.done ? 'rgba(220, 252, 231, 0.5)' : 'white',
              border: '1px solid ' + (item.done ? '#bbf7d0' : '#e0f2fe'),
              borderRadius: 8,
            }}
          >
            {item.done
              ? <CheckCircle2 size={18} style={{ color: '#059669', flexShrink: 0 }} />
              : <Circle size={18} style={{ color: '#94a3b8', flexShrink: 0 }} />}
            <div style={{ flex: 1, minWidth: 0 }}>
              <div style={{
                fontWeight: 500, fontSize: 13,
                color: item.done ? '#065f46' : '#0f172a',
                textDecoration: item.done ? 'line-through' : 'none',
                opacity: item.done ? 0.7 : 1,
              }}>{item.label}</div>
              <div style={{ fontSize: 11, color: '#64748b' }}>{item.description}</div>
            </div>
            {!item.done && (
              <Link to={item.action_href}
                    data-testid={`setup-checklist-action-${item.id}`}
                    className="btn btn--primary"
                    style={{ fontSize: 12, padding: '6px 12px', flexShrink: 0 }}>
                {item.action_label}
              </Link>
            )}
          </li>
        ))}
      </ul>

      <div style={{ fontSize: 11, color: '#64748b', marginTop: 12, textAlign: 'right' }}>
        {data.age_days <= 30 ? `Day ${data.age_days} of 30` : 'After day 30 this will auto-hide'}
      </div>
    </section>
  );
}

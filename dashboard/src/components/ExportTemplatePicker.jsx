import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Download, ChevronDown, Settings } from 'lucide-react';

/**
 * ExportTemplatePicker — a dropdown that lists every export template visible
 * for a given dataset (tenant + platform), and downloads the export when
 * one is picked.
 *
 *   <ExportTemplatePicker
 *     dataset="ap_payments"
 *     buildHref={(tplId) => `/modules/ap/api/payments.php?action=export_template&template_id=${tplId}&ids=${ids}`}
 *     disabled={ids.length === 0}
 *     label="Export to CSV"
 *   />
 *
 * If the tenant has no templates (and no platform presets), a "Manage
 * templates" link is shown instead.
 */
export default function ExportTemplatePicker({
  dataset,
  buildHref,
  disabled = false,
  label = 'Export CSV',
  testid = 'export-template-picker',
}) {
  const [templates, setTemplates] = useState([]);
  const [loading,   setLoading]   = useState(true);
  const [open,      setOpen]      = useState(false);
  const [err,       setErr]       = useState(null);

  useEffect(() => {
    let alive = true;
    setLoading(true);
    fetch(`/api/v1/reports/export-templates?dataset=${encodeURIComponent(dataset)}`, {
      credentials: 'include',
    })
      .then((r) => r.json().then((d) => r.ok ? d : Promise.reject(d)))
      .then((d) => { if (alive) setTemplates(d.templates || []); })
      .catch((e) => { if (alive) setErr(e.error || 'Failed to load templates'); })
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, [dataset]);

  const downloadVia = (id) => {
    setOpen(false);
    const href = buildHref(id);
    const a = document.createElement('a');
    a.href = href; a.rel = 'noopener'; a.click();
  };

  if (loading) {
    return <button className="btn btn--ghost" disabled data-testid={`${testid}-loading`}>Loading templates…</button>;
  }

  if (templates.length === 0) {
    return (
      <Link to="/admin/export-templates" className="btn btn--ghost" data-testid={`${testid}-empty`}>
        <Settings size={14} /> Set up an export template
      </Link>
    );
  }

  return (
    <div style={{ position: 'relative', display: 'inline-block' }} data-testid={testid}>
      <button
        type="button"
        className="btn btn--primary"
        onClick={() => !disabled && setOpen((v) => !v)}
        disabled={disabled}
        data-testid={`${testid}-trigger`}
        style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}
      >
        <Download size={14} /> {label} <ChevronDown size={14} />
      </button>
      {open && (
        <div
          data-testid={`${testid}-menu`}
          style={{
            position: 'absolute', top: '100%', left: 0, marginTop: 4, zIndex: 10,
            background: 'var(--cf-bg-elev)', border: '1px solid var(--cf-border)',
            borderRadius: 6, boxShadow: '0 4px 12px rgba(0,0,0,0.08)',
            minWidth: 260, padding: 4,
          }}
        >
          {templates.map((t) => (
            <button
              key={t.id}
              type="button"
              onClick={() => downloadVia(t.id)}
              data-testid={`${testid}-item-${t.id}`}
              style={{
                display: 'block', width: '100%', textAlign: 'left',
                padding: '8px 12px', border: 0, background: 'transparent',
                cursor: 'pointer', fontSize: 13, borderRadius: 4,
              }}
              onMouseEnter={(e) => { e.currentTarget.style.background = 'var(--cf-bg-hover, #f3f4f6)'; }}
              onMouseLeave={(e) => { e.currentTarget.style.background = 'transparent'; }}
            >
              <span style={{ fontWeight: 500 }}>{t.name}</span>
              {t.scope === 'platform' && (
                <span style={{ marginLeft: 6, fontSize: 10, padding: '1px 6px', background: '#eef2ff', color: '#4338ca', borderRadius: 999, fontWeight: 600 }}>
                  PLATFORM
                </span>
              )}
            </button>
          ))}
          <div style={{ borderTop: '1px solid var(--cf-border)', marginTop: 4, padding: 4 }}>
            <Link
              to="/admin/export-templates"
              onClick={() => setOpen(false)}
              data-testid={`${testid}-manage`}
              style={{ display: 'block', padding: '6px 12px', fontSize: 12, color: 'var(--cf-text-secondary)', textDecoration: 'none' }}
            >
              <Settings size={12} style={{ display: 'inline', marginRight: 4 }} />
              Manage templates →
            </Link>
          </div>
        </div>
      )}
      {err && <p className="error" style={{ fontSize: 12 }}>{err}</p>}
    </div>
  );
}

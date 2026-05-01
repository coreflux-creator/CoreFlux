import React, { useEffect, useRef, useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';

/**
 * <CompanyTypeahead /> — server-backed lookup against /api/people/companies.
 *
 * Props:
 *   role           Optional role filter ('client', 'vendor', 'msp', etc.)
 *   value          The selected { id, name } or null
 *   onChange       Fired with { id, name } on pick
 *   onCreate       Optional: if present, "Create '<query>'" affordance shows when no exact match
 *   placeholder    Input placeholder text
 *   testId         data-testid prefix (default: "company-typeahead")
 *   autoFocus      bool
 *   allowFreeText  Optional: emits {id:null, name: query} on Enter when no match (back-compat path)
 */
export default function CompanyTypeahead({
  role, value, onChange, onCreate, placeholder = 'Search companies…',
  testId = 'company-typeahead', autoFocus = false, allowFreeText = false,
}) {
  const [query, setQuery]   = useState(value?.name || '');
  const [open, setOpen]     = useState(false);
  const [rows, setRows]     = useState([]);
  const [loading, setLoading] = useState(false);
  const [highlighted, setHighlighted] = useState(0);
  const wrapRef = useRef(null);
  const debounceRef = useRef(null);

  // Sync external value → input
  useEffect(() => { setQuery(value?.name || ''); }, [value?.id, value?.name]);

  // Debounced lookup
  useEffect(() => {
    if (!open) return;
    if (debounceRef.current) clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(async () => {
      setLoading(true);
      try {
        const params = new URLSearchParams();
        if (query.trim()) params.set('q', query.trim());
        if (role) params.set('role', role);
        params.set('per_page', '12');
        const res = await api.get(`/modules/people/api/companies.php?${params.toString()}`);
        setRows(res.rows || []);
        setHighlighted(0);
      } catch { setRows([]); }
      finally { setLoading(false); }
    }, 180);
    return () => clearTimeout(debounceRef.current);
  }, [query, role, open]);

  // Click outside → close
  useEffect(() => {
    const onClick = (e) => { if (wrapRef.current && !wrapRef.current.contains(e.target)) setOpen(false); };
    document.addEventListener('mousedown', onClick);
    return () => document.removeEventListener('mousedown', onClick);
  }, []);

  const exactMatch = rows.find(r => (r.name || '').toLowerCase() === query.trim().toLowerCase());

  const pick = (row) => {
    onChange({ id: row.id, name: row.name, roles: row.roles || [] });
    setQuery(row.name);
    setOpen(false);
  };

  const handleCreate = async () => {
    if (!query.trim()) return;
    if (onCreate) {
      const created = await onCreate(query.trim());
      if (created?.id) pick(created);
      return;
    }
    // default behaviour: upsert via API
    try {
      const res = await api.post('/modules/people/api/companies.php?action=upsert', {
        name: query.trim(), roles: role ? [role] : [],
      });
      pick(res.company || { id: res.id, name: query.trim() });
    } catch (e) { alert(`Could not create company: ${e.message}`); }
  };

  const onKeyDown = (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); setHighlighted(h => Math.min(rows.length - 1, h + 1)); }
    if (e.key === 'ArrowUp')   { e.preventDefault(); setHighlighted(h => Math.max(0, h - 1)); }
    if (e.key === 'Enter') {
      e.preventDefault();
      if (rows[highlighted]) pick(rows[highlighted]);
      else if (query.trim() && !exactMatch) {
        if (allowFreeText) onChange({ id: null, name: query.trim() });
        else handleCreate();
      }
    }
    if (e.key === 'Escape') setOpen(false);
  };

  return (
    <div ref={wrapRef} style={{ position: 'relative' }} data-testid={`${testId}-wrap`}>
      <input
        className="input"
        placeholder={placeholder}
        value={query}
        onChange={(e) => { setQuery(e.target.value); setOpen(true); }}
        onFocus={() => setOpen(true)}
        onKeyDown={onKeyDown}
        autoFocus={autoFocus}
        data-testid={`${testId}-input`}
        style={{ width: '100%' }}
      />
      {value?.id && (
        <span style={{ position: 'absolute', right: 8, top: 8, fontSize: 11, color: '#6b7280' }} data-testid={`${testId}-chip`}>
          ✓ #{value.id}
          <button type="button" onClick={() => { onChange(null); setQuery(''); }} style={{ marginLeft: 6, border: 0, background: 'transparent', cursor: 'pointer', color: '#6b7280' }} data-testid={`${testId}-clear`}>×</button>
        </span>
      )}
      {open && (
        <div style={{
          position: 'absolute', top: '100%', left: 0, right: 0, zIndex: 50,
          background: 'var(--cf-surface, #fff)', border: '1px solid var(--cf-border, #e5e7eb)',
          borderRadius: 8, marginTop: 4, maxHeight: 280, overflowY: 'auto', boxShadow: '0 8px 24px rgba(0,0,0,0.08)',
        }} data-testid={`${testId}-dropdown`}>
          {loading && <div style={{ padding: 10, fontSize: 13, color: '#6b7280' }}>Searching…</div>}
          {!loading && rows.length === 0 && (
            <div style={{ padding: 10, fontSize: 13, color: '#6b7280' }} data-testid={`${testId}-empty`}>
              {query.trim() ? `No matches for "${query.trim()}".` : 'Start typing…'}
            </div>
          )}
          {rows.map((r, i) => (
            <button
              key={r.id}
              type="button"
              onClick={() => pick(r)}
              onMouseEnter={() => setHighlighted(i)}
              data-testid={`${testId}-row-${r.id}`}
              style={{
                display: 'block', width: '100%', textAlign: 'left',
                padding: '8px 12px', border: 0,
                background: i === highlighted ? 'var(--cf-surface-alt, #f3f4f6)' : 'transparent',
                cursor: 'pointer', fontSize: 14,
              }}
            >
              <div style={{ fontWeight: 500 }}>{r.name}</div>
              <div style={{ fontSize: 11, color: '#6b7280' }}>
                {(r.roles || []).join(' · ') || '—'}{r.city ? ` · ${r.city}, ${r.state || r.country || ''}` : ''}
              </div>
            </button>
          ))}
          {query.trim() && !exactMatch && !loading && (
            <button
              type="button"
              onClick={handleCreate}
              data-testid={`${testId}-create`}
              style={{
                display: 'block', width: '100%', textAlign: 'left',
                padding: '8px 12px', border: 0, borderTop: '1px solid var(--cf-border, #e5e7eb)',
                background: 'var(--cf-surface-alt, #f9fafb)', cursor: 'pointer',
                fontSize: 13, color: 'var(--cf-text-secondary, #6b7280)',
              }}
            >
              + Create "{query.trim()}"{role ? ` as ${role}` : ''}
            </button>
          )}
        </div>
      )}
    </div>
  );
}

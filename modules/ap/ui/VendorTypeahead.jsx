import React, { useEffect, useRef, useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';

/**
 * <VendorTypeahead /> — unified AP-vendor picker (2026-02).
 *
 * Drops in alongside CompanyTypeahead but searches the UNIFIED AP
 * vendors index (/modules/ap/api/vendors.php?q=…) so it finds:
 *   - 1099 individuals (stored only in `people`)
 *   - C2C corps (stored in `companies` + linked via placement_corp_details)
 *   - W-9 businesses & utility-style vendors
 *   - Anything previously created via VendorQuickCreate or AP bill flow
 *
 * Replaces the BillCreate use of CompanyTypeahead, which queried only
 * `companies` and missed 1099 individuals — the exact bug captured in
 * the Feb 2026 "vendors aren't available for AP" screenshot.
 *
 * Props:
 *   value         { id, name } | null  — selected vendor
 *   onChange      ({id, name, vendor_type, tax_id_last4, ...}) | null
 *   onCreate      Optional: opens VendorQuickCreate when no match
 *   placeholder
 *   testId        data-testid prefix (default 'vendor-typeahead')
 *   autoFocus
 *   allowFreeText emit {id:null, name: query} on Enter when no match
 */
export default function VendorTypeahead({
  value, onChange, onCreate, placeholder = 'Search vendors…',
  testId = 'vendor-typeahead', autoFocus = false, allowFreeText = false,
}) {
  const [query, setQuery]       = useState(value?.name || '');
  const [open, setOpen]         = useState(false);
  const [rows, setRows]         = useState([]);
  const [loading, setLoading]   = useState(false);
  const [highlighted, setHighlighted] = useState(0);
  const wrapRef = useRef(null);
  const debounceRef = useRef(null);

  useEffect(() => { setQuery(value?.name || ''); }, [value?.id, value?.name]);

  useEffect(() => {
    if (!open) return;
    if (debounceRef.current) clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(async () => {
      setLoading(true);
      try {
        const params = new URLSearchParams();
        if (query.trim()) params.set('q', query.trim());
        params.set('limit', '12');
        const res = await api.get(`/modules/ap/api/vendors.php?${params.toString()}`);
        setRows(res.rows || []);
        setHighlighted(0);
      } catch { setRows([]); }
      finally { setLoading(false); }
    }, 180);
    return () => clearTimeout(debounceRef.current);
  }, [query, open]);

  useEffect(() => {
    const onClick = (e) => { if (wrapRef.current && !wrapRef.current.contains(e.target)) setOpen(false); };
    document.addEventListener('mousedown', onClick);
    return () => document.removeEventListener('mousedown', onClick);
  }, []);

  const exactMatch = rows.find(r => (r.vendor_name || '').toLowerCase() === query.trim().toLowerCase());

  const pick = (row) => {
    onChange({
      id: row.id,
      name: row.vendor_name,
      vendor_type: row.vendor_type,
      tax_id_last4: row.tax_id_last4 || null,
      company_id: row.company_id || null,
    });
    setQuery(row.vendor_name);
    setOpen(false);
  };

  const handleCreate = async () => {
    if (!query.trim()) return;
    if (onCreate) {
      const created = await onCreate(query.trim());
      if (created?.id || created?.vendor_name) {
        pick({
          id: created.id,
          vendor_name: created.vendor_name || created.name || query.trim(),
          vendor_type: created.vendor_type,
          tax_id_last4: created.tax_id_last4,
        });
      }
      return;
    }
    // No onCreate provided → emit a free-text selection. Caller (e.g.
    // BillCreate) can then mount a VendorQuickCreate side modal.
    if (allowFreeText) onChange({ id: null, name: query.trim() });
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
          ✓ V-{value.id}
          <button type="button" onClick={() => { onChange(null); setQuery(''); }}
                  style={{ marginLeft: 6, border: 0, background: 'transparent', cursor: 'pointer', color: '#6b7280' }}
                  data-testid={`${testId}-clear`}>×</button>
        </span>
      )}
      {open && (
        <div style={{
          position: 'absolute', top: '100%', left: 0, right: 0, zIndex: 50,
          background: 'var(--cf-surface, #fff)', border: '1px solid var(--cf-border, #e5e7eb)',
          borderRadius: 8, marginTop: 4, maxHeight: 320, overflowY: 'auto', boxShadow: '0 8px 24px rgba(0,0,0,0.08)',
        }} data-testid={`${testId}-dropdown`}>
          {loading && <div style={{ padding: 10, fontSize: 13, color: '#6b7280' }}>Searching vendors…</div>}
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
              <div style={{ fontWeight: 500 }}>{r.vendor_name}</div>
              <div style={{ fontSize: 11, color: '#6b7280' }}>
                {(r.vendor_type || '—').toUpperCase()}
                {r.tax_id_last4 ? ` · TIN ••${r.tax_id_last4}` : ''}
                {r.last_bill_at ? ` · last bill ${r.last_bill_at.slice(0, 10)}` : ''}
                {r.bill_count ? ` · ${r.bill_count} bills` : ''}
              </div>
            </button>
          ))}
          {query.trim() && !exactMatch && !loading && (onCreate || allowFreeText) && (
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
              + Create "{query.trim()}" as vendor
            </button>
          )}
        </div>
      )}
    </div>
  );
}

import React, { useEffect, useRef, useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';

function personLabel(row) {
  const name = `${row?.first_name || ''} ${row?.last_name || ''}`.trim() || 'Unnamed person';
  return `P-${row?.id} | ${name}`;
}

export default function PersonPicker({
  value,
  onChange,
  placeholder = 'All people',
  testId = 'person-picker',
}) {
  const [selected, setSelected] = useState(null);
  const [query, setQuery] = useState('');
  const [rows, setRows] = useState([]);
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const wrapRef = useRef(null);

  useEffect(() => {
    const id = Number(value || 0);
    if (!id) {
      setSelected(null);
      setQuery('');
      return;
    }
    if (Number(selected?.id || 0) === id) return;

    let cancelled = false;
    api.get(`/modules/people/api/people.php?id=${id}`)
      .then((res) => {
        if (cancelled) return;
        const row = res?.person || null;
        setSelected(row);
        setQuery(row ? personLabel(row) : `P-${id}`);
      })
      .catch(() => {
        if (!cancelled) setQuery(`P-${id}`);
      });
    return () => { cancelled = true; };
  }, [value, selected?.id]);

  useEffect(() => {
    if (!open) return undefined;
    const timer = setTimeout(async () => {
      setLoading(true);
      try {
        const params = new URLSearchParams({ per_page: '20', sort: 'last_name', dir: 'asc' });
        if (query.trim() && !selected) params.set('q', query.trim());
        const res = await api.get(`/modules/people/api/people.php?${params.toString()}`);
        setRows(res?.rows || []);
      } catch {
        setRows([]);
      } finally {
        setLoading(false);
      }
    }, 180);
    return () => clearTimeout(timer);
  }, [open, query, selected]);

  useEffect(() => {
    const close = (event) => {
      if (wrapRef.current && !wrapRef.current.contains(event.target)) setOpen(false);
    };
    document.addEventListener('mousedown', close);
    return () => document.removeEventListener('mousedown', close);
  }, []);

  const choose = (row) => {
    setSelected(row);
    setQuery(personLabel(row));
    setOpen(false);
    onChange?.(row);
  };

  const clear = () => {
    setSelected(null);
    setQuery('');
    setRows([]);
    onChange?.(null);
  };

  return (
    <div ref={wrapRef} style={{ position: 'relative', minWidth: 230 }} data-testid={`${testId}-wrap`}>
      <input
        className="input"
        type="search"
        value={query}
        placeholder={placeholder}
        onFocus={() => setOpen(true)}
        onChange={(event) => {
          if (selected) {
            setSelected(null);
            onChange?.(null);
          }
          setQuery(event.target.value);
          setOpen(true);
        }}
        onKeyDown={(event) => {
          if (event.key === 'Escape') setOpen(false);
          if (event.key === 'Enter' && rows[0]) {
            event.preventDefault();
            choose(rows[0]);
          }
        }}
        data-testid={testId}
        aria-label="Search people"
        autoComplete="off"
        style={{ width: '100%', paddingRight: value ? 58 : undefined }}
      />
      {value ? (
        <button
          type="button"
          className="btn btn--ghost"
          onClick={clear}
          data-testid={`${testId}-clear`}
          title="Clear person filter"
          style={{ position: 'absolute', right: 4, top: 4, padding: '3px 7px', fontSize: 11 }}
        >Clear</button>
      ) : null}
      {open ? (
        <div
          data-testid={`${testId}-dropdown`}
          style={{
            position: 'absolute', top: '100%', left: 0, right: 0, zIndex: 80,
            marginTop: 4, maxHeight: 300, overflowY: 'auto',
            background: 'var(--cf-surface, #fff)', border: '1px solid var(--cf-border, #dbe3ef)',
            borderRadius: 6, boxShadow: '0 10px 26px rgba(15, 39, 71, 0.12)',
          }}
        >
          {loading ? <div style={{ padding: 10, fontSize: 12 }}>Searching...</div> : null}
          {!loading && rows.length === 0 ? (
            <div style={{ padding: 10, fontSize: 12, color: 'var(--cf-text-secondary, #64748b)' }}>
              No people found.
            </div>
          ) : null}
          {rows.map((row) => (
            <button
              key={row.id}
              type="button"
              onClick={() => choose(row)}
              data-testid={`${testId}-option-${row.id}`}
              style={{
                display: 'block', width: '100%', padding: '9px 11px', textAlign: 'left',
                border: 0, borderBottom: '1px solid var(--cf-border, #eef2f7)',
                background: 'transparent', cursor: 'pointer', color: 'inherit',
              }}
            >
              <strong style={{ display: 'block', fontSize: 13 }}>{personLabel(row)}</strong>
              <span style={{ display: 'block', marginTop: 2, fontSize: 11, color: 'var(--cf-text-secondary, #64748b)' }}>
                {[row.email_primary, row.classification, row.status].filter(Boolean).join(' | ')}
              </span>
            </button>
          ))}
        </div>
      ) : null}
    </div>
  );
}

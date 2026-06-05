/**
 * useTableList — generic client-side sort + filter for list pages.
 *
 * Why client-side?
 *  - The existing backends already do the heavy lifting (status,
 *    period, type). Sort + free-text search are pure UI affordances
 *    that don't need a round-trip and let us share one helper.
 *  - Combined with useApiCached, hovering, sorting, and free-text
 *    filtering all stay snappy with zero extra HTTP traffic.
 *
 * Public API:
 *   const {
 *     items,           // filtered + sorted rows ready to render
 *     sortKey, sortDir,
 *     toggleSort,      // (key) => void
 *     search, setSearch,
 *     headerProps,     // (key) => onClick + aria-sort + data-testid
 *     ariaSort,        // (key) => 'ascending'|'descending'|'none'
 *   } = useTableList(rows, {
 *     defaultSort: { key: 'id', dir: 'asc' },
 *     searchKeys:  ['title', 'vendor_name', 'invoice_number'],
 *     dateKeys:    ['issue_date', 'due_date'],   // sorted as dates
 *     numericKeys: ['total', 'amount_due'],      // sorted numerically
 *   });
 */
import { useMemo, useState, useCallback } from 'react';

const COLLATOR = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' });

export function useTableList(rows, options = {}) {
  const {
    defaultSort = { key: null, dir: 'asc' },
    searchKeys  = [],
    dateKeys    = [],
    numericKeys = [],
  } = options;

  const [sortKey, setSortKey] = useState(defaultSort.key);
  const [sortDir, setSortDir] = useState(defaultSort.dir === 'desc' ? 'desc' : 'asc');
  const [search,  setSearch]  = useState('');

  const toggleSort = useCallback((key) => {
    if (!key) return;
    setSortKey(prevKey => {
      if (prevKey === key) {
        // Same column — flip direction.
        setSortDir(d => (d === 'asc' ? 'desc' : 'asc'));
        return key;
      }
      // New column — start ascending.
      setSortDir('asc');
      return key;
    });
  }, []);

  const dateSet    = useMemo(() => new Set(dateKeys),    [dateKeys.join('|')]);    // eslint-disable-line react-hooks/exhaustive-deps
  const numericSet = useMemo(() => new Set(numericKeys), [numericKeys.join('|')]); // eslint-disable-line react-hooks/exhaustive-deps

  const items = useMemo(() => {
    if (!Array.isArray(rows)) return [];
    const q = search.trim().toLowerCase();

    // 1. Filter (free-text across searchKeys, OR across every searchKey).
    let out = rows;
    if (q && searchKeys.length > 0) {
      out = rows.filter(r => searchKeys.some(k => {
        const v = r?.[k];
        return v != null && String(v).toLowerCase().includes(q);
      }));
    }

    // 2. Sort.
    if (sortKey) {
      const dir = sortDir === 'desc' ? -1 : 1;
      const isDate    = dateSet.has(sortKey);
      const isNumeric = numericSet.has(sortKey);
      out = [...out].sort((a, b) => {
        const av = a?.[sortKey];
        const bv = b?.[sortKey];
        // Nulls/undefined sort last regardless of direction.
        if (av == null && bv == null) return 0;
        if (av == null) return 1;
        if (bv == null) return -1;
        if (isDate) {
          // ISO strings sort lexicographically as dates already; only
          // need Date.parse when the format is exotic.
          const ta = typeof av === 'string' ? av.slice(0, 10) : av;
          const tb = typeof bv === 'string' ? bv.slice(0, 10) : bv;
          if (ta < tb) return -1 * dir;
          if (ta > tb) return  1 * dir;
          return 0;
        }
        if (isNumeric) {
          const na = Number(av);
          const nb = Number(bv);
          if (na < nb) return -1 * dir;
          if (na > nb) return  1 * dir;
          return 0;
        }
        return COLLATOR.compare(String(av), String(bv)) * dir;
      });
    }
    return out;
  }, [rows, search, sortKey, sortDir, searchKeys.join('|'), dateSet, numericSet]); // eslint-disable-line react-hooks/exhaustive-deps

  const ariaSort = useCallback((key) => {
    if (sortKey !== key) return 'none';
    return sortDir === 'asc' ? 'ascending' : 'descending';
  }, [sortKey, sortDir]);

  const headerProps = useCallback((key, testidPrefix = 'sort-by') => ({
    onClick: () => toggleSort(key),
    role: 'button',
    tabIndex: 0,
    onKeyDown: (e) => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggleSort(key); }
    },
    'aria-sort': ariaSort(key),
    'data-testid': `${testidPrefix}-${key}`,
    style: { cursor: 'pointer', userSelect: 'none' },
  }), [toggleSort, ariaSort]);

  return { items, sortKey, sortDir, toggleSort, search, setSearch, headerProps, ariaSort };
}

/**
 * Tiny presentational helper — renders the up/down arrow next to a
 * sortable column title without pulling in lucide-react for one
 * caret. Keeps bundles thin.
 */
export function SortIndicator({ active, dir }) {
  if (!active) {
    return <span aria-hidden="true" style={{ opacity: 0.3, marginLeft: 4, fontSize: 10 }}>↕</span>;
  }
  return (
    <span aria-hidden="true" style={{ marginLeft: 4, fontSize: 10 }}>
      {dir === 'asc' ? '▲' : '▼'}
    </span>
  );
}

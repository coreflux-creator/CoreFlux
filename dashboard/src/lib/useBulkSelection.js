import { useState, useCallback, useMemo } from 'react';

/**
 * Generic bulk-selection hook for tabular lists.
 *
 *   const sel = useBulkSelection(rows.map(r => r.id));
 *   <input type="checkbox" checked={sel.allSelected} indeterminate={sel.someSelected} onChange={sel.toggleAll} />
 *   {rows.map(r => (
 *     <input type="checkbox" checked={sel.has(r.id)} onChange={() => sel.toggle(r.id)} />
 *   ))}
 *
 *   sel.ids       → number[]            currently selected ids (in input order)
 *   sel.size      → number              count
 *   sel.has(id)   → bool
 *   sel.toggle    → (id) => void
 *   sel.toggleAll → () => void          select-all / clear-all
 *   sel.clear     → () => void
 *   sel.selectMany→ (ids[]) => void
 */
export function useBulkSelection(allIds) {
  const [set, setSet] = useState(() => new Set());

  const has = useCallback((id) => set.has(id), [set]);
  const toggle = useCallback((id) => {
    setSet(prev => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  }, []);
  const clear = useCallback(() => setSet(new Set()), []);
  const selectMany = useCallback((ids) => setSet(new Set(ids)), []);

  const allIdsArr = useMemo(() => Array.isArray(allIds) ? allIds : [], [allIds]);
  const allSelected  = allIdsArr.length > 0 && allIdsArr.every(id => set.has(id));
  const someSelected = !allSelected && allIdsArr.some(id => set.has(id));
  const toggleAll = useCallback(() => {
    setSet(prev => {
      const next = new Set(prev);
      const everyOnPage = allIdsArr.length > 0 && allIdsArr.every(id => next.has(id));
      if (everyOnPage) allIdsArr.forEach(id => next.delete(id));
      else allIdsArr.forEach(id => next.add(id));
      return next;
    });
  }, [allIdsArr]);

  const ids = useMemo(() => Array.from(set), [set]);

  return { ids, size: set.size, has, toggle, toggleAll, allSelected, someSelected, clear, selectMany };
}

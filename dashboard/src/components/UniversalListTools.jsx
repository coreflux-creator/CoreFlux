import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import {
  CheckSquare,
  Columns3,
  Download,
  Filter,
  RotateCcw,
  Search,
  X,
} from 'lucide-react';
import {
  cleanCellText,
  compareCellText,
  makeColumnId,
  tableExportFileName,
  tableRowsToCsv,
} from '../lib/listTableUtils';

const TOOL_HOST_CLASS = 'cf-list-tools-host';
const INTERACTIVE_SELECTOR = 'a, button, input, select, textarea, label, summary, [role="button"], [contenteditable="true"]';
const EXCLUDED_CONTAINER_SELECTOR = 'dialog, [role="dialog"], .modal, .drawer, details, form, [data-testid*="modal"]';

function headerCells(table) {
  return Array.from(table.tHead?.rows?.[0]?.cells || []);
}

function bodyRows(table) {
  const columnCount = headerCells(table).length;
  return Array.from(table.tBodies?.[0]?.rows || []).filter(row => {
    if (!row.cells.length || row.matches('[data-list-tools-row="off"]')) return false;
    if (row.querySelector('.empty') || row.classList.contains('empty')) return false;
    const spanningCell = Array.from(row.cells).find(cell => cell.colSpan > 1);
    if (spanningCell && spanningCell.colSpan >= columnCount) return false;
    return true;
  });
}

function tableIdentity(table, routeKey) {
  const testId = table.getAttribute('data-testid');
  const labels = headerCells(table).map(cell => cleanCellText(cell.textContent || cell.innerText)).join('|');
  return `${routeKey}:${testId || labels || 'table'}`;
}

function isEligibleTable(table) {
  if (!(table instanceof HTMLTableElement)) return false;
  if (table.dataset.listTools === 'off' || table.closest('[data-list-tools="off"]')) return false;
  if (table.dataset.listTools !== 'on' && table.closest(EXCLUDED_CONTAINER_SELECTOR)) return false;
  if (!table.closest('main')) return false;
  if (!table.getAttribute('data-testid') && table.dataset.listTools !== 'on') return false;

  const headers = headerCells(table);
  if (headers.length < 2) return false;
  if (table.dataset.listTools !== 'on'
      && (table.tHead?.rows?.length !== 1 || headers.some(cell => cell.colSpan > 1))) {
    return false;
  }

  // Editable grids need domain-aware validation and save behavior, so the
  // universal read-only list controls deliberately leave them alone.
  if (table.tBodies?.[0]?.querySelector('input:not([type="checkbox"]):not([type="hidden"]), select, textarea, [contenteditable="true"]')) {
    return false;
  }
  return true;
}

function readColumns(table) {
  return headerCells(table).map((cell, index) => {
    const rawLabel = cleanCellText(cell.textContent || cell.innerText)
      .replace(/[\u2195\u25B2\u25BC]+$/g, '')
      .trim();
    const label = rawLabel || (index === headerCells(table).length - 1 ? 'Actions' : `Column ${index + 1}`);
    return { id: makeColumnId(label, index), index, label };
  });
}

function rowKey(row, fallbackIndex) {
  const explicit = row.getAttribute('data-testid') || row.dataset.rowId;
  if (explicit) return explicit;
  if (!row.dataset.cfListKey) {
    row.dataset.cfListKey = `${fallbackIndex}:${cleanCellText(row.cells?.[0]?.innerText || row.textContent)}`;
  }
  return row.dataset.cfListKey;
}

function useOutsideDismiss(ref, onDismiss, enabled) {
  useEffect(() => {
    if (!enabled) return undefined;
    const onPointerDown = event => {
      if (!ref.current?.contains(event.target)) onDismiss();
    };
    const onKeyDown = event => {
      if (event.key === 'Escape') onDismiss();
    };
    document.addEventListener('pointerdown', onPointerDown);
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('pointerdown', onPointerDown);
      document.removeEventListener('keydown', onKeyDown);
    };
  }, [enabled, onDismiss, ref]);
}

function TableTools({ table, routeKey }) {
  const initialColumns = useMemo(() => readColumns(table), [table]);
  const storageKey = useMemo(() => `cf:list-columns:${tableIdentity(table, routeKey)}`, [table, routeKey]);
  const [columns, setColumns] = useState(initialColumns);
  const [query, setQuery] = useState('');
  const [filters, setFilters] = useState({});
  const [hiddenColumns, setHiddenColumns] = useState(() => {
    try {
      const saved = JSON.parse(window.localStorage.getItem(storageKey) || '[]');
      return new Set(Array.isArray(saved) ? saved : []);
    } catch {
      return new Set();
    }
  });
  const [sort, setSort] = useState({ index: null, dir: 'asc' });
  const [selectedKeys, setSelectedKeys] = useState(() => new Set());
  const [menu, setMenu] = useState(null);
  const [revision, setRevision] = useState(0);
  const [stats, setStats] = useState({ total: 0, shown: 0 });
  const [exportMessage, setExportMessage] = useState('');
  const menuRef = useRef(null);
  const selectAllRef = useRef(null);
  const originalOrderRef = useRef(new WeakMap());
  const nextOrderRef = useRef(0);
  const signatureRef = useRef('');
  const ownsDomSortRef = useRef(false);

  // A checkbox elsewhere in a row may be an ordinary field toggle (for
  // example AP vendor PWP), not bulk selection. Only defer to a domain table
  // when it owns a select-all control or first-column row checkboxes.
  const selectionSupported = !table.querySelector(
    'thead input[type="checkbox"], tbody td:first-child input[type="checkbox"]',
  );

  const dismissMenu = useCallback(() => setMenu(null), []);
  useOutsideDismiss(menuRef, dismissMenu, menu !== null);

  useEffect(() => {
    try {
      window.localStorage.setItem(storageKey, JSON.stringify([...hiddenColumns]));
    } catch {
      // Storage can be disabled in hardened browser sessions. Column controls
      // still work for the current visit.
    }
  }, [hiddenColumns, storageKey]);

  // Notice React-driven row/header updates without treating our own row sort
  // as new source data. A compact text signature also catches keyed rows whose
  // contents change in place after a refresh.
  useEffect(() => {
    const computeSignature = () => {
      const heads = headerCells(table).map(cell => cleanCellText(cell.textContent)).join('|');
      const rows = bodyRows(table);
      return `${heads}::${rows.length}::${rows.map(row => cleanCellText(row.textContent)).join('||')}`;
    };
    signatureRef.current = computeSignature();

    let frame = null;
    const observer = new MutationObserver(() => {
      if (frame !== null) cancelAnimationFrame(frame);
      frame = requestAnimationFrame(() => {
        const nextSignature = computeSignature();
        if (nextSignature === signatureRef.current) return;
        signatureRef.current = nextSignature;
        const nextColumns = readColumns(table);
        setColumns(current => {
          const currentSignature = current.map(c => `${c.id}:${c.label}`).join('|');
          const nextColumnSignature = nextColumns.map(c => `${c.id}:${c.label}`).join('|');
          return currentSignature === nextColumnSignature ? current : nextColumns;
        });
        setRevision(value => value + 1);
      });
    });
    observer.observe(table, { childList: true, subtree: true, characterData: true });
    return () => {
      observer.disconnect();
      if (frame !== null) cancelAnimationFrame(frame);
    };
  }, [table]);

  useEffect(() => {
    const rows = bodyRows(table);
    rows.forEach(row => {
      if (!originalOrderRef.current.has(row)) {
        originalOrderRef.current.set(row, nextOrderRef.current++);
      }
    });

    const normalizedQuery = query.trim().toLocaleLowerCase();
    const activeFilters = Object.entries(filters)
      .map(([columnId, value]) => [columns.find(column => column.id === columnId), value.trim().toLocaleLowerCase()])
      .filter(([column, value]) => column && value);

    let shown = 0;
    rows.forEach((row, index) => {
      const allText = cleanCellText(row.innerText || row.textContent).toLocaleLowerCase();
      const matchesQuery = !normalizedQuery || allText.includes(normalizedQuery);
      const matchesColumns = activeFilters.every(([column, value]) => {
        const cellText = cleanCellText(row.cells[column.index]?.innerText || row.cells[column.index]?.textContent)
          .toLocaleLowerCase();
        return cellText.includes(value);
      });
      const visible = matchesQuery && matchesColumns;
      row.dataset.cfVisibilityManaged = 'true';
      row.hidden = !visible;
      if (visible) shown += 1;

      if (selectionSupported) {
        const key = rowKey(row, index);
        const selected = selectedKeys.has(key);
        row.dataset.cfSelectionManaged = 'true';
        row.dataset.cfListRow = 'true';
        row.dataset.cfSelected = selected ? 'true' : 'false';
        row.setAttribute('aria-selected', selected ? 'true' : 'false');
        row.tabIndex = 0;
      }
    });

    if (selectionSupported && selectedKeys.size > 0) {
      const availableKeys = new Set(rows.map((row, index) => rowKey(row, index)));
      const hasStaleSelection = [...selectedKeys].some(key => !availableKeys.has(key));
      if (hasStaleSelection) {
        setSelectedKeys(current => new Set([...current].filter(key => availableKeys.has(key))));
      }
    }

    const allRows = Array.from(table.tBodies?.[0]?.rows || []);
    const allHeaders = headerCells(table);
    columns.forEach(column => {
      const hidden = hiddenColumns.has(column.id);
      if (allHeaders[column.index]) {
        allHeaders[column.index].dataset.cfColumnManaged = 'true';
        allHeaders[column.index].hidden = hidden;
      }
      allRows.forEach(row => {
        if (row.cells[column.index]) {
          row.cells[column.index].dataset.cfColumnManaged = 'true';
          row.cells[column.index].hidden = hidden;
        }
      });
    });

    if (sort.index !== null && table.tBodies?.[0]) {
      ownsDomSortRef.current = true;
      const sorted = [...rows].sort((a, b) => {
        const comparison = compareCellText(
          a.cells[sort.index]?.innerText || a.cells[sort.index]?.textContent,
          b.cells[sort.index]?.innerText || b.cells[sort.index]?.textContent,
          sort.dir,
        );
        if (comparison !== 0) return comparison;
        return originalOrderRef.current.get(a) - originalOrderRef.current.get(b);
      });
      const current = bodyRows(table);
      if (sorted.some((row, index) => row !== current[index])) {
        sorted.forEach(row => table.tBodies[0].appendChild(row));
      }
    } else if (ownsDomSortRef.current && table.tBodies?.[0]) {
      const restored = [...rows].sort(
        (a, b) => originalOrderRef.current.get(a) - originalOrderRef.current.get(b),
      );
      const current = bodyRows(table);
      if (restored.some((row, index) => row !== current[index])) {
        restored.forEach(row => table.tBodies[0].appendChild(row));
      }
      ownsDomSortRef.current = false;
    }

    table.classList.add('cf-list-table');
    table.classList.toggle('cf-list-table--selectable', selectionSupported);
    setStats(current => (current.total === rows.length && current.shown === shown ? current : { total: rows.length, shown }));
  }, [columns, filters, hiddenColumns, query, revision, selectedKeys, selectionSupported, sort, table]);

  useEffect(() => () => {
    table.classList.remove('cf-list-table', 'cf-list-table--selectable');
    table.querySelectorAll('[data-cf-column-managed="true"]').forEach(cell => {
      cell.hidden = false;
      delete cell.dataset.cfColumnManaged;
    });
    table.querySelectorAll('tbody tr[data-cf-visibility-managed="true"]').forEach(row => {
      row.hidden = false;
      delete row.dataset.cfVisibilityManaged;
    });
    table.querySelectorAll('tbody tr[data-cf-selection-managed="true"]').forEach(row => {
      row.removeAttribute('aria-selected');
      row.removeAttribute('tabindex');
      delete row.dataset.cfSelectionManaged;
      delete row.dataset.cfListRow;
      delete row.dataset.cfSelected;
      delete row.dataset.cfListKey;
    });
  }, [table]);

  // Add keyboard/click sorting only to plain headers. Screens already using
  // useTableList keep their server/client sorting and aria state unchanged.
  useEffect(() => {
    const cleanups = [];
    headerCells(table).forEach((cell, index) => {
      if (!cleanCellText(cell.textContent || cell.innerText)) return;
      if (cell.matches('[role="button"], [aria-sort]') || cell.querySelector(INTERACTIVE_SELECTOR)) return;
      const activate = () => setSort(current => ({
        index,
        dir: current.index === index && current.dir === 'asc' ? 'desc' : 'asc',
      }));
      const onKeyDown = event => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          activate();
        }
      };
      cell.classList.add('cf-list-sortable');
      cell.tabIndex = 0;
      cell.addEventListener('click', activate);
      cell.addEventListener('keydown', onKeyDown);
      cleanups.push(() => {
        cell.classList.remove('cf-list-sortable', 'cf-list-sort-asc', 'cf-list-sort-desc');
        cell.removeEventListener('click', activate);
        cell.removeEventListener('keydown', onKeyDown);
        cell.removeAttribute('tabindex');
        cell.removeAttribute('aria-sort');
      });
    });
    return () => cleanups.forEach(cleanup => cleanup());
  }, [columns, table]);

  useEffect(() => {
    headerCells(table).forEach((cell, index) => {
      if (!cell.classList.contains('cf-list-sortable')) return;
      const active = sort.index === index;
      cell.classList.toggle('cf-list-sort-asc', active && sort.dir === 'asc');
      cell.classList.toggle('cf-list-sort-desc', active && sort.dir === 'desc');
      cell.setAttribute('aria-sort', active ? (sort.dir === 'asc' ? 'ascending' : 'descending') : 'none');
      const label = columns[index]?.label || `column ${index + 1}`;
      cell.title = `Sort by ${label}`;
    });
  }, [columns, sort, table]);

  const toggleRowSelection = useCallback(row => {
    const rows = bodyRows(table);
    const index = rows.indexOf(row);
    if (index < 0) return;
    const key = rowKey(row, index);
    setSelectedKeys(current => {
      const next = new Set(current);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
  }, [table]);

  // The checkbox is drawn in the first cell with CSS. Only its left-hand hit
  // area toggles on pointer input, so links and normal row navigation retain
  // their expected behavior. Space toggles a focused row for keyboard users.
  useEffect(() => {
    if (!selectionSupported || !table.tBodies?.[0]) return undefined;
    const body = table.tBodies[0];
    const onClick = event => {
      if (event.target.closest(INTERACTIVE_SELECTOR)) return;
      const row = event.target.closest('tr[data-cf-list-row="true"]');
      const firstCell = row?.cells?.[0];
      if (!row || !firstCell || firstCell.hidden) return;
      const bounds = firstCell.getBoundingClientRect();
      if (event.clientX - bounds.left <= 38) toggleRowSelection(row);
    };
    const onKeyDown = event => {
      const row = event.target.closest('tr[data-cf-list-row="true"]');
      if (row && event.target === row && event.key === ' ') {
        event.preventDefault();
        toggleRowSelection(row);
      }
    };
    body.addEventListener('click', onClick);
    body.addEventListener('keydown', onKeyDown);
    return () => {
      body.removeEventListener('click', onClick);
      body.removeEventListener('keydown', onKeyDown);
    };
  }, [selectionSupported, table, toggleRowSelection]);

  const visibleRows = useCallback(() => bodyRows(table).filter(row => !row.hidden), [table]);

  const currentlyVisibleRows = visibleRows();
  const allVisibleSelected = currentlyVisibleRows.length > 0
    && currentlyVisibleRows.every((row, index) => selectedKeys.has(rowKey(row, index)));

  useEffect(() => {
    if (selectAllRef.current) {
      selectAllRef.current.indeterminate = selectedKeys.size > 0 && !allVisibleSelected;
    }
  }, [allVisibleSelected, selectedKeys]);

  const toggleAllVisible = () => {
    const visible = visibleRows();
    setSelectedKeys(current => {
      const next = new Set(current);
      if (allVisibleSelected) {
        visible.forEach((row, index) => next.delete(rowKey(row, index)));
      } else {
        visible.forEach((row, index) => next.add(rowKey(row, index)));
      }
      return next;
    });
  };

  const activeFilterCount = Object.values(filters).filter(value => value.trim()).length;
  const visibleColumnCount = columns.filter(column => !hiddenColumns.has(column.id)).length;

  const toggleColumn = columnId => {
    setHiddenColumns(current => {
      const next = new Set(current);
      if (next.has(columnId)) next.delete(columnId);
      else if (visibleColumnCount > 1) next.add(columnId);
      return next;
    });
  };

  const resetView = () => {
    setQuery('');
    setFilters({});
    setHiddenColumns(new Set());
    setSelectedKeys(new Set());
    setSort({ index: null, dir: 'asc' });
    setMenu(null);
  };

  const exportView = () => {
    const rows = bodyRows(table);
    const selectedRows = rows.filter((row, index) => selectedKeys.has(rowKey(row, index)));
    const sourceRows = selectedRows.length > 0 ? selectedRows : rows.filter(row => !row.hidden);
    const exportedColumns = columns.filter(column => !hiddenColumns.has(column.id));
    const csv = tableRowsToCsv(
      exportedColumns.map(column => column.label),
      sourceRows.map(row => exportedColumns.map(column => cleanCellText(
        row.cells[column.index]?.innerText || row.cells[column.index]?.textContent,
      ))),
    );
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = tableExportFileName(routeKey, table.getAttribute('data-testid') || 'list');
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
    setExportMessage(`Exported ${sourceRows.length} ${sourceRows.length === 1 ? 'row' : 'rows'}`);
    window.setTimeout(() => setExportMessage(''), 2400);
  };

  return (
    <div className="cf-list-tools" data-testid="list-tools">
      <div className="cf-list-tools__search">
        <Search size={16} aria-hidden="true" />
        <input
          type="search"
          value={query}
          onChange={event => setQuery(event.target.value)}
          placeholder="Search this list…"
          aria-label="Search rows in this table"
          data-testid="list-tools-search"
        />
        {query && (
          <button type="button" className="cf-list-tools__clear-search" onClick={() => setQuery('')} aria-label="Clear search">
            <X size={14} aria-hidden="true" />
          </button>
        )}
      </div>

      <div className="cf-list-tools__actions" ref={menuRef}>
        <div className="cf-list-tools__menu-wrap">
          <button
            type="button"
            className={`cf-list-tools__button${activeFilterCount ? ' is-active' : ''}`}
            onClick={() => setMenu(current => current === 'filters' ? null : 'filters')}
            aria-expanded={menu === 'filters'}
            data-testid="list-tools-filter-button"
          >
            <Filter size={15} aria-hidden="true" />
            Filter
            {activeFilterCount > 0 && <span className="cf-list-tools__count">{activeFilterCount}</span>}
          </button>
          {menu === 'filters' && (
            <div className="cf-list-tools__popover cf-list-tools__popover--filters" role="group" aria-label="Column filters">
              <div className="cf-list-tools__popover-title">
                <span>Filter by column</span>
                {activeFilterCount > 0 && (
                  <button type="button" onClick={() => setFilters({})}>Clear all</button>
                )}
              </div>
              <div className="cf-list-tools__filter-list">
                {columns.map(column => (
                  <label key={column.id}>
                    <span>{column.label}</span>
                    <input
                      type="search"
                      value={filters[column.id] || ''}
                      onChange={event => setFilters(current => ({ ...current, [column.id]: event.target.value }))}
                      placeholder={`Contains…`}
                    />
                  </label>
                ))}
              </div>
            </div>
          )}
        </div>

        <div className="cf-list-tools__menu-wrap">
          <button
            type="button"
            className={`cf-list-tools__button${hiddenColumns.size ? ' is-active' : ''}`}
            onClick={() => setMenu(current => current === 'columns' ? null : 'columns')}
            aria-expanded={menu === 'columns'}
            data-testid="list-tools-columns-button"
          >
            <Columns3 size={15} aria-hidden="true" />
            Columns
            {hiddenColumns.size > 0 && <span className="cf-list-tools__count">{visibleColumnCount}</span>}
          </button>
          {menu === 'columns' && (
            <div className="cf-list-tools__popover cf-list-tools__popover--columns" role="group" aria-label="Choose visible columns">
              <div className="cf-list-tools__popover-title">
                <span>Visible columns</span>
                {hiddenColumns.size > 0 && (
                  <button type="button" onClick={() => setHiddenColumns(new Set())}>Show all</button>
                )}
              </div>
              <div className="cf-list-tools__column-list">
                {columns.map(column => {
                  const visible = !hiddenColumns.has(column.id);
                  const lastVisible = visible && visibleColumnCount === 1;
                  return (
                    <label key={column.id} title={lastVisible ? 'At least one column must remain visible' : ''}>
                      <input
                        type="checkbox"
                        checked={visible}
                        disabled={lastVisible}
                        onChange={() => toggleColumn(column.id)}
                      />
                      <span>{column.label}</span>
                    </label>
                  );
                })}
              </div>
              <p>Saved for this list on this device.</p>
            </div>
          )}
        </div>

        {selectionSupported && (
          <label className="cf-list-tools__select-all">
            <input
              ref={selectAllRef}
              type="checkbox"
              checked={allVisibleSelected}
              onChange={toggleAllVisible}
              disabled={stats.shown === 0}
            />
            <CheckSquare size={15} aria-hidden="true" />
            <span>{selectedKeys.size > 0 ? `${selectedKeys.size} selected` : 'Select page'}</span>
          </label>
        )}

        <button
          type="button"
          className="cf-list-tools__button"
          onClick={exportView}
          disabled={stats.shown === 0 && selectedKeys.size === 0}
          title={selectedKeys.size > 0 ? 'Export selected rows as CSV' : 'Export the current filtered view as CSV'}
          data-testid="list-tools-export"
        >
          <Download size={15} aria-hidden="true" />
          {selectedKeys.size > 0 ? 'Export selected' : 'Export view'}
        </button>

        {(query || activeFilterCount > 0 || hiddenColumns.size > 0 || selectedKeys.size > 0 || sort.index !== null) && (
          <button type="button" className="cf-list-tools__icon-button" onClick={resetView} title="Reset list view" aria-label="Reset list view">
            <RotateCcw size={15} aria-hidden="true" />
          </button>
        )}
      </div>

      <div className="cf-list-tools__meta" aria-live="polite">
        {exportMessage || (
          <>
            <strong>{stats.shown}</strong>
            {stats.shown === stats.total ? ' rows' : ` of ${stats.total} rows`}
            {(query || activeFilterCount > 0) && <span> in current view</span>}
          </>
        )}
      </div>
    </div>
  );
}

/**
 * Route-aware enhancement layer for standard CoreFlux data tables.
 *
 * It creates a React-owned toolbar host immediately before each eligible
 * table. The table remains owned by its domain component, preserving links,
 * buttons, state, API pagination, and existing sort/bulk-action behavior.
 */
export default function UniversalListTools({ routeKey }) {
  const [entries, setEntries] = useState([]);

  useEffect(() => {
    const hosts = new Map();
    let frame = null;

    const scan = () => {
      frame = null;
      const tables = Array.from(document.querySelectorAll('main table.data-table')).filter(isEligibleTable);
      const active = new Set(tables);

      hosts.forEach((host, table) => {
        if (!active.has(table) || !table.isConnected) {
          host.remove();
          hosts.delete(table);
        }
      });

      tables.forEach(table => {
        if (hosts.has(table)) return;
        const host = document.createElement('div');
        host.className = TOOL_HOST_CLASS;
        host.dataset.forTable = table.getAttribute('data-testid') || 'table';
        table.parentNode?.insertBefore(host, table);
        hosts.set(table, host);
      });

      const nextEntries = tables
        .filter(table => hosts.has(table))
        .map(table => ({ table, host: hosts.get(table) }));
      setEntries(current => {
        const unchanged = current.length === nextEntries.length
          && current.every((entry, index) => entry.table === nextEntries[index].table && entry.host === nextEntries[index].host);
        return unchanged ? current : nextEntries;
      });
    };

    const scheduleScan = () => {
      if (frame !== null) return;
      frame = requestAnimationFrame(scan);
    };

    scheduleScan();
    const observer = new MutationObserver(scheduleScan);
    const root = document.getElementById('root');
    if (root) observer.observe(root, { childList: true, subtree: true });

    return () => {
      observer.disconnect();
      if (frame !== null) cancelAnimationFrame(frame);
      hosts.forEach(host => host.remove());
      hosts.clear();
    };
  }, [routeKey]);

  return entries.map(({ table, host }) => createPortal(
    <TableTools table={table} routeKey={routeKey} />,
    host,
    `${routeKey}:${table.getAttribute('data-testid') || 'table'}`,
  ));
}

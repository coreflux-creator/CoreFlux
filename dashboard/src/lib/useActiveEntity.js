/**
 * useActiveEntity — shared hook that exposes the tenant's currently
 * selected accounting entity and re-renders on change.
 *
 * Backend contract (Sprint 4/B1 + 6b):
 *   GET  /api/active_entity.php                 → { active_entity_id, entities[] }
 *   POST /api/active_entity.php  { entity_id }  → { active_entity_id, entity }
 *
 * Header.jsx dispatches `cf:active-entity-changed` window events whenever
 * the user picks a new entity from the Briefcase dropdown; this hook
 * listens and refreshes `activeEntityId` so every consumer re-scopes its
 * queries automatically without a page reload.
 *
 * Usage:
 *   const { activeEntityId, entities, entityQuery } = useActiveEntity();
 *   const url = `/modules/accounting/api/journal_entries.php${entityQuery('?')}`;
 *   // entityQuery('?') returns '?entity_id=3' or '' when nothing active.
 */
import { useEffect, useState, useCallback } from 'react';
import { api } from './api';

export function useActiveEntity() {
  const [activeEntityId, setActiveEntityId] = useState(null);
  const [entities, setEntities] = useState([]);
  const [loaded, setLoaded] = useState(false);

  const load = useCallback(async () => {
    try {
      const r = await api.get('/api/active_entity.php');
      setActiveEntityId(r?.active_entity_id ?? null);
      setEntities(r?.entities ?? []);
    } catch {
      // API unavailable / tenant has no entities — silently no-scope.
      setActiveEntityId(null);
      setEntities([]);
    } finally {
      setLoaded(true);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  useEffect(() => {
    const handler = (e) => {
      const eid = e?.detail?.entity_id;
      if (eid !== undefined) setActiveEntityId(eid);
      else load();
    };
    window.addEventListener('cf:active-entity-changed', handler);
    return () => window.removeEventListener('cf:active-entity-changed', handler);
  }, [load]);

  /**
   * Helper: append `?entity_id=N` (or `&entity_id=N`) to a URL when an
   * entity is active. Returns '' when no entity is active so callers
   * can unconditionally interpolate: `${baseUrl}${entityQuery('?')}`.
   */
  const entityQuery = useCallback((prefix = '?') => {
    if (!activeEntityId) return '';
    return `${prefix}entity_id=${activeEntityId}`;
  }, [activeEntityId]);

  const activeEntity = entities.find(e => e.id === activeEntityId) || null;

  return { activeEntityId, activeEntity, entities, entityQuery, loaded, reload: load };
}

export default useActiveEntity;

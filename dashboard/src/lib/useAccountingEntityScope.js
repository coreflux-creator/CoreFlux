import { useCallback, useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useActiveEntity } from './useActiveEntity';

export function addEntityScope(path, entityId, allEntities = false) {
  const [beforeHash, hash = ''] = path.split('#', 2);
  const [pathname, query = ''] = beforeHash.split('?', 2);
  const params = new URLSearchParams(query);
  if (allEntities) params.set('entity_id', 'all');
  else if (entityId) params.set('entity_id', String(entityId));
  else params.delete('entity_id');
  const suffix = params.toString();
  return pathname + (suffix ? `?${suffix}` : '') + (hash ? `#${hash}` : '');
}

export function useAccountingEntityScope({ defaultAll = false } = {}) {
  const [params, setParams] = useSearchParams();
  const { activeEntityId, entities, loaded, error: entityError, reload: reloadEntities } = useActiveEntity();
  const requested = params.get('entity_id');
  const allEntities = requested === 'all' || (requested === null && loaded && !entityError
    && (entities.length === 0 || (defaultAll && entities.length > 1)));
  const requestedId = requested && /^[1-9][0-9]*$/.test(requested) ? Number(requested) : null;
  const entityId = allEntities ? null : requested === null ? activeEntityId : requestedId;
  const entity = entities.find(row => Number(row.id) === Number(entityId)) || null;
  const error = entityError?.message || (loaded && requested !== null && !allEntities && (!requestedId || !entity)
    ? 'That legal entity is not available in this workspace.' : null);
  const ready = loaded && !error && (allEntities || Boolean(entity));
  const scopeKey = allEntities ? 'all' : String(entityId || 'pending');
  const label = allEntities ? 'All entities (not consolidated)' : (entity ? `${entity.code} · ${entity.legal_name || entity.name || ''}` : '');
  const withScope = useCallback(
    path => addEntityScope(path, entityId, allEntities),
    [entityId, allEntities],
  );
  const apiQuery = useMemo(() => entityId ? `entity_id=${entityId}` : '', [entityId]);
  const setScope = useCallback(value => {
    const next = new URLSearchParams(params);
    next.set('entity_id', value);
    next.delete('bank_account_id');
    next.delete('page');
    setParams(next, { replace: true });
  }, [params, setParams]);

  return { entityId, entity, entities, allEntities, label, scopeKey, ready, loaded, error,
    apiQuery, withScope, setScope, reloadEntities };
}

import React from 'react';

export default function AccountingEntitySelector({ scope, testId = 'accounting-entity-selector', allowAll = true }) {
  return (
    <label style={{ display: 'inline-flex', flexDirection: 'column', gap: 3, minWidth: 190, fontSize: 11, color: '#64748b' }}>
      Legal entity
      <select className="input" data-testid={testId} aria-label="Legal entity"
        value={scope.error && !scope.entity ? 'invalid' : scope.allEntities ? 'all' : String(scope.entityId || '')}
        onChange={event => scope.setScope(event.target.value)}
        disabled={!scope.loaded || scope.entities.length === 0}
        style={{ fontSize: 12, maxWidth: 280 }}>
        {scope.error && !scope.entity && <option value="invalid" disabled>Select a legal entity</option>}
        {allowAll && <option value="all">All entities (not consolidated)</option>}
        {scope.entities.map(row => (
          <option key={row.id} value={String(row.id)}>{row.code} · {row.legal_name || row.name}</option>
        ))}
      </select>
    </label>
  );
}

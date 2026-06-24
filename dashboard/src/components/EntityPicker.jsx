import React from 'react';
import { useApi } from '../lib/api';

const ACCOUNTING_ENTITIES_API = '/api/v1/accounting/entities';

/**
 * <EntityPicker /> — reusable entity dropdown for module create forms
 * (AP bill, AR invoice, employee, etc.). Returns the accounting_entities
 * list filtered to active entities in the current tenant.
 *
 * Props:
 *   value        number|null
 *   onChange     (entity_id|null) => void
 *   required?    boolean
 *   testId?      string (defaults to 'entity-picker')
 *   label?       string (defaults to 'Entity')
 *   allowNone?   boolean (adds "— All / default —" option)
 */
export default function EntityPicker({
  value, onChange, required = false, testId = 'entity-picker',
  label = 'Entity', allowNone = true,
}) {
  const { data } = useApi(ACCOUNTING_ENTITIES_API);
  const entities = data?.rows || data?.entities || [];
  return (
    <label style={{ fontSize: 12 }}>
      {label}
      <select
        className="input"
        value={value ?? ''}
        onChange={e => onChange(e.target.value === '' ? null : Number(e.target.value))}
        required={required}
        data-testid={testId}
        style={{ display: 'block' }}
      >
        {allowNone && <option value="">— Default entity —</option>}
        {entities.map(en => (
          <option key={en.id} value={en.id}>
            {en.legal_name || en.code}
            {en.code && en.legal_name ? ` (${en.code})` : ''}
          </option>
        ))}
      </select>
    </label>
  );
}

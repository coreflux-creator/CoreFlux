import React, { useEffect, useMemo, useState } from 'react';
import { CheckSquare, X } from 'lucide-react';

function optionShape(option) {
  if (typeof option === 'string') return { value: option, label: option };
  return option;
}

function initialValue(field) {
  if (!field) return '';
  if (field.defaultValue != null) return String(field.defaultValue);
  return '';
}

/**
 * Shared selection editor for operational directories.
 *
 * The owning screen controls row selection and performs the API call. This
 * component keeps field/value picking consistent without knowing anything
 * about a placement, client, person, or accounting record.
 */
export default function BulkEditBar({
  count,
  noun = 'record',
  fields = [],
  busy = false,
  onApply,
  onClear,
  testid = 'bulk-edit',
}) {
  const [fieldKey, setFieldKey] = useState(fields[0]?.key || '');
  const field = useMemo(
    () => fields.find(candidate => candidate.key === fieldKey) || fields[0] || null,
    [fieldKey, fields]
  );
  const [value, setValue] = useState(() => initialValue(field));

  useEffect(() => {
    if (!fields.some(candidate => candidate.key === fieldKey)) {
      setFieldKey(fields[0]?.key || '');
    }
  }, [fieldKey, fields]);

  useEffect(() => {
    setValue(initialValue(field));
  }, [field]);

  if (count <= 0 || !field) return null;

  const options = (field.options || []).map(optionShape);
  const valueReady = field.allowBlank || String(value).trim() !== '';
  const apply = () => {
    if (!valueReady || busy) return;
    const nextValue = field.type === 'number' ? Number(value) : value;
    onApply?.(field.key, nextValue, field.label);
  };

  return (
    <div
      data-testid={`${testid}-toolbar`}
      style={{
        display: 'flex', alignItems: 'center', gap: 'var(--cf-space-2)', flexWrap: 'wrap',
        padding: 'var(--cf-space-2) var(--cf-space-3)', marginBottom: 'var(--cf-space-3)',
        background: '#f8fafc', border: '1px solid #cbd5e1', borderRadius: 6,
      }}
    >
      <CheckSquare size={16} aria-hidden="true" />
      <strong data-testid={`${testid}-selected-count`}>
        {count} {noun}{count === 1 ? '' : 's'} selected
      </strong>
      <span style={{ color: 'var(--cf-text-secondary)', fontSize: 13 }}>Change</span>
      <select
        className="input"
        value={field.key}
        onChange={event => setFieldKey(event.target.value)}
        disabled={busy}
        aria-label="Field to update"
        data-testid={`${testid}-field`}
        style={{ minWidth: 150 }}
      >
        {fields.map(candidate => (
          <option key={candidate.key} value={candidate.key}>{candidate.label}</option>
        ))}
      </select>

      {field.type === 'select' ? (
        <select
          className="input"
          value={value}
          onChange={event => setValue(event.target.value)}
          disabled={busy}
          aria-label={`New ${field.label}`}
          data-testid={`${testid}-value`}
          style={{ minWidth: 180, maxWidth: 320 }}
        >
          {field.placeholder && <option value="" disabled={!field.allowBlank}>{field.placeholder}</option>}
          {options.map(option => (
            <option key={String(option.value)} value={String(option.value)} disabled={option.disabled}>
              {option.label}
            </option>
          ))}
        </select>
      ) : (
        <input
          className="input"
          type={field.type === 'number' ? 'number' : 'text'}
          min={field.min}
          max={field.max}
          step={field.step}
          placeholder={field.placeholder || `New ${field.label}`}
          value={value}
          onChange={event => setValue(event.target.value)}
          disabled={busy}
          aria-label={`New ${field.label}`}
          data-testid={`${testid}-value`}
          style={{ minWidth: 180, maxWidth: 320 }}
        />
      )}

      <button
        type="button"
        className="btn btn--primary"
        disabled={!valueReady || busy}
        onClick={apply}
        data-testid={`${testid}-apply`}
      >
        {busy ? 'Applying…' : 'Apply to selected'}
      </button>
      <button
        type="button"
        className="btn btn--ghost"
        disabled={busy}
        onClick={onClear}
        title="Clear selection"
        aria-label="Clear selection"
        data-testid={`${testid}-clear`}
        style={{ width: 34, height: 34, padding: 0, display: 'inline-grid', placeItems: 'center' }}
      >
        <X size={16} aria-hidden="true" />
      </button>
    </div>
  );
}

import React from 'react';

/**
 * Shared line-item editor for AP bills and Billing invoices.
 *
 * Vocabulary (must match `apNormalizeItemType()` in /app/modules/ap/lib/ap.php):
 *   labor, expense, materials, fixed_fee, milestone, discount,
 *   subscription, mileage, per_diem, reimbursement, other
 *
 * Each row carries:
 *   item_type, description, quantity, unit, unit_price, gl_account_code
 * The component computes subtotal client-side for live feedback; the
 * server recomputes authoritatively on save.
 *
 * Props:
 *   testIdPrefix         e.g. "bill" or "invoice" — drives all data-testids
 *   lines                array of line objects (controlled)
 *   onChange(lines)      replace the array
 *   glLabel              column header for the GL field (e.g. "Expense GL")
 *   glField              key on each line (gl_expense_account_code | gl_revenue_account_code)
 *   accounts             list of {code,name,is_postable,active} from /api/accounting/accounts
 */
export const ITEM_TYPES = [
  { value: 'labor',         label: 'Labor (hours)',         defaultUnit: 'hour' },
  { value: 'expense',       label: 'Expense',               defaultUnit: 'each' },
  { value: 'materials',     label: 'Materials',             defaultUnit: 'each' },
  { value: 'fixed_fee',     label: 'Fixed fee',             defaultUnit: 'each' },
  { value: 'milestone',     label: 'Milestone',             defaultUnit: 'each' },
  { value: 'discount',      label: 'Discount (negative)',   defaultUnit: 'each' },
  { value: 'subscription',  label: 'Subscription',          defaultUnit: 'month' },
  { value: 'mileage',       label: 'Mileage',               defaultUnit: 'mile' },
  { value: 'per_diem',      label: 'Per diem',              defaultUnit: 'day' },
  { value: 'reimbursement', label: 'Reimbursement (1:1)',   defaultUnit: 'each' },
  { value: 'other',         label: 'Other',                 defaultUnit: 'each' },
];

export function blankLine(itemType = 'other') {
  const meta = ITEM_TYPES.find((t) => t.value === itemType) || ITEM_TYPES[ITEM_TYPES.length - 1];
  return { item_type: itemType, description: '', quantity: 1, unit: meta.defaultUnit, unit_price: '', gl_account_code: '' };
}

export default function LineItemEditor({ testIdPrefix, lines, onChange, glLabel, glField, accounts = [] }) {
  const setLine = (i, patch) => {
    const out = [...lines];
    out[i] = { ...out[i], ...patch };
    onChange(out);
  };

  const setItemType = (i, value) => {
    const meta = ITEM_TYPES.find((t) => t.value === value) || ITEM_TYPES[ITEM_TYPES.length - 1];
    // When changing type, reset unit to that type's default but preserve qty/desc/price.
    setLine(i, { item_type: value, unit: meta.defaultUnit });
  };

  const removeLine = (i) => {
    const out = lines.filter((_, j) => j !== i);
    onChange(out.length ? out : [blankLine()]);
  };

  const addLine = () => onChange([...lines, blankLine()]);

  const subtotal = lines.reduce((s, l) => s + (Number(l.quantity) || 0) * (Number(l.unit_price) || 0), 0);

  return (
    <div data-testid={`${testIdPrefix}-lines`}>
      <table className="data-table" style={{ width: '100%' }}>
        <thead>
          <tr>
            <th style={{ width: 150 }}>Item type</th>
            <th>Description</th>
            <th style={{ width: 90, textAlign: 'right' }}>Qty</th>
            <th style={{ width: 90 }}>Unit</th>
            <th style={{ width: 110, textAlign: 'right' }}>Unit price</th>
            <th style={{ width: 130 }}>{glLabel}</th>
            <th style={{ width: 100, textAlign: 'right' }}>Subtotal</th>
            <th style={{ width: 40 }}></th>
          </tr>
        </thead>
        <tbody>
          {lines.map((l, i) => {
            const lineSub = (Number(l.quantity) || 0) * (Number(l.unit_price) || 0);
            return (
              <tr key={i} data-testid={`${testIdPrefix}-line-${i}`}>
                <td>
                  <select
                    className="input"
                    value={l.item_type || 'other'}
                    onChange={(e) => setItemType(i, e.target.value)}
                    data-testid={`${testIdPrefix}-line-${i}-item-type`}
                  >
                    {ITEM_TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                  </select>
                </td>
                <td>
                  <input
                    className="input"
                    value={l.description}
                    onChange={(e) => setLine(i, { description: e.target.value })}
                    data-testid={`${testIdPrefix}-line-${i}-description`}
                    placeholder={l.item_type === 'labor' ? 'e.g. Senior Engineer — Acme — Jul 1-7' : 'Describe the line item'}
                    required
                  />
                </td>
                <td>
                  <input
                    className="input" type="number" step="0.0001"
                    style={{ textAlign: 'right' }}
                    value={l.quantity}
                    onChange={(e) => setLine(i, { quantity: e.target.value })}
                    data-testid={`${testIdPrefix}-line-${i}-quantity`}
                  />
                </td>
                <td>
                  <input
                    className="input"
                    value={l.unit}
                    onChange={(e) => setLine(i, { unit: e.target.value })}
                    data-testid={`${testIdPrefix}-line-${i}-unit`}
                    placeholder="hour|each|mile|day"
                  />
                </td>
                <td>
                  <input
                    className="input" type="number" step="0.0001"
                    style={{ textAlign: 'right' }}
                    value={l.unit_price}
                    onChange={(e) => setLine(i, { unit_price: e.target.value })}
                    data-testid={`${testIdPrefix}-line-${i}-unit-price`}
                  />
                </td>
                <td>
                  {accounts.length ? (
                    <select
                      className="input"
                      value={l.gl_account_code || ''}
                      onChange={(e) => setLine(i, { gl_account_code: e.target.value })}
                      data-testid={`${testIdPrefix}-line-${i}-gl`}
                    >
                      <option value="">— default —</option>
                      {accounts.map((a) => <option key={a.code} value={a.code}>{a.code} {a.name}</option>)}
                    </select>
                  ) : (
                    <input
                      className="input"
                      value={l.gl_account_code || ''}
                      onChange={(e) => setLine(i, { gl_account_code: e.target.value })}
                      data-testid={`${testIdPrefix}-line-${i}-gl`}
                      placeholder="(default)"
                    />
                  )}
                </td>
                <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }} data-testid={`${testIdPrefix}-line-${i}-subtotal`}>
                  {fmt(lineSub)}
                </td>
                <td>
                  {lines.length > 1 && (
                    <button type="button" className="btn btn--ghost" onClick={() => removeLine(i)} data-testid={`${testIdPrefix}-line-${i}-remove`} title="Remove line">×</button>
                  )}
                </td>
              </tr>
            );
          })}
          <tr style={{ fontWeight: 600, background: '#f9fafb' }}>
            <td colSpan={6} style={{ textAlign: 'right' }}>Subtotal</td>
            <td style={{ textAlign: 'right' }} data-testid={`${testIdPrefix}-subtotal`}>{fmt(subtotal)}</td>
            <td></td>
          </tr>
        </tbody>
      </table>
      <button type="button" className="btn btn--ghost" onClick={addLine} data-testid={`${testIdPrefix}-add-line`} style={{ marginTop: 8 }}>+ Add line</button>
    </div>
  );
}

function fmt(n) { return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

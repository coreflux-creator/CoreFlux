const DOCUMENT_DIMENSIONS = new Set(['vendor', 'legal_entity']);

export function directBillAccountChoices(rows) {
  return rows
    .filter(account => Number(account.active) === 1 && Number(account.is_postable) === 1
      && ['expense', 'other_expense'].includes(account.account_type))
    .map(account => {
      const missing = (account.required_dimensions || []).filter(key => !DOCUMENT_DIMENSIONS.has(key));
      const blocked = (account.blocked_dimensions || []).filter(key => DOCUMENT_DIMENSIONS.has(key));
      const labels = missing.map(key => key === 'placement' ? 'assignment / placement' : key.replaceAll('_', ' '));
      const blockedLabels = blocked.map(key => key.replaceAll('_', ' '));
      return {
        ...account,
        disabled: missing.length > 0 || blocked.length > 0,
        unavailable_reason: labels.length ? `requires ${labels.join(', ')}`
          : blockedLabels.length ? `blocks ${blockedLabels.join(', ')}` : '',
      };
    });
}

export function generatedBillAccountChoices(rows) {
  return rows.filter(account => Number(account.active) === 1 && Number(account.is_postable) === 1
    && account.account_type === 'expense' && account.subtype === 'cogs')
    .map(account => ({ ...account, disabled: false }));
}

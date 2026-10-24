const RECEIPT_SOURCE_TYPES = new Set([
  'billing_payment',
  'billing_deposit_application',
  'billing_deposit_refund',
]);

export function receiptId(value) {
  const text = String(value ?? '');
  if (!/^[1-9][0-9]*$/.test(text)) return null;
  const id = Number(text);
  return Number.isSafeInteger(id) ? String(id) : null;
}

export function receiptSourcePath(entry) {
  if (entry?.source_module !== 'billing' || !RECEIPT_SOURCE_TYPES.has(entry.source_ref_type)) return null;
  const id = receiptId(entry.source_ref_id);
  return id ? `/modules/billing/payments?payment_id=${id}` : null;
}

export function paymentListRequestUrl(page, perPage, query, focusedPaymentId) {
  const params = new URLSearchParams({ page: String(page), per_page: String(perPage) });
  if (query.trim()) params.set('q', query.trim());
  if (focusedPaymentId) params.set('payment_id', focusedPaymentId);
  return `/api/v1/billing/payments?${params.toString()}`;
}

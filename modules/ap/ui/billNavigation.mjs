export function billDetailUrl(billId, returnParams, ownerEntityId, attachmentFailed = false) {
  const params = new URLSearchParams(returnParams);
  const entityId = Number(ownerEntityId);
  if (Number.isSafeInteger(entityId) && entityId > 0) {
    params.set('entity_id', String(entityId));
  } else {
    params.delete('entity_id');
  }
  if (attachmentFailed) params.set('attach_error', '1');
  const query = params.toString();
  return `../bills/${encodeURIComponent(billId)}${query ? `?${query}` : ''}`;
}

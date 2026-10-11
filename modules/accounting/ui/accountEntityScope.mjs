const validEntityId = value => {
  const id = Number(value);
  return Number.isSafeInteger(id) && id > 0 ? id : null;
};

export function preferredAccountEntityId(entities = [], connections = {}, activeEntityId = null) {
  const available = new Set(entities.map(entity => validEntityId(entity.id)).filter(Boolean));
  const accountLinks = [
    ...(connections.bank_accounts || []),
    ...(connections.liability_accounts || []),
  ];
  const linked = new Set(accountLinks.map(connection => validEntityId(connection.entity_id)).filter(Boolean));
  const active = validEntityId(activeEntityId);

  if (accountLinks.some(connection => !validEntityId(connection.entity_id))
      || [...linked].some(id => !available.has(id))) return '';
  if (linked.size === 1) return String([...linked][0]);
  if (linked.size > 1) return active && linked.has(active) ? String(active) : '';
  if (active && available.has(active)) return String(active);
  return available.size === 1 ? String([...available][0]) : '';
}

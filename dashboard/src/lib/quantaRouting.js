/** Captured placement identity is authoritative; names are only legacy suggestions. */
export function quantaRoutingOptions(row, placements, personId) {
  const binding = row.core_assignment;
  return placements.filter(p => Number(p.person_id) === Number(personId) && ['active', 'ended'].includes(p.status)
    && (!p.start_date || p.start_date <= row.work_date) && (!p.end_date || p.end_date >= row.work_date)
    && (!binding || (p.placement_number === binding.placement.number && p.person_number === binding.person.number
      && Number(p.placement_directory_tenant_id) === binding.placement.directory_tenant_id
      && Number(p.person_directory_tenant_id) === binding.person.directory_tenant_id)));
}

export function quantaRoutingSuggestion(row, placements, personId, worksiteName) {
  if (!personId) return '';
  let choices = quantaRoutingOptions(row, placements, personId);
  if (!row.core_assignment) {
    const name = String(worksiteName || '').trim().toLowerCase();
    if (!name) return '';
    choices = choices.filter(p => String(p.end_client_name || '').trim().toLowerCase() === name);
  }
  return choices.length === 1 ? String(choices[0].id) : '';
}

export function quantaRoutingDraft(row, chosen, placements, personId, from) {
  const placement = quantaRoutingOptions(row, placements, personId).find(p => String(p.id) === String(chosen));
  if (!placement) throw new Error('The selected placement no longer matches this time. Refresh the routes.');
  return { worker_id: row.worker_id, worksite_id: row.worksite_id || '', dimension_values: row.dimension_values,
    placement_id: Number(placement.id), effective_from: from || row.work_date, effective_to: placement.end_date || null,
    quanta_assignment_id: row.core_assignment?.assignment_id || '' };
}

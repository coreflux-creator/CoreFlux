const STAFFING_ONLY_DIMENSIONS = new Set([
  'placement', 'worker', 'job', 'recruiter', 'account_manager', 'work_state', 'wc_class',
]);

export function visibleJournalDimensions(dimensions, productMode) {
  return dimensions.filter((dimension) => Number(dimension.active) === 1
    && dimension.dim_key !== 'legal_entity'
    && (productMode !== 'coreaccounting' || !STAFFING_ONLY_DIMENSIONS.has(dimension.dim_key)));
}

export function requireSupportedJournalDimensions(lines, productMode) {
  if (productMode === 'coreaccounting' && lines.some((line) => line.dims?.placement)) {
    throw new Error('This entry is linked to a staffing placement. Edit it in CoreFlux, where its assignment context can be verified.');
  }
}

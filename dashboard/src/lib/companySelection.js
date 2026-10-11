export function uniqueExactCompany(rows, query) {
  const name = String(query || '').trim().toLowerCase();
  if (!name) return null;
  const matches = (rows || []).filter((row) =>
    row?.id && String(row.name || '').trim().toLowerCase() === name);
  return matches.length === 1 ? matches[0] : null;
}

export function selectedCompanyMatchesQuery(company, query) {
  return Boolean(company?.id && String(company.name || '').trim().toLowerCase()
    === String(query || '').trim().toLowerCase());
}

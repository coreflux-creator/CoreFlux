const DOCUMENT_PATHS = {
  billing_invoices: '/modules/billing/invoices',
  ap_bills: '/modules/ap/bills',
};

export function csvImportDocumentLinks(entity, ids, limit = 20) {
  const base = DOCUMENT_PATHS[entity];
  if (!base || !ids || typeof ids !== 'object' || limit < 1) return [];
  return Object.entries(ids)
    .filter(([, id]) => Number.isSafeInteger(Number(id)) && Number(id) > 0)
    .slice(0, limit)
    .map(([number, id]) => ({ label: number, to: `${base}/${Number(id)}` }));
}

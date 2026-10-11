export function defaultRequires1099(vendorType) {
  return vendorType === '1099_individual';
}

export function withVendorType(form, vendorType) {
  return {
    ...form,
    vendor_type: vendorType,
    requires_1099: defaultRequires1099(vendorType),
  };
}

export function extractedRequires1099(draft, currentValue) {
  if (typeof draft.requires_1099 === 'boolean') return draft.requires_1099;
  if (draft.vendor_type) return defaultRequires1099(draft.vendor_type);
  return currentValue;
}

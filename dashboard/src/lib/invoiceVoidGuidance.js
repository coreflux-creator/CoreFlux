const CORRECTION_NOTE = 'This invoice cannot be voided from here. Its approval, ledger, and payment activity must be corrected together.';

export function invoiceVoidGuidance({ status, canVoid, approvalReady, approvalPending }) {
  if (status === 'void' || canVoid) return null;
  if (status === 'draft') {
    if (!approvalReady) return null;
    if (approvalPending) return 'This draft has an active approval request and cannot be voided while review is pending.';
  }
  return CORRECTION_NOTE;
}

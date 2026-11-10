import React, { useEffect, useState } from 'react';
import { api, getPinnedTenantId } from '../../../dashboard/src/lib/api';

export default function BankPaymentDelivery({ paymentId, onClose }) {
  const [tenantId] = useState(getPinnedTenantId);
  const [delivery, setDelivery] = useState(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const path = `/modules/ap/api/payments.php?id=${paymentId}`;
  useEffect(() => {
    let active = true;
    api.get(`${path}&action=bank_delivery`, tenantId ? { headers: { 'X-CoreFlux-Tenant-Id': tenantId } } : {})
      .then(data => { if (active) {
        if (tenantId && String(data.delivery.tenant_id) !== String(tenantId)) throw new Error('The delivery belongs to a different company.');
        setDelivery(data.delivery);
      } })
      .catch(e => { if (active) setError(e.message); });
    return () => { active = false; };
  }, [path, tenantId]);
  async function download() {
    const company = String(delivery.tenant_id);
    const companyChanged = () => getPinnedTenantId() && String(getPinnedTenantId()) !== company;
    if (companyChanged()) { setError('The company changed. Close this review and reopen the intended payment.'); return; }
    setBusy(true); setError('');
    try {
      const result = await api.get(`${path}&action=bank_file`, { headers: { 'X-CoreFlux-Tenant-Id': company } });
      if (companyChanged() || String(result.delivery.tenant_id) !== company) throw new Error('The company changed. Reopen the intended payment before downloading.');
      const bytes = Uint8Array.from(atob(result.delivery.nacha_file_b64), c => c.charCodeAt(0));
      const url = URL.createObjectURL(new Blob([bytes], { type: 'application/octet-stream' }));
      try {
        const link = document.createElement('a'); link.href = url;
        link.download = result.delivery.nacha_filename; link.click();
      } finally { URL.revokeObjectURL(url); }
    } catch (e) { setError(e.message); }
    finally { setBusy(false); }
  }
  return <div style={{ position: 'fixed', inset: 0, background: '#0006', display: 'grid', placeItems: 'center', zIndex: 1100 }}>
    <section role="dialog" aria-modal="true" aria-label="Original bank delivery"
      style={{ background: 'var(--cf-bg, white)', borderRadius: 12, padding: 24, width: 'min(760px, 94vw)', maxHeight: '90vh', overflow: 'auto' }}>
      <header style={{ display: 'flex', justifyContent: 'space-between', gap: 20 }}>
        <h2 style={{ marginTop: 0 }}>Original bank delivery</h2>
        <button type="button" className="btn btn--ghost" onClick={onClose} disabled={busy}>Close</button>
      </header>
      {error && <p role="alert">{error}</p>}
      {!delivery && !error && <p>Loading saved results…</p>}
      {delivery && <>
        <p>{delivery.rail === 'nacha' ? 'Bank payment file' : 'Online bank payment'} · {delivery.created_at}</p>
        <p>These are the original saved instructions. Opening this review does not send a payment.</p>
        {delivery.state !== 'recorded' && <p role="status"><strong>Outcome needs review.</strong> Keep these payments reserved until the original bank request is reconciled.</p>}
        <table className="table" style={{ width: '100%' }}>
          <thead><tr><th>Supplier</th><th>Payment</th><th>Amount</th><th>Saved result</th></tr></thead>
          <tbody>{delivery.payments.map(p => <tr key={p.payment_id}>
            <td>{p.vendor_name}</td><td>AP #{p.payment_id}</td>
            <td>{new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(Number(p.amount_cents) / 100)}</td>
            <td>{String(p.result_status || 'unknown').replaceAll('_', ' ')}</td>
          </tr>)}</tbody>
        </table>
        {delivery.can_download && <>
          <p>This downloads the same file for the entire original batch. Check whether your bank already received it before uploading it again.</p>
          <button type="button" className="btn btn--primary" onClick={download} disabled={busy}>
            {busy ? 'Retrieving file…' : 'Download original bank file'}
          </button>
        </>}
      </>}
    </section>
  </div>;
}

import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';

const fmtMoney = (n) =>
  (Number(n) || 0).toLocaleString(undefined, { style: 'currency', currency: 'USD' });

export default function PurchaseOrders() {
  const { data, loading, reload } = useApi('/modules/ap/api/purchase_orders.php');
  const rows = data?.rows || [];
  const [editing, setEditing] = useState(null); // 'new' | id

  return (
    <section data-testid="ap-purchase-orders">
      <header style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 16 }}>
        <h2 style={{ margin: 0, fontSize: 18 }}>Purchase orders</h2>
        <button type="button" className="btn btn--primary" onClick={() => setEditing('new')} data-testid="ap-po-new">+ New PO</button>
      </header>

      {loading && <p>Loading…</p>}
      {!loading && rows.length === 0 && <p className="empty" data-testid="ap-po-empty">No purchase orders yet. Create one to enable three-way match on bills.</p>}

      {rows.length > 0 && (
        <table className="data-table" data-testid="ap-po-table">
          <thead><tr><th>PO #</th><th>Vendor</th><th>Issued</th><th>Expected</th><th style={{ textAlign: 'right' }}>Total</th><th>Status</th></tr></thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.id} data-testid={`ap-po-row-${r.id}`} style={{ cursor: 'pointer' }} onClick={() => setEditing(r.id)}>
                <td><Link to={`/modules/ap/purchase-orders/${r.id}`} onClick={(e) => e.stopPropagation()}><code>{r.po_number}</code></Link></td>
                <td>{r.vendor_name}</td>
                <td>{r.issue_date}</td>
                <td>{r.expected_date || '—'}</td>
                <td style={{ textAlign: 'right' }}>{fmtMoney(r.total)}</td>
                <td><span className="badge">{r.status}</span></td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {editing === 'new' && <POEditor onCancel={() => setEditing(null)} onSaved={() => { setEditing(null); reload(); }} />}
      {typeof editing === 'number' && <PODetailModal id={editing} onClose={() => { setEditing(null); reload(); }} />}
    </section>
  );
}

function POEditor({ onCancel, onSaved }) {
  const [poNumber, setPoNumber] = useState('');
  const [vendorName, setVendorName] = useState('');
  const [issueDate, setIssueDate] = useState(new Date().toISOString().slice(0, 10));
  const [expectedDate, setExpectedDate] = useState('');
  const [lines, setLines] = useState([{ description: '', quantity: 1, unit: 'each', unit_price: 0, gl_expense_account_code: '' }]);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState(null);

  const total = lines.reduce((s, l) => s + (Number(l.quantity) || 0) * (Number(l.unit_price) || 0), 0);

  const save = async () => {
    setBusy(true); setErr(null);
    try {
      await api.post('/modules/ap/api/purchase_orders.php', {
        po_number: poNumber, vendor_name: vendorName,
        issue_date: issueDate, expected_date: expectedDate || null,
        lines: lines.filter((l) => l.description),
      });
      onSaved();
    } catch (e) { setErr(e.message); }
    finally { setBusy(false); }
  };

  const updateLine = (i, patch) => setLines((ls) => ls.map((l, idx) => idx === i ? { ...l, ...patch } : l));

  return (
    <div onClick={onCancel} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.4)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 50 }}>
      <div onClick={(e) => e.stopPropagation()} style={{ background: '#fff', padding: 20, borderRadius: 8, minWidth: 720, maxWidth: 880, maxHeight: '90vh', overflowY: 'auto' }} data-testid="ap-po-editor">
        <h3 style={{ margin: '0 0 12px' }}>New purchase order</h3>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 2fr', gap: 8, marginBottom: 8 }}>
          <input className="input" placeholder="PO number" value={poNumber} onChange={(e) => setPoNumber(e.target.value)} data-testid="ap-po-number" />
          <input className="input" placeholder="Vendor name" value={vendorName} onChange={(e) => setVendorName(e.target.value)} data-testid="ap-po-vendor" />
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8, marginBottom: 12 }}>
          <input className="input" type="date" value={issueDate} onChange={(e) => setIssueDate(e.target.value)} data-testid="ap-po-issue-date" />
          <input className="input" type="date" placeholder="Expected" value={expectedDate} onChange={(e) => setExpectedDate(e.target.value)} data-testid="ap-po-expected-date" />
        </div>
        <h4 style={{ margin: '8px 0', fontSize: 13 }}>Lines</h4>
        {lines.map((l, i) => (
          <div key={i} style={{ display: 'grid', gridTemplateColumns: '3fr 1fr 1fr 1fr 1fr 30px', gap: 6, marginBottom: 6 }}>
            <input className="input" placeholder="Description" value={l.description} onChange={(e) => updateLine(i, { description: e.target.value })} data-testid={`ap-po-line-${i}-desc`} />
            <input className="input" type="number" step="0.01" placeholder="Qty" value={l.quantity} onChange={(e) => updateLine(i, { quantity: e.target.value })} />
            <input className="input" placeholder="Unit" value={l.unit} onChange={(e) => updateLine(i, { unit: e.target.value })} />
            <input className="input" type="number" step="0.01" placeholder="Unit $" value={l.unit_price} onChange={(e) => updateLine(i, { unit_price: e.target.value })} />
            <input className="input" placeholder="GL acct" value={l.gl_expense_account_code} onChange={(e) => updateLine(i, { gl_expense_account_code: e.target.value })} />
            <button type="button" className="btn btn--ghost" onClick={() => setLines((ls) => ls.filter((_, idx) => idx !== i))} style={{ padding: '2px 6px' }}>×</button>
          </div>
        ))}
        <button type="button" className="btn btn--ghost" onClick={() => setLines((ls) => [...ls, { description: '', quantity: 1, unit: 'each', unit_price: 0, gl_expense_account_code: '' }])} data-testid="ap-po-add-line" style={{ fontSize: 12, marginBottom: 12 }}>+ Add line</button>
        <p className="muted" style={{ fontSize: 13, textAlign: 'right' }}>Total: <strong>{fmtMoney(total)}</strong></p>
        {err && <p className="error">{err}</p>}
        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
          <button type="button" className="btn btn--ghost" onClick={onCancel}>Cancel</button>
          <button type="button" className="btn btn--primary" onClick={save} disabled={busy || !poNumber || !vendorName} data-testid="ap-po-save">{busy ? 'Saving…' : 'Save'}</button>
        </div>
      </div>
    </div>
  );
}

function PODetailModal({ id, onClose }) {
  const { data, loading, reload } = useApi(`/modules/ap/api/purchase_orders.php?id=${id}`);
  const po = data?.po; const lines = data?.lines || []; const receipts = data?.receipts || [];
  const [receiveLines, setReceiveLines] = useState({});
  const [note, setNote] = useState('');
  const [err, setErr] = useState(null);

  const recordReceipt = async () => {
    try {
      const linesArr = Object.entries(receiveLines)
        .map(([k, v]) => ({ po_line_id: Number(k), quantity: Number(v) || 0 }))
        .filter((l) => l.quantity > 0);
      if (linesArr.length === 0) { setErr('Enter quantities to receive'); return; }
      await api.post(`/modules/ap/api/purchase_orders.php?id=${id}&action=receive`, { lines: linesArr, note });
      setReceiveLines({}); setNote(''); setErr(null);
      reload();
    } catch (e) { setErr(e.message); }
  };

  return (
    <div onClick={onClose} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.4)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 50 }}>
      <div onClick={(e) => e.stopPropagation()} style={{ background: '#fff', padding: 20, borderRadius: 8, minWidth: 720, maxHeight: '90vh', overflowY: 'auto' }} data-testid="ap-po-detail">
        {loading && <p>Loading…</p>}
        {po && (
          <>
            <h3 style={{ margin: '0 0 12px' }}><code>{po.po_number}</code> — {po.vendor_name}</h3>
            <p className="muted" style={{ fontSize: 12 }}>Issued {po.issue_date} • Expected {po.expected_date || '—'} • Total {fmtMoney(po.total)} • <span className="badge">{po.status}</span></p>
            <h4 style={{ margin: '12px 0 6px', fontSize: 13 }}>Lines (received vs ordered)</h4>
            <table className="data-table">
              <thead><tr><th>#</th><th>Description</th><th>Qty</th><th>Received</th><th>Receive now</th></tr></thead>
              <tbody>
                {lines.map((l) => (
                  <tr key={l.id}>
                    <td>{l.line_no}</td><td>{l.description}</td>
                    <td>{l.quantity}</td>
                    <td>{l.quantity_received}</td>
                    <td>
                      <input className="input" type="number" step="0.01" min="0" value={receiveLines[l.id] || ''} onChange={(e) => setReceiveLines((s) => ({ ...s, [l.id]: e.target.value }))} style={{ width: 80 }} data-testid={`ap-po-receive-line-${l.id}`} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            <input className="input" placeholder="Receipt note (optional)" value={note} onChange={(e) => setNote(e.target.value)} style={{ marginTop: 8, width: '100%' }} data-testid="ap-po-receipt-note" />
            {err && <p className="error">{err}</p>}
            <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 8 }}>
              <button type="button" className="btn btn--primary" onClick={recordReceipt} data-testid="ap-po-record-receipt">Record receipt</button>
            </div>
            {receipts.length > 0 && (
              <>
                <h4 style={{ margin: '16px 0 6px', fontSize: 13 }}>Past receipts</h4>
                <ul style={{ fontSize: 12, paddingLeft: 16 }}>
                  {receipts.map((r) => <li key={r.id}>{r.received_date} — {r.received_by_name || '—'} {r.note ? `· ${r.note}` : ''}</li>)}
                </ul>
              </>
            )}
          </>
        )}
        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
          <button type="button" className="btn btn--ghost" onClick={onClose}>Close</button>
        </div>
      </div>
    </div>
  );
}

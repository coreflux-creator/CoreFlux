import React, { useState } from 'react';
import { useApi, api } from '../../../dashboard/src/lib/api';

export default function AgingTable() {
  const [asOf, setAsOf] = useState(new Date().toISOString().slice(0, 10));
  const { data, loading, error } = useApi(`/api/v1/billing/aging?as_of=${asOf}`);
  const rows = data?.rows ?? [];
  const [preview, setPreview] = useState(null);  // {client_name, ...} after a GET preview
  const [sending, setSending] = useState(null);  // client_name currently being sent
  const [toast,   setToast]   = useState(null);  // {kind:'ok'|'err', text}
  const [batchBusy, setBatchBusy] = useState(false);
  const [batchReport, setBatchReport] = useState(null);

  const batchPreview = async () => {
    setBatchBusy(true); setToast(null);
    try {
      const r = await api.post('/api/v1/billing/send-statements-batch', { as_of: asOf, dry_run: true });
      setBatchReport(r);
    } catch (e) { setToast({ kind: 'err', text: e.message }); }
    finally { setBatchBusy(false); }
  };

  const batchSend = async () => {
    const proceed = confirm(`Send statements to ${batchReport?.sent || 0} client${batchReport?.sent === 1 ? '' : 's'} now?`);
    if (!proceed) return;
    setBatchBusy(true); setToast(null);
    try {
      const r = await api.post('/api/v1/billing/send-statements-batch', { as_of: asOf });
      setBatchReport(r);
      setToast({ kind: 'ok', text: `Batch complete — sent ${r.sent}, skipped ${r.skipped}, failed ${r.failed}.` });
    } catch (e) { setToast({ kind: 'err', text: e.message }); }
    finally { setBatchBusy(false); }
  };

  const previewStatement = async (clientName) => {
    setSending(clientName); setToast(null);
    try {
      const data = await api.get(`/api/v1/billing/send-statement?client_name=${encodeURIComponent(clientName)}&as_of=${asOf}`);
      setPreview({ ...data, client_name: clientName });
    } catch (e) {
      setToast({ kind: 'err', text: e.message });
    } finally {
      setSending(null);
    }
  };

  const sendStatement = async (clientName) => {
    setSending(clientName); setToast(null);
    try {
      const res = await api.post('/api/v1/billing/send-statement', { client_name: clientName, as_of: asOf });
      setPreview(null);
      setToast({ kind: 'ok', text: `Statement emailed to ${res.sent_to}${res.cc?.length ? ` (cc ${res.cc.join(', ')})` : ''} — ${res.count} invoice${res.count === 1 ? '' : 's'}.` });
    } catch (e) {
      setToast({ kind: 'err', text: e.message });
    } finally {
      setSending(null);
    }
  };

  const totals = rows.reduce((acc, r) => ({
    cur: acc.cur + Number(r.bucket_current),
    b1:  acc.b1  + Number(r.bucket_1_30),
    b2:  acc.b2  + Number(r.bucket_31_60),
    b3:  acc.b3  + Number(r.bucket_61_90),
    b4:  acc.b4  + Number(r.bucket_91_plus),
    tot: acc.tot + Number(r.total_due),
  }), { cur: 0, b1: 0, b2: 0, b3: 0, b4: 0, tot: 0 });

  return (
    <section data-testid="billing-aging">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-4)', gap: 12, flexWrap: 'wrap' }}>
        <h3 style={{ margin: 0 }}>AR aging</h3>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <button
            className="btn btn--ghost" style={{ fontSize: 12 }}
            onClick={batchPreview} disabled={batchBusy}
            data-testid="billing-aging-batch-preview"
          >
            {batchBusy && !batchReport ? 'Loading…' : 'Email all past-due'}
          </button>
          <label style={{ fontSize: 13 }}>
            As of <input type="date" className="input" value={asOf} onChange={(e) => setAsOf(e.target.value)} data-testid="billing-aging-asof" style={{ marginLeft: 8 }} />
          </label>
        </div>
      </div>

      {loading && <p>Loading…</p>}
      {error && <p className="error" data-testid="billing-aging-error">Error: {error.message}</p>}
      {toast && (
        <p className={toast.kind === 'ok' ? 'success' : 'error'}
           data-testid={`billing-aging-statement-${toast.kind === 'ok' ? 'sent' : 'error'}`}
           style={{ background: toast.kind === 'ok' ? '#f0fdf4' : '#fef2f2', padding: 10, borderRadius: 6, fontSize: 13 }}>
          {toast.text}
        </p>
      )}

      <table className="data-table" data-testid="billing-aging-table">
        <thead>
          <tr>
            <th>Client</th>
            <th style={{textAlign:'right'}}>Current</th>
            <th style={{textAlign:'right'}}>1-30</th>
            <th style={{textAlign:'right'}}>31-60</th>
            <th style={{textAlign:'right'}}>61-90</th>
            <th style={{textAlign:'right'}}>91+</th>
            <th style={{textAlign:'right'}}>Total due</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 && !loading && <tr><td colSpan={8} className="empty" data-testid="billing-aging-empty">Nothing outstanding as of {asOf}.</td></tr>}
          {rows.map((r, i) => (
            <tr key={i} data-testid={`billing-aging-row-${i}`}>
              <td>{r.client_name}</td>
              <td style={{textAlign:'right'}}>{Number(r.bucket_current).toFixed(2)}</td>
              <td style={{textAlign:'right'}}>{Number(r.bucket_1_30).toFixed(2)}</td>
              <td style={{textAlign:'right'}}>{Number(r.bucket_31_60).toFixed(2)}</td>
              <td style={{textAlign:'right', color: Number(r.bucket_61_90) > 0 ? 'var(--cf-warning, #b45309)' : undefined}}>{Number(r.bucket_61_90).toFixed(2)}</td>
              <td style={{textAlign:'right', color: Number(r.bucket_91_plus) > 0 ? 'var(--cf-danger, #b91c1c)' : undefined, fontWeight: Number(r.bucket_91_plus) > 0 ? 600 : 400}}>{Number(r.bucket_91_plus).toFixed(2)}</td>
              <td style={{textAlign:'right', fontWeight: 600}}>{Number(r.total_due).toFixed(2)}</td>
              <td style={{textAlign:'right'}}>
                <button
                  className="btn btn--ghost" style={{ fontSize: 11 }}
                  onClick={() => previewStatement(r.client_name)}
                  disabled={sending === r.client_name}
                  data-testid={`billing-aging-email-statement-${i}`}
                >
                  {sending === r.client_name ? 'Loading…' : 'Email statement'}
                </button>
              </td>
            </tr>
          ))}
          {rows.length > 0 && (
            <tr style={{borderTop: '2px solid var(--cf-text, #111827)', fontWeight: 600}} data-testid="billing-aging-totals">
              <td>TOTAL</td>
              <td style={{textAlign:'right'}}>{totals.cur.toFixed(2)}</td>
              <td style={{textAlign:'right'}}>{totals.b1.toFixed(2)}</td>
              <td style={{textAlign:'right'}}>{totals.b2.toFixed(2)}</td>
              <td style={{textAlign:'right'}}>{totals.b3.toFixed(2)}</td>
              <td style={{textAlign:'right'}}>{totals.b4.toFixed(2)}</td>
              <td style={{textAlign:'right'}}>{totals.tot.toFixed(2)}</td>
              <td></td>
            </tr>
          )}
        </tbody>
      </table>

      {preview && (
        <StatementPreviewModal
          preview={preview}
          asOf={asOf}
          busy={sending === preview.client_name}
          onClose={() => setPreview(null)}
          onSend={() => sendStatement(preview.client_name)}
        />
      )}

      {batchReport && (
        <BatchReportModal
          report={batchReport}
          busy={batchBusy}
          onClose={() => setBatchReport(null)}
          onSend={batchSend}
          alreadySent={!!toast && toast.kind === 'ok'}
        />
      )}
    </section>
  );
}

function BatchReportModal({ report, busy, onClose, onSend, alreadySent }) {
  const isPreview = report.rows?.some((r) => r.status === 'would_send');
  return (
    <div
      style={{ position: 'fixed', inset: 0, background: 'rgba(15,18,28,0.55)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }}
      data-testid="billing-aging-batch-modal"
      onClick={(e) => e.target === e.currentTarget && !busy && onClose()}
    >
      <div style={{ background: 'var(--cf-surface, #fff)', borderRadius: 12, width: 'min(720px, 100%)', maxHeight: '90vh', overflow: 'auto', padding: 24 }}>
        <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'start', marginBottom: 16 }}>
          <div>
            <h3 style={{ margin: 0 }}>{isPreview ? 'Batch statement preview' : 'Batch statement results'} ({report.as_of})</h3>
            <p style={{ margin: '4px 0 0', fontSize: 12, color: 'var(--cf-text-secondary)' }}>
              {isPreview
                ? <>Will send <strong>{report.sent}</strong> · skip <strong>{report.skipped}</strong> (no contact).</>
                : <>Sent <strong>{report.sent}</strong> · skipped <strong>{report.skipped}</strong> · failed <strong>{report.failed}</strong>.</>}
            </p>
          </div>
          <button className="btn btn--ghost" onClick={onClose} disabled={busy}>×</button>
        </header>

        <table className="data-table" data-testid="billing-aging-batch-rows" style={{ fontSize: 12 }}>
          <thead><tr><th>Client</th><th>Status</th><th>Reason / recipient</th></tr></thead>
          <tbody>
            {report.rows.map((r, i) => (
              <tr key={i} data-testid={`billing-aging-batch-row-${i}`}>
                <td>{r.client_name}</td>
                <td>{r.status}</td>
                <td>{r.reason || r.to || r.error || ''}</td>
              </tr>
            ))}
          </tbody>
        </table>

        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
          <button className="btn btn--ghost" onClick={onClose} disabled={busy} data-testid="billing-aging-batch-close">Close</button>
          {isPreview && !alreadySent && (
            <button className="btn btn--primary" onClick={onSend} disabled={busy || report.sent === 0} data-testid="billing-aging-batch-send">
              {busy ? 'Sending…' : `Send to ${report.sent} client${report.sent === 1 ? '' : 's'}`}
            </button>
          )}
        </div>
      </div>
    </div>
  );
}

function StatementPreviewModal({ preview, asOf, busy, onClose, onSend }) {
  const to  = preview?.recipients?.to;
  const cc  = preview?.recipients?.cc || [];
  const inv = preview?.invoices    || [];
  const buckets = preview?.buckets || {};
  return (
    <div
      style={{ position: 'fixed', inset: 0, background: 'rgba(15,18,28,0.55)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }}
      data-testid="billing-aging-statement-modal"
      onClick={(e) => e.target === e.currentTarget && !busy && onClose()}
    >
      <div style={{ background: 'var(--cf-surface, #fff)', borderRadius: 12, width: 'min(720px, 100%)', maxHeight: '90vh', overflow: 'auto', padding: 24 }}>
        <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'start', marginBottom: 16 }}>
          <div>
            <h3 style={{ margin: 0 }}>Statement preview — {preview.client_name}</h3>
            <p style={{ margin: '4px 0 0', fontSize: 12, color: 'var(--cf-text-secondary)' }}>As of {asOf} · {inv.length} open invoice{inv.length === 1 ? '' : 's'} · total ${Number(buckets.total || 0).toFixed(2)}</p>
          </div>
          <button className="btn btn--ghost" onClick={onClose} disabled={busy}>×</button>
        </header>

        <div style={{ background: '#f8fafc', borderRadius: 6, padding: 12, marginBottom: 12, fontSize: 13 }}>
          {to ? (
            <>
              <div data-testid="billing-aging-statement-to"><strong>To:</strong> {to}</div>
              {cc.length > 0 && <div data-testid="billing-aging-statement-cc"><strong>CC:</strong> {cc.join(', ')}</div>}
            </>
          ) : (
            <div className="error" data-testid="billing-aging-statement-no-contact">
              No AR contact on file for this client. Add one in <strong>Client contacts</strong> first.
            </div>
          )}
        </div>

        <div style={{ border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 6, padding: 12, marginBottom: 12, maxHeight: 280, overflow: 'auto' }}
             data-testid="billing-aging-statement-html"
             dangerouslySetInnerHTML={{ __html: preview?.email?.html || '' }} />

        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8 }}>
          <a
            className="btn btn--ghost" style={{ fontSize: 12, textDecoration: 'none' }}
            href={`/api/v1/billing/statement-pdf?client_name=${encodeURIComponent(preview.client_name)}&as_of=${encodeURIComponent(asOf)}&disposition=attachment`}
            target="_blank" rel="noopener noreferrer"
            data-testid="billing-aging-statement-pdf"
          >
            Download PDF
          </a>
          <div style={{ display: 'flex', gap: 8 }}>
            <button className="btn btn--ghost" onClick={onClose} disabled={busy} data-testid="billing-aging-statement-cancel">Cancel</button>
            <button
              className="btn btn--primary"
              onClick={onSend}
              disabled={busy || !to}
              data-testid="billing-aging-statement-send"
            >
              {busy ? 'Sending…' : 'Send statement'}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

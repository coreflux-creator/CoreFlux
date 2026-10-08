import React, { useEffect, useRef, useState } from 'react';
import { useApi, api, getPinnedTenantId } from '../../../dashboard/src/lib/api';
import { fmtMoney } from '../../../dashboard/src/lib/format';
import { useAccountingEntityScope } from '../../../dashboard/src/lib/useAccountingEntityScope';
import AccountingEntitySelector from '../../../dashboard/src/components/AccountingEntitySelector';

export default function AgingTable() {
  const [asOf, setAsOf] = useState(new Date().toISOString().slice(0, 10));
  const scope = useAccountingEntityScope();
  const url = scope.ready
    ? `/api/v1/billing/aging?as_of=${asOf}${scope.apiQuery ? `&${scope.apiQuery}` : ''}`
    : null;
  const { data, loading, error } = useApi(url, { enabled: scope.ready });
  const current = data?.as_of === asOf && Number(data?.entity_id || 0) === Number(scope.entityId || 0);
  const rows = current ? (data.rows ?? []) : [];
  const busy = !scope.loaded || (scope.ready && (loading || (!current && !error)));
  const [preview, setPreview] = useState(null);  // {client_name, ...} after a GET preview
  const [sending, setSending] = useState(null);  // client_name currently being sent
  const [toast,   setToast]   = useState(null);  // {kind:'ok'|'err', text}
  const [batchBusy, setBatchBusy] = useState(false);
  const [batchReport, setBatchReport] = useState(null);
  const [downloading, setDownloading] = useState(false);
  const requestSequence = useRef(0);

  useEffect(() => {
    requestSequence.current += 1;
    setPreview(null);
    setBatchReport(null);
    setToast(null);
    setSending(null);
    setBatchBusy(false);
  }, [asOf, scope.scopeKey]);

  const batchPreview = async () => {
    if (!scope.entityId) return;
    const sequence = ++requestSequence.current;
    setBatchBusy(true); setToast(null);
    try {
      const r = await api.post('/api/v1/billing/send-statements-batch', { as_of: asOf, entity_id: scope.entityId, dry_run: true });
      if (sequence === requestSequence.current) setBatchReport(r);
    } catch (e) { if (sequence === requestSequence.current) setToast({ kind: 'err', text: e.message }); }
    finally { if (sequence === requestSequence.current) setBatchBusy(false); }
  };

  const batchSend = async () => {
    if (!batchReport?.entity_id) return;
    const proceed = confirm(`Send statements to ${batchReport?.sent || 0} client${batchReport?.sent === 1 ? '' : 's'} now?`);
    if (!proceed) return;
    const sequence = ++requestSequence.current;
    setBatchBusy(true); setToast(null);
    try {
      const r = await api.post('/api/v1/billing/send-statements-batch', { as_of: batchReport.as_of, entity_id: batchReport.entity_id });
      if (sequence === requestSequence.current) {
        setBatchReport(r);
        setToast({ kind: 'ok', text: `Batch complete — sent ${r.sent}, skipped ${r.skipped}, failed ${r.failed}.` });
      }
    } catch (e) { if (sequence === requestSequence.current) setToast({ kind: 'err', text: e.message }); }
    finally { if (sequence === requestSequence.current) setBatchBusy(false); }
  };

  const previewStatement = async (clientName) => {
    if (!scope.entityId) return;
    const sequence = ++requestSequence.current;
    setSending(clientName); setToast(null);
    try {
      const data = await api.get(`/api/v1/billing/send-statement?client_name=${encodeURIComponent(clientName)}&as_of=${asOf}&entity_id=${scope.entityId}`);
      if (sequence === requestSequence.current) setPreview({ ...data, client_name: clientName });
    } catch (e) {
      if (sequence === requestSequence.current) setToast({ kind: 'err', text: e.message });
    } finally {
      if (sequence === requestSequence.current) setSending(null);
    }
  };

  const sendStatement = async (statement) => {
    const clientName = statement.client_name;
    const sequence = ++requestSequence.current;
    setSending(clientName); setToast(null);
    try {
      const res = await api.post('/api/v1/billing/send-statement', { client_name: clientName, as_of: statement.as_of, entity_id: statement.entity_id });
      if (sequence === requestSequence.current) {
        setPreview(null);
        setToast({ kind: 'ok', text: `Statement emailed to ${res.sent_to}${res.cc?.length ? ` (cc ${res.cc.join(', ')})` : ''} — ${res.count} invoice${res.count === 1 ? '' : 's'}.` });
      }
    } catch (e) {
      if (sequence === requestSequence.current) setToast({ kind: 'err', text: e.message });
    } finally {
      if (sequence === requestSequence.current) setSending(null);
    }
  };

  const downloadStatement = async (statement) => {
    setDownloading(true); setToast(null);
    try {
      const params = new URLSearchParams({ client_name: statement.client_name,
        as_of: statement.as_of, entity_id: String(statement.entity_id), disposition: 'attachment' });
      const tenantId = getPinnedTenantId();
      const response = await fetch(`/api/v1/billing/statement-pdf?${params}`, {
        credentials: 'include',
        headers: tenantId ? { 'X-CoreFlux-Tenant-Id': tenantId } : {},
      });
      if (!response.ok) {
        const detail = await response.json().catch(() => null);
        throw new Error(detail?.error || 'Could not download the statement.');
      }
      const url = URL.createObjectURL(await response.blob());
      const link = document.createElement('a');
      link.href = url;
      link.download = `statement-${statement.entity_id}-${statement.as_of}.pdf`;
      document.body.append(link);
      link.click();
      link.remove();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (e) {
      setToast({ kind: 'err', text: e.message });
    } finally {
      setDownloading(false);
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
  const pastDueTotal = totals.b1 + totals.b2 + totals.b3 + totals.b4;

  return (
    <section className="report-page aging-report" data-testid="billing-aging">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-4)', gap: 12, flexWrap: 'wrap' }}>
        <div>
          <h3 style={{ margin: 0 }}>Accounts receivable aging</h3>
          <p className="report-page__meta">Posted customer balances as of {asOf}</p>
        </div>
        <div style={{ display: 'flex', alignItems: 'end', gap: 12, flexWrap: 'wrap' }}>
          <button
            className="btn btn--ghost" style={{ fontSize: 12 }}
            onClick={batchPreview} disabled={!scope.entityId || batchBusy || busy || pastDueTotal <= 0}
            title={!scope.entityId ? 'Select one legal entity to email statements' : pastDueTotal > 0 ? 'Preview recipients before sending statements' : 'No past-due balances to email'}
            data-testid="billing-aging-batch-preview"
          >
            {batchBusy && !batchReport ? 'Loading…' : 'Email all past-due'}
          </button>
          <AccountingEntitySelector scope={scope} />
          <label style={{ fontSize: 13 }}>
            As of <input type="date" className="input" value={asOf} onChange={(e) => setAsOf(e.target.value)} data-testid="billing-aging-asof" style={{ marginLeft: 8 }} />
          </label>
        </div>
      </div>

      {scope.label && <p className="report-page__meta">{scope.label}</p>}
      {scope.allEntities && <p className="report-page__meta">Select one legal entity to preview, download or email customer statements.</p>}
      {busy && <p>Loading…</p>}
      {(scope.error || error) && <p className="error" data-testid="billing-aging-error">Error: {scope.error || error.message}</p>}
      {toast && (
        <p className={toast.kind === 'ok' ? 'success' : 'error'}
           data-testid={`billing-aging-statement-${toast.kind === 'ok' ? 'sent' : 'error'}`}
           style={{ background: toast.kind === 'ok' ? '#f0fdf4' : '#fef2f2', padding: 10, borderRadius: 6, fontSize: 13 }}>
          {toast.text}
        </p>
      )}

      {!busy && !scope.error && !error && (
        <div className="aging-summary" data-testid="billing-aging-summary">
          <AgingSummary label="Total receivables" value={totals.tot} tone="blue" />
          <AgingSummary label="Current" value={totals.cur} tone="teal" />
          <AgingSummary label="Past due" value={pastDueTotal} tone="amber" />
          <AgingSummary label="Over 90 days" value={totals.b4} tone="red" />
        </div>
      )}

      <div className="data-table-wrap aging-table-wrap">
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
          {rows.length === 0 && !busy && !scope.error && !error && <tr><td colSpan={8} className="empty" data-testid="billing-aging-empty">Nothing outstanding as of {asOf}.</td></tr>}
          {rows.map((r, i) => (
            <tr key={i} data-testid={`billing-aging-row-${i}`}>
              <td>{r.client_name}</td>
              <td style={{textAlign:'right'}}>{fmtMoney(r.bucket_current)}</td>
              <td style={{textAlign:'right'}}>{fmtMoney(r.bucket_1_30)}</td>
              <td style={{textAlign:'right'}}>{fmtMoney(r.bucket_31_60)}</td>
              <td style={{textAlign:'right', color: Number(r.bucket_61_90) > 0 ? 'var(--cf-warning, #b45309)' : undefined}}>{fmtMoney(r.bucket_61_90)}</td>
              <td style={{textAlign:'right', color: Number(r.bucket_91_plus) > 0 ? 'var(--cf-danger, #b91c1c)' : undefined, fontWeight: Number(r.bucket_91_plus) > 0 ? 600 : 400}}>{fmtMoney(r.bucket_91_plus)}</td>
              <td style={{textAlign:'right', fontWeight: 600}}>{fmtMoney(r.total_due)}</td>
              <td style={{textAlign:'right'}}>
                <button
                  className="btn btn--ghost" style={{ fontSize: 11 }}
                  onClick={() => previewStatement(r.client_name)}
                  disabled={!scope.entityId || sending === r.client_name}
                  title={!scope.entityId ? 'Select one legal entity to prepare a statement' : 'Preview statement and recipients'}
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
              <td style={{textAlign:'right'}}>{fmtMoney(totals.cur)}</td>
              <td style={{textAlign:'right'}}>{fmtMoney(totals.b1)}</td>
              <td style={{textAlign:'right'}}>{fmtMoney(totals.b2)}</td>
              <td style={{textAlign:'right'}}>{fmtMoney(totals.b3)}</td>
              <td style={{textAlign:'right'}}>{fmtMoney(totals.b4)}</td>
              <td style={{textAlign:'right'}}>{fmtMoney(totals.tot)}</td>
              <td></td>
            </tr>
          )}
        </tbody>
      </table>
      </div>

      {preview && (
        <StatementPreviewModal
          preview={preview}
          asOf={preview.as_of}
          busy={sending === preview.client_name}
          downloading={downloading}
          onClose={() => setPreview(null)}
          onSend={() => sendStatement(preview)}
          onDownload={() => downloadStatement(preview)}
          actionError={toast?.kind === 'err' ? toast.text : null}
        />
      )}

      {batchReport && (
        <BatchReportModal
          report={batchReport}
          busy={batchBusy}
          onClose={() => setBatchReport(null)}
          onSend={batchSend}
          alreadySent={!!toast && toast.kind === 'ok'}
          actionError={toast?.kind === 'err' ? toast.text : null}
        />
      )}
    </section>
  );
}

function AgingSummary({ label, value, tone }) {
  return (
    <div className={`aging-summary__item aging-summary__item--${tone}`}>
      <span>{label}</span>
      <strong>{fmtMoney(value)}</strong>
    </div>
  );
}

function BatchReportModal({ report, busy, onClose, onSend, alreadySent, actionError }) {
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
            <p style={{ margin: '4px 0 0', fontSize: 12 }}>{report.entity_name}</p>
            <p style={{ margin: '4px 0 0', fontSize: 12, color: 'var(--cf-text-secondary)' }}>
              {isPreview
                ? <>Will send <strong>{report.sent}</strong> · skip <strong>{report.skipped}</strong> (no contact).</>
                : <>Sent <strong>{report.sent}</strong> · skipped <strong>{report.skipped}</strong> · failed <strong>{report.failed}</strong>.</>}
            </p>
          </div>
          <button className="btn btn--ghost" onClick={onClose} disabled={busy}>×</button>
        </header>
        {actionError && <p className="error" role="alert">{actionError}</p>}

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

function StatementPreviewModal({ preview, asOf, busy, downloading, onClose, onSend, onDownload, actionError }) {
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
            <p style={{ margin: '4px 0 0', fontSize: 12 }}>{preview.entity_name}</p>
            <p style={{ margin: '4px 0 0', fontSize: 12, color: 'var(--cf-text-secondary)' }}>As of {asOf} · {inv.length} open invoice{inv.length === 1 ? '' : 's'} · total ${Number(buckets.total || 0).toFixed(2)}</p>
          </div>
          <button className="btn btn--ghost" onClick={onClose} disabled={busy}>×</button>
        </header>
        {actionError && <p className="error" role="alert">{actionError}</p>}

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
          <button type="button"
            className="btn btn--ghost" style={{ fontSize: 12 }}
            onClick={onDownload} disabled={downloading || busy}
            data-testid="billing-aging-statement-pdf"
          >
            {downloading ? 'Downloading…' : 'Download PDF'}
          </button>
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

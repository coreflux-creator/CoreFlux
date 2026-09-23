import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';
import { useBulkSelection } from '../../../dashboard/src/lib/useBulkSelection';
import { useActiveEntity } from '../../../dashboard/src/lib/useActiveEntity';
import ExportTemplatePicker from '../../../dashboard/src/components/ExportTemplatePicker';
import IdBadge from '../../../dashboard/src/components/IdBadge';
import VendorTypeahead from './VendorTypeahead';
import { ChevronLeft, ChevronRight, Landmark, Search, Send } from 'lucide-react';

const statusLabel = (value) => String(value || '—').replaceAll('_', ' ').replace(/\b\w/g, char => char.toUpperCase());
const METHOD_LABELS = {
  ach: 'Bank transfer (ACH)', wire: 'Wire', check: 'Check', card: 'Card', cash: 'Cash',
  plaid: 'Online bank payment', mercury: 'Mercury', other: 'Other',
};
const RAIL_LABELS = { plaid_transfer: 'Online bank payment', nacha: 'Bank payment file', mercury: 'Mercury', purepay: 'Pure//Pay' };

export default function PaymentsList() {
  const location = useLocation();
  const { activeEntityId, activeEntity } = useActiveEntity();
  const [searchInput, setSearchInput] = useState('');
  const [query, setQuery] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(50);
  const paymentParams = new URLSearchParams({ page: String(page), per_page: String(perPage) });
  if (activeEntityId) paymentParams.set('entity_id', String(activeEntityId));
  if (query) paymentParams.set('q', query);
  const paymentsPath = `/modules/ap/api/payments.php?${paymentParams.toString()}`;
  const { data, loading, error, reload } = useApi(paymentsPath);
  const apSettings = useApi('/modules/ap/api/settings.php');
  const defaultRail = apSettings.data?.settings?.disbursement_rail || 'nacha';
  const rows = useMemo(() => data?.rows ?? [], [data?.rows]);
  const total = Number(data?.total ?? 0);
  const totalPages = Math.max(1, Math.ceil(total / perPage));
  const createdRun = location.state?.paymentRun || null;
  const plaidEnabled = !!data?.plaid_enabled;
  const plaidTransferLinked = !!data?.plaid_transfer_linked;
  const mercuryConnected = !!data?.mercury_connected;
  const purepayConnected = !!data?.purepay_connected;
  const [showRecord, setShowRecord] = useState(false);
  const [showAllocate, setShowAllocate] = useState(null); // payment row
  const [batching, setBatching]   = useState(false);
  const [batchErr, setBatchErr]   = useState(null);
  const [batchInfo, setBatchInfo] = useState(null);
  const [bulkResult, setBulkResult] = useState(null);
  const [releaseRow, setReleaseRow] = useState({});

  const sel = useBulkSelection(rows.map(r => r.id));
  const autoSelectedRunRef = useRef('');
  const clearSelection = sel.clear;
  const selectMany = sel.selectMany;

  useEffect(() => {
    const timer = setTimeout(() => {
      setQuery(searchInput.trim());
      setPage(1);
    }, 300);
    return () => clearTimeout(timer);
  }, [searchInput]);

  useEffect(() => {
    clearSelection();
  }, [query, page, perPage, activeEntityId, clearSelection]);

  useEffect(() => {
    if (page > totalPages) setPage(totalPages);
  }, [page, totalPages]);

  useEffect(() => {
    const createdIds = (createdRun?.payments_created || []).map((payment) => Number(payment.payment_id));
    const key = createdIds.join(',');
    if (!key || autoSelectedRunRef.current === key || rows.length === 0) return;
    const visible = createdIds.filter((id) => rows.some((row) => Number(row.id) === id));
    if (visible.length) {
      selectMany(visible);
      autoSelectedRunRef.current = key;
    }
  }, [createdRun, rows, selectMany]);

  // Per-row Plaid Transfer origination state (id → 'busy' | { error } | { ok })
  const [plaidRow, setPlaidRow] = useState({});

  const sendViaPlaid = async (p) => {
    setPlaidRow(s => ({ ...s, [p.id]: 'busy' }));
    try {
      const res = await api.post(`/modules/ap/api/payments.php?action=originate&id=${p.id}&rail=plaid_transfer`, {});
      setPlaidRow(s => ({ ...s, [p.id]: { ok: true, ref: res.rail_external_ref || res.batch_id } }));
      reload();
    } catch (e) {
      setPlaidRow(s => ({ ...s, [p.id]: { error: e.message || String(e) } }));
    }
  };
  const [purepayRow, setPurepayRow] = useState({});
  const purepayEligible = (p) => purepayConnected && ['ach', 'plaid'].includes(p.method)
    && String(p.currency || 'USD').toUpperCase() === 'USD'
    && p.status === 'sent' && !p.rail_external_ref;
  const sendViaPurepay = async (p) => {
    if (!confirm(`Pay ${p.vendor_name} ${Number(p.amount).toFixed(2)} ${p.currency} through Pure//Pay now? This releases money from your Pure//Pay wallet.`)) return;
    setPurepayRow(s => ({ ...s, [p.id]: 'busy' }));
    try {
      const res = await api.post(`/modules/ap/api/payments.php?action=originate&id=${p.id}&rail=purepay`, {});
      setPurepayRow(s => ({ ...s, [p.id]: { ok: true, ref: res.batch_id } }));
      await reload();
    } catch (e) {
      setPurepayRow(s => ({ ...s, [p.id]: { error: e.message || String(e) } }));
    }
  };
  // Eligibility for the per-row "Send via Plaid" button — must be a sent
  // payment with method=plaid that hasn't been originated yet, and the
  // tenant must have linked a funding source.
  const plaidEligible = (p) =>
    plaidTransferLinked &&
    p.method === 'plaid' &&
    p.status === 'sent' &&
    !p.rail_external_ref;

  // Eligibility for "Send via Mercury" — sent + not yet attached to any rail
  // + Mercury is connected. Recipient mapping is validated server-side.
  const mercuryEligible = (p) =>
    mercuryConnected &&
    p.status === 'sent' &&
    !p.rail_external_ref;

  const [mercuryRow, setMercuryRow] = useState({});
  const sendViaMercury = async (p) => {
    setMercuryRow(s => ({ ...s, [p.id]: 'busy' }));
    try {
      const res = await api.post(`/modules/ap/api/payments.php?action=send_via_mercury&id=${p.id}`, {});
      setMercuryRow(s => ({ ...s, [p.id]: { ok: true, ref: `pi:${res.instruction_id}` } }));
      reload();
    } catch (e) {
      setMercuryRow(s => ({ ...s, [p.id]: { error: e.message || String(e) } }));
    }
  };

  // Eligibility for NACHA batch: ach|plaid method, status draft|queued|sent (without rail_external_ref).
  const isOriginatable = (p) =>
    ['ach','plaid'].includes(p.method) &&
    Number(p.unallocated_amount) <= 0.005 &&
    (['draft','queued'].includes(p.status) || (p.status === 'sent' && !p.rail_external_ref));

  const eligibleSelected = rows.filter(p => sel.has(p.id) && isOriginatable(p)
    && (defaultRail !== 'purepay' || String(p.currency || 'USD').toUpperCase() === 'USD'));
  const releasableSelected = rows.filter(p => (
    sel.has(p.id) && ['draft', 'queued'].includes(p.status) && Number(p.unallocated_amount) <= 0.005
  ));
  const mercurySelected = rows.filter(p => (
    sel.has(p.id) && p.method === 'mercury' && p.status === 'sent' && !p.rail_external_ref
  ));
  const purepaySelected = rows.filter(p => sel.has(p.id) && purepayEligible(p));

  const releasePayment = async (payment) => {
    if (!confirm(`Release payment #${payment.id} for ${payment.vendor_name} (${Number(payment.amount).toFixed(2)} ${payment.currency})?`)) return;
    setReleaseRow((current) => ({ ...current, [payment.id]: 'busy' }));
    try {
      await api.post(`/modules/ap/api/payments.php?action=send&id=${payment.id}`, {});
      setReleaseRow((current) => ({ ...current, [payment.id]: { ok: true } }));
      await reload();
    } catch (e) {
      setReleaseRow((current) => ({ ...current, [payment.id]: { error: e?.message || String(e) } }));
    }
  };

  const releaseSelected = async () => {
    if (!releasableSelected.length) return;
    if (!confirm(
      `Release ${releasableSelected.length} selected payment${releasableSelected.length === 1 ? '' : 's'}?\n\n` +
      'This records the authorized disbursement. Two-eye and pay-when-paid controls still apply to every payment.'
    )) return;
    setBatching(true); setBatchErr(null); setBulkResult(null);
    const failures = [];
    let succeeded = 0;
    try {
      for (const payment of releasableSelected) {
        try {
          await api.post(`/modules/ap/api/payments.php?action=send&id=${payment.id}`, {});
          succeeded += 1;
        } catch (e) {
          failures.push({ id: payment.id, reason: e?.message || String(e) });
        }
      }
      sel.selectMany(failures.map((failure) => failure.id));
      setBulkResult({ label: 'released', succeeded, failures });
      await reload();
    } finally {
      setBatching(false);
    }
  };

  const queueMercurySelected = async () => {
    if (!mercurySelected.length) return;
    if (!confirm(`Create ${mercurySelected.length} Mercury payment instruction${mercurySelected.length === 1 ? '' : 's'}? Treasury approval is still required before money moves.`)) return;
    setBatching(true); setBatchErr(null); setBulkResult(null);
    const failures = [];
    let succeeded = 0;
    try {
      for (const payment of mercurySelected) {
        try {
          await api.post(`/modules/ap/api/payments.php?action=send_via_mercury&id=${payment.id}`, {});
          succeeded += 1;
        } catch (e) {
          failures.push({ id: payment.id, reason: e?.message || String(e) });
        }
      }
      sel.selectMany(failures.map((failure) => failure.id));
      setBulkResult({ label: 'queued in Mercury', succeeded, failures });
      await reload();
    } finally {
      setBatching(false);
    }
  };

  const exportSelected = () => {
    if (!sel.size) return;
    const a = document.createElement('a');
    a.href = `/modules/ap/api/export.php?type=payments&ids=${sel.ids.join(',')}`;
    a.rel  = 'noopener';
    a.click();
  };

  const originateBatch = async (railOverride = null) => {
    const selected = railOverride === 'purepay' ? purepaySelected : eligibleSelected;
    if (!selected.length) return;
    let selectedRail = railOverride;
    if (!selectedRail) {
      try {
        const current = await api.get('/modules/ap/api/settings.php');
        selectedRail = current.settings?.disbursement_rail || 'nacha';
      } catch (e) { setBatchErr(e); return; }
    }
    const totalAmount = selected.reduce((sum, p) => sum + Number(p.amount || 0), 0).toFixed(2);
    const prompt = selectedRail === 'purepay'
      ? `Pay ${selected.length} selected vendor payment${selected.length === 1 ? '' : 's'} totaling $${totalAmount} through Pure//Pay now? This releases money from your Pure//Pay wallet. Each payment is checked against the AP release policy.`
      : `Prepare a ${RAIL_LABELS[selectedRail] || selectedRail} batch for ${selected.length} selected payment${selected.length === 1 ? '' : 's'}? CoreFlux will check each payment before dispatch.`;
    if (!confirm(prompt)) return;
    setBatching(true); setBatchErr(null); setBatchInfo(null);
    try {
      const res = await api.post('/modules/ap/api/payments.php?action=originate_batch', {
        ids: selected.map(p => p.id), rail: selectedRail,
      });
      // Trigger NACHA file download from the base64 payload.
      if (res?.nacha_file_b64) {
        const bin = atob(res.nacha_file_b64);
        const arr = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
        const blob = new Blob([arr], { type: 'application/octet-stream' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href = url; a.download = res.nacha_filename || 'ap-batch.ach'; a.click();
        URL.revokeObjectURL(url);
      }
      setBatchInfo(res);
      sel.clear();
      if (res.failed_items?.length) sel.selectMany(res.failed_items.map(item => item.payment_id));
      reload();
    } catch (e) { setBatchErr(e); }
    finally { setBatching(false); }
  };

  return (
    <section data-testid="ap-payments-list">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-4)', flexWrap: 'wrap', gap: 8 }}>
        <div>
          <h3 style={{ margin: 0, fontSize: 16 }}>Vendor payments</h3>
          <p style={{ margin: '4px 0 0', fontSize: 12, color: 'var(--cf-text-secondary)' }}>
            {plaidEnabled
              ? (plaidTransferLinked
                  ? <><span className="badge badge--success">Online payments ready</span> — approved payments can be sent from CoreFlux.</>
                  : (
                      <span data-testid="ap-plaid-link-cta">
                        <span className="badge" style={{ background: 'var(--cf-amber-bg, #fef3c7)', color: 'var(--cf-amber, #92400e)', padding: '2px 8px', borderRadius: 4 }}>
                          Payment account needed
                        </span>
                        {' '}
                        <Link to="/admin/integrations/plaid" data-testid="ap-plaid-link-cta-link" style={{ marginLeft: 6 }}>
                          Connect payment account →
                        </Link>
                      </span>
                    )
                )
              : (
                  <span data-testid="ap-plaid-disabled-notice">
                    Bank payment automation is off. You can still record manual payments, or{' '}
                    <Link to="/modules/ap/settings">connect a payment provider in Settings</Link>.
                  </span>
                )}
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <Link to="csv_import" className="btn" data-testid="ap-payments-import-csv">Import CSV</Link>
          <a className="btn" href={`/api/v1/ap/payments-csv-export${activeEntityId ? `?entity_id=${activeEntityId}` : ''}`} data-testid="ap-payments-export-all-csv">Export all (CSV)</a>
          <button className="btn btn--primary" onClick={() => setShowRecord(true)} data-testid="ap-record-payment">Record payment</button>
        </div>
      </div>

      {activeEntity && (
        <div className="entity-scope-note" data-testid="ap-payments-entity-scope">
          Showing <strong>{activeEntity.code}</strong>. Use the header to change entities.
        </div>
      )}

      {createdRun && (
        <div className="success" data-testid="ap-payment-run-created" style={{ marginBottom: 12 }}>
          Created and selected {createdRun.payments_created?.length || 0} draft payment{createdRun.payments_created?.length === 1 ? '' : 's'} on the {createdRun.rail} rail. A different approver can release them together below.
        </div>
      )}

      <div className="operational-toolbar" style={{ marginBottom: 12 }}>
        <div className="operational-toolbar__search">
          <Search size={16} aria-hidden="true" />
          <input
            type="search"
            className="input"
            value={searchInput}
            onChange={(event) => setSearchInput(event.target.value)}
            placeholder="Search vendor, reference, method or status"
            data-testid="ap-payments-search"
          />
        </div>
        <span className="operational-toolbar__count">{rows.length} on this page · {total} total</span>
      </div>

      {/* Bulk-actions toolbar — appears whenever any row is checked */}
      {sel.size > 0 && (
        <div data-testid="ap-payments-bulk-bar" style={bulkBar}>
          <span><strong>{sel.size}</strong> selected</span>
          <button className="btn btn--primary" onClick={releaseSelected} disabled={batching || !releasableSelected.length} data-testid="ap-payments-release-selected">
            <Send size={15} aria-hidden="true" /> Release ({releasableSelected.length})
          </button>
          <button className="btn btn--primary" onClick={() => originateBatch()} disabled={batching || apSettings.loading || (defaultRail === 'purepay' && !purepayConnected) || !eligibleSelected.length} data-testid="ap-payments-originate-selected">
            <Landmark size={15} aria-hidden="true" /> {defaultRail === 'purepay' ? 'Pay via Pure//Pay' : 'Prepare bank batch'} ({eligibleSelected.length})
          </button>
          {purepayConnected && defaultRail !== 'purepay' && (
            <button className="btn btn--ghost" onClick={() => originateBatch('purepay')} disabled={batching || !purepaySelected.length} data-testid="ap-payments-purepay-selected">
              <Landmark size={15} aria-hidden="true" /> Pay via Pure//Pay ({purepaySelected.length})
            </button>
          )}
          {mercuryConnected && (
            <button className="btn btn--ghost" onClick={queueMercurySelected} disabled={batching || !mercurySelected.length} data-testid="ap-payments-mercury-selected">
              <Landmark size={15} aria-hidden="true" /> Queue Mercury ({mercurySelected.length})
            </button>
          )}
          <ExportTemplatePicker
            dataset="ap_payments"
            buildHref={(tplId) => `/api/v1/ap/payments/export-template?template_id=${tplId}&ids=${sel.ids.join(',')}`}
            disabled={!sel.size}
            label="Export via template"
            testid="ap-payments-export-template"
          />
          <button className="btn btn--ghost" onClick={exportSelected} data-testid="ap-payments-export-selected">
            Raw CSV
          </button>
          <button className="btn btn--ghost" onClick={sel.clear} data-testid="ap-payments-clear-selection">Clear</button>
        </div>
      )}
      {batchErr && <p className="error" data-testid="ap-payments-batch-error">Batch failed: {batchErr.message}</p>}
      {bulkResult && (
        <p className={bulkResult.failures.length ? 'error' : 'success'} data-testid="ap-payments-bulk-result">
          {bulkResult.succeeded} {bulkResult.label}.
          {bulkResult.failures.length > 0 && <> {bulkResult.failures.length} need attention: {bulkResult.failures.map((failure) => `Payment ${failure.id}: ${failure.reason}`).join('; ')}</>}
        </p>
      )}
      {batchInfo && (
        <p data-testid="ap-payments-batch-success" style={{ background: '#ecfdf5', color: '#065f46', padding: 8, borderRadius: 6, fontSize: 13 }}>
          {batchInfo.originated_count ?? batchInfo.item_count - (batchInfo.failed_items?.length || 0)} of {batchInfo.item_count} dispatched via {RAIL_LABELS[batchInfo.rail] || batchInfo.rail} · total ${Number(batchInfo.amount_total).toFixed(2)}
          {batchInfo.nacha_filename && <> · file: <code>{batchInfo.nacha_filename}</code></>}
          {!!batchInfo.failed_items?.length && <> · {batchInfo.failed_items.length} failed: {batchInfo.failed_items.map(item => `#${item.payment_id}: ${item.error}`).join('; ')}</>}
        </p>
      )}

      {loading && <p>Loading…</p>}
      {error && <p className="error">Error: {error.message}</p>}

      <table className="data-table" data-testid="ap-payments-table">
        <thead>
          <tr>
            <th style={{ width: 32 }}>
              <input
                type="checkbox"
                checked={sel.allSelected}
                ref={el => { if (el) el.indeterminate = sel.someSelected; }}
                onChange={sel.toggleAll}
                data-testid="ap-payments-select-all"
                disabled={!rows.length}
              />
            </th>
            <th>#</th><th>Vendor</th><th>Date</th><th>Method</th><th>Ref</th>
            <th style={{textAlign:'right'}}>Amount</th>
            <th style={{textAlign:'right'}}>To allocate</th>
            <th>Status</th><th>Rail</th><th></th>
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 && !loading && <tr><td colSpan={11} className="empty" data-testid="ap-payments-empty">No payments yet.</td></tr>}
          {rows.map(p => (
            <tr key={p.id} data-testid={`ap-payment-row-${p.id}`} style={sel.has(p.id) ? { background: 'var(--cf-surface-alt, #f9fafb)' } : null}>
              <td>
                <input
                  type="checkbox"
                  checked={sel.has(p.id)}
                  onChange={() => sel.toggle(p.id)}
                  data-testid={`ap-payment-select-${p.id}`}
                />
              </td>
              <td><IdBadge id={p.id} prefix="PAY" /></td>
              <td>{p.vendor_name}</td>
              <td>{p.pay_date}</td>
              <td>{METHOD_LABELS[p.method] || statusLabel(p.method)}</td>
              <td>{p.reference || '—'}</td>
              <td style={{textAlign:'right'}}>{Number(p.amount).toFixed(2)} {p.currency}</td>
              <td style={{textAlign:'right'}}>{Number(p.unallocated_amount).toFixed(2)}</td>
              <td><span className={`badge badge--${p.status}`}>{statusLabel(p.status)}</span></td>
              <td style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>
                {p.disbursement_rail
                  ? <><span className="badge">{RAIL_LABELS[p.disbursement_rail] || statusLabel(p.disbursement_rail)}</span> {p.rail_status ? statusLabel(p.rail_status) : ''}</>
                  : '—'}
              </td>
              <td>
                {['draft', 'queued'].includes(p.status) && (
                  <button
                    className="btn btn--ghost"
                    onClick={() => releasePayment(p)}
                    disabled={releaseRow[p.id] === 'busy' || Number(p.unallocated_amount) > 0.005}
                    data-testid={`ap-payment-release-${p.id}`}
                    title={Number(p.unallocated_amount) > 0.005 ? 'Allocate the full payment before release' : 'Release this payment after all controls pass'}
                  >
                    {releaseRow[p.id] === 'busy' ? 'Releasing…' : Number(p.unallocated_amount) > 0.005 ? 'Allocate first' : 'Release'}
                  </button>
                )}
                {releaseRow[p.id]?.error && (
                  <div className="error" data-testid={`ap-payment-release-error-${p.id}`} style={{ fontSize: 11, marginTop: 4 }}>{releaseRow[p.id].error}</div>
                )}
                {Number(p.unallocated_amount) > 0 && (
                  <button className="btn btn--ghost" data-testid={`ap-allocate-open-${p.id}`} onClick={() => setShowAllocate(p)}>Allocate</button>
                )}
                {plaidEligible(p) && (
                  <button
                    className="btn btn--primary"
                    style={{ marginLeft: 6 }}
                    onClick={() => sendViaPlaid(p)}
                    disabled={plaidRow[p.id] === 'busy'}
                    data-testid={`ap-send-via-plaid-${p.id}`}
                    title="Send this approved payment online"
                  >
                    {plaidRow[p.id] === 'busy' ? 'Sending…' : 'Send online'}
                  </button>
                )}
                {plaidRow[p.id] && plaidRow[p.id].error && (
                  <div data-testid={`ap-send-via-plaid-error-${p.id}`} style={{ fontSize: 11, color: 'var(--cf-red, #b91c1c)', marginTop: 4 }}>
                    {plaidRow[p.id].error}
                  </div>
                )}
                {plaidRow[p.id] && plaidRow[p.id].ok && (
                  <div data-testid={`ap-send-via-plaid-ok-${p.id}`} style={{ fontSize: 11, color: 'var(--cf-green, #047857)', marginTop: 4 }}>
                    Sent ({plaidRow[p.id].ref})
                  </div>
                )}
                {mercuryEligible(p) && (
                  <button
                    className="btn btn--primary"
                    style={{ marginLeft: 6 }}
                    onClick={() => sendViaMercury(p)}
                    disabled={mercuryRow[p.id] === 'busy'}
                    data-testid={`ap-send-via-mercury-${p.id}`}
                    title="Create a Mercury payment_instruction (Draft) — treasury ops approves before money moves"
                  >
                    {mercuryRow[p.id] === 'busy' ? 'Sending…' : 'Send via Mercury'}
                  </button>
                )}
                {mercuryRow[p.id] && mercuryRow[p.id].error && (
                  <div data-testid={`ap-send-via-mercury-error-${p.id}`} style={{ fontSize: 11, color: 'var(--cf-red, #b91c1c)', marginTop: 4 }}>
                    {mercuryRow[p.id].error}
                  </div>
                )}
                {mercuryRow[p.id] && mercuryRow[p.id].ok && (
                  <div data-testid={`ap-send-via-mercury-ok-${p.id}`} style={{ fontSize: 11, color: 'var(--cf-green, #047857)', marginTop: 4 }}>
                    ✓ Queued as {mercuryRow[p.id].ref}
                  </div>
                )}
                {purepayEligible(p) && (
                  <button className="btn btn--primary" style={{ marginLeft: 6 }}
                    onClick={() => sendViaPurepay(p)} disabled={purepayRow[p.id] === 'busy'}
                    data-testid={`ap-send-via-purepay-${p.id}`} title="Release this approved AP payment through Pure//Pay">
                    {purepayRow[p.id] === 'busy' ? 'Paying…' : 'Pay via Pure//Pay'}
                  </button>
                )}
                {purepayRow[p.id]?.error && <div className="error" data-testid={`ap-send-via-purepay-error-${p.id}`} style={{ fontSize: 11, marginTop: 4 }}>{purepayRow[p.id].error}</div>}
                {purepayRow[p.id]?.ok && <div className="success" data-testid={`ap-send-via-purepay-ok-${p.id}`} style={{ fontSize: 11, marginTop: 4 }}>Submitted via Pure//Pay ({purepayRow[p.id].ref})</div>}
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      {total > 0 && (
        <footer className="operational-footer" data-testid="ap-payments-pagination">
          <span>Showing {(page - 1) * perPage + 1}–{Math.min(page * perPage, total)} of {total} payments</span>
          <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
            <label htmlFor="ap-payments-per-page">Rows</label>
            <select id="ap-payments-per-page" className="input" value={perPage} onChange={(event) => { setPerPage(Number(event.target.value)); setPage(1); }} style={{ width: 72, paddingBlock: 4 }}>
              {[25, 50, 100, 200].map((size) => <option key={size} value={size}>{size}</option>)}
            </select>
            <button className="btn btn--ghost btn--sm" onClick={() => setPage((current) => Math.max(1, current - 1))} disabled={page <= 1 || loading} aria-label="Previous payments page"><ChevronLeft size={16} /></button>
            <span>Page {page} of {totalPages}</span>
            <button className="btn btn--ghost btn--sm" onClick={() => setPage((current) => Math.min(totalPages, current + 1))} disabled={page >= totalPages || loading} aria-label="Next payments page"><ChevronRight size={16} /></button>
          </span>
        </footer>
      )}

      {showRecord && <RecordPaymentModal onClose={() => setShowRecord(false)} onCreated={() => { setShowRecord(false); reload(); }} plaidEnabled={plaidEnabled} mercuryEnabled={mercuryConnected} entityId={activeEntityId} />}
      {showAllocate && <AllocateModal payment={showAllocate} onClose={() => setShowAllocate(null)} onDone={() => { setShowAllocate(null); reload(); }} />}
    </section>
  );
}

const bulkBar = {
  display: 'flex', alignItems: 'center', gap: 12, padding: '10px 14px',
  background: 'var(--cf-surface-alt, #f3f4f6)', borderRadius: 8,
  marginBottom: 12, flexWrap: 'wrap',
};

function RecordPaymentModal({ onClose, onCreated, plaidEnabled, mercuryEnabled, entityId = null }) {
  const [form, setForm] = useState({
    vendor_name: '', pay_date: new Date().toISOString().slice(0, 10), method: 'ach',
    amount: '', reference: '', currency: 'USD', auto_allocate: true, notes: '',
  });
  const [vendor, setVendor] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const submit = async () => {
    setBusy(true); setError(null);
    try {
      await api.post('/modules/ap/api/payments.php', { ...form, amount: Number(form.amount), entity_id: entityId || null });
      onCreated?.();
    } catch (e) { setError(e); } finally { setBusy(false); }
  };
  return (
    <div data-testid="ap-record-payment-modal" style={modalOverlay} onClick={(e) => e.target === e.currentTarget && !busy && onClose?.()}>
      <div style={modalBox}>
        <header style={modalHeader}><h3 style={{ margin: 0 }}>Record payment</h3></header>
        <div style={{ padding: 20, display: 'grid', gap: 12 }}>
          <Field label="Vendor">
            <VendorTypeahead
              value={vendor}
              onChange={(next) => {
                setVendor(next);
                setForm((current) => ({ ...current, vendor_name: next?.name || '' }));
              }}
              placeholder="Search the vendor master"
              testId="ap-pay-vendor"
              autoFocus
            />
          </Field>
          <Field label="Pay date"><input className="input" type="date" value={form.pay_date} onChange={(e) => setForm({ ...form, pay_date: e.target.value })} data-testid="ap-pay-date" /></Field>
          <Field label="Method">
            <select className="input" value={form.method} onChange={(e) => setForm({ ...form, method: e.target.value })} data-testid="ap-pay-method">
              <option value="ach">Bank transfer (ACH)</option>
              <option value="wire">Wire</option>
              <option value="check">Check</option>
              <option value="card">Card</option>
              <option value="cash">Cash</option>
              <option value="plaid" disabled={!plaidEnabled}>Online bank payment{plaidEnabled ? '' : ' (not connected)'}</option>
              <option value="mercury" disabled={!mercuryEnabled}>Mercury{mercuryEnabled ? '' : ' (not connected)'}</option>
              <option value="other">Other</option>
            </select>
          </Field>
          {form.method === 'mercury' && mercuryEnabled && (
            <div data-testid="ap-pay-mercury-helper" style={{
              padding: 10, background: '#0c4a6e0d', borderRadius: 6, fontSize: 12,
              borderLeft: '3px solid #0c4a6e',
            }}>
              This saves an AP payment draft. A different approver releases it, then CoreFlux queues the
              Mercury instruction for Treasury approval. No money moves from this form.
            </div>
          )}
          <Field label="Amount"><input className="input" type="number" step="0.01" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} data-testid="ap-pay-amount" /></Field>
          <Field label="Reference (check #, wire ref)"><input className="input" value={form.reference} onChange={(e) => setForm({ ...form, reference: e.target.value })} data-testid="ap-pay-reference" /></Field>
          <label style={{ display: 'inline-flex', gap: 6, fontSize: 14 }}>
            <input type="checkbox" checked={form.auto_allocate} onChange={(e) => setForm({ ...form, auto_allocate: e.target.checked })} data-testid="ap-pay-auto-allocate" />
            Auto-allocate FIFO to oldest unpaid bills for this vendor
          </label>
          {error && <p className="error">Error: {error.message}</p>}
        </div>
        <footer style={modalFooter}>
          <button className="btn btn--ghost" onClick={onClose} data-testid="ap-pay-cancel">Cancel</button>
          <button className="btn btn--primary" onClick={submit} disabled={busy || !vendor?.id || !form.amount} data-testid="ap-pay-save">{busy ? 'Saving…' : 'Record payment'}</button>
        </footer>
      </div>
    </div>
  );
}

function AllocateModal({ payment, onClose, onDone }) {
  const [mode, setMode] = useState('fifo');
  const [manualAmounts, setManualAmounts] = useState({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const billsParams = new URLSearchParams({
    status: 'ready_to_pay',
    per_page: '200',
    vendor_name: payment.vendor_name,
  });
  if (payment.entity_id) billsParams.set('entity_id', String(payment.entity_id));
  const billsUrl = `/modules/ap/api/bills.php?${billsParams.toString()}`;
  const { data: billsData, loading: billsLoading, error: billsError } = useApi(billsUrl);
  const openBills = billsData?.rows ?? [];
  const availableForBill = (bill) => Number(bill.payment_available ?? bill.amount_due);
  const manualAllocations = openBills
    .map((bill) => ({ bill_id: Number(bill.id), amount: Number(manualAmounts[bill.id] || 0) }))
    .filter((line) => line.amount > 0);
  const manualTotal = manualAllocations.reduce((sum, line) => sum + line.amount, 0);
  const submit = async () => {
    setBusy(true); setError(null);
    try {
      const body = mode === 'fifo'
        ? { auto: 'fifo' }
        : { allocations: manualAllocations };
      await api.post(`/modules/ap/api/payments.php?action=allocate&id=${payment.id}`, body);
      onDone?.();
    } catch (e) { setError(e); } finally { setBusy(false); }
  };
  return (
    <div data-testid="ap-allocate-modal" style={modalOverlay} onClick={(e) => e.target === e.currentTarget && !busy && onClose?.()}>
      <div style={modalBox}>
        <header style={modalHeader}>
          <h3 style={{ margin: 0 }}>Allocate payment #{payment.id}</h3>
          <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--cf-text-secondary)' }}>Unallocated: {Number(payment.unallocated_amount).toFixed(2)} {payment.currency} · Vendor: {payment.vendor_name}</p>
        </header>
        <div style={{ padding: 20, display: 'grid', gap: 12 }}>
          <label style={{ display: 'flex', gap: 16 }}>
            <span style={{ display: 'inline-flex', gap: 6 }}>
              <input type="radio" checked={mode === 'fifo'} onChange={() => setMode('fifo')} data-testid="ap-allocate-fifo" /> Auto-FIFO (oldest first)
            </span>
            <span style={{ display: 'inline-flex', gap: 6 }}>
              <input type="radio" checked={mode === 'manual'} onChange={() => setMode('manual')} data-testid="ap-allocate-manual" /> Manual
            </span>
          </label>
          {mode === 'manual' && (
            <div>
              {billsLoading && <p className="muted">Loading approved bills...</p>}
              {billsError && <p className="error">Could not load bills: {billsError.message}</p>}
              {!billsLoading && !billsError && openBills.length === 0 && (
                <p className="empty-state">This vendor has no approved bills with an open balance.</p>
              )}
              {openBills.length > 0 && (
                <div className="data-table-wrap" style={{ maxHeight: 280, overflow: 'auto' }}>
                  <table className="data-table" data-testid="ap-allocate-open-bills">
                    <thead><tr><th>Bill</th><th>Due</th><th style={{ textAlign: 'right' }}>Open</th><th style={{ textAlign: 'right' }}>Apply</th></tr></thead>
                    <tbody>
                      {openBills.map((bill) => (
                        <tr key={bill.id}>
                          <td>
                            <strong>{bill.bill_number || bill.internal_ref}</strong>
                            <div className="muted">{bill.internal_ref}</div>
                          </td>
                          <td>{bill.due_date}</td>
                          <td style={{ textAlign: 'right' }}>{availableForBill(bill).toFixed(2)} {bill.currency}</td>
                          <td style={{ textAlign: 'right' }}>
                            <input
                              className="input"
                              type="number"
                              min="0"
                              max={Math.min(availableForBill(bill), Number(payment.unallocated_amount))}
                              step="0.01"
                              value={manualAmounts[bill.id] || ''}
                              onChange={(event) => setManualAmounts((current) => ({ ...current, [bill.id]: event.target.value }))}
                              placeholder="0.00"
                              aria-label={`Amount to apply to ${bill.bill_number || bill.internal_ref}`}
                              data-testid={`ap-alloc-amount-${bill.id}`}
                              style={{ width: 110, textAlign: 'right' }}
                            />
                          </td>
                        </tr>
                      ))}
                    </tbody>
                    <tfoot><tr><td colSpan={3}><strong>Total selected</strong></td><td style={{ textAlign: 'right' }}><strong>{manualTotal.toFixed(2)} {payment.currency}</strong></td></tr></tfoot>
                  </table>
                </div>
              )}
            </div>
          )}
          {error && <p className="error">Error: {error.message}</p>}
        </div>
        <footer style={modalFooter}>
          <button className="btn btn--ghost" onClick={onClose} data-testid="ap-alloc-cancel">Cancel</button>
          <button
            className="btn btn--primary"
            onClick={submit}
            disabled={busy || (mode === 'manual' && (manualAllocations.length === 0 || manualTotal - Number(payment.unallocated_amount) > 0.005))}
            data-testid="ap-alloc-confirm"
          >{busy ? 'Allocating…' : 'Allocate'}</button>
        </footer>
      </div>
    </div>
  );
}

const Field = ({ label, children }) => (
  <label style={{ display: 'block', fontSize: 13 }}>
    <span style={{ color: 'var(--cf-text-secondary)' }}>{label}</span>
    <div style={{ marginTop: 4 }}>{children}</div>
  </label>
);
const modalOverlay = { position: 'fixed', inset: 0, background: 'rgba(15,18,28,0.5)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 };
const modalBox = { background: 'var(--cf-surface, #fff)', borderRadius: 12, width: 'min(560px, 100%)', maxHeight: '90vh', display: 'flex', flexDirection: 'column' };
const modalHeader = { padding: 20, borderBottom: '1px solid var(--cf-border, #e5e7eb)' };
const modalFooter = { padding: 16, borderTop: '1px solid var(--cf-border, #e5e7eb)', display: 'flex', justifyContent: 'flex-end', gap: 8, background: 'var(--cf-surface-alt, #f9fafb)' };

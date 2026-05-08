import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { api, useApi } from '../lib/api';
import {
  AlertCircle, ArrowRight, ArrowUpRight, CheckCircle2, ChevronDown,
  Receipt, RefreshCw, Sparkles, SkipForward, Wallet,
} from 'lucide-react';

/**
 * TransactionsToReview — Layer-style "5 to review → first one ready in 2 clicks".
 *
 * Reads `?prefilter=oldest_first&autoload=1`:
 *   - prefilter=oldest_first|newest_first|amount_desc → server order
 *   - autoload=1 → automatically opens the first row + fetches its AI suggestion
 *
 * Per-row UX:
 *   - Click to expand · Sparkle "AI suggest" button · COA dropdown ·
 *     Accept · Skip / Ignore
 *
 * All mutations go through the existing endpoints from Sprint 6h/7e.1, so
 * accepting an AI suggestion both stamps the bank line AND feeds the
 * categorization-history moat.
 */
export default function TransactionsToReview() {
  const [params, setParams] = useSearchParams();
  const order   = params.get('prefilter') || params.get('order') || 'oldest_first';
  const autoload = params.get('autoload') === '1';
  const bankId   = parseInt(params.get('bank_account_id') || '0', 10) || 0;

  const queueUrl = `/api/transactions_to_review.php?order=${encodeURIComponent(order)}${bankId ? `&bank_account_id=${bankId}` : ''}`;
  const { data, error, loading, reload } = useApi(queueUrl);
  const accountsApi = useApi('/modules/accounting/api/accounts.php?active=1');

  const [openId, setOpenId]       = useState(null);
  const [aiByLine, setAiByLine]   = useState({});      // line_id → suggestion payload
  const [aiBusy, setAiBusy]       = useState({});      // line_id → bool
  const [acceptBusy, setAcceptBusy] = useState({});    // line_id → bool
  const [pickByLine, setPickByLine] = useState({});    // line_id → account_code
  const [accepted, setAccepted]   = useState(new Set());
  const [skipped, setSkipped]     = useState(new Set());
  const [errMsg, setErrMsg]       = useState(null);
  const autoloadFiredRef = useRef(false);

  const visibleRows = useMemo(() => {
    return (data?.rows || []).filter(r => !accepted.has(r.id) && !skipped.has(r.id));
  }, [data?.rows, accepted, skipped]);

  // ── Autoload: open first row + fetch AI suggestion ───────────────
  useEffect(() => {
    if (!autoload || autoloadFiredRef.current) return;
    if (loading || !data) return;
    const first = (data.rows || [])[0];
    if (!first) return;
    autoloadFiredRef.current = true;
    setOpenId(first.id);
    if (!aiByLine[first.id]) fetchAiSuggestion(first.id);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [autoload, loading, data]);

  async function fetchAiSuggestion(lineId) {
    setAiBusy(b => ({ ...b, [lineId]: true }));
    setErrMsg(null);
    try {
      const res = await api.post(`/modules/accounting/api/bank_ai.php?action=suggest_categorize&line_id=${lineId}`);
      setAiByLine(m => ({ ...m, [lineId]: res?.suggestion || null }));
      // pre-populate the picker with the AI suggestion
      const code = res?.suggestion?.account_code;
      if (code) setPickByLine(m => ({ ...m, [lineId]: code }));
    } catch (e) {
      setAiByLine(m => ({ ...m, [lineId]: { _error: e.message } }));
    } finally {
      setAiBusy(b => ({ ...b, [lineId]: false }));
    }
  }

  async function acceptCategorize(lineId, accountCode, suggestionId) {
    if (!accountCode) { setErrMsg('Pick an account before accepting.'); return; }
    setAcceptBusy(b => ({ ...b, [lineId]: true }));
    setErrMsg(null);
    try {
      await api.post(
        `/modules/accounting/api/bank_statements.php?action=accept_ai_categorize&line_id=${lineId}`,
        { account_code: accountCode, ai_suggestion_id: suggestionId || null }
      );
      setAccepted(s => { const n = new Set(s); n.add(lineId); return n; });
      // advance focus to next row
      const nextRow = visibleRows.find(r => r.id !== lineId && !accepted.has(r.id) && !skipped.has(r.id));
      if (nextRow) {
        setOpenId(nextRow.id);
        if (!aiByLine[nextRow.id]) fetchAiSuggestion(nextRow.id);
      } else {
        setOpenId(null);
      }
    } catch (e) {
      setErrMsg(e.message);
    } finally {
      setAcceptBusy(b => ({ ...b, [lineId]: false }));
    }
  }

  async function skipLine(lineId) {
    setAcceptBusy(b => ({ ...b, [lineId]: true }));
    try {
      await api.post(`/modules/accounting/api/bank_statements.php?action=ignore&line_id=${lineId}`);
      setSkipped(s => { const n = new Set(s); n.add(lineId); return n; });
      const nextRow = visibleRows.find(r => r.id !== lineId);
      if (nextRow) {
        setOpenId(nextRow.id);
        if (!aiByLine[nextRow.id]) fetchAiSuggestion(nextRow.id);
      } else {
        setOpenId(null);
      }
    } catch (e) {
      setErrMsg(e.message);
    } finally {
      setAcceptBusy(b => ({ ...b, [lineId]: false }));
    }
  }

  function changeOrder(nextOrder) {
    const p = new URLSearchParams(params);
    p.set('prefilter', nextOrder);
    setParams(p, { replace: true });
  }
  function changeBank(nextBid) {
    const p = new URLSearchParams(params);
    if (nextBid) p.set('bank_account_id', String(nextBid));
    else p.delete('bank_account_id');
    setParams(p, { replace: true });
  }

  if (error) {
    return (
      <div data-testid="transactions-to-review-error" style={errBox}>
        <AlertCircle size={18} /> Couldn't load queue: {error.message}
        <button onClick={reload} className="btn btn--ghost" style={{ marginLeft: 'auto' }} data-testid="transactions-to-review-retry">Retry</button>
      </div>
    );
  }

  const accounts = accountsApi.data?.rows || [];
  const totalRemaining = visibleRows.length;
  const totalServer    = data?.total ?? 0;

  return (
    <div data-testid="transactions-to-review-page" style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', gap: 12, flexWrap: 'wrap' }}>
        <div>
          <h1 style={{ display: 'flex', alignItems: 'center', gap: 10, fontSize: 22, fontWeight: 700, margin: 0 }}>
            <Receipt size={22} color="#0284c7" /> Transactions to review
          </h1>
          <p style={{ color: '#64748b', margin: '4px 0 0', fontSize: 13 }} data-testid="transactions-to-review-subtitle">
            {loading
              ? 'Loading queue…'
              : `${totalRemaining} of ${totalServer} uncategorized · accept the AI suggestion or pick from your chart of accounts.`}
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <select
            data-testid="transactions-to-review-order"
            className="input"
            value={order}
            onChange={e => changeOrder(e.target.value)}
            style={{ fontSize: 12, padding: '6px 8px' }}
          >
            <option value="oldest_first">Oldest first</option>
            <option value="newest_first">Newest first</option>
            <option value="amount_desc">Largest amount</option>
          </select>
          <select
            data-testid="transactions-to-review-bank-filter"
            className="input"
            value={bankId || ''}
            onChange={e => changeBank(parseInt(e.target.value, 10) || 0)}
            style={{ fontSize: 12, padding: '6px 8px' }}
          >
            <option value="">All bank accounts</option>
            {(data?.bank_accounts || []).map(b => (
              <option key={b.id} value={b.id}>{b.name}</option>
            ))}
          </select>
          <button data-testid="transactions-to-review-refresh" onClick={reload} className="btn btn--ghost" style={{ fontSize: 12 }}>
            <RefreshCw size={12} style={{ marginRight: 4, verticalAlign: 'middle' }} />Refresh
          </button>
        </div>
      </header>

      {errMsg && (
        <div data-testid="transactions-to-review-action-error" style={errBox}>
          <AlertCircle size={16} /> {errMsg}
        </div>
      )}

      {(!loading && totalRemaining === 0) && (
        <div data-testid="transactions-to-review-empty" style={{ padding: 36, background: '#ecfdf5', border: '1px solid #a7f3d0', borderRadius: 12, textAlign: 'center' }}>
          <CheckCircle2 size={28} color="#059669" style={{ marginBottom: 8 }} />
          <strong style={{ display: 'block', color: '#065f46', fontSize: 15 }}>You're all caught up</strong>
          <p style={{ color: '#047857', margin: '6px 0 0', fontSize: 13 }}>No bank-statement lines need attention right now.</p>
          <Link to="/modules/accounting/bookkeeping" className="btn btn--ghost" style={{ marginTop: 12, fontSize: 12 }} data-testid="transactions-to-review-back-overview">
            Back to bookkeeping overview <ArrowRight size={12} style={{ marginLeft: 4, verticalAlign: 'middle' }} />
          </Link>
        </div>
      )}

      {totalRemaining > 0 && (
        <div data-testid="transactions-to-review-list" style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
          {visibleRows.map((r) => {
            const isOpen   = openId === r.id;
            const ai       = aiByLine[r.id];
            const aiPick   = pickByLine[r.id] || ai?.account_code || '';
            const overdue  = (r.age_days ?? 0) >= 14;
            const tone     = (r.amount ?? 0) >= 0 ? '#059669' : '#dc2626';
            return (
              <div key={r.id}
                   data-testid={`transactions-to-review-row-${r.id}`}
                   style={{
                     border: `1px solid ${isOpen ? '#7c3aed44' : '#e2e8f0'}`,
                     background: isOpen ? '#faf5ff' : '#fff',
                     borderRadius: 10,
                   }}>
                {/* Summary row */}
                <button type="button"
                        data-testid={`transactions-to-review-row-toggle-${r.id}`}
                        onClick={() => {
                          const next = isOpen ? null : r.id;
                          setOpenId(next);
                          if (next && !aiByLine[next]) fetchAiSuggestion(next);
                        }}
                        style={summaryBtnStyle}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 10, flex: 1, minWidth: 0 }}>
                    <ChevronDown size={14} color="#64748b"
                                 style={{ transform: isOpen ? 'rotate(0deg)' : 'rotate(-90deg)', transition: 'transform .15s' }} />
                    <div style={{ minWidth: 90, fontSize: 12, color: overdue ? '#b91c1c' : '#64748b', fontFamily: 'ui-monospace, monospace' }}>
                      {r.posted_date}{r.age_days != null && <span style={{ marginLeft: 4 }}>· {r.age_days}d</span>}
                    </div>
                    <div style={{ flex: 1, minWidth: 0, textAlign: 'left', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', fontSize: 13, color: '#0f172a' }}>
                      {r.description || <span style={{ color: '#94a3b8' }}>(no description)</span>}
                    </div>
                    <span style={{ fontSize: 11, color: '#64748b' }}>{r.bank_account_name}</span>
                  </div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                    {r.ai_suggested_account_code && !ai && (
                      <span title={`Pre-suggested ${r.ai_suggested_account_code}`} style={badgePill('#0284c7')}>
                        <Sparkles size={10} /> {r.ai_suggested_account_code}
                      </span>
                    )}
                    <strong style={{ fontFamily: 'ui-monospace, monospace', fontSize: 13, color: tone, minWidth: 80, textAlign: 'right' }}>
                      {(r.amount ?? 0) >= 0 ? '+' : ''}{Number(r.amount ?? 0).toFixed(2)}
                    </strong>
                  </div>
                </button>

                {isOpen && (
                  <div data-testid={`transactions-to-review-row-detail-${r.id}`}
                       style={{ padding: '0 16px 14px 16px', borderTop: '1px dashed #ddd6fe' }}>
                    {/* AI suggestion block */}
                    <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginTop: 12, marginBottom: 8 }}>
                      <Sparkles size={14} color="#7c3aed" />
                      <strong style={{ fontSize: 12, color: '#5b21b6' }}>AI suggestion</strong>
                      {!ai && !aiBusy[r.id] && (
                        <button data-testid={`transactions-to-review-suggest-${r.id}`}
                                onClick={() => fetchAiSuggestion(r.id)}
                                className="btn btn--ghost" style={{ fontSize: 11, padding: '4px 10px' }}>
                          Get AI suggestion
                        </button>
                      )}
                      {aiBusy[r.id] && <span data-testid={`transactions-to-review-ai-loading-${r.id}`} style={{ fontSize: 11, color: '#7c3aed' }}>Thinking…</span>}
                    </div>
                    {ai && !ai._error && (
                      <div data-testid={`transactions-to-review-ai-result-${r.id}`}
                           style={{ background: '#fff', border: '1px solid #ddd6fe', borderRadius: 8, padding: 10, fontSize: 12, marginBottom: 10 }}>
                        <div style={{ display: 'flex', gap: 12, alignItems: 'center' }}>
                          <code style={{ fontFamily: 'ui-monospace, monospace', color: '#5b21b6', fontWeight: 600 }}>
                            {ai.account_code || '—'}
                          </code>
                          {ai.account_name && <span style={{ color: '#475569' }}>· {ai.account_name}</span>}
                          {ai.confidence != null && (
                            <span style={badgePill(ai.confidence >= 0.8 ? '#059669' : ai.confidence >= 0.5 ? '#d97706' : '#dc2626')}>
                              {Math.round(ai.confidence * 100)}% confidence
                            </span>
                          )}
                          {ai.source && <span style={{ marginLeft: 'auto', color: '#94a3b8' }}>{ai.source}</span>}
                        </div>
                        {ai.reasoning && (
                          <p style={{ margin: '6px 0 0', color: '#475569', lineHeight: 1.45 }}>{ai.reasoning}</p>
                        )}
                      </div>
                    )}
                    {ai?._error && (
                      <p data-testid={`transactions-to-review-ai-err-${r.id}`} className="error" style={{ fontSize: 12 }}>
                        AI suggestion failed: {ai._error}
                      </p>
                    )}

                    {/* Manual COA picker + accept */}
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 10, alignItems: 'center' }}>
                      <label style={{ fontSize: 12, color: '#475569' }}>
                        Account
                        <select
                          data-testid={`transactions-to-review-coa-${r.id}`}
                          className="input"
                          value={aiPick}
                          onChange={e => setPickByLine(m => ({ ...m, [r.id]: e.target.value }))}
                          style={{ display: 'block', minWidth: 280, fontSize: 13, marginTop: 2 }}
                        >
                          <option value="">— pick an account —</option>
                          {accounts.map(a => (
                            <option key={a.id} value={a.code}>
                              {a.code} — {a.name}{a.account_type ? ` (${a.account_type})` : ''}
                            </option>
                          ))}
                        </select>
                      </label>
                      <button
                        data-testid={`transactions-to-review-accept-${r.id}`}
                        className="btn btn--primary"
                        onClick={() => acceptCategorize(r.id, aiPick, ai?.suggestion_id || ai?.id)}
                        disabled={acceptBusy[r.id] || !aiPick}
                        style={{ fontSize: 12 }}
                      >
                        <CheckCircle2 size={12} style={{ marginRight: 4, verticalAlign: 'middle' }} />
                        {acceptBusy[r.id] ? 'Saving…' : 'Accept & next'}
                      </button>
                      <button
                        data-testid={`transactions-to-review-skip-${r.id}`}
                        className="btn btn--ghost"
                        onClick={() => skipLine(r.id)}
                        disabled={acceptBusy[r.id]}
                        style={{ fontSize: 12 }}
                      >
                        <SkipForward size={12} style={{ marginRight: 4, verticalAlign: 'middle' }} />
                        Skip
                      </button>
                      <Link
                        to={`/modules/accounting/bank-rec/${r.bank_account_id}`}
                        className="btn btn--ghost"
                        style={{ fontSize: 12, marginLeft: 'auto' }}
                        data-testid={`transactions-to-review-open-bank-${r.id}`}
                      >
                        Open in Bank Rec <ArrowUpRight size={12} style={{ marginLeft: 4, verticalAlign: 'middle' }} />
                      </Link>
                    </div>
                  </div>
                )}
              </div>
            );
          })}
        </div>
      )}

      <footer style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', color: '#94a3b8', fontSize: 11, paddingTop: 8 }}>
        <span data-testid="transactions-to-review-progress">
          Reviewed this session: {accepted.size} accepted · {skipped.size} skipped
        </span>
        <Link to="/modules/accounting/bookkeeping" style={{ color: '#0284c7' }} data-testid="transactions-to-review-overview-link">
          <Wallet size={11} style={{ marginRight: 3, verticalAlign: 'middle' }} />Bookkeeping overview
        </Link>
      </footer>
    </div>
  );
}

const summaryBtnStyle = {
  display: 'flex', alignItems: 'center', gap: 8, width: '100%',
  padding: '10px 14px', background: 'transparent', border: 'none',
  borderRadius: 10, cursor: 'pointer', textAlign: 'left',
};
const errBox = {
  display: 'flex', alignItems: 'center', gap: 8, padding: 12,
  background: '#fef2f2', border: '1px solid #fecaca', borderRadius: 8,
  color: '#7f1d1d', fontSize: 13,
};
const badgePill = (color) => ({
  display: 'inline-flex', alignItems: 'center', gap: 4,
  padding: '2px 8px', background: '#fff',
  border: `1px solid ${color}55`, borderRadius: 12,
  fontSize: 10, color, fontWeight: 600,
});

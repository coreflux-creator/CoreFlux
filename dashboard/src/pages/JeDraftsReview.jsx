import React, { useState, useEffect, useCallback } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../lib/api';

/**
 * <JeDraftsReview /> — operator inbox for AI-drafted journal entries.
 *
 * Slice C / Spec §11: every workflow that calls
 * coreflux.draft_journal_entry writes a status='draft' row into
 * accounting_journal_entries.  This page is the reviewer's home for
 * those rows.
 *
 * Two-column layout:
 *   left   — list of pending drafts (newest first)
 *   right  — drill-down: header + lines + re-run validation report +
 *            reject affordance (post requires a workflow_approval and
 *            lives in the workflow runtime cockpit).
 *
 * Mounted at /admin/ai/je-drafts. RBAC handled server-side
 * (`ai.audit.view` OR `accounting.review` for view, `accounting.approve`
 * for reject).
 */
export default function JeDraftsReview() {
  const [rows, setRows]         = useState([]);
  const [loading, setLoading]   = useState(true);
  const [error, setError]       = useState(null);
  const [selectedId, setSel]    = useState(null);
  const [detail, setDetail]     = useState(null);
  const [detailLoading, setDL]  = useState(false);
  const [busy, setBusy]         = useState(false);

  const loadList = useCallback(async () => {
    setLoading(true); setError(null);
    try {
      const r = await api.get('/api/ai/je_drafts.php');
      setRows(r.drafts || []);
    } catch (e) {
      setError(e.message || String(e));
    } finally {
      setLoading(false);
    }
  }, []);

  const loadDetail = useCallback(async (id) => {
    if (!id) { setDetail(null); return; }
    setDL(true);
    try {
      const r = await api.get(`/api/ai/je_drafts.php?action=detail&id=${id}`);
      setDetail(r);
    } catch (e) {
      setError(e.message || String(e));
    } finally { setDL(false); }
  }, []);

  useEffect(() => { loadList(); },           [loadList]);
  useEffect(() => { loadDetail(selectedId); }, [selectedId, loadDetail]);

  const handleReject = async () => {
    if (!detail?.draft) return;
    const reason = window.prompt('Reject reason (kept on the audit trail):', '');
    if (reason === null) return;
    setBusy(true); setError(null);
    try {
      await api.post('/api/ai/je_drafts.php?action=reject', {
        id: detail.draft.id,
        reason: reason || null,
      });
      await loadList();
      setSel(null);
      setDetail(null);
    } catch (e) { setError(e.message || String(e)); }
    finally { setBusy(false); }
  };

  return (
    <div data-testid="je-drafts-page" style={{ padding: '0 8px' }}>
      <header style={{ marginBottom: 16 }}>
        <h2 style={{ fontSize: 'var(--cf-text-xl)', margin: 0 }}
            data-testid="je-drafts-title">
          AI-drafted journal entries
        </h2>
        <p style={{ color: 'var(--cf-text-secondary)', fontSize: 13, margin: '4px 0 0' }}>
          Review drafts the AI proposed via <code>coreflux.draft_journal_entry</code>.
          Posting requires an approved workflow — open the row in the{' '}
          <Link to="/admin/ai-gateway/reviewer">Reviewer cockpit</Link> to approve.
          Use Reject here to dismiss a draft that shouldn't post.
        </p>
      </header>

      {error && (
        <div className="alert alert--error"
             data-testid="je-drafts-error"
             style={{ marginBottom: 12 }}>{error}</div>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: 'minmax(360px, 1fr) 2fr', gap: 16 }}>
        <JeDraftList rows={rows} loading={loading}
                     selectedId={selectedId} onSelect={setSel} />
        <JeDraftDetail detail={detail} loading={detailLoading}
                       selectedId={selectedId} busy={busy}
                       onReject={handleReject} />
      </div>
    </div>
  );
}

/* ─────────────────────────────────────────────────────── */
function JeDraftList({ rows, loading, selectedId, onSelect }) {
  if (loading) return <p data-testid="je-drafts-list-loading">Loading…</p>;
  if (rows.length === 0) {
    return (
      <p data-testid="je-drafts-list-empty"
         style={{ color: 'var(--cf-text-secondary)', fontSize: 13 }}>
        No AI-drafted journal entries pending review. (✓ inbox zero.)
      </p>
    );
  }
  return (
    <div data-testid="je-drafts-list"
         style={{ border: '1px solid var(--cf-border)', borderRadius: 6, overflow: 'hidden' }}>
      {rows.map(r => (
        <button key={r.id}
                type="button"
                onClick={() => onSelect(r.id)}
                data-testid={`je-drafts-row-${r.id}`}
                style={{
                  display: 'block', width: '100%', textAlign: 'left',
                  padding: '10px 12px', cursor: 'pointer',
                  background: r.id === selectedId ? 'var(--cf-bg-selected, #eff6ff)' : 'transparent',
                  borderBottom: '1px solid var(--cf-border-muted, #f1f5f9)',
                  border: 'none', borderTop: '1px solid var(--cf-border-muted, #f1f5f9)',
                }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
            <span style={{ fontWeight: 600, fontSize: 13 }}>
              {r.je_number} · {r.posting_date}
            </span>
            <span style={{ fontFamily: 'ui-monospace, monospace', fontSize: 12, color: '#475569' }}>
              {Number(r.total_debit).toFixed(2)} {r.currency}
            </span>
          </div>
          <div style={{ display: 'flex', gap: 8, fontSize: 11, color: 'var(--cf-text-secondary)', marginTop: 4 }}>
            <span>{r.line_count} lines</span>
            <code>{r.source_module}{r.source_ref_type ? ` · ${r.source_ref_type}` : ''}</code>
            <span style={{ marginLeft: 'auto' }}>{(r.created_at || '').slice(0, 16).replace('T', ' ')}</span>
          </div>
          {r.memo && (
            <div style={{ fontSize: 11, color: '#475569', marginTop: 3, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
              {r.memo}
            </div>
          )}
        </button>
      ))}
    </div>
  );
}

function JeDraftDetail({ detail, loading, selectedId, busy, onReject }) {
  if (!selectedId) {
    return (
      <div data-testid="je-drafts-detail-placeholder"
           style={{ padding: 24, color: 'var(--cf-text-secondary)', fontSize: 13,
                    border: '1px dashed var(--cf-border)', borderRadius: 6 }}>
        Select a draft on the left to drill in.
      </div>
    );
  }
  if (loading) return <p data-testid="je-drafts-detail-loading">Loading…</p>;
  if (!detail?.draft) return <p data-testid="je-drafts-detail-empty">Draft not found.</p>;

  const { draft, lines, validation } = detail;
  const ok = !!validation?.ok;

  return (
    <div data-testid="je-drafts-detail"
         style={{ border: '1px solid var(--cf-border)', borderRadius: 6, padding: 16 }}>
      <header style={{ marginBottom: 12 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 12 }}>
          <div>
            <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>
              JE #<code>{draft.id}</code> · {draft.je_number} · entity {draft.entity_id}
              {' · '}<StatusChip status={draft.status} />
            </div>
            <h3 data-testid="je-drafts-detail-memo"
                style={{ margin: '4px 0 0', fontSize: 16, fontWeight: 600 }}>
              {draft.memo || <em style={{ color: '#94a3b8' }}>(no memo)</em>}
            </h3>
          </div>
          <div style={{ textAlign: 'right', fontFamily: 'ui-monospace, monospace', fontSize: 13 }}>
            <div data-testid="je-drafts-detail-total-debit">
              Dr {Number(draft.total_debit).toFixed(2)} {draft.currency}
            </div>
            <div data-testid="je-drafts-detail-total-credit">
              Cr {Number(draft.total_credit).toFixed(2)} {draft.currency}
            </div>
          </div>
        </div>
        <div style={{ marginTop: 6, fontSize: 11, color: 'var(--cf-text-secondary)' }}>
          Posting date {draft.posting_date}
          {draft.source_module && <> · source <code>{draft.source_module}{draft.source_ref_type ? `:${draft.source_ref_type}` : ''}{draft.source_ref_id ? ` #${draft.source_ref_id}` : ''}</code></>}
          {' · created '} {(draft.created_at || '').slice(0, 16).replace('T', ' ')}
        </div>
      </header>

      {/* Re-validation block */}
      <div data-testid="je-drafts-detail-validation"
           style={{
             border: '1px solid ' + (ok ? '#16a34a55' : '#dc262655'),
             background: ok ? '#f0fdf4' : '#fef2f2',
             borderRadius: 6, padding: 10, marginBottom: 14, fontSize: 12,
           }}>
        <strong style={{ color: ok ? '#16a34a' : '#dc2626' }}
                data-testid="je-drafts-detail-validation-status">
          {ok ? '✓ Validation passes' : '✗ Validation failed'}
        </strong>
        <span style={{ marginLeft: 12, color: '#475569' }}>
          {Number(validation?.total_debit ?? 0).toFixed(2)} Dr ·
          {' '}{Number(validation?.total_credit ?? 0).toFixed(2)} Cr ·
          {' '}{validation?.balanced ? 'balanced' : 'unbalanced'}
        </span>
        {validation?.period && (
          <span style={{ marginLeft: 8, color: '#475569' }}>
            · period {validation.period.period_number} ({validation.period.status})
          </span>
        )}
        {!ok && validation?.errors?.length > 0 && (
          <ul data-testid="je-drafts-detail-validation-errors"
              style={{ margin: '6px 0 0 18px', padding: 0, color: '#991b1b' }}>
            {validation.errors.map((err, i) => (
              <li key={i} data-testid={`je-drafts-detail-validation-error-${i}`}>{err}</li>
            ))}
          </ul>
        )}
        {validation?.ai_advice && (
          <p style={{ margin: '6px 0 0', color: '#475569', fontStyle: 'italic' }}>
            {validation.ai_advice}
          </p>
        )}
      </div>

      {/* Lines table */}
      <table data-testid="je-drafts-detail-lines"
             style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12, marginBottom: 14 }}>
        <thead>
          <tr style={{ background: 'var(--cf-bg-muted, #f8fafc)', textAlign: 'left' }}>
            <th style={cellTH}>#</th>
            <th style={cellTH}>Account</th>
            <th style={{ ...cellTH, textAlign: 'right' }}>Debit</th>
            <th style={{ ...cellTH, textAlign: 'right' }}>Credit</th>
            <th style={cellTH}>Memo</th>
          </tr>
        </thead>
        <tbody>
          {lines?.map((ln) => (
            <tr key={ln.line_no} data-testid={`je-drafts-detail-line-${ln.line_no}`}>
              <td style={cellTD}>{ln.line_no}</td>
              <td style={cellTD}><code>{ln.account_code}</code> · {ln.account_name}</td>
              <td style={{ ...cellTD, textAlign: 'right', fontFamily: 'ui-monospace, monospace' }}>
                {ln.debit > 0 ? Number(ln.debit).toFixed(2) : ''}
              </td>
              <td style={{ ...cellTD, textAlign: 'right', fontFamily: 'ui-monospace, monospace' }}>
                {ln.credit > 0 ? Number(ln.credit).toFixed(2) : ''}
              </td>
              <td style={cellTD}>{ln.memo}</td>
            </tr>
          ))}
        </tbody>
      </table>

      {/* Action surface */}
      <div style={{ display: 'flex', gap: 8, borderTop: '1px solid var(--cf-border-muted, #f1f5f9)', paddingTop: 12 }}>
        <button type="button"
                className="btn btn--ghost"
                disabled={busy}
                onClick={onReject}
                data-testid="je-drafts-detail-reject">
          Reject draft
        </button>
        <Link to="/admin/ai-gateway/reviewer"
              className="btn btn--primary"
              data-testid="je-drafts-detail-open-reviewer">
          Open Reviewer cockpit →
        </Link>
        <span style={{ marginLeft: 'auto', alignSelf: 'center', fontSize: 11, color: 'var(--cf-text-secondary)' }}>
          Approval flows live in the Reviewer; posting calls
          {' '}<code>coreflux.post_approved_journal_entry</code>.
        </span>
      </div>
    </div>
  );
}

const cellTH = { padding: '6px 8px', borderBottom: '1px solid var(--cf-border-muted, #e2e8f0)', fontWeight: 600 };
const cellTD = { padding: '6px 8px', borderBottom: '1px solid var(--cf-border-muted, #f1f5f9)' };

function StatusChip({ status }) {
  const colors = {
    draft:    { bg: '#fef3c7', fg: '#92400e' },
    posted:   { bg: '#dcfce7', fg: '#166534' },
    void:     { bg: '#e5e7eb', fg: '#374151' },
    reversed: { bg: '#fee2e2', fg: '#991b1b' },
  };
  const c = colors[status] || { bg: '#e5e7eb', fg: '#374151' };
  return (
    <span data-testid={`je-drafts-status-${status}`}
          style={{
            display: 'inline-block', padding: '1px 6px', borderRadius: 999,
            background: c.bg, color: c.fg, fontSize: 10, fontWeight: 600,
          }}>{status}</span>
  );
}

import React, { useState } from 'react';
import { Link, useParams, useNavigate } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';
import AccountLink from '../../../dashboard/src/components/AccountLink';
import JeTracePane from './JeTracePane';
import {
  ArrowLeft, ExternalLink, FileCheck2, Pencil, RotateCcw, Trash2, X,
} from 'lucide-react';

/**
 * Journal Entry detail and correction hub.
 * Drafts can be edited directly. Posted entries can be corrected or removed
 * from active books while their original rows remain available for audit.
 */
export default function JournalEntryDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { data, loading, error, reload } = useApi(`/modules/accounting/api/journal_entries.php?id=${id}`);
  const [busy, setBusy] = useState(false);
  const [actionErr, setErr] = useState(null);
  const [reverseOpen, setReverseOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [reason, setReason] = useState('');
  const [deleteReason, setDeleteReason] = useState('');

  const reverse = async () => {
    if (!reason.trim()) return;
    setBusy(true); setErr(null);
    try {
      const res = await api.post(`/modules/accounting/api/journal_entries.php?action=reverse&id=${id}`, { reason: reason.trim() });
      navigate(`/modules/accounting/journal-entries/${res.je_id}`);
    } catch (e) { setErr(e.message || String(e)); }
    finally { setBusy(false); }
  };

  const postDraft = async () => {
    setBusy(true); setErr(null);
    try {
      await api.post(`/modules/accounting/api/journal_entries.php?action=post_draft&id=${id}`, {});
      setDeleteOpen(false);
      await reload();
    } catch (e) { setErr(e.message || String(e)); }
    finally { setBusy(false); }
  };

  const deleteEntry = async () => {
    setBusy(true); setErr(null);
    try {
      await api.post(`/modules/accounting/api/journal_entries.php?action=delete&id=${id}`, {
        reason: deleteReason.trim(),
      });
      navigate('/modules/accounting/journal-entries', { replace: true });
    } catch (e) { setErr(e.message || String(e)); }
    finally { setBusy(false); }
  };

  if (loading) return <p>Loading…</p>;
  if (error) return <p className="error">Could not load this journal entry: {error.message}</p>;
  const { entry, lines = [] } = data || {};
  if (!entry) return <p>Journal entry not found.</p>;

  const isDraft = entry.status === 'draft';
  const isPosted = entry.status === 'posted';
  const isReversed = entry.status === 'reversed';
  const approvalControlled = entry.source_module === 'system'
    || ['ai_workflow', 'workflow_run'].includes(entry.source_ref_type);
  const canManageDraft = isDraft && !approvalControlled;
  const canCorrect = isPosted || isReversed;
  const canDelete = canManageDraft || canCorrect;

  return (
    <section className="entry-detail" data-testid="accounting-je-detail">
      <Link to="/modules/accounting/journal-entries" className="entry-detail__back-link">
        <ArrowLeft size={14} aria-hidden="true" />Journal entries
      </Link>

      <header className="entry-detail__header">
        <div>
          <div className="entry-detail__eyebrow">Journal entry</div>
          <h2>JE #{entry.je_number} <StatusPill status={entry.status} /></h2>
        </div>
        <div className="entry-detail__actions" aria-label="Journal entry actions">
          {canManageDraft && (
            <>
              <Link className="btn btn--ghost" to={`/modules/accounting/journal-entries/${id}/edit`} data-testid="accounting-je-edit-draft">
                <Pencil size={15} aria-hidden="true" />Edit draft
              </Link>
              <button type="button" className="btn btn--primary" onClick={postDraft} disabled={busy} data-testid="accounting-je-post-draft">
                <FileCheck2 size={15} aria-hidden="true" />{busy ? 'Posting…' : 'Post draft'}
              </button>
              <button type="button" className="btn btn--danger-quiet" onClick={() => setDeleteOpen(true)} disabled={busy} data-testid="accounting-je-delete-draft">
                <Trash2 size={15} aria-hidden="true" />Delete draft
              </button>
            </>
          )}
          {canCorrect && (
            <>
              <Link className="btn btn--primary" to={`/modules/accounting/journal-entries/new?replace_id=${id}`} data-testid="accounting-je-correct">
                <Pencil size={15} aria-hidden="true" />Correct entry
              </Link>
              <button type="button" className="btn btn--danger-quiet" onClick={() => { setDeleteReason(''); setDeleteOpen(true); }} disabled={busy} data-testid="accounting-je-delete">
                <Trash2 size={15} aria-hidden="true" />Delete from books
              </button>
              {isPosted && (
                <button type="button" className="btn btn--ghost" onClick={() => setReverseOpen(true)} disabled={busy} data-testid="accounting-je-reverse">
                  <RotateCcw size={15} aria-hidden="true" />Reverse only
                </button>
              )}
            </>
          )}
        </div>
      </header>

      <EntryStatusNote status={entry.status} approvalControlled={approvalControlled} />

      {isDraft && approvalControlled && (
        <div className="entry-related" data-testid="accounting-je-approval-workflow-note">
          <span>Approval-controlled draft</span>
          <Link to="/ai-agents">Review in AI Agents <ExternalLink size={13} aria-hidden="true" /></Link>
        </div>
      )}

      {(entry.reverses_je_id || entry.reversed_by_je_id) && (
        <div className="entry-related" data-testid="accounting-je-related-entry">
          <span>{entry.reverses_je_id ? 'Reverses' : 'Reversed by'}</span>
          <Link to={`/modules/accounting/journal-entries/${entry.reverses_je_id || entry.reversed_by_je_id}`}>
            Open linked journal entry <ExternalLink size={13} aria-hidden="true" />
          </Link>
        </div>
      )}

      {entry.corrected_by_je_id && (
        <div className="entry-related" data-testid="accounting-je-corrected-by">
          <span>Corrected by</span>
          <Link to={`/modules/accounting/journal-entries/${entry.corrected_by_je_id}`}>
            {entry.corrected_by_je_number || 'Open corrected entry'} <ExternalLink size={13} aria-hidden="true" />
          </Link>
        </div>
      )}

      <div className="entry-detail__summary">
        <Field label="Posting date" value={entry.posting_date} testid="accounting-je-detail-posting-date" />
        <Field label="Currency" value={entry.currency} />
        <Field label="Total debit" value={fmt(entry.total_debit)} testid="accounting-je-detail-total-debit" />
        <Field label="Total credit" value={fmt(entry.total_credit)} testid="accounting-je-detail-total-credit" />
        <Field label="Source" value={renderSource(entry)} testid="accounting-je-detail-source" />
        <Field label="Memo" value={entry.memo || '—'} testid="accounting-je-detail-memo" />
      </div>

      <h3 className="entry-detail__section-title">Lines</h3>
      <div className="data-table-wrap">
        <table className="data-table" data-testid="accounting-je-detail-lines">
          <thead><tr><th>Account</th><th>Description</th><th style={{ textAlign: 'right' }}>Debit</th><th style={{ textAlign: 'right' }}>Credit</th></tr></thead>
          <tbody>
            {lines.map((line, index) => (
              <tr key={line.id || index} data-testid={`accounting-je-detail-line-${index}`}>
                <td><AccountLink accountId={line.account_id} accountCode={line.account_code} entityId={entry.entity_id}><code>{line.account_code}</code> {line.account_name}</AccountLink></td>
                <td>{line.description || line.memo || '—'}</td>
                <td className="numeric-cell">{fmt(line.debit)}</td>
                <td className="numeric-cell">{fmt(line.credit)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {reverseOpen && isPosted && (
        <div className="entry-action-modal-backdrop" onClick={() => setReverseOpen(false)}>
        <section className="entry-action-panel entry-action-panel--warning entry-action-panel--modal" role="dialog" aria-modal="true" aria-labelledby="reverse-entry-heading" data-testid="accounting-je-reverse-panel" onClick={(event) => event.stopPropagation()}>
          <button type="button" className="btn btn--ghost btn--icon entry-action-panel__close" onClick={() => setReverseOpen(false)} aria-label="Close reversal form" title="Close"><X size={15} /></button>
          <h3 id="reverse-entry-heading">Reverse this posted entry</h3>
          <p>The original remains in the audit trail and a new entry posts the opposite debits and credits. Use Correct entry when you need to replace it with edited lines.</p>
          <label htmlFor="accounting-je-reversal-reason">Reason for reversal</label>
          <textarea
            id="accounting-je-reversal-reason"
            className="input"
            value={reason}
            onChange={(event) => setReason(event.target.value)}
            aria-label="Reason for reversal"
            data-testid="accounting-journal-reverse-reason"
            placeholder="What was wrong, and what will replace it?"
            rows={3}
            required
          />
          <div className="entry-action-panel__actions">
            <button type="button" className="btn btn--ghost" onClick={() => setReverseOpen(false)}>Cancel</button>
            <button type="button" className="btn btn--danger" onClick={reverse} disabled={busy || !reason.trim()}>{busy ? 'Posting reversal…' : 'Post reversal'}</button>
          </div>
        </section>
        </div>
      )}

      {deleteOpen && canDelete && (
        <div className="entry-action-modal-backdrop" onClick={() => setDeleteOpen(false)}>
        <section className="entry-action-panel entry-action-panel--danger entry-action-panel--modal" role="dialog" aria-modal="true" aria-labelledby="delete-entry-heading" data-testid="accounting-je-delete-panel" onClick={(event) => event.stopPropagation()}>
          <button type="button" className="btn btn--ghost btn--icon entry-action-panel__close" onClick={() => setDeleteOpen(false)} aria-label="Close delete dialog" title="Close"><X size={15} /></button>
          <h3 id="delete-entry-heading">Delete this {isDraft ? 'draft' : 'entry'}?</h3>
          <p>
            {isDraft
              ? 'It will disappear from normal journal views, but its audit record will remain.'
              : 'It will stop affecting balances and reports. Its audit record will remain, and any linked bank line will return to the bank feed for review.'}
          </p>
          {!isDraft && (
            <label htmlFor="accounting-je-delete-reason">
              Reason for deletion
              <textarea
                id="accounting-je-delete-reason"
                className="input"
                rows={3}
                value={deleteReason}
                onChange={(event) => setDeleteReason(event.target.value)}
                placeholder="What was wrong with this entry?"
                data-testid="accounting-je-delete-reason"
                required
              />
            </label>
          )}
          <div className="entry-action-panel__actions">
            <button type="button" className="btn btn--ghost" onClick={() => setDeleteOpen(false)}>{isDraft ? 'Keep draft' : 'Cancel'}</button>
            <button type="button" className="btn btn--danger" onClick={deleteEntry} disabled={busy || (!isDraft && !deleteReason.trim())} data-testid="accounting-je-delete-confirm">
              {busy ? 'Deleting…' : (isDraft ? 'Delete draft' : 'Delete entry')}
            </button>
          </div>
        </section>
        </div>
      )}

      {actionErr && <p className="error" data-testid="accounting-je-detail-error">Could not complete that action: {actionErr}</p>}

      <JeTracePane jeId={entry.id} />
    </section>
  );
}

function EntryStatusNote({ status, approvalControlled = false }) {
  if (status === 'draft' && approvalControlled) {
    return (
      <div className="entry-status-note entry-status-note--draft" data-testid="accounting-je-status-note">
        <strong>Awaiting approval</strong><span>This system-generated draft stays off the ledger until it completes its review workflow.</span>
      </div>
    );
  }
  const content = {
    draft: ['Draft', 'This entry does not affect balances or reports until it is posted. You can edit or delete it.'],
    posted: ['Posted', 'This entry affects balances and reports. Correct it to replace it in one step, or delete it from active books; both actions preserve the audit trail.'],
    reversed: ['Reversed', 'This entry and its linked reversal remain visible for audit history. You can correct the chain or delete it from active books.'],
    void: ['Deleted', 'This entry no longer affects balances or reports. Its details remain here for audit history.'],
  };
  const [label, text] = content[status] || [status, ''];
  return (
    <div className={`entry-status-note entry-status-note--${status}`} data-testid="accounting-je-status-note">
      <strong>{label}</strong><span>{text}</span>
    </div>
  );
}

function renderSource(entry) {
  if (entry.source_ref_type === 'replaces_je' && entry.source_ref_id) {
    return <Link to={`/modules/accounting/journal-entries/${entry.source_ref_id}`} data-testid="accounting-je-source-link">Correction of journal entry <ExternalLink size={12} aria-hidden="true" /></Link>;
  }
  if (!entry.source_module || entry.source_module === 'manual') return 'Manual entry';
  const source = entry.source_module;
  const sourceId = entry.source_ref_id;
  const links = {
    ap_bills: sourceId ? `/modules/ap/bills/${sourceId}` : null,
    ap_payments: sourceId ? `/modules/ap/payments/${sourceId}` : null,
    billing_invoices: sourceId ? `/modules/billing/invoices/${sourceId}` : null,
    payroll_runs: sourceId ? `/modules/payroll/runs/${sourceId}` : null,
  };
  const path = links[source] || links[entry.source_ref_type];
  const label = source === 'treasury_feed' ? 'Bank feed' : source === 'reversal' ? 'Reversal' : source.replaceAll('_', ' ');
  return path
    ? <Link to={path} data-testid="accounting-je-source-link">{label} <ExternalLink size={12} aria-hidden="true" /></Link>
    : <span>{label}{sourceId ? ` · ${sourceId}` : ''}</span>;
}

function StatusPill({ status }) {
  return <span data-testid={`accounting-je-status-${status}`} className={`badge badge--${status === 'void' ? 'voided' : status}`}>{status === 'void' ? 'deleted' : status}</span>;
}

function Field({ label, value, testid }) {
  return (
    <div className="entry-detail__field">
      <div>{label}</div>
      <div data-testid={testid}>{value}</div>
    </div>
  );
}

function fmt(value) {
  return Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

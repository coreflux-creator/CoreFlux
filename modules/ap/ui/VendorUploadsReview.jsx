import React, { useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';

/**
 * Admin queue for vendor-portal uploads that didn't auto-approve.
 *
 * In the happy path this list is empty — W-9s and COIs auto-extract via
 * `aiExtract()` and apply themselves to the vendor record. Items land here
 * only when:
 *   - AI confidence < 0.80 on a W-9, or
 *   - extracted TIN disagrees with what AP already has on file, or
 *   - AI extraction failed (network/parse), or
 *   - the document type is just inherently exception-prone.
 */
export default function VendorUploadsReview() {
  const { data, loading, reload } = useApi('/modules/ap/api/vendor_portal.php?action=admin_pending');
  const rows = data?.rows || [];
  const [busyId, setBusyId] = useState(null);
  const [err, setErr] = useState(null);

  const decide = async (id, decision, note) => {
    setBusyId(id); setErr(null);
    try {
      await api.post(
        `/modules/ap/api/vendor_portal.php?action=admin_${decision}`,
        { id, note }
      );
      reload();
    } catch (e) { setErr(e.message); }
    finally { setBusyId(null); }
  };

  return (
    <section data-testid="ap-vendor-uploads-review">
      <header style={{ marginBottom: 16 }}>
        <h2 style={{ margin: 0, fontSize: 18 }}>Vendor uploads — pending review</h2>
        <p className="muted" style={{ margin: '4px 0 0', fontSize: 12 }}>
          W-9s and COIs auto-process on upload. Items appear here only when AI confidence
          is low, the extracted TIN doesn't match what AP has on file, or extraction failed.
        </p>
      </header>

      {err && <p className="error" data-testid="ap-vendor-uploads-error">{err}</p>}
      {loading && <p>Loading…</p>}
      {!loading && rows.length === 0 && (
        <p className="empty" data-testid="ap-vendor-uploads-empty">
          ✓ Inbox clear. No vendor uploads need manual attention.
        </p>
      )}

      {rows.map((r) => (
        <UploadCard
          key={r.id}
          row={r}
          busy={busyId === r.id}
          onApprove={(note) => decide(r.id, 'approve', note)}
          onReject={(note) => decide(r.id, 'reject', note)}
        />
      ))}
    </section>
  );
}

function UploadCard({ row, busy, onApprove, onReject }) {
  const [note, setNote] = useState('');
  const ai = row.ai_extracted || {};
  return (
    <div data-testid={`ap-vendor-upload-${row.id}`} style={{
      border: '1px solid #e5e7eb', borderRadius: 8, padding: 16, marginBottom: 16,
      background: '#fafafa',
    }}>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', marginBottom: 8 }}>
        <div>
          <strong>{row.vendor_name || `Vendor #${row.vendor_id}`}</strong>{' '}
          <span className="badge">{row.document_type}</span>{' '}
          {row.ai_action === 'flagged_for_review' && <span className="badge" style={{ background: '#fef3c7', color: '#92400e' }}>flagged by AI</span>}
        </div>
        <div className="muted" style={{ fontSize: 12 }}>{row.uploaded_at}</div>
      </header>

      <div style={{ fontSize: 13, marginBottom: 8 }}>
        <strong>File:</strong> {row.file_name}
        {row.ai_confidence != null && (
          <span className="muted" style={{ marginLeft: 12 }}>
            AI confidence: {(Number(row.ai_confidence) * 100).toFixed(0)}%
          </span>
        )}
      </div>

      {ai && Object.keys(ai).length > 0 && (
        <div style={{ background: '#fff', border: '1px solid #e5e7eb', padding: 8, borderRadius: 6, marginBottom: 8, fontSize: 12 }}>
          <strong style={{ display: 'block', marginBottom: 4 }}>AI extracted</strong>
          <table style={{ width: '100%', borderCollapse: 'collapse' }}>
            <tbody>
              {Object.entries(ai).map(([k, v]) => (
                <tr key={k}>
                  <td style={{ padding: '2px 8px', color: '#64748b', verticalAlign: 'top' }}>{k}</td>
                  <td style={{ padding: '2px 8px' }}>{v == null || v === '' ? <span className="muted">—</span> : String(v)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
        <input
          className="input"
          value={note}
          onChange={(e) => setNote(e.target.value)}
          placeholder="Note (optional, required for reject)"
          data-testid={`ap-vendor-upload-${row.id}-note`}
          style={{ flex: 1 }}
        />
        <button
          type="button"
          className="btn btn--primary"
          onClick={() => onApprove(note)}
          disabled={busy}
          data-testid={`ap-vendor-upload-${row.id}-approve`}
        >Approve</button>
        <button
          type="button"
          className="btn btn--ghost"
          onClick={() => { if (!note.trim()) { alert('Reject reason required'); return; } onReject(note); }}
          disabled={busy}
          data-testid={`ap-vendor-upload-${row.id}-reject`}
        >Reject</button>
      </div>
    </div>
  );
}

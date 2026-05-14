import React, { useState, useEffect, useRef } from 'react';
import { Paperclip, Download, Trash2, UploadCloud, FileText, Loader2 } from 'lucide-react';
import { api, useApi } from '../lib/api';

/**
 * <EvidenceAttachments subjectType="time_entry" subjectId={42} label="Timesheet docs" />
 *
 * Drop-in attachment panel for any polymorphic subject backed by the
 * evidence_attachments pivot. Used by:
 *   - Time entries / time bundles (signed timesheet PDFs, sign-in photos)
 *   - Billing invoices (supporting time bundle exports, customer POs)
 *   - AP bills (vendor invoices, receipts)
 *   - Placement / person / accounting events
 *
 * Upload flow:
 *   1. POST /api/evidence_upload_url.php → presigned S3 POST
 *   2. fetch(presigned.url) with multipart form-data (bytes go direct to S3)
 *   3. POST /api/accounting/evidence.php → record metadata
 *   4. Reload list
 *
 * Download: fetches a fresh signed URL each click (no leaking long-lived URLs).
 * Delete: soft delete via existing endpoint.
 */
export default function EvidenceAttachments({
  subjectType,
  subjectId,
  label = 'Attachments',
  documentType,           // optional default doc type (e.g. 'signed_timesheet', 'vendor_invoice')
  compact = false,
  readOnly = false,
  testidPrefix = 'evidence',
}) {
  const path = `/api/accounting/evidence.php?subject_type=${encodeURIComponent(subjectType)}&subject_id=${subjectId}`;
  const { data, reload } = useApi(subjectId ? path : null, [path]);
  const rows = data?.rows || [];

  const [uploading, setUploading] = useState(false);
  const [error, setError]         = useState(null);
  const fileRef = useRef(null);

  if (!subjectId) return null;

  const onPick = () => fileRef.current?.click();

  const onFile = async (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setUploading(true);
    setError(null);
    try {
      // Step 1 — presigned upload URL
      const presign = await api.post('/api/evidence_upload_url.php', {
        subject_type: subjectType,
        subject_id:   subjectId,
        filename:     file.name,
        content_type: file.type || 'application/octet-stream',
      });

      // Step 2 — upload bytes direct to S3 (or local-driver shim).
      const form = new FormData();
      Object.entries(presign.upload?.fields || {}).forEach(([k, v]) => form.append(k, v));
      form.append('file', file);

      if (presign.upload?.url) {
        const up = await fetch(presign.upload.url, { method: 'POST', body: form, credentials: 'omit' });
        if (!up.ok) throw new Error(`upload failed: ${up.status}`);
      }
      // LocalDriver returns no upload.url — the presigned key IS the
      // destination path on disk. Skipping the upload PUT is correct.

      // Step 3 — register metadata
      await api.post('/api/accounting/evidence.php', {
        subject_type:  subjectType,
        subject_id:    subjectId,
        document_type: documentType || _defaultDocType(subjectType),
        label:         file.name,
        storage_key:   presign.storage_key,
        content_type:  file.type || null,
        size_bytes:    file.size,
        source:        'manual_upload',
      });

      // Reload list
      reload?.();
    } catch (err) {
      setError(err?.message || 'upload failed');
    } finally {
      setUploading(false);
      if (fileRef.current) fileRef.current.value = '';
    }
  };

  const onDownload = async (row) => {
    // Re-fetch a fresh signed URL each click. The list payload doesn't
    // include one for security (signed URLs leak in logs / browser history).
    try {
      const r = await api.get(`/api/accounting/evidence.php?action=signed_url&id=${row.id}`);
      if (r?.signed_url) window.open(r.signed_url, '_blank', 'noopener');
      else if (row.storage_key) {
        // Fallback for older API revs — uses ap-style endpoint.
        const r2 = await api.get(`/api/ap/bills.php?action=attachment_url&storage_object_id=${row.id}`);
        if (r2?.signed_url) window.open(r2.signed_url, '_blank', 'noopener');
      }
    } catch (_) {
      setError('Could not generate download link');
    }
  };

  const onDelete = async (row) => {
    if (!confirm(`Delete "${row.label || 'this attachment'}"?`)) return;
    try {
      await api.delete(`/api/accounting/evidence.php?id=${row.id}`);
      reload?.();
    } catch (e) {
      setError(e?.message || 'delete failed');
    }
  };

  return (
    <section className="evidence-attachments"
             data-testid={`${testidPrefix}-panel`}
             data-subject-type={subjectType}
             data-subject-id={subjectId}
             style={{
               padding: compact ? 8 : 12,
               border: '1px solid #e2e8f0',
               borderRadius: 8,
               background: '#fff',
             }}>
      <div style={{ display:'flex', alignItems:'center', justifyContent:'space-between', marginBottom:8 }}>
        <div style={{ display:'inline-flex', alignItems:'center', gap:6, fontSize:13, fontWeight:600, color:'#475569' }}>
          <Paperclip size={14}/> {label} <span style={{ color:'#94a3b8', fontWeight:400 }}>({rows.length})</span>
        </div>
        {!readOnly && (
          <>
            <input type="file" ref={fileRef} style={{ display:'none' }} onChange={onFile}
                   data-testid={`${testidPrefix}-file-input`}/>
            <button onClick={onPick} disabled={uploading}
                    data-testid={`${testidPrefix}-upload-btn`}
                    className="btn btn--ghost"
                    style={{ display:'inline-flex', alignItems:'center', gap:6, fontSize:12 }}>
              {uploading ? <Loader2 size={12} className="cf-spin"/> : <UploadCloud size={12}/>}
              {uploading ? 'Uploading…' : 'Upload'}
            </button>
          </>
        )}
      </div>

      {error && (
        <p className="error" data-testid={`${testidPrefix}-error`}
           style={{ fontSize:12, color:'#dc2626', margin:'4px 0' }}>{error}</p>
      )}

      {rows.length === 0 && (
        <p data-testid={`${testidPrefix}-empty`}
           style={{ fontSize:12, color:'#94a3b8', margin:'4px 0' }}>
          No attachments yet.
        </p>
      )}

      {rows.length > 0 && (
        <ul style={{ listStyle:'none', padding:0, margin:0 }}
            data-testid={`${testidPrefix}-list`}>
          {rows.map(row => (
            <li key={row.id}
                data-testid={`${testidPrefix}-row-${row.id}`}
                style={{
                  display:'flex',
                  alignItems:'center',
                  gap:8,
                  padding:'6px 0',
                  borderTop:'1px solid #f1f5f9',
                  fontSize:13,
                }}>
              <FileText size={14} style={{ color:'#64748b', flexShrink:0 }}/>
              <span style={{ flex:1, overflow:'hidden', textOverflow:'ellipsis', whiteSpace:'nowrap' }}
                    title={row.label || row.storage_key}>
                {row.label || row.storage_key || `attachment ${row.id}`}
              </span>
              <span style={{ color:'#94a3b8', fontSize:11 }}>
                {row.document_type}
              </span>
              <button onClick={() => onDownload(row)}
                      data-testid={`${testidPrefix}-download-${row.id}`}
                      className="btn btn--ghost" style={{ padding:'2px 6px' }}
                      title="Download">
                <Download size={12}/>
              </button>
              {!readOnly && (
                <button onClick={() => onDelete(row)}
                        data-testid={`${testidPrefix}-delete-${row.id}`}
                        className="btn btn--ghost" style={{ padding:'2px 6px', color:'#dc2626' }}
                        title="Delete">
                  <Trash2 size={12}/>
                </button>
              )}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

function _defaultDocType(subjectType) {
  switch (subjectType) {
    case 'time_entry':
    case 'time_bundle':
    case 'time_uploaded_document':  return 'signed_timesheet';
    case 'billing_invoice':         return 'supporting_doc';
    case 'ap_bill':                 return 'vendor_invoice';
    case 'ap_bill_line':            return 'receipt';
    case 'placement':               return 'signed_contract';
    case 'person':                  return 'identity_doc';
    case 'company':                 return 'w9';
    case 'accounting_event':
    case 'journal_entry':           return 'supporting_doc';
    default:                        return 'document';
  }
}

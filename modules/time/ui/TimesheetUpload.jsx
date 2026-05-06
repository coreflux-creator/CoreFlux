import React, { useState, useEffect, useMemo } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';
import { uploadFileViaPresignedPost } from '../../../dashboard/src/lib/uploads';

const CATS = [
  'regular_billable','regular_nonbillable','OT_billable','OT_nonbillable',
  'holiday','vacation','sick','bereavement','unpaid_leave',
];

/**
 * Upload Timesheet — drop a paper sign-in sheet, scanned PDF, or phone
 * photo of a timesheet → AI extracts (date, project, hours) rows → user
 * maps each row's "project" to a placement → entries land as drafts.
 *
 * Mirrors the AP `BillCreate` extract-from-PDF pattern.
 */
export default function TimesheetUpload() {
  const [file, setFile]               = useState(null);
  const [weekEnding, setWeekEnding]   = useState('');
  const [extracting, setExtracting]   = useState(false);
  const [extractError, setExtractError] = useState(null);
  const [docId, setDocId]             = useState(null);
  const [draft, setDraft]             = useState(null);
  const [confidence, setConfidence]   = useState(null);
  const [model, setModel]             = useState(null);
  const [saving, setSaving]           = useState(false);
  const [saveError, setSaveError]     = useState(null);
  const [saveResult, setSaveResult]   = useState(null);

  // Placement directory for the typeahead.
  const placements = useApi('/modules/placements/api/placements.php?status=active&per_page=200');
  const placementOptions = useMemo(
    () => (placements.data?.rows || []).map((p) => ({
      id: p.id,
      label: `${p.title || 'Placement'} · ${p.end_client_name || ''}`.trim(),
      person_id: p.person_id,
    })),
    [placements.data]
  );

  // Editable lines (added once draft is in)
  const [lines, setLines] = useState([]);
  useEffect(() => {
    if (!draft) return;
    const aiLines = Array.isArray(draft.lines) ? draft.lines : [];
    setLines(aiLines.map((l, i) => ({
      tmpId:        i + 1,
      work_date:    l.work_date || '',
      project_text: l.project || '',
      placement_id: '', // user must pick
      person_id:    '',
      category:     CATS.includes(l.category) ? l.category : 'regular_billable',
      hours:        Number(l.hours) > 0 ? Number(l.hours) : 0,
      description:  l.description || '',
      include:      true,
      saved_id:     null,
      save_error:   null,
    })));
  }, [draft]);

  const extract = async () => {
    if (!file) { setExtractError('Drop a file first'); return; }
    setExtracting(true); setExtractError(null); setSaveResult(null);
    try {
      const upload = await uploadFileViaPresignedPost(
        `/modules/time/api/upload.php?action=upload_url&file_name=${encodeURIComponent(file.name)}`,
        file
      );
      const r = await api.post('/modules/time/api/upload.php?action=extract', {
        storage_key: upload.storage_key,
        file_name:   file.name,
        mime_type:   file.type || 'application/pdf',
        week_ending: weekEnding || null,
      });
      setDocId(r.document_id);
      setDraft(r.draft || {});
      setConfidence(r.confidence);
      setModel(r.model);
    } catch (e) { setExtractError(e.message); }
    finally { setExtracting(false); }
  };

  const updateLine = (tmpId, patch) =>
    setLines((ls) => ls.map((l) => l.tmpId === tmpId ? { ...l, ...patch } : l));

  const onPickPlacement = (tmpId, placementId) => {
    const opt = placementOptions.find((o) => o.id === Number(placementId));
    updateLine(tmpId, {
      placement_id: placementId ? Number(placementId) : '',
      person_id:    opt?.person_id || '',
    });
  };

  const saveAll = async () => {
    setSaving(true); setSaveError(null);
    const include = lines.filter((l) => l.include && !l.saved_id);
    if (include.length === 0) { setSaveError('Nothing to save'); setSaving(false); return; }
    if (include.some((l) => !l.placement_id || !l.work_date || !(Number(l.hours) > 0))) {
      setSaveError('Each line needs placement, date, and hours > 0');
      setSaving(false); return;
    }

    const savedIds = [];
    for (const l of include) {
      try {
        const r = await api.post('/modules/time/api/entries.php', {
          placement_id: l.placement_id,
          person_id:    l.person_id,
          work_date:    l.work_date,
          category:     l.category,
          hours:        Number(l.hours),
          description:  l.description || null,
          source:       'ai_inbox',
          source_ref_id: docId,
        });
        const newId = r?.entry?.id || r?.id;
        savedIds.push(newId);
        updateLine(l.tmpId, { saved_id: newId, save_error: null });
      } catch (e) {
        updateLine(l.tmpId, { save_error: e.message });
      }
    }

    if (savedIds.length > 0 && docId) {
      try {
        await api.post(`/modules/time/api/upload.php?id=${docId}&action=consume`, { entry_ids: savedIds });
      } catch (_) { /* best-effort */ }
    }

    setSaveResult({ saved: savedIds.length, attempted: include.length });
    setSaving(false);
  };

  return (
    <section data-testid="time-upload" style={{ maxWidth: 1100 }}>
      <header style={{ marginBottom: 16 }}>
        <h2 style={{ margin: 0, fontSize: 18 }}>Upload paper timesheet</h2>
        <p className="muted" style={{ margin: '4px 0 0', fontSize: 12 }}>
          Drop a PDF or phone-photo of a paper timesheet. AI extracts each (date, project, hours)
          row; you map each project to a placement and the entries land as drafts.
        </p>
      </header>

      {!draft && (
        <div style={{ padding: 16, border: '2px dashed #cbd5e1', borderRadius: 8, marginBottom: 16, background: '#f8fafc' }}>
          <input type="file" accept="image/*,application/pdf" onChange={(e) => setFile(e.target.files?.[0] || null)} data-testid="time-upload-file" />
          {file && <p style={{ fontSize: 13, marginTop: 6 }}>Picked: <strong>{file.name}</strong> ({Math.round(file.size / 1024)} KB)</p>}
          <div style={{ marginTop: 12, display: 'flex', gap: 8, alignItems: 'flex-end' }}>
            <div>
              <label style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>Week ending (optional hint)</label>
              <input className="input" type="date" value={weekEnding} onChange={(e) => setWeekEnding(e.target.value)} data-testid="time-upload-week-ending" />
            </div>
            <button type="button" className="btn btn--primary" onClick={extract} disabled={!file || extracting} data-testid="time-upload-extract">
              {extracting ? '✨ Extracting…' : '✨ Extract with AI'}
            </button>
          </div>
          {extractError && <p className="error" data-testid="time-upload-extract-error" style={{ marginTop: 8 }}>{extractError}</p>}
        </div>
      )}

      {draft && (
        <div data-testid="time-upload-review">
          <div style={{ background: '#ecfdf5', border: '1px solid #a7f3d0', padding: 8, borderRadius: 6, marginBottom: 12, fontSize: 13 }}>
            ✓ Extracted {lines.length} line{lines.length === 1 ? '' : 's'}
            {confidence != null && <> · confidence <strong>{(confidence * 100).toFixed(0)}%</strong></>}
            {model && <> · {model}</>}
            {draft.person_name && <> · for <strong>{draft.person_name}</strong></>}
            {draft.week_ending && <> · week ending <strong>{draft.week_ending}</strong></>}
            {' '}— review every row before saving.
          </div>

          <table className="data-table" data-testid="time-upload-lines-table">
            <thead>
              <tr>
                <th></th><th>Date</th><th>AI: project</th><th>Placement</th>
                <th>Category</th><th>Hours</th><th>Description</th><th>Status</th>
              </tr>
            </thead>
            <tbody>
              {lines.map((l) => (
                <tr key={l.tmpId} data-testid={`time-upload-line-${l.tmpId}`} style={{ background: l.saved_id ? '#ecfdf5' : (l.save_error ? '#fef2f2' : 'transparent') }}>
                  <td><input type="checkbox" checked={l.include} onChange={(e) => updateLine(l.tmpId, { include: e.target.checked })} disabled={!!l.saved_id} data-testid={`time-upload-line-${l.tmpId}-include`} /></td>
                  <td><input className="input" type="date" value={l.work_date} onChange={(e) => updateLine(l.tmpId, { work_date: e.target.value })} disabled={!!l.saved_id} data-testid={`time-upload-line-${l.tmpId}-date`} /></td>
                  <td><span className="muted" style={{ fontSize: 12 }}>{l.project_text || '—'}</span></td>
                  <td>
                    <select className="input" value={l.placement_id} onChange={(e) => onPickPlacement(l.tmpId, e.target.value)} disabled={!!l.saved_id} data-testid={`time-upload-line-${l.tmpId}-placement`} style={{ minWidth: 200 }}>
                      <option value="">— pick placement —</option>
                      {placementOptions.map((o) => <option key={o.id} value={o.id}>{o.label}</option>)}
                    </select>
                  </td>
                  <td>
                    <select className="input" value={l.category} onChange={(e) => updateLine(l.tmpId, { category: e.target.value })} disabled={!!l.saved_id} data-testid={`time-upload-line-${l.tmpId}-category`}>
                      {CATS.map((c) => <option key={c} value={c}>{c}</option>)}
                    </select>
                  </td>
                  <td><input className="input" type="number" step="0.25" min="0" value={l.hours} onChange={(e) => updateLine(l.tmpId, { hours: e.target.value })} disabled={!!l.saved_id} style={{ width: 80 }} data-testid={`time-upload-line-${l.tmpId}-hours`} /></td>
                  <td><input className="input" value={l.description} onChange={(e) => updateLine(l.tmpId, { description: e.target.value })} disabled={!!l.saved_id} data-testid={`time-upload-line-${l.tmpId}-desc`} style={{ minWidth: 160 }} /></td>
                  <td style={{ fontSize: 12 }}>
                    {l.saved_id ? <span className="badge badge--approved">saved #{l.saved_id}</span>
                                : l.save_error ? <span style={{ color: '#991b1b' }}>{l.save_error}</span>
                                : <span className="muted">draft</span>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
            <button type="button" className="btn btn--ghost" onClick={() => { setDraft(null); setDocId(null); setFile(null); setLines([]); setSaveResult(null); }} data-testid="time-upload-cancel">Discard + start over</button>
            <button type="button" className="btn btn--primary" onClick={saveAll} disabled={saving} data-testid="time-upload-save">
              {saving ? 'Saving…' : `Save ${lines.filter((l) => l.include && !l.saved_id).length} draft entries`}
            </button>
          </div>
          {saveError && <p className="error" data-testid="time-upload-save-error" style={{ marginTop: 8 }}>{saveError}</p>}
          {saveResult && (
            <p data-testid="time-upload-save-result" style={{ marginTop: 8, fontSize: 13, color: '#065f46' }}>
              ✓ Saved {saveResult.saved} of {saveResult.attempted} as draft entries. Open My Time to submit them.
            </p>
          )}
        </div>
      )}
    </section>
  );
}

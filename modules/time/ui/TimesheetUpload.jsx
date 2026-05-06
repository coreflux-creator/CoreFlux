import React, { useState, useEffect, useMemo } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';
import { uploadFileViaPresignedPost } from '../../../dashboard/src/lib/uploads';

const CATS = [
  'regular_billable','regular_nonbillable','OT_billable','OT_nonbillable',
  'holiday','vacation','sick','bereavement','unpaid_leave',
];

let _tmpId = 0;
const nextTmpId = () => ++_tmpId;

/**
 * Upload Timesheet — supports two modes:
 *   • Single — one person (you), N projects/days. Mirrors AP `BillCreate` extract.
 *   • Bulk   — one PDF with many people's hours (foreman daily log, crew sign-in).
 *              AI groups by person_name; you confirm the people-mapping; entries
 *              fan out to each person's draft entries.
 *
 * Save loop hits /api/time/entries.php POST per included line, stamps
 * source='ai_inbox' + source_ref_id={doc_id}, then /upload?action=consume.
 */
export default function TimesheetUpload() {
  const [mode, setMode]               = useState('single');
  const [file, setFile]               = useState(null);
  const [weekEnding, setWeekEnding]   = useState('');
  const [extracting, setExtracting]   = useState(false);
  const [extractError, setExtractError] = useState(null);
  const [docId, setDocId]             = useState(null);
  const [draft, setDraft]             = useState(null);
  const [confidence, setConfidence]   = useState(null);
  const [model, setModel]             = useState(null);
  const [groups, setGroups]           = useState([]); // [{ tmpId, person_name, person_id, lines: [...] }]
  const [saving, setSaving]           = useState(false);
  const [saveError, setSaveError]     = useState(null);
  const [saveResult, setSaveResult]   = useState(null);

  // ── Active placements directory (typeahead per line) ──
  const placementsAll = useApi('/modules/placements/api/placements.php?status=active&per_page=500');
  const placementsByPerson = useMemo(() => {
    const map = {};
    for (const p of (placementsAll.data?.rows || [])) {
      const opt = {
        id: p.id,
        person_id: p.person_id,
        label: `${p.title || 'Placement'} · ${p.end_client_name || ''}`.trim(),
      };
      if (!map[p.person_id]) map[p.person_id] = [];
      map[p.person_id].push(opt);
      if (!map['_all']) map['_all'] = [];
      map['_all'].push(opt);
    }
    return map;
  }, [placementsAll.data]);
  const placementOptionsForPerson = (personId) => {
    if (personId && placementsByPerson[personId]) return placementsByPerson[personId];
    return placementsByPerson['_all'] || [];
  };

  // ── Build groups when draft arrives ──
  useEffect(() => {
    if (!draft) { setGroups([]); return; }
    if (mode === 'bulk') {
      const people = Array.isArray(draft.people) ? draft.people : [];
      setGroups(people.map((p) => ({
        tmpId:        nextTmpId(),
        person_name:  p.person_name || '',
        person_id:    p.match_candidates?.length === 1 ? p.match_candidates[0].id : '',
        match_candidates: p.match_candidates || [],
        lines: (Array.isArray(p.lines) ? p.lines : []).map((l) => normaliseLine(l)),
      })));
    } else {
      const lines = Array.isArray(draft.lines) ? draft.lines : [];
      setGroups([{
        tmpId:        nextTmpId(),
        person_name:  draft.person_name || '',
        person_id:    '',
        match_candidates: [],
        lines: lines.map((l) => normaliseLine(l)),
      }]);
    }
  }, [draft, mode]);

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
        mode,
      });
      setDocId(r.document_id);
      setDraft(r.draft || {});
      setConfidence(r.confidence);
      setModel(r.model);
    } catch (e) { setExtractError(e.message); }
    finally { setExtracting(false); }
  };

  const updateGroup = (gid, patch) =>
    setGroups((gs) => gs.map((g) => g.tmpId === gid ? { ...g, ...patch } : g));
  const updateLine = (gid, lid, patch) =>
    setGroups((gs) => gs.map((g) =>
      g.tmpId === gid ? { ...g, lines: g.lines.map((l) => l.tmpId === lid ? { ...l, ...patch } : l) } : g
    ));

  const totalIncluded = groups.reduce((s, g) => s + g.lines.filter((l) => l.include && !l.saved_id).length, 0);

  const saveAll = async () => {
    setSaving(true); setSaveError(null);
    if (totalIncluded === 0) { setSaveError('Nothing to save'); setSaving(false); return; }
    // Validation
    for (const g of groups) {
      for (const l of g.lines) {
        if (!l.include || l.saved_id) continue;
        if (!l.placement_id || !l.work_date || !(Number(l.hours) > 0)) {
          setSaveError(`Each line needs placement, date, and hours > 0${g.person_name ? ` (group: ${g.person_name})` : ''}`);
          setSaving(false); return;
        }
      }
    }

    const savedIds = [];
    for (const g of groups) {
      for (const l of g.lines) {
        if (!l.include || l.saved_id) continue;
        try {
          const r = await api.post('/modules/time/api/entries.php', {
            placement_id:  l.placement_id,
            work_date:     l.work_date,
            category:      l.category,
            hours:         Number(l.hours),
            description:   l.description || null,
            source:        'ai_inbox',
            source_ref_id: docId,
          });
          const newId = r?.entry?.id || r?.id;
          savedIds.push(newId);
          updateLine(g.tmpId, l.tmpId, { saved_id: newId, save_error: null });
        } catch (e) {
          updateLine(g.tmpId, l.tmpId, { save_error: e.message });
        }
      }
    }

    if (savedIds.length > 0 && docId) {
      try { await api.post(`/modules/time/api/upload.php?id=${docId}&action=consume`, { entry_ids: savedIds }); } catch (_) {}
    }
    setSaveResult({ saved: savedIds.length, attempted: totalIncluded });
    setSaving(false);
  };

  const reset = () => {
    setDraft(null); setDocId(null); setFile(null); setGroups([]); setSaveResult(null);
  };

  return (
    <section data-testid="time-upload" style={{ maxWidth: 1180 }}>
      <header style={{ marginBottom: 16 }}>
        <h2 style={{ margin: 0, fontSize: 18 }}>Upload paper timesheet</h2>
        <p className="muted" style={{ margin: '4px 0 0', fontSize: 12 }}>
          Drop a PDF or phone-photo of a paper timesheet. AI extracts each (date, project, hours)
          row; you map each project to a placement and the entries land as drafts.
        </p>
      </header>

      {!draft && (
        <div style={{ padding: 16, border: '2px dashed #cbd5e1', borderRadius: 8, marginBottom: 16, background: '#f8fafc' }}>
          <fieldset style={{ display: 'flex', gap: 16, marginBottom: 12, border: 'none', padding: 0 }}>
            <label style={{ fontSize: 13 }}>
              <input type="radio" name="time-upload-mode" value="single" checked={mode === 'single'} onChange={() => setMode('single')} data-testid="time-upload-mode-single" /> Just my hours
            </label>
            <label style={{ fontSize: 13 }}>
              <input type="radio" name="time-upload-mode" value="bulk" checked={mode === 'bulk'} onChange={() => setMode('bulk')} data-testid="time-upload-mode-bulk" /> Many people (foreman log / crew sheet)
            </label>
          </fieldset>
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
            ✓ Extracted {groups.length} {mode === 'bulk' ? `person${groups.length === 1 ? '' : 's'}` : 'group'} with {groups.reduce((s, g) => s + g.lines.length, 0)} line{groups.reduce((s, g) => s + g.lines.length, 0) === 1 ? '' : 's'}
            {confidence != null && <> · confidence <strong>{(confidence * 100).toFixed(0)}%</strong></>}
            {model && <> · {model}</>}
            {draft.week_ending && <> · week ending <strong>{draft.week_ending}</strong></>}
          </div>

          {groups.map((g) => (
            <GroupCard
              key={g.tmpId}
              group={g}
              bulk={mode === 'bulk'}
              placementOptionsForPerson={placementOptionsForPerson}
              onPersonPick={(pid) => updateGroup(g.tmpId, { person_id: pid ? Number(pid) : '' })}
              onLineChange={(lid, patch) => updateLine(g.tmpId, lid, patch)}
            />
          ))}

          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
            <button type="button" className="btn btn--ghost" onClick={reset} data-testid="time-upload-cancel">Discard + start over</button>
            <button type="button" className="btn btn--primary" onClick={saveAll} disabled={saving} data-testid="time-upload-save">
              {saving ? 'Saving…' : `Save ${totalIncluded} draft entries`}
            </button>
          </div>
          {saveError && <p className="error" data-testid="time-upload-save-error" style={{ marginTop: 8 }}>{saveError}</p>}
          {saveResult && (
            <p data-testid="time-upload-save-result" style={{ marginTop: 8, fontSize: 13, color: '#065f46' }}>
              ✓ Saved {saveResult.saved} of {saveResult.attempted} as draft entries. Open My Time / Review Queue to submit.
            </p>
          )}
        </div>
      )}
    </section>
  );
}

function GroupCard({ group, bulk, placementOptionsForPerson, onPersonPick, onLineChange }) {
  const placementOpts = placementOptionsForPerson(group.person_id);
  const needsPersonPick = bulk && !group.person_id;
  return (
    <div data-testid={`time-upload-group-${group.tmpId}`} style={{
      border: '1px solid #e5e7eb', borderRadius: 8, marginBottom: 12, padding: 12,
      background: needsPersonPick ? '#fffbeb' : '#fff',
    }}>
      {bulk && (
        <header style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 10 }}>
          <strong style={{ fontSize: 14 }}>AI: {group.person_name || '(no name)'}</strong>
          <span className="muted" style={{ fontSize: 12 }}>→</span>
          <PersonPicker
            tmpId={group.tmpId}
            initialName={group.person_name}
            initialId={group.person_id}
            candidates={group.match_candidates}
            onPick={onPersonPick}
          />
          {needsPersonPick && <span className="badge" style={{ background: '#fef3c7', color: '#92400e' }}>Pick a person to save</span>}
        </header>
      )}
      <table className="data-table">
        <thead>
          <tr>
            <th></th><th>Date</th><th>AI: project</th><th>Placement</th>
            <th>Category</th><th>Hours</th><th>Description</th><th>Status</th>
          </tr>
        </thead>
        <tbody>
          {group.lines.map((l) => (
            <tr key={l.tmpId} data-testid={`time-upload-line-${group.tmpId}-${l.tmpId}`} style={{ background: l.saved_id ? '#ecfdf5' : (l.save_error ? '#fef2f2' : 'transparent') }}>
              <td><input type="checkbox" checked={l.include} onChange={(e) => onLineChange(l.tmpId, { include: e.target.checked })} disabled={!!l.saved_id} /></td>
              <td><input className="input" type="date" value={l.work_date} onChange={(e) => onLineChange(l.tmpId, { work_date: e.target.value })} disabled={!!l.saved_id} /></td>
              <td><span className="muted" style={{ fontSize: 12 }}>{l.project_text || '—'}</span></td>
              <td>
                <select
                  className="input"
                  value={l.placement_id}
                  onChange={(e) => onLineChange(l.tmpId, { placement_id: e.target.value ? Number(e.target.value) : '' })}
                  disabled={!!l.saved_id || (bulk && !group.person_id)}
                  style={{ minWidth: 200 }}
                >
                  <option value="">— pick placement —</option>
                  {placementOpts.map((o) => <option key={o.id} value={o.id}>{o.label}</option>)}
                </select>
              </td>
              <td>
                <select className="input" value={l.category} onChange={(e) => onLineChange(l.tmpId, { category: e.target.value })} disabled={!!l.saved_id}>
                  {CATS.map((c) => <option key={c} value={c}>{c}</option>)}
                </select>
              </td>
              <td><input className="input" type="number" step="0.25" min="0" value={l.hours} onChange={(e) => onLineChange(l.tmpId, { hours: e.target.value })} disabled={!!l.saved_id} style={{ width: 80 }} /></td>
              <td><input className="input" value={l.description} onChange={(e) => onLineChange(l.tmpId, { description: e.target.value })} disabled={!!l.saved_id} style={{ minWidth: 160 }} /></td>
              <td style={{ fontSize: 12 }}>
                {l.saved_id ? <span className="badge badge--approved">saved #{l.saved_id}</span>
                  : l.save_error ? <span style={{ color: '#991b1b' }}>{l.save_error}</span>
                  : <span className="muted">draft</span>}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function PersonPicker({ tmpId, initialName, initialId, candidates, onPick }) {
  const [query, setQuery]   = useState(initialName || '');
  const [results, setResults] = useState([]);
  const [open, setOpen]     = useState(false);
  const [pickedLabel, setPickedLabel] = useState(() => {
    if (initialId && candidates) {
      const c = candidates.find((c) => c.id === initialId);
      return c ? c.name : '';
    }
    return '';
  });

  const search = async (q) => {
    setQuery(q);
    if (!q || q.length < 2) { setResults(candidates || []); return; }
    try {
      const r = await api.get(`/modules/people/api/people.php?q=${encodeURIComponent(q)}&per_page=10`);
      setResults(r.rows || []);
    } catch (_) {}
  };

  const pick = (id, label) => { onPick(id); setPickedLabel(label); setOpen(false); };

  return (
    <div style={{ position: 'relative' }} data-testid={`time-upload-person-${tmpId}`}>
      {pickedLabel ? (
        <span style={{ fontSize: 13 }}>
          <strong>{pickedLabel}</strong>{' '}
          <button type="button" onClick={() => { setPickedLabel(''); onPick(''); setOpen(true); }} style={{ background: 'transparent', border: 'none', color: '#2563eb', cursor: 'pointer', fontSize: 12 }} data-testid={`time-upload-person-${tmpId}-change`}>change</button>
        </span>
      ) : (
        <input
          className="input"
          value={query}
          onFocus={() => { setOpen(true); if (candidates?.length && !results.length) setResults(candidates); }}
          onBlur={() => setTimeout(() => setOpen(false), 200)}
          onChange={(e) => search(e.target.value)}
          placeholder="Type to search people…"
          data-testid={`time-upload-person-${tmpId}-input`}
          style={{ width: 220 }}
        />
      )}
      {open && results.length > 0 && (
        <ul data-testid={`time-upload-person-${tmpId}-results`} style={{ position: 'absolute', zIndex: 10, top: '100%', left: 0, right: 0, background: '#fff', border: '1px solid #e5e7eb', borderRadius: 4, listStyle: 'none', margin: 0, padding: 0, maxHeight: 240, overflowY: 'auto' }}>
          {results.map((r) => {
            const label = r.name || `${r.first_name || ''} ${r.last_name || ''}`.trim();
            return (
              <li
                key={r.id}
                onMouseDown={(e) => { e.preventDefault(); pick(r.id, label); }}
                data-testid={`time-upload-person-${tmpId}-result-${r.id}`}
                style={{ padding: '6px 10px', cursor: 'pointer' }}
              >
                {label}
                {r.email_primary && <span className="muted" style={{ fontSize: 11, marginLeft: 8 }}>· {r.email_primary}</span>}
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}

function normaliseLine(l) {
  return {
    tmpId:        nextTmpId(),
    work_date:    l.work_date || '',
    project_text: l.project || '',
    placement_id: '',
    category:     CATS.includes(l.category) ? l.category : 'regular_billable',
    hours:        Number(l.hours) > 0 ? Number(l.hours) : 0,
    description:  l.description || '',
    include:      true,
    saved_id:     null,
    save_error:   null,
  };
}

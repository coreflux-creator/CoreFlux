import React, { useState, useEffect, useRef } from 'react';
import { api } from '../lib/api';
import { Pencil, Check, X as XIcon } from 'lucide-react';

/**
 * KpiNote — inline editable operator annotation for a KPI tile.
 *
 * Reads from /api/kpi_notes.php once (parent passes the whole map down).
 * Empty/unset notes render as a faint "Add note" affordance for managers
 * and render nothing at all for line staff.
 *
 * Props:
 *   noteKey     — string key (matches the row in tenant_kpi_notes.note_key)
 *   note        — { text, updated_at } | null
 *   canWrite    — bool
 *   onSaved(k, text) — parent updates its cache after a successful save
 */
export default function KpiNote({ noteKey, note, canWrite, onSaved }) {
  const [editing, setEditing] = useState(false);
  const [text, setText]       = useState(note?.text || '');
  const [busy, setBusy]       = useState(false);
  const inputRef = useRef(null);

  useEffect(() => { setText(note?.text || ''); }, [note?.text]);
  useEffect(() => { if (editing && inputRef.current) inputRef.current.focus(); }, [editing]);

  const save = async () => {
    setBusy(true);
    try {
      await api.post('/api/kpi_notes.php', { key: noteKey, text: text.trim() });
      onSaved?.(noteKey, text.trim());
      setEditing(false);
    } catch (e) { alert(`Could not save note: ${e.message}`); }
    finally   { setBusy(false); }
  };

  const cancel = () => { setText(note?.text || ''); setEditing(false); };

  if (!editing) {
    if (!note?.text) {
      if (!canWrite) return null;
      return (
        <button
          type="button"
          onClick={() => setEditing(true)}
          data-testid={`kpi-note-add-${noteKey}`}
          style={{
            marginTop: 6, padding: 0, background: 'none', border: 0,
            color: 'var(--cf-text-muted, #94a3b8)', fontSize: 11,
            cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: 4,
          }}
        >
          <Pencil size={10} /> Add note
        </button>
      );
    }
    return (
      <div
        data-testid={`kpi-note-${noteKey}`}
        style={{ marginTop: 6, padding: '6px 8px', background: '#fef9c3', borderRadius: 4, fontSize: 11, color: '#713f12', display: 'flex', gap: 6, alignItems: 'flex-start' }}
      >
        <span style={{ flex: 1 }}>{note.text}</span>
        {canWrite && (
          <button
            type="button"
            onClick={() => setEditing(true)}
            data-testid={`kpi-note-edit-${noteKey}`}
            title="Edit note"
            style={{ background: 'none', border: 0, padding: 0, cursor: 'pointer', color: '#713f12' }}
          >
            <Pencil size={11} />
          </button>
        )}
      </div>
    );
  }

  return (
    <div data-testid={`kpi-note-editor-${noteKey}`} style={{ marginTop: 6, display: 'flex', gap: 4, alignItems: 'center' }}>
      <input
        ref={inputRef}
        type="text"
        value={text}
        maxLength={280}
        onChange={(e) => setText(e.target.value)}
        onKeyDown={(e) => { if (e.key === 'Enter') save(); if (e.key === 'Escape') cancel(); }}
        placeholder="Leave context for the team…"
        data-testid={`kpi-note-input-${noteKey}`}
        disabled={busy}
        style={{ flex: 1, fontSize: 11, padding: '4px 6px', border: '1px solid #e2e8f0', borderRadius: 4 }}
      />
      <button type="button" onClick={save}   disabled={busy} title="Save (Enter)"  data-testid={`kpi-note-save-${noteKey}`}
              style={{ background: '#16a34a', color: '#fff', border: 0, borderRadius: 3, padding: '3px 6px', cursor: 'pointer' }}>
        <Check size={12} />
      </button>
      <button type="button" onClick={cancel} disabled={busy} title="Cancel (Esc)"  data-testid={`kpi-note-cancel-${noteKey}`}
              style={{ background: '#e5e7eb', color: '#0f172a', border: 0, borderRadius: 3, padding: '3px 6px', cursor: 'pointer' }}>
        <XIcon size={12} />
      </button>
    </div>
  );
}

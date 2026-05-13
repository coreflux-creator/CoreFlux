import React, { useState } from 'react';
import { useApi } from '../../../dashboard/src/lib/api';
import { ChevronDown, ChevronRight, GitBranch, Sparkles, FileText, User, Bot } from 'lucide-react';

/**
 * JE Trace pane — drills a posted journal entry back through:
 *   1. The originating business event (via accounting_subledger_links)
 *   2. The lineage chain upward (parent events) and downward (children)
 *   3. Every AI interpretation row for every event in the chain
 *
 * Answers the CPA question: "Why was this amount booked to that account?"
 * in one click. Lazy-loaded — only fetches when expanded.
 */
export default function JeTracePane({ jeId }) {
  const [open, setOpen] = useState(false);
  const { data, loading, error } = useApi(open ? `/api/accounting/je_trace.php?je_id=${jeId}` : null);

  return (
    <section data-testid="je-trace-pane" style={{ marginTop: 24, border: '1px solid #e2e8f0', borderRadius: 8, padding: 16 }}>
      <button
        type="button" onClick={() => setOpen(!open)}
        data-testid="je-trace-toggle"
        style={{
          all: 'unset', cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 8,
          fontSize: 14, fontWeight: 600, color: '#0f172a', width: '100%',
        }}>
        {open ? <ChevronDown size={16}/> : <ChevronRight size={16}/>}
        <GitBranch size={16}/> Trace this entry
        <span style={{ fontWeight: 400, color: '#64748b', fontSize: 12 }}>
          (source event + lineage + AI interpretations)
        </span>
      </button>

      {open && loading && <p style={{ marginTop: 12, color: '#94a3b8' }} data-testid="je-trace-loading">Loading lineage…</p>}
      {open && error && <p style={{ marginTop: 12, color: '#dc2626' }} data-testid="je-trace-error">Trace failed: {error.message}</p>}
      {open && data && <TraceBody trace={data}/>}
    </section>
  );
}

function TraceBody({ trace }) {
  const { source_event: src, ancestors = [], descendants = [], interpretations = {}, exceptions = {}, evidence = {} } = trace || {};
  if (!src) {
    return <p style={{ marginTop: 12, color: '#94a3b8' }} data-testid="je-trace-no-source">
      No originating event found. This may be a manual or pre-rails journal entry.
    </p>;
  }

  const ancRev = [...ancestors].sort((a, b) => b.depth - a.depth);

  return (
    <div style={{ marginTop: 16, display: 'flex', flexDirection: 'column', gap: 8 }} data-testid="je-trace-body">
      {ancRev.map(node => (
        <EventNode key={`anc-${node.related_event_id}`} node={node}
                   interpretations={interpretations[node.related_event_id]}
                   exceptions={exceptions[node.related_event_id]}
                   evidence={evidence[node.related_event_id]}
                   kind="ancestor"/>
      ))}
      <EventNode node={{
        related_event_id: src.id, event_type: src.event_type, source_module: src.source_module,
        source_record_id: src.source_record_id, event_date: src.event_date, status: src.status,
        journal_entry_id: src.journal_entry_id, payload: src.payload, depth: 0,
      }} interpretations={interpretations[src.id]} exceptions={exceptions[src.id]} evidence={evidence[src.id]} kind="source"/>
      {descendants.map(node => (
        <EventNode key={`dsc-${node.related_event_id}`} node={node}
                   interpretations={interpretations[node.related_event_id]}
                   exceptions={exceptions[node.related_event_id]}
                   evidence={evidence[node.related_event_id]}
                   kind="descendant"/>
      ))}
    </div>
  );
}

function EventNode({ node, interpretations = [], exceptions = [], evidence = [], kind }) {
  const isSource    = kind === 'source';
  const color       = isSource ? '#2563eb' : (kind === 'ancestor' ? '#0891b2' : '#7c3aed');
  const accepted    = interpretations.find(i => i.status === 'accepted');
  const proposed    = interpretations.filter(i => i.status === 'proposed');
  const overridden  = interpretations.filter(i => i.status === 'overridden');

  return (
    <div data-testid={`je-trace-event-${node.related_event_id}`}
         style={{
           borderLeft: `3px solid ${color}`, paddingLeft: 12, paddingTop: 6, paddingBottom: 6,
           background: isSource ? '#eff6ff' : '#f8fafc', borderRadius: '0 6px 6px 0',
         }}>
      <div style={{ display: 'flex', alignItems: 'baseline', gap: 8, flexWrap: 'wrap' }}>
        <strong style={{ fontSize: 13, fontFamily: 'monospace', color }}>{node.event_type}</strong>
        <span style={{ fontSize: 11, color: '#64748b' }}>
          #{node.related_event_id} · {node.event_date} · {node.source_module}:{node.source_record_id}
        </span>
        {isSource && <span data-testid="je-trace-source-tag" style={{ fontSize: 10, padding: '1px 6px', background: color, color: '#fff', borderRadius: 4, fontWeight: 600 }}>SOURCE</span>}
        {!isSource && <span style={{ fontSize: 10, padding: '1px 6px', background: color, color: '#fff', borderRadius: 4 }}>{kind} (depth {node.depth})</span>}
        {node.relationship_type && <code style={{ fontSize: 10, color: '#64748b' }}>{node.relationship_type}</code>}
        {node.journal_entry_id && <a href={`/modules/accounting/journal-entries/${node.journal_entry_id}`} style={{ fontSize: 11, color: '#2563eb' }}>JE #{node.journal_entry_id}</a>}
      </div>
      {interpretations.length === 0 && (
        <p style={{ margin: '4px 0 0', fontSize: 11, color: '#94a3b8' }}>No interpretations recorded.</p>
      )}
      {interpretations.length > 0 && (
        <div style={{ marginTop: 6, display: 'flex', flexDirection: 'column', gap: 4 }}>
          {accepted && <InterpretationRow row={accepted} highlight />}
          {proposed.map(r => <InterpretationRow key={r.id} row={r}/>)}
          {overridden.map(r => <InterpretationRow key={r.id} row={r}/>)}
        </div>
      )}
      {exceptions.length > 0 && (
        <div style={{ marginTop: 6, display: 'flex', flexDirection: 'column', gap: 4 }}
             data-testid={`je-trace-exceptions-${node.related_event_id}`}>
          {exceptions.map(ex => <ExceptionRow key={ex.id} row={ex}/>)}
        </div>
      )}
      {evidence.length > 0 && (
        <div style={{ marginTop: 6, display: 'flex', flexWrap: 'wrap', gap: 4 }}
             data-testid={`je-trace-evidence-${node.related_event_id}`}>
          {evidence.map(ev => <EvidenceChip key={ev.id} row={ev}/>)}
        </div>
      )}
    </div>
  );
}

function EvidenceChip({ row }) {
  return (
    <span data-testid={`je-trace-evidence-chip-${row.id}`}
          title={[row.document_type, row.content_type, row.size_bytes ? `${row.size_bytes} bytes` : null].filter(Boolean).join(' · ')}
          style={{
            display: 'inline-flex', alignItems: 'center', gap: 4,
            padding: '3px 8px', borderRadius: 12, background: '#f1f5f9',
            border: '1px solid #cbd5e1', fontSize: 11, color: '#0f172a',
          }}>
      <FileText size={11}/>
      <strong style={{ fontSize: 10, textTransform: 'uppercase', letterSpacing: '.04em', color: '#64748b' }}>
        {row.document_type}
      </strong>
      {row.label && <span>{row.label}</span>}
      {row.source && <em style={{ color: '#94a3b8', fontSize: 10 }}>· {row.source}</em>}
    </span>
  );
}

function ExceptionRow({ row }) {
  const sevColor = {
    critical: '#7f1d1d', high: '#dc2626', warn: '#ca8a04', info: '#64748b',
  }[row.severity] || '#64748b';
  const statusColor = {
    open: '#dc2626', snoozed: '#ca8a04', resolved: '#16a34a', dismissed: '#94a3b8',
  }[row.status] || '#64748b';
  return (
    <div data-testid={`je-trace-exception-${row.id}`}
         style={{
           padding: '6px 8px', borderRadius: 4, fontSize: 11,
           background: '#fef3c7', border: `1px solid ${sevColor}`,
         }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 6, flexWrap: 'wrap' }}>
        <strong style={{ color: sevColor, textTransform: 'uppercase', fontSize: 10 }}>
          ⚠ {row.severity} · {row.source}
        </strong>
        <span style={{ color: statusColor, fontWeight: 600, textTransform: 'uppercase', fontSize: 10 }}>{row.status}</span>
        <span style={{ color: '#94a3b8' }}>{row.opened_at}</span>
        {row.resolved_at && <span style={{ color: '#94a3b8' }}>resolved {row.resolved_at}</span>}
        {row.assigned_user_id && <span style={{ color: '#94a3b8' }}>assigned to user #{row.assigned_user_id}</span>}
      </div>
      <div style={{ marginTop: 3, color: '#0f172a' }}>{row.title}</div>
      {row.resolution_note && (
        <div style={{ marginTop: 4, padding: '4px 6px', background: '#fff', borderLeft: `2px solid ${statusColor}`, borderRadius: 2 }}>
          <strong style={{ fontSize: 10, color: statusColor }}>Resolution:</strong>{' '}
          <span style={{ color: '#0f172a' }}>{row.resolution_note}</span>
        </div>
      )}
    </div>
  );
}

function InterpretationRow({ row, highlight }) {
  const isAi   = row.proposed_by?.startsWith('ai:');
  const isRule = row.proposed_by?.startsWith('posting_rule:');
  const Icon   = isAi ? Sparkles : (isRule ? FileText : User);
  const statusColor = {
    accepted:   '#16a34a',
    proposed:   '#ca8a04',
    overridden: '#7c3aed',
    rejected:   '#dc2626',
    superseded: '#94a3b8',
  }[row.status] || '#64748b';

  return (
    <div data-testid={`je-trace-interp-${row.id}`}
         style={{
           padding: '6px 8px', borderRadius: 4, fontSize: 11,
           background: highlight ? '#dcfce7' : '#fff',
           border: '1px solid #e2e8f0',
         }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 6, flexWrap: 'wrap' }}>
        <Icon size={11}/>
        <strong style={{ color: '#0f172a' }}>{row.proposed_by}</strong>
        <span style={{ color: statusColor, fontWeight: 600, textTransform: 'uppercase', fontSize: 10 }}>{row.status}</span>
        <span style={{ color: '#94a3b8' }}>confidence {(Number(row.confidence) * 100).toFixed(1)}%</span>
        <span style={{ color: '#94a3b8' }}>{row.proposed_at}</span>
        {row.reviewer_user_id && <span style={{ color: '#94a3b8' }}>reviewed by user #{row.reviewer_user_id}</span>}
      </div>
      {row.typical_accounting_hint && (
        <div style={{ marginTop: 4, color: '#475569' }}>
          <em>Registry hint:</em> {row.typical_accounting_hint}
        </div>
      )}
      {row.reasoning && <div style={{ marginTop: 4, color: '#0f172a' }}>{row.reasoning}</div>}
      {row.review_disposition && <div style={{ marginTop: 4, color: '#475569' }}><strong>Reviewer:</strong> {row.review_disposition}</div>}
      {row.proposed_je?.lines?.length > 0 && (
        <table style={{ marginTop: 6, fontSize: 10, width: '100%', borderCollapse: 'collapse' }}>
          <thead><tr style={{ background: '#f1f5f9' }}>
            <th style={{ textAlign: 'left', padding: 3 }}>Account</th>
            <th style={{ textAlign: 'right', padding: 3 }}>Dr</th>
            <th style={{ textAlign: 'right', padding: 3 }}>Cr</th>
          </tr></thead>
          <tbody>
            {row.proposed_je.lines.map((l, i) => (
              <tr key={i}>
                <td style={{ padding: 3 }}><code>{l.account_code}</code></td>
                <td style={{ padding: 3, textAlign: 'right' }}>{Number(l.debit  || 0).toFixed(2)}</td>
                <td style={{ padding: 3, textAlign: 'right' }}>{Number(l.credit || 0).toFixed(2)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}

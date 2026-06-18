import React, { useEffect, useMemo, useState } from 'react';
import { api } from '../lib/api';

/**
 * IntegrationTriage — single operator inbox for every cross-integration
 * action item.
 *
 * Pulls from /api/admin/integration_triage.php which aggregates:
 *   • QBO push DLQ        → qbo_push_failures (retrying + dead_letter)
 *   • QBO sync drift      → qbo_sync_drift (open)
 *   • Mercury Failed PIs  → payment_instructions WHERE state IN ('Failed','Returned')
 *
 * Each row gets:
 *   • Severity pill (critical / warn / info)
 *   • Source chip
 *   • Summary + playbook suggested_fix (collapsed by default)
 *   • Action button (requeue / resolve / cancel) that hits the per-source
 *     POST endpoint so the source keeps its own audit + validation logic.
 */
export default function IntegrationTriage() {
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState({ severity: 'all', source: 'all' });
  const [expanded, setExpanded] = useState(() => new Set());
  const [busy, setBusy] = useState(null); // id of row currently being actioned

  const load = async () => {
    setLoading(true);
    setError(null);
    try {
      const r = await api.get('/api/admin/integration_triage.php');
      setData(r);
    } catch (e) {
      setError(e.message || 'Failed to load triage queue');
    } finally {
      setLoading(false);
    }
  };
  useEffect(() => {
    load();
  }, []);

  const filtered = useMemo(() => {
    const items = data?.items ?? [];
    return items.filter(
      (i) =>
        (filter.severity === 'all' || i.severity === filter.severity) &&
        (filter.source === 'all' || i.source === filter.source),
    );
  }, [data, filter]);

  const toggle = (key) => {
    setExpanded((prev) => {
      const n = new Set(prev);
      if (n.has(key)) n.delete(key);
      else n.add(key);
      return n;
    });
  };

  const handleAction = async (item) => {
    setBusy(`${item.source}:${item.id}`);
    try {
      if (item.source === 'qbo-dlq') {
        const reason = prompt('Reason for requeue?');
        if (!reason) return;
        await api.post('/api/admin/qbo/dead_letters.php', {
          tenant_id: item.tenant_id,
          entity_type: item.meta.entity_type,
          source_id: item.meta.source_id,
        });
      } else if (item.source === 'qbo-drift') {
        const note = prompt('Resolution note (optional):') || '';
        await api.post('/api/admin/qbo/sync_drift.php', {
          drift_id: item.id,
          resolution: 'reconciled',
          note,
        });
      } else if (item.source === 'mercury-failed') {
        const reason = prompt('Reason for requeue?');
        if (!reason) return;
        await api.post('/api/admin/mercury/failed_payments.php', {
          tenant_id: item.tenant_id,
          instruction_id: item.id,
          reason,
        });
      }
      await load();
    } catch (e) {
      alert(`Action failed: ${e.message || e}`);
    } finally {
      setBusy(null);
    }
  };

  return (
    <section
      data-testid="integration-triage-page"
      style={{
        padding: 'var(--cf-space-4, 16px)',
        maxWidth: 1280,
        margin: '0 auto',
      }}
    >
      <header style={{ marginBottom: 16 }}>
        <h1 style={{ margin: 0, fontSize: 22 }}>Integration triage</h1>
        <p style={{ margin: '4px 0 0', color: 'var(--cf-text-secondary, #6b7280)', fontSize: 13 }}>
          Cross-provider action items: push failures, sync drift, failed payments — all in one inbox.
        </p>
      </header>

      {data?.counts && <CountsBar counts={data.counts} />}

      <FilterRow filter={filter} setFilter={setFilter} counts={data?.counts} />

      {loading && <div style={{ padding: 24, color: '#6b7280' }}>Loading…</div>}
      {error && (
        <div style={{ padding: 12, background: '#fee2e2', color: '#991b1b', borderRadius: 6 }}>
          {error}
        </div>
      )}

      {!loading && !error && (
        <div data-testid="integration-triage-list" style={{ marginTop: 12 }}>
          {filtered.length === 0 ? (
            <EmptyState />
          ) : (
            filtered.map((item) => {
              const key = `${item.source}:${item.id}`;
              const open = expanded.has(key);
              return (
                <TriageRow
                  key={key}
                  item={item}
                  expanded={open}
                  busy={busy === key}
                  onToggle={() => toggle(key)}
                  onAction={() => handleAction(item)}
                />
              );
            })
          )}
        </div>
      )}

      <div style={{ marginTop: 16 }}>
        <button
          type="button"
          onClick={load}
          data-testid="integration-triage-refresh"
          style={btnSecondary()}
        >
          Refresh
        </button>
      </div>
    </section>
  );
}

function CountsBar({ counts }) {
  return (
    <div
      data-testid="integration-triage-counts"
      style={{
        display: 'flex',
        gap: 8,
        marginBottom: 12,
        flexWrap: 'wrap',
      }}
    >
      <CountPill label="Critical" n={counts.critical} bg="#fee2e2" fg="#991b1b" testid="critical" />
      <CountPill label="Warn"     n={counts.warn}     bg="#fef3c7" fg="#92400e" testid="warn" />
      <CountPill label="Info"     n={counts.info}     bg="#dbeafe" fg="#1e40af" testid="info" />
      <CountPill label="Total"    n={counts.total}    bg="#f3f4f6" fg="#374151" testid="total" bold />
    </div>
  );
}

function CountPill({ label, n, bg, fg, testid, bold }) {
  return (
    <span
      data-testid={`integration-triage-count-${testid}`}
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: 6,
        padding: '4px 10px',
        borderRadius: 999,
        background: bg,
        color: fg,
        fontSize: 12,
        fontWeight: bold ? 700 : 500,
      }}
    >
      {label}: <span style={{ fontVariantNumeric: 'tabular-nums', fontWeight: 700 }}>{n}</span>
    </span>
  );
}

function FilterRow({ filter, setFilter, counts }) {
  const sourceLabels = {
    'qbo-dlq': `QBO push DLQ${counts ? ` (${counts.by_source['qbo-dlq']})` : ''}`,
    'qbo-drift': `QBO drift${counts ? ` (${counts.by_source['qbo-drift']})` : ''}`,
    'mercury-failed': `Mercury Failed${counts ? ` (${counts.by_source['mercury-failed']})` : ''}`,
  };
  return (
    <div style={{ display: 'flex', gap: 12, marginBottom: 12, fontSize: 13 }}>
      <select
        value={filter.severity}
        onChange={(e) => setFilter((f) => ({ ...f, severity: e.target.value }))}
        data-testid="integration-triage-filter-severity"
        style={selectStyle()}
      >
        <option value="all">All severities</option>
        <option value="critical">Critical</option>
        <option value="warn">Warn</option>
        <option value="info">Info</option>
      </select>
      <select
        value={filter.source}
        onChange={(e) => setFilter((f) => ({ ...f, source: e.target.value }))}
        data-testid="integration-triage-filter-source"
        style={selectStyle()}
      >
        <option value="all">All sources</option>
        {Object.entries(sourceLabels).map(([k, v]) => (
          <option key={k} value={k}>
            {v}
          </option>
        ))}
      </select>
    </div>
  );
}

function TriageRow({ item, expanded, busy, onToggle, onAction }) {
  const sevColor = {
    critical: { bg: '#fee2e2', fg: '#991b1b', border: '#fca5a5' },
    warn:     { bg: '#fef3c7', fg: '#92400e', border: '#fcd34d' },
    info:     { bg: '#dbeafe', fg: '#1e40af', border: '#93c5fd' },
  }[item.severity] || { bg: '#f3f4f6', fg: '#374151', border: '#d1d5db' };

  return (
    <div
      data-testid={`integration-triage-row-${item.source}-${item.id}`}
      style={{
        border: `1px solid ${sevColor.border}`,
        borderLeft: `4px solid ${sevColor.border}`,
        borderRadius: 6,
        background: 'white',
        padding: 12,
        marginBottom: 8,
      }}
    >
      <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
        <span
          data-testid={`integration-triage-severity-${item.severity}`}
          style={{
            padding: '2px 8px',
            borderRadius: 4,
            background: sevColor.bg,
            color: sevColor.fg,
            fontSize: 11,
            fontWeight: 700,
            textTransform: 'uppercase',
          }}
        >
          {item.severity}
        </span>
        <span
          style={{
            padding: '2px 8px',
            borderRadius: 4,
            background: '#e0e7ff',
            color: '#3730a3',
            fontSize: 11,
            fontWeight: 600,
          }}
        >
          {item.source}
        </span>
        <span style={{ flex: 1, fontSize: 13, fontWeight: 500 }}>{item.summary}</span>
        {item.actionable && (
          <button
            type="button"
            disabled={busy}
            onClick={onAction}
            data-testid={`integration-triage-action-${item.source}-${item.id}`}
            style={btnPrimary(busy)}
          >
            {busy ? '…' : labelFor(item.actionable)}
          </button>
        )}
        <button
          type="button"
          onClick={onToggle}
          data-testid={`integration-triage-toggle-${item.source}-${item.id}`}
          style={btnSecondary()}
        >
          {expanded ? '▾' : '▸'}
        </button>
      </div>

      {expanded && (
        <div style={{ marginTop: 10, fontSize: 12, color: '#374151' }}>
          {item.playbook?.suggested_fix && (
            <div style={{ marginBottom: 6 }}>
              <strong>Suggested fix:</strong> {item.playbook.suggested_fix}
            </div>
          )}
          {item.playbook?.docs_link && (
            <div style={{ marginBottom: 6 }}>
              <a href={item.playbook.docs_link} target="_blank" rel="noreferrer">
                Vendor docs ↗
              </a>
            </div>
          )}
          {item.meta?.vendor_raw && (
            <details style={{ marginTop: 4 }}>
              <summary style={{ cursor: 'pointer', color: '#6b7280' }}>Raw vendor body</summary>
              <pre
                style={{
                  background: '#f9fafb',
                  padding: 8,
                  borderRadius: 4,
                  overflow: 'auto',
                  maxHeight: 200,
                  fontSize: 11,
                }}
              >
                {item.meta.vendor_raw}
              </pre>
            </details>
          )}
          <details style={{ marginTop: 4 }}>
            <summary style={{ cursor: 'pointer', color: '#6b7280' }}>Full payload</summary>
            <pre
              style={{
                background: '#f9fafb',
                padding: 8,
                borderRadius: 4,
                overflow: 'auto',
                maxHeight: 240,
                fontSize: 11,
              }}
            >
              {JSON.stringify(item.meta, null, 2)}
            </pre>
          </details>
        </div>
      )}
    </div>
  );
}

function EmptyState() {
  return (
    <div
      data-testid="integration-triage-empty"
      style={{
        padding: 32,
        textAlign: 'center',
        background: '#f0fdf4',
        border: '1px solid #bbf7d0',
        borderRadius: 8,
        color: '#166534',
      }}
    >
      <div style={{ fontSize: 32, marginBottom: 8 }}>✓</div>
      <div style={{ fontWeight: 600 }}>All clear</div>
      <div style={{ fontSize: 12, color: '#15803d', marginTop: 4 }}>
        No open push failures, drift, or failed payments across QBO + Mercury.
      </div>
    </div>
  );
}

function labelFor(act) {
  if (act === 'requeue') return 'Requeue';
  if (act === 'resolve') return 'Mark reconciled';
  if (act === 'cancel') return 'Cancel';
  return 'Action';
}
function btnPrimary(busy) {
  return {
    padding: '4px 10px',
    fontSize: 12,
    background: busy ? '#9ca3af' : '#2563eb',
    color: 'white',
    border: 'none',
    borderRadius: 4,
    cursor: busy ? 'wait' : 'pointer',
    fontWeight: 600,
  };
}
function btnSecondary() {
  return {
    padding: '4px 8px',
    fontSize: 12,
    background: 'white',
    color: '#374151',
    border: '1px solid #d1d5db',
    borderRadius: 4,
    cursor: 'pointer',
  };
}
function selectStyle() {
  return {
    padding: '4px 8px',
    borderRadius: 4,
    border: '1px solid #d1d5db',
    fontSize: 13,
    background: 'white',
  };
}

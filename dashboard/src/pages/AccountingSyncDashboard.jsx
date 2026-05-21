import React from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../lib/api';
import {
  BookOpen, CheckCircle2, XCircle, AlertTriangle, ExternalLink,
  ArrowRight, ArrowLeft, ArrowLeftRight, MinusCircle, Activity,
  TrendingUp, Layers,
} from 'lucide-react';

/**
 * AccountingSyncDashboard — single pane that surfaces QBO + Zoho Books
 * side-by-side. Mounted at /admin/integrations/accounting-sync.
 *
 * Sections:
 *   1. Two big "system" tiles (one per integration) with connection
 *      summary + quick-link to that system's settings.
 *   2. Coverage scorecard: how many entities are covered in both,
 *      QBO-only, Zoho-only, neither.
 *   3. Per-entity drift table: direction + last-sync time on each side,
 *      colour-coded drift signal.
 *   4. Unified activity feed: merged audit rows from both systems.
 */
export default function AccountingSyncDashboard() {
  const { data, loading, error } = useApi('/api/admin/accounting_sync_dashboard.php');

  if (loading) return <div data-testid="acct-sync-loading">Loading sync dashboard…</div>;
  if (error)   return <div data-testid="acct-sync-error" style={{ color: 'var(--cf-red, #b91c1c)' }}>Failed to load: {error.message || String(error)}</div>;
  if (!data)   return null;

  const { qbo, zoho_books: zoho, entities = [], summary = {}, unified_activity: activity = [] } = data;

  return (
    <div data-testid="accounting-sync-dashboard" style={{ maxWidth: 1100 }}>
      <header style={{ marginBottom: 24 }}>
        <h1 style={{ fontSize: 'var(--cf-text-2xl)', fontWeight: 700, marginBottom: 'var(--cf-space-2)' }}>
          Accounting sync dashboard
        </h1>
        <p style={{ color: 'var(--cf-text-secondary)', fontSize: 13 }}>
          Side-by-side view of QuickBooks Online and Zoho Books for this tenant.
          Use the coverage scorecard to spot entities that drift between systems
          or that haven't been opted into either rail.
        </p>
      </header>

      {/* ─────────────────────── system tiles ─────────────────────── */}
      <div
        data-testid="acct-sync-system-tiles"
        style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 16, marginBottom: 24 }}
      >
        <SystemTile
          testid="acct-sync-tile-qbo"
          system="qbo"
          title="QuickBooks Online"
          settingsHref="/admin/integrations/qbo"
          data={qbo}
          identityRow={{ label: 'Company', value: qbo?.company_name }}
          identityRow2={{ label: 'Realm', value: qbo?.realm_id, mono: true }}
          identityRow3={{ label: 'Env', value: qbo?.environment }}
        />
        <SystemTile
          testid="acct-sync-tile-zoho"
          system="zoho_books"
          title="Zoho Books"
          settingsHref="/admin/integrations/zoho-books"
          data={zoho}
          identityRow={{ label: 'Organization', value: zoho?.organization_name }}
          identityRow2={{ label: 'Org ID', value: zoho?.organization_id, mono: true }}
          identityRow3={{ label: 'Region', value: zoho?.dc }}
        />
      </div>

      {/* ─────────────────────── coverage scorecard ─────────────────────── */}
      <CoverageScorecard summary={summary} />

      {/* ─────────────────────── drift table ─────────────────────── */}
      <section
        data-testid="acct-sync-drift-table-section"
        className="card"
        style={{ padding: 16, border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8, marginBottom: 24 }}
      >
        <h3 style={{ margin: 0, fontSize: 15, fontWeight: 600 }}>
          <TrendingUp size={14} style={{ verticalAlign: 'middle', marginRight: 6 }} />
          Per-entity drift
        </h3>
        <p style={{ margin: '4px 0 12px', fontSize: 12, color: 'var(--cf-text-secondary)' }}>
          A signal of <strong>QBO ahead</strong> / <strong>Zoho ahead</strong> means the last successful sync on
          that side is ≥ 24 hours newer than the other — reconcile manually or flip a direction so they catch up.
        </p>
        <table data-testid="acct-sync-drift-table" style={{ width: '100%', fontSize: 13, borderCollapse: 'collapse' }}>
          <thead>
            <tr style={{ textAlign: 'left', color: 'var(--cf-text-secondary)', borderBottom: '1px solid var(--cf-border)' }}>
              <th style={{ padding: '8px 4px' }}>Entity</th>
              <th style={{ padding: '8px 4px' }}>QBO direction</th>
              <th style={{ padding: '8px 4px' }}>QBO last sync</th>
              <th style={{ padding: '8px 4px' }}>Zoho direction</th>
              <th style={{ padding: '8px 4px' }}>Zoho last sync</th>
              <th style={{ padding: '8px 4px' }}>Signal</th>
            </tr>
          </thead>
          <tbody>
            {entities.map((e) => (
              <EntityRow key={e.key} entity={e} />
            ))}
          </tbody>
        </table>
      </section>

      {/* ─────────────────────── unified activity ─────────────────────── */}
      <section
        data-testid="acct-sync-activity-section"
        className="card"
        style={{ padding: 16, border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8 }}
      >
        <h3 style={{ margin: 0, fontSize: 15, fontWeight: 600 }}>
          <Activity size={14} style={{ verticalAlign: 'middle', marginRight: 6 }} />
          Unified activity ({activity.length})
        </h3>
        <p style={{ margin: '4px 0 12px', fontSize: 12, color: 'var(--cf-text-secondary)' }}>
          Most recent sync events across both systems, newest first.
        </p>
        {activity.length === 0 ? (
          <p data-testid="acct-sync-activity-empty" style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>
            No activity yet. Once a sync runs (cron or manual), events land here.
          </p>
        ) : (
          <table data-testid="acct-sync-activity-table" style={{ width: '100%', fontSize: 12, borderCollapse: 'collapse' }}>
            <thead>
              <tr style={{ textAlign: 'left', color: 'var(--cf-text-secondary)', borderBottom: '1px solid var(--cf-border)' }}>
                <th style={{ padding: '4px' }}>When</th>
                <th style={{ padding: '4px' }}>System</th>
                <th style={{ padding: '4px' }}>Action</th>
                <th style={{ padding: '4px' }}>Entity</th>
                <th style={{ padding: '4px', textAlign: 'right' }}>Done</th>
                <th style={{ padding: '4px', textAlign: 'right' }}>Skipped</th>
                <th style={{ padding: '4px', textAlign: 'right' }}>Failed</th>
                <th style={{ padding: '4px' }}>Result</th>
              </tr>
            </thead>
            <tbody>
              {activity.map((r, idx) => (
                <tr
                  key={`${r.system}-${r.id}-${idx}`}
                  data-testid={`acct-sync-activity-row-${r.system}-${r.id}`}
                  style={{ borderBottom: '1px solid var(--cf-border-muted, #f1f5f9)' }}
                >
                  <td style={{ padding: '4px', fontFamily: 'var(--cf-mono, ui-monospace)' }}>{r.occurred_at}</td>
                  <td style={{ padding: '4px' }}><SystemBadge system={r.system} /></td>
                  <td style={{ padding: '4px' }}>{r.action}</td>
                  <td style={{ padding: '4px', color: 'var(--cf-text-secondary)' }}>{r.entity_type || '—'}</td>
                  <td style={{ padding: '4px', textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{r.items_processed || 0}</td>
                  <td style={{ padding: '4px', textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{r.items_skipped || 0}</td>
                  <td style={{ padding: '4px', textAlign: 'right', fontVariantNumeric: 'tabular-nums', color: (r.items_failed || 0) > 0 ? 'var(--cf-red, #b91c1c)' : undefined }}>{r.items_failed || 0}</td>
                  <td style={{ padding: '4px', color: r.ok ? 'var(--cf-green, #047857)' : 'var(--cf-red, #b91c1c)' }}>{r.ok ? 'ok' : 'fail'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </section>
    </div>
  );
}

/* ─────────────────────────────────────────────────────────── system tile */

function SystemTile({ testid, system, title, settingsHref, data, identityRow, identityRow2, identityRow3 }) {
  const configured = !!data?.configured;
  const connected  = !!data?.connected;
  const state = !configured ? 'not_configured' : connected ? 'connected' : 'not_connected';

  const stateMeta = {
    connected:      { label: 'Connected',     bg: '#d1fae5', fg: '#065f46', Icon: CheckCircle2 },
    not_connected:  { label: 'Not connected', bg: '#fef3c7', fg: '#92400e', Icon: AlertTriangle },
    not_configured: { label: 'Not configured', bg: '#fee2e2', fg: '#991b1b', Icon: XCircle },
  }[state];
  const StateIcon = stateMeta.Icon;

  return (
    <div
      data-testid={testid}
      className="card"
      style={{
        padding: 16, border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8,
        background: 'var(--cf-surface, #fff)', display: 'flex', flexDirection: 'column', gap: 10,
      }}
    >
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 10 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <BookOpen size={22} style={{ color: 'var(--cf-blue, #2563eb)' }} />
          <div>
            <div style={{ fontWeight: 600 }}>{title}</div>
            <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>system: {system}</div>
          </div>
        </div>
        <span
          data-testid={`${testid}-state`}
          style={{
            fontSize: 11, fontWeight: 600, padding: '2px 8px', borderRadius: 4,
            background: stateMeta.bg, color: stateMeta.fg,
            display: 'inline-flex', alignItems: 'center', gap: 4,
          }}
        >
          <StateIcon size={11} />{stateMeta.label}
        </span>
      </header>

      {connected && (
        <dl style={{ display: 'grid', gridTemplateColumns: 'max-content 1fr', gap: '4px 12px', margin: 0, fontSize: 12 }}>
          {[identityRow, identityRow2, identityRow3].filter(Boolean).map((r, i) => (
            <React.Fragment key={i}>
              <dt style={{ color: 'var(--cf-text-secondary)' }}>{r.label}</dt>
              <dd style={{ margin: 0, fontFamily: r.mono ? 'var(--cf-mono, ui-monospace)' : undefined }}>
                {r.value || '—'}
              </dd>
            </React.Fragment>
          ))}
        </dl>
      )}

      {data?.last_probe_error && (
        <div
          data-testid={`${testid}-probe-error`}
          style={{ fontSize: 11, color: 'var(--cf-red, #b91c1c)' }}
        >
          <AlertTriangle size={11} style={{ verticalAlign: 'middle', marginRight: 4 }} />
          Last error: {data.last_probe_error}
        </div>
      )}

      {connected && data?.last_probe_at && (
        <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>
          Last probe: {data.last_probe_at}
        </div>
      )}

      <footer style={{ marginTop: 'auto' }}>
        <Link
          to={settingsHref}
          data-testid={`${testid}-settings-link`}
          style={{ fontSize: 12, color: 'var(--cf-accent, #2563eb)', display: 'inline-flex', alignItems: 'center', gap: 4 }}
        >
          Manage <ExternalLink size={11} />
        </Link>
      </footer>
    </div>
  );
}

/* ─────────────────────────────────────────────────────────── scorecard */

function CoverageScorecard({ summary }) {
  const tiles = [
    { key: 'both',      label: 'Synced both ways',  count: summary.both      || 0, fg: '#047857', bg: '#ecfdf5' },
    { key: 'qbo_only',  label: 'QBO only',          count: summary.qbo_only  || 0, fg: '#1d4ed8', bg: '#eff6ff' },
    { key: 'zoho_only', label: 'Zoho only',         count: summary.zoho_only || 0, fg: '#7c3aed', bg: '#f5f3ff' },
    { key: 'neither',   label: 'Neither (off)',     count: summary.neither   || 0, fg: '#92400e', bg: '#fef3c7' },
  ];
  return (
    <section
      data-testid="acct-sync-scorecard"
      style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 12, marginBottom: 24 }}
    >
      {tiles.map((t) => (
        <div
          key={t.key}
          data-testid={`acct-sync-score-${t.key}`}
          style={{
            background: t.bg, color: t.fg, borderRadius: 8, padding: '14px 16px',
            border: '1px solid rgba(0,0,0,0.04)',
          }}
        >
          <div style={{ fontSize: 28, fontWeight: 700, lineHeight: 1.1 }} data-testid={`acct-sync-score-${t.key}-count`}>
            {t.count}
          </div>
          <div style={{ fontSize: 12, fontWeight: 600, marginTop: 4, opacity: 0.85 }}>{t.label}</div>
        </div>
      ))}
      <div
        data-testid="acct-sync-score-total"
        style={{
          background: 'var(--cf-surface, #fff)', borderRadius: 8, padding: '14px 16px',
          border: '1px dashed var(--cf-border, #e5e7eb)',
        }}
      >
        <div style={{ fontSize: 28, fontWeight: 700, lineHeight: 1.1, color: 'var(--cf-text-secondary)' }}>
          {summary.total || 0}
        </div>
        <div style={{ fontSize: 12, fontWeight: 600, marginTop: 4, color: 'var(--cf-text-secondary)' }}>
          <Layers size={12} style={{ verticalAlign: 'middle', marginRight: 4 }} />Entities tracked
        </div>
      </div>
    </section>
  );
}

/* ─────────────────────────────────────────────────────────── entity row */

const DIR_META = {
  push:    { icon: ArrowRight,     label: 'Push' },
  pull:    { icon: ArrowLeft,      label: 'Pull' },
  two_way: { icon: ArrowLeftRight, label: 'Two-way' },
  off:     { icon: MinusCircle,    label: 'Off' },
};

const SIGNAL_META = {
  aligned:    { label: 'Aligned',     fg: '#047857', bg: '#ecfdf5' },
  qbo_ahead:  { label: 'QBO ahead',   fg: '#1d4ed8', bg: '#eff6ff' },
  zoho_ahead: { label: 'Zoho ahead',  fg: '#7c3aed', bg: '#f5f3ff' },
  one_sided:  { label: 'One-sided',   fg: '#92400e', bg: '#fef3c7' },
  inactive:   { label: 'Inactive',    fg: '#475569', bg: '#f1f5f9' },
};

function EntityRow({ entity }) {
  const qMeta = DIR_META[entity.qbo_dir]  || DIR_META.off;
  const zMeta = DIR_META[entity.zoho_dir] || DIR_META.off;
  const sig   = SIGNAL_META[entity.drift_signal] || SIGNAL_META.inactive;
  const QIcon = qMeta.icon; const ZIcon = zMeta.icon;
  return (
    <tr data-testid={`acct-sync-row-${entity.key}`} style={{ borderBottom: '1px solid var(--cf-border-muted, #f1f5f9)' }}>
      <td style={{ padding: '8px 4px', fontWeight: 500 }}>{entity.label}</td>
      <td style={{ padding: '8px 4px' }} data-testid={`acct-sync-qbo-dir-${entity.key}`}>
        <QIcon size={12} style={{ verticalAlign: 'middle', marginRight: 4 }} />{qMeta.label}
      </td>
      <td style={{ padding: '8px 4px', fontSize: 12, color: 'var(--cf-text-secondary)', fontFamily: 'var(--cf-mono, ui-monospace)' }} data-testid={`acct-sync-qbo-last-${entity.key}`}>
        {entity.qbo_last_sync || '—'}
        {entity.qbo_last_ok === false && (
          <AlertTriangle size={11} style={{ marginLeft: 4, verticalAlign: 'middle', color: 'var(--cf-red, #b91c1c)' }} />
        )}
      </td>
      <td style={{ padding: '8px 4px' }} data-testid={`acct-sync-zoho-dir-${entity.key}`}>
        <ZIcon size={12} style={{ verticalAlign: 'middle', marginRight: 4 }} />{zMeta.label}
      </td>
      <td style={{ padding: '8px 4px', fontSize: 12, color: 'var(--cf-text-secondary)', fontFamily: 'var(--cf-mono, ui-monospace)' }} data-testid={`acct-sync-zoho-last-${entity.key}`}>
        {entity.zoho_last_sync || '—'}
        {entity.zoho_last_ok === false && (
          <AlertTriangle size={11} style={{ marginLeft: 4, verticalAlign: 'middle', color: 'var(--cf-red, #b91c1c)' }} />
        )}
      </td>
      <td style={{ padding: '8px 4px' }}>
        <span
          data-testid={`acct-sync-signal-${entity.key}`}
          style={{
            fontSize: 11, fontWeight: 600, padding: '2px 8px', borderRadius: 4,
            background: sig.bg, color: sig.fg,
          }}
        >
          {sig.label}
        </span>
      </td>
    </tr>
  );
}

function SystemBadge({ system }) {
  const meta = system === 'qbo'
    ? { label: 'QBO',  bg: '#eff6ff', fg: '#1d4ed8' }
    : { label: 'Zoho', bg: '#f5f3ff', fg: '#7c3aed' };
  return (
    <span
      data-testid={`acct-sync-system-badge-${system}`}
      style={{
        fontSize: 11, fontWeight: 600, padding: '1px 6px', borderRadius: 3,
        background: meta.bg, color: meta.fg,
      }}
    >
      {meta.label}
    </span>
  );
}

import React, { useState, useEffect, useMemo } from 'react';
import { Link } from 'react-router-dom';
import { useApi, api } from '../lib/api';
import {
  BookOpen, CheckCircle2, XCircle, AlertTriangle, ExternalLink,
  ArrowRight, ArrowLeft, ArrowLeftRight, MinusCircle, Activity,
  TrendingUp, Layers, RefreshCw, Search, Compass,
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
  const { data, loading, error, reload } = useApi('/api/admin/accounting_sync_dashboard.php');
  const [busyKey, setBusyKey] = useState(null);
  const [flash, setFlash] = useState(null);
  const [batchProgress, setBatchProgress] = useState(null);   // { idx, total, currentLabel } when reconcile-all is running

  const fireReconcile = async (entityKey) => {
    return api.post('/api/admin/accounting_sync_reconcile.php', { entity_key: entityKey });
  };

  const formatPerSystem = (r) => {
    const parts = [];
    if (r.qbo?.attempted) {
      const ok = !r.qbo?.error;
      const res = r.qbo?.result || {};
      const counts = [];
      if (res.pushed   !== undefined) counts.push(`${res.pushed} pushed`);
      if (res.pulled   !== undefined) counts.push(`${res.pulled} pulled`);
      if (res.matched  !== undefined) counts.push(`${res.matched} matched`);
      if (res.created  !== undefined) counts.push(`${res.created} created`);
      if (res.updated  !== undefined) counts.push(`${res.updated} updated`);
      if (res.skipped  !== undefined) counts.push(`${res.skipped} skipped`);
      if (res.skipped_unmapped !== undefined) counts.push(`${res.skipped_unmapped} skipped`);
      if (res.failed   !== undefined && res.failed > 0) counts.push(`${res.failed} failed`);
      parts.push(`QBO ${ok ? 'ok' : 'fail'}${counts.length ? ' (' + counts.join(' · ') + ')' : ''}${r.qbo.error ? ': ' + r.qbo.error : ''}`);
    } else {
      parts.push(`QBO skipped (${r.qbo?.reason || 'unknown'})`);
    }
    if (r.zoho_books?.attempted) {
      parts.push(`Zoho ok`);
    } else {
      parts.push(`Zoho skipped (${r.zoho_books?.reason || 'unknown'})`);
    }
    return parts;
  };

  const reconcile = async (entity) => {
    setBusyKey(entity.key); setFlash(null);
    try {
      const r = await fireReconcile(entity.key);
      setFlash({ kind: 'success', msg: `Reconcile ${entity.label}: ${formatPerSystem(r).join(' · ')}` });
      reload();
    } catch (e) {
      setFlash({ kind: 'error', msg: e.message || String(e) });
    } finally {
      setBusyKey(null);
    }
  };

  const reconcileAll = async () => {
    const eligible = (data?.entities || []).filter((e) => e.coverage !== 'neither');
    if (eligible.length === 0) {
      setFlash({ kind: 'error', msg: 'No eligible entities to reconcile — flip a direction on at least one entity first.' });
      return;
    }
    setFlash(null);
    setBatchProgress({ idx: 0, total: eligible.length, currentLabel: eligible[0].label });
    let okCount = 0; let failCount = 0; const lines = [];
    for (let i = 0; i < eligible.length; i++) {
      const e = eligible[i];
      setBatchProgress({ idx: i, total: eligible.length, currentLabel: e.label });
      setBusyKey(e.key);
      try {
        const r = await fireReconcile(e.key);
        const ok = !(r.qbo?.error);
        if (ok) okCount++; else failCount++;
        lines.push(`${e.label}: ${formatPerSystem(r).join(' · ')}`);
      } catch (err) {
        failCount++;
        lines.push(`${e.label}: error — ${err.message || String(err)}`);
      }
    }
    setBatchProgress(null);
    setBusyKey(null);
    setFlash({
      kind: failCount === 0 ? 'success' : 'error',
      msg: `Reconcile-all complete: ${okCount} ok / ${failCount} failed across ${eligible.length} entities. ` + lines.join(' • '),
    });
    reload();
  };

  if (loading) return <div data-testid="acct-sync-loading">Loading sync dashboard…</div>;
  if (error)   return <div data-testid="acct-sync-error" style={{ color: 'var(--cf-red, #b91c1c)' }}>Failed to load: {error.message || String(error)}</div>;
  if (!data)   return null;

  const { qbo, zoho_books: zoho, entities = [], summary = {}, unified_activity: activity = [] } = data;
  const eligibleCount = entities.filter((e) => e.coverage !== 'neither').length;

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

      {flash && (
        <div
          data-testid={`acct-sync-flash-${flash.kind}`}
          style={{
            padding: '10px 14px', borderRadius: 6, marginBottom: 16,
            background: flash.kind === 'success' ? 'var(--cf-green-bg, #ecfdf5)' : 'var(--cf-red-bg, #fef2f2)',
            color:      flash.kind === 'success' ? 'var(--cf-green, #047857)'    : 'var(--cf-red, #b91c1c)',
            fontSize: 13,
          }}
        >
          {flash.msg}
        </div>
      )}

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
        <header style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 12, marginBottom: 4 }}>
          <div>
            <h3 style={{ margin: 0, fontSize: 15, fontWeight: 600 }}>
              <TrendingUp size={14} style={{ verticalAlign: 'middle', marginRight: 6 }} />
              Per-entity drift
            </h3>
            <p style={{ margin: '4px 0 0', fontSize: 12, color: 'var(--cf-text-secondary)' }}>
              A signal of <strong>QBO ahead</strong> / <strong>Zoho ahead</strong> means the last successful sync on
              that side is ≥ 24 hours newer than the other — reconcile manually or flip a direction so they catch up.
            </p>
          </div>
          <button
            type="button"
            className="btn btn--primary"
            data-testid="acct-sync-reconcile-all-btn"
            onClick={reconcileAll}
            disabled={eligibleCount === 0 || !!busyKey}
            title={
              eligibleCount === 0
                ? 'No entities are eligible — flip a direction on at least one row first.'
                : `Runs every eligible sync (${eligibleCount}) sequentially.`
            }
            style={{ whiteSpace: 'nowrap' }}
          >
            <RefreshCw size={13} style={{ marginRight: 6 }} />
            {batchProgress ? `Running ${batchProgress.idx + 1} / ${batchProgress.total}…` : `Reconcile all (${eligibleCount})`}
          </button>
        </header>

        {batchProgress && (
          <div data-testid="acct-sync-reconcile-all-progress" style={{ margin: '12px 0 16px' }}>
            <div style={{ fontSize: 12, color: 'var(--cf-text-secondary)', marginBottom: 4 }}>
              {batchProgress.idx + 1} of {batchProgress.total} — {batchProgress.currentLabel}
            </div>
            <div
              role="progressbar"
              aria-valuemin={0}
              aria-valuemax={batchProgress.total}
              aria-valuenow={batchProgress.idx}
              style={{
                height: 6, background: 'var(--cf-border-muted, #f1f5f9)',
                borderRadius: 3, overflow: 'hidden',
              }}
            >
              <div
                data-testid="acct-sync-reconcile-all-progress-bar"
                style={{
                  height: '100%',
                  width: `${Math.round((batchProgress.idx / batchProgress.total) * 100)}%`,
                  background: 'var(--cf-blue, #2563eb)',
                  transition: 'width 200ms ease',
                }}
              />
            </div>
          </div>
        )}

        <table data-testid="acct-sync-drift-table" style={{ width: '100%', fontSize: 13, borderCollapse: 'collapse', marginTop: 12 }}>
          <thead>
            <tr style={{ textAlign: 'left', color: 'var(--cf-text-secondary)', borderBottom: '1px solid var(--cf-border)' }}>
              <th style={{ padding: '8px 4px' }}>Entity</th>
              <th style={{ padding: '8px 4px' }}>QBO direction</th>
              <th style={{ padding: '8px 4px' }}>QBO last sync</th>
              <th style={{ padding: '8px 4px' }}>Zoho direction</th>
              <th style={{ padding: '8px 4px' }}>Zoho last sync</th>
              <th style={{ padding: '8px 4px' }}>Signal</th>
              <th style={{ padding: '8px 4px' }}></th>
            </tr>
          </thead>
          <tbody>
            {entities.map((e) => (
              <EntityRow
                key={e.key}
                entity={e}
                onReconcile={() => reconcile(e)}
                busy={busyKey === e.key}
                anyBusy={!!busyKey}
              />
            ))}
          </tbody>
        </table>
      </section>

      {/* ─────────────────────── CoA coverage ─────────────────────── */}
      <CoaCoverageCard tenantChanged={data} />

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

function EntityRow({ entity, onReconcile, busy, anyBusy }) {
  const qMeta = DIR_META[entity.qbo_dir]  || DIR_META.off;
  const zMeta = DIR_META[entity.zoho_dir] || DIR_META.off;
  const sig   = SIGNAL_META[entity.drift_signal] || SIGNAL_META.inactive;
  const QIcon = qMeta.icon; const ZIcon = zMeta.icon;

  // Show Reconcile only when at least one side has a non-`off` direction.
  // `inactive` rows have nothing to sync — keep them quiet.
  const canReconcile = entity.coverage !== 'neither';
  const tooltip = entity.coverage === 'neither'
    ? 'Both sides are off — flip a direction in settings before reconciling.'
    : entity.drift_signal === 'qbo_ahead'
      ? 'QBO is ahead — run a sync to catch the lagging side up.'
      : entity.drift_signal === 'zoho_ahead'
        ? 'Zoho is ahead — run a sync to catch QBO up.'
        : entity.drift_signal === 'one_sided'
          ? 'Only one side is active — runs the eligible sync now.'
          : 'Run a sync on both systems now.';

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
      <td style={{ padding: '8px 4px', textAlign: 'right' }}>
        <button
          type="button"
          className="btn"
          data-testid={`acct-sync-reconcile-${entity.key}`}
          onClick={onReconcile}
          disabled={!canReconcile || anyBusy}
          title={tooltip}
          style={{ fontSize: 12, padding: '3px 10px' }}
        >
          <RefreshCw size={11} style={{ marginRight: 4 }} />
          {busy ? 'Running…' : 'Reconcile'}
        </button>
      </td>
    </tr>
  );
}

/* ─────────────────────────────────────────────────────────── coa coverage */

function CoaCoverageCard() {
  const { data, loading, error, reload } = useApi('/api/admin/accounting_coa_coverage.php');
  const [busyId, setBusyId] = useState(null);
  const [filter, setFilter] = useState('all');     // all | unmapped | qbo_only | zoho_only | both
  const [q, setQ] = useState('');
  const [flash, setFlash] = useState(null);
  const [selected, setSelected] = useState(new Set());
  const [batch, setBatch] = useState(null);        // { system, idx, total, currentLabel }

  const handleDiscover = async (account, system) => {
    const key = `${account.id}:${system}`;
    setBusyId(key); setFlash(null);
    try {
      const r = await api.post('/api/admin/accounting_coa_coverage.php', { account_id: account.id, system });
      if (r.status === 'mapped') {
        setFlash({ kind: 'success', msg: `${account.code} ${account.name} → ${system} "${r.external_name}" (id ${r.external_id})` });
      } else if (r.status === 'not_found') {
        setFlash({ kind: 'error', msg: `${account.code}: ${r.note || 'no match in ' + system}` });
      } else {
        setFlash({ kind: 'error', msg: `${account.code}: ${r.error || 'discover error'}` });
      }
      reload();
    } catch (e) {
      setFlash({ kind: 'error', msg: e.message || String(e) });
    } finally {
      setBusyId(null);
    }
  };

  const handleBulk = async (system) => {
    const ids = [...selected];
    const queue = (data?.accounts || [])
      .filter((a) => ids.includes(a.id))
      .filter((a) => (system === 'qbo' ? !a.qbo_mapped : !a.zoho_mapped));
    if (queue.length === 0) {
      setFlash({ kind: 'error', msg: `Nothing to do — selected rows are already mapped on ${system === 'qbo' ? 'QBO' : 'Zoho'}.` });
      return;
    }
    setFlash(null);
    let ok = 0; let notFound = 0; let errored = 0; const errs = [];
    setBatch({ system, idx: 0, total: queue.length, currentLabel: queue[0].code });
    for (let i = 0; i < queue.length; i++) {
      const acc = queue[i];
      setBatch({ system, idx: i, total: queue.length, currentLabel: `${acc.code} ${acc.name}` });
      try {
        const r = await api.post('/api/admin/accounting_coa_coverage.php', { account_id: acc.id, system });
        if      (r.status === 'mapped')    ok++;
        else if (r.status === 'not_found') { notFound++; if (errs.length < 5) errs.push(`${acc.code} (not found)`); }
        else                               { errored++;  if (errs.length < 5) errs.push(`${acc.code} (${r.error || 'error'})`); }
      } catch (e) {
        errored++;
        if (errs.length < 5) errs.push(`${acc.code} (${e.message || 'error'})`);
      }
    }
    setBatch(null);
    setSelected(new Set());
    setFlash({
      kind: errored === 0 ? 'success' : 'error',
      msg: `Bulk discover on ${system === 'qbo' ? 'QBO' : 'Zoho'}: ${ok} mapped · ${notFound} not found · ${errored} errored (of ${queue.length})` + (errs.length ? ' — ' + errs.join(', ') : ''),
    });
    reload();
  };

  const accounts  = data?.accounts || [];
  const summary   = data?.summary  || {};
  const qboActive = !!data?.qbo_active;
  const zohoActive= !!data?.zoho_active;

  const filtered = useMemo(() => {
    const needle = q.trim().toLowerCase();
    return accounts.filter((a) => {
      if (filter !== 'all' && a.coverage !== filter) return false;
      if (needle && !`${a.code} ${a.name}`.toLowerCase().includes(needle)) return false;
      return true;
    });
  }, [accounts, filter, q]);

  const toggle = (id) => {
    const n = new Set(selected); n.has(id) ? n.delete(id) : n.add(id); setSelected(n);
  };
  const visibleIds = filtered.map((a) => a.id);
  const allVisibleSelected = visibleIds.length > 0 && visibleIds.every((id) => selected.has(id));
  const toggleAllVisible = () => {
    const n = new Set(selected);
    if (allVisibleSelected) visibleIds.forEach((id) => n.delete(id));
    else                    visibleIds.forEach((id) => n.add(id));
    setSelected(n);
  };

  // Clear stale selections when filter/search trims them out of view.
  useEffect(() => {
    const visible = new Set(visibleIds);
    let changed = false;
    const next = new Set();
    for (const id of selected) {
      if (visible.has(id)) next.add(id); else changed = true;
    }
    if (changed) setSelected(next);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filter, q, accounts.length]);

  const selCount = selected.size;

  return (
    <section
      data-testid="coa-coverage-section"
      className="card"
      style={{ padding: 16, border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8, marginBottom: 24 }}
    >
      <header style={{ marginBottom: 4 }}>
        <h3 style={{ margin: 0, fontSize: 15, fontWeight: 600 }}>
          <Compass size={14} style={{ verticalAlign: 'middle', marginRight: 6 }} />
          Chart-of-accounts coverage
        </h3>
        <p style={{ margin: '4px 0 12px', fontSize: 12, color: 'var(--cf-text-secondary)' }}>
          Every CoreFlux account, mapped against QBO and Zoho Books. <strong>JE refs (90d)</strong> shows how
          often each account has been used recently — prioritise mapping the high-traffic ones first.
          Click <strong>Discover</strong> to auto-match by account code.
        </p>
      </header>

      {flash && (
        <div
          data-testid={`coa-coverage-flash-${flash.kind}`}
          style={{
            padding: '8px 12px', borderRadius: 6, marginBottom: 12, fontSize: 12,
            background: flash.kind === 'success' ? 'var(--cf-green-bg, #ecfdf5)' : 'var(--cf-red-bg, #fef2f2)',
            color:      flash.kind === 'success' ? 'var(--cf-green, #047857)'    : 'var(--cf-red, #b91c1c)',
          }}
        >
          {flash.msg}
        </div>
      )}

      {/* mini scorecard */}
      <div data-testid="coa-coverage-summary" style={{ display: 'flex', gap: 16, fontSize: 12, marginBottom: 12, flexWrap: 'wrap' }}>
        <Stat label="Total accounts"  value={summary.total       || 0} fg="#475569" />
        <Stat label="Both"            value={summary.mapped_both || 0} fg="#047857" testid="coa-coverage-stat-both" />
        <Stat label="QBO only"        value={summary.qbo_only    || 0} fg="#1d4ed8" testid="coa-coverage-stat-qbo-only" />
        <Stat label="Zoho only"       value={summary.zoho_only   || 0} fg="#7c3aed" testid="coa-coverage-stat-zoho-only" />
        <Stat label="Unmapped"        value={summary.unmapped    || 0} fg="#92400e" testid="coa-coverage-stat-unmapped" />
      </div>

      <div style={{ display: 'flex', gap: 8, marginBottom: 12, flexWrap: 'wrap', alignItems: 'center' }}>
        <div style={{ position: 'relative' }}>
          <Search size={12} style={{ position: 'absolute', left: 8, top: '50%', transform: 'translateY(-50%)', color: 'var(--cf-text-secondary)' }} />
          <input
            type="text"
            data-testid="coa-coverage-search"
            placeholder="Filter by code or name…"
            value={q}
            onChange={(e) => setQ(e.target.value)}
            style={{ paddingLeft: 26, padding: '6px 10px 6px 26px', borderRadius: 4, border: '1px solid var(--cf-border)', fontSize: 12, width: 220 }}
          />
        </div>
        <select
          data-testid="coa-coverage-filter"
          value={filter}
          onChange={(e) => setFilter(e.target.value)}
          style={{ padding: '6px 8px', borderRadius: 4, border: '1px solid var(--cf-border)', fontSize: 12 }}
        >
          <option value="all">All accounts</option>
          <option value="both">Mapped both</option>
          <option value="qbo_only">QBO only</option>
          <option value="zoho_only">Zoho only</option>
          <option value="neither">Unmapped</option>
        </select>
        <span style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>
          showing {filtered.length} / {accounts.length}
        </span>
        <span style={{ flex: 1 }} />
        <button
          type="button"
          className="btn"
          data-testid="coa-coverage-bulk-qbo-btn"
          onClick={() => handleBulk('qbo')}
          disabled={selCount === 0 || !qboActive || !!batch}
          title={qboActive ? `Auto-discover on QBO for ${selCount} selected` : 'Connect QBO first'}
          style={{ fontSize: 12, padding: '4px 10px' }}
        >
          <Compass size={12} style={{ marginRight: 4 }} />
          Bulk discover · QBO ({selCount})
        </button>
        <button
          type="button"
          className="btn"
          data-testid="coa-coverage-bulk-zoho-btn"
          onClick={() => handleBulk('zoho_books')}
          disabled={selCount === 0 || !zohoActive || !!batch}
          title={zohoActive ? `Auto-discover on Zoho for ${selCount} selected` : 'Connect Zoho Books first'}
          style={{ fontSize: 12, padding: '4px 10px' }}
        >
          <Compass size={12} style={{ marginRight: 4 }} />
          Bulk discover · Zoho ({selCount})
        </button>
      </div>

      {batch && (
        <div data-testid="coa-coverage-bulk-progress" style={{ marginBottom: 12 }}>
          <div style={{ fontSize: 12, color: 'var(--cf-text-secondary)', marginBottom: 4 }}>
            {batch.system === 'qbo' ? 'QBO' : 'Zoho'} · {batch.idx + 1} / {batch.total} — {batch.currentLabel}
          </div>
          <div role="progressbar" aria-valuemin={0} aria-valuemax={batch.total} aria-valuenow={batch.idx}
            style={{ height: 6, background: 'var(--cf-border-muted, #f1f5f9)', borderRadius: 3, overflow: 'hidden' }}
          >
            <div
              data-testid="coa-coverage-bulk-progress-bar"
              style={{
                height: '100%',
                width: `${Math.round((batch.idx / batch.total) * 100)}%`,
                background: batch.system === 'qbo' ? 'var(--cf-blue, #2563eb)' : '#7c3aed',
                transition: 'width 200ms ease',
              }}
            />
          </div>
        </div>
      )}

      {loading && <p data-testid="coa-coverage-loading">Loading…</p>}
      {error   && <p data-testid="coa-coverage-error" style={{ color: 'var(--cf-red, #b91c1c)' }}>Failed: {error.message || String(error)}</p>}

      {!loading && !error && (
        <div style={{ overflowX: 'auto', maxHeight: 420 }}>
          <table data-testid="coa-coverage-table" style={{ width: '100%', fontSize: 12, borderCollapse: 'collapse' }}>
            <thead style={{ position: 'sticky', top: 0, background: 'var(--cf-surface, #fff)' }}>
              <tr style={{ textAlign: 'left', color: 'var(--cf-text-secondary)', borderBottom: '1px solid var(--cf-border)' }}>
                <th style={{ padding: '6px 4px', width: 28 }}>
                  <input
                    type="checkbox"
                    data-testid="coa-coverage-select-all"
                    checked={allVisibleSelected}
                    onChange={toggleAllVisible}
                    disabled={visibleIds.length === 0}
                    title="Toggle all visible"
                  />
                </th>
                <th style={{ padding: '6px 4px' }}>Code</th>
                <th style={{ padding: '6px 4px' }}>Name</th>
                <th style={{ padding: '6px 4px' }}>Type</th>
                <th style={{ padding: '6px 4px', textAlign: 'right' }}>JE refs (90d)</th>
                <th style={{ padding: '6px 4px' }}>QBO</th>
                <th style={{ padding: '6px 4px' }}>Zoho</th>
              </tr>
            </thead>
            <tbody>
              {filtered.length === 0 ? (
                <tr><td colSpan={7} data-testid="coa-coverage-empty" style={{ padding: 16, textAlign: 'center', color: 'var(--cf-text-secondary)' }}>No accounts match.</td></tr>
              ) : filtered.map((a) => (
                <CoaRow
                  key={a.id}
                  account={a}
                  qboActive={qboActive}
                  zohoActive={zohoActive}
                  busyId={busyId}
                  onDiscover={handleDiscover}
                  selected={selected.has(a.id)}
                  onToggle={() => toggle(a.id)}
                />
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}

function Stat({ label, value, fg, testid }) {
  return (
    <div data-testid={testid} style={{ minWidth: 90 }}>
      <div style={{ fontSize: 18, fontWeight: 700, color: fg }}>{value}</div>
      <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>{label}</div>
    </div>
  );
}

function CoaRow({ account, qboActive, zohoActive, busyId, onDiscover, selected, onToggle }) {
  const inactiveStyle = !account.active ? { opacity: 0.55 } : null;
  return (
    <tr data-testid={`coa-coverage-row-${account.id}`} style={{ borderBottom: '1px solid var(--cf-border-muted, #f1f5f9)', ...inactiveStyle }}>
      <td style={{ padding: '6px 4px' }}>
        <input
          type="checkbox"
          data-testid={`coa-coverage-checkbox-${account.id}`}
          checked={!!selected}
          onChange={onToggle}
        />
      </td>
      <td style={{ padding: '6px 4px', fontFamily: 'var(--cf-mono, ui-monospace)' }}>{account.code}</td>
      <td style={{ padding: '6px 4px' }}>
        {account.name}
        {!account.active && (
          <span style={{ marginLeft: 6, fontSize: 10, color: 'var(--cf-text-secondary)' }} title="inactive">(inactive)</span>
        )}
      </td>
      <td style={{ padding: '6px 4px', color: 'var(--cf-text-secondary)', textTransform: 'capitalize' }}>{account.account_type}</td>
      <td style={{ padding: '6px 4px', textAlign: 'right', fontVariantNumeric: 'tabular-nums', fontWeight: account.je_refs_90d > 0 ? 600 : 400 }}>
        {account.je_refs_90d}
      </td>
      <CoaMappingCell
        testidBase={`coa-coverage-qbo-${account.id}`}
        mapped={account.qbo_mapped}
        externalId={account.qbo_external_id}
        externalName={account.qbo_external_name}
        systemActive={qboActive}
        busy={busyId === `${account.id}:qbo`}
        onDiscover={() => onDiscover(account, 'qbo')}
      />
      <CoaMappingCell
        testidBase={`coa-coverage-zoho-${account.id}`}
        mapped={account.zoho_mapped}
        externalId={account.zoho_external_id}
        externalName={account.zoho_external_name}
        systemActive={zohoActive}
        busy={busyId === `${account.id}:zoho_books`}
        onDiscover={() => onDiscover(account, 'zoho_books')}
      />
    </tr>
  );
}

function CoaMappingCell({ testidBase, mapped, externalId, externalName, systemActive, busy, onDiscover }) {
  if (mapped) {
    return (
      <td style={{ padding: '6px 4px' }} data-testid={`${testidBase}-mapped`}>
        <CheckCircle2 size={11} style={{ verticalAlign: 'middle', marginRight: 4, color: 'var(--cf-green, #047857)' }} />
        <span style={{ fontFamily: 'var(--cf-mono, ui-monospace)', color: 'var(--cf-text-secondary)' }} title={externalName || ''}>
          {externalId}
        </span>
      </td>
    );
  }
  return (
    <td style={{ padding: '6px 4px' }} data-testid={`${testidBase}-unmapped`}>
      <button
        type="button"
        className="btn"
        data-testid={`${testidBase}-discover-btn`}
        onClick={onDiscover}
        disabled={!systemActive || busy}
        title={systemActive ? 'Auto-match by account code' : 'Connect this system first'}
        style={{ fontSize: 11, padding: '2px 8px' }}
      >
        {busy ? '…' : 'Discover'}
      </button>
    </td>
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

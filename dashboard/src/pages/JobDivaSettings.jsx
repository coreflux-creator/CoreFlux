import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, useApi } from '../lib/api';
import {
  Activity, AlertCircle, AlertTriangle, CheckCircle2, ChevronDown, ChevronRight, Copy, Database, GitBranch, Link2,
  PlugZap, RefreshCw, ShieldCheck, Sparkles, Wrench, X, XCircle,
} from 'lucide-react';

/**
 * JobDiva integration settings — Sprint 8a / Slice A1.
 *
 * Lives at /admin/integrations/jobdiva. Lets a tenant admin:
 *   • Connect (clientid + username + password [+ optional webhook secret])
 *   • Disconnect (soft — preserves audit history)
 *   • Test connection (Ping)
 *   • Run "Sync now" (no-op in A1; will trigger entity pipelines in A2+)
 *   • Copy their webhook URL for pasting into JobDiva's webhook config
 *   • View recent audit trail (last 25 actions) + recent webhook events
 */
export default function JobDivaSettings() {
  const { data, error, loading, reload } = useApi('/api/jobdiva/status.php?action=status');
  const [form, setForm] = useState({
    client_id: '', username: '', password: '', webhook_secret: '',
  });
  const [busy, setBusy] = useState({});
  const [msg, setMsg]   = useState(null);
  const [err, setErr]   = useState(null);
  const [showPwd, setShowPwd] = useState(false);
  const [syncResult, setSyncResult] = useState(null);
  const [alignment, setAlignment] = useState(null);
  const [alignmentLoading, setAlignmentLoading] = useState(false);
  const [alignmentError, setAlignmentError] = useState(null);
  const [repairResult, setRepairResult] = useState(null);
  const [expandedAudit, setExpandedAudit] = useState({});

  const set = (k, v) => setForm(s => ({ ...s, [k]: v }));
  const clear = () => { setMsg(null); setErr(null); };

  const loadAlignment = useCallback(async () => {
    setAlignmentLoading(true);
    setAlignmentError(null);
    try {
      const r = await api.get('/api/admin/integrations/jobdiva_mapping_alignment.php');
      setAlignment(r);
    } catch (e) {
      setAlignmentError(e.message || 'Failed to load JobDiva mapping alignment');
    } finally {
      setAlignmentLoading(false);
    }
  }, []);

  useEffect(() => {
    loadAlignment();
  }, [loadAlignment]);

  const onRepairWorkflow = async () => {
    clear(); setRepairResult(null);
    if (!window.confirm('Run the ordered JobDiva alignment repair? This archives safe duplicate shells, repairs client links, corrects placement lifecycle states, retires source-owned People with no live placement, and creates source-backed draft rates. Rate approval remains a separate approval step.')) {
      return;
    }
    setBusy(b => ({ ...b, repairWorkflow: true }));
    try {
      const r = await api.post('/api/admin/integrations/jobdiva_mapping_alignment.php?action=repair_workflow', { limit: 5000 });
      const repair = r.repair || r;
      setRepairResult(repair);
      await loadAlignment();
      const changed = repair.totals?.changed ?? 0;
      const remaining = repair.remaining?.critical_issue_types ?? 0;
      setMsg(`JobDiva alignment repair finished: ${changed} change(s); ${remaining} critical blocker type(s) remain.`);
    } catch (e) {
      setErr(e.message);
    } finally {
      setBusy(b => ({ ...b, repairWorkflow: false }));
    }
  };

  const onRepairClientLinks = async () => {
    clear(); setRepairResult(null); setBusy(b => ({ ...b, repairClientLinks: true }));
    try {
      const r = await api.post('/api/admin/integrations/jobdiva_mapping_alignment.php?action=repair_client_links', {});
      setRepairResult(r.repair || r);
      await loadAlignment();
      setMsg(`Client links repaired: ${r.repair?.repaired ?? 0}.`);
    } catch (e) {
      setErr(e.message);
    } finally {
      setBusy(b => ({ ...b, repairClientLinks: false }));
    }
  };

  const onRepairRateDrafts = async () => {
    clear(); setRepairResult(null); setBusy(b => ({ ...b, repairRateDrafts: true }));
    try {
      const r = await api.post('/api/admin/integrations/jobdiva_mapping_alignment.php?action=repair_source_rate_drafts', {});
      setRepairResult(r.repair || r);
      await loadAlignment();
      setMsg(`Rate drafts repaired: ${r.repair?.drafted ?? 0}.`);
    } catch (e) {
      setErr(e.message);
    } finally {
      setBusy(b => ({ ...b, repairRateDrafts: false }));
    }
  };

  const onRepairDuplicatePlacements = async (dryRun = true) => {
    clear(); setRepairResult(null); setBusy(b => ({ ...b, repairDuplicatePlacements: true }));
    try {
      if (!dryRun && !window.confirm('Archive duplicate JobDiva/legacy placement rows that have no downstream time, billing, or AP activity?')) {
        return;
      }
      const r = await api.post('/api/admin/integrations/jobdiva_mapping_alignment.php?action=repair_duplicate_placements', {
        dry_run: dryRun,
      });
      setRepairResult(r.repair || r);
      await loadAlignment();
      const repaired = r.repair?.groups_repaired ?? 0;
      const archived = r.repair?.placements_archived ?? 0;
      setMsg(dryRun
        ? `Duplicate placement cleanup preview: ${repaired} group(s), ${archived} row(s).`
        : `Duplicate placement cleanup archived ${archived} row(s).`);
    } catch (e) {
      setErr(e.message);
    } finally {
      setBusy(b => ({ ...b, repairDuplicatePlacements: false }));
    }
  };

  const onRepairStalePlacements = async (dryRun = true) => {
    clear(); setRepairResult(null); setBusy(b => ({ ...b, repairStalePlacements: true }));
    try {
      if (!dryRun && !window.confirm('Apply source-backed lifecycle states to active JobDiva placements? This may mark placements ended, cancelled, pending, or on hold.')) {
        return;
      }
      const r = await api.post('/api/admin/integrations/jobdiva_mapping_alignment.php?action=repair_stale_placements', {
        dry_run: dryRun,
      });
      setRepairResult(r.repair || r);
      await loadAlignment();
      const changed = r.repair?.updated ?? r.repair?.ended ?? 0;
      setMsg(dryRun
        ? `Placement lifecycle preview: ${changed} placement(s) would change.`
        : `Placement lifecycle repair updated ${changed} placement(s).`);
    } catch (e) {
      setErr(e.message);
    } finally {
      setBusy(b => ({ ...b, repairStalePlacements: false }));
    }
  };

  const onRepairStalePeople = async (dryRun = true) => {
    clear(); setRepairResult(null); setBusy(b => ({ ...b, repairStalePeople: true }));
    try {
      if (!dryRun && !window.confirm('Mark former JobDiva workers inactive when they have placement history but no live placement? Historical records remain available; candidates who have never been placed and manual People records are not changed.')) {
        return;
      }
      const r = await api.post('/api/admin/integrations/jobdiva_mapping_alignment.php?action=repair_stale_people', {
        dry_run: dryRun,
      });
      setRepairResult(r.repair || r);
      await loadAlignment();
      const inactivated = r.repair?.inactivated ?? 0;
      setMsg(dryRun
        ? `People lifecycle preview: ${inactivated} source-owned record(s) would become inactive.`
        : `People lifecycle repair marked ${inactivated} source-owned record(s) inactive.`);
    } catch (e) {
      setErr(e.message);
    } finally {
      setBusy(b => ({ ...b, repairStalePeople: false }));
    }
  };

  const onConnect = async (e) => {
    e?.preventDefault?.();
    clear();
    if (!form.client_id || !form.username || !form.password) {
      setErr('client_id, username, and password are all required.');
      return;
    }
    setBusy(b => ({ ...b, connect: true }));
    try {
      const r = await api.post('/api/jobdiva/connect.php?action=connect', form);
      setMsg(r.ping?.ok
        ? `Connected. Round-trip ${r.ping.latency_ms}ms.`
        : `Saved credentials, but JobDiva rejected the auth: ${r.ping?.error}`);
      setForm(s => ({ ...s, password: '' }));
      reload();
    } catch (e) {
      setErr(e.message);
    } finally {
      setBusy(b => ({ ...b, connect: false }));
    }
  };

  const onPing = async () => {
    clear(); setBusy(b => ({ ...b, ping: true }));
    try {
      const r = await api.post('/api/jobdiva/ping.php?action=ping');
      setMsg(r.ok ? `Ping OK (${r.latency_ms}ms).` : `Ping failed: ${r.error}`);
      reload();
    } catch (e) { setErr(e.message); }
    finally    { setBusy(b => ({ ...b, ping: false })); }
  };

  const onSync = async () => {
    clear(); setSyncResult(null); setBusy(b => ({ ...b, sync: true }));
    try {
      const r = await api.post('/api/jobdiva/sync.php?action=sync');
      let contractProcessed = 0;
      let contractProjected = 0;
      let contractFailed = 0;
      let cursor = 0;
      for (let batchNumber = 0; batchNumber < 1000; batchNumber += 1) {
        const batch = await api.post('/api/jobdiva/sync.php?action=assignment_contracts_batch', {
          cursor,
          limit: 8,
        });
        contractProcessed += Number(batch.processed) || 0;
        contractProjected += Number(batch.projected) || 0;
        contractFailed += Number(batch.failed) || 0;
        setMsg(`Syncing JobDiva assignment contracts: ${contractProjected} projected${contractFailed ? `, ${contractFailed} unavailable` : ''}.`);
        const nextCursor = Number(batch.cursor) || 0;
        if (batch.done) break;
        if (nextCursor <= cursor) throw new Error('Assignment contract sync did not advance its cursor');
        cursor = nextCursor;
      }
      // A3+ returns { counts: {company, contact, placement, ...}, total, latency_ms }.
      // A1 returns { ok, note, ping } only — fall back to the note.
      const counts = r.counts && typeof r.counts === 'object' ? r.counts : null;
      const total  = typeof r.total === 'number' ? r.total
                    : (counts ? Object.values(counts).reduce((a, b) => a + (Number(b) || 0), 0) : 0);
      setSyncResult({
        ok: r.ok !== false,
        counts: counts ? { ...counts, assignment_contract: contractProjected } : counts,
        total: total + contractProjected,
        latency_ms: r.ping?.latency_ms ?? r.latency_ms ?? null,
        note: r.note || null,
        skipped_by_config: Array.isArray(r.skipped_by_config) ? r.skipped_by_config : [],
        by_entity: {
          ...(r.by_entity || {}),
          assignment_contract: {
            processed: contractProcessed,
            projected: contractProjected,
            failed: contractFailed,
          },
        },
        ts: new Date().toISOString(),
      });
      if (!counts) setMsg(r.note || 'Sync triggered.');
      reload();
      loadAlignment();
    } catch (e) { setErr(e.message); }
    finally    { setBusy(b => ({ ...b, sync: false })); }
  };

  const onDisconnect = async () => {
    if (!confirm('Disconnect JobDiva? Audit history is preserved.')) return;
    clear(); setBusy(b => ({ ...b, disconnect: true }));
    try {
      await api.post('/api/jobdiva/disconnect.php?action=disconnect');
      setMsg('Disconnected.');
      reload();
    } catch (e) { setErr(e.message); }
    finally    { setBusy(b => ({ ...b, disconnect: false })); }
  };

  const onConfigChange = async (entity, field, value) => {
    const next = { ...(data?.sync_config || {}) };
    next[entity] = { ...(next[entity] || { source: 'jobdiva', direction: 'pull' }), [field]: value };
    // Coherence guards mirror the server-side validation so the UI never
    // submits an invalid combo: source=coreflux ⇒ direction can't be pull;
    // source=jobdiva ⇒ direction can't be push.
    if (next[entity].source === 'coreflux' && next[entity].direction === 'pull') {
      next[entity].direction = 'push';
    }
    if (next[entity].source === 'jobdiva' && next[entity].direction === 'push') {
      next[entity].direction = 'pull';
    }
    clear(); setBusy(b => ({ ...b, config: true }));
    try {
      await api.post('/api/jobdiva.php?action=sync_config_set', { sync_config: next });
      setMsg(`Updated ${entity} sync config.`);
      reload();
    } catch (e) { setErr(e.message); }
    finally    { setBusy(b => ({ ...b, config: false })); }
  };

  const copyWebhook = () => {
    if (!data?.webhook_url) return;
    navigator.clipboard?.writeText(data.webhook_url);
    setMsg('Webhook URL copied to clipboard.');
  };

  const StatusBadge = ({ s }) => {
    const palette = {
      connected:   { bg: '#ecfdf5', fg: '#065f46', icon: CheckCircle2 },
      degraded:    { bg: '#fef3c7', fg: '#92400e', icon: AlertCircle  },
      error:       { bg: '#fef2f2', fg: '#7f1d1d', icon: XCircle      },
      disconnected:{ bg: '#f1f5f9', fg: '#475569', icon: PlugZap      },
    }[s] || { bg: '#f1f5f9', fg: '#475569', icon: PlugZap };
    const Icon = palette.icon;
    return (
      <span data-testid="jobdiva-settings-status-badge"
            style={{ display: 'inline-flex', alignItems: 'center', gap: 4,
                     padding: '3px 10px', borderRadius: 999,
                     background: palette.bg, color: palette.fg,
                     fontSize: 12, fontWeight: 600 }}>
        <Icon size={12} /> {s}
      </span>
    );
  };

  return (
    <section data-testid="jobdiva-settings-page" style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
      <header style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', flexWrap: 'wrap', gap: 12 }}>
        <div>
          <h2 style={{ display: 'flex', alignItems: 'center', gap: 8, margin: 0 }}>
            <PlugZap size={20} color="#7c3aed" /> JobDiva integration
          </h2>
          <p style={{ color: '#64748b', margin: '4px 0 0', fontSize: 13 }}>
            Tenant-level connection. Two-way sync with field-level ownership ships in the next slice — this slice wires the auth and webhook plumbing.
          </p>
        </div>
        <button data-testid="jobdiva-settings-refresh" onClick={reload} className="btn btn--ghost" style={{ fontSize: 12 }}>
          <RefreshCw size={12} style={{ marginRight: 4, verticalAlign: 'middle' }} />Refresh
        </button>
      </header>

      {/* Field Mapping Studio CTA — JobDiva is the most common entry point so we surface it here. */}
      <div data-testid="jobdiva-settings-field-map-cta"
           style={{ padding: 14, background: 'linear-gradient(135deg,#eef2ff,#faf5ff)',
                    border: '1px solid #c4b5fd', borderRadius: 10,
                    display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12 }}>
        <div>
          <strong style={{ fontSize: 13, color: '#5b21b6', display: 'flex', alignItems: 'center', gap: 6 }}>
            <Sparkles size={14} /> Advanced field overrides
          </strong>
          <p style={{ margin: '4px 0 0', fontSize: 12, color: '#475569' }}>
            CoreFlux already projects JobDiva into placements, people, companies, jobs, and rates. Use the
            <strong> Field Mapping Studio</strong> only when a tenant needs to override or add a specific source field.
          </p>
        </div>
        <Link
          to="/admin/integrations/field-map/studio?integration=jobdiva&entity_type=placement"
          data-testid="jobdiva-settings-field-map-studio-link"
          className="btn btn--primary"
          style={{ whiteSpace: 'nowrap', fontSize: 13 }}
        >
          Open Field Mapping Studio →
        </Link>
      </div>

      {loading && <p data-testid="jobdiva-settings-loading">Loading…</p>}
      {error   && <p data-testid="jobdiva-settings-error" className="error">Error: {error.message}</p>}
      {msg     && <p data-testid="jobdiva-settings-msg"  style={{ background: '#ecfdf5', border: '1px solid #a7f3d0', padding: 10, borderRadius: 8, color: '#065f46', fontSize: 13 }}>{msg}</p>}
      {err     && <p data-testid="jobdiva-settings-err"  style={{ background: '#fef2f2', border: '1px solid #fecaca', padding: 10, borderRadius: 8, color: '#7f1d1d', fontSize: 13 }}>{err}</p>}

      <JobDivaMappingAlignmentCard
        data={alignment}
        loading={alignmentLoading}
        error={alignmentError}
        onRefresh={loadAlignment}
        onRepairWorkflow={onRepairWorkflow}
        onRepairClientLinks={onRepairClientLinks}
        onRepairRateDrafts={onRepairRateDrafts}
        onRepairDuplicatePlacements={onRepairDuplicatePlacements}
        onRepairStalePlacements={onRepairStalePlacements}
        onRepairStalePeople={onRepairStalePeople}
        repairingWorkflow={!!busy.repairWorkflow}
        repairing={!!busy.repairClientLinks}
        repairingRates={!!busy.repairRateDrafts}
        repairingDuplicates={!!busy.repairDuplicatePlacements}
        repairingStale={!!busy.repairStalePlacements}
        repairingStalePeople={!!busy.repairStalePeople}
        repairResult={repairResult}
      />

      {/* Status / connection summary */}
      {data && (
        <div data-testid="jobdiva-settings-status-card"
             style={{ padding: 16, background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12,
                      display: 'flex', flexWrap: 'wrap', gap: 24 }}>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 4, minWidth: 180 }}>
            <span style={lbl}>Status</span>
            <StatusBadge s={data.status || (data.connected ? 'connected' : 'disconnected')} />
          </div>
          {data.connected && (
            <>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 4, minWidth: 160 }}>
                <span style={lbl}>Client ID</span>
                <code data-testid="jobdiva-settings-client-id" style={mono}>{data.client_id}</code>
              </div>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 4, minWidth: 200 }}>
                <span style={lbl}>Username</span>
                <code data-testid="jobdiva-settings-username" style={mono}>{data.username}</code>
              </div>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 4, minWidth: 200 }}>
                <span style={lbl}>Last ping</span>
                <span data-testid="jobdiva-settings-last-ping" style={mono}>{data.last_ping_at || '—'}</span>
              </div>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 4, minWidth: 200 }}>
                <span style={lbl}>Last sync</span>
                <span data-testid="jobdiva-settings-last-sync" style={mono}>{data.last_sync_at || '—'}</span>
              </div>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 4, minWidth: 200 }}>
                <span style={lbl}>Token expires</span>
                <span data-testid="jobdiva-settings-token-exp" style={mono}>{data.session_token_exp || '—'}</span>
              </div>
              {data.last_sync_error && (
                <div data-testid="jobdiva-settings-last-error"
                     style={{ flexBasis: '100%', color: '#b91c1c', fontSize: 12,
                              background: '#fef2f2', border: '1px solid #fecaca',
                              borderRadius: 6, padding: '10px 12px', marginTop: 8,
                              fontFamily: 'ui-monospace, SFMono-Regular, monospace',
                              whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 6, fontFamily: 'inherit',
                                marginBottom: 4, fontWeight: 600 }}>
                    <AlertCircle size={12} /> Last error
                  </div>
                  {data.last_sync_error}
                </div>
              )}
            </>
          )}
        </div>
      )}

      {/* Webhook URL panel — visible whether connected or not so the user can
          paste it into JobDiva *before* clicking Connect. */}
      {data?.webhook_url && (
        <div data-testid="jobdiva-settings-webhook-card"
             style={{ padding: 14, background: '#faf5ff', border: '1px solid #ddd6fe', borderRadius: 10 }}>
          <strong style={{ fontSize: 13, color: '#5b21b6', display: 'flex', alignItems: 'center', gap: 6 }}>
            <Link2 size={14} /> Webhook URL (paste into JobDiva)
          </strong>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: 6 }}>
            <code data-testid="jobdiva-settings-webhook-url"
                  style={{ ...mono, flex: 1, padding: '6px 10px', background: '#fff', border: '1px solid #ddd6fe', borderRadius: 6, fontSize: 12, overflowX: 'auto', whiteSpace: 'nowrap' }}>
              {data.webhook_url}
            </code>
            <button data-testid="jobdiva-settings-webhook-copy"
                    onClick={copyWebhook} className="btn btn--ghost" style={{ fontSize: 11 }}>
              <Copy size={11} style={{ marginRight: 3, verticalAlign: 'middle' }} />Copy
            </button>
          </div>
          {data.has_webhook_secret
            ? <span data-testid="jobdiva-settings-webhook-secret-set" style={{ fontSize: 11, color: '#059669' }}>
                <ShieldCheck size={11} style={{ marginRight: 3, verticalAlign: 'middle' }} />
                Signature verification enabled
              </span>
            : <span style={{ fontSize: 11, color: '#92400e' }}>
                <AlertCircle size={11} style={{ marginRight: 3, verticalAlign: 'middle' }} />
                No webhook secret set — incoming events will be rejected. Add one in the connect form.
              </span>}
        </div>
      )}

      {/* Sync result card — shows entity counts after a successful sync.
          A3+ populates counts; A1 leaves it null and we render note via msg. */}
      {syncResult && syncResult.counts && (
        <div data-testid="jobdiva-settings-sync-result-card"
             style={{ padding: 16, background: 'linear-gradient(135deg,#f5f3ff,#ecfeff)',
                      border: '1px solid #c4b5fd', borderRadius: 12, position: 'relative' }}>
          <button type="button" data-testid="jobdiva-settings-sync-result-dismiss"
                  onClick={() => setSyncResult(null)}
                  style={{ position: 'absolute', top: 10, right: 10, border: 0, background: 'transparent',
                           cursor: 'pointer', color: '#7c3aed' }} aria-label="Dismiss">
            <X size={14} />
          </button>
          <strong style={{ fontSize: 14, color: '#5b21b6', display: 'flex', alignItems: 'center', gap: 6 }}>
            <Sparkles size={14} />
            {(() => {
              const totalFailed = Object.values(syncResult.by_entity || {}).reduce((s, e) => s + (Number(e?.failed) || 0), 0);
              return totalFailed > 0
                ? <>Sync completed with {totalFailed} error{totalFailed === 1 ? '' : 's'}</>
                : <>Sync complete</>;
            })()}
            {syncResult.latency_ms != null && (
              <span data-testid="jobdiva-settings-sync-result-latency"
                    style={{ fontSize: 11, color: '#7c3aed', fontWeight: 400 }}>
                · {syncResult.latency_ms}ms
              </span>
            )}
          </strong>
          <div data-testid="jobdiva-settings-sync-result-summary"
               style={{ marginTop: 4, fontSize: 13, color: '#1e293b' }}>
            <strong>{syncResult.total}</strong> record{syncResult.total === 1 ? '' : 's'} imported from JobDiva
          </div>
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginTop: 10 }}>
            {Object.entries(syncResult.counts)
              .filter(([, n]) => Number(n) > 0)
              .map(([entity, n]) => (
              <span key={entity}
                    data-testid={`jobdiva-settings-sync-result-chip-${entity}`}
                    style={{ display: 'inline-flex', alignItems: 'center', gap: 4,
                             padding: '4px 10px', borderRadius: 999, background: '#fff',
                             border: '1px solid #c4b5fd', color: '#5b21b6',
                             fontSize: 12, fontWeight: 600 }}>
                <CheckCircle2 size={11} /> {n} {entity}{Number(n) === 1 ? '' : 's'}
              </span>
            ))}
            {Object.values(syncResult.counts).every(n => Number(n) === 0) && (
              <span data-testid="jobdiva-settings-sync-result-zero"
                    style={{ fontSize: 12, color: '#64748b' }}>
                {syncResult.skipped_by_config && syncResult.skipped_by_config.length > 0
                  ? <>No entities are configured to pull from JobDiva. Set direction to <strong>pull</strong> or <strong>two_way</strong> for <code data-testid="jobdiva-settings-sync-result-skipped-list">{syncResult.skipped_by_config.join(', ')}</code> in the Sync Direction section below.</>
                  : 'Nothing new to import — your tenant is already up to date.'}
              </span>
            )}
          </div>
          {syncResult.note && (
            <p style={{ fontSize: 11, color: '#64748b', margin: '8px 0 0' }}>{syncResult.note}</p>
          )}
          {/* Per-entity diagnostics — surfaces error / deferred reasons that
              would otherwise be invisible to the user. Always renders so
              the operator can see what each entity did during sync (helps
              localize JobDiva-side issues like "Not an array" 500s). */}
          {syncResult.by_entity && Object.keys(syncResult.by_entity).length > 0 && (
            <details data-testid="jobdiva-settings-sync-result-diag" style={{ marginTop: 10 }}>
              <summary style={{ fontSize: 11, color: '#7c3aed', cursor: 'pointer', fontWeight: 600 }}>
                Per-entity diagnostics
              </summary>
              <table style={{ width: '100%', fontSize: 11, marginTop: 6, background: 'rgba(255,255,255,0.7)', borderCollapse: 'collapse' }}>
                <thead>
                  <tr style={{ borderBottom: '1px solid #ddd6fe', textAlign: 'left' }}>
                    <th style={{ padding: '4px 6px' }}>Entity</th>
                    <th style={{ padding: '4px 6px' }}>Processed</th>
                    <th style={{ padding: '4px 6px' }}>Skipped</th>
                    <th style={{ padding: '4px 6px' }}>Failed</th>
                    <th style={{ padding: '4px 6px' }}>Notes</th>
                  </tr>
                </thead>
                <tbody>
                  {Object.entries(syncResult.by_entity).map(([entity, info]) => {
                    const sk = !!info?.skipped_by_config;
                    const def = info?.deferred_reason;
                    const errs = Array.isArray(info?.errors) ? info.errors : [];
                    const empty = info?.empty_response === true;
                    const fetched = info?.items_fetched;
                    const skipReasons = auditHasDetail(info?.skip_reasons) ? info.skip_reasons : null;
                    const mirrorStats = info?.placements_scanned !== undefined
                      ? `${info.placements_scanned} placements scanned; ${info.jobs_processed ?? 0} jobs, ${info.candidates_processed ?? 0} candidates, ${info.customers_processed ?? 0} contacts, ${info.assignments_processed ?? 0} assignments mirrored`
                      : null;
                    return (
                      <tr key={entity} data-testid={`jobdiva-settings-sync-result-diag-${entity}`} style={{ borderBottom: '1px solid #ede9fe' }}>
                        <td style={{ padding: '4px 6px', textTransform: 'capitalize', fontWeight: 500 }}>{entity}</td>
                        <td style={{ padding: '4px 6px', fontVariantNumeric: 'tabular-nums' }}>{info?.processed ?? 0}</td>
                        <td style={{ padding: '4px 6px', fontVariantNumeric: 'tabular-nums' }}>{info?.skipped ?? 0}</td>
                        <td style={{ padding: '4px 6px', fontVariantNumeric: 'tabular-nums', color: (info?.failed > 0) ? '#dc2626' : 'inherit' }}>{info?.failed ?? 0}</td>
                        <td style={{ padding: '4px 6px', color: '#475569' }}>
                          {sk && <span data-testid={`jobdiva-settings-sync-result-diag-${entity}-skipcfg`} style={{ display: 'block' }}>skipped_by_config</span>}
                          {def && <span data-testid={`jobdiva-settings-sync-result-diag-${entity}-deferred`} style={{ display: 'block' }}>{def}</span>}
                          {empty && <span data-testid={`jobdiva-settings-sync-result-diag-${entity}-empty`} style={{ display: 'block' }}>empty_response{info.endpoint ? ` from ${info.endpoint}` : ''}</span>}
                          {!empty && fetched !== undefined && <span data-testid={`jobdiva-settings-sync-result-diag-${entity}-fetched`} style={{ display: 'block' }}>{fetched} fetched</span>}
                          {mirrorStats && <span data-testid={`jobdiva-settings-sync-result-diag-${entity}-mirror`} style={{ display: 'block' }}>{mirrorStats}</span>}
                          {skipReasons && (
                            <code data-testid={`jobdiva-settings-sync-result-diag-${entity}-skip-reasons`}
                                  style={{ display: 'block', whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>
                              {JSON.stringify(skipReasons)}
                            </code>
                          )}
                          {errs.length > 0 && (
                            <ul style={{ margin: 0, paddingLeft: 14 }} data-testid={`jobdiva-settings-sync-result-diag-${entity}-errors`}>
                              {errs.slice(0, 3).map((e, i) => (
                                <li key={i} style={{ color: '#dc2626', fontFamily: 'ui-monospace, monospace', fontSize: 10, wordBreak: 'break-all' }}>
                                  {typeof e === 'string' ? e : (e?.message || JSON.stringify(e))}
                                </li>
                              ))}
                              {errs.length > 3 && <li style={{ color: '#94a3b8' }}>+{errs.length - 3} more</li>}
                            </ul>
                          )}
                          {!sk && !def && !empty && fetched === undefined && !mirrorStats && !skipReasons && errs.length === 0 && (info?.processed ?? 0) === 0 && '—'}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </details>
          )}
        </div>
      )}

      {/* Connect / re-connect form */}
      <form onSubmit={onConnect} data-testid="jobdiva-settings-connect-form"
            style={{ padding: 16, background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12 }}>
        <h3 style={{ margin: '0 0 4px', fontSize: 15 }}>{data?.connected ? 'Re-connect / update credentials' : 'Connect JobDiva'}</h3>
        <p style={{ color: '#64748b', fontSize: 12, margin: '0 0 12px' }}>
          Tenant logs in once. The server caches the session token and silently re-authenticates whenever it expires.
        </p>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(220px,1fr))', gap: 12 }}>
          <label style={fld}>Client ID
            <input className="input" data-testid="jobdiva-settings-client-id-input"
                   value={form.client_id} onChange={e => set('client_id', e.target.value)}
                   placeholder="JobDiva client ID" autoComplete="off" />
          </label>
          <label style={fld}>Username
            <input className="input" data-testid="jobdiva-settings-username-input"
                   value={form.username} onChange={e => set('username', e.target.value)}
                   placeholder="API user" autoComplete="off" />
          </label>
          <label style={fld}>Password
            <div style={{ display: 'flex', gap: 4 }}>
              <input className="input" type={showPwd ? 'text' : 'password'}
                     data-testid="jobdiva-settings-password-input"
                     value={form.password} onChange={e => set('password', e.target.value)}
                     placeholder="••••••••" autoComplete="new-password"
                     style={{ flex: 1 }} />
              <button type="button" data-testid="jobdiva-settings-show-pwd"
                      onClick={() => setShowPwd(s => !s)} className="btn btn--ghost"
                      style={{ fontSize: 11 }}>{showPwd ? 'Hide' : 'Show'}</button>
            </div>
          </label>
          <label style={fld}>Webhook secret (optional)
            <input className="input" data-testid="jobdiva-settings-webhook-secret-input"
                   value={form.webhook_secret} onChange={e => set('webhook_secret', e.target.value)}
                   placeholder="HMAC shared secret" autoComplete="off" />
          </label>
        </div>
        <div style={{ marginTop: 14, display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <button type="submit" className="btn btn--primary" data-testid="jobdiva-settings-connect"
                  disabled={busy.connect}>
            {busy.connect ? 'Connecting…' : (data?.connected ? 'Update credentials' : 'Connect')}
          </button>
          {data?.connected && (
            <>
              <button type="button" className="btn btn--ghost" data-testid="jobdiva-settings-ping"
                      onClick={onPing} disabled={busy.ping}>
                {busy.ping ? 'Pinging…' : 'Test connection'}
              </button>
              <button type="button" className="btn btn--ghost" data-testid="jobdiva-settings-sync"
                      onClick={onSync} disabled={busy.sync}>
                <Activity size={12} style={{ marginRight: 3, verticalAlign: 'middle' }} />
                {busy.sync ? 'Syncing…' : 'Sync now'}
              </button>
              <button type="button" className="btn btn--ghost" data-testid="jobdiva-settings-disconnect"
                      onClick={onDisconnect} disabled={busy.disconnect}
                      style={{ color: '#b91c1c' }}>
                {busy.disconnect ? 'Disconnecting…' : 'Disconnect'}
              </button>
            </>
          )}
        </div>
      </form>

      {/* Per-entity sync config picker (Slice A4). Tenant decides who owns
          each entity (JobDiva or CoreFlux) + what direction the sync runs.
          'time' defaults to OFF — the tenant must explicitly opt in. */}
      {data?.connected && data?.sync_config && (
        <div data-testid="jobdiva-settings-sync-config-card"
             style={{ padding: 16, background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12 }}>
          <h3 style={{ margin: '0 0 4px', fontSize: 14 }}>Per-entity sync configuration</h3>
          <p style={{ color: '#64748b', fontSize: 12, margin: '0 0 12px' }}>
            For each entity, choose the source of truth and the direction. <strong>time</strong> defaults to off — JobDiva and CoreFlux should not both own the same timesheet.
          </p>
          <table className="data-table" style={{ width: '100%' }} data-testid="jobdiva-settings-sync-config-table">
            <thead>
              <tr><th>Entity</th><th>Source of truth</th><th>Direction</th><th></th></tr>
            </thead>
            <tbody>
              {['company','contact','placement','time'].map(entity => {
                const cfg = data.sync_config[entity] || { source: 'jobdiva', direction: 'pull' };
                const off = cfg.direction === 'off';
                return (
                  <tr key={entity} data-testid={`jobdiva-settings-sync-config-row-${entity}`}>
                    <td style={{ textTransform: 'capitalize', fontWeight: 600 }}>{entity}</td>
                    <td>
                      <select className="input"
                              value={cfg.source} disabled={busy.config}
                              onChange={e => onConfigChange(entity, 'source', e.target.value)}
                              data-testid={`jobdiva-settings-sync-config-source-${entity}`}
                              style={{ fontSize: 13, padding: '4px 8px' }}>
                        <option value="jobdiva">JobDiva</option>
                        <option value="coreflux">CoreFlux</option>
                      </select>
                    </td>
                    <td>
                      <select className="input"
                              value={cfg.direction} disabled={busy.config}
                              onChange={e => onConfigChange(entity, 'direction', e.target.value)}
                              data-testid={`jobdiva-settings-sync-config-direction-${entity}`}
                              style={{ fontSize: 13, padding: '4px 8px' }}>
                        <option value="off">Off</option>
                        {cfg.source === 'jobdiva' && <option value="pull">Pull (JobDiva → CoreFlux)</option>}
                        {cfg.source === 'coreflux' && <option value="push">Push (CoreFlux → JobDiva)</option>}
                        <option value="two_way">Two-way</option>
                      </select>
                    </td>
                    <td style={{ fontSize: 11, color: off ? '#94a3b8' : '#059669' }}>
                      {off ? 'No sync' : 'Active'}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {/* Recent audit + recent webhook events */}
      {data?.recent_audit && (
        <div data-testid="jobdiva-settings-audit-card"
             style={{ padding: 16, background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12 }}>
          <h3 style={{ margin: '0 0 8px', fontSize: 14 }}>Recent activity</h3>
          <table className="data-table" style={{ width: '100%' }} data-testid="jobdiva-settings-audit-table">
            <thead>
              <tr><th>When</th><th>Action</th><th>Entity</th><th>Direction</th><th>OK</th><th>Items</th></tr>
            </thead>
            <tbody>
              {data.recent_audit.length === 0 && (
                <tr><td colSpan={6} className="empty" data-testid="jobdiva-settings-audit-empty">No activity yet.</td></tr>
              )}
              {data.recent_audit.map(r => {
                const hasDetail = auditHasDetail(r.detail);
                const expanded = !!expandedAudit[r.id];
                const hasCounts = r.items_processed > 0 || r.items_failed > 0 || r.items_skipped > 0;
                return (
                  <React.Fragment key={r.id}>
                    <tr data-testid={`jobdiva-settings-audit-row-${r.id}`}>
                      <td style={{ fontSize: 12, color: '#64748b' }}>{r.occurred_at}</td>
                      <td><code>{r.action}</code></td>
                      <td>{r.entity_type || '—'}</td>
                      <td>{r.direction}</td>
                      <td>{r.ok ? <CheckCircle2 size={12} color="#059669" /> : <XCircle size={12} color="#dc2626" />}</td>
                      <td style={{ fontSize: 12 }}>
                        <span>
                          {hasCounts || hasDetail
                            ? `${r.items_processed} ok · ${r.items_skipped} skip · ${r.items_failed} fail`
                            : '—'}
                        </span>
                        {hasDetail && (
                          <button
                            type="button"
                            onClick={() => setExpandedAudit(s => ({ ...s, [r.id]: !expanded }))}
                            aria-label={expanded ? 'Hide audit detail' : 'Show audit detail'}
                            data-testid={`jobdiva-settings-audit-detail-toggle-${r.id}`}
                            style={{ marginLeft: 8, border: 0, background: 'transparent', color: '#475569', cursor: 'pointer', verticalAlign: 'middle', padding: 2 }}
                          >
                            {expanded ? <ChevronDown size={13} /> : <ChevronRight size={13} />}
                          </button>
                        )}
                      </td>
                    </tr>
                    {hasDetail && expanded && (
                      <tr data-testid={`jobdiva-settings-audit-detail-${r.id}`}>
                        <td colSpan={6} style={{ padding: '8px 12px', background: '#f8fafc', borderTop: '1px solid #e2e8f0' }}>
                          <JobDivaAuditDetail detail={r.detail} />
                        </td>
                      </tr>
                    )}
                  </React.Fragment>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {data?.recent_events && data.recent_events.length > 0 && (
        <div data-testid="jobdiva-settings-webhook-events-card"
             style={{ padding: 16, background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12 }}>
          <h3 style={{ margin: '0 0 8px', fontSize: 14 }}>Recent webhook events</h3>
          <table className="data-table" style={{ width: '100%' }}>
            <thead>
              <tr><th>Received</th><th>Event</th><th>Status</th><th>Sig</th><th>JD ID</th><th>Error</th></tr>
            </thead>
            <tbody>
              {data.recent_events.map(e => (
                <tr key={e.id} data-testid={`jobdiva-settings-webhook-row-${e.id}`}>
                  <td style={{ fontSize: 12, color: '#64748b' }}>{e.received_at}</td>
                  <td><code>{e.event_type}</code></td>
                  <td>{e.status}</td>
                  <td>{e.signature_ok ? <CheckCircle2 size={12} color="#059669" /> : <XCircle size={12} color="#dc2626" />}</td>
                  <td style={{ fontSize: 11, color: '#64748b' }}>{e.jd_event_id || '—'}</td>
                  <td style={{ fontSize: 11, color: '#b91c1c' }}>{e.process_error || ''}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}

function auditHasDetail(detail) {
  return !!detail && typeof detail === 'object' && Object.keys(detail).length > 0;
}

function JobDivaAuditDetail({ detail }) {
  const errors = Array.isArray(detail?.errors) ? detail.errors.filter(Boolean) : [];
  const skipReasons = auditHasDetail(detail?.skip_reasons) ? detail.skip_reasons : null;
  const sampleKeys = auditHasDetail(detail?.sample_keys) ? detail.sample_keys : null;
  const sampleRecords = Array.isArray(detail?.sample_records) && detail.sample_records.length > 0
    ? detail.sample_records
    : null;

  const summaryKeys = [
    'endpoint', 'items_fetched', 'empty_response',
    'placements_scanned', 'unique_job_ids', 'unique_candidate_ids', 'unique_customer_ids', 'unique_start_ids',
    'jobs_returned', 'candidates_returned', 'customers_returned', 'assignments_returned',
    'jobs_processed', 'candidates_processed', 'customers_processed', 'assignments_processed',
    'assignment_channel',
  ];
  const summary = summaryKeys
    .filter(k => detail?.[k] !== undefined && detail?.[k] !== null && detail?.[k] !== '')
    .map(k => [k, detail[k]]);
  const rendered = summary.length > 0 || errors.length > 0 || skipReasons || sampleKeys || sampleRecords;

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 8, fontSize: 11, color: '#334155' }}>
      {summary.length > 0 && (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: 6 }}>
          {summary.map(([k, v]) => (
            <div key={k} style={{ border: '1px solid #e2e8f0', background: '#fff', borderRadius: 6, padding: '6px 8px' }}>
              <div style={{ textTransform: 'uppercase', letterSpacing: 0, color: '#64748b', fontSize: 10 }}>{k}</div>
              <code style={{ whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>{String(v)}</code>
            </div>
          ))}
        </div>
      )}

      {errors.length > 0 && (
        <div data-testid="jobdiva-settings-audit-detail-errors" style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
          {errors.map((e, i) => (
            <div key={i} style={{ color: '#991b1b', background: '#fef2f2', border: '1px solid #fecaca', borderRadius: 6, padding: '6px 8px' }}>
              <code style={{ whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>
                {typeof e === 'string' ? e : JSON.stringify(e, null, 2)}
              </code>
            </div>
          ))}
        </div>
      )}

      {skipReasons && <AuditJsonBlock label="Skip reasons" value={skipReasons} testid="jobdiva-settings-audit-detail-skip-reasons" />}
      {sampleKeys && <AuditJsonBlock label="Sample keys" value={sampleKeys} testid="jobdiva-settings-audit-detail-sample-keys" />}
      {sampleRecords && <AuditJsonBlock label="Sample skipped records" value={sampleRecords} testid="jobdiva-settings-audit-detail-sample-records" />}
      {!rendered && <AuditJsonBlock label="Detail" value={detail} testid="jobdiva-settings-audit-detail-json" />}
    </div>
  );
}

function AuditJsonBlock({ label, value, testid }) {
  return (
    <details data-testid={testid}>
      <summary style={{ cursor: 'pointer', fontWeight: 700, color: '#475569' }}>{label}</summary>
      <pre style={{
        margin: '6px 0 0',
        padding: 8,
        border: '1px solid #e2e8f0',
        borderRadius: 6,
        background: '#fff',
        color: '#0f172a',
        whiteSpace: 'pre-wrap',
        wordBreak: 'break-word',
        maxHeight: 240,
        overflow: 'auto',
      }}>
        {JSON.stringify(value, null, 2)}
      </pre>
    </details>
  );
}

function JobDivaMappingAlignmentCard({
  data,
  loading,
  error,
  onRefresh,
  onRepairWorkflow,
  onRepairClientLinks,
  onRepairRateDrafts,
  onRepairDuplicatePlacements,
  onRepairStalePlacements,
  onRepairStalePeople,
  repairingWorkflow,
  repairing,
  repairingRates,
  repairingDuplicates,
  repairingStale,
  repairingStalePeople,
  repairResult,
}) {
  const counts = data?.canonical_mapping_counts || data?.mapping_counts || {};
  const fields = data?.canonical_field_coverage || data?.field_coverage || {};
  const rawCounts = data?.mapping_counts || {};
  const rawFields = data?.field_coverage || {};
  const layers = data?.relationships?.mapping_layers || {};
  const readiness = data?.relationships?.projector?.workflow_readiness || {};
  const issues = Array.isArray(data?.issues) ? data.issues : [];
  const criticalIssues = issues.filter(i => i.severity === 'critical');
  const warningIssues = issues.filter(i => i.severity === 'warn');
  const critical = criticalIssues.length;
  const warn = warningIssues.length;
  const affectedRows = criticalIssues.reduce((s, i) => s + Number(i.count || 0), 0);
  const objectMap = data?.object_map || {};
  const canonicalKeys = ['placement', 'person', 'company', 'contact', 'time_entry'];
  const mirrorKeys = ['jobdiva_job', 'jobdiva_candidate', 'jobdiva_contact', 'jobdiva_assignment'];

  const countFor = (key) => Number(counts[key] || 0);
  const fieldFor = (key) => Number(fields[key] || 0);
  const totalCoreRecords = layers.canonical_mappings ?? canonicalKeys.reduce((s, k) => s + countFor(k), 0);
  const totalMirrors = layers.native_payload_mirrors ?? layers.mirror_only_rows ?? mirrorKeys.reduce((s, k) => s + Number(rawCounts[k] || 0), 0);
  const fieldPathTotal = layers.field_map_paths ?? layers.field_map_buckets ?? Object.values(fields).reduce((s, n) => s + Number(n || 0), 0);
  const identityBlockers =
    Number(readiness.missing_person || 0)
    + Number(readiness.missing_staffing_job || 0)
    + Number(readiness.missing_end_client_company || 0)
    + Number(readiness.missing_staffing_client || 0)
    + Number(readiness.client_company_mismatch || 0);
  const rateBlockers =
    Number(readiness.missing_rate_row || 0)
    + Number(readiness.active_missing_approved_rate || 0);
  const isWorkflowReady = critical === 0;
  const topIssues = issues.slice(0, 6).map(issue => ({
    ...issue,
    view: operatorIssueView(issue),
  }));
  const anyRepairing = !!(repairingWorkflow || repairing || repairingRates || repairingDuplicates || repairingStale || repairingStalePeople);

  return (
    <div data-testid="jobdiva-mapping-alignment-card"
         style={{ padding: 16, background: '#fff', border: '1px solid #cbd5e1', borderRadius: 8 }}>
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
        <div>
          <h3 style={{ margin: 0, display: 'flex', alignItems: 'center', gap: 8, fontSize: 15 }}>
            <GitBranch size={16} color="#0f766e" /> JobDiva sync health
          </h3>
          <p style={{ margin: '4px 0 0', color: '#64748b', fontSize: 12, maxWidth: 820 }}>
            This shows whether JobDiva records have landed in the CoreFlux records that placements, rates,
            billing, payroll, AP, and reporting actually use.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <button type="button" className="btn btn--ghost" onClick={onRefresh}
                  data-testid="jobdiva-mapping-alignment-refresh" disabled={loading}
                  style={{ fontSize: 12 }}>
            <RefreshCw size={12} style={{ marginRight: 4, verticalAlign: 'middle' }} />
            {loading ? 'Checking...' : 'Refresh'}
          </button>
          <button type="button" className="btn btn--primary" onClick={onRepairWorkflow}
                  data-testid="jobdiva-mapping-alignment-repair-workflow" disabled={anyRepairing}
                  style={{ fontSize: 12 }}>
            <Wrench size={12} style={{ marginRight: 4, verticalAlign: 'middle' }} />
            {repairingWorkflow ? 'Repairing alignment...' : 'Repair alignment'}
          </button>
          <button type="button" className="btn btn--primary" onClick={onRepairClientLinks}
                  data-testid="jobdiva-mapping-alignment-repair-client-links" disabled={anyRepairing}
                  style={{ fontSize: 12 }}>
            <Wrench size={12} style={{ marginRight: 4, verticalAlign: 'middle' }} />
            {repairing ? 'Repairing...' : 'Repair client links'}
          </button>
          <button type="button" className="btn btn--ghost" onClick={onRepairRateDrafts}
                  data-testid="jobdiva-mapping-alignment-repair-rate-drafts" disabled={anyRepairing}
                  style={{ fontSize: 12 }}>
            <Wrench size={12} style={{ marginRight: 4, verticalAlign: 'middle' }} />
            {repairingRates ? 'Repairing...' : 'Repair rate drafts'}
          </button>
          <Link to="/modules/placements/draft-rates" className="btn btn--ghost"
                data-testid="jobdiva-mapping-alignment-open-draft-rates"
                style={{ fontSize: 12 }}>
            Approve rates
          </Link>
        </div>
      </div>

      {error && (
        <p data-testid="jobdiva-mapping-alignment-error"
           style={{ margin: '10px 0 0', padding: 10, border: '1px solid #fecaca', background: '#fef2f2', color: '#991b1b', borderRadius: 6, fontSize: 12 }}>
          {error}
        </p>
      )}

      <div data-testid="jobdiva-mapping-alignment-health"
           style={{ marginTop: 12, padding: 12, borderRadius: 8,
                    border: `1px solid ${isWorkflowReady ? '#a7f3d0' : '#fecaca'}`,
                    background: isWorkflowReady ? '#ecfdf5' : '#fef2f2',
                    display: 'flex', alignItems: 'center', justifyContent: 'space-between',
                    gap: 12, flexWrap: 'wrap' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          {isWorkflowReady
            ? <CheckCircle2 size={18} color="#059669" />
            : <AlertTriangle size={18} color="#dc2626" />}
          <div>
            <strong style={{ color: isWorkflowReady ? '#065f46' : '#991b1b', fontSize: 14 }}>
              {isWorkflowReady ? 'JobDiva data is workflow-ready' : `${affectedRows} JobDiva-linked row${affectedRows === 1 ? '' : 's'} need attention`}
            </strong>
            <div style={{ color: isWorkflowReady ? '#047857' : '#7f1d1d', fontSize: 12, marginTop: 2 }}>
              {isWorkflowReady
                ? 'Placements can move through activation, billing, payroll, AP, and reporting.'
                : `${critical} blocker type${critical === 1 ? '' : 's'} must be cleared before the affected records are fully usable.`}
            </div>
          </div>
        </div>
        <span style={{ fontSize: 12, color: '#475569' }}>
          Last checked {data?.generated_at ? new Date(data.generated_at).toLocaleString() : 'after refresh'}
        </span>
      </div>

      <div data-testid="jobdiva-mapping-alignment-workflow"
           style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(210px,1fr))', gap: 8, marginTop: 12 }}>
        <WorkflowStep
          label="1. Imported"
          value={totalCoreRecords}
          detail={`${totalMirrors} raw JobDiva source row${totalMirrors === 1 ? '' : 's'} retained for audit`}
          tone={totalCoreRecords > 0 ? 'ok' : 'neutral'}
        />
        <WorkflowStep
          label="2. Matched"
          value={identityBlockers > 0 ? `${identityBlockers} blocked` : 'Ready'}
          detail={identityBlockers > 0 ? 'People, job, client, or company links need repair' : 'People, jobs, clients, and companies are linked'}
          tone={identityBlockers > 0 ? 'bad' : 'ok'}
        />
        <WorkflowStep
          label="3. Rates"
          value={rateBlockers > 0 ? `${rateBlockers} blocked` : 'Ready'}
          detail={rateBlockers > 0 ? 'Create or approve placement rates before activation' : 'Rates are present for workflow checks'}
          tone={rateBlockers > 0 ? 'bad' : 'ok'}
        />
        <WorkflowStep
          label="4. Processes"
          value={isWorkflowReady ? 'Ready' : `${critical} blockers`}
          detail={isWorkflowReady ? 'Activation, billing, payroll, AP, and reporting can consume the graph' : 'Clear blockers below, then refresh'}
          tone={isWorkflowReady ? 'ok' : 'bad'}
        />
      </div>

      <div style={{ display: 'none' }} aria-hidden="true">
        <AlignmentMetric label="CoreFlux records" value={totalCoreRecords} tone="ok" testid="canonical" />
        <AlignmentMetric label="Source rows" value={totalMirrors} tone="neutral" testid="mirror-only" />
        <AlignmentMetric label="Field choices" value={fieldPathTotal} tone="neutral" testid="field-paths" />
        <AlignmentMetric label="Blocker types" value={critical} tone={critical ? 'bad' : 'ok'} testid="critical" />
        <AlignmentMetric label="Warnings" value={warn} tone={warn ? 'warn' : 'ok'} testid="warnings" />
      </div>

      {repairResult && (
        'steps' in repairResult
          ? <JobDivaWorkflowRepairResult repair={repairResult} />
          : (
            <p data-testid="jobdiva-mapping-alignment-repair-result"
               style={{ margin: '10px 0 0', padding: 10, border: '1px solid #a7f3d0', background: '#ecfdf5', color: '#065f46', borderRadius: 6, fontSize: 12 }}>
              {'groups_checked' in repairResult
                ? <>Groups {repairResult.groups_checked ?? 0}; repaired {repairResult.groups_repaired ?? 0}; archived {repairResult.placements_archived ?? 0}; skipped {repairResult.skipped ?? 0}; failed {repairResult.failed ?? 0}.</>
                : 'inactivated' in repairResult
                  ? <>{repairResult.dry_run ? 'Previewed' : 'Checked'} {repairResult.checked ?? 0}; {repairResult.dry_run ? 'would inactivate' : 'inactivated'} {repairResult.inactivated ?? 0}; skipped {repairResult.skipped ?? 0}; failed {repairResult.failed ?? 0}.</>
                : 'ended' in repairResult
                  ? <>{repairResult.dry_run ? 'Previewed' : 'Checked'} {repairResult.checked ?? 0}; {repairResult.dry_run ? 'would update' : 'updated'} {repairResult.updated ?? repairResult.ended ?? 0}; ended {repairResult.ended ?? 0}; cancelled {repairResult.cancelled ?? 0}; skipped {repairResult.skipped ?? 0}; failed {repairResult.failed ?? 0}.</>
                : 'drafted' in repairResult
                  ? <>Checked {repairResult.checked ?? 0}; drafted {repairResult.drafted ?? 0}; skipped {repairResult.skipped ?? 0}; failed {repairResult.failed ?? 0}.</>
                : <>Checked {repairResult.checked ?? 0}; repaired {repairResult.repaired ?? 0}; skipped {repairResult.skipped ?? 0}; failed {repairResult.failed ?? 0}.</>}
              {Array.isArray(repairResult.errors) && repairResult.errors.length > 0 && (
                <span style={{ display: 'block', marginTop: 6, color: '#991b1b' }}>
                  First error: {repairResult.errors[0]}
                </span>
              )}
            </p>
          )
      )}

      {issues.length > 0 ? (
        <div data-testid="jobdiva-mapping-alignment-issues" style={{ marginTop: 14 }}>
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8, marginBottom: 6 }}>
            <strong style={{ color: '#0f172a', fontSize: 13 }}>What needs attention</strong>
            <span style={{ color: '#64748b', fontSize: 11 }}>{issues.length} issue type{issues.length === 1 ? '' : 's'}</span>
          </div>
          {topIssues.map(issue => (
            <div key={issue.code} data-testid={`jobdiva-mapping-alignment-issue-${issue.code}`}
                 style={{ display: 'grid', gridTemplateColumns: '110px minmax(0,1fr) auto', gap: 10,
                          alignItems: 'start', padding: '10px 0', borderTop: '1px solid #e2e8f0', fontSize: 12 }}>
              <span style={severityStyle(issue.severity)}>
                <AlertTriangle size={11} /> {issue.severity}
              </span>
              <div style={{ color: '#334155', minWidth: 0 }}>
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'baseline' }}>
                  <strong style={{ color: '#0f172a', fontSize: 13 }}>{issue.view.title}</strong>
                  <span style={{ color: '#64748b', fontVariantNumeric: 'tabular-nums' }}>
                    {issue.count} affected
                  </span>
                </div>
                <div style={{ color: '#475569', marginTop: 2 }}>{issue.view.impact}</div>
                <div style={{ color: '#64748b', marginTop: 4 }}>{issue.view.next}</div>
              </div>
              <IssueAction
                issue={issue}
                onRepairClientLinks={onRepairClientLinks}
                onRepairRateDrafts={onRepairRateDrafts}
                onRepairDuplicatePlacements={onRepairDuplicatePlacements}
                onRepairStalePlacements={onRepairStalePlacements}
                onRepairStalePeople={onRepairStalePeople}
                repairing={repairing || repairingWorkflow}
                repairingRates={repairingRates || repairingWorkflow}
                repairingDuplicates={repairingDuplicates || repairingWorkflow}
                repairingStale={repairingStale || repairingWorkflow}
                repairingStalePeople={repairingStalePeople || repairingWorkflow}
              />
            </div>
          ))}
        </div>
      ) : (
        <div data-testid="jobdiva-mapping-alignment-no-issues"
             style={{ marginTop: 12, padding: 10, border: '1px solid #bbf7d0', background: '#f0fdf4', color: '#166534', borderRadius: 6, fontSize: 12 }}>
          No JobDiva workflow blockers detected.
        </div>
      )}

      <details data-testid="jobdiva-mapping-alignment-maintenance-actions" style={{ marginTop: 12 }}>
        <summary style={{ cursor: 'pointer', fontSize: 12, fontWeight: 700, color: '#475569' }}>
          Maintenance actions
        </summary>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginTop: 8 }}>
          <button type="button" className="btn btn--ghost" onClick={() => onRepairDuplicatePlacements?.(true)}
                  data-testid="jobdiva-mapping-alignment-preview-duplicate-placements" disabled={anyRepairing}
                  style={{ fontSize: 12 }}>
            <Wrench size={12} style={{ marginRight: 4, verticalAlign: 'middle' }} />
            {repairingDuplicates ? 'Checking...' : 'Preview duplicate placements'}
          </button>
          <button type="button" className="btn btn--danger" onClick={() => onRepairDuplicatePlacements?.(false)}
                  data-testid="jobdiva-mapping-alignment-repair-duplicate-placements" disabled={anyRepairing}
                  style={{ fontSize: 12 }}>
            <Wrench size={12} style={{ marginRight: 4, verticalAlign: 'middle' }} />
            {repairingDuplicates ? 'Repairing...' : 'Archive safe duplicates'}
          </button>
          <button type="button" className="btn btn--ghost" onClick={() => onRepairStalePlacements?.(true)}
                  data-testid="jobdiva-mapping-alignment-preview-stale-placements" disabled={anyRepairing}
                  style={{ fontSize: 12 }}>
            <Wrench size={12} style={{ marginRight: 4, verticalAlign: 'middle' }} />
            {repairingStale ? 'Checking...' : 'Preview placement lifecycle'}
          </button>
          <button type="button" className="btn btn--danger" onClick={() => onRepairStalePlacements?.(false)}
                  data-testid="jobdiva-mapping-alignment-repair-stale-placements" disabled={anyRepairing}
                  style={{ fontSize: 12 }}>
            <Wrench size={12} style={{ marginRight: 4, verticalAlign: 'middle' }} />
            {repairingStale ? 'Repairing...' : 'Apply placement lifecycle'}
          </button>
          <button type="button" className="btn btn--ghost" onClick={() => onRepairStalePeople?.(true)}
                  data-testid="jobdiva-mapping-alignment-preview-stale-people" disabled={anyRepairing}
                  style={{ fontSize: 12 }}>
            <Wrench size={12} style={{ marginRight: 4, verticalAlign: 'middle' }} />
            {repairingStalePeople ? 'Checking...' : 'Preview stale People'}
          </button>
          <button type="button" className="btn btn--danger" onClick={() => onRepairStalePeople?.(false)}
                  data-testid="jobdiva-mapping-alignment-repair-stale-people" disabled={anyRepairing}
                  style={{ fontSize: 12 }}>
            <Wrench size={12} style={{ marginRight: 4, verticalAlign: 'middle' }} />
            {repairingStalePeople ? 'Repairing...' : 'Retire stale People'}
          </button>
        </div>
      </details>

      <details data-testid="jobdiva-mapping-alignment-object-map" style={{ marginTop: 14 }}>
        <summary style={{ cursor: 'pointer', fontSize: 12, fontWeight: 700, color: '#0f766e' }}>
          Advanced diagnostics: CoreFlux graph destinations
        </summary>
        <table className="data-table" style={{ width: '100%', marginTop: 8, fontSize: 12 }}>
          <thead>
            <tr><th>JobDiva source</th><th>CoreFlux record</th><th>Records</th><th>Field choices</th></tr>
          </thead>
          <tbody>
            {canonicalKeys.map(key => (
              <tr key={key} data-testid={`jobdiva-mapping-alignment-canonical-${key}`}>
                <td><code>{key}</code><div style={{ color: '#64748b' }}>{objectMap[key]?.source_object}</div></td>
                <td>{objectMap[key]?.core_owner}<div style={{ color: '#64748b' }}>{objectMap[key]?.core_table}</div></td>
                <td style={{ fontVariantNumeric: 'tabular-nums' }}>{countFor(key)}</td>
                <td style={{ fontVariantNumeric: 'tabular-nums' }}>{fieldFor(key)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </details>

      <details data-testid="jobdiva-mapping-alignment-mirror-only" style={{ marginTop: 8 }}>
        <summary style={{ cursor: 'pointer', fontSize: 12, fontWeight: 700, color: '#475569' }}>
          Advanced diagnostics: raw JobDiva source rows
        </summary>
        <table className="data-table" style={{ width: '100%', marginTop: 8, fontSize: 12 }}>
          <thead>
            <tr><th>Source row</th><th>Why it exists</th><th>Rows</th><th>Field choices</th></tr>
          </thead>
          <tbody>
            {mirrorKeys.map(key => (
              <tr key={key} data-testid={`jobdiva-mapping-alignment-mirror-${key}`}>
                <td><code>{key}</code></td>
                <td>{objectMap[key]?.identity_rule}</td>
                <td style={{ fontVariantNumeric: 'tabular-nums' }}>{Number(rawCounts[key] || 0)}</td>
                <td style={{ fontVariantNumeric: 'tabular-nums' }}>{Number(rawFields[key] || 0)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </details>

      {Array.isArray(data?.known_tensions) && data.known_tensions.length > 0 && (
        <details data-testid="jobdiva-mapping-alignment-known-tensions" style={{ marginTop: 8 }}>
          <summary style={{ cursor: 'pointer', fontSize: 12, fontWeight: 700, color: '#475569' }}>
            Architecture notes
          </summary>
          <div style={{ marginTop: 8, display: 'flex', flexDirection: 'column', gap: 4 }}>
            {data.known_tensions.map(t => (
              <div key={t.code} style={{ display: 'flex', alignItems: 'flex-start', gap: 6, color: '#64748b', fontSize: 11 }}>
                <Database size={11} style={{ marginTop: 2, flexShrink: 0 }} />
                <span><code>{t.code}</code>: {t.summary}</span>
              </div>
            ))}
          </div>
        </details>
      )}
    </div>
  );
}

const repairStepLabels = {
  assignment_sources: 'Assignment identity',
  canonical_projection: 'Canonical projection',
  duplicate_placements: 'Duplicate placements',
  client_links: 'Client links',
  stale_active_placements: 'Placement lifecycle',
  source_people_lifecycle: 'People lifecycle',
  source_rate_drafts: 'Source rate drafts',
};

function JobDivaWorkflowRepairResult({ repair }) {
  const steps = repair?.steps || {};
  const remainingTypes = repair?.remaining?.critical_issue_types ?? 0;
  const remainingRows = repair?.remaining?.critical_rows ?? 0;
  const changed = repair?.totals?.changed ?? 0;
  const failed = repair?.totals?.failed ?? 0;

  const lineFor = (key, step) => {
    if (key === 'assignment_sources') {
      return `verified ${step.verified ?? 0}/${step.checked ?? 0}; trusted source snapshots ${step.stored_assignments_trusted ?? 0}; restored ${step.placements_restored ?? 0}; explicit terminal rows archived ${step.placements_archived ?? 0}; review required ${step.review_required ?? 0}; API errors ${step.api_errors ?? 0}`;
    }
    if (key === 'canonical_projection') {
      return `projected ${step.projected ?? 0}/${step.checked ?? 0}; mapping writes ${step.mapping_writes ?? 0}; field writes ${step.field_map_writes ?? 0}; failed ${step.failed ?? 0}`;
    }
    if (key === 'duplicate_placements') {
      return `archived ${step.placements_archived ?? 0}; restored ids ${step.external_ids_restored ?? 0}; skipped ${step.skipped ?? 0}; failed ${step.failed ?? 0}`;
    }
    if (key === 'client_links') {
      return `repaired ${step.repaired ?? 0}; skipped ${step.skipped ?? 0}; failed ${step.failed ?? 0}`;
    }
    if (key === 'stale_active_placements') {
      return `updated ${step.updated ?? 0}; ended ${step.ended ?? 0}; cancelled ${step.cancelled ?? 0}; pending ${step.pending_start ?? 0}; on hold ${step.on_hold ?? 0}; failed ${step.failed ?? 0}`;
    }
    if (key === 'source_people_lifecycle') {
      return `inactivated ${step.inactivated ?? 0}; skipped ${step.skipped ?? 0}; failed ${step.failed ?? 0}`;
    }
    if (key === 'source_rate_drafts') {
      return `drafted ${step.drafted ?? 0}; skipped ${step.skipped ?? 0}; failed ${step.failed ?? 0}`;
    }
    return `checked ${step.checked ?? step.groups_checked ?? 0}; failed ${step.failed ?? 0}`;
  };
  const stepErrors = Object.entries(steps)
    .flatMap(([key, step]) => (Array.isArray(step?.errors) ? step.errors.map(err => `${repairStepLabels[key] || key}: ${err}`) : []))
    .slice(0, 3);

  return (
    <div data-testid="jobdiva-mapping-alignment-repair-workflow-result"
         style={{ margin: '10px 0 0', padding: 10, border: failed ? '1px solid #fecaca' : '1px solid #a7f3d0',
                  background: failed ? '#fef2f2' : '#ecfdf5', color: failed ? '#991b1b' : '#065f46',
                  borderRadius: 6, fontSize: 12 }}>
      <strong>
        Repair workflow finished: {changed} change{changed === 1 ? '' : 's'}; {failed} failed.
      </strong>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(220px,1fr))', gap: 6, marginTop: 8 }}>
        {Object.entries(steps).map(([key, step]) => (
          <div key={key} data-testid={`jobdiva-mapping-alignment-repair-workflow-step-${key}`}
               style={{ background: 'rgba(255,255,255,0.65)', border: '1px solid rgba(15,23,42,0.08)', borderRadius: 6, padding: 8 }}>
            <div style={{ fontWeight: 700, color: '#0f172a' }}>{repairStepLabels[key] || key}</div>
            <div style={{ color: '#475569', marginTop: 2 }}>{lineFor(key, step || {})}</div>
          </div>
        ))}
      </div>
      <div style={{ color: '#475569', marginTop: 8 }}>
        Remaining after refresh: {remainingTypes} critical blocker type{remainingTypes === 1 ? '' : 's'}, {remainingRows} affected row{remainingRows === 1 ? '' : 's'}.
      </div>
      {stepErrors.length > 0 && (
        <div data-testid="jobdiva-mapping-alignment-repair-workflow-errors"
             style={{ marginTop: 8, color: '#991b1b', fontFamily: 'ui-monospace, SFMono-Regular, monospace', whiteSpace: 'pre-wrap' }}>
          {stepErrors.map((err, idx) => <div key={idx}>{err}</div>)}
        </div>
      )}
    </div>
  );
}

function WorkflowStep({ label, value, detail, tone }) {
  const palette = {
    ok: { bg: '#f0fdf4', border: '#bbf7d0', fg: '#166534', icon: CheckCircle2 },
    bad: { bg: '#fef2f2', border: '#fecaca', fg: '#991b1b', icon: AlertTriangle },
    neutral: { bg: '#f8fafc', border: '#e2e8f0', fg: '#475569', icon: Activity },
  }[tone] || { bg: '#f8fafc', border: '#e2e8f0', fg: '#475569', icon: Activity };
  const Icon = palette.icon;
  return (
    <div style={{ border: `1px solid ${palette.border}`, background: palette.bg, borderRadius: 8, padding: 10, minWidth: 0 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 6, color: palette.fg, fontSize: 12, fontWeight: 700 }}>
        <Icon size={13} /> {label}
      </div>
      <div style={{ color: '#0f172a', fontSize: 18, lineHeight: 1.2, fontWeight: 800, marginTop: 4, fontVariantNumeric: 'tabular-nums' }}>
        {value}
      </div>
      <div style={{ color: '#64748b', fontSize: 11, marginTop: 3 }}>{detail}</div>
    </div>
  );
}

function operatorIssueView(issue) {
  const code = issue?.code || '';
  const fallback = {
    title: issue?.summary || 'JobDiva record needs attention',
    impact: issue?.summary || 'This data exists, but it is not fully usable yet.',
    next: issue?.action || 'Review the source record, run sync or repair, then refresh.',
  };
  return ({
    placement_active_missing_approved_rate: {
      title: 'Approve rates before activation',
      impact: 'These active JobDiva placements do not have an approved rate covering the placement start date.',
      next: 'Use Repair rate drafts to create or correct source-backed drafts, then approve valid rates.',
    },
    placement_missing_rate_row: {
      title: 'Create missing placement rates',
      impact: 'These JobDiva placements have no pricing row in CoreFlux, so billing and payroll cannot price the work.',
      next: 'Use Repair rate drafts, then approve any draft rates that appear.',
    },
    placement_auto_rate_bill_equals_pay: {
      title: 'Repair unsafe auto-drafted rates',
      impact: 'These JobDiva auto-drafted rates have identical bill and pay values, which would approve a fake zero-margin contract.',
      next: 'Use Repair rates to rebuild them from a real JobDiva pay field or remove unsafe drafts before approval.',
    },
    placement_missing_end_client_company: {
      title: 'Repair end-client company link',
      impact: 'The placement exists, but it is not tied to the company record that billing, AP, and payroll share.',
      next: 'Use Repair client links to rebuild the company and staffing client bridge.',
    },
    placement_missing_staffing_client: {
      title: 'Repair staffing client link',
      impact: 'The placement is missing placements.client_id, so staffing workflows cannot group it by client.',
      next: 'Use Repair client links to rebuild the staffing client row from the company graph.',
    },
    placement_missing_staffing_job: {
      title: 'Link the JobDiva job',
      impact: 'The placement is missing its staffing job record, so job details cannot flow into placements and reports.',
      next: 'Run JobDiva sync with job mirrors, then refresh sync health.',
    },
    duplicate_jobdiva_placement_rows: {
      title: 'Remove duplicate placement rows',
      impact: 'One JobDiva or legacy external placement identity points at more than one active CoreFlux placement row.',
      next: 'Preview duplicates first; archive only duplicate rows with no downstream activity.',
    },
    placement_active_past_end_date: {
      title: 'Repair placement lifecycle',
      impact: 'These active CoreFlux placements disagree with the JobDiva lifecycle or have already passed their end date.',
      next: 'Preview the source-backed changes, then apply ended, cancelled, pending, or on-hold states.',
    },
    jobdiva_person_without_live_placement: {
      title: 'Retire stale People records',
      impact: 'These former JobDiva workers have placement history but no current or upcoming placement.',
      next: 'Preview first, then mark them inactive while retaining their history and source identity.',
    },
  })[code] || fallback;
}

function IssueAction({ issue, onRepairClientLinks, onRepairRateDrafts, onRepairDuplicatePlacements, onRepairStalePlacements, onRepairStalePeople, repairing, repairingRates, repairingDuplicates, repairingStale, repairingStalePeople }) {
  const code = issue?.code || '';
  if (code === 'placement_missing_rate_row' || code === 'placement_auto_rate_bill_equals_pay') {
    return (
      <button type="button" className="btn btn--primary" onClick={onRepairRateDrafts}
              disabled={repairingRates} style={{ fontSize: 12, whiteSpace: 'nowrap' }}>
        {repairingRates ? 'Repairing...' : 'Repair rates'}
      </button>
    );
  }
  if (code === 'placement_active_missing_approved_rate') {
    return (
      <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', justifyContent: 'flex-end' }}>
        <button type="button" className="btn btn--primary" onClick={onRepairRateDrafts}
                disabled={repairingRates} style={{ fontSize: 12, whiteSpace: 'nowrap' }}>
          {repairingRates ? 'Repairing...' : 'Repair rates'}
        </button>
        <Link to="/modules/placements/draft-rates" className="btn btn--ghost" style={{ fontSize: 12, whiteSpace: 'nowrap' }}>
          Approve rates
        </Link>
      </div>
    );
  }
  if (code === 'placement_missing_end_client_company' || code === 'placement_missing_staffing_client') {
    return (
      <button type="button" className="btn btn--primary" onClick={onRepairClientLinks}
              disabled={repairing} style={{ fontSize: 12, whiteSpace: 'nowrap' }}>
        {repairing ? 'Repairing...' : 'Repair links'}
      </button>
    );
  }
  if (code === 'duplicate_jobdiva_placement_rows') {
    return (
      <button type="button" className="btn btn--ghost" onClick={() => onRepairDuplicatePlacements?.(true)}
              disabled={repairingDuplicates} style={{ fontSize: 12, whiteSpace: 'nowrap' }}>
        {repairingDuplicates ? 'Checking...' : 'Preview'}
      </button>
    );
  }
  if (code === 'placement_active_past_end_date') {
    return (
      <button type="button" className="btn btn--ghost" onClick={() => onRepairStalePlacements?.(true)}
              disabled={repairingStale} style={{ fontSize: 12, whiteSpace: 'nowrap' }}>
        {repairingStale ? 'Checking...' : 'Preview'}
      </button>
    );
  }
  if (code === 'jobdiva_person_without_live_placement') {
    return (
      <button type="button" className="btn btn--ghost" onClick={() => onRepairStalePeople?.(true)}
              disabled={repairingStalePeople} style={{ fontSize: 12, whiteSpace: 'nowrap' }}>
        {repairingStalePeople ? 'Checking...' : 'Preview'}
      </button>
    );
  }
  return <span style={{ color: '#94a3b8', fontSize: 11 }}>Refresh after repair</span>;
}

function AlignmentMetric({ label, value, tone, testid }) {
  const palette = {
    ok: { bg: '#ecfdf5', border: '#a7f3d0', fg: '#065f46' },
    warn: { bg: '#fffbeb', border: '#fde68a', fg: '#92400e' },
    bad: { bg: '#fef2f2', border: '#fecaca', fg: '#991b1b' },
    neutral: { bg: '#f8fafc', border: '#e2e8f0', fg: '#334155' },
  }[tone] || { bg: '#f8fafc', border: '#e2e8f0', fg: '#334155' };
  return (
    <div data-testid={`jobdiva-mapping-alignment-metric-${testid}`}
         style={{ border: `1px solid ${palette.border}`, background: palette.bg, color: palette.fg,
                  borderRadius: 8, padding: '10px 12px' }}>
      <div style={{ fontSize: 11, textTransform: 'uppercase', letterSpacing: 0 }}>{label}</div>
      <strong style={{ fontSize: 20, lineHeight: 1.2, fontVariantNumeric: 'tabular-nums' }}>{value ?? 0}</strong>
    </div>
  );
}

function severityStyle(severity) {
  const p = {
    critical: { bg: '#fee2e2', fg: '#991b1b', border: '#fecaca' },
    warn: { bg: '#fef3c7', fg: '#92400e', border: '#fde68a' },
    info: { bg: '#dbeafe', fg: '#1e40af', border: '#bfdbfe' },
  }[severity] || { bg: '#f1f5f9', fg: '#475569', border: '#e2e8f0' };
  return {
    display: 'inline-flex',
    alignItems: 'center',
    gap: 4,
    justifyContent: 'center',
    padding: '2px 6px',
    borderRadius: 999,
    background: p.bg,
    color: p.fg,
    border: `1px solid ${p.border}`,
    fontSize: 10,
    fontWeight: 700,
    textTransform: 'uppercase',
  };
}

const lbl  = { fontSize: 11, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: 0.4 };
const mono = { fontFamily: 'ui-monospace, monospace', fontSize: 13, color: '#0f172a' };
const fld  = { display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: '#475569' };

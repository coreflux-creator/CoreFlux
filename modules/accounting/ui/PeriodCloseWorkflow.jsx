import React, { useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';
import { useActiveEntity } from '../../../dashboard/src/lib/useActiveEntity';

/**
 * PeriodCloseWorkflow — runs the 9-step accounting close checklist
 * (Sprint 2 / B4) end-to-end for a single period.
 *
 * Endpoints:
 *   GET   /modules/accounting/api/close_tasks.php?period_id=N
 *   POST  /modules/accounting/api/close_tasks.php?action=seed         { period_id }
 *   POST  /modules/accounting/api/close_tasks.php?action=complete&id=N { notes? }
 *   PATCH /modules/accounting/api/close_tasks.php                     { id, status?, assignee_user_id?, due_date?, notes? }
 *   GET   /modules/accounting/api/close_packet.php?period_id=N
 *   GET   /modules/accounting/api/close_packet.php?period_id=N&action=list
 *   POST  /modules/accounting/api/close_packet.php?period_id=N&action=record
 *
 * Picks period from the existing periods endpoint; surfaces the
 * checklist with completion stamps, a live preview and saved packet versions.
 */
export default function PeriodCloseWorkflow() {
  const { activeEntityId, activeEntity, entityQuery } = useActiveEntity();
  const periodsApi = useApi('/modules/accounting/api/periods.php' + entityQuery('?'));
  const periods = periodsApi.data?.rows ?? [];
  const [periodId, setPeriodId] = useState(null);

  const tasksApi = useApi(periodId ? `/modules/accounting/api/close_tasks.php?period_id=${periodId}` : null,
                          { enabled: !!periodId });
  const tasks = tasksApi.data?.tasks ?? [];
  const stats = tasksApi.data?.stats ?? null;
  const packetsApi = useApi(periodId
    ? `/modules/accounting/api/close_packet.php?period_id=${periodId}&action=list` : null,
  { enabled: !!periodId });
  const packets = packetsApi.data?.period_id === periodId ? packetsApi.data.rows ?? [] : [];
  const selectedPeriod = periods.find(p => Number(p.id) === periodId);
  const canSavePacket = ['closed', 'locked'].includes(selectedPeriod?.status);

  const [busy, setBusy] = useState(null);
  const [err, setErr]   = useState(null);
  const [readiness, setReadiness] = useState(null);
  const [readinessBusy, setReadinessBusy] = useState(false);
  const [packetNotice, setPacketNotice] = useState(null);

  const askReadiness = async () => {
    if (!periodId) return;
    setReadinessBusy(true);
    try {
      const r = await api.post(`/modules/accounting/api/close_ai.php?action=readiness&period_id=${periodId}`, {});
      setReadiness(r);
    } catch {
      setReadiness({ summary: '', signals: null, _error: true });
    } finally {
      setReadinessBusy(false);
    }
  };

  const seed = async () => {
    if (!periodId) return;
    if (!confirm('Seed the default 9-step close checklist into this period?')) return;
    setBusy('seed'); setErr(null);
    try {
      await api.post('/modules/accounting/api/close_tasks.php?action=seed', { period_id: periodId });
      await tasksApi.reload();
    } catch (e) { setErr(e); } finally { setBusy(null); }
  };

  const complete = async (taskId) => {
    setBusy(`complete-${taskId}`); setErr(null);
    try {
      await api.post(`/modules/accounting/api/close_tasks.php?action=complete&id=${taskId}`, {});
      await tasksApi.reload();
    } catch (e) { setErr(e); } finally { setBusy(null); }
  };

  const setStatus = async (taskId, status) => {
    setBusy(`status-${taskId}`); setErr(null);
    try {
      await api.patch('/modules/accounting/api/close_tasks.php', { id: taskId, status });
      await tasksApi.reload();
    } catch (e) { setErr(e); } finally { setBusy(null); }
  };

  const showPacket = async (packetId = null, download = false) => {
    if (!periodId) return;
    const popup = download ? null : window.open('', '_blank');
    setBusy('packet-view'); setErr(null);
    try {
      const query = `/modules/accounting/api/close_packet.php?period_id=${periodId}`
        + (packetId ? `&packet_id=${packetId}` : '');
      const result = await api.get(query);
      const url = URL.createObjectURL(new Blob([result.html], { type: 'text/html;charset=utf-8' }));
      if (download) {
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = `close-packet-period-${periodId}-${packetId ? `version-${packetId}` : 'preview'}.html`;
        anchor.click();
        setTimeout(() => URL.revokeObjectURL(url), 60000);
      } else if (popup) {
        popup.opener = null;
        popup.location.href = url;
      } else {
        URL.revokeObjectURL(url);
        setErr(new Error('Your browser blocked the preview. Use Download instead.'));
      }
    } catch (e) {
      if (popup) popup.close();
      setErr(e);
    } finally { setBusy(null); }
  };

  const buildPacket = async () => {
    if (!periodId || !canSavePacket) return;
    setBusy('packet-save'); setErr(null); setPacketNotice(null);
    try {
      const recorded = await api.post(
        `/modules/accounting/api/close_packet.php?period_id=${periodId}&action=record`, {});
      setPacketNotice(`Saved version ${recorded.id}`);
      await packetsApi.reload();
    } catch (e) { setErr(e); } finally { setBusy(null); }
  };

  return (
    <section data-testid="accounting-period-close-workflow">
      <header style={{ marginBottom: 16 }}>
        <h2 style={{ margin: 0 }}>Period close workflow</h2>
        <p style={{ margin: '4px 0 0', fontSize: 13, color: '#666' }}>
          Pick a period, seed the 9-step checklist, walk it task-by-task, and build the printable close packet.
        </p>
        {activeEntity && (
          <p style={{ margin: '4px 0 0', fontSize: 12, color: '#1e40af' }} data-testid="close-entity-scope">
            Scoped to entity <code>{activeEntity.code}</code>.
          </p>
        )}
      </header>

      <div style={{ display: 'flex', gap: 12, alignItems: 'center', marginBottom: 16, flexWrap: 'wrap' }}>
        <label style={{ fontSize: 13, color: '#475569' }}>Period:</label>
        <select className="input" data-testid="close-period-select"
                value={periodId ?? ''}
                onChange={e => setPeriodId(e.target.value ? Number(e.target.value) : null)}>
          <option value="">— choose —</option>
          {periods.map(p => (
            <option key={p.id} value={p.id}>P{p.period_number} · {p.start_date} → {p.end_date} · {p.status}</option>
          ))}
        </select>

        {!!periodId && tasks.length === 0 && (
          <button className="btn btn--primary" data-testid="close-seed" disabled={busy === 'seed'} onClick={seed}>
            {busy === 'seed' ? 'Seeding…' : 'Seed default 9-step checklist'}
          </button>
        )}

        {!!periodId && <button className="btn btn--ghost" type="button"
          data-testid="close-preview-packet" disabled={busy === 'packet-view'}
          onClick={() => showPacket()}>
          Preview packet
        </button>}
        {!!periodId && canSavePacket && <button className="btn btn--primary" type="button"
          data-testid="close-build-packet" disabled={busy === 'packet-save'}
          onClick={buildPacket}>
          {busy === 'packet-save' ? 'Saving…' : 'Save close packet'}
        </button>}
      </div>

      {err && <p className="error" data-testid="close-error">Error: {err.message}</p>}
      {tasksApi.error && <p className="error">Error: {tasksApi.error.message}</p>}
      {periodsApi.error && <p className="error">Periods load error: {periodsApi.error.message}</p>}
      {packetsApi.error && <p className="error">Saved packets load error: {packetsApi.error.message}</p>}
      {packetNotice && <p role="status" data-testid="close-packet-saved">{packetNotice}</p>}
      {!!periodId && !canSavePacket && <p style={{ color: '#64748b', fontSize: 13 }}>
        Close the period to save a version. Preview reflects the current books.
      </p>}

      {!periodId && (
        <div data-testid="close-empty"
             style={{ padding: 32, textAlign: 'center', background: '#f8fafc', borderRadius: 10, color: '#64748b' }}>
          Select a period above to start.
        </div>
      )}

      {!!periodId && stats && (
        <div data-testid="close-stats"
             style={{ display: 'grid', gridTemplateColumns: 'repeat(5, 1fr)', gap: 8, marginBottom: 12 }}>
          <Stat label="Total"        value={stats.total}        color="#475569" />
          <Stat label="Done"         value={stats.done}         color="#16a34a" />
          <Stat label="In progress"  value={stats.in_progress}  color="#2563eb" />
          <Stat label="Pending"      value={stats.pending}      color="#64748b" />
          <Stat label="Blocked"      value={stats.blocked}      color="#dc2626" />
        </div>
      )}

      {!!periodId && (
        <div data-testid="close-readiness-block" style={{ marginBottom: 12 }}>
          {!readiness && (
            <button className="btn btn--ghost"
                    data-testid="close-readiness-ask"
                    disabled={readinessBusy}
                    onClick={askReadiness}
                    style={{ color: '#0369a1', borderColor: '#bae6fd' }}>
              {readinessBusy ? 'Thinking…' : '✨ AI close readiness · what is blocking close?'}
            </button>
          )}
          {!!readiness && (
            <div data-testid="close-readiness-card"
                 style={{ padding: 14, background: '#f0f9ff', border: '1px solid #bae6fd', borderRadius: 10 }}>
              <div style={{ fontSize: 11, color: '#0369a1', fontWeight: 600, textTransform: 'uppercase', letterSpacing: 0.5, marginBottom: 6 }}>
                AI close readiness · advisory only
              </div>
              {readiness.summary
                ? <p data-testid="close-readiness-summary" style={{ margin: 0, color: '#0c4a6e', lineHeight: 1.5 }}>{readiness.summary}</p>
                : <p style={{ margin: 0, color: '#64748b' }}>Summary unavailable right now — try again in a moment.</p>}
              {!!readiness.signals && (
                <div style={{ marginTop: 10, display: 'flex', gap: 14, flexWrap: 'wrap', fontSize: 12, color: '#0369a1' }}>
                  <span data-testid="close-readiness-signal-open">Open tasks: <strong>{readiness.signals.open_tasks}</strong></span>
                  <span>Blocked: <strong>{readiness.signals.blocked_tasks}</strong></span>
                  <span>Draft JEs in period: <strong>{readiness.signals.unposted_journal_entries}</strong></span>
                  <span>Pending-review timesheets: <strong>{readiness.signals.pending_review_timesheets}</strong></span>
                </div>
              )}
              <button className="btn btn--ghost"
                      data-testid="close-readiness-refresh"
                      disabled={readinessBusy}
                      onClick={askReadiness}
                      style={{ marginTop: 10, fontSize: 12 }}>
                {readinessBusy ? 'Refreshing…' : 'Refresh'}
              </button>
            </div>
          )}
        </div>
      )}

      {!!periodId && tasks.length > 0 && (
        <ol data-testid="close-task-list" style={{ listStyle: 'none', padding: 0, display: 'grid', gap: 8 }}>
          {tasks.map(t => (
            <li key={t.id}
                data-testid={`close-task-${t.id}`}
                style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: 10, padding: 14 }}>
              <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12 }}>
                <div style={{ flex: 1 }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <span style={{ fontSize: 11, color: '#94a3b8', fontFamily: 'monospace' }}>#{t.sort_order}</span>
                    <strong style={{ color: '#0f172a' }}>{t.title}</strong>
                    <StatusPill status={t.status} />
                  </div>
                  {t.description && <p style={{ margin: '6px 0', fontSize: 13, color: '#475569' }}>{t.description}</p>}
                  <div style={{ fontSize: 12, color: '#64748b', display: 'flex', gap: 12, flexWrap: 'wrap', marginTop: 4 }}>
                    {t.assignee_name && <span>Assignee: <strong>{t.assignee_name}</strong></span>}
                    {t.due_date      && <span>Due: {t.due_date}</span>}
                    {t.completed_at  && <span>Completed: {t.completed_at}{t.completed_by_name ? ` by ${t.completed_by_name}` : ''}</span>}
                  </div>
                </div>
                <div style={{ display: 'flex', gap: 6 }}>
                  {t.status !== 'done' && t.status !== 'in_progress' && (
                    <button className="btn btn--ghost" style={{ fontSize: 12 }}
                            data-testid={`close-task-start-${t.id}`}
                            disabled={busy === `status-${t.id}`}
                            onClick={() => setStatus(t.id, 'in_progress')}>Start</button>
                  )}
                  {t.status !== 'done' && (
                    <button className="btn btn--primary" style={{ fontSize: 12 }}
                            data-testid={`close-task-complete-${t.id}`}
                            disabled={busy === `complete-${t.id}`}
                            onClick={() => complete(t.id)}>Complete</button>
                  )}
                  {t.status !== 'blocked' && t.status !== 'done' && (
                    <button className="btn btn--ghost" style={{ fontSize: 12, color: '#dc2626' }}
                            data-testid={`close-task-block-${t.id}`}
                            disabled={busy === `status-${t.id}`}
                            onClick={() => setStatus(t.id, 'blocked')}>Block</button>
                  )}
                </div>
              </div>
            </li>
          ))}
        </ol>
      )}

      {!!periodId && packets.length > 0 && (
        <section data-testid="close-saved-packets" style={{ marginTop: 20 }}>
          <h3 style={{ fontSize: 16, marginBottom: 8 }}>Saved packets</h3>
          <div style={{ display: 'grid', gap: 8 }}>
            {packets.map(packet => (
              <div key={packet.id} style={{ display: 'flex', alignItems: 'center', gap: 12,
                flexWrap: 'wrap', borderBottom: '1px solid #e2e8f0', padding: '8px 0', fontSize: 13 }}>
                <strong>Version {packet.id}</strong>
                <span>{packet.built_at}</span>
                <span>{packet.summary?.period_status ?? 'Status unknown'}</span>
                <code title={packet.content_sha256}>SHA-256 {packet.content_sha256.slice(0, 12)}</code>
                <button className="btn btn--ghost" type="button"
                  onClick={() => showPacket(packet.id)}>View</button>
                <button className="btn btn--ghost" type="button"
                  onClick={() => showPacket(packet.id, true)}>Download</button>
              </div>
            ))}
          </div>
        </section>
      )}
    </section>
  );
}

function Stat({ label, value, color }) {
  return (
    <div style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: 8, padding: '10px 12px' }}>
      <div style={{ fontSize: 11, color: '#64748b', textTransform: 'uppercase', letterSpacing: 0.4 }}>{label}</div>
      <div style={{ fontSize: 22, fontWeight: 700, color, marginTop: 2 }}>{value}</div>
    </div>
  );
}

function StatusPill({ status }) {
  const colors = {
    pending:     { bg: '#e2e8f0', fg: '#475569' },
    in_progress: { bg: '#dbeafe', fg: '#1d4ed8' },
    done:        { bg: '#dcfce7', fg: '#166534' },
    blocked:     { bg: '#fee2e2', fg: '#991b1b' },
  };
  const c = colors[status] || { bg: '#e5e7eb', fg: '#374151' };
  return (
    <span data-testid={`close-task-pill-${status}`}
          style={{ background: c.bg, color: c.fg, padding: '2px 8px', borderRadius: 12,
                   fontSize: 11, fontWeight: 600, textTransform: 'uppercase' }}>
      {status.replace('_', ' ')}
    </span>
  );
}

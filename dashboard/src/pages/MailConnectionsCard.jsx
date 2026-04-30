import React, { useEffect, useState } from 'react';
import { api } from '../lib/api';
import { Card } from '../components/UIComponents';
import { Inbox, RefreshCw, Trash2, Plug, ChevronRight } from 'lucide-react';

/**
 * Inbound mail connections card for MailSettingsPage. Lists connected
 * mailboxes (M365 only in Slice 2a), shows their watched folders, and
 * lets the tenant admin connect a new mailbox via OAuth + pick a folder
 * to watch + manually trigger a poll.
 */
export default function MailConnectionsCard({ flash }) {
  const [data, setData] = useState({ connections: [], folders: [] });
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(null);
  const [error, setError] = useState(null);
  const [pickerFor, setPickerFor] = useState(null);    // connection_id we're picking a folder for
  const [folderList, setFolderList] = useState([]);
  const [pollResult, setPollResult] = useState(null);

  const load = async () => {
    setLoading(true); setError(null);
    try { setData(await api.get('/api/mail_connections.php')); }
    catch (e) { setError(e); }
    finally { setLoading(false); }
  };
  useEffect(() => { load(); }, []);

  const connect = async () => {
    setBusy('connect'); setError(null);
    try {
      const res = await api.post('/api/mail_connections.php?action=oauth_start&provider=m365', {});
      window.location.href = res.authorize_url;
    } catch (e) { setError(e); setBusy(null); }
  };

  const openPicker = async (connectionId) => {
    setBusy('list-' + connectionId); setError(null); setFolderList([]);
    try {
      const res = await api.post(`/api/mail_connections.php?action=list_folders&connection_id=${connectionId}`, {});
      setFolderList(res.folders || []);
      setPickerFor(connectionId);
    } catch (e) { setError(e); }
    finally { setBusy(null); }
  };

  const watchFolder = async (folder) => {
    setBusy('watch-' + folder.id); setError(null);
    try {
      await api.post('/api/mail_connections.php?action=watch_folder', {
        connection_id: pickerFor,
        folder_id_at_provider: folder.id,
        folder_path: folder.display_name,
        module: 'time',
        polling_interval_seconds: 600,
      });
      setPickerFor(null);
      await load();
    } catch (e) { setError(e); }
    finally { setBusy(null); }
  };

  const pollNow = async (folderId) => {
    setBusy('poll-' + folderId); setError(null); setPollResult(null);
    try {
      const res = await api.post(`/api/mail_connections.php?action=poll_now&folder_id=${folderId}`, {});
      setPollResult({ folderId, ...res });
      await load();
    } catch (e) { setError(e); }
    finally { setBusy(null); }
  };

  const revoke = async (connectionId) => {
    if (!confirm('Disconnect this mailbox? Polling stops immediately. You can reconnect later.')) return;
    setBusy('revoke-' + connectionId); setError(null);
    try {
      await api.delete(`/api/mail_connections.php?id=${connectionId}`);
      await load();
    } catch (e) { setError(e); }
    finally { setBusy(null); }
  };

  return (
    <Card>
      <div style={{ display: 'flex', gap: 'var(--cf-space-4)', alignItems: 'flex-start', marginBottom: 'var(--cf-space-3)' }}>
        <div style={{ width: 40, height: 40, borderRadius: 8, background: 'var(--cf-blue-bg, #eff6ff)', color: 'var(--cf-blue, #1d4ed8)', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
          <Inbox size={20} />
        </div>
        <div style={{ flex: 1 }}>
          <h3 style={{ margin: '0 0 4px' }}>Inbound mailboxes</h3>
          <p style={{ margin: 0, color: 'var(--cf-text-secondary)', fontSize: 14 }}>
            Connect a Microsoft 365 mailbox so the Time module can ingest
            timesheet emails. Pick a folder (e.g. <code>Inbox/Timesheets</code>)
            once connected. We never read mail outside the folders you pick.
          </p>
        </div>
        <button className="btn btn--primary" onClick={connect} disabled={busy === 'connect'} data-testid="mail-connect-m365">
          <Plug size={14} style={{ marginRight: 6, verticalAlign: -2 }} />
          {busy === 'connect' ? 'Redirecting…' : 'Connect Microsoft 365'}
        </button>
      </div>

      {flash?.kind === 'connected' && (
        <div data-testid="mail-flash-connected" style={{ background: '#ecfdf5', border: '1px solid #a7f3d0', color: '#047857', padding: 10, borderRadius: 8, fontSize: 14, marginBottom: 12 }}>
          Connected <strong>{flash.email}</strong>. Pick a folder below to start watching.
        </div>
      )}
      {flash?.kind === 'error' && (
        <div data-testid="mail-flash-error" style={{ background: '#fef2f2', border: '1px solid #fecaca', color: '#b91c1c', padding: 10, borderRadius: 8, fontSize: 14, marginBottom: 12 }}>
          Couldn't connect: {flash.msg}
        </div>
      )}
      {error && <p className="error" data-testid="mail-connections-error" style={{ marginTop: 8 }}>Error: {error.message}</p>}
      {loading && <p style={{ color: 'var(--cf-text-secondary)', fontSize: 14 }}>Loading…</p>}

      {!loading && data.connections.length === 0 && (
        <p style={{ color: 'var(--cf-text-secondary)', fontSize: 14, fontStyle: 'italic' }} data-testid="mail-connections-empty">
          No mailboxes connected yet.
        </p>
      )}

      {data.connections.map(c => {
        const folders = data.folders.filter(f => f.connection_id === c.id);
        const expired = c.oauth_expires_at && new Date(c.oauth_expires_at) < new Date();
        return (
          <div key={c.id} data-testid={`mail-connection-${c.id}`} style={{ borderTop: '1px solid var(--cf-border, #e5e7eb)', paddingTop: 'var(--cf-space-4)', marginTop: 'var(--cf-space-4)' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 12 }}>
              <div>
                <strong style={{ fontSize: 15 }}>{c.account_address}</strong>
                <span className={`badge badge--${c.status === 'active' ? 'success' : c.status === 'reauth_required' ? 'warning' : 'neutral'}`} style={{ marginLeft: 8 }}>{c.status}</span>
                <div style={{ color: 'var(--cf-text-secondary)', fontSize: 12, marginTop: 2 }}>
                  {c.display_name} · {c.provider} · {c.purpose}
                </div>
                {c.error_message && <div style={{ color: 'var(--cf-danger, #b91c1c)', fontSize: 12, marginTop: 4 }}>{c.error_message}</div>}
              </div>
              <div style={{ display: 'flex', gap: 8 }}>
                <button className="btn" onClick={() => openPicker(c.id)} disabled={busy === 'list-' + c.id} data-testid={`mail-pick-folder-${c.id}`}>
                  {busy === 'list-' + c.id ? 'Loading folders…' : 'Pick folder…'}
                </button>
                <button className="btn btn--ghost" onClick={() => revoke(c.id)} disabled={busy === 'revoke-' + c.id} data-testid={`mail-revoke-${c.id}`}>
                  <Trash2 size={14} />
                </button>
              </div>
            </div>

            {folders.length > 0 && (
              <table className="data-table" style={{ marginTop: 12 }} data-testid={`mail-folders-${c.id}`}>
                <thead><tr><th>Folder</th><th>Module</th><th>Last polled</th><th>Cursor</th><th></th></tr></thead>
                <tbody>
                  {folders.map(f => (
                    <tr key={f.id} data-testid={`mail-folder-row-${f.id}`}>
                      <td>{f.folder_path}</td>
                      <td><span className="badge badge--info">{f.module}</span></td>
                      <td>{f.last_polled_at ? f.last_polled_at.replace('T',' ').slice(0,16) : '—'}</td>
                      <td>{f.has_cursor ? '✓' : '—'}</td>
                      <td>
                        <button className="btn" onClick={() => pollNow(f.id)} disabled={busy === 'poll-' + f.id} data-testid={`mail-poll-now-${f.id}`}>
                          <RefreshCw size={13} style={{ marginRight: 4, verticalAlign: -2 }} />
                          {busy === 'poll-' + f.id ? 'Polling…' : 'Fetch now'}
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        );
      })}

      {pickerFor && (
        <div data-testid="mail-folder-picker" style={{ position: 'fixed', inset: 0, background: 'rgba(15,18,28,0.5)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }} onClick={(e) => e.target === e.currentTarget && setPickerFor(null)}>
          <div style={{ background: 'var(--cf-surface, #fff)', borderRadius: 12, width: 'min(560px, 100%)', maxHeight: '85vh', display: 'flex', flexDirection: 'column' }}>
            <header style={{ padding: 16, borderBottom: '1px solid var(--cf-border, #e5e7eb)' }}>
              <h3 style={{ margin: 0 }}>Pick a folder to watch for timesheets</h3>
              <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--cf-text-muted, #6b7280)' }}>The Time module will check this folder for new emails every 10 minutes.</p>
            </header>
            <div style={{ overflow: 'auto', padding: 8 }}>
              {folderList.length === 0 && <p style={{ padding: 12, color: 'var(--cf-text-muted, #6b7280)' }}>No folders.</p>}
              {folderList.map(f => (
                <button
                  key={f.id}
                  onClick={() => watchFolder(f)}
                  disabled={busy === 'watch-' + f.id}
                  data-testid={`mail-folder-pick-${f.id}`}
                  style={{ width: '100%', padding: 12, border: 'none', background: 'transparent', textAlign: 'left', cursor: 'pointer', display: 'flex', justifyContent: 'space-between', alignItems: 'center', borderRadius: 6 }}
                  onMouseOver={(e) => e.currentTarget.style.background = 'var(--cf-surface-alt, #f9fafb)'}
                  onMouseOut={(e) => e.currentTarget.style.background = 'transparent'}
                >
                  <span>
                    <strong>{f.display_name}</strong>
                    {f.child_folder_count > 0 && <span style={{ color: 'var(--cf-text-muted, #6b7280)', fontSize: 12, marginLeft: 8 }}>({f.child_folder_count} subfolders)</span>}
                  </span>
                  <span style={{ fontSize: 12, color: 'var(--cf-text-muted, #6b7280)' }}>{f.total_item_count} items <ChevronRight size={14} style={{ verticalAlign: -2 }} /></span>
                </button>
              ))}
            </div>
            <footer style={{ padding: 12, borderTop: '1px solid var(--cf-border, #e5e7eb)', textAlign: 'right' }}>
              <button className="btn btn--ghost" onClick={() => setPickerFor(null)} data-testid="mail-folder-picker-close">Cancel</button>
            </footer>
          </div>
        </div>
      )}

      {pollResult && (
        <div data-testid="mail-poll-result" style={{ marginTop: 12, padding: 12, background: 'var(--cf-surface-alt, #f9fafb)', borderRadius: 8, fontSize: 13 }}>
          <strong>Poll complete.</strong> {pollResult.messages_seen} message(s) seen. Sample subjects:
          <ul style={{ margin: '6px 0 0 18px' }}>
            {(pollResult.sample || []).slice(0, 5).map((m, i) => (
              <li key={i}><strong>{m.subject || '(no subject)'}</strong> <span style={{ color: 'var(--cf-text-muted, #6b7280)' }}>— {m.from_address || 'unknown'}</span></li>
            ))}
            {pollResult.messages_seen === 0 && <li style={{ color: 'var(--cf-text-muted, #6b7280)' }}>(empty — folder is up-to-date or has no messages since last poll)</li>}
          </ul>
        </div>
      )}
    </Card>
  );
}

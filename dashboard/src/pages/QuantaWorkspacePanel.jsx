import React, { useState } from 'react';
import { api } from '../lib/api';

export default function QuantaWorkspacePanel({ workspace }) {
  const [context, setContext] = useState(null);
  const [confirmed, setConfirmed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const check = async () => {
    setBusy(true); setError(''); setContext(null); setConfirmed(false);
    try { setContext(await api.get('/api/quanta.php?action=contract_context')); }
    catch (e) { setError(e.message || 'Could not verify the Quanta workspace'); }
    finally { setBusy(false); }
  };
  const confirm = async () => {
    setBusy(true); setError('');
    try {
      await api.post('/api/quanta.php?action=confirm_workspace', {
        remote_workspace_id: context.workspace.id, review_hash: context.review_hash,
        confirm_same_organization: confirmed,
      });
      setContext(current => ({ ...current, verified: true }));
    } catch (e) { setContext(null); setConfirmed(false); setError(e.message || 'Workspace verification failed'); }
    finally { setBusy(false); }
  };
  return <section style={{ marginTop: 24, paddingTop: 20, borderTop: '1px solid var(--cf-border)' }}>
    <h2 style={{ fontSize: 18, margin: '0 0 8px' }}>Verify the connected organization</h2>
    <p style={{ fontSize: 13 }}>Before importing time or publishing people and assignments, confirm which Quanta workspace this connection reaches.</p>
    <button className="btn btn-secondary btn-sm" disabled={busy} onClick={check}>{busy ? 'Checking…' : 'Check Quanta workspace'}</button>
    {error && <p role="alert" style={{ color: '#b91c1c', marginTop: 12 }}>{error}</p>}
    {context && <div style={{ marginTop: 12, fontSize: 13 }}>
      <p>Core: <strong>{workspace?.name || 'Current workspace'}</strong></p>
      <p>Quanta: <strong>{context.workspace.name}</strong> · Workspace ID: {context.workspace.id}</p>
      {!context.identity_ready && <p role="status">An administrator must finish this Core installation’s identity setup before importing or publishing.</p>}
      {context.verified ? <p role="status">Organization verified. Each import and delivery will recheck this connection.</p> : context.identity_ready && <>
        <label style={{ display: 'block', marginBottom: 12 }}><input type="checkbox" checked={confirmed} onChange={e => setConfirmed(e.target.checked)} /> I confirm these workspaces represent the same organization.</label>
        <button className="btn btn-primary btn-sm" disabled={busy || !confirmed} onClick={confirm}>Confirm organization</button>
      </>}
    </div>}
  </section>;
}

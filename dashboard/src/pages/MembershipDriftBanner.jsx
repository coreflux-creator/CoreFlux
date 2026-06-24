import React, { useState, useEffect, useCallback } from 'react';
import { AlertTriangle, CheckCircle2, RefreshCw, Wrench, ChevronDown, ChevronUp } from 'lucide-react';
import { api } from '../lib/api';
import { Card } from '../components/UIComponents';

/**
 * MembershipDriftBanner — surfaces the user_tenants → tenant_memberships
 * backfill drift on /admin/users.
 *
 * Hidden when there's no drift (clean slate). When drift exists, shows a
 * one-click "Heal all" + an expandable list of the drifting accounts with
 * per-row heal buttons. Master-admin only.
 *
 * Mounts mid-page on UsersAdmin so masters notice it without it dominating
 * the layout. Re-polls automatically after every heal action.
 */
export default function MembershipDriftBanner({ onHealed }) {
  const [data, setData]       = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState(null);
  const [busy, setBusy]       = useState(false);
  const [open, setOpen]       = useState(false);
  const [healErrors, setHealErrors] = useState([]);

  const reload = useCallback(async () => {
    setLoading(true); setError(null);
    try {
      const res = await api.get('/api/admin/membership_drift.php');
      setData(res);
    } catch (e) {
      setError(e?.message || String(e));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { reload(); }, [reload]);

  if (loading && !data)              return null;          // first paint silent
  if (error)                         return null;          // non-master sees nothing
  const drifting = data?.summary?.drifting_users ?? 0;
  if (!drifting)                     return (
    // Show a tiny "all clean" pill once data has loaded clean — useful
    // confirmation after a successful heal_all run.
    <div data-testid="drift-banner-clean"
         style={{ display: 'flex', alignItems: 'center', gap: 8,
                  fontSize: 13, color: 'var(--cf-success, #15803d)',
                  marginBottom: 'var(--cf-space-4)' }}>
      <CheckCircle2 size={16} />
      <span>Membership backfill is clean — every active user is in
            <code style={{ marginLeft: 4 }}>tenant_memberships</code>.</span>
    </div>
  );

  const healOne = async (userId) => {
    setBusy(true);
    setHealErrors([]);
    try {
      const res = await api.post(`/api/admin/membership_drift.php?action=heal&user_id=${userId}`, {});
      if (res?.errors?.length) setHealErrors(res.errors);
      await reload();
      onHealed?.();
    } catch (e) {
      alert(e?.message || 'Heal failed');
    } finally {
      setBusy(false);
    }
  };

  const healAll = async () => {
    if (!confirm(`Heal all ${drifting} drifting account(s)? This dual-writes legacy rows into tenant_memberships and is safe to re-run.`)) return;
    setBusy(true);
    setHealErrors([]);
    try {
      // The endpoint caps each batch at 250 users; loop until clean
      // or until the cap stops shrinking the count (defensive).
      let prev = -1, current = drifting, hops = 0;
      const allErrors = [];
      while (current > 0 && current !== prev && hops < 20) {
        prev = current;
        // eslint-disable-next-line no-await-in-loop
        const res = await api.post('/api/admin/membership_drift.php?action=heal_all', {});
        if (res?.errors?.length) allErrors.push(...res.errors);
        // eslint-disable-next-line no-await-in-loop
        const fresh = await api.get('/api/admin/membership_drift.php');
        current = fresh?.summary?.drifting_users ?? 0;
        setData(fresh);
        hops += 1;
      }
      if (allErrors.length) setHealErrors(allErrors.slice(0, 20));  // cap UI list
      onHealed?.();
    } catch (e) {
      alert(e?.message || 'Heal-all failed');
    } finally {
      setBusy(false);
    }
  };

  const summary = data.summary || {};
  const rows    = data.drifting || [];

  return (
    <Card data-testid="drift-banner"
          style={{ borderLeft: '4px solid #f59e0b', marginBottom: 'var(--cf-space-4)' }}>
      <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12 }}>
        <AlertTriangle size={20} color="#f59e0b" style={{ flexShrink: 0, marginTop: 2 }} />
        <div style={{ flex: 1 }}>
          <div style={{ fontWeight: 600, fontSize: 15, marginBottom: 4 }}>
            {drifting} account{drifting === 1 ? '' : 's'} still on the legacy membership table
          </div>
          <div style={{ fontSize: 13, color: 'var(--cf-text-secondary)', marginBottom: 8 }}>
            These users have rows in <code>user_tenants</code> that haven't been
            mirrored into <code>tenant_memberships</code> yet. They still log in
            (the read-shim covers them), but the new RBAC resolver only sees
            them after a heal. Each user heals automatically on their next
            login — or you can heal them in bulk now.
          </div>

          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 16, fontSize: 12,
                        color: 'var(--cf-text-secondary)', marginBottom: 12 }}>
            <span>Total active users: <strong>{summary.total_active_users ?? 0}</strong></span>
            <span>In <code>tenant_memberships</code>: <strong>{summary.in_new_table ?? 0}</strong></span>
            <span>Drifting: <strong style={{ color: '#b45309' }}>{drifting}</strong></span>
          </div>

          <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
            <button className="btn btn--primary"
                    onClick={healAll}
                    disabled={busy}
                    data-testid="drift-heal-all-btn">
              <Wrench size={14} /> {busy ? 'Healing…' : `Heal all ${drifting}`}
            </button>
            <button className="btn btn--ghost"
                    onClick={reload}
                    disabled={busy}
                    data-testid="drift-refresh-btn"
                    title="Re-check drift">
              <RefreshCw size={14} />
            </button>
            <button className="btn btn--ghost"
                    onClick={() => setOpen(o => !o)}
                    data-testid="drift-toggle-detail"
                    style={{ marginLeft: 'auto' }}>
              {open ? <ChevronUp size={14} /> : <ChevronDown size={14} />}
              {open ? ' Hide list' : ` Show list (${rows.length}${rows.length < drifting ? ` of ${drifting}` : ''})`}
            </button>
          </div>

          {healErrors.length > 0 && (
            <div
              data-testid="drift-heal-errors"
              style={{
                marginTop: 10,
                padding: 10,
                background: '#fef2f2',
                border: '1px solid #fecaca',
                borderRadius: 6,
                fontSize: 12,
                color: '#7f1d1d',
              }}
            >
              <strong>Heal blocked — surface the real error so we can fix it:</strong>
              <ul style={{ margin: '4px 0 0 16px', maxHeight: 200, overflowY: 'auto' }}>
                {healErrors.map((e, i) => (
                  <li key={i} style={{ fontFamily: 'monospace' }} data-testid={`drift-heal-error-${i}`}>{e}</li>
                ))}
              </ul>
            </div>
          )}

          {open && (
            <table className="data-table" style={{ marginTop: 12 }} data-testid="drift-table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Email</th>
                  <th>Tenants (legacy / unhealed)</th>
                  <th>Last active</th>
                  <th style={{ textAlign: 'right' }}>Action</th>
                </tr>
              </thead>
              <tbody>
                {rows.map(u => (
                  <tr key={u.id} data-testid={`drift-row-${u.id}`}>
                    <td style={{ fontWeight: 500 }}>{u.name}</td>
                    <td style={{ color: 'var(--cf-text-secondary)' }}>{u.email}</td>
                    <td title={u.tenant_names || ''}>
                      {u.legacy_tenants}
                      {u.unhealed_tenants !== u.legacy_tenants
                        ? <span style={{ color: '#b45309' }}> / {u.unhealed_tenants} unhealed</span>
                        : <span style={{ color: '#b45309' }}> / {u.unhealed_tenants}</span>}
                    </td>
                    <td style={{ color: 'var(--cf-text-secondary)', fontSize: 12 }}>
                      {u.last_active_at || '—'}
                    </td>
                    <td style={{ textAlign: 'right' }}>
                      <button className="btn btn--ghost"
                              onClick={() => healOne(u.id)}
                              disabled={busy}
                              data-testid={`drift-heal-${u.id}`}
                              title="Heal this user only">
                        <Wrench size={12} /> Heal
                      </button>
                    </td>
                  </tr>
                ))}
                {rows.length === 0 && (
                  <tr><td colSpan={5} style={{ textAlign: 'center', padding: 16,
                                               color: 'var(--cf-text-secondary)' }}>
                    No detail rows returned.
                  </td></tr>
                )}
              </tbody>
            </table>
          )}
        </div>
      </div>
    </Card>
  );
}

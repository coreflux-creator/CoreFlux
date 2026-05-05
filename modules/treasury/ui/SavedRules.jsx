import React, { useState } from 'react';
import { useApi } from '../../../dashboard/src/lib/api';
import { fmtRelative } from '../../../dashboard/src/lib/format';

/**
 * SavedRules — surfaces every (merchant → account) mapping the system has
 * learned from the user's accept / reject behaviour on AI categorization
 * suggestions. Each row shows accept count, reject count, the resulting
 * effective score, and lets the user mute (disabled_at) or forget (DELETE)
 * a rule entirely.
 *
 *   Auto-apply eligible : net score ≥ 3, not disabled
 *   Weak                : net score 1-2, surfaces as a soft suggestion only
 *   Contested           : reject_count > 0 — partial overrides have happened
 *   Disabled            : muted by the user; never auto-applies
 *
 * The header summary lets the user see at a glance "20 rules learned, 6
 * are auto-applying" so they understand why categorization is getting
 * faster the more they use the system.
 */
export default function SavedRules() {
  const { data, loading, reload } = useApi('/api/ai_categorization_rules.php');
  const rows = data?.rows || [];
  const [busyId, setBusyId] = useState(null);
  const [err, setErr]       = useState(null);

  const toggleDisable = async (row) => {
    setBusyId(row.id); setErr(null);
    try {
      const reason = !row.is_disabled
        ? prompt(`Why are you muting "${row.display_label}"? (optional)`) || ''
        : '';
      const res = await fetch(`/api/ai_categorization_rules.php?id=${row.id}`, {
        method: 'PATCH', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          disabled: !row.is_disabled,
          disabled_reason: reason || null,
        }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Update failed');
      reload();
    } catch (e) { setErr(e.message); } finally { setBusyId(null); }
  };

  const forget = async (row) => {
    if (!confirm(`Forget "${row.display_label}" entirely? Future imports won't get any suggestion for this merchant until you categorize it manually a few times.`)) return;
    setBusyId(row.id); setErr(null);
    try {
      const res = await fetch(`/api/ai_categorization_rules.php?id=${row.id}`, {
        method: 'DELETE', credentials: 'include',
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Delete failed');
      reload();
    } catch (e) { setErr(e.message); } finally { setBusyId(null); }
  };

  const counts = {
    total:    data?.count || 0,
    auto:     data?.auto_apply_count || 0,
    disabled: data?.disabled_count || 0,
  };

  return (
    <section data-testid="treasury-saved-rules">
      <header style={{ marginBottom: 16 }}>
        <h2 style={{ marginBottom: 4 }}>Saved categorization rules</h2>
        <p className="muted" style={{ fontSize: 13 }}>
          Every time you categorize a transaction, CoreFlux remembers the
          merchant → account mapping. After 3 accepts the rule becomes
          auto-applying — future syncs land that merchant in the same
          account without prompting. Mute or forget anything you don't want
          to influence future suggestions.
        </p>
        <div style={{ display: 'flex', gap: 16, marginTop: 12, fontSize: 13 }}>
          <span data-testid="saved-rules-count-total">
            <strong>{counts.total}</strong> learned
          </span>
          <span style={{ color: '#065f46' }} data-testid="saved-rules-count-auto">
            <strong>{counts.auto}</strong> auto-applying
          </span>
          <span style={{ color: '#6b7280' }} data-testid="saved-rules-count-disabled">
            <strong>{counts.disabled}</strong> muted
          </span>
        </div>
      </header>

      {loading && <p>Loading…</p>}
      {err && <p className="error" data-testid="saved-rules-error">{err}</p>}
      {!loading && rows.length === 0 && (
        <div
          data-testid="saved-rules-empty"
          style={{
            padding: 24, background: 'var(--cf-surface)',
            border: '1px dashed var(--cf-border)', borderRadius: 6,
            textAlign: 'center', color: 'var(--cf-text-muted, #6b7280)',
          }}
        >
          <p style={{ margin: '0 0 8px', fontSize: 14 }}>No rules learned yet.</p>
          <p style={{ margin: 0, fontSize: 12 }}>
            Categorize a few transactions on a deposit or liability account
            and they'll appear here automatically.
          </p>
        </div>
      )}

      {rows.length > 0 && (
        <table className="data-table" data-testid="saved-rules-table">
          <thead>
            <tr>
              <th>Pattern</th>
              <th>Signal</th>
              <th>→ Account</th>
              <th style={{ textAlign: 'right' }}>Accepts</th>
              <th style={{ textAlign: 'right' }}>Rejects</th>
              <th>Status</th>
              <th>Last activity</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.id} data-testid={`saved-rule-row-${r.id}`}>
                <td>
                  <strong>{r.display_label}</strong>
                </td>
                <td className="muted" style={{ fontSize: 12 }}>{r.signal_kind}</td>
                <td>
                  {r.account_code ? (
                    <span>
                      <code>{r.account_code}</code> {r.account_name}
                      {r.account_type && (
                        <span className="muted" style={{ fontSize: 11, marginLeft: 4 }}>
                          ({r.account_type})
                        </span>
                      )}
                    </span>
                  ) : (
                    <span className="muted">— (account deleted)</span>
                  )}
                </td>
                <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
                  {r.accept_count}
                </td>
                <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums', color: r.reject_count > 0 ? '#b91c1c' : undefined }}>
                  {r.reject_count}
                </td>
                <td>
                  {r.is_disabled && (
                    <span className="badge" data-testid={`saved-rule-status-disabled-${r.id}`}>
                      muted
                    </span>
                  )}
                  {!r.is_disabled && r.auto_apply_eligible && (
                    <span className="badge badge--active" data-testid={`saved-rule-status-auto-${r.id}`}>
                      auto-apply
                    </span>
                  )}
                  {!r.is_disabled && !r.auto_apply_eligible && r.weak && (
                    <span className="badge" style={{ background: '#fef3c7', color: '#78350f' }}>
                      weak
                    </span>
                  )}
                  {r.contested && !r.is_disabled && (
                    <span className="badge" style={{ background: '#fee2e2', color: '#991b1b', marginLeft: 4 }}>
                      contested
                    </span>
                  )}
                </td>
                <td className="muted" style={{ fontSize: 12, whiteSpace: 'nowrap' }}>
                  {r.last_accepted_at ? fmtRelative(r.last_accepted_at) : '—'}
                </td>
                <td style={{ whiteSpace: 'nowrap', textAlign: 'right' }}>
                  <button
                    type="button"
                    onClick={() => toggleDisable(r)}
                    disabled={busyId === r.id}
                    className="btn btn--ghost"
                    data-testid={`saved-rule-toggle-${r.id}`}
                    style={{ padding: '4px 10px', fontSize: 12, marginRight: 6 }}
                  >
                    {busyId === r.id ? '…' : (r.is_disabled ? 'Unmute' : 'Mute')}
                  </button>
                  <button
                    type="button"
                    onClick={() => forget(r)}
                    disabled={busyId === r.id}
                    className="btn btn--ghost"
                    data-testid={`saved-rule-forget-${r.id}`}
                    style={{ padding: '4px 10px', fontSize: 12, color: '#b91c1c' }}
                    title="Forget this rule entirely (deletes the history row)"
                  >
                    Forget
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}

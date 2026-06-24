import React, { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useApi, api } from '../../../dashboard/src/lib/api';
import IdBadge from '../../../dashboard/src/components/IdBadge';

/**
 * Draft Rates Queue — single screen showing every unapproved
 * placement_rates row across the tenant. Built so a finance operator
 * who just bulk-imported placements can review all the resulting
 * draft rates and approve them in one pass, instead of opening each
 * placement → Rates tab.
 *
 * Approve semantics are IDENTICAL to single-row approve because the
 * bulk API routes every row through the Placement Rate WorkflowGraph
 * bridge before the snapshot can be locked.
 *
 * Corrections (is_correction=true) are intentionally NOT supported
 * here — they require an operator note per row and the per-row
 * Approve modal is the right surface for that. Bulk path is for
 * fresh drafts only.
 */
export default function DraftRatesQueue() {
  const { data, loading, error, reload } = useApi('/modules/placements/api/rates.php?action=drafts');
  const rates = data?.rates ?? [];
  const total = data?.count ?? 0;

  const [selected, setSelected] = useState(() => new Set());
  const [busy, setBusy]         = useState(false);
  const [result, setResult]     = useState(null);

  useEffect(() => { setSelected(new Set()); }, [total]);

  const toggle = (id) => {
    setSelected(prev => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id); else next.add(id);
      return next;
    });
  };
  const allOn = rates.length > 0 && rates.every(r => selected.has(r.id));
  const toggleAll = () => {
    setSelected(prev => {
      const next = new Set(prev);
      if (allOn) rates.forEach(r => next.delete(r.id));
      else       rates.forEach(r => next.add(r.id));
      return next;
    });
  };

  const fmtMoney = (v, cur) => {
    if (v == null) return '—';
    const n = Number(v);
    if (Number.isNaN(n)) return String(v);
    return `${(cur || 'USD')} ${n.toFixed(2)}`;
  };

  const approveSelected = async () => {
    if (selected.size === 0) return;
    if (!confirm(`Route ${selected.size} draft rate${selected.size === 1 ? '' : 's'} for approval? Completed workflow approvals lock the snapshot; corrections require the per-row workflow.`)) return;
    setBusy(true); setResult(null);
    try {
      const res = await api.post(
        '/modules/placements/api/rates.php?action=bulk_approve',
        { ids: Array.from(selected) }
      );
      setResult(res);
      setSelected(new Set());
      reload();
    } catch (e) {
      setResult({ error: e?.message || String(e) });
    } finally {
      setBusy(false);
    }
  };

  return (
    <section className="people-directory" data-testid="placements-draft-rates">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-4)' }}>
        <div>
          <h2>Draft rates queue</h2>
          <p style={{ color: 'var(--cf-text-secondary)' }} data-testid="placements-draft-rates-count">
            {!data ? 'Loading…' : <>{total} unapproved rate{total === 1 ? '' : 's'} across all placements</>}
          </p>
        </div>
        <div style={{ display: 'flex', gap: 'var(--cf-space-2)' }}>
          <Link to="../list" className="btn btn--ghost" data-testid="placements-draft-rates-back">← Placements</Link>
          <button
            className="btn btn--primary"
            disabled={selected.size === 0 || busy}
            onClick={approveSelected}
            data-testid="placements-draft-rates-approve-btn"
          >
            {busy ? 'Approving…' : `Approve ${selected.size || ''} selected`}
          </button>
        </div>
      </header>

      {result && (
        <div
          data-testid="placements-draft-rates-result"
          style={{
            padding: 'var(--cf-space-2) var(--cf-space-3)',
            marginBottom: 'var(--cf-space-3)',
            background: result.error || result.failed ? '#fef9c3' : '#dcfce7',
            border: `1px solid ${result.error ? '#fca5a5' : (result.failed ? '#facc15' : '#86efac')}`,
            borderRadius: 6, fontSize: 13,
          }}
        >
          {result.error
            ? <>Bulk approve failed: {result.error}</>
            : <>
                Approved <strong>{result.approved}</strong>
                {result.pending ? <>, {result.pending} pending workflow</> : null}
                {result.failed ? <>, {result.failed} failed (open each placement to see why)</> : null}.
              </>
          }
        </div>
      )}

      {loading && <p>Loading…</p>}
      {error && <p className="error" data-testid="placements-draft-rates-error">Error: {error.message}</p>}

      {rates.length === 0 && !loading && (
        <div className="empty" data-testid="placements-draft-rates-empty" style={{ padding: 'var(--cf-space-4)', color: 'var(--cf-text-secondary)' }}>
          No draft rates pending approval. Imported placements with rates will appear here.
        </div>
      )}

      {rates.length > 0 && (
        <table className="data-table" data-testid="placements-draft-rates-table">
          <thead>
            <tr>
              <th style={{ width: 32 }}>
                <input
                  type="checkbox"
                  checked={allOn}
                  onChange={toggleAll}
                  data-testid="placements-draft-rates-select-all"
                  aria-label="Select all draft rates"
                />
              </th>
              <th>Placement</th>
              <th>Person</th>
              <th>End client</th>
              <th>Effective</th>
              <th>Bill</th>
              <th>Pay</th>
              <th>Created</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {rates.map(r => (
              <tr key={r.id} data-testid={`draft-rate-row-${r.id}`}>
                <td>
                  <input
                    type="checkbox"
                    checked={selected.has(r.id)}
                    onChange={() => toggle(r.id)}
                    data-testid={`draft-rate-select-${r.id}`}
                    aria-label={`Select draft rate ${r.id}`}
                  />
                </td>
                <td>
                  <IdBadge id={r.placement_id} prefix="PL" />{' '}
                  <Link to={`../${r.placement_id}`}>{r.placement_title || '—'}</Link>
                  {r.placement_status && r.placement_status !== 'active' ? (
                    <> <span className={`badge badge--${r.placement_status}`} style={{ fontSize: 10 }}>{r.placement_status}</span></>
                  ) : null}
                </td>
                <td>
                  {r.first_name ? `${r.first_name} ${r.last_name || ''}` : '—'}
                  {r.person_id ? <> <IdBadge id={r.person_id} prefix="P" /></> : null}
                </td>
                <td>{r.end_client_name || '—'}</td>
                <td>{r.effective_from || '—'}</td>
                <td>{fmtMoney(r.bill_rate, r.currency)} / {r.bill_rate_unit || 'hour'}</td>
                <td>{fmtMoney(r.pay_rate, r.currency)} / {r.pay_rate_unit || 'hour'}</td>
                <td style={{ fontSize: 12, color: '#64748b' }}>{r.created_at ? String(r.created_at).slice(0, 10) : '—'}</td>
                <td>
                  <Link to={`../${r.placement_id}/rates`} className="btn btn--ghost" style={{ fontSize: 12 }} data-testid={`draft-rate-open-${r.id}`}>
                    Review →
                  </Link>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}

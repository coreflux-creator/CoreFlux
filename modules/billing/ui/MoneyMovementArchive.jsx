import React, { useEffect, useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';

/**
 * Money Movement archive — last 12 weekly snapshots.
 *
 * Each card opens an inline read-only view of that historical snapshot.
 * The source is `tenant_money_movement_snapshots` rows written by the
 * Monday cron + on-demand sends.
 */
export default function MoneyMovementArchive() {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [detail, setDetail] = useState(null);  // {snapshot, wow, email}
  const [detailLoading, setDetailLoading] = useState(false);

  useEffect(() => {
    api.get('/modules/billing/api/money_movement_archive.php')
      .then((d) => setRows(d.rows || []))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, []);

  const openWeek = async (asOf) => {
    setDetailLoading(true);
    try {
      const d = await api.get(`/modules/billing/api/money_movement_archive.php?as_of=${encodeURIComponent(asOf)}`);
      setDetail({ ...d, as_of: asOf });
    } catch (e) { setError(e.message); }
    finally { setDetailLoading(false); }
  };

  if (loading) return <p>Loading…</p>;

  return (
    <section data-testid="money-movement-archive" style={{ maxWidth: 920, margin: '0 auto' }}>
      <header style={{ marginBottom: 18 }}>
        <h2 style={{ margin: 0 }}>Digest archive</h2>
        <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--cf-text-secondary)' }}>
          Last 12 weeks of Money Movement snapshots. Click any week to view the rendered digest.
        </p>
      </header>

      {error && <p className="error" data-testid="money-movement-archive-error">Error: {error}</p>}

      {rows.length === 0 ? (
        <p data-testid="money-movement-archive-empty" style={{ color: 'var(--cf-text-secondary)' }}>
          No snapshots yet. They land here automatically after the Monday cron runs.
        </p>
      ) : (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 12 }}>
          {rows.map((r) => {
            const net = Number(r.net_movement);
            return (
              <button
                key={r.as_of} type="button"
                onClick={() => openWeek(r.as_of)}
                disabled={detailLoading}
                data-testid={`money-movement-archive-card-${r.as_of}`}
                style={{
                  textAlign: 'left', background: 'var(--cf-surface, #fff)',
                  border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8,
                  padding: 14, cursor: 'pointer',
                }}
              >
                <div style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>
                  Week of {r.window_start} → {r.window_end}
                </div>
                <div style={{ fontSize: 20, fontWeight: 700, marginTop: 4, color: net >= 0 ? '#16a34a' : '#dc2626' }}>
                  {net >= 0 ? '+' : '−'}${Math.abs(net).toLocaleString(undefined, { maximumFractionDigits: 0 })}
                </div>
                <div style={{ fontSize: 12, marginTop: 4, color: 'var(--cf-text-secondary)' }}>
                  In ${Number(r.cash_in).toLocaleString()} · Out ${Number(r.cash_out).toLocaleString()}
                </div>
              </button>
            );
          })}
        </div>
      )}

      {detail && (
        <div
          style={{ position: 'fixed', inset: 0, background: 'rgba(15,18,28,0.55)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }}
          data-testid="money-movement-archive-modal"
          onClick={(e) => e.target === e.currentTarget && setDetail(null)}
        >
          <div style={{ background: 'var(--cf-surface, #fff)', borderRadius: 12, width: 'min(720px, 100%)', maxHeight: '90vh', overflow: 'auto', padding: 18 }}>
            <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
              <h3 style={{ margin: 0 }}>Snapshot for {detail.as_of}</h3>
              <button className="btn btn--ghost" onClick={() => setDetail(null)}>×</button>
            </header>
            <div
              data-testid="money-movement-archive-html"
              style={{ border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8, padding: 4 }}
              dangerouslySetInnerHTML={{ __html: detail.email?.html || '' }}
            />
          </div>
        </div>
      )}
    </section>
  );
}

import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { useApi, api } from '../../../dashboard/src/lib/api';

/**
 * Tenant-wide unacknowledged anomaly feed. Linked from the dashboard alert
 * badge in PayrollOverview.
 */
export default function PayrollAnomalies() {
  const { data, loading, reload } = useApi('/modules/payroll/api/anomalies.php?dashboard=1&limit=200');
  const items = data?.unacknowledged ?? [];
  const [busy, setBusy] = useState(null);

  const ack = async (id) => {
    setBusy(id);
    try { await api.put(`/modules/payroll/api/anomalies.php?id=${id}`, {}); await reload(); }
    finally { setBusy(null); }
  };

  return (
    <section className="payroll-anomalies-page" data-testid="payroll-anomalies-page">
      <header className="payroll-overview__header">
        <div>
          <h2>AI Anomaly Findings</h2>
          <p>
            Hours drift, missing time, and rate changes detected across all recent runs.
            Findings are deterministic — AI only summarizes them.
          </p>
        </div>
      </header>

      {loading && <p>Loading…</p>}
      {!loading && items.length === 0 && (
        <p className="empty-state" data-testid="payroll-anomalies-empty">
          No unacknowledged anomalies. Nice work.
        </p>
      )}

      {items.length > 0 && (
        <table className="data-table" data-testid="payroll-anomalies-table">
          <thead>
            <tr>
              <th>Severity</th><th>Code</th><th>Pay date</th><th>Employee</th>
              <th>Message</th><th></th>
            </tr>
          </thead>
          <tbody>
            {items.map((a) => (
              <tr key={a.id} data-testid={`payroll-anomalies-row-${a.id}`}>
                <td><span className={`badge badge--${a.severity}`}>{a.severity}</span></td>
                <td>{a.code}</td>
                <td>{a.pay_date ?? '—'}</td>
                <td>
                  {a.legal_first_name} {a.legal_last_name}
                  {a.employee_number && <span className="muted"> (#{a.employee_number})</span>}
                </td>
                <td>{a.message}</td>
                <td>
                  {a.run_id && (
                    <Link
                      to={`../runs/${a.run_id}`}
                      className="btn btn--ghost btn--sm"
                      data-testid={`payroll-anomalies-open-run-${a.id}`}
                    >
                      Open run
                    </Link>
                  )}
                  <button
                    className="btn btn--ghost btn--sm"
                    onClick={() => ack(a.id)}
                    disabled={busy === a.id}
                    data-testid={`payroll-anomalies-ack-${a.id}`}
                    style={{ marginLeft: 8 }}
                  >
                    {busy === a.id ? 'Acking…' : 'Acknowledge'}
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

import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';

/**
 * Companies — Merge duplicates admin tool.
 *
 * Backend returns groups of companies whose names normalise to the same
 * stem (inc/llc/corp/punctuation stripped). Admin picks a survivor and
 * merges the rest into it. The backend redirects every FK across AP,
 * Billing, placements to the survivor, unions roles, reparents contacts
 * and addresses, then soft-deletes the losers.
 */
export default function CompaniesMerge() {
  const path = '/modules/people/api/companies.php?action=duplicates';
  const { data, loading, error, reload } = useApi(path);
  const groups = data?.groups ?? [];
  const [busyKey, setBusyKey] = useState(null);
  const [notice, setNotice]   = useState(null);

  const merge = async (survivorId, victimId) => {
    const ok = confirm(`Merge company #${victimId} into #${survivorId}? This redirects all AP/Billing/placement records and is logged to audit.`);
    if (!ok) return;
    setBusyKey(`${survivorId}:${victimId}`); setNotice(null);
    try {
      const res = await api.post(`/modules/people/api/companies.php?action=merge&id=${survivorId}`, { victim_id: victimId });
      const total = Object.values(res.redirected || {}).reduce((s, n) => s + (Number(n) || 0), 0);
      setNotice({ type: 'ok', text: `Merged. ${total} row${total === 1 ? '' : 's'} redirected to survivor #${survivorId}.`, detail: res.redirected });
      reload();
    } catch (e) {
      setNotice({ type: 'err', text: e.message });
    } finally {
      setBusyKey(null);
    }
  };

  return (
    <section className="companies-merge" data-testid="companies-merge-page">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '1rem', gap: 16, flexWrap: 'wrap' }}>
        <div>
          <h2 style={{ margin: 0 }}>Merge duplicate companies</h2>
          <p style={{ margin: '4px 0 0', color: '#666', fontSize: 13 }}>
            Candidates grouped by normalised name (inc/llc/corp/punctuation stripped). Pick a survivor and merge the rest.
          </p>
        </div>
        <Link to="../directory" className="btn btn--ghost" data-testid="companies-merge-back">← Back to directory</Link>
      </header>

      {notice && (
        <p
          data-testid="companies-merge-notice"
          className={notice.type === 'ok' ? 'notice notice--ok' : 'error'}
          style={{ padding: '8px 12px', borderRadius: 6, background: notice.type === 'ok' ? '#ecfdf5' : '#fef2f2', color: notice.type === 'ok' ? '#065f46' : '#991b1b' }}
        >
          {notice.text}
        </p>
      )}

      {loading && <p data-testid="companies-merge-loading">Loading…</p>}
      {error && <p className="error" data-testid="companies-merge-error">Error: {error.message}</p>}

      {!loading && groups.length === 0 && (
        <p data-testid="companies-merge-empty" style={{ color: '#555' }}>
          No duplicate candidates detected. Every company name in this tenant is unique after normalisation.
        </p>
      )}

      {groups.map((g, gi) => (
        <MergeGroup key={g.normalized} group={g} gi={gi} onMerge={merge} busyKey={busyKey} />
      ))}
    </section>
  );
}

function MergeGroup({ group, gi, onMerge, busyKey }) {
  // Pre-select the company with the highest use_count as the proposed survivor.
  const proposed = [...group.companies].sort((a, b) => (b.use_count || 0) - (a.use_count || 0))[0];
  const [survivorId, setSurvivorId] = useState(proposed.id);

  return (
    <div
      data-testid={`companies-merge-group-${gi}`}
      style={{ border: '1px solid #e5e7eb', borderRadius: 8, padding: 12, marginBottom: 12 }}
    >
      <p style={{ margin: '0 0 8px', color: '#111', fontSize: 13 }}>
        <strong>Normalised:</strong> <code data-testid={`companies-merge-normalized-${gi}`}>{group.normalized}</code>
        {' · '}
        <span style={{ color: '#666' }}>{group.companies.length} variants</span>
      </p>
      <table className="data-table" style={{ width: '100%', fontSize: 14 }}>
        <thead>
          <tr>
            <th style={{ width: 40 }}>Survivor</th>
            <th>Name</th>
            <th style={{ width: 80 }}>Roles</th>
            <th style={{ width: 80 }}>Uses</th>
            <th style={{ width: 160 }}>Last used</th>
            <th style={{ width: 150 }}></th>
          </tr>
        </thead>
        <tbody>
          {group.companies.map((c) => {
            const isSurvivor = c.id === survivorId;
            const key = `${survivorId}:${c.id}`;
            return (
              <tr key={c.id} data-testid={`companies-merge-row-${c.id}`}>
                <td>
                  <input
                    type="radio"
                    name={`survivor-${gi}`}
                    checked={isSurvivor}
                    onChange={() => setSurvivorId(c.id)}
                    data-testid={`companies-merge-pick-${c.id}`}
                  />
                </td>
                <td>
                  <strong>{c.name}</strong>{' '}
                  <span style={{ color: '#888', fontSize: 12 }}>#{c.id}</span>
                </td>
                <td>{c.role_count ?? 0}</td>
                <td>{c.use_count ?? 0}</td>
                <td style={{ color: '#666' }}>{c.last_used_at || '—'}</td>
                <td>
                  {!isSurvivor && (
                    <button
                      type="button"
                      className="btn btn--primary"
                      data-testid={`companies-merge-into-survivor-${c.id}`}
                      disabled={busyKey === key}
                      onClick={() => onMerge(survivorId, c.id)}
                    >
                      {busyKey === key ? 'Merging…' : `→ Merge into #${survivorId}`}
                    </button>
                  )}
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

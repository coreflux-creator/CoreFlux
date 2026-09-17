import React, { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../../../dashboard/src/lib/api';

const STAGES = ['sourced','screened','submitted','interview','offer','placed','bench','terminated','rejected'];
const STAGE_LABELS = {
  sourced: 'Sourced', screened: 'Screened', submitted: 'Submitted', interview: 'Interview',
  offer: 'Offer', placed: 'Placed', bench: 'Bench', terminated: 'Ended', rejected: 'Rejected',
};
const CLASSIFICATION_LABELS = {
  w2: 'W-2 employee', '1099': '1099 contractor', c2c: 'C2C contractor',
  temp: 'Temporary worker', perm: 'Permanent employee', candidate: 'Candidate', alumni: 'Former worker',
};
const STATUS_LABELS = { active: 'Active', bench: 'Bench', inactive: 'Inactive', do_not_rehire: 'Do not rehire' };

export default function Pipeline() {
  const summaryPath = '/modules/people/api/pipeline.php?summary=1';
  const { data: summaryData, loading: summaryLoading } = useApi(summaryPath);
  const summary = summaryData?.summary ?? {};

  const [stage, setStage] = useState('sourced');
  const listPath = useMemo(() => {
    const params = new URLSearchParams();
    params.set('pipeline_stage', stage);
    params.set('per_page', '50');
    return `/modules/people/api/people.php?${params.toString()}`;
  }, [stage]);

  const { data: listData, loading: listLoading, error } = useApi(listPath);
  const rows = listData?.rows ?? [];

  return (
    <section data-testid="pipeline-page">
      <h2>Hiring Pipeline</h2>

      <div className="pipeline-stages" data-testid="pipeline-summary" style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap', marginBottom: '1rem' }}>
        {STAGES.map(s => (
          <button
            key={s}
            onClick={() => setStage(s)}
            className={`btn ${s === stage ? 'btn--primary' : 'btn--ghost'}`}
            data-testid={`pipeline-stage-${s}`}
            style={{
              padding: '0.5rem 0.85rem',
              fontWeight: s === stage ? 600 : 400,
              borderRadius: '6px',
            }}
          >
            {STAGE_LABELS[s] || s} <span data-testid={`pipeline-count-${s}`} style={{ opacity: 0.7 }}>({summaryLoading ? '…' : (summary[s] ?? 0)})</span>
          </button>
        ))}
      </div>

      {listLoading && <p>Loading…</p>}
      {error && <p className="error" data-testid="pipeline-error">Error: {error.message}</p>}

      <table className="data-table" data-testid="pipeline-people-table" style={{ width: '100%' }}>
        <thead><tr><th>Name</th><th>Email</th><th>Classification</th><th>Status</th></tr></thead>
        <tbody>
          {rows.length === 0 && <tr><td colSpan={4} className="empty" data-testid="pipeline-empty">No people in {String(STAGE_LABELS[stage] || stage).toLowerCase()}.</td></tr>}
          {rows.map(p => (
            <tr key={p.id} data-testid={`pipeline-row-${p.id}`}>
              <td><Link to={`../${p.id}`}>{p.preferred_name || p.first_name} {p.last_name}</Link></td>
              <td>{p.email_primary}</td>
              <td>{CLASSIFICATION_LABELS[p.classification] || p.classification}</td>
              <td>{STATUS_LABELS[p.status] || p.status}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  );
}

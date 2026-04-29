import React from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../../../dashboard/src/lib/api';

/**
 * Simple indented-list org chart. A graphical tree renderer can replace this
 * later without changing the API.
 */
function Node({ node, depth = 0 }) {
  return (
    <li className="org-node" data-testid={`org-node-${node.id}`}>
      <div className="org-node__row" style={{ paddingLeft: depth * 20 }}>
        <Link to={`../${node.id}`}>
          {node.preferred_name || node.legal_first_name} {node.legal_last_name}
        </Link>
        <span className="muted"> · {node.job_title || '—'} · {node.department || '—'}</span>
      </div>
      {node.children?.length > 0 && (
        <ul className="org-children">
          {node.children.map((c) => <Node key={c.id} node={c} depth={depth + 1} />)}
        </ul>
      )}
    </li>
  );
}

export default function OrgChart() {
  const { data, loading, error } = useApi('/modules/people/api/org_chart.php');
  if (loading) return <p>Loading org chart…</p>;
  if (error)   return <p className="error">Error: {error.message}</p>;
  const forest = data?.forest ?? [];
  if (forest.length === 0) return <p className="empty">No active employees.</p>;
  return (
    <section className="org-chart" data-testid="people-org-chart">
      <h2>Org chart</h2>
      <ul className="org-root">
        {forest.map((root) => <Node key={root.id} node={root} depth={0} />)}
      </ul>
    </section>
  );
}

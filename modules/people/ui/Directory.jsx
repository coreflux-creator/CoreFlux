import React, { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../../../dashboard/src/lib/api';
import IdBadge from '../../../dashboard/src/components/IdBadge';
import ExportTemplatePicker from '../../../dashboard/src/components/ExportTemplatePicker';

const API = '/modules/people/api/people.php';

const CLASSIFICATIONS = ['', 'w2', '1099', 'c2c', 'temp', 'perm', 'candidate', 'alumni'];
const STATUSES        = ['', 'active', 'bench', 'inactive', 'do_not_rehire'];

export default function Directory() {
  const [q, setQ] = useState('');
  const [classification, setClassification] = useState('');
  const [status, setStatus] = useState('');
  const [needsReview, setNeedsReview] = useState(false);
  const [page, setPage] = useState(1);

  const path = useMemo(() => {
    const params = new URLSearchParams();
    if (q) params.set('q', q);
    if (classification) params.set('classification', classification);
    if (status) params.set('status', status);
    if (needsReview) {
      // Targets auto-imported placeholders from
      // jobdivaPlacementsAutoCreatePerson(): @no-email.invalid emails,
      // "JobDiva" firstname, "Candidate-…" lastname.
      params.set('source', 'jobdiva');
      params.set('needs_review', '1');
    }
    params.set('page', String(page));
    return `${API}?${params.toString()}`;
  }, [q, classification, status, needsReview, page]);

  const { data, loading, error, reload } = useApi(path);
  const rows  = data?.rows ?? [];
  const total = data?.total ?? 0;
  const perPage = data?.per_page ?? 25;
  const lastPage = Math.max(1, Math.ceil(total / perPage));
  const buildTemplateExportHref = (tplId) => {
    const params = new URLSearchParams({ template_id: String(tplId) });
    if (classification) params.set('classification', classification);
    if (status) params.set('status', status);
    return `/modules/people/api/csv_export.php?${params.toString()}`;
  };

  return (
    <section className="people-directory" data-testid="people-directory">
      <header className="people-directory__header" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1rem' }}>
        <div>
          <h2>People Directory</h2>
          <p className="people-directory__subtitle" data-testid="people-directory-count">
            {data ? `${total} total` : 'Loading…'}
          </p>
        </div>
        <div className="people-directory__actions" style={{ display: 'flex', gap: 'var(--cf-space-2)' }}>
          <Link to="../csv_import" className="btn" data-testid="people-csv-import-btn">
            Import CSV
          </Link>
          <a href="/modules/people/api/csv_export.php" className="btn" data-testid="people-csv-export-btn">
            Export CSV
          </a>
          <ExportTemplatePicker
            dataset="people_directory"
            buildHref={buildTemplateExportHref}
            label="Export via template"
            testid="people-export-template"
          />
          <Link to="../new" className="btn btn--primary" data-testid="people-add-btn">
            + Add person
          </Link>
        </div>
      </header>

      <div className="people-directory__filters" style={{ display: 'flex', gap: '0.75rem', marginBottom: '1rem', flexWrap: 'wrap' }}>
        <input
          type="search"
          placeholder="Search name, email, external id…"
          value={q}
          onChange={(e) => { setQ(e.target.value); setPage(1); }}
          data-testid="people-directory-search"
          className="input"
        />
        <select
          value={classification}
          onChange={(e) => { setClassification(e.target.value); setPage(1); }}
          data-testid="people-directory-classification-filter"
          className="input"
        >
          {CLASSIFICATIONS.map(c => <option key={c} value={c}>{c === '' ? 'All classifications' : c}</option>)}
        </select>
        <select
          value={status}
          onChange={(e) => { setStatus(e.target.value); setPage(1); }}
          data-testid="people-directory-status-filter"
          className="input"
        >
          {STATUSES.map(s => <option key={s} value={s}>{s === '' ? 'All statuses' : s}</option>)}
        </select>
        <label
          className="btn btn--ghost"
          data-testid="people-directory-needs-review-toggle"
          style={{
            display: 'inline-flex', alignItems: 'center', gap: '0.4rem',
            cursor: 'pointer',
            // Visually distinct when active so it's obvious the list is filtered.
            background: needsReview ? 'var(--cf-color-amber-100, #fef3c7)' : undefined,
            borderColor: needsReview ? 'var(--cf-color-amber-400, #fbbf24)' : undefined,
          }}
        >
          <input
            type="checkbox"
            checked={needsReview}
            onChange={(e) => { setNeedsReview(e.target.checked); setPage(1); }}
            data-testid="people-directory-needs-review-checkbox"
          />
          Imported from JobDiva — needs review
        </label>
        <button onClick={reload} className="btn btn--ghost" data-testid="people-directory-refresh">
          Refresh
        </button>
      </div>

      {loading && <p data-testid="people-directory-loading">Loading…</p>}
      {error && <p className="error" data-testid="people-directory-error">Error: {error.message}</p>}

      {!loading && !error && (
        <>
          <table className="data-table" data-testid="people-directory-table" style={{ width: '100%' }}>
            <thead>
              <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Email</th>
                <th>Classification</th>
                <th>Status</th>
                <th>Work auth</th>
                <th>Created</th>
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 && (
                <tr><td colSpan={7} className="empty" data-testid="people-directory-empty">No people match.</td></tr>
              )}
              {rows.map((p) => {
                // A row "needs review" iff it carries one of the synthetic
                // placeholders that jobdivaPlacementsAutoCreatePerson()
                // sets when JobDiva's payload was missing real data. Same
                // predicate as the SQL filter — duplicated here so the
                // badge shows even when the operator hasn't toggled the
                // filter (helpful for catching imports mixed into normal
                // search results).
                const needsReviewRow =
                  (p.email_primary && p.email_primary.endsWith('@no-email.invalid'))
                  || p.first_name === 'JobDiva'
                  || (p.last_name && p.last_name.startsWith('Candidate-'));
                return (
                <tr key={p.id} data-testid={`people-row-${p.id}`}>
                  <td><IdBadge id={p.id} prefix="P" /></td>
                  <td>
                    <Link to={`../${p.id}`} data-testid={`people-row-link-${p.id}`}>
                      {p.preferred_name || p.first_name} {p.last_name}
                    </Link>
                    {needsReviewRow && (
                      <span
                        data-testid={`people-row-needs-review-${p.id}`}
                        title="Auto-imported from JobDiva with placeholder fields — review and complete."
                        style={{
                          marginLeft: '0.5rem',
                          fontSize: '0.7rem',
                          padding: '0.1rem 0.4rem',
                          borderRadius: '999px',
                          background: 'var(--cf-color-amber-100, #fef3c7)',
                          color: 'var(--cf-color-amber-800, #92400e)',
                          border: '1px solid var(--cf-color-amber-300, #fcd34d)',
                          verticalAlign: 'middle',
                        }}
                      >
                        Needs review
                      </span>
                    )}
                  </td>
                  <td>{p.email_primary}</td>
                  <td><span className={`badge badge--${p.classification}`}>{p.classification}</span></td>
                  <td><span className={`badge badge--${p.status}`}>{p.status}</span></td>
                  <td>{p.work_auth_status || '—'}{p.work_auth_expiry ? ` (exp ${p.work_auth_expiry})` : ''}</td>
                  <td>{(p.created_at || '').slice(0, 10)}</td>
                </tr>
                );
              })}
            </tbody>
          </table>

          <div className="people-directory__pagination" style={{ marginTop: '1rem', display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
            <button
              disabled={page <= 1}
              onClick={() => setPage(p => Math.max(1, p - 1))}
              className="btn"
              data-testid="people-directory-prev-page"
            >Prev</button>
            <span data-testid="people-directory-page-indicator">Page {page} of {lastPage}</span>
            <button
              disabled={page >= lastPage}
              onClick={() => setPage(p => Math.min(lastPage, p + 1))}
              className="btn"
              data-testid="people-directory-next-page"
            >Next</button>
          </div>
        </>
      )}
    </section>
  );
}

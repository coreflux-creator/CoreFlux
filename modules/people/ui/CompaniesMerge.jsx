import React, { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';
import CompanyMergeReview from './CompanyMergeReview';

export default function CompaniesMerge() {
  const { data, loading, error, reload } = useApi('/modules/people/api/companies.php?action=duplicates');
  const [review, setReview] = useState(null), [busy, setBusy] = useState(false), [notice, setNotice] = useState(null), [reviewError, setReviewError] = useState('');
  const generation = useRef(0);
  useEffect(() => () => { generation.current++; }, []);
  const preview = async (survivor, retired) => {
    const request = ++generation.current; setBusy(true); setNotice(null); setReview(null); setReviewError('');
    try { const result = await api.post('/modules/people/api/companies.php?action=merge_preview', { survivor_id: Number(survivor), retired_id: Number(retired) }); if (request === generation.current) setReview(result.review); }
    catch (e) { if (request === generation.current) setNotice({ error: true, text: e.message }); }
    finally { if (request === generation.current) setBusy(false); }
  };
  const apply = async choices => {
    if (!review || busy) return;
    const request = generation.current; setBusy(true); setReviewError('');
    try {
      const result = await api.post('/modules/people/api/companies.php?action=merge', { review_token: review.review_token, choices, confirm_merge: true });
      if (request !== generation.current) return;
      setReview(null); setNotice({ error: false, text: result.replayed ? `This merge into ${result.company_number} was already applied. Later edits were preserved.` : `Merged into ${result.company_number}. ${result.retired_number} and all source IDs are preserved.` }); reload();
    } catch (e) { if (request === generation.current) setReviewError(e.message); }
    finally { if (request === generation.current) setBusy(false); }
  };
  const groups = data?.groups ?? [];
  return <section data-testid="companies-merge-page">
    <header style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 16 }}><div><h2>Merge duplicate companies</h2><p>Similar names are suggestions. Review the records and choose the company to keep before merging.</p></div><Link to="../directory" className="btn btn--ghost" data-testid="companies-merge-back">← Back to directory</Link></header>
    {notice && <p data-testid="companies-merge-notice" role={notice.error ? 'alert' : 'status'} className={notice.error ? 'error' : 'notice'}>{notice.text}</p>}
    {loading && <p data-testid="companies-merge-loading">Loading…</p>}{error && <p className="error" data-testid="companies-merge-error">{error.message}</p>}
    {!loading && data && !data.can_merge && <p>Company and integration management access is required to review and apply a merge.</p>}
    {review && <CompanyMergeReview key={review.review_token} review={review} busy={busy} error={reviewError} onApply={apply} onRefresh={() => preview(review.survivor.id, review.retired.id)} onCancel={() => { generation.current++; setReview(null); }} />}
    {!loading && !groups.length && <p data-testid="companies-merge-empty">No duplicate name candidates were found.</p>}
    {groups.map((group, index) => <MergeGroup key={group.normalized} group={group} index={index} disabled={busy || !data?.can_merge} onReview={preview} />)}
  </section>;
}

function MergeGroup({ group, index, disabled, onReview }) {
  const [survivor, setSurvivor] = useState(() => [...group.companies].sort((a, b) => (b.use_count || 0) - (a.use_count || 0))[0].id);
  return <div data-testid={`companies-merge-group-${index}`} style={{ border: '1px solid var(--cf-border, #ddd)', borderRadius: 8, padding: 12, marginBottom: 12 }}>
    <table className="data-table"><thead><tr><th>Keep</th><th>Company</th><th>Uses</th><th>Review</th></tr></thead><tbody>{group.companies.map(c => <tr key={c.id}>
      <td><input type="radio" aria-label={`Keep ${c.company_number}`} name={`company-survivor-${index}`} checked={c.id === survivor} disabled={disabled} onChange={() => setSurvivor(c.id)} data-testid={`companies-merge-pick-${c.id}`} /></td>
      <td>{c.name} · {c.company_number}</td><td>{c.use_count}</td><td>{c.id !== survivor && <button type="button" className="btn btn--ghost" disabled={disabled} data-testid={`companies-merge-into-survivor-${c.id}`} onClick={() => onReview(survivor, c.id)}>Review merge</button>}</td>
    </tr>)}</tbody></table>
  </div>;
}

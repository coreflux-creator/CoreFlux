import React, { useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { Check, Download, FileUp, Upload } from 'lucide-react';
import { api, useApi } from '../../../dashboard/src/lib/api';

const ENDPOINT = '/modules/accounting/api/opening_balances.php';

export default function OpeningBalances() {
  const { data: entities, loading: loadingEntities, error: entitiesError } = useApi('/modules/accounting/api/entities.php');
  const fileRef = useRef(null);
  const [entityId, setEntityId] = useState('');
  const [csv, setCsv] = useState('');
  const [fileName, setFileName] = useState('');
  const [preview, setPreview] = useState(null);
  const [confirmed, setConfirmed] = useState(false);
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');
  const [posted, setPosted] = useState(null);
  const activeEntities = (entities?.rows || []).filter(row => Number(row.active) === 1);

  const resetReview = () => {
    setPreview(null);
    setConfirmed(false);
    setPosted(null);
    setError('');
  };

  const chooseFile = async event => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    try {
      const contents = await file.text();
      setCsv(contents);
      setFileName(file.name);
      resetReview();
    } catch {
      setError('Could not read this file.');
    }
  };

  const review = async () => {
    setBusy('preview'); setError(''); setPosted(null); setConfirmed(false);
    try {
      setPreview(await api.post(`${ENDPOINT}?action=preview`, { entity_id: Number(entityId), csv }));
    } catch (err) {
      setPreview(null);
      setError(err.message);
    } finally {
      setBusy('');
    }
  };

  const commit = async () => {
    if (!preview?.preview_token || !confirmed || busy) return;
    setBusy('commit'); setError('');
    try {
      const result = await api.post(`${ENDPOINT}?action=commit`, {
        entity_id: Number(entityId), csv, preview_token: preview.preview_token,
      });
      setPosted(result);
    } catch (err) {
      setError(err.message);
      setPreview(null);
      setConfirmed(false);
    } finally {
      setBusy('');
    }
  };

  return (
    <section className="opening-balances" data-testid="accounting-opening-balances">
      <header className="opening-balances__header">
        <div>
          <h2>Opening balances</h2>
          <p>Bring a new legal entity&apos;s balance-sheet accounts into the books.</p>
        </div>
        <a className="btn btn--ghost" href={`${ENDPOINT}?action=template`} download="accounting-opening-balances.csv">
          <Download size={15} aria-hidden="true" /> Template
        </a>
      </header>

      <div className="opening-balances__inputs">
        <label>Legal entity
          <select value={entityId} onChange={event => { setEntityId(event.target.value); resetReview(); }}
            disabled={loadingEntities || busy !== ''} data-testid="opening-entity">
            <option value="">Select entity</option>
            {activeEntities.map(entity => (
              <option key={entity.id} value={entity.id}>{entity.code} · {entity.legal_name}</option>
            ))}
          </select>
        </label>
        <div>
          <span className="opening-balances__label">Balances CSV</span>
          <input ref={fileRef} type="file" accept=".csv,.tsv,text/csv,text/tab-separated-values"
            onChange={chooseFile} hidden data-testid="opening-file" />
          <button className="btn btn--ghost" type="button" onClick={() => fileRef.current?.click()} disabled={busy !== ''}>
            <FileUp size={15} aria-hidden="true" /> {fileName || 'Choose file'}
          </button>
        </div>
        <button className="btn btn--primary" type="button" onClick={review}
          disabled={!entityId || !csv.trim() || busy !== ''} data-testid="opening-preview">
          {busy === 'preview' ? 'Reviewing' : 'Preview balances'}
        </button>
      </div>
      <p className="opening-balances__note">Receivables, payables, payroll, and tax controls require their source records. Only unused entities can receive an opening journal.</p>
      {!loadingEntities && !entitiesError && activeEntities.length === 0 && (
        <p className="opening-balances__note">No legal entity is set up yet. <Link to="/modules/accounting/entities">Create one</Link> before importing balances.</p>
      )}
      {entitiesError && <p role="alert" className="error">{entitiesError.message}</p>}
      {error && <p role="alert" className="error" data-testid="opening-error">{error}</p>}

      {preview && (
        <div className="opening-balances__review" data-testid="opening-review">
          <div className="opening-balances__summary">
            <div><span>Entity</span><strong>{preview.entity_name}</strong></div>
            <div><span>Opening date</span><strong>{preview.posting_date}</strong></div>
            <div><span>First fiscal day</span><strong>{preview.first_fiscal_day}</strong></div>
          </div>
          {preview.error_count > 0 && (
            <div className="opening-balances__errors" role="alert" data-testid="opening-preview-errors">
              <strong>{preview.error_count} issue{preview.error_count === 1 ? '' : 's'} to resolve</strong>
              {Object.entries(preview.errors).map(([row, messages]) => (
                <p key={row}>{row === '0' ? 'File' : `Row ${row}`}: {messages.join(' ')}</p>
              ))}
            </div>
          )}
          <div className="opening-balances__table-wrap">
            <table className="data-table opening-balances__table">
              <thead><tr><th>Account</th><th>Balance</th><th>Debit</th><th>Credit</th></tr></thead>
              <tbody>
                {preview.rows.map(row => (
                  <tr key={row.account_code}>
                    <td><strong>{row.account_code}</strong> {row.account_name}</td>
                    <td>{row.negative ? '(' : ''}{row.balance}{row.negative ? ')' : ''}</td>
                    <td>{row.debit}</td><td>{row.credit}</td>
                  </tr>
                ))}
                {(preview.balancing_equity.debit !== '0.00' || preview.balancing_equity.credit !== '0.00') && (
                  <tr className="opening-balances__equity"><td><strong>3000</strong> Opening Balance Equity</td><td>Calculated</td>
                    <td>{preview.balancing_equity.debit}</td><td>{preview.balancing_equity.credit}</td></tr>
                )}
              </tbody>
              <tfoot><tr><th colSpan="2">Journal total</th><th>{preview.total_debit}</th><th>{preview.total_credit}</th></tr></tfoot>
            </table>
          </div>
          {preview.already_posted ? (
            <p className="opening-balances__success" role="status"><Check size={16} aria-hidden="true" /> Already posted.
              <Link to={`/modules/accounting/journal-entries/${preview.journal_entry_id}`}>View journal</Link></p>
          ) : preview.preview_token && !posted ? (
            <div className="opening-balances__commit">
              <label><input type="checkbox" checked={confirmed} onChange={event => setConfirmed(event.target.checked)} />
                I verified this cutover date, balances, and calculated equity.</label>
              <button className="btn btn--primary" type="button" onClick={commit} disabled={!confirmed || busy !== ''}
                data-testid="opening-post"><Upload size={15} aria-hidden="true" /> {busy === 'commit' ? 'Posting' : 'Post opening journal'}</button>
            </div>
          ) : null}
          {posted && <p className="opening-balances__success" role="status" data-testid="opening-posted">
            <Check size={16} aria-hidden="true" /> Opening balances posted.
            <Link to={`/modules/accounting/journal-entries/${posted.journal_entry_id}`}>View journal</Link>
          </p>}
        </div>
      )}
    </section>
  );
}

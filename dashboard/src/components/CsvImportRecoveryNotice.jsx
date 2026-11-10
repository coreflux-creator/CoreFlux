import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import {
  abandonUnstartedCsvImportAudit, forgetCsvImportRun, pendingCsvImportRuns, recoverCsvImportAudit,
} from '../lib/csvImportAudit';
import { csvImportDocumentLinks } from '../lib/csvImportDocumentLinks';

const LABELS = {
  ap_vendors: 'Vendors',
  accounting_accounts: 'Chart of accounts',
  billing_items: 'Products & services',
  ap_bills: 'AP bills',
  billing_invoices: 'AR invoices',
  ap_payments: 'Vendor payments',
  billing_payments: 'Customer payments',
};

export default function CsvImportRecoveryNotice({ entity = null, onRecovered = null }) {
  const [runs, setRuns] = useState(() => pendingCsvImportRuns().filter(run => !entity || run.entity === entity));
  const [results, setResults] = useState([]);
  const [errors, setErrors] = useState({});
  const [closable, setClosable] = useState({});
  const [checking, setChecking] = useState(null);
  const [closing, setClosing] = useState(null);

  const accept = (run, receipt) => {
    forgetCsvImportRun(run.id);
    setRuns(previous => previous.filter(item => item.id !== run.id));
    setResults(previous => [...previous, { ...run, ...receipt.result }]);
    if (onRecovered) onRecovered();
  };

  const check = async (run) => {
    setChecking(run.id);
    setErrors(previous => ({ ...previous, [run.id]: null }));
    setClosable(previous => ({ ...previous, [run.id]: false }));
    try {
      const receipt = await recoverCsvImportAudit({ runId: run.id });
      accept(run, receipt);
    } catch (error) {
      if (error.status === 404) {
        forgetCsvImportRun(run.id);
        setRuns(previous => previous.filter(item => item.id !== run.id));
        setErrors(previous => ({ ...previous, [run.id]: 'This run is not available to this sign-in. Check Import History for your own runs.' }));
      } else if (error.status === 409 && error.data?.can_abandon_unstarted === true) {
        setClosable(previous => ({ ...previous, [run.id]: true }));
        setErrors(previous => ({ ...previous, [run.id]: 'No committed result was found. Closing this unstarted run checks the server again.' }));
      } else {
        setErrors(previous => ({ ...previous, [run.id]: error.message || 'The server outcome is not available yet.' }));
      }
    } finally { setChecking(null); }
  };

  const closeUnstarted = async (run) => {
    setClosing(run.id);
    setErrors(previous => ({ ...previous, [run.id]: null }));
    try {
      await abandonUnstartedCsvImportAudit({ runId: run.id });
      const receipt = await recoverCsvImportAudit({ runId: run.id });
      accept(run, receipt);
    } catch (error) {
      setErrors(previous => ({ ...previous, [run.id]: error.message || 'This run still needs review.' }));
    } finally { setClosing(null); }
  };

  if (!runs.length && !results.length && !Object.values(errors).some(Boolean)) return null;
  return (
    <div className="alert alert--warning" data-testid="csv-import-recovery-notice" style={{ marginBottom: 'var(--cf-space-4)' }}>
      {runs.length > 0 && <strong>Previous import needs checking</strong>}
      {runs.map(run => (
        <div key={run.id} data-testid={`csv-import-pending-${run.id}`} style={{ marginTop: 8 }}>
          <span>{LABELS[run.entity]} run #{run.id}. Check its server receipt before importing that file again.</span>{' '}
          <button className="btn" onClick={() => check(run)} disabled={checking !== null || closing !== null} data-testid={`csv-import-recover-${run.id}`}>
            {checking === run.id ? 'Checking...' : 'Check server outcome'}
          </button>
          {closable[run.id] && <button className="btn btn--ghost" onClick={() => closeUnstarted(run)}
            disabled={checking !== null || closing !== null} data-testid={`csv-import-close-${run.id}`}>
            {closing === run.id ? 'Closing...' : 'Close unstarted run'}
          </button>}
          {errors[run.id] && <p className="error" role="alert">{errors[run.id]}</p>}
        </div>
      ))}
      {results.map(result => {
        const links = csvImportDocumentLinks(result.entity, result.ids);
        return (
          <div key={result.id} data-testid={`csv-import-recovered-${result.id}`} style={{ marginTop: 8 }}>
            {LABELS[result.entity]} run #{result.id}: {result.abandoned
              ? 'closed without an import. You can upload the file again if needed.'
              : `${result.imported_count} ${result.recovered_counts_are_rows ? 'rows ' : ''}imported, ${result.skipped_count} ${result.recovered_counts_are_rows ? 'rows ' : ''}skipped. Verified from the server receipt.`}
            {links.length > 0 && (
              <ul style={{ margin: '6px 0', paddingLeft: 20 }}>
                {links.map(link => <li key={link.to}><Link to={link.to}>{link.label}</Link></li>)}
              </ul>
            )}
            {Object.keys(result.ids || {}).length > links.length && links.length > 0
              && <p>Showing the first {links.length} document links. The rest are in the document worklist.</p>}
            {result.ids_truncated && <p>Only the first 500 record links were retained. Use the document worklist for the rest.</p>}
          </div>
        );
      })}
      {runs.length === 0 && Object.values(errors).filter(Boolean).map((message, index) => (
        <p key={index} className="error" role="alert">{message}</p>
      ))}
      <Link to="/modules/accounting/import-history">View import history</Link>
    </div>
  );
}
